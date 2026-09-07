<?php

declare(strict_types=1);

$pageTitle = 'NPC Biographies';
$topNavSection = 'configuration';
$embedded = (string) ($_GET['embed'] ?? '') === '1';
$BODY_CLASS = 'hub-page biography-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

if (isset($_GET['template']) || isset($_GET['oghma'])) {
    $template = trim((string)($_GET['profile_id']??''))!==''
        ? $uiRepository->installationBiographyTemplate((string)$_GET['profile_id'],(string)($_GET['installation_id']??''))
        : $uiRepository->biographyTemplate((string) ($_GET['template']??$_GET['oghma']));
    if ($template === null) {
        http_response_code(404);
        $template = ['error' => 'biography_template_not_found'];
    }
    if(isset($_GET['oghma']) && !isset($template['error'])){
        try{$template=$productRepository->oghmaKnowledgeForBiography((string)($_GET['installation_id']??''),(string)($template['oghma_knowledge_tags']??''),$_GET);}
        catch(Throwable){http_response_code(404);$template=['error'=>'biography_knowledge_unavailable'];}
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($template, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}



$installations = $uiRepository->rows('installations');
$requestedInstallation = (string) (is_string($_GET['installation_id'] ?? null) ? $_GET['installation_id'] : '');
$installationId = (string) ($installations[0]['installation_id'] ?? '');
foreach ($installations as $installation) {
    if ($requestedInstallation !== '' && (string) $installation['installation_id'] === $requestedInstallation) $installationId = $requestedInstallation;
}

$catalog = $uiRepository->biographyCatalog($installationId,$_GET);
$rows = $catalog['rows'];

/** Normalize typed profile tag values for the copied Oghma Tags column. */
function lorkhan_biography_tags(array $content): array
{
    $value = $content['oghma_knowledge_tags'] ?? $content['oghma_tags'] ?? $content['knowledge_tags'] ?? $content['tags'] ?? [];
    if (is_string($value)) $value = preg_split('/\s*,\s*/', trim($value)) ?: [];
    if (!is_array($value)) return [];
    return array_values(array_filter(array_map(static fn(mixed $tag): string => trim((string) $tag), $value)));
}

require dirname(__DIR__).'/tmpl/biography_fields.php';

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
    <p class="lorkhan-status" id="biography-load-error" role="alert" hidden></p>

    <?php if (isset($_GET['status'])):
        $importCount = (int) (is_string($_GET['count'] ?? null) ? $_GET['count'] : 0);
        $statusText = match(is_string($_GET['status']) ? $_GET['status'] : '') {
            'imported' => $importCount . ' biography ' . ($importCount === 1 ? 'template' : 'templates') . ' imported.',
            'reset' => $importCount . ' custom biography ' . ($importCount === 1 ? 'template' : 'templates') . ' removed. Factory templates and active NPC profiles were preserved.',
            default => 'Biography saved.',
        };
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
                    <a class="action-button export-csv" href="<?php echo lorkhan_ui_h($managementBasePath . '/exports/biographies/custom.csv?' . http_build_query(['installation_id' => $installationId])); ?>">Export Custom NPCs</a>
                </div>
            </form>
            <p id="biography-import-help"><strong>Relationships column:</strong> Use a JSON object seed such as <code>{"Player":{"aff":25,"type":"professional"}}</code>, or leave it empty. Prose does not seed relationship affinity.</p>
            <p>Check imported entries in the <b>NPC Bio Templates Database</b> below. Installation templates use <code>content_file</code> and <code>record_id</code> as their identity; they are saved as revisioned templates for the selected installation.</p>
            <p>Global catalog edits are stored in <code>public.bio_templates_custom</code>. They override the factory template for every installation. The table below shows both global and selected-installation templates; NPCs already in game keep their own profiles.</p>
            <p><strong>Export Custom NPCs:</strong> Download all global custom entries and the selected installation's templates, including extended profiles and voice overrides. The CSV <code>scope</code> column preserves ownership when re-imported. Global rows affect every installation.</p>
            <button type="button" class="action-button danger" data-biography-reset>Factory Reset NPC Override Table</button>
            <p>This removes global custom overrides and the selected installation's reusable templates. Factory defaults, active NPC profiles and other installations' templates remain. Export your custom NPCs before resetting.</p>
            <details class="biography-tips"><summary>CSV ownership, limits and individual profiles</summary><p><code>global</code> rows use the original catalog name and leave <code>content_file</code> blank; <code>installation</code> rows use OpenMW record identity. The example and older files without <code>scope</code> import into the selected installation.</p><p>Imports are limited to 1,000 rows and the server's upload-size limit. The entire batch succeeds or no changes are saved. For a single active NPC, use LORKHAN NPCs to export or import the complete profile as JSON. Legacy fallback profiles without OpenMW record identity are not CSV templates and are preserved by reset.</p></details>
        <?php endif; ?>

    </section>

    <section class="database-section" id="table">
        <?php $biographyUrl=static function(int $page=1,?string $letter=null)use($catalog,$installationId,$embedded):string{
            return '?'.http_build_query(['installation_id'=>$installationId,'search'=>$catalog['search'],'letter'=>$letter??$catalog['letter'],'page'=>$page,'embed'=>$embedded?'1':'0']).'#table';
        }; ?>
        <h1>NPC Bio Templates Database</h1>
        <div class="action-container">
            <button type="button" class="action-button add-new" data-biography-create<?php echo $installationId===''?' disabled':''; ?>>Add New Entry</button>
            <form class="search-container" method="get" action="#table">
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
                <input type="hidden" name="letter" value="<?php echo lorkhan_ui_h($catalog['letter']); ?>">
                <label class="visually-hidden" for="biography-search">Search NPC names</label>
                <input type="text" id="biography-search" name="search" maxlength="100" value="<?php echo lorkhan_ui_h($catalog['search']); ?>" placeholder="Search NPC names...">
                <button type="submit" class="action-button edit" id="biography-search-button">Search</button>
            </form>
        </div>
        <h3 class="database-note">Note: Edit templates before an NPC is activated in game. After activation, edit their individual profile in NPC Management.</h3>

        <div class="filter-buttons" aria-label="Filter biographies by first letter">
            <?php foreach (['All',...range('A','Z')] as $letter): $value=$letter==='All'?'':$letter; ?>
                <a class="alphabet-button<?php echo $catalog['letter']===$value?' active':''; ?>" href="<?php echo lorkhan_ui_h($biographyUrl(1,$value)); ?>"<?php echo $catalog['letter']===$value?' aria-current="true"':''; ?>><?php echo $letter; ?></a>
            <?php endforeach; ?>
        </div>

        <div class="table-container" id="npc-table-container" role="region" aria-label="NPC biography templates" tabindex="0">
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
                    $biography = trim((string) ($content['core'] ?? ''));
                    $biographySummary = mb_strlen($biography, 'UTF-8') > 200 ? mb_substr($biography, 0, 200, 'UTF-8') . '...' : $biography;
                    $searchName = strtolower(trim((string) ($row['name'] ?? '')));
                ?>
                    <tr data-biography-row data-search-name="<?php echo lorkhan_ui_h($searchName); ?>">
                        <td><?php echo lorkhan_ui_h($row['name']); ?></td>
                        <td><?php echo nl2br(lorkhan_ui_h($biographySummary)); ?></td>
                        <td><button type="button" class="profile-details-button" data-biography-details data-template-name="<?php echo lorkhan_ui_h($row['name']); ?>" data-template-profile="<?php echo lorkhan_ui_h($row['profile_id']??''); ?>"><span class="profile-progress"><?php echo $extendedCount; ?> of <?php echo count($extendedFields); ?> fields completed</span><span class="profile-details">Click to view details</span></button></td>
                        <td class="voice-meta">
                            <div><strong>VoiceID:</strong> <?php echo trim((string) ($voice['id'] ?? '')) !== '' ? lorkhan_ui_h($voice['id']) : '<span class="automatic">Automatic</span>'; ?></div>
                            <div><strong>Gender:</strong> <?php echo trim((string) ($content['gender'] ?? $identity['gender'] ?? '')) !== '' ? lorkhan_ui_h($content['gender'] ?? $identity['gender']) : '<span class="automatic">Automatic</span>'; ?></div>
                            <div><strong>Race:</strong> <?php echo trim((string) ($content['race'] ?? $identity['race'] ?? '')) !== '' ? lorkhan_ui_h($content['race'] ?? $identity['race']) : '<span class="automatic">Automatic</span>'; ?></div>
                            <div><strong>RefID:</strong> <?php echo trim((string) ($identity['record_id'] ?? '')) !== '' ? lorkhan_ui_h($identity['record_id']) : '<span class="automatic">Automatic</span>'; ?></div>
                        </td>
                        <td><?php if ($tags !== []): foreach ($tags as $tag): ?><span class="oghma-tag"><?php echo lorkhan_ui_h($tag); ?></span><?php endforeach; else: ?><span class="automatic">None</span><?php endif; ?></td>
                        <td><div class="row-actions">
                            <button type="button" class="action-button edit" data-biography-edit data-template-name="<?php echo lorkhan_ui_h($row['name']); ?>" data-template-profile="<?php echo lorkhan_ui_h($row['profile_id']??''); ?>">Edit</button>
                            <button type="button" class="action-button" data-biography-oghma data-template-name="<?php echo lorkhan_ui_h($row['name']); ?>" data-template-profile="<?php echo lorkhan_ui_h($row['profile_id']??''); ?>"<?php echo $installationId===''?' disabled':''; ?>>Oghma</button>
                            <span class="profile-details"><?php echo $source === 'installation' ? 'Installation template' : ($source === 'custom' ? 'Custom template' : 'Factory template'); ?></span>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?><tr><td colspan="6"><div class="no-data"><?php echo $catalog['search']!==''||$catalog['letter']!==''?'No NPCs found.':'No NPC biography templates are installed.'; ?></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <nav class="biography-pagination" aria-label="Biography pages">
            <span><?php echo number_format($catalog['total']); ?> templates &middot; Page <?php echo $catalog['page']; ?> of <?php echo $catalog['pages']; ?></span>
            <div><?php if($catalog['page']>1): ?><a class="alphabet-button" rel="prev" href="<?php echo lorkhan_ui_h($biographyUrl($catalog['page']-1)); ?>">Previous</a><?php endif; ?>
            <?php foreach(array_values(array_unique([1,...range(max(1,$catalog['page']-2),min($catalog['pages'],$catalog['page']+2)),$catalog['pages']])) as $page): ?>
                <a class="alphabet-button<?php echo $page===$catalog['page']?' active':''; ?>" href="<?php echo lorkhan_ui_h($biographyUrl($page)); ?>" aria-label="Page <?php echo $page; ?>"<?php echo $page===$catalog['page']?' aria-current="page"':''; ?>><?php echo $page; ?></a>
            <?php endforeach; ?>
            <?php if($catalog['page']<$catalog['pages']): ?><a class="alphabet-button" rel="next" href="<?php echo lorkhan_ui_h($biographyUrl($catalog['page']+1)); ?>">Next</a><?php endif; ?></div>
        </nav>
    </section>
</main>

<div class="biography-modal" id="biography-create-modal" aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="biography-create-title">
        <div class="modal-header"><h2 class="modal-title" id="biography-create-title">Add New NPC Entry</h2></div>
        <div class="modal-body"><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/biography-template-create">
            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
            <?php lorkhan_biography_fields(true); ?><div class="modal-footer"><button type="submit" class="action-button upload-csv"<?php echo $installationId===''?' disabled':''; ?>>Save</button><button type="button" class="action-button" data-biography-create-close>Cancel</button></div></form></div>
    </div>
</div>

<div class="biography-modal" id="biography-edit-modal" aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="biography-modal-title">
        <div class="modal-header"><h2 class="modal-title" id="biography-modal-title">Edit NPC Entry</h2></div>
        <div class="modal-body"><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/biography-template-revise">

                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="npc_name" id="biography-template-name"><input type="hidden" name="profile_id" id="biography-template-profile"><input type="hidden" name="expected_revision" id="biography-template-revision"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                <p id="biography-profile-meta" class="profile-progress" hidden></p>
                <?php lorkhan_biography_fields(false); ?>
            <div class="modal-footer"><button type="submit" class="action-button upload-csv" aria-describedby="biography-profile-meta">Save Changes</button><button type="button" class="action-button" data-biography-close>Cancel</button></div></form></div>
    </div>
</div>

<div class="biography-modal" id="biography-details-modal" aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="biography-details-title">
        <div class="modal-header"><h2 class="modal-title" id="biography-details-title">Extended Profile</h2></div>
        <div class="modal-body"><div class="biography-detail-grid">
            <?php foreach (['npc_static_bio' => 'Static', 'personality' => 'Personality', 'appearance' => 'Appearance', 'relationships' => 'Relationships', 'occupation' => 'Occupation &amp; Role', 'skills' => 'Skills &amp; Abilities', 'speechstyle' => 'Speech Style', 'goals' => 'Goals &amp; Aspirations'] as $field => $label): ?>
                <section><h4><?php echo $label; ?></h4><div data-biography-detail-field="<?php echo $field; ?>"></div></section>
            <?php endforeach; ?>
        </div>
        <div class="modal-footer"><button type="button" class="action-button" data-biography-details-close>Close</button></div></div>
    </div>
</div>

<div class="biography-modal" id="biography-oghma-modal" aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="biography-oghma-title">
        <div class="modal-header"><h2 class="modal-title" id="biography-oghma-title">Oghma Knowledge</h2></div>
        <div class="modal-body">
            <p id="biography-oghma-loading" class="oghma-message" role="status" hidden>Loading Oghma knowledge...</p>
            <p id="biography-oghma-error" class="oghma-message" role="alert" hidden></p>
            <form id="biography-oghma-filters" class="biography-oghma-filters">
                <div><label for="biography-oghma-search">Search Topics &amp; Descriptions:</label><input type="search" id="biography-oghma-search" maxlength="100" placeholder="Search knowledge articles..."></div>
                <div><label for="biography-oghma-category">Category:</label><select id="biography-oghma-category"><option value="">All Categories</option></select></div>
                <div class="oghma-filter-actions"><button type="submit" class="action-button">Apply Filters</button><button type="button" class="action-button" id="biography-oghma-clear">Clear</button></div>
            </form>
            <div class="biography-oghma-table" role="region" aria-label="Accessible Oghma articles" tabindex="0"><table>
                <thead><tr><th>Topic</th><th>Knowledge Level</th><th>Description</th></tr></thead><tbody id="biography-oghma-items"></tbody>
            </table></div>
            <p id="biography-oghma-empty" class="oghma-message" hidden>No accessible knowledge found for this NPC.</p>
            <nav class="biography-pagination" aria-label="Oghma article pages"><span id="biography-oghma-count" role="status"></span><div><button type="button" class="action-button" id="biography-oghma-previous">Previous</button><button type="button" class="action-button" id="biography-oghma-next">Next</button></div></nav>
            <div class="modal-footer"><button type="button" class="action-button" data-biography-oghma-close>Close</button></div>
        </div>
    </div>
</div>

<div class="biography-modal" id="biography-reset-modal" aria-hidden="true"><div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="biography-reset-title">
    <div class="modal-header"><h2 class="modal-title" id="biography-reset-title">Factory Reset NPC Override Table</h2></div>
    <div class="modal-body"><p>This deletes <strong>all global custom biography overrides</strong>, affecting every installation, and removes reusable templates for the selected installation.</p><p>Factory templates, active NPC profiles, their history and other installations' reusable templates are preserved. Deleted global overrides can only be recovered from an export or backup.</p>
        <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath.'/forms/biography-reset'); ?>">
            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>"><input type="hidden" name="confirm" value="Reset">
            <div class="modal-footer"><button type="submit" class="action-button danger">Reset Custom Templates</button><button type="button" class="action-button" data-biography-reset-close>Cancel</button></div>
        </form>
    </div>
</div></div>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/biographies.js?v=<?php echo lorkhan_ui_h((string) filemtime(dirname(__DIR__) . '/js/biographies.js')); ?>"></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
