<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Normalize the Local LLM setup independently of profile routing and private credential storage. */
final class QuickstartLocalLlm
{
    // Keep Quickstart's optional local-server key separate from cloud and custom connector credentials.
    public const CREDENTIAL = 'LORKHAN_CUSTOM_QUICKSTART_LOCAL_LLM_API_KEY';

    public const SERVERS=['lm_studio'=>['LM Studio',1234],'ollama'=>['Ollama',11434],
        'llama_cpp'=>['llama.cpp',8080],'koboldcpp'=>['KoboldCPP',5001],'other'=>['Other OpenAI-compatible server',null]];

    /** Read local WSL routing only; never probe a model server or infer a Windows host from an unknown mode. */
    public static function networkIps():array
    {
        $empty=['host_ip'=>'','wsl_ip'=>''];
        if(PHP_OS_FAMILY!=='Linux'||stripos((string)@file_get_contents('/proc/sys/kernel/osrelease'),'microsoft')===false)return $empty;
        $mode=self::networkCommand(['/usr/bin/wslinfo','--networking-mode']);
        $routes=json_decode(self::networkCommand(['/usr/sbin/ip','-j','-4','route','show','default']),true);
        $addresses=json_decode(self::networkCommand(['/usr/sbin/ip','-j','-4','address','show','scope','global']),true);
        return self::networkAddresses(trim($mode),is_array($routes)?$routes:[],is_array($addresses)?$addresses:[]);
    }

    /** Resolve the default interface's address, with a Windows gateway only in confirmed NAT mode. */
    public static function networkAddresses(string $mode,array $routes,array $interfaces):array
    {
        $result=['host_ip'=>'','wsl_ip'=>''];
        foreach($routes as $route){
            if(($route['dst']??'')!=='default'||!is_string($route['dev']??null))continue;
            foreach($interfaces as $interface){
                if(($interface['ifname']??'')!==$route['dev'])continue;
                foreach($interface['addr_info']??[] as $address){
                    $ip=$address['local']??'';
                    if(($address['family']??'')==='inet'&&is_string($ip)&&filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
                        $result['wsl_ip']=$ip;break;
                    }
                }
            }
            $gateway=$route['gateway']??'';
            if($mode==='nat'&&is_string($gateway)&&filter_var($gateway,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))$result['host_ip']=$gateway;
            elseif($mode==='mirrored')$result['host_ip']='127.0.0.1';
            break;
        }
        return $result;
    }

    /** Bound fixed OS discovery commands so an unavailable WSL utility cannot stall Quickstart. */
    private static function networkCommand(array $command):string
    {
        if(!function_exists('proc_open')||!is_executable($command[0]))return '';
        $process=@proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
        if(!is_resource($process))return '';
        stream_set_blocking($pipes[1],false);$output='';$deadline=microtime(true)+0.5;
        do{
            $output.=stream_get_contents($pipes[1],65536-strlen($output));
            $status=proc_get_status($process);
            if(!$status['running'])break;
            usleep(5000);
        }while(microtime(true)<$deadline&&strlen($output)<65536);
        if($status['running']){proc_terminate($process,9);$output='';}
        elseif($status['exitcode']!==0)$output='';
        fclose($pipes[1]);proc_close($process);
        return $output;
    }

    public static function normalize(array $values):array
    {
        if(array_diff(array_keys($values),['server_type','scope','endpoint','model','timeout_seconds','disable_streaming','credential']))
            throw new InvalidArgumentException('invalid_local_llm_setup');
        $server=$values['server_type']??'lm_studio';$scope=$values['scope']??'conversations';
        $timeout=$values['timeout_seconds']??30;$disabled=$values['disable_streaming']??false;
        if(!is_string($server)||!isset(self::SERVERS[$server])||!in_array($scope,['conversations','all'],true)
            ||!is_int($timeout)||$timeout<5||$timeout>120||!is_bool($disabled))throw new InvalidArgumentException('invalid_local_llm_setup');
        if(isset($values['model'])&&is_string($values['model']))$values['model']=trim($values['model']);
        if(isset($values['endpoint'])&&is_string($values['endpoint']))$values['endpoint']=trim($values['endpoint']);
        $content=LlmConnector::validate(['driver'=>'openai-compatible','service'=>'local','endpoint'=>$values['endpoint']??'',
            'model'=>$values['model']??'','timeout_ms'=>$timeout*1000,'credential'=>$values['credential']??'none',
            'options'=>['max_tokens'=>512,'temperature'=>0.7,'json_mode'=>true,'prefill_json'=>false,'stream'=>!$disabled]]);
        return ['server_type'=>$server,'scope'=>$scope,'name'=>'Local LLM - '.self::SERVERS[$server][0],'content'=>$content];
    }
}
