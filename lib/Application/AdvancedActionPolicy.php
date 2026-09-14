<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

use DomainException;

/** Explicit player world actions use only the native observations frozen with their request. */
final class AdvancedActionPolicy
{
    public const NAMES=['item.create','gold.create','actor.spawn','actor.teleport_to_player','player.teleport','actor.restore','actor.resurrect','actor.kill'];

    public static function validCandidates(mixed $value): bool
    {
        if(!is_array($value)||array_is_list($value))return false;
        $keys=array_keys($value);sort($keys);
        if($keys!==['actors','destinations','items'])return false;
        foreach($value as$group=>$rows){
            if(!is_array($rows)||!array_is_list($rows)||count($rows)>16)return false;
            $seen=[];$idKey=$group==='destinations'?'destination_id':'record_id';
            foreach($rows as$row){
                if(!is_array($row))return false;
                $keys=array_keys($row);sort($keys);
                $expected=$group==='actors'?['kind','name','record_id']:[$idKey,'name'];sort($expected);
                if($keys!==$expected)return false;
                foreach([$idKey,'name']as$key)if(!is_string($row[$key])||$row[$key]===''||strlen($row[$key])>($key==='destination_id'?128:256)
                    ||preg_match('/[\x00-\x1f\x7f]/',$row[$key]))return false;
                if(isset($seen[$row[$idKey]]))return false;$seen[$row[$idKey]]=true;
                if($group==='actors'&&!in_array($row['kind'],['npc','creature'],true))return false;
            }
        }
        return true;
    }

    public static function eligible(array $turn): bool
    {
        return in_array($turn['execution_mode']??null,['cheat','narrator'],true)
            && in_array($turn['ui_source']??null,['lorkhan_text','lorkhan_voice'],true)
            && ($turn['speaker']['kind']??null)==='player'
            && ($turn['context']['player']['kind']??null)==='player'
            && TransferActionPolicy::sameIdentity($turn['speaker'],$turn['context']['player'])
            && !isset($turn['director_instruction_id']);
    }

    public static function available(string $name,array $turn,array $capabilities): bool
    {
        if(!in_array($name,self::NAMES,true))return true;
        if(!self::eligible($turn)||!in_array('action.confirmation',$capabilities,true)
            ||!self::validCandidates($turn['context']['advanced_actions']??null))return false;
        $group=match($name){'item.create'=>'items','actor.spawn'=>'actors','player.teleport'=>'destinations',default=>null};
        if($group===null)return true;
        $rows=$turn['context']['advanced_actions'][$group]??[];
        return is_array($rows)&&array_is_list($rows)&&count($rows)>0&&count($rows)<=16;
    }

    /** Include dead observations for resurrection; retain the original nearby selector indices. */
    public static function recipients(array $turn): array
    {
        $physical=$turn;
        foreach(($physical['context']['nearbyActors']['items']??[]) as $i=>$row)
            if(is_array($row))$physical['context']['nearbyActors']['items'][$i]['dead']=false;
        $result=ObservedActionActors::recipients($physical,true);
        if(($turn['context']['player']['kind']??null)==='player')$result['player']=$turn['context']['player'];
        return $result;
    }

    public static function validate(array $proposal,array $turn,array $capabilities): void
    {
        $name=$proposal['name'];
        if(!in_array($name,self::NAMES,true))return;
        if(!self::available($name,$turn,$capabilities)
            || !TransferActionPolicy::sameIdentity($proposal['actor'],$turn['context']['player']??null))
            throw new DomainException('provider_action_not_allowed');
        $matched=false;
        foreach(self::recipients($turn) as $actor)
            if(TransferActionPolicy::sameIdentity($proposal['target'],$actor))$matched=true;
        $kind=$proposal['target']['kind']??null;
        if(!$matched || (in_array($name,['gold.create','actor.spawn','player.teleport'],true)&&$kind!=='player')
            || (in_array($name,['actor.teleport_to_player','actor.resurrect','actor.kill'],true)&&!in_array($kind,['npc','creature'],true)))
            throw new DomainException('action_target_invalid');
        $state=TransferActionPolicy::sameIdentity($proposal['target'],$turn['target']??null)?($turn['context']['targetState']??[]):[];
        if($kind==='player')$state=$turn['context']['playerState']??[];
        foreach($turn['context']['nearbyActors']['items']??[] as$row)
            if(TransferActionPolicy::sameIdentity($proposal['target'],$row))$state=$row;
        if(($state['available']??true)===false
            || (in_array($name,['actor.kill','actor.restore'],true)&&($state['dead']??false)===true)
            || ($name==='actor.resurrect'&&array_key_exists('dead',$state)&&$state['dead']!==true))
            throw new DomainException('action_target_invalid');
        $group=match($name){'item.create'=>'items','actor.spawn'=>'actors','player.teleport'=>'destinations',default=>null};
        if($group!==null){
            $key=$group==='destinations'?'destination_id':'record_id';$matched=0;
            foreach($turn['context']['advanced_actions'][$group] as $row)
                if(is_array($row)&&is_string($row[$key]??null)&&$row[$key]===($proposal['parameters'][$key]??null))$matched++;
            if($matched!==1)throw new DomainException('action_parameters_invalid');
        }
    }

    public static function prompt(array $turn): string
    {
        if(!self::eligible($turn))return '';
        $labels=[];
        foreach(self::recipients($turn) as $selector=>$actor)$labels[$selector]=$actor['display_name'];
        return "\nWorld actions require explicit player approval and always execute through actor_id player in Narrator mode. "
            ."They may specify recipient_id from ".json_encode($labels,JSON_THROW_ON_ERROR)."; omission selects player. "
            ."Use only these native loaded-record candidates; ambiguous names require asking the player to choose, never guess: "
            .json_encode($turn['context']['advanced_actions']??[],JSON_THROW_ON_ERROR).". Killing or spawning actors may affect quests. Never claim success before the result.";
    }
}
