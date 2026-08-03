<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Security\OutboundUrlPolicy;
use RuntimeException;

/** OpenAI-compatible chat-completions adapter with a strict JSON response contract. */
final class OpenAiCompatibleProvider implements StreamingProvider
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $allowedHosts,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly int $timeoutMs = 30_000,
        private readonly bool $disableReasoning = false,
    ) {
        OutboundUrlPolicy::validate($endpoint, $allowedHosts);
        if ($model === '' || strlen($model) > 200 || $timeoutMs < 1000 || $timeoutMs > 120_000) {
            throw new \InvalidArgumentException('invalid_openai_compatible_configuration');
        }
    }

    public function complete(array $turn, CancellationToken $cancellation): array
    {
        return $this->completeStreaming($turn, $cancellation, static function (string $delta): void {});
    }

    public function completeStreaming(array $turn, CancellationToken $cancellation, callable $onDialogueDelta): array
    {
        $cancellation->throwIfCancellationRequested();
        $prompt = $turn['_prompt']['_assembled_prompt'] ?? null;
        if (!is_string($prompt) || $prompt === '') {
            $prompt = json_encode($turn['payload'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $request = [
            'model' => $this->model,
            'temperature' => 0.7,
            'stream' => true,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => 'You roleplay Morrowind characters. Return one JSON object with exactly two keys: utterances and action. utterances contains one to four objects with a non-empty text key. action must be null or an object with exactly name and parameters; the server adds actor, target, and tier. Allowed actions are null; inspect.report with empty parameters; ai.follow with distance 192; ai.stop with empty parameters; ai.wander with integer distance 0..2048 and duration_seconds 1..3600; combat.start or combat.stop with empty parameters; animation.play with group idle2 through idle9; item.use with the exact record_id of an item present in the supplied inventory; item.equip with that exact record_id and one listed equipment slot; or item.unequip with one listed equipment slot. Do not add prose outside JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        if ($this->disableReasoning) {
            $request['reasoning'] = ['exclude' => true, 'enabled' => false];
        }
        $body = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $handle = curl_init(OutboundUrlPolicy::validate($this->endpoint, $this->allowedHosts));
        if ($handle === false) throw new RuntimeException('provider_unavailable');
        $headers = ['Content-Type: application/json', 'Accept: text/event-stream, application/json'];
        if ($this->apiKey !== '') $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        $networkBuffer = '';
        $responseBody = '';
        $content = '';
        $streamed = false;
        $visible = new StreamingDialogueText();
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $this->timeoutMs),
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $bytes) use (
                &$networkBuffer, &$responseBody, &$content, &$streamed, $visible, $onDialogueDelta, $cancellation
            ): int {
                unset($handle);
                if ($cancellation->isCancellationRequested()) return 0;
                $responseBody .= $bytes;
                if (strlen($responseBody) > 2_097_152) return 0;
                $networkBuffer .= $bytes;
                while (($newline = strpos($networkBuffer, "\n")) !== false) {
                    $line = trim(substr($networkBuffer, 0, $newline));
                    $networkBuffer = substr($networkBuffer, $newline + 1);
                    if (!str_starts_with($line, 'data:')) continue;
                    $streamed = true;
                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') continue;
                    try {
                        $event = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        return 0;
                    }
                    $delta = $event['choices'][0]['delta']['content'] ?? '';
                    if (!is_string($delta)) return 0;
                    $content .= $delta;
                    foreach ($visible->push($delta) as $text) $onDialogueDelta($text);
                }
                return strlen($bytes);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static function ($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($cancellation): int {
                unset($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded);
                return $cancellation->isCancellationRequested() ? 1 : 0;
            },
        ]);
        try {
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($cancellation->isCancellationRequested()) throw new OperationCancelled('operation_cancelled');
            if ($ok !== true || $status < 200 || $status >= 300 || strlen($responseBody) > 2_097_152) {
                throw new RuntimeException('provider_unavailable');
            }
        } finally {
            curl_close($handle);
        }
        if (!$streamed) {
            $decoded = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);
            $content = $decoded['choices'][0]['message']['content'] ?? null;
        }
        if (!is_string($content) || $content === '') throw new RuntimeException('provider_invalid_output');
        foreach ($visible->push('', true) as $text) $onDialogueDelta($text);
        $result = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result) || array_is_list($result)) throw new RuntimeException('provider_invalid_output');
        return $this->normalizeAction($result, $turn);
    }

    /** Add trusted turn identities and canonical tiers to a compact model-proposed action. */
    private function normalizeAction(array $result, array $turn): array
    {
        $action = $result['action'] ?? null;
        if ($action === null) {
            $result['action'] = null;
            return $result;
        }
        if (!is_array($action) || array_is_list($action)) {
            $result['action'] = null;
            return $result;
        }
        $keys = array_keys($action);
        sort($keys);
        if ($keys === ['actor', 'name', 'parameters', 'target', 'tier']) return $result;
        if ($keys !== ['name', 'parameters'] && $keys !== ['function', 'parameters']) {
            $result['action'] = null;
            return $result;
        }
        $name = $action['name'] ?? $action['function'] ?? null;
        $tiers = [
            'inspect.report' => 0,
            'ai.follow' => 1,
            'ai.stop' => 1,
            'ai.wander' => 1,
            'combat.start' => 2,
            'combat.stop' => 1,
            'animation.play' => 1,
            'item.use' => 2,
            'item.equip' => 2,
            'item.unequip' => 2,
        ];
        $payload = $turn['payload'] ?? null;
        if (!is_string($name) || !array_key_exists($name, $tiers)
            || !is_array($action['parameters'] ?? null)
            || !is_array($payload) || !is_array($payload['target'] ?? null) || !is_array($payload['speaker'] ?? null)) {
            $result['action'] = null;
            return $result;
        }
        $result['action'] = [
            'name' => $name,
            'tier' => $tiers[$name],
            'actor' => $payload['target'],
            'target' => $payload['speaker'],
            'parameters' => $action['parameters'],
        ];
        return $result;
    }
}
