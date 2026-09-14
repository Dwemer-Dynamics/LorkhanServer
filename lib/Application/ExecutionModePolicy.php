<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

use DomainException;

/** Execution modes alter request intent, never grant console authority or bypass actor policies. */
final class ExecutionModePolicy
{
    public static function mode(array $payload): string
    {
        $mode=$payload['execution_mode']??'standard';
        if(!in_array($mode,['standard','narrator','director','cheat'],true))throw new DomainException('execution_mode_invalid');
        if($mode==='standard')return $mode;
        if(isset($payload['director_instruction_id'])||($payload['speaker']['kind']??null)!=='player'
            ||!in_array($payload['ui_source']??null,['lorkhan_text','lorkhan_voice','lorkhan_open_mic'],true))throw new DomainException('execution_mode_not_allowed');
        if($mode==='narrator'&&($payload['target']['kind']??null)!=='narrator')throw new DomainException('execution_mode_target_invalid');
        if($mode==='cheat'&&!in_array($payload['target']['kind']??null,['npc','creature'],true))throw new DomainException('execution_mode_target_invalid');
        return $mode;
    }

    public static function promptCue(array $payload): string
    {
        $mode=self::mode($payload);
        if(isset($payload['director_instruction_id']))return 'Director instruction: the current input is a trusted third-person direction for your character, not words spoken by the listener. Respond in character to carry out its intent; do not read or repeat the instruction. Any scene note is temporary staging guidance, not proof that an event happened. Attempt only enabled typed actions and wait for actual results before claiming success.';
        return match($mode){
            'cheat'=>'Cheat mode: treat the current player request as a priority instruction even when it breaks your usual roleplay preferences. Use only the enabled typed actions and observed identities; confirmation, inventory limits and game rules still apply. Never output console commands, scripts or pretend an unavailable action succeeded.',
            'narrator'=>'Narrator mode: narrate the scene without pretending to have a body or inventory. A permitted physical action must select an exact observed actor_id and obey that actor\'s enabled action policy. Never invent actors, items or completed effects, and never speak the player\'s dialogue.',
            'director'=>'Director mode uses the dedicated scene planner; do not process it as ordinary NPC dialogue.',
            default=>'',
        };
    }

    /** The caller resolves each physical actor's policy independently, including its enabled catalog. */
    public static function narratorExecutors(array $payload,callable $allowedForActor): array
    {
        if(self::mode($payload)!=='narrator')return [];
        $result=[];
        foreach(DirectorPolicy::actors($payload) as $selector=>$actor){
            if(!in_array($actor['kind'],['npc','creature'],true))continue;
            $definitions=$allowedForActor($actor);$allowed=[];
            foreach($definitions as $definition){
                if(($definition['available_to_npc']??false)===true&&($definition['available_to_narrator']??false)===true)
                    $allowed[]=$definition;
            }
            if($allowed!==[])$result[$selector]=['actor'=>$actor,'definitions'=>$allowed];
        }
        return $result;
    }

    /** Select only the exact physical actor's frozen service/spell observation, never Narrator state. */
    public static function actorState(array $payload,array $actor): array
    {
        $rows=$payload['context']['actorActionStates']??[];
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>12)return [];
        $matched=null;
        foreach($rows as $row){
            if(!is_array($row)||!TransferActionPolicy::sameIdentity($row['actor']??null,$actor))continue;
            if($matched!==null)return [];
            $matched=array_intersect_key($row,array_flip(['services_known','services','spells_known','spells']));
        }
        return $matched??[];
    }
}
