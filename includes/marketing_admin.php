<?php
declare(strict_types=1);
function mm_slug(string $value): string { $value=strtolower(trim($value)); $value=preg_replace('/[^a-z0-9]+/','-',$value)??''; return trim($value,'-'); }

function mm_form_text(mixed $value, int $maximum = 100000): string
{
    return is_string($value) ? substr($value, 0, $maximum) : '';
}

function mm_checked(mixed $value): bool
{
    if ($value === null || $value === '') return false;
    if ($value !== '1') throw new InvalidArgumentException('A form option is invalid. Reload and try again.');
    return true;
}

function mm_publication_meta(string $collection): array
{
    return match (\Velvet\Core\MarketingPublication::collection($collection)) {
        'posts' => ['tab' => 'posts', 'action' => 'save_post', 'edit' => 'edit_post', 'name' => 'Post', 'plural' => 'Posts', 'button' => 'Save Post'],
        'banners' => ['tab' => 'banners', 'action' => 'save_banner', 'edit' => 'edit_banner', 'name' => 'Promotion Banner', 'plural' => 'Saved banners', 'button' => 'Save Banner'],
        'landing_pages' => ['tab' => 'campaigns', 'action' => 'save_page', 'edit' => 'edit_page', 'name' => 'Campaign Landing Page', 'plural' => 'Saved landing pages', 'button' => 'Save Page'],
    };
}

function mm_publication_input(string $collection, array $post): array
{
    $allowed = match ($collection) {
        'posts' => ['title', 'slug', 'category', 'excerpt', 'body', 'status', 'publish_at', 'expires_at'],
        'banners' => ['text', 'url', 'starts_at', 'ends_at'],
        'landing_pages' => ['title', 'slug', 'eyebrow', 'excerpt', 'body', 'cta_label', 'cta_url', 'starts_at', 'ends_at'],
        default => throw new InvalidArgumentException('Choose a valid publication.'),
    };
    $input = array_intersect_key($post, array_flip($allowed));
    if ($collection !== 'banners') {
        foreach (['featured_asset_id', 'featured_alt', 'seo_title', 'seo_description'] as $key) {
            if (array_key_exists($key, $post)) $input[$key] = $post[$key];
        }
    }
    if ($collection === 'posts') {
        if (array_key_exists('tags', $post)) $input['tags'] = \Velvet\Core\MarketingMetadata::tagsFromText($post['tags']);
        if (array_key_exists('author_name', $post)) $input['author_name'] = $post['author_name'];
        if (array_key_exists('related_post_ids', $post)) {
            $related = $post['related_post_ids'];
            if (!is_array($related) || !array_is_list($related) || count($related) > \Velvet\Core\MarketingMetadata::MAX_RELATED
                || array_filter($related, static fn(mixed $id): bool => !is_string($id)) !== []) {
                throw new InvalidArgumentException('Choose at most six related posts in order.');
            }
            $input['related_post_ids'] = \Velvet\Core\MarketingMetadata::relatedIds(array_values(array_filter($related, static fn(string $id): bool => $id !== '')));
        }
    }
    $input['id'] = $post['id'] ?? '';
    $input['expected_revision'] = $post['publication_revision'] ?? null;
    if ($collection !== 'posts') $input['enabled'] = mm_checked($post['enabled'] ?? null);
    if (array_key_exists('access_mode', $post) || array_key_exists('access_plan_ids', $post)) {
        $input['access'] = mm_publication_access_input($post);
    }
    return $input;
}

function mm_publication_access_input(array $post): array
{
    $mode = $post['access_mode'] ?? null;
    $plans = $post['access_plan_ids'] ?? [];
    if (!is_array($plans) || !array_is_list($plans) || count($plans) > 100
        || array_filter($plans, static fn(mixed $id): bool => !is_string($id)) !== []) {
        throw new InvalidArgumentException('Choose the membership tiers again before saving.');
    }
    return \Velvet\Core\MarketingAccess::validate(['version' => 1, 'mode' => $mode,
        'plan_ids' => $mode === 'tiers' ? $plans : []]);
}

/** Private owner choices; public renderers resolve the selected asset afresh. */
function mm_publication_metadata_catalog(): array
{
    try {
        return ['images' => \Velvet\Core\MarketingMetadata::imageCatalog(ROOT_PATH), 'problem' => ''];
    } catch (Throwable) {
        return ['images' => [], 'problem' => 'The saved image catalogue could not be checked. Existing image selections stay saved; choose an available image before changing one.'];
    }
}

/** Rechecked inside the publication lock; safe withdrawals need no policy repair. */
function mm_publication_authorize(array $record, bool $validateAccess = true): void
{
    if (!velvet_marketing_is_owner()) throw new RuntimeException('Only the active site owner can change publications.', 403);
    if (!$validateAccess) return;
    $policy = \Velvet\Core\MarketingAccess::policy($record);
    if ($policy['mode'] === 'tiers') {
        \Velvet\Core\MarketingAccess::validateAvailable($policy,
            \Velvet\Core\MarketingAccess::availablePlanIds(new \App\Core\Store(new \App\Core\Config(app_config()->path()))));
        // Catalogue queries can wait independently of the marketing data lock.
        // Do not save or enable after the owner was demoted during that wait.
        if (!velvet_marketing_is_owner()) throw new RuntimeException('Only the active site owner can change publications.', 403);
    }
}

function mm_publication_access_catalog(): array
{
    try {
        $store = new \App\Core\Store(new \App\Core\Config(app_config()->path()));
        $available = \Velvet\Core\MarketingAccess::availablePlanIds($store);
        $plans = [];
        foreach ($store->membershipPlans(false) as $plan) {
            $id = $plan['id'] ?? null;
            if (!is_string($id) && !is_int($id)) continue;
            $id = (string) $id;
            try { \Velvet\Core\MarketingAccess::validate(['version' => 1, 'mode' => 'tiers', 'plan_ids' => [$id]]); }
            catch (InvalidArgumentException) { continue; }
            $name = mm_form_text($plan['name'] ?? '', 500);
            $plans[] = ['id' => $id, 'name' => $name !== '' ? $name : 'Membership tier',
                'available' => in_array($id, $available, true)];
        }
        return ['plans' => $plans, 'available_plan_ids' => $available, 'problem' => ''];
    } catch (Throwable) {
        return ['plans' => [], 'available_plan_ids' => [],
            'problem' => 'Membership tiers could not be checked. Selected-tier access stays unavailable until they can be verified.'];
    }
}

function mm_publication_access_summary(array $access, array $catalog): string
{
    if ($access['mode'] === 'public') return 'Public';
    if ($access['mode'] === 'members') return 'Members';
    if ($access['mode'] !== 'tiers') return 'Access needs review';
    $names = [];
    foreach ($access['plan_ids'] as $id) {
        $label = 'Unavailable saved tier';
        foreach ($catalog['plans'] as $plan) {
            if ($plan['id'] === $id) { $label = $plan['name'] . ($plan['available'] ? '' : ' (unavailable)'); break; }
        }
        $names[] = $label;
    }
    return 'Selected tiers' . ($names !== [] ? ': ' . implode(', ', $names) : '');
}

function mm_publication_preview_url(string $collection, string $id): string
{
    \Velvet\Core\MarketingPublication::collection($collection);
    \Velvet\Core\MarketingPublication::id($id);
    return base_url('admin/?page=marketing-preview&collection=' . rawurlencode($collection) . '&id=' . rawurlencode($id));
}

function mm_publication_history_url(string $collection, string $id, ?string $version = null): string
{
    \Velvet\Core\MarketingPublication::collection($collection);
    \Velvet\Core\MarketingPublication::id($id);
    if ($version !== null && preg_match('/\A[a-f0-9]{32}\z/D', $version) !== 1) {
        throw new InvalidArgumentException('Choose a saved version.');
    }
    return base_url('admin/?page=marketing-history&collection=' . rawurlencode($collection) . '&id=' . rawurlencode($id)
        . ($version !== null ? '&version=' . rawurlencode($version) : ''));
}

function mm_audience_rules(array $post): array
{
    return \Velvet\Core\MarketingAudience::rules([
        'version' => 1,
        'sources' => \Velvet\Core\MarketingAudience::sourcesFromText($post['sources'] ?? ''),
        'confirmed_after' => \Velvet\Core\MarketingAudience::formDate($post['confirmed_after'] ?? ''),
        'confirmed_before' => \Velvet\Core\MarketingAudience::formDate($post['confirmed_before'] ?? ''),
    ]);
}

function mm_audience_option(array $selection): string
{
    $selection = \Velvet\Core\MarketingAudience::selection($selection);
    return $selection['mode'] === 'all' ? 'all' : $selection['segment_id'] . ':' . $selection['segment_revision'];
}

function mm_campaign_selection(array $post): array
{
    $id = \Velvet\Core\MarketingAudience::id($post['id'] ?? '', true);
    if (!array_key_exists('audience_selection', $post)) {
        if ($id !== '') throw new InvalidArgumentException('Choose the campaign audience again before saving this draft.');
        return ['mode' => 'all'];
    }
    return \Velvet\Core\MarketingAudience::selectionFromForm($post['audience_selection']);
}

function mm_audience_summary(array $rules): string
{
    $rules = \Velvet\Core\MarketingAudience::rules($rules);
    $parts = [$rules['sources'] === [] ? 'Any saved signup source' : 'Signup source: ' . implode(', ', $rules['sources'])];
    if ($rules['confirmed_after'] !== null) $parts[] = 'confirmed on or after ' . str_replace('T', ' ', \Velvet\Core\MarketingAudience::dateForForm($rules['confirmed_after']));
    if ($rules['confirmed_before'] !== null) $parts[] = 'confirmed before ' . str_replace('T', ' ', \Velvet\Core\MarketingAudience::dateForForm($rules['confirmed_before']));
    return implode(' · ', $parts);
}
