<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Security\OutboundUrlPolicy;
use RuntimeException;

/** OpenAI-compatible chat-completions adapter with a strict JSON response contract. */
final class OpenAiCompatibleProvider implements StreamingProvider
{
    private array $reportedUsage = [];

    /** Expose numeric provider accounting only, never prompts, keys, or the raw response. */
    public function reportedUsage(): array { return $this->reportedUsage; }
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $allowedHosts,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly int $timeoutMs = 30_000,
        private readonly bool $disableReasoning = false,
        private readonly array $options = [],
        private readonly bool $allowLoopbackHttp = false,
        private readonly bool $directConnection = false,
        private readonly bool $localNetwork = false,
        private readonly bool $player2 = false,
    ) {
        $addresses=null;
        OutboundUrlPolicy::validate($endpoint, $allowedHosts, $allowLoopbackHttp, $addresses, $localNetwork);
        LlmConnector::validateOptions($options);
        if ((!$player2 && $model === '') || strlen($model) > 256 || $timeoutMs < 1000 || $timeoutMs > 120_000) {
            throw new \InvalidArgumentException('invalid_openai_compatible_configuration');
        }
    }

    public function complete(array $turn, CancellationToken $cancellation): array
    {
        return $this->completeStreaming($turn, $cancellation, static function (string $delta): void {});
    }

    public function completeStreaming(array $turn, CancellationToken $cancellation, callable $onDialogueDelta, ?callable $diagnosticObserver = null): array
    {
        $cancellation->throwIfCancellationRequested();
        $this->reportedUsage=[];
        $messages = $this->promptMessages($turn);
        $languageEnabled=($turn['_prompt']['_llm_tts_language']??false)===true;
        $prefix = LlmConnector::prefillMessages($messages, $this->options, $languageEnabled?'language':'utterances');
        $request = LlmConnector::requestOptions($this->options,$this->directConnection?null:0.7,$this->disableReasoning,
            ($this->options['json_schema'] ?? false) ? $this->responseSchema($turn) : null) + [
            'model' => $this->model,
            'stream' => $this->options['stream'] ?? true,
            'messages' => $messages,
        ];
        // Player2 selects the model in its app, including legacy placeholder connector revisions.
        if ($this->player2) unset($request['model']);
        $body = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if(($request['stream']??false)===true&&in_array(strtolower((string)parse_url($this->endpoint,PHP_URL_HOST)),['api.openai.com','openrouter.ai'],true)){
            $request['stream_options']=['include_usage'=>true];
            $body=json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        }
        $this->emitDiagnostic($diagnosticObserver, 'request', $request);
        $networkOptions = OutboundUrlPolicy::curlOptions($this->endpoint,$this->allowedHosts,$this->allowLoopbackHttp,$this->directConnection,$this->localNetwork);
        $handle = curl_init($this->endpoint);
        if ($handle === false) throw new RuntimeException('provider_unavailable');
        $headers = LlmConnector::requestHeaders($this->apiKey,$this->player2,true);
        $networkBuffer = '';
        $responseBody = '';
        $content = '';
        $streamed = false;
        $usage=[];
        $visible = new StreamingDialogueText();
        curl_setopt_array($handle, $networkOptions + [
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
                &$networkBuffer, &$responseBody, $cancellation
            ): int {
                unset($handle);
                if ($cancellation->isCancellationRequested()) return 0;
                $responseBody .= $bytes;
                if (strlen($responseBody) > 2_097_152) return 0;
                $networkBuffer .= $bytes;
                return strlen($bytes);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static function ($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($cancellation): int {
                unset($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded);
                return $cancellation->isCancellationRequested() ? 1 : 0;
            },
        ]);
        // Run dialogue callbacks only after libcurl yields so they may start first-sentence TTS safely.
        $drainStream = static function () use (
            &$networkBuffer, &$content, &$streamed, &$usage, $visible, $onDialogueDelta, $cancellation, $languageEnabled, $prefix
        ): void {
            while (($newline = strpos($networkBuffer, "\n")) !== false) {
                if ($cancellation->isCancellationRequested()) throw new OperationCancelled('operation_cancelled');
                $line = trim(substr($networkBuffer, 0, $newline));
                $networkBuffer = substr($networkBuffer, $newline + 1);
                if (!str_starts_with($line, 'data:')) continue;
                $streamed = true;
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') continue;
                try {
                    $event = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new RuntimeException('provider_unavailable');
                }
                $delta = $event['choices'][0]['delta']['content'] ?? '';
                if(is_array($event['usage']??null))$usage=$event['usage'];
                if (!is_string($delta)) throw new RuntimeException('provider_unavailable');
                $content .= $delta;
                foreach ($visible->push($delta) as $index => $text) $onDialogueDelta($text, $languageEnabled?SpeechLanguage::fromJsonPrefix(str_starts_with(ltrim($content),'{')?$content:$prefix.$content):null, $visible->chunkMoods()[$index] ?? null, $visible->chunkTones()[$index] ?? null);
            }
        };
        $multi = curl_multi_init();
        if ($multi === false) {
            curl_close($handle);
            throw new RuntimeException('provider_unavailable');
        }
        $added = false;
        try {
            if (curl_multi_add_handle($multi, $handle) !== CURLM_OK) {
                throw new RuntimeException('provider_unavailable');
            }
            $added = true;
            $running = 0;
            do {
                do {
                    $multiStatus = curl_multi_exec($multi, $running);
                } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);
                $drainStream();
                if ($cancellation->isCancellationRequested()) throw new OperationCancelled('operation_cancelled');
                if ($multiStatus !== CURLM_OK) throw new RuntimeException('provider_unavailable');
                if ($running > 0 && curl_multi_select($multi, 0.1) === -1) usleep(1000);
            } while ($running > 0);
            $drainStream();
            $curlResult = CURLE_OK;
            while (($info = curl_multi_info_read($multi)) !== false) {
                if (($info['handle'] ?? null) === $handle) $curlResult = (int) ($info['result'] ?? CURLE_OK);
            }
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($cancellation->isCancellationRequested()) throw new OperationCancelled('operation_cancelled');
            if ($curlResult !== CURLE_OK || $status < 200 || $status >= 300 || strlen($responseBody) > 2_097_152) {
                throw new RuntimeException('provider_unavailable');
            }
        } finally {
            if ($added) curl_multi_remove_handle($multi, $handle);
            curl_multi_close($multi);
            curl_close($handle);
        }
        if (!$streamed) {
            try {
                $decoded = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('provider_invalid_output');
            }
            $content = $decoded['choices'][0]['message']['content'] ?? null;
            if(is_array($decoded['usage']??null))$usage=$decoded['usage'];
        }
        foreach(['prompt_tokens','completion_tokens','total_tokens']as$key){
            if(is_int($usage[$key]??null)&&$usage[$key]>=0&&$usage[$key]<=100_000_000)$this->reportedUsage[$key]=$usage[$key];
        }
        if(strtolower((string)parse_url($this->endpoint,PHP_URL_HOST))==='openrouter.ai'
            &&is_numeric($usage['cost']??null)&&(float)$usage['cost']>=0&&(float)$usage['cost']<=1_000_000){
            $this->reportedUsage['cost_usd']=(float)$usage['cost'];
        }
        if (!is_string($content) || $content === '') throw new RuntimeException('provider_invalid_output');
        foreach ($visible->push('', true) as $index => $text) $onDialogueDelta($text, $languageEnabled?SpeechLanguage::fromJsonPrefix(str_starts_with(ltrim($content),'{')?$content:$prefix.$content):null, $visible->chunkMoods()[$index] ?? null, $visible->chunkTones()[$index] ?? null);
        $result = $this->decodeStructuredContent($content, $prefix);
        $language=$languageEnabled?SpeechLanguage::normalize($result['language']??null):null;
        if($languageEnabled)unset($result['language']);
        $this->validateResultShape($result);
        if($language!==null)foreach($result['utterances'] as &$utterance)$utterance+=SpeechLanguage::payload($language);
        unset($utterance);
        $result = $this->normalizeAction($result, $turn);
        $this->emitDiagnostic($diagnosticObserver, 'response', $result);
        return $result;
    }

    /** Explicit test readers receive bounded body data only, with the selected key removed even if echoed. */
    private function emitDiagnostic(?callable $observer, string $stage, array $value): void
    {
        if ($observer === null) return;
        $redact = function (mixed $item, ?string $key = null) use (&$redact): mixed {
            $item = \LorkhanServer\Security\Redactor::value($item, $key);
            if (is_array($item)) {
                foreach ($item as $field=>$child) $item[$field] = $redact($child, is_string($field)?$field:null);
            } elseif (is_object($item)) {
                $item = clone $item;
                foreach (get_object_vars($item) as $field=>$child) $item->$field = $redact($child, $field);
            } elseif (is_string($item) && $this->apiKey !== '') $item = str_replace($this->apiKey, '[REDACTED]', $item);
            return $item;
        };
        $safe = $redact($value);
        if (strlen(json_encode($safe, JSON_THROW_ON_ERROR)) > 131072) $safe = ['truncated'=>true];
        $observer($stage, $safe);
    }

    /** Decode the strict response while tolerating one common one-item transport wrapper. */
    private function decodeStructuredContent(string $content, string $prefix = ''): array
    {
        $content = ReasoningOutputCleaner::clean($content, ($this->options['reasoning_model'] ?? false) === true);
        try {
            $result = LlmConnector::decodeResponse($content, $prefix);
        } catch (\JsonException) {
            throw new RuntimeException('provider_invalid_output');
        }
        if (is_array($result) && array_is_list($result) && count($result) === 1
            && is_array($result[0]) && !array_is_list($result[0])) {
            $result = $result[0];
        }
        if (!is_array($result) || array_is_list($result)) throw new RuntimeException('provider_invalid_output');
        return $result;
    }

    /** Expose only negotiated actions; the normal action validator remains authoritative. */
    private function responseSchema(array $turn): array
    {
        $actions = [['type'=>'null']];
        $narrator=ExecutionModePolicy::mode($turn['payload']??[])==='narrator';
        $executors=$narrator?($turn['_narrator_action_executors']??[]):[''=>['definitions'=>$turn['_allowed_action_definitions']??[]]];
        foreach($executors as $selector=>$executor){
        $physicalPayload=$turn['payload']??[];
        if($narrator)$physicalPayload['target']=$executor['actor'];
        foreach ($executor['definitions'] as $definition) {
            $parameters = $definition['parameter_schema'];
            $candidateGroup=match($definition['name']){'item.create'=>'items','actor.spawn'=>'actors','player.teleport'=>'destinations',default=>null};
            if($candidateGroup!==null){
                $candidateKey=$candidateGroup==='destinations'?'destination_id':'record_id';
                $parameters['properties'][$candidateKey]['enum']=array_column($turn['payload']['context']['advanced_actions'][$candidateGroup]??[],$candidateKey);
            }
            $parameters['properties'] = (object)($parameters['properties'] ?? []);
            $parameters['required'] ??= [];
            $actorProperty=$narrator?['actor_id'=>['type'=>'string','enum'=>[$selector]]]:[];
            $actions[] = LlmConnector::objectSchema($actorProperty+[
                'name'=>['type'=>'string', 'enum'=>[$definition['name']]],
                'parameters'=>$parameters,
            ]);
            if (in_array($definition['name'],array_merge(['item.give','gold.give','spell.cast'],\LorkhanServer\Application\AdvancedActionPolicy::NAMES),true)) {
                $recipients=in_array($definition['name'],\LorkhanServer\Application\AdvancedActionPolicy::NAMES,true)
                    ? \LorkhanServer\Application\AdvancedActionPolicy::recipients($turn['payload']??[])
                    : ObservedActionActors::recipients($physicalPayload,$definition['name']==='spell.cast');
                if ($recipients!==[]) $actions[]=LlmConnector::objectSchema($actorProperty+[
                    'name'=>['type'=>'string','enum'=>[$definition['name']]],
                    'parameters'=>$parameters,
                    'recipient_id'=>['type'=>'string','enum'=>array_keys($recipients)],
                ]);
            }
        }
        }
        $properties = ($turn['_prompt']['_llm_tts_language']??false)===true ? ['language'=>['type'=>'string','enum'=>SpeechLanguage::CODES]] : [];
        $textSchema=['type'=>'string','minLength'=>1,'maxLength'=>4096];
        $moodSchema=['type'=>'string','maxLength'=>64,'pattern'=>'^[a-zA-Z -]*$'];
        $toneSchema=LlmConnector::objectSchema(array_fill_keys(ZonosGradioSpeechProvider::TONES,['type'=>'number','minimum'=>0,'maximum'=>1]));
        return LlmConnector::objectSchema($properties + [
            'utterances'=>['type'=>'array', 'minItems'=>1, 'maxItems'=>4,
                'items'=>['anyOf'=>[LlmConnector::objectSchema(['text'=>$textSchema]), LlmConnector::objectSchema(['mood'=>$moodSchema,'text'=>$textSchema]),
                    LlmConnector::objectSchema(['tones'=>$toneSchema,'text'=>$textSchema]), LlmConnector::objectSchema(['tones'=>$toneSchema,'mood'=>$moodSchema,'text'=>$textSchema])]]],
            'action'=>count($actions) === 1 ? $actions[0] : ['anyOf'=>$actions],
        ]);
    }

    /** Enforce the typed utterance envelope before a provider attempt can be marked successful. */
    private function validateResultShape(array $result): void
    {
        $keys = array_keys($result);
        sort($keys);
        if ($keys !== ['action', 'utterances']) throw new RuntimeException('provider_invalid_output');
        $utterances = $result['utterances'];
        if (!is_array($utterances) || !array_is_list($utterances)
            || $utterances === [] || count($utterances) > 4) {
            throw new RuntimeException('provider_invalid_output');
        }
        $totalBytes = 0;
        foreach ($utterances as $utterance) {
            if (!is_array($utterance) || array_is_list($utterance) || !in_array(array_keys($utterance), [['text'],['mood','text'],['tones','text'],['tones','mood','text']], true)) {
                throw new RuntimeException('provider_invalid_output');
            }
            if (array_key_exists('mood',$utterance) && (!is_string($utterance['mood']) || !preg_match('/^[a-zA-Z -]{0,64}$/D',$utterance['mood']))) throw new RuntimeException('provider_invalid_output');
            if (array_key_exists('tones',$utterance)) ZonosGradioSpeechProvider::validateTones($utterance['tones']);
            $text = $utterance['text'];
            if (!is_string($text) || trim($text) === '' || !mb_check_encoding($text, 'UTF-8')
                || mb_strlen($text, 'UTF-8') > 4096 || strlen($text) > 16_384) {
                throw new RuntimeException('provider_invalid_output');
            }
            $totalBytes += strlen($text);
        }
        if ($totalBytes > 32_768) throw new RuntimeException('provider_invalid_output');
    }

    /** Prefer the frozen compact Markdown messages while refreshing turn-specific action authority. */
    private function promptMessages(array $turn): array
    {
        $contract = '- **Action Contract:** ' . (new ActionPolicyValidator())->promptContract($turn);
        $messages = $turn['_prompt']['_messages'] ?? null;
        if (is_array($messages) && array_is_list($messages) && count($messages) >= 2 && count($messages) <= 64) {
            $safe = [];
            foreach ($messages as $message) {
                if (!is_array($message) || array_is_list($message)
                    || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)
                    || !is_string($message['content'] ?? null) || $message['content'] === ''
                    || strlen($message['content']) > 131_072) {
                    $safe = [];
                    break;
                }
                $safe[] = ['role' => $message['role'], 'content' => $message['content']];
            }
            if ($safe !== [] && $safe[0]['role'] === 'system' && $safe[array_key_last($safe)]['role'] === 'user') {
                if (str_contains($safe[0]['content'], '- **Action Contract:**')) {
                    $safe[0]['content'] = preg_replace_callback('/^- \*\*Action Contract:\*\*.*$/m',
                        static fn(): string => $contract, $safe[0]['content']) ?? $safe[0]['content'];
                    return $safe;
                }
                $safe[0]['content'] .= "\n\n## Negotiated Actions\n\n" . $contract;
                return $safe;
            }
        }
        $prompt = $turn['_prompt']['_assembled_prompt'] ?? null;
        if (!is_string($prompt) || $prompt === '') {
            $prompt = json_encode($turn['payload'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return [
            ['role' => 'system', 'content' => "# Roleplay Context\n\n## Output Contract\n\n"
                . "Return one JSON object with exactly two keys: utterances and action. Do not add prose outside JSON.\n\n"
                . "## Negotiated Actions\n\n" . $contract],
            ['role' => 'user', 'content' => $prompt],
        ];
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
        $narrator=ExecutionModePolicy::mode($turn['payload']??[])==='narrator';
        if ($keys === ['actor', 'name', 'parameters', 'target', 'tier']&&!$narrator) return $result;
        $executor=null;
        if($narrator){
            $selector=$action['actor_id']??null;
            if(!is_string($selector)||!isset($turn['_narrator_action_executors'][$selector])){
                $result['action']=null;return $result;
            }
            $executor=$turn['_narrator_action_executors'][$selector];
            unset($action['actor_id']);$keys=array_keys($action);sort($keys);
        }
        if (!in_array($keys,[['name','parameters'],['function','parameters'],
            ['name','parameters','recipient_id'],['function','parameters','recipient_id']],true)) {
            $result['action'] = null;
            return $result;
        }
        $name = $action['name'] ?? $action['function'] ?? null;
        // The same negotiated catalog drives tool exposure and normalization, including new actions.
        $tiers = [];
        foreach (($executor['definitions']??($turn['_allowed_action_definitions']??[])) as $definition) {
            if (is_string($definition['name'] ?? null) && is_int($definition['tier'] ?? null))
                $tiers[$definition['name']]=$definition['tier'];
        }
        $payload = $turn['payload'] ?? null;
        if($narrator&&is_array($payload))$payload['target']=$executor['actor'];
        if (!is_string($name) || !array_key_exists($name, $tiers)
            || !is_array($action['parameters'] ?? null)
            || !is_array($payload) || !is_array($payload['target'] ?? null) || !is_array($payload['speaker'] ?? null)) {
            $result['action'] = null;
            return $result;
        }
        $recipient=$payload['speaker'];
        if ((in_array($name,['item.take','item.pickup','gold.take'],true)
            || in_array($name,\LorkhanServer\Application\ServiceActionPolicy::NAMES,true))
            && ($payload['context']['player']['kind']??null)==='player') {
            $recipient=$payload['context']['player'];
        }
        if (array_key_exists('recipient_id',$action)) {
            $recipients=in_array($name,\LorkhanServer\Application\AdvancedActionPolicy::NAMES,true)
                ? \LorkhanServer\Application\AdvancedActionPolicy::recipients($turn['payload'])
                : ObservedActionActors::recipients($payload,$name==='spell.cast');
            if (!in_array($name,array_merge(['item.give','gold.give','spell.cast'],\LorkhanServer\Application\AdvancedActionPolicy::NAMES),true) || !is_string($action['recipient_id'])
                || !isset($recipients[$action['recipient_id']])) {
                $result['action']=null;
                return $result;
            }
            $recipient=$recipients[$action['recipient_id']];
        }
        $result['action'] = [
            'name' => $name,
            'tier' => $tiers[$name],
            'actor' => in_array($name,\LorkhanServer\Application\AdvancedActionPolicy::NAMES,true)?$payload['context']['player']:$payload['target'],
            'target' => $recipient,
            'parameters' => $action['parameters'],
        ];
        return $result;
    }
}
