<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Portable Core content and its explicit connector graph; source IDs are references, never destination ownership. */
final class CoreProfileBundle
{
    public const SCHEMA='lorkhan.core-profile-export.v1';

    public static function configurationKind(string $field):string
    {
        return match($field){'prompt_configuration_id'=>'prompt','tts_configuration_id'=>'tts_provider',default=>'provider'};
    }

    /** Compare/import settings without allowing a file to select a destination's credential. */
    public static function portableConfiguration(string $kind,array $content):array
    {
        if($kind==='provider'){
            $content=LlmConnector::validate($content);
            if($content['driver']!=='mock')$content['credential']='none';
        }elseif($kind==='tts_provider'){
            $content=ConnectorCatalog::validate($kind,$content);$content['credential']='none';
        }elseif($kind==='prompt'){
            unset($content['format']);
            if(array_key_exists('player_mood_prompts',$content))$content['player_mood_prompts']=PlayerMoodPolicy::validateTemplates($content['player_mood_prompts']);
            if(strlen(json_encode($content,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>65_536)throw new InvalidArgumentException('invalid_prompt_content');
        }else throw new InvalidArgumentException('invalid_core_profile_connector_kind');
        return$content;
    }

    public static function validate(array $document):array
    {
        $keys=array_keys($document);sort($keys);
        if($keys!==['connectors','exported_at','name','profile','schema'] || ($document['schema']??null)!==self::SCHEMA
            ||!is_string($document['exported_at'])||strlen($document['exported_at'])>64
            ||!is_string($document['name'])||trim($document['name'])===''||strlen($document['name'])>128||!mb_check_encoding($document['name'],'UTF-8')
            ||!is_array($document['profile'])||!is_array($document['connectors'])||count($document['connectors'])>12)
            throw new InvalidArgumentException('invalid_core_profile_bundle');
        $document['profile']=EffectiveSettingsResolver::validateCoreProfile($document['profile']);
        $expected=[];
        foreach($document['profile']['routing'] as $field=>$id){
            if(!is_string($id)||$id==='')continue;
            $kind=self::configurationKind($field);
            if(isset($expected[$id])&&$expected[$id]!==$kind)throw new InvalidArgumentException('invalid_core_profile_connector_kind');
            $expected[$id]=$kind;
        }
        if(array_diff_key($document['connectors'],$expected)!==[]||array_diff_key($expected,$document['connectors'])!==[])
            throw new InvalidArgumentException('core_profile_bundle_connector_missing');
        foreach($document['connectors'] as $id=>&$connector){
            if(!is_array($connector))throw new InvalidArgumentException('invalid_core_profile_bundle_connector');
            $keys=array_keys($connector);sort($keys);
            if($keys!==['content','kind','name']||$connector['kind']!==$expected[$id]
                ||!is_string($connector['name'])||trim($connector['name'])===''||strlen($connector['name'])>128||!mb_check_encoding($connector['name'],'UTF-8')
                ||!is_array($connector['content'])||($connector['content']!==[]&&array_is_list($connector['content'])))
                throw new InvalidArgumentException('invalid_core_profile_bundle_connector');
            $connector['content']=self::portableConfiguration($connector['kind'],$connector['content']);
        }
        unset($connector);
        return$document;
    }
}
