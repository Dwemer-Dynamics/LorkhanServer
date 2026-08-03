<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use RuntimeException;

final class MorrowindVoiceCatalog
{
    /** @var list<array<string,mixed>> */
    private array $voices;
    /** @var array<string,array{race:string,gender:string}> */
    private array $knownActorDefaults;

    public function __construct(private readonly string $path)
    {
        $raw=file_get_contents($path);
        if(!is_string($raw))throw new RuntimeException('morrowind_voice_catalog_unavailable');
        $document=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($document)||($document['schema']??null)!=='almsivi.morrowind-voice-catalog.v1'
            ||!is_array($document['voices']??null)||!is_array($document['known_actor_defaults']??null))
            throw new RuntimeException('morrowind_voice_catalog_invalid');
        $this->voices=array_values($document['voices']);
        $this->knownActorDefaults=$document['known_actor_defaults'];
    }

    public static function bundled():self
    {
        return new self(dirname(__DIR__,2).'/resources/voices/morrowind-goty-en.json');
    }

    /** Resolve the most specific bundled voice family from one authoritative OpenMW actor snapshot. */
    public function resolve(array $identity,array $context=[]):?array
    {
        if(!in_array(strtolower((string)($identity['kind']??'')),['actor','npc'],true))return null;
        $actorState=is_array($context['targetState']??null)?$context['targetState']:[];
        $metadata=is_array($actorState['identity']??null)?$actorState['identity']:[];
        $recordId=$this->normalize((string)($identity['record_id']??''));
        $known=$this->knownActorDefaults[$recordId]??[];
        $raceValue=trim((string)($metadata['race']??''));
        if($raceValue==='')$raceValue=(string)($known['race']??'');
        $race=$this->normalizeRace($raceValue);
        $gender=$this->normalizeGender($metadata,$known);

        foreach($this->voices as$voice){$contains=$this->normalize((string)($voice['record_id_contains']??''));
            if($contains!==''&&str_contains($recordId,$contains))return$this->result($voice,'actor_catalog','high');}
        if($race===''||$gender==='')return null;
        foreach($this->voices as$voice){if(isset($voice['record_id_contains']))continue;
            if($this->normalizeRace((string)($voice['race']??''))===$race
                &&$this->normalize((string)($voice['gender']??''))===$gender)
                return$this->result($voice,'race_gender_catalog','high');}
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function voices():array{return$this->voices;}

    private function result(array $voice,string $source,string $confidence):array
    {
        return['id'=>(string)$voice['voice_id'],'key'=>(string)$voice['key'],'display_name'=>(string)$voice['display_name'],
            'language'=>'en','race'=>(string)$voice['race'],'gender'=>ucfirst((string)$voice['gender']),
            'source'=>$source,'confidence'=>$confidence,'sample_path'=>(string)$voice['sample_path'],
            'reference_text'=>(string)$voice['reference_text']];
    }

    private function normalizeGender(array $metadata,array $known):string
    {
        $genderValue=trim((string)($metadata['gender']??''));
        if($genderValue==='')$genderValue=(string)($known['gender']??'');
        $gender=$this->normalize($genderValue);
        if(in_array($gender,['male','female'],true))return$gender;
        return is_bool($metadata['is_male']??null)?($metadata['is_male']?'male':'female'):'';
    }

    private function normalizeRace(string $value):string
    {
        $race=$this->normalize($value);
        return match($race){'dunmer'=>'dark elf','altmer'=>'high elf','bosmer'=>'wood elf',default=>$race};
    }

    private function normalize(string $value):string
    {
        return trim((string)preg_replace('/[^a-z0-9]+/',' ',strtolower($value)));
    }
}
