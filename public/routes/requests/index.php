<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';

if (!is_installed()) redirect(base_url('install/'));
$member = require_member();
$store = app_store();
$communicationSettings = $store->communicationSettings();
$memberId = (string) $member['id'];
$error = '';
$success = flash('request_success');
$blocked = $store->isCommunicationBlocked($memberId, (string) $member['email'], 'requests');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your form session expired. Refresh and try again.');
        if (empty($communicationSettings['contentRequestsEnabled'])) throw new RuntimeException('Content requests are temporarily unavailable.');
        if ($blocked) throw new RuntimeException('Content requests are not available for this account.');
        $action = (string) ($_POST['action'] ?? '');
        $attachments = [];
        if (!empty($communicationSettings['memberAttachmentsEnabled']) && isset($_FILES['references'])) {
            $attachments = communication_upload_service()->uploadMany((array) $_FILES['references']);
        }
        if ($action === 'create_request') {
            $request = $store->createContentRequest([
                'memberId' => $memberId,
                'category' => (string) ($_POST['category'] ?? 'general'),
                'title' => (string) ($_POST['title'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'budgetMin' => (string) ($_POST['budget_min'] ?? ''),
                'budgetMax' => (string) ($_POST['budget_max'] ?? ''),
                'currency' => (string) ($_POST['currency'] ?? 'USD'),
                'requestedDueAt' => (string) ($_POST['requested_due_at'] ?? ''),
                'attachments' => $attachments,
            ]);
            if (!empty($communicationSettings['autoAcknowledgeRequests'])) {
                $mailer = new \App\Core\MailService(app_config(), $store);
                $mailer->send($memberId, (string) $member['email'], 'Content request received', 'content_request.received',
                    "Your private content request was received.\n\nReference: VVX-R-" . strtoupper(substr((string) $request['id'], -8)) . "\nTitle: " . (string) $request['title'] . "\nStatus: Submitted\n\nUpdates and replies will appear in your private inbox."
                );
            }
            addon_event('content_request.submitted', ['request'=>$request,'member'=>$member]);
            flash('request_success', 'Your content request was submitted for private review.');
            redirect(base_url('requests/?request=' . rawurlencode((string) $request['id'])));
        }
        if ($action === 'reply_request') {
            $requestId = (string) ($_POST['request_id'] ?? '');
            $request = $store->contentRequest($requestId);
            if (!$request || (string) $request['memberId'] !== $memberId) throw new RuntimeException('Request not found.');
            if (in_array((string)$request['status'],['declined','completed','cancelled'],true)) throw new RuntimeException('This request no longer accepts replies.');
            $store->sendCommunicationMessage([
                'threadId' => $request['threadId'], 'senderType'=>'member','senderMemberId'=>$memberId,
                'body'=>(string)($_POST['message']??''),'attachments'=>$attachments,
            ]);
            flash('request_success', 'Your reply was added to the request.');
            redirect(base_url('requests/?request=' . rawurlencode($requestId)));
        }
        if ($action === 'cancel_request') {
            $requestId = (string) ($_POST['request_id'] ?? '');
            $request = $store->contentRequest($requestId);
            if (!$request || (string) $request['memberId'] !== $memberId) throw new RuntimeException('Request not found.');
            if (!in_array((string)$request['status'],['submitted','reviewing','quoted'],true)) throw new RuntimeException('This request cannot be cancelled online.');
            $store->updateContentRequest($requestId,['status'=>'cancelled']);
            flash('request_success', 'The request was cancelled.');
            redirect(base_url('requests/?request=' . rawurlencode($requestId)));
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$requests = $store->contentRequests(['memberId'=>$memberId]);
$selectedId = (string) ($_GET['request'] ?? ($requests[0]['id'] ?? ''));
$selected = $selectedId !== '' ? $store->contentRequest($selectedId) : null;
if ($selected && (string)$selected['memberId'] !== $memberId) $selected = null;
$messages = $selected && !empty($selected['threadId']) ? $store->communicationMessages((string)$selected['threadId'], false) : [];
if ($selected && !empty($selected['threadId'])) $store->markCommunicationThreadRead((string)$selected['threadId'],'member');
$pageTitle='Content Requests';$activeNav='membership';require root_path('app/Views/public/partials/header.php');
?>
<div class="communication-main"><div class="communication-wrap">
  <div class="request-page-head"><div><span class="communication-kicker">Member requests</span><h1>Request something personal.</h1><p>Submit a private idea, follow its review status, receive a quote, and keep every reply attached to the request.</p></div><a class="button button--ghost" href="<?=e(base_url('messages/'))?>">Open Messages</a></div>
  <?php if($success):?><div class="communication-alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="communication-alert error"><?=e($error)?></div><?php endif;?>
  <?php if($blocked):?><div class="communication-alert warning">New messages and requests are restricted for this account. Existing request history remains visible.</div><?php endif;?>

  <div class="request-dashboard">
    <aside class="communication-card">
      <span class="communication-kicker">Your request history</span><h2>Requests</h2>
      <div class="request-list">
        <?php foreach($requests as $request):?><a class="<?=$selected&&(string)$selected['id']===(string)$request['id']?'active':''?>" href="<?=e(base_url('requests/?request='.rawurlencode((string)$request['id'])))?>"><strong><?=e((string)$request['title'])?></strong><small><?=e(ucfirst((string)$request['category']))?> · <?=e((string)$request['createdAt'])?></small><span class="communication-status <?=e((string)$request['status'])?>"><?=e(str_replace('_',' ',(string)$request['status']))?></span></a><?php endforeach;?>
        <?php if($requests===[]):?><div class="member-empty">No requests have been submitted yet.</div><?php endif;?>
      </div>
    </aside>

    <div style="display:grid;gap:22px">
      <section class="communication-card">
        <span class="communication-kicker">New private request</span><h2>Describe your idea.</h2><p>Requests are reviewed before acceptance. Submitting an idea does not guarantee availability, price, or delivery.</p>
        <?php if(!$blocked&&!empty($communicationSettings['contentRequestsEnabled'])):?><form method="post" enctype="multipart/form-data" class="communication-form"><?=csrf_field()?><input type="hidden" name="action" value="create_request">
          <div class="communication-form-grid"><label>Request category<select name="category" required><option value="photo">Photo set</option><option value="video">Video</option><option value="audio">Audio / song</option><option value="custom">Custom package</option><option value="general">General idea</option></select></label><label>Request title<input name="title" maxlength="190" required></label></div>
          <label>Describe the request<textarea name="description" maxlength="10000" required></textarea></label>
          <div class="communication-form-grid"><label>Budget minimum <small>optional</small><input type="number" min="0" step="0.01" name="budget_min"></label><label>Budget maximum <small>optional</small><input type="number" min="0" step="0.01" name="budget_max"></label><label>Currency<input name="currency" value="USD" maxlength="3"></label><label>Preferred completion date <small>optional</small><input type="datetime-local" name="requested_due_at"></label></div>
          <?php if(!empty($communicationSettings['memberAttachmentsEnabled'])):?><label class="communication-upload">Reference files <small>optional · up to 5 files</small><input type="file" name="references[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf,text/plain"></label><?php endif;?>
          <button class="button button--primary communication-full-button">Submit Private Request</button>
        </form><?php else:?><div class="communication-alert warning">New content requests are unavailable.</div><?php endif;?>
      </section>

      <?php if($selected):?><section class="communication-card"><div class="member-panel-head"><div><span class="communication-kicker">Request <?=e('VVX-R-'.strtoupper(substr((string)$selected['id'],-8)))?></span><h2><?=e((string)$selected['title'])?></h2></div><span class="communication-status <?=e((string)$selected['status'])?>"><?=e(str_replace('_',' ',(string)$selected['status']))?></span></div>
        <p><?=nl2br(e((string)$selected['description']))?></p>
        <div class="request-detail-grid"><article><span>Category</span><strong><?=e(ucfirst((string)$selected['category']))?></strong></article><article><span>Budget</span><strong><?php if($selected['budgetMin']!==null||$selected['budgetMax']!==null):?><?=e((string)$selected['currency'])?> <?=e(number_format((float)($selected['budgetMin']??0),2))?>–<?=e(number_format((float)($selected['budgetMax']??$selected['budgetMin']??0),2))?><?php else:?>Not specified<?php endif;?></strong></article><article><span>Quote</span><strong><?=$selected['quotedAmount']!==null?e((string)$selected['currency'].' '.number_format((float)$selected['quotedAmount'],2)):'Pending review'?></strong></article></div>
        <?php if(!empty($selected['attachments'])):?><div class="attachment-list"><?php foreach($selected['attachments'] as $attachment):?><a href="<?=e(base_url('communication/download.php?kind='.rawurlencode((string)($attachment['kind']??'request')).'&id='.rawurlencode((string)$attachment['id'])))?>">⇩ <?=e((string)$attachment['originalName'])?></a><?php endforeach;?></div><?php endif;?>
        <div class="conversation-messages" style="padding:20px 0;background:none"><?php foreach($messages as $message):?><article class="message-bubble <?=e((string)$message['senderType'])?>"><div class="message-meta"><strong><?=$message['senderType']==='member'?e((string)$member['displayName']):($message['senderType']==='admin'?'Nikki / Staff':'System')?></strong><time><?=e((string)$message['createdAt'])?></time></div><p><?=e((string)$message['body'])?></p><?php if(!empty($message['attachments'])):?><div class="attachment-list"><?php foreach($message['attachments'] as $attachment):?><a href="<?=e(base_url('communication/download.php?kind='.rawurlencode((string)($attachment['kind']??'request')).'&id='.rawurlencode((string)$attachment['id'])))?>">⇩ <?=e((string)$attachment['originalName'])?></a><?php endforeach;?></div><?php endif;?></article><?php endforeach;?></div>
        <?php if(!$blocked&&!in_array((string)$selected['status'],['declined','completed','cancelled'],true)):?><form method="post" enctype="multipart/form-data" class="communication-form"><?=csrf_field()?><input type="hidden" name="action" value="reply_request"><input type="hidden" name="request_id" value="<?=e((string)$selected['id'])?>"><label>Reply to this request<textarea name="message" maxlength="10000" required></textarea></label><?php if(!empty($communicationSettings['memberAttachmentsEnabled'])):?><input type="file" name="references[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf,text/plain"><?php endif;?><button class="button button--primary">Send Reply</button></form><?php endif;?>
        <?php if(in_array((string)$selected['status'],['submitted','reviewing','quoted'],true)):?><form method="post" style="margin-top:16px" onsubmit="return confirm('Cancel this request?')"><?=csrf_field()?><input type="hidden" name="action" value="cancel_request"><input type="hidden" name="request_id" value="<?=e((string)$selected['id'])?>"><button class="button button--ghost button--small member-danger">Cancel Request</button></form><?php endif;?>
      </section><?php endif;?>
    </div>
  </div>
</div></div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
