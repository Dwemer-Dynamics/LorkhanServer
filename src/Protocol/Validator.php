<?php

declare(strict_types=1);

namespace ALMSIVIserver\Protocol;

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
            'almsivi.session.init.v1' => $this->session($message),
            'almsivi.turn.v1' => $this->turn($message),
            'almsivi.interrupt.v1' => $this->interrupt($message),
            'almsivi.action-result.v1' => $this->actionResult($message),
            'almsivi.stt.request.v1' => $this->stt($message),
            'almsivi.dialogue-delivery-result.v1' => $this->delivery($message),
            'almsivi.controls.query.v1' => $this->controlsQuery($message),
            'almsivi.controls.select.v1' => $this->controlsSelect($message),
            'almsivi.response.v1' => $this->response($message),
            default => throw new ValidationException('invalid_schema'),
        };
    }

    /** @param array<string, mixed> $message */
    private function session(array $message): void
    {
        $this->keys($message, ['schema','message_id','installation_id','profile_id','playthrough_id','generation','created_at','runtime','content_fingerprint']);
        $this->common($message, 'almsivi.session.init.v1', ['message_id','installation_id','profile_id','playthrough_id']);
    }

    /** @param array<string, mixed> $message */
    private function turn(array $message): void
    {
        $this->keys($message, ['schema','message_id','request_id','turn_id','installation_id','profile_id','playthrough_id','session_id','generation','runtime_generation','created_at','runtime','content_fingerprint','payload']);
        $this->common($message, 'almsivi.turn.v1', ['message_id','request_id','turn_id','installation_id','profile_id','playthrough_id','session_id']);
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

    /** @param array<string, mixed> $message */
    private function interrupt(array $message): void
    {
        $this->keys($message, ['schema','message_id','request_id','turn_id','session_id','generation','created_at','reason']);
        if ($message['schema'] !== 'almsivi.interrupt.v1' || !is_string($message['reason']) || $message['reason'] === ''
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
        if ($message['schema'] !== 'almsivi.action-result.v1'
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
        if($message['schema']!=='almsivi.stt.request.v1'||$message['codec']!=='wav'||!is_int($message['audio_bytes'])||$message['audio_bytes']<1||$message['audio_bytes']>16777216||!is_string($message['sha256'])||preg_match('/^[0-9a-f]{64}$/D',$message['sha256'])!==1||!is_string($message['language'])||preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/D',$message['language'])!==1||!is_int($message['generation'])||$message['generation']<0)throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','turn_id','session_id']as$field)$this->uuid($message[$field]);$this->timestamp($message['created_at']);
    }

    private function delivery(array $message): void
    {
        $this->keys($message,['schema','message_id','request_id','dialogue_message_id','turn_id','session_id','generation','speaker','status','reason_code','completed_at']);
        if($message['schema']!=='almsivi.dialogue-delivery-result.v1'||!in_array($message['status'],['expired','failed','interrupted','played'],true)||!is_string($message['reason_code'])||preg_match('/^[a-z][a-z0-9_]{0,127}$/D',$message['reason_code'])!==1||!is_int($message['generation'])||$message['generation']<0)throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','dialogue_message_id','turn_id','session_id']as$field)$this->uuid($message[$field]);$this->identity($message['speaker']);$this->timestamp($message['completed_at']);
    }

    private function controlsQuery(array $message): void
    {
        $this->keys($message,['schema','message_id','request_id','session_id','generation','target']);
        if(($message['schema']??null)!=='almsivi.controls.query.v1'||!is_int($message['generation'])
            ||$message['generation']<0||$message['generation']>9_007_199_254_740_991)throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','session_id']as$field)$this->uuid($message[$field]??null);
        $this->identity($message['target']??null);
    }

    private function controlsSelect(array $message): void
    {
        $this->keys($message,['schema','message_id','request_id','session_id','generation','created_at','kind','selection_id','target']);
        if(($message['schema']??null)!=='almsivi.controls.select.v1'||!is_int($message['generation'])
            ||$message['generation']<0||$message['generation']>9_007_199_254_740_991
            ||!in_array($message['kind']??null,['actor_profile','model_slot','profile_generate','narrator_profile_generate'],true)
            ||(in_array($message['kind']??null,['profile_generate','narrator_profile_generate'],true)&&($message['selection_id']??null)===null))throw new ValidationException('invalid_schema');
        foreach(['message_id','request_id','session_id']as$field)$this->uuid($message[$field]??null);
        if(($message['selection_id']??null)!==null)$this->uuid($message['selection_id']);
        $this->identity($message['target']??null);$this->timestamp($message['created_at']??null);
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
        if (($message['schema']??null)!=='almsivi.response.v1') throw new ValidationException('invalid_schema');
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
        if(($line['schema']??null)!=='almsivi.response.line.v1'||!is_int($line['line_index'])||$line['line_index']<0||$line['line_index']>63
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
