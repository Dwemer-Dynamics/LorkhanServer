<?php

declare(strict_types=1);

$pageTitle = 'NPC Biographies';
$topNavSection = 'configuration';
$embedded = (string) ($_GET['embed'] ?? '') === '1';
$BODY_CLASS = 'hub-page biography-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

$rows = $uiRepository->rows('npc_biographies');

/** Normalize typed profile tag values for the copied Oghma Tags column. */
function almsivi_biography_tags(array $content): array
{
    $value = $content['oghma_tags'] ?? $content['knowledge_tags'] ?? $content['tags'] ?? [];
    if (is_string($value)) $value = preg_split('/\s*,\s*/', trim($value)) ?: [];
    if (!is_array($value)) return [];
    return array_values(array_filter(array_map(static fn(mixed $tag): string => trim((string) $tag), $value)));
}

$additionalStylesheets = ['herika-biographies.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-biographies.css')];
$includeManagementStyles = false;
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<main class="biography-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <div class="page-header">
        <h1>NPC Biography Management</h1>
        <p class="page-subtitle">Create custom character profiles for AI NPCs during roleplay</p>
    </div>

    <?php if (isset($_GET['status'])): ?><div class="almsivi-status" role="status">Biography saved.</div><?php endif; ?>

    <section class="content-section">
        <h1>Batch Upload</h1>
        <h3><strong>Please use stable OpenMW record IDs instead of display names.</strong></h3>
        <h4>Example: Fargoth uses the record ID <code>fargoth</code>.</h4>
        <div class="placeholder-field">
            <label>Select .csv file to upload: <?php echo almsivi_ui_feature_badge('config.biographies.import', true); ?></label>
            <input type="file" accept=".csv" disabled aria-disabled="true">
        </div>
        <div class="button-group">
            <button type="button" class="action-button upload-csv" disabled aria-disabled="true">Upload CSV <?php echo almsivi_ui_feature_badge('config.biographies.import', true); ?></button>
            <button type="button" class="action-button" disabled aria-disabled="true">Download Example CSV <?php echo almsivi_ui_feature_badge('config.biographies.export', true); ?></button>
            <button type="button" class="action-button" disabled aria-disabled="true">Export Custom NPCs <?php echo almsivi_ui_feature_badge('config.biographies.export', true); ?></button>
        </div>
        <p><strong>Relationships column:</strong> ALMSIVI preserves relationship data through its typed actor relationship repository rather than biography CSV prose.</p>
        <p>Imported biographies will eventually resolve against stable <code>content_file</code> and <code>record_id</code> actor identity before creating a profile revision.</p>
        <p>Current biographies are stored inside revisioned PostgreSQL NPC profiles. Identity, routing and inherited Core Profile settings remain untouched when a biography is saved.</p>
        <p><strong>Export Custom NPCs:</strong> Portable typed exports are planned and will include actor identity plus the complete versioned profile document.</p>
        <div class="button-group">
            <button type="button" class="btn-danger" disabled aria-disabled="true">Factory Reset NPC Override Table <?php echo almsivi_ui_feature_badge('config.biographies.reset', true); ?></button>
        </div>
        <p>ALMSIVI does not expose a destructive biography-table reset. Every biography change remains revisioned.</p>
    </section>

    <section class="database-section" id="table">
        <h1>NPC Bio Templates Database</h1>
        <div class="action-container">
            <button type="button" class="action-button add-new" disabled aria-disabled="true">Add New Entry <?php echo almsivi_ui_feature_badge('config.biographies.create', true); ?></button>
            <div class="search-container">
                <label class="visually-hidden" for="biography-search">Search NPC names</label>
                <input type="text" id="biography-search" placeholder="Search NPC names...">
                <button type="button" class="action-button edit" id="biography-search-button">Search</button>
            </div>
        </div>
        <h3 class="database-note">Note: This page provides focused biography edits. Use ALMSIVI NPCs for identity, routing, Core Profile inheritance and all other NPC settings.</h3>
        <p>NPC entries are created from stable OpenMW actor identity and preserve their complete revision history.</p>

        <div class="filter-buttons" aria-label="Filter biographies by first letter">
            <button type="button" class="alphabet-button active" data-biography-letter="">All</button>
            <?php foreach (range('A', 'Z') as $letter): ?><button type="button" class="alphabet-button" data-biography-letter="<?php echo $letter; ?>"><?php echo $letter; ?></button><?php endforeach; ?>
        </div>

        <div class="table-container" id="npc-table-container">
            <table>
                <thead><tr><th>Name</th><th>Summary Bio</th><th>Extended Profiles</th><th>Voice Overrides</th><th>Oghma Tags</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $content = is_array($row['content'] ?? null) ? $row['content'] : [];
                    $identity = is_array($row['actor_identity'] ?? null) ? $row['actor_identity'] : [];
                    $voice = is_array($content['voice'] ?? null) ? $content['voice'] : [];
                    $extendedFields = ['appearance', 'personality', 'relationships', 'occupation', 'skills', 'speech_style', 'goals', 'notes'];
                    $extendedCount = count(array_filter($extendedFields, static fn(string $field): bool => trim((string) ($content[$field] ?? '')) !== ''));
                    $tags = almsivi_biography_tags($content);
                    $biography = trim((string) ($content['biography'] ?? ''));
                    $biographySummary = strlen($biography) > 200 ? substr($biography, 0, 200) . '...' : $biography;
                    $searchName = strtolower(trim((string) ($row['name'] ?? '')));
                ?>
                    <tr data-biography-row data-search-name="<?php echo almsivi_ui_h($searchName); ?>">
                        <td><?php echo almsivi_ui_h($row['name']); ?></td>
                        <td><?php echo nl2br(almsivi_ui_h($biographySummary)); ?></td>
                        <td><span class="profile-progress"><?php echo $extendedCount; ?> of <?php echo count($extendedFields); ?> fields completed</span><br><span class="profile-details">Preserved in typed profile</span></td>
                        <td class="voice-meta">
                            <div><strong>VoiceID:</strong> <?php echo trim((string) ($voice['id'] ?? '')) !== '' ? almsivi_ui_h($voice['id']) : '<span class="automatic">Automatic</span>'; ?></div>
                            <div><strong>Gender:</strong> <?php echo trim((string) ($content['gender'] ?? $identity['gender'] ?? '')) !== '' ? almsivi_ui_h($content['gender'] ?? $identity['gender']) : '<span class="automatic">Automatic</span>'; ?></div>
                            <div><strong>Race:</strong> <?php echo trim((string) ($content['race'] ?? $identity['race'] ?? '')) !== '' ? almsivi_ui_h($content['race'] ?? $identity['race']) : '<span class="automatic">Automatic</span>'; ?></div>
                            <div><strong>RefID:</strong> <?php echo trim((string) ($identity['record_id'] ?? '')) !== '' ? almsivi_ui_h($identity['record_id']) : '<span class="automatic">Automatic</span>'; ?></div>
                        </td>
                        <td><?php if ($tags !== []): foreach ($tags as $tag): ?><span class="oghma-tag"><?php echo almsivi_ui_h($tag); ?></span><?php endforeach; else: ?><span class="automatic">None</span><?php endif; ?></td>
                        <td><div class="row-actions">
                            <button type="button" class="action-button edit" data-biography-edit data-profile-id="<?php echo almsivi_ui_h($row['profile_id']); ?>" data-profile-name="<?php echo almsivi_ui_h($row['name']); ?>" data-biography="<?php echo almsivi_ui_h($biography); ?>" data-base-content="<?php echo almsivi_ui_h(json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>" data-revision="<?php echo almsivi_ui_h($row['current_revision']); ?>">Edit</button>
                            <button type="button" class="action-button" disabled aria-disabled="true">Oghma</button>
                            <?php echo almsivi_ui_feature_badge('config.biographies.oghma', true); ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?><tr><td colspan="6"><div class="no-data">No NPC profiles exist yet. Encounter an NPC in OpenMW or create one from the ALMSIVI NPCs page.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="no-data" id="biography-no-results" hidden>No NPCs found.</p>
    </section>
</main>

<div class="biography-modal" id="biography-edit-modal" aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="biography-modal-title">
        <div class="modal-header"><h2 class="modal-title" id="biography-modal-title">Edit NPC Entry</h2><button type="button" class="modal-close" data-biography-close aria-label="Close">&times;</button></div>
        <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/profile-biography-revise">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                <input type="hidden" name="profile_id" id="biography-profile-id">
                <input type="hidden" name="base_content_json" id="biography-base-content">
                <p id="biography-profile-meta" class="profile-progress"></p>
                <label for="biography-text">Biography</label>
                <textarea id="biography-text" name="biography"></textarea>
                <label for="biography-change-reason">Revision note</label>
                <input id="biography-change-reason" name="change_reason" required maxlength="512" value="Management biography update">
            </div>
            <div class="modal-footer"><button type="button" class="action-button" data-biography-close>Cancel</button><button type="submit" class="action-button upload-csv">Save Biography</button></div>
        </form>
    </div>
</div>

<script src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/biographies.js"></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
