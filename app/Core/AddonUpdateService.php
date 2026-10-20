<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Signed remote update checks and protected package staging. */
final class AddonUpdateService
{
    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
        private readonly AddonLicenseClient $licenses,
        private readonly RemoteHttpClient $http = new RemoteHttpClient(),
        private readonly ?AddonManager $manager = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function check(string $addonKey, ?string $channel = null): array
    {
        $addon=$this->store->addon($addonKey);if($addon===null)throw new RuntimeException('The selected add-on is not installed.');
        $manifest=AddonManifest::fromArray((array)$addon['manifest']);$channel=$this->normalizeChannel($channel ?: (string)($this->store->addonLicense($addonKey)['channel']??$manifest->updates()['channel']??'stable'));
        try{$payload=$this->licenses->updateFeed($addonKey,$channel);$status=(string)($payload['status']??'none');}
        catch(\Throwable $e){$this->store->saveAddonUpdateCheck(['addonKey'=>$addonKey,'installationId'=>(string)$this->config->get('installation_id',''),'currentVersion'=>(string)$addon['version'],'channel'=>$channel,'status'=>'error','response'=>['message'=>$e->getMessage()]]);throw $e;}
        $check=$this->store->saveAddonUpdateCheck(['addonKey'=>$addonKey,'installationId'=>(string)$this->config->get('installation_id',''),'currentVersion'=>(string)$addon['version'],'channel'=>$channel,'status'=>$status,'availableVersion'=>$payload['available_version']??null,'response'=>$payload]);
        $this->store->logAddonActivity($addonKey,'update.checked',['status'=>$status,'availableVersion'=>$payload['available_version']??null,'channel'=>$channel]);return $check;
    }

    /** @return array<string,mixed> */
    public function downloadForReview(string $addonKey): array
    {
        $check=$this->store->latestAddonUpdateCheck($addonKey);if($check===null||($check['status']??'')!=='available')throw new RuntimeException('Check for an available update before downloading it.');
        $payload=(array)($check['response']??[]);$url=(string)($payload['download_url']??'');$sha=strtolower((string)($payload['package_sha256']??''));$size=(int)($payload['package_size']??0);
        if($url===''||preg_match('/^[a-f0-9]{64}$/',$sha)!==1)throw new RuntimeException('The signed update feed did not include valid download information.');
        if($size<1||$size>25*1024*1024)throw new RuntimeException('The update package size is outside the supported limit.');
        $directory=root_path('addons/staging');if(!is_dir($directory)&&!mkdir($directory,0775,true)&&!is_dir($directory))throw new RuntimeException('The update staging directory could not be created.');
        $target=$directory.'/remote-update-'.preg_replace('/[^a-z0-9_-]+/i','-',$addonKey).'-'.bin2hex(random_bytes(8)).'.zip';
        $this->http->download($url,$target,[],25*1024*1024);$actual=hash_file('sha256',$target);
        if(!is_string($actual)||!hash_equals($sha,strtolower($actual))){@unlink($target);throw new RuntimeException('The downloaded update package did not match the signed feed checksum.');}
        $manager=$this->manager??new AddonManager($this->store);$review=$manager->stageRemotePackage($target,$sha,['source'=>'remote-update','feed'=>$payload]);
        $this->store->logAddonActivity($addonKey,'update.downloaded',['version'=>$payload['available_version']??'','sha256'=>$sha]);return $review;
    }

    public function setChannel(string $addonKey,string $channel):array{return $this->licenses->setChannel($addonKey,$this->normalizeChannel($channel));}
    private function normalizeChannel(string $channel):string{$channel=strtolower(trim($channel));return in_array($channel,['stable','beta','development'],true)?$channel:'stable';}
}
