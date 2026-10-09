<?php
/**
 * Loading indicator component.
 *
 * Pass $label (string) for a caption. Used for initial page loads and
 * client-side fetch states.
 */
$label = $label ?? 'Loading…';
?>
<div class="loading" data-loading role="status" aria-live="polite">
    <span class="spinner" aria-hidden="true"></span>
    <span class="loading__label"><?= e($label) ?></span>
</div>
