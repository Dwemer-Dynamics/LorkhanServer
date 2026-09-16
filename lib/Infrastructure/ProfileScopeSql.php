<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

/** Internal SQL fragments only: callers supply fixed aliases/expressions, never user input. */
final class ProfileScopeSql
{
    public static function visible(string $alias,string $playthrough):string
    {
        return '('.self::matches($alias,$playthrough)." OR ($alias.playthrough_id IS NULL AND "
            ."$alias.actor_identity->>'kind' IN ('narrator','template')))";
    }

    public static function current(string $alias):string
    {
        return self::visible($alias,"(SELECT scope_session.playthrough_id FROM sessions scope_session "
            ."WHERE scope_session.installation_id=$alias.installation_id AND scope_session.character_id IS NOT NULL "
            ."ORDER BY scope_session.generation DESC LIMIT 1)");
    }

    public static function matches(string $alias,string $playthrough,bool $allowNarrator=false):string
    {
        $shared=$allowNarrator?"$alias.actor_identity->>'kind'='narrator' OR ":'';
        return "($alias.playthrough_id=$playthrough OR ($alias.playthrough_id IS NULL AND ($shared"
            ."NOT EXISTS(SELECT 1 FROM character_playthrough_bindings scope_binding "
            ."WHERE scope_binding.installation_id=$alias.installation_id))))";
    }
}
