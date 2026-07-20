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
        $this->keys($message, ['schema','message_id','request_id','turn_id','installation_id','profile_id','playthrough_id','session_id','generation','created_at','runtime','content_fingerprint','payload']);
        $this->common($message, 'almsivi.turn.v1', ['message_id','request_id','turn_id','installation_id','profile_id','playthrough_id','session_id']);
        $payload = $message['payload'] ?? null;
        if (!is_array($payload) || array_is_list($payload)) {
            throw new ValidationException('invalid_schema');
        }
        $this->keys($payload, ['input','speaker','target','audience','context','recent_action_results','ui_source']);
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
        $input = $payload['input'];
        if (!is_array($input) || array_is_list($input)) {
            throw new ValidationException('invalid_schema');
        }
        $this->keys($input, ['kind','text','language']);
        if (!in_array($input['kind'], ['text','stt'], true) || !is_string($input['text']) || $input['text'] === ''
            || strlen($input['text']) > 16_384 || !mb_check_encoding($input['text'], 'UTF-8')
            || !is_string($input['language']) || preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $input['language']) !== 1) {
            throw new ValidationException('invalid_schema');
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
        if (($message['schema'] ?? null) !== $schema || !is_int($message['generation']) || $message['generation'] < 0 || $message['generation'] > 9_007_199_254_740_991
            || !is_string($message['content_fingerprint']) || !preg_match(self::FINGERPRINT, $message['content_fingerprint'])) {
            throw new ValidationException('invalid_schema');
        }
        foreach ($uuidFields as $field) {
            $this->uuid($message[$field] ?? null);
        }
        $this->timestamp($message['created_at'] ?? null);
        $this->runtime($message['runtime'] ?? null);
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
