<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Private performer, release and report records. Reads never initialize state. */
final class ProtectionRecords
{
    public const KINDS = ['post', 'landing_page', 'gallery', 'video', 'track', 'album', 'playlist', 'asset'];
    public const CASE_STATES = ['received', 'in_review', 'waiting_reporter', 'taken_down', 'appealed', 'closed', 'reinstated'];
    public const LIMITS = ['performers' => 200, 'releases' => 500, 'cases' => 500];
    private readonly PrivateState $state;
    private ?SecureValue $vault = null;
    private readonly Store $store;
    private readonly string $root;

    public function __construct(private readonly Config $config, ?PrivateState $state = null, private readonly ?ProtectionUploads $uploads = null)
    {
        $this->root = dirname($config->path(), 2);
        $this->state = $state ?? new PrivateState($config, $this->root);
        $this->store = new Store($config);
    }

    private function vault(): SecureValue { return $this->vault ??= new SecureValue($this->config); }

    public static function id(mixed $id): string
    {
        if (!is_string($id) || preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) throw new InvalidArgumentException('Choose a saved protection record.');
        return $id;
    }

    public static function text(mixed $value, int $limit, string $label, bool $required = false): string
    {
        if (!is_string($value) || strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)
            || preg_match('//u', $value) !== 1) throw new InvalidArgumentException($label . ' contains unsupported text or is too long.');
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if ($required && $value === '') throw new InvalidArgumentException('Enter ' . strtolower($label) . '.');
        return $value;
    }

    public static function target(mixed $value): array
    {
        if (!is_array($value) || count($value) !== 2 || !in_array($value['kind'] ?? null, self::KINDS, true)
            || !is_string($value['id'] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $value['id']) !== 1) {
            throw new InvalidArgumentException('Choose a valid linked publication or media item.');
        }
        return ['kind' => $value['kind'], 'id' => $value['id']];
    }

    public static function targets(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 20) throw new InvalidArgumentException('Choose up to 20 linked items.');
        $result = [];
        foreach ($values as $value) { $target = self::target($value); $result[$target['kind'] . ':' . $target['id']] = $target; }
        if (count($result) !== count($values)) throw new InvalidArgumentException('Choose each linked item once.');
        return array_values($result);
    }

    public static function revision(array $record): string
    {
        unset($record['revision']);
        return hash('sha256', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function snapshot(): array { return $this->validate($this->state->read('protection')); }

    /** Member binding, not a same-email guess, determines export and removal scope. */
    public function privacyRows(string $memberId): array
    {
        return array_values(array_filter($this->snapshot()['cases'], static fn(array $row): bool => $row['reporter_member_id'] === $memberId));
    }

    public function privacyForget(string $memberId, callable $authorize): void
    {
        $this->mutate(function (array &$data) use ($memberId, $authorize): void {
            $authorize();
            foreach ($data['cases'] as &$record) {
                if ($record['reporter_member_id'] !== $memberId) continue;
                $record['label'] = 'Privacy-anonymized report'; $record['reporter_member_id'] = null;
                $record['receipt_hash'] = hash('sha256', random_bytes(32)); $record['rate_hash'] = hash('sha256', random_bytes(32));
                foreach (['name','email','description','url'] as $field) $record[$field . '_encrypted'] = '';
                foreach ($record['events'] as &$event) {
                    // Internal owner safety evidence remains; reporter text and public replies are removed.
                    if (in_array($event['type'], ['received','appeal','reporter_reply'], true)) $event['message_encrypted'] = '';
                    $event['public_message_encrypted'] = '';
                }
                unset($event);
                if (count($record['events']) < 100) $record['events'] = $this->event($record['events'], 'privacy_anonymized', null, 'Member identity and reporter text removed; safety decision and evidence retained.');
                ++$record['version']; $record['updated_at'] = gmdate('c');
            }
            unset($record); $authorize();
        });
    }

    public function enabled(): bool
    {
        $this->config->reload();
        return $this->config->get('protection.enabled', false) === true;
    }

    /** Explicit owner setup. Once enabled, unavailable state always denies delivery. */
    public function activate(string $expectedConfig, string $expectedState, array $owner, callable $privileged): void
    {
        $this->requireOwner($owner); $privileged();
        $before = $this->snapshot();
        if (!hash_equals(self::revision($before), $expectedState)) throw new RuntimeException('The protection workspace changed. Reload before enabling it.', 409);
        $this->config->reload();
        if (!hash_equals($this->config->revision(), $expectedConfig)) throw new RuntimeException('The website settings changed. Reload before enabling protection.', 409);
        $authorize = function () use ($owner, $privileged): void { $this->requireOwner($owner); $privileged(); };
        ($this->uploads ?? new ProtectionUploads($this->config))->installAndVerify($authorize);
        $this->config->update(function (array &$values) use ($owner, $privileged, $before): void {
            $this->requireOwner($owner); $privileged();
            if (($values['protection']['enabled'] ?? false) === true && ($values['protection']['setup_pending'] ?? false) !== true && !$before['ready']) {
                throw new RuntimeException('The configured protection journal is unavailable. Restore its verified backup before continuing.', 503);
            }
            $values['protection']['enabled'] = true;
            $values['protection']['setup_pending'] = true;
        }, $expectedConfig);
        // The tested static-file barrier and the flag are durable before moving
        // any file. An interrupted setup cannot expose a remaining raw upload.
        $resources = new ProtectionResources($this->config);
        $bindings = $resources->allBindings();
        $this->mutate(function (array &$data) use ($owner, $privileged, $expectedState, $resources, $bindings): void {
            if (!hash_equals(self::revision($data), $expectedState)) throw new RuntimeException('The protection workspace changed. Reload and finish setup.', 409);
            $this->requireOwner($owner); $privileged();
            $resources->preserve($bindings, $data['vault'], function () use ($owner, $privileged): void { $this->requireOwner($owner); $privileged(); });
            $this->requireOwner($owner); $privileged(); $data['ready'] = true;
        });
        $this->config->update(function (array &$values) use ($owner, $privileged): void {
            $this->requireOwner($owner); $privileged();
            if (($values['protection']['enabled'] ?? false) !== true) throw new RuntimeException('Protection settings changed. Review them before continuing.', 409);
            $values['protection']['setup_pending'] = false;
        });
    }

    /** Upload callers retain their ordinary authentication; no public path is returned. */
    public function preserveUpload(array $asset): void
    {
        if (!$this->enabled()) return;
        $resources = new ProtectionResources($this->config);
        $bindings = $resources->assetBindings([(string) ($asset['id'] ?? 'new') => $asset]);
        $this->mutate(function (array &$data) use ($resources, $bindings): void {
            if (!$data['ready'] && $this->config->get('protection.setup_pending', false) !== true) throw new RuntimeException('Protection storage is unavailable. No media can be published until it is restored.', 503);
            $resources->preserve($bindings, $data['vault']);
        });
    }

    public function publicationAllowed(string $kind, string $id, ?array $record = null): bool
    {
        if (!$this->enabled()) return true;
        try {
            if (!$this->snapshot()['ready'] || $this->config->get('protection.setup_pending', false) === true) return false;
            // A newly created publication still cannot reuse held assets.
            return $this->allowed($kind, $id, $record);
        } catch (Throwable) { return false; }
    }

    /** Safe labels only. Sensitive references and report text require explicit owner review. */
    public function overview(array $owner): array
    {
        $this->requireOwner($owner);
        $data = $this->snapshot();
        $rows = [];
        foreach (self::LIMITS as $collection => $limit) {
            $rows[$collection] = array_map(static fn(array $row): array => [
                'id' => $row['id'], 'label' => $row['label'], 'status' => $row['status'],
                'updated_at' => $row['updated_at'], 'revision' => self::revision($row),
                'hold' => $row['hold'] ?? false,
            ], array_values($data[$collection]));
            usort($rows[$collection], static fn(array $a, array $b): int => strcmp($b['updated_at'], $a['updated_at']));
        }
        $rows['media'] = [];
        if ($data['offline_assets'] !== []) {
            $catalog = (new ProtectionResources($this->config))->catalog();
            foreach ($data['offline_assets'] as $id => $offline) {
                $asset = $catalog['asset'][$id] ?? null;
                $rows['media'][] = ['id' => (string) $id, 'label' => (string) ($asset['title'] ?? 'Missing media item'), 'updated_at' => $offline['at'],
                    'revision' => $asset === null ? 'missing' : self::revision($asset),
                    'can_release' => $asset !== null && $this->holdsAllow($data, 'asset', (string) $id, $asset, $catalog)];
            }
        }
        $enabled = $this->enabled();
        $this->requireOwner($owner);
        return $rows + ['revision' => self::revision($data), 'limits' => self::LIMITS, 'ready' => $data['ready'], 'enabled' => $enabled, 'config_revision' => $this->config->revision()];
    }

    public function review(string $collection, string $id, array $owner, callable $privileged): array
    {
        $this->requireCollection($collection); self::id($id); $this->requireOwner($owner); $privileged();
        $data = $this->snapshot();
        $record = $data[$collection][$id] ?? null;
        if ($record === null) throw new RuntimeException('This protection record is no longer available.', 404);
        $revision = self::revision($record);
        foreach ($record as $key => $value) {
            if (str_ends_with($key, '_encrypted')) { $record[substr($key, 0, -10)] = $this->vault()->decrypt($value); unset($record[$key]); }
        }
        foreach ($record['events'] as &$event) {
            $event['message'] = $this->vault()->decrypt($event['message_encrypted']);
            $event['public_message'] = $this->vault()->decrypt($event['public_message_encrypted']);
            unset($event['message_encrypted'], $event['public_message_encrypted']);
        }
        unset($event, $record['receipt_hash'], $record['rate_hash']);
        $this->requireOwner($owner); $privileged();
        return $record + ['revision' => $revision];
    }

    public function savePerformer(array $input, string $expected, array $owner, callable $privileged): string
    {
        $id = ($input['id'] ?? '') === '' ? '' : self::id($input['id']);
        $label = self::text($input['label'] ?? null, 160, 'Display name', true);
        $status = $input['status'] ?? null;
        if (!in_array($status, ['active', 'inactive'], true)) throw new InvalidArgumentException('Choose an active or inactive collaborator.');
        $reference = self::text($input['document_reference'] ?? '', 1500, 'Private document reference');
        $notes = self::text($input['notes'] ?? '', 4000, 'Private notes');
        $reviewed = $this->date($input['reviewed_on'] ?? '');
        $this->requireReady(); $this->requireOwner($owner); $privileged();
        $snapshot = $this->snapshot(); $previous = $this->existing($snapshot, 'performers', $id, $expected);
        $offline = [];
        if ($status === 'active' && ($previous['status'] ?? 'active') !== 'active') {
            $linked = $this->performerLinks($snapshot, $id);
            $offline = (new ProtectionResources($this->config))->withdraw($linked['targets'], $input['content_revision'] ?? '', $owner, $privileged, $linked['asset_ids']);
        }
        return $this->mutate(function (array &$data) use ($id, $label, $status, $reference, $notes, $reviewed, $expected, $owner, $privileged, $offline): string {
            $previous = $this->existing($data, 'performers', $id, $expected);
            $this->requireOwner($owner); $privileged();
            $this->keepOffline($data, $offline);
            $record = $this->record($previous, $label, $status) + [
                'document_reference_encrypted' => $this->vault()->encrypt($reference), 'notes_encrypted' => $this->vault()->encrypt($notes),
                'reviewed_on' => $reviewed,
            ];
            $record['events'] = $this->event($previous['events'] ?? [], 'saved', $owner['id'], 'Collaborator record saved.');
            $this->requireOwner($owner); $privileged();
            $data['performers'][$record['id']] = $record;
            return $record['id'];
        });
    }

    /** Managed files are preserved privately before a release can govern their availability. */
    public function saveRelease(array $input, string $expected, array $owner, callable $privileged): string
    {
        $id = ($input['id'] ?? '') === '' ? '' : self::id($input['id']);
        $label = self::text($input['label'] ?? null, 160, 'Release title', true);
        $status = $input['status'] ?? null;
        if (!in_array($status, ['draft', 'active', 'revoked'], true)) throw new InvalidArgumentException('Choose Draft, Approved or Revoked.');
        $people = $input['performer_ids'] ?? [];
        if (!is_array($people) || !array_is_list($people) || count($people) > 20 || count(array_unique($people, SORT_REGULAR)) !== count($people)) throw new InvalidArgumentException('Choose up to 20 collaborators.');
        foreach ($people as $person) self::id($person);
        $targets = self::targets($input['targets'] ?? []);
        if ($targets === []) throw new InvalidArgumentException('Link at least one publication or media item.');
        $reference = self::text($input['document_reference'] ?? '', 1500, 'Private release reference');
        $notes = self::text($input['notes'] ?? '', 4000, 'Private release notes');
        $expires = $this->date($input['expires_on'] ?? '');
        if ($status === 'active' && ($people === [] || $reference === '' || ($input['consent_reviewed'] ?? null) !== true
            || ($expires !== '' && $expires < gmdate('Y-m-d')))) throw new InvalidArgumentException('Approval requires collaborators, a private release reference, reviewed consent and a current expiration date.');
        $this->requireReady(); $this->requireOwner($owner); $privileged();
        $media = new ProtectionResources($this->config);
        $bindings = $media->bindings($targets);
        if ($status === 'active' && $bindings['missing_assets'] !== []) throw new InvalidArgumentException('Restore or replace the missing linked media before approving this release.');
        // Reapproval can clear a hold, but it must never publish a previously held item.
        $before = $this->snapshot();
        $old = $this->existing($before, 'releases', $id, $expected);
        $offline = [];
        if ($status === 'active' && $old !== [] && !$this->releaseAllows($before, $old)) {
            $offline = $media->withdraw($old['targets'], $input['content_revision'] ?? '', $owner, $privileged, $old['asset_ids']);
        } elseif ($old !== [] && !$this->releaseAllows($before, $old)) {
            if (array_diff(array_map(static fn(array $row): string => $row['kind'] . ':' . $row['id'], $old['targets']), array_map(static fn(array $row): string => $row['kind'] . ':' . $row['id'], $targets)) !== []) {
                throw new InvalidArgumentException('Approve and review the existing hold before removing its linked items.');
            }
            $bindings['asset_ids'] = array_values(array_unique(array_merge($old['asset_ids'], $bindings['asset_ids'])));
        }
        return $this->mutate(function (array &$data) use ($id, $label, $status, $people, $targets, $reference, $notes, $expires, $bindings, $expected, $owner, $privileged, $media, $offline): string {
            $previous = $this->existing($data, 'releases', $id, $expected);
            foreach ($people as $person) {
                if (!isset($data['performers'][$person]) || ($status === 'active' && $data['performers'][$person]['status'] !== 'active')) throw new InvalidArgumentException('Choose saved active collaborators before approving this release.');
            }
            if (count($previous['events'] ?? []) >= 100) throw new RuntimeException('This release has reached its saved-history limit.');
            $this->requireOwner($owner); $privileged();
            $media->preserve($bindings, $data['vault'], function () use ($owner, $privileged): void { $this->requireOwner($owner); $privileged(); });
            $this->requireOwner($owner); $privileged();
            $this->keepOffline($data, $offline);
            $record = $this->record($previous, $label, $status) + [
                'performer_ids' => $people, 'targets' => $targets, 'asset_ids' => $bindings['asset_ids'],
                'document_reference_encrypted' => $this->vault()->encrypt($reference), 'notes_encrypted' => $this->vault()->encrypt($notes),
                'expires_on' => $expires, 'consent_reviewed' => $status === 'active',
            ];
            $record['events'] = $this->event($previous['events'] ?? [], 'saved', $owner['id'], 'Release saved as ' . $status . '.');
            $this->requireOwner($owner); $privileged();
            $data['releases'][$record['id']] = $record;
            return $record['id'];
        });
    }

    /** A public report records a request; it never withdraws content by itself. */
    public function report(array $input, string $rateIdentity, ?array $member = null): array
    {
        $category = $input['category'] ?? null;
        if (!in_array($category, ['content', 'copyright', 'consent', 'privacy', 'other'], true)) throw new InvalidArgumentException('Choose what you want to report.');
        $label = self::text($input['label'] ?? null, 180, 'Report subject', true);
        $name = self::text($input['name'] ?? null, 160, 'Your name', true);
        $email = EmailAddress::normalize(self::text($input['email'] ?? null, 190, 'Email address', true));
        if (!EmailAddress::isValid($email)) throw new InvalidArgumentException('Enter a valid email address.');
        $description = self::text($input['description'] ?? null, 6000, 'Report details', true);
        $url = self::text($input['url'] ?? '', 1500, 'Content address');
        if ($url !== '' && !$this->localUrl($url)) throw new InvalidArgumentException('Enter a content address on this website.');
        if (($input['accurate'] ?? null) !== true) throw new InvalidArgumentException('Confirm that your report is accurate to the best of your knowledge.');
        $memberId = $this->currentMemberId($member);
        $secret = bin2hex(random_bytes(24));
        $rate = hash_hmac('sha256', $rateIdentity, (string) $this->config->get('security.app_key'));
        return $this->mutate(function (array &$data) use ($category, $label, $name, $email, $description, $url, $memberId, $member, $secret, $rate): array {
            if ($this->currentMemberId($member) !== $memberId) throw new RuntimeException('Sign in again before continuing.', 403);
            $now = time(); $recent = 0;
            foreach ($data['cases'] as $case) if (hash_equals($case['rate_hash'], $rate) && strtotime($case['created_at']) > $now - 3600) ++$recent;
            if ($recent >= 3) throw new RuntimeException('Three reports have already been received from this connection in the last hour. Please wait before submitting another.', 429);
            if (count($data['cases']) >= self::LIMITS['cases']) throw new RuntimeException('The report workspace is full. Please use the published support contact.', 503);
            $record = $this->record([], $label, 'received') + [
                'category' => $category, 'reporter_member_id' => $memberId, 'receipt_hash' => hash('sha256', $secret), 'rate_hash' => $rate,
                'name_encrypted' => $this->vault()->encrypt($name), 'email_encrypted' => $this->vault()->encrypt($email),
                'description_encrypted' => $this->vault()->encrypt($description), 'url_encrypted' => $this->vault()->encrypt($url),
                'targets' => [], 'asset_ids' => [], 'hold' => false, 'appeals' => 0,
            ];
            $record['events'] = $this->event([], 'received', null, 'Report received.', 'Your report has been received for review.');
            $data['cases'][$record['id']] = $record;
            return ['id' => $record['id'], 'access_key' => $secret];
        });
    }

    public function publicReview(string $id, string $secret = '', ?array $member = null): array
    {
        self::id($id);
        $record = $this->snapshot()['cases'][$id] ?? null;
        $this->requireReporter($record, $secret, $member);
        $events = [];
        foreach ($record['events'] as $event) {
            $message = $this->vault()->decrypt($event['public_message_encrypted']);
            if ($message !== '') $events[] = ['at' => $event['at'], 'type' => $event['type'], 'message' => $message];
        }
        $this->requireReporter($record, $secret, $member);
        return ['id' => $id, 'label' => $record['label'], 'status' => $record['status'], 'created_at' => $record['created_at'],
            'revision' => self::revision($record), 'events' => $events,
            'can_appeal' => in_array($record['status'], ['taken_down', 'closed', 'reinstated'], true) && $record['appeals'] < 3,
            'can_reply' => $record['status'] === 'waiting_reporter'];
    }

    public function reporterReply(string $id, string $expected, string $secret, ?array $member, string $message, bool $appeal): void
    {
        self::id($id); $message = self::text($message, 6000, $appeal ? 'Appeal details' : 'Reply', true);
        $this->mutate(function (array &$data) use ($id, $expected, $secret, $member, $message, $appeal): void {
            $record = $data['cases'][$id] ?? null;
            $this->requireReporter($record, $secret, $member); $this->expect($record, $expected);
            if ($appeal && (!in_array($record['status'], ['taken_down', 'closed', 'reinstated'], true) || $record['appeals'] >= 3)) throw new RuntimeException('This case is not accepting another appeal.', 409);
            if (!$appeal && $record['status'] !== 'waiting_reporter') throw new RuntimeException('This case is not waiting for a reply.', 409);
            $record['events'] = $this->event($record['events'], $appeal ? 'appeal' : 'reporter_reply', null, $message,
                $appeal ? 'Your appeal has been received for review.' : 'Your reply has been received for review.');
            if ($appeal) ++$record['appeals'];
            $record['status'] = $appeal ? 'appealed' : 'in_review'; $record['updated_at'] = gmdate('c'); ++$record['version'];
            $data['cases'][$id] = $record;
        });
    }

    public function caseAction(string $id, string $expected, array $input, array $owner, callable $privileged): void
    {
        self::id($id);
        $action = $input['action'] ?? null;
        if (!in_array($action, ['review', 'request_reply', 'respond', 'takedown', 'reinstate', 'close'], true)) throw new InvalidArgumentException('Choose a case action.');
        $message = self::text($input['message'] ?? '', 4000, 'Private decision notes', true);
        $public = self::text($input['public_message'] ?? '', 4000, 'Response to the reporter');
        if (in_array($action, ['request_reply', 'respond', 'takedown', 'reinstate', 'close'], true) && $public === '') throw new InvalidArgumentException('Enter a response the reporter can read in their case.');
        $this->requireReady(); $this->requireOwner($owner); $privileged();
        $snapshot = $this->snapshot(); $current = $snapshot['cases'][$id] ?? null;
        if ($current === null) throw new RuntimeException('The case is no longer available.', 404);
        $this->expect($current, $expected);
        $targets = $action === 'takedown' ? self::targets($input['targets'] ?? []) : $current['targets'];
        if ($action === 'takedown' && $current['hold'] && array_diff(array_map(static fn(array $row): string => $row['kind'] . ':' . $row['id'], $current['targets']), array_map(static fn(array $row): string => $row['kind'] . ':' . $row['id'], $targets)) !== []) {
            throw new InvalidArgumentException('Lift and review the current hold before removing its linked items.');
        }
        if ($action === 'takedown' && $targets === []) throw new InvalidArgumentException('Choose at least one saved item to withdraw.');
        if ($action === 'reinstate' && !$current['hold']) throw new RuntimeException('This case has no active publication hold.', 409);
        $resources = new ProtectionResources($this->config);
        $bindings = $action === 'takedown' ? $resources->bindings($targets) : null;
        if ($bindings !== null && $current['hold']) $bindings['asset_ids'] = array_values(array_unique(array_merge($current['asset_ids'], $bindings['asset_ids'])));
        $offline = $action === 'reinstate' ? $resources->withdraw($targets, $input['content_revision'] ?? '', $owner, $privileged, $current['asset_ids']) : [];
        $this->mutate(function (array &$data) use ($id, $expected, $action, $message, $public, $targets, $bindings, $owner, $privileged, $resources, $offline): void {
            $record = $data['cases'][$id] ?? null;
            if ($record === null) throw new RuntimeException('The case is no longer available.', 404);
            $this->expect($record, $expected); $this->requireOwner($owner); $privileged();
            if (count($record['events']) >= 100) throw new RuntimeException('This case has reached its saved-history limit.');
            if ($action === 'takedown') {
                $resources->preserve($bindings, $data['vault'], function () use ($owner, $privileged): void { $this->requireOwner($owner); $privileged(); });
                $this->requireOwner($owner); $privileged();
                $record['targets'] = $targets; $record['asset_ids'] = $bindings['asset_ids']; $record['hold'] = true;
            }
            if ($action === 'reinstate') { $this->keepOffline($data, $offline); $record['hold'] = false; }
            $record['status'] = match ($action) {
                'review', 'respond' => 'in_review', 'request_reply' => 'waiting_reporter',
                'takedown' => 'taken_down', 'reinstate' => 'reinstated', 'close' => 'closed',
            };
            $record['events'] = $this->event($record['events'], $action, $owner['id'], $message, $public);
            $record['updated_at'] = gmdate('c'); ++$record['version'];
            $this->requireOwner($owner); $privileged();
            $data['cases'][$id] = $record;
        });
    }

    /** Holds compose: clearing one case cannot override another case or a release. */
    public function allowed(string $kind, string $id, ?array $record = null): bool
    {
        if ($id !== '') self::target(['kind' => $kind, 'id' => $id]);
        elseif (!in_array($kind, self::KINDS, true) || $record === null) throw new InvalidArgumentException('Choose a saved item.');
        $data = $this->snapshot();
        $catalog = $kind === 'asset' ? (new ProtectionResources($this->config))->catalog() : null;
        if (!$this->holdsAllow($data, $kind, $id, $record, $catalog)) return false;
        if ($kind === 'asset') return !isset($data['offline_assets'][$id]);
        if ($record !== null && array_intersect(ProtectionResources::assetIds($kind, $record), array_keys($data['offline_assets'])) !== []) return false;
        return true;
    }

    /** Serializes ordinary media editors/workers with hold changes without saving state. */
    public function withPublicationLock(callable $operation): mixed
    {
        if (!$this->enabled()) return $operation();
        return $this->state->update('protection', function (array &$data) use ($operation): mixed {
            $saved = $this->validate($data);
            if (!$saved['ready'] || $this->config->get('protection.setup_pending', false) === true) throw new RuntimeException('Protected delivery setup must finish before media can be changed.', 503);
            return $operation();
        });
    }

    private function holdsAllow(array $data, string $kind, string $id, ?array $record = null, ?array $catalog = null): bool
    {
        $assets = $record === null ? [] : ProtectionResources::assetIds($kind, $record);
        foreach ($data['cases'] as $case) {
            if (!$case['hold']) continue;
            $held = $case['asset_ids'];
            if ($kind === 'asset') $held = array_merge($held, (new ProtectionResources($this->config))->currentAssetIds($case['targets'], $catalog));
            if ($this->linked($case, $kind, $id) || ($kind === 'asset' && in_array($id, $held, true)) || array_intersect($assets, $held) !== []) return false;
        }
        foreach ($data['releases'] as $release) {
            $linkedAssets = $release['asset_ids'];
            if ($kind === 'asset') $linkedAssets = array_merge($linkedAssets, (new ProtectionResources($this->config))->currentAssetIds($release['targets'], $catalog));
            if (!$this->linked($release, $kind, $id) && !($kind === 'asset' && in_array($id, $linkedAssets, true)) && array_intersect($assets, $linkedAssets) === []) continue;
            if (!$this->releaseAllows($data, $release)) return false;
            if ($kind === 'asset' && !in_array($id, $release['asset_ids'], true)) return false;
            if ($record !== null && array_diff(ProtectionResources::assetIds($kind, $record), $release['asset_ids']) !== []) return false;
        }
        return true;
    }

    /** An explicit, freshly reviewed release for media with no live parent. */
    public function releaseMedia(string $id, string $expectedState, string $expectedAsset, array $owner, callable $privileged): void
    {
        self::target(['kind' => 'asset', 'id' => $id]);
        $this->requireReady(); $this->requireOwner($owner); $privileged();
        $this->mutate(function (array &$data) use ($id, $expectedState, $expectedAsset, $owner, $privileged): void {
            $this->requireOwner($owner); $privileged();
            if (!hash_equals(self::revision($data), $expectedState) || !isset($data['offline_assets'][$id])) throw new RuntimeException('This media review changed. Reload before making it available.', 409);
            $catalog = (new ProtectionResources($this->config))->catalog(); $asset = $catalog['asset'][$id] ?? null;
            if ($asset === null || !hash_equals(self::revision($asset), $expectedAsset)) throw new RuntimeException('The saved media changed. Reload its current review.', 409);
            if (!$this->holdsAllow($data, 'asset', $id, $asset, $catalog)) throw new RuntimeException('A release or case still holds this media. Review that hold first.', 409);
            $this->requireOwner($owner); $privileged();
            unset($data['offline_assets'][$id]);
        });
    }

    private function keepOffline(array &$data, array $withdrawn): void
    {
        if ($withdrawn === []) return;
        $current = (new ProtectionResources($this->config))->withdrawalReview($withdrawn['targets'], $withdrawn['frozen_assets']);
        if (!hash_equals($withdrawn['revision'], $current['revision'])) throw new RuntimeException('Linked content changed while its hold was being reviewed. The hold remains active; reload before continuing.', 409);
        foreach ($withdrawn['assets'] as $asset) $data['offline_assets'][$asset['id']] = ['id' => $asset['id'], 'at' => gmdate('c'), 'asset_revision' => $asset['revision']];
    }

    public function vaultEntry(string $path): ?array
    {
        return $this->snapshot()['vault'][hash('sha256', $path)] ?? null;
    }

    public function performerLinks(array $snapshot, string $id): array
    {
        $targets = []; $assets = [];
        foreach ($snapshot['releases'] as $release) if (in_array($id, $release['performer_ids'], true)) {
            foreach ($release['targets'] as $target) $targets[$target['kind'] . ':' . $target['id']] = $target;
            $assets = array_merge($assets, $release['asset_ids']);
        }
        return ['targets' => array_values($targets), 'asset_ids' => array_values(array_unique($assets))];
    }

    private function requireReady(): void
    {
        if (!$this->enabled() || !$this->snapshot()['ready'] || $this->config->get('protection.setup_pending', false) === true) throw new RuntimeException('Enable or finish the protection workspace before changing these records.', 409);
    }

    private function linked(array $record, string $kind, string $id): bool
    {
        foreach ($record['targets'] as $target) if ($target['kind'] === $kind && $target['id'] === $id) return true;
        return false;
    }

    private function releaseAllows(array $data, array $release): bool
    {
        if ($release['status'] !== 'active' || !$release['consent_reviewed'] || ($release['expires_on'] !== '' && $release['expires_on'] < gmdate('Y-m-d'))) return false;
        foreach ($release['performer_ids'] as $id) if (($data['performers'][$id]['status'] ?? '') !== 'active') return false;
        return $release['performer_ids'] !== [] && trim($this->vault()->decrypt($release['document_reference_encrypted'])) !== '';
    }

    public function requireOwner(array $identity): void
    {
        if (!is_scalar($identity['id'] ?? null) || !is_string($identity['email'] ?? null)) throw new RuntimeException('Current owner authentication is required.', 403);
        $owner = (new Store(new Config($this->config->path())))->findAdminByEmail($identity['email']);
        if (!$owner || empty($owner['is_active']) || ($owner['role'] ?? '') !== 'owner' || (string) $owner['id'] !== (string) $identity['id']) throw new RuntimeException('Current owner authentication is required.', 403);
    }

    private function currentMemberId(?array $member): ?string
    {
        if ($member === null) return null;
        if (!is_scalar($member['id'] ?? null)) throw new RuntimeException('Sign in again before continuing.', 403);
        $saved = (new Store($this->config))->member((string) $member['id']);
        if (!$saved || ($saved['status'] ?? '') !== 'active' || empty($saved['emailVerifiedAt']) || !empty($saved['deletedAt'])
            || (string) $saved['email'] !== (string) ($member['email'] ?? '')) throw new RuntimeException('Sign in again before continuing.', 403);
        return (string) $saved['id'];
    }

    private function requireReporter(?array $record, string $secret, ?array $member): void
    {
        $memberId = $this->currentMemberId($member);
        $memberMatches = $record !== null && $memberId !== null && $record['reporter_member_id'] === $memberId;
        $keyMatches = $record !== null && preg_match('/\A[a-f0-9]{48}\z/D', $secret) === 1 && hash_equals($record['receipt_hash'], hash('sha256', $secret));
        if (!$memberMatches && !$keyMatches) throw new RuntimeException('The case number or access key was not accepted.', 403);
    }

    private function requireCollection(string $collection): void
    {
        if (!array_key_exists($collection, self::LIMITS)) throw new InvalidArgumentException('Choose Collaborators, Releases or Cases.');
    }

    private function expect(array $record, mixed $expected): void
    {
        if (!is_string($expected) || !hash_equals(self::revision($record), $expected)) throw new RuntimeException('This saved record changed. Reload its review before continuing.', 409);
    }

    private function existing(array $data, string $collection, string $id, string $expected): array
    {
        if ($id === '') {
            if ($expected !== 'new') throw new RuntimeException('Open a new form before creating this record.', 409);
            if (count($data[$collection]) >= self::LIMITS[$collection]) throw new RuntimeException('This private workspace has reached its saved-record limit. Existing records were preserved.');
            return [];
        }
        $record = $data[$collection][$id] ?? null;
        if ($record === null) throw new RuntimeException('This saved record is no longer available.', 404);
        $this->expect($record, $expected); return $record;
    }

    private function record(array $previous, string $label, string $status): array
    {
        return ['id' => $previous['id'] ?? bin2hex(random_bytes(16)), 'label' => $label, 'status' => $status,
            'created_at' => $previous['created_at'] ?? gmdate('c'), 'updated_at' => gmdate('c'), 'version' => ($previous['version'] ?? 0) + 1];
    }

    private function event(array $events, string $type, string|int|null $actor, string $message, string $public = ''): array
    {
        if (count($events) >= 100) throw new RuntimeException('This record has reached its 100-entry history limit. Its existing history was preserved.');
        $events[] = ['id' => bin2hex(random_bytes(16)), 'at' => gmdate('c'), 'type' => $type, 'actor_id' => $actor === null ? null : (string) $actor,
            'message_encrypted' => $this->vault()->encrypt($message), 'public_message_encrypted' => $this->vault()->encrypt($public)];
        return $events;
    }

    private function date(mixed $date): string
    {
        if (!is_string($date) || ($date !== '' && (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date) !== 1
            || !($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'))) || $parsed->format('Y-m-d') !== $date))) {
            throw new InvalidArgumentException('Enter a valid calendar date.');
        }
        return $date;
    }

    private function instant(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D', $value) !== 1
            || !($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value)) || $parsed->format('c') !== $value) throw new RuntimeException('A saved protection date needs review.');
    }

    private function cipher(mixed $value): void
    {
        if (!is_string($value) || strlen($value) > 16000 || ($value !== '' && preg_match('/\Avvx1:(?:sodium|aesgcm):[A-Za-z0-9+\/]+={0,2}\z/D', $value) !== 1)) throw new RuntimeException('A saved private reference needs review.');
    }

    private function localUrl(string $url): bool
    {
        $parts = parse_url($url); $base = parse_url((string) $this->config->get('base_url'));
        return is_array($parts) && is_array($base) && !isset($parts['user']) && !isset($parts['pass'])
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && strtolower($parts['host'] ?? '') === strtolower($base['host'] ?? '')
            && ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80)) === ($base['port'] ?? (($base['scheme'] ?? '') === 'https' ? 443 : 80))
            && str_starts_with(($parts['path'] ?? '/') . '/', rtrim($base['path'] ?? '', '/') . '/');
    }

    private function validate(array $data): array
    {
        if ($data === []) return ['schema' => 1, 'revision' => 0, 'ready' => false, 'performers' => [], 'releases' => [], 'cases' => [], 'vault' => [], 'offline_assets' => []];
        try {
            if (count($data) !== 8 || array_diff(array_keys($data), ['schema', 'revision', 'ready', 'performers', 'releases', 'cases', 'vault', 'offline_assets']) !== []
                || ($data['schema'] ?? null) !== 1 || !is_bool($data['ready'] ?? null) || !is_int($data['revision'] ?? null) || $data['revision'] < 0 || !is_array($data['vault'] ?? null)
                || !is_array($data['offline_assets'] ?? null) || count($data['offline_assets']) > 10000) throw new RuntimeException();
            foreach ($data['offline_assets'] as $id => $offline) {
                if (!is_array($offline) || count($offline) !== 3 || ($offline['id'] ?? null) !== (string) $id || !is_string($offline['at'] ?? null)
                    || !is_string($offline['asset_revision'] ?? null) || ($offline['asset_revision'] !== 'missing' && preg_match('/\A[a-f0-9]{64}\z/D', $offline['asset_revision']) !== 1)) throw new RuntimeException();
                self::target(['kind' => 'asset', 'id' => (string) $id]); $this->instant($offline['at']);
            }
            foreach (self::LIMITS as $collection => $limit) {
                if (!is_array($data[$collection] ?? null) || count($data[$collection]) > $limit) throw new RuntimeException();
                foreach ($data[$collection] as $key => $row) {
                    $fields = ['id', 'label', 'status', 'created_at', 'updated_at', 'version', 'events'];
                    $fields = array_merge($fields, match ($collection) {
                        'performers' => ['document_reference_encrypted', 'notes_encrypted', 'reviewed_on'],
                        'releases' => ['performer_ids', 'targets', 'asset_ids', 'document_reference_encrypted', 'notes_encrypted', 'expires_on', 'consent_reviewed'],
                        'cases' => ['category', 'reporter_member_id', 'receipt_hash', 'rate_hash', 'name_encrypted', 'email_encrypted', 'description_encrypted', 'url_encrypted', 'targets', 'asset_ids', 'hold', 'appeals'],
                    });
                    if (!is_array($row) || self::id($row['id'] ?? null) !== $key || !is_string($row['label'] ?? null)
                        || count($row) !== count($fields) || array_diff(array_keys($row), $fields) !== []
                        || !is_string($row['status'] ?? null) || !is_int($row['version'] ?? null) || $row['version'] < 1
                        || !is_string($row['created_at'] ?? null) || !is_string($row['updated_at'] ?? null)
                        || !is_array($row['events'] ?? null) || !array_is_list($row['events']) || count($row['events']) > 100) throw new RuntimeException();
                    self::text($row['label'], 180, 'Saved label', true);
                    foreach (['created_at', 'updated_at'] as $field) $this->instant($row[$field]);
                    foreach ($row as $field => $value) if (str_ends_with($field, '_encrypted')) $this->cipher($value);
                    $eventIds = [];
                    foreach ($row['events'] as $event) {
                        if (!is_array($event) || count($event) !== 6 || !is_string($event['at'] ?? null) || !is_string($event['type'] ?? null)
                            || !array_key_exists('actor_id', $event) || ($event['actor_id'] !== null && !is_string($event['actor_id']))
                            || !is_string($event['message_encrypted'] ?? null) || !is_string($event['public_message_encrypted'] ?? null)) throw new RuntimeException();
                        self::id($event['id'] ?? null); $this->instant($event['at']);
                        if (isset($eventIds[$event['id']]) || !in_array($event['type'], ['saved', 'received', 'appeal', 'reporter_reply', 'review', 'request_reply', 'respond', 'takedown', 'reinstate', 'close', 'privacy_anonymized'], true)
                            || ($event['actor_id'] !== null && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $event['actor_id']) !== 1)) throw new RuntimeException();
                        $eventIds[$event['id']] = true; $this->cipher($event['message_encrypted']); $this->cipher($event['public_message_encrypted']);
                    }
                    if ($collection === 'performers') {
                        if (!in_array($row['status'], ['active', 'inactive'], true) || !is_string($row['document_reference_encrypted'] ?? null)
                            || !is_string($row['notes_encrypted'] ?? null)) throw new RuntimeException();
                        $this->date($row['reviewed_on'] ?? null);
                    } else {
                        self::targets($row['targets'] ?? null);
                        if (!is_array($row['asset_ids'] ?? null) || !array_is_list($row['asset_ids']) || count($row['asset_ids']) > 500 || count(array_unique($row['asset_ids'], SORT_REGULAR)) !== count($row['asset_ids'])) throw new RuntimeException();
                        foreach ($row['asset_ids'] as $asset) self::target(['kind' => 'asset', 'id' => $asset]);
                    }
                    if ($collection === 'releases') {
                        if (!in_array($row['status'], ['draft', 'active', 'revoked'], true) || !is_bool($row['consent_reviewed'] ?? null)
                            || !is_string($row['document_reference_encrypted'] ?? null) || !is_string($row['notes_encrypted'] ?? null)
                            || !is_array($row['performer_ids'] ?? null) || !array_is_list($row['performer_ids']) || count($row['performer_ids']) > 20 || count(array_unique($row['performer_ids'], SORT_REGULAR)) !== count($row['performer_ids'])) throw new RuntimeException();
                        foreach ($row['performer_ids'] as $id) self::id($id);
                        $this->date($row['expires_on'] ?? null);
                        if ($row['status'] === 'active' && (!$row['consent_reviewed'] || $row['performer_ids'] === [] || $row['document_reference_encrypted'] === '')) throw new RuntimeException();
                    }
                    if ($collection === 'cases') {
                        if (!in_array($row['status'], self::CASE_STATES, true) || !in_array($row['category'] ?? '', ['content', 'copyright', 'consent', 'privacy', 'other'], true)
                            || !is_bool($row['hold'] ?? null) || !is_int($row['appeals'] ?? null) || $row['appeals'] < 0 || $row['appeals'] > 3
                            || !array_key_exists('reporter_member_id', $row) || ($row['reporter_member_id'] !== null && !is_string($row['reporter_member_id']))
                            || preg_match('/\A[a-f0-9]{64}\z/D', $row['receipt_hash'] ?? '') !== 1 || preg_match('/\A[a-f0-9]{64}\z/D', $row['rate_hash'] ?? '') !== 1) throw new RuntimeException();
                        foreach (['name', 'email', 'description', 'url'] as $field) if (!is_string($row[$field . '_encrypted'] ?? null)) throw new RuntimeException();
                        if ($row['hold'] && $row['targets'] === []) throw new RuntimeException();
                    }
                }
            }
            ProtectionResources::validateVault($data['vault']);
            if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 8 * 1024 * 1024) throw new RuntimeException();
        } catch (Throwable $error) { throw new RuntimeException('Protection records need a storage review. Existing records and publication holds were preserved.', 0, $error); }
        return $data;
    }

    private function mutate(callable $operation): mixed
    {
        return $this->state->update('protection', function (array &$data) use ($operation): mixed {
            $data = $this->validate($data); $result = $operation($data); ++$data['revision']; $this->validate($data); return $result;
        });
    }
}
