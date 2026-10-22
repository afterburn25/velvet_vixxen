<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }require ROOT_PATH.'/app/bootstrap.php';
if(!is_installed())json_response(['ok'=>false,'message'=>'Application is not installed.'],503);
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'message'=>'POST required.'],405);
$provider=(string)($_GET['provider']??'sandbox');$raw=(string)file_get_contents('php://input',false,null,0,\App\Payments\PaymentContract::MAX_BODY_BYTES+1);$signature=(string)($_SERVER['HTTP_X_VELVET_SIGNATURE']??'');
try{$result=commerce_service()->handleWebhook($provider,$raw,$signature);json_response(['ok'=>true]+$result);}catch(Throwable $e){$recovery=$e->getCode()===503;json_response(['ok'=>false,'message'=>$recovery?'Authenticated payment is retained for local recovery.':'Payment webhook was not accepted.'],$recovery?503:400);}
