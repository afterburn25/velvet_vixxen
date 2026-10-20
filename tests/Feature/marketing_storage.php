<?php
declare(strict_types=1);

// Source regressions for the new storage boundary; actual SQL is covered by the
// independent packaged tests/Database/marketing_sql_mysql.php acceptance gate.
require_once dirname(__DIR__, 2) . '/app/Core/MarketingStore.php';
$root = sys_get_temp_dir() . '/vvx-marketing-storage-source-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
file_put_contents($root . '/.vvx-marketing-storage-fixture', 'Owned disposable storage source fixture.');
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $operation, string $message) use ($assert): void {
    $failure = null;
    try { $operation(); } catch (RuntimeException $error) { $failure = $error; }
    $assert($failure instanceof RuntimeException, $message);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    @rmdir($path);
};
try {
    $storage = new \Velvet\Core\MarketingStorage($root);
    $review = $storage->status();
    $assert($review['state'] === 'file' && $review['mode'] === 'file' && !$review['can_migrate'] && $review['plan'] === '',
        'A file installation was offered a database move without verified MySQL.');
    $assert(!$review['source_preserved'] && !is_dir($root . '/storage') && $storage->status() === $review,
        'Read-only review initialized a private directory or produced unstable defaults.');
    $marketing = new \Velvet\Core\MarketingStore($root);
    $directory = $root . '/storage/private/marketing'; $path = $directory . '/data.json'; $guardPath = $directory . '/sql-cutover.json';
    $assert(is_file($path) && $storage->status()['source_preserved'], 'Ordinary file marketing no longer initializes its protected storage.');
    $legacy = ['meta' => ['revision' => 17, 'extra' => ['retain' => true]],
        'settings' => ['newsletter_signup_enabled' => false, 'unknown_owner_option' => ['enabled' => true]],
        'posts' => ['named-key' => ['id' => 'legacy-post', 'extra' => ['number' => 1.0]], 7 => ['id' => 'sparse-post'], '007' => ['id' => 'string-key-post']],
        'contacts' => [['id' => 'retained-contact', 'status' => 'unsubscribed', 'unsubscribe_token' => 'retained-fixture-token']],
        'extension' => ['unknown_order' => ['z' => null, 'a' => false], 'list' => [1.0, 0, null]]];
    $bytes = json_encode($legacy, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($path, $bytes); touch($path, 1704067200);
    $first = $marketing->all(); $second = $marketing->all();
    $assert($first === $second && $first['meta']['revision'] === 17 && is_string($first['meta']['analytics_salt'])
        && $first['meta']['analytics_salt'] !== '', 'Missing old metadata was randomized between unchanged reads.');
    $assert($first['posts'] === $legacy['posts'] && $first['contacts'] === $legacy['contacts'] && $first['extension'] === $legacy['extension']
        && $first['settings']['unknown_owner_option'] === $legacy['settings']['unknown_owner_option'],
        'Legacy normalization changed entry keys/order, unknown fields, tokens or consent.');
    $status = $storage->status();
    $assert($status['counts']['posts'] === 3 && $status['counts']['contacts'] === 1 && $status['counts']['links'] === 3
        && $status === $storage->status() && file_get_contents($path) === $bytes,
        'Storage review rewrote original JSON or reported incorrect normalized counts.');
    $reject(static fn() => $storage->migrate(str_repeat('a', 64)), 'A file-only site accepted a SQL cutover.');
    $assert(file_get_contents($path) === $bytes && !is_file($guardPath), 'Refusing a file-only cutover changed the source or recorded SQL authority.');
    $marketing->update(static function (array &$data): void { $data['posts'][] = ['id' => 'new-file-post']; });
    $saved = $marketing->all(); $savedBytes = file_get_contents($path);
    $assert($saved['meta']['revision'] === 18 && $saved['meta']['analytics_salt'] === $first['meta']['analytics_salt']
        && $saved['extension'] === $first['extension'] && $saved['posts']['007'] === $first['posts']['007'],
        'A file mutation lost normalized metadata or unknown legacy content.');

    foreach (['{invalid-json', '{"contacts":"invalid-collection"}', '{"segments":["invalid-audience"]}'] as $invalid) {
        file_put_contents($path, $invalid);
        $assert($storage->status()['state'] === 'blocked' && !$storage->status()['can_migrate'], 'Malformed private data was offered as usable storage.');
        $reject(static fn() => $marketing->all(), 'Malformed private data was silently replaced by defaults.');
        $reject(static fn() => $marketing->update(static function (array &$data): void { $data['posts'] = []; }), 'A write overwrote malformed private data.');
        $assert(file_get_contents($path) === $invalid, 'Refusing malformed data changed its original bytes.');
    }
    file_put_contents($path, $savedBytes);
    rename($path, $path . '.owned-original'); symlink($path . '.owned-original', $path);
    try {
        $assert($storage->status()['state'] === 'blocked', 'A private data symlink was offered as valid storage.');
        $reject(static fn() => $marketing->all(), 'Marketing followed a symbolic link to private records.');
        $assert(file_get_contents($path . '.owned-original') === $savedBytes, 'A symbolic-link refusal changed the original fixture.');
    } finally { unlink($path); rename($path . '.owned-original', $path); }
    chmod($path, 0440);
    try {
        $reject(static fn() => $marketing->update(static function (array &$data): void { $data['posts'][] = ['id' => 'read-only-loss']; }),
            'A write replaced storage with explicitly read-only mode bits.');
        $assert(file_get_contents($path) === $savedBytes, 'A refused read-only write changed original bytes.');
    } finally { chmod($path, 0640); }

    $guard = ['version' => 1, 'installation_id' => 'source-fixture-installation', 'storage_id' => str_repeat('a', 32),
        'database_hash' => str_repeat('b', 64), 'source_sha256' => hash('sha256', $savedBytes)];
    $guardBytes = json_encode($guard, JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($guardPath, $guardBytes); chmod($guardPath, 0640);
    foreach (['missing-config', 'file-config', 'incomplete-mysql-config'] as $configuration) {
        if ($configuration !== 'missing-config') {
            if (!is_dir($root . '/config')) mkdir($root . '/config', 0700);
            $config = $configuration === 'file-config' ? ['storage_mode' => 'file'] : ['storage_mode' => 'mysql', 'installed' => false];
            file_put_contents($root . '/config/app.php', '<?php return ' . var_export($config, true) . ';');
        }
        $held = $storage->status();
        $assert($held['state'] === 'blocked' && $held['mode'] === null && !$held['can_migrate'], 'SQL-required ' . $configuration . ' silently selected file mode.');
        $reject(static fn() => $marketing->all(), 'An existing MarketingStore object fell back after SQL intent and configuration loss.');
        $reject(static fn() => $marketing->update(static function (array &$data): void { $data['contacts'] = []; }), 'SQL-required storage wrote the old file without its database.');
        $assert(file_get_contents($path) === $savedBytes && file_get_contents($guardPath) === $guardBytes, 'Held SQL-required state changed the original or its guard.');
    }
    file_put_contents($guardPath, '{invalid SQL-required marker');
    $assert($storage->status()['state'] === 'blocked' && !$storage->status()['can_migrate'], 'A malformed SQL-required marker was ignored.');
    $reject(static fn() => $marketing->all(), 'An invalid local guard allowed file fallback.');
    $assert(file_get_contents($path) === $savedBytes && file_get_contents($guardPath) === '{invalid SQL-required marker', 'Guard refusal modified either source.');
    echo 'Marketing storage source regressions passed (' . $checks . ' checks; read-only review, stable legacy normalization and SQL-required refusal).' . PHP_EOL;
} finally {
    if (!str_starts_with($root, sys_get_temp_dir() . '/vvx-marketing-storage-source-') || !is_file($root . '/.vvx-marketing-storage-fixture')) {
        throw new RuntimeException('Refusing cleanup of an unowned source fixture.');
    }
    $remove($root);
}
