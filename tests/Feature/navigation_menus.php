<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\MenuService;
use App\Core\Store;

/** Shared file/MySQL acceptance; every readback uses a fresh Store. */
function vvx_menu_acceptance(Config $config, ?PDO $database = null): int
{
    $checks = 0;
    $assert = static function (bool $okay, string $message) use (&$checks): void {
        $checks++;
        if (!$okay) throw new RuntimeException($message);
    };
    $reject = static function (callable $operation, string $message, ?int $code = null) use ($assert): void {
        $error = null;
        try { $operation(); } catch (RuntimeException|InvalidArgumentException $caught) { $error = $caught; }
        $assert($error !== null && ($code === null || $error->getCode() === $code), $message);
    };
    $file = root_path((string) $config->get('file_store_path', 'storage/data.json'));
    $fresh = static fn(): Store => new Store(new Config($config->path()));
    $state = static function () use ($database, $file): array {
        if ($database === null) return ['data' => is_file($file) ? file_get_contents($file) : null];
        return ['settings' => $database->query('SELECT setting_key,setting_value,updated_at FROM settings ORDER BY setting_key')->fetchAll(PDO::FETCH_ASSOC),
            'audit' => $database->query('SELECT * FROM audit_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)];
    };
    $beforeRead = $state();
    $header = $fresh()->navigationMenu('header');
    $footer = $fresh()->navigationMenu('footer');
    $homeFooter = $fresh()->navigationMenu('footer', 'home');
    $assert($header['revision'] === 0 && $header['configured'] === false
        && array_column($header['items'], 'label') === ['Home', 'Music', 'Photos', 'Videos', 'Membership', 'Store', 'Booking', 'About Me'],
        'An untouched Header did not preserve the original ordered links.');
    $assert(array_column($header['items'], 'url') === ['/', 'music/', 'photos/', 'videos/', 'membership/', 'shop/', 'booking/', 'about/'],
        'The original Header destinations changed.');
    $assert($footer['configured'] === false && array_column($footer['items'], 'label')
        === ['Home', 'Music', 'Photos', 'Videos', 'Membership', 'Store', 'Messages', 'Booking', 'About Me'],
        'An untouched common Footer lost its Messages link or ordering.');
    $assert($homeFooter['items'][0]['url'] === '#top' && count($homeFooter['items']) === 8
        && !in_array('Messages', array_column($homeFooter['items'], 'label'), true),
        'An untouched home Footer did not preserve its page-specific defaults.');
    $assert($state() === $beforeRead, 'Reading default menus wrote settings, audit records or file data.');
    if ($database === null && !is_file($file)) {
        $assert(!is_file($file . '.lock'), 'Reading a default menu created a write lock file.');
        $reject(static fn() => $fresh()->saveNavigationMenu('header', [], 0, ['id' => 'missing', 'email' => 'missing@example.test']),
            'A nonexistent owner initialized menu storage.', 403);
        $assert(!is_file($file) && !is_file($file . '.lock'), 'An unauthorized first write initialized file storage.');
    }

    $email = 'menu-' . bin2hex(random_bytes(6)) . '@example.test';
    $fresh()->createAdmin('Menu acceptance owner', $email, 'MenuFixturePassword12');
    $owner = $fresh()->findAdminByEmail($email);
    $assert(is_array($owner), 'The reserved menu test owner was not created.');
    $fresh()->setSetting('menu_acceptance_unrelated', ['retained' => 'unchanged', 'nested' => [1, 2, 3]]);
    $node = static fn(string $id, string $label, string $url, string $target = 'self', bool $enabled = true, array $children = []): array
        => compact('id', 'label', 'url', 'target', 'enabled', 'children');
    $items = [
        $node('start', 'Home & <text>', '/'),
        $node('explore', 'Explore', '', 'self', true, [
            $node('media', 'Media', 'music/?sort=latest#tracks', 'self', true, [
                $node('photo-tab', 'Photos — café', 'photos/', 'tab'),
            ]),
            $node('window', 'Example window', 'https://example.test/window', 'window'),
        ]),
        $node('hidden', 'Hidden section', 'videos/', 'self', false, [$node('hidden-child', 'Also hidden', 'booking/')]),
    ];
    $saved = $fresh()->saveNavigationMenu('header', $items, 0, $owner);
    $assert($saved === ['revision' => 1, 'configured' => true, 'items' => $items]
        && $fresh()->navigationMenu('header') === $saved, 'Nested items, targets, order or explicit visibility did not persist exactly.');
    if ($database !== null) {
        $query = $database->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $query->execute(['navigation_menu_header']);
        $record = json_decode((string) $query->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
        $assert($record === ['schema' => 1, 'revision' => 1, 'configured' => true, 'items' => $items],
            'The independent MySQL connection did not see the committed menu record.');
        $query = $database->prepare("SELECT context_json FROM audit_log WHERE admin_id=? AND action_key='site.menu.saved' ORDER BY id DESC LIMIT 1");
        $query->execute([$owner['id']]);
        $assert(json_decode((string) $query->fetchColumn(), true, 32, JSON_THROW_ON_ERROR)
            === ['location' => 'header', 'revision' => 1, 'items' => 7], 'The atomic SQL audit did not commit with the saved menu.');
    }
    $assert(array_column(MenuService::publicItems($saved), 'id') === ['start', 'explore'], 'A hidden parent or its descendants reached the public menu.');
    $onlyHidden = [$node('group', 'Empty visible group', '', 'self', true, [$node('child', 'Hidden child', '/', 'self', false)])];
    $assert(MenuService::publicItems(['items' => $onlyHidden]) === [], 'A destination-free group with no visible children reached the public menu.');
    $assert($fresh()->navigationMenu('footer')['revision'] === 0, 'Saving Header changed the independent Footer revision.');
    $assert(MenuService::parseItems(json_encode($items, JSON_THROW_ON_ERROR)) === $items, 'The real form JSON changed valid nested item data.');

    $invalidItems = [];
    foreach (['javascript:alert(1)', 'data:text/html,test', '//example.test', '%2f%2fexample.test', '../admin/', '%2e%2e/admin/',
        'https://user:pass@example.test/', "https://example.test/\n", '/x%0aheader', '\\example.test', 'mailto:owner@example.test'] as $url) {
        $bad = $items; $bad[0]['url'] = $url; $invalidItems[] = $bad;
    }
    foreach (['label' => ['', str_repeat('界', 81), "bad\0label", ['nested']],
        'url' => [str_repeat('x', 2049), ['nested']], 'target' => ['_blank', '', ['tab']],
        'enabled' => ['false', 0, null], 'id' => ['', 'space id', str_repeat('x', 81), ['nested']],
        'children' => [null, ['not-a-list' => []]]] as $key => $values) {
        foreach ($values as $value) { $bad = $items; $bad[0][$key] = $value; $invalidItems[] = $bad; }
    }
    $bad = $items; $bad[1]['children'][0]['id'] = 'start'; $invalidItems[] = $bad;
    $bad = $items; $bad[0]['unexpected'] = true; $invalidItems[] = $bad;
    $bad = $items; unset($bad[0]['target']); $invalidItems[] = $bad;
    $bad = $items; $bad[0]['url'] = ''; $invalidItems[] = $bad;
    $bad = $items; $bad[1]['target'] = 'tab'; $invalidItems[] = $bad;
    $bad = $items; $bad[1]['children'][0]['children'][0]['children'] = [$node('fourth', 'Too deep', '/')]; $invalidItems[] = $bad;
    $invalidItems[] = array_map(static fn(int $number): array => $node('item-' . $number, 'Item ' . $number, '/'), range(1, 51));
    $invalidItems[] = ['header' => $items[0]];
    foreach ($invalidItems as $index => $bad) {
        $before = $state();
        $reject(static fn() => $fresh()->saveNavigationMenu('header', $bad, 1, $owner), 'Invalid menu edit was accepted (case ' . $index . ').');
        $assert($state() === $before, 'Rejected menu edit changed committed data or audit (case ' . $index . ').');
        $assert(MenuService::publicItems(['items' => $bad]) === [], 'An invalid imported tree was rendered (case ' . $index . ').');
    }
    foreach (['{broken', '{}', 'null', '"text"', '[[]]', '[{"children":{}}]', str_repeat(' ', MenuService::MAX_JSON_BYTES + 1)] as $json) {
        $reject(static fn() => MenuService::parseItems($json), 'A malformed/oversized JSON tree was accepted.');
    }
    foreach ([-1, 1.0, null, [], '', '00', '01', '1e2', ' 1', '1 ', '9007199254740992', PHP_INT_MAX] as $revision) {
        $reject(static fn() => MenuService::revision($revision), 'A malformed or unsafe JavaScript revision was accepted.');
    }
    $assert(MenuService::revision('0') === 0 && MenuService::revision('9007199254740991') === MenuService::MAX_REVISION,
        'The revision boundaries do not round-trip safely.');
    $assert(MenuService::validateItems([$node('unicode', str_repeat('界', 80), '#top')])[0]['label'] === str_repeat('界', 80),
        'A valid 80-character UTF-8 label was rejected.');

    $before = $state();
    $reject(static fn() => $fresh()->saveNavigationMenu('header', [], 0, $owner), 'A stale save overwrote the current menu.', 409);
    $reject(static fn() => $fresh()->resetNavigationMenu('header', 0, $owner), 'A stale reset overwrote the current menu.', 409);
    $reject(static fn() => $fresh()->saveNavigationMenu('sidebar', [], 0, $owner), 'An unsupported menu location was written.');
    $reject(static fn() => $fresh()->saveNavigationMenu('header', [], 1, ['id' => $owner['id'], 'email' => 'someone@example.test', 'role' => 'owner']),
        'A mismatched owner email was trusted.', 403);
    $reject(static fn() => $fresh()->saveNavigationMenu('header', [], 1, ['id' => 'missing', 'email' => $email, 'role' => 'owner']),
        'A mismatched owner identity was trusted.', 403);
    $assert($state() === $before, 'Rejected stale/location/identity operations changed data or audit.');

    $changeOwner = static function (string $role, int $active) use ($database, $fresh, $owner): void {
        if ($database !== null) $database->prepare('UPDATE admins SET role=?,is_active=? WHERE id=?')->execute([$role, $active, $owner['id']]);
        else $fresh()->mutateFileStore(static function (array &$data) use ($owner, $role, $active): void {
            $data['admins'][(string) $owner['id']]['role'] = $role;
            $data['admins'][(string) $owner['id']]['is_active'] = $active;
        });
    };
    foreach ([['editor', 1], ['owner', 0]] as [$role, $active]) {
        $changeOwner($role, $active);
        $before = $state();
        $reject(static fn() => $fresh()->saveNavigationMenu('header', [], 1, $owner), 'A stale logged-in owner could save after demotion/deactivation.', 403);
        $reject(static fn() => $fresh()->resetNavigationMenu('header', 1, $owner), 'A stale logged-in owner could reset after demotion/deactivation.', 403);
        $assert($state() === $before, 'A rejected inactive owner mutation changed data or audit.');
    }
    $changeOwner('owner', 1);
    $assert((string) $fresh()->requireNavigationOwner(array_replace($owner, ['email' => strtoupper($email)]))['id'] === (string) $owner['id'],
        'The authenticated owner email comparison became case-sensitive.');

    $earlierStore = $fresh();
    $earlierStore->setting('menu_acceptance_unrelated');
    $fresh()->setSetting('menu_acceptance_after_read', 'Retain a concurrent unrelated change');
    $empty = $earlierStore->saveNavigationMenu('header', [], 1, $owner);
    $assert($empty === ['revision' => 2, 'configured' => true, 'items' => []]
        && $fresh()->navigationMenu('header')['items'] === [], 'An explicitly cleared Header fell back to defaults.');
    $assert($fresh()->setting('menu_acceptance_after_read') === 'Retain a concurrent unrelated change',
        'A menu edit reverted a separate setting saved after an earlier read.');
    $footerItems = [$node('footer-custom', 'Custom Footer', 'about/', 'tab')];
    $fresh()->saveNavigationMenu('footer', $footerItems, 0, $owner);
    $assert($fresh()->navigationMenu('footer')['items'] === $footerItems
        && $fresh()->navigationMenu('footer', 'home')['items'] === $footerItems,
        'A custom Footer was not shared across public page contexts.');
    $reset = $fresh()->resetNavigationMenu('header', 2, $owner);
    $assert($reset['revision'] === 3 && !$reset['configured'] && $reset['items'] === MenuService::defaults('header'),
        'Restore defaults did not advance the location revision and return the original Header.');
    $before = $state();
    $reject(static fn() => $fresh()->saveNavigationMenu('header', $items, 2, $owner), 'A pre-reset edit overwrote the reset tombstone.', 409);
    $assert($state() === $before, 'A stale pre-reset edit changed committed storage.');
    $footerReset = $fresh()->resetNavigationMenu('footer', 1, $owner);
    $assert($footerReset['revision'] === 2 && !$footerReset['configured']
        && $fresh()->navigationMenu('footer', 'home')['items'] === MenuService::defaults('footer', 'home'),
        'Restoring Footer defaults lost its page-specific legacy behavior.');

    // An invalid stored record is an error, never permission to replace it with defaults.
    $record = $fresh()->setting('navigation_menu_header');
    foreach ([null, 'bad', ['schema' => 2, 'revision' => 3, 'configured' => true, 'items' => []],
        ['schema' => 1, 'revision' => 3, 'configured' => false, 'items' => $items]] as $corrupt) {
        $fresh()->setSetting('navigation_menu_header', $corrupt);
        $before = $state();
        $reject(static fn() => $fresh()->navigationMenu('header'), 'A corrupt saved record silently became defaults.');
        $reject(static fn() => $fresh()->saveNavigationMenu('header', [], 3, $owner), 'A mutation overwrote a corrupt saved record.');
        $assert($state() === $before, 'Reading/rejecting corrupt menu data changed its original bytes or audit.');
        $fresh()->setSetting('navigation_menu_header', $record);
    }
    $assert($fresh()->setting('menu_acceptance_unrelated') === ['retained' => 'unchanged', 'nested' => [1, 2, 3]],
        'Menu operations altered unrelated settings.');
    $before = $state();
    $fresh()->navigationMenu('header'); $fresh()->navigationMenu('footer', 'home');
    $assert($state() === $before, 'A later public menu read wrote storage.');
    if ($database === null) {
        $lock = fopen($file . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Unable to hold the real file-store lock.');
        try {
            $reject(static fn() => $fresh()->saveNavigationMenu('header', $items, 3, $owner), 'A menu write bypassed the common sidecar lock.');
            $assert($state() === $before, 'A locked menu mutation changed saved data.');
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        $assert((glob($file . '.tmp.*') ?: []) === [], 'Menu writes left incomplete activation files.');
    }
    return $checks;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $source = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir() . '/vvx-menu-state-' . bin2hex(random_bytes(6));
    mkdir($temporary . '/storage', 0755, true);
    define('ROOT_PATH', $temporary);
    spl_autoload_register(static function (string $class) use ($source): void {
        if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    });
    require_once $source . '/app/Support/functions.php';
    date_default_timezone_set('UTC');
    try {
        $config = new Config($temporary . '/config/app.php');
        $config->write(['storage_mode' => 'file', 'file_store_path' => 'storage/data.json', 'timezone' => 'UTC',
            'base_url' => 'http://127.0.0.1:8765', 'mail' => ['mode' => 'disabled'],
            'security' => ['app_key' => bin2hex(random_bytes(32))]]);
        $checks = vvx_menu_acceptance($config);
        echo "Navigation menu file acceptance passed ({$checks} assertions; defaults, nested edits, validation, stale revisions, owner checks and atomic persistence).\n";
    } finally {
        $remove = static function (string $path) use (&$remove): void {
            if (is_file($path) || is_link($path)) { unlink($path); return; }
            foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
            rmdir($path);
        };
        $remove($temporary);
    }
}
