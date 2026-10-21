<?php
declare(strict_types=1);
$publicationMeta = mm_publication_meta($publicationCollection);
$publicationRows = $marketingStore->publicationRecords($publicationCollection);
$shownBanner = false;
$publicationListCatalog = mm_publication_access_catalog();
?>
<section class="marketing-card" data-publication-list="<?= velvet_marketing_escape($publicationCollection) ?>">
  <h2><?= velvet_marketing_escape($publicationMeta['plural']) ?></h2>
  <?php if ($publicationCollection === 'banners'): ?><p>The first active banner each visitor is allowed to see is shown in this saved order. Other eligible banners wait behind it. Guest visibility is shown below; members may see an earlier restricted banner. Editing keeps this order.</p><?php endif; ?>
  <?php if ($publicationRows === []): ?><p>No saved <?= $publicationCollection === 'landing_pages' ? 'landing pages' : $publicationCollection ?> yet.</p><?php endif; ?>
  <table class="marketing-table marketing-publication-table"><tbody>
  <?php foreach ($publicationRows as $publicationRow):
    $publicationId = mm_form_text($publicationRow['id'] ?? '');
    $publicationEnabled = \Velvet\Core\MarketingPublication::enabled($publicationCollection, $publicationRow);
    $publicationState = $publicationRow['visibility']['state'];
    $publicationRowAccess = \Velvet\Core\MarketingAccess::describe($publicationRow, $publicationListCatalog['available_plan_ids']);
    if ($publicationCollection === 'banners' && $publicationRow['visibility']['visible']) {
        if (!$publicationRowAccess['valid']) $publicationState = 'Access needs review';
        elseif ($publicationRowAccess['mode'] === 'public') {
            $publicationState = $shownBanner ? 'Active · waiting behind the shown banner for guests' : 'Active · shown now to guests';
            $shownBanner = true;
        } else $publicationState = 'Active · available to eligible members in this order';
    }
    $publicationTitle = mm_form_text($publicationRow[$publicationCollection === 'banners' ? 'text' : 'title'] ?? 'Untitled publication');
    $publicationUrl = $publicationCollection === 'banners' ? '' : base_url(($publicationCollection === 'posts' ? 'blog/post.php?slug=' : 'campaign/?slug=') . rawurlencode(mm_form_text($publicationRow['slug'] ?? '')));
    try { $publicationPreviewUrl = mm_publication_preview_url($publicationCollection, $publicationId); }
    catch (InvalidArgumentException) { $publicationPreviewUrl = ''; }
  ?>
    <tr data-publication-id="<?= velvet_marketing_escape($publicationId) ?>">
      <td><strong><?= velvet_marketing_escape($publicationTitle) ?></strong>
        <p data-publication-state><?= velvet_marketing_escape($publicationState) ?></p>
        <p data-publication-access><?= velvet_marketing_escape(mm_publication_access_summary($publicationRowAccess, $publicationListCatalog)) ?></p>
        <?php if (!$publicationRowAccess['valid']): ?><p data-editorial-access-problem><?= velvet_marketing_escape($publicationRowAccess['problem'] ?? 'Access needs review.') ?> Visibility stays restricted until repaired.</p><?php endif; ?>
        <?php if ($publicationRow['visibility']['problem'] !== null): ?><p><?= velvet_marketing_escape($publicationRow['visibility']['problem']) ?> Public visibility is held until repaired.</p><?php endif; ?>
        <?php foreach ($publicationCollection === 'posts' ? ['publish_at' => 'Publish', 'expires_at' => 'Expires'] : ['starts_at' => 'Start', 'ends_at' => 'End'] as $dateKey => $dateLabel):
          try { $dateValue = \Velvet\Core\MarketingPublication::dateForForm($publicationRow[$dateKey] ?? null); }
          catch (InvalidArgumentException) { $dateValue = 'Needs review'; }
        ?><small><?= $dateLabel ?>: <?= velvet_marketing_escape($dateValue === '' ? ($dateKey === 'expires_at' ? 'No expiry' : 'Open') : str_replace('T', ' ', $dateValue)) ?></small><br><?php endforeach; ?>
      </td>
      <td>
        <div class="marketing-publication-actions">
          <a href="?page=marketing&amp;tab=<?= $publicationMeta['tab'] ?>&amp;<?= $publicationMeta['edit'] ?>=<?= rawurlencode($publicationId) ?>">Edit</a>
          <?php if ($publicationUrl !== ''): ?><a href="<?= velvet_marketing_escape($publicationUrl) ?>">View</a><?php endif; ?>
          <?php if ($publicationPreviewUrl !== ''): ?><a data-publication-preview href="<?= velvet_marketing_escape($publicationPreviewUrl) ?>" target="_blank" rel="noopener noreferrer">Preview Saved</a><?php endif; ?>
          <?php if ($publicationPreviewUrl !== ''): ?><a data-publication-history href="<?= velvet_marketing_escape(mm_publication_history_url($publicationCollection, $publicationId)) ?>">History</a><?php endif; ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_publication_enabled"><input type="hidden" name="collection" value="<?= $publicationCollection ?>"><input type="hidden" name="id" value="<?= velvet_marketing_escape($publicationId) ?>"><input type="hidden" name="publication_revision" value="<?= $publicationRow['revision'] ?>"><input type="hidden" name="enabled" value="<?= $publicationEnabled ? '0' : '1' ?>"><button class="marketing-button"><?= $publicationCollection === 'posts' ? ($publicationEnabled ? 'Move to draft' : 'Publish') : ($publicationEnabled ? 'Disable' : 'Enable') ?></button></form>
          <?php if ($publicationCollection === 'posts'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_publication"><input type="hidden" name="collection" value="posts"><input type="hidden" name="id" value="<?= velvet_marketing_escape($publicationId) ?>"><input type="hidden" name="publication_revision" value="<?= $publicationRow['revision'] ?>"><button class="marketing-button">Delete</button></form><?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php if ($publicationRows !== []): ?><p>Times shown in <?= velvet_marketing_escape(date_default_timezone_get()) ?>. Visibility updates on the next public request.</p><?php endif; ?>
  <?php if ($publicationCollection === 'posts' && $publicationRows !== []): ?><p class="marketing-field-help">Deleting a post permanently removes that post and its saved history. Move it to draft to keep its content and versions.</p><?php endif; ?>
</section>
