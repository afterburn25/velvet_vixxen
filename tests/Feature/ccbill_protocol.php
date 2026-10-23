<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/addons/sdk/ccbill/src/FlexForms.php';
use Velvet\CCBill\FlexForms;

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks;
    if (!$ok) throw new RuntimeException($message);
};
$reject = static function (callable $call, string $message) use ($assert): void {
    try { $call(); } catch (InvalidArgumentException|TypeError) { $assert(true, $message); return; }
    $assert(false, $message);
};
// Public sample values from CCBill's official formDigest guide, not merchant credentials.
$salt = '7d901dad245fd0ff6bc20d06';
$flex = '687fa3e0-e60d-4466-88e2-181fa56dd6a9';
$price = ['initialMinor'=>1000, 'initialDays'=>30, 'currency'=>'USD'];
$build = static fn(array $p): array => FlexForms::request($flex, '0000', $p, $salt);
$one = $build($price);
$assert($one['parameters']['formDigest'] === 'a7459445d0e5dc0963fe736dc5cf900b', 'Official single-sale digest vector failed.');
$recurring = $price + ['recurringMinor'=>1000, 'recurringDays'=>30, 'rebills'=>99];
$many = $build($recurring);
$assert($many['parameters']['formDigest'] === '48f0b12e4307e64edb781c479665c899', 'Official recurring digest vector failed.');
$assert($one['method'] === 'POST' && $one['action'] === 'https://api.ccbill.com/wap-frontflex/flexforms/'.$flex, 'Fixed hosted request target changed.');
$assert($one['parameters']['clientSubacc'] === '0000', 'Subaccount leading zeros were lost.');
$assert(!str_contains(json_encode($many, JSON_THROW_ON_ERROR), $salt), 'Request exposed its salt.');
foreach ([1=>'0.01', 109=>'1.09', 9999999=>'99999.99'] as $minor=>$decimal) {
    $assert($build(array_replace($price, ['initialMinor'=>$minor]))['parameters']['initialPrice'] === $decimal, 'Minor-unit price changed.');
}
foreach (['USD'=>'840','EUR'=>'978','GBP'=>'826','CAD'=>'124','AUD'=>'036'] as $currency=>$numeric) {
    $assert($build(array_replace($price, ['currency'=>$currency]))['parameters']['currencyCode'] === $numeric, 'Currency or leading zero changed.');
}
foreach (['initialMinor'=>[0,-1,10000000,10.01,'1000',true,null], 'initialDays'=>[0,1,366,'30',30.0],
    'currency'=>['usd','JPY','USD ',840,null]] as $field=>$values) {
    foreach ($values as $value) $reject(fn()=>$build(array_replace($price, [$field=>$value])), 'Invalid price field accepted: '.$field);
}
foreach (['recurringMinor'=>[0,-1,'1000'], 'recurringDays'=>[29,31,91,'30'], 'rebills'=>[0,100,'99',99.0]] as $field=>$values) {
    foreach ($values as $value) $reject(fn()=>$build(array_replace($recurring, [$field=>$value])), 'Invalid recurring field accepted: '.$field);
}
foreach (['initialMinor','initialDays','currency','recurringMinor','recurringDays','rebills'] as $key) {
    $bad=$recurring;unset($bad[$key]);$reject(fn()=>$build($bad), 'Incomplete recurring price accepted.');
}
foreach (['salt','initialPrice','formDigest','approvalURL','customer_fname','cardNumber'] as $key) {
    $reject(fn()=>$build($price+[$key=>'untrusted']), 'Unsupported caller field accepted.');
}
foreach (['','https://other.example/','../forms','687fa3e0-e60d-4466-88e2-181fa56dd6a9?x=1',strtoupper($flex)] as $bad) {
    $reject(fn()=>FlexForms::request($bad, '0000', $price, $salt), 'Untrusted hosted target accepted.');
}
foreach (['','0','00000',' 0000',"0000\n"] as $bad) $reject(fn()=>FlexForms::request($flex, $bad, $price, $salt), 'Malformed subaccount accepted.');
foreach (['',"salt\n",' salt',str_repeat('x',1025)] as $bad) $reject(fn()=>FlexForms::request($flex, '0000', $price, $bad), 'Malformed salt accepted.');
$assert(FlexForms::exactDays('day',30)===30 && FlexForms::exactDays('week',4)===28, 'Exact day/week conversion failed.');
foreach ([['month',1],['year',1],['one_time',1],['day',1],['week',53],['day',366]] as [$interval,$count]) {
    $reject(fn()=>FlexForms::exactDays($interval,$count), 'Calendar/unsupported duration was silently converted.');
}
// Independent Python hashlib oracle; preserves a 20-digit subscription ID as text.
$subscription='00123456789012345678';
$digest='bf23490ee759af5ff4d2a90c9c437f5d';
$assert(FlexForms::matchesSaleResponseDigest($subscription,true,$digest,'owned-fixture-salt'), 'Independent response digest vector failed.');
foreach ([[$subscription,false,$digest],['123456789012345678',true,$digest],[$subscription,true,'0e'.str_repeat('0',30)],
    [$subscription,true,strtoupper($digest)],['1e18',true,$digest],[$subscription,true,$digest."\n"]] as [$id,$approved,$value]) {
    $assert(!FlexForms::matchesSaleResponseDigest($id,$approved,$value,'owned-fixture-salt'), 'Mismatched or ambiguous response digest accepted.');
}
foreach ([212,215,240,241] as $network) {
    foreach ([1,254] as $host) $assert(FlexForms::isDocumentedWebhookPeer("64.38.$network.$host"), 'Documented direct peer boundary rejected.');
    foreach ([0,255] as $host) $assert(!FlexForms::isDocumentedWebhookPeer("64.38.$network.$host"), 'Out-of-range peer accepted.');
}
foreach (['64.38.213.1','64.39.212.1','::ffff:64.38.212.1','064.38.212.1','127.0.0.1','64.38.212.1, 127.0.0.1',"64.38.212.1\n"] as $peer) {
    $assert(!FlexForms::isDocumentedWebhookPeer($peer), 'Untrusted/forwarded peer accepted.');
}
// Explicitly show the digest does not bind amount: it must never grant authority.
$assert(FlexForms::matchesSaleResponseDigest($subscription,true,$digest,'owned-fixture-salt'), 'Digest scope observation changed.');
$assert(!is_subclass_of(FlexForms::class, App\Payments\PaymentProviderInterface::class), 'SDK preparation became an active provider.');
echo "CCBill protocol preparation passed: $checks checks; no network or payment dispatch.\n";
