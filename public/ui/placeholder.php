<?php

declare(strict_types=1);

$featureId = isset($_GET['feature']) ? preg_replace('/[^a-z0-9.-]+/', '', strtolower((string) $_GET['feature'])) : '';
$pageTitle = 'ALMSIVI';
$topNavSection = str_starts_with($featureId, 'roleplay.') ? 'roleplay' : (str_starts_with($featureId, 'control.') ? 'control' : 'configuration');
$BODY_CLASS = 'placeholder-page';
require __DIR__ . '/ui_bootstrap.php';
$feature = almsivi_ui_feature($featureId);
$pageTitle = (string) $feature['title'];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<link rel="stylesheet" href="<?php echo almsivi_ui_h($webRoot); ?>/ui/css/main.css">
<main class="container-fluid feature-placeholder-page">
    <section class="page-header">
        <h1 class="api-title"><?php echo almsivi_ui_h($feature['title']); ?></h1>
        <p class="page-subtitle"><?php echo almsivi_ui_h($feature['description']); ?></p>
    </section>
    <section class="feature-placeholder-panel" aria-labelledby="feature-placeholder-heading">
        <div class="feature-placeholder-heading">
            <h2 id="feature-placeholder-heading"><?php echo almsivi_ui_h($feature['title']); ?></h2>
            <?php echo almsivi_ui_feature_badge($featureId); ?>
        </div>
        <p>The HerikaServer presentation is retained so this control remains in its expected location. It performs no action in ALMSIVI.</p>
        <?php if ($feature['controls'] !== []): ?>
        <div class="feature-placeholder-controls">
            <?php foreach ($feature['controls'] as $control): echo almsivi_ui_placeholder_control((string) $control, $featureId); endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
</main>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
