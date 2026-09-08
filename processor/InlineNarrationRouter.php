<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

/** Routes opt-in narration and keeps disabled stage directions out of speech. */
final class InlineNarrationRouter
{
    /** @param array<string,mixed> $turn @param array<string,mixed> $result @return array<string,mixed> */
    public function route(array $turn,array $result):array
    {
        $profile=$turn['_narrator_profile']??null;$content=is_array($profile)&&!array_is_list($profile)
            ?($profile['content']??null):null;$identity=is_array($profile)&&!array_is_list($profile)
            ?($profile['actor_identity']??null):null;
        $mode=is_array($content)&&!array_is_list($content)&&($content['enabled']??false)===true
            &&is_array($identity)&&!array_is_list($identity)
            ?(string)($content['inline_narration_mode']??'Disabled'):'Disabled';
        $raw=$result['utterances']??null;
        if($raw===null&&is_string($result['text']??null))$raw=[['text'=>$result['text']]];
        if(!is_array($raw)||!array_is_list($raw))return$result;
        $filters=NarrationTextPolicy::validate($content['narration_filters']??[]);
        if(!in_array($mode,['Narrator','NPC','Text Only'],true)&&!$filters['remove_npc_output_asterisks'])return$result;
        $routed=[];
        foreach($raw as$candidate){
            if($filters['remove_npc_output_asterisks']&&!in_array($mode,['Narrator','NPC','Text Only'],true)
                &&is_array($candidate)&&is_string($candidate['text']??null)){
                $original=$candidate['text'];$spoken=NarrationTextPolicy::spoken($original);
                if($spoken!==''){
                    $candidate['text']=$candidate['_subtitle']=$candidate['_tts_text']=$spoken;
                    $candidate['_history_text']=$filters['keep_npc_narration_in_history']?$original:$spoken;
                }else continue; // A narration-only candidate has no spoken response after this filter.
                $routed[]=$candidate;continue;
            }
            if(!is_array($candidate)||array_is_list($candidate)||!is_string($candidate['text']??null)
                ||preg_match('/^\*([^*\r\n]{1,2048})\*\s*(.*)$/us',$candidate['text'],$match)!==1){
                $routed[]=$candidate;continue;
            }
            $narration=trim($match[1]);$spoken=trim($match[2]);
            if($narration===''||($mode==='Narrator'||$mode==='Text Only')&&count($routed)+(int)($spoken!=='')+1>4){
                $routed[]=$candidate;continue;
            }
            if($mode==='NPC'){
                $candidate['text']=trim($narration.' '.$spoken);$routed[]=$candidate;continue;
            }
            $routed[]=['speaker'=>$identity,'text'=>$narration,'speech_enabled'=>$mode==='Narrator']+SpeechLanguage::payload($candidate['tts_language']??null);
            if($spoken!==''){$candidate['text']=$spoken;$candidate['speaker']??=$turn['payload']['target']??null;$routed[]=$candidate;}
        }
        if($routed===[]&&$filters['remove_npc_output_asterisks'])throw new \DomainException('provider_invalid_output');
        if($routed!==[]&&count($routed)<=4)$result['utterances']=$routed;
        return$result;
    }
}
