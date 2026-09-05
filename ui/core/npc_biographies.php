<?php

declare(strict_types=1);

$pageTitle = 'NPC Biographies';
$topNavSection = 'configuration';
$embedded = (string) ($_GET['embed'] ?? '') === '1';
$BODY_CLASS = 'hub-page biography-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

if (isset($_GET['template'])) {
    $template = $uiRepository->biographyTemplate((string) $_GET['template']);
    if ($template === null) {
        http_response_code(404);
        $template = ['error' => 'biography_template_not_found'];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($template, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = $uiRepository->rows('npc_biographies');

$installations = $uiRepository->rows('installations');
$requestedInstallation = (string) (is_string($_GET['installation_id'] ?? null) ? $_GET['installation_id'] : '');
$installationId = (string) ($installations[0]['installation_id'] ?? '');
foreach ($installations as $installation) {
    if ($requestedInstallation !== '' && (string) $installation['installation_id'] === $requestedInstallation) $installationId = $requestedInstallation;
}

/** Normalize typed profile tag values for the copied Oghma Tags column. */
function lorkhan_biography_tags(array $content): array
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
    <div class="page-header lorkhan-page-head">
        <h1>NPC Biography Management</h1>
        <p class="page-subtitle lorkhan-page-head-note">Review the installed biography templates used to initialize AI NPC profiles</p>
    </div>
    <p class="lorkhan-status" id="biography-load-error" role="alert" hidden></p>

    <?php if (isset($_GET['status'])):
        $importCount = (int) (is_string($_GET['count'] ?? null) ? $_GET['count'] : 0);
        $statusText = (is_string($_GET['status']) ? $_GET['status'] : '') === 'imported'
            ? $importCount . ' biography ' . ($importCount === 1 ? 'template' : 'templates') . ' imported.'
            : 'Biography saved.';
    ?><div class="lorkhan-status" role="status"><?php echo lorkhan_ui_h($statusText); ?></div><?php endif; ?>

    <section class="content-section">
        <h1>Batch Upload</h1>
        <h3><strong>Please use stable OpenMW record IDs instead of display names.</strong></h3>
        <h4>Example: Fargoth uses the record ID <code>fargoth</code>.</h4>

        <?php if ($installations === []): ?>
            <p class="no-data">Connect OpenMW once before importing installation-scoped biographies.</p>
            <div class="button-group">
                <a class="action-button download-csv" href="<?php echo lorkhan_ui_h($managementBasePath . '/exports/biographies/example.csv'); ?>">Download Example CSV</a>
            </div>
        <?php else: ?>
            <?php if (count($installations) > 1): ?>
                <form class="biography-installation" method="get" action="<?php echo lorkhan_ui_h($webRoot . '/ui/core/npc_biographies.php'); ?>">
                    <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <div class="biography-field">
                        <label for="biography-installation">Installation</label>
                        <select id="biography-installation" name="installation_id">
                            <?php foreach ($installations as $installation): ?>
                                <option value="<?php echo lorkhan_ui_h($installation['installation_id']); ?>"<?php echo (string) $installation['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h(trim((string) ($installation['display_name'] ?? '')) !== '' ? $installation['display_name'] : $installation['installation_id']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="action-button">Switch</button>
                </form>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" action="<?php echo lorkhan_ui_h($managementBasePath . '/forms/biography-import'); ?>">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="embed" value="<?php echo $embedded ? '1' : '0'; ?>">
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                <div class="biography-field">
                    <label for="biography-csv-file">Select .csv file to upload:</label>
                    <input type="file" name="csv_file" id="biography-csv-file" accept=".csv,text/csv" required aria-describedby="biography-import-help">
                </div>
                <div class="button-group">
                    <button type="submit" class="action-button upload-csv">Upload CSV</button>
                    <a class="action-button download-csv" href="<?php echo lorkhan_ui_h($managementBasePath . '/exports/biographies/example.csv'); ?>">Download Example CSV</a>
                    <a class="action-button export-csv" href="<?php echo lorkhan_ui_h($managementBasePath . '/exports/biographies/custom.csv?' . http_build_query(['installation_id' => $installationId])); ?>">Export Biography Templates</a>
                </div>
            </form>
            <p id="biography-import-help">Each row is one reusable biography template for the selected installation, identified by <code>content_file</code> and <code>record_id</code>.</p>

            <details class="biography-tips">
                <summary>CSV format and what an import changes</summary>
                <ul>
                    <li><code>content_file</code> and <code>record_id</code> are the identity. Display names are never used as keys.</li>
                    <li>A row creates a template or revises the matching custom one. NPCs already living in your game keep their own profiles.</li>
                    <li>The whole file is validated and size-bounded first, so an import either applies completely or changes nothing.</li>
                    <li><code>relationships</code> must be a JSON object such as <code>{"Player":{"aff":25,"type":"professional"}}</code>, or left empty.</li>
                    <li>For a single NPC, LORKHAN NPCs still exports and imports the complete profile as JSON.</li>
                </ul>
            </details>
        <?php endif; ?>

    </section>

    <details class="content-section" id="create-biography">
        <summary>Add New Entry</summary>
        <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/biography-template-create">
            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
            <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
            <input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
            <?php foreach(['content_file'=>'Content file (for example Morrowind.esm)','record_id'=>'NPC record ID','name'=>'NPC name','core'=>'Core biography','biography'=>'Extended biography','oghma_tags'=>'Oghma tags']as$field=>$label): ?>
            <div class="biography-field"><label for="new-bio-<?php echo $field; ?>"><?php echo lorkhan_ui_h($label); ?></label>
            <?php if(in_array($field,['core','biography'],true)): ?><textarea id="new-bio-<?php echo $field; ?>" name="<?php echo $field; ?>" maxlength="16384" <?php echo $field==='core'?'required':''; ?>></textarea>
            <?php else: ?><input id="new-bio-<?php echo $field; ?>" name="<?php echo $field; ?>" maxlength="<?php echo $field==='oghma_tags'?4096:256; ?>" <?php echo $field!=='oghma_tags'?'required':''; ?>><?php endif; ?></div>
            <?php endforeach; ?>
            <button class="action-button" type="submit">Save Entry</button>
        </form>
    </details>
    <section class="database-section" id="table">
        <h1>NPC Bio Templates Database</h1>
        <div class="action-container">
            <a class="action-button add-new" href="#create-biography">Add New Entry</a>
            <div class="search-container">
                <label class="visually-hidden" for="biography-search">Search NPC names</label>
                <input type="text" id="biography-search" placeholder="Search NPC names...">
                <button type="button" class="action-button edit" id="biography-search-button">Search</button>
            </div>
        </div>
        <h3 class="database-note">Note: This page provides focused biography edits. Use LORKHAN NPCs for identity, routing, Core Profile inheritance and all other NPC settings.</h3>
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
                    $source = (string) ($row['source'] ?? 'factory');
                    $extendedFields = ['biography', 'appearance', 'personality', 'relationships', 'occupation', 'skills', 'speech_style', 'goals'];
                    $extendedCount = count(array_filter($extendedFields, static fn(string $field): bool => trim((string) ($content[$field] ?? '')) !== ''));
                    $tags = lorkhan_biography_tags($content);
                    $biography = trim((string) ($content['biography'] ?? ''));
                    $biographySummary = strlen($biography) > 200 ? substr($biography, 0, 200) . '...' : $biography;
                    $searchName = strtolower(trim((string) ($row['name'] ?? '')));
                ?>
                    <tr data-biography-row data-search-name="<?php echo lorkhan_ui_h($searchName); ?>">
                        <td><?php echo lorkhan_ui_h($row['name']); ?></td>
                        <td><?php echo nl2br(lorkhan_ui_h($biographySummary)); ?></td>
                        <td><button type="button" class="profile-details-button" data-biography-details data-template-name="<?php echo lorkhan_ui_h($row['name']); ?>"><span class="profile-progress"><?php echo $extendedCount; ?> of <?php echo count($extendedFields); ?> fields completed</span><span class="profile-details">Click to view details</span></button></td>
                        <td class="voice-meta">
                            <div><strong>VoiceID:</strong> <?php echo trim((string) ($voice['id'] ?? '')) !== '' ? lorkhan_ui_h($voice['id']) : '<span class="automatic">Automatic</span>'; ?></div>
                            <div><strong>Gender:</strong> <?php echo trim((string) ($content['gender'] ?? $identity['gender'] ?? '')) !== '' ? lorkhan_ui_h($content['gender'] ?? $identity['gender']) : '<span class="automatic">Automatic</span>'; ?></div>
                            <div><strong>Race:</strong> <?php echo trim((string) ($content['race'] ?? $identity['race'] ?? '')) !== '' ? lorkhan_ui_h($content['race'] ?? $identity['race']) : '<span class="automatic">Automatic</span>'; ?></div>
                            <div><strong>RefID:</strong> <?php echo trim((string) ($identity['record_id'] ?? '')) !== '' ? lorkhan_ui_h($identity['record_id']) : '<span class="automatic">Automatic</span>'; ?></div>
                        </td>
                        <td><?php if ($tags !== []): foreach ($tags as $tag): ?><span class="oghma-tag"><?php echo lorkhan_ui_h($tag); ?></span><?php endforeach; else: ?><span class="automatic">None</span><?php endif; ?></td>
                        <td><div class="row-actions">
                            <button type="button" class="action-button edit" data-biography-edit data-template-name="<?php echo lorkhan_ui_h($row['name']); ?>">Edit</button>
                            <a class="action-button" href="<?php echo lorkhan_ui_h($webRoot.'/ui/worldknowledge_upload.php?'.http_build_query(['installation_id'=>$installationId,'search'=>$tags[0]??$row['name']])); ?>">Oghma</a>
                            <span class="profile-details"><?php echo $source === 'custom' ? 'Custom template' : 'Factory template'; ?></span>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?><tr><td colspan="6"><div class="no-data">No NPC biography templates are installed.</div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="no-data" id="biography-no-results" hidden>No NPCs found.</p>
    </section>
</main>

<div class="biography-modal" id="biography-edit-modal" aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="biography-modal-title">
        <div class="modal-header"><h2 class="modal-title" id="biography-modal-title">Edit NPC Entry</h2><button type="button" class="modal-close" data-biography-close aria-label="Close">&times;</button></div>
        <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/biography-template-revise">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="npc_name" id="biography-template-name">
                <p id="biography-profile-meta" class="profile-progress"></p>
                <label for="biography-core">Summary</label><textarea id="biography-core" name="core" required maxlength="16384"></textarea>
                <label for="biography-oghma-tags">Oghma Tags</label><input id="biography-oghma-tags" name="oghma_knowledge_tags" maxlength="4096">
                <label for="biography-text">Biography</label><textarea id="biography-text" name="npc_static_bio" maxlength="16384"></textarea>
                <label for="biography-appearance">Appearance</label><textarea id="biography-appearance" name="appearance" maxlength="16384"></textarea>
                <label for="biography-personality">Personality</label><textarea id="biography-personality" name="personality" maxlength="16384"></textarea>
                <label for="biography-relationships">Relationships</label><textarea id="biography-relationships" name="relationships" maxlength="16384"></textarea>
                <small>Use a JSON object such as <code>{"Player":{"aff":25,"type":"professional"}}</code>.</small>
                <label for="biography-occupation">Occupation</label><textarea id="biography-occupation" name="occupation" maxlength="16384"></textarea>
                <label for="biography-skills">Skills</label><textarea id="biography-skills" name="skills" maxlength="16384"></textarea>
                <label for="biography-speechstyle">Speech Style</label><textarea id="biography-speechstyle" name="speechstyle" maxlength="16384"></textarea>
                <label for="biography-goals">Goals</label><textarea id="biography-goals" name="goals" maxlength="16384"></textarea>
                <label for="biography-voiceid">Voice ID</label><input id="biography-voiceid" name="voiceid" maxlength="256">
                <label for="biography-gender">Gender</label><input id="biography-gender" name="gender" maxlength="256">
                <label for="biography-race">Race</label><input id="biography-race" name="race" maxlength="256">
                <label for="biography-refid">Record ID</label><input id="biography-refid" name="refid" maxlength="256" readonly>
            </div>
            <div class="modal-footer"><button type="button" class="action-button" data-biography-close>Cancel</button><button type="submit" class="action-button upload-csv">Save Custom Override</button></div>
        </form>
    </div>
</div>

<div class="biography-modal" id="biography-details-modal" aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="biography-details-title">
        <div class="modal-header"><h2 class="modal-title" id="biography-details-title">Extended Profile</h2><button type="button" class="modal-close" data-biography-details-close aria-label="Close">&times;</button></div>
        <div class="modal-body biography-detail-grid">
            <?php foreach (['npc_static_bio' => 'Static Biography', 'appearance' => 'Appearance', 'personality' => 'Personality', 'relationships' => 'Relationships', 'occupation' => 'Occupation', 'skills' => 'Skills', 'speechstyle' => 'Speech Style', 'goals' => 'Goals'] as $field => $label): ?>
                <section><h3><?php echo $label; ?></h3><div data-biography-detail-field="<?php echo $field; ?>"></div></section>
            <?php endforeach; ?>
        </div>
        <div class="modal-footer"><button type="button" class="action-button" data-biography-details-close>Close</button></div>
    </div>
</div>

<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/biographies.js?v=<?php echo lorkhan_ui_h((string) filemtime(dirname(__DIR__) . '/js/biographies.js')); ?>"></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
