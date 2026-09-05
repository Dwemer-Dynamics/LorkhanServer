#!/usr/bin/env php
<?php

declare(strict_types=1);

use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\OghmaCatalogImporter;

require dirname(__DIR__) . '/lib/Autoload.php';

$usage=static function():never{fwrite(STDERR,"Usage:\n  php scripts/import-morrowind-oghma.php dry-run|sync --articles=PATH --manifest=PATH --catalog-version=VERSION\n  php scripts/import-morrowind-oghma.php status\n");exit(2);};
try{
    $command=$argv[1]??null;if(!in_array($command,['dry-run','sync','apply','status'],true))$usage();$options=[];
    foreach(array_slice($argv,2)as$argument){if(preg_match('/^--(articles|manifest|catalog-version)=(.+)$/D',$argument,$match)!==1)$usage();$options[$match[1]]=$match[2];}
    $configFile=getenv('LORKHAN_CONFIG')?:dirname(__DIR__).'/conf/server.php';if(!is_file($configFile))throw new RuntimeException('Server configuration is unavailable. Set LORKHAN_CONFIG.');
    $config=require$configFile;if(!is_array($config))throw new RuntimeException('Server configuration is invalid.');
    $config['database_password']=getenv('LORKHAN_DATABASE_PASSWORD')?:(string)($config['database_password']??'');
    $importer=new OghmaCatalogImporter(Connection::open($config));
    if(in_array($command,['dry-run','sync','apply'],true)){foreach(['articles','manifest','catalog-version']as$required)if(!isset($options[$required]))$usage();
        $result=$command==='dry-run'?$importer->plan($options['articles'],$options['manifest'],$options['catalog-version'])
            :$importer->apply($options['articles'],$options['manifest'],$options['catalog-version']);}
    else$result=$importer->status();
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable$error){fwrite(STDERR,'Oghma catalog import failed: '.$error->getMessage().PHP_EOL);exit(1);}
