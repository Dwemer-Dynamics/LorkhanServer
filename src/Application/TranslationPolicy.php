<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use InvalidArgumentException;

/** Validate the installation-scoped policy that freezes NPC output translation for each turn. */
final class TranslationPolicy
{
    public const FREE_ENDPOINT = 'https://api-free.deepl.com/v2/translate';
    public const PRO_ENDPOINT = 'https://api.deepl.com/v2/translate';

    public static function defaults(): array
    {
        return ['schema'=>'almsivi.translation-policy.v1','provider'=>'none','translate_text'=>false,
            'translate_audio'=>false,'save_translated_text'=>false,'source_language'=>'','target_language'=>'',
            'endpoint'=>self::FREE_ENDPOINT];
    }

    /** @param array<string,mixed> $content @return array<string,mixed> */
    public static function validate(array $content): array
    {
        $expected=array_keys(self::defaults());$keys=array_keys($content);sort($expected);sort($keys);
        if($keys!==$expected||($content['schema']??null)!=='almsivi.translation-policy.v1')
            throw new InvalidArgumentException('invalid_translation_policy');
        if(!in_array($content['provider']??null,['none','deepl'],true))
            throw new InvalidArgumentException('invalid_translation_provider');
        foreach(['translate_text','translate_audio','save_translated_text']as$field)
            if(!is_bool($content[$field]??null))throw new InvalidArgumentException('invalid_'.$field);
        foreach(['source_language','target_language']as$field){
            $value=$content[$field]??null;
            if(!is_string($value)||strlen($value)>16||!mb_check_encoding($value,'UTF-8')
                ||($value!==''&&preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z]{2})?$/D',$value)!==1))
                throw new InvalidArgumentException('invalid_'.$field);
            $content[$field]=strtoupper($value);
        }
        if(!is_string($content['endpoint']??null)
            ||!in_array($content['endpoint'],[self::FREE_ENDPOINT,self::PRO_ENDPOINT],true))
            throw new InvalidArgumentException('invalid_translation_endpoint');
        $enabled=$content['translate_text']||$content['translate_audio'];
        if($enabled&&($content['provider']!=='deepl'||$content['target_language']===''))
            throw new InvalidArgumentException('invalid_translation_activation');
        if($content['save_translated_text']&&!$enabled)
            throw new InvalidArgumentException('invalid_translation_history');
        return$content;
    }
}
