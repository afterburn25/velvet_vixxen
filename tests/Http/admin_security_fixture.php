<?php
declare(strict_types=1);
$root = realpath($argv[1] ?? ''); $action=$argv[2]??'';
if (PHP_SAPI!=='cli' || !$root || !str_starts_with($root, sys_get_temp_dir().'/') || !is_file($root.'/.vvx-test-runtime') || !is_file($root.'/storage/temp/editorial-access-ownership.json')) throw new RuntimeException('Owned disposable runtime required.');
$owned=json_decode((string)file_get_contents($root.'/storage/temp/editorial-access-ownership.json'),true,32,JSON_THROW_ON_ERROR);
$configValues=require $root.'/config/app.php';
if (($owned['runtime']??'')!==$root || ($configValues['base_url']??'')!==($owned['base']??'') || parse_url($owned['base'],PHP_URL_HOST)!=='127.0.0.1' || ($configValues['storage_mode']??'')!==($owned['mode']??'')) throw new RuntimeException('Exact owned loopback fixture required.');
define('ROOT_PATH',$root); require $root.'/includes/autoload.php';require $root.'/app/Support/functions.php';
$config=new App\Core\Config($root.'/config/app.php');$store=new App\Core\Store($config);
if($action==='snapshot'){echo json_encode($store->adminSecurityState(),JSON_THROW_ON_ERROR);exit;}
if($action==='age-session'){
 $id=$argv[3]??''; if(!preg_match('/\A[a-zA-Z0-9,-]{20,128}\z/D',$id))throw new RuntimeException('Invalid fixture session.');
 $directory=$root.'/storage/sessions';$path=$directory.'/sess_'.$id;
 if(!is_file($path)||is_link($path)||realpath(dirname($path))!==$directory)throw new RuntimeException('Saved fixture session required.');
 session_save_path($directory);session_id($id);session_start();
 if(($_SESSION['admin']['email']??'')!=='owner@example.test')throw new RuntimeException('Only fixture owner session may be aged.');
 $_SESSION['admin']['authenticated_at']=time()-901;session_write_close();echo '{"aged":true}';exit;
}
throw new RuntimeException('Unsupported fixture action.');
