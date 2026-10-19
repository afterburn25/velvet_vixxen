<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/RuntimeInventoryService.php';

/** Retire only the exact public forwarding files distributed in Build 20261019.5. */
final class RootLayoutService
{
    // Literal SHA-256 values from committed 2bc178013c9c528f45a5d0447624f3cbaf4395f2.
    // A changed file, even a harmless comment-only edit, belongs to the owner.
    private const RETIRED_FILES = [
        'about/index.php' => '6673a6a3022b8292f859b505099c3a848a1c59c33393c3991bcc28adb71d9495',
        'account/billing.php' => 'e5f08cbc4f3faf11f1b0c38eef95b04567c6d21db3c9ccf71de0f07232346148',
        'account/forgot.php' => 'cc7779472e236f67627e15de56783ce918b52df37aee8dffa50214390caf10db',
        'account/index.php' => 'be98a9fed1101c3913490593be3620a297dca5a9fa06f44afd166994e4e57cb3',
        'account/library.php' => '7064848139e14678a055a585eaee3c9b434254e31666d720dedcdd6d4b04ad9f',
        'account/login.php' => '1238210f1c3638855d0fd204a025bec9906d2ad11ac0f5e52e1cda8968f68f82',
        'account/logout.php' => '669b2970dd1f927b576fa7ffba3470d9b3b8ca59978802fb49e63af6b739376f',
        'account/register.php' => 'f605bd6f1baa7cf262dfc3c9badbebac30aa82e975f4772781355275b25fd85a',
        'account/reset.php' => '5256f51745d05697a7bbe498185e66f8ba8df8bce30f51e413571277ed7dbc1f',
        'account/security.php' => '8659b03bf7da621fc456e660d536f53c3a9195e15adae47a09216dedf636016b',
        'account/verify.php' => '7b0bb5cfdde8f06d797c5c651b625ba201bf796834b44f22e967fc86afde646a',
        'api/addon-license.php' => '5449d9da97f1d0c2bfd0deb3ecf78ff3177264a7d0b538cf023080a8532be771',
        'api/marketing-event.php' => 'bcf6a4b232b26f8cfaa5090438f9263430dd7580f9e2a74dd4068ef1e8edeb7f',
        'api/member-state.php' => '4ae844e971e8abfcd73a8afad1c2649ced1da6738b7956fd34b6550fb83d9a85',
        'api/payment-webhook.php' => '36e97ec07d7fa7737ab8b8d1543a49a882893853413998cc662ffbf119333027',
        'api/scene.php' => 'bf97fd31e85008ecdf500bb3110aa9d2a20e891b0d7f5f97897710e6abc0ccd1',
        'api/upload.php' => '145a6b2c774b0c480e228525896c06d316e04996cf2cd3ab42e07db4d33b7795',
        'blog/index.php' => '4335f46812310a0e622d42420c6d4b9d8a1919a667892fbcfa3eb2938f2adbcd',
        'blog/post.php' => '179655657ce92c5fa3c12d153ed3ebaabb4a6606acc8464ed9bc3bb6a54fbf2d',
        'booking/index.php' => '6af98186b72593578af306f1856b36bed3d83587843b357818ddc52dbed312f6',
        'campaign/index.php' => 'e0246bd62fef634604433af230240d82fe9a5a3bac1eb7f6e34602f7ca94c365',
        'cart/index.php' => 'f944149b2df8b63b68610c367da98dd963ad93b623909b762611079f4c67d5a2',
        'checkout/index.php' => 'c3d65a753a128429082a0a034813823192e2b61994c519501221c0e5d53b8bbb',
        'checkout/success.php' => '928e19e6c6cd9932da9d8bb54b8ab22c588edd16c0217bf7d6d79726eed7aadd',
        'collections/index.php' => '6caad4cb1467770c58f633a81a81bf882e8e34e84e6390410f25ab5de61595de',
        'communication/download.php' => '356295356545523ae6b559bdaa0aefd58d6ce64f0cf7bea0e181268c9f92fe36',
        'create-account/index.php' => 'f605bd6f1baa7cf262dfc3c9badbebac30aa82e975f4772781355275b25fd85a',
        'extension/index.php' => '708e120e9c68dff499ec6d5215996c2eece6bb6bf2ea0f47207e6273898b99d1',
        'join/index.php' => 'f605bd6f1baa7cf262dfc3c9badbebac30aa82e975f4772781355275b25fd85a',
        'links/index.php' => 'd863f16b5b5b5a11a64f3da9b90cf52ae2fb94854279045a10515221809d5eef',
        'login/index.php' => '1238210f1c3638855d0fd204a025bec9906d2ad11ac0f5e52e1cda8968f68f82',
        'media.php' => '7e98f83ec622f8db265abcd6f6e663795cde75877eae3753ad81c346963806a8',
        'membership/index.php' => '50eaaa938972858fb76339a5f9ed1743da1475f1171ac4a145c0033d755f2007',
        'messages/index.php' => '9c040b75a1d5b97513872b1d5105e459efeaab099d982b28f73528b0ae27dc85',
        'music/index.php' => 'ce22e2af3021a334d1333e1669e1dce4fe422c53e840af29c470f6de54335ffc',
        'newsletter/confirm.php' => '08876afa42dbd5faf56306e1b5f3e2ebc5e61c45d15ac4cd5fb78897d351e910',
        'newsletter/index.php' => '59c2b1684ae96cf1495bfd11404c65cce7e815e9e8299c0c738191d73a8e28b2',
        'newsletter/unsubscribe.php' => '77bbfd6ec2dc6cfaa215068bf85bec7ed384ba6562ae73b2356d7bf9b6733f1e',
        'photos/index.php' => 'a64a0b7529c486b1d8c83ae747d483f4bfb5b18a19be8dd28be94d709fe6c5b2',
        'photos/view.php' => '188e49fde540ee8d88d90eca287ce4ff239c9f0c41b167b41e8969533c2605b7',
        'receipt/index.php' => '221a4e5aa4a6ef94f1368a2bbb9328b4d6fa196ec04845a3b854d2098c5824ff',
        'recover/index.php' => 'cc7779472e236f67627e15de56783ce918b52df37aee8dffa50214390caf10db',
        'requests/index.php' => 'c00a02a84893e57c591d48e7d20a6832606527e0dea41732074e4732638afacd',
        'shop/index.php' => '42dcedc511eeecf4f1169d63aa1e805c5a9f708717d9b7e6a28a3f657cea03bf',
        'videos/index.php' => '853031d6517b95274977a9786a606932e20497767895e5d98df1d2c573e1118a',
        'videos/watch.php' => 'fee9979c26c15082fc12ff3d47d237a405488dd31ce21f90061688966ebc658f',
    ];

    private readonly string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) throw new RuntimeException('The installation folder is unavailable.');
        $this->root = $real;
    }

    /** File names and reasons only; never expose the contents of a custom file. */
    public function inspect(): array
    {
        clearstatcache(true);
        $known = [];
        $preserved = [];
        $directories = [];
        $empty = [];
        foreach (self::RETIRED_FILES as $relative => $expected) {
            try {
                $path = $this->path($relative, true);
                if (!file_exists($path)) continue;
                if ($this->knownBytes($relative, $expected) !== null) {
                    $known[] = $relative;
                } else {
                    $preserved[$relative] = 'Custom, changed, unreadable or nonregular file; left in place.';
                }
            } catch (RuntimeException $error) {
                $preserved[$relative] = $error->getMessage();
            }
        }
        foreach ($this->retiredDirectories() as $relative) {
            try {
                $path = $this->path($relative, true);
                if (!file_exists($path)) continue;
                if (!is_dir($path)) {
                    $preserved[$relative] = 'A custom file occupies this former route-folder name; left in place.';
                    continue;
                }
                $directories[] = $relative;
                $items = @scandir($path);
                if (!is_array($items)) {
                    $preserved[$relative] = 'The folder could not be read; left in place.';
                    continue;
                }
                $items = array_values(array_diff($items, ['.', '..']));
                if ($items === []) $empty[] = $relative;
                foreach ($items as $name) {
                    $child = $relative . '/' . $name;
                    if (!isset(self::RETIRED_FILES[$child])) {
                        $preserved[$child] = 'Custom or unknown item; left in place without opening or moving it.';
                    }
                }
            } catch (RuntimeException $error) {
                $preserved[$relative] = $error->getMessage();
            }
        }
        ksort($preserved);
        return [
            'known_files' => $known, 'directories' => $directories, 'empty_directories' => $empty,
            'preserved' => array_map(static fn(string $path, string $reason): array => ['path' => $path, 'reason' => $reason], array_keys($preserved), array_values($preserved)),
            'has_work' => $known !== [] || $empty !== [],
            'organized' => $known === [] && $directories === [] && $preserved === [],
        ];
    }

    /** The owner HTTP controller enforces authentication, current role and CSRF. */
    public function retire(): array
    {
        $runtime = new RuntimeInventoryService($this->root);
        $build = $runtime->inspect();
        if (!$build['files_match']) throw new RuntimeException('The application update is incomplete. Replace the listed runtime files before repairing the root layout.');
        if (!$build['running_build_matches'] || version_compare($build['build_id'], '20261019.6', '<')) {
            throw new RuntimeException('Refresh application PHP first, then reload this page before repairing the root layout.');
        }
        $before = $this->inspect();
        if (!$before['has_work']) return ['retired' => [], 'removed_directories' => [], 'failed' => [], 'archive' => '', 'remaining' => $before];

        // New dispatchers must be active before any retired physical entry is removed.
        // This never invalidates another site's cache or any private runtime data.
        $runtime->refreshPhpCache();
        $archiveBase = 'storage/private/root-layout';
        $this->makeDirectory($archiveBase);
        $lockRelative = $archiveBase . '/repair.lock';
        $lockPath = $this->path($lockRelative, true);
        $lockMarker = "Velvet Vixxen root-layout repair lock v1\n";
        if (!file_exists($lockPath)) $this->writeNewFile($lockRelative, $lockMarker);
        if (!is_file($lockPath) || @file_get_contents($lockPath) !== $lockMarker) {
            throw new RuntimeException('A custom file occupies the repair-lock path. It was preserved and no root files were removed.');
        }
        $lock = @fopen($lockPath, 'r+b');
        if ($lock === false) throw new RuntimeException('Private repair storage is not writable. No root files were removed.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another root-layout repair is running. Reload this page when it finishes.');
        }
        try {
            $entries = [];
            foreach ($before['known_files'] as $relative) {
                $bytes = $this->knownBytes($relative, self::RETIRED_FILES[$relative]);
                if ($bytes !== null) {
                    $entries[$relative] = ['sha256' => self::RETIRED_FILES[$relative], 'encoding' => 'base64', 'contents' => base64_encode($bytes)];
                }
            }
            $archive = $archiveBase . '/retired-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8));
            $this->makeDirectory($archive, false);
            $this->writeNewFile($archive . '/.htaccess', "Require all denied\n");
            $record = json_encode([
                'schema' => 1, 'product' => 'Velvet Vixxen', 'source_build' => '20261019.5',
                'source_commit' => '2bc178013c9c528f45a5d0447624f3cbaf4395f2',
                'installed_build' => $build['build_id'], 'entries' => $entries,
                'retired_directory_names' => $this->retiredDirectories(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $recordPath = $archive . '/forwarders.json';
            $this->writeNewFile($recordPath, $record);

            $retired = [];
            $failed = [];
            foreach ($entries as $relative => $entry) {
                $holding = $archive . '/captured-' . hash('sha256', $relative) . '.data';
                try {
                    if ($this->knownBytes($relative, $entry['sha256']) === null) {
                        throw new RuntimeException('The file changed during review and was left in place.');
                    }
                    $source = $this->path($relative);
                    $this->invalidateRetiredPhp($source);
                    if ($this->knownBytes($relative, $entry['sha256']) === null) {
                        throw new RuntimeException('The file changed during cache refresh and was left in place.');
                    }
                    $target = $this->path($holding, true);
                    if (file_exists($target) || is_link($target) || !@rename($source, $target)) {
                        throw new RuntimeException('The host did not allow this verified file to be retired; it was left in place.');
                    }
                    // Atomic capture prevents a later file created at the original name
                    // from being deleted. A concurrent change is restored, never discarded.
                    if (!$this->matches($holding, $entry['sha256'])
                        || @file_get_contents($this->path($recordPath)) !== $record) {
                        throw new RuntimeException('The source or private archive changed during repair.');
                    }
                    if (!@unlink($this->path($holding))) {
                        throw new RuntimeException('The private copy could not be finalized.');
                    }
                    $retired[] = $relative;
                } catch (Throwable $error) {
                    $recovery = $this->restoreCapture($holding, $relative);
                    $failed[] = ['path' => $relative, 'reason' => $error->getMessage() . $recovery];
                }
            }
            $removed = [];
            foreach ($this->retiredDirectories() as $relative) {
                try {
                    $path = $this->path($relative, true);
                    if (!is_dir($path)) continue;
                    $items = @scandir($path);
                    if (!is_array($items) || array_values(array_diff($items, ['.', '..'])) !== []) continue;
                    if (@rmdir($path)) {
                        $removed[] = $relative;
                    } else {
                        $failed[] = ['path' => $relative, 'reason' => 'The empty folder could not be removed; its permissions were not changed.'];
                    }
                } catch (RuntimeException) {
                    // Linked, changed and custom entries remain visible in the next inspection.
                }
            }
            return ['retired' => $retired, 'removed_directories' => $removed, 'failed' => $failed,
                'archive' => $recordPath, 'remaining' => $this->inspect()];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function retiredDirectories(): array
    {
        $directories = [];
        foreach (array_keys(self::RETIRED_FILES) as $relative) {
            if (str_contains($relative, '/')) $directories[] = explode('/', $relative, 2)[0];
        }
        $directories = array_values(array_unique($directories));
        sort($directories);
        return $directories;
    }

    private function knownBytes(string $relative, string $expected): ?string
    {
        $path = $this->path($relative, true);
        if (!is_file($path) || !is_readable($path) || @filesize($path) > 8192) return null;
        $bytes = @file_get_contents($path);
        return is_string($bytes) && hash_equals($expected, hash('sha256', $bytes)) ? $bytes : null;
    }

    private function matches(string $relative, string $expected): bool
    {
        return $this->knownBytes($relative, $expected) !== null;
    }

    private function invalidateRetiredPhp(string $path): void
    {
        $enabled = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)
            && (PHP_SAPI !== 'cli' || filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN));
        if ($enabled && (!function_exists('opcache_invalidate') || !@opcache_invalidate($path, true))) {
            throw new RuntimeException('The host did not allow this old PHP entry to be refreshed; the file was preserved.');
        }
    }

    /** Restore an atomically captured entry without overwriting a newer owner file. */
    private function restoreCapture(string $holding, string $relative): string
    {
        try {
            $captured = $this->path($holding, true);
            if (!file_exists($captured) && !is_link($captured)) return '';
            $source = $this->path($relative, true);
            // Both paths were on the same filesystem for rename to succeed. link()
            // creates the original name atomically and refuses any existing entry.
            if (!file_exists($source) && !is_link($source) && @link($captured, $source)) {
                @unlink($captured);
                return ' The captured file was restored without overwriting another file.';
            }
        } catch (Throwable) {
            // Keep the captured bytes and report their location for owner recovery.
        }
        return ' A captured copy is preserved at ' . $holding . '; no existing file was overwritten.';
    }

    private function path(string $relative, bool $allowMissing = false): string
    {
        if ($relative === '' || str_contains($relative, '\\') || str_contains($relative, "\0")) {
            throw new RuntimeException('An unsafe repair path was refused.');
        }
        $path = $this->root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new RuntimeException('An unsafe repair path was refused.');
            $path .= '/' . $part;
            clearstatcache(true, $path);
            if (is_link($path)) throw new RuntimeException('A symbolic link was left in place without following it.');
            if (!file_exists($path)) {
                if (!$allowMissing) throw new RuntimeException('A repair path is missing.');
            } elseif ($path !== $this->root . '/' . $relative && !is_dir($path)) {
                throw new RuntimeException('A custom file occupies a folder path; it was left in place.');
            }
        }
        return $path;
    }

    private function makeDirectory(string $relative, bool $allowExisting = true): void
    {
        $path = $this->path($relative, true);
        if (file_exists($path)) {
            if (!$allowExisting || !is_dir($path)) throw new RuntimeException('The private repair destination already exists or is not a directory.');
            return;
        }
        if (!@mkdir($path, 0750, true)) throw new RuntimeException('Private repair storage could not be created. No unverified files were removed.');
        $this->path($relative);
    }

    private function writeNewFile(string $relative, string $contents): void
    {
        $path = $this->path($relative, true);
        $stream = @fopen($path, 'xb');
        if ($stream === false) throw new RuntimeException('A private repair record could not be created without overwriting an existing file.');
        @chmod($path, 0640);
        try {
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($stream, substr($contents, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('The private repair record was incomplete.');
                $offset += $written;
            }
            if (!fflush($stream)) throw new RuntimeException('The private repair record could not be saved.');
        } finally {
            fclose($stream);
        }
        if (@file_get_contents($this->path($relative)) !== $contents) {
            throw new RuntimeException('The private repair record did not verify. Root files were preserved.');
        }
    }
}
