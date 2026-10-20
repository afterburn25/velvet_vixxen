<?php
declare(strict_types=1);

namespace Velvet\Core;

require_once dirname(__DIR__) . '/Support/public_routes.php';

final class MarketingStore
{
    private string $root;
    private string $directory;
    private string $dataFile;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?: dirname(__DIR__, 2);
        $this->directory = $this->root . '/storage/private/marketing';
        $this->dataFile = $this->directory . '/data.json';
        $this->ensureStorage();
    }

    public function all(): array
    {
        return $this->locked(LOCK_SH, fn(): array => $this->read());
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
        return $this->locked(LOCK_EX, function () use ($operation): mixed {
            $data = $this->read();
            $before = $data;
            $result = $operation($data);
            if ($data !== $before) {
                $data['meta']['revision'] = (int) ($before['meta']['revision'] ?? 0) + 1;
                $data['meta']['updated_at'] = gmdate('c');
                $this->write($data);
            }
            return $result;
        });
    }

    /** Network work uses its own mutex; unsubscribes and content edits stay writable. */
    public function withDeliveryLock(callable $operation): mixed
    {
        $lock = $this->openLock('delivery.lock');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return null; }
        try { return $operation(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
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

    public function publishedPosts(?int $limit = null): array
    {
        $now = time();
        $posts = array_values(array_filter($this->all()['posts'], static function (array $post) use ($now): bool {
            if (($post['status'] ?? 'draft') !== 'published') return false;
            $when = strtotime((string)($post['publish_at'] ?? 'now')) ?: 0;
            return $when <= $now;
        }));
        usort($posts, static fn(array $a, array $b): int => strcmp((string)($b['publish_at'] ?? ''), (string)($a['publish_at'] ?? '')));
        return $limit ? array_slice($posts, 0, $limit) : $posts;
    }

    public function findPost(string $slug): ?array
    {
        foreach ($this->publishedPosts() as $post) {
            if (($post['slug'] ?? '') === $slug) return $post;
        }
        return null;
    }

    public function activeBanners(): array
    {
        return $this->activeWindow('banners');
    }

    public function activeLandingPage(string $slug): ?array
    {
        foreach ($this->activeWindow('landing_pages') as $page) {
            if (($page['slug'] ?? '') === $slug) return $page;
        }
        return null;
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

    public function recordEvent(string $name, array $properties = []): void
    {
        $allowed = ['page_view','newsletter_signup','newsletter_confirmed','cta_click','campaign_view','referral_visit','purchase_intent'];
        if (!in_array($name, $allowed, true)) return;
        $path = \vvx_public_request_path((string) ($properties['path'] ?? ($_SERVER['REQUEST_URI'] ?? '/')));
        if (preg_match('#/(?:admin|account|messages|requests|communication|receipt|newsletter/(?:confirm|unsubscribe))(/|\\.|$)#i', $path)) return;
        $this->update(function (array &$data) use ($name, $properties, $path): void {
            $data['events'][] = [
                'id' => $this->id('event'), 'name' => $name, 'path' => substr($path, 0, 500),
                'referral' => substr((string) ($properties['referral'] ?? ($_COOKIE['velvet_ref'] ?? '')), 0, 80),
                'campaign' => substr((string) ($properties['campaign'] ?? ''), 0, 120),
                'session_hash' => hash('sha256', gmdate('Y-m-d') . '|' . (session_id() ?: 'anonymous') . '|' . $data['meta']['analytics_salt']),
                'created_at' => gmdate('c'),
            ];
            if (count($data['events']) > 20000) $data['events'] = array_slice($data['events'], -20000);
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

    public function upsert(string $collection, array $record): array
    {
        $this->requireCollection($collection);
        return $this->update(function (array &$data) use ($collection, $record): array {
            $record['id'] = trim((string) ($record['id'] ?? '')) ?: $this->id(rtrim($collection, 's'));
            $record['updated_at'] = gmdate('c');
            foreach ($data[$collection] as $i => $existing) {
                if (($existing['id'] ?? '') !== $record['id']) continue;
                if ($collection === 'campaigns' && isset($existing['delivery_snapshot'])) {
                    foreach (['subject', 'preheader', 'body'] as $key) {
                        if (isset($record[$key]) && $record[$key] !== ($existing[$key] ?? '')) {
                            throw new \RuntimeException('Queued campaign text is frozen. Create a new campaign for a different message.');
                        }
                    }
                    unset($record['status'], $record['delivery_snapshot'], $record['delivery_paused']);
                }
                return $data[$collection][$i] = array_merge($existing, $record);
            }
            $record['created_at'] = gmdate('c');
            $data[$collection][] = $record;
            return $record;
        });
    }

    public function delete(string $collection, string $id): void
    {
        $this->requireCollection($collection);
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

    public function queueCampaign(string $id): int
    {
        return $this->update(function (array &$data) use ($id): int {
            $index = array_search($id, array_column($data['campaigns'], 'id'), true);
            if ($index === false) throw new \RuntimeException('Campaign not found.');
            $campaign = &$data['campaigns'][$index];
            $scheduled = trim((string) ($campaign['scheduled_at'] ?? ''));
            if ($scheduled !== '' && strtotime($scheduled) === false) throw new \InvalidArgumentException('Enter a valid campaign schedule.');
            if (trim((string) ($campaign['subject'] ?? $campaign['name'] ?? '')) === '' || trim((string) ($campaign['body'] ?? '')) === '') {
                throw new \InvalidArgumentException('Enter a campaign subject and message before queueing.');
            }
            $campaign['delivery_snapshot'] ??= [
                'subject' => (string) ($campaign['subject'] ?? $campaign['name'] ?? 'Velvet Vixxen update'),
                'preheader' => (string) ($campaign['preheader'] ?? ''), 'body' => (string) ($campaign['body'] ?? ''),
            ];
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
            foreach ($data['contacts'] as $contact) {
                $email = strtolower(trim((string) ($contact['email'] ?? '')));
                if (isset($existing[$email]) || !$this->eligibleContact($data, (string) $contact['id'], $email)) continue;
                $data['campaign_queue'][] = [
                    'id' => $this->id('delivery'), 'campaign_id' => $id, 'contact_id' => (string) $contact['id'],
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

    private function activeWindow(string $collection): array
    {
        $now = time();
        return array_values(array_filter($this->all()[$collection] ?? [], static function (array $item) use ($now): bool {
            if (empty($item['enabled'])) return false;
            $start = !empty($item['starts_at']) ? (strtotime((string)$item['starts_at']) ?: 0) : 0;
            $end = !empty($item['ends_at']) ? (strtotime((string)$item['ends_at']) ?: PHP_INT_MAX) : PHP_INT_MAX;
            return $start <= $now && $end >= $now;
        }));
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Marketing storage directory could not be created.');
        }
        $this->locked(LOCK_EX, function (): void {
            if (!is_file($this->dataFile)) $this->write($this->defaults());
        });
    }

    private function openLock(string $name)
    {
        $path = $this->directory . '/' . $name;
        if (is_link($path) || is_link($this->dataFile)) throw new \RuntimeException('Marketing storage cannot use symbolic links.');
        $lock = @fopen($path, 'c+b');
        if (!is_resource($lock)) throw new \RuntimeException('Marketing storage could not be locked.');
        @chmod($path, 0640);
        return $lock;
    }

    private function locked(int $mode, callable $operation): mixed
    {
        $lock = $this->openLock('data.lock');
        if (!flock($lock, $mode)) { fclose($lock); throw new \RuntimeException('Marketing storage is busy.'); }
        try { return $operation(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function read(): array
    {
        $raw = @file_get_contents($this->dataFile);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) throw new \RuntimeException('Marketing data could not be read. Existing records were preserved.');
        $defaults = $this->defaults();
        $data = array_replace($defaults, $decoded);
        foreach (['meta', 'settings'] as $key) $data[$key] = array_replace($defaults[$key], (array) ($decoded[$key] ?? []));
        foreach (['contacts', 'consents', 'suppressions', 'campaigns', 'campaign_queue', 'events', 'posts', 'banners', 'links', 'landing_pages'] as $key) {
            if (!is_array($data[$key])) throw new \RuntimeException('Marketing records are invalid. Existing data was preserved.');
        }
        return $data;
    }

    private function write(array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $temp = $this->directory . '/data.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($temp, $encoded, LOCK_EX) !== strlen($encoded)) {
            @unlink($temp);
            throw new \RuntimeException('Marketing storage could not be written.');
        }
        @chmod($temp, 0640);
        if (!@rename($temp, $this->dataFile)) {
            @unlink($temp);
            throw new \RuntimeException('Marketing storage could not be activated.');
        }
        @chmod($this->dataFile, 0640);
        clearstatcache(true, $this->dataFile);
    }

    private function defaults(): array
    {
        return [
            'meta' => ['version' => 2, 'revision' => 0, 'updated_at' => gmdate('c'), 'analytics_salt' => bin2hex(random_bytes(16))],
            'settings' => ['newsletter_signup_enabled' => true, 'campaign_delivery_enabled' => true],
            'suppressions' => [], 'posts' => [], 'banners' => [], 'landing_pages' => [],
            'links' => [
                ['id'=>'link-home','label'=>'Official Website','url'=>'/','enabled'=>true,'sort'=>10],
                ['id'=>'link-membership','label'=>'Join the Velvet Club','url'=>'/membership/','enabled'=>true,'sort'=>20],
                ['id'=>'link-booking','label'=>'Booking & Appearances','url'=>'/booking/','enabled'=>true,'sort'=>30],
            ],
            'referral_codes' => [], 'contacts' => [], 'segments' => [], 'campaigns' => [],
            'campaign_queue' => [], 'events' => [], 'consents' => [],
        ];
    }

    private function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }
}
