<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require ROOT_PATH . '/app/bootstrap.php';

use App\Core\UploadService;
use App\Core\MediaAdminService;
use App\Core\MailService;
use App\Core\RepairBackupService;

if (!is_installed()) {
    redirect(base_url('install/'));
}

$adminSessionOwner = (string) ($_SESSION['admin']['id'] ?? '');
$page = (string) ($_GET['page'] ?? ($_SESSION['admin_last_pages'][$adminSessionOwner] ?? 'dashboard'));
$error = '';

if ($page === 'logout') {
    app_auth()->logout();
    redirect(base_url('admin/?page=login'));
}

if ($page === 'login') {
    if (app_auth()->check()) {
        redirect(base_url('admin/'));
    }
    $installComplete = isset($_GET['installed']) && is_installed();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf()) {
            $error = 'Your sign-in session expired. Refresh and try again.';
        } elseif (!app_auth()->attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            $error = 'The email address or password was not accepted.';
        } else {
            redirect(base_url('admin/'));
        }
    }
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Sign In</title><link rel="stylesheet" href="<?= e(asset_url('css/admin.css')) ?>"><link rel="icon" href="<?= e(base_url('images/defaults/brand-mark.png')) ?>" type="image/png"></head><body class="login-body"><main class="login-shell"><div class="login-brand"><span>Nikki Wilder</span><small>The Velvet Vixxen</small></div><form method="post" class="login-card"><?= csrf_field() ?><div class="admin-kicker">Private administration</div><h1>Welcome back.</h1><p>Sign in to manage the live design, Canva templates, and media.</p><?php if ($installComplete): ?><div class="admin-alert success"><strong>Installation complete.</strong> The database, owner account, organized folders, security secrets, and installation lock were verified. The installer cleanup was completed or safely locked by the server.</div><?php endif; ?><?php if ($error): ?><div class="admin-alert error"><?= e($error) ?></div><?php endif; ?><label>Email address<input type="email" name="email" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="admin-button primary">Sign in</button><a class="back-link" href="<?= e(base_url()) ?>">← Return to the website</a></form></main><script src="<?= e(asset_url('js/admin-ux.js')) ?>" defer></script></body></html>
    <?php exit;
}

require_admin();
$user = app_auth()->user();
$store = app_store();
$addonManager = addon_manager();
$licenseHub = addon_license_hub();
$licenseClient = addon_license_client();
$addonUpdateService = addon_update_service();
$addonReview = null;
$repairService = new RepairBackupService(ROOT_PATH);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf()) {
            throw new RuntimeException('Your session token expired. Refresh the page and try again.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($page === 'marketing') require root_path('app/Views/admin/marketing-actions.php');
        require root_path('app/Views/admin/merchandise-actions.php');
        if ($action === 'apply_database_updates') require root_path('app/Support/database_updates_action.php');
        if ($action === 'save_about_page') {
            if (($user['role'] ?? '') !== 'owner') throw new RuntimeException('Owner access is required.');
            (new App\Core\AboutPage($store))->save($_POST);
            $store->audit(app_auth()->id(), 'site.about.updated');
            flash('success', 'About Me was saved.');
            redirect(base_url('admin/?page=about'));
        }

        if (in_array($action, ['save_booking_form_field', 'move_booking_form_field', 'toggle_booking_form_field', 'delete_booking_form_field'], true)) {
            if ((string) ($user['role'] ?? '') !== 'owner') throw new RuntimeException('Only the owner can change the booking form.');
            $bookingBuilder = new \App\Core\BookingFormService($store);
            $fieldId = is_string($_POST['field_id'] ?? null) ? $_POST['field_id'] : '';
            $revision = is_string($_POST['form_revision'] ?? null) ? $_POST['form_revision'] : '';
            if ($action === 'save_booking_form_field') {
                $bookingBuilder->saveField(['id' => $fieldId, 'label' => $_POST['field_label'] ?? '', 'type' => $_POST['field_type'] ?? 'text',
                    'help' => $_POST['field_help'] ?? '', 'placeholder' => $_POST['field_placeholder'] ?? '',
                    'options' => $_POST['field_options'] ?? '', 'minSelections' => $_POST['field_minimum'] ?? 0,
                    'required' => isset($_POST['field_required']), 'enabled' => isset($_POST['field_enabled'])], $revision);
            } elseif ($action === 'move_booking_form_field') {
                $bookingBuilder->moveField($fieldId, (string) ($_POST['direction'] ?? ''), $revision);
            } elseif ($action === 'toggle_booking_form_field') {
                $bookingBuilder->toggleField($fieldId, $revision);
            } else {
                $bookingBuilder->deleteField($fieldId, $revision);
            }
            $store->audit(app_auth()->id(), 'booking_form.' . $action, ['fieldId' => $fieldId]);
            flash('success', 'Booking form updated. Earlier inquiries were preserved.');
            redirect(base_url('admin/?page=booking-form'));
        }
        if ($action === 'inspect_addon_upload') {
            $addonReview = $addonManager->stageUpload((array) ($_FILES['addon_package'] ?? []));
            $page = 'addons';
        }
        if ($action === 'inspect_starter_addon') {
            $addonReview = $addonManager->stageBundledStarter();
            $page = 'addons';
        }
        if ($action === 'install_addon') {
            $record = $addonManager->install((string) ($_POST['review_token'] ?? ''), isset($_POST['trust_package']), isset($_POST['activate_after_install']));
            $store->audit(app_auth()->id(), 'addon.installed', ['addonKey'=>(string)$record['addonKey'],'version'=>(string)$record['version']]);
            flash('success', (string)$record['name'] . ' was installed' . (($record['status']??'')==='active' ? ' and activated.' : '.'));
            redirect(base_url('admin/?page=addons'));
        }
        if ($action === 'activate_addon') {
            $record = $addonManager->activate((string) ($_POST['addon_id'] ?? ''));
            $store->audit(app_auth()->id(), 'addon.activated', ['addonKey'=>(string)$record['addonKey']]);
            flash('success', (string)$record['name'] . ' activated.');
            redirect(base_url('admin/?page=addons'));
        }
        if ($action === 'deactivate_addon') {
            $record = $addonManager->deactivate((string) ($_POST['addon_id'] ?? ''));
            $store->audit(app_auth()->id(), 'addon.deactivated', ['addonKey'=>(string)$record['addonKey']]);
            flash('success', (string)$record['name'] . ' deactivated.');
            redirect(base_url('admin/?page=addons'));
        }
        if ($action === 'rollback_addon') {
            $record = $addonManager->rollbackLatest((string) ($_POST['addon_id'] ?? ''));
            $store->audit(app_auth()->id(), 'addon.rolled_back', ['addonKey'=>(string)$record['addonKey'],'version'=>(string)$record['version']]);
            flash('success', (string)$record['name'] . ' restored from its latest backup.');
            redirect(base_url('admin/?page=addons'));
        }
        if ($action === 'uninstall_addon') {
            $addonId = (string) ($_POST['addon_id'] ?? '');
            $addonManager->uninstall($addonId, isset($_POST['purge_data']));
            $store->audit(app_auth()->id(), 'addon.uninstalled', ['addonKey'=>$addonId,'purgeData'=>isset($_POST['purge_data'])]);
            flash('success', 'Add-on uninstalled.');
            redirect(base_url('admin/?page=addons'));
        }
        if ($action === 'toggle_addon_safe_mode') {
            $enabled = (string) ($_POST['enabled'] ?? '0') === '1';
            $addonManager->setSafeMode($enabled);
            $store->audit(app_auth()->id(), $enabled ? 'addons.safe_mode.enabled' : 'addons.safe_mode.disabled');
            flash('success', $enabled ? 'Add-on Safe Mode enabled. Optional add-on code will not load.' : 'Add-on Safe Mode disabled. Active add-ons may load again.');
            redirect(base_url('admin/?page=addons'));
        }
        if ($action === 'generate_license_hub_keys') {
            $keys = $licenseHub->ensureSigningKeys();
            $store->audit(app_auth()->id(), 'addon.license_hub.keys.verified', ['fingerprint'=>$keys['fingerprint']]);
            flash('success', 'The licensing signing key pair is available and verified.');
            redirect(base_url('admin/?page=licenses'));
        }
        if ($action === 'save_addon_license_product') {
            $product = $licenseHub->createProduct([
                'productKey'=>(string)($_POST['product_key']??''),'addonKey'=>(string)($_POST['addon_key']??''),'name'=>(string)($_POST['name']??''),
                'licenseType'=>(string)($_POST['license_type']??'lifetime'),'billingInterval'=>(string)($_POST['billing_interval']??'one_time'),
                'durationDays'=>(int)($_POST['duration_days']??0),'siteLimit'=>(int)($_POST['site_limit']??1),
                'updatesDays'=>(int)($_POST['updates_days']??365),'supportDays'=>(int)($_POST['support_days']??365),
                'graceDays'=>(int)($_POST['grace_days']??7),'price'=>(float)($_POST['price']??0),'currency'=>(string)($_POST['currency']??'USD'),
                'active'=>isset($_POST['is_active']),
            ]);
            $store->audit(app_auth()->id(), 'addon.license_product.saved', ['productKey'=>$product['productKey'],'addonKey'=>$product['addonKey']]);
            flash('success', (string)$product['name'] . ' license product saved.');
            redirect(base_url('admin/?page=licenses'));
        }
        if ($action === 'issue_addon_license') {
            $issued = $licenseHub->issueLicense([
                'productKey'=>(string)($_POST['product_key']??''),'customerEmail'=>(string)($_POST['customer_email']??''),
                'amount'=>(float)($_POST['amount']??0),'reference'=>(string)($_POST['reference']??''),
            ]);
            $_SESSION['_issued_addon_license_once'] = [
                'licenseKey'=>$issued['licenseKey'],'customerEmail'=>$issued['license']['customerEmail'],'productKey'=>$issued['license']['productKey'],
            ];
            $store->audit(app_auth()->id(), 'addon.license.issued', ['licenseId'=>$issued['license']['id'],'productKey'=>$issued['license']['productKey']]);
            flash('success', 'License issued. Copy the raw key from the one-time panel.');
            redirect(base_url('admin/?page=licenses'));
        }
        if ($action === 'change_issued_license_status') {
            $license = $licenseHub->setLicenseStatus((string)($_POST['license_id']??''),(string)($_POST['status']??''),(string)($_POST['reason']??''));
            $store->audit(app_auth()->id(), 'addon.license.status.changed', ['licenseId'=>$license['id'],'status'=>$license['status']]);
            flash('success', 'License status changed to ' . (string)$license['status'] . '.');
            redirect(base_url('admin/?page=licenses'));
        }
        if ($action === 'renew_issued_license') {
            $license = $licenseHub->renewLicense((string)($_POST['license_id']??''),(int)($_POST['days']??365),(float)($_POST['amount']??0),(string)($_POST['reference']??''));
            $store->audit(app_auth()->id(), 'addon.license.renewed', ['licenseId'=>$license['id'],'expiresAt'=>$license['expiresAt']]);
            flash('success', 'License and update entitlement renewed.');
            redirect(base_url('admin/?page=licenses'));
        }
        if ($action === 'publish_addon_release') {
            $release = $licenseHub->publishReleaseUpload((array)($_FILES['release_package']??[]),[
                'channel'=>(string)($_POST['channel']??'stable'),'changelog'=>(string)($_POST['changelog']??''),
                'releaseNotesUrl'=>(string)($_POST['release_notes_url']??''),'published'=>isset($_POST['published']),
            ]);
            $store->audit(app_auth()->id(), 'addon.release.published', ['addonKey'=>$release['addonKey'],'version'=>$release['version'],'channel'=>$release['channel']]);
            flash('success', (string)$release['addonKey'] . ' v' . (string)$release['version'] . ' release saved.');
            redirect(base_url('admin/?page=licenses'));
        }
        if ($action === 'activate_addon_license') {
            $record = $licenseClient->activate((string)($_POST['addon_id']??''),(string)($_POST['license_key']??''),(string)($_POST['server_url']??''),(string)($_POST['public_key']??''),(string)($_POST['channel']??'stable'));
            $store->audit(app_auth()->id(), 'addon.client_license.activated', ['addonKey'=>$record['addonKey'],'status'=>$record['status']]);
            flash('success', 'License activated for ' . (string)$record['addonKey'] . '.');
            redirect(base_url('admin/?page=addons'));
        }
        if ($action === 'check_addon_license') {
            $record = $licenseClient->check((string)($_POST['addon_id']??''), true);
            $store->audit(app_auth()->id(), 'addon.client_license.checked', ['addonKey'=>$record['addonKey'],'status'=>$record['status']]);
            flash('success', 'License status: ' . (string)$record['status'] . '.');
            redirect(base_url('admin/?page=addons'));
        }
        if ($action === 'deactivate_addon_license') {
            $record = $licenseClient->deactivate((string)($_POST['addon_id']??''));
            $store->audit(app_auth()->id(), 'addon.client_license.deactivated', ['addonKey'=>$record['addonKey']]);
            flash('success', 'License deactivated for this installation.');
            redirect(base_url('admin/?page=addons'));
        }
        if ($action === 'save_addon_update_channel') {
            $record = $addonUpdateService->setChannel((string)($_POST['addon_id']??''),(string)($_POST['channel']??'stable'));
            flash('success', 'Update channel changed to ' . (string)$record['channel'] . '.');
            redirect(base_url('admin/?page=addons'));
        }
        if ($action === 'check_addon_update') {
            $check = $addonUpdateService->check((string)($_POST['addon_id']??''),(string)($_POST['channel']??'stable'));
            $message = ($check['status']??'') === 'available' ? 'Update ' . (string)($check['availableVersion']??'') . ' is available.' : 'No newer eligible update was found.';
            flash('success', $message);
            redirect(base_url('admin/?page=addons'));
        }
        if ($action === 'download_addon_update') {
            $addonReview = $addonUpdateService->downloadForReview((string)($_POST['addon_id']??''));
            $page = 'addons';
        }
        if ($action === 'save_addon_settings') {
            $addonId = (string) ($_POST['addon_id'] ?? '');
            $context = addon_runtime()->context($addonId);
            if ($context === null) throw new RuntimeException('The add-on must be active before its settings can be changed.');
            $settings = (array) ($_POST['settings'] ?? []);
            foreach ($settings as $key => $value) {
                if (!is_scalar($value)) continue;
                $context->setSetting((string)$key, is_string($value) ? trim($value) : $value);
            }
            if (!array_key_exists('enabled', $settings)) $context->setSetting('enabled', false);
            $store->audit(app_auth()->id(), 'addon.settings.updated', ['addonKey'=>$addonId]);
            flash('success', 'Add-on settings saved.');
            $returnPage = preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['return_page']??'addons')) ?: 'addons';
            redirect(base_url('admin/?page=' . $returnPage));
        }
        if ($action === 'admin_send_message') {
            $threadId = (string) ($_POST['thread_id'] ?? '');
            $thread = $store->communicationThread($threadId);
            if (!$thread) throw new RuntimeException('Conversation not found.');
            $visibility = isset($_POST['internal_note']) ? 'internal' : 'public';
            $attachments = [];
            if (isset($_FILES['attachments'])) {
                $attachments = communication_upload_service()->uploadMany((array) $_FILES['attachments']);
            }
            $message = $store->sendCommunicationMessage([
                'threadId' => $threadId,
                'senderType' => 'admin',
                'senderAdminId' => app_auth()->id(),
                'visibility' => $visibility,
                'body' => (string) ($_POST['message'] ?? ''),
                'attachments' => $attachments,
            ]);
            if ($visibility === 'public') {
                $recipient = '';
                $memberId = !empty($thread['memberId']) ? (string) $thread['memberId'] : null;
                if ($memberId !== null && !empty($thread['member']['email'])) {
                    $recipient = (string) $thread['member']['email'];
                } elseif ((string) ($thread['relatedType'] ?? '') === 'booking_request') {
                    $booking = $store->bookingRequest((string) ($thread['relatedId'] ?? ''));
                    $recipient = (string) ($booking['contactEmail'] ?? '');
                }
                if ($recipient !== '') {
                    (new MailService(app_config(), $store))->send(
                        $memberId,
                        $recipient,
                        'New private reply: ' . (string) $thread['subject'],
                        'communication.reply',
                        "A new private reply is waiting.\n\nSubject: " . (string) $thread['subject'] . "\n\n" . (string) $message['body'] . "\n\nOpen the website to review the full conversation and protected attachments.",
                        ['threadId' => $threadId]
                    );
                }
                addon_event('message.sent', ['thread' => $thread, 'message' => $message, 'senderType' => 'admin']);
            }
            $store->audit(app_auth()->id(), $visibility === 'internal' ? 'communication.note.added' : 'communication.reply.sent', ['threadId' => $threadId]);
            flash('success', $visibility === 'internal' ? 'Internal note saved.' : 'Reply sent.');
            redirect(base_url('admin/?page=messages&thread=' . rawurlencode($threadId)));
        }
        if ($action === 'admin_update_thread') {
            $threadId = (string) ($_POST['thread_id'] ?? '');
            $thread = $store->updateCommunicationThread($threadId, [
                'status' => (string) ($_POST['status'] ?? ''),
                'priority' => (string) ($_POST['priority'] ?? ''),
                'assignedAdminId' => (string) ($_POST['assigned_admin_id'] ?? ''),
                'internalNotes' => (string) ($_POST['internal_notes'] ?? ''),
            ]);
            $store->audit(app_auth()->id(), 'communication.thread.updated', ['threadId' => $threadId, 'status' => $thread['status'], 'priority' => $thread['priority']]);
            flash('success', 'Conversation details updated.');
            redirect(base_url('admin/?page=messages&thread=' . rawurlencode($threadId)));
        }
        if ($action === 'admin_update_content_request') {
            $requestId = (string) ($_POST['request_id'] ?? '');
            $before = $store->contentRequest($requestId);
            $request = $store->updateContentRequest($requestId, [
                'status' => (string) ($_POST['status'] ?? ''),
                'quotedAmount' => (string) ($_POST['quoted_amount'] ?? ''),
                'adminNotes' => (string) ($_POST['admin_notes'] ?? ''),
            ]);
            if ($before && (string) ($before['status'] ?? '') !== (string) $request['status']) {
                $member = $store->member((string) $request['memberId']);
                $store->createMemberNotification((string) $request['memberId'], 'content_request.status', 'Content request updated', '“' . (string) $request['title'] . '” is now ' . str_replace('_', ' ', (string) $request['status']) . '.', 'requests/?request=' . rawurlencode($requestId));
                if ($member && !empty($member['email'])) {
                    (new MailService(app_config(), $store))->send((string) $request['memberId'], (string) $member['email'], 'Content request update', 'content_request.status', "Your content request has been updated.\n\nRequest: " . (string) $request['title'] . "\nStatus: " . ucwords(str_replace('_', ' ', (string) $request['status'])) . ($request['quotedAmount'] !== null ? "\nQuote: " . (string) $request['currency'] . ' ' . number_format((float) $request['quotedAmount'], 2) : ''));
                }
            }
            $store->audit(app_auth()->id(), 'content_request.updated', ['requestId' => $requestId, 'status' => $request['status']]);
            flash('success', 'Content request updated.');
            redirect(base_url('admin/?page=content-requests&request=' . rawurlencode($requestId)));
        }
        if ($action === 'admin_update_booking_request') {
            $requestId = (string) ($_POST['request_id'] ?? '');
            $before = $store->bookingRequest($requestId);
            $request = $store->updateBookingRequest($requestId, [
                'status' => (string) ($_POST['status'] ?? ''),
                'priority' => (string) ($_POST['priority'] ?? ''),
                'assignedAdminId' => (string) ($_POST['assigned_admin_id'] ?? ''),
                'internalNotes' => (string) ($_POST['internal_notes'] ?? ''),
            ]);
            if ($before && (string) ($before['status'] ?? '') !== (string) $request['status']) {
                if (!empty($request['memberId'])) {
                    $store->createMemberNotification((string) $request['memberId'], 'booking.status', 'Booking inquiry updated', 'Your booking inquiry is now ' . str_replace('_', ' ', (string) $request['status']) . '.', 'messages/?thread=' . rawurlencode((string) $request['threadId']));
                }
                if (!empty($request['contactEmail'])) {
                    (new MailService(app_config(), $store))->send($request['memberId'] !== null ? (string) $request['memberId'] : null, (string) $request['contactEmail'], 'Booking inquiry update', 'booking.status', "Your booking inquiry has been updated.\n\nReference: VVX-B-" . strtoupper(substr($requestId, -8)) . "\nStatus: " . ucwords(str_replace('_', ' ', (string) $request['status'])));
                }
            }
            $store->audit(app_auth()->id(), 'booking_request.updated', ['requestId' => $requestId, 'status' => $request['status']]);
            flash('success', 'Booking inquiry updated.');
            redirect(base_url('admin/?page=booking-requests&request=' . rawurlencode($requestId)));
        }
        if ($action === 'save_saved_reply') {
            $reply = $store->saveSavedReply([
                'id' => (string) ($_POST['id'] ?? ''),
                'title' => (string) ($_POST['title'] ?? ''),
                'category' => (string) ($_POST['category'] ?? 'general'),
                'body' => (string) ($_POST['body'] ?? ''),
                'isActive' => isset($_POST['is_active']),
                'sortOrder' => (int) ($_POST['sort_order'] ?? 100),
            ]);
            $store->audit(app_auth()->id(), 'communication.saved_reply.saved', ['replyId' => $reply['id']]);
            flash('success', 'Saved reply updated.');
            redirect(base_url('admin/?page=communication-settings'));
        }
        if ($action === 'save_communication_settings') {
            $settings = $store->saveCommunicationSettings([
                'messagingEnabled' => isset($_POST['messaging_enabled']),
                'contentRequestsEnabled' => isset($_POST['content_requests_enabled']),
                'bookingEnabled' => isset($_POST['booking_enabled']),
                'memberAttachmentsEnabled' => isset($_POST['member_attachments_enabled']),
                'publicBookingAttachmentsEnabled' => isset($_POST['public_booking_attachments_enabled']),
                'maxAttachmentMb' => (int) ($_POST['max_attachment_mb'] ?? 10),
                'allowedExtensions' => preg_split('/[\s,]+/', (string) ($_POST['allowed_extensions'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                'autoAcknowledgeBooking' => isset($_POST['auto_acknowledge_booking']),
                'autoAcknowledgeRequests' => isset($_POST['auto_acknowledge_requests']),
                'supportReplyName' => (string) ($_POST['support_reply_name'] ?? ''),
                'bookingResponseDays' => (int) ($_POST['booking_response_days'] ?? 3),
            ]);
            $store->audit(app_auth()->id(), 'communication.settings.updated', ['settings' => $settings]);
            flash('success', 'Communication settings saved.');
            redirect(base_url('admin/?page=communication-settings'));
        }
        if ($action === 'block_communication') {
            $block = $store->blockCommunication([
                'memberId' => (string) ($_POST['member_id'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'scope' => (string) ($_POST['scope'] ?? 'all'),
                'reason' => (string) ($_POST['reason'] ?? ''),
                'expiresAt' => trim((string) ($_POST['expires_at'] ?? '')) !== '' ? date('c', strtotime((string) $_POST['expires_at'])) : null,
                'adminId' => app_auth()->id(),
            ]);
            $store->audit(app_auth()->id(), 'communication.block.created', ['blockId' => $block['id'], 'scope' => $block['scope']]);
            flash('success', 'Communication restriction added.');
            redirect(base_url('admin/?page=communication-settings'));
        }
        if ($action === 'remove_communication_block') {
            $store->removeCommunicationBlock((string) ($_POST['block_id'] ?? ''));
            $store->audit(app_auth()->id(), 'communication.block.removed', ['blockId' => (string) ($_POST['block_id'] ?? '')]);
            flash('success', 'Communication restriction removed.');
            redirect(base_url('admin/?page=communication-settings'));
        }
        if ($action === 'save_site_settings') {
            $site = (array) $store->setting('site', []);
            $site['brand_name'] = trim((string) ($_POST['brand_name'] ?? 'Nikki Wilder'));
            $site['brand_subtitle'] = trim((string) ($_POST['brand_subtitle'] ?? 'The Velvet Vixxen'));
            $site['tagline'] = trim((string) ($_POST['tagline'] ?? 'Bolder. Sexier. Closer.'));
            $site['hero_copy'] = trim((string) ($_POST['hero_copy'] ?? ''));
            $site['age_gate_enabled'] = isset($_POST['age_gate_enabled']);
            $site['newsletter_enabled'] = isset($_POST['newsletter_enabled']);
            $store->setSetting('site', $site);
            $store->audit(app_auth()->id(), 'settings.site.updated');
            flash('success', 'Site settings saved.');
            redirect(base_url('admin/?page=settings'));
        }
        if ($action === 'save_canva_url') {
            $templates = $store->templates();
            $id = (string) ($_POST['template_id'] ?? '');
            foreach ($templates as $template) {
                if ((string) ($template['id'] ?? '') === $id) {
                    $template['canvaUrl'] = trim((string) ($_POST['canva_url'] ?? ''));
                    $store->saveTemplate($template);
                    $store->audit(app_auth()->id(), 'canva.template.url.updated', ['template' => $id]);
                    flash('success', 'Canva edit link saved.');
                    redirect(base_url('admin/?page=canva'));
                }
            }
            throw new RuntimeException('Template not found.');
        }
        if ($action === 'import_canva_export') {
            $templates = $store->templates();
            $id = (string) ($_POST['template_id'] ?? '');
            $template = null;
            foreach ($templates as $candidate) {
                if ((string) ($candidate['id'] ?? '') === $id) {
                    $template = $candidate;
                    break;
                }
            }
            if (!$template) {
                throw new RuntimeException('Template not found.');
            }
            $asset = (new UploadService())->upload($_FILES['export_file'] ?? [], 'export');
            $asset['title'] = $template['title'] . ' — Canva Export';
            $asset = $store->addMedia($asset);
            $template['export'] = $asset['file'];
            $template['preview'] = 'uploads/' . $asset['thumbnail'];
            $store->saveTemplate($template);
            $assign = (string) ($_POST['assign_section'] ?? '');
            if (in_array($assign, ['hero', 'footer'], true)) {
                $scene = $store->scene($assign, false);
                $scene['background']['src'] = $asset['file'];
                $scene['background']['x'] = 50;
                $scene['background']['y'] = 50;
                $scene['background']['zoom'] = 1;
                $scene['background']['title'] = $asset['title'];
                unset($scene['background']['libraryId'], $scene['background']['modelZone'], $scene['background']['textZone']);
                foreach (['desktop', 'tablet', 'mobile'] as $device) {
                    $scene['devices'][$device]['background'] = array_replace((array) ($scene['devices'][$device]['background'] ?? $scene['background']), [
                        'src' => $asset['file'], 'x' => 50, 'y' => 50, 'zoom' => 1, 'title' => $asset['title'],
                    ]);
                    unset($scene['devices'][$device]['background']['libraryId'], $scene['devices'][$device]['background']['modelZone'], $scene['devices'][$device]['background']['textZone']);
                }
                $store->saveScene($assign, $scene, false, app_auth()->id());
            }
            $store->audit(app_auth()->id(), 'canva.export.imported', ['template' => $id, 'assign' => $assign]);
            flash('success', 'Canva export imported' . ($assign ? ' and assigned to the ' . $assign . ' draft.' : '.') );
            redirect(base_url('admin/?page=canva'));
        }
        if ($action === 'save_membership_plan') {
            $benefits = preg_split('/\R+/', (string) ($_POST['benefits'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $plan = $store->saveMembershipPlan([
                'id' => (string) ($_POST['id'] ?? ''),
                'key' => (string) ($_POST['key'] ?? ''),
                'name' => (string) ($_POST['name'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'billingInterval' => (string) ($_POST['billing_interval'] ?? 'month'),
                'intervalCount' => (int) ($_POST['interval_count'] ?? 1),
                'price' => (float) ($_POST['price'] ?? 0),
                'currency' => (string) ($_POST['currency'] ?? 'USD'),
                'trialDays' => (int) ($_POST['trial_days'] ?? 0),
                'ppvDiscountPercent' => (float) ($_POST['ppv_discount_percent'] ?? 0),
                'benefits' => $benefits,
                'isActive' => isset($_POST['is_active']),
                'sortOrder' => (int) ($_POST['sort_order'] ?? 100),
            ]);
            $store->audit(app_auth()->id(), 'membership.plan.saved', ['id' => (string) $plan['id']]);
            flash('success', (string) $plan['name'] . ' saved.');
            redirect(base_url('admin/?page=plans&edit=' . rawurlencode((string) $plan['id'])));
        }
        if ($action === 'update_member') {
            $memberId = (string) ($_POST['member_id'] ?? '');
            $member = $store->updateMember($memberId, ['status' => (string) ($_POST['status'] ?? 'active')]);
            if (isset($_POST['verify_email']) && empty($member['emailVerifiedAt'])) {
                $member = $store->markMemberVerified($memberId);
            }
            $store->audit(app_auth()->id(), 'member.updated', ['memberId' => $memberId, 'status' => $member['status']]);
            flash('success', 'Member account updated.');
            redirect(base_url('admin/?page=members&member=' . rawurlencode($memberId)));
        }
        if ($action === 'assign_subscription') {
            $memberId = (string) ($_POST['member_id'] ?? '');
            $months = max(1, min(36, (int) ($_POST['months'] ?? 1)));
            $subscription = $store->saveSubscription([
                'memberId' => $memberId,
                'planId' => (string) ($_POST['plan_id'] ?? ''),
                'status' => (string) ($_POST['status'] ?? 'active'),
                'currentPeriodStart' => now_iso(),
                'currentPeriodEnd' => gmdate('c', strtotime('+' . $months . ' months')),
                'providerKey' => 'manual-admin',
            ]);
            $store->audit(app_auth()->id(), 'member.subscription.assigned', ['memberId' => $memberId, 'subscriptionId' => (string) $subscription['id']]);
            flash('success', 'Membership access assigned.');
            redirect(base_url('admin/?page=members&member=' . rawurlencode($memberId)));
        }
        if ($action === 'cancel_subscription') {
            $id = (string) ($_POST['subscription_id'] ?? '');
            $memberId = (string) ($_POST['member_id'] ?? '');
            foreach ($store->subscriptions($memberId) as $existing) {
                if ((string) ($existing['id'] ?? '') !== $id) continue;
                $store->saveSubscription(array_merge($existing, ['status' => 'cancelled', 'cancelledAt' => now_iso()]));
                break;
            }
            flash('success', 'Subscription cancelled.');
            redirect(base_url('admin/?page=members&member=' . rawurlencode($memberId)));
        }
        if ($action === 'grant_entitlement') {
            $memberId = (string) ($_POST['member_id'] ?? '');
            $contentType = (string) ($_POST['content_type'] ?? '');
            $contentId = (string) ($_POST['content_id'] ?? '');
            if (entitlement_resolver()->content($contentType, $contentId) === null) {
                throw new RuntimeException('Choose a valid content item.');
            }
            $expires = trim((string) ($_POST['expires_at'] ?? ''));
            $entitlement = $store->grantEntitlement($memberId, $contentType, $contentId, (string) ($_POST['source_type'] ?? 'complimentary'), 'admin', $expires !== '' ? date('c', strtotime($expires)) : null, ['note' => trim((string) ($_POST['note'] ?? ''))]);
            $store->audit(app_auth()->id(), 'member.entitlement.granted', ['memberId' => $memberId, 'entitlementId' => (string) $entitlement['id']]);
            flash('success', 'Content access granted.');
            redirect(base_url('admin/?page=entitlements&member=' . rawurlencode($memberId)));
        }
        if ($action === 'revoke_entitlement') {
            $memberId = (string) ($_POST['member_id'] ?? '');
            $store->revokeEntitlement($memberId, (string) ($_POST['entitlement_id'] ?? ''));
            flash('success', 'Content access revoked.');
            redirect(base_url('admin/?page=entitlements&member=' . rawurlencode($memberId)));
        }
        if ($action === 'save_content_access') {
            $contentType = (string) ($_POST['content_type'] ?? '');
            $contentId = (string) ($_POST['content_id'] ?? '');
            if (entitlement_resolver()->content($contentType, $contentId) === null) {
                throw new RuntimeException('Choose a valid content item.');
            }
            $store->setContentPlanRules($contentType, $contentId, array_values((array) ($_POST['plan_ids'] ?? [])));
            flash('success', 'Subscription access rules saved.');
            redirect(base_url('admin/?page=plans#content-access'));
        }
        if ($action === 'save_coupon') {
            $starts = trim((string) ($_POST['starts_at'] ?? ''));
            $ends = trim((string) ($_POST['ends_at'] ?? ''));
            $coupon = $store->saveCoupon([
                'id' => (string) ($_POST['id'] ?? ''), 'code' => (string) ($_POST['code'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''), 'discountType' => (string) ($_POST['discount_type'] ?? 'percent'),
                'value' => (float) ($_POST['value'] ?? 0), 'scope' => (string) ($_POST['scope'] ?? 'all'),
                'minimumSubtotal' => (float) ($_POST['minimum_subtotal'] ?? 0), 'maximumDiscount' => (string) ($_POST['maximum_discount'] ?? ''),
                'usageLimit' => (int) ($_POST['usage_limit'] ?? 0), 'perMemberLimit' => (int) ($_POST['per_member_limit'] ?? 0),
                'startsAt' => $starts !== '' ? date('c', strtotime($starts)) : null, 'endsAt' => $ends !== '' ? date('c', strtotime($ends)) : null,
                'isActive' => isset($_POST['is_active']),
            ]);
            $store->audit(app_auth()->id(), 'commerce.coupon.saved', ['couponId'=>(string)$coupon['id'],'code'=>$coupon['code']]);
            flash('success', 'Coupon ' . (string) $coupon['code'] . ' saved.');
            redirect(base_url('admin/?page=coupons&edit=' . rawurlencode((string) $coupon['id'])));
        }
        if ($action === 'save_bundle') {
            $items = [];
            foreach ((array) ($_POST['bundle_items'] ?? []) as $index => $value) {
                [$type, $itemId] = array_pad(explode(':', (string) $value, 2), 2, '');
                if (in_array($type, ['gallery','video'], true) && $itemId !== '') {
                    $items[] = ['itemType'=>$type,'itemId'=>$itemId,'sortOrder'=>($index + 1) * 10];
                }
            }
            $bundle = $store->saveBundle([
                'id'=>(string)($_POST['id']??''),'slug'=>(string)($_POST['slug']??''),'title'=>(string)($_POST['title']??''),
                'description'=>(string)($_POST['description']??''),'price'=>(float)($_POST['price']??0),
                'subscriberPrice'=>(string)($_POST['subscriber_price']??''),'currency'=>(string)($_POST['currency']??'USD'),
                'isActive'=>isset($_POST['is_active']),'sortOrder'=>(int)($_POST['sort_order']??100),'items'=>$items,
            ]);
            $store->audit(app_auth()->id(), 'commerce.bundle.saved', ['bundleId'=>(string)$bundle['id']]);
            flash('success', (string)$bundle['title'] . ' saved.');
            redirect(base_url('admin/?page=bundles&edit=' . rawurlencode((string)$bundle['id'])));
        }
        if ($action === 'save_commerce_settings') {
            $commerce = (array) $store->setting('commerce', []);
            $commerce['currency'] = strtoupper(substr((string)($_POST['currency']??'USD'),0,3));
            $commerce['tax_rate_percent'] = max(0, min(100, (float)($_POST['tax_rate_percent']??0)));
            $commerce['receipt_footer'] = trim((string)($_POST['receipt_footer']??''));
            $commerce['store_enabled'] = isset($_POST['store_enabled']);
            $store->setSetting('commerce', $commerce);
            $store->audit(app_auth()->id(), 'commerce.settings.updated');
            flash('success', 'Commerce settings saved.');
            redirect(base_url('admin/?page=payments'));
        }
        if ($action === 'save_payment_provider') {
            $key = (string)($_POST['provider_key']??'sandbox');
            $existing = $store->paymentProvider($key) ?? ['key'=>$key,'name'=>ucfirst($key),'supportsRecurring'=>false,'settings'=>[],'sortOrder'=>100];
            $settings = (array)($existing['settings']??[]);
            $secret = trim((string)($_POST['webhook_secret']??''));
            if ($secret !== '') $settings['webhookSecret'] = $secret;
            $provider = $store->savePaymentProvider(array_merge($existing, ['enabled'=>isset($_POST['enabled']),'settings'=>$settings]));
            $store->audit(app_auth()->id(), 'commerce.provider.updated', ['providerKey'=>$key,'enabled'=>$provider['enabled']]);
            flash('success', (string)$provider['name'] . ' settings saved.');
            redirect(base_url('admin/?page=payments'));
        }
        if ($action === 'refund_order') {
            $order = commerce_service()->refundOrder((string)($_POST['order_id']??''), trim((string)($_POST['reason']??'')));
            $store->audit(app_auth()->id(), 'commerce.order.refunded', ['orderId'=>(string)$order['id'],'orderNumber'=>$order['orderNumber']]);
            flash('success', 'Order ' . (string)$order['orderNumber'] . ' was refunded and its purchased access was revoked.');
            redirect(base_url('admin/?page=orders&order=' . rawurlencode((string)$order['id'])));
        }
        if ($action === 'archive_legacy_root_entries') {
            $result = $repairService->archiveLegacyRootEntries();
            $store->audit(app_auth()->id(), 'system.legacy_root.archived', $result);
            $message = count($result['moved']) . ' obsolete root entry/entries moved into private storage.';
            if ($result['failed'] !== []) $message .= ' ' . count($result['failed']) . ' could not be moved.';
            flash('success', $message);
            redirect(base_url('admin/?page=repair-backups'));
        }
        if ($action === 'archive_repair_residues') {
            $result = $repairService->archiveRootResidues();
            $store->audit(app_auth()->id(), 'system.repair_backups.archived', $result);
            $message = $result['moved'] . ' root repair backup(s) moved to private storage.';
            if ($result['failed'] !== []) $message .= ' ' . count($result['failed']) . ' could not be moved; review server permissions.';
            flash('success', $message);
            redirect(base_url('admin/?page=repair-backups'));
        }
        if ($action === 'merge_repair_backup') {
            $result = $repairService->mergeMissing((string) ($_POST['backup_id'] ?? ''));
            $store->audit(app_auth()->id(), 'system.repair_backup.merged', $result);
            flash('success', $result['copied'] . ' missing file(s) restored; ' . $result['skipped'] . ' existing file(s) left untouched.');
            redirect(base_url('admin/?page=repair-backups'));
        }
        if ($action === 'delete_repair_backup') {
            $repairService->delete((string) ($_POST['backup_id'] ?? ''), trim((string) ($_POST['confirmation'] ?? '')));
            $store->audit(app_auth()->id(), 'system.repair_backup.deleted');
            flash('success', 'Repair backup deleted.');
            redirect(base_url('admin/?page=repair-backups'));
        }
        if (in_array($action, ['save_album', 'save_track', 'save_audio_playlist', 'save_gallery', 'save_video'], true)) {
            $mediaAdmin = new MediaAdminService($store);
            $result = match ($action) {
                'save_album' => $mediaAdmin->saveAlbum($_POST, $_FILES),
                'save_track' => $mediaAdmin->saveTrack($_POST, $_FILES),
                'save_audio_playlist' => $mediaAdmin->saveAudioPlaylist($_POST),
                'save_gallery' => $mediaAdmin->saveGallery($_POST, $_FILES),
                'save_video' => $mediaAdmin->saveVideo($_POST, $_FILES),
            };
            $destination = match ($action) {
                'save_gallery' => 'galleries',
                'save_video' => 'videos',
                default => 'music',
            };
            $contentType = match ($action) {
                'save_gallery' => 'gallery',
                'save_video' => 'video',
                'save_track' => 'track',
                default => null,
            };
            if ($contentType !== null && isset($result['id'])) {
                $store->setContentPlanRules($contentType, (string) $result['id'], array_values((array) ($_POST['plan_ids'] ?? [])));
            }
            $store->audit(app_auth()->id(), 'media.' . str_replace('save_', '', $action) . '.saved', [
                'id' => (string) ($result['id'] ?? ''),
                'title' => (string) ($result['title'] ?? ''),
            ]);
            flash('success', (string) ($result['title'] ?? 'Media item') . ' saved.');
            redirect(base_url('admin/?page=' . $destination));
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$success = flash('success');
$pageTitles = [
    'dashboard' => 'Dashboard',
    'composer' => 'Visual Composer',
    'about' => 'About Me',
    'merchandise' => 'Merchandise',
    'marketing' => 'Marketing & Publishing',
    'music' => 'Music',
    'galleries' => 'Photo Galleries',
    'videos' => 'Videos',
    'members' => 'Members',
    'plans' => 'Membership Plans',
    'entitlements' => 'Entitlements',
    'commerce' => 'Commerce Dashboard',
    'orders' => 'Orders & Transactions',
    'coupons' => 'Coupons',
    'bundles' => 'PPV Bundles',
    'payments' => 'Payment Settings',
    'messages' => 'Private Messages',
    'content-requests' => 'Content Requests',
    'booking-requests' => 'Booking Requests',
    'booking-form' => 'Booking Form Builder',
    'communication-settings' => 'Communication Settings',
    'media' => 'Media Library',
    'canva' => 'Canva Templates',
    'addons' => 'Add-ons',
    'licenses' => 'Add-on Licensing',
    'settings' => 'Site Settings',
    'repair-backups' => 'Repair Backups',
    'database-updates' => 'Database Updates',
];
foreach (addon_runtime()->adminPages() as $addonPageKey => $addonPage) {
    $pageTitles[$addonPageKey] = (string) $addonPage['label'];
}
if (isset($pageTitles[$page])) $_SESSION['admin_last_pages'][(string) $user['id']] = $page;
$title = $pageTitles[$page] ?? 'Page not found';
$nav = [
    'dashboard' => ['⌂', 'Dashboard'],
    'composer' => ['✦', 'Visual Composer'],
    'about' => ['♡', 'About Me'],
    'merchandise' => ['▱', 'Merchandise'],
    'marketing' => ['↗', 'Marketing & Publishing'],
    'music' => ['♫', 'Music'],
    'galleries' => ['◇', 'Photo Galleries'],
    'videos' => ['▶', 'Videos'],
    'members' => ['♙', 'Members'],
    'plans' => ['♛', 'Membership Plans'],
    'entitlements' => ['◆', 'Entitlements'],
    'commerce' => ['$', 'Commerce'],
    'orders' => ['▤', 'Orders'],
    'coupons' => ['%', 'Coupons'],
    'bundles' => ['✦', 'Bundles'],
    'payments' => ['¤', 'Payments'],
    'messages' => ['✉', 'Messages'],
    'content-requests' => ['♡', 'Content Requests'],
    'booking-requests' => ['▦', 'Booking Requests'],
    'booking-form' => ['☷', 'Booking Form Builder'],
    'communication-settings' => ['⚑', 'Communication Settings'],
    'media' => ['▣', 'Media Library'],
    'canva' => ['C', 'Canva Templates'],
    'addons' => ['◇', 'Add-ons'],
    'licenses' => ['⌁', 'Add-on Licensing'],
    'settings' => ['⚙', 'Site Settings'],
    'repair-backups' => ['↺', 'Repair Backups'],
    'database-updates' => ['↑', 'Database Updates'],
];
foreach (addon_runtime()->adminPages() as $addonPageKey => $addonPage) {
    $nav[$addonPageKey] = [(string)$addonPage['icon'], (string)$addonPage['label']];
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?= e(csrf_token()) ?>"><title><?= e($title) ?> · Velvet Vixxen Admin</title><link rel="stylesheet" href="<?= e(asset_url('css/admin.css')) ?>"><?php if ($page === 'marketing'): ?><link rel="stylesheet" href="<?= e(asset_url('css/marketing.css')) ?>"><?php endif; ?><link rel="stylesheet" href="<?= e(asset_url('css/admin-ux.css')) ?>"><?php if ($page === 'composer'): ?><link rel="stylesheet" href="<?= e(asset_url('css/composer.css')) ?>"><?php endif; ?><link rel="icon" href="<?= e(base_url('images/defaults/brand-mark.png')) ?>" type="image/png"></head>
<body class="admin-body"><?php require root_path('app/Views/admin/navigation.php'); ?>
<div class="admin-shell"><header class="admin-topbar"><button class="sidebar-toggle" type="button" aria-label="Toggle menu" aria-controls="adminSidebar" aria-expanded="false">☰</button><div><div class="admin-kicker">Velvet Vixxen · v0.8.1</div><h1><?= e($title) ?></h1></div><div class="admin-user"><span class="avatar"><?= e(strtoupper(substr((string)($user['name']??'N'),0,1))) ?></span><div><strong><?= e($user['name']??'Owner') ?></strong><small><?= e($user['role']??'owner') ?></small></div></div></header><main class="admin-content"><?php if ($success): ?><div class="admin-alert success"><?= e($success) ?></div><?php endif; ?><?php if ($error): ?><div class="admin-alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($page === 'dashboard'):
$stats = $store->stats(); ?>
<section class="welcome-panel"><div><span class="admin-kicker">Approved visual direction</span><h2>The pink Velvet Vixxen build is active.</h2><p>The public site, one-page installer, Visual Composer, Media Center, member commerce, add-ons, private messaging, content requests, and booking intake are active.</p><div class="panel-actions"><a class="admin-button primary" href="<?= e(base_url('admin/?page=composer')) ?>">Open Visual Composer</a><a class="admin-button secondary" href="<?= e(base_url('admin/?page=canva')) ?>">Open Canva Templates</a></div></div><img src="<?= e(image_url('defaults/canva-hero-preview.jpg')) ?>" alt="Approved pink hero design"></section>
<div class="stat-grid"><article><span>Members</span><strong><?= e((string)$stats['members']) ?></strong><small><?= e((string)$stats['verifiedMembers']) ?> verified accounts</small></article><article><span>Unread Messages</span><strong><?= e((string)($stats['unreadThreads']??0)) ?></strong><small><?= e((string)($stats['openThreads']??0)) ?> open conversations</small></article><article><span>Open Requests</span><strong><?= e((string)($stats['openContentRequests']??0)) ?></strong><small>Private member ideas and quotes</small></article><article><span>Open Bookings</span><strong><?= e((string)($stats['openBookings']??0)) ?></strong><small>Professional inquiries awaiting action</small></article></div>
<div class="dashboard-grid"><section class="admin-card"><div class="card-head"><div><span class="admin-kicker">First build workflow</span><h2>Add your photos as separate layers</h2></div></div><ol class="workflow"><li><b>Upload</b><span>Add transparent PNG or WebP photos, with optional custom thumbnails.</span></li><li><b>Compose</b><span>Drag a thumbnail onto the Hero or Footer canvas, then resize and position it.</span></li><li><b>Preview</b><span>Check desktop, tablet, and mobile arrangements independently.</span></li><li><b>Publish</b><span>Create a restorable version and update the live homepage.</span></li></ol></section><section class="admin-card"><div class="card-head"><div><span class="admin-kicker">Canva bridge</span><h2>Editable design templates</h2></div></div><p class="muted-copy">Download a layered starter file, upload it to Canva, save the Canva edit link, and import the exported background. Add your transparent photos as separate Composer layers.</p><a class="admin-button secondary full" href="<?= e(base_url('admin/?page=canva')) ?>">Manage Canva Templates</a></section></div>
<?php elseif ($page === 'marketing'):
require root_path('app/Views/admin/marketing.php'); ?>
<?php elseif ($page === 'about'):
require root_path('app/Views/admin/about.php'); ?>
<?php elseif ($page === 'merchandise'):
require root_path('app/Views/admin/merchandise.php'); ?>
<?php elseif ($page === 'composer'):
require root_path('app/Views/admin/composer.php'); ?>
<?php elseif (in_array($page, ['music', 'galleries', 'videos'], true)):
require root_path('app/Views/admin/media-center.php'); ?>
<?php elseif (in_array($page, ['members', 'plans', 'entitlements'], true)):
require root_path('app/Views/admin/members.php'); ?>
<?php elseif (in_array($page, ['commerce','orders','coupons','bundles','payments'], true)):
require root_path('app/Views/admin/commerce.php'); ?>
<?php elseif ($page === 'booking-form'):
    if ((string) ($user['role'] ?? '') === 'owner') require root_path('app/Views/admin/booking-form.php');
    else echo '<section class="admin-card"><h2>Owner access required.</h2></section>';
?>
<?php elseif (in_array($page, ['messages','content-requests','booking-requests','communication-settings'], true)):
require root_path('app/Views/admin/communications.php'); ?>
<?php elseif ($page === 'addons'):
require root_path('app/Views/admin/addons.php'); ?>
<?php elseif ($page === 'licenses'):
require root_path('app/Views/admin/addon-licensing.php'); ?>
<?php elseif (($addonPageContent = addon_runtime()->renderAdminPage($page, ['user'=>$user])) !== null): ?>
<?= $addonPageContent ?>
<?php elseif ($page === 'media'):
require root_path('app/Views/admin/media-library.php'); ?>
<?php elseif ($page === 'canva'):
$templates = $store->templates(); ?>
<div class="admin-card canva-intro"><div><span class="admin-kicker">Canva-compatible workflow</span><h2>Editable template library</h2><p class="muted-copy">Use the layered starter kit to build a Canva design, save its edit link here, and import the finished export back into the Hero, Footer, gallery, or video system.</p></div><div class="canva-actions"><a class="admin-button primary" href="<?= e(base_url('templates/canva/Nikki-Wilder-Canva-Starter-Kit.zip')) ?>" download>Download Starter Kit</a><a class="admin-button secondary" href="https://www.canva.com/" target="_blank" rel="noopener">Open Canva ↗</a></div></div><div class="notice-panel"><strong>Manual export/import:</strong> “Edit in Canva” opens your saved design link; it does not synchronize automatically. Export backgrounds or decorative artwork from Canva and import them here. Keep transparent model photos as separate Visual Composer layers. Navigation, text, buttons, forms, paywalls, and media players stay live in the website.</div><div class="template-grid"><?php foreach ($templates as $template): ?><article class="template-card"><div class="template-preview"><img src="<?= e(scene_asset_url((string)($template['preview']??''))) ?>" alt="<?= e((string)$template['title']) ?>"></div><div class="template-body"><div class="template-meta"><span><?= e((string)$template['category']) ?></span><span><?= e((string)$template['device']) ?></span><span><?= e((string)$template['width']) ?>×<?= e((string)$template['height']) ?></span></div><h3><?= e((string)$template['title']) ?></h3><div class="template-links"><?php if (!empty($template['canvaUrl'])): ?><a class="admin-button primary" href="<?= e((string)$template['canvaUrl']) ?>" target="_blank" rel="noopener">Edit in Canva ↗</a><?php else: ?><span class="admin-button disabled">Add Canva link first</span><?php endif; ?><a class="admin-button secondary" href="<?= e(base_url((string)$template['starterFile'])) ?>" download>Download SVG</a></div><details><summary>Canva link &amp; import</summary><form method="post" class="compact-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_canva_url"><input type="hidden" name="template_id" value="<?= e((string)$template['id']) ?>"><label>Canva edit URL<input type="url" name="canva_url" value="<?= e((string)($template['canvaUrl']??'')) ?>" placeholder="https://www.canva.com/design/..."></label><button class="mini-button">Save Edit Link</button></form><form method="post" enctype="multipart/form-data" class="compact-form"><?= csrf_field() ?><input type="hidden" name="action" value="import_canva_export"><input type="hidden" name="template_id" value="<?= e((string)$template['id']) ?>"><label>Finished Canva export<input type="file" name="export_file" accept="image/png,image/jpeg,image/webp" required></label><label>Assign as draft background<select name="assign_section"><option value="">Do not assign</option><option value="hero">Homepage Hero</option><option value="footer">Visual Footer</option></select></label><button class="mini-button">Import Finished Design</button></form></details></div></article><?php endforeach; ?></div>
<?php elseif ($page === 'repair-backups'):
require root_path('app/Views/admin/repair-backups.php'); ?>
<?php elseif ($page === 'database-updates'):
require root_path('app/Views/admin/database-updates.php'); ?>
<?php elseif ($page === 'settings'):
$site = (array)$store->setting('site',[]); ?>
<form method="post" class="settings-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_site_settings"><section class="admin-card"><div class="card-head"><div><span class="admin-kicker">Brand</span><h2>Public site text</h2></div></div><label>Brand name<input name="brand_name" value="<?= e((string)($site['brand_name']??'Nikki Wilder')) ?>" required></label><label>Brand subtitle<input name="brand_subtitle" value="<?= e((string)($site['brand_subtitle']??'The Velvet Vixxen')) ?>" required></label><label>Hero tagline<input name="tagline" value="<?= e((string)($site['tagline']??'Bolder. Sexier. Closer.')) ?>" required></label><label>Hero supporting copy<textarea name="hero_copy" rows="4"><?= e((string)($site['hero_copy']??'')) ?></textarea></label></section><section class="admin-card"><div class="card-head"><div><span class="admin-kicker">Public behavior</span><h2>Homepage options</h2></div></div><label class="toggle-row"><input type="checkbox" name="age_gate_enabled" <?= !empty($site['age_gate_enabled'])?'checked':'' ?>><span><b>18+ entry confirmation</b><small>Show the age-confirmation dialog to new visitors.</small></span></label><label class="toggle-row"><input type="checkbox" name="newsletter_enabled" <?= !empty($site['newsletter_enabled'])?'checked':'' ?>><span><b>Newsletter form</b><small>Display the public email subscription panel.</small></span></label><div class="settings-note"><strong>Canva API status:</strong><span>Manual template-link and export import workflow is active. OAuth synchronization remains a future integration milestone because it requires your Canva developer credentials.</span></div><button class="admin-button primary full">Save Settings</button></section></form>
<?php else: ?><div class="admin-card"><h2>Page not found</h2></div><?php endif; ?>
</main></div><script src="<?= e(asset_url('js/admin.js')) ?>" defer></script><?php if ($page === 'composer'): ?><script src="<?= e(asset_url('js/composer.js')) ?>" defer></script><?php endif; ?><?php if (in_array($page, ['music','galleries','videos'], true)): ?><script src="<?= e(asset_url('js/admin-media.js')) ?>" defer></script><?php endif; ?><script src="<?= e(asset_url('js/admin-ux.js')) ?>" defer></script></body></html>
