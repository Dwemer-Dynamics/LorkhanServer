<?php
declare(strict_types=1);

use ALMSIVIserver\Application\EffectiveSettingsResolver;

$pageTitle='Global Settings';$topNavSection='configuration';$BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';
$installations=$uiRepository->rows('installations');
$requested=trim((string)($_GET['installation_id']??''));$installationId='';
foreach($installations as$row)if($requested!==''&&hash_equals((string)$row['installation_id'],$requested))$installationId=$requested;
if($installationId===''&&isset($installations[0]))$installationId=(string)$installations[0]['installation_id'];
$stored=$installationId===''?null:$productRepository->globalSettingsForInstallation($installationId);
$settings=is_array($stored['content']??null)?$stored['content']:EffectiveSettingsResolver::defaults();
$embedded=isset($_GET['embed'])&&$_GET['embed']==='1';
$check=static fn(bool$value):string=>$value?' checked':'';
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="almsivi-page<?php echo $embedded?' embedded':''; ?>">
    <header class="almsivi-page-header">
        <div><h1>Global Settings</h1><p>Installation defaults inherited by every Core Profile and NPC unless a lower layer explicitly overrides them.</p></div>
        <div class="almsivi-badges"><span class="almsivi-badge default">Global layer</span><span class="almsivi-badge">Revision <?php echo almsivi_ui_h($stored['current_revision']??'default'); ?></span></div>
    </header>
    <?php if(isset($_GET['status'])): ?><div class="almsivi-status">Global settings saved as a new revision.</div><?php endif; ?>
    <?php if($installations===[]): ?><section class="almsivi-card almsivi-empty">Connect OpenMW once before configuring installation settings.</section><?php else: ?>
    <div class="almsivi-toolbar"><label>Installation<select data-installation-select><?php foreach($installations as$row): ?><option value="<?php echo almsivi_ui_h($row['installation_id']); ?>"<?php echo $row['installation_id']===$installationId?' selected':''; ?>><?php echo almsivi_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label></div>
    <div class="almsivi-inheritance"><span class="active">1. Global defaults</span><span>2. Core Profile overrides</span><span>3. NPC overrides</span></div>
    <form class="almsivi-editor" method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/global-settings-save">
        <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
        <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>">
        <div class="p-3">
            <div class="almsivi-form-grid">
                <fieldset class="almsivi-group"><legend>Conversation behavior</legend>
                    <label><span><input type="checkbox" name="auto_greeting" value="1"<?php echo $check($settings['behavior']['auto_greeting']); ?>> Automatic greeting</span></label>
                    <label><span><input type="checkbox" name="rechat" value="1"<?php echo $check($settings['behavior']['rechat']); ?>> Rechat</span></label>
                    <label>Rechat delay (seconds)<input type="number" min="30" max="3600" name="rechat_delay_seconds" value="<?php echo almsivi_ui_h($settings['behavior']['rechat_delay_seconds']); ?>"></label>
                    <label>Maximum rechat depth<input type="number" min="1" max="20" name="rechat_max_depth" value="<?php echo almsivi_ui_h($settings['behavior']['rechat_max_depth']); ?>"></label>
                    <label><span><input type="checkbox" name="boredom" value="1"<?php echo $check($settings['behavior']['boredom']); ?>> Boredom events</span></label>
                    <label>Boredom delay (seconds)<input type="number" min="30" max="86400" name="boredom_delay_seconds" value="<?php echo almsivi_ui_h($settings['behavior']['boredom_delay_seconds']); ?>"></label>
                    <label><span><input type="checkbox" name="combat_barks" value="1"<?php echo $check($settings['behavior']['combat_barks']); ?>> Combat barks</span></label>
                    <label>Combat bark period<input type="number" min="5" max="300" name="combat_bark_period_seconds" value="<?php echo almsivi_ui_h($settings['behavior']['combat_bark_period_seconds']); ?>"></label>
                </fieldset>
                <fieldset class="almsivi-group"><legend>Memory</legend>
                    <label>Recent turns<input type="number" min="1" max="100" name="recent_turn_limit" value="<?php echo almsivi_ui_h($settings['memory']['recent_turn_limit']); ?>"></label>
                    <label>Knowledge results<input type="number" min="0" max="20" name="knowledge_limit" value="<?php echo almsivi_ui_h($settings['memory']['knowledge_limit']); ?>"></label>
                </fieldset>
                <fieldset class="almsivi-group"><legend>Narration</legend>
                    <label><span><input type="checkbox" name="narrator_enabled" value="1"<?php echo $check($settings['narrator']['enabled']); ?>> Enable narrator</span></label>
                    <label>Narrator name<input name="narrator_name" maxlength="128" value="<?php echo almsivi_ui_h($settings['narrator']['name']); ?>"></label>
                    <label>Inline mode<select name="narrator_inline_mode"><?php foreach(['Disabled','Narrator','NPC','Text Only']as$mode): ?><option<?php echo $settings['narrator']['inline_mode']===$mode?' selected':''; ?>><?php echo almsivi_ui_h($mode); ?></option><?php endforeach; ?></select></label>
                    <?php foreach(['context_visibility'=>'Include narrator context','welcome_events'=>'Welcome events','random_events'=>'Random events','quest_events'=>'Quest events','book_events'=>'Book events']as$field=>$label): ?><label><span><input type="checkbox" name="narrator_<?php echo almsivi_ui_h($field); ?>" value="1"<?php echo $check($settings['narrator'][$field]); ?>> <?php echo almsivi_ui_h($label); ?></span></label><?php endforeach; ?>
                </fieldset>
                <fieldset class="almsivi-group"><legend>Presentation</legend>
                    <label><span><input type="checkbox" name="show_status_hud" value="1"<?php echo $check($settings['presentation']['show_status_hud']); ?>> Show status HUD</span></label>
                    <label>Transcript rows<input type="number" min="2" max="20" name="transcript_rows" value="<?php echo almsivi_ui_h($settings['presentation']['transcript_rows']); ?>"></label>
                    <label>ALMSIVI TTS volume boost<input type="number" min="1" max="4" name="tts_volume_boost" value="<?php echo almsivi_ui_h($settings['presentation']['tts_volume_boost']); ?>"></label>
                </fieldset>
                <fieldset class="almsivi-group wide"><legend>Safety and OpenMW actions</legend>
                    <div class="almsivi-form-grid"><?php foreach(['actions_enabled'=>'Enable negotiated actions','allow_hostile'=>'Allow hostile NPC targets','allow_creatures'=>'Allow creature targets']as$field=>$label): ?><label><span><input type="checkbox" name="<?php echo almsivi_ui_h($field); ?>" value="1"<?php echo $check($settings['safety'][$field]); ?>> <?php echo almsivi_ui_h($label); ?></span></label><?php endforeach; ?></div>
                </fieldset>
                <label class="wide">Revision note<input name="change_reason" maxlength="512" value="Management global settings"></label>
            </div>
            <div class="almsivi-actions"><button type="submit">Save Global Settings</button></div>
        </div>
    </form>
    <?php endif; ?>
</main>
<?php include __DIR__.'/tmpl/footer.html'; ?>
