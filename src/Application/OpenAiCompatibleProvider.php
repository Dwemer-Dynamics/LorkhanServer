<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Security\OutboundUrlPolicy;
use RuntimeException;

/** OpenAI-compatible chat-completions adapter with a strict JSON response contract. */
final class OpenAiCompatibleProvider implements Provider
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $allowedHosts,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly int $timeoutMs = 30_000,
    ) {
        OutboundUrlPolicy::validate($endpoint, $allowedHosts);
        if ($model === '' || strlen($model) > 200 || $timeoutMs < 1000 || $timeoutMs > 120_000) {
            throw new \InvalidArgumentException('invalid_openai_compatible_configuration');
        }
    }

    public function complete(array $turn, CancellationToken $cancellation): array
    {
        $cancellation->throwIfCancellationRequested();
        $prompt = $turn['_prompt']['_assembled_prompt'] ?? null;
        if (!is_string($prompt) || $prompt === '') {
            $prompt = json_encode($turn['payload'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $body = json_encode([
            'model' => $this->model,
            'temperature' => 0.7,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => 'You roleplay Morrowind characters. Return one JSON object with utterances (one to four objects containing non-empty text) and optional action. Allowed actions are null; inspect.report with empty parameters; ai.follow with distance 192; ai.stop with empty parameters; ai.wander with integer distance 0..2048 and duration_seconds 1..3600; combat.start or combat.stop with empty parameters; animation.play with group idle2 through idle9; item.use with the exact record_id of an item present in the supplied inventory; item.equip with that exact record_id and one listed equipment slot; or item.unequip with one listed equipment slot. combat.start, item.use, item.equip, and item.unequip are tier 2 and require explicit player confirmation. All other actions are tier 1 except inspect.report tier 0. Do not add prose outside JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $handle = curl_init(OutboundUrlPolicy::validate($this->endpoint, $this->allowedHosts));
        if ($handle === false) throw new RuntimeException('provider_unavailable');
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->apiKey !== '') $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $this->timeoutMs),
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static function ($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($cancellation): int {
                unset($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded);
                return $cancellation->isCancellationRequested() ? 1 : 0;
            },
        ]);
        try {
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($cancellation->isCancellationRequested()) throw new OperationCancelled('operation_cancelled');
            if (!is_string($response) || $status < 200 || $status >= 300 || strlen($response) > 2_097_152) {
                throw new RuntimeException('provider_unavailable');
            }
        } finally {
            curl_close($handle);
        }
        $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') throw new RuntimeException('provider_invalid_output');
        $result = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result) || array_is_list($result)) throw new RuntimeException('provider_invalid_output');
        return $result;
    }
}
