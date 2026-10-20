<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Store;
use Velvet\Core\MarketingAccess as Access;
use Velvet\Core\MarketingMetadata as Metadata;
use Velvet\Core\MarketingPublication as Publication;
use Velvet\Core\MarketingStore;

/** Real small images and deliberately ineligible assets, only in an owned fixture. */
function vvx_metadata_test_assets(Store $store, string $root): array
{
    if (!str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/')
        || (!is_file($root . '/.vvx-test-runtime') && !is_file($root . '/metadata.fixture'))) {
        throw new RuntimeException('A marked disposable metadata fixture is required.');
    }
    foreach (['uploads/images', 'storage/private/media/images'] as $relative) {
        if (!is_dir($root . '/' . $relative)) mkdir($root . '/' . $relative, 0700, true);
    }
    $image = imagecreatetruecolor(96, 64);
    imagefill($image, 0, 0, imagecolorallocate($image, 82, 9, 55));
    imagefilledrectangle($image, 12, 12, 84, 52, imagecolorallocate($image, 232, 47, 147));
    $assets = [];
    foreach (['a', 'b', 'deleted', 'mismatch'] as $name) {
        $path = 'uploads/images/editorial-metadata-' . $name . '.png';
        imagepng($image, $root . '/' . $path);
        $assets[$name] = $store->addMedia(['title' => 'Metadata public image ' . $name, 'type' => 'image',
            'file' => $path, 'thumbnail' => $path, 'mime' => $name === 'mismatch' ? 'image/jpeg' : 'image/png',
            'width' => 96, 'height' => 64, 'private' => false]);
    }
    imagepng($image, $root . '/storage/private/media/images/editorial-metadata-private.png');
    imagedestroy($image);
    $assets['private'] = $store->addMedia(['title' => 'PRIVATE_METADATA_IMAGE_TITLE', 'type' => 'gallery',
        'file' => 'private:images/editorial-metadata-private.png', 'thumbnail' => $assets['a']['file'],
        'mime' => 'image/png', 'width' => 96, 'height' => 64, 'private' => true]);
    file_put_contents($root . '/uploads/images/editorial-metadata.svg', '<svg xmlns="http://www.w3.org/2000/svg"><text>Reserved fixture SVG</text></svg>');
    file_put_contents($root . '/uploads/images/editorial-metadata.txt', 'Reserved non-image metadata fixture.');
    $invalid = [
        'svg' => ['uploads/images/editorial-metadata.svg', 'image/svg+xml'],
        'nonimage' => ['uploads/images/editorial-metadata.txt', 'image/png'],
        'missing' => ['uploads/images/editorial-metadata-missing.png', 'image/png'],
        'external' => ['https://reserved.example.test/metadata.png', 'image/png'],
        'traversal' => ['uploads/images/../../storage/private/media/images/editorial-metadata-private.png', 'image/png'],
        'private-path' => ['storage/private/media/images/editorial-metadata-private.png', 'image/png'],
        'query' => [$assets['a']['file'] . '?signature=RESERVED_PRIVATE_SIGNATURE', 'image/png'],
    ];
    $link = $root . '/uploads/images/editorial-metadata-link.png';
    if (!symlink($root . '/storage/private/media/images/editorial-metadata-private.png', $link)) {
        throw new RuntimeException('The owned image symlink negative control is required.');
    }
    $invalid['symlink'] = ['uploads/images/editorial-metadata-link.png', 'image/png'];
    foreach ($invalid as $name => [$file, $mime]) {
        $assets[$name] = $store->addMedia(['title' => 'Ineligible metadata image ' . $name, 'type' => 'image',
            'file' => $file, 'thumbnail' => $assets['a']['file'], 'mime' => $mime, 'private' => false]);
    }
    return $assets;
}

/** A compact saved record with distinctive values for disclosure checks. */
function vvx_metadata_test_post(string $name, array $changes = []): array
{
    return array_replace(['id' => 'metadata-post-' . $name, 'title' => 'Metadata post ' . $name,
        'slug' => 'metadata-post-' . $name, 'category' => 'Preserved category',
        'excerpt' => 'META_EXCERPT_' . $name, 'body' => 'META_BODY_' . $name,
        'status' => 'published', 'publish_at' => '2020-01-01T00:00:00Z',
        'access' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []],
        'tags' => ['META_TAG_' . $name], 'author_name' => 'META_AUTHOR_' . $name,
        'featured_asset_id' => '', 'featured_alt' => 'META_ALT_' . $name,
        'seo_title' => 'META_SEO_' . $name, 'seo_description' => 'META_DESCRIPTION_' . $name,
        'related_post_ids' => [], 'unknown_metadata' => ['nested' => ['keep' => $name]],
        'created_at' => '2020-01-01T00:00:00Z', 'updated_at' => '2020-01-01T00:00:00Z', 'edit_version' => 4], $changes);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;

$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/vvx-metadata-source-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700) || file_put_contents($root . '/metadata.fixture', 'editorial-metadata-source') === false) {
    throw new RuntimeException('The owned metadata fixture could not be created.');
}
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require_once $source . '/app/Support/functions.php';
require_once $source . '/app/Core/MarketingStore.php';
require_once $source . '/app/Core/MarketingMetadata.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$reject = static function (callable $operation, string $message, ?string $file = null) use ($assert): void {
    $before = $file === null ? null : file_get_contents($file);
    $caught = false;
    try { $operation(); } catch (InvalidArgumentException|RuntimeException) { $caught = true; }
    $assert($caught, $message);
    if ($file !== null) $assert(file_get_contents($file) === $before, $message . ' Refusal changed saved bytes.');
};
$zone = date_default_timezone_get();
try {
    date_default_timezone_set('America/Chicago');
    $expected = ['tags' => ['Studio', 'Café', '旅行'], 'author_name' => 'Nikki & Guests',
        'featured_asset_id' => '', 'featured_alt' => 'A clear image description',
        'seo_title' => 'Saved SEO "title" <text>', 'seo_description' => 'Saved description & more.',
        'related_post_ids' => ['post-b', 'post-a']];
    $input = array_replace($expected, ['tags' => [' Studio ', 'studio', 'Café', 'CAFÉ', '旅行']]);
    $assert(Metadata::fields('posts', $input) == $expected, 'Canonical post metadata did not trim/deduplicate tags while preserving first spelling/order.');
    $assert(Metadata::tagsFromText(' Studio, studio, Café, CAFÉ, 旅行 ') === $expected['tags'], 'The actual comma-separated tags contract differs from canonical tags.');
    $assert(Metadata::fields('posts', []) === [], 'Omitted metadata created replacement values.');
    $assert(Metadata::fields('landing_pages', array_intersect_key($expected, array_flip(['featured_asset_id', 'featured_alt', 'seo_title', 'seo_description'])))
        == array_intersect_key($expected, array_flip(['featured_asset_id', 'featured_alt', 'seo_title', 'seo_description'])), 'Landing page image/SEO metadata differs.');
    foreach ([null, false, 1, ['nested'], new stdClass()] as $bad) {
        $reject(static fn() => Metadata::tagsFromText($bad), 'A nonscalar tags form value was accepted.');
    }
    foreach ([null, false, 'tag', [['nested']], [1], ['bad' => 'tag'], [2 => 'tag'], ["bad\0tag"], ["bad\nline"], ["bad\xFF"]] as $bad) {
        $reject(static fn() => Metadata::fields('posts', ['tags' => $bad]), 'Malformed canonical tags were accepted.');
    }
    $assert(Metadata::fields('posts', ['tags' => array_map(static fn(int $n): string => 'tag-' . $n, range(1, 30))])['tags'][29] === 'tag-30', 'The documented tag-count boundary was rejected.');
    $reject(static fn() => Metadata::fields('posts', ['tags' => array_map(static fn(int $n): string => 'tag-' . $n, range(1, 31))]), 'Too many tags were accepted.');
    $assert(Metadata::fields('posts', ['tags' => [str_repeat('é', 64)]])['tags'] === [str_repeat('é', 64)], 'Unicode tag lengths were incorrectly counted as bytes.');
    $reject(static fn() => Metadata::fields('posts', ['tags' => [str_repeat('é', 65)]]), 'An overlong Unicode tag was accepted.');
    foreach (['author_name' => 120, 'featured_alt' => 240, 'seo_title' => 120, 'seo_description' => 320] as $field => $maximum) {
        $assert(Metadata::fields('posts', [$field => str_repeat('é', $maximum)])[$field] === str_repeat('é', $maximum), 'The documented Unicode boundary failed: ' . $field);
        foreach ([null, false, 7, [], ['nested'], "bad\0value", "bad\nvalue", "bad\xFF", str_repeat('é', $maximum + 1)] as $bad) {
            $reject(static fn() => Metadata::fields('posts', [$field => $bad]), 'Malformed or overlong metadata was accepted: ' . $field);
        }
    }
    foreach ([null, false, 7, [], '../private', 'https://reserved.example.test/x', "image\n", str_repeat('i', 101)] as $bad) {
        $reject(static fn() => Metadata::fields('posts', ['featured_asset_id' => $bad]), 'An unsafe or malformed image identity was accepted.');
    }
    foreach ([null, false, 'one', [['nested']], [1], ['a', 'a'], ['bad' => 'a'], ['../a'], array_map(static fn(int $n): string => 'post-' . $n, range(1, 7))] as $bad) {
        $reject(static fn() => Metadata::fields('posts', ['related_post_ids' => $bad]), 'Malformed, duplicate or excessive related identities were accepted.');
    }
    $assert(Metadata::fields('posts', ['related_post_ids' => ['p1', 'p2', 'p3', 'p4', 'p5', 'p6']])['related_post_ids'] === ['p1', 'p2', 'p3', 'p4', 'p5', 'p6'], 'The six-related-post boundary or selection order changed.');
    $empty = Metadata::describe('posts', []);
    $assert($empty['problems'] === [] && $empty['values']['tags'] === [] && $empty['values']['related_post_ids'] === []
        && $empty['values']['author_name'] === '' && $empty['values']['featured_asset_id'] === '', 'Legacy absence did not remain a safe metadata default.');
    $malformed = Metadata::describe('posts', ['tags' => ['nested' => []], 'author_name' => ['secret'], 'seo_title' => false,
        'featured_asset_id' => '../private', 'related_post_ids' => ['self', 'self']]);
    $assert(count($malformed['problems']) >= 5 && $malformed['values']['tags'] === [] && $malformed['values']['author_name'] === ''
        && $malformed['values']['seo_title'] === '' && $malformed['values']['featured_asset_id'] === '' && $malformed['values']['related_post_ids'] === [],
        'Malformed saved fields were silently exposed instead of reported with safe per-field fallbacks.');

    $config = new Config($root . '/config/app.php');
    $config->write(['storage_mode' => 'file', 'file_store_path' => 'storage/data.json', 'timezone' => 'America/Chicago',
        'base_url' => 'http://127.0.0.1:8765', 'mail' => ['mode' => 'disabled'], 'security' => ['app_key' => bin2hex(random_bytes(32))]]);
    if (!is_dir($root . '/storage')) mkdir($root . '/storage', 0700);
    file_put_contents($root . '/storage/data.json', '{"settings":[],"admins":[],"members":[],"media":[],"membershipPlans":[],"subscriptions":[],"memberSessions":[]}');
    $store = new Store($config);
    $assets = vvx_metadata_test_assets($store, $root);
    foreach (['a', 'b'] as $name) {
        $resolved = Metadata::resolveImage((string) $assets[$name]['id'], $root);
        $assert(is_array($resolved) && $resolved['id'] === (string) $assets[$name]['id'] && $resolved['path'] === $assets[$name]['file']
            && $resolved['width'] === 96 && $resolved['height'] === 64, 'A decoded public image did not resolve without a private signature.');
    }
    mkdir($root . '/images/defaults', 0700, true);
    $legacyImage = imagecreatefrompng($root . '/' . $assets['a']['file']);
    imagejpeg($legacyImage, $root . '/images/defaults/hero.jpg', 90);
    imagedestroy($legacyImage);
    $legacyAsset = ['id' => 'default-hero', 'title' => 'Legacy bundled hero', 'type' => 'background',
        'file' => 'images/defaults/hero.jpg', 'thumbnail' => 'images/defaults/hero.jpg', 'width' => 96, 'height' => 64];
    $assert((Metadata::image($legacyAsset, $root)['path'] ?? null) === 'images/defaults/hero.jpg',
        'A real decoded legacy bundled image with an absent MIME field was rejected.');
    $assert((Metadata::image($legacyAsset + ['mime' => ''], $root)['path'] ?? null) === 'images/defaults/hero.jpg',
        'The empty MIME representation of a decoded SQL legacy image was rejected.');
    foreach ([null, false, [], 'text/plain', 'image/png'] as $badMime) {
        $assert(Metadata::image($legacyAsset + ['mime' => $badMime], $root) === null,
            'The absent-MIME compatibility exception accepted an explicit invalid or mismatched MIME.');
    }
    foreach (array_diff(array_keys($assets), ['a', 'b', 'deleted']) as $name) {
        $assert(Metadata::image($assets[$name], $root) === null && Metadata::resolveImage((string) $assets[$name]['id'], $root) === null,
            'An ineligible public-image negative control resolved: ' . $name);
    }
    $assert(Metadata::image(array_replace($assets['a'], ['private' => true]), $root) === null, 'A private asset with a public thumbnail was eligible.');
    $assert(Metadata::image(array_replace($assets['a'], ['deletedAt' => '2020-01-01T00:00:00Z']), $root) === null, 'A deleted image asset was eligible.');
    $catalog = Metadata::imageCatalog($root);
    $ids = array_column($catalog, 'id');
    $assert(in_array((string) $assets['a']['id'], $ids, true) && in_array((string) $assets['b']['id'], $ids, true), 'The safe public image catalogue omitted eligible originals.');
    foreach (array_diff(array_keys($assets), ['a', 'b', 'deleted']) as $name) $assert(!in_array((string) $assets[$name]['id'], $ids, true), 'The image catalogue exposed an ineligible entry: ' . $name);

    $marketing = new MarketingStore($root);
    $file = $root . '/storage/private/marketing/data.json';
    $main = vvx_metadata_test_post('main');
    $targets = [vvx_metadata_test_post('public-a'), vvx_metadata_test_post('public-b'),
        vvx_metadata_test_post('members', ['access' => ['version' => 1, 'mode' => 'members', 'plan_ids' => []]]),
        vvx_metadata_test_post('gold', ['access' => ['version' => 1, 'mode' => 'tiers', 'plan_ids' => ['gold']]]),
        vvx_metadata_test_post('draft', ['status' => 'draft']), vvx_metadata_test_post('future', ['publish_at' => '2035-01-01T00:00:00Z'])];
    $legacy = ['id' => 'metadata-post-legacy', 'title' => 'Legacy post', 'slug' => 'metadata-post-legacy', 'status' => 'published',
        'body' => 'Legacy exact body', 'publish_at' => '2020-01-01T00:00:00Z', 'legacy_unknown' => ['keep' => true]];
    $marketing->update(static function (array &$data) use ($main, $targets, $legacy): void {
        $data['posts'] = [$legacy, $main, ...$targets];
        $data['retained_metadata_header'] = ['keep' => true, 'unicode' => 'Café'];
        $data['contacts'] = [['id' => 'kept-contact', 'unsubscribe_token' => 'RESERVED_METADATA_TOKEN', 'status' => 'active']];
        $data['campaigns'] = [['id' => 'kept-campaign', 'delivery_snapshot' => ['body' => 'Frozen body']]];
        $data['campaign_queue'] = [['id' => 'kept-queue', 'status' => 'unknown', 'attempts' => 1]];
        $data['consents'] = [['id' => 'kept-consent', 'granted' => true]];
        $data['suppressions'] = [['id' => 'kept-suppression', 'active' => true]];
    });
    $initial = $marketing->all();
    $save = static function (array $updates) use ($marketing): array {
        $current = $marketing->publicationRecord('posts', 'metadata-post-main');
        return $marketing->savePublication('posts', ['id' => $current['id'], 'expected_revision' => $current['revision']] + $updates);
    };
    $ordered = ['metadata-post-gold', 'metadata-post-draft', 'metadata-post-future', 'metadata-post-members', 'metadata-post-public-b', 'metadata-post-public-a'];
    $saved = $save(['tags' => ['Studio', 'Café'], 'author_name' => 'A saved byline', 'featured_asset_id' => (string) $assets['a']['id'],
        'featured_alt' => 'A saved alternative', 'seo_title' => 'Saved SEO', 'seo_description' => 'Saved description', 'related_post_ids' => $ordered]);
    $assert($saved['related_post_ids'] === $ordered && $saved['unknown_metadata'] === $main['unknown_metadata']
        && $saved['id'] === $main['id'] && $saved['slug'] === $main['slug'] && $saved['access'] === $main['access']
        && $saved['publish_at'] === $main['publish_at'] && $saved['created_at'] === $main['created_at'], 'Metadata save lost selection order, stable identity, policy, time or unknown data.');
    $assert(array_column($marketing->all()['posts'], 'id') === array_column($initial['posts'], 'id'), 'Metadata save reordered unrelated saved posts.');
    $stale = $saved['revision'];
    $titleOnly = $save(['title' => 'A title-only edit']);
    foreach (array_keys($expected) as $key) $assert($titleOnly[$key] === $saved[$key], 'An omitted metadata field did not preserve its saved value: ' . $key);
    $reject(static fn() => $marketing->savePublication('posts', ['id' => $saved['id'], 'expected_revision' => $stale, 'seo_title' => 'Stale']), 'A stale metadata save was accepted.', $file);
    foreach ([['metadata-post-main'], ['missing-post'], ['metadata-post-public-a', 'metadata-post-public-a']] as $bad) {
        $reject(static fn() => $save(['related_post_ids' => $bad]), 'A self/missing/duplicate related post was accepted.', $file);
    }
    foreach (['private', 'missing', 'external', 'traversal', 'symlink', 'mismatch'] as $name) {
        $reject(static fn() => $save(['featured_asset_id' => (string) $assets[$name]['id']]), 'An unsafe selected image was saved: ' . $name, $file);
    }
    $current = $marketing->publicationRecord('posts', $main['id']);
    $reject(static fn() => $marketing->savePublication('posts', ['id' => $main['id'], 'expected_revision' => $current['revision'],
        'featured_asset_id' => (string) $assets['b']['id'], 'seo_title' => 'Denied owner'], static function (): void { throw new RuntimeException('Owner no longer active.', 403); }),
        'The locked authorization callback did not deny a metadata save.', $file);
    $guest = Access::guest();
    $member = ['member' => true, 'plan_ids' => [], 'available_plan_ids' => ['gold']];
    $gold = array_replace($member, ['plan_ids' => ['gold']]);
    foreach ([[$guest, ['metadata-post-public-b', 'metadata-post-public-a']], [$member, ['metadata-post-members', 'metadata-post-public-b', 'metadata-post-public-a']],
        [$gold, ['metadata-post-gold', 'metadata-post-members', 'metadata-post-public-b', 'metadata-post-public-a']]] as [$viewer, $ids]) {
        $assert(array_column($marketing->relatedPosts($main['id'], $viewer), 'id') === $ids, 'Related posts did not enforce current access/timing before preserving the selected order.');
    }
    $beforePreview = file_get_contents($file);
    $assert(array_column(MarketingStore::previewRelatedPosts($main['id'], $root), 'id') === $ordered, 'Private saved preview did not retain selected saved draft/future/restricted peers.');
    $assert(file_get_contents($file) === $beforePreview, 'Private related preview changed the saved snapshot.');
    $marketing->update(static function (array &$data): void {
        foreach ($data['posts'] as &$post) if ($post['id'] === 'metadata-post-public-b') $post['status'] = 'draft';
        unset($post);
    });
    $assert(array_column($marketing->relatedPosts($main['id'], $guest), 'id') === ['metadata-post-public-a'], 'A newly unpublished related post remained visible on a fresh read.');
    $save(['access' => ['version' => 1, 'mode' => 'members', 'plan_ids' => []]]);
    $assert($marketing->relatedPosts($main['id'], $guest) === [], 'An inaccessible source disclosed its related selection.');
    $assert(count($marketing->relatedPosts($main['id'], $member)) === 2, 'A qualifying free member lost allowed related posts.');
    rename($root . '/' . $assets['a']['file'], $root . '/' . $assets['a']['file'] . '.held');
    $assert(Metadata::resolveImage((string) $assets['a']['id'], $root) === null, 'A selected image that disappeared was served from stale metadata.');
    symlink($root . '/storage/private/media/images/editorial-metadata-private.png', $root . '/' . $assets['a']['file']);
    $assert(Metadata::resolveImage((string) $assets['a']['id'], $root) === null,
        'Replacing a previously resolved image with a symlink reused a cached eligible path.');
    unlink($root . '/' . $assets['a']['file']);
    rename($root . '/' . $assets['a']['file'] . '.held', $root . '/' . $assets['a']['file']);
    $assert(Metadata::resolveImage((string) $assets['a']['id'], $root) !== null, 'A restored real image did not recover on a fresh resolution.');
    $cleared = $save(['tags' => [], 'author_name' => '', 'featured_asset_id' => '', 'featured_alt' => '', 'seo_title' => '', 'seo_description' => '', 'related_post_ids' => []]);
    $assert($cleared['tags'] === [] && $cleared['related_post_ids'] === [] && $cleared['featured_asset_id'] === '' && $cleared['seo_title'] === '', 'Explicit empty metadata did not clear previous values.');
    $legacyBefore = $marketing->publicationRecord('posts', $legacy['id']);
    $legacyAfter = $marketing->savePublication('posts', ['id' => $legacy['id'], 'expected_revision' => $legacyBefore['revision'], 'title' => 'Legacy title changed']);
    foreach (array_keys($expected) as $key) $assert(!array_key_exists($key, $legacyAfter), 'A legacy title-only edit fabricated a previously absent metadata field: ' . $key);
    $final = $marketing->all();
    $assert(array_diff_key($final, array_flip(['meta', 'posts'])) === array_diff_key($initial, array_flip(['meta', 'posts'])),
        'Metadata actions changed frozen contacts, consent, suppressions, campaigns, queue or unknown header data.');
    $emptyRoot = $root . '/absent-preview';
    mkdir($emptyRoot, 0700);
    $assert(MarketingStore::previewRelatedPosts('absent-post', $emptyRoot) === [] && (scandir($emptyRoot) ?: []) === ['.', '..'],
        'A related preview initialized missing marketing storage.');
    // Remove the actual file after Store/Config construction. The explicit
    // read-only path must remain safe even after an earlier existence check.
    $dataPath = $root . '/storage/data.json';
    $beforeData = file_get_contents($dataPath);
    mkdir($root . '/storage/defaults', 0700);
    file_put_contents($root . '/storage/defaults/data.json', $beforeData);
    $peekStore = new Store($config);
    unlink($dataPath);
    if (is_file($dataPath . '.lock')) unlink($dataPath . '.lock');
    $assert($peekStore->media(true) === [] && $peekStore->mediaAsset((string) $assets['a']['id'], true) === null,
        'Read-only media methods did not safely handle a file removed after Store construction.');
    $assert(!file_exists($dataPath) && !file_exists($dataPath . '.lock'),
        'Read-only media methods initialized a removed file store or its write lock.');
    $digest = new ReflectionProperty(Store::class, 'fileReadDigest');
    $assert($digest->getValue($peekStore) === null, 'Read-only media lookup changed a future write revision.');
    $assert(Metadata::resolveImage((string) $assets['a']['id'], $root) === null,
        'Metadata resolution recreated or reused a removed media store.');
    try { Metadata::imageCatalog($root); } catch (RuntimeException) { /* Owners receive an unavailable catalogue. */ }
    $assert(!file_exists($dataPath) && !file_exists($dataPath . '.lock'),
        'Metadata resolution/catalogue reads initialized a missing store or write lock.');
    $assert($peekStore->media() !== [] && is_file($dataPath) && is_file($dataPath . '.lock'),
        'The ordinary initializing read negative control did not exercise the preserved initialization path.');
    file_put_contents($dataPath, $beforeData);
} finally {
    date_default_timezone_set($zone);
    if (!str_starts_with($root, sys_get_temp_dir() . '/vvx-metadata-source-')
        || file_get_contents($root . '/metadata.fixture') !== 'editorial-metadata-source') {
        throw new RuntimeException('Refusing metadata cleanup without its exact owned marker.');
    }
    $remove = static function (string $path) use (&$remove): void {
        if (is_file($path) || is_link($path)) { unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
        rmdir($path);
    };
    $remove($root);
}
echo "Editorial metadata source acceptance passed ({$checks} assertions; strict saved metadata, safe public images, current related visibility, preservation and locked authorization).\n";
