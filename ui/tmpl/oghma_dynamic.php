<?php
/** Herika's quest-stage editor uses native installation IDs and version-checked forms. */
$dynamicUrl=static fn(array $replace=[]):string=>'?'.http_build_query(array_merge(['installation_id'=>$selectedInstallation,'embed'=>$embedded?'1':'0','tab'=>'dynamic','dynamic_cat'=>$dynamicCategory,'dynamic_page'=>1],$replace)).'#dynamic';
$dynamicFields=['id_quest'=>'Quest ID','stage'=>'Quest Stage','topic'=>'Topic','topic_desc'=>'Topic Description','knowledge_class'=>'Knowledge Class','topic_desc_basic'=>'Topic Description (Basic)','knowledge_class_basic'=>'Knowledge Class (Basic)','tags'=>'Tags','category'=>'Category'];
?>
<div id="dynamic-tab" class="tab-content<?php echo $dynamicActive?' active':''; ?>"<?php echo $dynamicActive?'':' hidden'; ?>>
    <div class="content-grid">
        <div class="content-section">
            <h2>Batch Upload</h2>
            <form method="post" enctype="multipart/form-data" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/oghma-dynamic-import">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($selectedInstallation); ?>">
                <input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
                <label for="dynamic-csv-file">Select .csv file to upload dynamic entries:</label>
                <input type="file" name="csv_file" id="dynamic-csv-file" accept=".csv,text/csv" required>
                <div class="button-group">
                    <button type="submit" class="action-button upload-csv"<?php echo $selectedInstallation===''?' disabled':''; ?>>Upload CSV</button>
                    <a class="action-button download-csv" href="<?php echo lorkhan_ui_h($managementBasePath); ?>/exports/oghma-dynamic/example.csv">Download Example CSV</a>
                </div>
            </form>
            <p>All uploaded entries are saved in <code>oghma_dynamic</code>. Matching quest, stage and topic entries are updated together.</p>
            <p>Use the example's nine-column UTF-8 format; up to 1,000 entries per upload.</p>
        </div>
        <div class="content-section">
            <h2>Database Management</h2>
            <p>Article rules:<br><b>lorkhan_internal &rarr; oghma_dynamic</b></p>
            <p>Rules belong to the selected installation. Applied knowledge belongs to the playthrough where the quest stage was observed.</p>
            <div class="button-group database-actions">
                <button type="button" class="btn-danger" data-dynamic-delete-all<?php echo $selectedInstallation===''?' disabled':''; ?>>Delete All Dynamic Entries</button>
            </div>
        </div>
    </div>
    <div class="full-width-section">
        <h2 id="dynamic"><span aria-hidden="true">&#x1F4CB;</span> Dynamic Oghma Entries</h2>
        <div class="action-container"><button type="button" class="action-button add-new" data-dynamic-new<?php echo $selectedInstallation===''?' disabled':''; ?>>Add New Dynamic Entry</button></div>
        <div class="filter-section">
            <div class="category-filter"><strong>Filter by Category:</strong><br><div class="filter-buttons">
                <a class="alphabet-button<?php echo $dynamicCategory===''?' selected':''; ?>" href="<?php echo lorkhan_ui_h($dynamicUrl(['dynamic_cat'=>''])); ?>">All Categories</a>
                <?php foreach($dynamicCatalog['categories']as$category): ?>
                <a class="alphabet-button<?php echo $dynamicCategory===$category?' selected':''; ?>" href="<?php echo lorkhan_ui_h($dynamicUrl(['dynamic_cat'=>$category])); ?>"><?php echo lorkhan_ui_h($category); ?></a>
                <?php endforeach; ?>
            </div></div>
        </div>
        <div class="table-container" role="region" tabindex="0" aria-label="Dynamic Oghma entries">
            <table><thead><tr><?php foreach($dynamicFields as$field=>$label): ?><th><?php echo lorkhan_ui_h($field==='stage'?'Stage':$label); ?></th><?php endforeach; ?><th>Action</th></tr></thead>
                <tbody><?php foreach($dynamicCatalog['rows']as$row): ?><tr>
                    <?php foreach($dynamicFields as$field=>$label): ?><td><?php echo nl2br(lorkhan_ui_h((string)$row[$field])); ?></td><?php endforeach; ?>
                    <td class="action-cell"><button type="button" class="action-button edit" data-dynamic-edit='<?php echo lorkhan_ui_h(json_encode(array_intersect_key($row,array_flip(array_merge(array_keys($dynamicFields),['id','revision']))),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)); ?>'>Edit</button></td>
                </tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <?php if($dynamicCatalog['rows']===[]): ?><p class="oghma-empty">No dynamic entries found.</p><?php endif; ?>
        <nav class="oghma-pagination" aria-label="Dynamic Oghma pages">
            <span><?php echo (int)$dynamicCatalog['total']; ?> entries &middot; Page <?php echo (int)$dynamicCatalog['page']; ?> of <?php echo (int)$dynamicCatalog['pages']; ?></span>
            <?php if($dynamicCatalog['page']>1): ?><a class="oghma-page-link" href="<?php echo lorkhan_ui_h($dynamicUrl(['dynamic_page'=>$dynamicCatalog['page']-1])); ?>">Previous</a><?php endif; ?>
            <?php if($dynamicCatalog['page']<$dynamicCatalog['pages']): ?><a class="oghma-page-link" href="<?php echo lorkhan_ui_h($dynamicUrl(['dynamic_page'=>$dynamicCatalog['page']+1])); ?>">Next</a><?php endif; ?>
        </nav>
    </div>
</div>

<div id="dynamicEditorModal" class="modal-backdrop" hidden aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="dynamic-editor-title">
        <div class="modal-header"><h2 class="modal-title" id="dynamic-editor-title">Add New Dynamic Oghma Entry</h2><p>Leave fields blank to retain existing topic values. Use <b>clearall</b> to clear a field.</p></div>
        <div class="modal-body">
            <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/oghma-dynamic-save">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($selectedInstallation); ?>">
                <input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
                <input type="hidden" name="id" id="dynamic-editor-id">
                <input type="hidden" name="revision" id="dynamic-editor-revision">
                <?php foreach($dynamicFields as$field=>$label):$required=in_array($field,['id_quest','stage','topic'],true); ?>
                <label for="dynamic-field-<?php echo $field; ?>"><?php echo lorkhan_ui_h($label); ?><?php echo $required?' (required)':''; ?>:</label>
                <small><?php echo match($field){'id_quest'=>'The OpenMW quest record ID that triggers this entry.','stage'=>'The exact journal stage that triggers this entry.','topic'=>'Topic to update or add. Use lowercase letters and underscores.','topic_desc'=>'Advanced knowledge information on the subject.','knowledge_class'=>'Advanced access tags, separated with commas.','topic_desc_basic'=>'Basic information about the subject.','knowledge_class_basic'=>'Basic access tags, separated with commas.','tags'=>'Additional search tags.',default=>'Category for organization.'}; ?></small>
                <?php if(in_array($field,['topic_desc','topic_desc_basic'],true)): ?>
                <textarea rows="5" name="<?php echo $field; ?>" id="dynamic-field-<?php echo $field; ?>"></textarea>
                <?php else: ?>
                <input type="<?php echo $field==='stage'?'number':'text'; ?>" name="<?php echo $field; ?>" id="dynamic-field-<?php echo $field; ?>"<?php echo $required?' required':''; ?><?php echo $field==='stage'?' min="0" max="2147483647" step="1" value="0"':''; ?>>
                <?php endif; endforeach; ?>
                <div class="modal-footer">
                    <button type="submit" class="btn-save" id="dynamic-editor-save">Save</button>
                    <button type="button" class="btn-danger" id="dynamic-editor-delete" hidden>Delete</button>
                    <button type="button" class="btn-base btn-cancel" data-oghma-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="dynamicDeleteModal" class="modal-backdrop" hidden aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="dynamic-delete-title">
        <div class="modal-header"><h2 class="modal-title" id="dynamic-delete-title">Delete Dynamic Oghma Entry</h2></div>
        <div class="modal-body">
            <p>Delete the selected rules from this installation? Future quest observations will no longer apply them. Previously applied story knowledge is retained.</p>
            <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/oghma-dynamic-delete">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($selectedInstallation); ?>">
                <input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>">
                <input type="hidden" name="confirm" value="Delete">
                <input type="hidden" name="mode" id="dynamic-delete-mode">
                <input type="hidden" name="id" id="dynamic-delete-id">
                <input type="hidden" name="revision" id="dynamic-delete-revision">
                <div class="modal-footer">
                    <button type="submit" class="btn-danger" id="dynamic-delete-submit">Delete</button>
                    <button type="button" class="btn-base btn-cancel" data-oghma-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
