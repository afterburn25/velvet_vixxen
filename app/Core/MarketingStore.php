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
        $this->ensureStorage();
        $raw = @file_get_contents($this->dataFile);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? array_replace_recursive($this->defaults(), $decoded) : $this->defaults();
    }

    public function save(array $data): void
    {
        $this->ensureStorage();
        $data['meta']['updated_at'] = gmdate('c');
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temp = $this->directory . '/data.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($temp, $encoded . "\n", LOCK_EX) === false) {
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
        $data = $this->all();
        $token = bin2hex(random_bytes(24));
        $now = gmdate('c');
        $existing = null;
        foreach ($data['contacts'] as $i => $contact) {
            if (($contact['email'] ?? '') === $email) { $existing = $i; break; }
        }
        $record = [
            'id' => $existing === null ? $this->id('contact') : (string)$data['contacts'][$existing]['id'],
            'email' => $email,
            'name' => trim($name),
            'status' => 'pending',
            'source' => substr(trim($source), 0, 120),
            'confirm_token_hash' => hash('sha256', $token),
            'unsubscribe_token' => $existing === null ? bin2hex(random_bytes(24)) : (string)($data['contacts'][$existing]['unsubscribe_token'] ?? bin2hex(random_bytes(24))),
            'consented_at' => $now,
            'confirmed_at' => null,
            'updated_at' => $now,
        ];
        if ($existing === null) $data['contacts'][] = $record; else $data['contacts'][$existing] = $record;
        $data['consents'][] = [
            'id' => $this->id('consent'), 'email' => $email, 'type' => 'newsletter', 'granted' => true,
            'source' => $record['source'], 'recorded_at' => $now,
        ];
        $this->save($data);
        return ['contact' => $record, 'token' => $token];
    }

    public function confirm(string $token): bool
    {
        $hash = hash('sha256', trim($token));
        $data = $this->all();
        foreach ($data['contacts'] as &$contact) {
            if (hash_equals((string)($contact['confirm_token_hash'] ?? ''), $hash)) {
                $contact['status'] = 'active';
                $contact['confirmed_at'] = gmdate('c');
                $contact['confirm_token_hash'] = null;
                $this->save($data);
                return true;
            }
        }
        return false;
    }

    public function unsubscribe(string $token): bool
    {
        $data = $this->all();
        foreach ($data['contacts'] as &$contact) {
            if (hash_equals((string)($contact['unsubscribe_token'] ?? ''), trim($token))) {
                $contact['status'] = 'unsubscribed';
                $contact['updated_at'] = gmdate('c');
                $data['consents'][] = [
                    'id' => $this->id('consent'), 'email' => (string)$contact['email'], 'type' => 'newsletter',
                    'granted' => false, 'source' => 'unsubscribe', 'recorded_at' => gmdate('c'),
                ];
                $this->save($data);
                return true;
            }
        }
        return false;
    }

    public function recordEvent(string $name, array $properties = []): void
    {
        $allowed = ['page_view','newsletter_signup','newsletter_confirmed','cta_click','campaign_view','referral_visit','purchase_intent'];
        if (!in_array($name, $allowed, true)) return;
        $data = $this->all();
        $rawPath = (string) ($properties['path'] ?? ($_SERVER['REQUEST_URI'] ?? '/'));
        $path = \vvx_public_request_path($rawPath);
        $path = is_string($path) && str_starts_with($path, '/') ? $path : '/';
        if (preg_match('#/(?:admin|account|messages|requests|communication|receipt|newsletter/(?:confirm|unsubscribe))(/|\\.|$)#i', $path)) return;
        $day = gmdate('Y-m-d');
        $session = session_id() ?: 'anonymous';
        $data['events'][] = [
            'id' => $this->id('event'),
            'name' => $name,
            'path' => substr($path, 0, 500),
            'referral' => substr((string)($properties['referral'] ?? ($_COOKIE['velvet_ref'] ?? '')), 0, 80),
            'campaign' => substr((string)($properties['campaign'] ?? ''), 0, 120),
            'session_hash' => hash('sha256', $day . '|' . $session . '|' . ($this->all()['meta']['analytics_salt'] ?? 'velvet')),
            'created_at' => gmdate('c'),
        ];
        if (count($data['events']) > 20000) $data['events'] = array_slice($data['events'], -20000);
        $this->save($data);
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
        $allowed = ['posts','banners','landing_pages','links','referral_codes','campaigns','segments'];
        if (!in_array($collection, $allowed, true)) throw new \InvalidArgumentException('Unsupported marketing collection.');
        $data = $this->all();
        $record['id'] = trim((string)($record['id'] ?? '')) ?: $this->id(rtrim($collection, 's'));
        $record['updated_at'] = gmdate('c');
        $found = false;
        foreach ($data[$collection] as $i => $existing) {
            if (($existing['id'] ?? '') === $record['id']) { $data[$collection][$i] = array_merge($existing, $record); $found = true; break; }
        }
        if (!$found) { $record['created_at'] = gmdate('c'); $data[$collection][] = $record; }
        $this->save($data);
        return $record;
    }

    public function delete(string $collection, string $id): void
    {
        $data = $this->all();
        if (!isset($data[$collection]) || !is_array($data[$collection])) return;
        $data[$collection] = array_values(array_filter($data[$collection], static fn(array $record): bool => ($record['id'] ?? '') !== $id));
        $this->save($data);
    }

    public function queueCampaign(string $id): int
    {
        $data = $this->all();
        $campaign = null;
        foreach ($data['campaigns'] as &$candidate) {
            if (($candidate['id'] ?? '') === $id) { $candidate['status'] = 'queued'; $candidate['queued_at'] = gmdate('c'); $campaign = $candidate; break; }
        }
        if (!$campaign) throw new \RuntimeException('Campaign not found.');
        $count = 0;
        foreach ($data['contacts'] as $contact) {
            if (($contact['status'] ?? '') !== 'active') continue;
            $data['campaign_queue'][] = [
                'id' => $this->id('delivery'), 'campaign_id' => $id, 'contact_id' => $contact['id'],
                'email' => $contact['email'], 'status' => 'queued', 'created_at' => gmdate('c'),
            ];
            $count++;
        }
        $this->save($data);
        return $count;
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
        @chmod($this->directory, 0775);
        if (!is_file($this->dataFile)) {
            $encoded = json_encode($this->defaults(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (@file_put_contents($this->dataFile, $encoded . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('Marketing storage could not be initialized.');
            }
            @chmod($this->dataFile, 0640);
        }
    }

    private function defaults(): array
    {
        return [
            'meta' => ['version' => 1, 'updated_at' => gmdate('c'), 'analytics_salt' => bin2hex(random_bytes(16))],
            'posts' => [], 'banners' => [], 'landing_pages' => [],
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
