<?php

declare(strict_types=1);

$studioTabs = [
    'xtts' => ['label' => 'XTTS', 'drivers' => ['xtts-fastapi', 'xtts']],
    'chatterbox' => ['label' => 'Chatterbox', 'drivers' => ['chatterbox']],
    'pockettts' => ['label' => 'PocketTTS', 'drivers' => ['pockettts']],
    'omnivoice' => ['label' => 'OmniVoice', 'drivers' => ['omnivoice']],
    'cartesia' => ['label' => 'Cartesia', 'drivers' => ['cartesia']],
    'inworld' => ['label' => 'Inworld', 'drivers' => ['inworld']],
    'fallbacks' => ['label' => 'Fallback Voices', 'drivers' => []],
];
$activeContent = is_array($activeTts['content'] ?? null) ? $activeTts['content'] : [];
$activeDriver = (string) ($activeContent['driver'] ?? '');
$tabUrl = static function (string $tab) use ($webRoot, $embedded): string {
    return $webRoot . '/ui/core/voice_library.php?' . http_build_query(array_filter(['tab' => $tab, 'embed' => $embedded ? '1' : null]));
};
$presetsForTab = static function (string $tab) use ($studioTabs, $ttsPresets): array {
    $drivers = $studioTabs[$tab]['drivers'] ?? [];
    return array_values(array_filter($ttsPresets, static fn(array $preset): bool => in_array((string) ($preset['content']['driver'] ?? ''), $drivers, true)));
};

include $uiRootDir . '/tmpl/head.html';
if (!$embedded) include $uiRootDir . '/tmpl/navbar.php';
?>
<main class="tts-studio-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <div class="page-header">
        <h1>Voice Management</h1>
        <p class="page-subtitle">Manage voice samples and global NPC fallback voices across all TTS providers.</p>
        <p class="page-note"><strong>Note:</strong> XTTS, Chatterbox, and PocketTTS share a simple voice sample flow. OmniVoice imports voices into the selected language library.</p>
    </div>

    <nav class="tab-nav" aria-label="TTS Studio providers">
        <span class="visually-hidden">Configured TTS Connectors</span>
        <?php foreach ($studioTabs as $tabKey => $tab):
            $tabPresets = $presetsForTab($tabKey);
            $isActiveProvider = $tabKey !== 'fallbacks' && in_array($activeDriver, $tab['drivers'], true);
            $statusClass = $tabKey === 'fallbacks' ? 'configured' : ($isActiveProvider ? 'connected' : ($tabPresets !== [] ? 'configured' : 'unconfigured'));
            $statusLabel = $tabKey === 'fallbacks' ? 'Global' : ($isActiveProvider ? 'Active' : ($tabPresets !== [] ? 'Configured' : 'Unconfigured'));
        ?>
            <a class="tab-btn tab-<?php echo almsivi_ui_h($tabKey); ?><?php echo $activeTab === $tabKey ? ' active' : ''; ?>" href="<?php echo almsivi_ui_h($tabUrl($tabKey)); ?>">
                <span class="tab-label"><?php echo almsivi_ui_h($tab['label']); ?></span>
                <span class="tab-status <?php echo almsivi_ui_h($statusClass); ?>"><?php echo almsivi_ui_h($statusLabel); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($notice !== ''): ?><p class="page-status" role="status"><?php echo almsivi_ui_h($notice); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><div class="page-error" role="alert"><p><?php echo almsivi_ui_h($error); ?></p><?php if ($errorReferences !== []): ?><ul><?php foreach ($errorReferences as $reference): ?><li><?php echo almsivi_ui_h($reference); ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>

    <?php if ($activeTab === 'fallbacks'):
        $morrowindRaces = ['argonian' => 'Argonian', 'breton' => 'Breton', 'dark_elf' => 'Dark Elf', 'high_elf' => 'High Elf', 'imperial' => 'Imperial', 'khajiit' => 'Khajiit', 'nord' => 'Nord', 'orc' => 'Orc', 'redguard' => 'Redguard', 'wood_elf' => 'Wood Elf'];
    ?>
        <section class="content-section">
            <h1>Fallback Voices <?php echo almsivi_ui_feature_badge('config.tts-studio.fallbacks', true); ?></h1>
            <p>Choose the voice used when an NPC has no explicit voice assigned. These settings apply to every TTS connector.</p>
            <p><strong>ALMSIVI resolution:</strong> explicit NPC voice, provider actor voice, then the selected connector's male or female fallback.</p>
            <div class="fallback-voice-grid" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.tts-studio.fallbacks')['description']); ?>">
                <?php foreach ($morrowindRaces as $raceId => $raceLabel): ?>
                    <section class="fallback-race-card">
                        <h2><?php echo almsivi_ui_h($raceLabel); ?></h2><div class="fallback-race-key"><?php echo almsivi_ui_h($raceId); ?></div>
                        <div class="fallback-gender-grid"><div><label>Male</label><input type="text" disabled aria-disabled="true"></div><div><label>Female</label><input type="text" disabled aria-disabled="true"></div></div>
                    </section>
                <?php endforeach; ?>
            </div>
            <div class="button-group"><span class="feature-control"><button type="button" class="btn-primary feature-placeholder-control" disabled aria-disabled="true">Save Fallback Voices</button><?php echo almsivi_ui_feature_badge('config.tts-studio.fallbacks', true); ?></span></div>
        </section>
    <?php else:
        $tab = $studioTabs[$activeTab];
        $providerLabel = (string) $tab['label'];
        $providerPresets = $presetsForTab($activeTab);
        $selectedProvider = $providerPresets[0] ?? null;
        foreach ($providerPresets as $preset) if (($preset['configuration_id'] ?? '') === $selectedDiscoveryId) $selectedProvider = $preset;
        $selectedProviderId = (string) ($selectedProvider['configuration_id'] ?? '');
        $selectedProviderContent = is_array($selectedProvider['content'] ?? null) ? $selectedProvider['content'] : [];
        $selectedProviderDriver = (string) ($selectedProviderContent['driver'] ?? '');
        $canBrowse = is_array($selectedProvider) && in_array($selectedProviderDriver, $voiceDiscoveryDrivers, true) && almsivi_voice_can_sync($selectedProvider);
        $canSync = is_array($selectedProvider) && in_array($selectedProviderDriver, $sampleUploadDrivers, true) && almsivi_voice_can_sync($selectedProvider);
        $cloudClone = in_array($activeTab, ['cartesia', 'inworld'], true);
        $catalogMatchesTab = is_array($discoveredPreset) && in_array((string) ($discoveredPreset['content']['driver'] ?? ''), $tab['drivers'], true);
    ?>
        <section class="content-section">
            <h1>Voice Sample Upload</h1>
            <p>Upload voice samples to ALMSIVI's persistent voice library. Compatible local connectors can sync the same bounded sample explicitly.</p>
            <span class="visually-hidden">Add WAV voice samples</span>
            <form method="post" enctype="multipart/form-data" action="<?php echo almsivi_ui_h($tabUrl($activeTab)); ?>">
                <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="studio_tab" value="<?php echo almsivi_ui_h($activeTab); ?>">
                <div class="field-row"><div><label for="voice-name">Voice name for a single WAV</label><input type="text" id="voice-name" name="voice_name" maxlength="80"></div><div><label for="voice-sample">Voice sample or batch</label><input id="voice-sample" name="voice_sample" type="file" accept="audio/wav,.wav,application/zip,.zip" required></div></div>
                <div class="button-group"><button class="btn-primary" type="submit">Upload Voice Sample</button></div>
            </form>
            <div class="requirements"><p><strong>📋 File Requirements:</strong></p><ul><li>PCM-compatible RIFF/WAVE, up to 16 MiB per sample</li><li>Voice names use letters, numbers, spaces, underscores, plus, dot, or hyphen</li><li>A flat ZIP batch may contain up to 64 WAV files and 128 MiB extracted</li><li>Files remain outside profile JSON and browser cookies</li></ul></div>
        </section>

        <section class="content-section">
            <h1><?php echo almsivi_ui_h($providerLabel); ?> Voice Cache</h1>
            <span class="visually-hidden">Provider Voice Browser</span>
            <p>Explicitly query the selected connector's speaker library. Opening TTS Studio never contacts a provider automatically.</p>
            <?php if (is_array($selectedProvider)): ?><p><strong>Current connector default:</strong> <?php echo almsivi_ui_h((string)($selectedProviderContent['voice'] ?? 'Connector default')); ?> <span class="status-badge"><?php echo almsivi_ui_h((string)($selectedProviderContent['language'] ?? 'en')); ?></span></p><?php endif; ?>
            <?php if ($canBrowse): ?>
                <form method="post" action="<?php echo almsivi_ui_h($tabUrl($activeTab)); ?>">
                    <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="action" value="discover"><input type="hidden" name="studio_tab" value="<?php echo almsivi_ui_h($activeTab); ?>">
                    <div class="field-row"><div><label for="voice-discovery-connector">TTS connector</label><select id="voice-discovery-connector" name="configuration_id"><?php foreach ($providerPresets as $preset): ?><option value="<?php echo almsivi_ui_h($preset['configuration_id'] ?? ''); ?>"<?php echo $selectedProviderId === ($preset['configuration_id'] ?? '') ? ' selected' : ''; ?>><?php echo almsivi_ui_h($preset['name'] ?? $providerLabel); ?></option><?php endforeach; ?></select></div><div><label for="voice-discovery-language">Language</label><input type="text" id="voice-discovery-language" name="language" value="<?php echo almsivi_ui_h($discoverLanguage); ?>" maxlength="12"></div></div>
                    <div class="button-group"><button class="btn-primary" type="submit">Refresh <?php echo almsivi_ui_h($providerLabel); ?> Server Voices</button></div>
                </form>
            <?php elseif ($cloudClone): ?>
                <p>This cloud provider can be configured as a typed TTS connector, but browser voice-clone generation is not connected yet.</p><div class="button-group"><span class="feature-control"><button type="button" class="btn-primary feature-placeholder-control" disabled aria-disabled="true">Discover voices</button><?php echo almsivi_ui_feature_badge('config.tts-studio.cloud-cloning', true); ?></span></div>
            <?php else: ?>
                <p>No compatible <?php echo almsivi_ui_h($providerLabel); ?> connector is configured.</p><div class="button-group"><button type="button" class="btn-primary feature-placeholder-control" disabled aria-disabled="true">Refresh <?php echo almsivi_ui_h($providerLabel); ?> Server Voices</button></div>
            <?php endif; ?>

            <?php if ($catalogMatchesTab && $catalogLoaded): ?>
                <?php if ($discoveredVoices === []): ?><p>The provider returned no voices for this language.</p><?php else: ?><div class="voice-status-grid"><?php foreach ($discoveredVoices as $item): ?><article class="voice-status-item"><span class="voice-name"><?php echo almsivi_ui_h($item['display']); ?></span><span class="status-icon synced">✓</span><div class="voice-actions"><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/connector-test"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>"><input type="hidden" name="kind" value="tts_provider"><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($discoveredPreset['configuration_id'] ?? ''); ?>"><input type="hidden" name="voice_id" value="<?php echo almsivi_ui_h($item['id']); ?>"><button type="submit" title="Test voice">▶</button></form><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/connector-default-voice"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($discoveredPreset['configuration_id'] ?? ''); ?>"><input type="hidden" name="voice_id" value="<?php echo almsivi_ui_h($item['id']); ?>"><input type="hidden" name="language" value="<?php echo almsivi_ui_h($item['language']); ?>"><button class="btn-primary" type="submit" title="Set connector default">✓</button></form></div></article><?php endforeach; ?></div><?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="content-section">
            <h1><?php echo almsivi_ui_h($providerLabel); ?> Local Voice Library</h1>
            <span class="visually-hidden">Voice Library</span>
            <p>Manage persistent local samples and explicitly sync or test them with this provider.</p>
            <?php if ($samples === []): ?><p>No voice files found in ALMSIVI's persistent voice library. Upload voice samples above first.</p><?php else: ?><div class="voice-status-grid">
                <?php foreach ($samples as $sample): $references = almsivi_voice_references($sample['name'], $voiceReferenceIndex); ?>
                    <article class="voice-status-item"><span class="voice-name" title="<?php echo almsivi_ui_h($sample['name']); ?>"><?php echo almsivi_ui_h($sample['name']); ?></span><span class="status-icon <?php echo $canSync ? 'unsynced' : 'synced'; ?>"><?php echo $canSync ? '✗' : '✓'; ?></span><div class="voice-actions">
                        <?php if ($canSync): ?><form method="post" action="<?php echo almsivi_ui_h($tabUrl($activeTab)); ?>"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="studio_tab" value="<?php echo almsivi_ui_h($activeTab); ?>"><input type="hidden" name="voice_name" value="<?php echo almsivi_ui_h($sample['name']); ?>"><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($selectedProviderId); ?>"><input type="hidden" name="language" value="<?php echo almsivi_ui_h($discoverLanguage); ?>"><button class="btn-primary" type="submit" title="Sync this voice">↻</button></form><?php endif; ?>
                        <?php if (is_array($selectedProvider)): ?><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/connector-test"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>"><input type="hidden" name="kind" value="tts_provider"><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($selectedProviderId); ?>"><input type="hidden" name="voice_id" value="<?php echo almsivi_ui_h($sample['name']); ?>"><button type="submit" title="Test voice">▶</button></form><?php endif; ?>
                        <?php if ($references === []): ?><form method="post" action="<?php echo almsivi_ui_h($tabUrl($activeTab)); ?>" data-confirm="Delete this local voice sample?"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="studio_tab" value="<?php echo almsivi_ui_h($activeTab); ?>"><input type="hidden" name="voice_name" value="<?php echo almsivi_ui_h($sample['name']); ?>"><button class="btn-danger" type="submit" title="Delete local sample">×</button></form><?php endif; ?>
                    </div></article>
                <?php endforeach; ?>
            </div><?php endif; ?>
        </section>

        <section class="content-section">
            <h1>Batch Process Missing Voices <?php echo almsivi_ui_feature_badge('config.tts-studio.batch-sync', true); ?></h1>
            <p>Upload missing local voices to the <?php echo almsivi_ui_h($providerLabel); ?> server.</p>
            <div class="button-group"><span class="feature-control"><button type="button" class="btn-primary feature-placeholder-control" disabled aria-disabled="true">Batch Upload Missing Voices (<?php echo count($samples); ?>)</button><?php echo almsivi_ui_feature_badge('config.tts-studio.batch-sync', true); ?></span></div>
        </section>

        <section class="content-section">
            <h1>Cloud <?php echo almsivi_ui_h($providerLabel); ?> Sync</h1>
            <p><strong>Only required for online provider instances.</strong> ALMSIVI keeps provider writes explicit and auditable.</p>
            <div class="button-group"><span class="feature-control"><button type="button" class="btn-primary feature-placeholder-control" disabled aria-disabled="true">Sync Voice Cache</button><?php echo almsivi_ui_feature_badge('config.tts-studio.batch-sync', true); ?></span></div>
        </section>
    <?php endif; ?>
</main>
<script src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/almsivi-management.js" defer></script>
<?php include $uiRootDir . '/tmpl/footer.html'; ?>
