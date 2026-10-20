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
