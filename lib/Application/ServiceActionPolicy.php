<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

use DomainException;

/** Offered service bits filter prompts; the native engine rechecks live dialogue refusals before opening. */
final class ServiceActionPolicy
{
    public const NAMES=['service.barter','service.training','service.spells','service.travel',
        'service.spellmaking','service.enchanting','service.repair'];

    public static function available(string $name,array $turn): bool
    {
        if(!in_array($name,self::NAMES,true))return true;
        $state=$turn['context']['targetState']??[];
        $services=$state['services']??null;
        return in_array($turn['target']['kind']??null,['npc','creature'],true)
            && ($state['services_known']??false)===true && is_array($services) && array_is_list($services)
            && count($services)<=7 && in_array(substr($name,8),$services,true);
    }

    public static function validate(array $proposal,array $turn): void
    {
        if(!in_array($proposal['name'],self::NAMES,true))return;
        $player=$turn['context']['player']??($turn['speaker']??null);
        if(!self::available($proposal['name'],$turn) || ($player['kind']??null)!=='player'
            || !TransferActionPolicy::sameIdentity($proposal['actor'],$turn['target']??null)
            || !TransferActionPolicy::sameIdentity($proposal['target'],$player))
            throw new DomainException('provider_action_not_allowed');
    }
}
