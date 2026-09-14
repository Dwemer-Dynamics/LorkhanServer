<?php

declare(strict_types=1);

namespace LorkhanServer\Protocol;

final class Validator
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    private const TIMESTAMP = '/^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](\.[0-9]{1,9})?Z$/D';
    private const FINGERPRINT = '/^sha256:[0-9a-f]{64}$/D';

    /** @return array<string, mixed> */
    public function decode(string $json, int $maxBytes): array
    {
        if (strlen($json) > $maxBytes) {
            throw new ValidationException('payload_too_large');
        }
        try {
            $document = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException('invalid_schema');
        }
        if (!is_object($document) || !is_array($value)) {
            throw new ValidationException('invalid_schema');
        }
        return $value;
    }

    /** @param array<string, mixed> $message */
    public function validate(array $message, string $expectedSchema): void
    {
        match ($expectedSchema) {
            'lorkhan.session.init.v1' => $this->session($message),
            'lorkhan.turn.v1' => $this->turn($message),
            'lorkhan.gamedata.v1' => $this->gameData($message),
            'lorkhan.interrupt.v1' => $this->interrupt($message),
            'lorkhan.action-result.v1' => $this->actionResult($message),
            'lorkhan.stt.request.v1' => $this->stt($message),
            'lorkhan.dialogue-delivery-result.v1' => $this->delivery($message),
            'lorkhan.menu-dialogue-tts.v1' => $this->menuDialogueTts($message),
            'lorkhan.book.read-aloud.v1' => $this->bookReadAloud($message),
            'lorkhan.player-autochat.v1' => $this->playerAutochat($message),
            'lorkhan.controls.query.v1' => $this->controlsQuery($message),
            'lorkhan.controls.select.v1' => $this->controlsSelect($message),
            'lorkhan.debug-command.query.v1' => $this->debugCommandQuery($message),
            'lorkhan.debug-command-result.v1' => $this->debugCommandResult($message),
            'lorkhan.response.v1' => $this->response($message),
            default => throw new ValidationException('invalid_schema'),
        };
    }

    /** @param array<string, mixed> $message */
    private function session(array $message): void
    {
        $keys=['schema','message_id','installation_id','profile_id','playthrough_id','generation','created_at','runtime','content_fingerprint'];
        if(array_key_exists('loaded_save',$message)){
            $keys[]='loaded_save';$calendar=$message['loaded_save'];
            if($calendar!==null){
                if(!is_array($calendar))throw new ValidationException('invalid_schema');
                $this->keys($calendar,['year','month','day','hour']);
                if(!(is_int($calendar['hour'])||is_float($calendar['hour']))||!is_finite((float)$calendar['hour'])
                    ||$calendar['hour']<0||$calendar['hour']>=24||\LorkhanServer\Application\MorrowindCalendar::parse($calendar)===null)throw new ValidationException('invalid_schema');
            }
        }
        $this->keys($message, $keys);
        $this->common($message, 'lorkhan.session.init.v1', ['message_id','installation_id','profile_id','playthrough_id']);
    }

    /** @param array<string, mixed> $message */
    private function turn(array $message): void
    {
        $this->keys($message, ['schema','message_id','request_id','turn_id','installation_id','profile_id','playthrough_id','session_id','generation','runtime_generation','created_at','runtime','content_fingerprint','payload']);
        $this->common($message, 'lorkhan.turn.v1', ['message_id','request_id','turn_id','installation_id','profile_id','playthrough_id','session_id']);
        if (!is_int($message['runtime_generation']) || $message['runtime_generation'] < 1
            || $message['runtime_generation'] > 9_007_199_254_740_991) {
            throw new ValidationException('invalid_schema');
        }
        $payload = $message['payload'] ?? null;
        if (!is_array($payload) || array_is_list($payload)) {
            throw new ValidationException('invalid_schema');
        }
        $payloadKeys = ['input','speaker','target','audience','context','recent_action_results','ui_source'];
        if (array_key_exists('action_request', $payload)) $payloadKeys[] = 'action_request';
        $this->keys($payload, $payloadKeys);
        if (!is_array($payload['audience']) || !array_is_list($payload['audience']) || count($payload['audience']) > 12
            || !is_array($payload['recent_action_results']) || !array_is_list($payload['recent_action_results'])
            || count($payload['recent_action_results']) > 16
            || strlen(json_encode($payload['recent_action_results'], JSON_THROW_ON_ERROR)) > 65_536
            || !is_array($payload['context']) || ($payload['context'] !== [] && array_is_list($payload['context']))
            || strlen(json_encode($payload['context'], JSON_THROW_ON_ERROR)) > 131_072) {
            throw new ValidationException('invalid_schema');
        }
        if (!is_string($payload['ui_source']) || count($payload['context']) > 256) {
            throw new ValidationException('invalid_schema');
        }
        foreach ($payload['recent_action_results'] as $result) $this->embeddedActionResult($result);
        foreach ([$payload['speaker'], $payload['target'], ...$payload['audience']] as $identity) {
            $this->identity($identity);
        }
        if (array_key_exists('action_request', $payload)) {
            $action = $payload['action_request'];
            if (!is_array($action) || array_is_list($action)) throw new ValidationException('invalid_schema');
            $actionKeys = ['name','tier','parameters'];
            if (array_key_exists('target', $action)) $actionKeys[] = 'target';
            $this->keys($action, $actionKeys);
            if (!is_string($action['name']) || preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $action['name']) !== 1
                || !is_int($action['tier']) || $action['tier'] < 0 || $action['tier'] > 3
                || !is_array($action['parameters']) || ($action['parameters'] !== [] && array_is_list($action['parameters']))
                || count($action['parameters']) > 16
                || strlen(json_encode($action['parameters'], JSON_THROW_ON_ERROR)) > 16_384)
                throw new ValidationException('invalid_schema');
            if (array_key_exists('target', $action)) $this->identity($action['target']);
        }
        $input = $payload['input'];
        if (!is_array($input) || array_is_list($input)) {
            throw new ValidationException('invalid_schema');
        }
        $inputKeys = ['kind','text','language'];
        if (array_key_exists('mood', $input)) $inputKeys[] = 'mood';
        $this->keys($input, $inputKeys);
        if (!in_array($input['kind'], ['text','stt'], true) || !is_string($input['text']) || $input['text'] === ''
            || strlen($input['text']) > 16_384 || !mb_check_encoding($input['text'], 'UTF-8')
            || !is_string($input['language']) || preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $input['language']) !== 1) {
            throw new ValidationException('invalid_schema');
        }
        if (array_key_exists('mood', $input)) {
            $mood = $input['mood'];
            if (!is_array($mood) || array_is_list($mood) || !is_string($mood['kind'] ?? null)) {
                throw new ValidationException('invalid_schema');
            }
            $kinds = ['happy','sad','angry','annoyed','scared','surprised','confused','suspicious','playful','flirty','custom'];
            if (!in_array($mood['kind'], $kinds, true)) throw new ValidationException('invalid_schema');
            $moodKeys = ['kind'];
            if ($mood['kind'] === 'custom') $moodKeys[] = 'custom';
            $this->keys($mood, $moodKeys);
            if ($mood['kind'] === 'custom') {
                $custom = $mood['custom'] ?? null;
                if (!is_string($custom) || trim($custom) === '' || mb_strlen($custom, 'UTF-8') > 80
                    || !mb_check_encoding($custom, 'UTF-8') || preg_match('/[\r\n\p{Cc}]/u', $custom) === 1) {
                    throw new ValidationException('invalid_schema');
                }
            }
        }
    }

    /** Validate the standalone game-data families implemented by the runtime. */
    private function gameData(array $message): void
    {
        $this->keys($message,['schema','installation_id','playthrough_id','session_id','request_id','generation',
            'runtime_generation','observed_at','game','type','payload']);
        $type=$message['type']??null;
        if(($message['schema']??null)!=='lorkhan.gamedata.v1'||($message['game']??null)!=='tes3'
            ||!in_array($type,['actor_profile','automatic_diary','captured_dialogue','rpg_event','bored_event','quest_event','journal','inventory','spell_cast'],true)
            ||!is_int($message['generation'])||$message['generation']<1
            ||$message['generation']>9_007_199_254_740_991||!is_int($message['runtime_generation'])
            ||$message['runtime_generation']<1||$message['runtime_generation']>9_007_199_254_740_991)
            throw new ValidationException('invalid_schema');
        foreach(['installation_id','playthrough_id','session_id','request_id']as$field)$this->uuid($message[$field]??null);
        $this->timestamp($message['observed_at']??null);
        $payload=$message['payload']??null;
        if(!is_array($payload)||array_is_list($payload))throw new ValidationException('invalid_schema');
        if($type==='spell_cast'){
            $fields=['caster','spell_id','spell_name','game_time'];foreach(['target','audience']as$optional)if(array_key_exists($optional,$payload))$fields[]=$optional;
            $this->keys($payload,$fields);
            foreach(['caster','target']as$field){
                if($field==='target'&&!array_key_exists($field,$payload))continue;
                $this->identity($payload[$field]??null);
                if(!in_array($payload[$field]['kind']??null,['player','npc','creature'],true))throw new ValidationException('invalid_schema');
            }
            if(array_key_exists('audience',$payload)){
                if(!is_array($payload['audience'])||!array_is_list($payload['audience'])||count($payload['audience'])>12)throw new ValidationException('invalid_schema');
                $seen=[];foreach($payload['audience']as$witness){$this->identity($witness);
                    if(!in_array($witness['kind']??null,['player','npc','creature'],true))throw new ValidationException('invalid_schema');
                    ksort($witness);ksort($witness['cell']);ksort($witness['refnum']);$key=json_encode($witness,JSON_THROW_ON_ERROR);
                    if(isset($seen[$key]))throw new ValidationException('invalid_schema');$seen[$key]=true;}
            }
            foreach(['spell_id','spell_name']as$field)$this->boundedUtf8($payload[$field]??null,1,256);
            if((!is_int($payload['game_time']??null)&&!is_float($payload['game_time']??null))
                ||!is_finite((float)$payload['game_time'])||$payload['game_time']<0||$payload['game_time']>9_007_199_254_740_991)
                throw new ValidationException('invalid_schema');
            return;
        }
        if($type==='inventory'){
            $this->keys($payload,['owner','items']);$this->identity($payload['owner']??null);
            if(!in_array($payload['owner']['kind']??null,['npc','creature','player'],true))throw new ValidationException('invalid_schema');
            if(!is_array($payload['items']??null)||!array_is_list($payload['items'])||count($payload['items'])>512)
                throw new ValidationException('invalid_schema');
            foreach($payload['items']as$item){
                if(!is_array($item))throw new ValidationException('invalid_schema');
                $fields=['record_id','name','count','value','equipped'];
                foreach(['condition','content_file']as$optional)if(array_key_exists($optional,$item))$fields[]=$optional;
                $this->keys($item,$fields);
                foreach(['record_id','name','content_file']as$field){
                    if($field==='content_file'&&!array_key_exists($field,$item))continue;
                    if(!is_string($item[$field]??null)||$item[$field]===''||!mb_check_encoding($item[$field],'UTF-8')
                        ||mb_strlen($item[$field],'UTF-8')>256)throw new ValidationException('invalid_schema');
                }
                if(!is_int($item['count']??null)||$item['count']<1||$item['count']>2147483647
                    ||!is_int($item['value']??null)||$item['value']<0||$item['value']>2147483647
                    ||!is_bool($item['equipped']??null))throw new ValidationException('invalid_schema');
                if(array_key_exists('condition',$item)&&((!is_int($item['condition'])&&!is_float($item['condition']))
                    ||!is_finite((float)$item['condition'])||$item['condition']<0||$item['condition']>1))
                    throw new ValidationException('invalid_schema');
            }
            return;
        }
        if($type==='journal'){
            $this->keys($payload,['entries']);
            if(!is_array($payload['entries']??null)||!array_is_list($payload['entries'])||count($payload['entries'])>128)
                throw new ValidationException('invalid_schema');
            $seen=[];foreach($payload['entries']as$entry){
                if(!is_array($entry))throw new ValidationException('invalid_schema');
                $this->keys($entry,['journal_id','title','status','stage','text']);
                foreach(['journal_id'=>256,'title'=>512,'text'=>4096]as$field=>$limit){
                    if(!is_string($entry[$field]??null)||$entry[$field]===''||!mb_check_encoding($entry[$field],'UTF-8')
                        ||mb_strlen($entry[$field],'UTF-8')>$limit)throw new ValidationException('invalid_schema');
                }
                if(!is_int($entry['stage']??null)||$entry['stage']<0||$entry['stage']>2147483647
                    ||!in_array($entry['status']??null,['active','completed','failed','mentioned'],true))throw new ValidationException('invalid_schema');
                ksort($entry);$key=json_encode($entry,JSON_THROW_ON_ERROR);
                if(isset($seen[$key]))throw new ValidationException('invalid_schema');$seen[$key]=true;
            }
            return;
        }
        if($type==='bored_event'||$type==='quest_event'){
            $this->keys($payload,$type==='quest_event'?['responder','game_time','text']:['responder','game_time']);
            if($type==='quest_event'&&(!is_string($payload['text']??null)||trim($payload['text'])===''
                ||!mb_check_encoding($payload['text'],'UTF-8')||mb_strlen($payload['text'],'UTF-8')>8192))throw new ValidationException('invalid_schema');$this->identity($payload['responder']??null);
            if(($payload['responder']['kind']??null)!=='npc'
                ||(!is_int($payload['game_time']??null)&&!is_float($payload['game_time']??null))
                ||!is_finite((float)$payload['game_time'])||$payload['game_time']<0
                ||$payload['game_time']>9_007_199_254_740_991)throw new ValidationException('invalid_schema');
            return;
        }
        if($type==='rpg_event'){
            $fields=['kind','player','game_time','text'];
            if(array_key_exists('responder',$payload)){
                $fields[]='responder';$this->identity($payload['responder']);
                if(!in_array($payload['responder']['kind']??null,['npc','creature'],true))throw new ValidationException('invalid_schema');
            }
            $this->keys($payload,$fields);$this->identity($payload['player']??null);
            if(!in_array($payload['kind']??null,['levelup','combat_end','sleep','wait'],true)
                ||($payload['player']['kind']??null)!=='player'
                ||(!is_int($payload['game_time']??null)&&!is_float($payload['game_time']??null))
                ||$payload['game_time']<0||$payload['game_time']>9_007_199_254_740_991
                ||!is_string($payload['text']??null)||trim($payload['text'])===''||!mb_check_encoding($payload['text'],'UTF-8')
                ||mb_strlen($payload['text'],'UTF-8')>1024)throw new ValidationException('invalid_schema');
            return;
        }
        if($type==='automatic_diary'){
            $this->keys($payload,['trigger','game_time','actors']);
            if(!in_array($payload['trigger']??null,['timer','sleep','wait'],true)
                ||(!is_int($payload['game_time']??null)&&!is_float($payload['game_time']??null))
                ||$payload['game_time']<0||$payload['game_time']>9_007_199_254_740_991
                ||!is_array($payload['actors']??null)||!array_is_list($payload['actors'])||count($payload['actors'])>12)
                throw new ValidationException('invalid_schema');
            $seen=[];foreach($payload['actors']as$actor){$this->identity($actor);
                if(!in_array($actor['kind']??null,['npc','creature'],true))throw new ValidationException('invalid_schema');
                $key=json_encode($actor,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                if(isset($seen[$key]))throw new ValidationException('invalid_schema');$seen[$key]=true;}
            return;
        }
        if($type==='actor_profile'){
            $this->keys($payload,['actor','race','class','gender','level','disposition','factions']);
            $this->identity($payload['actor']??null);
            if(!in_array($payload['actor']['kind']??null,['creature','npc'],true)||!is_string($payload['race']??null)||$payload['race']===''
                ||!mb_check_encoding($payload['race'],'UTF-8')||mb_strlen($payload['race'],'UTF-8')>128
                ||!is_string($payload['class']??null)||!mb_check_encoding($payload['class'],'UTF-8')
                ||mb_strlen($payload['class'],'UTF-8')>128
                ||!in_array($payload['gender']??null,['female','male','none','unknown'],true)
                ||!is_int($payload['level']??null)||$payload['level']<1||$payload['level']>255
                ||!is_int($payload['disposition']??null)||$payload['disposition']<0||$payload['disposition']>100
                ||!is_array($payload['factions']??null)||!array_is_list($payload['factions'])
                ||count($payload['factions'])>32)throw new ValidationException('invalid_schema');
            $seen=[];foreach($payload['factions']as$faction){
                if(!is_string($faction)||$faction===''||!mb_check_encoding($faction,'UTF-8')
                    ||mb_strlen($faction,'UTF-8')>256||isset($seen[$faction]))throw new ValidationException('invalid_schema');
                $seen[$faction]=true;
            }
            return;
        }
        $payloadKeys=['source','speaker','listener','audience','text','topic'];
        if(array_key_exists('game_time',$payload))$payloadKeys[]='game_time';
        $this->keys($payload,$payloadKeys);
        if(!in_array($payload['source']??null,['background','menu'],true)
            ||!is_string($payload['text']??null)||$payload['text']===''||!mb_check_encoding($payload['text'],'UTF-8')
            ||mb_strlen($payload['text'],'UTF-8')>4096||!is_string($payload['topic']??null)
            ||!mb_check_encoding($payload['topic'],'UTF-8')||mb_strlen($payload['topic'],'UTF-8')>256
            ||!is_array($payload['audience']??null)||!array_is_list($payload['audience'])||count($payload['audience'])>12
            ||(array_key_exists('game_time',$payload)&&(!is_int($payload['game_time'])&&!is_float($payload['game_time'])
                ||$payload['game_time']<0||$payload['game_time']>9_007_199_254_740_991)))
            throw new ValidationException('invalid_schema');
        $this->identity($payload['speaker']??null);$this->identity($payload['listener']??null);
        $seen=[];foreach($payload['audience']as$actor){$this->identity($actor);
            $key=json_encode($actor,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if(isset($seen[$key]))throw new ValidationException('invalid_schema');$seen[$key]=true;}
    }

    /** @param array<string, mixed> $message */
    private function interrupt(array $message): void
    {
        $this->keys($message, ['schema','message_id','request_id','turn_id','session_id','generation','created_at','reason']);
        if ($message['schema'] !== 'lorkhan.interrupt.v1' || !is_string($message['reason']) || $message['reason'] === ''
            || strlen($message['reason']) > 128 || !is_int($message['generation']) || $message['generation'] < 0 || $message['generation'] > 9_007_199_254_740_991) {
            throw new ValidationException('invalid_schema');
        }
        foreach (['message_id','request_id','turn_id','session_id'] as $field) {
            $this->uuid($message[$field] ?? null);
        }
        $this->timestamp($message['created_at'] ?? null);
    }

    /** @param array<string, mixed> $message */
    private function actionResult(array $message): void
    {
        $this->keys($message, ['schema','message_id','request_id','action_id','turn_id','session_id','generation','status','reason_code','observed','completed_at']);
        if ($message['schema'] !== 'lorkhan.action-result.v1'
            || !is_int($message['generation']) || $message['generation'] < 0 || $message['generation'] > 9_007_199_254_740_991
            || !in_array($message['status'], ['cancelled','failed','rejected','succeeded','timed_out'], true)
            || !is_string($message['reason_code']) || $message['reason_code'] === '' || strlen($message['reason_code']) > 128
            || !is_array($message['observed']) || ($message['observed'] !== [] && array_is_list($message['observed']))
            || count($message['observed']) > 64 || strlen(json_encode($message['observed'], JSON_THROW_ON_ERROR)) > 16_384) {
            throw new ValidationException('invalid_schema');
        }
        foreach (['message_id','request_id','action_id','turn_id','session_id'] as $field) {
            $this->uuid($message[$field]);
        }
        $this->timestamp($message['completed_at']);
    }

    private function stt(array $message): void
    {
        $this->keys($message,['schema','message_id','request_id','turn_id','session_id','generation','created_at','codec','language','audio_bytes','sha256']);
        if($message['schema']!=='lorkhan.stt.request.v1'||$message['codec']!=='wav'||!is_int($message['audio_bytes'])||$message['audio_bytes']<1||$message['audio_bytes']>16777216||!is_string($message['sha256'])||preg_match('/^[0-9a-f]{64}$/D',$message['sha256'])!==1||!is_string($message['language'])||preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/D',$message['language'])!==1||!is_int($message['generation'])||$message['generation']<0)throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','turn_id','session_id']as$field)$this->uuid($message[$field]);$this->timestamp($message['created_at']);
    }

    private function delivery(array $message): void
    {
        $this->keys($message,['schema','message_id','request_id','dialogue_message_id','turn_id','session_id','generation','speaker','status','reason_code','completed_at']);
        if($message['schema']!=='lorkhan.dialogue-delivery-result.v1'||!in_array($message['status'],['expired','failed','interrupted','played'],true)||!is_string($message['reason_code'])||preg_match('/^[a-z][a-z0-9_]{0,127}$/D',$message['reason_code'])!==1||!is_int($message['generation'])||$message['generation']<0)throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','dialogue_message_id','turn_id','session_id']as$field)$this->uuid($message[$field]);$this->identity($message['speaker']);$this->timestamp($message['completed_at']);
    }

    /** @param array<string, mixed> $message */
    private function menuDialogueTts(array $message): void
    {
        $this->keys($message,['schema','message_id','request_id','session_id','generation','created_at','actor','text']);
        if($message['schema']!=='lorkhan.menu-dialogue-tts.v1'||!is_int($message['generation'])||$message['generation']<0
            ||!is_string($message['text'])||$message['text']===''||strlen($message['text'])>16_384
            ||mb_strlen($message['text'],'UTF-8')>4096||!mb_check_encoding($message['text'],'UTF-8'))
            throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','session_id']as$field)$this->uuid($message[$field]);
        $this->timestamp($message['created_at']);$this->identity($message['actor']);
    }

    /** Accept one book chunk; the server owns the Narrator identity and voice route. */
    private function bookReadAloud(array $message): void
    {
        $this->keys($message,['schema','message_id','request_id','session_id','generation','created_at','book_id','title','text']);
        if($message['schema']!=='lorkhan.book.read-aloud.v1'||!is_int($message['generation'])||$message['generation']<0
            ||$message['generation']>9_007_199_254_740_991)throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','session_id']as$field)$this->uuid($message[$field]);
        $this->timestamp($message['created_at']);
        foreach(['book_id'=>512,'title'=>512,'text'=>4096]as$field=>$max){
            $value=$message[$field];if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||str_contains($value,"\0")
                ||mb_strlen($value,'UTF-8')>$max||($field!=='title'&&trim($value)===''))throw new ValidationException('invalid_schema');
        }
    }

    /** @param array<string, mixed> $message */
    private function playerAutochat(array $message): void
    {
        $this->keys($message,['schema','message_id','request_id','session_id','generation','created_at','player','target','intent']);
        if($message['schema']!=='lorkhan.player-autochat.v1'||!is_int($message['generation'])||$message['generation']<0
            ||!is_string($message['intent'])||trim($message['intent'])===''||strlen($message['intent'])>16_384
            ||mb_strlen($message['intent'],'UTF-8')>4096||!mb_check_encoding($message['intent'],'UTF-8'))
            throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','session_id']as$field)$this->uuid($message[$field]);
        $this->timestamp($message['created_at']);$this->identity($message['player']);$this->identity($message['target']);
        if(($message['player']['kind']??null)!=='player'||($message['target']['kind']??null)==='player')
            throw new ValidationException('invalid_schema');
    }

    private function controlsQuery(array $message): void
    {
        $keys=['schema','message_id','request_id','session_id','generation','target'];
        if(array_key_exists('include_settings_editor',$message)){if(!is_bool($message['include_settings_editor']))throw new ValidationException('invalid_schema');$keys[]='include_settings_editor';}
        $this->keys($message,$keys);
        if(($message['schema']??null)!=='lorkhan.controls.query.v1'||!is_int($message['generation'])
            ||$message['generation']<0||$message['generation']>9_007_199_254_740_991)throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','session_id']as$field)$this->uuid($message[$field]??null);
        $this->identity($message['target']??null);
    }

    private function controlsSelect(array $message): void
    {
        $keys=['schema','message_id','request_id','session_id','generation','created_at','kind','selection_id','selection_key','target'];
        if(array_key_exists('include_settings_editor',$message)){if(!is_bool($message['include_settings_editor']))throw new ValidationException('invalid_schema');$keys[]='include_settings_editor';}
        if(($message['kind']??null)==='setting'){
            $keys[]='setting';$setting=$message['setting']??null;
            if(!is_array($setting)||array_is_list($setting)||($message['selection_id']??null)!==null)throw new ValidationException('invalid_schema');
            $this->keys($setting,['scope','key','value','change_token']);
            if(!in_array($setting['scope'],['global','core_profile','npc'],true)
                ||!is_string($setting['key'])||preg_match('/^[a-z0-9_.]{1,128}$/D',$setting['key'])!==1
                ||!is_string($setting['value'])||!mb_check_encoding($setting['value'],'UTF-8')||mb_strlen($setting['value'],'UTF-8')>512||str_contains($setting['value'],"\0")
                ||!is_string($setting['change_token'])||preg_match('/^[0-9a-f]{64}$/D',$setting['change_token'])!==1)throw new ValidationException('invalid_schema');
        }
        $this->keys($message,$keys);
        $modelSlot=($message['kind']??null)==='model_slot';
        if(($message['schema']??null)!=='lorkhan.controls.select.v1'||!is_int($message['generation'])
            ||$message['generation']<0||$message['generation']>9_007_199_254_740_991
            ||!in_array($message['kind']??null,['actor_profile','model_slot','profile_generate','narrator_profile_generate','setting'],true)
            ||($modelSlot&&(($message['selection_id']??null)!==null
                ||!in_array($message['selection_key']??null,['standard','fast','powerful','experimental'],true)))
            ||(!$modelSlot&&($message['selection_key']??null)!==null)
            ||(in_array($message['kind']??null,['profile_generate','narrator_profile_generate'],true)&&($message['selection_id']??null)===null))throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','session_id']as$field)$this->uuid($message[$field]??null);
        if(($message['selection_id']??null)!==null)$this->uuid($message['selection_id']);
        $this->identity($message['target']??null);$this->timestamp($message['created_at']??null);
    }

    private function debugCommandQuery(array $message): void
    {
        $this->keys($message,['schema','message_id','request_id','session_id','generation']);
        if(($message['schema']??null)!=='lorkhan.debug-command.query.v1'||!is_int($message['generation'])
            ||$message['generation']<0||$message['generation']>9_007_199_254_740_991)
            throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','session_id']as$field)$this->uuid($message[$field]??null);
    }

    private function debugCommandResult(array $message): void
    {
        $this->keys($message,['schema','message_id','request_id','command_id','session_id','generation','status',
            'reason_code','observed','completed_at']);
        if(($message['schema']??null)!=='lorkhan.debug-command-result.v1'||!is_int($message['generation'])
            ||$message['generation']<0||$message['generation']>9_007_199_254_740_991
            ||!in_array($message['status']??null,['succeeded','failed','rejected'],true)
            ||!is_string($message['reason_code']??null)
            ||preg_match('/^[a-z][a-z0-9_]{0,127}$/D',$message['reason_code'])!==1)
            throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','command_id','session_id']as$field)$this->uuid($message[$field]??null);
        $this->timestamp($message['completed_at']??null);
        $observed=$message['observed']??null;
        if(!is_array($observed)||($observed!==[]&&array_is_list($observed))||count($observed)>8)
            throw new ValidationException('invalid_schema');
        $boolean=['ai_enabled','collision_enabled','god_mode','mwscript_enabled','shader_hot_reload_enabled',
            'shaders_reload_requested','actor_available','return_available'];
        $integer=['count'=>[0,1_000_000_000],'level'=>[1,1_000],'bounty'=>[0,1_000_000_000]];
        $number=['base'=>[0,1_000_000],'current'=>[0,1_000_000],'health'=>[0,1_000_000],
            'magicka'=>[0,1_000_000],'fatigue'=>[0,1_000_000],'scale'=>[0.01,100],
            'timescale'=>[0,10_000],'hours_advanced'=>[0,8_760],
            'x'=>[-100_000_000,100_000_000],'y'=>[-100_000_000,100_000_000],
            'z'=>[-100_000_000,100_000_000]];
        $string=['error'=>256,'record_id'=>256,'operation'=>64,'stat'=>16,'attribute'=>32,'skill'=>32,
            'cell'=>300,'return_cell'=>300,'region_id'=>128,'weather'=>32,'target'=>256,'render_mode_toggled'=>32];
        foreach($observed as$key=>$value){
            if(in_array($key,$boolean,true)){if(!is_bool($value))throw new ValidationException('invalid_schema');continue;}
            if(isset($integer[$key])){[$minimum,$maximum]=$integer[$key];
                if(!is_int($value)||$value<$minimum||$value>$maximum)throw new ValidationException('invalid_schema');continue;}
            if(isset($number[$key])){[$minimum,$maximum]=$number[$key];
                if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)
                    ||$value<$minimum||$value>$maximum)throw new ValidationException('invalid_schema');continue;}
            if(isset($string[$key])){if(!is_string($value)||!mb_check_encoding($value,'UTF-8')
                ||mb_strlen($value,'UTF-8')>$string[$key])throw new ValidationException('invalid_schema');
                if($key==='render_mode_toggled'&&!in_array($value,
                    ['collision','wireframe','pathgrid','water','scene','navmesh','actors_paths','recast_mesh'],true))
                    throw new ValidationException('invalid_schema');
                continue;}
            throw new ValidationException('invalid_schema');
        }
    }

    private function embeddedActionResult(mixed $result): void
    {
        if (!is_array($result) || array_is_list($result)) throw new ValidationException('invalid_schema');
        $this->keys($result, ['action_id','status','reason_code','observed','completed_at']);
        if (!in_array($result['status'], ['cancelled','failed','rejected','succeeded','timed_out'], true)
            || !is_string($result['reason_code']) || $result['reason_code'] === '' || strlen($result['reason_code']) > 128
            || !is_array($result['observed']) || ($result['observed'] !== [] && array_is_list($result['observed']))
            || count($result['observed']) > 64) throw new ValidationException('invalid_schema');
        $this->uuid($result['action_id']);
        $this->timestamp($result['completed_at']);
    }

    /** @param array<string, mixed> $message @param list<string> $uuidFields */
    private function common(array $message, string $schema, array $uuidFields): void
    {
        if (($message['schema'] ?? null) !== $schema || !is_int($message['generation']) || $message['generation'] < 1 || $message['generation'] > 9_007_199_254_740_991
            || !is_string($message['content_fingerprint']) || !preg_match(self::FINGERPRINT, $message['content_fingerprint'])) {
            throw new ValidationException('invalid_schema');
        }
        foreach ($uuidFields as $field) {
            $this->uuid($message[$field] ?? null);
        }
        $this->timestamp($message['created_at'] ?? null);
        $this->runtime($message['runtime'] ?? null);
    }

    /** Validate the server-owned canonical response before it is persisted or projected. */
    private function response(array $message): void
    {
        $this->keys($message,['schema','response_id','installation_id','profile_id','playthrough_id','session_id',
            'turn_id','request_id','generation','runtime_generation','created_at','ok','lines','close','error']);
        if (($message['schema']??null)!=='lorkhan.response.v1') throw new ValidationException('invalid_schema');
        foreach(['response_id','installation_id','profile_id','playthrough_id','session_id','turn_id','request_id'] as $field) {
            $this->uuid($message[$field]??null);
        }
        if(!is_int($message['generation'])||$message['generation']<1||$message['generation']>9_007_199_254_740_991
            ||!is_int($message['runtime_generation'])||$message['runtime_generation']<1
            ||$message['runtime_generation']>9_007_199_254_740_991||!is_bool($message['ok'])||!is_bool($message['close'])
            ||!is_string($message['error'])||!mb_check_encoding($message['error'],'UTF-8')||mb_strlen($message['error'],'UTF-8')>256
            ||!is_array($message['lines'])||!array_is_list($message['lines'])||count($message['lines'])>64) {
            throw new ValidationException('invalid_schema');
        }
        $this->timestamp($message['created_at']);
        $lineIds=[];
        foreach($message['lines'] as $line){$this->responseLine($line);$lineId=$line['line_id'];
            if(isset($lineIds[$lineId]))throw new ValidationException('invalid_schema');$lineIds[$lineId]=true;}
    }

    private function responseLine(mixed $line): void
    {
        if(!is_array($line)||array_is_list($line))throw new ValidationException('invalid_schema');
        $keys=['schema','line_id','line_index','speaker','display_name','speaker_identity','action','text','subtitle','tts_text',
            'request_id','utterance_id','listener','listener_identity','rechat_target','rechat_target_identity','final_response_line','metadata'];
        foreach(['command_args','command_name','media','tts_cache_key'] as $optional)if(array_key_exists($optional,$line))$keys[]=$optional;
        $this->keys($line,$keys);
        if(($line['schema']??null)!=='lorkhan.response.line.v1'||!is_int($line['line_index'])||$line['line_index']<0||$line['line_index']>63
            ||!in_array($line['action']??null,['say','rolecommand'],true)||!is_bool($line['final_response_line']??null)) {
            throw new ValidationException('invalid_schema');
        }
        foreach(['line_id','request_id','utterance_id'] as $field)$this->uuid($line[$field]??null);
        foreach(['speaker','display_name','listener','rechat_target'] as $field)$this->boundedUtf8($line[$field]??null,1,256);
        foreach(['text','subtitle','tts_text'] as $field)$this->boundedUtf8($line[$field]??null,$line['action']==='say'?1:0,4096);
        foreach(['speaker_identity','listener_identity','rechat_target_identity'] as $field)$this->identity($line[$field]??null);
        if(array_key_exists('tts_cache_key',$line)
            &&(!is_string($line['tts_cache_key'])||preg_match('/^[A-Za-z0-9._:-]{1,256}$/D',$line['tts_cache_key'])!==1)) {
            throw new ValidationException('invalid_schema');
        }
        if(array_key_exists('media',$line))$this->mediaDescriptor($line['media']);
        if(!is_array($line['metadata'])||($line['metadata']!==[]&&array_is_list($line['metadata']))||count($line['metadata'])>6
            ||array_diff(array_keys($line['metadata']),['animation','emotion','mood','rechat_depth','speech_enabled','source'])) {
            throw new ValidationException('invalid_schema');
        }
        foreach(['animation','emotion','mood','source'] as $field)if(array_key_exists($field,$line['metadata']))$this->boundedUtf8($line['metadata'][$field],0,64);
        if(array_key_exists('rechat_depth',$line['metadata'])&&(!is_int($line['metadata']['rechat_depth'])||$line['metadata']['rechat_depth']<0||$line['metadata']['rechat_depth']>20))throw new ValidationException('invalid_schema');
        if(array_key_exists('speech_enabled',$line['metadata'])&&!is_bool($line['metadata']['speech_enabled']))throw new ValidationException('invalid_schema');
        if($line['action']==='rolecommand'){
            if(!is_string($line['command_name']??null)||preg_match('/^[a-z][a-z0-9_.]{0,63}$/D',$line['command_name'])!==1
                ||!is_array($line['command_args']??null)||!array_is_list($line['command_args'])||count($line['command_args'])>16)throw new ValidationException('invalid_schema');
            foreach($line['command_args'] as $argument)$this->boundedUtf8($argument,0,512);
        }
    }

    private function boundedUtf8(mixed $value,int $minimum,int $maximum):void
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))throw new ValidationException('invalid_schema');
        $length=mb_strlen($value,'UTF-8');if($length<$minimum||$length>$maximum)throw new ValidationException('invalid_schema');
    }

    private function mediaDescriptor(mixed $media):void
    {
        if(!is_array($media)||array_is_list($media))throw new ValidationException('invalid_schema');
        $this->keys($media,['media_id','dialogue_message_id','sha256','bytes','codec','duration_ms','expires_at']);
        $this->uuid($media['media_id']??null);$this->uuid($media['dialogue_message_id']??null);
        if(!is_string($media['sha256']??null)||preg_match('/^[0-9a-f]{64}$/D',$media['sha256'])!==1
            ||!is_int($media['bytes'])||$media['bytes']<1||$media['bytes']>33_554_432
            ||!in_array($media['codec']??null,['mp3','ogg','wav'],true)
            ||!is_int($media['duration_ms'])||$media['duration_ms']<1)throw new ValidationException('invalid_schema');
        $this->timestamp($media['expires_at']??null);
    }

    private function runtime(mixed $runtime): void
    {
        if (!is_array($runtime) || ($runtime !== [] && array_is_list($runtime))) {
            throw new ValidationException('invalid_schema');
        }
        $this->keys($runtime, ['game','variant','openmw_version','openmw_commit','lua_api_revision','client_version','platform','capabilities']);
        if ($runtime['game'] !== 'tes3' || $runtime['variant'] !== 'openmw' || $runtime['openmw_version'] !== '0.51.0'
            || $runtime['openmw_commit'] !== 'f4bec41444214a7903bebd178389ca22ca13f646' || $runtime['lua_api_revision'] !== 129
            || !is_string($runtime['client_version']) || $runtime['client_version'] === '' || strlen($runtime['client_version']) > 64
            || !is_string($runtime['platform']) || $runtime['platform'] === '' || strlen($runtime['platform']) > 64
            || !is_array($runtime['capabilities']) || !array_is_list($runtime['capabilities']) || count($runtime['capabilities']) > 64) {
            throw new ValidationException('invalid_schema');
        }
        foreach ($runtime['capabilities'] as $capability) {
            if (!is_string($capability)) {
                throw new ValidationException('invalid_schema');
            }
        }
    }

    private function identity(mixed $identity): void
    {
        if (!is_array($identity) || ($identity !== [] && array_is_list($identity))) {
            throw new ValidationException('invalid_schema');
        }
        $this->keys($identity, ['kind','record_id','refnum','content_file','cell','display_name']);
        foreach (['kind','record_id','content_file','display_name'] as $field) {
            if (!is_string($identity[$field]) || $identity[$field] === '' || strlen($identity[$field]) > 256
                || !mb_check_encoding($identity[$field], 'UTF-8')) {
                throw new ValidationException('invalid_schema');
            }
        }
        $cell = $identity['cell'];
        if (!is_array($cell) || array_is_list($cell) || !isset($cell['kind']) || !is_string($cell['kind'])
            || !in_array($cell['kind'], ['interior', 'exterior'], true)) {
            throw new ValidationException('invalid_schema');
        }
        if ($cell['kind'] === 'interior') {
            $this->keys($cell, ['kind', 'name']);
            if (!is_string($cell['name']) || $cell['name'] === '' || strlen($cell['name']) > 256) {
                throw new ValidationException('invalid_schema');
            }
        } else {
            $this->keys($cell, ['kind', 'grid_x', 'grid_y']);
            if (!is_int($cell['grid_x']) || !is_int($cell['grid_y'])) {
                throw new ValidationException('invalid_schema');
            }
        }
        $refnum = $identity['refnum'];
        if (!is_array($refnum) || array_is_list($refnum)) {
            throw new ValidationException('invalid_schema');
        }
        $this->keys($refnum, ['index','content_file']);
        if (!is_int($refnum['index']) || !is_int($refnum['content_file'])) {
            throw new ValidationException('invalid_schema');
        }
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private function keys(array $value, array $allowed): void
    {
        $keys = array_keys($value);
        sort($keys);
        $expected = $allowed;
        sort($expected);
        if ($keys !== $expected) {
            throw new ValidationException('invalid_schema');
        }
    }

    private function uuid(mixed $value): void
    {
        if (!is_string($value) || !preg_match(self::UUID, $value)) {
            throw new ValidationException('invalid_schema');
        }
    }

    private function timestamp(mixed $value): void
    {
        if (!is_string($value) || !preg_match(self::TIMESTAMP, $value)) {
            throw new ValidationException('invalid_schema');
        }
        $base = preg_replace('/\.([0-9]{1,9})Z$/', 'Z', $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', (string) $base, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ValidationException('invalid_schema');
        }
    }
}
