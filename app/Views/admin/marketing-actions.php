<?php
declare(strict_types=1);
require_once root_path('includes/marketing.php');
velvet_marketing_require_owner();
$marketingStore = velvet_marketing_store();
$notice = '';

require_once root_path('includes/marketing_admin.php');
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{$action=(string)($_POST['action']??'');
  if ($action === 'save_segment' && ($_POST['preview_segment'] ?? null) === '1') $action = 'preview_segment';
  if ($action === 'save_campaign' && ($_POST['preview_audience'] ?? null) === '1') $action = 'preview_campaign_audience';
  if($action==='save_post'){$marketingStore->upsert('posts',['id'=>(string)($_POST['id']??''),'title'=>trim((string)($_POST['title']??'')),'slug'=>mm_slug((string)($_POST['slug']?:$_POST['title']??'')),'excerpt'=>trim((string)($_POST['excerpt']??'')),'body'=>trim((string)($_POST['body']??'')),'category'=>trim((string)($_POST['category']??'Update')),'status'=>(string)($_POST['status']??'draft'),'publish_at'=>(string)($_POST['publish_at']??gmdate('Y-m-d\TH:i'))]);$notice='Post saved.';}
  elseif($action==='save_banner'){$marketingStore->upsert('banners',['id'=>(string)($_POST['id']??''),'text'=>trim((string)($_POST['text']??'')),'url'=>trim((string)($_POST['url']??'')),'enabled'=>!empty($_POST['enabled']),'starts_at'=>(string)($_POST['starts_at']??''),'ends_at'=>(string)($_POST['ends_at']??'')]);$notice='Banner saved.';}
  elseif($action==='save_link'){$marketingStore->upsert('links',['id'=>(string)($_POST['id']??''),'label'=>trim((string)($_POST['label']??'')),'url'=>trim((string)($_POST['url']??'')),'enabled'=>!empty($_POST['enabled']),'sort'=>(int)($_POST['sort']??0)]);$notice='Link saved.';}
  elseif($action==='save_page'){$marketingStore->upsert('landing_pages',['id'=>(string)($_POST['id']??''),'title'=>trim((string)($_POST['title']??'')),'slug'=>mm_slug((string)($_POST['slug']?:$_POST['title']??'')),'eyebrow'=>trim((string)($_POST['eyebrow']??'Special Offer')),'excerpt'=>trim((string)($_POST['excerpt']??'')),'body'=>trim((string)($_POST['body']??'')),'cta_label'=>trim((string)($_POST['cta_label']??'Learn More')),'cta_url'=>trim((string)($_POST['cta_url']??'')),'enabled'=>!empty($_POST['enabled']),'starts_at'=>(string)($_POST['starts_at']??''),'ends_at'=>(string)($_POST['ends_at']??'')]);$notice='Campaign page saved.';}
  elseif($action==='save_referral'){$marketingStore->upsert('referral_codes',['id'=>(string)($_POST['id']??''),'code'=>strtoupper(preg_replace('/[^A-Za-z0-9_-]/','',(string)($_POST['code']??''))),'label'=>trim((string)($_POST['label']??'')),'enabled'=>!empty($_POST['enabled'])]);$notice='Referral code saved.';}
  elseif ($action === 'save_segment' || $action === 'preview_segment') {
    $segmentForm = $_POST;
    $rules = mm_audience_rules($_POST);
    if ($action === 'preview_segment') {
      $segmentPreview = $marketingStore->previewSegment($rules);
    } else {
      $segment = $marketingStore->saveSegment(['id' => $_POST['id'] ?? '', 'name' => $_POST['name'] ?? '',
        'enabled' => mm_checked($_POST['enabled'] ?? null), 'rules' => $rules, 'expected_revision' => $_POST['segment_revision'] ?? null]);
      $notice = 'Audience saved. Existing queued campaigns keep their saved audience rules.';
      $returnSegment = $segment['id'];
    }
  }
  elseif ($action === 'delete_segment') {
    $id = \Velvet\Core\MarketingAudience::id($_POST['id'] ?? null);
    $revision = $_POST['segment_revision'] ?? null;
    if (!is_string($revision)) throw new InvalidArgumentException('Reload the audience before removing it.');
    $marketingStore->deleteSegment($id, $revision);
    $notice = 'Audience removed. Existing queued campaigns keep their saved audience rules.';
  }
  elseif ($action === 'preview_campaign_audience') {
    $campaignForm = $_POST;
    $selection = mm_campaign_selection($_POST);
    $campaignAudiencePreview = $marketingStore->previewAudience($selection);
    $campaignForm['audience_preview_revision'] = $campaignAudiencePreview['revision'];
  }
  elseif ($action === 'preview_campaign_queue') {
    $queuePreviewId = \Velvet\Core\MarketingAudience::id($_POST['campaign_id'] ?? null);
    $queueAudiencePreview = $marketingStore->previewCampaignAudience($queuePreviewId);
  }
  elseif ($action === 'save_campaign') {
    $campaignForm = $_POST;
    $subject = \Velvet\Core\MarketingAudience::text($_POST['subject'] ?? '', 255, 'Campaign subject');
    if (!is_string($_POST['body'] ?? null)) throw new InvalidArgumentException('Enter plain campaign text.');
    $preheader = \Velvet\Core\MarketingAudience::text($_POST['preheader'] ?? '', 500, 'Campaign preheader');
    $body = trim($_POST['body']);
    if ($subject === '' || $body === '' || strlen($body) > 100000 || preg_match('//u', $body) !== 1) throw new InvalidArgumentException('Enter a subject and a message of up to 100,000 bytes of valid text.');
    $schedule = \Velvet\Core\MarketingAudience::formDate($_POST['scheduled_at'] ?? '') ?? '';
    if ($schedule !== '') $schedule = (new DateTimeImmutable($schedule))->format(DateTimeInterface::ATOM);
    $selection = mm_campaign_selection($_POST);
    $queueNow = mm_checked($_POST['queue'] ?? null);
    $previewRevision = $_POST['audience_preview_revision'] ?? null;
    if ($previewRevision === '') $previewRevision = null;
    if ($previewRevision !== null && !\Velvet\Core\MarketingAudience::validRevision($previewRevision)) throw new InvalidArgumentException('Refresh the audience preview before queueing.');
    $campaign = $marketingStore->upsert('campaigns', ['id' => \Velvet\Core\MarketingAudience::id($_POST['id'] ?? '', true), 'subject' => $subject,
      'preheader' => $preheader, 'body' => $body, 'status' => 'draft', 'scheduled_at' => $schedule, 'audience' => $selection]);
    $campaignForm['id'] = $campaign['id'];
    $campaignDraftSaved = !isset($campaign['delivery_snapshot']) && !array_key_exists('audience_snapshot', $campaign);
    $count = $queueNow ? $marketingStore->queueCampaign((string) $campaign['id'], $previewRevision) : null;
    $notice = 'Campaign saved.';
    if ($count !== null) $notice .= ' ' . $count . ' new eligible recipients queued from the selected audience.';
  }
  elseif ($action === 'queue_campaign') {
    $previewRevision = $_POST['audience_preview_revision'] ?? null;
    if ($previewRevision === '') $previewRevision = null;
    if ($previewRevision !== null && !is_string($previewRevision)) throw new InvalidArgumentException('Refresh the audience preview before queueing.');
    $notice = $marketingStore->queueCampaign(\Velvet\Core\MarketingAudience::id($_POST['campaign_id'] ?? null), $previewRevision) . ' new eligible recipients queued. Existing deliveries were not duplicated.';
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
 }catch(Throwable $e){$notice='';$error=(!empty($campaignDraftSaved) ? 'The campaign draft was saved, but no new recipients were queued. ' : '') . $e->getMessage();}
}
if ($notice !== '') {
  flash('success', $notice);
  $returnTab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? 'overview')) ?: 'overview';
  redirect(base_url('admin/?page=marketing&tab=' . $returnTab . (isset($returnSegment) ? '&edit_segment=' . rawurlencode($returnSegment) : '')));
}
