#!/usr/bin/env php
<?php

declare(strict_types=1);

use LORKHANserver\Infrastructure\Connection;
use LORKHANserver\Infrastructure\ProductRepository;

require dirname(__DIR__).'/src/Autoload.php';

if(PHP_SAPI!=='cli'||count($argv)!==1){fwrite(STDERR,"Usage: php scripts/backfill-morrowind-localities.php\n");exit(2);}

try{
    $configFile=getenv('LORKHAN_CONFIG')?:dirname(__DIR__).'/config/server.php';
    if(!is_file($configFile))throw new RuntimeException('Server configuration is unavailable. Set LORKHAN_CONFIG.');
    $config=require$configFile;
    if(!is_array($config))throw new RuntimeException('Server configuration is invalid.');
    $config['database_password']=getenv('LORKHAN_DATABASE_PASSWORD')?:(string)($config['database_password']??'');
    $products=new ProductRepository(Connection::open($config));
    $result=$products->backfillMorrowindCatalogLocalities(gmdate('Y-m-d\TH:i:s\Z'));
    echo json_encode(['schema'=>'lorkhan.morrowind-locality-backfill.v1',...$result],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $error){
    fwrite(STDERR,'Morrowind locality backfill failed: '.$error->getMessage().PHP_EOL);exit(1);
}
