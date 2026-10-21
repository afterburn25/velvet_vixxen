<?php
declare(strict_types=1);
$brandState = $marketingStore->emailBrand();
$brandForm = $emailBrandForm ?? ($brandState['template'] + ['brand_revision' => $brandState['revision']]);
?>
<section class="marketing-card" style="margin-top:22px" id="email-brand">
  <h2>Email brand</h2>
  <p>A reusable design for branded campaign messages. Queuing saves a copy of this template; changes here apply to campaigns queued later. HTML email always includes a plain-text alternative.</p>
  <form class="marketing-form" method="post" data-email-brand-form>
    <?= csrf_field() ?><input type="hidden" name="action" value="save_email_brand">
    <input type="hidden" name="brand_revision" value="<?= e(mm_form_text($brandForm['brand_revision'] ?? '', 64)) ?>">
    <label>Brand name<input name="brand_name" maxlength="120" required value="<?= e(mm_form_text($brandForm['brand_name'] ?? '', 480)) ?>"></label>
    <label>Tagline<input name="tagline" maxlength="200" value="<?= e(mm_form_text($brandForm['tagline'] ?? '', 800)) ?>"></label>
    <label>Accent color<input name="accent" pattern="#[0-9a-fA-F]{6}" maxlength="7" required value="<?= e(mm_form_text($brandForm['accent'] ?? '', 7)) ?>"></label>
    <label>Footer text<input name="footer" maxlength="1000" value="<?= e(mm_form_text($brandForm['footer'] ?? '', 4000)) ?>"></label>
    <button class="marketing-button">Save Email Brand</button>
  </form>
</section>
