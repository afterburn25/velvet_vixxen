<?php
declare(strict_types=1);

require_once root_path('includes/marketing.php');
require_once root_path('includes/marketing_admin.php');

function vvx_marketing_recovery_require_owner(): void
{
    if (!is_installed() || !velvet_marketing_is_owner()) {
        throw new RuntimeException('Only the active site owner can manage deleted content.', 403);
    }
}

function vvx_marketing_recovery_request(): array
{
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $collection = $post ? ($_POST['collection'] ?? null) : ($_GET['collection'] ?? 'all');
    if (!is_string($collection)) throw new InvalidArgumentException('Choose a content type.');
    if ($collection === 'all' && !$post) $collection = null;
    else \Velvet\Core\MarketingPublication::collection($collection);
    $id = $post ? ($_POST['recovery_id'] ?? null) : ($_GET['item'] ?? null);
    if ($id !== null) \Velvet\Core\MarketingRecovery::id($id);
    if (($post || $id !== null) && ($collection === null || $id === null)) {
        throw new InvalidArgumentException('Open the deleted item to review it before continuing.');
    }
    return ['collection' => $collection, 'id' => $id];
}

function vvx_marketing_recovery_url(?string $collection = null, ?string $id = null): string
{
    if ($collection !== null) \Velvet\Core\MarketingPublication::collection($collection);
    if ($id !== null) \Velvet\Core\MarketingRecovery::id($id);
    return base_url('admin/?' . http_build_query(array_filter(['page' => 'marketing', 'tab' => 'deleted',
        'collection' => $collection, 'item' => $id], static fn($value) => $value !== null), '', '&', PHP_QUERY_RFC3986));
}

function vvx_marketing_recovery_date(string $value): string
{
    return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('M j, Y · g:i A T');
}

/** Prepare escaped-field values before the final current-owner read, never during output. */
function vvx_marketing_recovery_read(array $request): array
{
    vvx_marketing_recovery_require_owner();
    $review = \Velvet\Core\MarketingStore::previewDeletedPublications($request['collection'], $request['id'], ROOT_PATH);
    $result = ['review' => $review, 'fields' => [], 'problem' => '', 'notices' => [],
        'can_restore' => false, 'history_count' => null, 'title' => '', 'revision' => ''];
    $entry = $review['selected'];
    if ($entry !== null) {
        $collection = $entry['collection']; $record = $entry['record'];
        $result['title'] = \Velvet\Core\MarketingRecovery::summary($entry)['title'];
        $result['revision'] = \Velvet\Core\MarketingRecovery::revision($entry);
        try {
            $record = \Velvet\Core\MarketingRecovery::project($entry);
            $result['history_count'] = count(\Velvet\Core\MarketingHistory::entries($collection, $record));
            $result['can_restore'] = true;
        } catch (Throwable $error) {
            $result['problem'] = 'This saved item needs repair before it can be restored. Its original content remains in Deleted content.';
        }
        $catalog = mm_publication_access_catalog();
        $access = \Velvet\Core\MarketingAccess::describe($record, $catalog['available_plan_ids']);
        if (!$access['valid']) $result['notices'][] = 'The saved audience needs review. Its restrictions will be preserved when you restore; choose available membership tiers in the editor before publishing.';
        $labels = match ($collection) {
            'posts' => ['title' => 'Title', 'slug' => 'Permalink', 'category' => 'Category', 'excerpt' => 'Excerpt',
                'body' => 'Body', 'author_name' => 'Author display name', 'tags' => 'Tags'],
            'banners' => ['text' => 'Banner text', 'url' => 'Link'],
            'landing_pages' => ['title' => 'Title', 'slug' => 'Permalink', 'eyebrow' => 'Eyebrow', 'excerpt' => 'Excerpt',
                'body' => 'Body', 'cta_label' => 'Button label', 'cta_url' => 'Button link'],
        };
        if ($collection !== 'banners') {
            $labels += ['featured_asset_id' => 'Featured image', 'featured_alt' => 'Image description',
                'seo_title' => 'Search title', 'seo_description' => 'Search description'];
        }
        if ($collection === 'posts') $labels['related_post_ids'] = 'Related updates';
        $labels['access'] = 'Who can view this?';
        $labels += $collection === 'posts'
            ? ['status' => 'Status before deletion', 'publish_at' => 'Publish at', 'expires_at' => 'Expires at']
            : ['enabled' => 'Visibility before deletion', 'starts_at' => 'Starts', 'ends_at' => 'Ends'];
        foreach ($labels as $key => $label) {
            $value = $record[$key] ?? '';
            if ($key === 'access') $text = mm_publication_access_summary($access, $catalog);
            elseif ($key === 'enabled') $text = \Velvet\Core\MarketingPublication::enabled($collection, $record) ? 'Enabled' : 'Disabled';
            elseif (in_array($key, ['publish_at', 'expires_at', 'starts_at', 'ends_at'], true)) {
                try {
                    $date = \Velvet\Core\MarketingPublication::dateForForm($value);
                    $text = $date === '' ? ($key === 'expires_at' ? 'No expiry' : 'Open') : str_replace('T', ' ', $date);
                } catch (Throwable) { $text = 'Needs review'; }
            } elseif ($key === 'featured_asset_id') {
                $image = is_string($value) && $value !== '' ? \Velvet\Core\MarketingMetadata::resolveImage($value, ROOT_PATH) : null;
                $text = $value === '' ? 'No featured image' : ($image['title'] ?? 'Unavailable saved image');
                if ($value !== '' && $image === null) $result['notices'][] = 'The saved featured image is unavailable. Its selection will be preserved so you can replace it after restoring.';
            } elseif ($key === 'tags') {
                $text = is_array($value) && array_is_list($value) && count($value) <= 12
                    && count(array_filter($value, 'is_string')) === count($value) ? implode(', ', $value) : 'Needs review';
            } elseif ($key === 'related_post_ids') {
                $related = []; $missing = false;
                if (is_array($value) && array_is_list($value) && count($value) <= 12) {
                    foreach ($value as $id) {
                        if (is_string($id) && isset($review['related_titles'][$id])) $related[] = $review['related_titles'][$id];
                        else { $related[] = 'Unavailable saved update'; $missing = true; }
                    }
                    $text = implode("\n", $related);
                } else { $text = 'Needs review'; $missing = true; }
                if ($missing) $result['notices'][] = 'Some related updates are unavailable. Restore those updates or choose replacements in the editor before publishing.';
            } else $text = mm_form_text($value);
            $result['fields'][$key] = ['label' => $label, 'value' => $text !== '' ? $text : 'Not set'];
        }
    }
    // Saved record, media and tier reads may wait for another writer. No private
    // content or mutation form is rendered after the saved owner is revoked.
    vvx_marketing_recovery_require_owner();
    return $result;
}
