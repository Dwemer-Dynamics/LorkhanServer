<?php
declare(strict_types=1);

// Require the supplied session, not a fresh session created by the page bootstrap.
$suppliedCookies=is_string($_SERVER['HTTP_COOKIE']??null)?$_SERVER['HTTP_COOKIE']:null;
require __DIR__.'/ui_bootstrap.php';
header('Cache-Control: private, no-store');
$suppliedSession=\LorkhanServer\Security\BrowserSession::parse($suppliedCookies);
$suppliedCsrf=\LorkhanServer\Security\BrowserSession::parseCsrf($suppliedCookies);
if($suppliedSession===null||$suppliedCsrf===null||!$managementRepository->validate($suppliedSession,$suppliedCsrf)){
    http_response_code(401);header('Content-Type: text/plain; charset=utf-8');echo'Authentication required.';exit;
}
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
$mediaId=(string)($_GET['media_id']??'');$installation=(string)($_GET['installation_id']??'');
if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$mediaId)!==1
    ||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$installation)!==1){http_response_code(404);exit;}
try{
    $statement=$database->prepare('SELECT media_id,byte_count,sha256,mime_type FROM media_objects WHERE media_id=:media AND installation_id=:installation AND deleted_at IS NULL AND expires_at>clock_timestamp()');
    $statement->execute(['media'=>$mediaId,'installation'=>$installation]);$row=$statement->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('unavailable');
    $size=(int)$row['byte_count'];$maximum=min(33554432,(int)($config['media_max_bytes']??33554432));
    if($size<1||$size>$maximum||!in_array($row['mime_type'],['audio/wav','audio/ogg','audio/mpeg'],true))throw new RuntimeException('unavailable');
    $store=new \LorkhanServer\Infrastructure\MediaStore((string)($config['media_storage_path']??''),$maximum,max($maximum,(int)($config['media_quota_bytes']??268435456)));
    $bytes=$store->read($mediaId,$size,(string)$row['sha256']);
    header('Content-Type: '.$row['mime_type']);header('Content-Disposition: inline');header('Accept-Ranges: bytes');header('X-Content-Type-Options: nosniff');
    $start=0;$end=$size-1;$range=(string)($_SERVER['HTTP_RANGE']??'');
    if($range!==''){
        if(preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$match)!==1||($match[1]===''&&$match[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
        if($match[1]===''){$suffix=(int)$match[2];if($suffix<1){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}$start=max(0,$size-$suffix);}
        else{$start=(int)$match[1];if($match[2]!=='')$end=min($end,(int)$match[2]);}
        if($start>$end||$start>=$size){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
        http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    }
    header('Content-Length: '.($end-$start+1));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo substr($bytes,$start,$end-$start+1);
}catch(Throwable){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo'Audio unavailable.';}
