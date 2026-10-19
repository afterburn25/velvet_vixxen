<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

trait CommerceStoreTrait
{
    /** @return list<array<string,mixed>> */
    public function paymentProviders(bool $enabledOnly = false): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['paymentProviders'] ?? []));
            if ($enabledOnly) {
                $rows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['enabled'])));
            }
            usort($rows, static fn(array $a, array $b): int => ((int) ($a['sortOrder'] ?? 100)) <=> ((int) ($b['sortOrder'] ?? 100)));
            return $rows;
        }
        $sql = 'SELECT * FROM payment_providers' . ($enabledOnly ? ' WHERE is_enabled=1' : '') . ' ORDER BY sort_order,id';
        return array_map(fn(array $row): array => $this->mapPaymentProviderRow($row), $this->pdo()->query($sql)->fetchAll());
    }

    public function paymentProvider(string $key): ?array
    {
        foreach ($this->paymentProviders(false) as $provider) {
            if ((string) ($provider['key'] ?? '') === $key) {
                return $provider;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $provider */
    public function savePaymentProvider(array $provider): array
    {
        $key = slugify((string) ($provider['key'] ?? ''));
        if ($key === '' || $key === 'item') {
            throw new RuntimeException('Payment provider key is required.');
        }
        $record = [
            'key' => $key,
            'name' => trim((string) ($provider['name'] ?? ucfirst($key))),
            'enabled' => (bool) ($provider['enabled'] ?? false),
            'testMode' => (bool) ($provider['testMode'] ?? true),
            'supportsRecurring' => (bool) ($provider['supportsRecurring'] ?? false),
            'settings' => (array) ($provider['settings'] ?? []),
            'sortOrder' => (int) ($provider['sortOrder'] ?? 100),
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $existing = (array) ($data['paymentProviders'][$key] ?? []);
            $record['createdAt'] = (string) ($existing['createdAt'] ?? $record['createdAt']);
            $data['paymentProviders'][$key] = array_merge($existing, $record);
            $this->writeFileStore($data);
            return $data['paymentProviders'][$key];
        }
        $stmt = $this->pdo()->prepare('INSERT INTO payment_providers (provider_key,name,is_enabled,test_mode,supports_recurring,settings_json,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),is_enabled=VALUES(is_enabled),test_mode=VALUES(test_mode),supports_recurring=VALUES(supports_recurring),settings_json=VALUES(settings_json),sort_order=VALUES(sort_order),updated_at=NOW()');
        $stmt->execute([$key, $record['name'], $record['enabled'] ? 1 : 0, $record['testMode'] ? 1 : 0, $record['supportsRecurring'] ? 1 : 0, json_encode($record['settings']), $record['sortOrder']]);
        return $this->paymentProvider($key) ?? $record;
    }

    /** @return list<array<string,mixed>> */
    public function coupons(bool $activeOnly = false): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['coupons'] ?? []));
            if ($activeOnly) {
                $now = time();
                $rows = array_values(array_filter($rows, static function (array $coupon) use ($now): bool {
                    if (empty($coupon['isActive'])) return false;
                    $starts = strtotime((string) ($coupon['startsAt'] ?? '')) ?: 0;
                    $ends = strtotime((string) ($coupon['endsAt'] ?? '')) ?: 0;
                    return ($starts === 0 || $starts <= $now) && ($ends === 0 || $ends > $now);
                }));
            }
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
            return $rows;
        }
        $sql = 'SELECT c.*,(SELECT COUNT(*) FROM coupon_redemptions cr WHERE cr.coupon_id=c.id AND cr.status=\'applied\') AS redemption_count FROM coupons c';
        if ($activeOnly) {
            $sql .= ' WHERE c.is_active=1 AND (c.starts_at IS NULL OR c.starts_at<=NOW()) AND (c.ends_at IS NULL OR c.ends_at>NOW())';
        }
        $sql .= ' ORDER BY c.id DESC';
        return array_map(fn(array $row): array => $this->mapCouponRow($row), $this->pdo()->query($sql)->fetchAll());
    }

    public function coupon(string|int $idOrCode): ?array
    {
        $lookup = strtoupper(trim((string) $idOrCode));
        foreach ($this->coupons(false) as $coupon) {
            if ((string) ($coupon['id'] ?? '') === (string) $idOrCode || strtoupper((string) ($coupon['code'] ?? '')) === $lookup) {
                return $coupon;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $coupon */
    public function saveCoupon(array $coupon): array
    {
        $id = trim((string) ($coupon['id'] ?? ''));
        $code = strtoupper(preg_replace('/[^A-Z0-9_-]+/', '', strtoupper(trim((string) ($coupon['code'] ?? '')))) ?? '');
        if ($code === '') {
            throw new RuntimeException('Coupon code is required.');
        }
        $discountType = (string) ($coupon['discountType'] ?? 'percent');
        if (!in_array($discountType, ['percent', 'fixed'], true)) $discountType = 'percent';
        $scope = (string) ($coupon['scope'] ?? 'all');
        if (!in_array($scope, ['all', 'subscription', 'ppv', 'rental', 'bundle'], true)) $scope = 'all';
        $value = max(0, round((float) ($coupon['value'] ?? 0), 2));
        if ($discountType === 'percent') $value = min(100, $value);
        $record = [
            'id' => $id !== '' ? $id : bin2hex(random_bytes(7)),
            'code' => $code,
            'description' => trim((string) ($coupon['description'] ?? '')),
            'discountType' => $discountType,
            'value' => $value,
            'scope' => $scope,
            'minimumSubtotal' => max(0, round((float) ($coupon['minimumSubtotal'] ?? 0), 2)),
            'maximumDiscount' => ($coupon['maximumDiscount'] ?? '') !== '' ? max(0, round((float) $coupon['maximumDiscount'], 2)) : null,
            'usageLimit' => max(0, (int) ($coupon['usageLimit'] ?? 0)),
            'perMemberLimit' => max(0, (int) ($coupon['perMemberLimit'] ?? 0)),
            'redemptionCount' => max(0, (int) ($coupon['redemptionCount'] ?? 0)),
            'startsAt' => $coupon['startsAt'] ?? null,
            'endsAt' => $coupon['endsAt'] ?? null,
            'isActive' => (bool) ($coupon['isActive'] ?? true),
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ];
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            foreach ((array) ($data['coupons'] ?? []) as $existingId => $existingCoupon) {
                if (strtoupper((string) ($existingCoupon['code'] ?? '')) === $code && (string) $existingId !== $id) {
                    throw new RuntimeException('That coupon code is already in use.');
                }
            }
            $existing = (array) ($data['coupons'][(string) $record['id']] ?? []);
            $record['createdAt'] = (string) ($existing['createdAt'] ?? $record['createdAt']);
            $record['redemptionCount'] = (int) ($existing['redemptionCount'] ?? $record['redemptionCount']);
            $data['coupons'][(string) $record['id']] = array_merge($existing, $record);
            $this->writeFileStore($data);
            return $data['coupons'][(string) $record['id']];
        }
        if ($id !== '' && ctype_digit($id)) {
            $stmt = $this->pdo()->prepare('UPDATE coupons SET code=?,description=?,discount_type=?,discount_value=?,scope=?,minimum_subtotal=?,maximum_discount=?,usage_limit=?,per_member_limit=?,starts_at=?,ends_at=?,is_active=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([$code, $record['description'], $discountType, $value, $scope, $record['minimumSubtotal'], $record['maximumDiscount'], $record['usageLimit'] ?: null, $record['perMemberLimit'] ?: null, $this->mysqlDate($record['startsAt']), $this->mysqlDate($record['endsAt']), $record['isActive'] ? 1 : 0, (int) $id]);
            return $this->coupon($id) ?? $record;
        }
        $stmt = $this->pdo()->prepare('INSERT INTO coupons (code,description,discount_type,discount_value,scope,minimum_subtotal,maximum_discount,usage_limit,per_member_limit,starts_at,ends_at,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
        $stmt->execute([$code, $record['description'], $discountType, $value, $scope, $record['minimumSubtotal'], $record['maximumDiscount'], $record['usageLimit'] ?: null, $record['perMemberLimit'] ?: null, $this->mysqlDate($record['startsAt']), $this->mysqlDate($record['endsAt']), $record['isActive'] ? 1 : 0]);
        $record['id'] = (string) $this->pdo()->lastInsertId();
        return $record;
    }

    public function couponMemberRedemptionCount(string|int $couponId, string|int $memberId): int
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            return count(array_filter((array) ($data['couponRedemptions'] ?? []), static fn(array $row): bool => (string) ($row['couponId'] ?? '') === (string) $couponId && (string) ($row['memberId'] ?? '') === (string) $memberId && (string) ($row['status'] ?? '') === 'applied'));
        }
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id=? AND member_id=? AND status='applied'");
        $stmt->execute([(int) $couponId, (int) $memberId]);
        return (int) $stmt->fetchColumn();
    }

    public function recordCouponRedemption(string|int $couponId, string|int $memberId, string|int $orderId, float $discount): void
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $id = bin2hex(random_bytes(7));
            $data['couponRedemptions'][$id] = ['id'=>$id,'couponId'=>(string)$couponId,'memberId'=>(string)$memberId,'orderId'=>(string)$orderId,'discount'=>round($discount,2),'status'=>'applied','createdAt'=>now_iso()];
            if (isset($data['coupons'][(string) $couponId])) {
                $data['coupons'][(string) $couponId]['redemptionCount'] = (int) ($data['coupons'][(string) $couponId]['redemptionCount'] ?? 0) + 1;
                $data['coupons'][(string) $couponId]['updatedAt'] = now_iso();
            }
            $this->writeFileStore($data);
            return;
        }
        $stmt = $this->pdo()->prepare("INSERT INTO coupon_redemptions (coupon_id,member_id,order_id,discount_amount,status,created_at) VALUES (?,?,?,?, 'applied',NOW())");
        $stmt->execute([(int) $couponId, (int) $memberId, (int) $orderId, round($discount, 2)]);
    }

    /** @return list<array<string,mixed>> */
    public function bundles(bool $activeOnly = false): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['bundles'] ?? []));
            if ($activeOnly) $rows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['isActive'])));
            usort($rows, static fn(array $a, array $b): int => ((int) ($a['sortOrder'] ?? 100)) <=> ((int) ($b['sortOrder'] ?? 100)));
            return $rows;
        }
        $sql = 'SELECT * FROM commerce_bundles' . ($activeOnly ? ' WHERE is_active=1' : '') . ' ORDER BY sort_order,id';
        $rows = $this->pdo()->query($sql)->fetchAll();
        return array_map(function (array $row): array {
            $bundle = $this->mapBundleRow($row);
            $bundle['items'] = $this->bundleItems((string) $bundle['id']);
            return $bundle;
        }, $rows);
    }

    public function bundle(string|int $idOrSlug): ?array
    {
        $lookup = (string) $idOrSlug;
        foreach ($this->bundles(false) as $bundle) {
            if ((string) ($bundle['id'] ?? '') === $lookup || (string) ($bundle['slug'] ?? '') === $lookup) return $bundle;
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function bundleItems(string|int $bundleId): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            return array_values((array) ($data['bundles'][(string) $bundleId]['items'] ?? []));
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM commerce_bundle_items WHERE bundle_id=? ORDER BY sort_order,id');
        $stmt->execute([(int) $bundleId]);
        return array_map(static fn(array $row): array => ['id'=>(string)$row['id'],'bundleId'=>(string)$row['bundle_id'],'itemType'=>$row['item_type'],'itemId'=>(string)$row['item_id'],'sortOrder'=>(int)$row['sort_order']], $stmt->fetchAll());
    }

    /** @param array<string,mixed> $bundle */
    public function saveBundle(array $bundle): array
    {
        $id = trim((string) ($bundle['id'] ?? ''));
        $title = trim((string) ($bundle['title'] ?? ''));
        if ($title === '') throw new RuntimeException('Bundle title is required.');
        $items = [];
        foreach ((array) ($bundle['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $type = (string) ($item['itemType'] ?? $item['type'] ?? '');
            $itemId = (string) ($item['itemId'] ?? $item['id'] ?? '');
            if (in_array($type, ['gallery','video'], true) && $itemId !== '') {
                $items[] = ['itemType'=>$type,'itemId'=>$itemId,'sortOrder'=>(int)($item['sortOrder']??100)];
            }
        }
        $record = [
            'id'=>$id !== '' ? $id : bin2hex(random_bytes(7)), 'slug'=>slugify((string)($bundle['slug']??$title)), 'title'=>$title,
            'description'=>trim((string)($bundle['description']??'')), 'price'=>max(0,round((float)($bundle['price']??0),2)),
            'subscriberPrice'=>($bundle['subscriberPrice']??'')!==''?max(0,round((float)$bundle['subscriberPrice'],2)):null,
            'currency'=>strtoupper(substr((string)($bundle['currency']??'USD'),0,3)), 'isActive'=>(bool)($bundle['isActive']??true),
            'sortOrder'=>(int)($bundle['sortOrder']??100), 'items'=>$items, 'createdAt'=>now_iso(), 'updatedAt'=>now_iso(),
        ];
        if ($this->mode === 'file') {
            $data=$this->readFileStore();$existing=(array)($data['bundles'][(string)$record['id']]??[]);$record['createdAt']=(string)($existing['createdAt']??$record['createdAt']);$data['bundles'][(string)$record['id']]=array_merge($existing,$record);$this->writeFileStore($data);return $data['bundles'][(string)$record['id']];
        }
        $pdo=$this->pdo();$pdo->beginTransaction();
        try {
            if($id!==''&&ctype_digit($id)){$stmt=$pdo->prepare('UPDATE commerce_bundles SET slug=?,title=?,description=?,price=?,subscriber_price=?,currency=?,is_active=?,sort_order=?,updated_at=NOW() WHERE id=?');$stmt->execute([$record['slug'],$title,$record['description'],$record['price'],$record['subscriberPrice'],$record['currency'],$record['isActive']?1:0,$record['sortOrder'],(int)$id]);$bundleId=(int)$id;}else{$stmt=$pdo->prepare('INSERT INTO commerce_bundles (slug,title,description,price,subscriber_price,currency,is_active,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())');$stmt->execute([$record['slug'],$title,$record['description'],$record['price'],$record['subscriberPrice'],$record['currency'],$record['isActive']?1:0,$record['sortOrder']]);$bundleId=(int)$pdo->lastInsertId();}
            $pdo->prepare('DELETE FROM commerce_bundle_items WHERE bundle_id=?')->execute([$bundleId]);
            $insert=$pdo->prepare('INSERT INTO commerce_bundle_items (bundle_id,item_type,item_id,sort_order,created_at) VALUES (?,?,?,?,NOW())');foreach($items as $item){$insert->execute([$bundleId,$item['itemType'],(int)$item['itemId'],$item['sortOrder']]);}
            $pdo->commit();return $this->bundle((string)$bundleId)??array_merge($record,['id'=>(string)$bundleId]);
        } catch(Throwable $e){$pdo->rollBack();throw $e;}
    }

    /** @return list<array<string,mixed>> */
    public function orders(?string $memberId = null, ?string $status = null, int $limit = 200): array
    {
        $limit=max(1,min(1000,$limit));
        if($this->mode==='file'){$data=$this->readFileStore();$rows=array_values((array)($data['orders']??[]));$rows=array_values(array_filter($rows,static function(array $row)use($memberId,$status):bool{return($memberId===null||(string)($row['memberId']??'')===$memberId)&&($status===null||$status===''||(string)($row['status']??'')===$status);}));usort($rows,static fn(array $a,array $b):int=>strcmp((string)($b['createdAt']??''),(string)($a['createdAt']??'')));foreach($rows as &$row){$row['items']=array_values((array)($data['orderItems'][(string)($row['id']??'')]??[]));}unset($row);return array_slice($rows,0,$limit);}
        $where=[];$params=[];if($memberId!==null){$where[]='o.member_id=?';$params[]=(int)$memberId;}if($status!==null&&$status!==''){$where[]='o.status=?';$params[]=$status;}$sql='SELECT o.*,m.display_name,m.email FROM orders o JOIN members m ON m.id=o.member_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY o.id DESC LIMIT '.$limit;$stmt=$this->pdo()->prepare($sql);$stmt->execute($params);$rows=array_map(fn(array $row):array=>$this->mapOrderRow($row),$stmt->fetchAll());foreach($rows as &$row){$row['items']=$this->orderItems((string)$row['id']);}unset($row);return $rows;
    }

    public function order(string|int $idOrNumber): ?array
    {
        $lookup=(string)$idOrNumber;
        if($this->mode==='file'){foreach($this->orders(null,null,1000) as $order){if((string)($order['id']??'')===$lookup||(string)($order['orderNumber']??'')===$lookup||(string)($order['idempotencyKey']??'')===$lookup)return $order;}return null;}
        if(ctype_digit($lookup)){$stmt=$this->pdo()->prepare('SELECT o.*,m.display_name,m.email FROM orders o JOIN members m ON m.id=o.member_id WHERE o.id=? LIMIT 1');$stmt->execute([(int)$lookup]);}else{$stmt=$this->pdo()->prepare('SELECT o.*,m.display_name,m.email FROM orders o JOIN members m ON m.id=o.member_id WHERE o.order_number=? OR o.idempotency_key=? LIMIT 1');$stmt->execute([$lookup,$lookup]);}$row=$stmt->fetch();if(!is_array($row))return null;$order=$this->mapOrderRow($row);$order['items']=$this->orderItems((string)$order['id']);return $order;
    }

    /** @return list<array<string,mixed>> */
    public function orderItems(string|int $orderId): array
    {
        if($this->mode==='file'){$data=$this->readFileStore();return array_values((array)($data['orderItems'][(string)$orderId]??[]));}
        $stmt=$this->pdo()->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$stmt->execute([(int)$orderId]);return array_map(fn(array $row):array=>$this->mapOrderItemRow($row),$stmt->fetchAll());
    }

    /** @param array<string,mixed> $order @param list<array<string,mixed>> $items */
    public function createOrder(array $order, array $items): array
    {
        $memberId=(string)($order['memberId']??'');if(!$this->member($memberId))throw new RuntimeException('Member account not found.');
        $idempotency=trim((string)($order['idempotencyKey']??''));if($idempotency!==''){ $existing=$this->order($idempotency);if($existing)return $existing; }
        $record=['id'=>bin2hex(random_bytes(8)),'memberId'=>$memberId,'memberName'=>(string)($this->member($memberId)['displayName']??''),'memberEmail'=>(string)($this->member($memberId)['email']??''),'orderNumber'=>(string)($order['orderNumber']??('VVX-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(3))))),'status'=>(string)($order['status']??'pending'),'subtotal'=>round((float)($order['subtotal']??0),2),'discountTotal'=>round((float)($order['discountTotal']??0),2),'taxTotal'=>round((float)($order['taxTotal']??0),2),'grandTotal'=>round((float)($order['grandTotal']??0),2),'refundedTotal'=>round((float)($order['refundedTotal']??0),2),'currency'=>strtoupper((string)($order['currency']??'USD')),'couponCode'=>$order['couponCode']??null,'providerKey'=>$order['providerKey']??null,'providerCheckoutId'=>$order['providerCheckoutId']??null,'idempotencyKey'=>$idempotency?:null,'billingEmail'=>(string)($order['billingEmail']??($this->member($memberId)['email']??'')),'metadata'=>(array)($order['metadata']??[]),'paidAt'=>$order['paidAt']??null,'createdAt'=>now_iso(),'updatedAt'=>now_iso(),'items'=>[]];
        $validStatuses=['pending','processing','paid','failed','refunded','partially_refunded','chargeback','cancelled'];if(!in_array($record['status'],$validStatuses,true))$record['status']='pending';
        $normalized=[];foreach($items as $item){$normalized[]=['id'=>bin2hex(random_bytes(7)),'orderId'=>$record['id'],'itemType'=>(string)($item['itemType']??''),'itemId'=>(string)($item['itemId']??''),'title'=>(string)($item['title']??'Item'),'quantity'=>max(1,(int)($item['quantity']??1)),'unitPrice'=>round((float)($item['unitPrice']??0),2),'discountTotal'=>round((float)($item['discountTotal']??0),2),'lineTotal'=>round((float)($item['lineTotal']??0),2),'metadata'=>(array)($item['metadata']??[]),'fulfillmentStatus'=>(string)($item['fulfillmentStatus']??'pending'),'createdAt'=>now_iso()];}
        if($this->mode==='file'){$data=$this->readFileStore();$data['orders'][(string)$record['id']]=$record;$data['orderItems'][(string)$record['id']]=$normalized;$this->writeFileStore($data);$record['items']=$normalized;return $record;}
        $pdo=$this->pdo();$pdo->beginTransaction();try{$stmt=$pdo->prepare('INSERT INTO orders (member_id,order_number,status,subtotal,discount_total,tax_total,grand_total,refunded_total,currency,coupon_code,provider_key,provider_checkout_id,idempotency_key,billing_email,metadata_json,paid_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');$stmt->execute([(int)$memberId,$record['orderNumber'],$record['status'],$record['subtotal'],$record['discountTotal'],$record['taxTotal'],$record['grandTotal'],$record['refundedTotal'],$record['currency'],$record['couponCode'],$record['providerKey'],$record['providerCheckoutId'],$record['idempotencyKey'],$record['billingEmail'],json_encode($record['metadata']),$this->mysqlDate($record['paidAt'])]);$orderId=(int)$pdo->lastInsertId();$insert=$pdo->prepare('INSERT INTO order_items (order_id,item_type,item_id,title,quantity,unit_price,discount_total,line_total,metadata_json,fulfillment_status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');foreach($normalized as $item){$insert->execute([$orderId,$item['itemType'],(int)$item['itemId'],$item['title'],$item['quantity'],$item['unitPrice'],$item['discountTotal'],$item['lineTotal'],json_encode($item['metadata']),$item['fulfillmentStatus']]);}$pdo->commit();return $this->order((string)$orderId)??array_merge($record,['id'=>(string)$orderId]);}catch(Throwable $e){$pdo->rollBack();throw $e;}
    }

    /** @param array<string,mixed> $changes */
    public function updateOrder(string|int $orderId, array $changes): array
    {
        $order=$this->order($orderId);if(!$order)throw new RuntimeException('Order not found.');$allowed=['pending','processing','paid','failed','refunded','partially_refunded','chargeback','cancelled'];if(isset($changes['status'])&&in_array((string)$changes['status'],$allowed,true))$order['status']=(string)$changes['status'];foreach(['providerKey','providerCheckoutId','couponCode','paidAt','billingEmail'] as $key){if(array_key_exists($key,$changes))$order[$key]=$changes[$key];}foreach(['refundedTotal','discountTotal','taxTotal','grandTotal','subtotal'] as $key){if(array_key_exists($key,$changes))$order[$key]=round((float)$changes[$key],2);}if(isset($changes['metadata'])&&is_array($changes['metadata']))$order['metadata']=array_merge((array)($order['metadata']??[]),$changes['metadata']);$order['updatedAt']=now_iso();
        if($this->mode==='file'){$data=$this->readFileStore();$copy=$order;unset($copy['items']);$data['orders'][(string)$order['id']]=$copy;$this->writeFileStore($data);$order['items']=$this->orderItems((string)$order['id']);return $order;}
        $stmt=$this->pdo()->prepare('UPDATE orders SET status=?,subtotal=?,discount_total=?,tax_total=?,grand_total=?,refunded_total=?,coupon_code=?,provider_key=?,provider_checkout_id=?,billing_email=?,metadata_json=?,paid_at=?,updated_at=NOW() WHERE id=?');$stmt->execute([$order['status'],$order['subtotal'],$order['discountTotal'],$order['taxTotal'],$order['grandTotal'],$order['refundedTotal'],$order['couponCode'],$order['providerKey'],$order['providerCheckoutId'],$order['billingEmail'],json_encode((array)$order['metadata']),$this->mysqlDate($order['paidAt']),(int)$order['id']]);return $this->order((string)$order['id'])??$order;
    }

    public function updateOrderItem(string|int $orderId, string|int $itemId, array $changes): array
    {
        $items=$this->orderItems($orderId);$updated=null;foreach($items as &$item){if((string)($item['id']??'')!==(string)$itemId)continue;if(isset($changes['metadata'])&&is_array($changes['metadata']))$item['metadata']=array_merge((array)($item['metadata']??[]),$changes['metadata']);if(isset($changes['fulfillmentStatus']))$item['fulfillmentStatus']=(string)$changes['fulfillmentStatus'];$updated=$item;break;}unset($item);if(!$updated)throw new RuntimeException('Order item not found.');
        if($this->mode==='file'){$data=$this->readFileStore();$data['orderItems'][(string)$orderId]=$items;$this->writeFileStore($data);return $updated;}
        $stmt=$this->pdo()->prepare('UPDATE order_items SET metadata_json=?,fulfillment_status=? WHERE id=? AND order_id=?');$stmt->execute([json_encode((array)$updated['metadata']),$updated['fulfillmentStatus'],(int)$itemId,(int)$orderId]);return $updated;
    }

    /** @return list<array<string,mixed>> */
    public function paymentTransactions(?string $orderId = null, int $limit = 300): array
    {
        $limit=max(1,min(1000,$limit));if($this->mode==='file'){$data=$this->readFileStore();$rows=array_values((array)($data['paymentTransactions']??[]));if($orderId!==null)$rows=array_values(array_filter($rows,static fn(array $row):bool=>(string)($row['orderId']??'')===$orderId));usort($rows,static fn(array $a,array $b):int=>strcmp((string)($b['createdAt']??''),(string)($a['createdAt']??'')));return array_slice($rows,0,$limit);} $sql='SELECT * FROM payment_transactions'.($orderId!==null?' WHERE order_id=?':'').' ORDER BY id DESC LIMIT '.$limit;$stmt=$this->pdo()->prepare($sql);$stmt->execute($orderId!==null?[(int)$orderId]:[]);return array_map(fn(array $row):array=>$this->mapTransactionRow($row),$stmt->fetchAll());
    }

    public function paymentTransaction(string|int $idOrProviderId): ?array
    {
        $lookup=(string)$idOrProviderId;foreach($this->paymentTransactions(null,1000) as $row){if((string)($row['id']??'')===$lookup||(string)($row['providerTransactionId']??'')===$lookup)return $row;}return null;
    }

    /** @param array<string,mixed> $transaction */
    public function savePaymentTransaction(array $transaction): array
    {
        $id=trim((string)($transaction['id']??''));$record=['id'=>$id!==''?$id:bin2hex(random_bytes(8)),'orderId'=>isset($transaction['orderId'])?(string)$transaction['orderId']:null,'subscriptionId'=>isset($transaction['subscriptionId'])?(string)$transaction['subscriptionId']:null,'providerKey'=>(string)($transaction['providerKey']??'sandbox'),'providerTransactionId'=>$transaction['providerTransactionId']??null,'transactionType'=>(string)($transaction['transactionType']??'charge'),'status'=>(string)($transaction['status']??'pending'),'amount'=>round((float)($transaction['amount']??0),2),'currency'=>strtoupper((string)($transaction['currency']??'USD')),'last4'=>$transaction['last4']??null,'brand'=>$transaction['brand']??null,'payload'=>(array)($transaction['payload']??[]),'createdAt'=>now_iso(),'updatedAt'=>now_iso()];
        if($this->mode==='file'){$data=$this->readFileStore();$existing=(array)($data['paymentTransactions'][(string)$record['id']]??[]);$record['createdAt']=(string)($existing['createdAt']??$record['createdAt']);$data['paymentTransactions'][(string)$record['id']]=array_merge($existing,$record);$this->writeFileStore($data);return $data['paymentTransactions'][(string)$record['id']];}
        if($id!==''&&ctype_digit($id)){$stmt=$this->pdo()->prepare('UPDATE payment_transactions SET order_id=?,subscription_id=?,provider_key=?,provider_transaction_id=?,transaction_type=?,status=?,amount=?,currency=?,last4=?,brand=?,provider_payload_json=?,updated_at=NOW() WHERE id=?');$stmt->execute([$record['orderId']!==null?(int)$record['orderId']:null,$record['subscriptionId']!==null?(int)$record['subscriptionId']:null,$record['providerKey'],$record['providerTransactionId'],$record['transactionType'],$record['status'],$record['amount'],$record['currency'],$record['last4'],$record['brand'],json_encode($record['payload']),(int)$id]);return $this->paymentTransaction($id)??$record;}
        $stmt=$this->pdo()->prepare('INSERT INTO payment_transactions (order_id,subscription_id,provider_key,provider_transaction_id,transaction_type,status,amount,currency,last4,brand,provider_payload_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');$stmt->execute([$record['orderId']!==null?(int)$record['orderId']:null,$record['subscriptionId']!==null?(int)$record['subscriptionId']:null,$record['providerKey'],$record['providerTransactionId'],$record['transactionType'],$record['status'],$record['amount'],$record['currency'],$record['last4'],$record['brand'],json_encode($record['payload'])]);$record['id']=(string)$this->pdo()->lastInsertId();return $record;
    }

    public function webhookEvent(string $providerKey, string $eventId): ?array
    {
        if($this->mode==='file'){$data=$this->readFileStore();return isset($data['paymentWebhookEvents'][$providerKey.':'.$eventId])?(array)$data['paymentWebhookEvents'][$providerKey.':'.$eventId]:null;}$stmt=$this->pdo()->prepare('SELECT * FROM payment_webhook_events WHERE provider_key=? AND provider_event_id=? LIMIT 1');$stmt->execute([$providerKey,$eventId]);$row=$stmt->fetch();return is_array($row)?['id'=>(string)$row['id'],'providerKey'=>$row['provider_key'],'eventId'=>$row['provider_event_id'],'eventType'=>$row['event_type'],'status'=>$row['status'],'payload'=>safe_json_decode((string)($row['payload_json']??'{}')),'processedAt'=>$row['processed_at'],'createdAt'=>$row['created_at']]:null;
    }

    public function saveWebhookEvent(string $providerKey,string $eventId,string $eventType,array $payload,string $status='processed'): array
    {
        $existing=$this->webhookEvent($providerKey,$eventId);if($existing)return $existing;$record=['id'=>bin2hex(random_bytes(7)),'providerKey'=>$providerKey,'eventId'=>$eventId,'eventType'=>$eventType,'status'=>$status,'payload'=>$payload,'processedAt'=>$status==='processed'?now_iso():null,'createdAt'=>now_iso()];if($this->mode==='file'){$data=$this->readFileStore();$data['paymentWebhookEvents'][$providerKey.':'.$eventId]=$record;$this->writeFileStore($data);return $record;}$stmt=$this->pdo()->prepare('INSERT INTO payment_webhook_events (provider_key,provider_event_id,event_type,status,payload_json,processed_at,created_at) VALUES (?,?,?,?,?,?,NOW())');$stmt->execute([$providerKey,$eventId,$eventType,$status,json_encode($payload),$this->mysqlDate($record['processedAt'])]);$record['id']=(string)$this->pdo()->lastInsertId();return $record;
    }

    public function revokeEntitlementsBySource(string $sourceType, string|int $sourceId): int
    {
        $count=0;if($this->mode==='file'){$data=$this->readFileStore();foreach((array)($data['entitlements']??[]) as $id=>$row){if((string)($row['sourceType']??'')===$sourceType&&(string)($row['sourceId']??'')===(string)$sourceId&&(string)($row['status']??'')==='active'){$data['entitlements'][$id]['status']='revoked';$data['entitlements'][$id]['updatedAt']=now_iso();$count++;}}$this->writeFileStore($data);return $count;}$stmt=$this->pdo()->prepare("UPDATE content_entitlements SET status='revoked',updated_at=NOW() WHERE source_type=? AND source_id=? AND status='active'");$stmt->execute([$sourceType,ctype_digit((string)$sourceId)?(int)$sourceId:null]);return $stmt->rowCount();
    }

    public function commerceStats(): array
    {
        $orders=$this->orders(null,null,1000);$paid=array_values(array_filter($orders,static fn(array $o):bool=>in_array((string)($o['status']??''),['paid','partially_refunded','refunded'],true)));$revenue=0.0;$refunds=0.0;foreach($paid as $order){$revenue+=(float)($order['grandTotal']??0);$refunds+=(float)($order['refundedTotal']??0);}return ['orders'=>count($orders),'paidOrders'=>count($paid),'grossRevenue'=>round($revenue,2),'refundedRevenue'=>round($refunds,2),'netRevenue'=>round($revenue-$refunds,2),'coupons'=>count($this->coupons(false)),'bundles'=>count($this->bundles(false))];
    }

    private function mapPaymentProviderRow(array $row): array{return ['key'=>$row['provider_key'],'name'=>$row['name'],'enabled'=>(bool)$row['is_enabled'],'testMode'=>(bool)$row['test_mode'],'supportsRecurring'=>(bool)$row['supports_recurring'],'settings'=>safe_json_decode((string)($row['settings_json']??'{}')),'sortOrder'=>(int)$row['sort_order'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];}
    private function mapCouponRow(array $row): array{return ['id'=>(string)$row['id'],'code'=>$row['code'],'description'=>$row['description']??'','discountType'=>$row['discount_type'],'value'=>(float)$row['discount_value'],'scope'=>$row['scope'],'minimumSubtotal'=>(float)$row['minimum_subtotal'],'maximumDiscount'=>$row['maximum_discount']!==null?(float)$row['maximum_discount']:null,'usageLimit'=>(int)($row['usage_limit']??0),'perMemberLimit'=>(int)($row['per_member_limit']??0),'redemptionCount'=>(int)($row['redemption_count']??0),'startsAt'=>$row['starts_at'],'endsAt'=>$row['ends_at'],'isActive'=>(bool)$row['is_active'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];}
    private function mapBundleRow(array $row): array{return ['id'=>(string)$row['id'],'slug'=>$row['slug'],'title'=>$row['title'],'description'=>$row['description']??'','price'=>(float)$row['price'],'subscriberPrice'=>$row['subscriber_price']!==null?(float)$row['subscriber_price']:null,'currency'=>$row['currency'],'isActive'=>(bool)$row['is_active'],'sortOrder'=>(int)$row['sort_order'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];}
    private function mapOrderRow(array $row): array{return ['id'=>(string)$row['id'],'memberId'=>(string)$row['member_id'],'memberName'=>$row['display_name']??'','memberEmail'=>$row['email']??$row['billing_email']??'','orderNumber'=>$row['order_number'],'status'=>$row['status'],'subtotal'=>(float)$row['subtotal'],'discountTotal'=>(float)$row['discount_total'],'taxTotal'=>(float)$row['tax_total'],'grandTotal'=>(float)$row['grand_total'],'refundedTotal'=>(float)($row['refunded_total']??0),'currency'=>$row['currency'],'couponCode'=>$row['coupon_code'],'providerKey'=>$row['provider_key']??null,'providerCheckoutId'=>$row['provider_checkout_id']??null,'idempotencyKey'=>$row['idempotency_key']??null,'billingEmail'=>$row['billing_email']??'','metadata'=>safe_json_decode((string)($row['metadata_json']??'{}')),'paidAt'=>$row['paid_at'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];}
    private function mapOrderItemRow(array $row): array{return ['id'=>(string)$row['id'],'orderId'=>(string)$row['order_id'],'itemType'=>$row['item_type'],'itemId'=>(string)$row['item_id'],'title'=>$row['title'],'quantity'=>(int)$row['quantity'],'unitPrice'=>(float)$row['unit_price'],'discountTotal'=>(float)$row['discount_total'],'lineTotal'=>(float)$row['line_total'],'metadata'=>safe_json_decode((string)($row['metadata_json']??'{}')),'fulfillmentStatus'=>$row['fulfillment_status']??'pending','createdAt'=>$row['created_at']];}
    private function mapTransactionRow(array $row): array{return ['id'=>(string)$row['id'],'orderId'=>$row['order_id']!==null?(string)$row['order_id']:null,'subscriptionId'=>$row['subscription_id']!==null?(string)$row['subscription_id']:null,'providerKey'=>$row['provider_key'],'providerTransactionId'=>$row['provider_transaction_id'],'transactionType'=>$row['transaction_type'],'status'=>$row['status'],'amount'=>(float)$row['amount'],'currency'=>$row['currency'],'last4'=>$row['last4']??null,'brand'=>$row['brand']??null,'payload'=>safe_json_decode((string)($row['provider_payload_json']??'{}')),'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];}
}
