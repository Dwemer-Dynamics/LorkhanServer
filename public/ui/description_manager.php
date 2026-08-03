<?php

declare(strict_types=1);

$pageTitle='Descriptions';$topNavSection='configuration';$bodyClass='management-page';
require __DIR__.'/ui_bootstrap.php';
$rows=$uiRepository->rows('descriptions');$installations=[];
foreach($uiRepository->rows('global_settings')as$installation){$id=(string)($installation['installation_id']??'');if($id!=='')$installations[$id]=(string)($installation['display_name']??$id);}
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="management-page">
    <h1>Descriptions</h1>
    <p>Describe Morrowind records by content file and record ID. ALMSIVI adds a description only when that record is present in the current bounded inventory or nearby-object context.</p>
    <?php if(($_GET['status']??'')==='saved'):?><p class="page-status" role="status">Changes saved.</p><?php endif;?>
    <form class="management-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath.'/forms/description-save');?>"><fieldset><legend>Add or update description</legend>
        <label for="description-installation">Installation</label><select id="description-installation" name="installation_id"><?php foreach($installations as$id=>$label):?><option value="<?php echo almsivi_ui_h($id);?>"><?php echo almsivi_ui_h($label);?></option><?php endforeach;?></select>
        <label for="description-content-file">Content file</label><input id="description-content-file" name="content_file" value="Morrowind.esm" maxlength="256" required>
        <label for="description-record-id">Record ID</label><input id="description-record-id" name="record_id" maxlength="256" required>
        <label for="description-name">Display name</label><input id="description-name" name="display_name" maxlength="256" required>
        <label for="description-text">Description</label><textarea id="description-text" name="description" maxlength="8192" required></textarea>
    </fieldset><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf);?>"><button class="btn-base btn-primary" type="submit">Save description</button></form>
    <section class="widget widget-wide"><div class="widget-header"><h3>Description Database</h3></div><div class="widget-content">
        <?php if($rows===[]):?><p class="empty-state">No custom descriptions are configured.</p><?php else:?><div class="profile-grid"><?php foreach($rows as$row):?>
            <article class="profile-card"><header><div><span class="connector-kind"><?php echo almsivi_ui_h($row['content_file']);?></span><h3><?php echo almsivi_ui_h($row['display_name']);?></h3></div><span class="status-badge"><?php echo almsivi_ui_h($row['record_id']);?></span></header><p><?php echo nl2br(almsivi_ui_h($row['description']));?></p>
                <details><summary>Edit description</summary><form class="management-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath.'/forms/description-save');?>"><fieldset><legend>Save description</legend>
                    <label for="content-<?php echo almsivi_ui_h($row['description_id']);?>">Content file</label><input id="content-<?php echo almsivi_ui_h($row['description_id']);?>" name="content_file" value="<?php echo almsivi_ui_h($row['content_file']);?>" maxlength="256" required>
                    <label for="record-<?php echo almsivi_ui_h($row['description_id']);?>">Record ID</label><input id="record-<?php echo almsivi_ui_h($row['description_id']);?>" name="record_id" value="<?php echo almsivi_ui_h($row['record_id']);?>" maxlength="256" required>
                    <label for="name-<?php echo almsivi_ui_h($row['description_id']);?>">Display name</label><input id="name-<?php echo almsivi_ui_h($row['description_id']);?>" name="display_name" value="<?php echo almsivi_ui_h($row['display_name']);?>" maxlength="256" required>
                    <label for="text-<?php echo almsivi_ui_h($row['description_id']);?>">Description</label><textarea id="text-<?php echo almsivi_ui_h($row['description_id']);?>" name="description" maxlength="8192" required><?php echo almsivi_ui_h($row['description']);?></textarea>
                </fieldset><input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($row['installation_id']);?>"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf);?>"><button class="btn-base btn-primary" type="submit">Save description</button></form></details>
                <form class="danger-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath.'/forms/description-delete');?>"><input type="hidden" name="description_id" value="<?php echo almsivi_ui_h($row['description_id']);?>"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf);?>"><button class="btn-base btn-danger" type="submit">Delete description</button></form>
            </article><?php endforeach;?></div><?php endif;?>
    </div></section>
</main>
<?php include __DIR__.'/tmpl/footer.html';?>
