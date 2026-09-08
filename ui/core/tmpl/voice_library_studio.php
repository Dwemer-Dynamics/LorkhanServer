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
    'pronunciations' => ['label' => 'Pronunciations', 'drivers' => []],
];
$activeContent = is_array($activeTts['content'] ?? null) ? $activeTts['content'] : [];
$activeDriver = (string) ($activeContent['driver'] ?? '');
$tabUrl = static function (string $tab) use ($webRoot, $embedded, &$discoverLanguage, &$selectedDiscoveryId): string {
    return $webRoot . '/ui/core/voice_library.php?' . http_build_query(array_filter(['tab' => $tab, 'embed' => $embedded ? '1' : null, 'language'=>$tab==='omnivoice'?$discoverLanguage:null, 'configuration_id'=>$tab==='omnivoice'?$selectedDiscoveryId:null]));
};
$presetsForTab = static function (string $tab) use ($studioTabs, $ttsPresets): array {
    $drivers = $studioTabs[$tab]['drivers'] ?? [];
    return array_values(array_filter($ttsPresets, static fn(array $preset): bool => in_array((string) ($preset['content']['driver'] ?? ''), $drivers, true)));
};

include $uiRootDir . '/tmpl/head.html';
if (!$embedded) include $uiRootDir . '/tmpl/navbar.php';
?>
<main class="tts-studio-page<?php echo $embedded ? ' embedded' : ''; ?>" data-voice-preview-endpoint="<?php echo lorkhan_ui_h($managementBasePath); ?>/api/v1/tts-previews">
    <div class="page-header">
        <h1>Voice Management</h1>
        <p class="page-subtitle">Manage voice samples, global NPC fallback voices, and pronunciations across all TTS providers.</p>
        <p class="page-note"><strong>Note:</strong> XTTS, Chatterbox, and PocketTTS share a simple voice sample flow. OmniVoice imports voices into the selected language library.</p>
    </div>

    <nav class="tab-nav" aria-label="TTS Studio providers">
        <span class="visually-hidden">Configured TTS Connectors</span>
        <?php foreach ($studioTabs as $tabKey => $tab):
            $tabPresets = $presetsForTab($tabKey);
            $tabLabel = $tab['label'];
            if ($tabKey === 'pockettts') {
                $pocketPreset = $activeDriver === 'pockettts' ? $activeTts : ($tabPresets[0] ?? null);
                $pocketMode = $pocketPreset !== null && !lorkhan_voice_can_sync($pocketPreset) ? 'audio.cpp' : 'Standard API';
                $tabLabel .= ' ('.$pocketMode.')';
            }
            // Fallback voices and the pronunciation dictionary apply to every connector, so both report one global status.
            $isGlobalTab = in_array($tabKey, ['fallbacks', 'pronunciations'], true);
            $isActiveProvider = !$isGlobalTab && in_array($activeDriver, $tab['drivers'], true);
            $statusClass = $isGlobalTab ? 'configured' : ($isActiveProvider ? 'connected' : ($tabPresets !== [] ? 'configured' : 'unconfigured'));
            $statusLabel = $isGlobalTab ? 'Global' : ($isActiveProvider ? 'Active' : ($tabPresets !== [] ? 'Configured' : 'Not configured'));
        ?>
            <a class="tab-btn tab-<?php echo lorkhan_ui_h($tabKey); ?><?php echo $activeTab === $tabKey ? ' active' : ''; ?>" href="<?php echo lorkhan_ui_h($tabUrl($tabKey)); ?>">
                <span class="tab-label"><?php echo lorkhan_ui_h($tabLabel); ?></span>
                <span class="tab-status <?php echo lorkhan_ui_h($statusClass); ?>"><?php echo lorkhan_ui_h($statusLabel); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($notice !== ''): ?><p class="page-status" role="status"><?php echo lorkhan_ui_h($notice); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><div class="page-error" role="alert"><p><?php echo lorkhan_ui_h($error); ?></p><?php if ($errorReferences !== []): ?><ul><?php foreach ($errorReferences as $reference): ?><li><?php echo lorkhan_ui_h($reference); ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>

    <?php if ($activeTab === 'pronunciations'):
        // The dictionary is global to every TTS connector, so it owns one tab instead of
        // repeating beneath each provider. Every input still comes from the controller.
        $pronEntries = (isset($pronunciationEntries) && is_array($pronunciationEntries)) ? $pronunciationEntries : [];
        $pronNotice = isset($pronunciationNotice) ? (string) $pronunciationNotice : '';
        $pronError = isset($pronunciationError) ? (string) $pronunciationError : '';
        $pronFilter = isset($pronunciationFilter) ? (string) $pronunciationFilter : '';
        $pronBaseUrl = $tabUrl('pronunciations');
        // Posting back to the filtered URL keeps the visible list stable across a save.
        $pronUrl = $webRoot . '/ui/core/voice_library.php?' . http_build_query(array_filter(['tab' => 'pronunciations', 'embed' => $embedded ? '1' : null, 'oghma_tag' => $pronFilter !== '' ? $pronFilter : null]));
        $pronBool = static fn(mixed $value): bool => in_array($value, [true, 1, '1', 't', 'true', 'on', 'yes', 'y'], true);
        $pronScopeList = static function (mixed $value): array {
            $parts = array_map('trim', explode(',', (string) $value));
            return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        };
        // Only populated scope groups are announced so a row never claims a filter it does not use.
        $pronScopeGroups = static function (array $entry) use ($pronScopeList): array {
            $groups = [];
            foreach (['npc_names' => 'NPC names', 'races' => 'Races', 'oghma_tags' => 'Oghma tags'] as $field => $label) {
                $values = $pronScopeList($entry[$field] ?? '');
                if ($values !== []) $groups[] = ['label' => $label, 'values' => $values];
            }
            return $groups;
        };
        $pronLower = static fn(string $value): string => mb_strtolower($value, 'UTF-8');
        $pronTags = [];
        $pronBuiltinEntries = [];
        $pronCustomEntries = [];
        foreach ($pronEntries as $pronIndex => $pronEntry) {
            if (!is_array($pronEntry)) continue;
            $pronEntry['_key'] = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($pronEntry['id'] ?? '')) . '-' . (int) $pronIndex;
            $pronEntryTags = $pronScopeList($pronEntry['oghma_tags'] ?? '');
            foreach ($pronEntryTags as $pronEntryTag) $pronTags[$pronLower($pronEntryTag)] = $pronEntryTag;
            if ($pronBool($pronEntry['is_builtin'] ?? false)) { $pronBuiltinEntries[] = $pronEntry; continue; }
            // The tag filter narrows only the editable custom list; built-in defaults stay listed in full.
            if ($pronFilter !== '' && !in_array($pronLower($pronFilter), array_map($pronLower, $pronEntryTags), true)) continue;
            $pronCustomEntries[] = $pronEntry;
        }
        ksort($pronTags, SORT_NATURAL | SORT_FLAG_CASE);

        // Preview inputs come from the controller. A missing piece only disables the play
        // controls; it never removes the strip or breaks the rest of the page.
        $pronPreviewOptions = (isset($pronunciationPreview) && is_array($pronunciationPreview)) ? $pronunciationPreview : [];
        $pronPreviewConnectors = is_array($pronPreviewOptions['connectors'] ?? null) ? $pronPreviewOptions['connectors'] : [];
        $pronPreviewVoices = is_array($pronPreviewOptions['voices'] ?? null) ? $pronPreviewOptions['voices'] : [];
        $pronPreviewConnectorId = (string) ($pronPreviewOptions['default_connector_id'] ?? '');
        $pronPreviewVoice = (string) ($pronPreviewOptions['default_voice'] ?? '');
        // Each connector carries only the voices it can actually speak, so switching connectors
        // rebuilds the voice list instead of leaving another connector's voice selected.
        $pronPreviewConnectorVoices = [];
        foreach ($pronPreviewConnectors as $pronPreviewConnector) {
            $pronPreviewConnectorVoices[(string) $pronPreviewConnector['id']] =
                is_array($pronPreviewConnector['voices'] ?? null) ? array_values($pronPreviewConnector['voices']) : [];
        }
        $pronPreviewEndpoint = isset($pronunciationPreviewEndpoint) ? trim((string) $pronunciationPreviewEndpoint) : '';
        $pronPreviewInstallation = isset($installationId) ? (string) $installationId : '';
        $pronPreviewNotice = '';
        if ($pronPreviewEndpoint === '' || $pronPreviewInstallation === '') {
            $pronPreviewNotice = 'Preview is unavailable: this server has no paired installation to preview with.';
        } elseif ($pronPreviewConnectors === []) {
            $pronPreviewNotice = 'Preview is unavailable: no TTS connector with an installed voice is configured yet.';
        } elseif ($pronPreviewVoices === []) {
            $pronPreviewNotice = 'Preview is unavailable: no installed voices were found.';
        }
        $pronPreviewReady = $pronPreviewNotice === '';
        // One helper keeps the play control identical in the add row, the editable custom rows,
        // and the read-only built-in rows.
        $pronPlay = static function (string $label, ?string $inputId = null, ?string $staticText = null, string $context = '') use ($pronPreviewReady): string {
            $name = 'Play ' . $label . ($context !== '' ? ' for ' . $context : '');
            $attributes = ' data-pron-play="1"';
            if ($inputId !== null && $inputId !== '') $attributes .= ' data-pron-input="' . lorkhan_ui_h($inputId) . '"';
            if ($staticText !== null) $attributes .= ' data-pron-text="' . lorkhan_ui_h($staticText) . '"';
            if (!$pronPreviewReady) $attributes .= ' disabled';
            return '<button class="pron-play" type="button"' . $attributes . ' title="' . lorkhan_ui_h($name) . '">'
                . '<span class="pron-play-icon" aria-hidden="true"></span>'
                . '<span class="pron-play-text">' . lorkhan_ui_h($name) . '</span></button>';
        };
    ?>
        <?php if ($pronNotice !== ''): ?><p class="page-status" role="status"><?php echo lorkhan_ui_h($pronNotice); ?></p><?php endif; ?>
        <?php if ($pronError !== ''): ?><div class="page-error" role="alert"><p><?php echo lorkhan_ui_h($pronError); ?></p></div><?php endif; ?>

        <section class="content-section pron-section" aria-labelledby="pron-heading">
            <h1 id="pron-heading">Pronunciations</h1>
            <p>Rewrite how the TTS engine says a word without changing anything the player reads. These entries apply to every TTS connector.</p>
            <ul class="pron-intro-list">
                <li><strong>Audio only:</strong> subtitles and stored dialogue keep the original spelling &mdash; only the text sent to the voice engine is rewritten.</li>
                <li><strong>Joined spelling:</strong> write phonetic forms without hyphens because some voice engines pause at every dash.</li>
                <li><strong>Blank field:</strong> that filter is not applied. With NPC names, races, and Oghma tags all blank the entry is global and every NPC uses it.</li>
                <li><strong>Commas inside one field</strong> are alternatives &mdash; <em>Nord, Dark Elf</em> matches either race.</li>
                <li><strong>Two or more fields filled:</strong> the speaker must match all of them, so <em>Dark Elf</em> plus <em>companion</em> only fires for a Dark Elf carrying that Oghma tag.</li>
                <li><strong>Built-in entries</strong> keep their original term, but their spoken version can be edited and they can be disabled or deleted.</li>
            </ul>

            <div class="pron-preview<?php echo $pronPreviewReady ? '' : ' is-unavailable'; ?>" id="pron-preview"
                 data-pron-endpoint="<?php echo lorkhan_ui_h($pronPreviewEndpoint); ?>"
                 data-pron-installation="<?php echo lorkhan_ui_h($pronPreviewInstallation); ?>"
                 data-pron-csrf="<?php echo lorkhan_ui_h($csrf); ?>"
                 data-pron-max-length="<?php echo (int) \LorkhanServer\Application\SpeechPreviewCatalog::MAX_TEXT_LENGTH; ?>"
                 data-pron-ready="<?php echo $pronPreviewReady ? '1' : '0'; ?>"
                 data-pron-connector-voices="<?php echo lorkhan_ui_h(json_encode($pronPreviewConnectorVoices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
                <p class="pron-preview-caption">Preview voice</p>
                <div class="pron-preview-field">
                    <label class="pron-label" for="pron-preview-connector">Connector</label>
                    <select class="pron-field" id="pron-preview-connector"<?php echo $pronPreviewReady ? '' : ' disabled'; ?>>
                        <?php if ($pronPreviewConnectors === []): ?><option value="">No connector configured</option><?php else: ?>
                            <?php foreach ($pronPreviewConnectors as $pronPreviewConnector): ?><option value="<?php echo lorkhan_ui_h($pronPreviewConnector['id']); ?>"<?php echo (string) $pronPreviewConnector['id'] === $pronPreviewConnectorId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($pronPreviewConnector['label']); ?></option><?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="pron-preview-field">
                    <label class="pron-label" for="pron-preview-voice">Voice</label>
                    <select class="pron-field" id="pron-preview-voice"<?php echo $pronPreviewReady ? '' : ' disabled'; ?>>
                        <?php if ($pronPreviewVoices === []): ?><option value="">No voice installed</option><?php else: ?>
                            <?php foreach ($pronPreviewVoices as $pronPreviewVoiceOption): ?><option value="<?php echo lorkhan_ui_h($pronPreviewVoiceOption); ?>"<?php echo (string) $pronPreviewVoiceOption === $pronPreviewVoice ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($pronPreviewVoiceOption); ?></option><?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="pron-preview-player">
                    <span class="pron-label" id="pron-preview-player-label">Last preview</span>
                    <audio class="pron-preview-audio" id="pron-preview-audio" controls preload="none" aria-labelledby="pron-preview-player-label"></audio>
                </div>
                <p class="pron-preview-status" id="pron-preview-status" role="status" aria-live="polite"><?php
                    echo $pronPreviewReady
                        ? 'Pick a connector and voice, then use a play button beside any entry.'
                        : lorkhan_ui_h($pronPreviewNotice);
                ?></p>
            </div>
        </section>

        <section class="content-section pron-section">
            <h1>Add Custom Pronunciation</h1>
            <p>Original text is matched as a whole term. Leave every access field blank to apply the entry to all NPCs.</p>

            <form method="post" action="<?php echo lorkhan_ui_h($pronUrl); ?>" class="pron-cols pron-add-row">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="action" value="pronunciation_save">
                <input type="hidden" name="studio_tab" value="pronunciations">
                <div>
                    <label class="pron-label" for="pron-add-source">Original</label>
                    <div class="pron-input-row">
                        <input class="pron-field" type="text" id="pron-add-source" name="source_text" maxlength="120" required autocomplete="off" spellcheck="false" placeholder="Vvardenfell">
                        <?php echo $pronPlay('Original', 'pron-add-source'); ?>
                    </div>
                </div>
                <div>
                    <label class="pron-label" for="pron-add-spoken">Spoken version</label>
                    <div class="pron-input-row">
                        <input class="pron-field" type="text" id="pron-add-spoken" name="spoken_text" maxlength="240" required autocomplete="off" spellcheck="false" placeholder="Vardenfell">
                        <?php echo $pronPlay('Spoken version', 'pron-add-spoken'); ?>
                    </div>
                </div>
                <div class="pron-access">
                    <p class="pron-scope pron-scope-hint" id="pron-add-help">Blank fields add no restriction. Fill more than one and the speaker must match them all.</p>
                    <div class="pron-access-field"><label class="pron-label" for="pron-add-names">NPC names (optional)</label><input class="pron-field" type="text" id="pron-add-names" name="npc_names" maxlength="512" autocomplete="off" spellcheck="false" placeholder="Caius Cosades, Jiub" aria-describedby="pron-add-help"></div>
                    <div class="pron-access-field"><label class="pron-label" for="pron-add-races">Races (optional)</label><input class="pron-field" type="text" id="pron-add-races" name="races" maxlength="512" autocomplete="off" spellcheck="false" placeholder="Nord, Dark Elf" aria-describedby="pron-add-help"></div>
                    <div class="pron-access-field"><label class="pron-label" for="pron-add-tags">Oghma tags (optional)</label><input class="pron-field" type="text" id="pron-add-tags" name="oghma_tags" maxlength="512" autocomplete="off" spellcheck="false" list="pron-tag-options" placeholder="companion, balmora" aria-describedby="pron-add-help"></div>
                </div>
                <div class="pron-toggle">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" id="pron-add-enabled" name="enabled" value="1" checked>
                    <label class="pron-toggle-label" for="pron-add-enabled">Enabled</label>
                </div>
                <div class="pron-actions"><button class="btn-primary pron-btn" type="submit">Add Entry</button></div>
            </form>

            <datalist id="pron-tag-options">
                <?php foreach ($pronTags as $pronTagOption): ?><option value="<?php echo lorkhan_ui_h($pronTagOption); ?>"></option><?php endforeach; ?>
            </datalist>
        </section>

        <section class="content-section pron-section">
            <h1>Custom Pronunciations</h1>

            <form method="get" action="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/voice_library.php" class="pron-toolbar">
                <input type="hidden" name="tab" value="pronunciations">
                <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <div class="pron-toolbar-field">
                    <label class="pron-label" for="pron-tag-filter">Filter by Oghma tag</label>
                    <select class="pron-field" id="pron-tag-filter" name="oghma_tag">
                        <option value=""<?php echo $pronFilter === '' ? ' selected' : ''; ?>>All tags</option>
                        <?php foreach ($pronTags as $pronTagOption): ?><option value="<?php echo lorkhan_ui_h($pronTagOption); ?>"<?php echo $pronFilter !== '' && $pronLower($pronFilter) === $pronLower($pronTagOption) ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($pronTagOption); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-secondary pron-btn" type="submit">Apply Filter</button>
                <?php if ($pronFilter !== ''): ?><a class="pron-clear-filter" href="<?php echo lorkhan_ui_h($pronBaseUrl); ?>">Clear filter</a><?php endif; ?>
            </form>

            <p class="pron-count"><?php echo count($pronCustomEntries); ?> custom <?php echo count($pronCustomEntries) === 1 ? 'entry' : 'entries'; ?><?php echo $pronFilter !== '' ? ' tagged &quot;' . lorkhan_ui_h($pronFilter) . '&quot;' : ''; ?>.</p>

            <div class="pron-grid">
                <div class="pron-cols pron-head" aria-hidden="true"><span>Original</span><span>Spoken Version</span><span>Applies To</span><span>Enabled</span><span>Actions</span></div>

                <?php if ($pronCustomEntries === []): ?>
                    <p class="pron-empty">
                        <?php if ($pronFilter !== ''): ?>No custom entries use the tag &quot;<?php echo lorkhan_ui_h($pronFilter); ?>&quot;. Choose <strong>All tags</strong> to see every entry.<?php else: ?>No custom pronunciations yet. Add one above to override how a word is spoken.<?php endif; ?>
                    </p>
                <?php else: ?>
                    <?php foreach ($pronCustomEntries as $pronEntry):
                        $pronKey = (string) $pronEntry['_key'];
                        $pronId = (string) ($pronEntry['id'] ?? '');
                        $pronWritten = (string) ($pronEntry['source_text'] ?? '');
                        $pronEnabled = $pronBool($pronEntry['enabled'] ?? false);
                        $pronGroups = $pronScopeGroups($pronEntry);
                    ?>
                        <form method="post" action="<?php echo lorkhan_ui_h($pronUrl); ?>" class="pron-cols pron-row<?php echo $pronEnabled ? '' : ' is-disabled'; ?>">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                            <input type="hidden" name="action" value="pronunciation_save">
                            <input type="hidden" name="studio_tab" value="pronunciations">
                            <input type="hidden" name="id" value="<?php echo lorkhan_ui_h($pronId); ?>">
                            <div>
                                <label class="pron-label" for="pron-source-<?php echo lorkhan_ui_h($pronKey); ?>">Original</label>
                                <div class="pron-input-row">
                                    <input class="pron-field" type="text" id="pron-source-<?php echo lorkhan_ui_h($pronKey); ?>" name="source_text" value="<?php echo lorkhan_ui_h($pronWritten); ?>" maxlength="120" required autocomplete="off" spellcheck="false">
                                    <?php echo $pronPlay('Original', 'pron-source-' . $pronKey, null, $pronWritten); ?>
                                </div>
                            </div>
                            <div>
                                <label class="pron-label" for="pron-spoken-<?php echo lorkhan_ui_h($pronKey); ?>">Spoken version</label>
                                <div class="pron-input-row">
                                    <input class="pron-field" type="text" id="pron-spoken-<?php echo lorkhan_ui_h($pronKey); ?>" name="spoken_text" value="<?php echo lorkhan_ui_h((string) ($pronEntry['spoken_text'] ?? '')); ?>" maxlength="240" required autocomplete="off" spellcheck="false">
                                    <?php echo $pronPlay('Spoken version', 'pron-spoken-' . $pronKey, null, $pronWritten); ?>
                                </div>
                            </div>
                            <div class="pron-access">
                                <p class="pron-scope" id="pron-scope-<?php echo lorkhan_ui_h($pronKey); ?>"><?php if ($pronGroups === []): ?><span class="pron-badge">Global</span> Every NPC uses this entry.<?php else: ?>Speaker must match <?php foreach ($pronGroups as $pronGroupIndex => $pronGroup): ?><?php echo $pronGroupIndex > 0 ? ' <strong>and</strong> ' : ''; ?><span class="pron-scope-label"><?php echo lorkhan_ui_h($pronGroup['label']); ?>:</span> <?php echo lorkhan_ui_h(implode(' or ', $pronGroup['values'])); ?><?php endforeach; ?>.<?php endif; ?></p>
                                <div class="pron-access-field"><label class="pron-label" for="pron-names-<?php echo lorkhan_ui_h($pronKey); ?>">NPC names</label><input class="pron-field" type="text" id="pron-names-<?php echo lorkhan_ui_h($pronKey); ?>" name="npc_names" value="<?php echo lorkhan_ui_h((string) ($pronEntry['npc_names'] ?? '')); ?>" maxlength="512" autocomplete="off" spellcheck="false" placeholder="Blank = any name" aria-describedby="pron-scope-<?php echo lorkhan_ui_h($pronKey); ?>"></div>
                                <div class="pron-access-field"><label class="pron-label" for="pron-races-<?php echo lorkhan_ui_h($pronKey); ?>">Races</label><input class="pron-field" type="text" id="pron-races-<?php echo lorkhan_ui_h($pronKey); ?>" name="races" value="<?php echo lorkhan_ui_h((string) ($pronEntry['races'] ?? '')); ?>" maxlength="512" autocomplete="off" spellcheck="false" placeholder="Blank = any race" aria-describedby="pron-scope-<?php echo lorkhan_ui_h($pronKey); ?>"></div>
                                <div class="pron-access-field"><label class="pron-label" for="pron-tags-<?php echo lorkhan_ui_h($pronKey); ?>">Oghma tags</label><input class="pron-field" type="text" id="pron-tags-<?php echo lorkhan_ui_h($pronKey); ?>" name="oghma_tags" value="<?php echo lorkhan_ui_h((string) ($pronEntry['oghma_tags'] ?? '')); ?>" maxlength="512" autocomplete="off" spellcheck="false" list="pron-tag-options" placeholder="Blank = any tag" aria-describedby="pron-scope-<?php echo lorkhan_ui_h($pronKey); ?>"></div>
                            </div>
                            <div class="pron-toggle">
                                <input type="hidden" name="enabled" value="0">
                                <input type="checkbox" id="pron-enabled-<?php echo lorkhan_ui_h($pronKey); ?>" name="enabled" value="1" aria-label="<?php echo lorkhan_ui_h('Enable ' . $pronWritten); ?>"<?php echo $pronEnabled ? ' checked' : ''; ?>>
                                <label class="pron-toggle-label" for="pron-enabled-<?php echo lorkhan_ui_h($pronKey); ?>">Enabled</label>
                            </div>
                            <div class="pron-actions">
                                <button class="btn-primary pron-btn" type="submit" aria-label="<?php echo lorkhan_ui_h('Save ' . $pronWritten); ?>">Save</button>
                                <button class="btn-danger pron-btn" type="submit" form="pron-delete-form-<?php echo lorkhan_ui_h($pronKey); ?>" aria-label="<?php echo lorkhan_ui_h('Delete ' . $pronWritten); ?>">Delete</button>
                            </div>
                        </form>
                        <form method="post" action="<?php echo lorkhan_ui_h($pronUrl); ?>" id="pron-delete-form-<?php echo lorkhan_ui_h($pronKey); ?>" class="pron-hidden-form" data-confirm="<?php echo lorkhan_ui_h('Delete the custom pronunciation for "' . $pronWritten . '"?'); ?>">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                            <input type="hidden" name="action" value="pronunciation_delete">
                            <input type="hidden" name="studio_tab" value="pronunciations">
                            <input type="hidden" name="id" value="<?php echo lorkhan_ui_h($pronId); ?>">
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="content-section pron-section">
            <h1>Built-in Pronunciations</h1>

            <div class="pron-grid">
                <div class="pron-cols pron-head" aria-hidden="true"><span>Original</span><span>Spoken Version</span><span>Applies To</span><span>Enabled</span><span>Actions</span></div>

                <?php if ($pronBuiltinEntries === []): ?>
                    <p class="pron-empty">No built-in pronunciations are available.</p>
                <?php else: ?>
                    <?php foreach ($pronBuiltinEntries as $pronEntry):
                        $pronKey = 'b' . (string) $pronEntry['_key'];
                        $pronWritten = (string) ($pronEntry['source_text'] ?? '');
                        $pronSpoken = (string) ($pronEntry['spoken_text'] ?? '');
                        $pronEnabled = $pronBool($pronEntry['enabled'] ?? false);
                        $pronGroups = $pronScopeGroups($pronEntry);
                    ?>
                        <form method="post" action="<?php echo lorkhan_ui_h($pronUrl); ?>" class="pron-cols pron-row<?php echo $pronEnabled ? '' : ' is-disabled'; ?>">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                            <input type="hidden" name="action" value="pronunciation_toggle" data-pron-action>
                            <input type="hidden" name="studio_tab" value="pronunciations">
                            <input type="hidden" name="id" value="<?php echo lorkhan_ui_h((string) ($pronEntry['id'] ?? '')); ?>">
                            <div><span class="pron-static-label">Original</span><div class="pron-static-row"><p class="pron-static"><?php echo lorkhan_ui_h($pronWritten); ?></p><?php echo $pronPlay('Original', null, $pronWritten, $pronWritten); ?></div></div>
                            <div><label class="pron-label" for="pron-spoken-<?php echo lorkhan_ui_h($pronKey); ?>">Spoken version</label>
                                <div class="pron-static-row pron-built-in-display" data-pron-display><p class="pron-static"><?php echo lorkhan_ui_h($pronSpoken); ?></p><?php echo $pronPlay('Spoken version', null, $pronSpoken, $pronWritten); ?></div>
                                <div class="pron-input-row pron-built-in-editor" id="pron-editor-<?php echo lorkhan_ui_h($pronKey); ?>" data-pron-editor hidden>
                                    <input class="pron-field" type="text" id="pron-spoken-<?php echo lorkhan_ui_h($pronKey); ?>" name="spoken_text" value="<?php echo lorkhan_ui_h($pronSpoken); ?>" maxlength="240" required autocomplete="off" spellcheck="false">
                                    <?php echo $pronPlay('Spoken version', 'pron-spoken-'.$pronKey, null, $pronWritten); ?>
                                </div>
                            </div>
                            <div><span class="pron-static-label">Applies To</span>
                                <?php if ($pronGroups === []): ?><p class="pron-scope"><span class="pron-badge">Global</span></p><?php else: ?>
                                    <?php foreach ($pronGroups as $pronGroup): ?><p class="pron-scope"><span class="pron-scope-label"><?php echo lorkhan_ui_h($pronGroup['label']); ?>:</span> <?php echo lorkhan_ui_h(implode(' or ', $pronGroup['values'])); ?></p><?php endforeach; ?>
                                    <?php if (count($pronGroups) > 1): ?><p class="pron-scope pron-scope-hint">All of these must match.</p><?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="pron-toggle">
                                <input type="hidden" name="enabled" value="0">
                                <input type="checkbox" id="pron-enabled-<?php echo lorkhan_ui_h($pronKey); ?>" name="enabled" value="1" aria-label="<?php echo lorkhan_ui_h('Enable built-in ' . $pronWritten); ?>"<?php echo $pronEnabled ? ' checked' : ''; ?>>
                                <label class="pron-toggle-label" for="pron-enabled-<?php echo lorkhan_ui_h($pronKey); ?>">Enabled</label>
                            </div>
                            <div class="pron-actions">
                                <button class="btn-primary pron-btn" type="submit" data-pron-apply>Apply</button>
                                <button class="btn-secondary pron-btn" type="button" data-pron-edit aria-expanded="false" aria-controls="pron-editor-<?php echo lorkhan_ui_h($pronKey); ?>">Edit</button>
                                <button class="btn-danger pron-btn" type="submit" form="pron-delete-form-<?php echo lorkhan_ui_h($pronKey); ?>" aria-label="<?php echo lorkhan_ui_h('Delete built-in '.$pronWritten); ?>">Delete</button>
                            </div>
                        </form>
                        <form method="post" action="<?php echo lorkhan_ui_h($pronUrl); ?>" id="pron-delete-form-<?php echo lorkhan_ui_h($pronKey); ?>" class="pron-hidden-form" data-confirm="<?php echo lorkhan_ui_h('Delete the built-in pronunciation for "'.$pronWritten.'"?'); ?>">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="action" value="pronunciation_delete"><input type="hidden" name="studio_tab" value="pronunciations"><input type="hidden" name="id" value="<?php echo (int)$pronEntry['id']; ?>">
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

    <?php elseif ($activeTab === 'fallbacks'):
        $morrowindRaces = ['argonian' => 'Argonian', 'breton' => 'Breton', 'dark_elf' => 'Dark Elf', 'high_elf' => 'High Elf', 'imperial' => 'Imperial', 'khajiit' => 'Khajiit', 'nord' => 'Nord', 'orc' => 'Orc', 'redguard' => 'Redguard', 'wood_elf' => 'Wood Elf'];
        // One matrix covers every TTS connector, so it is read from the global fallback store
        // instead of the selected connector revision.
        $fallbackVoices = (new \LorkhanServer\Infrastructure\TtsFallbackRepository($database))->matrix();
        $fallbackVoiceIds=array_column($samples,'name');
        foreach(($pronunciationPreview['connectors']??[])as$connector)$fallbackVoiceIds=array_merge($fallbackVoiceIds,$connector['voices']??[]);
        foreach($fallbackVoices as$genders)$fallbackVoiceIds=array_merge($fallbackVoiceIds,array_values($genders));
        $fallbackVoiceIds=array_values(array_unique(array_filter($fallbackVoiceIds,static fn($voice):bool=>is_string($voice)&&trim($voice)!=='')));
        natcasesort($fallbackVoiceIds);
    ?>
        <section class="content-section">
            <h1>Fallback Voices</h1>
            <p>Choose the voice used when an NPC has no explicit voice assigned. These settings apply to every TTS connector.</p>
            <p class="fallback-resolution"><strong>Resolution order:</strong> explicit NPC voice, matching race and gender voice, then the connector's fallback voice. Leave a field blank to skip the race fallback for that combination.</p>
            <form method="post" class="fallback-voice-form"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="action" value="fallback_save"><input type="hidden" name="studio_tab" value="fallbacks">
            <div class="fallback-voice-grid" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.tts-studio.fallbacks')['description']); ?>">
                <?php foreach ($morrowindRaces as $raceId => $raceLabel): ?>
                    <section class="fallback-race-card">
                        <h2><?php echo lorkhan_ui_h($raceLabel); ?></h2><div class="fallback-race-key"><?php echo lorkhan_ui_h($raceId); ?></div>
                        <div class="fallback-gender-grid"><?php foreach (['male'=>'Male','female'=>'Female'] as $gender=>$genderLabel): ?><div><label for="fallback-<?php echo $raceId.'-'.$gender; ?>"><?php echo $genderLabel; ?></label><input id="fallback-<?php echo $raceId.'-'.$gender; ?>" name="fallbacks[<?php echo $raceId; ?>][<?php echo $gender; ?>]" type="text" maxlength="512" value="<?php echo lorkhan_ui_h($fallbackVoices[$raceId][$gender] ?? ''); ?>" list="tts-fallback-voiceids" autocomplete="off" spellcheck="false" placeholder="Use connector fallback" aria-label="<?php echo lorkhan_ui_h($raceLabel.' '.strtolower($genderLabel).' fallback voice'); ?>"></div><?php endforeach; ?></div>
                    </section>
                <?php endforeach; ?>
            </div>
            <datalist id="tts-fallback-voiceids"><?php foreach($fallbackVoiceIds as$voiceId): ?><option value="<?php echo lorkhan_ui_h($voiceId); ?>"></option><?php endforeach; ?></datalist>
            <div class="button-group"><button type="submit" class="btn-primary">Save Fallback Voices</button></div></form>
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
        $canBrowse = is_array($selectedProvider) && in_array($selectedProviderDriver, $voiceDiscoveryDrivers, true) && lorkhan_voice_can_sync($selectedProvider);
        $canSync = is_array($selectedProvider) && in_array($selectedProviderDriver, $sampleUploadDrivers, true) && lorkhan_voice_can_sync($selectedProvider);
        $cloudClone = in_array($activeTab, ['cartesia', 'inworld'], true);
        $localOnly=$selectedProviderDriver==='pockettts'&&!$canSync;
        $pocketModeLabel = 'Not configured';
        if (is_array($selectedProvider)) $pocketModeLabel = $localOnly ? 'audio.cpp' : 'Standard API';
        $primaryRefresh = !$cloudClone && $canBrowse;
        $omniLanguages=[];
        if($activeTab==='omnivoice'){
            if($requestedLanguage==='')$discoverLanguage=lorkhan_voice_language((string)($selectedProviderContent['language']??'en'));
            if(is_array($selectedProvider)){
                try{
                    $libraries=lorkhan_voice_fetch_json((string)$selectedProviderContent['endpoint'],'/voice_libraries');
                    foreach(array_slice($libraries,0,128)as$library){
                        if(!is_array($library))continue;
                        try{$id=lorkhan_voice_language((string)($library['id']??''));}catch(InvalidArgumentException){continue;}
                        $label=trim((string)($library['name']??$library['display_name']??strtoupper($id)));
                        $omniLanguages[$id]=mb_substr($label!==''?$label:strtoupper($id),0,128);
                    }
                }catch(Throwable){/* Offline services still allow the configured/current language to be selected. */}
            }
            $omniLanguages[$discoverLanguage]??=strtoupper($discoverLanguage);
            if(is_array($selectedProvider)){
                try{
                    $discoveredVoices=lorkhan_voice_discover($selectedProvider,$discoverLanguage,$cloudLibrary);
                    $products->replaceConnectorVoiceCatalog($selectedProviderId,$discoveredVoices,gmdate('Y-m-d\TH:i:s\Z'));
                    $discoveredPreset=$selectedProvider;$catalogLoaded=true;
                }catch(Throwable){
                    $discoveredVoices=array_values(array_filter($discoveredVoices,static fn(array $row):bool=>($row['language']??'en')===$discoverLanguage));
                }
            }
        }
        $syncedSamples=[];$syncedSampleIds=[];$voiceStates=[];$deletableOmniVoices=[];$displaySamples=$samples;
        $localNames=[];foreach($samples as$sample)$localNames[mb_strtolower($sample['name'])]=true;
        if($selectedProviderId!=='')foreach($products->connectorVoiceCatalog($selectedProviderId)as$knownVoice){
            if($activeTab==='omnivoice'&&($knownVoice['language']??'en')!==$discoverLanguage)continue;
            $idKey=mb_strtolower($knownVoice['id']);$displayKey=mb_strtolower($knownVoice['display']);
            $voiceStates[$idKey]=$voiceStates[$displayKey]=$knownVoice['status'];
            if($activeTab==='omnivoice'&&$knownVoice['custom'])$deletableOmniVoices[$idKey]=$deletableOmniVoices[$displayKey]=true;
            $syncedSampleIds[$idKey]=$syncedSampleIds[$displayKey]=$knownVoice['id'];
            if($activeTab!=='omnivoice'||lorkhan_voice_omnivoice_ready($knownVoice))$syncedSamples[$idKey]=$syncedSamples[$displayKey]=true;
            if($activeTab==='omnivoice'&&!isset($localNames[$idKey])&&!isset($localNames[$displayKey]))$displaySamples[]=['name'=>$knownVoice['id']];
        }
        usort($displaySamples,static fn(array $a,array $b):int=>strnatcasecmp($a['name'],$b['name']));
        $managedSamples=[];
        if($cloudClone&&is_array($selectedProvider)){
            try{
                $cacheResolver=lorkhan_voice_cloud_resolver($selectedProvider,$cloudLibrary,$config);
                foreach($samples as$sample){
                    $cachedVoice=$cacheResolver->cachedVoice($sample['name']);if($cachedVoice===[])continue;
                    $key=mb_strtolower($sample['name']);$syncedSamples[$key]=true;$syncedSampleIds[$key]=$cachedVoice['id'];
                    if($cachedVoice['managed'])$managedSamples[$key]=true;
                }
            }catch(Throwable){/* Missing credentials must not prevent browsing local samples. */}
        }
        $missingCount=count(array_filter($samples,static fn(array$sample):bool=>!isset($syncedSamples[mb_strtolower($sample['name'])])));
        $batchVerb=$cloudClone?'Generate':($activeTab==='omnivoice'?'Import':'Upload');
        $batchDelayMs=match($activeTab){'inworld'=>3000,'cartesia'=>2000,default=>0};
        $catalogMatchesTab = is_array($discoveredPreset) && in_array((string) ($discoveredPreset['content']['driver'] ?? ''), $tab['drivers'], true);
    ?>
        <?php if($activeTab==='omnivoice'): ?><section class="content-section">
            <h1>OmniVoice Language Library</h1>
            <p>Choose the language library that uploads, sync, and tests should use.</p>
            <form method="get" action="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/voice_library.php" class="voice-language-form">
                <input type="hidden" name="tab" value="omnivoice"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selectedProviderId); ?>">
                <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <label for="omnivoice-language">Language library:</label>
                <select id="omnivoice-language" name="language" data-voice-language><?php foreach($omniLanguages as$id=>$label): ?><option value="<?php echo lorkhan_ui_h($id); ?>"<?php echo $id===$discoverLanguage?' selected':''; ?>><?php echo lorkhan_ui_h($label.' ('.$id.')'); ?></option><?php endforeach; ?></select>
                <noscript><button type="submit">Select language</button></noscript>
            </form>
        </section><?php endif; ?>
        <section class="content-section">
            <h1>Voice Sample Upload</h1>
            <?php if($activeTab==='omnivoice'): ?><p>Upload WAV samples into the selected OmniVoice language library. Reference text is generated automatically by local STT.</p><?php else: ?><p>Upload voice samples to LORKHAN's persistent voice library. <?php if($cloudClone): ?>Files will be available for generating voices in <?php echo lorkhan_ui_h($providerLabel); ?>.<?php elseif ($localOnly): ?>PocketTTS audio.cpp uses these local samples directly; no server synchronization is needed.<?php else: ?>Files will be available to sync with <?php echo lorkhan_ui_h($providerLabel); ?>.<?php endif; ?></p><?php endif; ?>
            <?php if ($activeTab === 'pockettts' && is_array($selectedProvider)): ?><p class="voice-mode-note"><strong>Detected PocketTTS Mode:</strong> <?php echo lorkhan_ui_h($pocketModeLabel); ?>.</p><?php endif; ?>
            <span class="visually-hidden">Add WAV voice samples</span>
            <form class="voice-upload-form" method="post" enctype="multipart/form-data" action="<?php echo lorkhan_ui_h($tabUrl($activeTab)); ?>">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="studio_tab" value="<?php echo lorkhan_ui_h($activeTab); ?>">
                <div class="voice-upload-field"><label for="voice-sample"><?php echo $activeTab==='omnivoice'?'Select .wav file(s) or .zip archive to import:':'Select .wav file(s) or .zip archive to upload:'; ?></label><input id="voice-sample" name="voice_sample[]" type="file" accept="audio/wav,.wav,application/zip,.zip" multiple required></div><?php if($activeTab!=='omnivoice'): ?><details class="voice-upload-name"><summary>Custom voice name (optional)</summary><label for="voice-name">Voice name for a single WAV</label><input type="text" id="voice-name" name="voice_name" maxlength="80" placeholder="Leave blank to use the filename"><p>Leave this blank when selecting multiple files.</p></details><?php endif; ?>
                <?php if($activeTab==='omnivoice'): ?><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selectedProviderId); ?>"><input type="hidden" name="language" value="<?php echo lorkhan_ui_h($discoverLanguage); ?>"><?php endif; ?>
                <input type="hidden" name="upload_count" value="">
                <div class="button-group"><button class="btn-primary" type="submit"><?php echo $activeTab==='omnivoice'?'Import Voice Sample':'Submit'; ?></button></div>
            </form>
            <?php if($activeTab==='omnivoice'): ?><div class="requirements"><p><strong>File Requirements:</strong></p><ul style="margin:0;padding-left:20px"><li>Format: WAV voice reference sample</li><li>Filename becomes the VoiceID, such as <code>femalenord.wav</code></li><li>No transcript file is required; local STT creates the reference text</li></ul></div><?php else: ?>
            <div class="requirements"><p><strong>📋 File Requirements:</strong></p><ul><li>PCM-compatible RIFF/WAVE, up to 16 MiB per sample</li><li>Voice names use letters, numbers, spaces, underscores, plus, dot, or hyphen</li><li>A flat ZIP batch or multi-file selection may contain up to 64 WAV files and 128 MiB extracted, within the server upload limits</li><li>The whole selection is checked before saving. Existing samples are never overwritten.</li><li>Files remain outside profile JSON and browser cookies</li></ul></div><?php endif; ?>
        </section>

        <section class="content-section">
            <h1><?php echo lorkhan_ui_h($providerLabel); ?> Voice <?php echo $activeTab==='omnivoice'?'Library':'Cache'; ?></h1>
            <span class="visually-hidden">Voice Library</span>
            <?php if($activeTab==='omnivoice'): ?><p>Manage voices for the selected language library: <code><?php echo lorkhan_ui_h($discoverLanguage); ?></code>.</p><?php elseif($activeTab === 'pockettts'): ?><p>Manage voice samples for PocketTTS. Current mode: <strong><?php echo lorkhan_ui_h($pocketModeLabel); ?></strong>.</p><?php else: ?><p>Manage voice <?php echo $cloudClone?'generation':'synchronization'; ?> for <?php echo lorkhan_ui_h($providerLabel); ?> from the persistent local WAV library.</p><?php endif; ?>
            <?php if($cloudClone&&!is_array($selectedProvider)): ?><div class="voice-warning"><strong>⚠️ No <?php echo lorkhan_ui_h($providerLabel); ?> connector is configured</strong><p><a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/tts_connectors.php">Configure a TTS connector</a> before discovering or generating provider voices.</p></div><?php endif; ?>
            <?php if($cloudClone): ?><div class="voice-info"><strong>ℹ️ Automatic Voice Generation</strong><p>Voices are generated from local samples when needed for dialogue. You do not need to sync every voice before playing.</p></div><?php endif; ?>
            <?php if($primaryRefresh): ?><div class="button-group voice-cache-refresh"><button class="btn-primary" type="submit" form="voice-discovery-form">Refresh <?php echo lorkhan_ui_h($providerLabel); ?><?php echo in_array($activeTab, ['xtts', 'chatterbox'], true) ? ' Server' : ''; ?> Voices</button></div><?php endif; ?>
            <details class="voice-provider-library"><summary>Provider Voice Browser &amp; Connector Settings</summary>
            <p><?php echo $activeTab==='omnivoice'?'The selected language library and speaker list are read from OmniVoice. Use Refresh above to check again.':"Explicitly query the selected connector's speaker library. Opening TTS Studio never contacts a provider automatically."; ?></p>
            <?php if (is_array($selectedProvider)): ?><p><strong>Current connector default:</strong> <?php echo lorkhan_ui_h((string)($selectedProviderContent['voice'] ?? 'Connector default')); ?> <span class="status-badge"><?php echo lorkhan_ui_h((string)($selectedProviderContent['language'] ?? 'en')); ?></span></p><?php endif; ?>
            <?php if ($canBrowse): ?>
                <form id="voice-discovery-form" method="post" action="<?php echo lorkhan_ui_h($tabUrl($activeTab)); ?>">
                    <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="action" value="discover"><input type="hidden" name="studio_tab" value="<?php echo lorkhan_ui_h($activeTab); ?>">
                    <div class="field-row"><div><label for="voice-discovery-connector">TTS connector</label><select id="voice-discovery-connector" name="configuration_id"><?php foreach ($providerPresets as $preset): ?><option value="<?php echo lorkhan_ui_h($preset['configuration_id'] ?? ''); ?>"<?php echo $selectedProviderId === ($preset['configuration_id'] ?? '') ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($preset['name'] ?? $providerLabel); ?></option><?php endforeach; ?></select></div><div><?php if($activeTab!=='omnivoice'): ?><label for="voice-discovery-language">Language</label><?php endif; ?><input type="<?php echo $activeTab==='omnivoice'?'hidden':'text'; ?>" id="voice-discovery-language" name="language" value="<?php echo lorkhan_ui_h($discoverLanguage); ?>" maxlength="12"></div></div>
                    <?php if(!$primaryRefresh): ?><div class="button-group"><button class="btn-primary" type="submit">Refresh <?php echo lorkhan_ui_h($providerLabel); ?> Server Voices</button></div><?php endif; ?>
                </form>
            <?php elseif ($cloudClone): ?>
                <p>Choose or configure a connector to discover voices and upload samples.</p><div class="button-group"><span class="feature-control"><a class="btn-primary" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/tts_connectors.php">Configure TTS connector</a><?php echo lorkhan_ui_feature_badge('config.tts-studio.cloud-cloning', true); ?></span></div>
            <?php else: ?>
                <p>No compatible <?php echo lorkhan_ui_h($providerLabel); ?> connector is configured.</p><div class="button-group"><button type="button" class="btn-primary feature-placeholder-control" disabled aria-disabled="true">Refresh <?php echo lorkhan_ui_h($providerLabel); ?> Server Voices</button></div>
            <?php endif; ?>

            <?php if ($catalogMatchesTab && $catalogLoaded && $activeTab!=='omnivoice'): ?>
                <?php if ($discoveredVoices === []): ?><p>The provider returned no voices for this language.</p><?php else: ?><div class="voice-status-grid"><?php foreach ($discoveredVoices as $item): ?><article class="voice-status-item"><span class="voice-name"><?php echo lorkhan_ui_h($item['display']); ?></span><span class="status-icon synced">✓</span><div class="voice-actions"><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/connector-test"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="kind" value="tts_provider"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($discoveredPreset['configuration_id'] ?? ''); ?>"><input type="hidden" name="voice_id" value="<?php echo lorkhan_ui_h($item['id']); ?>"><input type="hidden" name="language" value="<?php echo lorkhan_ui_h($discoverLanguage); ?>"><button type="submit" title="Test voice">▶</button></form><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/connector-default-voice"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($discoveredPreset['configuration_id'] ?? ''); ?>"><input type="hidden" name="voice_id" value="<?php echo lorkhan_ui_h($item['id']); ?>"><input type="hidden" name="language" value="<?php echo lorkhan_ui_h($item['language']); ?>"><button class="btn-primary" type="submit" title="Set connector default">✓</button></form></div></article><?php endforeach; ?></div><?php endif; ?>
            <?php endif; ?>
            </details>
            <p class="voice-cache-caption"><?php echo count($samples); ?> local voice samples<?php if(is_array($selectedProvider)): ?> · <?php echo lorkhan_ui_h($selectedProvider['name']); ?><?php endif; ?><?php if($activeTab==='omnivoice'): ?> · <?php echo lorkhan_ui_h($discoverLanguage); ?><?php endif; ?></p>
            <p class="voice-copy-status" data-voice-copy-status role="status" hidden></p>
            <?php if($cloudClone&&$canSync&&count($samples)>0): ?><label class="voice-cloud-consent"><input type="checkbox" data-voice-cloud-consent> Allow selected samples to be uploaded to <?php echo lorkhan_ui_h($providerLabel); ?> for cloning (provider charges may apply).</label><noscript><p>JavaScript is needed for individual cloud uploads. The confirmed batch form below also works without it.</p></noscript><?php endif; ?>
            <?php if ($displaySamples === []): ?><p><?php echo $activeTab==='omnivoice'?'No OmniVoice voices or local voice files exist.':"No voice files found in LORKHAN's persistent voice library. Upload voice samples above first."; ?></p><?php else: ?><div class="voice-status-grid">
                <?php foreach ($displaySamples as $sample): $hasLocalSample=isset($localNames[mb_strtolower($sample['name'])]); $references = lorkhan_voice_references($sample['name'], $voiceReferenceIndex); $isSynced=isset($syncedSamples[mb_strtolower($sample['name'])]); ?>
                    <?php $remoteId=$syncedSampleIds[mb_strtolower($sample['name'])]??''; ?>
                    <article class="voice-status-item<?php echo $activeTab==='omnivoice'?' omnivoice-status-item':''; ?>">
                        <button type="button" class="voice-name voice-copy" data-copy-voice="<?php echo lorkhan_ui_h($sample['name']); ?>" title="Copy voice name: <?php echo lorkhan_ui_h($sample['name']); ?>"><?php echo lorkhan_ui_h($sample['name']); ?></button>
                        <span class="status-icon <?php echo $isSynced||$localOnly?'synced':(is_array($selectedProvider)?'unsynced':'unknown'); ?>" title="<?php echo $isSynced?'Cached provider voice':($localOnly?'Available as a local sample':(is_array($selectedProvider)?'Not in the cached provider library':'No connector configured')); ?>"><?php echo $isSynced||$localOnly?'✓':(is_array($selectedProvider)?'✗':'—'); ?></span>
                        <?php if($activeTab==='omnivoice'&&(!$hasLocalSample||!$isSynced)): ?><span class="voice-id" title="<?php echo lorkhan_ui_h($voiceStates[mb_strtolower($sample['name'])]??'Local sample can be imported'); ?>"><?php echo $isSynced?'server':($hasLocalSample?'local':'needs text'); ?></span><?php endif; ?>
                        <?php if($cloudClone&&$remoteId!==''): ?><span class="voice-id" title="<?php echo lorkhan_ui_h($remoteId); ?>"><?php echo lorkhan_ui_h(substr($remoteId,0,15).(strlen($remoteId)>15?'...':'')); ?></span><?php endif; ?>
                        <div class="voice-actions">
                        <?php if (is_array($selectedProvider)&&($activeTab!=='omnivoice'||$isSynced)): ?><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/connector-test"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="kind" value="tts_provider"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selectedProviderId); ?>"><input type="hidden" name="voice_id" value="<?php echo lorkhan_ui_h($syncedSampleIds[mb_strtolower($sample['name'])]??$sample['name']); ?>"><input type="hidden" name="language" value="<?php echo lorkhan_ui_h($discoverLanguage); ?>"><button type="submit" title="Test voice">▶</button></form><?php endif; ?>
                        <?php if ($cloudClone&&$isSynced&&is_array($selectedProvider)): ?><form method="post" action="<?php echo lorkhan_ui_h($tabUrl($activeTab)); ?>" data-confirm="Forget this cached voice ID? The local sample and remote voice will not be deleted."><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="action" value="unsync"><input type="hidden" name="studio_tab" value="<?php echo lorkhan_ui_h($activeTab); ?>"><input type="hidden" name="voice_name" value="<?php echo lorkhan_ui_h($sample['name']); ?>"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selectedProviderId); ?>"><button class="btn-danger" type="submit" title="Forget cached voice ID" aria-label="Forget cached voice ID for <?php echo lorkhan_ui_h($sample['name']); ?>">×</button></form><?php endif; ?>
                        <?php if ($canSync&&$hasLocalSample&&($activeTab!=='omnivoice'||!$isSynced)): ?><form method="post" action="<?php echo lorkhan_ui_h($tabUrl($activeTab)); ?>"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="studio_tab" value="<?php echo lorkhan_ui_h($activeTab); ?>"><input type="hidden" name="voice_name" value="<?php echo lorkhan_ui_h($sample['name']); ?>"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selectedProviderId); ?>"><input type="hidden" name="language" value="<?php echo lorkhan_ui_h($discoverLanguage); ?>"><?php if($cloudClone): ?><input type="hidden" name="consent" value="0" data-voice-upload-consent><?php endif; ?><button class="btn-primary" type="submit"<?php echo $cloudClone?' disabled':''; ?> title="<?php echo $cloudClone?($isSynced?'Regenerate this voice':'Clone this voice'):'Sync this voice'; ?>">↻</button></form><?php endif; ?>
                        <?php if(isset($managedSamples[mb_strtolower($sample['name'])])): ?><form method="post" action="<?php echo lorkhan_ui_h($tabUrl($activeTab)); ?>" data-confirm="Delete this Lorkhan-managed remote voice? The local WAV will be kept. Using this sample again can create a new clone."><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="action" value="delete_managed"><input type="hidden" name="studio_tab" value="<?php echo lorkhan_ui_h($activeTab); ?>"><input type="hidden" name="voice_name" value="<?php echo lorkhan_ui_h($sample['name']); ?>"><input type="hidden" name="voice_id" value="<?php echo lorkhan_ui_h($remoteId); ?>"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selectedProviderId); ?>"><button class="btn-danger" type="submit" title="Delete managed remote voice" aria-label="Delete managed remote voice for <?php echo lorkhan_ui_h($sample['name']); ?>">🗑</button></form><?php endif; ?>
                        <?php if($activeTab==='omnivoice'&&isset($deletableOmniVoices[mb_strtolower($sample['name'])])): ?><form method="post" action="<?php echo lorkhan_ui_h($tabUrl($activeTab)); ?>" data-confirm="Remove this custom voice from OmniVoice? The local WAV will be kept for re-upload."><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="action" value="delete_provider"><input type="hidden" name="studio_tab" value="omnivoice"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selectedProviderId); ?>"><input type="hidden" name="language" value="<?php echo lorkhan_ui_h($discoverLanguage); ?>"><input type="hidden" name="voice_id" value="<?php echo lorkhan_ui_h($remoteId); ?>"><button class="btn-danger" type="submit" title="Remove custom voice from OmniVoice">×</button></form><?php endif; ?>
                        <?php if ($references === []&&$activeTab!=='omnivoice'&&!$cloudClone&&$hasLocalSample): ?><form method="post" action="<?php echo lorkhan_ui_h($tabUrl($activeTab)); ?>" data-confirm="Delete this local voice sample?"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="studio_tab" value="<?php echo lorkhan_ui_h($activeTab); ?>"><input type="hidden" name="voice_name" value="<?php echo lorkhan_ui_h($sample['name']); ?>"><button class="btn-danger" type="submit" title="Delete local sample">×</button></form><?php endif; ?>
                    </div></article>
                <?php endforeach; ?>
            </div><?php endif; ?>
        </section>

        <section class="content-section">
            <h1>Batch <?php echo $cloudClone?'Generate':($activeTab==='omnivoice'?'Import':'Process'); ?> Missing Voices</h1>
            <p><?php if ($activeTab === 'pockettts'): ?>Make missing local voices available to PocketTTS.<?php else: ?><?php echo $batchVerb; ?> missing local samples to <?php echo lorkhan_ui_h($providerLabel); ?>. Existing provider voices are skipped.<?php endif; ?></p>
            <?php if($canSync&&$missingCount>0): ?><p class="voice-missing-count">Found <?php echo $missingCount; ?> voice(s) not yet <?php echo $cloudClone?'generated':'synced'; ?> in the cached provider library.</p>
            <?php if($batchDelayMs>0&&$missingCount>1): ?><p class="voice-batch-estimate">Estimated time: <?php echo (int)(($missingCount-1)*$batchDelayMs/1000); ?> seconds of request spacing, plus provider processing. Browser batches wait <?php echo $batchDelayMs/1000; ?> seconds between voices.</p><?php endif; ?>
            <form method="post" action="<?php echo lorkhan_ui_h($tabUrl($activeTab)); ?>" data-voice-batch data-voice-batch-delay="<?php echo $batchDelayMs; ?>">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="action" value="batch_sync"><input type="hidden" name="studio_tab" value="<?php echo lorkhan_ui_h($activeTab); ?>"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selectedProviderId); ?>"><input type="hidden" name="language" value="<?php echo lorkhan_ui_h($discoverLanguage); ?>">
                <label><input type="checkbox" name="consent" value="1" required> Upload these samples to the selected provider<?php echo $cloudClone?' and create cloud voices (provider charges may apply)':''; ?>.</label>
                <div class="button-group"><button class="btn-primary" type="submit">Batch <?php echo $batchVerb; ?> Missing Voices (<?php echo $missingCount; ?>)</button><button class="btn-danger" type="button" data-voice-batch-stop hidden title="Stop after the current voice finishes">Cancel</button></div>
                <div class="voice-batch-progress" data-voice-batch-progress hidden>
                    <div class="voice-batch-count"><strong>Progress: <span data-voice-batch-current>0</span> / <span data-voice-batch-total>0</span></strong><span data-voice-batch-eta></span></div>
                    <div class="voice-batch-track" role="progressbar" aria-label="Voice batch progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div data-voice-batch-bar></div></div>
                    <div class="voice-batch-log" data-voice-batch-log role="log" aria-label="Voice batch results" aria-live="polite"></div>
                    <p role="status" data-voice-batch-status></p><a href="<?php echo lorkhan_ui_h($tabUrl($activeTab)); ?>" data-voice-batch-refresh hidden>Refresh voice cache</a>
                </div>
            </form>
            <?php elseif($localOnly): ?><p class="voice-ready">✓ Local samples are ready for PocketTTS audio.cpp; no server upload is needed.</p>
            <?php elseif(!$canSync): ?><p>Configure a compatible <?php echo lorkhan_ui_h($providerLabel); ?> connector to upload voices.</p><a class="btn-primary" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/tts_connectors.php">Configure TTS connector</a>
            <?php else: ?><p class="voice-ready">✓ No missing voices in the cached library. Refresh Server Voices above to check the provider.</p><?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/voice-batch.js?v=<?php echo (int)filemtime($uiRootDir.'/js/voice-batch.js'); ?>" defer></script>
<?php if ($activeTab === 'pronunciations'): ?><script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/pronunciation-preview.js?v=<?php echo (int) @filemtime($uiRootDir . '/js/pronunciation-preview.js'); ?>" defer></script><?php endif; ?>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/voice-preview.js?v=<?php echo (int)filemtime($uiRootDir.'/js/voice-preview.js'); ?>"></script><?php include $uiRootDir . '/tmpl/footer.html'; ?>
