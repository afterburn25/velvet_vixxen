<?php
declare(strict_types=1);

vvx_marketing_recovery_require_owner();
$action = $_POST['action'] ?? null;
if (!in_array($action, ['editorial_deleted_restore', 'editorial_deleted_purge'], true)) {
    throw new InvalidArgumentException('Choose Restore or Permanently remove from the saved item review.');
}
$revision = $_POST['recovery_revision'] ?? null;
if (!is_string($revision)) throw new InvalidArgumentException('Reload the saved review before continuing.');
$collection = $recoveryRequest['collection'];
$id = $recoveryRequest['id'];
if ($action === 'editorial_deleted_purge' && ($_POST['confirm_permanent'] ?? null) !== '1') {
    throw new InvalidArgumentException('Confirm that this item and its saved history will be permanently removed.');
}
$recoveryStore = velvet_marketing_store();
if ($action === 'editorial_deleted_restore') {
    $restored = $recoveryStore->restoreDeletedPublication($collection, $id, $revision,
        static fn(array $record) => mm_publication_authorize($record, false));
    $meta = mm_publication_meta($collection);
    flash('success', $meta['name'] . ' restored ' . ($collection === 'posts' ? 'as a draft.' : 'disabled.')
        . ' Review the content, images, related updates, dates and audience before publishing.');
    redirect(base_url('admin/?page=marketing&tab=' . $meta['tab'] . '&' . $meta['edit'] . '=' . rawurlencode($restored['id'])));
}
$recoveryStore->purgeDeletedPublication($collection, $id, $revision,
    static fn(array $record) => mm_publication_authorize($record, false));
flash('success', 'The deleted item and its saved history were permanently removed.');
redirect(vvx_marketing_recovery_url($collection));
