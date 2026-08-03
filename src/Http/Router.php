<?php
declare(strict_types=1);

namespace ALMSIVIserver\Http;

use ALMSIVIserver\Application\PromptAssembler;
use ALMSIVIserver\Application\Provider;
use ALMSIVIserver\Application\SpeechProvider;
use ALMSIVIserver\Application\SpeechToTextProvider;
use ALMSIVIserver\Infrastructure\ManagementRepository;
use ALMSIVIserver\Infrastructure\MediaStore;
use ALMSIVIserver\Infrastructure\ProductRepository;
use ALMSIVIserver\Infrastructure\ProviderAttemptRepository;
use ALMSIVIserver\Infrastructure\Repository;
use ALMSIVIserver\Infrastructure\Uuid;
use ALMSIVIserver\Protocol\ValidationException;
use ALMSIVIserver\Protocol\Validator;
use ALMSIVIserver\Security\RequestMac;
use DomainException;
use OutOfBoundsException;
use Throwable;
use UnexpectedValueException;

final class Router
{
    private ?string $authenticatedInstallation=null;
    public function __construct(
        private readonly Repository $repository,
        private readonly Validator $validator,
        private readonly Provider $provider,
        private readonly string $pairingTokenHash,
        private readonly string $basePath = '/ALMSIVIserver/api/v1',
        private readonly int $maxJsonBytes = 2_097_152,
        private readonly int $eventLimit = 100,
        private readonly int $rateLimitRequests = 120,
        private readonly int $rateLimitWindowSeconds = 60,
        private readonly ?MediaStore $mediaStore = null,
        private readonly ?SpeechProvider $speechProvider = null,
        private readonly ?ProviderAttemptRepository $providerAttempts = null,
        private readonly int $eventMaxWaitSeconds = 15,
        private readonly ?ManagementRepository $management = null,
        private readonly ?ProductRepository $products = null,
        private readonly ?PromptAssembler $promptAssembler = null,
        private readonly ?SpeechToTextProvider $sttProvider = null,
    ) {
        if (($products === null) !== ($promptAssembler === null)) throw new \InvalidArgumentException('Incomplete prompt composition.');
    }

    public function dispatch(Request $request): Response
    {
        $candidate=$request->header('X-ALMSIVI-Request-Id')??$request->header('Idempotency-Key');
        if(str_contains(strtolower((string)$request->header('Content-Type')),'application/json')){try{$raw=json_decode($request->body,true,8,JSON_THROW_ON_ERROR);if(is_array($raw)&&is_string($raw['request_id']??null))$candidate=$raw['request_id'];}catch(Throwable){}}
        $correlation=is_string($candidate)&&$this->uuid($candidate)?$candidate:Uuid::v4();
        try {
            $path = $this->path($request->path);
            if ($request->method === 'GET' && $path === '/health') {
                return Response::json(200, ['schema' => 'almsivi.health.v1']);
            }
            $this->authenticatedInstallation=null;
            $this->authenticate($request);
            if (!$this->repository->consumeRateLimit($this->pairingTokenHash . '|' . $this->rateLimitRoute($path),
                $this->rateLimitRequests, $this->rateLimitWindowSeconds)) {
                throw new ApiException(429, 'rate_limited', 'Request rate exceeded.', true, 1000);
            }
            if ($request->method === 'POST' && $path === '/sessions') return $this->createSession($request);
            if ($request->method === 'DELETE' && preg_match('#^/sessions/([0-9a-f-]{36})$#D', $path, $m)) return $this->endSession($request, $m[1]);
            if ($request->method === 'POST' && $path === '/turns') return $this->createTurn($request);
            if ($request->method === 'POST' && $path === '/controls/query') return $this->controlsQuery($request);
            if ($request->method === 'POST' && $path === '/controls/select') return $this->controlsSelect($request);
            if ($request->method === 'GET' && $path === '/events') return $this->events($request);
            if ($request->method === 'GET' && preg_match('#^/media/([0-9a-f-]{36})$#D', $path, $m)) return $this->media($m[1]);
            if ($request->method === 'POST' && $path === '/interruptions') return $this->interrupt($request);
            if ($request->method === 'POST' && $path === '/action-results') return $this->actionResult($request);
            if ($request->method === 'POST' && $path === '/stt') return $this->stt($request);
            if ($request->method === 'POST' && $path === '/dialogue-delivery-results') return $this->deliveryResult($request);
            throw new ApiException(404, 'not_found', 'Route not found.');
        } catch (ApiException $error) {
            return $this->error($error, $correlation);
        } catch (ValidationException $error) {
            return Response::error($error->getMessage() === 'payload_too_large' ? 413 : 422, $error->getMessage(), $correlation);
        } catch (DomainException $error) {
            return Response::error(409, $this->publicCode($error->getMessage()), $correlation);
        } catch (UnexpectedValueException $error) {
            return Response::error(409, $this->publicCode($error->getMessage()), $correlation);
        } catch (OutOfBoundsException $error) {
            return Response::error(404, $this->publicCode($error->getMessage()), $correlation);
        } catch (Throwable) {
            return Response::error(500, 'internal_error', $correlation, true);
        }
    }

    private function createSession(Request $request): Response
    {
        $m = $this->json($request, 'almsivi.session.init.v1');
        $this->assertPrincipal($m['installation_id']);
        $this->requireIdempotency($request, $m['message_id']);
        return $this->repository->serializedIdempotency($m['installation_id'], $m['message_id'], '/sessions', function () use ($m): Response {
            return $this->idempotent($m['installation_id'], $m['message_id'], '/sessions', $m, function () use ($m): array {
                $sessionId = Uuid::v4();
                $session = $this->repository->createSession($m,$sessionId,$this->pairingTokenHash,$this->bootstrapMacKey());
                return [201, ['schema' => 'almsivi.session.accepted.v1', 'message_id' => $m['message_id'],
                    'session_id' => $sessionId, 'generation' => $session['generation'],
                    'capabilities' => $session['capabilities'], 'config_revision' => 'independent-foundation-v1', 'event_cursor' => 0]];
            });
        });
    }

    private function endSession(Request $request, string $sessionId): Response
    {
        if (!$this->uuid($sessionId)) throw new ApiException(404, 'not_found', 'Route not found.');
        $this->assertPrincipal($this->repository->sessionInstallation($sessionId));
        $requestId = $this->requireIdempotency($request);
        [$status, $body] = $this->repository->endSession($sessionId, $requestId);
        return Response::json($status, $body);
    }

    private function createTurn(Request $request): Response
    {
        $m = $this->json($request, 'almsivi.turn.v1');
        $this->assertPrincipal($m['installation_id']);
        $this->requireIdempotency($request, $m['message_id']);
        return $this->repository->serializedIdempotency($m['installation_id'], $m['message_id'], '/turns', function () use ($m): Response {
            $hash = $this->semanticHash($m);
            $cached = $this->repository->idempotent($m['installation_id'], $m['message_id'], '/turns', $hash);
            if ($cached !== null) return Response::json($cached['status'], $cached['body']);

            $directAction = $m['payload']['action_request'] ?? null;
            $providerInput = $directAction === null ? $m : null;
            $assembled = null;
            if ($directAction === null && $this->products !== null && $this->promptAssembler !== null) {
                $selection = $this->products->promptContext($m, gmdate('Y-m-d\TH:i:s\Z'));
                $providerInput['_selected_profile_id']=$selection['selected_profile_id'];
                if(is_array($selection['player_profile']??null))$providerInput['_player_profile']=$selection['player_profile'];
                if(is_array($selection['narrator_profile']??null))$providerInput['_narrator_profile']=$selection['narrator_profile'];
                if(is_array($selection['item_descriptions']??null)&&$selection['item_descriptions']!==[])$providerInput['_item_descriptions']=$selection['item_descriptions'];
                $assembled = $this->promptAssembler->assemble($providerInput, $selection);
                $providerInput['_prompt'] = $assembled['provider_input'];
                $providerConfiguration=$this->products->providerContext($m);
                if($providerConfiguration!==null)$providerInput['_provider_configuration']=$providerConfiguration;
                $fallbackConfiguration=$this->products->fallbackProviderContext($m,$providerConfiguration['configuration_id']??null);
                if($fallbackConfiguration!==null)$providerInput['_fallback_provider_configuration']=$fallbackConfiguration;
            }
            $body = ['schema' => 'almsivi.turn.accepted.v1', 'message_id' => $m['message_id'], 'turn_id' => $m['turn_id'],
                'request_id' => $m['request_id'], 'session_id' => $m['session_id'], 'generation' => $m['generation']];
            $accepted = $this->repository->acceptTurn($m, $providerInput, $assembled['trace'] ?? null, $hash, $body, $directAction);
            $body['event_cursor']=$accepted['sequence'];
            return Response::json(202, $body);
        });
    }

    private function events(Request $request): Response
    {
        $session = $request->query['session_id'] ?? '';
        $generation = filter_var($request->query['generation'] ?? null, FILTER_VALIDATE_INT);
        $after = filter_var($request->query['after'] ?? '0', FILTER_VALIDATE_INT);
        $wait = filter_var($request->query['wait_ms'] ?? '0', FILTER_VALIDATE_INT);
        if (!$this->uuid($session) || $generation === false || $generation < 0 || $after === false || $after < 0
            || $wait === false || $wait < 0 || $wait > $this->eventMaxWaitSeconds * 1000) {
            throw new ApiException(422, 'invalid_schema', 'Invalid event cursor.');
        }
        $this->assertPrincipal($this->repository->sessionInstallation($session));
        $deadline = microtime(true) + ($wait / 1000);
        $autonomy=[];
        do {
            $events = $this->repository->events($session, $generation, $after, $this->eventLimit);
            if($this->products!==null){
                $sessionRow=$this->repository->session($session,$generation);
                $now=gmdate('Y-m-d\TH:i:s\Z');
                foreach($this->products->dueAutonomy(['installation_id'=>$sessionRow['installation_id'],
                    'profile_id'=>$sessionRow['profile_id'],'playthrough_id'=>$sessionRow['playthrough_id']],$now) as $due){
                    if((string)$due['current_session_id']!==$session)continue;
                    $autonomy[]=['schema'=>'almsivi.autonomy-directive.v1','schedule_id'=>(string)$due['schedule_id'],
                        'kind'=>(string)$due['kind'],'issued_at'=>$now];
                    $this->products->markAutonomyTriggered((string)$due['schedule_id'],$now);
                    if(count($autonomy)>=3)break;
                }
            }
            if ($events !== [] || $autonomy !== [] || microtime(true) >= $deadline) break;
            usleep((int) min(100_000, max(1_000, ($deadline - microtime(true)) * 1_000_000)));
        } while (true);
        $next = $events === [] ? $after : $events[array_key_last($events)]['sequence'];
        return Response::json(200, ['schema' => 'almsivi.events.v1', 'session_id' => $session,
            'generation' => $generation, 'next_after' => $next, 'events' => $events,'autonomy'=>$autonomy]);
    }

    private function controlsQuery(Request $request): Response
    {
        if($this->products===null)throw new ApiException(503,'provider_unavailable','Controls unavailable.',true);
        $m=$this->json($request,'almsivi.controls.query.v1');
        $session=$this->repository->session($m['session_id'],$m['generation']);
        $this->assertPrincipal((string)$session['installation_id']);
        return Response::json(200,$this->controlsBody($m,$session));
    }

    private function controlsSelect(Request $request): Response
    {
        if($this->products===null)throw new ApiException(503,'provider_unavailable','Controls unavailable.',true);
        $m=$this->json($request,'almsivi.controls.select.v1');
        $session=$this->repository->session($m['session_id'],$m['generation']);
        $this->assertPrincipal((string)$session['installation_id']);
        $this->requireIdempotency($request,$m['message_id']);
        return $this->repository->serializedIdempotency((string)$session['installation_id'],$m['message_id'],'/controls/select',function()use($m,$session):Response{
            $hash=$this->semanticHash($m);$cached=$this->repository->idempotent((string)$session['installation_id'],$m['message_id'],'/controls/select',$hash);
            if($cached!==null)return Response::json($cached['status'],$cached['body']);
            if($m['kind']==='model_slot')$this->products->selectSessionProvider($session,$m['selection_id']);
            elseif($m['kind']==='actor_profile')$this->products->bindActorProfile($session,$m['target'],$m['selection_id'],$m['created_at']);
            elseif($m['kind']==='profile_generate')$this->products->enqueueBoundProfileGeneration($session,$m['target'],(string)$m['selection_id']);
            else{$narrator=$this->products->narratorProfileForInstallation((string)$session['installation_id']);
                if($narrator===null||!is_string($m['selection_id'])||!hash_equals((string)$narrator['profile_id'],$m['selection_id']))
                    throw new ApiException(422,'profile_not_narrator','The selected narrator profile is unavailable.');
                $this->products->enqueueNarratorProfileGeneration($m['selection_id']);}
            $updated=$this->repository->session($m['session_id'],$m['generation']);
            $body=$this->controlsBody($m,$updated);
            $this->repository->remember((string)$session['installation_id'],$m['message_id'],'/controls/select',$hash,200,$body);
            return Response::json(200,$body);
        });
    }

    private function controlsBody(array $request,array $session):array
    {
        $controls=$this->products?->sessionControls($session,$request['target']);
        if($controls===null)throw new ApiException(503,'provider_unavailable','Controls unavailable.',true);
        return ['schema'=>'almsivi.controls.v1','message_id'=>$request['message_id'],'request_id'=>$request['request_id'],
            'session_id'=>$request['session_id'],'generation'=>$request['generation'],'target'=>$request['target']]+$controls;
    }

    private function media(string $mediaId): Response
    {
        if (!$this->uuid($mediaId) || $this->mediaStore === null) throw new ApiException(404, 'media_unavailable', 'Media unavailable.');
        try {
            $media = $this->repository->media($mediaId);
            if($this->authenticatedInstallation!==null){if(!hash_equals($this->authenticatedInstallation,(string)$media['installation_id']))throw new ApiException(404,'media_unavailable','Media unavailable.');}
            elseif (!hash_equals((string) $media['token_fingerprint'], $this->pairingTokenHash)) {
                throw new ApiException(404, 'media_unavailable', 'Media unavailable.');
            }
            $bytes = $this->mediaStore->read($mediaId, (int) $media['byte_count'], (string) $media['sha256']);
        } catch (Throwable) {
            throw new ApiException(404, 'media_unavailable', 'Media unavailable.');
        }
        return new Response(200, $bytes, [
            'Content-Type' => (string) $media['mime_type'],
            'Content-Length' => (string) strlen($bytes),
            'Content-Digest' => 'sha-256=:' . base64_encode(hex2bin((string) $media['sha256'])),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function synthesize(array $message, array $capabilities, string $text): ?array
    {
        if (!in_array('speech.say', $capabilities, true) || $this->mediaStore === null || $this->speechProvider === null) return null;
        $cancellation = new \ALMSIVIserver\Application\NeverCancelledToken();
        $attemptId = Uuid::v4();
        try {
            $this->providerAttempts?->start($attemptId, 'tts', 'mock', 'synthesize', 1, $message['request_id'], $message['turn_id'],
                inputBytes: strlen($text), metadata: ['mode' => 'deterministic_mock']);
            $result = $this->speechProvider->synthesize($text, $cancellation);
            $mediaId = Uuid::v4();
            $sha = $this->mediaStore->put($mediaId, $result['bytes'], $result['codec'], $result['mime_type']);
            $this->providerAttempts?->finish($attemptId, 'succeeded', strlen($result['bytes']));
            return ['media_id' => $mediaId, 'sha256' => $sha, 'bytes' => strlen($result['bytes']), 'codec' => $result['codec'],
                'mime_type' => $result['mime_type'], 'duration_ms' => $result['duration_ms'],
                'expires_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z')];
        } catch (Throwable $error) {
            try { $this->providerAttempts?->finish($attemptId, 'failed', errorCode: 'provider_unavailable'); } catch (Throwable) {}
            throw $error;
        }
    }

    private function interrupt(Request $request): Response
    {
        $m = $this->json($request, 'almsivi.interrupt.v1');
        $this->assertPrincipal($this->repository->sessionInstallation($m['session_id']));
        $this->requireIdempotency($request, $m['message_id']);
        $result = $this->repository->interrupt($m);
        return Response::json(202, ['schema' => 'almsivi.interruption.accepted.v1', 'message_id' => $m['message_id'],
            'request_id' => $m['request_id'], 'session_id' => $m['session_id'], 'generation' => $m['generation'],
            'turn_id' => $m['turn_id'], 'event_cursor' => $result['cursor'], 'duplicate' => $result['duplicate']]);
    }

    private function actionResult(Request $request): Response
    {
        $m = $this->json($request, 'almsivi.action-result.v1');
        $this->assertPrincipal($this->repository->sessionInstallation($m['session_id']));
        $this->requireIdempotency($request, $m['message_id']);
        $result = $this->repository->actionResult($m);
        return Response::json(200, ['schema' => 'almsivi.action-result.accepted.v1', 'message_id' => $m['message_id'],
            'request_id' => $m['request_id'], 'action_id' => $m['action_id'], 'turn_id' => $m['turn_id'],
            'session_id' => $m['session_id'], 'generation' => $m['generation'], 'status' => $m['status'],
            'duplicate' => $result['duplicate']]);
    }

    private function stt(Request $request):Response
    {
        if($this->sttProvider===null||$this->mediaStore===null)throw new ApiException(503,'provider_unavailable','STT unavailable.',true,1000);if(strtolower(trim((string)$request->header('Content-Type')))!=='application/octet-stream')throw new ApiException(415,'invalid_schema','Binary audio required.');$h=fn(string $n):string=>(string)($request->header('X-ALMSIVI-'.$n)??'');$m=['schema'=>$h('Schema'),'message_id'=>$h('Message-Id'),'request_id'=>$h('Request-Id'),'turn_id'=>$h('Turn-Id'),'session_id'=>$h('Session-Id'),'generation'=>filter_var($h('Generation'),FILTER_VALIDATE_INT),'created_at'=>$h('Created-At'),'codec'=>$h('Codec'),'language'=>$h('Language'),'audio_bytes'=>filter_var($h('Audio-Bytes'),FILTER_VALIDATE_INT),'sha256'=>$h('Sha256')];$this->validator->validate($m,'almsivi.stt.request.v1');$this->assertPrincipal($this->repository->sessionInstallation($m['session_id']));$this->requireIdempotency($request,$m['message_id']);if(strlen($request->body)!==$m['audio_bytes']||!hash_equals($m['sha256'],hash('sha256',$request->body)))throw new ValidationException('invalid_schema');$semantic=$this->semanticHash($m);$mediaId=Uuid::v4();try{$this->mediaStore->put($mediaId,$request->body,'wav','audio/wav');}catch(\Throwable){throw new ApiException(422,'invalid_audio','Audio is not a valid WAV payload.');}try{$result=$this->repository->acceptStt($m,$mediaId,$semantic);if($result['duplicate'])$this->mediaStore->delete($mediaId);}catch(Throwable $e){$this->mediaStore->delete($mediaId);throw$e;}return Response::json(202,['schema'=>'almsivi.stt.accepted.v1','message_id'=>$m['message_id'],'request_id'=>$m['request_id'],'turn_id'=>$m['turn_id'],'session_id'=>$m['session_id'],'generation'=>$m['generation'],'event_cursor'=>$result['cursor'],'duplicate'=>$result['duplicate']]);
    }

    private function deliveryResult(Request $request):Response
    {
        $m=$this->json($request,'almsivi.dialogue-delivery-result.v1');$this->assertPrincipal($this->repository->sessionInstallation($m['session_id']));$this->requireIdempotency($request,$m['message_id']);$result=$this->repository->dialogueDeliveryResult($m);return Response::json(200,['schema'=>'almsivi.dialogue-delivery-result.accepted.v1','message_id'=>$m['message_id'],'request_id'=>$m['request_id'],'dialogue_message_id'=>$m['dialogue_message_id'],'turn_id'=>$m['turn_id'],'session_id'=>$m['session_id'],'generation'=>$m['generation'],'status'=>$m['status'],'duplicate'=>$result['duplicate']]);
    }

    private function authenticate(Request $request): void
    {
        foreach ($request->query as $key => $_) {
            if (in_array(strtolower($key), ['token', 'pairing_token', 'authorization', 'access_token'], true)) {
                throw new ApiException(400, 'invalid_schema', 'Credentials are not accepted in query strings.');
            }
        }
        if($request->header('Authorization')!==null)throw new ApiException(401,'unauthorized','Bearer authentication is not accepted.');
        $principal=$this->management?->requestMacPrincipal($request);
        if($principal===null){$key=$this->bootstrapMacKey();$principal=$key===null?false:RequestMac::verify($request,$key);}
        if(!is_string($principal))throw new ApiException(401,'unauthorized','Authentication failed.');
        $this->authenticatedInstallation=$principal;
    }

    private function assertPrincipal(string $installation):void
    {
        if($this->authenticatedInstallation!==null&&!hash_equals($this->authenticatedInstallation,$installation))
            throw new ApiException(403,'installation_forbidden','Installation does not match authenticated token.');
    }

    private function json(Request $request, string $schema): array
    {
        $type = $request->header('Content-Type');
        if ($type === null || preg_match('#^application/json(?:\s*;\s*charset=utf-8)?$#iD', trim($type)) !== 1) {
            throw new ApiException(415, 'invalid_schema', 'JSON UTF-8 content type required.');
        }
        $message = $this->validator->decode($request->body, $this->maxJsonBytes);
        $this->validator->validate($message, $schema);
        return $message;
    }

    private function requireIdempotency(Request $request, ?string $expected = null): string
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || !$this->uuid($key) || ($expected !== null && !hash_equals($expected, $key))) {
            throw new ApiException(422, 'invalid_idempotency_key', 'A coherent Idempotency-Key is required.');
        }
        return $key;
    }

    private function idempotent(string $installation, string $key, string $route, array $message, callable $callback): Response
    {
        $hash = $this->semanticHash($message);
        $cached = $this->repository->idempotent($installation, $key, $route, $hash);
        if ($cached !== null) return Response::json($cached['status'], $cached['body']);
        [$status, $body] = $callback();
        $this->repository->remember($installation, $key, $route, $hash, $status, $body);
        return Response::json($status, $body);
    }

    private function semanticHash(array $message): string
    {
        return hash('sha256', json_encode($this->canonical($message), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn($v) => $this->canonical($v), $value);
        ksort($value);
        foreach ($value as &$item) $item = $this->canonical($item);
        return $value;
    }

    private function path(string $path): string
    {
        if (!str_starts_with($path, $this->basePath)) throw new ApiException(404, 'not_found', 'Route not found.');
        $value = substr($path, strlen($this->basePath));
        return $value === '' ? '/' : $value;
    }

    private function rateLimitRoute(string $path): string
    {
        if (preg_match('#^/media/[0-9a-f-]{36}$#D', $path)) return '/media/{id}';
        if (preg_match('#^/sessions/[0-9a-f-]{36}$#D', $path)) return '/sessions/{id}';
        return $path;
    }

    private function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function bootstrapMacKey():?string
    {
        $value=getenv('ALMSIVI_PAIRING_MAC_KEY');if(!is_string($value)||$value==='')return hex2bin($this->pairingTokenHash)?:null;
        $decoded=base64_decode(strtr($value,'-_','+/'),true);return is_string($decoded)&&strlen($decoded)===32?$decoded:null;
    }

    private function publicCode(string $code):string
    {
        $aliases=['dialogue_result_mismatch'=>'request_mismatch','dialogue_result_time_invalid'=>'invalid_schema','unknown_dialogue'=>'not_found'];if(isset($aliases[$code]))return$aliases[$code];
        $allowed=['action_disabled','action_parameters_invalid','action_result_expired','action_result_mismatch',
            'action_target_invalid','action_tier_mismatch','cursor_expired','duplicate_conflict','invalid_idempotency_key',
            'invalid_schema','media_unavailable','not_found','provider_action_not_allowed','provider_invalid_action',
            'provider_invalid_output','provider_timeout','provider_unavailable','rate_limited','request_mismatch',
            'stale_generation','turn_terminal','unauthorized','unknown_action','unknown_session','unknown_turn'];
        return in_array($code,$allowed,true)?$code:'internal_error';
    }

    private function error(ApiException $error, string $correlation): Response
    {
        return Response::error($error->status, $error->errorCode, $correlation, $error->retrySafe, $error->retryAfterMs);
    }
}
