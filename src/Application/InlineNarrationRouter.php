<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

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
        if(!in_array($mode,['Narrator','NPC','Text Only'],true)){
            foreach($raw as&$candidate){
                if(!is_array($candidate)||array_is_list($candidate)||!is_string($candidate['text']??null)
                    ||strlen($candidate['text'])>16_384||mb_strlen($candidate['text'],'UTF-8')>4096)continue;
                $spoken=preg_replace('/\*+[^*]+\*+/u','',$candidate['text']);
                if($spoken===null||$spoken===$candidate['text'])continue;
                $spoken=trim($spoken);
                // A stage-direction-only reply stays visible without an empty dialogue or a TTS job.
                if($spoken==='')$candidate['speech_enabled']=false;
                else$candidate['text']=$spoken;
            }
            unset($candidate);
            $result['utterances']=$raw;
            return$result;
        }
        $routed=[];
        foreach($raw as$candidate){
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
            $routed[]=['speaker'=>$identity,'text'=>$narration,'speech_enabled'=>$mode==='Narrator'];
            if($spoken!==''){$candidate['text']=$spoken;$candidate['speaker']??=$turn['payload']['target']??null;$routed[]=$candidate;}
        }
        if($routed!==[]&&count($routed)<=4)$result['utterances']=$routed;
        return$result;
    }
}
