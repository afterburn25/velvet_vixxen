<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class AboutPage
{
    public function __construct(private readonly Store $store) {}

    public function details(): array
    {
        return array_replace([
            'title' => 'About Me',
            'biography' => 'Welcome to my world. Explore my music, latest releases, and personal updates, or visit Booking for a professional inquiry.',
            'statistics' => array_map(static fn(string $label): array => ['label' => $label, 'value' => ''], ['Height', 'Measurements', 'Hair', 'Eyes', 'Location', 'Availability']),
            'imageId' => '', 'imageFile' => '', 'imageAlt' => '',
            'ctaLabel' => 'Booking & Appearances', 'ctaUrl' => '/booking/',
        ], (array) $this->store->setting('about_page', []));
    }

    public function images(): array
    {
        return array_values(array_filter($this->store->media(), static function (array $asset): bool {
            $file = (string) ($asset['file'] ?? '');
            if (!empty($asset['private']) || str_starts_with($file, 'private:') || str_starts_with($file, 'storage/') || str_contains($file, '..')) return false;
            return preg_match('/\.(?:png|jpe?g|webp)$/i', (string) ($asset['file'] ?? '')) === 1
                && (int) ($asset['width'] ?? 0) > 0 && (int) ($asset['height'] ?? 0) > 0;
        }));
    }

    public function save(array $input): array
    {
        $text = static function (mixed $value, int $limit): string {
            if (!is_string($value) || mb_strlen($value) > $limit) throw new InvalidArgumentException('A text value is missing or too long.');
            return trim(str_replace("\0", '', $value));
        };
        $title = $text($input['title'] ?? '', 120);
        if ($title === '') throw new InvalidArgumentException('Enter a page title.');
        $rows = $input['statistics'] ?? [];
        if (!is_array($rows) || count($rows) > 40) throw new InvalidArgumentException('Use no more than 40 statistics.');
        $statistics = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Invalid statistic row.');
            $label = $text($row['label'] ?? '', 80);
            $value = $text($row['value'] ?? '', 180);
            if ($label === '' && $value !== '') throw new InvalidArgumentException('Give every filled statistic a label.');
            if ($label !== '' || $value !== '') $statistics[] = compact('label', 'value');
        }
        $url = $text($input['ctaUrl'] ?? '', 1500);
        if ($url !== '' && (!preg_match('#^/(?!/)[^\\\\\r\n]*$#', $url)
            && !(filter_var($url, FILTER_VALIDATE_URL) && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'))) {
            throw new InvalidArgumentException('Use a site path beginning with / or a complete https:// link.');
        }
        $imageId = $text($input['imageId'] ?? '', 120);
        $imageFile = '';
        if ($imageId !== '') {
            foreach ($this->images() as $image) if ((string) $image['id'] === $imageId) $imageFile = (string) $image['file'];
            if ($imageFile === '') throw new InvalidArgumentException('Choose an available image from the Media Library.');
        }
        $result = [
            'title' => $title, 'biography' => $text($input['biography'] ?? '', 20000), 'statistics' => $statistics,
            'imageId' => $imageId, 'imageFile' => $imageFile, 'imageAlt' => $text($input['imageAlt'] ?? '', 250),
            'ctaLabel' => $text($input['ctaLabel'] ?? '', 80), 'ctaUrl' => $url,
        ];
        $this->store->setSetting('about_page', $result);
        return $result;
    }
}
