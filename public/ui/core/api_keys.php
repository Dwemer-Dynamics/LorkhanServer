<?php

declare(strict_types=1);

use ALMSIVIserver\Application\CredentialStore;

$pageTitle='API Keys';$topNavSection='configuration';$bodyClass='configuration-resource view-api-keys';
require dirname(__DIR__).'/ui_bootstrap.php';
$store=new CredentialStore((string)$config['credential_storage_path']);
$notice='';$error='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['_csrf']??'')))throw new RuntimeException('unauthorized');
        $variable=(string)($_POST['variable']??'');$action=(string)($_POST['action']??'');
        if($action==='set'){$store->set($variable,(string)($_POST['credential']??''));$notice='Credential saved.';}
        elseif($action==='delete'){$store->delete($variable);$notice='Managed credential removed.';}
        else throw new InvalidArgumentException('invalid_credential_action');
    }catch(Throwable$exception){$error=$exception instanceof InvalidArgumentException?$exception->getMessage():'credential_update_failed';}
}
$statuses=$store->statuses();
include dirname(__DIR__).'/tmpl/head.html';if(!$embedded)include dirname(__DIR__).'/tmpl/navbar.php';
?>
<main class="management-page">
    <header class="configuration-page-header"><h1>API Keys</h1><p>Store provider credentials outside the web root. Values are write-only: this page reports status and source, never the credential itself.</p></header>
    <?php if($notice!==''):?><p class="page-status" role="status"><?php echo almsivi_ui_h($notice);?></p><?php endif;?>
    <?php if($error!==''):?><p class="page-error" role="alert"><?php echo almsivi_ui_h($error);?></p><?php endif;?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Provider Credentials</h3></div><div class="widget-content"><div class="connector-grid">
        <?php foreach($statuses as$status):$variable=(string)$status['variable'];$configured=(bool)$status['configured'];?>
            <article class="connector-card<?php echo $configured?' active':'';?>"><header><div><span class="connector-kind">Credential</span><h3><?php echo almsivi_ui_h(str_replace(['ALMSIVI_','_API_KEY','_'],['','',' '],$variable));?></h3></div><span class="status-badge<?php echo $configured?' connector-active':'';?>"><?php echo $configured?'Configured':'Not configured';?></span></header>
                <dl><dt>Variable</dt><dd><code><?php echo almsivi_ui_h($variable);?></code></dd><dt>Source</dt><dd><?php echo almsivi_ui_h($status['source']);?></dd></dl>
                <?php if($status['source']==='environment'):?><p>This credential is controlled by the Apache/worker environment and cannot be replaced from the browser.</p><?php else:?><form class="management-form" method="post"><label for="credential-<?php echo almsivi_ui_h(strtolower($variable));?>"><?php echo $configured?'Replacement credential':'Credential';?></label><input id="credential-<?php echo almsivi_ui_h(strtolower($variable));?>" name="credential" type="password" autocomplete="new-password" maxlength="8192" required><input type="hidden" name="variable" value="<?php echo almsivi_ui_h($variable);?>"><input type="hidden" name="action" value="set"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf);?>"><button class="btn-base btn-primary" type="submit"><?php echo $configured?'Replace':'Save';?></button></form><?php endif;?>
                <?php if($configured&&$status['source']==='managed store'):?><form class="danger-form" method="post"><input type="hidden" name="variable" value="<?php echo almsivi_ui_h($variable);?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf);?>"><button class="btn-base btn-danger" type="submit">Remove managed credential</button></form><?php endif;?>
            </article>
        <?php endforeach;?>
    </div></div></section>
</main>
<?php include dirname(__DIR__).'/tmpl/footer.html';?>
