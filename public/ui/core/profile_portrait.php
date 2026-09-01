<?php

declare(strict_types=1);

use LorkhanServer\Application\DeterministicClock;
use LorkhanServer\Application\ProductService;
use LorkhanServer\Infrastructure\ProductRepository;

$uiRootDir=dirname(__DIR__);$pageTitle='NPC Portrait';$topNavSection='configuration';
require $uiRootDir.'/ui_bootstrap.php';

$portraitRoot=(string)($config['portrait_storage_path']??(is_dir('/var/lib/lorkhanserver')?'/var/lib/lorkhanserver/profile-portraits':($applicationRoot.'/storage/profile-portraits')));
if(!is_dir($portraitRoot)&&!mkdir($portraitRoot,0750,true)&&!is_dir($portraitRoot))throw new RuntimeException('Portrait storage is unavailable.');
$products=new ProductRepository($database);$service=new ProductService($products,new DeterministicClock());

/** Load only ordinary NPC profiles and normalize their current content. */
function lorkhan_portrait_profile(ProductRepository $products,string $profileId):array
{
    if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$profileId)!==1)throw new InvalidArgumentException('invalid_profile_id');
    $profile=$products->getRevisioned('profile',$profileId);$identity=$profile['actor_identity']??[];
    if(is_string($identity))$identity=json_decode($identity,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($identity)||array_is_list($identity)||in_array($identity['kind']??'actor',['player','narrator'],true))throw new InvalidArgumentException('profile_not_portraitable');
    $profile['content']=is_array($profile['content']??null)?$profile['content']:[];return$profile;
}

/** Resolve only the server-generated filename belonging to this exact profile. */
function lorkhan_portrait_path(string $root,string $profileId,array $portrait):?string
{
    $filename=(string)($portrait['filename']??'');
    if(!str_starts_with($filename,$profileId.'-')||preg_match('/^[0-9a-f-]{36}-[0-9a-f]{16}\.(?:png|jpg|webp)$/D',$filename)!==1)return null;
    return rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
}

$profileId=trim((string)($_REQUEST['profile_id']??''));
try{
    $profile=lorkhan_portrait_profile($products,$profileId);$content=$profile['content'];
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $portrait=is_array($content['portrait']??null)?$content['portrait']:[];$path=lorkhan_portrait_path($portraitRoot,$profileId,$portrait);
        if($path===null)throw new RuntimeException('portrait_metadata_invalid');
        if(!is_file($path))throw new RuntimeException('portrait_file_missing');
        header('Content-Type: '.(string)$portrait['mime']);header('Content-Length: '.(string)filesize($path));
        header('Cache-Control: private, max-age=300');header('X-Content-Type-Options: nosniff');readfile($path);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals($csrf,(string)($_POST['_csrf']??'')))throw new RuntimeException('unauthorized');
    $action=(string)($_POST['action']??'');$old=is_array($content['portrait']??null)?$content['portrait']:[];
    if($action==='upload'){
        $upload=$_FILES['portrait']??null;if(!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK
            ||!is_uploaded_file((string)($upload['tmp_name']??'')))throw new InvalidArgumentException('portrait_upload_failed');
        $tmp=(string)$upload['tmp_name'];$bytes=filesize($tmp);$size=@getimagesize($tmp);$finfo=finfo_open(FILEINFO_MIME_TYPE);
        $mime=$finfo===false?'':(string)finfo_file($finfo,$tmp);if($finfo!==false)finfo_close($finfo);
        $extensions=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
        if(!is_int($bytes)||$bytes<1||$bytes>5_242_880||!is_array($size)||($size[0]??0)<1||($size[0]??0)>2048
            ||($size[1]??0)<1||($size[1]??0)>2048||!isset($extensions[$mime]))throw new InvalidArgumentException('invalid_profile_portrait');
        $sha=hash_file('sha256',$tmp);$filename=$profileId.'-'.substr($sha,0,16).'.'.$extensions[$mime];$path=$portraitRoot.DIRECTORY_SEPARATOR.$filename;
        if(!is_file($path)&&!move_uploaded_file($tmp,$path))throw new RuntimeException('portrait_upload_failed');@chmod($path,0640);
        $content['portrait']=['filename'=>$filename,'mime'=>$mime,'bytes'=>$bytes,'width'=>(int)$size[0],'height'=>(int)$size[1],
            'sha256'=>$sha,'updated_at'=>gmdate('Y-m-d\TH:i:s\Z')];
        try{$service->revise('profile',$profileId,$content,'portrait upload');}
        catch(Throwable $error){if(!hash_equals((string)($old['filename']??''),$filename))@unlink($path);throw$error;}
        $oldPath=lorkhan_portrait_path($portraitRoot,$profileId,$old);if($oldPath!==null&&!hash_equals($oldPath,$path))@unlink($oldPath);
    }elseif($action==='delete'){
        unset($content['portrait']);$service->revise('profile',$profileId,$content,'portrait delete');
        $oldPath=lorkhan_portrait_path($portraitRoot,$profileId,$old);if($oldPath!==null&&is_file($oldPath))@unlink($oldPath);
    }else throw new InvalidArgumentException('invalid_portrait_action');
    header('Location: '.$webRoot.'/ui/core/npc_master.php?status=saved',true,303);exit;
}catch(Throwable $error){
    if($_SERVER['REQUEST_METHOD']==='GET'){$safeGetCodes=['invalid_profile_id','not_found','profile_not_portraitable','portrait_metadata_invalid','portrait_file_missing'];
        $code=in_array($error->getMessage(),$safeGetCodes,true)?$error->getMessage():'portrait_not_found';
        http_response_code(404);header('Content-Type: text/plain; charset=utf-8');header('X-LORKHAN-Portrait-Status: '.$code);echo"Portrait not found.\n";exit;}
    $known=['invalid_profile_id','profile_not_portraitable','portrait_upload_failed','invalid_profile_portrait','invalid_portrait_action','unauthorized'];
    $message=in_array($error->getMessage(),$known,true)?$error->getMessage():'portrait_action_failed';
    header('Location: '.$webRoot.'/ui/core/npc_master.php?error='.rawurlencode($message),true,303);exit;
}
