<?php
/**
 * Flash message component.
 *
 * Reads and clears one-time session messages. Types: success, error, warning,
 * info. Uses role="status" + aria-live so assistive tech announces them.
 */
$types = ['success', 'error', 'warning', 'info'];
$messages = [];

foreach ($types as $type) {
    $message = pull_flash($type);

    if (is_string($message) && $message !== '') {
        $messages[] = ['type' => $type, 'text' => $message];
    }
}
?>
<?php if ($messages !== []): ?>
    <div class="flash-stack" role="status" aria-live="polite">
        <?php foreach ($messages as $item): ?>
            <div class="flash flash--<?= e($item['type']) ?>" data-flash>
                <span class="flash__icon" aria-hidden="true">
                    <?php
                    echo match ($item['type']) {
                        'success' => '✓',
                        'error'   => '✕',
                        'warning' => '!',
                        default   => 'i',
                    };
                    ?>
                </span>
                <p class="flash__text"><?= e($item['text']) ?></p>
                <button type="button" class="flash__close" aria-label="Dismiss notification" data-flash-close>&times;</button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
