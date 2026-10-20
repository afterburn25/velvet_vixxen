<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class MerchandiseService
{
    public function __construct(private readonly Store $store) {}

    /** Detect catalog edits or sales that occurred after an owner opened the form. */
    public static function revision(array $product): string
    {
        $catalog = array_intersect_key($product, array_flip(['id', 'slug', 'title', 'description', 'price', 'currency', 'status', 'requiresShipping', 'shippingPrice', 'imageIds', 'sortOrder', 'deletedAt']));
        $variants = array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['id', 'label', 'sku', 'options', 'price', 'stock', 'isEnabled', 'sortOrder'])), (array) ($product['variants'] ?? []));
        return hash('sha256', json_encode([$catalog, $variants], JSON_THROW_ON_ERROR));
    }

    public function quoteItem(array $item): array
    {
        $product = $this->store->merchandiseProduct((string) ($item['id'] ?? ''), true);
        if (!$product) throw new RuntimeException('This product is no longer available.');
        $variantId = (string) ($item['variantId'] ?? '');
        $variant = null;
        foreach ($product['variants'] as $candidate) if ((string) $candidate['id'] === $variantId) $variant = $candidate;
        if (!$variant) throw new RuntimeException('Choose an available product option.');
        $quantity = filter_var($item['quantity'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
        if ($quantity === false) throw new RuntimeException('Choose a quantity from one to 99.');
        if ((int) $variant['stock'] < $quantity) throw new RuntimeException((string) $product['title'] . ' — ' . (string) $variant['label'] . ' does not have enough stock.');
        $price = $variant['price'] !== null ? (float) $variant['price'] : (float) $product['price'];
        return [
            'key' => 'merchandise:' . $product['id'] . ':' . $variantId, 'itemType' => 'merchandise', 'itemId' => (string) $product['id'],
            'mode' => 'purchase', 'title' => (string) $product['title'] . ' — ' . (string) $variant['label'],
            'quantity' => $quantity, 'unitPrice' => $price, 'currency' => (string) $product['currency'],
            'couponEligible' => true, 'scope' => 'merchandise',
            'metadata' => [
                'purchaseMode' => 'merchandise', 'productTitle' => $product['title'], 'productSlug' => $product['slug'],
                'variantId' => $variantId, 'variantLabel' => $variant['label'], 'sku' => $variant['sku'], 'options' => $variant['options'],
                'imageId' => $product['imageIds'][0] ?? null, 'requiresShipping' => (bool) $product['requiresShipping'],
                'shippingPrice' => (float) $product['shippingPrice'],
            ],
        ];
    }

    /** Validate a private shipping snapshot before creating an order or charging. */
    public static function shippingAddress(array $input): array
    {
        $fields = ['name' => 120, 'address1' => 190, 'address2' => 190, 'city' => 100, 'region' => 100, 'postalCode' => 30, 'country' => 2];
        $result = [];
        foreach ($fields as $name => $limit) {
            $value = $input[$name] ?? '';
            if (!is_scalar($value)) throw new RuntimeException('Enter a valid shipping address.');
            $value = trim((string) $value);
            if (($name !== 'address2' && $value === '') || mb_strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new RuntimeException('Complete every required shipping field using valid text.');
            $result[$name] = $value;
        }
        $result['country'] = strtoupper($result['country']);
        if (!preg_match('/^[A-Z]{2}$/', $result['country'])) throw new RuntimeException('Choose the two-letter shipping country code.');
        return $result;
    }

    /** Translate the owner form into the validated catalog API. */
    public function saveFromForm(array $post, array $files): array
    {
        $images = array_values(array_filter(array_map('strval', (array) ($post['image_ids'] ?? []))));
        $uploads = (array) ($files['product_images'] ?? []);
        if (isset($uploads['name']) && is_array($uploads['name'])) {
            if (count($uploads['name']) + count($images) > 12) throw new RuntimeException('Use at most twelve product images.');
            foreach (array_keys($uploads['name']) as $index) {
                if ((int) ($uploads['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $file = [];
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $field) $file[$field] = $uploads[$field][$index] ?? null;
                $asset = $this->store->addMedia((new MediaUploadService())->upload($file, 'image', false));
                $images[] = (string) $asset['id'];
            }
        }
        $variants = [];
        foreach ((array) ($post['variants'] ?? []) as $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid variant form.');
            $options = [];
            foreach (preg_split('/[;\r\n]+/', (string) ($row['option_text'] ?? '')) ?: [] as $line) {
                if (trim($line) === '') continue;
                $pair = explode(':', $line, 2);
                if (count($pair) !== 2) throw new RuntimeException('Write options as Size: M; Color: Rose.');
                $options[trim($pair[0])] = trim($pair[1]);
            }
            $variants[] = [
                'id' => (string) ($row['id'] ?? ''), 'label' => (string) ($row['label'] ?? ''), 'sku' => (string) ($row['sku'] ?? ''),
                'options' => $options, 'price' => $row['price'] ?? '', 'stock' => $row['stock'] ?? '', 'isEnabled' => isset($row['enabled']),
            ];
        }
        return $this->store->saveMerchandiseProduct([
            'id' => (string) ($post['product_id'] ?? ''), 'title' => (string) ($post['title'] ?? ''),
            'revision' => is_string($post['product_revision'] ?? null) ? $post['product_revision'] : '',
            'slug' => trim((string) ($post['slug'] ?? '')) ?: (string) ($post['title'] ?? ''),
            'description' => (string) ($post['description'] ?? ''), 'price' => $post['price'] ?? '',
            'currency' => (string) ($post['currency'] ?? 'USD'), 'status' => (string) ($post['status'] ?? 'draft'),
            'requiresShipping' => isset($post['requires_shipping']), 'shippingPrice' => $post['shipping_price'] ?? 0,
            'imageIds' => $images, 'sortOrder' => $post['sort_order'] ?? 100, 'variants' => $variants,
        ]);
    }
}
