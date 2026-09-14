<?php
declare(strict_types=1);

namespace LorkhanServer\Application;

use DomainException;

/** Bind transfers to exact item instances and owners frozen in the accepted turn. */
final class TransferActionPolicy
{
    public const NAMES = ['item.give','item.take','item.pickup','gold.give','gold.take'];

    /** Rechat's speaker may be an NPC; inventory ownership still belongs to the observed player. */
    private static function player(array $turn): ?array
    {
        $player=$turn['context']['player']??($turn['speaker']??null);
        return is_array($player) && ($player['kind']??null)==='player' ? $player : null;
    }

    public static function sameIdentity(mixed $left, mixed $right): bool
    {
        if (!is_array($left) || !is_array($right)) return false;
        foreach (['kind','record_id','refnum','content_file','cell'] as $key) {
            if (!isset($left[$key],$right[$key]) || $left[$key] != $right[$key]) return false;
        }
        return in_array($left['kind'], ['npc','creature','player'], true);
    }

    /** Return only unambiguous, well-formed observations belonging to this turn's source actor. */
    private static function rows(string $name, array $turn): array
    {
        $rows = $turn['context']['action_items'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows)>128) return [];
        $location = $name==='item.pickup' ? 'ground' : (str_ends_with($name,'.take') ? 'player_inventory' : 'actor_inventory');
        $owner = $location==='player_inventory' ? self::player($turn) : ($turn['target']??null);
        $result=[]; $seen=[];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['item_id']??null)
                || preg_match('/^@?0x[0-9a-f]{1,16}$/D',$row['item_id'])!==1
                || isset($seen[$row['item_id']]) || !is_int($row['count']??null)
                || $row['count']<1 || $row['count']>2147483647
                || !is_string($row['record_id']??null) || $row['record_id']==='' || strlen($row['record_id'])>256
                || !is_string($row['name']??null) || strlen($row['name'])>256
                || !in_array($row['location']??null,['actor_inventory','player_inventory','ground'],true)
                || ($row['location']!=='ground' && !array_key_exists('owner',$row))) return [];
            $seen[$row['item_id']]=true;
            if ($row['location']!==$location) continue;
            if ($location==='ground' ? ($row['owner']??null)!==null : !self::sameIdentity($row['owner'],$owner)) continue;
            $result[]=$row;
        }
        return $result;
    }

    public static function available(string $name, array $turn, array $capabilities): bool
    {
        if (!in_array($name,self::NAMES,true)) return true;
        if (!in_array('action.confirmation',$capabilities,true)
            || !in_array($turn['target']['kind']??null,['npc','creature'],true)
            || self::player($turn)===null) return false;
        foreach (self::rows($name,$turn) as $row) {
            if (!str_starts_with($name,'gold.') || $row['record_id']==='gold_001') return true;
        }
        return false;
    }

    public static function validate(array $proposal, array $turn, array $capabilities): void
    {
        $name=$proposal['name'];
        if (!in_array($name,self::NAMES,true)) return;
        if (!self::available($name,$turn,$capabilities)
            || !self::sameIdentity($proposal['actor'],$turn['target']??null)) throw new DomainException('provider_action_not_allowed');
        $target=$proposal['target'];
        $recipientAllowed=self::sameIdentity($target,self::player($turn));
        if (in_array($name,['item.give','gold.give'],true)) {
            foreach (ObservedActionActors::recipients($turn) as $candidate) {
                if (self::sameIdentity($target,$candidate)) $recipientAllowed=true;
            }
        }
        if (!$recipientAllowed || self::sameIdentity($target,$proposal['actor'])) throw new DomainException('action_target_invalid');
        $parameters=$proposal['parameters']; $rows=self::rows($name,$turn);
        if (str_starts_with($name,'gold.')) {
            $available=0;
            foreach ($rows as $row) if ($row['record_id']==='gold_001') $available+=$row['count'];
            $amount=$parameters['amount']??null;
            if (!is_int($amount) || $amount<1 || $amount>100000 || $amount>$available) throw new DomainException('action_parameters_invalid');
            return;
        }
        foreach ($rows as $row) {
            if ($row['item_id']!==($parameters['item_id']??null)) continue;
            if ($name==='item.pickup') return;
            $count=$parameters['count']??null;
            if (is_int($count) && $count>=1 && $count<=1000 && $count<=$row['count']) return;
        }
        throw new DomainException('action_parameters_invalid');
    }
}
