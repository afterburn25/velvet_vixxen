<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';

use App\Core\MailService;

if (!is_installed()) redirect(base_url('install/'));
$store = app_store();
$communicationSettings = $store->communicationSettings();
$member = current_member();
$error = '';
$success = flash('booking_success');
$reference = flash('booking_reference');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your form session expired. Refresh and try again.');
        if (empty($communicationSettings['bookingEnabled'])) throw new RuntimeException('Booking inquiries are temporarily unavailable.');
        if (trim((string) ($_POST['website'] ?? '')) !== '') throw new RuntimeException('The inquiry could not be submitted.');
        if (!isset($_POST['consent'])) throw new RuntimeException('Confirm that the inquiry details may be used to respond.');
        $attachments = [];
        if (!empty($communicationSettings['publicBookingAttachmentsEnabled']) && isset($_FILES['references'])) {
            $attachments = communication_upload_service()->uploadMany((array) $_FILES['references']);
        }
        $request = $store->createBookingRequest([
            'memberId' => $member['id'] ?? null,
            'inquiryType' => (string) ($_POST['inquiry_type'] ?? 'appearance'),
            'contactName' => (string) ($_POST['contact_name'] ?? ''),
            'contactEmail' => (string) ($_POST['contact_email'] ?? ''),
            'phone' => (string) ($_POST['phone'] ?? ''),
            'requestedDate' => (string) ($_POST['requested_date'] ?? ''),
            'city' => (string) ($_POST['city'] ?? ''),
            'venue' => (string) ($_POST['venue'] ?? ''),
            'budget' => (string) ($_POST['budget'] ?? ''),
            'details' => (string) ($_POST['details'] ?? ''),
            'attachments' => $attachments,
        ]);
        if (!empty($communicationSettings['autoAcknowledgeBooking'])) {
            $mailer = new MailService(app_config(), $store);
            $mailer->send($member['id'] ?? null, (string) $request['contactEmail'], 'Booking inquiry received', 'booking.received',
                "Thank you for your booking inquiry.\n\nReference: VVX-B-" . strtoupper(substr((string) $request['id'], -8)) . "\nStatus: Submitted\n\nYour details are being reviewed privately. Replies and status changes will be sent by email" . ($member ? ' and will also appear in your member account.' : '.')
            );
        }
        addon_event('booking.submitted', ['request' => $request, 'member' => $member]);
        flash('booking_success', 'Your private inquiry was submitted successfully.');
        flash('booking_reference', 'VVX-B-' . strtoupper(substr((string) $request['id'], -8)));
        redirect(base_url('booking/?submitted=1'));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$memberBookings = $member ? $store->bookingRequests(['memberId' => (string) $member['id']]) : [];
$pageTitle = 'Booking & Appearances';
$activeNav = 'booking';
require root_path('app/Views/public/partials/header.php');
?>
<div class="communication-main">
  <div class="communication-wrap">
    <section class="communication-hero">
      <div class="communication-hero-content">
        <span class="communication-kicker">Professional inquiries</span>
        <h1>Booking &amp;<br>Appearances</h1>
        <p>Submit an appearance, collaboration, interview, media, or professional-event inquiry. Details are stored privately and reviewed through the owner booking queue.</p>
        <a class="button button--primary" href="#bookingForm">Start an Inquiry</a>
      </div>
    </section>

    <?php if ($success): ?>
      <div class="communication-alert success" style="margin-top:24px"><strong><?= e($success) ?></strong><?php if ($reference): ?><br>Reference: <?= e($reference) ?><?php endif; ?></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="communication-alert error" style="margin-top:24px"><?= e($error) ?></div><?php endif; ?>
    <?php if (empty($communicationSettings['bookingEnabled'])): ?><div class="communication-alert warning" style="margin-top:24px">Booking inquiries are temporarily unavailable.</div><?php endif; ?>

    <div class="communication-layout" id="bookingForm">
      <section class="communication-card">
        <span class="communication-kicker">Private intake</span>
        <h2>Tell me about the opportunity.</h2>
        <p>Required fields are marked. Attachments are protected outside the public web directory.</p>
        <form method="post" enctype="multipart/form-data" class="communication-form">
          <?= csrf_field() ?>
          <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">
          <label>Inquiry type
            <select name="inquiry_type" required>
              <option value="">Choose an inquiry type</option>
              <?php foreach (['appearance'=>'Appearance','collaboration'=>'Collaboration','interview'=>'Interview / Podcast','event'=>'Private or Public Event','media'=>'Media / Press','other'=>'Other Professional Inquiry'] as $value=>$label): ?>
                <option value="<?= e($value) ?>" <?= (string)($_POST['inquiry_type']??'')===$value?'selected':'' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="communication-form-grid">
            <label>Your name<input name="contact_name" maxlength="120" required value="<?= e((string)($_POST['contact_name']??$member['displayName']??'')) ?>"></label>
            <label>Email address<input type="email" name="contact_email" maxlength="190" required value="<?= e((string)($_POST['contact_email']??$member['email']??'')) ?>"></label>
            <label>Phone <small>optional</small><input name="phone" maxlength="80" value="<?= e((string)($_POST['phone']??'')) ?>"></label>
            <label>Requested date <small>optional</small><input type="datetime-local" name="requested_date" value="<?= e((string)($_POST['requested_date']??'')) ?>"></label>
            <label>City<input name="city" maxlength="190" value="<?= e((string)($_POST['city']??'')) ?>"></label>
            <label>Venue or platform<input name="venue" maxlength="255" value="<?= e((string)($_POST['venue']??'')) ?>"></label>
          </div>
          <label>Estimated budget <small>optional</small><input name="budget" maxlength="190" placeholder="Example: $1,500–$2,500" value="<?= e((string)($_POST['budget']??'')) ?>"></label>
          <label>Inquiry details<textarea name="details" maxlength="10000" required><?= e((string)($_POST['details']??'')) ?></textarea></label>
          <?php if (!empty($communicationSettings['publicBookingAttachmentsEnabled'])): ?>
          <label class="communication-upload">Reference files <small>optional · up to 5 JPG, PNG, WebP, PDF, or text files · <?= e((string)$communicationSettings['maxAttachmentMb']) ?> MB each</small><input type="file" name="references[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf,text/plain"></label>
          <?php endif; ?>
          <label class="communication-check"><input type="checkbox" name="consent" value="1" required><span>I confirm these details are accurate and may be used to respond to this professional inquiry.</span></label>
          <button class="button button--primary communication-full-button" <?= empty($communicationSettings['bookingEnabled'])?'disabled':'' ?>>Submit Private Inquiry</button>
        </form>
      </section>
      <aside class="communication-aside">
        <section class="communication-card"><span class="communication-kicker">What happens next</span><h3>Private review</h3><p>Your inquiry enters a status-controlled owner queue. Replies and quote updates are sent by email, and signed-in members can follow them in their account.</p></section>
        <section class="communication-card"><span class="communication-kicker">Good details help</span><h3>Include the essentials</h3><p>Share the date, city or platform, expected scope, budget range, and any firm deadlines. Do not upload sensitive identity or payment documents here.</p></section>
        <section class="communication-card"><span class="communication-kicker">Member advantage</span><h3>Keep everything together</h3><p>Sign in before submitting to connect the inquiry to your private inbox, notifications, attachments, and status history.</p><?php if (!$member): ?><a class="button button--ghost button--small" href="<?= e(base_url('account/login.php?return='.rawurlencode('/booking/'))) ?>">Member Sign In</a><?php else: ?><a class="button button--ghost button--small" href="<?= e(base_url('messages/')) ?>">Open Private Inbox</a><?php endif; ?></section>
      </aside>
    </div>

    <?php if ($member && $memberBookings !== []): ?>
    <section class="communication-card" style="margin-top:24px">
      <span class="communication-kicker">Your account</span><h2>Recent booking inquiries</h2>
      <div class="communication-history">
      <?php foreach (array_slice($memberBookings,0,8) as $booking): ?>
        <a class="communication-history-card" href="<?= e(base_url('messages/?thread='.rawurlencode((string)$booking['threadId']))) ?>">
          <div><strong><?= e(ucwords(str_replace('_',' ',(string)$booking['inquiryType']))) ?></strong><small><?= e((string)$booking['city']) ?><?= $booking['requestedDate']?' · '.e(date('M j, Y',strtotime((string)$booking['requestedDate']))):'' ?></small></div>
          <span class="communication-status <?= e((string)$booking['status']) ?>"><?= e(str_replace('_',' ',(string)$booking['status'])) ?></span>
        </a>
      <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
