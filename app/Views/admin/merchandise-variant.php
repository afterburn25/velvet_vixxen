<?php declare(strict_types=1); ?>
<fieldset class="merchandise-variant-row" data-variant-row>
  <legend>Product option</legend><input type="hidden" name="variants[<?= e((string) $variantIndex) ?>][id]" value="<?= e((string) ($variant['id'] ?? '')) ?>">
  <div class="control-grid">
    <label>Option label<input name="variants[<?= e((string) $variantIndex) ?>][label]" value="<?= e((string) ($variant['label'] ?? 'Standard')) ?>" maxlength="190" required placeholder="Rose / Medium"></label>
    <label>SKU<input name="variants[<?= e((string) $variantIndex) ?>][sku]" value="<?= e((string) ($variant['sku'] ?? '')) ?>" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9._\-]*" required placeholder="VELVET-ROSE-M"></label>
    <label>Option price (optional)<input type="number" name="variants[<?= e((string) $variantIndex) ?>][price]" min="0" max="99999999.99" step=".01" value="<?= e((string) ($variant['price'] ?? '')) ?>" placeholder="Use product price"></label>
    <label>Available stock<input type="number" name="variants[<?= e((string) $variantIndex) ?>][stock]" min="0" max="1000000" value="<?= e((string) ($variant['stock'] ?? 0)) ?>" required></label>
  </div>
  <?php $optionText = (string) ($variant['option_text'] ?? implode('; ', array_map(static fn(string $name, mixed $value): string => $name . ': ' . $value, array_keys((array) ($variant['options'] ?? [])), array_values((array) ($variant['options'] ?? []))))); ?>
  <label>Size / color / other options<input name="variants[<?= e((string) $variantIndex) ?>][option_text]" value="<?= e($optionText) ?>" placeholder="Size: M; Color: Rose"><small>Separate options with semicolons. Each option uses a name and value.</small></label>
  <div class="merchandise-variant-actions"><label class="toggle-row"><input type="checkbox" name="variants[<?= e((string) $variantIndex) ?>][enabled]" value="1" <?= !empty($variant['isEnabled']) || !empty($variant['enabled']) ? 'checked' : '' ?>><span>Option enabled</span></label><button type="button" class="mini-button danger" data-remove-variant>Remove option</button></div>
</fieldset>
