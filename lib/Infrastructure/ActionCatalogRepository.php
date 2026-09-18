<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

/**
 * Read-only access to the server-owned action catalog and current action policy.
 */
final class ActionCatalogRepository
{
    private const DEFAULT_FOLLOWUP_PROMPT = 'Respond briefly to the completed action result. Acknowledge the observed outcome without proposing or performing another action.';

    public function __construct(private readonly PDO $db) {}

    /** @return list<array<string,mixed>> */
    public function enabledDefinitions(): array
    {
        $rows = $this->db->query(
            'SELECT action_name, tier, display_name, category, sort_order, description, parameter_schema, result_schema, '
            . 'client_capability, server_owned, terminal_result_required, continuation_capable, confirmation_mode, confirmation_default, '
            . "followup_default, followup_actions_supported, cooldown_seconds, COALESCE((to_jsonb(action_catalog)->>'available_to_narrator')::boolean,false) AS available_to_narrator "
            . 'FROM action_catalog WHERE enabled = true ORDER BY category, sort_order, action_name'
        )->fetchAll();

        return array_map(fn(array $row): array => $this->definition($row), $rows);
    }

    /**
     * Loads the active session boundary, enabled catalog rows, and the most-specific current policy.
     * The returned definitions are not yet policy-filtered; ActionPolicyValidator owns that decision.
     *
     * @return array{session:array<string,mixed>,policy:?array<string,mixed>,definitions:list<array<string,mixed>>}
     */
    public function loadForSession(string $sessionId, int $generation): array
    {
        $stmt = $this->db->prepare(
            "SELECT session_id, installation_id, profile_id, playthrough_id, generation, capabilities, enabled_actions "
            . "FROM sessions WHERE session_id = :session AND generation = :generation AND state = 'active'"
        );
        $stmt->execute(['session' => $sessionId, 'generation' => $generation]);
        $session = $stmt->fetch();
        if (!$session) {
            throw new RuntimeException('unknown_session');
        }

        $session['generation'] = (int) $session['generation'];
        $session['capabilities'] = $this->parsePgArray((string) $session['capabilities']);
        $session['enabled_actions'] = $this->parsePgArray((string) $session['enabled_actions']);

        return [
            'session' => $session,
            'policy' => $this->currentPolicy((string) $session['installation_id'], (string) $session['profile_id']),
            'definitions' => $this->enabledDefinitions(),
            'last_action_at' => $this->lastActionTimes($sessionId, $generation),
        ];
    }

    /**
     * A profile policy overrides an installation-wide policy. Ordering is deterministic when legacy
     * data contains more than one current policy at the same specificity.
     *
     * @return array<string,mixed>|null
     */
    public function currentPolicy(string $installationId, ?string $profileId): ?array
    {
        $installation = $this->policyAtScope($installationId, null);
        $profile = $profileId === null ? null : $this->policyAtScope($installationId, $profileId);
        if ($installation === null && $profile === null) return null;

        $selected = $profile ?? $installation;
        $selected['content'] = $this->mergePolicyContents(
            is_array($installation['content'] ?? null) ? $installation['content'] : [],
            is_array($profile['content'] ?? null) ? $profile['content'] : []
        );
        $selected['sources'] = ['installation' => $installation, 'profile' => $profile];
        return $selected;
    }

    /** @return array{action_id:string,allow_action:bool,depth:int,prompt:string}|null */
    public function continuation(string $actionId,string $sessionId,int $generation):?array
    {
        $statement=$this->db->prepare("SELECT a.action_id,a.followup_actions_allowed,a.followup_depth,a.followup_prompt "
            ."FROM action_delivery d JOIN action_results r ON r.action_id=d.action_id JOIN action_intents a ON a.action_id=d.action_id "
            ."WHERE d.action_id=:action AND a.session_id=:session AND a.generation=:generation AND a.followup_enabled "
            ."AND d.continuation_state='eligible' AND d.terminal_at IS NOT NULL");
        $statement->execute(['action'=>$actionId,'session'=>$sessionId,'generation'=>$generation]);
        $row=$statement->fetch();
        return$row?['action_id'=>(string)$row['action_id'],'allow_action'=>$this->boolean($row['followup_actions_allowed']),
            'depth'=>min(1,(int)$row['followup_depth']+1),'prompt'=>(string)$row['followup_prompt']]:null;
    }

    /** Atomically bind an eligible action result to its now-persisted continuation turn. */
    public function consumeContinuation(string $actionId,string $turnId,string $sessionId,int $generation):bool
    {
        $statement=$this->db->prepare("UPDATE action_delivery d SET continuation_state='consumed',continuation_turn_id=:turn,updated_at=clock_timestamp() "
            ."FROM action_results r,action_intents a WHERE d.action_id=:action AND r.action_id=d.action_id AND a.action_id=d.action_id "
            ."AND a.session_id=:session AND a.generation=:generation AND a.followup_enabled "
            ."AND d.continuation_state='eligible' AND d.terminal_at IS NOT NULL");
        $statement->execute(['action'=>$actionId,'turn'=>$turnId,'session'=>$sessionId,'generation'=>$generation]);
        return$statement->rowCount()===1;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function definition(array $row): array
    {
        $name = (string) $row['action_name'];
        $parameterSchema = $this->json($row['parameter_schema']);
        $resultSchema = $this->json($row['result_schema']);
        $continuationCapable = $this->boolean($row['continuation_capable']);
        $followupDefault = $this->boolean($row['followup_default']);
        $followupActionsSupported = $this->boolean($row['followup_actions_supported']);
        $cooldownSeconds = (int) $row['cooldown_seconds'];
        $metadata = [
            'tier' => (int) $row['tier'],
            'client_capability' => (string) $row['client_capability'],
            'result_schema' => $resultSchema,
            'server_owned' => $this->boolean($row['server_owned']),
            'terminal_result_required' => $this->boolean($row['terminal_result_required']),
            'continuation_capable' => $continuationCapable,
            'confirmation_mode' => (string) $row['confirmation_mode'],
            'followup' => [
                'enabled' => $followupDefault,
                'arg_name' => 'result',
                'prompt' => self::DEFAULT_FOLLOWUP_PROMPT,
                'use_functions_again' => false,
            ],
            'custom_config' => [],
            'followup_actions_supported' => $followupActionsSupported,
            'cooldown_seconds' => $cooldownSeconds,
        ];

        return [
            // HerikaServer-compatible action row fields. Policies persist this complete shape.
            'code_name' => $name,
            'action_name' => (string) $row['display_name'],
            'description' => (string) $row['description'],
            'return_message' => '',
            'available_to_npc' => !in_array($name,\LorkhanServer\Application\AdvancedActionPolicy::NAMES,true),
            'available_to_followers' => false,
            'available_to_narrator' => $this->boolean($row['available_to_narrator'] ?? false),
            'is_activated' => true,
            'parameters_json' => $parameterSchema,
            'metadata' => $metadata,
            'game_function' => true,
            'import_version' => 1,
            'script_proxy_program' => null,
            'is_custom' => false,
            'has_base' => true,

            // Typed LORKHAN aliases retained for runtime and client protocol validation.
            'name' => $name,
            'tier' => (int) $row['tier'],
            'enabled'=>true,
            'display_name'=>(string)$row['display_name'],
            'category'=>(string)$row['category'],
            'sort_order'=>(int)$row['sort_order'],
            'parameter_schema' => $parameterSchema,
            'result_schema' => $resultSchema,
            'client_capability' => (string) $row['client_capability'],
            'game_function' => true,
            'source' => 'base',
            'server_owned' => $this->boolean($row['server_owned']),
            'terminal_result_required'=>$this->boolean($row['terminal_result_required']),
            'continuation_capable'=>$continuationCapable,
            'confirmation_mode'=>(string)$row['confirmation_mode'],
            'confirmation_default'=>$this->boolean($row['confirmation_default']),
            'followup_default'=>$followupDefault,
            'followup_prompt'=>self::DEFAULT_FOLLOWUP_PROMPT,
            'followup_actions_supported'=>$followupActionsSupported,
            'cooldown_seconds'=>$cooldownSeconds,
        ];
    }

    /** Return the one active policy document at an exact installation or NPC scope. */
    public function policyAtScope(string $installationId,?string $profileId):?array
    {
        $sql="SELECT c.configuration_id,c.installation_id,c.profile_id,c.name,c.current_revision AS revision,r.content "
            ."FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id "
            ."AND r.revision=c.current_revision WHERE c.installation_id=:installation AND c.kind='action_policy' "
            ."AND c.deleted_at IS NULL ".($profileId===null?'AND c.profile_id IS NULL ':'AND c.profile_id=:profile ')
            ."ORDER BY c.current_revision DESC,c.created_at DESC,c.configuration_id DESC LIMIT 1";
        $statement=$this->db->prepare($sql);$parameters=['installation'=>$installationId];
        if($profileId!==null)$parameters['profile']=$profileId;$statement->execute($parameters);$row=$statement->fetch();
        if(!$row)return null;$row['revision']=(int)$row['revision'];$row['content']=$this->json($row['content']);return$row;
    }

    /** Merge sparse NPC overrides over the installation policy without broadening catalog contracts. */
    private function mergePolicyContents(array $installation,array $profile):array
    {
        $result=$installation;
        foreach($profile as$key=>$value){
            if($key!=='actions'){$result[$key]=$value;continue;}
            $base=is_array($result['actions']??null)&&!array_is_list($result['actions'])?$result['actions']:[];
            if(is_array($value)&&!array_is_list($value))foreach($value as$name=>$override){
                $prior=is_array($base[$name]??null)&&!array_is_list($base[$name])?$base[$name]:[];
                $base[$name]=is_array($override)&&!array_is_list($override)?array_replace($prior,$override):$override;
            }
            $result['actions']=$base;
        }
        return$result;
    }

    /** @return array<string,string> */
    private function lastActionTimes(string $sessionId,int $generation):array
    {
        $statement=$this->db->prepare('SELECT action_name,max(emitted_at) AS emitted_at FROM action_intents '
            .'WHERE session_id=:session AND generation=:generation GROUP BY action_name');
        $statement->execute(['session'=>$sessionId,'generation'=>$generation]);$result=[];
        foreach($statement->fetchAll()as$row)$result[(string)$row['action_name']]=(string)$row['emitted_at'];
        return$result;
    }

    /** @return array<string,mixed> */
    private function json(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('invalid_action_configuration');
        }
        return $decoded;
    }

    /** @return list<string> */
    private function parsePgArray(string $value): array
    {
        if ($value === '{}') {
            return [];
        }
        return array_values(str_getcsv(trim($value, '{}'), ',', '"', '\\'));
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
