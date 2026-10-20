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
  elseif ($action === 'save_campaign') {
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($subject === '' || strlen($subject) > 255 || $body === '' || strlen($body) > 100000) throw new InvalidArgumentException('Enter a subject of up to 255 characters and a message of up to 100,000 characters.');
    $schedule = trim((string) ($_POST['scheduled_at'] ?? ''));
    if ($schedule !== '') {
      $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $schedule);
      $errors = DateTimeImmutable::getLastErrors();
      if (!$parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) throw new InvalidArgumentException('Enter a valid campaign schedule.');
      $schedule = $parsed->format(DateTimeInterface::ATOM);
    }
    $campaign = $marketingStore->upsert('campaigns', ['id' => (string) ($_POST['id'] ?? ''), 'subject' => $subject,
      'preheader' => substr(trim((string) ($_POST['preheader'] ?? '')), 0, 500), 'body' => $body, 'status' => 'draft', 'scheduled_at' => $schedule]);
    $notice = 'Campaign saved.';
    if (!empty($_POST['queue'])) $notice .= ' ' . $marketingStore->queueCampaign((string) $campaign['id']) . ' new confirmed recipients queued.';
  }
  elseif ($action === 'queue_campaign') {
    $notice = $marketingStore->queueCampaign((string) ($_POST['campaign_id'] ?? '')) . ' new confirmed recipients queued. Existing deliveries were not duplicated.';
  }
  elseif ($action === 'pause_campaign' || $action === 'resume_campaign') {
    $marketingStore->pauseCampaign((string) ($_POST['campaign_id'] ?? ''), $action === 'pause_campaign');
    $notice = $action === 'pause_campaign' ? 'Campaign paused. A message already transmitting cannot be recalled.' : 'Campaign resumed. Its schedule is still honored.';
  }
  elseif ($action === 'retry_campaign') {
    $notice = $marketingStore->retryCampaign((string) ($_POST['campaign_id'] ?? '')) . ' definitely failed deliveries queued for retry after their cooldown. Accepted and unknown outcomes were not retried.';
  }
  elseif ($action === 'save_marketing_settings') {
    $marketingStore->setSettings(['newsletter_signup_enabled' => !empty($_POST['newsletter_signup_enabled']),
      'campaign_delivery_enabled' => !empty($_POST['campaign_delivery_enabled'])]);
    $notice = 'Marketing settings saved. Unsubscribe links remain available.';
  }
  elseif ($action === 'run_campaign_batch') {
    $limit = filter_var($_POST['batch_limit'] ?? 3, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
    if ($limit === false) throw new InvalidArgumentException('Choose a batch size between 1 and 10.');
    $service = new \App\Core\CampaignDeliveryService(app_config(), $marketingStore, new \App\Core\MailService(app_config(), app_store()));
    $sessionOpen = session_status() === PHP_SESSION_ACTIVE;
    if ($sessionOpen) session_write_close();
    try {
      $stats = $service->run($limit, !empty($_POST['campaign_id']) ? (string) $_POST['campaign_id'] : null);
    } finally {
      // Re-read current session state; a parallel owner request may have changed it.
      if ($sessionOpen) session_start();
    }
    $notice = $stats['busy'] ? 'Another campaign batch is running. No duplicate worker was started.'
      : ($stats['held'] ?: sprintf('Batch finished: %d accepted by the mail transport, %d failed, %d unknown, %d suppressed.', $stats['accepted'], $stats['failed'], $stats['unknown'], $stats['suppressed']));
  }
  elseif($action==='delete'){ $marketingStore->delete((string)$_POST['collection'],(string)$_POST['id']);$notice='Item removed.'; }
 }catch(Throwable $e){$error=$e->getMessage();}
}
if ($notice !== '') {
  flash('success', $notice);
  $returnTab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'overview')) ?: 'overview';
  redirect(base_url('admin/?page=marketing&tab=' . $returnTab));
}
