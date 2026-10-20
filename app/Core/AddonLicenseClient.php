<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Installation-side client for commercial license activation and resilient checks. */
final class AddonLicenseClient
{
    private SecureValue $vault;

    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
        private readonly RemoteHttpClient $http = new RemoteHttpClient(),
    ) {
        $this->vault = new SecureValue($config);
    }

    /** @return array<string,mixed> */
    public function activate(string $addonKey, string $licenseKey, ?string $serverUrl = null, ?string $publicKey = null, ?string $channel = null): array
    {
        $addon = $this->requiredAddon($addonKey);
        $manifest = AddonManifest::fromArray((array) $addon['manifest']);
        $licensing = $manifest->licensing();
        $updates = $manifest->updates();
        $serverUrl = $this->serviceUrl($serverUrl ?: (string)($licensing['server_url']??''));
        $publicKey = trim($publicKey ?: (string)($licensing['public_key']??$updates['public_key']??''));
        $this->assertPublicKey($publicKey);
        $channel = $this->normalizeChannel($channel ?: (string)($updates['channel']??'stable'));
        $normalizedKey = $this->normalizeLicenseKey($licenseKey);
        if ($normalizedKey === '') throw new RuntimeException('Enter a license key.');
        $response = $this->http->requestJson('POST', $this->endpoint($serverUrl), [
            'action'=>'activate','license_key'=>$normalizedKey,'addon_id'=>$manifest->id(),
            'product_id'=>(string)($licensing['product_id']??$manifest->id()),
            'installation_id'=>$this->installationId(),'site_url'=>$this->siteUrl(),
            'core_version'=>VELVET_VIXXEN_VERSION,'current_version'=>$manifest->version(),'channel'=>$channel,
        ]);
        $payload = $this->verifyEnvelope($response, $publicKey, $manifest->id());
        if (empty($payload['ok']) || !in_array((string)($payload['status']??''), ['active','lifetime','trial'], true)) {
            throw new RuntimeException('The remote service did not activate this license.');
        }
        $record = $this->store->saveAddonLicense([
            'addonKey'=>$manifest->id(),'keyHash'=>hash('sha256',$normalizedKey),'keyEncrypted'=>$this->vault->encrypt($normalizedKey),
            'keyPrefix'=>(string)($payload['license_prefix']??$this->maskLicenseKey($normalizedKey)),
            'licenseType'=>(string)($payload['license_type']??$manifest->licenseType()),'status'=>(string)$payload['status'],
            'productKey'=>(string)($payload['product_id']??$licensing['product_id']??$manifest->id()),
            'serverUrl'=>$serverUrl,'publicKey'=>$publicKey,'domainName'=>(string)($payload['domain']??$this->domain()),
            'installationId'=>$this->installationId(),'expiresAt'=>$payload['license_expires_at']??null,
            'updatesExpiresAt'=>$payload['updates_expires_at']??null,'supportExpiresAt'=>$payload['support_expires_at']??null,
            'graceUntil'=>$payload['grace_until']??null,'lastCheckedAt'=>now_iso(),'lastSuccessfulAt'=>now_iso(),'lastError'=>null,
            'responseSignature'=>(string)($response['signature']??''),'channel'=>$channel,
            'metadata'=>['lastPayload'=>$payload,'keyId'=>(string)($response['key_id']??''),'siteLimit'=>(int)($payload['site_limit']??1),'activationsUsed'=>(int)($payload['activations_used']??1)],
        ]);
        $this->store->logAddonActivity($manifest->id(),'client_license.activated',['status'=>$record['status'],'serverUrl'=>$serverUrl,'prefix'=>$record['keyPrefix']]);
        return $record;
    }

    /** @return array<string,mixed> */
    public function check(string $addonKey, bool $force = false): array
    {
        $addon = $this->requiredAddon($addonKey);
        $manifest = AddonManifest::fromArray((array)$addon['manifest']);
        $record = $this->store->addonLicense($manifest->id());
        if ($record === null || empty($record['keyEncrypted'])) throw new RuntimeException('Activate a license before checking its status.');
        if (!$force && !empty($record['lastCheckedAt']) && (strtotime((string)$record['lastCheckedAt']) ?: 0) > time()-900) return $record;
        try {
            $payload = $this->verifiedRequest($manifest,$record,'check');
            $record = $this->store->saveAddonLicense(array_merge($record,[
                'status'=>(string)($payload['status']??'unknown'),'expiresAt'=>$payload['license_expires_at']??$record['expiresAt'],
                'updatesExpiresAt'=>$payload['updates_expires_at']??$record['updatesExpiresAt'],'supportExpiresAt'=>$payload['support_expires_at']??$record['supportExpiresAt'],
                'graceUntil'=>$payload['grace_until']??$record['graceUntil'],'lastCheckedAt'=>now_iso(),'lastSuccessfulAt'=>now_iso(),'lastError'=>null,
                'metadata'=>array_merge((array)($record['metadata']??[]),['lastPayload'=>$payload,'siteLimit'=>(int)($payload['site_limit']??1),'activationsUsed'=>(int)($payload['activations_used']??1)]),
            ]));
            $this->store->logAddonActivity($manifest->id(),'client_license.checked',['status'=>$record['status']]);
            return $record;
        } catch (\Throwable $e) {
            $grace = strtotime((string)($record['graceUntil']??''));
            $status = $grace !== false && $grace >= time() && self::recordAllowsActivation($record) ? 'grace' : 'unknown';
            $record = $this->store->saveAddonLicense(array_merge($record,['status'=>$status,'lastCheckedAt'=>now_iso(),'lastError'=>$e->getMessage()]));
            $this->store->logAddonActivity($manifest->id(),'client_license.check_failed',['status'=>$status,'error'=>$e->getMessage()],'warning');
            return $record;
        }
    }

    /** @return array<string,mixed> */
    public function deactivate(string $addonKey): array
    {
        $addon=$this->requiredAddon($addonKey);$manifest=AddonManifest::fromArray((array)$addon['manifest']);$record=$this->store->addonLicense($manifest->id());
        if($record===null||empty($record['keyEncrypted']))throw new RuntimeException('No activated license was found for this add-on.');
        $payload=$this->verifiedRequest($manifest,$record,'deactivate');
        $record=$this->store->saveAddonLicense(array_merge($record,['status'=>'deactivated','lastCheckedAt'=>now_iso(),'lastSuccessfulAt'=>now_iso(),'lastError'=>null,'metadata'=>array_merge((array)($record['metadata']??[]),['lastPayload'=>$payload])]));
        $this->store->logAddonActivity($manifest->id(),'client_license.deactivated');return $record;
    }

    /** @return array<string,mixed> */
    public function updateFeed(string $addonKey, ?string $channel = null): array
    {
        $addon=$this->requiredAddon($addonKey);$manifest=AddonManifest::fromArray((array)$addon['manifest']);$record=$this->store->addonLicense($manifest->id());
        if($record===null||empty($record['keyEncrypted']))throw new RuntimeException('Activate a license before checking for remote updates.');
        $channel=$this->normalizeChannel($channel ?: (string)($record['channel']??$manifest->updates()['channel']??'stable'));
        $key=$this->vault->decrypt((string)$record['keyEncrypted']);
        $response=$this->http->requestJson('POST',$this->endpoint((string)$record['serverUrl']),[
            'action'=>'feed','license_key'=>$key,'addon_id'=>$manifest->id(),'product_id'=>(string)$record['productKey'],
            'installation_id'=>$this->installationId(),'site_url'=>$this->siteUrl(),'core_version'=>VELVET_VIXXEN_VERSION,
            'current_version'=>$manifest->version(),'channel'=>$channel,
        ]);
        $payload=$this->verifyEnvelope($response,(string)$record['publicKey'],$manifest->id());
        $this->store->saveAddonLicense(array_merge($record,['channel'=>$channel,'lastCheckedAt'=>now_iso(),'lastSuccessfulAt'=>now_iso(),'lastError'=>null,'responseSignature'=>(string)($response['signature']??'')]));
        return $payload;
    }

    /** @return array<string,mixed>|null */
    public function status(string $addonKey): ?array
    {
        return $this->store->addonLicense($addonKey);
    }

    public function setChannel(string $addonKey,string $channel):array
    {
        $record=$this->store->addonLicense($addonKey)??['addonKey'=>$addonKey];$record['channel']=$this->normalizeChannel($channel);return $this->store->saveAddonLicense($record);
    }

    public function assertMayActivate(string $addonKey): void
    {
        $addon=$this->requiredAddon($addonKey);$manifest=AddonManifest::fromArray((array)$addon['manifest']);
        if(!$manifest->requiresLicense())return;$record=$this->store->addonLicense($addonKey);
        if(!self::recordAllowsActivation($record))throw new RuntimeException('Activate a valid commercial license before enabling this add-on. Existing installed files were not removed.');
    }

    /** @param array<string,mixed>|null $record */
    public static function recordAllowsActivation(?array $record): bool
    {
        if($record===null)return false;$status=(string)($record['status']??'');
        if(!in_array($status,['active','lifetime','trial','grace'],true))return false;
        $expiry=strtotime((string)($record['expiresAt']??''));if($expiry!==false&&$expiry<time()&&$status!=='grace')return false;
        if($status==='grace'){$grace=strtotime((string)($record['graceUntil']??''));return $grace!==false&&$grace>=time();}
        return true;
    }

    public static function updateEntitled(?array $record): bool
    {
        if(!self::recordAllowsActivation($record))return false;$expiry=strtotime((string)($record['updatesExpiresAt']??''));return $expiry===false||$expiry>=time();
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function verifiedRequest(AddonManifest $manifest,array $record,string $action):array
    {
        $key=$this->vault->decrypt((string)$record['keyEncrypted']);
        $response=$this->http->requestJson('POST',$this->endpoint((string)$record['serverUrl']),[
            'action'=>$action,'license_key'=>$key,'addon_id'=>$manifest->id(),'product_id'=>(string)$record['productKey'],
            'installation_id'=>$this->installationId(),'site_url'=>$this->siteUrl(),'core_version'=>VELVET_VIXXEN_VERSION,'current_version'=>$manifest->version(),'channel'=>(string)($record['channel']??'stable'),
        ]);
        return $this->verifyEnvelope($response,(string)$record['publicKey'],$manifest->id());
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private function verifyEnvelope(array $response,string $publicKey,string $expectedAddon):array
    {
        $payload=$response['payload']??null;$signature=(string)($response['signature']??'');
        if(!is_array($payload)||$signature===''||!SignedPayload::verify($payload,$signature,$publicKey))throw new RuntimeException('The license server response signature was invalid.');
        if(isset($payload['addon_id'])&&(string)$payload['addon_id']!==$expectedAddon)throw new RuntimeException('The license server response was for a different add-on.');
        if(isset($payload['installation_id'])&&(string)$payload['installation_id']!==$this->installationId())throw new RuntimeException('The license server response was for a different installation.');
        $serverTime=strtotime((string)($payload['server_time']??''));if($serverTime!==false&&abs(time()-$serverTime)>900)throw new RuntimeException('The license server response timestamp was outside the allowed window.');
        return $payload;
    }

    /** @return array<string,mixed> */
    private function requiredAddon(string $addonKey):array{$addon=$this->store->addon($addonKey);if($addon===null)throw new RuntimeException('The selected add-on is not installed.');return $addon;}
    private function serviceUrl(string $url):string{$url=rtrim(trim($url),'/');if($url==='')throw new RuntimeException('Enter the license service base URL.');$parts=parse_url($url);if(!is_array($parts)||!isset($parts['scheme'],$parts['host'])||!in_array(strtolower((string)$parts['scheme']),['http','https'],true))throw new RuntimeException('The license service URL is invalid.');$host=strtolower(trim((string)$parts['host'],'[]'));$local=in_array($host,['localhost','127.0.0.1','::1'],true)||str_ends_with($host,'.test');if(strtolower((string)$parts['scheme'])!=='https'&&!$local)throw new RuntimeException('The license service must use HTTPS.');return $url;}
    private function endpoint(string $serverUrl): string
    {
        $serverUrl = rtrim($serverUrl, '/');
        $parts = parse_url($serverUrl);
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (basename((string) ($parts['path'] ?? '')) === 'route.php'
            && ($query['vvx_route'] ?? null) === 'api/addon-license.php') return $serverUrl;
        // Preserve explicitly configured legacy services; current Hub screens
        // supply their complete canonical endpoint for organized installations.
        return str_ends_with($serverUrl, '/api/addon-license.php') ? $serverUrl : $serverUrl . '/api/addon-license.php';
    }
    private function assertPublicKey(string $key):void{$raw=base64_decode($key,true);if(!is_string($raw)||strlen($raw)!==32)throw new RuntimeException('Enter the license service Ed25519 public key.');}
    private function installationId():string{$id=trim((string)$this->config->get('installation_id',''));if($id===''){$seed=(string)$this->config->get('security.app_key','').$this->siteUrl();$id='vvx-'.substr(hash('sha256',$seed),0,32);}return $id;}
    private function siteUrl():string{$url=rtrim((string)$this->config->get('base_url',''),'/');if($url==='')$url=base_url();return $url;}
    private function domain():string{$host=parse_url($this->siteUrl(),PHP_URL_HOST);return strtolower(is_string($host)?$host:'');}
    private function normalizeLicenseKey(string $key):string{return strtoupper(preg_replace('/\s+/','',trim($key))??'');}
    private function maskLicenseKey(string $key):string{$parts=explode('-',$key);return count($parts)>=3?$parts[0].'-'.$parts[1].'-••••-'.end($parts):substr($key,0,8).'••••'.substr($key,-4);}
    private function normalizeChannel(string $channel):string{$channel=strtolower(trim($channel));return in_array($channel,['stable','beta','development'],true)?$channel:'stable';}
}
