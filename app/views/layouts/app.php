<?php
/**
 * Base application layout.
 *
 * @var string $content Rendered view output.
 * @var string|null $title
 * @var string|null $bodyClass
 * @var string|null $headExtra Extra head markup (page-specific CSS/CDN).
 * @var string|null $scripts Extra footer scripts (page-specific JS).
 */
$appName = (string) config('app.name', 'ResQLink');
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . $appName : $appName;
$bodyClass = $bodyClass ?? '';
$headExtra = (string) ($headExtra ?? '');
$scripts = (string) ($scripts ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="theme-color" content="#0b1220" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#070b14" media="(prefers-color-scheme: dark)">
    <meta name="description" content="ResQLink — coordinate emergency volunteers, organizations and resources.">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='22' fill='%23ef4444'/><text x='50' y='68' font-size='58' text-anchor='middle' fill='white' font-family='system-ui,sans-serif' font-weight='700'>Q</text></svg>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?= $headExtra ?>
</head>
<body class="app-shell <?= e($bodyClass) ?>">
    <a class="skip-link" href="#main">Skip to content</a>

    <?php require view_path('components/navbar.php'); ?>

    <main id="main" class="app-main" role="main">
        <div class="container">
            <?php require view_path('components/flash.php'); ?>
            <?= $content ?>
        </div>
    </main>

    <footer class="app-footer">
        <div class="container app-footer__inner">
            <span>&copy; <?= date('Y') ?> <?= e($appName) ?> — Emergency coordination platform.</span>
            <span class="muted">Coordination only · Not a replacement for emergency services (112 / 108).</span>
        </div>
    </footer>

    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
    <script src="<?= e(asset('js/notifications.js')) ?>" defer></script>
    <?= $scripts ?>
</body>
</html>
