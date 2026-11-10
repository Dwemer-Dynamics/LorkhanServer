<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

/** Source-specific narration filters; absent flags preserve the existing Lorkhan behavior. */
final class NarrationTextPolicy
{
    public static function defaults(): array
    {
        return ['remove_player_input_asterisks'=>false,'remove_npc_output_asterisks'=>false,
            'remove_player_autochat_asterisks'=>false,'keep_npc_narration_in_history'=>true];
    }

    public static function validate(mixed $settings): array
    {
        if(!is_array($settings)||($settings!==[]&&array_is_list($settings))||array_diff_key($settings,self::defaults())!==[])
            throw new \InvalidArgumentException('invalid_narration_filters');
        foreach($settings as$value)if(!is_bool($value))throw new \InvalidArgumentException('invalid_narration_filters');
        return $settings+self::defaults();
    }

    public static function spoken(string $text): string
    {
        return trim((string)preg_replace('/\s+/u',' ',preg_replace('/\*[^*]*\*/u',' ',$text)??$text));
    }

    /** CHIM's disabled-narration TTS text: asterisked directions and stray asterisks are never spoken. */
    public static function speech(string $text): string
    {
        $open=false;return self::speechText($text,$open,false);
    }

    /** Speech text for one streamed sentence; an unclosed direction continues into the next sentence. */
    public static function streamedSpeech(string $text,bool &$open): string
    {
        return self::speechText($text,$open,true);
    }

    private static function speechText(string $text,bool &$open,bool $streamed): string
    {
        $text=(string)preg_replace('/\*[^*]+\*/u',' ',($open?'*':'').$text);
        $open=$streamed&&preg_match('/(?<!\*)\*(?!\*)[^*]*$/u',$text)===1;
        if($open)$text=(string)preg_replace('/(?<!\*)\*(?!\*)[^*]*$/u','',$text);
        return trim((string)preg_replace('/\s+/u',' ',str_replace('*','',$text)));
    }
}
