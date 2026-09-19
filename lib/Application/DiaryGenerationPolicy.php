<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;
use RuntimeException;

/** Validate the opt-in, event-driven diary settings and generated document contract. */
final class DiaryGenerationPolicy
{
    private const DEFAULT_PROMPT = 'Write a concise first-person diary entry about the witnessed Morrowind events. Preserve uncertainty and do not invent facts.';

    /** @return array{materialize_enabled:bool,enabled:bool,automatic_enabled:bool,automatic_wait_enabled:bool,automatic_interval_seconds:int,include_in_context:bool,latest_entry_in_context:bool,context_turn_limit:int,prompt:string} */
    public static function defaults(): array
    {
        return ['materialize_enabled'=>false,'enabled'=>false,'automatic_enabled'=>false,'automatic_wait_enabled'=>false,
            'automatic_interval_seconds'=>120,'include_in_context'=>true,'latest_entry_in_context'=>false,'context_turn_limit'=>100,
            'prompt'=>self::DEFAULT_PROMPT];
    }

    /** @return array<string,mixed> */
    public static function validateOverrides(mixed $settings): array
    {
        if(!is_array($settings)||($settings!==[]&&array_is_list($settings))
            ||array_diff(array_keys($settings),array_keys(self::defaults()))!==[])
            throw new InvalidArgumentException('invalid_settings_overrides');
        foreach(['materialize_enabled','enabled','automatic_enabled','automatic_wait_enabled','include_in_context','latest_entry_in_context']as$field)
            if(array_key_exists($field,$settings)&&!is_bool($settings[$field]))throw new InvalidArgumentException('invalid_settings_overrides');
        if(array_key_exists('automatic_interval_seconds',$settings)&&(!is_int($settings['automatic_interval_seconds'])
            ||$settings['automatic_interval_seconds']<10||$settings['automatic_interval_seconds']>86400))
            throw new InvalidArgumentException('invalid_settings_overrides');
        if(array_key_exists('context_turn_limit',$settings)&&(!is_int($settings['context_turn_limit'])
            ||$settings['context_turn_limit']<0||$settings['context_turn_limit']>400))throw new InvalidArgumentException('invalid_settings_overrides');
        if(array_key_exists('prompt',$settings)&&(!is_string($settings['prompt'])||trim($settings['prompt'])===''
            ||strlen($settings['prompt'])>8192||!mb_check_encoding($settings['prompt'],'UTF-8')))
            throw new InvalidArgumentException('invalid_settings_overrides');
        if(array_key_exists('prompt',$settings))$settings['prompt']=trim($settings['prompt']);
        return$settings;
    }

    /** @return array{title:string,content:string} */
    public static function output(mixed $output): array
    {
        if(!is_array($output)||array_is_list($output)){throw new RuntimeException('provider_invalid_output');}
        $keys=array_keys($output);sort($keys);
        if($keys!==['content','title'])throw new RuntimeException('provider_invalid_output');
        foreach(['title'=>256,'content'=>65_536]as$field=>$max){$value=$output[$field]??null;
            if(!is_string($value)||trim($value)===''||strlen($value)>$max||!mb_check_encoding($value,'UTF-8'))
                throw new RuntimeException('provider_invalid_output');$output[$field]=trim($value);}
        return$output;
    }
}
