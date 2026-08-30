<?php

declare(strict_types=1);

namespace LORKHANserver\Application;

use LORKHANserver\Infrastructure\Uuid;
use DomainException;

/** Convert one validated provider result into the immutable LORKHAN response/line contract. */
final class CanonicalResponseNormalizer
{
    public function __construct(private readonly DialoguePlanner $planner = new DialoguePlanner()) {}

    /** @param array<string,mixed> $turn @param array<string,mixed> $providerResult @return array<string,mixed> */
    public function normalize(array $turn, array $providerResult, array $streamedLines = []): array
    {
        $runtimeGeneration = $turn['runtime_generation'] ?? null;
        if (!is_int($runtimeGeneration) || $runtimeGeneration < 1) {
            throw new DomainException('provider_invalid_output');
        }
        $utterances = $this->planner->plan($turn, $providerResult);
        $requestId = (string) ($turn['request_id'] ?? '');
        $rechat = is_array($turn['payload']['context']['rechat'] ?? null)
            ? $turn['payload']['context']['rechat'] : [];
        $rechatDepth = max(0, min(20, (int) ($rechat['rechat_depth'] ?? 0)));
        $lines = [];
        foreach ($utterances as $index => $utterance) {
            $streamed = $streamedLines[$index] ?? null;
            if ($streamed !== null && (!is_array($streamed)
                || ($streamed['text'] ?? null) !== $utterance['_history_text']
                || !Uuid::isValid((string) ($streamed['line_id'] ?? ''))
                || !Uuid::isValid((string) ($streamed['utterance_id'] ?? '')))) {
                throw new DomainException('provider_invalid_output');
            }
            $speaker = $utterance['speaker'];
            $listener = $utterance['addressee'];
            $rechatTarget = is_array($rechat['rechat_target_hint'] ?? null)
                ? $rechat['rechat_target_hint'] : $speaker;
            $text = $utterance['_history_text'];
            $lines[] = [
                'schema' => 'lorkhan.response.line.v1',
                'line_id' => $streamed['line_id'] ?? Uuid::v4(),
                'line_index' => $index,
                'speaker' => $this->displayName($speaker),
                'display_name' => $this->displayName($speaker),
                'speaker_identity' => $speaker,
                'action' => 'say',
                'text' => $text,
                'subtitle' => $utterance['_subtitle'],
                'tts_text' => $utterance['_tts_text'],
                'request_id' => $requestId,
                'utterance_id' => $streamed['utterance_id'] ?? Uuid::v4(),
                'listener' => $this->displayName($listener),
                'listener_identity' => $listener,
                'rechat_target' => $this->displayName($rechatTarget),
                'rechat_target_identity' => $rechatTarget,
                'final_response_line' => $index === array_key_last($utterances),
                'metadata' => ['rechat_depth' => $rechatDepth,
                    'speech_enabled' => ($utterance['speech_enabled'] ?? true) !== false, 'source' => 'provider'],
            ];
        }
        if (is_array($providerResult['action'] ?? null)) {
            $action = $providerResult['action'];
            $lines[] = [
                'schema' => 'lorkhan.response.line.v1',
                'line_id' => Uuid::v4(),
                'line_index' => count($lines),
                'speaker' => $this->displayName($action['actor']),
                'display_name' => $this->displayName($action['actor']),
                'speaker_identity' => $action['actor'],
                'action' => 'rolecommand',
                'text' => '',
                'subtitle' => '',
                'tts_text' => '',
                'request_id' => $requestId,
                'utterance_id' => Uuid::v4(),
                'listener' => $this->displayName($action['target']),
                'listener_identity' => $action['target'],
                'rechat_target' => $this->displayName($action['actor']),
                'rechat_target_identity' => $action['actor'],
                'command_name' => $action['name'],
                'command_args' => $this->commandArguments($action['parameters']),
                'final_response_line' => false,
                'metadata' => ['rechat_depth' => $rechatDepth, 'source' => 'provider'],
            ];
        }
        return [
            'schema' => 'lorkhan.response.v1',
            'response_id' => Uuid::v4(),
            'installation_id' => $turn['installation_id'],
            'profile_id' => $turn['profile_id'],
            'playthrough_id' => $turn['playthrough_id'],
            'session_id' => $turn['session_id'],
            'turn_id' => $turn['turn_id'],
            'request_id' => $requestId,
            'generation' => $turn['generation'],
            'runtime_generation' => $runtimeGeneration,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'ok' => true,
            'lines' => $lines,
            'close' => false,
            'error' => '',
        ];
    }

    /** Normalize a catalog-validated direct action without invoking a dialogue provider. */
    public function actionOnly(array $turn, array $action): array
    {
        $runtimeGeneration = $turn['runtime_generation'] ?? null;
        if (!is_int($runtimeGeneration) || $runtimeGeneration < 1) throw new DomainException('provider_invalid_output');
        $rechat = is_array($turn['payload']['context']['rechat'] ?? null)
            ? $turn['payload']['context']['rechat'] : [];
        $line = [
            'schema' => 'lorkhan.response.line.v1',
            'line_id' => Uuid::v4(),
            'line_index' => 0,
            'speaker' => $this->displayName($action['actor']),
            'display_name' => $this->displayName($action['actor']),
            'speaker_identity' => $action['actor'],
            'action' => 'rolecommand',
            'text' => '',
            'subtitle' => '',
            'tts_text' => '',
            'request_id' => $turn['request_id'],
            'utterance_id' => Uuid::v4(),
            'listener' => $this->displayName($action['target']),
            'listener_identity' => $action['target'],
            'rechat_target' => $this->displayName($action['actor']),
            'rechat_target_identity' => $action['actor'],
            'command_name' => $action['name'],
            'command_args' => $this->commandArguments($action['parameters']),
            'final_response_line' => false,
            'metadata' => ['rechat_depth' => max(0, min(20, (int) ($rechat['rechat_depth'] ?? 0))),
                'source' => 'direct_action'],
        ];
        return [
            'schema' => 'lorkhan.response.v1', 'response_id' => Uuid::v4(),
            'installation_id' => $turn['installation_id'], 'profile_id' => $turn['profile_id'],
            'playthrough_id' => $turn['playthrough_id'], 'session_id' => $turn['session_id'],
            'turn_id' => $turn['turn_id'], 'request_id' => $turn['request_id'],
            'generation' => $turn['generation'], 'runtime_generation' => $runtimeGeneration,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'ok' => true, 'lines' => [$line],
            'close' => false, 'error' => '',
        ];
    }

    /** Persist a strict terminal response for failures and lifecycle cancellation. */
    public function failure(array $turn, string $error, bool $close = false): array
    {
        $runtimeGeneration = $turn['runtime_generation'] ?? null;
        if (!is_int($runtimeGeneration) || $runtimeGeneration < 1
            || $error === '' || mb_strlen($error, 'UTF-8') > 256) {
            throw new DomainException('provider_invalid_output');
        }
        return [
            'schema' => 'lorkhan.response.v1', 'response_id' => Uuid::v4(),
            'installation_id' => $turn['installation_id'], 'profile_id' => $turn['profile_id'],
            'playthrough_id' => $turn['playthrough_id'], 'session_id' => $turn['session_id'],
            'turn_id' => $turn['turn_id'], 'request_id' => $turn['request_id'],
            'generation' => $turn['generation'], 'runtime_generation' => $runtimeGeneration,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'ok' => false, 'lines' => [],
            'close' => $close, 'error' => $error,
        ];
    }

    /** @param array<string,mixed> $identity */
    private function displayName(array $identity): string
    {
        $name = trim((string) ($identity['display_name'] ?? $identity['record_id'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 256) throw new DomainException('provider_invalid_identity');
        return $name;
    }

    /** @param array<string,mixed> $parameters @return list<string> */
    private function commandArguments(array $parameters): array
    {
        ksort($parameters);
        $arguments = [];
        foreach ($parameters as $name => $value) {
            if (!is_string($name) || count($arguments) >= 16) throw new DomainException('provider_invalid_action');
            $encoded = is_scalar($value) || $value === null
                ? ($value === true ? 'true' : ($value === false ? 'false' : (string) $value))
                : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $argument = $name . '=' . $encoded;
            if (strlen($argument) > 512) throw new DomainException('provider_invalid_action');
            $arguments[] = $argument;
        }
        return $arguments;
    }
}
