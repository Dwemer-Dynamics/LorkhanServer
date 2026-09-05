<?php

declare(strict_types=1);

$pageTitle='Game Debug';
$topNavSection='control';
$BODY_CLASS='configuration-resource view-game-debug';
require __DIR__.'/ui_bootstrap.php';
$sessions=$productRepository->debugCommandSessions();
$additionalStylesheets=['herika-control-pages.css?v='.(string)filemtime(__DIR__.'/css/herika-control-pages.css'),
    'game-debug.css?v='.(string)filemtime(__DIR__.'/css/game-debug.css')];
include __DIR__.'/tmpl/head.html';
if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="lorkhan-page control-specialist-page game-debug-page<?php echo $embedded?' embedded':''; ?>">
    <header class="lorkhan-page-header">
        <div><h1>Game Debug</h1><p>Run fixed OpenMW diagnostics in the connected game. Commands expire after 30 seconds and are never exposed to AI prompts or actions.</p></div>
        <span class="lorkhan-state-pill is-neutral" data-debug-connection>Checking game</span>
    </header>
    <div data-game-debug data-api-base="<?php echo lorkhan_ui_h($managementBasePath.'/api/v1'); ?>" data-csrf="<?php echo lorkhan_ui_h($csrf); ?>">
        <section class="widget widget-wide debug-session-card">
            <div class="widget-header"><h3>Connected game</h3></div>
            <div class="widget-content debug-session-row">
                <label for="debug-session">Session</label>
                <select id="debug-session" data-debug-session>
                    <?php if($sessions===[]): ?><option value="">No active LORKHAN game</option><?php endif; ?>
                    <?php foreach($sessions as$row): ?><option value="<?php echo lorkhan_ui_h($row['session_id']); ?>" data-supported="<?php echo $row['supported']?'true':'false'; ?>"><?php echo lorkhan_ui_h($row['label'].' · generation '.$row['generation']); ?></option><?php endforeach; ?>
                </select>
                <button type="button" class="btn-base" data-debug-refresh>Refresh state</button>
            </div>
            <p class="debug-feedback" data-debug-feedback role="status" aria-live="polite"></p>
        </section>

        <section class="debug-command-grid" aria-label="Debug commands">
            <article class="widget debug-command-card">
                <div class="widget-header"><h3>Runtime switches</h3></div>
                <div class="widget-content debug-switch-list">
                    <?php foreach([['god_mode.set','God Mode'],['collision.set','Collision'],['ai.set','AI'],['mwscript.set','MWScript'],['shader_hot_reload.set','Shader hot reload']] as[$command,$label]): ?>
                    <div class="debug-switch-row"><span><?php echo lorkhan_ui_h($label); ?></span><div class="debug-button-pair"><button type="button" class="btn-base" data-debug-command="<?php echo lorkhan_ui_h($command); ?>" data-enabled="true">On</button><button type="button" class="btn-base" data-debug-command="<?php echo lorkhan_ui_h($command); ?>" data-enabled="false">Off</button></div></div>
                    <?php endforeach; ?>
                </div>
            </article>
            <article class="widget debug-command-card">
                <div class="widget-header"><h3>Render overlays</h3></div>
                <div class="widget-content debug-button-grid">
                    <?php foreach(['collision'=>'Collision','wireframe'=>'Wireframe','pathgrid'=>'Pathgrid','water'=>'Water','scene'=>'Scene','navmesh'=>'NavMesh','actors_paths'=>'Actor paths','recast_mesh'=>'Recast mesh'] as$mode=>$label): ?>
                    <button type="button" class="btn-base" data-debug-command="render_mode.toggle" data-mode="<?php echo lorkhan_ui_h($mode); ?>"><?php echo lorkhan_ui_h($label); ?></button>
                    <?php endforeach; ?>
                </div>
            </article>
            <article class="widget debug-command-card">
                <div class="widget-header"><h3>Reload</h3></div>
                <div class="widget-content"><button type="button" class="btn-base btn-primary" data-debug-command="shaders.reload">Reload shaders</button><p class="debug-help">Lua reload is intentionally excluded because it destroys the script reporting completion.</p></div>
            </article>
        </section>

        <section class="widget widget-wide debug-history-card">
            <div class="widget-header"><h3>Recent commands</h3><span data-debug-count>0 commands</span></div>
            <div class="widget-content table-container">
                <table><thead><tr><th>Command</th><th>Parameters</th><th>Status</th><th>Result</th><th>Created</th></tr></thead><tbody data-debug-history><tr><td colspan="5">No debug commands have been sent.</td></tr></tbody></table>
            </div>
        </section>
    </div>
</main>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/game-debug.js?v=<?php echo lorkhan_ui_h((string)filemtime(__DIR__.'/js/game-debug.js')); ?>"></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
