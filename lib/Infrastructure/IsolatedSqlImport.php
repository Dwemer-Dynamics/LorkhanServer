<?php

declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use RuntimeException;

/** Run an uploaded dump in the existing unprivileged sandbox and expose only a bounded data stream. */
final class IsolatedSqlImport
{
    /** The caller validates and consumes the data before this worker-owned quarantine is removed. */
    public function read(string $source,string $sha256,callable $heartbeat,callable $consume):mixed
    {
        if(!function_exists('posix_geteuid')||posix_geteuid()===0)throw new RuntimeException('import_unprivileged_worker_required');
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha256)||!is_file($source)||is_link($source))throw new RuntimeException('import_source_invalid');
        $input=fopen($source,'rb');if($input===false)throw new RuntimeException('import_source_unavailable');
        $directory=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'lorkhan-import-'.Uuid::v4();
        $process=null;$pipes=[];$capture=null;$copy=null;$created=false;
        try{
            $stat=fstat($input);if(!$stat||($stat['mode']&0170000)!==0100000||$stat['size']<1||$stat['size']>SqlImportData::MAX_BYTES)throw new RuntimeException('import_source_invalid');
            $mask=umask(0077);try{$created=mkdir($directory,0700);}finally{umask($mask);}
            if(!$created)throw new RuntimeException('import_storage_unavailable');
            $private=$directory.'/input.sql';$output=$directory.'/data.jsonl';
            $copy=fopen($private,'xb');if($copy===false)throw new RuntimeException('import_storage_unavailable');
            $hash=hash_init('sha256');$bytes=0;$last=0;
            while(!feof($input)){
                $chunk=fread($input,1048576);if($chunk===false)throw new RuntimeException('import_source_unavailable');
                if($chunk==='')break;$bytes+=strlen($chunk);if($bytes>SqlImportData::MAX_BYTES)throw new RuntimeException('import_source_too_large');
                hash_update($hash,$chunk);$offset=0;while($offset<strlen($chunk)){$written=fwrite($copy,substr($chunk,$offset));if($written===false||$written===0)throw new RuntimeException('import_storage_unavailable');$offset+=$written;}
                if(hrtime(true)-$last>1000000000){if(!$heartbeat())throw new RuntimeException('lease_lost');$last=hrtime(true);}
            }
            if($bytes!==(int)$stat['size']||!hash_equals($sha256,hash_final($hash)))throw new RuntimeException('import_integrity_failed');
            if(!fflush($copy))throw new RuntimeException('import_storage_unavailable');fclose($copy);$copy=null;fclose($input);$input=null;
            if(!chmod($private,0400))throw new RuntimeException('import_storage_unavailable');
            if(!$heartbeat())throw new RuntimeException('lease_lost');
            $script=dirname(__DIR__,2).'/scripts/sql-import-capture.py';
            // The sandbox inherits no database, provider, pairing or deployment environment variables.
            $process=proc_open(['/usr/bin/python3',$script,$private,$output],
                [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes,null,
                ['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8'],['bypass_shell'=>true]);
            if(!is_resource($process))throw new RuntimeException('import_sandbox_unavailable');
            stream_set_blocking($pipes[2],false);$deadline=hrtime(true)+620000000000;$diagnostics=0;$last=0;
            do{
                $error=stream_get_contents($pipes[2],8192);$diagnostics+=strlen($error===false?'':$error);
                if($diagnostics>65536)throw new RuntimeException('import_diagnostic_limit');
                $status=proc_get_status($process);$now=hrtime(true);
                if($now>$deadline)throw new RuntimeException('import_sandbox_timeout');
                if($now-$last>1000000000){if(!$heartbeat())throw new RuntimeException('lease_lost');$last=$now;}
                if($status['running'])usleep(50000);
            }while($status['running']);
            $exit=$status['exitcode'];fclose($pipes[2]);$pipes=[];proc_close($process);$process=null;
            if($exit!==0)throw new RuntimeException('import_sandbox_failed');
            if(!is_file($output)||is_link($output)||filesize($output)>SqlImportData::MAX_BYTES)throw new RuntimeException('import_capture_invalid');
            $capture=fopen($output,'rb');if($capture===false)throw new RuntimeException('import_capture_invalid');
            if(!$heartbeat())throw new RuntimeException('lease_lost');
            return $consume($capture);
        }finally{
            if(is_resource($process)){
                // Capture's SIGTERM handler owns termination of the isolated subprocess group.
                proc_terminate($process);$until=hrtime(true)+10000000000;
                while(proc_get_status($process)['running']&&hrtime(true)<$until)usleep(50000);
                if(proc_get_status($process)['running'])proc_terminate($process,9);
                proc_close($process);
            }
            foreach($pipes as$pipe)if(is_resource($pipe))fclose($pipe);
            foreach([$capture,$copy,$input]as$stream)if(is_resource($stream))fclose($stream);
            if($created){
                foreach(['input.sql','data.jsonl']as$name)if(is_file($directory.'/'.$name))unlink($directory.'/'.$name);
                foreach(glob($directory.'/.sql-import-*.partial')?:[]as$partial)if(is_file($partial)&&!is_link($partial))unlink($partial);
                rmdir($directory);
            }
        }
    }
}
