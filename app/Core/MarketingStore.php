<?php
declare(strict_types=1);

namespace Velvet\Core;

require_once __DIR__ . '/MarketingProviderEvents.php';

require_once dirname(__DIR__) . '/Support/public_routes.php';
require_once __DIR__ . '/MarketingAudience.php';
require_once __DIR__ . '/MarketingPublication.php';
require_once __DIR__ . '/MarketingHistory.php';
require_once __DIR__ . '/MarketingRecovery.php';
require_once __DIR__ . '/MarketingStorage.php';
require_once __DIR__ . '/MarketingCampaignMessages.php';
require_once __DIR__ . '/MarketingAttributionTrait.php';
require_once __DIR__ . '/MarketingAnalyticsTrait.php';
require_once __DIR__ . '/MarketingAttributionAdminTrait.php';

final class MarketingStore
{
    use MarketingProviderEvents;
    use MarketingCampaignMessages;
    use MarketingAttributionTrait;
    use MarketingAnalyticsTrait;
    use MarketingAttributionAdminTrait;
    private MarketingStorage $storage;
    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?: dirname(__DIR__, 2);
        $this->storage = new MarketingStorage($root);
        $this->storage->initialize();
    }

    public function all(): array
    {
        return $this->storage->all();
    }

    /** Replace a previously read snapshot only if nobody has changed it. */
    public function save(array $data): void
    {
        $this->update(static function (array &$current) use ($data): void {
            if ((int) ($data['meta']['revision'] ?? 0) !== (int) ($current['meta']['revision'] ?? 0)) {
                throw new \RuntimeException('Marketing data changed. Reload before saving.');
            }
            $current = $data;
        });
    }

    /** All mutations read fresh state while holding the same short-lived lock. */
    public function update(callable $operation): mixed
    {
        return $this->storage->update($operation);
    }

    /** Network work uses its own mutex; unsubscribes and content edits stay writable. */
    public function withDeliveryLock(callable $operation): mixed
    {
        return $this->storage->withDeliveryLock($operation);
    }

    public function settings(): array
    {
        return $this->all()['settings'];
    }

    public function setSettings(array $settings): void
    {
        $this->update(static function (array &$data) use ($settings): void {
            foreach (['newsletter_signup_enabled', 'campaign_delivery_enabled'] as $key) {
                if (array_key_exists($key, $settings)) $data['settings'][$key] = (bool) $settings[$key];
            }
        });
    }

    public function publishedPosts(?int $limit = null, ?array $viewer = null): array
    {
        $now = time();
        $posts = array_values(array_filter($this->all()['posts'], static fn(mixed $post): bool => is_array($post)
            && MarketingPublication::visibility('posts', $post, $now)['visible'] && MarketingAccess::allows($post, $viewer)));
        usort($posts, static fn(array $a, array $b): int => (MarketingPublication::storedDate($b['publish_at'] ?? null)?->getTimestamp() ?? 0)
            <=> (MarketingPublication::storedDate($a['publish_at'] ?? null)?->getTimestamp() ?? 0));
        return array_map(MarketingHistory::withoutHistory(...), $limit ? array_slice($posts, 0, $limit) : $posts);
    }

    public function findPost(string $slug, ?array $viewer = null): ?array
    {
        foreach ($this->publishedPosts(null, $viewer) as $post) {
            if (($post['slug'] ?? '') === $slug) return $post;
        }
        return null;
    }

    /** Both the source and related posts are resolved from one current snapshot. */
    public function relatedPosts(string $sourceId, ?array $viewer = null): array
    {
        MarketingPublication::id($sourceId);
        return self::relatedFromSnapshot($this->all(), $sourceId, $viewer, false);
    }

    /** The active-owner caller rechecks ownership after this and all media reads. */
    public static function previewRelatedPosts(string $sourceId, ?string $root = null): array
    {
        MarketingPublication::id($sourceId);
        $data = (new MarketingStorage($root))->peek();
        return $data === null ? [] : self::relatedFromSnapshot($data, $sourceId, null, true);
    }

    private static function relatedFromSnapshot(array $data, string $sourceId, ?array $viewer, bool $preview): array
    {
        $posts = []; $duplicates = [];
        foreach ($data['posts'] as $record) {
            if (!is_array($record) || !is_string($record['id'] ?? null)) continue;
            $id = $record['id'];
            if (array_key_exists($id, $posts)) $duplicates[$id] = true;
            $posts[$id] = $record;
        }
        $source = $posts[$sourceId] ?? null;
        $now = time();
        if ($source === null || isset($duplicates[$sourceId]) || (!$preview
            && (!MarketingPublication::visibility('posts', $source, $now)['visible'] || !MarketingAccess::allows($source, $viewer)))) return [];
        $ids = MarketingMetadata::describe('posts', $source)['values']['related_post_ids'];
        $related = [];
        foreach ($ids as $id) {
            if ($id === $sourceId || isset($duplicates[$id]) || !isset($posts[$id])) continue;
            $record = $posts[$id];
            if (!$preview && (!MarketingPublication::visibility('posts', $record, $now)['visible']
                || !MarketingAccess::allows($record, $viewer))) continue;
            $related[] = MarketingHistory::withoutHistory($record);
        }
        return $related;
    }

    public function activeBanners(?array $viewer = null): array
    {
        return $this->activeWindow('banners', $viewer);
    }

    public function activeLandingPage(string $slug, ?array $viewer = null): ?array
    {
        foreach ($this->activeWindow('landing_pages', $viewer) as $page) {
            if (($page['slug'] ?? '') === $slug) return $page;
        }
        return null;
    }

    /** Owner records retain saved order; public banners additionally filter by viewer. */
    public function publicationRecords(string $collection): array
    {
        MarketingPublication::collection($collection);
        $now = time();
        return array_values(array_map(static fn(array $record): array => MarketingHistory::withoutHistory(MarketingPublication::describe($collection, $record, $now)),
            array_filter($this->all()[$collection], 'is_array')));
    }

    public function publicationRecord(string $collection, string $id): ?array
    {
        MarketingPublication::collection($collection);
        MarketingPublication::id($id);
        $data = $this->all();
        $index = $this->publicationIndex($data, $collection, $id);
        return $index === false ? null : MarketingHistory::describe($collection, $data[$collection][$index]);
    }

    /** The caller must freshly authorize the active owner before this private saved read. */
    public static function previewPublication(string $collection, string $id, ?string $root = null): ?array
    {
        MarketingPublication::collection($collection);
        MarketingPublication::id($id);
        $data = (new MarketingStorage($root))->peek();
        if ($data === null) return null;
        $record = null;
        foreach ($data[$collection] as $candidate) {
            if (!is_array($candidate) || ($candidate['id'] ?? '') !== $id) continue;
            if ($record !== null) throw new \RuntimeException('Duplicate publication identities need review before editing.');
            $record = $candidate;
        }
        return $record === null ? null : MarketingHistory::describe($collection, $record);
    }

    /** Owner-only history; the HTTP caller rechecks current authorization after every read. */
    public function publicationHistory(string $collection, string $id, ?string $entryId = null): ?array
    {
        MarketingPublication::collection($collection);
        MarketingPublication::id($id);
        if ($entryId !== null) MarketingHistory::id($entryId);
        $data = $this->storage->peek();
        return $data === null ? null : self::publicationHistoryFromSnapshot($data, $collection, $id, $entryId);
    }

    /** Saved-state-only read; does not create marketing storage, a lock, defaults or a SQL guard. */
    public static function previewPublicationHistory(string $collection, string $id, ?string $entryId = null, ?string $root = null): ?array
    {
        MarketingPublication::collection($collection);
        MarketingPublication::id($id);
        if ($entryId !== null) MarketingHistory::id($entryId);
        $data = (new MarketingStorage($root))->peek();
        if ($data === null) return null;
        return self::publicationHistoryFromSnapshot($data, $collection, $id, $entryId);
    }

    /** Related labels use this exact saved snapshot, without another initializing read. */
    private static function publicationHistoryFromSnapshot(array $data, string $collection, string $id, ?string $entryId): ?array
    {
        $record = null;
        foreach ($data[$collection] as $candidate) {
            if (!is_array($candidate) || ($candidate['id'] ?? '') !== $id) continue;
            if ($record !== null) throw new \RuntimeException('Duplicate publication identities need review before editing.');
            $record = $candidate;
        }
        if ($record === null) return null;
        $review = MarketingHistory::review($collection, $record, $entryId);
        if ($review === null) return null;
        $titles = []; $seen = [];
        foreach ($data['posts'] as $post) {
            if (!is_array($post)) continue;
            try { $postId = MarketingPublication::id($post['id'] ?? null); }
            catch (\InvalidArgumentException) { continue; }
            // An ambiguous identity must not be presented as a verified title.
            if (isset($seen[$postId])) { unset($titles[$postId]); continue; }
            $seen[$postId] = true;
            try { $titles[$postId] = MarketingAudience::text($post['title'] ?? null, 255, 'Related post title'); }
            catch (\InvalidArgumentException) { /* Invalid saved titles are not echoed. */ }
        }
        $review['related_titles'] = $titles;
        return $review;
    }

    /** Restore only a server-held prior version and always leave the result offline. */
    public function restorePublication(string $collection, string $id, string $historyId, string $revision, ?callable $authorize = null): array
    {
        MarketingPublication::collection($collection);
        MarketingPublication::id($id);
        MarketingHistory::id($historyId);
        return $this->update(function (array &$data) use ($collection, $id, $historyId, $revision, $authorize): array {
            $index = $this->publicationIndex($data, $collection, $id);
            if ($index === false) throw new \RuntimeException('This publication no longer exists. Reload before restoring it.');
            $previous = $data[$collection][$index];
            $this->requirePublicationRevision($previous, $revision);
            $review = MarketingHistory::review($collection, $previous, $historyId);
            if ($review === null) throw new \RuntimeException('This saved version is no longer available. Reload the history before restoring.');
            $fields = MarketingHistory::snapshot($collection, MarketingHistory::project($collection, $previous, $review['selected']));
            if ($collection === 'posts') $fields['status'] = 'draft'; else $fields['enabled'] = false;
            $record = array_replace($previous, $fields, ['edit_version' => (int) ($previous['edit_version'] ?? 0) + 1, 'updated_at' => gmdate('c')]);
            if ($collection === 'posts' && !array_key_exists('expires_at', $fields)) unset($record['expires_at']);
            $this->validateMetadataReferences($collection, $fields, $record, $data);
            $record = MarketingHistory::capture($collection, $previous, $record, 'restore');
            // Media/tier resolution may wait. The final current-owner callback is
            // deliberately after every reference and private-history operation.
            if ($authorize !== null) $authorize($record);
            $data[$collection][$index] = $record;
            return MarketingHistory::describe($collection, $record);
        });
    }

    /** Merge one validated editor under the existing fresh marketing-store lock. */
    public function savePublication(string $collection, array $input, ?callable $authorize = null): array
    {
        MarketingPublication::collection($collection);
        $id = MarketingPublication::id($input['id'] ?? '', true);
        return $this->update(function (array &$data) use ($collection, $input, $id, $authorize): array {
            $index = $id === '' ? false : $this->publicationIndex($data, $collection, $id);
            if ($id !== '' && $index === false) throw new \RuntimeException('This publication no longer exists. Reload before saving.');
            $existing = $index === false ? [] : $data[$collection][$index];
            if ($index !== false) $this->requirePublicationRevision($existing, $input['expected_revision'] ?? null);
            $fields = MarketingPublication::fields($collection, $input, $existing);
            if ($collection !== 'banners' && ($fields['slug'] ?? '') !== ($existing['slug'] ?? '')) {
                foreach ($data[$collection] as $other) {
                    if (is_array($other) && ($other['slug'] ?? '') === $fields['slug']) throw new \InvalidArgumentException('That permalink is already used. Choose a different slug.');
                }
                foreach (MarketingRecovery::entries($data) as $deleted) {
                    if ($deleted['collection'] === $collection && ($deleted['record']['slug'] ?? null) === $fields['slug']) {
                        throw new \InvalidArgumentException('That permalink belongs to an item in Deleted content. Restore that item or choose a different slug.');
                    }
                }
            }
            $record = array_replace($existing, $fields, ['id' => $id ?: $this->id(rtrim($collection, 's')),
                'edit_version' => (int) ($existing['edit_version'] ?? 0) + 1,
                'created_at' => $existing['created_at'] ?? gmdate('c'), 'updated_at' => gmdate('c')]);
            $this->validateMetadataReferences($collection, $fields, $record, $data);
            $record = MarketingHistory::capture($collection, $existing, $record, 'save');
            // Media catalogue reads can wait. Authorization must be checked only
            // after every saved reference has been resolved inside this lock.
            if ($authorize !== null) $authorize($record);
            if ($index === false) $data[$collection][] = $record; else $data[$collection][$index] = $record;
            return MarketingHistory::describe($collection, $record);
        });
    }

    private function validateMetadataReferences(string $collection, array $fields, array $record, array $data): void
    {
        if (array_key_exists('featured_asset_id', $fields) && $fields['featured_asset_id'] !== ''
            && MarketingMetadata::resolveImage($fields['featured_asset_id'], $this->root) === null) {
            throw new \InvalidArgumentException('That featured image is unavailable. Choose a current public image from the Media Library.');
        }
        if ($collection !== 'posts' || !array_key_exists('related_post_ids', $fields)) return;
        foreach ($fields['related_post_ids'] as $id) {
            if ($id === $record['id']) throw new \InvalidArgumentException('A post cannot be related to itself.');
            $matches = 0;
            foreach ($data['posts'] as $other) if (is_array($other) && ($other['id'] ?? null) === $id) $matches++;
            if ($matches !== 1) throw new \InvalidArgumentException('A selected related post is unavailable. Choose the related posts again.');
        }
    }

    /** A safe withdrawal is allowed even if legacy dates need repair. */
    public function setPublicationEnabled(string $collection, string $id, bool $enabled, string $revision, ?callable $authorize = null): array
    {
        MarketingPublication::collection($collection);
        MarketingPublication::id($id);
        return $this->update(function (array &$data) use ($collection, $id, $enabled, $revision, $authorize): array {
            $index = $this->publicationIndex($data, $collection, $id);
            if ($index === false) throw new \RuntimeException('This publication no longer exists. Reload before changing it.');
            $previous = $data[$collection][$index];
            $record = $previous;
            $this->requirePublicationRevision($record, $revision);
            if ($collection === 'posts') $record['status'] = $enabled ? 'published' : 'draft';
            else $record['enabled'] = $enabled;
            $visibility = MarketingPublication::visibility($collection, $record);
            if ($enabled && $visibility['problem'] !== null) throw new \InvalidArgumentException('Repair this publication before enabling it. ' . $visibility['problem']);
            $record['edit_version'] = (int) ($record['edit_version'] ?? 0) + 1;
            $record['updated_at'] = gmdate('c');
            $record = MarketingHistory::capture($collection, $previous, $record, 'visibility', !$enabled);
            if ($authorize !== null) $authorize($record);
            $data[$collection][$index] = $record;
            return MarketingHistory::describe($collection, $record);
        });
    }

    /** Removal and private capture are one commit; no archive entry is silently evicted. */
    public function deletePublication(string $collection, string $id, string $revision, ?callable $authorize = null): array
    {
        MarketingPublication::collection($collection);
        MarketingPublication::id($id);
        return $this->update(function (array &$data) use ($collection, $id, $revision, $authorize): array {
            $index = $this->publicationIndex($data, $collection, $id);
            if ($index === false) throw new \RuntimeException('This publication no longer exists. Reload before removing it.');
            $record = $data[$collection][$index];
            $this->requirePublicationRevision($record, $revision);
            $entries = MarketingRecovery::entries($data);
            $position = array_search($index, array_keys($data[$collection]), true);
            $entry = MarketingRecovery::capture($collection, $record, (int) $position, $entries);
            array_unshift($entries, $entry);
            $envelope = MarketingRecovery::envelope($entries);
            // The current owner is checked after all potentially waiting reads
            // and archive validation, immediately before this single commit.
            if ($authorize !== null) $authorize($record);
            $data[MarketingRecovery::KEY] = $envelope;
            unset($data[$collection][$index]);
            $data[$collection] = array_values($data[$collection]);
            return MarketingRecovery::summary($entry);
        });
    }

    /** No initialization, storage migration, history creation or write on a review GET. */
    public static function previewDeletedPublications(?string $collection = null, ?string $entryId = null, ?string $root = null): array
    {
        if ($collection !== null) MarketingPublication::collection($collection);
        if ($entryId !== null) MarketingRecovery::id($entryId);
        return MarketingRecovery::review((new MarketingStorage($root))->peek() ?? [], $collection, $entryId);
    }

    /** Restore the original identity and content offline; references remain available for repair. */
    public function restoreDeletedPublication(string $collection, string $entryId, string $revision, ?callable $authorize = null): array
    {
        MarketingPublication::collection($collection);
        MarketingRecovery::id($entryId);
        return $this->update(function (array &$data) use ($collection, $entryId, $revision, $authorize): array {
            [$entries, $index] = $this->deletedPublicationIndex($data, $collection, $entryId);
            $entry = $entries[$index];
            MarketingRecovery::requireRevision($entry, $revision);
            $previous = $entry['record'];
            if ($this->publicationIndex($data, $collection, $previous['id']) !== false) {
                throw new \RuntimeException('An active item already uses this saved identity. No item was replaced. Review the current content before restoring.');
            }
            if ($collection !== 'banners') {
                foreach ($data[$collection] as $other) {
                    if (is_array($other) && ($other['slug'] ?? null) === ($previous['slug'] ?? null)) {
                        throw new \RuntimeException('An active item already uses this permalink. No item was replaced. Review the current content before restoring.');
                    }
                }
            }
            $restoredPrevious = MarketingRecovery::project($entry);
            $record = $restoredPrevious;
            if ($collection === 'posts') $record['status'] = 'draft'; else $record['enabled'] = false;
            $record['edit_version'] = (int) ($previous['edit_version'] ?? 0) + 1;
            $record['updated_at'] = gmdate('c');
            // The deleted item's local dates belong to its captured timezone.
            // Keep the normalized original instants in a new history snapshot
            // even when the site's timezone changed while the item was deleted.
            $record = MarketingHistory::capture($collection, $restoredPrevious, $record, 'restore');
            unset($entries[$index]);
            $envelope = MarketingRecovery::envelope($entries);
            // Missing images, related posts or tiers are preserved for repair in
            // the normal editor. Restoring never publishes or widens access.
            // The callback uses a fresh owner check, without granting visibility.
            if ($authorize !== null) $authorize($record);
            array_splice($data[$collection], min($entry['position'], count($data[$collection])), 0, [$record]);
            $data[MarketingRecovery::KEY] = $envelope;
            return MarketingHistory::describe($collection, $record);
        });
    }

    /** Explicit permanent removal is distinct from ordinary editorial deletion. */
    public function purgeDeletedPublication(string $collection, string $entryId, string $revision, ?callable $authorize = null): void
    {
        MarketingPublication::collection($collection);
        MarketingRecovery::id($entryId);
        $this->update(function (array &$data) use ($collection, $entryId, $revision, $authorize): void {
            [$entries, $index] = $this->deletedPublicationIndex($data, $collection, $entryId);
            MarketingRecovery::requireRevision($entries[$index], $revision);
            $record = $entries[$index]['record'];
            unset($entries[$index]);
            $envelope = MarketingRecovery::envelope($entries);
            if ($authorize !== null) $authorize($record);
            $data[MarketingRecovery::KEY] = $envelope;
        });
    }

    private function deletedPublicationIndex(array $data, string $collection, string $entryId): array
    {
        $entries = MarketingRecovery::entries($data);
        foreach ($entries as $index => $entry) {
            if ($entry['id'] === $entryId && $entry['collection'] === $collection) return [$entries, $index];
        }
        throw new \RuntimeException('This deleted item is no longer available. Reload Deleted content before continuing.');
    }

    private function publicationIndex(array $data, string $collection, string $id): int|string|false
    {
        $match = false;
        foreach ($data[$collection] as $index => $record) {
            if (!is_array($record) || ($record['id'] ?? '') !== $id) continue;
            if ($match !== false) throw new \RuntimeException('Duplicate publication identities need review before editing.');
            $match = $index;
        }
        return $match;
    }

    private function requirePublicationRevision(array $record, mixed $revision): void
    {
        if (!MarketingAudience::validRevision($revision) || !hash_equals(MarketingPublication::revision($record), $revision)) {
            throw new \RuntimeException('This publication changed. Reload it before saving or changing its visibility.');
        }
    }

    public function links(): array
    {
        $links = array_values(array_filter($this->all()['links'], static fn(array $link): bool => !empty($link['enabled'])));
        usort($links, static fn(array $a, array $b): int => ((int)($a['sort'] ?? 0)) <=> ((int)($b['sort'] ?? 0)));
        return $links;
    }

    public function subscribe(string $email, string $name, string $source, bool $consent): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a valid email address.');
        if (!$consent) throw new \InvalidArgumentException('Consent is required to join the mailing list.');
        return $this->update(function (array &$data) use ($email, $name, $source): array {
            if (empty($data['settings']['newsletter_signup_enabled'])) throw new \RuntimeException('Newsletter signup is disabled.');
            $token = bin2hex(random_bytes(24));
            $now = gmdate('c');
            $existing = null;
            foreach ($data['contacts'] as $i => $contact) {
                if (strtolower((string) ($contact['email'] ?? '')) === $email) { $existing = $i; break; }
            }
            $previous = $existing === null ? [] : $data['contacts'][$existing];
            $suppressed = $this->isSuppressed($data, $previous + ['email' => $email]);
            $record = array_replace($previous, [
                'id' => $previous['id'] ?? $this->id('contact'), 'email' => $email, 'name' => trim($name),
                'status' => $suppressed ? 'suppressed' : 'pending', 'source' => substr(trim($source), 0, 120),
                'confirm_token_hash' => $suppressed ? null : hash('sha256', $token),
                'unsubscribe_token' => $previous['unsubscribe_token'] ?? bin2hex(random_bytes(24)),
                'consented_at' => $now, 'confirmed_at' => null, 'updated_at' => $now,
            ]);
            if ($existing === null) $data['contacts'][] = $record; else $data['contacts'][$existing] = $record;
            $data['consents'][] = ['id' => $this->id('consent'), 'email' => $email, 'type' => 'newsletter',
                'granted' => true, 'source' => $record['source'], 'recorded_at' => $now];
            return ['contact' => $record, 'token' => $token];
        });
    }

    public function confirm(string $token): bool
    {
        if (trim($token) === '') return false;
        $hash = hash('sha256', trim($token));
        return $this->update(function (array &$data) use ($hash): bool {
            if (empty($data['settings']['newsletter_signup_enabled'])) return false;
            foreach ($data['contacts'] as &$contact) {
                if (($contact['status'] ?? '') === 'pending' && !$this->isSuppressed($data, $contact)
                    && hash_equals((string) ($contact['confirm_token_hash'] ?? ''), $hash)) {
                    $contact['status'] = 'active';
                    $contact['confirmed_at'] = gmdate('c');
                    $contact['updated_at'] = gmdate('c');
                    $contact['confirm_token_hash'] = null;
                    return true;
                }
            }
            return false;
        });
    }

    public function unsubscribe(string $token): bool
    {
        if (trim($token) === '') return false;
        return $this->update(function (array &$data) use ($token): bool {
            foreach ($data['contacts'] as &$contact) {
                if (!hash_equals((string) ($contact['unsubscribe_token'] ?? ''), trim($token))) continue;
                if (($contact['status'] ?? '') !== 'unsubscribed') {
                    $contact['status'] = 'unsubscribed';
                    $contact['updated_at'] = gmdate('c');
                    $contact['confirm_token_hash'] = null;
                    $data['consents'][] = ['id' => $this->id('consent'), 'email' => (string) $contact['email'],
                        'type' => 'newsletter', 'granted' => false, 'source' => 'unsubscribe', 'recorded_at' => gmdate('c')];
                }
                foreach ($data['campaign_queue'] as &$delivery) {
                    if (strtolower((string) ($delivery['email'] ?? '')) !== strtolower((string) $contact['email'])) continue;
                    if (in_array($delivery['status'] ?? '', ['queued', 'prepared', 'claimed', 'failed'], true)) {
                        $delivery['status'] = 'suppressed';
                        $delivery['last_error'] = 'The recipient unsubscribed.';
                        $delivery['updated_at'] = gmdate('c');
                        unset($delivery['claim_token']);
                    } elseif (($delivery['status'] ?? '') === 'sending') {
                        // Transmission has begun; it cannot be recalled. Subsequent deliveries are suppressed.
                        $delivery['unsubscribe_requested_at'] = gmdate('c');
                    }
                }
                unset($delivery);
                return true;
            }
            return false;
        });
    }

    /** Require confirmed consent and check independent suppression records at every dispatch. */
    public function eligibleContact(array $data, string $contactId, string $email): ?array
    {
        $email = strtolower(trim($email));
        foreach ($data['contacts'] as $contact) {
            if ((string) ($contact['id'] ?? '') !== $contactId || strtolower((string) ($contact['email'] ?? '')) !== $email) continue;
            if (($contact['status'] ?? '') !== 'active' || !$this->hasDate($contact['confirmed_at'] ?? null)
                || !$this->hasDate($contact['consented_at'] ?? null) || empty($contact['unsubscribe_token'])
                || !filter_var($email, FILTER_VALIDATE_EMAIL) || $this->isSuppressed($data, $contact)) return null;
            $granted = false;
            foreach ($data['consents'] as $consent) {
                if (strtolower((string) ($consent['email'] ?? '')) === $email && ($consent['type'] ?? '') === 'newsletter') {
                    $granted = ($consent['granted'] ?? false) === true;
                }
            }
            return $granted ? $contact : null;
        }
        return null;
    }

    private function hasDate(mixed $value): bool
    {
        return is_string($value) && $value !== '' && ($time = strtotime($value)) !== false && $time <= time();
    }

    private function isSuppressed(array $data, array $contact): bool
    {
        if (!empty($contact['suppressed']) || !empty($contact['suppressed_at'])
            || in_array($contact['status'] ?? '', ['suppressed', 'bounced', 'complained', 'blocked'], true)) return true;
        foreach ($data['suppressions'] as $suppression) {
            if (strtolower((string) ($suppression['email'] ?? '')) === strtolower((string) ($contact['email'] ?? ''))
                && empty($suppression['revoked_at']) && ($suppression['active'] ?? true) !== false) return true;
        }
        return false;
    }

    /** Global delivery suppression is independent of a member's optional notice choices. */
    public function emailSuppressed(string $email): bool
    {
        $data = $this->all();
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return true;
        foreach ($data['contacts'] as $contact) {
            if (strtolower((string) ($contact['email'] ?? '')) === $email && $this->isSuppressed($data, $contact)) return true;
        }
        return $this->isSuppressed($data, ['email' => $email]);
    }

    public function recordEvent(string $name, array $properties = []): void
    {
        $allowed = ['page_view','newsletter_signup','newsletter_confirmed','cta_click','campaign_view','referral_visit','purchase_intent'];
        if (!in_array($name, $allowed, true)) return;
        $path = \vvx_public_request_path((string) ($properties['path'] ?? ($_SERVER['REQUEST_URI'] ?? '/')));
        if (preg_match('#/(?:admin|account|messages|requests|communication|receipt|newsletter/(?:confirm|unsubscribe))(/|\\.|$)#i', $path)) return;
        $this->update(function (array &$data) use ($name, $properties, $path): void {
            // Legacy callers share the retained event collection. Never evict
            // consented measurements to make room for an unverified event.
            if (count($data['events']) >= MarketingAnalytics::MAX_EVENTS) return;
            $data['events'][] = [
                'id' => $this->id('event'), 'name' => $name, 'path' => substr($path, 0, 500),
                'referral' => substr((string) ($properties['referral'] ?? ($_COOKIE['velvet_ref'] ?? '')), 0, 80),
                'campaign' => substr((string) ($properties['campaign'] ?? ''), 0, 120),
                'session_hash' => hash('sha256', gmdate('Y-m-d') . '|' . (session_id() ?: 'anonymous') . '|' . $data['meta']['analytics_salt']),
                'created_at' => gmdate('c'),
            ];
        });
    }

    public function analyticsSummary(int $days = 30): array
    {
        $after = time() - max(1, $days) * 86400;
        $events = array_values(array_filter($this->all()['events'], static fn(array $e): bool => (strtotime((string)($e['created_at'] ?? '')) ?: 0) >= $after));
        $counts = [];
        $sessions = [];
        $paths = [];
        foreach ($events as $event) {
            $name = (string)($event['name'] ?? 'unknown');
            $counts[$name] = ($counts[$name] ?? 0) + 1;
            if (!empty($event['session_hash'])) $sessions[$event['session_hash']] = true;
            if ($name === 'page_view') {
                $path = (string)($event['path'] ?? '/');
                $paths[$path] = ($paths[$path] ?? 0) + 1;
            }
        }
        arsort($paths);
        return ['events' => count($events), 'unique_sessions' => count($sessions), 'counts' => $counts, 'top_paths' => array_slice($paths, 0, 10, true)];
    }

    /** @return list<array<string,mixed>> */
    public function segments(): array
    {
        return array_map(fn(array $record): array => $this->describeSegment($record), array_values($this->all()['segments']));
    }

    public function segment(string $id): ?array
    {
        MarketingAudience::id($id);
        foreach ($this->segments() as $segment) if ($segment['id'] === $id) return $segment;
        return null;
    }

    public function saveSegment(array $input, ?callable $authorize = null): array
    {
        if (array_diff(array_keys($input), ['id', 'name', 'enabled', 'rules', 'expected_revision']) !== []) {
            throw new \InvalidArgumentException('The audience contains unsupported fields.');
        }
        $id = MarketingAudience::id($input['id'] ?? '', true);
        $name = MarketingAudience::text($input['name'] ?? '', 120, 'Audience name');
        $rules = MarketingAudience::rules($input['rules'] ?? null);
        if ($name === '' || !is_bool($input['enabled'] ?? null)) throw new \InvalidArgumentException('Enter an audience name and enabled state.');
        if (!MarketingAudience::restricted($rules)) throw new \InvalidArgumentException('Choose at least one audience criterion; use All eligible newsletter contacts for the whole list.');
        return $this->update(function (array &$data) use ($input, $id, $name, $rules, $authorize): array {
            if ($authorize !== null) $authorize();
            if (($rules['plan_ids'] ?? []) !== []) {
                $available = MarketingAccess::availablePlanIds(new \App\Core\Store(new \App\Core\Config($this->root . '/config/app.php')));
                if (array_diff($rules['plan_ids'], $available) !== []) throw new \InvalidArgumentException('A selected membership tier is unavailable. Review the saved selection before changing it.');
            }
            $index = $id === '' ? false : $this->segmentIndex($data, $id);
            if ($id !== '' && $index === false) throw new \RuntimeException('The audience no longer exists. Reload before saving.');
            $existing = $index === false ? [] : $data['segments'][$index];
            if ($index !== false) $this->requireSegmentRevision($existing, $input['expected_revision'] ?? null);
            if ($index === false && count($data['segments']) >= MarketingAudience::MAX_SEGMENTS) throw new \RuntimeException('Keep at most ' . MarketingAudience::MAX_SEGMENTS . ' saved audiences.');
            $record = array_replace($existing, ['id' => $id ?: $this->id('segment'), 'name' => $name,
                'enabled' => $input['enabled'], 'rules' => $rules, 'edit_version' => (int) ($existing['edit_version'] ?? 0) + 1,
                'created_at' => $existing['created_at'] ?? gmdate('c'), 'updated_at' => gmdate('c')]);
            if ($index === false) $data['segments'][] = $record; else $data['segments'][$index] = $record;
            if ($authorize !== null) $authorize();
            return $this->describeSegment($record);
        });
    }

    public function deleteSegment(string $id, string $revision): void
    {
        MarketingAudience::id($id);
        $this->update(function (array &$data) use ($id, $revision): void {
            $index = $this->segmentIndex($data, $id);
            if ($index === false) throw new \RuntimeException('The audience no longer exists. Reload before removing it.');
            $this->requireSegmentRevision($data['segments'][$index], $revision);
            unset($data['segments'][$index]);
            $data['segments'] = array_values($data['segments']);
        });
    }

    private function segmentIndex(array $data, string $id): int|string|false
    {
        foreach ($data['segments'] as $index => $segment) if (($segment['id'] ?? '') === $id) return $index;
        return false;
    }

    private function requireSegmentRevision(array $record, mixed $revision): void
    {
        if (!MarketingAudience::validRevision($revision) || !hash_equals(MarketingAudience::segmentRevision($record), $revision)) {
            throw new \RuntimeException('This audience changed. Reload it before saving, previewing or queueing.');
        }
    }

    /** Invalid legacy definitions remain visible for correction, never selectable. */
    private function describeSegment(array $record): array
    {
        $valid = true;
        $name = 'Audience needs review';
        $rules = MarketingAudience::rules([]);
        try {
            MarketingAudience::id($record['id'] ?? null);
            $name = MarketingAudience::text($record['name'] ?? null, 120, 'Audience name');
            $rules = MarketingAudience::rules($record['rules'] ?? null);
            if ($name === '' || !is_bool($record['enabled'] ?? null) || !MarketingAudience::restricted($rules)) $valid = false;
        } catch (\InvalidArgumentException) { $valid = false; }
        return ['id' => is_string($record['id'] ?? null) ? $record['id'] : '', 'name' => $name,
            'enabled' => ($record['enabled'] ?? null) === true, 'rules' => $rules, 'valid' => $valid,
            'revision' => MarketingAudience::segmentRevision($record), 'updated_at' => $record['updated_at'] ?? null];
    }

    /** Preview unsaved rules without creating an audience, campaign or delivery. */
    public function previewSegment(array $rules): array
    {
        $rules = MarketingAudience::rules($rules);
        if (!MarketingAudience::restricted($rules)) throw new \InvalidArgumentException('Choose a signup source or confirmation date to preview this audience.');
        $snapshot = ['version' => 1, 'mode' => 'segment', 'label' => 'Audience preview', 'segment_id' => 'preview',
            'segment_revision' => str_repeat('0', 64), 'rules' => $rules];
        return $this->evaluateAudience($this->all(), $snapshot)[0];
    }

    public function previewAudience(array $selection): array
    {
        $data = $this->all();
        return $this->evaluateAudience($data, $this->resolveAudience($data, $selection))[0];
    }

    public function campaign(string $id): ?array
    {
        MarketingAudience::id($id);
        foreach ($this->all()['campaigns'] as $campaign) if (($campaign['id'] ?? '') === $id) return $campaign;
        return null;
    }

    public function previewCampaignAudience(string $id): array
    {
        MarketingAudience::id($id);
        $data = $this->all();
        foreach ($data['campaigns'] as $campaign) {
            if (($campaign['id'] ?? '') === $id) return $this->evaluateAudience($data, $this->campaignAudience($data, $campaign), $id)[0];
        }
        throw new \RuntimeException('Campaign not found.');
    }

    private function resolveAudience(array $data, array $selection): array
    {
        $selection = MarketingAudience::selection($selection);
        if ($selection['mode'] === 'all') {
            return ['version' => 1, 'mode' => 'all', 'label' => 'All eligible newsletter contacts', 'segment_id' => null,
                'segment_revision' => null, 'rules' => MarketingAudience::rules([])];
        }
        foreach ($data['segments'] as $record) {
            if (($record['id'] ?? '') !== $selection['segment_id']) continue;
            $this->requireSegmentRevision($record, $selection['segment_revision']);
            $segment = $this->describeSegment($record);
            if (!$segment['valid'] || !$segment['enabled']) throw new \RuntimeException('This audience is disabled or needs review. Choose another audience before queueing.');
            return ['version' => 1, 'mode' => 'segment', 'label' => $segment['name'], 'segment_id' => $segment['id'],
                'segment_revision' => $segment['revision'], 'rules' => $segment['rules']];
        }
        throw new \RuntimeException('The selected audience no longer exists. Choose another audience.');
    }

    private function campaignAudience(array $data, array $campaign): array
    {
        if (array_key_exists('audience_snapshot', $campaign)) return MarketingAudience::snapshot($campaign['audience_snapshot']);
        // Older queued campaigns selected the entire eligible list. Do not
        // reinterpret arbitrary historical metadata as a newly selected segment.
        if (isset($campaign['delivery_snapshot'])) return $this->resolveAudience($data, ['mode' => 'all']);
        return $this->resolveAudience($data, array_key_exists('audience', $campaign) ? MarketingAudience::selection($campaign['audience']) : ['mode' => 'all']);
    }

    /** @return array{0:array<string,mixed>,1:list<array{contact_id:string,email:string}>} */
    private function evaluateAudience(array $data, array $snapshot, ?string $campaignId = null): array
    {
        $recipients = [];
        $seen = [];
        $businessFacts = []; $erasedActors = [];
        if (MarketingAudience::businessRestricted($snapshot['rules'])) {
            $emails = [];
            foreach ($data['contacts'] as $contact) if (is_array($contact) && is_string($contact['email'] ?? null)
                && filter_var($contact['email'], FILTER_VALIDATE_EMAIL) && MarketingAudience::matches($contact, $snapshot['rules'])) $emails[] = strtolower(trim($contact['email']));
            $businessFacts = (new \App\Core\Store(new \App\Core\Config($this->root . '/config/app.php')))->marketingAudienceFacts($emails);
            $erasedActors = MarketingAttribution::state($data)['erased_actors'];
        }
        foreach ($data['contacts'] as $contact) {
            if (!is_array($contact) || !is_string($contact['email'] ?? null) || !is_string($contact['id'] ?? null)
                || !MarketingAudience::matches($contact, $snapshot['rules'])) continue;
            $email = strtolower(trim($contact['email']));
            if (!MarketingAudience::matchesBusiness($businessFacts[$email] ?? null, $snapshot['rules'], $erasedActors)) continue;
            if (isset($seen[$email]) || !$this->eligibleContact($data, $contact['id'], $email)) continue;
            $recipients[] = ['contact_id' => $contact['id'], 'email' => $email];
            $seen[$email] = true;
        }
        $existing = [];
        foreach ($data['campaign_queue'] as $delivery) {
            if ($campaignId !== null && ($delivery['campaign_id'] ?? '') === $campaignId) $existing[strtolower(trim((string) ($delivery['email'] ?? '')))] = true;
        }
        $new = array_values(array_filter($recipients, static fn(array $recipient): bool => !isset($existing[$recipient['email']])));
        $canonical = $recipients;
        usort($canonical, static fn(array $left, array $right): int => strcmp($left['email'] . ':' . $left['contact_id'], $right['email'] . ':' . $right['contact_id']));
        $existingEmails = array_keys($existing);
        sort($existingEmails, SORT_STRING);
        $revision = hash('sha256', json_encode([$snapshot, $canonical, $existingEmails], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return [['audience' => $snapshot, 'eligible_count' => count($recipients), 'new_count' => count($new),
            'existing_count' => count($existing), 'revision' => $revision], $new];
    }

    public function upsert(string $collection, array $record): array
    {
        $this->requireCollection($collection);
        if ($collection === 'segments') return $this->saveSegment($record);
        if ($collection === 'campaigns' && array_key_exists('audience_snapshot', $record)) throw new \InvalidArgumentException('Campaign audience snapshots are created only when queueing.');
        return $this->update(function (array &$data) use ($collection, $record): array {
            if ($collection === 'campaigns' && array_key_exists('audience', $record)) $record['audience'] = MarketingAudience::selection($record['audience']);
            $record['id'] = trim((string) ($record['id'] ?? '')) ?: $this->id(rtrim($collection, 's'));
            $record['updated_at'] = gmdate('c');
            foreach ($data[$collection] as $i => $existing) {
                if (($existing['id'] ?? '') !== $record['id']) continue;
                if ($collection === 'campaigns' && (isset($existing['delivery_snapshot']) || array_key_exists('audience_snapshot', $existing))) {
                    foreach (['subject', 'preheader', 'body', 'format', 'cta_label', 'cta_url', 'message_schema'] as $key) {
                        if (isset($record[$key]) && $record[$key] !== ($existing[$key] ?? '')) {
                            throw new \RuntimeException('Queued campaign text is frozen. Create a new campaign for a different message.');
                        }
                    }
                    if (array_key_exists('audience', $record)) {
                        $frozen = $this->campaignAudience($data, $existing);
                        $selection = $frozen['mode'] === 'all' ? ['mode' => 'all'] : ['mode' => 'segment', 'segment_id' => $frozen['segment_id'], 'segment_revision' => $frozen['segment_revision']];
                        if ($record['audience'] !== $selection) throw new \RuntimeException('The queued campaign audience is frozen. Create a new campaign for a different audience.');
                    }
                    unset($record['status'], $record['delivery_snapshot'], $record['delivery_paused']);
                } elseif ($collection === 'campaigns' && array_key_exists('audience', $record)) {
                    $this->resolveAudience($data, $record['audience']);
                }
                return $data[$collection][$i] = array_merge($existing, $record);
            }
            if ($collection === 'campaigns' && array_key_exists('audience', $record)) $this->resolveAudience($data, $record['audience']);
            $record['created_at'] = gmdate('c');
            $data[$collection][] = $record;
            return $record;
        });
    }

    public function delete(string $collection, string $id): void
    {
        $this->requireCollection($collection);
        if ($collection === 'segments') throw new \InvalidArgumentException('Remove audiences through their revision-checked editor.');
        if (in_array($collection, ['posts', 'banners', 'landing_pages'], true)) {
            throw new \InvalidArgumentException('Move editorial items to Deleted content through their revision-checked editor.');
        }
        $this->update(static function (array &$data) use ($collection, $id): void {
            $data[$collection] = array_values(array_filter($data[$collection], static fn(array $record): bool => ($record['id'] ?? '') !== $id));
            if ($collection === 'campaigns') {
                foreach ($data['campaign_queue'] as &$delivery) {
                    if (($delivery['campaign_id'] ?? '') === $id && in_array($delivery['status'] ?? '', ['queued', 'prepared', 'claimed', 'failed'], true)) {
                        $delivery['status'] = 'suppressed';
                        $delivery['last_error'] = 'The campaign was removed.';
                        unset($delivery['claim_token']);
                    }
                }
            }
        });
    }

    private function requireCollection(string $collection): void
    {
        if (!in_array($collection, ['posts','banners','landing_pages','links','referral_codes','campaigns','segments'], true)) {
            throw new \InvalidArgumentException('Unsupported marketing collection.');
        }
    }

    public function queueCampaign(string $id, ?string $previewRevision = null): int
    {
        MarketingAudience::id($id);
        return $this->update(function (array &$data) use ($id, $previewRevision): int {
            $index = array_search($id, array_column($data['campaigns'], 'id'), true);
            if ($index === false) throw new \RuntimeException('Campaign not found.');
            $campaign = &$data['campaigns'][$index];
            $scheduled = trim((string) ($campaign['scheduled_at'] ?? ''));
            if ($scheduled !== '' && strtotime($scheduled) === false) throw new \InvalidArgumentException('Enter a valid campaign schedule.');
            if (trim((string) ($campaign['subject'] ?? $campaign['name'] ?? '')) === '' || trim((string) ($campaign['body'] ?? '')) === '') {
                throw new \InvalidArgumentException('Enter a campaign subject and message before queueing.');
            }
            $audience = $this->campaignAudience($data, $campaign);
            [$preview, $recipients] = $this->evaluateAudience($data, $audience, $id);
            if ($previewRevision !== null && (!MarketingAudience::validRevision($previewRevision) || !hash_equals($preview['revision'], $previewRevision))) {
                throw new \RuntimeException('The audience or eligible recipients changed. Refresh the preview before queueing.');
            }
            $campaign['audience_snapshot'] ??= $audience + ['queued_at' => gmdate('c')];
            $campaign['delivery_snapshot'] ??= CampaignMessage::snapshot($campaign,
                CampaignMessage::template($data['settings']['email_brand'] ?? null));
            $campaign['status'] = 'queued';
            $campaign['queued_at'] ??= gmdate('c');
            $existing = [];
            foreach ($data['campaign_queue'] as &$delivery) {
                if (($delivery['campaign_id'] ?? '') !== $id) continue;
                $email = strtolower(trim((string) ($delivery['email'] ?? '')));
                $existing[$email] = true;
                // The old runner prepared files without transmitting them. Explicit owner queueing releases those records.
                if (($delivery['status'] ?? '') === 'prepared') {
                    $delivery['status'] = 'queued';
                    $delivery['legacy_prepared_at'] = $delivery['prepared_at'] ?? null;
                }
            }
            unset($delivery);
            $count = 0;
            foreach ($recipients as $recipient) {
                $email = $recipient['email'];
                if (isset($existing[$email])) continue;
                $deliveryId = $this->id('delivery');
                $data['campaign_queue'][] = [
                    'id' => $deliveryId, 'message_id' => CampaignMessage::messageId('campaign', $deliveryId),
                    'campaign_id' => $id, 'contact_id' => $recipient['contact_id'],
                    'email' => $email, 'status' => 'queued', 'attempts' => 0, 'created_at' => gmdate('c'),
                ];
                $existing[$email] = true;
                $count++;
            }
            return $count;
        });
    }

    public function pauseCampaign(string $id, bool $paused): void
    {
        $this->update(static function (array &$data) use ($id, $paused): void {
            foreach ($data['campaigns'] as &$campaign) {
                if (($campaign['id'] ?? '') === $id) { $campaign['delivery_paused'] = $paused; return; }
            }
            throw new \RuntimeException('Campaign not found.');
        });
    }

    public function retryCampaign(string $id): int
    {
        return $this->update(function (array &$data) use ($id): int {
            $count = 0;
            foreach ($data['campaign_queue'] as &$delivery) {
                if (($delivery['campaign_id'] ?? '') !== $id || ($delivery['status'] ?? '') !== 'failed'
                    || (int) ($delivery['attempts'] ?? 0) >= 3) continue;
                if (!$this->eligibleContact($data, (string) $delivery['contact_id'], (string) $delivery['email'])) {
                    $delivery['status'] = 'suppressed';
                    $delivery['last_error'] = 'The recipient is no longer eligible.';
                    continue;
                }
                $delivery['status'] = 'queued';
                $count++;
            }
            return $count;
        });
    }

    public function campaignSummary(string $id): array
    {
        $counts = array_fill_keys(['queued', 'prepared', 'claimed', 'sending', 'sent', 'failed', 'unknown', 'suppressed'], 0);
        $deliveries = [];
        foreach ($this->all()['campaign_queue'] as $delivery) {
            if (($delivery['campaign_id'] ?? '') !== $id) continue;
            $status = (string) ($delivery['status'] ?? 'unknown');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $deliveries[] = $delivery;
        }
        return ['counts' => $counts, 'deliveries' => $deliveries];
    }

    private function activeWindow(string $collection, ?array $viewer = null): array
    {
        $now = time();
        return array_map(MarketingHistory::withoutHistory(...), array_values(array_filter($this->all()[$collection] ?? [], static fn(mixed $item): bool => is_array($item)
            && MarketingPublication::visibility($collection, $item, $now)['visible'] && MarketingAccess::allows($item, $viewer))));
    }

    private function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }
}
