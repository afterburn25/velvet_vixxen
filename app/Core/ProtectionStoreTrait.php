<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/** Read-only bindings and status-only withdrawals for protection decisions. */
trait ProtectionStoreTrait
{
    private const PROTECTION_COLLECTIONS = ['album' => 'albums', 'track' => 'tracks', 'playlist' => 'audioPlaylists', 'gallery' => 'galleries', 'video' => 'videos'];
    private const PROTECTION_TABLES = ['album' => 'audio_albums', 'track' => 'audio_tracks', 'playlist' => 'audio_playlists', 'gallery' => 'photo_galleries', 'video' => 'videos'];

    public function protectionMediaSnapshot(): array
    {
        if ($this->mode === 'file') return $this->protectionFileSnapshot($this->loadFileStore()[0]);
        $result = ['asset' => []];
        foreach ($this->media(true) as $asset) $result['asset'][(string) $asset['id']] = $asset;
        foreach (['album' => $this->albums(false), 'track' => $this->tracks(false), 'playlist' => $this->audioPlaylists(false),
            'gallery' => $this->galleries(false), 'video' => $this->videos(false)] as $kind => $rows) {
            $result[$kind] = [];
            foreach ($rows as $row) if (empty($row['deletedAt'])) $result[$kind][(string) $row['id']] = $row;
        }
        return $result;
    }

    private function protectionFileSnapshot(array $data): array
    {
        $result = ['asset' => []];
        foreach (($data['media'] ?? []) as $asset) if (is_array($asset) && empty($asset['deletedAt'])) $result['asset'][(string) $asset['id']] = $asset;
        foreach (self::PROTECTION_COLLECTIONS as $kind => $collection) {
            $result[$kind] = [];
            foreach (($data['mediaCenter'][$collection] ?? []) as $row) {
                if (!is_array($row) || !empty($row['deletedAt'])) continue;
                if (in_array($kind, ['playlist', 'gallery'], true)) $row = $this->hydrateFileMedia($collection, $row, $data);
                $result[$kind][(string) $row['id']] = $row;
            }
        }
        return $result;
    }

    /** A reviewed record becomes draft without copying old content over new edits. */
    public function withdrawProtectedMedia(array $expected, callable $authorize): void
    {
        if ($expected === []) return;
        $apply = function (array &$data, ?PDO $pdo = null) use ($expected, $authorize): void {
            $current = $pdo === null ? $this->protectionFileSnapshot($data) : $this->protectionMediaSnapshot();
            foreach ($expected as $entry) {
                $kind = $entry['kind']; $id = $entry['id'];
                if (!isset(self::PROTECTION_COLLECTIONS[$kind]) || !isset($current[$kind][$id])
                    || !hash_equals($entry['revision'], ProtectionRecords::revision($current[$kind][$id]))) {
                    throw new RuntimeException('Linked content changed. Review its current version before lifting this hold.', 409);
                }
            }
            // Dependent content reads may have waited for an editor.
            $authorize();
            foreach ($expected as $entry) {
                $kind = $entry['kind']; $id = $entry['id'];
                if ($pdo === null) {
                    $row =& $data['mediaCenter'][self::PROTECTION_COLLECTIONS[$kind]][$id];
                    $row['status'] = 'draft'; $row['scheduledAt'] = null; $row['updatedAt'] = gmdate('c');
                    unset($row);
                } else {
                    $pdo->prepare('UPDATE ' . self::PROTECTION_TABLES[$kind] . " SET status='draft',scheduled_at=NULL,updated_at=NOW() WHERE id=?")->execute([$id]);
                }
            }
        };
        if ($this->mode === 'file') { $this->mutateFileStore($apply); return; }
        $this->mediaTransaction(function (PDO $pdo) use ($apply): void {
            // Use one stable lock order for all selected records and their
            // dependent gallery/playlist rows before reading exact revisions.
            foreach (self::PROTECTION_TABLES as $table) $pdo->query('SELECT id FROM ' . $table . ' ORDER BY id FOR UPDATE')->fetchAll();
            foreach (['audio_playlist_items', 'photo_gallery_items'] as $table) $pdo->query('SELECT * FROM ' . $table . ' FOR UPDATE')->fetchAll();
            $unused = []; $apply($unused, $pdo);
        });
    }

    /** Publication writers call this inside their existing storage lock. */
    private function requireProtectedPublication(string $kind, array $record): void
    {
        if (!in_array($record['status'] ?? '', ['published', 'unlisted', 'scheduled'], true)) return;
        $id = (string) ($record['id'] ?? '');
        if ($kind === 'playlist') $record['tracks'] = array_values(array_filter(array_map(fn(string $id): ?array => $this->track($id), array_map('strval', (array) ($record['trackIds'] ?? [])))));
        $protection = new ProtectionRecords($this->config);
        if (!$protection->publicationAllowed($kind, $id, $record)) throw new RuntimeException('A protection hold or release review prevents publication. Save this item as a draft and review Protection & policies.', 409);
    }

    public function protectionAllowed(string $kind, string $id, ?array $record = null): bool
    {
        return (new ProtectionRecords($this->config))->publicationAllowed($kind, $id, $record);
    }

    private function protectedPublicRows(string $kind, array $rows, bool $publishedOnly): array
    {
        return !$publishedOnly ? $rows : array_values(array_filter($rows, fn(array $row): bool => $this->protectionAllowed($kind, (string) $row['id'], $row)));
    }
}
