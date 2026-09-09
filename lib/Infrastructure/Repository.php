<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\ActionPolicyValidator;
use LorkhanServer\Application\SpeechLanguage;
use PDO;
use Throwable;

final class Repository
{
    private const SERVER_CAPABILITIES = ['dialogue.text', 'speech.say', 'speech.listen', 'controls.session', 'debug.commands.v1', 'action.inspect.report', 'action.ai.follow',
        'action.ai.stop', 'action.ai.approach', 'action.ai.wait', 'action.ai.travel', 'action.ai.escort', 'action.ai.face', 'action.ai.wander',
        'action.combat.start', 'action.combat.stop', 'action.animation.play', 'action.item.equip', 'action.item.unequip', 'action.item.use',
        'action.inventory.inspect','action.confirmation','action.result-followup'];
    private const ENABLED_ACTIONS = ['inspect.report','inventory.inspect','ai.follow','ai.stop','ai.approach','ai.wait','ai.travel','ai.escort',
        'ai.face','ai.wander','combat.start','combat.stop','animation.play','item.equip','item.unequip','item.use'];
    public function __construct(
        private readonly PDO $db,
        private readonly int $eventReplayLimit = 256,
        private readonly ?ActionCatalogRepository $actionCatalog = null,
        private readonly ?ActionPolicyValidator $actionPolicy = null,
        private readonly ?DefaultConnectorProvisioner $defaultConnectors = null,
        private readonly ?EventLogRepository $eventLogRepository = null,
    ) {
        if ($eventReplayLimit < 1 || $eventReplayLimit > 1000) throw new \InvalidArgumentException('Invalid event replay limit.');
        if (($actionCatalog === null) !== ($actionPolicy === null)) throw new \InvalidArgumentException('Incomplete action policy composition.');
    }

    public function migrate(string $migration): void
    {
        $this->db->exec(file_get_contents($migration) ?: throw new \RuntimeException('Cannot read migration.'));
    }

    /** Read one eligible result follow-up; acceptTurn consumes it atomically with the new turn. */
    public function actionContinuation(string $actionId,string $sessionId,int $generation):?array
    {
        return $this->actionCatalog?->continuation($actionId,$sessionId,$generation);
    }

    public function ensureInstallation(string $installationId, string $tokenHash, ?string $macKey = null): void
    {
        $insert = $this->db->prepare('INSERT INTO installations (installation_id, token_fingerprint) VALUES (:id, :token) '
            . 'ON CONFLICT (installation_id) DO NOTHING RETURNING installation_id');
        $insert->execute(['id' => $installationId, 'token' => $tokenHash]);
        $created = $insert->fetchColumn() !== false;
        if (!$created) {
            $this->db->prepare('UPDATE installations SET last_seen_at=clock_timestamp() WHERE installation_id=:id')
                ->execute(['id' => $installationId]);
        }
        // Migration 004 makes pairing credentials server-owned. Seed the configured hash once;
        // subsequent requests only update last_seen_at and never reset rotated/revoked state.
        $pairing = $this->db->prepare("INSERT INTO pairing_tokens (pairing_token_id, installation_id, token_hash, mac_key, state) "
            . "SELECT :pairing, :id, CAST(:token AS char(64)), decode(CAST(:token AS text), 'hex'), 'active' WHERE NOT EXISTS (SELECT 1 FROM pairing_tokens WHERE installation_id = :id) "
            . "ON CONFLICT DO NOTHING");
        $pairing->execute(['pairing' => Uuid::v4(), 'id' => $installationId, 'token' => $tokenHash]);
        if($macKey!==null){$update=$this->db->prepare('UPDATE pairing_tokens SET mac_key=:key WHERE installation_id=:id AND state=\'active\'');$update->bindValue(':key',$macKey,PDO::PARAM_LOB);$update->bindValue(':id',$installationId);$update->execute();}
        if ($created && $this->defaultConnectors !== null) $this->defaultConnectors->provision($installationId);
        if($created)(new OghmaCatalogImporter($this->db))->provisionInstallation($installationId);
    }

    public function consumeRateLimit(string $key, int $limit, int $windowSeconds): bool
    {
        return $this->transaction(function () use ($key, $limit, $windowSeconds): bool {
            $bucket = hash('sha256', $key);
            $stmt = $this->db->prepare('SELECT window_started_at, request_count FROM rate_limit_buckets WHERE bucket_key = :key FOR UPDATE');
            $stmt->execute(['key' => $bucket]);
            $row = $stmt->fetch();
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (!$row || $now->getTimestamp() - (new \DateTimeImmutable($row['window_started_at']))->getTimestamp() >= $windowSeconds) {
                $this->db->prepare('INSERT INTO rate_limit_buckets (bucket_key, window_started_at, request_count) VALUES (:key, clock_timestamp(), 1) '
                    . 'ON CONFLICT (bucket_key) DO UPDATE SET window_started_at = EXCLUDED.window_started_at, request_count = 1')
                    ->execute(['key' => $bucket]);
                return true;
            }
            if ((int) $row['request_count'] >= $limit) return false;
            $this->db->prepare('UPDATE rate_limit_buckets SET request_count = request_count + 1 WHERE bucket_key = :key')
                ->execute(['key' => $bucket]);
            return true;
        });
    }

    public function transaction(callable $callback): mixed
    {
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $result = $callback();
            if ($owns) $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function serializedIdempotency(string $installationId, string $key, string $route, callable $callback): mixed
    {
        $lockKey = $installationId . '|' . $route . '|' . $key;
        $lock = $this->db->prepare('SELECT pg_advisory_lock(hashtext(:key))');
        $unlock = $this->db->prepare('SELECT pg_advisory_unlock(hashtext(:key))');
        $lock->execute(['key' => $lockKey]);
        try {
            return $callback();
        } finally {
            $unlock->execute(['key' => $lockKey]);
        }
    }

    public function idempotent(string $installationId, string $key, string $route, string $hash): ?array
    {
        $stmt = $this->db->prepare('SELECT semantic_hash, http_status, response_body FROM idempotency_requests '
            . 'WHERE installation_id = :installation AND idempotency_key = :key AND route = :route');
        $stmt->execute(['installation' => $installationId, 'key' => $key, 'route' => $route]);
        $row = $stmt->fetch();
        if (!$row) return null;
        if (!hash_equals((string) $row['semantic_hash'], $hash)) throw new \DomainException('duplicate_conflict');
        return ['status' => (int) $row['http_status'], 'body' => $this->json($row['response_body'])];
    }

    public function remember(string $installationId, string $key, string $route, string $hash, int $status, array $body): void
    {
        $stmt = $this->db->prepare('INSERT INTO idempotency_requests '
            . '(installation_id, idempotency_key, route, semantic_hash, http_status, response_body) '
            . 'VALUES (:installation, :key, :route, :hash, :status, CAST(:body AS jsonb)) ON CONFLICT DO NOTHING');
        $stmt->execute(['installation' => $installationId, 'key' => $key, 'route' => $route, 'hash' => $hash,
            'status' => $status, 'body' => $this->encode($body)]);
        $stored = $this->idempotent($installationId, $key, $route, $hash);
        if ($stored === null || $stored['status'] !== $status || $stored['body'] != $body) throw new \DomainException('duplicate_conflict');
    }

    public function createSession(array $message, string $sessionId, string $tokenHash, ?string $macKey=null): array
    {
        return $this->transaction(function () use ($message, $sessionId, $tokenHash,$macKey): array {
            $this->ensureInstallation($message['installation_id'], $tokenHash,$macKey);
            $lock = $this->db->prepare('SELECT installation_id FROM installations WHERE installation_id = :id FOR UPDATE');
            $lock->execute(['id' => $message['installation_id']]);
            $this->ensureSessionOwners($message);
            $latest = $this->db->prepare('SELECT MAX(generation) FROM sessions WHERE installation_id = :id');
            $latest->execute(['id' => $message['installation_id']]);
            $previous = $latest->fetchColumn();
            if ($previous !== null && $message['generation'] <= (int) $previous) throw new \UnexpectedValueException('stale_generation');
            $active=$this->db->prepare("SELECT session_id FROM sessions WHERE installation_id=:id AND state='active' FOR UPDATE");
            $active->execute(['id'=>$message['installation_id']]);$activeSessions=$active->fetchAll(PDO::FETCH_COLUMN);
            foreach($activeSessions as $activeSession)$this->cancelOutstandingTurns((string)$activeSession,'session_replaced');
            $this->db->prepare("UPDATE sessions SET state = 'replaced', ended_at = clock_timestamp() "
                . "WHERE installation_id = :id AND state = 'active'")->execute(['id' => $message['installation_id']]);
            $capabilities = array_values(array_intersect(self::SERVER_CAPABILITIES, $message['runtime']['capabilities']));
            $stt=$this->db->prepare("SELECT r.content->>'driver' FROM installation_provider_selections s JOIN configuration_sets c "
                . "ON c.configuration_id=s.configuration_id AND c.deleted_at IS NULL JOIN configuration_revisions r "
                . "ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE s.installation_id=:id AND s.provider_kind='stt_provider'");
            $stt->execute(['id'=>$message['installation_id']]);$sttDriver=$stt->fetchColumn();
            if($sttDriver===false||$sttDriver==='none')$capabilities=array_values(array_diff($capabilities,['speech.listen']));
            $actions=[];foreach(self::ENABLED_ACTIONS as $action){$capability='action.'.$action;if(in_array($capability,$capabilities,true))$actions[]=$action;}
            $stmt = $this->db->prepare('INSERT INTO sessions '
                . '(session_id, installation_id, profile_id, playthrough_id, generation, content_fingerprint, openmw_version, '
                . 'openmw_commit, lua_api_revision, client_version, platform, capabilities, enabled_actions, created_at) VALUES '
                . '(:session, :installation, :profile, :playthrough, :generation, :fingerprint, :version, :commit, :api, '
                . ':client, :platform, CAST(:capabilities AS text[]), CAST(:actions AS text[]), :created)');
            $r = $message['runtime'];
            $stmt->execute(['session' => $sessionId, 'installation' => $message['installation_id'], 'profile' => $message['profile_id'],
                'playthrough' => $message['playthrough_id'], 'generation' => $message['generation'], 'fingerprint' => $message['content_fingerprint'],
                'version' => $r['openmw_version'], 'commit' => $r['openmw_commit'], 'api' => $r['lua_api_revision'], 'client' => $r['client_version'],
                'platform' => $r['platform'], 'capabilities' => $this->pgArray($capabilities), 'actions' => $this->pgArray($actions),
                'created' => $message['created_at']]);
            $this->source($message['message_id'], $message['installation_id'], $sessionId, $message['generation'], 'session.init',
                $message['created_at'], $message['schema'], null, null, null, $message);
            return ['session_id' => $sessionId, 'generation' => $message['generation'], 'capabilities' => $capabilities];
        });
    }

    public function session(string $sessionId, int $generation, bool $lock = false, bool $allowEnded = false): array
    {
        $stmt = $this->db->prepare('SELECT * FROM sessions WHERE session_id = :id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $sessionId]);
        $row = $stmt->fetch();
        if (!$row || (!$allowEnded && $row['state'] !== 'active')) throw new \OutOfBoundsException('unknown_session');
        if ((int) $row['generation'] !== $generation) throw new \UnexpectedValueException('stale_generation');
        $row['capabilities'] = $this->parsePgArray((string) $row['capabilities']);
        $row['enabled_actions'] = $this->parsePgArray((string) $row['enabled_actions']);
        return $row;
    }

    public function sessionInstallation(string $sessionId):string{$s=$this->db->prepare('SELECT installation_id FROM sessions WHERE session_id=:id');$s->execute(['id'=>$sessionId]);$v=$s->fetchColumn();if($v===false)throw new \OutOfBoundsException('unknown_session');return(string)$v;}

    /** Claim one operator-authored, typed debug command for the current game generation. */
    public function claimDebugCommand(array $message):?array
    {
        return $this->transaction(function()use($message):?array{
            $session=$this->session((string)$message['session_id'],(int)$message['generation'],true);
            if(!in_array('debug.commands.v1',$session['capabilities'],true))throw new \DomainException('debug_commands_unsupported');
            $this->db->prepare("UPDATE debug_commands SET state='expired',completed_at=clock_timestamp(),reason_code='command_expired' "
                ."WHERE session_id=:session AND generation=:generation AND state IN ('queued','delivered') AND expires_at<=clock_timestamp()")
                ->execute(['session'=>$message['session_id'],'generation'=>$message['generation']]);
            $select=$this->db->prepare("SELECT command_id,command_name,parameters,expires_at FROM debug_commands "
                ."WHERE session_id=:session AND generation=:generation AND state='queued' AND expires_at>clock_timestamp() "
                ."ORDER BY created_at,command_id LIMIT 1 FOR UPDATE SKIP LOCKED");
            $select->execute(['session'=>$message['session_id'],'generation'=>$message['generation']]);
            $row=$select->fetch();if(!$row)return null;
            $this->db->prepare("UPDATE debug_commands SET state='delivered',delivered_at=clock_timestamp() WHERE command_id=:id AND state='queued'")
                ->execute(['id'=>$row['command_id']]);
            return['command_id'=>(string)$row['command_id'],'name'=>(string)$row['command_name'],
                'parameters'=>$this->json($row['parameters']),'expires_at'=>$this->utc((string)$row['expires_at'])];
        });
    }

    /** Persist exactly one terminal result for an operator debug command. */
    public function completeDebugCommand(array $message):bool
    {
        return $this->transaction(function()use($message):bool{
            $session=$this->session((string)$message['session_id'],(int)$message['generation'],true);
            if(!in_array('debug.commands.v1',$session['capabilities'],true))throw new \DomainException('debug_commands_unsupported');
            $select=$this->db->prepare('SELECT installation_id,session_id,generation,state,result_message_id,expires_at FROM debug_commands WHERE command_id=:id FOR UPDATE');
            $select->execute(['id'=>$message['command_id']]);$row=$select->fetch();
            if(!$row)throw new \OutOfBoundsException('debug_command_not_found');
            if((string)$row['installation_id']!==(string)$session['installation_id']
                ||(string)$row['session_id']!==(string)$message['session_id']||(int)$row['generation']!==(int)$message['generation'])
                throw new \UnexpectedValueException('stale_generation');
            if($row['result_message_id']!==null){
                if(hash_equals((string)$row['result_message_id'],(string)$message['message_id']))return true;
                throw new \DomainException('debug_command_terminal');
            }
            if($row['state']!=='delivered')throw new \DomainException('debug_command_not_delivered');
            if(new \DateTimeImmutable((string)$row['expires_at'])<=new \DateTimeImmutable((string)$message['completed_at'])){
                $this->db->prepare("UPDATE debug_commands SET state='expired',completed_at=clock_timestamp(),reason_code='command_expired' WHERE command_id=:id")
                    ->execute(['id'=>$message['command_id']]);
                throw new \DomainException('debug_command_expired');
            }
            $update=$this->db->prepare('UPDATE debug_commands SET state=:state,completed_at=:completed,result_message_id=:message,'
                .'reason_code=:reason,observed=CAST(:observed AS jsonb) WHERE command_id=:id AND state=\'delivered\'');
            $update->execute(['state'=>$message['status'],'completed'=>$message['completed_at'],'message'=>$message['message_id'],
                'reason'=>$message['reason_code'],'observed'=>$this->encode($message['observed']),'id'=>$message['command_id']]);
            if($update->rowCount()!==1)throw new \DomainException('debug_command_terminal');
            return false;
        });
    }

    public function endSession(string $sessionId, string $requestId): array
    {
        return $this->transaction(function () use ($sessionId, $requestId): array {
            $stmt = $this->db->prepare('SELECT installation_id, generation, state FROM sessions WHERE session_id = :id FOR UPDATE');
            $stmt->execute(['id' => $sessionId]);
            $session = $stmt->fetch();
            if (!$session) throw new \OutOfBoundsException('unknown_session');
            $route = '/sessions/' . $sessionId;
            $hash = hash('sha256', $sessionId);
            $cached = $this->idempotent($session['installation_id'], $requestId, $route, $hash);
            if ($cached !== null) return [$cached['status'], $cached['body']];
            $ended = $session['state'] === 'active';
            if ($ended) {
                $this->cancelOutstandingTurns($sessionId,'session_ended');
                $this->db->prepare("UPDATE sessions SET state = 'ended', ended_at = clock_timestamp() WHERE session_id = :id")
                    ->execute(['id' => $sessionId]);
            }
            $body = ['schema' => 'lorkhan.session.ended.v1', 'request_id' => $requestId, 'session_id' => $sessionId,
                'generation' => (int) $session['generation'], 'ended' => $ended];
            $this->remember($session['installation_id'], $requestId, $route, $hash, 200, $body);
            return [200, $body];
        });
    }

    /** @param array<string,mixed>|null $providerInput @param array<string,mixed>|null $promptTrace */
    public function acceptTurn(array $m, ?array $providerInput = null, ?array $promptTrace = null,
        ?string $idempotencyHash = null, ?array $idempotencyResponse = null, ?array $directAction = null, ?array $dynamicPlan = null): array
    {
        return $this->transaction(function () use (
            $m, $providerInput, $promptTrace, $idempotencyHash, $idempotencyResponse, $directAction, $dynamicPlan
        ): array {
            $session = $this->session($m['session_id'], $m['generation'], true);
            foreach (['installation_id' => 'installation_id', 'profile_id' => 'profile_id', 'playthrough_id' => 'playthrough_id',
                'content_fingerprint' => 'content_fingerprint'] as $request => $stored) {
                if ((string) $m[$request] !== (string) $session[$stored]) throw new \UnexpectedValueException('stale_generation');
            }
            $p = $m['payload'];
            $validatedDirectAction = null;
            if ($directAction !== null) {
                if ($this->actionCatalog === null || $this->actionPolicy === null) throw new \DomainException('action_disabled');
                $actionTarget = $p['speaker'];
                if (array_key_exists('target', $directAction)) {
                    if (!in_array($directAction['name'], ['ai.face','combat.start','combat.stop'], true)
                        || !$this->contextContainsIdentity($p['context'], $directAction['target'])
                        || $this->sameIdentity($p['target'], $directAction['target'])) {
                        throw new \DomainException('action_target_invalid');
                    }
                    $actionTarget = $directAction['target'];
                }
                $proposal = ['name' => $directAction['name'], 'tier' => $directAction['tier'],
                    'parameters' => $directAction['parameters'], 'actor' => $p['target'], 'target' => $actionTarget];
                $validatedDirectAction = $this->actionPolicy->validate($proposal,
                    $this->actionCatalog->loadForSession($m['session_id'], $m['generation']));
            }
            $stmt = $this->db->prepare('INSERT INTO turns (turn_id, request_id, message_id, session_id, generation, runtime_generation, input_kind, '
                . 'input_language, input_text, speaker, target, audience, context, state, accepted_at) VALUES '
                . '(:turn, :request, :message, :session, :generation, :runtime_generation, :kind, :language, :text, CAST(:speaker AS jsonb), '
                . 'CAST(:target AS jsonb), CAST(:audience AS jsonb), CAST(:context AS jsonb), :state, :accepted)');
            $stmt->execute(['turn' => $m['turn_id'], 'request' => $m['request_id'], 'message' => $m['message_id'], 'session' => $m['session_id'],
                'generation' => $m['generation'], 'runtime_generation' => $m['runtime_generation'], 'kind' => $p['input']['kind'], 'language' => $p['input']['language'], 'text' => $p['input']['text'],
                'speaker' => $this->encode($p['speaker']), 'target' => $this->encode($p['target']), 'audience' => $this->encode($p['audience']),
                'context' => $this->encode($p['context']), 'state' => 'accepted', 'accepted' => $m['created_at']]);
            if(is_array($m['_action_continuation']??null)){
                $actionId=(string)($m['_action_continuation']['action_id']??'');
                if($actionId===''||$this->actionCatalog===null||!$this->actionCatalog->consumeContinuation(
                    $actionId,(string)$m['turn_id'],(string)$m['session_id'],(int)$m['generation']))
                    throw new \DomainException('action_followup_not_allowed');
            }
            $rechat=is_array($p['context']['rechat']??null)?$p['context']['rechat']:null;
            if(($m['payload']['ui_source']??null)==='lorkhan_rechat'){
                if($rechat===null||!Uuid::isValid((string)($rechat['chain_id']??''))||!Uuid::isValid((string)($rechat['origin_turn_id']??''))
                    ||!is_int($rechat['rechat_depth']??null)||!is_int($rechat['max_depth']??null)||!is_int($rechat['round_budget']??null)
                    ||$rechat['rechat_depth']<1||$rechat['rechat_depth']>$rechat['round_budget']
                    ||$rechat['round_budget']>$rechat['max_depth']||$rechat['max_depth']>20)throw new \DomainException('invalid_rechat_context');
                $chain=$this->db->prepare("INSERT INTO rechat_chains (chain_id,installation_id,playthrough_id,session_id,generation,configured_mode,mode,state,max_depth,current_depth,probability_percent,strict_targeting,round_budget,participants,previous_speaker,previous_listener,next_target,origin_line,origin_turn_id,latest_turn_id,expires_at) "
                    ."VALUES (:chain,:installation,:playthrough,:session,:generation,:configured_mode,:mode,'request_in_flight',:max_depth,:depth,:probability,:strict,:round_budget,CAST(:participants AS jsonb),CAST(:speaker AS jsonb),CAST(:listener AS jsonb),CAST(:target AS jsonb),:origin_line,:origin,:latest,clock_timestamp()+interval '10 minutes') "
                    ."ON CONFLICT (chain_id) DO UPDATE SET state='request_in_flight',current_depth=EXCLUDED.current_depth,previous_speaker=EXCLUDED.previous_speaker,previous_listener=EXCLUDED.previous_listener,next_target=EXCLUDED.next_target,latest_turn_id=EXCLUDED.latest_turn_id,expires_at=EXCLUDED.expires_at,updated_at=clock_timestamp() "
                    ."WHERE rechat_chains.session_id=EXCLUDED.session_id AND rechat_chains.generation=EXCLUDED.generation "
                    ."AND rechat_chains.origin_turn_id=EXCLUDED.origin_turn_id AND rechat_chains.current_depth+1=EXCLUDED.current_depth "
                    ."AND rechat_chains.state IN ('awaiting_playback','request_in_flight') RETURNING chain_id");
                $chain->execute(['chain'=>$rechat['chain_id'],'installation'=>$m['installation_id'],'playthrough'=>$m['playthrough_id'],
                    'session'=>$m['session_id'],'generation'=>$m['generation'],'configured_mode'=>$rechat['configured_mode'],
                    'mode'=>$rechat['mode'],'max_depth'=>$rechat['max_depth'],'depth'=>$rechat['rechat_depth'],
                    'probability'=>$rechat['probability_percent'],'strict'=>$rechat['strict_targeting']?'true':'false',
                    'round_budget'=>$rechat['round_budget'],'participants'=>$this->encode($rechat['participants']),
                    'speaker'=>$this->encode($rechat['speaker']),
                    'listener'=>isset($rechat['listener_hint'])?$this->encode($rechat['listener_hint']):null,
                    'target'=>$this->encode($p['target']),'origin_line'=>$rechat['origin_line'],
                    'origin'=>$rechat['origin_turn_id'],'latest'=>$m['turn_id']]);
                if($chain->fetchColumn()===false)throw new \DomainException('rechat_chain_conflict');
            }else{
                $this->db->prepare("UPDATE rechat_chains SET state='cancelled',cancellation_reason='new_player_input',updated_at=clock_timestamp() WHERE session_id=:session AND generation=:generation AND state IN ('open','awaiting_playback','request_in_flight')")
                    ->execute(['session'=>$m['session_id'],'generation'=>$m['generation']]);
            }
            $sourceKind=($m['payload']['ui_source']??null)==='lorkhan_rechat'?'rechat':'turn.requested';
            $projectionContext=['player_mood_cue'=>is_string($promptTrace['player_mood_cue']??null)
                ?$promptTrace['player_mood_cue']:''];
            $this->source($m['message_id'], $m['installation_id'], $m['session_id'], $m['generation'], $sourceKind, $m['created_at'],
                $m['schema'], $m['request_id'], $m['turn_id'], null, $m, $projectionContext);
            $dynamicOghma=new DynamicOghmaRepository($this->db);
            $dynamicOghma->apply((string)$m['installation_id'],(string)$m['playthrough_id'],(string)$m['message_id'],$dynamicPlan??$dynamicOghma->plan($m));
            $event = $this->event($m['session_id'], $m['generation'], $m['request_id'], $m['turn_id'], 'turn.accepted', ['status' => 'accepted']);
            if ($providerInput !== null) {
                $providerInput['_negotiated_capabilities']=$session['capabilities'];
                $jobPayload = ['turn_id' => $m['turn_id'], 'session_id' => $m['session_id'], 'generation' => $m['generation']];
                // Freeze the exact provider input accepted for this turn. The raw client message
                // remains in source_events, while workers consume only this assembled snapshot.
                $manifest=['message'=>$providerInput,'capabilities'=>$session['capabilities'],'trace'=>$promptTrace];
                $this->db->prepare('INSERT INTO turn_provider_snapshots (turn_id,source_manifest,input_sha256,created_at) '
                    . 'VALUES (:turn,CAST(:manifest AS jsonb),:sha,clock_timestamp())')
                    ->execute(['turn'=>$m['turn_id'],'manifest'=>$this->encode($manifest),
                        'sha'=>hash('sha256',$this->encodeCanonical($providerInput))]);
                if ($promptTrace !== null) $this->recordPromptTrace($m, $promptTrace);
                $this->db->prepare("INSERT INTO durable_jobs (job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) "
                    . "VALUES (:id,'turn.process',1,:key,CAST(:payload AS jsonb),3,100) ON CONFLICT (job_type,idempotency_key) DO NOTHING")
                    ->execute(['id' => Uuid::v4(), 'key' => 'turn:' . $m['turn_id'], 'payload' => $this->encode($jobPayload)]);
            }
            if ($validatedDirectAction !== null) {
                $canonical = $this->validatedCanonicalResponse(
                    (new \LorkhanServer\Application\CanonicalResponseNormalizer())->actionOnly($m, $validatedDirectAction));
                $actionLine = $canonical['lines'][0];
                $this->event($m['session_id'],$m['generation'],$m['request_id'],$m['turn_id'],
                    'response.complete',$canonical,$canonical['response_id']);
                $this->action($m, $m['request_id'], $validatedDirectAction, $actionLine['line_id']);
                $this->db->prepare("UPDATE responselog SET tag='response.line',actor=:actor,text=NULL,action='rolecommand',"
                    . 'actor_identity=CAST(:identity AS jsonb),payload=CAST(:payload AS jsonb) WHERE response_message_id=:message')
                    ->execute(['actor'=>$actionLine['display_name'],'identity'=>$this->encode($actionLine['speaker_identity']),
                        'payload'=>$this->encode($actionLine),'message'=>$actionLine['line_id']]);
                $event = $this->event($m['session_id'], $m['generation'], $m['request_id'], $m['turn_id'],
                    'turn.complete', ['status' => 'complete']);
                $updated = $this->db->prepare(
                    "UPDATE turns SET state='complete',completed_at=clock_timestamp(),response_id=:response,"
                    . "response_payload=CAST(:payload AS jsonb),response_created_at=:created WHERE turn_id=:id AND state='accepted'"
                );
                $updated->execute(['id' => $m['turn_id'], 'response' => $canonical['response_id'],
                    'payload' => $this->encode($canonical), 'created' => $canonical['created_at']]);
                if ($updated->rowCount() !== 1) throw new \DomainException('turn_terminal');
            }
            $event['capabilities'] = $session['capabilities'];
            if ($idempotencyHash !== null && $idempotencyResponse !== null) {
                $idempotencyResponse['event_cursor'] = $event['sequence'];
                $this->remember($m['installation_id'], $m['message_id'], '/turns', $idempotencyHash, 202, $idempotencyResponse);
            }
            return $event;
        });
    }

    /** Store a standalone game observation after checking its active session ownership. */
    public function acceptGameData(array $message): void
    {
        $this->transaction(function () use ($message): void {
            $session = $this->session((string) $message['session_id'], (int) $message['generation'], true);
            if ((string) $session['installation_id'] !== (string) $message['installation_id']
                || (string) $session['playthrough_id'] !== (string) $message['playthrough_id']) {
                throw new \UnexpectedValueException('stale_generation');
            }
            $this->source((string) $message['request_id'], (string) $message['installation_id'],
                (string) $message['session_id'], (int) $message['generation'], 'gamedata.' . (string) $message['type'],
                (string) $message['observed_at'], (string) $message['schema'], (string) $message['request_id'],
                null, null, $message);
            if($message['type']==='journal'){
                $dynamicOghma=new DynamicOghmaRepository($this->db);
                $dynamicOghma->apply((string)$message['installation_id'],(string)$message['playthrough_id'],(string)$message['request_id'],$dynamicOghma->plan($message,true));
            }
        });
    }

    /** Return the durable server-owned state for one active rechat chain. */
    public function rechatChain(string $chainId, string $sessionId, int $generation): ?array
    {
        $statement=$this->db->prepare('SELECT * FROM rechat_chains WHERE chain_id=:chain AND session_id=:session AND generation=:generation');
        $statement->execute(['chain'=>$chainId,'session'=>$sessionId,'generation'=>$generation]);
        $row=$statement->fetch();
        if(!$row)return null;
        foreach(['participants','previous_speaker','previous_listener','next_target']as$field){
            if($row[$field]!==null)$row[$field]=$this->json($row[$field]);
        }
        foreach(['generation','max_depth','current_depth','probability_percent','round_budget']as$field)$row[$field]=(int)$row[$field];
        $row['strict_targeting']=filter_var($row['strict_targeting'],FILTER_VALIDATE_BOOL);
        return$row;
    }

    /** Enforce the Herika end-of-conversation cooldown without client timers or extra polling. */
    public function rechatCooldownActive(string $installationId, string $playthroughId, int $seconds): bool
    {
        if($seconds<=0)return false;
        $statement=$this->db->prepare("SELECT 1 FROM rechat_chains WHERE installation_id=:installation "
            ."AND playthrough_id=:playthrough AND state='closed' "
            ."AND updated_at>clock_timestamp()-(:seconds||' seconds')::interval ORDER BY updated_at DESC LIMIT 1");
        $statement->execute(['installation'=>$installationId,'playthrough'=>$playthroughId,'seconds'=>(string)$seconds]);
        return$statement->fetchColumn()!==false;
    }

    /** Verify a player-selected action target against the bounded nearby-actor snapshot. */
    private function contextContainsIdentity(array $context, array $target): bool
    {
        $items = $context['nearbyActors']['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) return false;
        foreach ($items as $candidate) {
            if (is_array($candidate) && !array_is_list($candidate) && $this->sameIdentity($candidate, $target)) return true;
        }
        return false;
    }

    /** Compare only immutable OpenMW identity fields; display names are snapshots, not authority. */
    private function sameIdentity(array $left, array $right): bool
    {
        return ($left['kind'] ?? null) === ($right['kind'] ?? null)
            && ($left['record_id'] ?? null) === ($right['record_id'] ?? null)
            && ($left['refnum'] ?? null) == ($right['refnum'] ?? null)
            && ($left['content_file'] ?? null) === ($right['content_file'] ?? null)
            && ($left['cell'] ?? null) == ($right['cell'] ?? null);
    }

    /** @return array<string,mixed> */
    public function claimTurnForProcessing(string $turnId, string $jobId, string $leaseToken, int $jobAttempt): array
    {
        return $this->transaction(function () use ($turnId,$jobId,$leaseToken,$jobAttempt): array {
            $lease=$this->db->prepare("SELECT 1 FROM durable_jobs WHERE job_id=:job AND state='leased' AND lease_token=:token "
                . 'AND attempt_count=:attempt AND lease_expires_at>clock_timestamp() FOR UPDATE');
            $lease->execute(['job'=>$jobId,'token'=>$leaseToken,'attempt'=>$jobAttempt]);
            if(!$lease->fetchColumn()) throw new \RuntimeException('lease_lost');
            $statement = $this->db->prepare("UPDATE turns SET state='processing',processing_started_at=clock_timestamp(),"
                . "processing_attempts=processing_attempts+1,processing_job_id=:job,processing_lease_token=:token,processing_job_attempt=:attempt "
                . "WHERE turn_id=:turn AND state IN ('accepted','processing') RETURNING *");
            $statement->execute(['turn'=>$turnId,'job'=>$jobId,'token'=>$leaseToken,'attempt'=>$jobAttempt]);
            $row = $statement->fetch();
            if (!$row) throw new \DomainException('turn_terminal');
            foreach (['speaker','target','audience','context'] as $field) $row[$field] = $this->json($row[$field]);
            $row['generation'] = (int) $row['generation'];
            return $row;
        });
    }

    public function isTurnCancellationRequested(string $sessionId, string $turnId, int $generation): bool
    {
        $statement = $this->db->prepare('SELECT t.state,s.state AS session_state,s.generation FROM turns t JOIN sessions s ON s.session_id=t.session_id '
            . 'WHERE t.turn_id=:turn AND t.session_id=:session');
        $statement->execute(['turn' => $turnId, 'session' => $sessionId]);
        $row = $statement->fetch();
        return !$row || !in_array($row['state'], ['accepted','processing'], true) || $row['session_state'] !== 'active'
            || (int) $row['generation'] !== $generation;
    }

    /** Resolve prompt-visible actions through the same catalog and policy used at execution. */
    public function allowedPromptActions(string $sessionId, int $generation): array
    {
        // Preserve ingress stale/closed-session errors before the catalog's active-session read.
        $this->session($sessionId, $generation);
        if ($this->actionCatalog === null || $this->actionPolicy === null) return [];
        return $this->actionPolicy->allowedDefinitions($this->actionCatalog->loadForSession($sessionId, $generation));
    }

    /** @return array<string,mixed> */
    public function turnMessage(string $turnId): array
    {
        $statement=$this->db->prepare('SELECT source_manifest FROM turn_provider_snapshots WHERE turn_id=:turn');
        $statement->execute(['turn'=>$turnId]);$snapshot=$statement->fetchColumn();
        if($snapshot===false)throw new \OutOfBoundsException('turn_snapshot_missing');
        $manifest=$this->json($snapshot);$message=$manifest['message']??null;
        if(!is_array($message)||array_is_list($message))throw new \RuntimeException('turn_snapshot_invalid');
        $message['_negotiated_capabilities']=$manifest['capabilities']??[];
        return $message;
    }

    public function appendDialogueDelta(array $m, string $text, array $fence): array
    {
        if ($text === '' || strlen($text) > 4096 || !mb_check_encoding($text, 'UTF-8')) {
            throw new \DomainException('provider_invalid_output');
        }
        return $this->transaction(function () use ($m, $text, $fence): array {
            $this->session($m['session_id'], $m['generation'], true);
            $turn = $this->lockPendingTurn($m['turn_id'], $m['session_id'], $fence);
            return $this->event($m['session_id'], $m['generation'], $turn['request_id'], $m['turn_id'],
                'dialogue.delta', ['text' => $text]);
        });
    }

    /** Commit one sentence as soon as the structured stream makes it complete and queue durable TTS fallback. */
    public function appendStreamedDialogue(array $m, string $text, array $fence, int $index): array
    {
        if ($text === '' || strlen($text) > 16_384 || !mb_check_encoding($text, 'UTF-8')
            || $index < 1 || $index > \LorkhanServer\Application\DialoguePlanner::MAX_UTTERANCES) {
            throw new \DomainException('provider_invalid_output');
        }
        return $this->transaction(function () use ($m, $text, $fence, $index): array {
            $this->session($m['session_id'], $m['generation'], true);
            $turn = $this->lockPendingTurn($m['turn_id'], $m['session_id'], $fence);
            $speaker = $m['payload']['target'];
            $addressee = $m['payload']['speaker'];
            $audience = $m['payload']['audience'];
            if (!array_filter($audience, fn(array $identity): bool => $this->sameIdentity($identity, $speaker))) {
                array_unshift($audience, $speaker);
            }
            $lineId = Uuid::v4();
            $utteranceId = Uuid::v4();
            $dialogue = $this->event($m['session_id'], $m['generation'], $turn['request_id'], $m['turn_id'],
                'dialogue.complete', ['speaker' => $speaker, 'addressee' => $addressee, 'text' => $text], $lineId);
            $this->db->prepare('INSERT INTO dialogue_utterances (dialogue_message_id,session_id,turn_id,request_id,generation,utterance_index,'
                . 'utterance_count,response_line_id,utterance_id,runtime_generation,speaker,addressee,audience,text,emitted_at,delivery_deadline_at) VALUES '
                . '(:id,:session,:turn,:request,:generation,:idx,:count,:line,:utterance,:runtime_generation,CAST(:speaker AS jsonb),CAST(:addressee AS jsonb),'
                . 'CAST(:audience AS jsonb),:text,:emitted,CAST(:emitted AS timestamptz)+interval \'5 minutes\')')
                ->execute(['id'=>$dialogue['message_id'],'session'=>$m['session_id'],'turn'=>$m['turn_id'],'request'=>$turn['request_id'],
                    'generation'=>$m['generation'],'idx'=>$index,
                    'count'=>\LorkhanServer\Application\DialoguePlanner::MAX_UTTERANCES,
                    'line'=>$lineId,'utterance'=>$utteranceId,
                    'runtime_generation'=>(int)$turn['runtime_generation'],'speaker'=>$this->encode($speaker),
                    'addressee'=>$this->encode($addressee),'audience'=>$this->encode($audience),'text'=>$text,
                    'emitted'=>$dialogue['created_at']]);
            $utterance=['speaker'=>$speaker,'addressee'=>$addressee,'audience'=>$audience,'text'=>$text,'speech_enabled'=>true];
            $this->eventLog()->projectDialogue($m+['request_id'=>$turn['request_id']],$utterance,$dialogue['message_id'],$dialogue['created_at']);
            $this->db->prepare('INSERT INTO speech (installation_id,playthrough_id,session_id,turn_id,dialogue_message_id,sess,speaker,speech,'
                . 'location,listener,localts,gamets,ts,utterance_id,speaker_identity,listener_identity,audience,delivery_state,created_at) VALUES '
                . '(:installation,:playthrough,:session,:turn,:dialogue,:sess,:speaker,:speech,:location,:listener,'
                . 'extract(epoch FROM CAST(:created AS timestamptz))::bigint,:gamets,(extract(epoch FROM CAST(:created AS timestamptz))*1000)::bigint,'
                . ':utterance,CAST(:speaker_identity AS jsonb),CAST(:listener_identity AS jsonb),CAST(:audience AS jsonb),\'emitted\',:created)')
                ->execute(['installation'=>$m['installation_id'],'playthrough'=>$m['playthrough_id'],'session'=>$m['session_id'],
                    'turn'=>$m['turn_id'],'dialogue'=>$dialogue['message_id'],'sess'=>$m['session_id'],
                    'speaker'=>$speaker['display_name']??$speaker['record_id']??null,'speech'=>$text,
                    'location'=>$m['payload']['context']['location']['name']??null,
                    'listener'=>$addressee['display_name']??$addressee['record_id']??null,'created'=>$dialogue['created_at'],
                    'gamets'=>(int)($m['payload']['context']['world']['game_time']??0),'utterance'=>$utteranceId,
                    'speaker_identity'=>$this->encode($speaker),'listener_identity'=>$this->encode($addressee),
                    'audience'=>$this->encode($audience)]);
            $expiryJob=Uuid::v4();
            $this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,next_run_at,priority) VALUES(:job,'dialogue.expire',1,:key,CAST(:payload AS jsonb),1,CAST(:deadline AS timestamptz),50)")
                ->execute(['job'=>$expiryJob,'key'=>'dialogue:'.$dialogue['message_id'],
                    'payload'=>$this->encode(['dialogue_message_id'=>$dialogue['message_id']]),
                    'deadline'=>(new \DateTimeImmutable($dialogue['created_at']))->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z')]);
            $this->db->prepare('UPDATE dialogue_utterances SET expiry_job_id=:job WHERE dialogue_message_id=:id')
                ->execute(['job'=>$expiryJob,'id'=>$dialogue['message_id']]);
            return ['event'=>$dialogue,'dialogue_message_id'=>$dialogue['message_id'],'line_id'=>$lineId,
                'utterance_id'=>$utteranceId,'text'=>$text,'speaker'=>$speaker,'addressee'=>$addressee,
                'audience'=>$audience,'utterance_index'=>$index];
        });
    }

    /** Queue durable synthesis only when the CHIM-style inline attempt cannot publish media. */
    public function queueStreamedDialogueSpeech(array $m, array $dialogue, array $fence): void
    {
        $this->transaction(function () use ($m, $dialogue, $fence): void {
            $this->lockPendingTurn($m['turn_id'],$m['session_id'],$fence);
            $id=(string)($dialogue['dialogue_message_id']??'');
            $text=(string)($dialogue['text']??'');
            $this->db->prepare("INSERT INTO durable_jobs (job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) "
                . "VALUES (:job,'speech.synthesize',1,:key,CAST(:payload AS jsonb),3,90) ON CONFLICT (job_type,idempotency_key) DO NOTHING")
                ->execute(['job'=>Uuid::v4(),'key'=>'speech:'.$id,
                    'payload'=>$this->encode(['dialogue_message_id'=>$id,'tts_text'=>$text]+array_intersect_key($dialogue,['mood'=>true])+SpeechLanguage::payload($dialogue['tts_language']??null))]);
        });
    }

    /** Publish media generated inline by the turn worker while the remaining provider stream continues. */
    public function completeStreamedDialogueSpeech(array $m, array $dialogue, array $speech, array $fence): array
    {
        return $this->transaction(function () use ($m, $dialogue, $speech, $fence): array {
            $turn=$this->lockPendingTurn($m['turn_id'],$m['session_id'],$fence);
            $id=(string)($dialogue['dialogue_message_id']??'');
            $exists=$this->db->prepare('SELECT 1 FROM dialogue_utterances WHERE dialogue_message_id=:id FOR UPDATE');
            $exists->execute(['id'=>$id]);
            if(!$exists->fetchColumn())throw new \OutOfBoundsException('dialogue_not_found');
            $insert=$this->db->prepare('INSERT INTO media_objects (media_id,installation_id,session_id,turn_id,generation,sha256,byte_count,'
                . 'codec,mime_type,duration_ms,expires_at,dialogue_message_id) VALUES (:id,:installation,:session,:turn,:generation,:sha,:bytes,'
                . ':codec,:mime,:duration,:expires,:dialogue) ON CONFLICT (dialogue_message_id) DO NOTHING');
            $insert->execute(['id'=>$speech['media_id'],'installation'=>$m['installation_id'],'session'=>$m['session_id'],
                'turn'=>$m['turn_id'],'generation'=>$m['generation'],'sha'=>$speech['sha256'],'bytes'=>$speech['bytes'],
                'codec'=>$speech['codec'],'mime'=>$speech['mime_type'],'duration'=>$speech['duration_ms'],
                'expires'=>$speech['expires_at'],'dialogue'=>$id]);
            if($insert->rowCount()===0)return[];
            $descriptor=$speech;unset($descriptor['mime_type']);$descriptor['dialogue_message_id']=$id;
            return $this->event($m['session_id'],$m['generation'],$turn['request_id'],$m['turn_id'],'speech.ready',$descriptor);
        });
    }

    public function completeTurn(array $m, array $providerResult, ?array $speech = null, ?array $fence = null,
        bool $queueSpeech = false, array $streamedDialogues = []): array
    {
        return $this->transaction(function () use ($m, $providerResult, $speech, $fence, $queueSpeech, $streamedDialogues): array {
            $session = $this->session($m['session_id'], $m['generation'], true);
            $turn = $this->lockPendingTurn($m['turn_id'], $m['session_id'], $fence);
            $m['runtime_generation'] ??= (int) $turn['runtime_generation'];
            if (($m['payload']['ui_source'] ?? null) === 'lorkhan_rechat'
                && ($m['payload']['context']['rechat']['allow_actions'] ?? false) !== true) {
                $providerResult['action'] = null;
            }
            $providerResult=$this->validateProviderResult($providerResult, $session, $m);
            $canonical = $this->validatedCanonicalResponse(
                (new \LorkhanServer\Application\CanonicalResponseNormalizer())->normalize($m, $providerResult, $streamedDialogues));
            $responseEvent=$this->event($m['session_id'],$m['generation'],$turn['request_id'],$m['turn_id'],
                'response.complete',$canonical,$canonical['response_id']);
            $dialogueLines = array_values(array_filter($canonical['lines'],
                static fn(array $line): bool => $line['action'] === 'say'));
            $actionLines = array_values(array_filter($canonical['lines'],
                static fn(array $line): bool => $line['action'] === 'rolecommand'));
            $audience = $m['payload']['audience'];
            if (!array_filter($audience, fn(array $identity): bool => $this->sameIdentity($identity, $m['payload']['target']))) {
                array_unshift($audience, $m['payload']['target']);
            }
            $dialogues = [];
            $speechEvents = [];
            foreach ($dialogueLines as $index => $line) {
                $utterance = ['speaker' => $line['speaker_identity'], 'addressee' => $line['listener_identity'],
                    'audience' => $audience, 'text' => $line['text'],
                    'speech_enabled' => ($line['metadata']['speech_enabled'] ?? true) !== false];
                $streamed=$streamedDialogues[$index]??null;
                if($streamed!==null){
                    $dialogue=$streamed['event'];
                    $this->db->prepare('UPDATE dialogue_utterances SET utterance_count=:count,text=:text WHERE dialogue_message_id=:id')
                        ->execute(['count'=>count($dialogueLines),'text'=>$utterance['text'],'id'=>$dialogue['message_id']]);
                    $this->db->prepare("UPDATE responselog SET tag='response.line',actor=:actor,text=:text,action='say',"
                        . 'actor_identity=CAST(:identity AS jsonb),payload=CAST(:payload AS jsonb) WHERE response_message_id=:message')
                        ->execute(['actor'=>$line['display_name'],'text'=>$line['text'],'identity'=>$this->encode($line['speaker_identity']),
                            'payload'=>$this->encode($line),'message'=>$line['line_id']]);
                    $dialogues[]=$dialogue;
                    continue;
                }
                $dialogue = $this->event($m['session_id'], $m['generation'], $turn['request_id'], $m['turn_id'], 'dialogue.complete', [
                    'speaker' => $utterance['speaker'], 'addressee' => $utterance['addressee'], 'text' => $line['subtitle'],
                ], $line['line_id']);
                $this->db->prepare('INSERT INTO dialogue_utterances (dialogue_message_id,session_id,turn_id,request_id,generation,utterance_index,'
                    . 'utterance_count,response_line_id,utterance_id,runtime_generation,speaker,addressee,audience,text,emitted_at,delivery_deadline_at) VALUES '
                    . '(:id,:session,:turn,:request,:generation,:idx,:count,:line,:utterance,:runtime_generation,CAST(:speaker AS jsonb),CAST(:addressee AS jsonb),CAST(:audience AS jsonb),'
                    . ':text,:emitted,CAST(:emitted AS timestamptz)+interval \'5 minutes\')')->execute(['id' => $dialogue['message_id'],
                        'session' => $m['session_id'], 'turn' => $m['turn_id'], 'request' => $turn['request_id'], 'generation' => $m['generation'],
                        'idx' => $index + 1, 'count' => count($dialogueLines), 'line' => $line['line_id'],
                        'utterance' => $line['utterance_id'], 'runtime_generation' => $m['runtime_generation'], 'speaker' => $this->encode($utterance['speaker']),
                        'addressee' => $this->encode($utterance['addressee']), 'audience' => $this->encode($utterance['audience']),
                        'text' => $utterance['text'], 'emitted' => $dialogue['created_at']]);
                $this->eventLog()->projectDialogue($m + ['request_id'=>$turn['request_id']], $utterance,
                    $dialogue['message_id'], $dialogue['created_at']);
                $this->db->prepare('INSERT INTO speech (installation_id,playthrough_id,session_id,turn_id,dialogue_message_id,sess,speaker,speech,'
                    . 'location,listener,localts,gamets,ts,utterance_id,speaker_identity,listener_identity,audience,delivery_state,created_at) '
                    . 'VALUES (:installation,:playthrough,:session,:turn,:dialogue,:sess,:speaker,:speech,:location,:listener,'
                    . 'extract(epoch FROM CAST(:created AS timestamptz))::bigint,:gamets,'
                    . '(extract(epoch FROM CAST(:created AS timestamptz))*1000)::bigint,:utterance,CAST(:speaker_identity AS jsonb),'
                    . 'CAST(:listener_identity AS jsonb),CAST(:audience AS jsonb),\'emitted\',:created) ON CONFLICT (dialogue_message_id) DO NOTHING')
                    ->execute(['installation'=>$m['installation_id'],'playthrough'=>$m['playthrough_id'],'session'=>$m['session_id'],
                        'turn'=>$m['turn_id'],'dialogue'=>$dialogue['message_id'],'sess'=>$m['session_id'],
                        'speaker'=>$utterance['speaker']['display_name']??$utterance['speaker']['record_id']??null,
                        'speech'=>$utterance['text'],'location'=>$m['payload']['context']['location']['name']??null,
                        'listener'=>$utterance['addressee']['display_name']??$utterance['addressee']['record_id']??null,
                        'created'=>$dialogue['created_at'],'gamets'=>(int)($m['payload']['context']['world']['game_time']??0),
                        'utterance'=>$line['utterance_id'],'speaker_identity'=>$this->encode($utterance['speaker']),
                        'listener_identity'=>$this->encode($utterance['addressee']),'audience'=>$this->encode($utterance['audience'])]);
                $this->db->prepare("UPDATE responselog SET tag='response.line',actor=:actor,text=:text,action='say',"
                    . 'actor_identity=CAST(:identity AS jsonb),payload=CAST(:payload AS jsonb) WHERE response_message_id=:message')
                    ->execute(['actor'=>$line['display_name'],'text'=>$line['text'],'identity'=>$this->encode($line['speaker_identity']),
                        'payload'=>$this->encode($line),'message'=>$line['line_id']]);
                $expiryJob=Uuid::v4();$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,next_run_at,priority) VALUES(:job,'dialogue.expire',1,:key,CAST(:payload AS jsonb),1,CAST(:deadline AS timestamptz),50)")->execute(['job'=>$expiryJob,'key'=>'dialogue:'.$dialogue['message_id'],'payload'=>$this->encode(['dialogue_message_id'=>$dialogue['message_id']]),'deadline'=>(new \DateTimeImmutable($dialogue['created_at']))->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z')]);$this->db->prepare('UPDATE dialogue_utterances SET expiry_job_id=:job WHERE dialogue_message_id=:id')->execute(['job'=>$expiryJob,'id'=>$dialogue['message_id']]);
                $dialogues[] = $dialogue;
                if ($queueSpeech && ($utterance['speech_enabled'] ?? true) !== false) {
                    $this->db->prepare("INSERT INTO durable_jobs (job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) "
                        . "VALUES (:job,'speech.synthesize',1,:key,CAST(:payload AS jsonb),3,90) ON CONFLICT (job_type,idempotency_key) DO NOTHING")
                        ->execute(['job'=>Uuid::v4(),'key'=>'speech:'.$dialogue['message_id'],
                            'payload'=>$this->encode(['dialogue_message_id'=>$dialogue['message_id'],'tts_text'=>$line['tts_text']]+array_intersect_key($utterance,['mood'=>true])+SpeechLanguage::payload($utterance['tts_language']??null))]);
                }
                $currentSpeech = $speech[$index] ?? ($index === 0 && isset($speech['media_id']) ? $speech : null);
                if ($currentSpeech !== null) {
                    $this->db->prepare('INSERT INTO media_objects (media_id, installation_id, session_id, turn_id, generation, sha256, byte_count, '
                        . 'codec, mime_type, duration_ms, expires_at, dialogue_message_id) VALUES (:id, :installation, :session, :turn, :generation, :sha, :bytes, '
                        . ':codec, :mime, :duration, :expires, :dialogue)')->execute(['id' => $currentSpeech['media_id'], 'installation' => $m['installation_id'],
                            'session' => $m['session_id'], 'turn' => $m['turn_id'], 'generation' => $m['generation'], 'dialogue'=>$dialogue['message_id'], 'sha' => $currentSpeech['sha256'],
                            'bytes' => $currentSpeech['bytes'], 'codec' => $currentSpeech['codec'], 'mime' => $currentSpeech['mime_type'],
                            'duration' => $currentSpeech['duration_ms'], 'expires' => $currentSpeech['expires_at']]);
                    $descriptor = $currentSpeech; unset($descriptor['mime_type']);
                    $speechEvents[] = $this->event($m['session_id'], $m['generation'], $turn['request_id'], $m['turn_id'], 'speech.ready', $descriptor);
                }
            }
            $action = null;
            if (($providerResult['action'] ?? null) !== null) {
                $actionLine = $actionLines[0] ?? throw new \DomainException('provider_invalid_output');
                $action = $this->action($m, $turn['request_id'], $providerResult['action'], $actionLine['line_id']);
                $this->db->prepare("UPDATE responselog SET tag='response.line',actor=:actor,text=NULL,action='rolecommand',"
                    . 'actor_identity=CAST(:identity AS jsonb),payload=CAST(:payload AS jsonb) WHERE response_message_id=:message')
                    ->execute(['actor'=>$actionLine['display_name'],'identity'=>$this->encode($actionLine['speaker_identity']),
                        'payload'=>$this->encode($actionLine),'message'=>$actionLine['line_id']]);
            }
            $complete = $this->event($m['session_id'], $m['generation'], $turn['request_id'], $m['turn_id'], 'turn.complete', ['status' => 'complete']);
            $updated = $this->db->prepare("UPDATE turns SET state='complete',completed_at=clock_timestamp(),response_id=:response,"
                . 'response_payload=CAST(:payload AS jsonb),response_created_at=:created WHERE turn_id=:id AND state IN (\'accepted\',\'processing\')');
            $updated->execute(['id' => $m['turn_id'], 'response' => $canonical['response_id'],
                'payload' => $this->encode($canonical), 'created' => $canonical['created_at']]);
            if ($updated->rowCount() !== 1) throw new \DomainException('turn_terminal');
            $this->db->prepare("UPDATE rechat_chains SET state=CASE WHEN current_depth>=round_budget THEN 'closed' ELSE 'awaiting_playback' END,updated_at=clock_timestamp() WHERE latest_turn_id=:turn AND state='request_in_flight'")
                ->execute(['turn'=>$m['turn_id']]);
            return ['cursor' => $complete['sequence'], 'dialogues' => $dialogues, 'speech' => $speechEvents,
                'action' => $action, 'response_event'=>$responseEvent, 'response' => $canonical];
        });
    }

    /** @return array<string,mixed>|null */
    public function claimDialogueForSpeech(string $dialogueId, array $fence): ?array
    {
        return $this->transaction(function () use ($dialogueId, $fence): ?array {
            $lease=$this->db->prepare("SELECT 1 FROM durable_jobs WHERE job_id=:job AND state='leased' AND lease_token=:token "
                . 'AND attempt_count=:attempt AND lease_expires_at>clock_timestamp() FOR UPDATE');
            $lease->execute(['job'=>$fence['job_id'],'token'=>$fence['lease_token'],'attempt'=>$fence['attempt']]);
            if(!$lease->fetchColumn()) throw new \RuntimeException('lease_lost');
            $statement=$this->db->prepare('SELECT u.*,s.installation_id,s.playthrough_id,s.state AS session_state '
                . 'FROM dialogue_utterances u JOIN sessions s ON s.session_id=u.session_id '
                . 'WHERE u.dialogue_message_id=:id FOR UPDATE OF u');
            $statement->execute(['id'=>$dialogueId]);$row=$statement->fetch();
            if(!$row) throw new \OutOfBoundsException('dialogue_not_found');
            $existing=$this->db->prepare('SELECT 1 FROM media_objects WHERE dialogue_message_id=:id');
            $existing->execute(['id'=>$dialogueId]);
            if($existing->fetchColumn()||$row['session_state']!=='active') return null;
            foreach(['speaker','addressee','audience'] as$field)$row[$field]=$this->json($row[$field]);
            $row['generation']=(int)$row['generation'];
            return $row;
        });
    }

    public function completeDialogueSpeech(array $dialogue, array $speech, array $fence): array
    {
        return $this->transaction(function () use ($dialogue, $speech, $fence): array {
            $current=$this->claimDialogueForSpeech((string)$dialogue['dialogue_message_id'],$fence);
            if($current===null) return [];
            $this->db->prepare('INSERT INTO media_objects (media_id,installation_id,session_id,turn_id,generation,sha256,byte_count,'
                . 'codec,mime_type,duration_ms,expires_at,dialogue_message_id) VALUES '
                . '(:id,:installation,:session,:turn,:generation,:sha,:bytes,:codec,:mime,:duration,:expires,:dialogue)')
                ->execute(['id'=>$speech['media_id'],'installation'=>$current['installation_id'],'session'=>$current['session_id'],
                    'turn'=>$current['turn_id'],'generation'=>$current['generation'],'sha'=>$speech['sha256'],'bytes'=>$speech['bytes'],
                    'codec'=>$speech['codec'],'mime'=>$speech['mime_type'],'duration'=>$speech['duration_ms'],
                    'expires'=>$speech['expires_at'],'dialogue'=>$current['dialogue_message_id']]);
            $descriptor=$speech;unset($descriptor['mime_type']);
            $descriptor['dialogue_message_id']=$current['dialogue_message_id'];
            return $this->event($current['session_id'],$current['generation'],$current['request_id'],$current['turn_id'],
                'speech.ready',$descriptor);
        });
    }

    public function failTurn(array $m, string $reason, ?array $fence = null): void
    {
        $this->transaction(function () use ($m, $reason, $fence): void {
            $this->session($m['session_id'], $m['generation'], true);
            $turn=$this->lockPendingTurn($m['turn_id'],$m['session_id'],$fence);
            $m['runtime_generation'] ??= (int) $turn['runtime_generation'];
            $canonical=$this->validatedCanonicalResponse(
                (new \LorkhanServer\Application\CanonicalResponseNormalizer())->failure($m,$reason));
            $this->db->prepare("UPDATE turns SET state='failed',completed_at=clock_timestamp(),response_id=:response,"
                . "response_payload=CAST(:payload AS jsonb),response_created_at=:created WHERE turn_id=:turn")
                ->execute(['turn'=>$m['turn_id'],'response'=>$canonical['response_id'],
                    'payload'=>$this->encode($canonical),'created'=>$canonical['created_at']]);
            $this->event($m['session_id'],$m['generation'],$turn['request_id'],$m['turn_id'],
                'response.complete',$canonical,$canonical['response_id']);
            $this->event($m['session_id'], $m['generation'], $turn['request_id'], $m['turn_id'], 'turn.failed',
                ['code' => $reason, 'retriable' => false]);
            $this->db->prepare("UPDATE rechat_chains SET state='cancelled',cancellation_reason=:reason,updated_at=clock_timestamp() WHERE latest_turn_id=:turn AND state IN ('request_in_flight','awaiting_playback')")
                ->execute(['reason'=>$reason,'turn'=>$m['turn_id']]);
        });
    }

    public function events(string $sessionId, int $generation, int $after, int $limit): array
    {
        $session = $this->session($sessionId, $generation, false, true);
        if($session['state']!=='active'&&new \DateTimeImmutable((string)$session['ended_at'])<new \DateTimeImmutable('-5 minutes'))throw new \OutOfBoundsException('unknown_session');
        $latest = (int) $session['event_sequence'];
        $oldestRetained = max(1, $latest - $this->eventReplayLimit + 1);
        if ($after > $latest || ($latest>0 && $after < $oldestRetained - 1)) {
            throw new \DomainException('cursor_expired');
        }
        $stmt = $this->db->prepare('SELECT message_id, request_id, session_id, generation, sequence, turn_id, created_at, event_type, payload '
            . 'FROM response_events WHERE session_id = :session AND sequence > :after ORDER BY sequence LIMIT :limit');
        $stmt->bindValue(':session', $sessionId);
        $stmt->bindValue(':after', $after, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $events = [];
        foreach ($stmt->fetchAll() as $row) {
            $payload = $this->json($row['payload']);
            if ($row['event_type'] === 'action.intent' && ($payload['parameters'] ?? null) === []) {
                $payload['parameters'] = (object) [];
            }
            $events[] = ['message_id' => $row['message_id'], 'request_id' => $row['request_id'], 'turn_id' => $row['turn_id'],
                'session_id' => $row['session_id'], 'generation' => (int) $row['generation'], 'sequence' => (int) $row['sequence'],
                'created_at' => $this->utc($row['created_at']), 'type' => $row['event_type'], 'payload' => $payload];
        }
        return $events;
    }

    public function acceptStt(array $m,string $storageMediaId,string $semanticHash):array
    {
        return $this->transaction(function()use($m,$storageMediaId,$semanticHash):array{$session=$this->session($m['session_id'],$m['generation'],true);$existing=$this->db->prepare('SELECT * FROM stt_requests WHERE message_id=:message OR request_id=:request FOR UPDATE');$existing->execute(['message'=>$m['message_id'],'request'=>$m['request_id']]);if($row=$existing->fetch()){if($row['semantic_hash']!==$semanticHash)throw new \DomainException('duplicate_conflict');return['cursor'=>(int)$row['accepted_cursor'],'duplicate'=>true];}$cursor=(int)$session['event_sequence'];$this->db->prepare('INSERT INTO stt_requests (message_id,request_id,turn_id,session_id,generation,codec,language,audio_bytes,sha256,state,created_at,storage_media_id,semantic_hash,accepted_cursor) VALUES (:message,:request,:turn,:session,:generation,:codec,:language,:bytes,:sha,\'accepted\',:created,:media,:hash,:cursor)')->execute(['message'=>$m['message_id'],'request'=>$m['request_id'],'turn'=>$m['turn_id'],'session'=>$m['session_id'],'generation'=>$m['generation'],'codec'=>$m['codec'],'language'=>$m['language'],'bytes'=>$m['audio_bytes'],'sha'=>$m['sha256'],'created'=>$m['created_at'],'media'=>$storageMediaId,'hash'=>$semanticHash,'cursor'=>$cursor]);$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) VALUES(:job,'stt.process',1,:key,CAST(:payload AS jsonb),3,100)")->execute(['job'=>Uuid::v4(),'key'=>'stt:'.$m['message_id'],'payload'=>$this->encode(['message_id'=>$m['message_id']])]);return['cursor'=>$cursor,'duplicate'=>false];});
    }

    public function claimStt(string $messageId,string $jobId,string $leaseToken,int $attempt):?array{return$this->transaction(function()use($messageId,$jobId,$leaseToken,$attempt):?array{$lease=$this->db->prepare("SELECT 1 FROM durable_jobs WHERE job_id=:job AND state='leased' AND lease_token=:token AND attempt_count=:attempt AND lease_expires_at>clock_timestamp() FOR UPDATE");$lease->execute(['job'=>$jobId,'token'=>$leaseToken,'attempt'=>$attempt]);if(!$lease->fetchColumn())throw new \RuntimeException('lease_lost');$s=$this->db->prepare("UPDATE stt_requests SET state='processing',processing_job_id=:job,processing_lease_token=:token,processing_job_attempt=:attempt WHERE message_id=:id AND state IN('accepted','processing') RETURNING *");$s->execute(['id'=>$messageId,'job'=>$jobId,'token'=>$leaseToken,'attempt'=>$attempt]);$r=$s->fetch();return$r?:null;});}
    public function completeStt(string $messageId,array $result,array $fence):void{$this->transaction(function()use($messageId,$result,$fence):void{$r=$this->lockStt($messageId,$fence);if($r['state']==='transcribed')return;$keys=array_keys($result);sort($keys);if($keys!==['language','text']||!is_string($result['text'])||$result['text']===''||strlen($result['text'])>16384||!mb_check_encoding($result['text'],'UTF-8')||!is_string($result['language'])||preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/D',$result['language'])!==1)throw new \DomainException('provider_invalid_output');$source=Uuid::v4();$payload=['schema'=>'lorkhan.stt.transcript.v1','message_id'=>$source,'request_id'=>$r['request_id'],'turn_id'=>$r['turn_id'],'session_id'=>$r['session_id'],'generation'=>(int)$r['generation']]+$result;$this->source($source,$this->sessionInstallation($r['session_id']),$r['session_id'],(int)$r['generation'],'stt.transcript',gmdate('Y-m-d\TH:i:s\Z'),'lorkhan.stt.transcript.v1',$r['request_id'],$r['turn_id'],null,$payload);$this->event($r['session_id'],(int)$r['generation'],$r['request_id'],$r['turn_id'],'stt.transcript',$result);$this->db->prepare("UPDATE stt_requests SET state='transcribed',transcript=:text,completed_at=clock_timestamp(),provider_error_code=NULL,cleanup_pending=true WHERE message_id=:id")->execute(['text'=>$result['text'],'id'=>$messageId]);});}
    public function failStt(string $messageId,string $code,array $fence):void{$this->transaction(function()use($messageId,$code,$fence):void{$r=$this->lockStt($messageId,$fence);if(in_array($r['state'],['failed','transcribed'],true))return;if(!in_array($code,['invalid_audio','provider_unavailable','provider_invalid_output','provider_timeout'],true))$code='provider_unavailable';$this->event($r['session_id'],(int)$r['generation'],$r['request_id'],$r['turn_id'],'stt.failed',['code'=>$code,'retriable'=>false]);$this->db->prepare("UPDATE stt_requests SET state='failed',provider_error_code=:code,completed_at=clock_timestamp(),cleanup_pending=true WHERE message_id=:id")->execute(['code'=>$code,'id'=>$messageId]);});}

    private function lockStt(string $messageId,array $fence):array
    {
        $s=$this->db->prepare('SELECT * FROM stt_requests WHERE message_id=:id FOR UPDATE');$s->execute(['id'=>$messageId]);$r=$s->fetch();if(!$r)throw new \OutOfBoundsException('unknown_stt');
        if($r['processing_job_id']!==($fence['job_id']??null)||$r['processing_lease_token']!==($fence['lease_token']??null)||(int)$r['processing_job_attempt']!==(int)($fence['attempt']??-1))throw new \RuntimeException('lease_lost');
        $lease=$this->db->prepare("SELECT 1 FROM durable_jobs WHERE job_id=:job AND state='leased' AND lease_token=:token AND attempt_count=:attempt AND lease_expires_at>clock_timestamp()");$lease->execute(['job'=>$fence['job_id'],'token'=>$fence['lease_token'],'attempt'=>$fence['attempt']]);if(!$lease->fetchColumn())throw new \RuntimeException('lease_lost');return$r;
    }

    public function expireDialogue(string $dialogueId):void
    {
        $this->transaction(function()use($dialogueId):void{
            $s=$this->db->prepare("SELECT u.*,se.installation_id FROM dialogue_utterances u JOIN sessions se ON se.session_id=u.session_id WHERE u.dialogue_message_id=:id FOR UPDATE");
            $s->execute(['id'=>$dialogueId]);
            $u=$s->fetch();
            if(!$u||$u['delivery_state']!=='pending'||new \DateTimeImmutable($u['delivery_deadline_at'])>new \DateTimeImmutable())return;
            $source=Uuid::v4();
            $payload=['schema'=>'lorkhan.dialogue-delivery-result.v1','message_id'=>$source,'request_id'=>$u['request_id'],
                'dialogue_message_id'=>$dialogueId,'turn_id'=>$u['turn_id'],'session_id'=>$u['session_id'],
                'generation'=>(int)$u['generation'],'speaker'=>$this->json($u['speaker']),'status'=>'expired',
                'reason_code'=>'delivery_deadline','completed_at'=>$this->utc($u['delivery_deadline_at'])];
            $this->source($source,$u['installation_id'],$u['session_id'],(int)$u['generation'],'dialogue.delivery',
                $payload['completed_at'],$payload['schema'],$u['request_id'],$u['turn_id'],null,$payload);
            $this->db->prepare('INSERT INTO dialogue_delivery_results(dialogue_message_id,source_event_id,message_id,request_id,turn_id,session_id,generation,speaker,status,reason_code,completed_at) '
                . 'VALUES(:dialogue,:source,:message,:request,:turn,:session,:generation,CAST(:speaker AS jsonb),\'expired\',\'delivery_deadline\',:completed)')
                ->execute(['dialogue'=>$dialogueId,'source'=>$source,'message'=>$source,'request'=>$u['request_id'],
                    'turn'=>$u['turn_id'],'session'=>$u['session_id'],'generation'=>$u['generation'],'speaker'=>$u['speaker'],
                    'completed'=>$payload['completed_at']]);
            $this->db->prepare("UPDATE dialogue_utterances SET delivery_state='expired',delivered_at=delivery_deadline_at WHERE dialogue_message_id=:id")
                ->execute(['id'=>$dialogueId]);
            $this->db->prepare("UPDATE speech SET delivery_state='expired' WHERE dialogue_message_id=:id")
                ->execute(['id'=>$dialogueId]);
            $this->eventLog()->updateDialogueDelivery($dialogueId, 'expired');
        });
    }

    public function dialogueDeliveryResult(array $m):array
    {
        return $this->transaction(function()use($m):array{
            $utterance=$this->db->prepare('SELECT u.*,s.installation_id,s.profile_id,s.playthrough_id FROM dialogue_utterances u JOIN sessions s ON s.session_id=u.session_id WHERE u.dialogue_message_id=:id FOR UPDATE');
            $utterance->execute(['id'=>$m['dialogue_message_id']]);$stored=$utterance->fetch();if(!$stored)throw new \OutOfBoundsException('unknown_dialogue');
            $existing=$this->db->prepare('SELECT * FROM dialogue_delivery_results WHERE dialogue_message_id=:id OR message_id=:message');
            $existing->execute(['id'=>$m['dialogue_message_id'],'message'=>$m['message_id']]);
            if($row=$existing->fetch()){
                $same=$row['dialogue_message_id']===$m['dialogue_message_id']&&$row['message_id']===$m['message_id']&&$row['request_id']===$m['request_id']
                    &&$row['turn_id']===$m['turn_id']&&$row['session_id']===$m['session_id']&&(int)$row['generation']===$m['generation']
                    &&$this->json($row['speaker'])==$m['speaker']&&$row['status']===$m['status']&&$row['reason_code']===$m['reason_code']
                    &&$this->utc($row['completed_at'])===$m['completed_at'];
                if(!$same)throw new \DomainException('duplicate_conflict');return['duplicate'=>true];
            }
            if($stored['request_id']!==$m['request_id']||$stored['turn_id']!==$m['turn_id']||$stored['session_id']!==$m['session_id']
                ||(int)$stored['generation']!==$m['generation']||$this->json($stored['speaker'])!=$m['speaker'])throw new \DomainException('dialogue_result_mismatch');
            $completed=new \DateTimeImmutable($m['completed_at']);$emitted=new \DateTimeImmutable($stored['emitted_at']);$deadline=new \DateTimeImmutable($stored['delivery_deadline_at']);
            if($completed<$emitted||$completed>$deadline->modify('+5 minutes')||($m['status']==='expired'&&$completed<$deadline)||($m['status']!=='expired'&&$completed>$deadline))throw new \DomainException('dialogue_result_time_invalid');
            $this->source($m['message_id'],$stored['installation_id'],$m['session_id'],$m['generation'],'dialogue.delivery',$m['completed_at'],$m['schema'],$m['request_id'],$m['turn_id'],null,$m);
            $this->db->prepare('INSERT INTO dialogue_delivery_results VALUES (:dialogue,:source,:message,:request,:turn,:session,:generation,CAST(:speaker AS jsonb),:status,:reason,:completed,clock_timestamp())')
                ->execute(['dialogue'=>$m['dialogue_message_id'],'source'=>$m['message_id'],'message'=>$m['message_id'],'request'=>$m['request_id'],'turn'=>$m['turn_id'],'session'=>$m['session_id'],'generation'=>$m['generation'],'speaker'=>$this->encode($m['speaker']),'status'=>$m['status'],'reason'=>$m['reason_code'],'completed'=>$m['completed_at']]);
            $this->db->prepare('UPDATE dialogue_utterances SET delivery_state=:state,delivered_at=:completed WHERE dialogue_message_id=:id')
                ->execute(['state'=>$m['status'],'completed'=>$m['completed_at'],'id'=>$m['dialogue_message_id']]);
            $speechState=$m['status']==='played'?'spoken':$m['status'];
            $this->db->prepare('UPDATE speech SET delivery_state=:state WHERE dialogue_message_id=:id')
                ->execute(['state'=>$speechState,'id'=>$m['dialogue_message_id']]);
            $this->eventLog()->updateDialogueDelivery($m['dialogue_message_id'], $m['status']);
            $this->db->prepare("UPDATE durable_jobs SET state='succeeded',completed_at=clock_timestamp(),updated_at=clock_timestamp() WHERE job_id=:job AND state='queued'")->execute(['job'=>$stored['expiry_job_id']]);
            if ($m['status'] === 'played') {
                $speaker = $this->json($stored['speaker']);
                $speakerName = trim((string)($speaker['display_name'] ?? $speaker['record_id'] ?? 'NPC')) ?: 'NPC';
                $memoryId = Uuid::deterministicV4('memory:dialogue:' . $m['dialogue_message_id']);
                $payload = [
                    'memory_id'=>$memoryId,'installation_id'=>$stored['installation_id'],'profile_id'=>$stored['profile_id'],
                    'playthrough_id'=>$stored['playthrough_id'],'tier'=>'recent','content'=>$speakerName . ': ' . $stored['text'],
                    'source_event_id'=>$m['message_id'],'occurred_at'=>$m['completed_at'],
                    'provenance'=>['source'=>'dialogue.delivery','revision'=>1,'dialogue_message_id'=>$m['dialogue_message_id'],
                        'turn_id'=>$m['turn_id'],'request_id'=>$m['request_id'],'status'=>'played',
                        'source_event_ids'=>[$m['message_id']]],
                ];
                $this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) "
                    . "VALUES(:job,'memory.derive',1,:key,CAST(:payload AS jsonb),3,40) ON CONFLICT(job_type,idempotency_key) DO NOTHING")
                    ->execute(['job'=>Uuid::v4(),'key'=>'memory:dialogue:'.$m['dialogue_message_id'],'payload'=>$this->encode($payload)]);
                (new RelationshipEvaluationRepository($this->db))->enqueue($m['message_id']);
            }
            return['duplicate'=>false];
        });
    }

    public function storeMedia(array $m, string $mediaId, string $sha256, int $bytes, string $codec, string $mimeType,
        int $durationMs, string $expiresAt): array
    {
        return $this->transaction(function () use ($m, $mediaId, $sha256, $bytes, $codec, $mimeType, $durationMs, $expiresAt): array {
            $session = $this->session($m['session_id'], $m['generation'], true);
            if ($session['installation_id'] !== $m['installation_id']) throw new \UnexpectedValueException('stale_generation');
            $this->db->prepare('INSERT INTO media_objects (media_id, installation_id, session_id, turn_id, generation, sha256, byte_count, '
                . 'codec, mime_type, duration_ms, expires_at) VALUES (:id, :installation, :session, :turn, :generation, :sha, :bytes, '
                . ':codec, :mime, :duration, :expires)')->execute(['id' => $mediaId, 'installation' => $m['installation_id'],
                    'session' => $m['session_id'], 'turn' => $m['turn_id'], 'generation' => $m['generation'], 'sha' => $sha256,
                    'bytes' => $bytes, 'codec' => $codec, 'mime' => $mimeType, 'duration' => $durationMs, 'expires' => $expiresAt]);
            return $this->event($m['session_id'], $m['generation'], $m['request_id'], $m['turn_id'], 'speech.ready', [
                'media_id' => $mediaId, 'sha256' => $sha256, 'bytes' => $bytes, 'codec' => $codec,
                'duration_ms' => $durationMs, 'expires_at' => $expiresAt,
            ]);
        });
    }

    public function media(string $mediaId): array
    {
        $stmt = $this->db->prepare('SELECT m.*, s.state AS session_state, s.installation_id AS session_installation, '
            . 's.generation AS session_generation, i.token_fingerprint FROM media_objects m JOIN sessions s ON s.session_id = m.session_id '
            . 'JOIN installations i ON i.installation_id = m.installation_id WHERE m.media_id = :id');
        $stmt->execute(['id' => $mediaId]);
        $row = $stmt->fetch();
        if (!$row || $row['deleted_at'] !== null || $row['session_state'] !== 'active'
            || $row['installation_id'] !== $row['session_installation'] || (int) $row['generation'] !== (int) $row['session_generation']
            || new \DateTimeImmutable($row['expires_at']) <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new \OutOfBoundsException('media_unavailable');
        }
        return $row;
    }

    /** Persist one vanilla dialogue TTS artifact without creating a synthetic AI turn. */
    public function recordMenuDialogueSpeech(array $message,array $session,array $speech): void
    {
        $this->transaction(function()use($message,$session,$speech):void{
            $current=$this->session((string)$message['session_id'],(int)$message['generation'],true);
            if((string)$current['installation_id']!==(string)$session['installation_id']
                ||(string)$current['playthrough_id']!==(string)$session['playthrough_id'])
                throw new \UnexpectedValueException('stale_generation');
            $this->db->prepare('INSERT INTO menu_dialogue_tts_requests '
                .'(message_id,request_id,installation_id,playthrough_id,session_id,generation,actor,text_sha256,created_at) '
                .'VALUES (:message,:request,:installation,:playthrough,:session,:generation,CAST(:actor AS jsonb),:text_sha,:created)')
                ->execute(['message'=>$message['message_id'],'request'=>$message['request_id'],
                    'installation'=>$session['installation_id'],'playthrough'=>$session['playthrough_id'],
                    'session'=>$message['session_id'],'generation'=>$message['generation'],'actor'=>$this->encode($message['actor']),
                    'text_sha'=>hash('sha256',(string)$message['text']),'created'=>$message['created_at']]);
            $this->db->prepare('INSERT INTO media_objects (media_id,installation_id,session_id,turn_id,generation,sha256,'
                .'byte_count,codec,mime_type,duration_ms,expires_at,menu_dialogue_message_id) VALUES '
                .'(:id,:installation,:session,NULL,:generation,:sha,:bytes,:codec,:mime,:duration,:expires,:message)')
                ->execute(['id'=>$speech['media_id'],'installation'=>$session['installation_id'],'session'=>$message['session_id'],
                    'generation'=>$message['generation'],'sha'=>$speech['sha256'],'bytes'=>$speech['bytes'],
                    'codec'=>$speech['codec'],'mime'=>$speech['mime_type'],'duration'=>$speech['duration_ms'],
                    'expires'=>$speech['expires_at'],'message'=>$message['message_id']]);
        });
    }

    public function pruneExpiredEvents(): int
    {
        $stmt = $this->db->prepare('DELETE FROM response_events e USING sessions s WHERE e.session_id = s.session_id '
            . 'AND e.sequence <= GREATEST(0, s.event_sequence - :retain)');
        $stmt->bindValue(':retain', $this->eventReplayLimit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function interrupt(array $m): array
    {
        return $this->transaction(function () use ($m): array {
            $this->session($m['session_id'], $m['generation'], true);
            $existing = $this->db->prepare('SELECT * FROM interruptions WHERE message_id = :message OR request_id = :request FOR UPDATE');
            $existing->execute(['message' => $m['message_id'], 'request' => $m['request_id']]);
            if ($row = $existing->fetch()) {
                $same = $row['message_id'] === $m['message_id'] && $row['request_id'] === $m['request_id']
                    && $row['session_id'] === $m['session_id'] && $row['turn_id'] === $m['turn_id']
                    && (int) $row['generation'] === $m['generation'] && $row['reason'] === $m['reason']
                    && $this->utc($row['created_at']) === $m['created_at'];
                if (!$same) throw new \DomainException('duplicate_conflict');
                $cursor = $this->db->prepare("SELECT sequence FROM response_events WHERE session_id = :session AND turn_id = :turn "
                    . "AND event_type = 'turn.cancelled' ORDER BY sequence DESC LIMIT 1");
                $cursor->execute(['session' => $m['session_id'], 'turn' => $m['turn_id']]);
                return ['cursor' => (int) $cursor->fetchColumn(), 'duplicate' => true];
            }
            $stmt = $this->db->prepare('SELECT t.state,t.request_id,t.runtime_generation,s.installation_id,s.profile_id,s.playthrough_id '
                . 'FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE t.turn_id=:turn AND t.session_id=:session FOR UPDATE OF t');
            $stmt->execute(['turn' => $m['turn_id'], 'session' => $m['session_id']]);
            $turn = $stmt->fetch();
            if (!$turn) throw new \OutOfBoundsException('unknown_turn');
            if ($turn['request_id'] !== $m['request_id']) throw new \DomainException('request_mismatch');
            if (!in_array($turn['state'], ['accepted', 'processing'], true)) throw new \DomainException('turn_terminal');
            $this->db->prepare('INSERT INTO interruptions VALUES (:message, :request, :session, :turn, :generation, :reason, :created)')
                ->execute(['message' => $m['message_id'], 'request' => $m['request_id'], 'session' => $m['session_id'], 'turn' => $m['turn_id'],
                    'generation' => $m['generation'], 'reason' => $m['reason'], 'created' => $m['created_at']]);
            $installation = $this->session($m['session_id'], $m['generation']);
            $this->source($m['message_id'], $installation['installation_id'], $m['session_id'], $m['generation'], 'turn.interrupted',
                $m['created_at'], $m['schema'], $m['request_id'], $m['turn_id'], null, $m);
            $terminal=$m+['installation_id'=>$turn['installation_id'],'profile_id'=>$turn['profile_id'],
                'playthrough_id'=>$turn['playthrough_id'],'runtime_generation'=>(int)$turn['runtime_generation']];
            $canonical=$this->validatedCanonicalResponse((new \LorkhanServer\Application\CanonicalResponseNormalizer())
                ->failure($terminal,'interrupted.'.$m['reason']));
            $this->db->prepare("UPDATE turns SET state='cancelled',completed_at=clock_timestamp(),response_id=:response,"
                . "response_payload=CAST(:payload AS jsonb),response_created_at=:created WHERE turn_id=:turn")
                ->execute(['turn'=>$m['turn_id'],'response'=>$canonical['response_id'],
                    'payload'=>$this->encode($canonical),'created'=>$canonical['created_at']]);
            $this->db->prepare("UPDATE durable_jobs SET state='succeeded',completed_at=clock_timestamp(),updated_at=clock_timestamp() WHERE job_type='turn.process' AND idempotency_key=:key AND state='queued'")->execute(['key'=>'turn:'.$m['turn_id']]);
            $this->db->prepare("UPDATE dialogue_utterances SET delivery_state='interrupted',delivered_at=clock_timestamp() WHERE turn_id=:turn AND delivery_state='pending'")->execute(['turn'=>$m['turn_id']]);
            $this->db->prepare("UPDATE media_objects SET expires_at=LEAST(expires_at,clock_timestamp()) WHERE turn_id=:turn AND deleted_at IS NULL")->execute(['turn'=>$m['turn_id']]);
            $this->db->prepare("UPDATE action_intents SET state='terminal' WHERE turn_id=:turn AND state<>'terminal'")->execute(['turn'=>$m['turn_id']]);
            $this->db->prepare("UPDATE action_delivery d SET terminal_at=COALESCE(terminal_at,clock_timestamp()),continuation_state='none',updated_at=clock_timestamp() FROM action_intents a WHERE a.turn_id=:turn AND d.action_id=a.action_id")->execute(['turn'=>$m['turn_id']]);
            $this->event($m['session_id'],$m['generation'],$m['request_id'],$m['turn_id'],
                'response.complete',$canonical,$canonical['response_id']);
            $event = $this->event($m['session_id'], $m['generation'], $m['request_id'], $m['turn_id'], 'turn.cancelled',
                ['reason' => $m['reason']]);
            return ['cursor' => $event['sequence'], 'duplicate' => false];
        });
    }

    public function actionResult(array $m): array
    {
        return $this->transaction(function () use ($m): array {
            $stmt = $this->db->prepare('SELECT a.*, s.installation_id, s.state AS session_state FROM action_intents a '
                . 'JOIN sessions s ON s.session_id = a.session_id WHERE a.action_id = :id FOR UPDATE');
            $stmt->execute(['id' => $m['action_id']]);
            $action = $stmt->fetch();
            if (!$action) throw new \OutOfBoundsException('unknown_action');
            foreach (['session_id', 'turn_id'] as $field) {
                if ($action[$field] !== $m[$field]) throw new \DomainException('action_result_mismatch');
            }
            if ((int) $action['generation'] !== $m['generation']) throw new \UnexpectedValueException('stale_generation');
            // A byte-for-byte terminal replay remains valid after action/session expiry.
            $existing = $this->db->prepare('SELECT message_id, request_id, status, reason_code, observed, completed_at FROM action_results WHERE action_id = :id');
            $existing->execute(['id' => $m['action_id']]);
            if ($row = $existing->fetch()) {
                $same = $row['message_id'] === $m['message_id'] && $row['request_id'] === $m['request_id']
                    && $row['status'] === $m['status'] && $row['reason_code'] === $m['reason_code']
                    && $this->json($row['observed']) == $m['observed'] && $this->utc($row['completed_at']) === $m['completed_at'];
                if (!$same) throw new \DomainException('duplicate_conflict');
                return ['duplicate' => true];
            }
            $completed = new \DateTimeImmutable($m['completed_at']);
            $emitted = new \DateTimeImmutable($this->utc($action['emitted_at']));
            $expires=new \DateTimeImmutable($action['expires_at']);
            $lateBound=$expires->modify('+5 minutes');
            if ($completed < $emitted || $completed > $lateBound) throw new \DomainException('action_result_expired');
            if($completed>$expires&&$m['status']!=='timed_out')throw new \DomainException('action_result_expired');
            $this->source($m['message_id'], $action['installation_id'], $action['session_id'], (int) $action['generation'], 'action.result',
                $m['completed_at'], $m['schema'], $m['request_id'], $action['turn_id'], $m['action_id'], $m);
            $this->db->prepare('INSERT INTO action_results (action_id, source_event_id, message_id, request_id, status, reason_code, observed, completed_at) '
                . 'VALUES (:action, :source, :message, :request, :status, :reason, CAST(:observed AS jsonb), :completed)')
                ->execute(['action' => $m['action_id'], 'source' => $m['message_id'], 'message' => $m['message_id'], 'request' => $m['request_id'],
                    'status' => $m['status'], 'reason' => $m['reason_code'], 'observed' => $this->encode($m['observed']), 'completed' => $m['completed_at']]);
            $this->db->prepare("UPDATE action_intents SET state = 'terminal' WHERE action_id = :id AND state <> 'terminal'")->execute(['id' => $m['action_id']]);
            $this->db->prepare("UPDATE action_delivery d SET terminal_at=:completed,continuation_state=CASE WHEN a.followup_enabled AND d.continuation_state='none' THEN 'eligible' ELSE 'none' END,updated_at=clock_timestamp() FROM action_intents a WHERE d.action_id=:id AND a.action_id=d.action_id")
                ->execute(['completed' => $m['completed_at'], 'id' => $m['action_id']]);
            return ['duplicate' => false];
        });
    }

    private function ensureSessionOwners(array $message): void
    {
        $profile = $this->db->prepare('SELECT installation_id,deleted_at FROM profiles WHERE profile_id = :id FOR UPDATE');
        $profile->execute(['id' => $message['profile_id']]);
        $profileRow = $profile->fetch();
        $profileOwner = $profileRow === false ? false : $profileRow['installation_id'];
        if ($profileOwner !== false && $profileOwner !== $message['installation_id']) {
            throw new \DomainException('profile_scope_conflict');
        }
        if ($profileOwner === false) {
            $this->db->prepare("INSERT INTO profiles (profile_id, installation_id, name, actor_identity, created_at) VALUES "
                . "(:id, :installation, :name, '{}'::jsonb, :created)")
                ->execute(['id' => $message['profile_id'], 'installation' => $message['installation_id'],
                    'name' => 'Imported profile ' . $message['profile_id'], 'created' => $message['created_at']]);
            $this->db->prepare("INSERT INTO profile_revisions (profile_id, revision, content, change_reason, created_at) VALUES "
                . "(:id, 1, '{\"source\":\"session-binding\"}'::jsonb, 'session binding', :created)")
                ->execute(['id' => $message['profile_id'], 'created' => $message['created_at']]);
        } elseif ($profileRow['deleted_at'] !== null) {
            // A configured save profile remains authoritative when the game opens that save again.
            $this->db->prepare('UPDATE profiles SET deleted_at=NULL WHERE profile_id=:id')
                ->execute(['id'=>$message['profile_id']]);
        }

        $playthrough = $this->db->prepare('SELECT installation_id, profile_id FROM playthroughs WHERE playthrough_id = :id');
        $playthrough->execute(['id' => $message['playthrough_id']]);
        $owner = $playthrough->fetch();
        if ($owner && ($owner['installation_id'] !== $message['installation_id'] || $owner['profile_id'] !== $message['profile_id'])) {
            throw new \DomainException('playthrough_scope_conflict');
        }
        if (!$owner) {
            $this->db->prepare('INSERT INTO playthroughs (playthrough_id, installation_id, profile_id, name, content_fingerprint, created_at) '
                . 'VALUES (:id, :installation, :profile, :name, :fingerprint, :created)')
                ->execute(['id' => $message['playthrough_id'], 'installation' => $message['installation_id'],
                    'profile' => $message['profile_id'], 'name' => 'Imported playthrough ' . $message['playthrough_id'],
                    'fingerprint' => $message['content_fingerprint'], 'created' => $message['created_at']]);
            $this->db->prepare("INSERT INTO playthrough_revisions (playthrough_id, revision, content, change_reason, created_at) VALUES "
                . "(:id, 1, '{\"source\":\"session-binding\"}'::jsonb, 'session binding', :created)")
                ->execute(['id' => $message['playthrough_id'], 'created' => $message['created_at']]);
        }
    }

    private function action(array $m, string $requestId, array $action, ?string $messageId = null): array
    {
        $actionId = Uuid::v4();
        $expires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 seconds')->format('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare('INSERT INTO action_intents (action_id, session_id, turn_id, request_id, generation, action_name, tier, actor, target, '
            . 'parameters, expires_at, policy_configuration_id, policy_revision, display_name, confirmation_required, followup_enabled, '
            . 'followup_actions_allowed, followup_depth, followup_prompt, cooldown_seconds, emitted_at) '
            . 'VALUES (:id, :session, :turn, :request, :generation, :name, :tier, CAST(:actor AS jsonb), CAST(:target AS jsonb), '
            . 'CAST(:parameters AS jsonb), :expires, :policy, :policy_revision, :display_name, :confirmation, :followup, '
            . ':followup_actions, :followup_depth, :followup_prompt, :cooldown, clock_timestamp())');
        $stmt->execute(['id' => $actionId, 'session' => $m['session_id'], 'turn' => $m['turn_id'], 'request' => $requestId,
            'generation' => $m['generation'], 'name' => $action['name'], 'tier' => $action['tier'], 'actor' => $this->encode($action['actor']),
            'target' => $this->encode($action['target']), 'parameters' => $this->encode($action['parameters']), 'expires' => $expires,
            'policy'=>$action['policy_configuration_id']??null,'policy_revision'=>$action['policy_revision']??null,
            'display_name'=>$action['display_name']??null,'confirmation'=>!empty($action['confirmation_required'])?'true':'false',
            'followup'=>!empty($action['followup_enabled'])?'true':'false',
            'followup_actions'=>!empty($action['followup_actions_allowed'])?'true':'false',
            'followup_depth'=>(int)($action['followup_depth']??0),'followup_prompt'=>(string)($action['followup_prompt']??''),
            'cooldown'=>(int)($action['cooldown_seconds']??0)]);
        $this->db->prepare("INSERT INTO action_delivery (action_id, emitted_at, continuation_state) VALUES (:id, clock_timestamp(), 'none')")
            ->execute(['id' => $actionId]);
        // PHP represents both an empty JSON object and an empty list as [], so restore the
        // protocol-owned object shape at the wire boundary after catalog validation.
        $wireParameters = $action['parameters'] === [] ? (object) [] : $action['parameters'];
        $payload = ['schema' => 'lorkhan.action-intent.v1', 'action_id' => $actionId, 'turn_id' => $m['turn_id'], 'name' => $action['name'],
            'tier' => $action['tier'], 'actor' => $action['actor'], 'target' => $action['target'], 'parameters' => $wireParameters, 'expires_at' => $expires];
        if (isset($action['display_name'])) $payload['display_name'] = $action['display_name'];
        if (array_key_exists('confirmation_required', $action)) $payload['confirmation_required'] = $action['confirmation_required'];
        if (array_key_exists('followup_enabled', $action)) $payload['followup_enabled'] = $action['followup_enabled'];
        if (array_key_exists('followup_actions_allowed', $action)) $payload['followup_actions_allowed'] = $action['followup_actions_allowed'];
        if (array_key_exists('followup_depth', $action)) $payload['followup_depth'] = $action['followup_depth'];
        return $this->event($m['session_id'], $m['generation'], $requestId, $m['turn_id'], 'action.intent', $payload, $messageId);
    }

    private function event(string $sessionId, int $generation, ?string $requestId, ?string $turnId, string $type,
        array $payload, ?string $messageId = null): array
    {
        $next = $this->db->prepare('UPDATE sessions SET event_sequence = event_sequence + 1 WHERE session_id = :session RETURNING event_sequence');
        $next->execute(['session' => $sessionId]);
        $sequence = (int) $next->fetchColumn();
        $messageId ??= Uuid::v4();
        $created = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare('INSERT INTO response_events '
            . '(session_id, sequence, message_id, request_id, generation, turn_id, event_type, payload, created_at) '
            . 'VALUES (:session, :sequence, :message, :request, :generation, :turn, :type, CAST(:payload AS jsonb), :created)');
        $stmt->execute(['session' => $sessionId, 'sequence' => $sequence, 'message' => $messageId, 'request' => $requestId,
            'generation' => $generation, 'turn' => $turnId, 'type' => $type, 'payload' => $this->encode($payload), 'created' => $created]);
        $scope=$this->db->prepare('SELECT installation_id,playthrough_id FROM sessions WHERE session_id=:session');
        $scope->execute(['session'=>$sessionId]);$owner=$scope->fetch();
        if($owner){
            $actor=is_array($payload['speaker']??null)?$payload['speaker']:[];
            $this->db->prepare('INSERT INTO responselog (installation_id,playthrough_id,session_id,turn_id,response_message_id,localts,sent,actor,text,action,tag,actor_identity,payload,created_at) '
                . 'VALUES (:installation,:playthrough,:session,:turn,:message,extract(epoch FROM CAST(:created AS timestamptz))::bigint,1,:actor,:text,:action,:tag,'
                . 'CAST(:identity AS jsonb),CAST(:payload AS jsonb),:created) ON CONFLICT (response_message_id) DO NOTHING')
                ->execute(['installation'=>$owner['installation_id'],'playthrough'=>$owner['playthrough_id'],'session'=>$sessionId,
                    'turn'=>$turnId,'message'=>$messageId,'created'=>$created,'actor'=>$actor['display_name']??$actor['record_id']??null,
                    'text'=>is_string($payload['text']??null)?$payload['text']:null,'action'=>$type==='action.intent'?($payload['name']??null):null,
                    'tag'=>$type,'identity'=>$actor===[]?'{}':$this->encode($actor),'payload'=>$this->encode($payload)]);
        }
        return ['message_id' => $messageId, 'request_id' => $requestId, 'turn_id' => $turnId, 'session_id' => $sessionId,
            'generation' => $generation, 'sequence' => $sequence, 'created_at' => $created, 'type' => $type, 'payload' => $payload];
    }

    private function lockPendingTurn(string $turnId, string $sessionId, ?array $fence = null): array
    {
        $stmt = $this->db->prepare('SELECT request_id,state,runtime_generation,processing_job_id,processing_lease_token,processing_job_attempt '
            . 'FROM turns WHERE turn_id = :turn AND session_id = :session FOR UPDATE');
        $stmt->execute(['turn' => $turnId, 'session' => $sessionId]);
        $turn = $stmt->fetch();
        if (!$turn) throw new \OutOfBoundsException('unknown_turn');
        if (!in_array($turn['state'], ['accepted', 'processing'], true)) throw new \DomainException('turn_terminal');
        if($fence!==null){
            if($turn['processing_job_id']!==($fence['job_id']??null)||$turn['processing_lease_token']!==($fence['lease_token']??null)
                ||(int)$turn['processing_job_attempt']!==(int)($fence['attempt']??-1)) throw new \RuntimeException('lease_lost');
            $lease=$this->db->prepare("SELECT 1 FROM durable_jobs WHERE job_id=:job AND state='leased' AND lease_token=:token "
                . 'AND attempt_count=:attempt AND lease_expires_at>clock_timestamp()');
            $lease->execute(['job'=>$fence['job_id'],'token'=>$fence['lease_token'],'attempt'=>$fence['attempt']]);
            if(!$lease->fetchColumn())throw new \RuntimeException('lease_lost');
        }
        return $turn;
    }

    private function validateProviderResult(array $result, array $session, array $m): array
    {
        $keys = array_keys($result);
        sort($keys);
        if ($keys !== ['action', 'utterances']) throw new \DomainException('provider_invalid_output');
        if ($result['action'] === null) return$result;
        $action = $result['action'];
        if (!is_array($action) || array_is_list($action)) throw new \DomainException('provider_invalid_action');
        if ($this->actionCatalog !== null && $this->actionPolicy !== null) {
            $loaded=$this->actionCatalog->loadForSession($m['session_id'],$m['generation']);
            if(is_array($m['_action_continuation']??null))$loaded['continuation']=$m['_action_continuation'];
            $validated = $this->actionPolicy->validate($action,$loaded);
            if ($validated['actor'] != $m['payload']['target'] || $validated['target'] != $m['payload']['speaker']) {
                throw new \DomainException('provider_action_not_allowed');
            }
            $result['action']=$validated;return$result;
        }
        $keys = array_keys($action);
        sort($keys);
        if ($keys !== ['actor', 'name', 'parameters', 'target', 'tier'] || !is_string($action['name'])
            || !in_array($action['name'], $session['enabled_actions'], true) || $action['name'] !== 'ai.follow'
            || $action['tier'] !== 1 || !is_array($action['parameters']) || array_is_list($action['parameters'])
            || array_keys($action['parameters']) !== ['distance'] || !is_int($action['parameters']['distance'])
            || $action['parameters']['distance'] !== 192
            || $action['actor'] != $m['payload']['target'] || $action['target'] != $m['payload']['speaker']) {
            throw new \DomainException('provider_action_not_allowed');
        }
        return$result;
    }

    private function cancelOutstandingTurns(string $sessionId,string $reason):void
    {
        $select=$this->db->prepare("SELECT t.turn_id,t.request_id,t.generation,t.runtime_generation,s.installation_id,s.profile_id,s.playthrough_id "
            . "FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE t.session_id=:session "
            . "AND t.state IN ('accepted','processing') FOR UPDATE OF t");
        $select->execute(['session'=>$sessionId]);
        foreach($select->fetchAll() as $turn){
            $terminal=['installation_id'=>$turn['installation_id'],'profile_id'=>$turn['profile_id'],
                'playthrough_id'=>$turn['playthrough_id'],'session_id'=>$sessionId,'turn_id'=>$turn['turn_id'],
                'request_id'=>$turn['request_id'],'generation'=>(int)$turn['generation'],
                'runtime_generation'=>(int)$turn['runtime_generation']];
            $canonical=$this->validatedCanonicalResponse((new \LorkhanServer\Application\CanonicalResponseNormalizer())
                ->failure($terminal,$reason,in_array($reason,['session_ended','session_replaced'],true)));
            $this->db->prepare("UPDATE turns SET state='cancelled',completed_at=clock_timestamp(),response_id=:response,"
                . "response_payload=CAST(:payload AS jsonb),response_created_at=:created WHERE turn_id=:turn")
                ->execute(['turn'=>$turn['turn_id'],'response'=>$canonical['response_id'],
                    'payload'=>$this->encode($canonical),'created'=>$canonical['created_at']]);
            $this->event($sessionId,(int)$turn['generation'],$turn['request_id'],$turn['turn_id'],
                'response.complete',$canonical,$canonical['response_id']);
            $this->event($sessionId,(int)$turn['generation'],$turn['request_id'],$turn['turn_id'],'turn.cancelled',['reason'=>$reason]);
            $this->db->prepare("UPDATE provider_attempts SET state='cancelled',finished_at=clock_timestamp(),error_code='operation_cancelled',"
                . "duration_ms=GREATEST(0,floor(extract(epoch FROM(clock_timestamp()-started_at))*1000)::integer) WHERE turn_id=:turn AND state='started'")
                ->execute(['turn'=>$turn['turn_id']]);
        }
    }

    private function recordPromptTrace(array $turn,array $trace):void
    {
        $settingsSources=$trace['settings_sources']??[];
        $settingsSourcesJson=json_encode($settingsSources===[]?(object)[]:$settingsSources,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $id=Uuid::v4();$this->db->prepare('INSERT INTO prompt_traces (prompt_trace_id,installation_id,profile_id,playthrough_id,session_id,turn_id,request_id,prompt_configuration_id,prompt_revision,selected_profile_id,selected_profile_revision,core_profile_id,core_profile_revision,effective_settings_sha256,settings_sources,algorithm,input_sha256,input_bytes,truncated,created_at) VALUES (:id,:installation,:profile,:playthrough,:session,:turn,:request,:config,:revision,:selected_profile,:selected_revision,:core_profile,:core_revision,:settings_sha,CAST(:settings_sources AS jsonb),:algorithm,:sha,:bytes,:truncated,clock_timestamp())')->execute(['id'=>$id,'installation'=>$turn['installation_id'],'profile'=>$turn['profile_id'],'playthrough'=>$turn['playthrough_id'],'session'=>$turn['session_id'],'turn'=>$turn['turn_id'],'request'=>$turn['request_id'],'config'=>$trace['prompt_configuration_id']===$trace['profile_id']?null:$trace['prompt_configuration_id'],'revision'=>$trace['prompt_revision'],'selected_profile'=>$trace['profile_id'],'selected_revision'=>$trace['profile_revision'],'core_profile'=>$trace['core_profile_id']??null,'core_revision'=>$trace['core_profile_revision']??null,'settings_sha'=>$trace['effective_settings_sha256']??null,'settings_sources'=>$settingsSourcesJson,'algorithm'=>$trace['algorithm'],'sha'=>$trace['input_sha256'],'bytes'=>$trace['input_bytes'],'truncated'=>$trace['truncated']?'true':'false']);
        foreach($trace['sources'] as $source)$this->db->prepare('INSERT INTO prompt_trace_sources '
            . '(prompt_trace_id,ordinal,source_kind,source_id,included,reason,source_sha256,included_bytes,redacted_preview,'
            . 'section_key,section_order,source_table,source_revision,source_occurred_at,playthrough_id,source_characters,estimated_tokens) '
            . 'VALUES (:trace,:ordinal,:kind,:source,:included,:reason,:sha,:bytes,:preview,:section_key,:section_order,:source_table,'
            . ':source_revision,:source_occurred_at,:playthrough,:characters,:tokens)')->execute([
                'trace'=>$id,'ordinal'=>$source['ordinal'],'kind'=>$source['source_kind'],'source'=>$source['source_id'],
                'included'=>$source['included']?'true':'false','reason'=>$source['reason'],'sha'=>$source['source_sha256'],
                'bytes'=>$source['included_bytes'],'preview'=>$source['redacted_preview'],'section_key'=>$source['section_key'],
                'section_order'=>$source['section_order'],'source_table'=>$source['source_table'],
                'source_revision'=>$source['source_revision'],'source_occurred_at'=>$source['source_occurred_at'],
                'playthrough'=>$source['playthrough_id'],'characters'=>$source['source_characters'],'tokens'=>$source['estimated_tokens']]);
        foreach($trace['sections'] as $section)$this->db->prepare('INSERT INTO prompt_trace_sections '
            . '(prompt_trace_id,section_order,section_key,source_refs,inclusion_reason,source_occurred_at,playthrough_id,'
            . 'source_characters,estimated_tokens,redacted_preview,source_sha256) '
            . 'VALUES (:trace,:section_order,:section_key,CAST(:source_refs AS jsonb),:reason,:occurred,:playthrough,'
            . ':characters,:tokens,:preview,:sha)')->execute([
                'trace'=>$id,'section_order'=>$section['section_order'],'section_key'=>$section['section_key'],
                'source_refs'=>$this->encode($section['source_refs']),'reason'=>$section['inclusion_reason'],
                'occurred'=>$section['source_occurred_at'],'playthrough'=>$section['playthrough_id'],
                'characters'=>$section['source_characters'],'tokens'=>$section['estimated_tokens'],
                'preview'=>$section['redacted_preview'],'sha'=>$section['source_sha256']]);
        foreach(['memory_retrieval'=>'memory','knowledge_retrieval'=>'knowledge']as$traceField=>$domain){
        $retrieval=$trace[$traceField]??null;
        if(is_array($retrieval)&&!array_is_list($retrieval)){
            $this->db->prepare('INSERT INTO retrieval_traces '
                . '(retrieval_trace_id,installation_id,profile_id,playthrough_id,domain,query,result_ids,scores,algorithm,created_at,'
                . 'turn_id,prompt_section,reasons) VALUES (:id,:installation,:profile,:playthrough,:domain,:query,'
                . 'CAST(:ids AS uuid[]),CAST(:scores AS jsonb),:algorithm,:created,:turn,:section,CAST(:reasons AS jsonb))')
                ->execute(['id'=>Uuid::v4(),'installation'=>$turn['installation_id'],
                    'profile'=>$retrieval['scope']['profile_id']??$turn['profile_id'],
                    'playthrough'=>$turn['playthrough_id'],'query'=>$retrieval['query'],'ids'=>$this->pgArray($retrieval['result_ids']),
                    'scores'=>$this->encodeObject($retrieval['scores']),'algorithm'=>$retrieval['algorithm'],
                    'created'=>$retrieval['created_at'],'turn'=>$turn['turn_id'],'domain'=>$domain,
                    'section'=>$retrieval['prompt_section'],'reasons'=>$this->encodeObject($retrieval['reasons'])]);
        }
        }
    }

    private function source(string $id, string $installation, ?string $session, ?int $generation, string $kind, string $occurred,
        string $schema, ?string $request, ?string $turn, ?string $action, array $payload,
        array $projectionContext = []): void
    {
        $stmt = $this->db->prepare('INSERT INTO source_events (source_event_id, installation_id, session_id, generation, event_kind, occurred_at, '
            . 'schema_name, request_id, turn_id, action_id, payload) VALUES (:id, :installation, :session, :generation, :kind, :occurred, '
            . ':schema, :request, :turn, :action, CAST(:payload AS jsonb))');
        $stmt->execute(['id' => $id, 'installation' => $installation, 'session' => $session, 'generation' => $generation, 'kind' => $kind,
            'occurred' => $occurred, 'schema' => $schema, 'request' => $request, 'turn' => $turn, 'action' => $action, 'payload' => $this->encode($payload)]);
        $this->eventLog()->projectSource($id, $installation, $session, $kind, $occurred, $request, $turn, $action, $payload,
            $projectionContext);
    }

    private function eventLog(): EventLogRepository
    {
        return $this->eventLogRepository ?? new EventLogRepository($this->db);
    }

    private function validatedCanonicalResponse(array $response):array
    {
        (new \LorkhanServer\Protocol\Validator())->validate($response,'lorkhan.response.v1');
        return $response;
    }

    private function encode(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); }
    private function encodeObject(array $value):string{return json_encode($value===[]?(object)[]:$value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
    private function encodeCanonical(array $value):string{$sort=static function(mixed $v)use(&$sort):mixed{if(!is_array($v))return$v;if(array_is_list($v))return array_map($sort,$v);ksort($v);foreach($v as &$x)$x=$sort($x);return$v;};return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
    private function json(mixed $value): array { return is_array($value) ? $value : json_decode((string) $value, true, 64, JSON_THROW_ON_ERROR); }
    private function pgArray(array $values): string { return '{' . implode(',', array_map(fn($v) => '"' . addcslashes((string) $v, '"\\') . '"', $values)) . '}'; }
    private function parsePgArray(string $value): array { if ($value === '{}') return []; return str_getcsv(trim($value, '{}'), ',', '"', '\\'); }
    private function utc(string $value): string { return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'); }
}
