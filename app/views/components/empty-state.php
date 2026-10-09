<?php
/**
 * Empty-state component.
 *
 * Reusable for lists/tables that have no data yet. Pass:
 *   $icon (string, optional), $title (string), $message (string), $action (string HTML, optional)
 */
$icon = $icon ?? '📭';
$title = $title ?? 'Nothing here yet';
$message = $message ?? 'There is no data to display at the moment.';
$action = $action ?? null;
?>
<div class="empty-state" role="status">
    <div class="empty-state__icon" aria-hidden="true"><?= e($icon) ?></div>
    <h3 class="empty-state__title"><?= e($title) ?></h3>
    <p class="empty-state__message"><?= e($message) ?></p>
    <?php if ($action !== null): ?>
        <div class="empty-state__action"><?= $action /* trusted markup built by the caller */ ?></div>
    <?php endif; ?>
</div>
