<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\MorrowindVoiceCatalog;
use InvalidArgumentException;
use PDO;

/** Herika's global race/gender fallback table, shared by every TTS connector. */
final class TtsFallbackRepository
{
    public function __construct(private readonly PDO $db) {}

    public function matrix():array
    {
        $matrix=MorrowindVoiceCatalog::bundled()->raceFallbacks();
        foreach($this->db->query('SELECT race,gender,voiceid FROM public.core_tts_fallback')->fetchAll(PDO::FETCH_ASSOC)as$row){
            if(isset($matrix[$row['race']][$row['gender']]))$matrix[$row['race']][$row['gender']]=$row['voiceid'];
        }
        return $matrix;
    }

    public function voice(string $race,string $gender):string
    {
        $race=trim((string)preg_replace('/[^a-z0-9]+/','_',strtolower(trim($race))),'_');
        $race=match($race){'dunmer'=>'dark_elf','altmer'=>'high_elf','bosmer'=>'wood_elf',default=>$race};
        return (string)($this->matrix()[$race][strtolower(trim($gender))]??'');
    }

    /** Validate the complete global form before atomically replacing its twenty values. */
    public function save(array $matrix):void
    {
        $defaults=MorrowindVoiceCatalog::bundled()->raceFallbacks();
        if(array_diff(array_keys($matrix),array_keys($defaults))!==[]||array_diff(array_keys($defaults),array_keys($matrix))!==[])
            throw new InvalidArgumentException('invalid_voice_fallbacks');
        foreach($matrix as$race=>&$genders){
            if(!is_array($genders)||count($genders)!==2||!array_key_exists('male',$genders)||!array_key_exists('female',$genders))
                throw new InvalidArgumentException('invalid_voice_fallbacks');
            foreach($genders as&$voice){
                if(!is_string($voice)||strlen($voice)>512||!mb_check_encoding($voice,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$voice))
                    throw new InvalidArgumentException('invalid_voice_name');
                $voice=trim($voice);
            }unset($voice);
        }unset($genders);
        $this->db->beginTransaction();
        try{
            $statement=$this->db->prepare('INSERT INTO public.core_tts_fallback(race,gender,voiceid) VALUES (:race,:gender,:voice) '
                .'ON CONFLICT (race,gender) DO UPDATE SET voiceid=EXCLUDED.voiceid,updated_at=CURRENT_TIMESTAMP');
            foreach($matrix as$race=>$genders)foreach($genders as$gender=>$voice)$statement->execute(['race'=>$race,'gender'=>$gender,'voice'=>$voice]);
            $this->db->commit();
        }catch(\Throwable $error){$this->db->rollBack();throw $error;}
    }
}
