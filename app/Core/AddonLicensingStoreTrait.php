<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Persistence for local add-on licenses, publisher products, activations, releases, and update history. */
trait AddonLicensingStoreTrait
{
    /** @return array<string,mixed>|null */
    public function addonLicense(string $addonKey): ?array
    {
        $addonKey = strtolower(trim($addonKey));
        if ($addonKey === '') return null;
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $row = $data['addonLicenses'][$addonKey] ?? null;
            return is_array($row) ? $row : null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM addon_licenses WHERE addon_key = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$addonKey]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->mapAddonLicenseRow($row) : null;
    }

    /** @param array<string,mixed> $license @return array<string,mixed> */
    public function saveAddonLicense(array $license): array
    {
        $addonKey = strtolower(trim((string) ($license['addonKey'] ?? $license['addon_key'] ?? '')));
        if ($addonKey === '') throw new RuntimeException('Add-on license requires an add-on key.');
        $existing = $this->addonLicense($addonKey) ?? [];
        $record = array_merge([
            'id' => $existing['id'] ?? bin2hex(random_bytes(8)),
            'addonKey' => $addonKey,
            'licenseType' => 'free',
            'status' => 'inactive',
            'keyHash' => '',
            'keyEncrypted' => '',
            'keyPrefix' => '',
            'productKey' => '',
            'serverUrl' => '',
            'publicKey' => '',
            'domainName' => '',
            'installationId' => '',
            'expiresAt' => null,
            'updatesExpiresAt' => null,
            'supportExpiresAt' => null,
            'graceUntil' => null,
            'lastCheckedAt' => null,
            'lastSuccessfulAt' => null,
            'lastError' => null,
            'responseSignature' => '',
            'channel' => 'stable',
            'metadata' => [],
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ], $existing, $license);
        $record['addonKey'] = $addonKey;
        $record['channel'] = in_array((string) $record['channel'], ['stable','beta','development'], true) ? (string) $record['channel'] : 'stable';
        $record['metadata'] = (array) ($record['metadata'] ?? []);
        $record['updatedAt'] = now_iso();
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $data['addonLicenses'][$addonKey] = $record;
            $this->writeFileStore($data);
            return $record;
        }
        $stmt = $this->pdo()->prepare(
            'INSERT INTO addon_licenses (addon_key,license_key_hash,license_key_encrypted,license_key_prefix,license_type,status,product_key,server_url,public_key,domain_name,installation_id,expires_at,updates_expires_at,support_expires_at,grace_until,last_checked_at,last_successful_at,last_error,response_signature,update_channel,metadata_json,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE license_key_hash=VALUES(license_key_hash),license_key_encrypted=VALUES(license_key_encrypted),license_key_prefix=VALUES(license_key_prefix),license_type=VALUES(license_type),status=VALUES(status),product_key=VALUES(product_key),server_url=VALUES(server_url),public_key=VALUES(public_key),domain_name=VALUES(domain_name),expires_at=VALUES(expires_at),updates_expires_at=VALUES(updates_expires_at),support_expires_at=VALUES(support_expires_at),grace_until=VALUES(grace_until),last_checked_at=VALUES(last_checked_at),last_successful_at=VALUES(last_successful_at),last_error=VALUES(last_error),response_signature=VALUES(response_signature),update_channel=VALUES(update_channel),metadata_json=VALUES(metadata_json),updated_at=NOW()'
        );
        $stmt->execute([
            $addonKey, $record['keyHash'] ?: null, $record['keyEncrypted'] ?: null, $record['keyPrefix'] ?: null,
            $record['licenseType'], $record['status'], $record['productKey'] ?: null, $record['serverUrl'] ?: null,
            $record['publicKey'] ?: null, $record['domainName'] ?: null, $record['installationId'] ?: null,
            $this->sqlDate($record['expiresAt']), $this->sqlDate($record['updatesExpiresAt']), $this->sqlDate($record['supportExpiresAt']),
            $this->sqlDate($record['graceUntil']), $this->sqlDate($record['lastCheckedAt']), $this->sqlDate($record['lastSuccessfulAt']),
            $record['lastError'] ?: null, $record['responseSignature'] ?: null, $record['channel'],
            json_encode($record['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        return $record;
    }

    public function deleteAddonLicense(string $addonKey): void
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            unset($data['addonLicenses'][$addonKey]);
            $this->writeFileStore($data);
            return;
        }
        $this->pdo()->prepare('DELETE FROM addon_licenses WHERE addon_key = ?')->execute([$addonKey]);
    }

    /** @return list<array<string,mixed>> */
    public function addonLicenseProducts(?string $addonKey = null, bool $activeOnly = false): array
    {
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['addonLicenseProducts'] ?? []));
            if ($addonKey !== null) $rows = array_values(array_filter($rows, static fn(array $row): bool => ($row['addonKey'] ?? '') === $addonKey));
            if ($activeOnly) $rows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['active'])));
            usort($rows, static fn(array $a,array $b): int => strcasecmp((string)($a['name']??''),(string)($b['name']??'')));
            return $rows;
        }
        $where = []; $params = [];
        if ($addonKey !== null) { $where[] = 'addon_key = ?'; $params[] = $addonKey; }
        if ($activeOnly) $where[] = 'is_active = 1';
        $sql = 'SELECT * FROM addon_license_products' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY name, product_key';
        $stmt = $this->pdo()->prepare($sql); $stmt->execute($params);
        return array_map([$this,'mapAddonLicenseProductRow'],$stmt->fetchAll());
    }

    public function addonLicenseProduct(string $productKey): ?array
    {
        foreach ($this->addonLicenseProducts() as $product) if ((string)$product['productKey'] === $productKey) return $product;
        return null;
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    public function saveAddonLicenseProduct(array $product): array
    {
        $key = strtolower(trim((string) ($product['productKey'] ?? $product['key'] ?? '')));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,189}$/', $key) !== 1) throw new RuntimeException('License product key is invalid.');
        $existing = $this->addonLicenseProduct($key) ?? [];
        $record = array_merge([
            'id'=>$existing['id']??bin2hex(random_bytes(8)), 'productKey'=>$key, 'addonKey'=>'', 'name'=>$key,
            'licenseType'=>'lifetime','billingInterval'=>'one_time','durationDays'=>0,'siteLimit'=>1,'updatesDays'=>365,
            'supportDays'=>365,'graceDays'=>7,'price'=>0.0,'currency'=>'USD','active'=>true,'metadata'=>[],
            'createdAt'=>now_iso(),'updatedAt'=>now_iso(),
        ], $existing, $product);
        $record['productKey']=$key; $record['addonKey']=strtolower(trim((string)$record['addonKey']));
        if ($record['addonKey']==='') throw new RuntimeException('License product requires an add-on key.');
        $record['licenseType']=in_array((string)$record['licenseType'],['free','trial','one_time','monthly','annual','lifetime'],true)?(string)$record['licenseType']:'lifetime';
        $record['siteLimit']=max(1,min(100000,(int)$record['siteLimit']));
        $record['graceDays']=max(0,min(90,(int)$record['graceDays']));
        $record['metadata']=(array)($record['metadata']??[]); $record['updatedAt']=now_iso();
        if ($this->mode==='file') { $data=$this->readFileStore();$data['addonLicenseProducts'][$key]=$record;$this->writeFileStore($data);return $record; }
        $stmt=$this->pdo()->prepare('INSERT INTO addon_license_products (product_key,addon_key,name,license_type,billing_interval,duration_days,site_limit,updates_days,support_days,grace_days,price,currency,is_active,metadata_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE addon_key=VALUES(addon_key),name=VALUES(name),license_type=VALUES(license_type),billing_interval=VALUES(billing_interval),duration_days=VALUES(duration_days),site_limit=VALUES(site_limit),updates_days=VALUES(updates_days),support_days=VALUES(support_days),grace_days=VALUES(grace_days),price=VALUES(price),currency=VALUES(currency),is_active=VALUES(is_active),metadata_json=VALUES(metadata_json),updated_at=NOW()');
        $stmt->execute([$key,$record['addonKey'],$record['name'],$record['licenseType'],$record['billingInterval'],(int)$record['durationDays'],(int)$record['siteLimit'],(int)$record['updatesDays'],(int)$record['supportDays'],(int)$record['graceDays'],(float)$record['price'],strtoupper((string)$record['currency']),$record['active']?1:0,json_encode($record['metadata'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        return $record;
    }

    /** @return list<array<string,mixed>> */
    public function issuedAddonLicenses(?string $addonKey = null, ?string $status = null): array
    {
        if ($this->mode==='file') {
            $data=$this->readFileStore();$rows=array_values((array)($data['issuedAddonLicenses']??[]));
            if($addonKey!==null)$rows=array_values(array_filter($rows,static fn(array $r):bool=>($r['addonKey']??'')===$addonKey));
            if($status!==null)$rows=array_values(array_filter($rows,static fn(array $r):bool=>($r['status']??'')===$status));
            usort($rows,static fn(array $a,array $b):int=>strcmp((string)($b['createdAt']??''),(string)($a['createdAt']??'')));
            return $rows;
        }
        $where=[];$params=[]; if($addonKey!==null){$where[]='addon_key=?';$params[]=$addonKey;} if($status!==null){$where[]='status=?';$params[]=$status;}
        $stmt=$this->pdo()->prepare('SELECT * FROM addon_license_keys'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC');$stmt->execute($params);
        return array_map([$this,'mapIssuedAddonLicenseRow'],$stmt->fetchAll());
    }

    public function issuedAddonLicense(string|int $id): ?array
    {
        if($this->mode==='file'){foreach($this->issuedAddonLicenses() as $row)if((string)$row['id']===(string)$id)return $row;return null;}
        $stmt=$this->pdo()->prepare('SELECT * FROM addon_license_keys WHERE id=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();return is_array($row)?$this->mapIssuedAddonLicenseRow($row):null;
    }

    public function issuedAddonLicenseByHash(string $hash): ?array
    {
        if($this->mode==='file'){foreach($this->issuedAddonLicenses() as $row)if(hash_equals((string)($row['keyHash']??''),$hash))return $row;return null;}
        $stmt=$this->pdo()->prepare('SELECT * FROM addon_license_keys WHERE license_key_hash=? LIMIT 1');$stmt->execute([$hash]);$row=$stmt->fetch();return is_array($row)?$this->mapIssuedAddonLicenseRow($row):null;
    }

    /** @param array<string,mixed> $license @return array<string,mixed> */
    public function saveIssuedAddonLicense(array $license): array
    {
        $existing=!empty($license['id'])?$this->issuedAddonLicense((string)$license['id']):null;
        $record=array_merge([
            'id'=>$existing['id']??bin2hex(random_bytes(8)),'productKey'=>'','addonKey'=>'','keyHash'=>'','keyPrefix'=>'','customerEmail'=>'',
            'status'=>'active','startsAt'=>now_iso(),'expiresAt'=>null,'updatesExpiresAt'=>null,'supportExpiresAt'=>null,
            'maxActivations'=>1,'metadata'=>[],'createdAt'=>now_iso(),'updatedAt'=>now_iso(),
        ],$existing??[],$license);
        if($record['productKey']===''||$record['addonKey']===''||$record['keyHash']==='')throw new RuntimeException('Issued license is missing product, add-on, or key data.');
        $record['maxActivations']=max(1,min(100000,(int)$record['maxActivations']));$record['metadata']=(array)($record['metadata']??[]);$record['updatedAt']=now_iso();
        if($this->mode==='file'){$data=$this->readFileStore();$data['issuedAddonLicenses'][(string)$record['id']]=$record;$this->writeFileStore($data);return $record;}
        if($existing===null){$stmt=$this->pdo()->prepare('INSERT INTO addon_license_keys (product_key,addon_key,license_key_hash,license_key_prefix,customer_email,status,starts_at,expires_at,updates_expires_at,support_expires_at,max_activations,metadata_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');$stmt->execute([$record['productKey'],$record['addonKey'],$record['keyHash'],$record['keyPrefix'],$record['customerEmail']?:null,$record['status'],$this->sqlDate($record['startsAt']),$this->sqlDate($record['expiresAt']),$this->sqlDate($record['updatesExpiresAt']),$this->sqlDate($record['supportExpiresAt']),(int)$record['maxActivations'],json_encode($record['metadata'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);$record['id']=(string)$this->pdo()->lastInsertId();}
        else{$stmt=$this->pdo()->prepare('UPDATE addon_license_keys SET product_key=?,addon_key=?,license_key_hash=?,license_key_prefix=?,customer_email=?,status=?,starts_at=?,expires_at=?,updates_expires_at=?,support_expires_at=?,max_activations=?,metadata_json=?,updated_at=NOW() WHERE id=?');$stmt->execute([$record['productKey'],$record['addonKey'],$record['keyHash'],$record['keyPrefix'],$record['customerEmail']?:null,$record['status'],$this->sqlDate($record['startsAt']),$this->sqlDate($record['expiresAt']),$this->sqlDate($record['updatesExpiresAt']),$this->sqlDate($record['supportExpiresAt']),(int)$record['maxActivations'],json_encode($record['metadata'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$record['id']]);}
        return $record;
    }

    /** @return list<array<string,mixed>> */
    public function addonLicenseActivations(string|int|null $licenseId = null, ?string $addonKey = null): array
    {
        if($this->mode==='file'){$data=$this->readFileStore();$rows=array_values((array)($data['addonLicenseActivations']??[]));if($licenseId!==null)$rows=array_values(array_filter($rows,static fn(array $r):bool=>(string)($r['licenseId']??'')===(string)$licenseId));if($addonKey!==null)$rows=array_values(array_filter($rows,static fn(array $r):bool=>($r['addonKey']??'')===$addonKey));return $rows;}
        $where=[];$params=[];if($licenseId!==null){$where[]='license_id=?';$params[]=$licenseId;}if($addonKey!==null){$where[]='addon_key=?';$params[]=$addonKey;}$stmt=$this->pdo()->prepare('SELECT * FROM addon_license_activations'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC');$stmt->execute($params);return array_map([$this,'mapAddonLicenseActivationRow'],$stmt->fetchAll());
    }

    public function addonLicenseActivation(string|int $licenseId,string $installationId):?array
    {foreach($this->addonLicenseActivations($licenseId) as $row)if((string)$row['installationId']===$installationId)return $row;return null;}

    /** @param array<string,mixed> $activation @return array<string,mixed> */
    public function saveAddonLicenseActivation(array $activation):array
    {
        $existing=(!empty($activation['licenseId'])&&!empty($activation['installationId']))?$this->addonLicenseActivation((string)$activation['licenseId'],(string)$activation['installationId']):null;
        $record=array_merge(['id'=>$existing['id']??bin2hex(random_bytes(8)),'licenseId'=>'','addonKey'=>'','installationId'=>'','domainName'=>'','siteUrl'=>'','status'=>'active','activatedAt'=>now_iso(),'lastSeenAt'=>now_iso(),'deactivatedAt'=>null,'metadata'=>[]],$existing??[],$activation);
        if($record['licenseId']===''||$record['addonKey']===''||$record['installationId']==='')throw new RuntimeException('License activation is incomplete.');$record['metadata']=(array)$record['metadata'];
        if($this->mode==='file'){$data=$this->readFileStore();$key=(string)$record['licenseId'].':'.(string)$record['installationId'];$data['addonLicenseActivations'][$key]=$record;$this->writeFileStore($data);return $record;}
        $stmt=$this->pdo()->prepare('INSERT INTO addon_license_activations (license_id,addon_key,installation_id,domain_name,site_url,status,activated_at,last_seen_at,deactivated_at,metadata_json) VALUES (?,?,?,?,?,?,COALESCE(?,NOW()),COALESCE(?,NOW()),?,?) ON DUPLICATE KEY UPDATE domain_name=VALUES(domain_name),site_url=VALUES(site_url),status=VALUES(status),last_seen_at=VALUES(last_seen_at),deactivated_at=VALUES(deactivated_at),metadata_json=VALUES(metadata_json)');$stmt->execute([$record['licenseId'],$record['addonKey'],$record['installationId'],$record['domainName']?:null,$record['siteUrl']?:null,$record['status'],$this->sqlDate($record['activatedAt']),$this->sqlDate($record['lastSeenAt']),$this->sqlDate($record['deactivatedAt']),json_encode($record['metadata'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);if($existing===null)$record['id']=(string)$this->pdo()->lastInsertId();return $record;
    }

    /** @return list<array<string,mixed>> */
    public function addonReleases(?string $addonKey=null,?string $channel=null,bool $publishedOnly=false):array
    {
        if($this->mode==='file'){$data=$this->readFileStore();$rows=array_values((array)($data['addonReleases']??[]));if($addonKey!==null)$rows=array_values(array_filter($rows,static fn(array $r):bool=>($r['addonKey']??'')===$addonKey));if($channel!==null)$rows=array_values(array_filter($rows,static fn(array $r):bool=>($r['channel']??'')===$channel));if($publishedOnly)$rows=array_values(array_filter($rows,static fn(array $r):bool=>!empty($r['published'])));usort($rows,static fn(array $a,array $b):int=>version_compare((string)($b['version']??'0.0.0'),(string)($a['version']??'0.0.0')));return $rows;}
        $where=[];$params=[];if($addonKey!==null){$where[]='addon_key=?';$params[]=$addonKey;}if($channel!==null){$where[]='channel=?';$params[]=$channel;}if($publishedOnly)$where[]='is_published=1';$stmt=$this->pdo()->prepare('SELECT * FROM addon_releases'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY published_at DESC,id DESC');$stmt->execute($params);return array_map([$this,'mapAddonReleaseRow'],$stmt->fetchAll());
    }

    public function addonRelease(string|int $id):?array{foreach($this->addonReleases() as $row)if((string)$row['id']===(string)$id)return $row;return null;}

    /** @param array<string,mixed> $release @return array<string,mixed> */
    public function saveAddonRelease(array $release):array
    {
        $existing=!empty($release['id'])?$this->addonRelease((string)$release['id']):null;$record=array_merge(['id'=>$existing['id']??bin2hex(random_bytes(8)),'addonKey'=>'','version'=>'','channel'=>'stable','packagePath'=>'','packageSha256'=>'','packageSize'=>0,'signatureStatus'=>'unsigned','minimumCore'=>VELVET_VIXXEN_VERSION,'maximumCore'=>'','minimumPhp'=>'8.2.0','changelog'=>'','releaseNotesUrl'=>'','published'=>false,'publishedAt'=>null,'metadata'=>[],'createdAt'=>now_iso(),'updatedAt'=>now_iso()],$existing??[],$release);
        if($record['addonKey']===''||preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/',(string)$record['version'])!==1)throw new RuntimeException('Add-on release requires an add-on id and semantic version.');$record['channel']=in_array((string)$record['channel'],['stable','beta','development'],true)?(string)$record['channel']:'stable';$record['metadata']=(array)$record['metadata'];$record['updatedAt']=now_iso();if($record['published']&&empty($record['publishedAt']))$record['publishedAt']=now_iso();
        if($this->mode==='file'){$data=$this->readFileStore();$key=(string)$record['addonKey'].':'.(string)$record['channel'].':'.(string)$record['version'];$record['id']=$existing['id']??$key;$data['addonReleases'][$key]=$record;$this->writeFileStore($data);return $record;}
        $stmt=$this->pdo()->prepare('INSERT INTO addon_releases (addon_key,version,channel,package_path,package_sha256,package_size,signature_status,minimum_core_version,maximum_core_version,minimum_php_version,changelog,release_notes_url,is_published,published_at,metadata_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE package_path=VALUES(package_path),package_sha256=VALUES(package_sha256),package_size=VALUES(package_size),signature_status=VALUES(signature_status),minimum_core_version=VALUES(minimum_core_version),maximum_core_version=VALUES(maximum_core_version),minimum_php_version=VALUES(minimum_php_version),changelog=VALUES(changelog),release_notes_url=VALUES(release_notes_url),is_published=VALUES(is_published),published_at=VALUES(published_at),metadata_json=VALUES(metadata_json),updated_at=NOW()');$stmt->execute([$record['addonKey'],$record['version'],$record['channel'],$record['packagePath'],$record['packageSha256'],(int)$record['packageSize'],$record['signatureStatus'],$record['minimumCore'],$record['maximumCore']?:null,$record['minimumPhp'],$record['changelog'],$record['releaseNotesUrl']?:null,$record['published']?1:0,$this->sqlDate($record['publishedAt']),json_encode($record['metadata'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);return $record;
    }

    /** @param array<string,mixed> $check @return array<string,mixed> */
    public function saveAddonUpdateCheck(array $check):array
    {
        $record=array_merge(['id'=>bin2hex(random_bytes(8)),'addonKey'=>'','installationId'=>'','currentVersion'=>'','channel'=>'stable','status'=>'none','availableVersion'=>null,'response'=>[],'checkedAt'=>now_iso()],$check);
        if($this->mode==='file'){$data=$this->readFileStore();$data['addonUpdateChecks'][]=$record;$data['addonUpdateChecks']=array_slice((array)$data['addonUpdateChecks'],-500);$this->writeFileStore($data);return $record;}
        $stmt=$this->pdo()->prepare('INSERT INTO addon_update_checks (addon_key,installation_id,current_version,channel,status,available_version,response_json,checked_at) VALUES (?,?,?,?,?,?,?,NOW())');$stmt->execute([$record['addonKey'],$record['installationId']?:null,$record['currentVersion'],$record['channel'],$record['status'],$record['availableVersion']?:null,json_encode($record['response'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);$record['id']=(string)$this->pdo()->lastInsertId();return $record;
    }

    public function latestAddonUpdateCheck(string $addonKey):?array
    {
        if($this->mode==='file'){$data=$this->readFileStore();$rows=array_values(array_filter((array)($data['addonUpdateChecks']??[]),static fn(array $r):bool=>($r['addonKey']??'')===$addonKey));return $rows===[]?null:$rows[array_key_last($rows)];}
        $stmt=$this->pdo()->prepare('SELECT * FROM addon_update_checks WHERE addon_key=? ORDER BY id DESC LIMIT 1');$stmt->execute([$addonKey]);$row=$stmt->fetch();if(!is_array($row))return null;return ['id'=>(string)$row['id'],'addonKey'=>(string)$row['addon_key'],'installationId'=>(string)($row['installation_id']??''),'currentVersion'=>(string)$row['current_version'],'channel'=>(string)$row['channel'],'status'=>(string)$row['status'],'availableVersion'=>$row['available_version']??null,'response'=>safe_json_decode((string)($row['response_json']??'{}')),'checkedAt'=>(string)$row['checked_at']];
    }

    /** @return list<array<string,mixed>> */
    public function addonLicenseTransactions(string|int|null $licenseId = null, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $rows = array_values((array) ($data['addonLicenseTransactions'] ?? []));
            if ($licenseId !== null) $rows = array_values(array_filter($rows, static fn(array $row): bool => (string) ($row['licenseId'] ?? '') === (string) $licenseId));
            usort($rows, static fn(array $a,array $b): int => strcmp((string)($b['createdAt']??''),(string)($a['createdAt']??'')));
            return array_slice($rows,0,$limit);
        }
        if ($licenseId !== null) {
            $stmt=$this->pdo()->prepare('SELECT * FROM addon_license_transactions WHERE license_id=? ORDER BY id DESC LIMIT '.$limit);$stmt->execute([$licenseId]);$rows=$stmt->fetchAll();
        } else {
            $rows=$this->pdo()->query('SELECT * FROM addon_license_transactions ORDER BY id DESC LIMIT '.$limit)->fetchAll();
        }
        return array_map(static fn(array $row):array=>['id'=>(string)$row['id'],'licenseId'=>(string)$row['license_id'],'eventType'=>(string)$row['event_type'],'amount'=>(float)$row['amount'],'currency'=>(string)$row['currency'],'reference'=>(string)($row['reference_key']??''),'metadata'=>safe_json_decode((string)($row['metadata_json']??'{}')),'createdAt'=>(string)$row['created_at']],$rows);
    }

    /** @param array<string,mixed> $transaction */
    public function saveAddonLicenseTransaction(array $transaction):array
    {
        $record=array_merge(['id'=>bin2hex(random_bytes(8)),'licenseId'=>'','eventType'=>'purchase','amount'=>0.0,'currency'=>'USD','reference'=>'','metadata'=>[],'createdAt'=>now_iso()],$transaction);
        if($this->mode==='file'){$data=$this->readFileStore();$data['addonLicenseTransactions'][]=$record;$this->writeFileStore($data);return $record;}
        $stmt=$this->pdo()->prepare('INSERT INTO addon_license_transactions (license_id,event_type,amount,currency,reference_key,metadata_json,created_at) VALUES (?,?,?,?,?,?,NOW())');$stmt->execute([$record['licenseId'],$record['eventType'],(float)$record['amount'],strtoupper((string)$record['currency']),$record['reference']?:null,json_encode((array)$record['metadata'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);$record['id']=(string)$this->pdo()->lastInsertId();return $record;
    }

    /** @param array<string,mixed> $token */
    public function saveAddonDownloadToken(array $token):array
    {
        $record=array_merge(['id'=>bin2hex(random_bytes(8)),'tokenHash'=>'','licenseId'=>'','releaseId'=>'','installationId'=>'','expiresAt'=>gmdate('c',time()+300),'lastUsedAt'=>null,'downloadCount'=>0,'createdAt'=>now_iso()],$token);
        if($this->mode==='file'){$data=$this->readFileStore();$data['addonDownloadTokens'][(string)$record['tokenHash']]=$record;$this->writeFileStore($data);return $record;}
        $stmt=$this->pdo()->prepare('INSERT INTO addon_download_tokens (token_hash,license_id,release_id,installation_id,expires_at,last_used_at,download_count,created_at) VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at),last_used_at=VALUES(last_used_at),download_count=VALUES(download_count)');$stmt->execute([$record['tokenHash'],$record['licenseId'],$record['releaseId'],$record['installationId']?:null,$this->sqlDate($record['expiresAt']),$this->sqlDate($record['lastUsedAt']),(int)$record['downloadCount']]);return $record;
    }

    public function addonDownloadToken(string $hash):?array
    {
        if($this->mode==='file'){$data=$this->readFileStore();$row=$data['addonDownloadTokens'][$hash]??null;return is_array($row)?$row:null;}
        $stmt=$this->pdo()->prepare('SELECT * FROM addon_download_tokens WHERE token_hash=? LIMIT 1');$stmt->execute([$hash]);$row=$stmt->fetch();if(!is_array($row))return null;return ['id'=>(string)$row['id'],'tokenHash'=>(string)$row['token_hash'],'licenseId'=>(string)$row['license_id'],'releaseId'=>(string)$row['release_id'],'installationId'=>(string)($row['installation_id']??''),'expiresAt'=>(string)$row['expires_at'],'lastUsedAt'=>$row['last_used_at']??null,'downloadCount'=>(int)$row['download_count'],'createdAt'=>(string)$row['created_at']];
    }

    /** @param array<string,mixed> $row */
    private function mapAddonLicenseRow(array $row):array{return ['id'=>(string)$row['id'],'addonKey'=>(string)$row['addon_key'],'keyHash'=>(string)($row['license_key_hash']??''),'keyEncrypted'=>(string)($row['license_key_encrypted']??''),'keyPrefix'=>(string)($row['license_key_prefix']??''),'licenseType'=>(string)$row['license_type'],'status'=>(string)$row['status'],'productKey'=>(string)($row['product_key']??''),'serverUrl'=>(string)($row['server_url']??''),'publicKey'=>(string)($row['public_key']??''),'domainName'=>(string)($row['domain_name']??''),'installationId'=>(string)($row['installation_id']??''),'expiresAt'=>$row['expires_at']??null,'updatesExpiresAt'=>$row['updates_expires_at']??null,'supportExpiresAt'=>$row['support_expires_at']??null,'graceUntil'=>$row['grace_until']??null,'lastCheckedAt'=>$row['last_checked_at']??null,'lastSuccessfulAt'=>$row['last_successful_at']??null,'lastError'=>$row['last_error']??null,'responseSignature'=>(string)($row['response_signature']??''),'channel'=>(string)($row['update_channel']??'stable'),'metadata'=>safe_json_decode((string)($row['metadata_json']??'{}')),'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at']];}
    /** @param array<string,mixed> $row */
    private function mapAddonLicenseProductRow(array $row):array{return ['id'=>(string)$row['id'],'productKey'=>(string)$row['product_key'],'addonKey'=>(string)$row['addon_key'],'name'=>(string)$row['name'],'licenseType'=>(string)$row['license_type'],'billingInterval'=>(string)$row['billing_interval'],'durationDays'=>(int)$row['duration_days'],'siteLimit'=>(int)$row['site_limit'],'updatesDays'=>(int)$row['updates_days'],'supportDays'=>(int)$row['support_days'],'graceDays'=>(int)$row['grace_days'],'price'=>(float)$row['price'],'currency'=>(string)$row['currency'],'active'=>(bool)$row['is_active'],'metadata'=>safe_json_decode((string)($row['metadata_json']??'{}')),'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at']];}
    /** @param array<string,mixed> $row */
    private function mapIssuedAddonLicenseRow(array $row):array{return ['id'=>(string)$row['id'],'productKey'=>(string)$row['product_key'],'addonKey'=>(string)$row['addon_key'],'keyHash'=>(string)$row['license_key_hash'],'keyPrefix'=>(string)$row['license_key_prefix'],'customerEmail'=>(string)($row['customer_email']??''),'status'=>(string)$row['status'],'startsAt'=>$row['starts_at']??null,'expiresAt'=>$row['expires_at']??null,'updatesExpiresAt'=>$row['updates_expires_at']??null,'supportExpiresAt'=>$row['support_expires_at']??null,'maxActivations'=>(int)$row['max_activations'],'metadata'=>safe_json_decode((string)($row['metadata_json']??'{}')),'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at']];}
    /** @param array<string,mixed> $row */
    private function mapAddonLicenseActivationRow(array $row):array{return ['id'=>(string)$row['id'],'licenseId'=>(string)$row['license_id'],'addonKey'=>(string)$row['addon_key'],'installationId'=>(string)$row['installation_id'],'domainName'=>(string)($row['domain_name']??''),'siteUrl'=>(string)($row['site_url']??''),'status'=>(string)$row['status'],'activatedAt'=>(string)$row['activated_at'],'lastSeenAt'=>(string)$row['last_seen_at'],'deactivatedAt'=>$row['deactivated_at']??null,'metadata'=>safe_json_decode((string)($row['metadata_json']??'{}'))];}
    /** @param array<string,mixed> $row */
    private function mapAddonReleaseRow(array $row):array{return ['id'=>(string)$row['id'],'addonKey'=>(string)$row['addon_key'],'version'=>(string)$row['version'],'channel'=>(string)$row['channel'],'packagePath'=>(string)$row['package_path'],'packageSha256'=>(string)$row['package_sha256'],'packageSize'=>(int)$row['package_size'],'signatureStatus'=>(string)$row['signature_status'],'minimumCore'=>(string)$row['minimum_core_version'],'maximumCore'=>(string)($row['maximum_core_version']??''),'minimumPhp'=>(string)$row['minimum_php_version'],'changelog'=>(string)($row['changelog']??''),'releaseNotesUrl'=>(string)($row['release_notes_url']??''),'published'=>(bool)$row['is_published'],'publishedAt'=>$row['published_at']??null,'metadata'=>safe_json_decode((string)($row['metadata_json']??'{}')),'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at']];}
}
