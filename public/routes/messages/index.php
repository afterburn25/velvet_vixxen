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
$success = flash('message_success');
$blocked = $store->isCommunicationBlocked($memberId, (string) $member['email'], 'messaging');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your form session expired. Refresh and try again.');
        if (empty($communicationSettings['messagingEnabled'])) throw new RuntimeException('Private messaging is temporarily unavailable.');
        if ($blocked) throw new RuntimeException('Messaging is not available for this account.');
        $action = (string) ($_POST['action'] ?? '');
        $attachments = [];
        if (!empty($communicationSettings['memberAttachmentsEnabled']) && isset($_FILES['attachments'])) {
            $attachments = communication_upload_service()->uploadMany((array) $_FILES['attachments']);
        }
        if ($action === 'new_thread') {
            $thread = $store->createCommunicationThread([
                'memberId' => $memberId,
                'type' => 'member',
                'subject' => (string) ($_POST['subject'] ?? ''),
                'status' => 'pending_staff',
            ]);
            $store->sendCommunicationMessage([
                'threadId' => $thread['id'],
                'senderType' => 'member',
                'senderMemberId' => $memberId,
                'body' => (string) ($_POST['message'] ?? ''),
                'attachments' => $attachments,
            ]);
            addon_event('message.thread.created', ['thread' => $thread, 'member' => $member]);
            flash('message_success', 'Your private message was sent.');
            redirect(base_url('messages/?thread=' . rawurlencode((string) $thread['id'])));
        }
        if ($action === 'send_message') {
            $threadId = (string) ($_POST['thread_id'] ?? '');
            $thread = $store->communicationThread($threadId);
            if (!$thread || (string) ($thread['memberId'] ?? '') !== $memberId) throw new RuntimeException('Conversation not found.');
            if (in_array((string) $thread['status'], ['closed','blocked'], true)) throw new RuntimeException('This conversation is closed.');
            $store->sendCommunicationMessage([
                'threadId' => $threadId,
                'senderType' => 'member',
                'senderMemberId' => $memberId,
                'body' => (string) ($_POST['message'] ?? ''),
                'attachments' => $attachments,
            ]);
            addon_event('message.sent', ['thread' => $thread, 'member' => $member, 'senderType' => 'member']);
            flash('message_success', 'Message sent.');
            redirect(base_url('messages/?thread=' . rawurlencode($threadId)));
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$threads = $store->communicationThreads(['memberId' => $memberId]);
$selectedId = (string) ($_GET['thread'] ?? ($threads[0]['id'] ?? ''));
$selected = $selectedId !== '' ? $store->communicationThread($selectedId) : null;
if ($selected && (string) ($selected['memberId'] ?? '') !== $memberId) $selected = null;
$messages = $selected ? $store->communicationMessages((string) $selected['id'], false) : [];
if ($selected) $store->markCommunicationThreadRead((string) $selected['id'], 'member');
$notifications = $store->memberNotifications($memberId, true);
$pageTitle = 'Private Messages';
$activeNav = 'membership';
require root_path('app/Views/public/partials/header.php');
?>
<div class="communication-main">
  <div class="communication-wrap">
    <div class="request-page-head"><div><span class="communication-kicker">Private communication center</span><h1>Messages</h1><p>Keep member questions, booking replies, content requests, and protected attachments together.</p></div><div><a class="button button--ghost" href="<?= e(base_url('requests/')) ?>">Content Requests</a> <a class="button button--ghost" href="<?= e(base_url('booking/')) ?>">Booking</a></div></div>
    <?php if ($success): ?><div class="communication-alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="communication-alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($blocked): ?><div class="communication-alert warning">Messaging and new requests are restricted for this account. Existing messages remain visible.</div><?php endif; ?>
    <?php if ($notifications !== []): ?><div class="communication-alert success"><strong><?= e((string) count($notifications)) ?> unread notification<?= count($notifications)===1?'':'s' ?>.</strong> Open a conversation below to review the newest reply.</div><?php endif; ?>

    <div class="inbox-shell">
      <aside class="inbox-sidebar">
        <div class="inbox-sidebar-head"><span class="communication-kicker">Inbox</span><h1>Your conversations</h1></div>
        <?php if (!$blocked && !empty($communicationSettings['messagingEnabled'])): ?>
        <form method="post" enctype="multipart/form-data" class="inbox-new">
          <?= csrf_field() ?><input type="hidden" name="action" value="new_thread">
          <strong>Start a new conversation</strong>
          <input name="subject" maxlength="190" placeholder="Subject" required>
          <textarea name="message" maxlength="10000" rows="4" placeholder="Write your private message" required></textarea>
          <?php if (!empty($communicationSettings['memberAttachmentsEnabled'])): ?><label>Attachments (optional)<input type="file" name="attachments[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf,text/plain"></label><?php endif; ?>
          <button class="button button--primary button--small">Send Message</button>
        </form>
        <?php endif; ?>
        <nav class="thread-list" aria-label="Conversation list">
          <?php foreach ($threads as $thread): ?>
          <a class="<?= $selected && (string)$selected['id']===(string)$thread['id']?'active':'' ?>" href="<?= e(base_url('messages/?thread='.rawurlencode((string)$thread['id']))) ?>">
            <div class="thread-list-meta"><strong><?= e((string)$thread['subject']) ?></strong><?php if ((int)$thread['memberUnreadCount']>0): ?><span class="unread-dot"><?= e((string)$thread['memberUnreadCount']) ?></span><?php endif; ?></div>
            <small><?= e(ucwords(str_replace('_',' ',(string)$thread['type']))) ?> · <?= e(ucwords(str_replace('_',' ',(string)$thread['status']))) ?></small>
            <small><?= e((string)($thread['lastMessageAt']??$thread['createdAt'])) ?></small>
          </a>
          <?php endforeach; ?>
          <?php if ($threads === []): ?><div class="conversation-empty" style="min-height:180px">No conversations yet.</div><?php endif; ?>
        </nav>
      </aside>

      <section class="conversation-panel">
        <?php if ($selected): ?>
          <header class="conversation-head"><div><span class="communication-kicker"><?= e(ucwords(str_replace('_',' ',(string)$selected['type']))) ?></span><h1><?= e((string)$selected['subject']) ?></h1><p>Status: <?= e(ucwords(str_replace('_',' ',(string)$selected['status']))) ?></p></div><span class="communication-status <?= e((string)$selected['status']) ?>"><?= e(str_replace('_',' ',(string)$selected['status'])) ?></span></header>
          <div class="conversation-messages">
            <?php foreach ($messages as $message): ?>
              <article class="message-bubble <?= e((string)$message['senderType']) ?>">
                <div class="message-meta"><strong><?= $message['senderType']==='member'?e((string)$member['displayName']):($message['senderType']==='admin'?'Nikki / Staff':'System') ?></strong><time><?= e((string)$message['createdAt']) ?></time></div>
                <p><?= e((string)$message['body']) ?></p>
                <?php if (!empty($message['attachments'])): ?><div class="attachment-list"><?php foreach ($message['attachments'] as $attachment): ?><a href="<?= e(base_url('communication/download.php?id='.rawurlencode((string)$attachment['id']))) ?>">⇩ <?= e((string)$attachment['originalName']) ?></a><?php endforeach; ?></div><?php endif; ?>
              </article>
            <?php endforeach; ?>
            <?php if ($messages === []): ?><div class="conversation-empty">No messages are in this conversation yet.</div><?php endif; ?>
          </div>
          <?php if (!$blocked && !in_array((string)$selected['status'],['closed','blocked'],true) && !empty($communicationSettings['messagingEnabled'])): ?>
          <form method="post" enctype="multipart/form-data" class="conversation-reply">
            <?= csrf_field() ?><input type="hidden" name="action" value="send_message"><input type="hidden" name="thread_id" value="<?= e((string)$selected['id']) ?>">
            <textarea name="message" maxlength="10000" rows="5" placeholder="Write a private reply" required></textarea>
            <?php if (!empty($communicationSettings['memberAttachmentsEnabled'])): ?><label>Attachments (optional)<input type="file" name="attachments[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf,text/plain"></label><?php endif; ?>
            <button class="button button--primary communication-full-button">Send Reply</button>
          </form>
          <?php else: ?><div class="communication-alert warning" style="margin:20px">This conversation is closed or messaging is unavailable.</div><?php endif; ?>
        <?php else: ?><div class="conversation-empty"><div><span class="communication-kicker">Private inbox</span><h2>Choose a conversation.</h2><p>Select an existing thread or start a new one from the left panel.</p></div></div><?php endif; ?>
      </section>
    </div>
  </div>
</div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
