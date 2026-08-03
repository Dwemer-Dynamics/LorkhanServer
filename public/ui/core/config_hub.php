<?php
declare(strict_types=1);
$pageTitle='Configuration';$topNavSection='configuration';$BODY_CLASS='hub-page';require dirname(__DIR__).'/ui_bootstrap.php';
$sections=[
    'characters'=>['label'=>'Characters','tabs'=>[
        'npc-page'=>['NPCs','🌟',$webRoot.'/ui/core/npc_master.php?embed=1'],
        'profiles-page'=>['Core Profiles','🗃️',$webRoot.'/ui/core/core_profiles.php?embed=1'],
        'player-page'=>['Player','👤',$webRoot.'/ui/core/player_management.php?embed=1'],
        'narration-page'=>['Narration','🗣️',$webRoot.'/ui/narrator_management.php?embed=1'],
        'npcbio-page'=>['NPC Biographies','🚪',$webRoot.'/ui/core/npc_biographies.php?embed=1'],
    ]],
    'ai-voice'=>['label'=>'AI & Voice','tabs'=>[
        'llm-page'=>['LLM','🧠',$webRoot.'/ui/core/llm_connectors.php?embed=1'],
        'tts-page'=>['TTS','🔊',$webRoot.'/ui/core/tts_connectors.php?embed=1'],
        'studio-page'=>['TTS Studio','📢',$webRoot.'/ui/core/voice_library.php?embed=1'],
        'keys-page'=>['API Keys','🔑',$webRoot.'/ui/api_keys.php?embed=1'],
    ]],
    'world-behavior'=>['label'=>'World & Behavior','tabs'=>[
        'globals-page'=>['Global Settings','🌐',$webRoot.'/ui/global_settings.php?embed=1'],
        'knowledge-page'=>['Oghma Infinium','📜',$webRoot.'/ui/worldknowledge_upload.php?embed=1'],
        'items-page'=>['Descriptions','📖',$webRoot.'/ui/description_manager.php?embed=1'],
        'actions-page'=>['Action Editor','⚔️',$webRoot.'/ui/function_editor.php?embed=1'],
        'prompts-page'=>['Prompts Manager','💬',$webRoot.'/ui/prompts_manager.php?embed=1'],
        'plugins-page'=>['Server Plugins','🧩',$webRoot.'/ui/server_plugins.php?embed=1'],
    ]],
];
$requested=(string)($_GET['tab']??'npc-page');$allTabs=[];foreach($sections as$section)foreach($section['tabs']as$id=>$tab)$allTabs[$id]=$tab;
$active=array_key_exists($requested,$allTabs)?$requested:'npc-page';
include dirname(__DIR__).'/tmpl/head.html';include dirname(__DIR__).'/tmpl/navbar.php';
?>
<main class="almsivi-config-hub" data-config-hub data-active-tab="<?php echo almsivi_ui_h($active); ?>">
    <div class="config-navigation" aria-label="Configuration sections"><div class="tab-groups">
        <?php foreach($sections as$sectionId=>$section): ?><section class="tab-group" data-category="<?php echo almsivi_ui_h($sectionId); ?>"><div class="tab-group-label"><?php echo almsivi_ui_h($section['label']); ?></div><div class="tab-buttons" role="tablist">
            <?php foreach($section['tabs']as$tabId=>[$label,$icon]): ?><button class="tab-button" type="button" data-tab="<?php echo almsivi_ui_h($tabId); ?>" data-category="<?php echo almsivi_ui_h($sectionId); ?>"><span class="tab-icon" aria-hidden="true"><?php echo $icon; ?></span><span><?php echo almsivi_ui_h($label); ?></span></button><?php endforeach; ?>
        </div></section><?php endforeach; ?>
    </div></div>
    <div class="almsivi-hub-content"><?php foreach($allTabs as$tabId=>[$label,$icon,$src]): ?><section id="<?php echo almsivi_ui_h($tabId); ?>" class="almsivi-hub-panel" data-tab-panel><iframe title="<?php echo almsivi_ui_h($label); ?>" loading="<?php echo $tabId===$active?'eager':'lazy'; ?>" src="<?php echo $tabId===$active?almsivi_ui_h($src):'about:blank'; ?>" data-src="<?php echo almsivi_ui_h($src); ?>"></iframe></section><?php endforeach; ?></div>
</main>
<?php include dirname(__DIR__).'/tmpl/footer.html'; ?>
