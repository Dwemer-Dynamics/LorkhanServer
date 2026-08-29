<?php

declare(strict_types=1);

namespace ALMSIVIserver\Infrastructure;

use ALMSIVIserver\Application\PlayerMoodPolicy;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class EventLogRepository
{
    private const DEFAULT_HIDDEN_TYPES = [
        'prechat','rechat','infonpc','request','infonpc_close','addnpc','addbgnpc','user_input','infosave','init',
        'playerinfo','oghma_import','biography_import','dynamic_oghma_import','infoitems','description_import',
        'traditional_quest_import','backgroundaction','innerchat','npc_reanimated','npcvoice_refresh','status_msg',
        'region','ext_nsfw_physics_raw','session.init','turn.requested','dialogue.delivery','turn.interrupted',
        'stt.transcript',
    ];

    public function __construct(private readonly PDO $db) {}

    /** Resolve the active browser scope without trusting names as ownership keys. */
    public function scope(?string $installationId = null, ?string $playthroughId = null): ?array
    {
        foreach ([$installationId, $playthroughId] as $id) {
            if ($id !== null && preg_match('/^[0-9a-f-]{36}$/D', $id) !== 1) {
                throw new InvalidArgumentException('invalid_eventlog_scope');
            }
        }
        $where = [];
        $parameters = [];
        if ($installationId !== null) {
            $where[] = 's.installation_id=:installation';
            $parameters['installation'] = $installationId;
        }
        if ($playthroughId !== null) {
            $where[] = 's.playthrough_id=:playthrough';
            $parameters['playthrough'] = $playthroughId;
        }
        $statement = $this->db->prepare(
            'SELECT s.installation_id,s.playthrough_id,s.profile_id,s.session_id,s.state,s.created_at,'
            . "COALESCE(i.display_name,s.installation_id::text) AS installation_name,COALESCE(p.name,s.playthrough_id::text) AS playthrough_name "
            . 'FROM sessions s JOIN installations i ON i.installation_id=s.installation_id '
            . 'LEFT JOIN playthroughs p ON p.playthrough_id=s.playthrough_id '
            . ($where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . ' ')
            . "ORDER BY (s.state='active') DESC,s.created_at DESC,s.session_id DESC LIMIT 1"
        );
        $statement->execute($parameters);
        $sessionScope = $statement->fetch();
        if ($sessionScope) return $sessionScope;
        if ($installationId === null) return null;
        $fallback = $this->db->prepare('SELECT i.installation_id,p.playthrough_id,p.profile_id,NULL::uuid AS session_id,'
            . "'inactive'::text AS state,p.created_at,i.display_name AS installation_name,p.name AS playthrough_name "
            . 'FROM installations i JOIN playthroughs p ON p.installation_id=i.installation_id '
            . 'WHERE i.installation_id=:installation AND (CAST(:playthrough_filter AS uuid) IS NULL OR p.playthrough_id=CAST(:playthrough AS uuid)) '
            . 'ORDER BY p.created_at DESC,p.playthrough_id DESC LIMIT 1');
        $fallback->execute(['installation'=>$installationId,'playthrough_filter'=>$playthroughId,'playthrough'=>$playthroughId]);
        return $fallback->fetch() ?: null;
    }

    /** Return one CHIM-shaped page, including contiguous cursor reads for live monitoring. */
    public function page(array $query): array
    {
        $scope = $this->scope($this->nullableString($query['installation_id'] ?? null),
            $this->nullableString($query['playthrough_id'] ?? null));
        $limit = max(10, min(500, (int) ($query['limit'] ?? 100)));
        $page = max(1, (int) ($query['page'] ?? 1));
        $sinceRowId = max(0, (int) ($query['since_rowid'] ?? 0));
        $sinceGamets = max(0, (int) ($query['since_gamets'] ?? 0));
        $selectedType = trim((string) ($query['event_type'] ?? ''));
        if (strlen($selectedType) > 128) throw new InvalidArgumentException('invalid_event_type');
        if ($scope === null) {
            return ['success'=>true,'scope'=>null,'data'=>[],'new_count'=>0,'latest_gamets'=>0,'hidden_types'=>[],
                'event_types'=>[],'pagination'=>['current_page'=>1,'total_pages'=>0,'total_records'=>0,'limit'=>$limit]];
        }

        $hidden = array_values(array_unique(array_merge(self::DEFAULT_HIDDEN_TYPES,
            $this->hiddenTypes((string) $scope['installation_id']))));
        $parameters = ['installation'=>$scope['installation_id'],'playthrough'=>$scope['playthrough_id']];
        $where = ['m.installation_id=:installation','m.playthrough_id=:playthrough','m.suppressed_at IS NULL'];
        if ($hidden !== []) {
            $placeholders = [];
            foreach ($hidden as $index => $type) {
                $key = 'hidden_' . $index;
                $parameters[$key] = $type;
                $placeholders[] = ':' . $key;
            }
            $where[] = 'e.type NOT IN (' . implode(',', $placeholders) . ')';
        }
        if ($selectedType !== '') {
            $where[] = 'e.type=:selected_type';
            $parameters['selected_type'] = $selectedType;
        }
        if ($sinceRowId > 0) {
            $where[] = 'e.rowid>:since_rowid';
            $parameters['since_rowid'] = $sinceRowId;
        } elseif ($sinceGamets > 0) {
            $where[] = 'e.gamets>=:since_gamets';
            $parameters['since_gamets'] = $sinceGamets;
        }

        $baseSql = 'SELECT e.type,e.data,e.people,e.gamets,e.localts,e.ts,e.rowid,e.location,e.delivery_state '
            . 'FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid WHERE ' . implode(' AND ', $where);
        if ($sinceRowId > 0) {
            $sql = 'SELECT * FROM (' . $baseSql . ' ORDER BY e.rowid ASC LIMIT :limit) incremental '
                . 'ORDER BY gamets DESC,ts DESC,localts DESC,rowid DESC';
        } else {
            $sql = $baseSql . ' ORDER BY e.gamets DESC,e.ts DESC,e.localts DESC,e.rowid DESC LIMIT :limit OFFSET :offset';
        }
        $statement = $this->db->prepare($sql);
        foreach ($parameters as $key => $value) $statement->bindValue(':' . $key, $value);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        if ($sinceRowId === 0) $statement->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = array_map(fn(array $row): array => $this->present($row), $statement->fetchAll());

        $countParameters = ['installation'=>$scope['installation_id'],'playthrough'=>$scope['playthrough_id']];
        $countWhere = ['m.installation_id=:installation','m.playthrough_id=:playthrough','m.suppressed_at IS NULL'];
        if ($hidden !== []) {
            $items = [];
            foreach ($hidden as $index => $type) {
                $key = 'count_hidden_' . $index;
                $countParameters[$key] = $type;
                $items[] = ':' . $key;
            }
            $countWhere[] = 'e.type NOT IN (' . implode(',', $items) . ')';
        }
        if ($selectedType !== '') {
            $countWhere[] = 'e.type=:count_selected_type';
            $countParameters['count_selected_type'] = $selectedType;
        }
        $count = $this->db->prepare('SELECT count(*) FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid WHERE '
            . implode(' AND ', $countWhere));
        $count->execute($countParameters);
        $total = (int) $count->fetchColumn();

        return ['success'=>true,'scope'=>$scope,'data'=>$rows,'new_count'=>count($rows),
            'latest_gamets'=>$rows === [] ? 0 : max(array_column($rows, 'gamets')),
            'hidden_types'=>$this->hiddenTypes((string) $scope['installation_id']),
            'event_types'=>$this->visibleTypes($scope, $hidden),
            'pagination'=>['current_page'=>$page,'total_pages'=>(int) ceil($total / $limit),'total_records'=>$total,'limit'=>$limit]];
    }

    public function hideType(string $installationId, string $type): array
    {
        $type = trim($type);
        if ($type === '' || strlen($type) > 128) throw new InvalidArgumentException('invalid_event_type');
        $statement = $this->db->prepare('INSERT INTO eventlog_hidden_types (installation_id,event_type) VALUES (:installation,:type) ON CONFLICT DO NOTHING');
        $statement->execute(['installation'=>$installationId,'type'=>$type]);
        return $this->hiddenTypes($installationId);
    }

    public function showType(string $installationId, string $type): array
    {
        $statement = $this->db->prepare('DELETE FROM eventlog_hidden_types WHERE installation_id=:installation AND event_type=:type');
        $statement->execute(['installation'=>$installationId,'type'=>trim($type)]);
        return $this->hiddenTypes($installationId);
    }

    public function clearHiddenTypes(string $installationId): array
    {
        $this->db->prepare('DELETE FROM eventlog_hidden_types WHERE installation_id=:installation')
            ->execute(['installation'=>$installationId]);
        return [];
    }

    /** Suppress only the mutable projection; immutable source events, turns and speech remain intact. */
    public function suppress(array $input): array
    {
        $scope = $this->scope($this->nullableString($input['installation_id'] ?? null),
            $this->nullableString($input['playthrough_id'] ?? null));
        if ($scope === null) throw new InvalidArgumentException('invalid_eventlog_scope');
        $mode = (string) ($input['mode'] ?? '');
        $rowIds = [];
        if ($mode === 'row') {
            $rowIds = [(int) ($input['rowid'] ?? 0)];
        } elseif ($mode === 'selected') {
            $values = $input['rowids'] ?? null;
            if (!is_array($values) || !array_is_list($values) || count($values) > 500) {
                throw new InvalidArgumentException('invalid_event_rows');
            }
            $rowIds = array_map('intval', $values);
        } elseif ($mode === 'latest') {
            $count = (int) ($input['count'] ?? 0);
            if (!in_array($count, [5,10,20,50,100], true)) throw new InvalidArgumentException('invalid_delete_count');
            $hiddenTypes = array_values(array_unique(array_merge(
                self::DEFAULT_HIDDEN_TYPES,
                $this->hiddenTypes((string) $scope['installation_id'])
            )));
            $select = $this->db->prepare('SELECT e.rowid FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid '
                . 'WHERE m.installation_id=:installation AND m.playthrough_id=:playthrough AND m.suppressed_at IS NULL '
                . 'AND e.type <> ALL(CAST(:hidden AS text[])) ORDER BY e.gamets DESC,e.ts DESC,e.localts DESC,e.rowid DESC LIMIT :limit');
            $select->bindValue(':installation', $scope['installation_id']);
            $select->bindValue(':playthrough', $scope['playthrough_id']);
            $select->bindValue(':hidden', $this->pgArray($hiddenTypes));
            $select->bindValue(':limit', $count, PDO::PARAM_INT);
            $select->execute();
            $rowIds = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
        } elseif ($mode === 'all') {
            if (($input['confirmation'] ?? null) !== 'Delete') throw new InvalidArgumentException('delete_confirmation_required');
            $statement = $this->db->prepare("UPDATE eventlog_metadata SET suppressed_at=clock_timestamp(),suppression_reason='browser_delete_all' "
                . 'WHERE installation_id=:installation AND playthrough_id=:playthrough AND suppressed_at IS NULL');
            $statement->execute(['installation'=>$scope['installation_id'],'playthrough'=>$scope['playthrough_id']]);
            return ['ok'=>true,'deleted_count'=>$statement->rowCount()];
        } else {
            throw new InvalidArgumentException('invalid_delete_mode');
        }
        $rowIds = array_values(array_unique(array_filter($rowIds, static fn(int $id): bool => $id > 0)));
        if ($rowIds === []) return ['ok'=>true,'deleted_count'=>0];
        $placeholders = [];
        $parameters = ['installation'=>$scope['installation_id'],'playthrough'=>$scope['playthrough_id']];
        foreach ($rowIds as $index => $rowId) {
            $key = 'row_' . $index;
            $parameters[$key] = $rowId;
            $placeholders[] = ':' . $key;
        }
        $statement = $this->db->prepare("UPDATE eventlog_metadata SET suppressed_at=clock_timestamp(),suppression_reason='browser_delete' "
            . 'WHERE installation_id=:installation AND playthrough_id=:playthrough AND suppressed_at IS NULL '
            . 'AND rowid IN (' . implode(',', $placeholders) . ')');
        $statement->execute($parameters);
        return ['ok'=>true,'deleted_count'=>$statement->rowCount()];
    }

    /** Project only roleplay-relevant typed source records into the CHIM event log. */
    public function projectSource(string $sourceId, string $installationId, ?string $sessionId, string $kind,
        string $occurredAt, ?string $requestId, ?string $turnId, ?string $actionId, array $payload): void
    {
        if ($sessionId === null) return;
        $scope = $this->scopeForSession($sessionId);
        $body = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        $speaker = $this->object($body['speaker'] ?? []);
        $target = $this->object($body['target'] ?? []);
        $audience = $this->list($body['audience'] ?? []);
        $context = $this->object($body['context'] ?? []);
        $common = ['installation_id'=>$installationId,'playthrough_id'=>$scope['playthrough_id'],'profile_id'=>$scope['profile_id'],
            'session_id'=>$sessionId,'source_event_id'=>$sourceId,'request_id'=>$requestId,'turn_id'=>$turnId,
            'speaker'=>$speaker,'target'=>$target,'audience'=>$audience,'payload'=>$body,'created_at'=>$occurredAt,
            'gamets'=>$this->gameTime($context),'location'=>$this->location($context),'sess'=>$sessionId,
            'people'=>$this->people($speaker,$target,$audience)];
        if ($kind === 'turn.requested' || $kind === 'rechat') {
            $this->projectContext($sourceId, $common, $context);
            $input = $this->object($body['input'] ?? []);
            $text = is_string($input['text'] ?? null) ? trim($input['text']) : '';
            if ($text === '') return;
            $type = $kind === 'rechat' ? 'rechat' : 'inputtext';
            $projectedText=PlayerMoodPolicy::decorate($text,$input['mood']??null);
            $this->insert($common + ['type'=>$type,'data'=>$this->displayName($speaker,'Player').': '.$projectedText,
                'projection_kind'=>'turn','projection_key'=>'turn:'.($turnId ?? $sourceId),'delivery_state'=>null,'utterance_id'=>null]);
            return;
        }
        if ($kind === 'action.result') {
            $status = is_string($body['status'] ?? null) ? $body['status'] : 'completed';
            $actionName = $this->actionName($actionId);
            $text=$this->displayName($speaker,'Actor').' '.$actionName.' '.$status;
            $this->insert(array_merge($common, ['payload'=>['text'=>$text,'action'=>$actionName,'status'=>$status],
                'type'=>'infoaction','data'=>$text,
                'projection_kind'=>'action','projection_key'=>'action-result:'.$sourceId,'delivery_state'=>null,'utterance_id'=>null]));
            return;
        }
        if (in_array($kind, ['location','death','narration'], true)) {
            $text = is_string($body['text'] ?? null) ? $body['text'] : json_encode($body, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $this->insert(array_merge($common, ['payload'=>['text'=>(string)$text],'type'=>$kind,'data'=>(string) $text,
                'projection_kind'=>'world','projection_key'=>$kind.':'.$sourceId,
                'delivery_state'=>null,'utterance_id'=>null]));
        }
    }

    /** Add one generated NPC utterance using its UUID as the exact delivery correlation key. */
    public function projectDialogue(array $turn, array $utterance, string $dialogueMessageId, string $createdAt): void
    {
        $context = $this->object($turn['payload']['context'] ?? []);
        $speaker = $this->object($utterance['speaker'] ?? []);
        $target = $this->object($utterance['addressee'] ?? []);
        $audience = $this->list($utterance['audience'] ?? []);
        $text = (string) ($utterance['text'] ?? '');
        $this->insert(['installation_id'=>$turn['installation_id'],'playthrough_id'=>$turn['playthrough_id'],
            'profile_id'=>$turn['profile_id'] ?? null,'session_id'=>$turn['session_id'],'source_event_id'=>null,
            'request_id'=>$turn['request_id'] ?? null,'turn_id'=>$turn['turn_id'],'speaker'=>$speaker,'target'=>$target,
            'audience'=>$audience,'payload'=>['text'=>$text,'speaker'=>$speaker,'addressee'=>$target,'audience'=>$audience],
            'created_at'=>$createdAt,'gamets'=>$this->gameTime($context),'location'=>$this->location($context),
            'sess'=>$turn['session_id'],'people'=>$this->people($speaker,$target,$audience),'type'=>'chat',
            'data'=>$this->displayName($speaker,'NPC').': '.$text,'projection_kind'=>'dialogue',
            'projection_key'=>'dialogue:'.$dialogueMessageId,'delivery_state'=>'emitted','utterance_id'=>$dialogueMessageId,
            'dialogue_message_id'=>$dialogueMessageId]);
    }

    public function updateDialogueDelivery(string $dialogueMessageId, string $state): void
    {
        $statement = $this->db->prepare('UPDATE eventlog e SET delivery_state=:state FROM eventlog_metadata m '
            . 'WHERE m.rowid=e.rowid AND m.dialogue_message_id=:dialogue');
        $statement->execute(['state'=>$state,'dialogue'=>$dialogueMessageId]);
    }

    private function projectContext(string $sourceId, array $common, array $context): void
    {
        $location = $this->location($context);
        if ($location !== null) {
            $latest = $this->db->prepare("SELECT e.location FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid "
                . "WHERE m.installation_id=:installation AND m.playthrough_id=:playthrough AND m.suppressed_at IS NULL AND e.type='location' "
                . 'ORDER BY e.rowid DESC LIMIT 1');
            $latest->execute(['installation'=>$common['installation_id'],'playthrough'=>$common['playthrough_id']]);
            if ($latest->fetchColumn() !== $location) {
                $world=$this->object($context['world']??[]);
                $payload=['location'=>$location];
                foreach(['region','cell_identity','calendar','weather','game_time']as$field){if(array_key_exists($field,$world))$payload[$field]=$world[$field];}
                $this->insert(array_merge($common, ['payload'=>$payload,'type'=>'location','data'=>'Player entered '.$location,
                    'projection_kind'=>'location','projection_key'=>'location:'.$sourceId,
                    'delivery_state'=>null,'utterance_id'=>null]));
            }
        }
        $weather=$this->object($context['world']['weather']??[]);
        $weatherName=trim((string)($weather['name']??$weather['record_id']??''));
        if($weatherName!==''){
            $latest=$this->db->prepare("SELECT e.data FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid "
                ."WHERE m.installation_id=:installation AND m.playthrough_id=:playthrough AND m.suppressed_at IS NULL AND e.type='weather' "
                .'ORDER BY e.rowid DESC LIMIT 1');
            $latest->execute(['installation'=>$common['installation_id'],'playthrough'=>$common['playthrough_id']]);
            $text='Weather changed to '.$weatherName;
            if($latest->fetchColumn()!==$text){$this->insert(array_merge($common,['payload'=>['weather'=>$weatherName,
                'record_id'=>$weather['record_id']??null,'is_storm'=>($weather['is_storm']??false)===true],
                'type'=>'weather','data'=>$text,'projection_kind'=>'weather','projection_key'=>'weather:'.$sourceId,
                'delivery_state'=>null,'utterance_id'=>null]));}
        }
        $journal = $this->list($context['journal']['items'] ?? []);
        foreach ($journal as $item) {
            if (!is_array($item) || array_is_list($item)) continue;
            $text = (string) ($item['text'] ?? $item['journal_entry'] ?? '');
            if ($text === '') continue;
            $normalizedText=mb_strtolower(preg_replace('/\s+/u',' ',trim($text))??trim($text),'UTF-8');
            $key = hash('sha256', json_encode([
                'quest_id'=>mb_strtolower(trim((string)($item['quest_id']??$item['id']??$item['record_id']??'')),'UTF-8'),
                'index'=>$item['index']??$item['stage']??null,
                'text'=>$normalizedText,
            ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $this->insert(array_merge($common, ['payload'=>$item,'type'=>'quest','data'=>$text,'projection_kind'=>'journal',
                'projection_key'=>'quest:'.$common['playthrough_id'].':'.$key,'delivery_state'=>null,'utterance_id'=>null]));
        }
        $books = $this->list($context['books']['items'] ?? []);
        foreach ($books as $item) {
            if (!is_array($item) || array_is_list($item)) continue;
            $recordId = trim((string) ($item['record_id'] ?? ''));
            if ($recordId === '') continue;
            $title = trim((string) ($item['title'] ?? $recordId));
            $text = trim((string) ($item['text'] ?? ''));
            $this->insert(array_merge($common, ['payload'=>$item,'type'=>'book','data'=>$title.($text === '' ? '' : ': '.$text),
                'projection_kind'=>'book','projection_key'=>'book:'.$common['playthrough_id'].':'.strtolower($recordId),
                'delivery_state'=>null,'utterance_id'=>null]));
        }
    }

    /** Insert the CHIM row and typed metadata atomically; duplicate projections are harmless. */
    private function insert(array $row): void
    {
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $exists = $this->db->prepare('SELECT 1 FROM eventlog_metadata WHERE projection_kind=:kind AND projection_key=:key');
            $exists->execute(['kind'=>$row['projection_kind'],'key'=>$row['projection_key']]);
            if ($exists->fetchColumn()) {
                if ($owns) $this->db->commit();
                return;
            }
            $event = $this->db->prepare('INSERT INTO eventlog (type,data,sess,gamets,localts,ts,people,location,party,utterance_id,delivery_state) '
                . 'VALUES (:type,:data,:sess,:gamets,extract(epoch FROM CAST(:created AS timestamptz))::bigint,'
                . '(extract(epoch FROM CAST(:created AS timestamptz))*1000)::bigint,:people,:location,NULL,:utterance,:delivery) RETURNING rowid');
            $event->execute(['type'=>$row['type'],'data'=>$row['data'],'sess'=>$row['sess'],'gamets'=>$row['gamets'],
                'created'=>$row['created_at'],'people'=>$row['people'],'location'=>$row['location'],
                'utterance'=>$row['utterance_id'] ?? null,'delivery'=>$row['delivery_state'] ?? null]);
            $rowId = (int) $event->fetchColumn();
            $metadata = $this->db->prepare('INSERT INTO eventlog_metadata (rowid,installation_id,playthrough_id,profile_id,session_id,'
                . 'source_event_id,dialogue_message_id,request_id,turn_id,projection_kind,projection_key,speaker,target,audience,payload,created_at) '
                . 'VALUES (:rowid,:installation,:playthrough,:profile,:session,:source,:dialogue,:request,:turn,:kind,:key,'
                . 'CAST(:speaker AS jsonb),CAST(:target AS jsonb),CAST(:audience AS jsonb),CAST(:payload AS jsonb),:created)');
            $metadata->execute(['rowid'=>$rowId,'installation'=>$row['installation_id'],'playthrough'=>$row['playthrough_id'],
                'profile'=>$row['profile_id'] ?? null,'session'=>$row['session_id'] ?? null,'source'=>$row['source_event_id'] ?? null,
                'dialogue'=>$row['dialogue_message_id'] ?? null,'request'=>$row['request_id'] ?? null,'turn'=>$row['turn_id'] ?? null,
                'kind'=>$row['projection_kind'],'key'=>$row['projection_key'],'speaker'=>$this->encodeObject($row['speaker'] ?? []),
                'target'=>$this->encodeObject($row['target'] ?? []),'audience'=>$this->encodeList($row['audience'] ?? []),
                'payload'=>$this->encodeObject($row['payload'] ?? []),'created'=>$row['created_at']]);
            if ($owns) $this->db->commit();
        } catch (Throwable $error) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function hiddenTypes(string $installationId): array
    {
        $statement = $this->db->prepare('SELECT event_type FROM eventlog_hidden_types WHERE installation_id=:installation ORDER BY event_type');
        $statement->execute(['installation'=>$installationId]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function visibleTypes(array $scope, array $hidden): array
    {
        $parameters = ['installation'=>$scope['installation_id'],'playthrough'=>$scope['playthrough_id']];
        $where = ['m.installation_id=:installation','m.playthrough_id=:playthrough','m.suppressed_at IS NULL'];
        if ($hidden !== []) {
            $items = [];
            foreach ($hidden as $index => $type) {
                $key = 'type_hidden_' . $index;
                $parameters[$key] = $type;
                $items[] = ':' . $key;
            }
            $where[] = 'e.type NOT IN (' . implode(',', $items) . ')';
        }
        $statement = $this->db->prepare('SELECT e.type,count(*) AS total FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid '
            . 'WHERE ' . implode(' AND ', $where) . ' GROUP BY e.type ORDER BY e.type');
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    private function present(array $row): array
    {
        $people = trim((string) ($row['people'] ?? ''), '|');
        return ['rowid'=>(int) $row['rowid'],'type'=>(string) $row['type'],'data'=>(string) $row['data'],
            'people'=>$people === '' ? '' : str_replace('|', ', ', $people),'gamets'=>(int) $row['gamets'],
            'game_time'=>$this->formatGameTime((int) $row['gamets']),
            'localts'=>(int) $row['localts'],'time_utc'=>gmdate('d-m-Y H:i:s', (int) $row['localts']),
            'ts'=>$row['ts'] === null ? null : (int) $row['ts'],'location'=>$row['location'],
            'delivery_state'=>$row['delivery_state']];
    }

    private function scopeForSession(string $sessionId): array
    {
        $statement = $this->db->prepare('SELECT installation_id,playthrough_id,profile_id FROM sessions WHERE session_id=:session');
        $statement->execute(['session'=>$sessionId]);
        $row = $statement->fetch();
        if (!$row) throw new RuntimeException('unknown_session');
        return $row;
    }

    private function actionName(?string $actionId): string
    {
        if ($actionId === null) return 'action';
        $statement = $this->db->prepare('SELECT action_name FROM action_intents WHERE action_id=:action');
        $statement->execute(['action'=>$actionId]);
        return (string) ($statement->fetchColumn() ?: 'action');
    }

    private function gameTime(array $context): int
    {
        $value = $context['world']['game_time'] ?? 0;
        return is_int($value) || is_float($value) ? max(0, (int) floor($value)) : 0;
    }

    private function formatGameTime(int $seconds): string
    {
        if ($seconds <= 0) return '—';
        $day = intdiv($seconds, 86_400) + 1;
        $withinDay = $seconds % 86_400;
        return sprintf('Day %d, %02d:%02d', $day, intdiv($withinDay, 3_600), intdiv($withinDay % 3_600, 60));
    }

    private function location(array $context): ?string
    {
        $value = $context['world']['cell'] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function people(array $speaker, array $target, array $audience): string
    {
        $names = [];
        foreach (array_merge([$speaker,$target], $audience) as $identity) {
            if (!is_array($identity)) continue;
            $name = $this->displayName($identity, '');
            if ($name !== '') $names[strtolower($name)] = $name;
        }
        return $names === [] ? '' : '|' . implode('|', array_values($names)) . '|';
    }

    private function displayName(array $identity, string $fallback): string
    {
        foreach (['display_name','name','record_id'] as $field) {
            if (is_string($identity[$field] ?? null) && trim($identity[$field]) !== '') return trim($identity[$field]);
        }
        return $fallback;
    }

    private function object(mixed $value): array { return is_array($value) && !array_is_list($value) ? $value : []; }
    private function list(mixed $value): array { return is_array($value) && array_is_list($value) ? $value : []; }
    private function canonicalValue(mixed $value): mixed
    {
        if(!is_array($value))return$value;
        if(array_is_list($value))return array_map(fn(mixed$item):mixed=>$this->canonicalValue($item),$value);
        ksort($value,SORT_STRING);foreach($value as$key=>$item)$value[$key]=$this->canonicalValue($item);return$value;
    }
    private function nullableString(mixed $value): ?string { return is_string($value) && trim($value) !== '' ? trim($value) : null; }
    private function encodeObject(array $value): string { return json_encode($value === [] ? (object) [] : $value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
    private function encodeList(array $value): string { return json_encode(array_values($value), JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
    private function pgArray(array $values): string { return '{' . implode(',', array_map(fn(string $v): string => '"' . addcslashes($v, '"\\') . '"', $values)) . '}'; }
}
