#!/usr/bin/env php
<?php

declare(strict_types=1);

use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\OghmaCatalogImporter;

require dirname(__DIR__) . '/lib/Autoload.php';

try{
    $planOnly=in_array('--plan',$argv??[],true);
    $base=dirname(__DIR__).'/data/oghma/morrowind-official';
    $override=trim((string)getenv('LORKHAN_OGHMA_CATALOG_DIR'));
    $activeVersionFile=$base.'/active-catalog-version.txt';
    $activeVersion=is_file($activeVersionFile)?trim((string)file_get_contents($activeVersionFile)):'';
    $directory=$override!==''?$override:($activeVersion!==''?$base.'/catalogs/'.$activeVersion:$base.'/catalog');
    if(!is_dir($directory)){echo json_encode(['schema'=>'lorkhan.default-oghma.v1','status'=>'skipped','reason'=>'current_dataset_not_bundled'],JSON_THROW_ON_ERROR).PHP_EOL;exit(0);}
    $configFile=getenv('LORKHAN_CONFIG')?:dirname(__DIR__).'/conf/server.php';if(!is_file($configFile))throw new RuntimeException('Server configuration is unavailable. Set LORKHAN_CONFIG.');
    $config=require$configFile;if(!is_array($config))throw new RuntimeException('Server configuration is invalid.');
    $config['database_password']=getenv('LORKHAN_DATABASE_PASSWORD')?:(string)($config['database_password']??'');
    $importer=new OghmaCatalogImporter(Connection::open($config));
    $articles=$directory.'/articles.json';$manifest=$directory.'/manifest.json';$versionFile=$directory.'/catalog-version.txt';
    if(!is_file($articles)||!is_file($manifest)||!is_file($versionFile))throw new RuntimeException('Bundled current Oghma dataset is incomplete: '.$directory);
    $version=trim((string)file_get_contents($versionFile));
    $result=$planOnly?$importer->plan($articles,$manifest,$version):$importer->apply($articles,$manifest,$version);
    echo json_encode(['schema'=>'lorkhan.default-oghma.v3','status'=>$planOnly?'planned':'ready','current_dataset_version'=>$version,'result'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable$error){fwrite(STDERR,'Default Oghma provisioning failed: '.$error->getMessage().PHP_EOL);exit(1);}
