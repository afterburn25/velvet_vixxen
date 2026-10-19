<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) { json_response(['ok'=>false,'message'=>'Application is not installed.'],503); }
$member=require_member(true);$memberId=(string)$member['id'];$type=(string)($_GET['type']??$_POST['type']??'');$contentId=(string)($_GET['id']??$_POST['id']??'');
if ($_SERVER['REQUEST_METHOD']==='POST' && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')) { $payload=safe_json_decode((string)file_get_contents('php://input')); $type=(string)($payload['type']??$type);$contentId=(string)($payload['id']??$contentId);$_POST=array_merge($_POST,$payload); }
if(!in_array($type,['track','gallery','video'],true)||$contentId===''||entitlement_resolver()->content($type,$contentId)===null){json_response(['ok'=>false,'message'=>'A valid media item is required.'],422);}
if($_SERVER['REQUEST_METHOD']==='GET'){json_response(['ok'=>true,'state'=>app_store()->memberMediaState($memberId,$type,$contentId)]);}
if($_SERVER['REQUEST_METHOD']!=='POST'){json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
require_csrf();
$changes=[];
if(array_key_exists('progressSeconds',$_POST))$changes['progressSeconds']=max(0,(float)$_POST['progressSeconds']);
if(array_key_exists('isFavorite',$_POST))$changes['isFavorite']=filter_var($_POST['isFavorite'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)??false;
if(!empty($_POST['viewed']))$changes['viewed']=true;
if(!empty($_POST['completed']))$changes['completedAt']=now_iso();
if(isset($_POST['metadata'])&&is_array($_POST['metadata']))$changes['metadata']=$_POST['metadata'];
$state=app_store()->saveMemberMediaState($memberId,$type,$contentId,$changes);
json_response(['ok'=>true,'state'=>$state]);
