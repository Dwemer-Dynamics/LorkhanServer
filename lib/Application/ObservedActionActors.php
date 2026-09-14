<?php
declare(strict_types=1);

namespace LorkhanServer\Application;

/** Request-local selectors for canonical actors, never names or model-supplied identities. */
final class ObservedActionActors
{
    private static function identity(mixed $row): ?array
    {
        if (!is_array($row) || !in_array($row['kind']??null,['npc','creature','player'],true)) return null;
        foreach (['record_id','content_file','display_name'] as $field)
            if (!is_string($row[$field]??null) || $row[$field]==='') return null;
        if (!is_int($row['refnum']['index']??null) || !is_int($row['refnum']['content_file']??null)) return null;
        $cell=$row['cell']??null;
        if (!is_array($cell)) return null;
        if (($cell['kind']??null)==='interior' && is_string($cell['name']??null) && $cell['name']!=='')
            $cell=['kind'=>'interior','name'=>$cell['name']];
        elseif (($cell['kind']??null)==='exterior' && is_int($cell['grid_x']??null) && is_int($cell['grid_y']??null))
            $cell=['kind'=>'exterior','grid_x'=>$cell['grid_x'],'grid_y'=>$cell['grid_y']];
        else return null;
        return ['kind'=>$row['kind'],'record_id'=>$row['record_id'],
            'refnum'=>['index'=>$row['refnum']['index'],'content_file'=>$row['refnum']['content_file']],
            'content_file'=>$row['content_file'],'cell'=>$cell,'display_name'=>$row['display_name']];
    }

    /** Preserve nearby list indices so omitted unavailable actors never renumber later selectors. */
    public static function recipients(array $payload,bool $includeSelf=false): array
    {
        $result=[];
        if($includeSelf){
            $self=self::identity($payload['target']??null);
            if($self!==null&&in_array($self['kind'],['npc','creature'],true))$result['self']=$self;
        }
        $player=self::identity($payload['context']['player']??null);
        if (($player['kind']??null)!=='player') $player=self::identity($payload['speaker']??null);
        if (($player['kind']??null)==='player' && !TransferActionPolicy::sameIdentity($player,$payload['target']??null))
            $result['player']=$player;
        $rows=$payload['context']['nearbyActors']['items']??[];
        if (!is_array($rows) || !array_is_list($rows) || count($rows)>32) return $result;
        foreach ($rows as $index=>$row) {
            if (!is_array($row) || ($row['available']??true)===false || ($row['dead']??false)===true) continue;
            $actor=self::identity($row);
            if ($actor===null || TransferActionPolicy::sameIdentity($actor,$payload['target']??null)) continue;
            $duplicate=false;
            foreach ($result as $known) if (TransferActionPolicy::sameIdentity($known,$actor)) {$duplicate=true;break;}
            if (!$duplicate) $result['nearby:'.($index+1)]=$actor;
        }
        return $result;
    }
}
