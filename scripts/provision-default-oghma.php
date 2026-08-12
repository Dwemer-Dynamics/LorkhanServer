#!/usr/bin/env php
<?php

declare(strict_types=1);

use ALMSIVIserver\Infrastructure\Connection;
use ALMSIVIserver\Infrastructure\OghmaCatalogImporter;

require dirname(__DIR__) . '/src/Autoload.php';

try{
    $directory=getenv('ALMSIVI_OGHMA_CATALOG_DIR')?:dirname(__DIR__).'/resources/oghma/morrowind-official/catalog';
    $articles=$directory.'/articles.json';$manifest=$directory.'/manifest.json';$versionFile=$directory.'/catalog-version.txt';
    if(!is_dir($directory)){echo json_encode(['schema'=>'almsivi.default-oghma.v1','status'=>'skipped','reason'=>'catalog_not_bundled'],JSON_THROW_ON_ERROR).PHP_EOL;exit(0);}
    if(!is_file($articles)||!is_file($manifest)||!is_file($versionFile))throw new RuntimeException('Bundled Oghma catalog is incomplete.');
    $configFile=getenv('ALMSIVI_CONFIG')?:dirname(__DIR__).'/config/server.php';if(!is_file($configFile))throw new RuntimeException('Server configuration is unavailable. Set ALMSIVI_CONFIG.');
    $config=require$configFile;if(!is_array($config))throw new RuntimeException('Server configuration is invalid.');
    $config['database_password']=getenv('ALMSIVI_DATABASE_PASSWORD')?:(string)($config['database_password']??'');
    $result=(new OghmaCatalogImporter(Connection::open($config)))->provision($articles,$manifest,trim((string)file_get_contents($versionFile)));
    echo json_encode(['schema'=>'almsivi.default-oghma.v1','status'=>'ready','result'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable$error){fwrite(STDERR,'Default Oghma provisioning failed: '.$error->getMessage().PHP_EOL);exit(1);}
