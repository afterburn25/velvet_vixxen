<?php
declare(strict_types=1);
require_once root_path('includes/marketing.php');
velvet_marketing_require_owner();
$marketingStore = velvet_marketing_store();
$notice = '';

require_once root_path('includes/marketing_admin.php');
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{$action=(string)($_POST['action']??'');
  if($action==='save_post'){$marketingStore->upsert('posts',['id'=>(string)($_POST['id']??''),'title'=>trim((string)($_POST['title']??'')),'slug'=>mm_slug((string)($_POST['slug']?:$_POST['title']??'')),'excerpt'=>trim((string)($_POST['excerpt']??'')),'body'=>trim((string)($_POST['body']??'')),'category'=>trim((string)($_POST['category']??'Update')),'status'=>(string)($_POST['status']??'draft'),'publish_at'=>(string)($_POST['publish_at']??gmdate('Y-m-d\TH:i'))]);$notice='Post saved.';}
  elseif($action==='save_banner'){$marketingStore->upsert('banners',['id'=>(string)($_POST['id']??''),'text'=>trim((string)($_POST['text']??'')),'url'=>trim((string)($_POST['url']??'')),'enabled'=>!empty($_POST['enabled']),'starts_at'=>(string)($_POST['starts_at']??''),'ends_at'=>(string)($_POST['ends_at']??'')]);$notice='Banner saved.';}
  elseif($action==='save_link'){$marketingStore->upsert('links',['id'=>(string)($_POST['id']??''),'label'=>trim((string)($_POST['label']??'')),'url'=>trim((string)($_POST['url']??'')),'enabled'=>!empty($_POST['enabled']),'sort'=>(int)($_POST['sort']??0)]);$notice='Link saved.';}
  elseif($action==='save_page'){$marketingStore->upsert('landing_pages',['id'=>(string)($_POST['id']??''),'title'=>trim((string)($_POST['title']??'')),'slug'=>mm_slug((string)($_POST['slug']?:$_POST['title']??'')),'eyebrow'=>trim((string)($_POST['eyebrow']??'Special Offer')),'excerpt'=>trim((string)($_POST['excerpt']??'')),'body'=>trim((string)($_POST['body']??'')),'cta_label'=>trim((string)($_POST['cta_label']??'Learn More')),'cta_url'=>trim((string)($_POST['cta_url']??'')),'enabled'=>!empty($_POST['enabled']),'starts_at'=>(string)($_POST['starts_at']??''),'ends_at'=>(string)($_POST['ends_at']??'')]);$notice='Campaign page saved.';}
  elseif($action==='save_referral'){$marketingStore->upsert('referral_codes',['id'=>(string)($_POST['id']??''),'code'=>strtoupper(preg_replace('/[^A-Za-z0-9_-]/','',(string)($_POST['code']??''))),'label'=>trim((string)($_POST['label']??'')),'enabled'=>!empty($_POST['enabled'])]);$notice='Referral code saved.';}
  elseif($action==='save_campaign'){$record=$marketingStore->upsert('campaigns',['id'=>(string)($_POST['id']??''),'subject'=>trim((string)($_POST['subject']??'')),'preheader'=>trim((string)($_POST['preheader']??'')),'body'=>trim((string)($_POST['body']??'')),'status'=>(string)($_POST['status']??'draft'),'scheduled_at'=>(string)($_POST['scheduled_at']??'')]);if(!empty($_POST['queue'])){$count=$marketingStore->queueCampaign($record['id']);$notice='Campaign saved and '.$count.' deliveries queued.';}else{$notice='Campaign saved.';}}
  elseif($action==='delete'){ $marketingStore->delete((string)$_POST['collection'],(string)$_POST['id']);$notice='Item removed.'; }
 }catch(Throwable $e){$error=$e->getMessage();}
}
if ($notice !== '') flash('success', $notice);
