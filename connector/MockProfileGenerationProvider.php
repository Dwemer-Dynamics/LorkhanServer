<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

/** Deterministic profile generator used only by mock and test configurations. */
final class MockProfileGenerationProvider implements ProfileGenerationProvider
{
    public function generate(array $profile, CancellationToken $cancellation): array
    {
        $cancellation->throwIfCancellationRequested();
        if(($profile['generation_mode']??'')==='npc_evolution_report')return ['report'=>"**Mock evolution report**\n* ".count($profile['history']??[]).' distinct personality snapshots supplied. No NPC profile was changed.'];
        if(in_array($profile['generation_mode']??'',['relationship_build','relationship_text_conversion'],true))return ['relationships'=>[]];
        if(($profile['generation_mode']??'')==='relationship_evaluation')
            return ['disposition_delta'=>0,'affinity_delta'=>0,'reason'=>'Mock evaluation preserves the current relationship.'];
        if(($profile['generation_mode']??'')==='memory_summary'){
            return['summary'=>mb_strcut(trim((string)($profile['memory']??'')),0,4096,'UTF-8')];
        }
        if(($profile['generation_mode']??'')==='diary_generation'){
            $name=trim((string)($profile['name']??'Unknown'))?:'Unknown';
            $context=is_array($profile['witnessed_context']??null)?$profile['witnessed_context']:[];
            return['title'=>$name.' diary','content'=>$name.' records '.count($context).' witnessed Morrowind event'.(count($context)===1?'':'s').'.'];
        }
        if(($profile['generation_mode']??'npc_profile')==='player_speech_style'){
            $inputs=is_array($profile['recent_player_inputs']??null)?$profile['recent_player_inputs']:[];
            return['speech_style'=>'Speaks in concise, direct sentences inferred from '.count($inputs).' recent player input'.(count($inputs)===1?'':'s').'.'];
        }
        if(($profile['generation_mode']??'npc_profile')==='player_autochat'){
            return['text'=>trim((string)($profile['intent']??''))];
        }
        $name=(string)($profile['name']??'Unknown');$identity=is_array($profile['actor_identity']??null)?$profile['actor_identity']:[];
        if(($profile['generation_mode']??'npc_profile')==='narrator_profile')return[
            'appearance'=>'A disembodied narrative voice without a physical form in Vvardenfell.',
            'biography'=>$name.' observes and frames the player character journey through Morrowind.',
            'personality'=>'Perceptive, measured, atmospheric, and restrained.',
            'speech_style'=>'Uses concise sensory prose grounded in the immediate Morrowind scene.',
            'occupation'=>'Narrator of the player character journey',
            'goals'=>'Clarify scenes and actions without taking agency from the player or speaking as an NPC.',
            'relationships'=>'An impartial but attentive narrative presence accompanying the player.',
            'notes'=>'Deterministic mock narrator generation; replace or regenerate with a configured live provider.',
        ];
        $record=(string)($identity['record_id']??'an unbound Morrowind actor');$contentFile=(string)($identity['content_file']??'an unknown content file');
        $notes=($profile['generation_mode']??'npc_profile')==='npc_profile_backfill'
            ?'Deterministic mock backfill from '.count((array)($profile['recent_events']??[])).' recent events; replace or regenerate with a configured live provider.'
            :'Deterministic mock generation; replace or regenerate with a configured live provider.';
        return (in_array('skills', (array)($profile['dynamic_fields']??[]), true) ? ['skills'=>'Practical skills informed by witnessed events.'] : []) + [
            'appearance'=>$name.' has an appearance that should be refined from observed in-game context.',
            'biography'=>$name.' is a Morrowind character identified as '.$record.' from '.$contentFile.'.',
            'personality'=>$name.' is attentive, guarded, and shaped by life in Vvardenfell.',
            'speech_style'=>'Speaks briefly in the grounded cadence of a Morrowind resident.',
            'occupation'=>'Resident of Vvardenfell',
            'goals'=>'Protect personal interests and respond credibly to the player.',
            'relationships'=>'Relationships should follow current scoped memories and disposition.',
            'notes'=>$notes,
        ];
    }
}
