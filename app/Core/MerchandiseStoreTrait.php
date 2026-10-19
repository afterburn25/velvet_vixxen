<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

trait MerchandiseStoreTrait
{
    private bool $commerceLockHeld = false;

    /** Serialize catalog edits, inventory allocation and checkout retries. */
    public function withCommerceLock(callable $callback): mixed
    {
        if ($this->commerceLockHeld) return $callback();
        $handle = null;
        $lockName = '';
        if ($this->mode === 'file') {
            $directory = dirname($this->filePath);
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException('Commerce storage is unavailable.');
            }
            $handle = fopen($this->filePath . '.commerce.lock', 'c');
            if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Commerce is busy. Please try again.');
            @chmod($this->filePath . '.commerce.lock', 0640);
        } else {
            $lockName = 'vvx-commerce-' . substr(hash('sha256', (string) $this->pdo()->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
            $statement = $this->pdo()->prepare('SELECT GET_LOCK(?, 10)');
            $statement->execute([$lockName]);
            if ((int) $statement->fetchColumn() !== 1) throw new RuntimeException('Commerce is busy. Please try again.');
        }
        $this->commerceLockHeld = true;
        try {
            return $callback();
        } finally {
            $this->commerceLockHeld = false;
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            } elseif ($lockName !== '') {
                $this->pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function merchandiseProducts(bool $publicOnly = false, bool $includeDeleted = false): array
    {
        if ($this->mode === 'file') {
            $rows = array_values((array) ($this->readFileStore()['merchandiseProducts'] ?? []));
        } else {
            $rows = array_map(fn(array $row): array => $this->mapMerchandiseProduct($row), $this->pdo()->query('SELECT * FROM merchandise_products ORDER BY sort_order,id')->fetchAll());
        }
        $rows = array_values(array_filter($rows, static fn(array $row): bool => ($includeDeleted || empty($row['deletedAt'])) && (!$publicOnly || ($row['status'] ?? '') === 'active')));
        foreach ($rows as &$row) $row['variants'] = $this->merchandiseVariants((string) $row['id'], $publicOnly);
        unset($row);
        usort($rows, static fn(array $a, array $b): int => ((int) $a['sortOrder'] <=> (int) $b['sortOrder']) ?: strcmp((string) $a['title'], (string) $b['title']));
        return $rows;
    }

    public function merchandiseProduct(string|int $idOrSlug, bool $publicOnly = false): ?array
    {
        foreach ($this->merchandiseProducts($publicOnly) as $product) {
            if ((string) $product['id'] === (string) $idOrSlug || (string) $product['slug'] === (string) $idOrSlug) return $product;
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function merchandiseVariants(string|int $productId, bool $enabledOnly = false): array
    {
        if ($this->mode === 'file') {
            $rows = array_values((array) ($this->readFileStore()['merchandiseVariants'] ?? []));
            $rows = array_values(array_filter($rows, static fn(array $row): bool => (string) $row['productId'] === (string) $productId));
        } else {
            $statement = $this->pdo()->prepare('SELECT * FROM merchandise_variants WHERE product_id=? ORDER BY sort_order,id');
            $statement->execute([(int) $productId]);
            $rows = array_map(fn(array $row): array => $this->mapMerchandiseVariant($row), $statement->fetchAll());
        }
        if ($enabledOnly) $rows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['isEnabled'])));
        usort($rows, static fn(array $a, array $b): int => (int) $a['sortOrder'] <=> (int) $b['sortOrder']);
        return $rows;
    }

    public function saveMerchandiseProduct(array $input): array
    {
        return $this->withCommerceLock(function () use ($input): array {
            $id = trim((string) ($input['id'] ?? ''));
            $existing = $id !== '' ? $this->merchandiseProduct($id) : null;
            if ($id !== '' && !$existing) throw new RuntimeException('Product not found.');
            if ($existing && array_key_exists('revision', $input) && (!is_string($input['revision']) || !hash_equals(MerchandiseService::revision($existing), $input['revision']))) throw new RuntimeException('This product or its stock changed. Reload the product before saving.');
            $title = trim((string) ($input['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 190) throw new RuntimeException('Product title must contain 1–190 characters.');
            $slug = slugify((string) ($input['slug'] ?? $title));
            if ($slug === '' || strlen($slug) > 190) throw new RuntimeException('Choose a shorter product URL slug.');
            foreach ($this->merchandiseProducts(false, true) as $candidate) {
                if ((string) $candidate['slug'] === $slug && (string) $candidate['id'] !== $id) throw new RuntimeException('That product URL is already in use.');
            }
            $description = trim((string) ($input['description'] ?? ''));
            if (mb_strlen($description) > 10000) throw new RuntimeException('Product description cannot exceed 10,000 characters.');
            $currency = strtoupper(trim((string) ($input['currency'] ?? 'USD')));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new RuntimeException('Use a three-letter currency code.');
            $status = (string) ($input['status'] ?? 'draft');
            if (!in_array($status, ['draft', 'active', 'archived'], true)) throw new RuntimeException('Invalid product status.');
            $imageIds = array_values(array_unique(array_map('strval', (array) ($input['imageIds'] ?? []))));
            if (count($imageIds) > 12) throw new RuntimeException('A product can have up to twelve images.');
            foreach ($imageIds as $imageId) {
                $asset = $this->mediaAsset($imageId);
                if (!$asset || !str_starts_with((string) ($asset['mime'] ?? ''), 'image/') || !empty($asset['private']) || str_starts_with((string) ($asset['file'] ?? ''), 'private:')) {
                    throw new RuntimeException('Product images must be public image files from the media library.');
                }
            }
            $requiresShipping = (bool) ($input['requiresShipping'] ?? true);
            $record = [
                'id' => $id !== '' ? $id : bin2hex(random_bytes(8)), 'slug' => $slug, 'title' => $title, 'description' => $description,
                'price' => self::merchandiseMoney($input['price'] ?? ''), 'currency' => $currency, 'status' => $status,
                'requiresShipping' => $requiresShipping,
                'shippingPrice' => $requiresShipping ? self::merchandiseMoney($input['shippingPrice'] ?? 0) : 0.0,
                'imageIds' => $imageIds, 'sortOrder' => max(0, min(100000, (int) ($input['sortOrder'] ?? 100))),
                'deletedAt' => null, 'createdAt' => $existing['createdAt'] ?? now_iso(), 'updatedAt' => now_iso(),
            ];
            $variants = $this->normalizeMerchandiseVariants((array) ($input['variants'] ?? []), $existing);
            if ($this->mode === 'file') {
                $data = $this->readFileStore();
                $data['merchandiseProducts'][$record['id']] = $record;
                foreach ((array) ($data['merchandiseVariants'] ?? []) as $key => $variant) {
                    if ((string) $variant['productId'] === (string) $record['id']) $data['merchandiseVariants'][$key]['isEnabled'] = false;
                }
                foreach ($variants as $variant) {
                    $variant['productId'] = $record['id'];
                    $data['merchandiseVariants'][$variant['id']] = $variant;
                }
                $this->writeFileStore($data);
            } else {
                $pdo = $this->pdo();
                $pdo->beginTransaction();
                try {
                    $values = [$slug, $title, $description, $record['price'], $currency, $status, $record['requiresShipping'] ? 1 : 0, $record['shippingPrice'], json_encode($imageIds), $record['sortOrder']];
                    if ($existing) {
                        $pdo->prepare('UPDATE merchandise_products SET slug=?,title=?,description=?,price=?,currency=?,status=?,requires_shipping=?,shipping_price=?,image_ids_json=?,sort_order=?,updated_at=NOW() WHERE id=?')->execute([...$values, (int) $id]);
                    } else {
                        $pdo->prepare('INSERT INTO merchandise_products (slug,title,description,price,currency,status,requires_shipping,shipping_price,image_ids_json,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')->execute($values);
                        $record['id'] = (string) $pdo->lastInsertId();
                    }
                    $pdo->prepare('UPDATE merchandise_variants SET is_enabled=0 WHERE product_id=?')->execute([(int) $record['id']]);
                    foreach ($variants as $variant) {
                        $values = [$variant['label'], $variant['sku'], json_encode($variant['options']), $variant['price'], $variant['stock'], $variant['isEnabled'] ? 1 : 0, $variant['sortOrder']];
                        if ($variant['existing']) {
                            $pdo->prepare('UPDATE merchandise_variants SET label=?,sku=?,options_json=?,price=?,stock=?,is_enabled=?,sort_order=?,updated_at=NOW() WHERE id=? AND product_id=?')->execute([...$values, (int) $variant['id'], (int) $record['id']]);
                        } else {
                            $pdo->prepare('INSERT INTO merchandise_variants (product_id,label,sku,options_json,price,stock,is_enabled,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())')->execute([(int) $record['id'], ...$values]);
                        }
                    }
                    $pdo->commit();
                } catch (Throwable $error) {
                    $pdo->rollBack();
                    throw $error;
                }
            }
            return $this->merchandiseProduct((string) $record['id']) ?? $record;
        });
    }

    private function normalizeMerchandiseVariants(array $input, ?array $product): array
    {
        if ($input === [] || count($input) > 100) throw new RuntimeException('Provide between one and 100 product variants.');
        $known = [];
        foreach ((array) ($product['variants'] ?? []) as $variant) $known[(string) $variant['id']] = $variant;
        $skus = [];
        $variantIds = [];
        $allSkus = [];
        foreach ($this->merchandiseProducts(false, true) as $item) foreach ($item['variants'] as $variant) $allSkus[strtoupper((string) $variant['sku'])] = (string) $variant['id'];
        $rows = [];
        foreach (array_values($input) as $index => $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid product variant.');
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '' && !isset($known[$id])) throw new RuntimeException('Variant does not belong to this product.');
            if ($id !== '' && isset($variantIds[$id])) throw new RuntimeException('Each product variant can appear only once.');
            if ($id !== '') $variantIds[$id] = true;
            $label = trim((string) ($row['label'] ?? ''));
            $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
            if ($label === '' || mb_strlen($label) > 190) throw new RuntimeException('Each variant needs a label of up to 190 characters.');
            if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,99}$/', $sku)) throw new RuntimeException('Each variant needs a SKU containing letters, numbers, dots, dashes or underscores.');
            if (isset($skus[$sku]) || (isset($allSkus[$sku]) && $allSkus[$sku] !== $id)) throw new RuntimeException('Variant SKUs must be unique.');
            $skus[$sku] = true;
            $stock = filter_var($row['stock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
            if ($stock === false) throw new RuntimeException('Variant stock must be a whole number from zero to 1,000,000.');
            $options = [];
            foreach ((array) ($row['options'] ?? []) as $name => $value) {
                if (!is_scalar($value) || trim((string) $name) === '' || mb_strlen((string) $name) > 40 || mb_strlen((string) $value) > 100) throw new RuntimeException('Variant option names and values are invalid.');
                $options[trim((string) $name)] = trim((string) $value);
            }
            if (count($options) > 10) throw new RuntimeException('Each variant can have up to ten options.');
            $rows[] = [
                'id' => $id ?: bin2hex(random_bytes(8)), 'existing' => $id !== '', 'productId' => (string) ($product['id'] ?? ''),
                'label' => $label, 'sku' => $sku, 'options' => $options,
                'price' => ($row['price'] ?? '') === '' || ($row['price'] ?? null) === null ? null : self::merchandiseMoney($row['price']),
                'stock' => $stock, 'isEnabled' => (bool) ($row['isEnabled'] ?? true), 'sortOrder' => $index,
                'createdAt' => $known[$id]['createdAt'] ?? now_iso(), 'updatedAt' => now_iso(),
            ];
        }
        if (!array_filter($rows, static fn(array $row): bool => $row['isEnabled'])) throw new RuntimeException('Keep at least one variant enabled, or archive the product.');
        return $rows;
    }

    private static function merchandiseMoney(mixed $input): float
    {
        if (!is_scalar($input) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', trim((string) $input))) throw new RuntimeException('Enter a valid non-negative price with at most two decimal places.');
        return round((float) $input, 2);
    }

    /** Delete from the catalog while retaining order and inventory history. */
    public function deleteMerchandiseProduct(string|int $id): void
    {
        $this->withCommerceLock(function () use ($id): void {
            if (!$this->merchandiseProduct($id)) throw new RuntimeException('Product not found.');
            if ($this->mode === 'file') {
                $data = $this->readFileStore();
                $data['merchandiseProducts'][(string) $id]['status'] = 'archived';
                $data['merchandiseProducts'][(string) $id]['deletedAt'] = now_iso();
                $this->writeFileStore($data);
            } else {
                $this->pdo()->prepare("UPDATE merchandise_products SET status='archived',deleted_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int) $id]);
            }
        });
    }

    /** Reserve stock before charging; retries use the existing reservation. */
    public function reserveMerchandiseStock(array $order): void
    {
        $items = array_values(array_filter((array) $order['items'], static fn(array $row): bool => ($row['itemType'] ?? '') === 'merchandise'));
        if ($items === []) return;
        $this->withCommerceLock(function () use ($items, $order): void {
            if ($this->mode === 'file') {
                $data = $this->readFileStore();
                foreach ($items as $item) {
                    $variantId = (string) $item['metadata']['variantId'];
                    $key = (string) $order['id'] . ':' . $variantId;
                    if (isset($data['merchandiseReservations'][$key]) && ($data['merchandiseReservations'][$key]['status'] ?? '') !== 'released') continue;
                    $variant = $data['merchandiseVariants'][$variantId] ?? null;
                    if (!$variant || empty($variant['isEnabled']) || (int) $variant['stock'] < (int) $item['quantity']) throw new RuntimeException('An item has sold out. Refresh your cart before paying.');
                    $data['merchandiseVariants'][$variantId]['stock'] -= (int) $item['quantity'];
                    $data['merchandiseReservations'][$key] = ['orderId' => (string) $order['id'], 'variantId' => $variantId, 'quantity' => (int) $item['quantity'], 'status' => 'reserved'];
                }
                $this->writeFileStore($data);
                return;
            }
            $pdo = $this->pdo();
            $pdo->beginTransaction();
            try {
                foreach ($items as $item) {
                    $variantId = (int) $item['metadata']['variantId'];
                    $check = $pdo->prepare('SELECT status FROM merchandise_reservations WHERE order_id=? AND variant_id=?');
                    $check->execute([(int) $order['id'], $variantId]);
                    $status = $check->fetchColumn();
                    if ($status !== false && $status !== 'released') continue;
                    $quantity = (int) $item['quantity'];
                    $update = $pdo->prepare('UPDATE merchandise_variants SET stock=stock-?,updated_at=NOW() WHERE id=? AND is_enabled=1 AND stock>=?');
                    $update->execute([$quantity, $variantId, $quantity]);
                    if ($update->rowCount() !== 1) throw new RuntimeException('An item has sold out. Refresh your cart before paying.');
                    $pdo->prepare("INSERT INTO merchandise_reservations (order_id,variant_id,quantity,status,created_at,updated_at) VALUES (?,?,?,'reserved',NOW(),NOW()) ON DUPLICATE KEY UPDATE status='reserved',quantity=VALUES(quantity),updated_at=NOW()")->execute([(int) $order['id'], $variantId, $quantity]);
                }
                $pdo->commit();
            } catch (Throwable $error) {
                $pdo->rollBack();
                throw $error;
            }
        });
    }

    public function settleMerchandiseStock(array $order, bool $release = false): void
    {
        $this->withCommerceLock(function () use ($order, $release): void {
            $canReturn = !in_array((string) ($order['metadata']['shippingStatus'] ?? 'pending'), ['shipped', 'delivered', 'completed'], true);
            if ($release && !$canReturn) return;
            if ($this->mode === 'file') {
                $data = $this->readFileStore();
                foreach ((array) ($data['merchandiseReservations'] ?? []) as $key => $row) {
                    if ((string) $row['orderId'] !== (string) $order['id'] || $row['status'] === 'released') continue;
                    if ($release) $data['merchandiseVariants'][(string) $row['variantId']]['stock'] += (int) $row['quantity'];
                    $data['merchandiseReservations'][$key]['status'] = $release ? 'released' : 'committed';
                }
                $this->writeFileStore($data);
                return;
            }
            $pdo = $this->pdo();
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare("SELECT * FROM merchandise_reservations WHERE order_id=? AND status<>'released' FOR UPDATE");
                $statement->execute([(int) $order['id']]);
                foreach ($statement->fetchAll() as $row) {
                    if ($release) $pdo->prepare('UPDATE merchandise_variants SET stock=stock+?,updated_at=NOW() WHERE id=?')->execute([(int) $row['quantity'], (int) $row['variant_id']]);
                    $pdo->prepare('UPDATE merchandise_reservations SET status=?,updated_at=NOW() WHERE id=?')->execute([$release ? 'released' : 'committed', (int) $row['id']]);
                }
                $pdo->commit();
            } catch (Throwable $error) {
                $pdo->rollBack();
                throw $error;
            }
        });
    }

    public function updateMerchandiseFulfillment(string|int $orderId, string $status, string $tracking = ''): array
    {
        return $this->withCommerceLock(function () use ($orderId, $status, $tracking): array {
            $order = $this->order($orderId);
            if (!$order || ($order['status'] ?? '') !== 'paid' || ($order['metadata']['checkoutFlow'] ?? '') !== 'merchandise') throw new RuntimeException('A paid merchandise order is required.');
            $allowed = !empty($order['metadata']['requiresShipping']) ? ['pending', 'packing', 'shipped', 'delivered'] : ['pending', 'packing', 'completed'];
            if (!in_array($status, $allowed, true)) throw new RuntimeException('Invalid fulfillment status.');
            $previous = (string) ($order['metadata']['shippingStatus'] ?? 'pending');
            if (in_array($previous, ['shipped', 'delivered', 'completed'], true) && array_search($status, $allowed, true) < array_search($previous, $allowed, true)) throw new RuntimeException('Dispatched orders cannot return to an unshipped state.');
            $tracking = trim($tracking);
            if (mb_strlen($tracking) > 190 || preg_match('/[\r\n]/', $tracking)) throw new RuntimeException('Tracking reference is too long or invalid.');
            foreach ($order['items'] as $item) {
                $this->updateOrderItem((string) $order['id'], (string) $item['id'], ['fulfillmentStatus' => in_array($status, ['shipped', 'delivered', 'completed'], true) ? 'fulfilled' : 'pending']);
            }
            return $this->updateOrder((string) $order['id'], ['metadata' => ['shippingStatus' => $status, 'trackingReference' => $tracking, 'fulfillmentUpdatedAt' => now_iso()]]);
        });
    }

    private function mapMerchandiseProduct(array $row): array
    {
        return ['id' => (string) $row['id'], 'slug' => $row['slug'], 'title' => $row['title'], 'description' => $row['description'], 'price' => (float) $row['price'], 'currency' => $row['currency'], 'status' => $row['status'], 'requiresShipping' => (bool) $row['requires_shipping'], 'shippingPrice' => (float) $row['shipping_price'], 'imageIds' => safe_json_decode((string) ($row['image_ids_json'] ?? '[]')), 'sortOrder' => (int) $row['sort_order'], 'deletedAt' => $row['deleted_at'], 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at']];
    }

    private function mapMerchandiseVariant(array $row): array
    {
        return ['id' => (string) $row['id'], 'productId' => (string) $row['product_id'], 'label' => $row['label'], 'sku' => $row['sku'], 'options' => safe_json_decode((string) ($row['options_json'] ?? '{}')), 'price' => $row['price'] !== null ? (float) $row['price'] : null, 'stock' => (int) $row['stock'], 'isEnabled' => (bool) $row['is_enabled'], 'sortOrder' => (int) $row['sort_order'], 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at']];
    }
}
