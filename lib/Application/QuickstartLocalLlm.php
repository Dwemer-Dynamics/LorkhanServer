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
