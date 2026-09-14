<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

use DomainException;

/** Known spells and targets come from the accepted observation; native release checks remain authoritative. */
final class SpellActionPolicy
{
    public static function available(string $name,array $turn,array $capabilities): bool
    {
        if($name!=='spell.cast')return true;
        $state=$turn['context']['targetState']??[];
        return in_array('action.confirmation',$capabilities,true)
            && in_array($turn['target']['kind']??null,['npc','creature'],true)
            && ($state['spells_known']??false)===true && is_array($state['spells']??null)
            && array_is_list($state['spells']) && count($state['spells'])>0 && count($state['spells'])<=128;
    }

    public static function validate(array $proposal,array $turn,array $capabilities): void
    {
        if($proposal['name']!=='spell.cast')return;
        if(!self::available('spell.cast',$turn,$capabilities)
            || !TransferActionPolicy::sameIdentity($proposal['actor'],$turn['target']??null))
            throw new DomainException('provider_action_not_allowed');
        $targetAllowed=TransferActionPolicy::sameIdentity($proposal['target'],$proposal['actor']);
        foreach(ObservedActionActors::recipients($turn) as $actor)
            if(TransferActionPolicy::sameIdentity($proposal['target'],$actor))$targetAllowed=true;
        if(!$targetAllowed)throw new DomainException('action_target_invalid');
        foreach($turn['context']['targetState']['spells'] as $spell)
            if(is_array($spell)&&is_string($spell['spell_id']??null)
                && $spell['spell_id']===($proposal['parameters']['spell_id']??null))return;
        throw new DomainException('action_parameters_invalid');
    }
}
