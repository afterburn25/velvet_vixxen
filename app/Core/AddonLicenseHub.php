<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Publisher-side license and release service. It can be deployed with the main
 * application for a small vendor or moved behind the same JSON contract later.
 */
final class AddonLicenseHub
{
    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
        private readonly AddonPackageInspector $inspector = new AddonPackageInspector(),
    ) {
    }

    /** @return array{public:string,fingerprint:string} */
    public function ensureSigningKeys(): array
    {
        $directory = root_path('storage/private/licensing');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The private licensing directory could not be created.');
        }
        @chmod($directory, 0775);
        $privatePath = $directory . '/hub-private.key';
        $publicPath = $directory . '/hub-public.key';
        $private = is_file($privatePath) ? trim((string) file_get_contents($privatePath)) : '';
        $public = is_file($publicPath) ? trim((string) file_get_contents($publicPath)) : '';
        if ($private === '' || $public === '') {
            $keys = SignedPayload::generateKeyPair();
            if (file_put_contents($privatePath, $keys['private'], LOCK_EX) === false
                || file_put_contents($publicPath, $keys['public'], LOCK_EX) === false) {
                @unlink($privatePath); @unlink($publicPath);
                throw new RuntimeException('The licensing signing keys could not be written.');
            }
            @chmod($privatePath, 0640); @chmod($publicPath, 0640);
            $private = $keys['private']; $public = $keys['public'];
        }
        // Validate the key pair before returning a public identity.
        $probe = ['purpose'=>'velvet-vixxen-license-key-check','time'=>gmdate('c')];
        $signature = SignedPayload::sign($probe, $private);
        if (!SignedPayload::verify($probe, $signature, $public)) {
            throw new RuntimeException('The licensing signing key pair failed verification.');
        }
        return ['public'=>$public,'fingerprint'=>SignedPayload::fingerprint($public)];
    }

    /** @return array{enabled:bool,publicKey:string,fingerprint:string,endpoint:string,error:?string} */
    public function status(): array
    {
        try {
            $keys = $this->ensureSigningKeys();
            return [
                'enabled'=>true,'publicKey'=>$keys['public'],'fingerprint'=>$keys['fingerprint'],
                'endpoint'=>base_url('api/addon-license.php'),'error'=>null,
            ];
        } catch (\Throwable $e) {
            return ['enabled'=>false,'publicKey'=>'','fingerprint'=>'','endpoint'=>base_url('api/addon-license.php'),'error'=>$e->getMessage()];
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function createProduct(array $data): array
    {
        return $this->store->saveAddonLicenseProduct($data);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{license:array<string,mixed>,licenseKey:string}
     */
    public function issueLicense(array $data): array
    {
        $product = $this->store->addonLicenseProduct((string) ($data['productKey'] ?? ''));
        if ($product === null || empty($product['active'])) {
            throw new RuntimeException('Choose an active add-on license product.');
        }
        $rawKey = isset($data['licenseKey']) && trim((string) $data['licenseKey']) !== ''
            ? $this->normalizeLicenseKey((string) $data['licenseKey'])
            : $this->generateLicenseKey();
        $hash = $this->hashLicenseKey($rawKey);
        if ($this->store->issuedAddonLicenseByHash($hash) !== null) {
            throw new RuntimeException('That license key already exists.');
        }
        $now = time();
        $duration = max(0, (int) ($product['durationDays'] ?? 0));
        $updatesDays = max(0, (int) ($product['updatesDays'] ?? 0));
        $supportDays = max(0, (int) ($product['supportDays'] ?? 0));
        $startsAt = gmdate('c', $now);
        $expiresAt = $duration > 0 ? gmdate('c', $now + $duration * 86400) : null;
        $updatesExpiresAt = $updatesDays > 0 ? gmdate('c', $now + $updatesDays * 86400) : null;
        $supportExpiresAt = $supportDays > 0 ? gmdate('c', $now + $supportDays * 86400) : null;
        if (in_array((string) $product['licenseType'], ['monthly','annual'], true) && $expiresAt === null) {
            $expiresAt = gmdate('c', $now + ((string)$product['licenseType'] === 'annual' ? 365 : 31) * 86400);
        }
        $license = $this->store->saveIssuedAddonLicense([
            'productKey'=>(string)$product['productKey'],
            'addonKey'=>(string)$product['addonKey'],
            'keyHash'=>$hash,
            'keyPrefix'=>$this->maskLicenseKey($rawKey),
            'customerEmail'=>strtolower(trim((string)($data['customerEmail']??''))),
            'status'=>'active','startsAt'=>$startsAt,'expiresAt'=>$expiresAt,
            'updatesExpiresAt'=>$updatesExpiresAt,'supportExpiresAt'=>$supportExpiresAt,
            'maxActivations'=>(int)$product['siteLimit'],
            'metadata'=>array_merge((array)($data['metadata']??[]),['licenseType'=>$product['licenseType'],'graceDays'=>(int)$product['graceDays']]),
        ]);
        $this->store->saveAddonLicenseTransaction([
            'licenseId'=>(string)$license['id'],'eventType'=>'purchase','amount'=>(float)($data['amount']??$product['price']??0),
            'currency'=>(string)($data['currency']??$product['currency']??'USD'),'reference'=>(string)($data['reference']??''),
            'metadata'=>['productKey'=>$product['productKey'],'customerEmail'=>$license['customerEmail']],
        ]);
        $this->store->logAddonActivity((string)$license['addonKey'],'license.issued',['licenseId'=>$license['id'],'productKey'=>$license['productKey'],'prefix'=>$license['keyPrefix']]);
        return ['license'=>$license,'licenseKey'=>$rawKey];
    }

    /** @return array<string,mixed> */
    public function setLicenseStatus(string|int $licenseId, string $status, string $reason = ''): array
    {
        if (!in_array($status, ['active','suspended','expired','revoked','refunded'], true)) {
            throw new RuntimeException('Choose a supported license status.');
        }
        $license = $this->store->issuedAddonLicense($licenseId);
        if ($license === null) throw new RuntimeException('The issued license could not be found.');
        $license['status'] = $status;
        $metadata = (array) ($license['metadata'] ?? []);
        if ($reason !== '') $metadata['statusReason'] = $reason;
        $metadata['statusChangedAt'] = now_iso();
        $license['metadata'] = $metadata;
        $license = $this->store->saveIssuedAddonLicense($license);
        foreach ($this->store->addonLicenseActivations($licenseId) as $activation) {
            if ($status === 'active') continue;
            $activation['status'] = $status === 'revoked' || $status === 'refunded' ? 'revoked' : 'deactivated';
            $activation['deactivatedAt'] = now_iso();
            $this->store->saveAddonLicenseActivation($activation);
        }
        if (in_array($status, ['refunded','revoked'], true)) {
            $this->store->saveAddonLicenseTransaction(['licenseId'=>(string)$licenseId,'eventType'=>$status === 'refunded'?'refund':'revocation','metadata'=>['reason'=>$reason]]);
        }
        $this->store->logAddonActivity((string)$license['addonKey'],'license.status.changed',['licenseId'=>$licenseId,'status'=>$status,'reason'=>$reason]);
        return $license;
    }

    /** @return array<string,mixed> */
    public function renewLicense(string|int $licenseId, int $days, float $amount = 0.0, string $reference = ''): array
    {
        $license = $this->store->issuedAddonLicense($licenseId);
        if ($license === null) throw new RuntimeException('The issued license could not be found.');
        $days = max(1, min(3650, $days));
        $base = max(time(), strtotime((string)($license['expiresAt']??'')) ?: 0);
        $license['expiresAt'] = gmdate('c', $base + $days * 86400);
        $updatesBase = max(time(), strtotime((string)($license['updatesExpiresAt']??'')) ?: 0);
        $license['updatesExpiresAt'] = gmdate('c', $updatesBase + $days * 86400);
        $supportBase = max(time(), strtotime((string)($license['supportExpiresAt']??'')) ?: 0);
        $license['supportExpiresAt'] = gmdate('c', $supportBase + $days * 86400);
        $license['status'] = 'active';
        $license = $this->store->saveIssuedAddonLicense($license);
        $this->store->saveAddonLicenseTransaction(['licenseId'=>(string)$licenseId,'eventType'=>'renewal','amount'=>$amount,'reference'=>$reference,'metadata'=>['days'=>$days]]);
        return $license;
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function activate(array $request): array
    {
        $license = $this->resolveRequestLicense($request);
        $product = $this->requiredProduct((string)$license['productKey']);
        $this->assertLicenseUsable($license);
        $this->assertRequestIdentity($license, $request);
        $installationId = $this->requiredInstallationId($request);
        $existing = $this->store->addonLicenseActivation((string)$license['id'], $installationId);
        $active = array_values(array_filter($this->store->addonLicenseActivations((string)$license['id']), static fn(array $row): bool => ($row['status']??'') === 'active'));
        if ($existing === null && count($active) >= (int)$license['maxActivations']) {
            throw new RuntimeException('This license has reached its site activation limit. Deactivate another site first.');
        }
        $siteUrl = $this->normalizeSiteUrl((string)($request['site_url']??''));
        $siteDomain = $this->domainFromUrl($siteUrl);
        if ($existing !== null && ($existing['status'] ?? '') === 'active'
            && (string) ($existing['domainName'] ?? '') !== ''
            && !hash_equals((string) $existing['domainName'], $siteDomain)) {
            throw new RuntimeException('This installation id is already bound to another domain. Deactivate it before moving the license.');
        }
        $activation = $this->store->saveAddonLicenseActivation([
            'licenseId'=>(string)$license['id'],'addonKey'=>(string)$license['addonKey'],'installationId'=>$installationId,
            'domainName'=>$siteDomain,'siteUrl'=>$siteUrl,'status'=>'active',
            'activatedAt'=>$existing['activatedAt']??now_iso(),'lastSeenAt'=>now_iso(),'deactivatedAt'=>null,
            'metadata'=>['clientVersion'=>(string)($request['core_version']??''),'addonVersion'=>(string)($request['current_version']??'')],
        ]);
        $this->store->logAddonActivity((string)$license['addonKey'],'license.activated',['licenseId'=>$license['id'],'installationId'=>$installationId,'domain'=>$activation['domainName']]);
        return $this->signedEnvelope($this->licensePayload($license,$product,$activation,'active'));
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function check(array $request): array
    {
        $license = $this->resolveRequestLicense($request);
        $product = $this->requiredProduct((string)$license['productKey']);
        $this->assertRequestIdentity($license, $request);
        $installationId = $this->requiredInstallationId($request);
        $activation = $this->store->addonLicenseActivation((string)$license['id'], $installationId);
        if ($activation === null || ($activation['status']??'') !== 'active') {
            throw new RuntimeException('This installation is not activated for the supplied license.');
        }
        $this->assertActivationSite($activation, $request);
        $activation['lastSeenAt'] = now_iso();
        $activation['metadata'] = array_merge((array)($activation['metadata']??[]),['lastCoreVersion'=>(string)($request['core_version']??''),'lastAddonVersion'=>(string)($request['current_version']??'')]);
        $activation = $this->store->saveAddonLicenseActivation($activation);
        $status = $this->computedLicenseStatus($license);
        return $this->signedEnvelope($this->licensePayload($license,$product,$activation,$status));
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function deactivate(array $request): array
    {
        $license = $this->resolveRequestLicense($request);
        $this->assertRequestIdentity($license, $request);
        $installationId = $this->requiredInstallationId($request);
        $activation = $this->store->addonLicenseActivation((string)$license['id'], $installationId);
        if ($activation !== null) {
            $this->assertActivationSite($activation, $request);
            $activation['status']='deactivated';$activation['deactivatedAt']=now_iso();$activation['lastSeenAt']=now_iso();
            $this->store->saveAddonLicenseActivation($activation);
        }
        $this->store->logAddonActivity((string)$license['addonKey'],'license.deactivated',['licenseId'=>$license['id'],'installationId'=>$installationId]);
        return $this->signedEnvelope(['ok'=>true,'status'=>'deactivated','addon_id'=>$license['addonKey'],'installation_id'=>$installationId,'server_time'=>now_iso(),'nonce'=>bin2hex(random_bytes(12))]);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function feed(array $request): array
    {
        $license = $this->resolveRequestLicense($request);
        $product = $this->requiredProduct((string)$license['productKey']);
        $this->assertRequestIdentity($license, $request);
        $installationId = $this->requiredInstallationId($request);
        $activation = $this->store->addonLicenseActivation((string)$license['id'],$installationId);
        if ($activation===null||($activation['status']??'')!=='active') throw new RuntimeException('Activate this installation before checking for updates.');
        $this->assertActivationSite($activation, $request);
        $status=$this->computedLicenseStatus($license);
        if (!in_array($status,['active','lifetime','trial'],true)) throw new RuntimeException('This license is not currently eligible for updates.');
        $updatesExpiry=strtotime((string)($license['updatesExpiresAt']??''));
        if ($updatesExpiry!==false && $updatesExpiry<time()) {
            return $this->signedEnvelope(['ok'=>true,'status'=>'updates_expired','update_available'=>false,'addon_id'=>$license['addonKey'],'current_version'=>(string)($request['current_version']??''),'updates_expires_at'=>$license['updatesExpiresAt'],'server_time'=>now_iso(),'nonce'=>bin2hex(random_bytes(12))]);
        }
        $channel=strtolower((string)($request['channel']??'stable'));
        if(!in_array($channel,['stable','beta','development'],true))$channel='stable';
        $current=(string)($request['current_version']??'0.0.0');
        $release=null;
        foreach($this->store->addonReleases((string)$license['addonKey'],$channel,true) as $candidate){
            if(version_compare((string)$candidate['version'],$current,'>') && version_compare(VELVET_VIXXEN_VERSION,(string)$candidate['minimumCore'],'>=') && version_compare(PHP_VERSION,(string)$candidate['minimumPhp'],'>=')){$release=$candidate;break;}
        }
        if($release===null){return $this->signedEnvelope(['ok'=>true,'status'=>'current','update_available'=>false,'addon_id'=>$license['addonKey'],'current_version'=>$current,'channel'=>$channel,'server_time'=>now_iso(),'nonce'=>bin2hex(random_bytes(12))]);}
        $token=$this->createDownloadToken($license,$release,$installationId);
        $payload=['ok'=>true,'status'=>'available','update_available'=>true,'addon_id'=>$license['addonKey'],'current_version'=>$current,'available_version'=>$release['version'],'channel'=>$channel,'package_sha256'=>$release['packageSha256'],'package_size'=>$release['packageSize'],'signature_status'=>$release['signatureStatus'],'minimum_core_version'=>$release['minimumCore'],'maximum_core_version'=>$release['maximumCore'],'minimum_php_version'=>$release['minimumPhp'],'changelog'=>$release['changelog'],'release_notes_url'=>$release['releaseNotesUrl'],'download_url'=>base_url('api/addon-license.php?download='.rawurlencode($token)),'download_expires_at'=>gmdate('c',time()+600),'server_time'=>now_iso(),'nonce'=>bin2hex(random_bytes(12))];
        return $this->signedEnvelope($payload);
    }

    /** @param array<string,mixed> $file @param array<string,mixed> $metadata @return array<string,mixed> */
    public function publishReleaseUpload(array $file, array $metadata): array
    {
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Choose an add-on release ZIP package.');
        $source=(string)($file['tmp_name']??'');$name=(string)($file['name']??'release.zip');
        if(!is_file($source)||strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='zip')throw new RuntimeException('The release must be a ZIP package.');
        return $this->publishReleasePath($source,$metadata,true);
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public function publishReleasePath(string $source,array $metadata,bool $copy=true):array
    {
        $review=$this->inspector->inspect($source,null);$manifest=AddonManifest::fromArray((array)$review['manifest']);
        $channel=strtolower((string)($metadata['channel']??'stable'));if(!in_array($channel,['stable','beta','development'],true))throw new RuntimeException('Choose stable, beta, or development release channel.');
        $directory=root_path('storage/private/addon-releases/'.$manifest->id().'/'.$channel);if(!is_dir($directory)&&!mkdir($directory,0775,true)&&!is_dir($directory))throw new RuntimeException('The protected release directory could not be created.');@chmod($directory,0775);
        $filename=$manifest->id().'-'.$manifest->version().'.zip';$target=$directory.'/'.$filename;
        if($copy){if(!copy($source,$target))throw new RuntimeException('The release package could not be copied into protected storage.');}elseif(realpath($source)!==realpath($target)){if(!copy($source,$target))throw new RuntimeException('The release package could not be staged.');}
        @chmod($target,0640);$sha=hash_file('sha256',$target);if(!is_string($sha))throw new RuntimeException('The release package checksum could not be calculated.');
        $release=$this->store->saveAddonRelease(['addonKey'=>$manifest->id(),'version'=>$manifest->version(),'channel'=>$channel,'packagePath'=>$this->relativePath($target),'packageSha256'=>$sha,'packageSize'=>(int)filesize($target),'signatureStatus'=>(string)($review['signature']['status']??'unsigned'),'minimumCore'=>(string)$manifest->toArray()['minimum_core_version'],'maximumCore'=>(string)$manifest->toArray()['maximum_core_version'],'minimumPhp'=>(string)$manifest->toArray()['minimum_php_version'],'changelog'=>trim((string)($metadata['changelog']??'')),'releaseNotesUrl'=>trim((string)($metadata['releaseNotesUrl']??'')),'published'=>!empty($metadata['published']),'publishedAt'=>!empty($metadata['published'])?now_iso():null,'metadata'=>['packageReviewWarnings'=>$review['warnings']??[]]]);
        $this->store->logAddonActivity($manifest->id(),'release.published',['version'=>$manifest->version(),'channel'=>$channel,'published'=>$release['published'],'sha256'=>$sha]);
        return $release;
    }

    /** @return array{path:string,name:string,release:array<string,mixed>} */
    public function resolveDownload(string $rawToken):array
    {
        $hash=hash('sha256',$rawToken);$token=$this->store->addonDownloadToken($hash);if($token===null)throw new RuntimeException('The update download token is invalid.');
        if((strtotime((string)$token['expiresAt'])?:0)<time())throw new RuntimeException('The update download token expired.');
        $license=$this->store->issuedAddonLicense((string)$token['licenseId']);$release=$this->store->addonRelease((string)$token['releaseId']);
        if($license===null||$release===null)throw new RuntimeException('The licensed update release is unavailable.');
        $status=$this->computedLicenseStatus($license);if(!in_array($status,['active','lifetime','trial'],true))throw new RuntimeException('The license no longer permits this download.');
        $token['downloadCount']=(int)($token['downloadCount']??0)+1;$token['lastUsedAt']=now_iso();$this->store->saveAddonDownloadToken($token);
        $path=root_path((string)$release['packagePath']);if(!is_file($path))throw new RuntimeException('The update package is missing from protected storage.');
        $sha=hash_file('sha256',$path);if(!is_string($sha)||!hash_equals((string)$release['packageSha256'],$sha))throw new RuntimeException('The stored update package failed integrity verification.');
        return ['path'=>$path,'name'=>basename($path),'release'=>$release];
    }

    /** @param array<string,mixed> $payload @return array{payload:array<string,mixed>,signature:string,key_id:string} */
    public function signedEnvelope(array $payload):array
    {
        $keys=$this->privateKeys();return ['payload'=>$payload,'signature'=>SignedPayload::sign($payload,$keys['private']),'key_id'=>$keys['fingerprint']];
    }

    /** @return array{private:string,public:string,fingerprint:string} */
    private function privateKeys():array
    {
        $public=$this->ensureSigningKeys();$private=trim((string)file_get_contents(root_path('storage/private/licensing/hub-private.key')));
        return ['private'=>$private,'public'=>$public['public'],'fingerprint'=>$public['fingerprint']];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function resolveRequestLicense(array $request):array
    {
        $raw=$this->normalizeLicenseKey((string)($request['license_key']??''));if($raw==='')throw new RuntimeException('A license key is required.');
        $license=$this->store->issuedAddonLicenseByHash($this->hashLicenseKey($raw));if($license===null)throw new RuntimeException('The license key was not recognized.');return $license;
    }

    /** @param array<string,mixed> $license @param array<string,mixed> $request */
    private function assertRequestIdentity(array $license,array $request):void
    {
        $addon=strtolower(trim((string)($request['addon_id']??'')));$product=strtolower(trim((string)($request['product_id']??'')));
        if($addon!==''&&!hash_equals((string)$license['addonKey'],$addon))throw new RuntimeException('The license is not valid for this add-on.');
        if($product!==''&&!hash_equals((string)$license['productKey'],$product))throw new RuntimeException('The license is not valid for this product.');
    }

    /** @param array<string,mixed> $license */
    private function assertLicenseUsable(array $license):void
    {
        $status=$this->computedLicenseStatus($license);if(!in_array($status,['active','lifetime','trial'],true))throw new RuntimeException('This license is '.$status.' and cannot be activated.');
    }

    /** @param array<string,mixed> $license */
    private function computedLicenseStatus(array $license):string
    {
        $status=(string)($license['status']??'inactive');if(in_array($status,['suspended','revoked','refunded'],true))return $status;
        $start=strtotime((string)($license['startsAt']??''));if($start!==false&&$start>time())return 'pending';
        $expiry=strtotime((string)($license['expiresAt']??''));if($expiry!==false&&$expiry<time())return 'expired';
        $type=(string)($license['metadata']['licenseType']??'');if($type==='lifetime')return 'lifetime';if($type==='trial')return 'trial';return 'active';
    }

    /** @param array<string,mixed> $license @param array<string,mixed> $product @param array<string,mixed> $activation @return array<string,mixed> */
    private function licensePayload(array $license,array $product,array $activation,string $status):array
    {
        $grace=max(0,(int)($product['graceDays']??7));$active=array_values(array_filter($this->store->addonLicenseActivations((string)$license['id']),static fn(array $row):bool=>($row['status']??'')==='active'));
        return ['ok'=>true,'status'=>$status,'addon_id'=>$license['addonKey'],'product_id'=>$license['productKey'],'license_type'=>$license['metadata']['licenseType']??$product['licenseType'],'license_prefix'=>$license['keyPrefix'],'installation_id'=>$activation['installationId'],'domain'=>$activation['domainName'],'site_limit'=>(int)$license['maxActivations'],'activations_used'=>count($active),'license_expires_at'=>$license['expiresAt'],'updates_expires_at'=>$license['updatesExpiresAt'],'support_expires_at'=>$license['supportExpiresAt'],'grace_until'=>gmdate('c',time()+$grace*86400),'server_time'=>now_iso(),'nonce'=>bin2hex(random_bytes(12))];
    }

    /** @param array<string,mixed> $license @param array<string,mixed> $release */
    private function createDownloadToken(array $license,array $release,string $installationId):string
    {
        $raw=rtrim(strtr(base64_encode(random_bytes(36)),'+/','-_'),'=');$this->store->saveAddonDownloadToken(['tokenHash'=>hash('sha256',$raw),'licenseId'=>(string)$license['id'],'releaseId'=>(string)$release['id'],'installationId'=>$installationId,'expiresAt'=>gmdate('c',time()+600)]);return $raw;
    }


    /** @param array<string,mixed> $activation @param array<string,mixed> $request */
    private function assertActivationSite(array $activation, array $request): void
    {
        $siteUrl = $this->normalizeSiteUrl((string) ($request['site_url'] ?? ''));
        $domain = $this->domainFromUrl($siteUrl);
        $bound = strtolower(trim((string) ($activation['domainName'] ?? '')));
        if ($bound !== '' && !hash_equals($bound, $domain)) {
            throw new RuntimeException('This license activation is bound to a different domain.');
        }
    }

    /** @return array<string,mixed> */
    private function requiredProduct(string $productKey):array{$product=$this->store->addonLicenseProduct($productKey);if($product===null)throw new RuntimeException('The license product is unavailable.');return $product;}
    private function requiredInstallationId(array $request):string{$id=trim((string)($request['installation_id']??''));if($id===''||strlen($id)>190)throw new RuntimeException('A valid installation id is required.');return $id;}
    private function normalizeLicenseKey(string $key):string{return strtoupper(preg_replace('/\s+/','',trim($key))??'');}
    private function hashLicenseKey(string $key):string{$secret=(string)$this->config->get('security.app_key','');if($secret==='')throw new RuntimeException('The license-key hashing secret is unavailable.');return hash_hmac('sha256',$this->normalizeLicenseKey($key),$secret);}
    private function maskLicenseKey(string $key):string{$key=$this->normalizeLicenseKey($key);$parts=explode('-',$key);if(count($parts)>=3)return $parts[0].'-'.$parts[1].'-••••-'.end($parts);return substr($key,0,8).'••••'.substr($key,-4);}
    private function generateLicenseKey():string{$alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';$raw='';for($i=0;$i<20;$i++)$raw.=$alphabet[random_int(0,strlen($alphabet)-1)];return 'VVX-'.implode('-',str_split($raw,4));}
    private function normalizeSiteUrl(string $url):string{$url=trim($url);$parts=parse_url($url);if(!is_array($parts)||!isset($parts['scheme'],$parts['host'])||!in_array(strtolower((string)$parts['scheme']),['http','https'],true))throw new RuntimeException('A valid site URL is required.');return rtrim($url,'/');}
    private function domainFromUrl(string $url):string{$host=parse_url($url,PHP_URL_HOST);return strtolower(is_string($host)?$host:'');}
    private function relativePath(string $path):string{$root=rtrim(str_replace('\\','/',root_path()),'/').'/';$normalized=str_replace('\\','/',$path);return str_starts_with($normalized,$root)?substr($normalized,strlen($root)):$normalized;}
}
