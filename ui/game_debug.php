<?php

declare(strict_types=1);

$pageTitle='Game Debug';
$topNavSection='control';
$BODY_CLASS='hub-page view-game-debug';
require __DIR__.'/ui_bootstrap.php';
$sessions=$productRepository->debugCommandSessions();
$additionalStylesheets=['control-reader.css?v='.(string)filemtime(__DIR__.'/css/control-reader.css'),
    'request-logs.css?v='.(string)filemtime(__DIR__.'/css/request-logs.css'),
    'game-debug.css?v='.(string)filemtime(__DIR__.'/css/game-debug.css')];
include __DIR__.'/tmpl/head.html';
if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="request-log-page game-debug-page"><div class="tab-content">
    <header class="debug-page-header"><h1 id="page-title" class="page-title">Game Debug</h1>
        <span class="status-pill status-unknown" data-debug-connection role="status">Checking game</span>
    </header>
    <details class="debug-notes"><summary>About game commands</summary><p>Run fixed OpenMW diagnostics in the connected game. Commands expire after 30 seconds and are never exposed to AI prompts or actions. Refresh state queues a read-only game snapshot; every other button changes the running game.</p></details>
    <div data-game-debug data-api-base="<?php echo lorkhan_ui_h($managementBasePath.'/api/v1'); ?>" data-csrf="<?php echo lorkhan_ui_h($csrf); ?>">
        <section class="debug-session-card" aria-label="Connected game">
            <div class="debug-session-row">
                <label for="debug-session">Session</label>
                <select id="debug-session" data-debug-session>
                    <?php if($sessions===[]): ?><option value="">No active LORKHAN game</option><?php endif; ?>
                    <?php foreach($sessions as$row): ?><option value="<?php echo lorkhan_ui_h($row['session_id']); ?>" data-supported="<?php echo $row['supported']?'true':'false'; ?>"><?php echo lorkhan_ui_h($row['label'].' · generation '.$row['generation']); ?></option><?php endforeach; ?>
                </select>
                <button type="button" class="btn-base btn-secondary" data-debug-refresh>Refresh state</button>
            </div>
            <p class="debug-feedback" data-debug-feedback role="status" aria-live="polite"></p>
        </section>

        <section class="debug-command-grid" aria-label="Debug commands">
            <section class="debug-command-card" aria-labelledby="debug-switches-title">
                <h2 id="debug-switches-title">Runtime switches</h2>
                <div class="debug-switch-list">
                    <?php foreach([['god_mode.set','God Mode'],['collision.set','Collision'],['ai.set','AI'],['mwscript.set','MWScript'],['shader_hot_reload.set','Shader hot reload']] as[$command,$label]): ?>
                    <div class="debug-switch-row"><span><?php echo lorkhan_ui_h($label); ?></span><div class="debug-button-pair"><button type="button" class="btn-base btn-secondary" aria-label="<?php echo lorkhan_ui_h($label); ?> on" data-debug-command="<?php echo lorkhan_ui_h($command); ?>" data-enabled="true">On</button><button type="button" class="btn-base btn-secondary" aria-label="<?php echo lorkhan_ui_h($label); ?> off" data-debug-command="<?php echo lorkhan_ui_h($command); ?>" data-enabled="false">Off</button></div></div>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="debug-command-card" aria-labelledby="debug-render-title">
                <h2 id="debug-render-title">Render overlays</h2>
                <div class="debug-button-grid">
                    <?php foreach(['collision'=>'Collision','wireframe'=>'Wireframe','pathgrid'=>'Pathgrid','water'=>'Water','scene'=>'Scene','navmesh'=>'NavMesh','actors_paths'=>'Actor paths','recast_mesh'=>'Recast mesh'] as$mode=>$label): ?>
                    <button type="button" class="btn-base btn-secondary" data-debug-command="render_mode.toggle" data-mode="<?php echo lorkhan_ui_h($mode); ?>"><?php echo lorkhan_ui_h($label); ?></button>
                    <?php endforeach; ?>
                </div>
                <h2 class="debug-reload-title">Reload</h2>
                <button type="button" class="btn-base btn-secondary" data-debug-command="shaders.reload">Reload shaders</button>
                <details class="debug-notes"><summary>Why no Lua reload?</summary><p>Lua reload destroys the script reporting completion, so it is excluded from this page.</p></details>
            </section>
        </section>

        <section class="debug-history-card" aria-labelledby="debug-history-title">
            <div class="debug-history-heading"><h2 id="debug-history-title">Recent commands</h2><span class="meta-line" data-debug-count>0 commands</span></div>
            <div class="table-container" role="region" aria-label="Recent commands" tabindex="0">
                <div class="empty-state" data-debug-empty>No debug commands have been sent.</div>
                <table data-debug-table hidden><thead><tr><th scope="col">Command</th><th scope="col">Parameters</th><th scope="col">Status</th><th scope="col">Result</th><th scope="col">Created (UTC)</th></tr></thead><tbody data-debug-history></tbody></table>
            </div>
        </section>
    </div>
</div></main>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/game-debug.js?v=<?php echo lorkhan_ui_h((string)filemtime(__DIR__.'/js/game-debug.js')); ?>"></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
