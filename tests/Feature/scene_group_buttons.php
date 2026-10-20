<?php
declare(strict_types=1);

$source = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/vvx-scene-group-test-' . bin2hex(random_bytes(8));
mkdir($fixture . '/config', 0700, true);
mkdir($fixture . '/storage', 0700, true);
file_put_contents($fixture . '/.vvx-test-fixture', 'Disposable scene group acceptance fixture');
define('ROOT_PATH', $fixture);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require_once $file;
    }
});
require $source . '/app/Support/functions.php';
file_put_contents($fixture . '/config/app.php', '<?php return ' . var_export([
    'storage_mode' => 'file', 'file_store_path' => 'storage/data.json', 'base_url' => 'https://example.test/velvet',
], true) . ';');
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $operation, string $message) use ($assert): void {
    try { $operation(); } catch (InvalidArgumentException) { $assert(true, $message); return; }
    $assert(false, $message);
};
$render = static function (array $scene, string $section = 'hero', bool $signedIn = false, bool $trailer = false) use ($source): string {
    $publicScenes = [$section => $scene];
    $sceneButtonSection = $section;
    $member = $signedIn ? ['id' => 'disposable-member'] : null;
    $trailerSrc = $trailer ? 'https://example.test/velvet/disposable-trailer.mp4' : '';
    ob_start();
    require $source . '/app/Views/public/partials/scene-buttons.php';
    return (string) ob_get_clean();
};
$buttonsIn = static function (string $html): DOMNodeList {
    $document = new DOMDocument();
    $document->loadHTML('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NONET);
    return (new DOMXPath($document))->query('//*[@data-content-button-id]');
};

try {
    $store = new App\Core\Store(new App\Core\Config($fixture . '/config/app.php'));
    $photo = ['id' => 'photo-preserved', 'src' => 'models/disposable-cutout.png', 'x' => -28, 'y' => 73, 'width' => 208, 'custom' => ['preserved' => true]];
    $standalone = ['id' => 'independent-link', 'type' => 'button', 'label' => 'Separate booking', 'url' => 'booking/', 'x' => 82, 'width' => 15];
    $legacy = ['title' => 'Legacy group geometry', 'devices' => []];
    foreach (['desktop' => 760, 'tablet' => 900, 'mobile' => 860] as $device => $height) {
        $legacy['devices'][$device] = ['height' => $height, 'layers' => [$photo, $standalone],
            'background' => ['src' => 'images/defaults/hero-pink-room.jpg', 'x' => $height / 20],
            'contentBox' => ['x' => 7, 'y' => $device === 'mobile' ? 45 : 17, 'width' => $device === 'mobile' ? 86 : 36, 'height' => 50],
        ];
    }
    $legacyVersion = $store->saveScene('hero', $legacy, true);
    $legacyPublished = $store->scene('hero', true);
    $assert(!array_key_exists('contentButtons', $legacyPublished), 'Saving an untouched legacy scene must not materialize custom group buttons.');
    foreach (['hero' => 2, 'footer' => 1] as $section => $count) {
        foreach ([false, true] as $signedIn) {
            foreach ([false, true] as $trailer) {
                $html = $render([], $section, $signedIn, $trailer);
                $nodes = $buttonsIn($html);
                $assert($nodes->length === $count, 'Legacy Hero/Footer action count must be preserved.');
                $first = $nodes->item(0);
                $label = $section === 'hero' ? ($signedIn ? 'My Account' : 'Join Now') : ($signedIn ? 'Open My Library' : 'Join My World');
                $assert(str_contains($first->textContent, $label), 'Automatic account wording must preserve member and section context.');
                $assert($first->getAttribute('href') === base_url($signedIn ? 'account/' : 'account/register.php'), 'Automatic account action must retain its actual destination.');
                if ($section === 'hero') {
                    $second = $nodes->item(1);
                    $assert($second->nodeName === ($trailer ? 'button' : 'a'), 'Trailer action must use a real button only when a trailer exists.');
                    $assert(str_contains($second->textContent, $trailer ? 'Watch Trailer' : 'Browse Videos'), 'Trailer fallback wording must remain accurate.');
                    $assert($trailer ? $second->hasAttribute('data-open-trailer') : $second->getAttribute('href') === base_url('videos/'), 'Legacy trailer and Videos fallback must remain wired.');
                }
            }
        }
    }

    $third = ['id' => 'content-third', 'action' => 'link', 'label' => 'Book <now> & visit',
        'url' => 'booking/?ref=hero&vvx_route=videos#inquiry', 'style' => 'glass', 'newTab' => true];
    $scene = $legacyPublished;
    $scene['contentButtons'] = [...App\Core\SceneButton::defaultContentButtons('hero'), $third];
    $store->saveScene('hero', $scene, false);
    $assert(!array_key_exists('contentButtons', $store->scene('hero', true)), 'Adding a button to the draft must leave the published legacy actions unchanged.');
    $saved = $store->scene('hero', false);
    $assert($saved['contentButtons'] === $scene['contentButtons'], 'All three ordered group buttons must survive draft save and reload.');
    foreach (['desktop', 'tablet', 'mobile'] as $device) {
        $assert($saved['devices'][$device] === $legacyPublished['devices'][$device], 'Adding a group action must not alter any existing device layout, photo or standalone button.');
        $assert($saved['devices'][$device]['layers'][0] === $photo, 'Transparent photo data must remain independent.');
    }
    $publishedVersion = $store->saveScene('hero', $saved, true);
    $published = $store->scene('hero', true);
    $assert($published['contentButtons'] === $saved['contentButtons'], 'Publish must retain the same group controls as the draft.');
    $nodes = $buttonsIn($render($published));
    $assert($nodes->length === 3, 'The third group button must be real server-rendered public HTML.');
    $thirdNode = $nodes->item(2);
    $assert($thirdNode->getAttribute('href') === 'https://example.test/velvet/route.php?vvx_route=booking&ref=hero#inquiry', 'The new link must preserve nested installation routing and query/fragment while reserving the dispatcher key.');
    $assert($thirdNode->getAttribute('target') === '_blank' && $thirdNode->getAttribute('rel') === 'noopener noreferrer', 'New-tab group actions must isolate their opener.');
    $assert(str_contains($thirdNode->getAttribute('class'), 'button--glass'), 'Configured group style must reach the public link.');
    $assert(str_contains($thirdNode->textContent, $third['label']), 'Button labels must remain literal text.');
    $assert(!str_contains($render($published), '<now>'), 'Owner-entered markup must be HTML-escaped.');

    $reordered = $published;
    $reordered['contentButtons'] = [$third, $published['contentButtons'][0]];
    $reordered['contentButtons'][0]['label'] = 'Reordered and renamed';
    $store->saveScene('hero', $reordered, false);
    $assert($buttonsIn($render($store->scene('hero', false)))->item(0)->getAttribute('data-content-button-id') === 'content-third', 'Draft preview must reflect remove/reorder without changing button identity.');
    $assert($buttonsIn($render($store->scene('hero', true)))->length === 3, 'A later draft must not remove the published third action.');
    $store->restoreScene('hero', $publishedVersion);
    $assert($store->scene('hero', true)['contentButtons'] === $published['contentButtons'], 'Publication history restore must recover group order, labels, links and styles.');
    $store->restoreScene('hero', $legacyVersion);
    $assert(!array_key_exists('contentButtons', $store->scene('hero', true)), 'Restoring a legacy version must recover the automatic two-button group.');
    $empty = array_replace($scene, ['contentButtons' => []]);
    $store->saveScene('hero', $empty, true);
    $assert($store->scene('hero', true)['contentButtons'] === [] && $buttonsIn($render($empty))->length === 0, 'An explicitly empty group must not silently reintroduce legacy buttons.');

    $store->saveScene('footer', array_replace($scene, ['contentButtons' => [
        ...App\Core\SceneButton::defaultContentButtons('footer'), $third,
    ]]), true);
    $assert($buttonsIn($render($store->scene('footer', true), 'footer'))->length === 2, 'Footer group controls must use the same persistence and renderer.');
    $assert($store->scene('hero', true)['contentButtons'] === [], 'Footer publication must not change Hero buttons.');

    $before = file_get_contents($fixture . '/storage/data.json');
    foreach (['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', '//outside.test', '%2f%2foutside.test',
        'java%73cript:alert(1)', '/%5coutside.test', "https://example.test/path\n", '../outside/', '/%2e%2e/outside/',
        'https://user:password@example.test/', '/bad%escape', '', 'mailto:owner@example.test'] as $url) {
        $bad = $scene;
        $bad['contentButtons'][2]['url'] = $url;
        $reject(fn() => $store->saveScene('hero', $bad, true), 'Unsafe group destination must fail before publication.');
        $assert(file_get_contents($fixture . '/storage/data.json') === $before, 'Invalid group destinations must not change either scene, draft or version history.');
        $assert($buttonsIn($render($bad))->length === 2, 'Unsafe imported group actions must not render on the public page.');
    }
    foreach ([['id' => ''], ['id' => '<bad>'], ['id' => ['forged']], ['label' => ''], ['label' => ['forged']],
        ['label' => str_repeat('x', 121)], ['url' => ['forged']], ['action' => 'execute'], ['style' => 'url(javascript:1)'],
        ['newTab' => 'true'], ['action' => 'account', 'url' => 'booking/']] as $invalid) {
        $bad = $scene;
        $bad['contentButtons'][2] = array_replace($third, $invalid);
        $reject(fn() => $store->saveScene('hero', $bad, true), 'Malformed group button controls must be rejected.');
        $assert(file_get_contents($fixture . '/storage/data.json') === $before, 'Malformed controls must leave all saved scenes intact.');
    }
    foreach ([null, 'not-a-list', ['named' => $third], [$third, $third], [42],
        array_map(static fn(int $index): array => array_replace($third, ['id' => 'content-' . $index]), range(1, 9))] as $invalidList) {
        $reject(fn() => $store->saveScene('hero', array_replace($scene, ['contentButtons' => $invalidList]), true), 'Malformed, duplicate or oversized groups must fail before writing.');
        $assert(file_get_contents($fixture . '/storage/data.json') === $before, 'Rejected button lists must preserve data.');
    }
    $maximum = array_replace($scene, ['contentButtons' => array_map(static fn(int $index): array => array_replace($third, ['id' => 'content-' . $index]), range(1, 8))]);
    $store->saveScene('hero', $maximum, true);
    $assert(count($store->scene('hero', true)['contentButtons']) === 8 && $buttonsIn($render($maximum))->length === 8, 'The advertised eight-button limit must be usable.');
    foreach (['primary', 'glass', 'ghost'] as $style) {
        $custom = array_replace($scene, ['contentButtons' => [array_replace($third, ['style' => $style, 'url' => 'https://outside.example.test/path?x=1#top'])]]);
        $node = $buttonsIn($render($custom))->item(0);
        $assert(str_contains($node->getAttribute('class'), 'button--' . $style), 'Every offered style must render.');
        $assert($node->getAttribute('href') === 'https://outside.example.test/path?x=1#top', 'Complete safe external URLs must remain intact.');
    }
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($fixture);
}
echo "Live text group buttons, legacy actions, safe public links and restorable publication passed: {$checks} assertions.\n";
