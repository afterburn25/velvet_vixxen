<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require ROOT_PATH . '/app/bootstrap.php';

use App\Core\MailService;
use App\Core\BookingFormService;
use App\Core\BookingFormValidationException;
use App\Core\BookingSubmissionLimiter;

if (!is_installed()) redirect(base_url('install/'));
$store = app_store();
$communicationSettings = $store->communicationSettings();
$member = current_member();
$error = '';
$success = flash('booking_success');
$reference = flash('booking_reference');
$bookingBuilder = new BookingFormService($store);
$bookingDefinition = $bookingBuilder->definition();
$bookingValues = $_SERVER['REQUEST_METHOD'] === 'POST' ? $bookingBuilder->valuesFromPost($_POST)
    : ['contact_name' => (string) ($member['displayName'] ?? ''), 'contact_email' => (string) ($member['email'] ?? '')];
$bookingFieldErrors = [];
$bookingUploads = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your form session expired. Refresh and try again.');
        if (empty($communicationSettings['bookingEnabled'])) throw new RuntimeException('Booking inquiries are temporarily unavailable.');
        if (!is_string($_POST['website'] ?? '') || trim($_POST['website'] ?? '') !== '') throw new RuntimeException('The inquiry could not be submitted.');
        if (($_POST['consent'] ?? '') !== '1') throw new RuntimeException('Confirm that the inquiry details may be used to respond.');
        (new BookingSubmissionLimiter(ROOT_PATH, (string) app_config('security.app_key', '')))->consume((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $validation = $bookingBuilder->validate($bookingValues, is_string($_POST['form_revision'] ?? null) ? $_POST['form_revision'] : '');
        if ($validation['errors'] !== []) throw new BookingFormValidationException($validation['errors']);
        $attachments = [];
        if (empty($communicationSettings['publicBookingAttachmentsEnabled']) && isset($_FILES['references'])) {
            foreach ((array) ($_FILES['references']['error'] ?? UPLOAD_ERR_NO_FILE) as $uploadError) {
                if ((int) $uploadError !== UPLOAD_ERR_NO_FILE) throw new RuntimeException('Reference attachments are currently disabled.');
            }
        }
        if (!empty($communicationSettings['publicBookingAttachmentsEnabled']) && isset($_FILES['references'])) {
            $bookingUploads = communication_upload_service();
            $attachments = $bookingUploads->uploadMany((array) $_FILES['references']);
        }
        $request = $store->createBookingRequest([
            'memberId' => $member['id'] ?? null,
            'formValues' => $bookingValues,
            'formRevision' => $validation['submission']['definitionRevision'],
            'attachments' => $attachments,
        ]);
        $bookingUploads?->completeUploads();
        if (!empty($communicationSettings['autoAcknowledgeBooking']) && $request['contactEmail'] !== '') {
            $mailer = new MailService(app_config(), $store);
            $mailer->send($member['id'] ?? null, (string) $request['contactEmail'], 'Booking inquiry received', 'booking.received',
                "Thank you for your booking inquiry.\n\nReference: VVX-B-" . strtoupper(substr((string) $request['id'], -8)) . "\nStatus: Submitted\n\nYour details are being reviewed privately. Replies and status changes will be sent by email" . ($member ? ' and will also appear in your member account.' : '.')
            );
        }
        addon_event('booking.submitted', ['request' => $request, 'member' => $member]);
        flash('booking_success', 'Your private inquiry was submitted successfully.');
        flash('booking_reference', 'VVX-B-' . strtoupper(substr((string) $request['id'], -8)));
        redirect(base_url('booking/?submitted=1'));
    } catch (BookingFormValidationException $e) {
        $bookingUploads?->discardPendingUploads();
        $bookingFieldErrors = $e->errors;
        $error = $bookingFieldErrors['_form'] ?? $e->getMessage();
    } catch (Throwable $e) {
        $bookingUploads?->discardPendingUploads();
        $error = $e->getMessage();
    }
}

$memberBookings = $member ? $store->bookingRequests(['memberId' => (string) $member['id']]) : [];
$pageTitle = 'Booking & Appearances';
$activeNav = 'booking';
require root_path('app/Views/public/partials/header.php');
?>
<link rel="stylesheet" href="<?=e(asset_url('css/booking-form.css'))?>">
<div class="communication-main booking-page">
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
        <form method="post" enctype="multipart/form-data" class="communication-form" data-booking-form>
          <?= csrf_field() ?>
          <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">
          <input type="hidden" name="form_revision" value="<?=e($bookingDefinition['revision'])?>">
          <div class="booking-fields-grid"><?php require root_path('app/Views/public/partials/booking-fields.php'); ?></div>
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
<script src="<?=e(asset_url('js/booking-form.js'))?>" defer></script>
<?php require root_path('app/Views/public/partials/footer.php'); ?>
