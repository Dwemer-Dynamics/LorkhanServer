#!/usr/bin/env php
<?php

declare(strict_types=1);

use ALMSIVIserver\Infrastructure\Connection;
use ALMSIVIserver\Infrastructure\OghmaCatalogImporter;

require dirname(__DIR__) . '/src/Autoload.php';

try{
    $planOnly=in_array('--plan',$argv??[],true);
    $rollbackVersion=null;
    foreach($argv??[]as$argument)if(str_starts_with($argument,'--rollback='))$rollbackVersion=trim(substr($argument,11));
    $base=dirname(__DIR__).'/resources/oghma/morrowind-official';
    $override=trim((string)getenv('ALMSIVI_OGHMA_CATALOG_DIR'));
    $activeVersionFile=$base.'/active-catalog-version.txt';
    $activeVersion=is_file($activeVersionFile)?trim((string)file_get_contents($activeVersionFile)):'';
    $directories=[];
    if($override!=='')$directories[]=$override;
    elseif(is_dir($base.'/catalogs')){
        foreach(glob($base.'/catalogs/*',GLOB_ONLYDIR)?:[]as$directory)$directories[]=$directory;
        usort($directories,static function(string$left,string$right)use($activeVersion):int{
            $leftActive=basename($left)===$activeVersion;$rightActive=basename($right)===$activeVersion;
            return$leftActive===$rightActive?strcmp($left,$right):($leftActive?1:-1);
        });
    }elseif(is_dir($base.'/catalog'))$directories[]=$base.'/catalog';
    if($directories===[]){echo json_encode(['schema'=>'almsivi.default-oghma.v1','status'=>'skipped','reason'=>'catalog_not_bundled'],JSON_THROW_ON_ERROR).PHP_EOL;exit(0);}
    $configFile=getenv('ALMSIVI_CONFIG')?:dirname(__DIR__).'/config/server.php';if(!is_file($configFile))throw new RuntimeException('Server configuration is unavailable. Set ALMSIVI_CONFIG.');
    $config=require$configFile;if(!is_array($config))throw new RuntimeException('Server configuration is invalid.');
    $config['database_password']=getenv('ALMSIVI_DATABASE_PASSWORD')?:(string)($config['database_password']??'');
    $importer=new OghmaCatalogImporter(Connection::open($config));$results=[];
    if($rollbackVersion!==null){
        if($rollbackVersion==='')throw new RuntimeException('Rollback catalog version is required.');
        echo json_encode($importer->rollback($rollbackVersion),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    }
    foreach($directories as$directory){
        $articles=$directory.'/articles.json';$manifest=$directory.'/manifest.json';$versionFile=$directory.'/catalog-version.txt';
        if(!is_file($articles)||!is_file($manifest)||!is_file($versionFile))throw new RuntimeException('Bundled Oghma catalog is incomplete: '.$directory);
        $version=trim((string)file_get_contents($versionFile));
        $results[]=$planOnly?$importer->plan($articles,$manifest,$version)
            :($version===$activeVersion?$importer->apply($articles,$manifest,$version):$importer->provision($articles,$manifest,$version));
    }
    echo json_encode(['schema'=>'almsivi.default-oghma.v2','status'=>$planOnly?'planned':'ready','active_catalog_version'=>$activeVersion,'results'=>$results],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable$error){fwrite(STDERR,'Default Oghma provisioning failed: '.$error->getMessage().PHP_EOL);exit(1);}
