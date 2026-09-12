<?php

declare(strict_types=1);

namespace LorkhanServer\Http;

use LorkhanServer\Application\DeterministicRetrieval;
use LorkhanServer\Application\ConnectorCatalog;
use LorkhanServer\Application\DiaryGenerationPolicy;
use LorkhanServer\Application\EffectiveSettingsResolver;
use LorkhanServer\Application\LlmConnector;
use LorkhanServer\Application\NeverCancelledToken;
use LorkhanServer\Application\PlayerMoodPolicy;
use LorkhanServer\Application\ProductService;
use LorkhanServer\Application\Provider;
use LorkhanServer\Application\ProviderFactory;
use LorkhanServer\Application\SpeechPreviewCatalog;
use LorkhanServer\Application\SttTestSample;
use LorkhanServer\Application\SettingsCatalog;
use LorkhanServer\Application\TranslationPolicy;
use LorkhanServer\Infrastructure\ManagementRepository;
use LorkhanServer\Infrastructure\EventLogRepository;
use LorkhanServer\Infrastructure\OghmaCatalogImporter;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\Uuid;
use LorkhanServer\Security\BrowserSession;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ManagementRouter
{
    private const PAGES=['quickstart','roleplay','configuration','control-panel','characters','profiles','player','npc-biographies','providers','ai-voice','prompts-actions','action-editor','world','descriptions','traces','memory','relationships','knowledge','playthroughs','narrative-autonomy','jobs','response-queue','oghma-audit','provider-usage','cache','backup-health','database-manager','server-logs','diagnostics','game-debug'];
    /** One pronunciation term never needs a long clip, so an oversized answer is treated as a failure. */
    private const MAX_PREVIEW_AUDIO_BYTES=8_388_608;
    private const PREVIEW_AUDIO_MIME_TYPES=['audio/wav','audio/mpeg','audio/ogg','audio/webm','audio/flac','audio/mp4'];
    private const BIOGRAPHY_CSV_HEADER=['content_file','record_id','name','core','biography','appearance','personality',
        'relationships','occupation','skills','speech_style','goals','oghma_tags','voice_id','gender','race'];
    private const UI_PAGES=[
        'quickstart'=>'/ui/home.php',
        'roleplay'=>'/ui/events-memories.php',
        'configuration'=>'/ui/core/config_hub.php',
        'control-panel'=>'/ui/control_panel.php',
        'characters'=>'/ui/core/npc_master.php',
        'profiles'=>'/ui/core/core_profiles.php',
        'player'=>'/ui/core/player_management.php',
        'narrator'=>'/ui/core/config_hub.php?tab=narration-page',
        'npc-biographies'=>'/ui/core/npc_biographies.php',
        'providers'=>'/ui/core/llm_connectors.php',
        'ai-voice'=>'/ui/core/tts_connectors.php',
        'tts-connectors'=>'/ui/core/tts_connectors.php?embed=1',
        'tts-studio'=>'/ui/core/voice_library.php',
        'stt-connectors'=>'/ui/core/stt_connectors.php?embed=1',
        'prompts-actions'=>'/ui/prompts_manager.php',
        'action-editor'=>'/ui/function_editor.php',
        'world'=>'/ui/core/config_hub.php?tab=globals-page',
        'descriptions'=>'/ui/description_manager.php',
        'traces'=>'/ui/request_logs.php',
        'memory'=>'/ui/events-memories.php?tab=memory',
        'relationships'=>'/ui/control_panel.php?tab=rellogs',
        'knowledge'=>'/ui/core/config_hub.php?tab=knowledge-page',
        'playthroughs'=>'/ui/control_panel.php?tab=playthrough-page',
        'playthrough-form'=>'/ui/playthrough_manager.php',
        'narrative-autonomy'=>'/ui/narrative_manager.php',
        'jobs'=>'/ui/control_panel.php?tab=jobs-page',
        'response-queue'=>'/ui/control_panel.php?tab=queue-page',
        'oghma-audit'=>'/ui/control_panel.php?tab=oghma-audit-page',
        'provider-usage'=>'/ui/control_panel.php?tab=usage-page',
        'cache'=>'/ui/control_panel.php?tab=cache-page',
        'backup-health'=>'/ui/control_panel.php?tab=database-page',
        'database-manager'=>'/ui/database_manager.php',
        'server-logs'=>'/ui/control_panel.php?tab=server-logs-page',
        'diagnostics'=>'/ui/control_panel.php?tab=srvlogs',
        'game-debug'=>'/ui/control_panel.php?tab=game-debug',
    ];

    public function __construct(private readonly ManagementRepository $management,private readonly ProductRepository $repository,
        private readonly ProductService $service,private readonly string $basePath='/LorkhanServer/manage',
        private readonly int $maxJsonBytes=2_097_152,private readonly int $sessionTtl=3600,
        private readonly array $providerConfig=[],private readonly ?EventLogRepository $eventLogRepository=null,
        private readonly ?OghmaCatalogImporter $oghmaCatalogImporter=null){ }

    public function dispatch(Request $r):Response
    {
        try{
            $path=$this->path($r->path);
            if($r->method==='GET'&&in_array($path,['/','/login'],true))return$this->redirect($this->uiPath('quickstart'));
            if($r->method==='GET'&&in_array(ltrim($path,'/'),self::PAGES,true))return$this->redirect($this->uiPath(ltrim($path,'/')));
            $session=$this->authenticatedSession($r);
            if($session===null){if($r->method==='GET'&&$this->htmlRequest($r))return$this->openBrowserSession($r->path);throw new RuntimeException('unauthorized');}
            if($r->method==='GET'&&$path==='/api/v1/database-maintenance')return Response::json(200,['job'=>$this->management->databaseMaintenanceStatus()]);
            if($r->method==='GET'&&$path==='/api/v1/database-backup')return Response::json(200,['job'=>$this->management->databaseMaintenanceStatus('database.backup')]);
            if($r->method==='GET'&&$path==='/api/v1/database-restore')return Response::json(200,['job'=>$this->management->databaseMaintenanceStatus('database.restore')]);
            if($r->method==='GET'&&$path==='/api/v1/database-replay')return Response::json(200,['job'=>$this->management->databaseMaintenanceStatus('database.replay')]);
            if($r->method==='GET'&&$path==='/api/v1/database-replay-plan')return Response::json(200,$this->management->databaseReplayPlan());
            if($r->method==='GET'&&$path==='/api/v1/database-factory-reset')return Response::json(200,['job'=>$this->management->databaseMaintenanceStatus('database.factory_reset')]);
            if($r->method==='GET'&&$path==='/api/v1/playthrough-snapshot')return Response::json(200,['job'=>$this->management->snapshotSaveStatus()]);
            if($r->method==='GET'&&preg_match('#^/exports/database/([0-9a-f-]{36})\\.sql$#D',$path,$m)){
                $record=$this->repository->configurationBackupRecord($m[1]);
                if(($record['scope']['kind']??'')!=='database_sql')throw new RuntimeException('not_found');
                $file=(new \LorkhanServer\Infrastructure\DatabaseSqlBackup($this->providerConfig))->path($m[1]);
                if(!is_file($file)||filesize($file)!==(int)$record['byte_count']||!hash_equals($record['content_sha256'],hash_file('sha256',$file)))throw new RuntimeException('backup_integrity_failed');
                $stream=fopen($file,'rb');if($stream===false)throw new RuntimeException('backup_storage_unavailable');
                return new Response(200,'',['Content-Type'=>'application/sql','Content-Disposition'=>'attachment; filename="LorkhanServer-'.$m[1].'.sql"'],$stream);
            }
            if($r->method==='GET'&&preg_match('#^/exports/profiles/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportProfile($m[1]);
            if($r->method==='GET'&&preg_match('#^/exports/core-profile-settings/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportCoreProfileSettings($m[1]);
            if($r->method==='GET'&&preg_match('#^/exports/player-profile-settings/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportSpecialProfileSettings($m[1],'player');
            if($r->method==='GET'&&preg_match('#^/exports/narrator-profile-settings/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportSpecialProfileSettings($m[1],'narrator');
            if($r->method==='GET'&&preg_match('#^/exports/global-settings/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportGlobalSettings($m[1]);
            if($r->method==='GET'&&preg_match('#^/exports/playthroughs/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportPlaythroughState($m[1]);
            if($r->method==='GET'&&preg_match('#^/exports/providers/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportProvider($m[1]);
            if($r->method==='GET'&&preg_match('#^/exports/prompts/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportPrompt($m[1]);
            if($r->method==='GET'&&preg_match('#^/exports/connectors/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportConnector($m[1]);
            if($r->method==='GET'&&preg_match('#^/exports/backups/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->downloadConfigurationBackup($m[1]);
            if($r->method==='GET'&&$path==='/exports/descriptions/example.csv')return$this->exampleDescriptionsCsv();
            if($r->method==='GET'&&$path==='/exports/descriptions/custom.csv')return$this->exportDescriptionsCsv($this->queryUuid($r,'installation_id'));
            if($r->method==='GET'&&$path==='/exports/biographies/example.csv')return$this->exampleBiographiesCsv();
            if($r->method==='GET'&&$path==='/exports/biographies/custom.csv')return$this->exportBiographiesCsv($this->queryUuid($r,'installation_id'));
            if($r->method==='GET'&&$path==='/exports/oghma/example.csv')return$this->exampleOghmaCsv();
            if($r->method==='GET'&&$path==='/exports/oghma-dynamic/example.csv')return$this->exampleOghmaCsv(true);
            if(in_array($r->method,['POST','PUT','PATCH','DELETE'],true))$this->csrf($r,$session);
            if($r->method==='POST'&&$path==='/logout'){$this->management->revoke($session);return$this->redirect($this->uiPath('quickstart'),['Set-Cookie'=>['lorkhan_management=; Path='.$this->webRoot().'; Max-Age=0; HttpOnly; SameSite=Strict','lorkhan_csrf=; Path='.$this->webRoot().'; Max-Age=0; SameSite=Strict']]);}
            if(str_starts_with($path,'/api/v1/'))return$this->api($r,$path,$session);
            if($r->method==='POST'&&preg_match('#^/forms/([a-z-]+)$#D',$path,$m))return$this->submit($m[1],$r);
            throw new RuntimeException('not_found');
        }catch(InvalidArgumentException $e){return$this->htmlRequest($r)?$this->errorPage($e->getMessage(),422):Response::json(422,['error'=>$e->getMessage()]);}
        catch(RuntimeException $e){
            if(in_array($e->getMessage(),['relationship_restore_conflict','revision_conflict'],true))
                return$this->htmlRequest($r)?$this->errorPage($e->getMessage(),409):Response::json(409,['error'=>$e->getMessage()]);
            if(in_array($e->getMessage(),['relationship_revision_conflict','relationship_already_exists'],true)){
                if($path==='/forms/profile-revise')return$this->errorPage($e->getMessage(),409);
                if($this->htmlRequest($r))return $this->redirect($this->relationshipPageLocation($this->form($r),$e->getMessage()));
                return Response::json(409,['error'=>$e->getMessage()]);
            }
            $status=$e->getMessage()==='unauthorized'?401:404;if($status===401&&$this->htmlRequest($r))return$this->redirect($this->uiPath('quickstart'));return Response::json($status,['error'=>$e->getMessage()]);
        }
        catch(Throwable){return$this->htmlRequest($r)?$this->errorPage('internal_error',500):Response::json(500,['error'=>'internal_error']);}
    }

    /** Proxy fixed public catalogues without credentials or weakening the UI's same-origin CSP. */
    private function openRouterCatalogue(bool $providers): Response
    {
        $error = $providers ? 'provider_catalogue_unavailable' : 'model_catalogue_unavailable';
        if (!function_exists('curl_init')) return Response::json(502, ['error'=>$error]);
        $handle = curl_init($providers ? 'https://openrouter.ai/api/v1/providers' : 'https://openrouter.ai/api/v1/models');
        if ($handle === false) return Response::json(502, ['error'=>$error]);
        $body = '';
        try {
            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_CONNECTTIMEOUT_MS=>3000, CURLOPT_TIMEOUT_MS=>8000,
                CURLOPT_HTTPHEADER=>['Accept: application/json'],
                CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 8_388_608) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $success = curl_exec($handle);
            if ($success === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                return Response::json(502, ['error'=>$error]);
            }
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) return Response::json(502, ['error'=>$error]);
            return Response::json(200, $providers ? ConnectorCatalog::normalizeOpenRouterProviders($payload) : ConnectorCatalog::normalizeOpenRouterModels($payload));
        } catch (Throwable) {
            return Response::json(502, ['error'=>$error]);
        } finally { curl_close($handle); }
    }

    private function api(Request $r,string $path,string $browserSession):Response
    {
        if ($r->method === 'POST' && $path === '/api/v1/llm-groq-models') {
            $body = $this->json($r); $keys = array_keys($body); sort($keys);
            if ($r->query !== [] || $keys !== ['credential','driver'] || !is_string($body['credential'])
                || !in_array($body['driver'], ['configured','openai-compatible'], true)) throw new InvalidArgumentException('invalid_groq_catalogue_request');
            if ($body['driver'] === 'configured') {
                $provider = $this->providerConfig['provider'] ?? [];
                if (($provider['driver'] ?? '') !== 'openai-compatible'
                    || rtrim((string)($provider['endpoint'] ?? ''), '/') !== 'https://api.groq.com/openai/v1/chat/completions') {
                    throw new InvalidArgumentException('groq_runtime_not_configured');
                }
                if ($body['credential'] !== '') {
                    if (LlmConnector::credentialVariable($body['credential']) === null) throw new InvalidArgumentException('invalid_groq_credential');
                    $provider['credential'] = $body['credential'];
                }
            } else {
                if (LlmConnector::credentialVariable($body['credential']) === null) throw new InvalidArgumentException('invalid_groq_credential');
                $provider = ['credential'=>$body['credential']];
            }
            $apiKey = ProviderFactory::apiKey($provider, 'LORKHAN_LLM_API_KEY', $this->providerConfig);
            if ($apiKey === '') return Response::json(422, ['error'=>'groq_api_key_required']);
            try { return Response::json(200, ConnectorCatalog::groqModels($apiKey)); }
            catch (Throwable) { return Response::json(502, ['error'=>'groq_catalogue_unavailable']); }
        }
        if ($r->method === 'GET' && in_array($path, ['/api/v1/llm-models', '/api/v1/llm-providers'], true)) {
            $providers = $path === '/api/v1/llm-providers';
            if ($r->query !== []) throw new InvalidArgumentException($providers ? 'invalid_provider_catalogue_query' : 'invalid_model_catalogue_query');
            return $this->openRouterCatalogue($providers);
        }
        if ($r->method === 'GET' && $path === '/api/v1/quickstart-local-llm') {
            if(array_keys($r->query)!==['installation_id'])throw new InvalidArgumentException('invalid_local_llm_setup_query');
            $installation=$this->queryUuid($r,'installation_id');
            return Response::json(200,['routing_plan'=>$this->repository->quickstartLocalRoutingPlan($installation)]);
        }
        if ($r->method === 'POST' && $path === '/api/v1/quickstart-local-llm') {
            $body=$this->json($r);$keys=array_keys($body);sort($keys);
            if($r->query!==[]||$keys!==['fingerprint','installation_id','setup']
                ||!is_string($body['installation_id'])||!is_string($body['fingerprint'])
                ||!preg_match('/^[a-f0-9]{64}$/D',$body['fingerprint'])
                ||!is_array($body['setup'])||array_is_list($body['setup']))throw new InvalidArgumentException('invalid_local_llm_setup_request');
            $this->uuid($body['installation_id'],'installation_id');
            $saved=$this->repository->applyQuickstartLocalLlm($body['installation_id'],$body['setup'],$body['fingerprint'],gmdate('Y-m-d\TH:i:s\Z'));
            // Return routing metadata only: provider configuration and credential references stay out of this response.
            return Response::json(200,['configuration_id'=>$saved['configuration_id'],'routing_plan'=>$saved['routing_plan']]);
        }
        if ($r->method === 'POST' && $path === '/api/v1/quickstart-local-llm-test') {
            $body=$this->json($r);$keys=array_keys($body);sort($keys);
            if($r->query!==[]||!in_array($keys,[['installation_id','setup'],['api_key','installation_id','setup']],true)
                ||!is_string($body['installation_id'])||!is_array($body['setup'])||array_is_list($body['setup']))
                throw new InvalidArgumentException('invalid_local_llm_test_request');
            $draftKey=array_key_exists('api_key',$body)?$body['api_key']:'';
            if(!is_string($draftKey)||strlen($draftKey)>8192||preg_match('/[\x00-\x1F\x7F]/',$draftKey))
                throw new InvalidArgumentException('invalid_local_llm_test_key');
            $draftKey=trim($draftKey);
            $this->uuid($body['installation_id'],'installation_id');
            $this->repository->quickstartLocalRoutingPlan($body['installation_id']);
            $setup=\LorkhanServer\Application\QuickstartLocalLlm::normalize($body['setup']);
            if(!$this->management->allowTtsPreview($browserSession))return Response::json(429,['error'=>'local_llm_test_rate_limited']);
            try {
                // A transient slot exercises unsaved fields without creating a connector or changing any routes.
                if($draftKey!==''){
                    // Keep the unsaved key in this adapter only; never put it in config, snapshots or the credential store.
                    $content=$setup['content'];
                    $provider=new \LorkhanServer\Application\OpenAiCompatibleProvider(
                        endpoint:$content['endpoint'],allowedHosts:[(string)parse_url($content['endpoint'],PHP_URL_HOST)],
                        model:$content['model'],apiKey:$draftKey,timeoutMs:$content['timeout_ms'],
                        options:$content['options'],allowLoopbackHttp:str_starts_with($content['endpoint'],'http://'),
                        directConnection:true,localNetwork:true);
                }else{
                    $provider=ProviderFactory::dialogueForSlot($this->providerConfig,[
                        'configuration_id'=>'00000000-0000-4000-8000-000000000001','revision'=>1,'content'=>$setup['content']]);
                }
                return Response::json(200,['ok'=>true,'message'=>$this->diagnoseProvider($provider)]);
            } catch(Throwable) { return Response::json(502,['error'=>'local_llm_test_failed']); }
        }
        if ($r->method === 'POST' && $path === '/api/v1/quickstart-minime') {
            $body=$this->json($r);
            if ($r->query!==[] || array_keys($body)!==['installation_id'] || !is_string($body['installation_id'])) {
                throw new InvalidArgumentException('invalid_minime_probe_request');
            }
            $this->uuid($body['installation_id'],'installation_id');
            if (!$this->management->allowTtsPreview($browserSession)) return Response::json(429,['error'=>'minime_probe_rate_limited']);
            $policy=\LorkhanServer\Application\MemoryEmbeddingPolicy::validate(
                $this->repository->memoryEmbeddingPolicyForInstallation($body['installation_id'])['content']
                ?? \LorkhanServer\Application\MemoryEmbeddingPolicy::defaults());
            $url=$policy['endpoint']!==''?$policy['endpoint']:'http://127.0.0.1:8082';
            if (str_ends_with($url,'/embed')) $url=substr($url,0,-6);
            $parts=parse_url($url);$started=microtime(true);$code=0;$ok=false;
            try {
                $options=\LorkhanServer\Security\OutboundUrlPolicy::curlOptions($url,[$parts['host']],$parts['scheme']==='http',true);
                $handle=curl_init($url);
                if ($handle===false) throw new RuntimeException('probe_unavailable');
                try {
                    // Probe reachability only: discard bounded output and never send game text or credentials.
                    $bytes=0;
                    curl_setopt_array($handle,$options+[
                        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>2000,CURLOPT_TIMEOUT_MS=>4000,
                        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                        CURLOPT_HTTPHEADER=>['Accept: application/json, text/plain;q=0.9'],
                        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$bytes):int {
                            $bytes+=strlen($chunk);return $bytes>65536?0:strlen($chunk);
                        },
                    ]);
                    $success=curl_exec($handle);$code=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
                    $ok=$success!==false&&$code>=200&&$code<500;
                } finally {curl_close($handle);}
            } catch (Throwable) {$ok=false;}
            return Response::json(200,['ok'=>$ok,'http_code'=>$code,'latency_ms'=>(int)round((microtime(true)-$started)*1000),
                'message'=>$ok?'MiniMe service reachable.':'MiniMe service not reachable. Check the service and its endpoint in Global Settings.']);
        }
        if($r->method==='POST'&&$path==='/api/v1/quickstart-key'){
            $body=$this->json($r);$keys=array_keys($body);sort($keys);
            if($keys!==['credential','provider']||!is_string($body['provider'])||!is_string($body['credential']))
                throw new InvalidArgumentException('invalid_quickstart_key');
            $variable=match($body['provider']){'openrouter'=>'LORKHAN_LLM_API_KEY','deepgram'=>'LORKHAN_TTS_DEEPGRAM_API_KEY',
                'local_llm'=>\LorkhanServer\Application\QuickstartLocalLlm::CREDENTIAL,
                default=>throw new InvalidArgumentException('invalid_quickstart_key')};
            // Environment-owned keys cannot be changed by writing an ineffective managed replacement.
            $environment=getenv($variable);
            if(is_string($environment)&&$environment!=='')return Response::json(409,['error'=>'credential_managed_by_environment']);
            $store=new \LorkhanServer\Application\CredentialStore((string)($this->providerConfig['credential_storage_path']??'/var/lib/lorkhanserver/credentials/provider-keys.json'));
            $store->set($variable,$body['credential']);
            return Response::json(200,['saved'=>true]);
        }
        if ($r->method==='POST' && $path==='/api/v1/roleplay/sync-memories') {
            $body=$this->json($r);
            if (($body['confirm']??'')!=='Sync') throw new InvalidArgumentException('confirmation_mismatch');
            foreach (['installation_id','playthrough_id'] as $field) {
                if (!is_string($body[$field]??null)) throw new InvalidArgumentException('invalid_memory_scope');
            }
            return Response::json(202,$this->management->syncMemorySummaries($body['installation_id'],$body['playthrough_id']));
        }
        if ($r->method === 'POST' && $path === '/api/v1/response-queue/remove') {
            $body=$this->json($r);
            if (($body['confirm']??'')!=='Remove') throw new InvalidArgumentException('confirmation_mismatch');
            if (!is_string($body['installation_id']??null) || !is_int($body['rowid']??null)) throw new InvalidArgumentException('invalid_response_queue_scope');
            return Response::json(200,['removed'=>$this->management->removeResponseQueueEntry($body['installation_id'],$body['rowid'])]);
        }
        if ($r->method === 'POST' && $path === '/api/v1/request-logs/clear') {
            $body = $this->json($r);
            if (($body['confirm'] ?? '') !== 'Clear') throw new InvalidArgumentException('confirmation_mismatch');
            if (!is_string($body['installation_id'] ?? null)) throw new InvalidArgumentException('invalid_installation_id');
            return Response::json(200, ['cleared'=>$this->management->clearRequestLog($body['installation_id'])]);
        }
        if ($r->method === 'POST' && $path === '/api/v1/relationship-logs/clear') {
            $body=$this->json($r);
            if(($body['confirm']??'')!=='Clear')throw new InvalidArgumentException('confirmation_mismatch');
            if(!is_string($body['installation_id']??null)||!is_string($body['age']??null))throw new InvalidArgumentException('invalid_relationship_log_scope');
            return Response::json(200,['cleared'=>$this->management->clearRequestLog($body['installation_id'],$body['age'])]);
        }
        if ($r->method === 'POST' && $path === '/api/v1/roleplay/clear') {
            $body = $this->json($r);
            if (($body['confirm'] ?? '') !== 'Clear') throw new InvalidArgumentException('confirmation_mismatch');
            foreach (['installation_id','playthrough_id','kind'] as $field) {
                if (!is_string($body[$field] ?? null)) throw new InvalidArgumentException('invalid_roleplay_log_scope');
            }
            return Response::json(200, ['cleared'=>$this->management->clearRoleplayLog(
                $body['installation_id'], $body['playthrough_id'], $body['kind'])]);
        }
        if(preg_match('#^/api/v1/profiles/([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/eventlog(?:/([1-9][0-9]*))?$#D',$path,$m)){
            $events=$this->eventLogRepository??throw new RuntimeException('not_found');
            if($r->method==='GET'&&!isset($m[2]))return Response::json(200,['data'=>$events->profileHistory(
                $m[1],$this->queryUuid($r,'playthrough_id'),isset($r->query['type'])?(string)$r->query['type']:null,
                (int)($r->query['limit']??100))]);
            if(($r->method==='POST'&&!isset($m[2]))||($r->method==='DELETE'&&isset($m[2]))){
                $body=$this->json($r);$playthrough=(string)($body['playthrough_id']??'');$this->uuid($playthrough,'playthrough_id');
                if($r->method==='POST')return Response::json(201,['data'=>$events->injectProfileEvent($m[1],$playthrough,$body)]);
                return Response::json(200,['data'=>$events->suppressProfileEvent($m[1],$playthrough,(int)$m[2])]);
            }
        }
        if($path==='/api/v1/eventlog'){
            $events=$this->eventLogRepository??throw new RuntimeException('not_found');
            if($r->method==='GET')return Response::json(200,$events->page($r->query));
            if($r->method==='DELETE')return Response::json(200,$events->suppress($this->json($r)));
        }
        if($r->method==='POST'&&$path==='/api/v1/eventlog/hidden-types'){
            $events=$this->eventLogRepository??throw new RuntimeException('not_found');$body=$this->json($r);
            $scope=$events->scope(is_string($body['installation_id']??null)?$body['installation_id']:null,
                is_string($body['playthrough_id']??null)?$body['playthrough_id']:null);
            if($scope===null)throw new InvalidArgumentException('invalid_eventlog_scope');
            $action=(string)($body['action']??'');$type=(string)($body['type']??'');
            $hidden=match($action){'hide'=>$events->hideType($scope['installation_id'],$type),
                'show'=>$events->showType($scope['installation_id'],$type),'clear'=>$events->clearHiddenTypes($scope['installation_id']),
                default=>throw new InvalidArgumentException('invalid_hidden_type_action')};
            return Response::json(200,['ok'=>true,'hidden_types'=>$hidden]);
        }
        if($path==='/api/v1/profile-assignment-rules'){
            if($r->method==='GET')return Response::json(200,$this->repository->profileAssignmentRulesPlan($this->queryUuid($r,'installation_id')));
            if($r->method==='POST'){$body=$this->json($r);$operation=(string)($body['operation']??'');
                if($operation==='save')return Response::json(200,$this->repository->saveProfileAssignmentRule($body,gmdate('Y-m-d\TH:i:s\Z')));
                if($operation==='delete'){$this->repository->deleteProfileAssignmentRule((string)($body['installation_id']??''),(string)($body['rule_id']??''));
                    return Response::json(200,['deleted'=>true]);}
                throw new InvalidArgumentException('invalid_profile_assignment_rule_operation');
            }
        }
        if($r->method==='GET'&&$path==='/api/v1/diagnostics')return Response::json(200,$this->repository->diagnostics());
        if($r->method==='GET'&&$path==='/api/v1/debug-command-sessions')return Response::json(200,['items'=>$this->repository->debugCommandSessions()]);
        if($r->method==='POST'&&$path==='/api/v1/browser-speech'){
            // Stable utterance IDs make retries safe; a queued receipt is not game acceptance.
            $body=$this->json($r);$keys=array_keys($body);sort($keys);
            if($keys!==['language','request_id','session_id','text'])throw new InvalidArgumentException('invalid_browser_speech_request');
            foreach(['session_id','request_id']as$key){
                if(!is_string($body[$key]))throw new InvalidArgumentException('invalid_browser_speech_request');
                $this->uuid($body[$key],$key);
            }
            $command=$this->repository->queueDebugCommand($body['session_id'],'player.dialogue.submit',
                ['text'=>$body['text'],'language'=>$body['language']],$body['request_id']);
            return Response::json(202,['command'=>$command]);
        }
        if($path==='/api/v1/debug-commands'){
            if($r->method==='GET')return Response::json(200,['items'=>$this->repository->debugCommands($this->queryUuid($r,'session_id'))]);
            if($r->method==='POST'){$body=$this->json($r);$session=(string)($body['session_id']??'');$this->uuid($session,'session_id');
                $name=$body['name']??null;$parameters=$body['parameters']??null;
                if(!is_string($name)||!is_array($parameters)||($parameters!==[]&&array_is_list($parameters)))throw new InvalidArgumentException('invalid_debug_command');
                return Response::json(201,['command'=>$this->repository->queueDebugCommand($session,$name,$parameters)]);}
        }
        if($r->method==='GET'&&$path==='/api/v1/context-filter-candidates')return Response::json(200,$this->repository->contextFilterCandidates($this->queryUuid($r,'installation_id'),(string)($r->query['kind']??'')));
        if($path==='/api/v1/global-connector-tests'){
            if($r->method==='GET')return Response::json(200,$this->repository->globalConnectorTestPlan($this->queryUuid($r,'installation_id')));
            if($r->method==='POST'){
                $values=$this->json($r);
                if(($values['confirm']??'')!=='Run tests')throw new InvalidArgumentException('confirmation_mismatch');
                $plan=$this->repository->globalConnectorTestPlan($this->need($values,'installation_id'));
                $allowed=false;
                foreach($plan['jobs']as$job)if(($values['kind']??null)===$job['kind']&&($values['configuration_id']??null)===$job['configuration_id'])$allowed=true;
                if(!$allowed)throw new InvalidArgumentException('connector_test_plan_changed');
                return Response::json(200,['result'=>$this->runProfileConnectorTest($values)]);
            }
        }
        if($r->method==='POST'&&$path==='/api/v1/core-profile-copy-setting')return Response::json(200,$this->repository->copyCoreProfileSetting($this->json($r)));
        if($path==='/api/v1/profile-connector-tests'){
            if($r->method==='GET')return Response::json(200,$this->repository->coreProfileConnectorTestPlan($this->queryUuid($r,'installation_id')));
            if($r->method==='POST')return Response::json(200,['result'=>$this->runProfileConnectorTest($this->json($r))]);
        }
        if ($r->method === 'POST' && $path === '/api/v1/stt-connector-tests') {
            // Expensive speech diagnostics share the existing browser preview rate budget.
            if (!$this->management->allowTtsPreview($browserSession)) return Response::json(429, ['error'=>'stt_test_rate_limited']);
            $values = $this->json($r);
            $keys = array_keys($values); sort($keys);
            if ($keys !== ['configuration_id', 'installation_id']) throw new InvalidArgumentException('invalid_stt_test_request');
            try { return Response::json(200, $this->testSpeechToText($values)); }
            catch (RuntimeException $error) {
                if ($error->getMessage() === 'not_found') throw $error;
                return Response::json(502, ['error'=>'stt_test_failed']);
            }
        }
        if($r->method==='POST'&&$path==='/api/v1/diary-audio')return$this->diaryAudio($this->json($r),$browserSession);
        if($r->method==='POST'&&$path==='/api/v1/tts-previews')return$this->speechPreview($this->json($r),$browserSession);
        if($r->method==='GET'&&$path==='/api/v1/actions')return Response::json(200,['items'=>$this->actions()]);
        if($r->method==='GET'&&$path==='/api/v1/action-policies/editor')return Response::json(200,$this->actionPolicyEditor($r));
        if($r->method==='POST'&&$path==='/api/v1/action-policies/revisions')return Response::json(200,$this->saveActionPolicyRevision($this->json($r)));
        if($r->method==='GET'&&$path==='/api/v1/traces')return Response::json(200,['items'=>$this->repository->searchTraces($this->queryUuid($r,'installation_id'),(string)($r->query['q']??''))]);
        if($r->method==='GET'&&preg_match('#^/api/v1/traces/([0-9a-f-]{36})$#D',$path,$m))return Response::json(200,$this->repository->traceDetail($m[1]));
        if(preg_match('#^/api/v1/(profiles|core-profiles|playthroughs|prompts|providers|tts-providers|stt-providers|action-policies)$#D',$path,$m)){
            $kind=$this->singular($m[1]);if($r->method==='GET')return Response::json(200,['items'=>$this->repository->listRevisioned($kind,$this->queryUuid($r,'installation_id'))]);
            if($r->method==='POST')return Response::json(201,$this->service->createRevisioned($kind,$this->json($r)));
        }
        if($r->method==='POST'&&preg_match('#^/api/v1/(profiles|core-profiles|playthroughs|prompts|providers|tts-providers|stt-providers|action-policies)/([0-9a-f-]{36})/(revisions|rollback)$#D',$path,$m)){$b=$this->json($r);return$m[3]==='revisions'?Response::json(201,$this->service->revise($this->singular($m[1]),$m[2],$b['content']??[],$b['reason']??'updated')):Response::json(200,$this->service->rollback($this->singular($m[1]),$m[2],(int)($b['revision']??0),(string)($b['reason']??'rollback')));}
        if($r->method==='DELETE'&&preg_match('#^/api/v1/(profiles|core-profiles|playthroughs|prompts|providers|tts-providers|stt-providers|action-policies)/([0-9a-f-]{36})$#D',$path,$m)){$this->service->deleteRevisioned($this->singular($m[1]),$m[2]);return Response::json(200,['deleted'=>true]);}
        if($path==='/api/v1/connector-selections'){
            if($r->method==='GET')return Response::json(200,['items'=>$this->repository->connectorSelections($this->queryUuid($r,'installation_id'))]);
            if($r->method==='POST')return Response::json(200,$this->service->selectConnector($this->json($r)));
        }
        if($r->method==='POST'&&$path==='/api/v1/memory')return Response::json(201,$this->service->createMemory($this->json($r)));
        if($r->method==='GET'&&$path==='/api/v1/memory/search')return Response::json(200,$this->service->searchMemory($this->scopeQuery($r),(string)($r->query['q']??''),(int)($r->query['limit']??10)));
        if($r->method==='PATCH'&&preg_match('#^/api/v1/memory/([0-9a-f-]{36})$#D',$path,$m)){$b=$this->json($r);$c=(string)($b['content']??'');if($c===''||strlen($c)>16384)throw new InvalidArgumentException('invalid_content');return Response::json(200,$this->repository->updateMemory($m[1],$c,DeterministicRetrieval::terms($c),DeterministicRetrieval::fakeVector($c),gmdate('Y-m-d\TH:i:s\Z')));}
        if($r->method==='DELETE'&&preg_match('#^/api/v1/memory/([0-9a-f-]{36})$#D',$path,$m)){$this->repository->deleteMemory($m[1],gmdate('Y-m-d\TH:i:s\Z'));return Response::json(200,['deleted'=>true]);}
        if($r->method==='POST'&&$path==='/api/v1/memory/rebuild')return Response::json(200,['rebuilt'=>$this->repository->rebuildMemories($this->json($r),gmdate('Y-m-d\TH:i:s\Z'))]);
        if($path==='/api/v1/relationships')return$r->method==='POST'?Response::json(201,$this->service->setRelationship($this->json($r))):Response::json(200,['items'=>$this->repository->relationships($this->scopeQuery($r))]);
        if($r->method==='POST'&&$path==='/api/v1/knowledge')return Response::json(201,$this->service->ingestKnowledge($this->json($r)));
        if($r->method==='GET'&&$path==='/api/v1/knowledge/search')return Response::json(200,$this->service->searchKnowledge($this->scopeQuery($r),(string)($r->query['q']??''),(int)($r->query['limit']??10)));
        if($r->method==='PATCH'&&preg_match('#^/api/v1/knowledge/([0-9a-f-]{36})$#D',$path,$m))return Response::json(200,$this->service->updateKnowledge($m[1],$this->json($r)));
        if($r->method==='DELETE'&&preg_match('#^/api/v1/knowledge/([0-9a-f-]{36})$#D',$path,$m)){$this->repository->deleteKnowledge($m[1],gmdate('Y-m-d\TH:i:s\Z'));return Response::json(200,['deleted'=>true]);}
        if($r->method==='POST'&&$path==='/api/v1/narratives/generate')return Response::json(202,$this->repository->enqueueDiaryGeneration($this->json($r)));
        if($path==='/api/v1/narratives')return$r->method==='POST'?Response::json(201,$this->service->createNarrative($this->json($r))):Response::json(200,['items'=>$this->repository->narratives($this->scopeQuery($r))]);
        if($r->method==='GET'&&$path==='/api/v1/playthrough-export')return Response::json(200,$this->service->exportPlaythrough($this->scopeQuery($r)));
        if($r->method==='POST'&&$path==='/api/v1/playthrough-restore')return Response::json(200,$this->service->restorePlaythrough($this->json($r)));
        if($r->method==='POST'&&$path==='/api/v1/operations/retention'){$b=$this->json($r);return Response::json(200,$this->repository->prune((int)($b['days']??30),gmdate('Y-m-d\TH:i:s\Z')));}
        if($r->method==='POST'&&$path==='/api/v1/pairing/rotate'){$b=$this->json($r);$installation=(string)($b['installation_id']??'');if(preg_match('/^[0-9a-f-]{36}$/D',$installation)!==1)throw new InvalidArgumentException('invalid_installation_id');$overlap=max(0,min(3600,(int)($b['overlap_seconds']??60)));return Response::json(201,$this->management->rotatePairingToken($installation,'',$overlap));}
        if($r->method==='POST'&&preg_match('#^/api/v1/pairing/([0-9a-f-]{36})/revoke$#D',$path,$m)){$this->management->revokePairingToken($m[1]);return Response::json(200,['revoked'=>true]);}
        throw new RuntimeException('not_found');
    }

    private function submit(string $domain,Request $r):Response
    {
        $v=$this->form($r);$scope=$this->scopeForm($v);
        if ($domain === 'narrator-prompt-save') {
            $revision = filter_var($v['expected_revision'] ?? null, FILTER_VALIDATE_INT);
            if ($revision === false || !is_string($v['custom_prompt'] ?? null)) throw new InvalidArgumentException('invalid_narrator_prompt');
            $result = $this->repository->saveNarratorEventPrompt(
                $scope['installation_id'] ?? throw new InvalidArgumentException('invalid_installation_id'),
                $this->need($v, 'prompt_key'), $v['custom_prompt'], $revision);
            return Response::json(200, ['ok' => true, 'revision' => (int)$result['current_revision']]);
        }
        if($domain==='global-settings-preset')return $this->namedGlobalSettingsPreset($v,$scope);
        if($domain==='core-profile-preset')return $this->namedCoreProfilePreset($v,$scope);
        if($domain==='playthrough-snapshot'){
            $operation=$this->need($v,'operation');
            try{
                if($operation==='create'){
                    $this->management->queueDatabaseBackup(false,['name'=>$this->need($v,'name'),'notes'=>$v['notes']??'']);$status='snapshot-save-queued';
                }elseif(in_array($operation,['copy','delete'],true)){
                    if(($v['confirm']??'')!==($operation==='copy'?'Copy':'Delete'))throw new InvalidArgumentException('confirmation_mismatch');
                    $id=$this->need($v,'backup_id');$this->uuid($id,'backup_id');$record=$this->repository->configurationBackupRecord($id);
                    if(!isset($record['scope']['snapshot']))throw new RuntimeException('not_found');
                    if($operation==='copy'){$this->management->queueDatabaseRestore($id);$status='snapshot-copy-queued';}
                    else{$this->management->deleteStoredDatabaseBackup($id,$this->providerConfig,'snapshot');$status='snapshot-deleted';}
                }else throw new InvalidArgumentException('invalid_snapshot_operation');
            }catch(RuntimeException $error){$status=match($error->getMessage()){'maintenance_busy'=>'snapshot-busy','snapshot_name_exists'=>'snapshot-name-exists','backup_restore_pending'=>'snapshot-protected','default_snapshot_protected'=>'snapshot-default-protected','backup_delete_failed'=>'snapshot-delete-failed',default=>throw $error};}
            return $this->redirect($this->webRoot().'/ui/playthrough_manager.php?'.http_build_query(['status'=>$status,'embed'=>($v['embed']??'')==='1'?'1':'0']));
        }
        if($domain==='database-backup-delete'){
            if(($v['confirm']??'')!=='Delete')throw new InvalidArgumentException('confirmation_mismatch');
            $status='backup-deleted';
            try{$this->management->deleteStoredDatabaseBackup($this->need($v,'backup_id'),$this->providerConfig);}
            catch(RuntimeException $error){$status=match($error->getMessage()){'maintenance_busy'=>'maintenance-busy','backup_restore_pending'=>'backup-restore-pending','backup_delete_failed'=>'backup-delete-failed',default=>throw $error};}
            return $this->redirect($this->webRoot().'/ui/database_manager.php?'.http_build_query(['status'=>$status,'embed'=>($v['embed']??'')==='1'?'1':'0']));
        }
        if($domain==='database-replay'){
            $version=filter_var($v['version']??null,FILTER_VALIDATE_INT);
            if($version===false||$version<1)throw new InvalidArgumentException('invalid_replay_version');
            if(($v['confirm']??'')!=='Replay '.$version)throw new InvalidArgumentException('confirmation_mismatch');
            try{$this->management->queueDatabaseReplay($version,$this->need($v,'fingerprint'));$status='replay-queued';}
            catch(RuntimeException $error){$status=match($error->getMessage()){'maintenance_busy'=>'maintenance-busy','replay_plan_changed'=>'replay-plan-changed',default=>throw $error};}
            return $this->redirect($this->webRoot().'/ui/database_manager.php?'.http_build_query(['status'=>$status,'embed'=>($v['embed']??'')==='1'?'1':'0']));
        }
        if($domain==='database-factory-reset'){
            if(($v['confirm']??'')!=='Factory Reset')throw new InvalidArgumentException('confirmation_mismatch');
            try{$this->management->queueDatabaseFactoryReset($this->need($v,'fingerprint'),$this->providerConfig);$status='factory-queued';}
            catch(RuntimeException $error){$status=$error->getMessage()==='maintenance_busy'?'maintenance-busy':'factory-unavailable';}
            return $this->redirect($this->webRoot().'/ui/database_manager.php?'.http_build_query(['status'=>$status,'embed'=>($v['embed']??'')==='1'?'1':'0']));
        }
        if($domain==='database-restore'){
            if(($v['confirm']??'')!=='Restore SQL')throw new InvalidArgumentException('confirmation_mismatch');
            try{$this->management->queueDatabaseRestore($this->need($v,'backup_id'));$status='restore-queued';}
            catch(RuntimeException $error){if($error->getMessage()!=='maintenance_busy')throw $error;$status='maintenance-busy';}
            return $this->redirect($this->webRoot().'/ui/database_manager.php?'.http_build_query(['status'=>$status,'embed'=>($v['embed']??'')==='1'?'1':'0']));
        }
        if($domain==='database-backup-settings'){
            $enabled=$v['enabled']??null;$max=isset($v['max_count'])?filter_var($v['max_count'],FILTER_VALIDATE_INT):null;
            if(($enabled!==null&&!in_array($enabled,['0','1'],true))||$max===false||($enabled===null&&$max===null))throw new InvalidArgumentException('invalid_backup_settings');
            $this->management->saveDatabaseBackupSettings($enabled===null?null:$enabled==='1',$max);
            return $this->redirect($this->webRoot().'/ui/database_manager.php?'.http_build_query(['status'=>'backup-settings-saved','embed'=>($v['embed']??'')==='1'?'1':'0']));
        }
        if($domain==='database-backup'){
            if(($v['confirm']??'')!=='Backup')throw new InvalidArgumentException('confirmation_mismatch');
            try{$this->management->queueDatabaseBackup();$status='backup-queued';}
            catch(RuntimeException $error){if($error->getMessage()!=='maintenance_busy')throw $error;$status='maintenance-busy';}
            return $this->redirect($this->webRoot().'/ui/database_manager.php?'.http_build_query(['status'=>$status,'embed'=>($v['embed']??'')==='1'?'1':'0']));
        }
        if($domain==='database-maintenance'){
            if(($v['confirm']??'')!=='Maintenance')throw new InvalidArgumentException('confirmation_mismatch');
            $status='maintenance-queued';
            try{$this->management->queueDatabaseMaintenance();}
            catch(RuntimeException $error){
                $status=match($error->getMessage()){
                    'maintenance_busy'=>'maintenance-busy',
                    'maintenance_permission_required'=>'maintenance-permission',
                    'maintenance_no_tables'=>'maintenance-empty',
                    'maintenance_failed'=>'maintenance-failed',
                    default=>throw $error,
                };
            }
            return $this->redirect($this->webRoot().'/ui/database_manager.php?'.http_build_query([
                'status'=>$status,'embed'=>($v['embed']??'')==='1'?'1':'0']));
        }
        if($domain==='relationship-preview'){
            foreach(['installation_id','profile_id','playthrough_id'] as $field)
                if(!isset($scope[$field]))throw new InvalidArgumentException('invalid_relationship_scope');
            $profile=$this->repository->getRevisioned('profile',$scope['profile_id']);
            if(($profile['installation_id']??null)!==$scope['installation_id'])throw new RuntimeException('not_found');
            $identity=$profile['actor_identity'];if(is_string($identity))$identity=json_decode($identity,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($identity)||array_is_list($identity)||!in_array($identity['kind']??'actor',['actor','npc','creature'],true))throw new InvalidArgumentException('profile_not_editable');
            $operation=$this->need($v,'operation');
            if($operation==='status')return Response::json(200,$this->repository->relationshipBuildPreviewStatus($scope,$this->need($v,'job_id')));
            if($operation!=='generate')throw new InvalidArgumentException('invalid_generation_operation');
            $request=$this->need($v,'request_id');$this->uuid($request,'request_id');
            $limit=filter_var($v['history_limit']??null,FILTER_VALIDATE_INT);
            if($limit===false||$limit<1||$limit>100)throw new InvalidArgumentException('invalid_relationship_build_request');
            return Response::json(202,$this->repository->enqueueRelationshipBuild($scope,$request,$limit,
                \LorkhanServer\Application\RelationshipBuildPolicy::direction($v['direction']??''),true));
        }
        if($domain==='player-speech-style-generate'){
            $installation=$this->need($v,'installation_id');$profileId=$this->need($v,'profile_id');
            $profile=$this->repository->getRevisioned('profile',$profileId);
            if(($profile['installation_id']??null)!==$installation)throw new RuntimeException('not_found');
            if(($v['operation']??'generate')==='status')return Response::json(200,$this->repository->playerSpeechStyleDraft($installation,$profileId,$this->need($v,'job_id')));
            if(($v['operation']??'generate')!=='generate')throw new InvalidArgumentException('invalid_generation_operation');
            return Response::json(202,$this->repository->enqueuePlayerSpeechStyleGeneration($profileId,$v['speech_style_guidance']??'',$v['current_speech_style']??null,$v['request_id']??null));
        }
        $content=$domain==='relationships'&&(!empty($v['actor_profile_id'])||!empty($v['relationship_id']))?[]:$this->jsonField($v,'content_json');
        if($domain==='autonomy')throw new RuntimeException('not_found');
        if($domain==='relationship-clear'){
            if(($v['confirm_clear']??null)!=='Clear')throw new InvalidArgumentException('confirmation_required');
            foreach(['installation_id','profile_id','playthrough_id']as$key)if(!isset($scope[$key]))throw new InvalidArgumentException('invalid_relationship_scope');
            $this->repository->clearRelationships($scope,$this->need($v,'snapshot_token'),gmdate('Y-m-d\TH:i:s\Z'));
            return $this->redirect($this->relationshipPageLocation($v,'relationships_cleared'));
        }
        if($domain==='relationship-history-build'){
            $request=$this->need($v,'request_id');$this->uuid($request,'request_id');
            $limit=filter_var($v['history_limit']??null,FILTER_VALIDATE_INT);
            if($limit===false||$limit<1||$limit>100)throw new InvalidArgumentException('invalid_relationship_build_request');
            try{
                $this->repository->enqueueRelationshipBuild($scope,$request,$limit,
                    \LorkhanServer\Application\RelationshipBuildPolicy::direction($v['direction']??''));
                $status='relationship_build_requested';
            }catch(InvalidArgumentException $error){
                $status=$error->getMessage();
                if(!in_array($status,['relationship_build_pending','relationship_build_locked','relationship_build_no_connector',
                    'relationship_build_no_history','relationship_build_ambiguous_owner','relationship_build_ambiguous_records',
                    'relationship_build_too_large','relationship_build_request_conflict'],true))throw $error;
            }
            return $this->redirect($this->relationshipPageLocation($v,$status).'#relationship-builder');
        }
        if($domain==='relationship-text-convert'){
            if(!hash_equals('Build',$this->need($v,'confirm')))throw new InvalidArgumentException('confirmation_mismatch');
            $request=$this->need($v,'request_id');$this->uuid($request,'request_id');
            $mode=$this->need($v,'mode');
            if(!in_array($mode,['missing','rebuild'],true))throw new InvalidArgumentException('invalid_relationship_conversion_request');
            $playthrough=$scope['playthrough_id']??throw new InvalidArgumentException('invalid_playthrough_id');
            try{$playthroughRow=$this->repository->getRevisioned('playthrough',$playthrough);}
            catch(RuntimeException $error){
                if($error->getMessage()!=='not_found')throw$error;
                throw new InvalidArgumentException('invalid_relationship_conversion_scope');
            }
            $scope=['installation_id'=>(string)$playthroughRow['installation_id'],'playthrough_id'=>$playthrough];
            try{
                $summary=$this->repository->enqueueRelationshipConversion($scope,$request,$mode);
                $status=$summary['queued']>0?'relationship_conversion_requested':'relationship_conversion_no_eligible';
            }catch(InvalidArgumentException $error){
                $status=$error->getMessage();$summary=[];
                if(!in_array($status,['relationship_conversion_request_conflict','relationship_conversion_too_large',
                    'relationship_conversion_too_many_owners','relationship_conversion_too_many_candidates',
                    'relationship_conversion_too_many_targets','relationship_conversion_ambiguous_records'],true))throw $error;
            }
            return $this->redirect($this->characterPageLocation($v,$status,$summary));
        }
        if($domain==='connector-test'){
            $detail=$this->testConnector($v);
            $target=(($v['kind']??'')==='stt_provider'?'stt-connectors':'tts-connectors');
            $joiner=str_contains($this->uiPath($target),'?')?'&':'?';
            return$this->redirect($this->uiPath($target).$joiner.http_build_query(['status'=>'tested','detail'=>$detail]));
        }
        if($domain==='provider-test'){
            $diagnostics=$this->htmlRequest($r)?null:[];
            try{$detail=$this->testProvider($v,$diagnostics);}catch(RuntimeException $error){
                if(!$this->htmlRequest($r))return Response::json(502,['error'=>'provider_test_failed','diagnostics'=>$diagnostics]);
                throw$error;
            }
            if(!$this->htmlRequest($r))return Response::json(200,['ok'=>true,'message'=>$detail,'diagnostics'=>$diagnostics]);
            return$this->redirect($this->uiPath('providers').'?'.http_build_query(['status'=>'tested','detail'=>$detail]));
        }
        if($domain==='provider-runtime-test'){
            $detail=$this->diagnoseProvider(ProviderFactory::dialogue($this->providerConfig));
            return$this->redirect($this->uiPath('providers').'?'.http_build_query(['status'=>'tested','detail'=>$detail]));
        }
        if($domain==='player-profile-create'&&$this->repository->playerProfileForInstallation($scope['installation_id'])!==null){
            throw new InvalidArgumentException('player_profile_already_exists');
        }
        if($domain==='narrator-profile-create'&&$this->repository->narratorProfileForInstallation($scope['installation_id'])!==null){
            throw new InvalidArgumentException('narrator_profile_already_exists');
        }
        if($domain==='description-import'){
            $saved=$this->service->importItemDescriptions($scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),$this->descriptionCsvRows($r));
            return$this->redirect($this->descriptionPageLocation($scope['installation_id'],'imported',count($saved)));
        }
        if($domain==='biography-template-create'){
            $row=[];foreach(self::BIOGRAPHY_CSV_HEADER as$field)$row[$field]=trim((string)($v[$field]??''));
            $saved=$this->service->importBiographyTemplates($scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),[$row]);
            return$this->redirect($this->biographyPageLocation($v,'imported',count($saved)));
        }
        if($domain==='biography-import'){
            $saved=$this->service->importBiographyTemplates($scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),$this->biographyCsvRows($r));
            return$this->redirect($this->biographyPageLocation($v,'imported',count($saved)));
        }
        if($domain==='biography-reset'){
            if(($v['confirm']??'')!=='Reset')throw new InvalidArgumentException('confirmation_required');
            $count=$this->repository->resetBiographyTemplates($scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),gmdate('c'));
            return$this->redirect($this->biographyPageLocation($v,'reset',$count));
        }
        if($domain==='knowledge-import'){
            $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');$inputs=[];
            foreach($this->oghmaCsvRows($r)as$row)$inputs[]=['installation_id'=>$installation]+$row+['provenance'=>['source'=>'management-csv','category'=>$row['category']]];
            $saved=$this->service->importKnowledge($inputs);
            return$this->redirect($this->uiPath('knowledge').'&status=imported&count='.count($saved));
        }
        if(in_array($domain,['oghma-dynamic-save','oghma-dynamic-import','oghma-dynamic-delete'],true)){
            $installation=$this->need($scope,'installation_id');$rules=$this->repository->dynamicOghma();
            if($domain==='oghma-dynamic-delete'){
                if(($v['confirm']??'')!=='Delete')throw new InvalidArgumentException('oghma_confirmation_required');
                $mode=$this->need($v,'mode');if(!in_array($mode,['single','all'],true))throw new InvalidArgumentException('invalid_dynamic_oghma_action');
                $id=$mode==='single'?$this->need($v,'id'):'';if($id!=='')$this->uuid($id,'id');
                $revision=filter_var($v['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if($id!==''&&$revision===false)throw new InvalidArgumentException('invalid_dynamic_oghma_revision');
                $count=$rules->delete($installation,$id===''?null:$id,$revision===false?null:$revision);$status='dynamic-deleted';
            }else{
                $csv=$domain==='oghma-dynamic-import';$count=$rules->save($installation,$csv?$this->oghmaCsvRows($r,true):[$v],$csv);$status=$csv?'dynamic-imported':'dynamic-saved';
            }
            return$this->redirect($this->webRoot().'/ui/worldknowledge_upload.php?'.http_build_query(['installation_id'=>$installation,'embed'=>($v['embed']??'')==='1'?'1':'0','tab'=>'dynamic','status'=>$status,'count'=>$count]).'#dynamic');
        }
        if($domain==='oghma-maintenance'){
            $installation=$this->need($scope,'installation_id');$this->uuid($installation,'installation_id');
            $action=$this->need($v,'action');
            if(!in_array($action,['delete-all','delete-entry','factory-reset'],true)||($v['confirm']??'')!==($action==='factory-reset'?'Reset':'Delete'))throw new InvalidArgumentException('oghma_confirmation_required');
            $document=$action==='delete-entry'?$this->need($v,'document_id'):null;if($document!==null)$this->uuid($document,'document_id');
            $count=($this->oghmaCatalogImporter??throw new RuntimeException('not_found'))->maintainInstallation($installation,$action,$document);
            return$this->redirect($this->webRoot().'/ui/worldknowledge_upload.php?'.http_build_query(['installation_id'=>$installation,'embed'=>($v['embed']??'')==='1'?'1':'0','status'=>$action==='factory-reset'?'factory-reset':'deleted','count'=>$count]));
        }
        if($domain==='oghma-factory-sync'){
            $result=$this->syncBundledOghmaCatalog();$query=['status'=>'factory-synced','count'=>(int)($result['row_count']??0)];
            if(isset($scope['installation_id']))$query['installation_id']=$scope['installation_id'];
            if(($v['embed']??'')==='1')$query['embed']='1';
            return$this->redirect($this->webRoot().'/ui/worldknowledge_upload.php?'.http_build_query($query));
        }
        $memoryReturnScope=in_array($domain,['memory-revise','memory-delete'],true)
            ?$this->repository->memory($this->need($v,'memory_id')):$scope;
        $result=match($domain){
            'profiles'=>$this->service->createRevisioned('profile',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$content]),
            'profile-create'=>$this->createNpcProfile($v,$scope),
            'profile-template-create'=>$this->service->createRevisioned('profile',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'actor_identity'=>$this->templateIdentity($v),'content'=>$this->profileContent($v)]),
            'profile-revise'=>$this->reviseNpcProfile($v),
            'profile-reset-biography'=>$this->resetNpcBiography($v),
            'quickstart-save'=>$this->saveQuickstart($v,$scope),
            'profile-toggle-favorite'=>$this->toggleNpcProfileManagement($v,'favorite'),
            'profile-toggle-lock'=>$this->toggleNpcProfileManagement($v,'locked'),
            'profile-import'=>$this->service->createRevisioned('profile',['installation_id'=>$scope['installation_id']]+$this->profileImportDocument($v)),
            'profile-clone'=>$this->cloneProfile($v),
            'core-profile-create'=>$this->createCoreProfile($v,$scope),
            'core-profile-clone'=>$this->cloneCoreProfile($v),
            'core-profile-settings-import'=>$this->importCoreProfileSettings($v,$scope),
            'core-profile-save'=>$this->saveCoreProfile($v),
            'core-profile-revise'=>$this->service->revise('core_profile',$this->need($v,'core_profile_id'),$this->coreProfileContent($v),$this->need($v,'change_reason')),
            'core-profile-default'=>$this->makeDefaultCoreProfile($v),
            'core-profile-rollback'=>$this->service->rollback('core_profile',$this->need($v,'core_profile_id'),(int)($v['revision']??0),'management rollback'),
            'core-profile-delete'=>$this->service->deleteRevisioned('core_profile',$this->need($v,'core_profile_id')),
            'player-profile-create'=>$this->service->createRevisioned('profile',['installation_id'=>$scope['installation_id'],
                'name'=>$this->need($v,'name'),'actor_identity'=>$this->playerIdentity($v),'content'=>$this->playerContent($v)]),
            'player-profile-revise'=>$this->service->revisePlayer($this->need($v,'installation_id'),$this->need($v,'profile_id'),$this->need($v,'name'),$this->playerContent($v),$this->need($v,'change_reason'),(int)($v['expected_revision']??0)),
            'player-profile-settings-import'=>$this->importSpecialProfileSettings($v,$scope,'player'),
            'narrator-profile-create'=>$this->service->createRevisioned('profile',['installation_id'=>$scope['installation_id'],
                'name'=>$this->need($v,'name'),'actor_identity'=>$this->narratorIdentity($v),'content'=>$this->narratorContent($v)]
                +(trim((string)($v['core_profile_id']??''))===''?[]:['core_profile_id'=>$v['core_profile_id']])),
            'narrator-profile-revise'=>$this->service->reviseNarrator($this->need($v,'installation_id'),$this->need($v,'profile_id'),$this->need($v,'name'),$this->narratorContent($v),$this->need($v,'change_reason'),trim((string)($v['core_profile_id']??'')),(int)($v['expected_revision']??0)),
            'narrator-profile-settings-import'=>$this->importSpecialProfileSettings($v,$scope,'narrator'),
            'narrator-profile-generate'=>$this->repository->enqueueNarratorProfileGeneration($this->need($v,'profile_id')),
            'global-settings-save'=>$this->saveGlobalSettings($v,$scope),
            'global-settings-import'=>$this->importGlobalSettings($v,$scope),
            'global-settings-rollback'=>$this->rollbackGlobalSettings($v,$scope),
            'memory-policy'=>$this->saveMemoryPolicy($v,$scope),
            'memory-summarize'=>$this->requestMemorySummary($v,$scope),
            'memory-embedding-policy'=>$this->saveMemoryEmbeddingPolicy($v,$scope),
            'memory-embedding-backfill'=>$this->requestMemoryEmbeddingBackfill($v,$scope),
            'profile-biography-revise'=>$this->reviseNpcProfile($v),
            'biography-template-revise'=>$this->repository->saveBiographyTemplate($v),
            'profile-rollback'=>$this->service->rollback('profile',$this->need($v,'profile_id'),(int)($v['revision']??0),'management rollback'),
            'profile-delete'=>$this->service->deleteRevisioned('profile',$this->need($v,'profile_id')),
            'profile-generate'=>$this->repository->enqueueProfileGeneration($this->need($v,'profile_id')),
            'profile-bulk-generate'=>$this->bulkGenerateProfiles($v,$scope),
            'profile-bulk-unlock'=>$this->bulkUnlockProfiles($v,$scope),
            'profile-bulk-delete'=>$this->bulkDeleteProfiles($v,$scope),
            'profile-bulk-switch'=>$this->bulkSwitchProfiles($v,$scope),
            'profile-auto-lock'=>$this->repository->setProfileAutoLock($scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),isset($v['enabled']),gmdate('Y-m-d\TH:i:s\Z')),
            'providers'=>$this->service->createRevisioned('provider',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$this->providerFormContent($v)]),
            'provider-revise'=>$this->reviseConnectorForm('provider',$v,$this->providerFormContent($v)),
            'provider-rollback'=>$this->service->rollback('provider',$this->need($v,'configuration_id'),(int)($v['revision']??0),'management rollback'),
            'provider-delete'=>$this->service->deleteRevisioned('provider',$this->need($v,'configuration_id')),
            'provider-clone'=>$this->cloneProvider($v),
            'provider-import'=>$this->importProvider($v,$scope),
            'tts-providers'=>$this->service->createRevisioned('tts_provider',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$this->connectorFormContent($v,'tts_provider')]),
            'stt-providers'=>$this->service->createRevisioned('stt_provider',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$this->connectorFormContent($v,'stt_provider')]),
            'connector-revise'=>$this->reviseConnectorForm($this->need($v,'kind'),$v,$this->connectorFormContent($v,$this->need($v,'kind'))),
            'connector-rollback'=>$this->service->rollback($this->need($v,'kind'),$this->need($v,'configuration_id'),(int)($v['revision']??0),'management rollback'),
            'connector-delete'=>$this->service->deleteRevisioned($this->need($v,'kind'),$this->need($v,'configuration_id')),
            'connector-selection'=>$this->service->selectConnector(['installation_id'=>$scope['installation_id'],'kind'=>$this->need($v,'kind'),'configuration_id'=>$this->need($v,'configuration_id')]),
            'connector-default-voice'=>$this->reviseConnectorDefaultVoice($v),
            'connector-clone'=>$this->cloneConnector($v),
            'connector-import'=>$this->importConnector($v,$scope),
            'description-save'=>$this->service->saveItemDescription(['installation_id'=>$scope['installation_id'],'content_file'=>$this->need($v,'content_file'),'record_id'=>$this->need($v,'record_id'),'display_name'=>(string)($v['display_name']??''),'description'=>(string)($v['description']??'')]),
            'description-delete'=>$this->service->deleteItemDescription($this->need($v,'description_id'),$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id')),
            'description-reset'=>$this->resetDescriptions($v,$scope),
            'prompts'=>$this->service->createRevisioned('prompt',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$this->promptFormContent($v,$content,true)]),
            'prompt-clone'=>$this->clonePrompt($v),
            'prompt-import'=>$this->importPrompt($v,$scope),
            'action-policies'=>$this->service->createRevisioned('action_policy',['installation_id'=>$scope['installation_id'],'profile_id'=>$scope['profile_id']??null,'name'=>$this->need($v,'name'),'content'=>$content]),
            'action-policy-controls-create'=>$this->service->createRevisioned('action_policy',['installation_id'=>$scope['installation_id'],'profile_id'=>$scope['profile_id']??null,'name'=>$this->need($v,'name'),'content'=>$this->actionPolicyFormContent($v)]),
            'action-policy-controls-revise'=>$this->service->revise('action_policy',$this->need($v,'configuration_id'),$this->actionPolicyFormContent($v),$this->need($v,'change_reason')),
            'configuration-revise'=>$this->reviseConfiguration($v,$content),
            'configuration-rollback'=>$this->service->rollback($this->configurationKind($v),$this->need($v,'configuration_id'),(int)($v['revision']??0),'management rollback'),
            'configuration-delete'=>$this->service->deleteRevisioned($this->configurationKind($v),$this->need($v,'configuration_id')),
            'playthroughs'=>$this->service->createRevisioned('playthrough',['installation_id'=>$scope['installation_id'],'profile_id'=>$scope['profile_id'],'name'=>$this->need($v,'name'),'content'=>$content]),
            'playthrough-import'=>$this->restorePlaythroughState($v,$scope),
            'memory'=>$this->service->createMemory($scope+['tier'=>$v['tier']??'recent','content'=>$this->need($v,'content'),'provenance'=>['source'=>$this->need($v,'provenance')]]),
            'memory-revise'=>$this->reviseMemory($v),
            'memory-delete'=>$this->repository->deleteMemory($this->need($v,'memory_id'),gmdate('Y-m-d\TH:i:s\Z')),
            'memory-rebuild'=>$this->repository->rebuildMemories($scope,gmdate('Y-m-d\TH:i:s\Z')),
            'relationships'=>$this->saveRelationship($v,$scope,$content),
            'relationship-delete'=>$this->service->deleteRelationship($this->need($v,'relationship_id'),$this->relationshipRevision($v)),
            'knowledge'=>$this->service->ingestKnowledge($scope+$this->knowledgeFormInput($v)),
            'knowledge-revise'=>$this->service->updateKnowledge($this->need($v,'document_id'),$this->knowledgeFormInput($v)),
            'knowledge-delete'=>$this->deleteKnowledgeDocument($v),
            'narratives'=>$this->service->createNarrative($scope+['kind'=>$v['kind']??'narrator','title'=>$this->need($v,'title'),'content'=>$this->need($v,'content'),'provenance'=>['source'=>$this->need($v,'provenance')]]),
            'narrative-revise'=>$this->service->updateNarrative($this->need($v,'narrative_id'),['kind'=>$v['kind']??'narrator','title'=>$this->need($v,'title'),'content'=>$this->need($v,'content'),'provenance'=>['source'=>$this->need($v,'provenance')]]),
            'narrative-delete'=>$this->deleteNarrativeDocument($v),
            'narrative-generate'=>$this->repository->enqueueDiaryGeneration($scope+['request_id'=>trim((string)($v['request_id']??''))?:Uuid::v4()]),
            'configuration-backup'=>$this->createConfigurationBackup($v,$scope),
            'configuration-restore'=>$this->restoreConfigurationBackup($v,$scope),
            'retention'=>$this->repository->prune((int)($v['days']??30),gmdate('Y-m-d\TH:i:s\Z')),
            default=>throw new RuntimeException('not_found')};
        if(in_array($domain,['memory','memory-revise','memory-delete','memory-rebuild'],true)) {
            return$this->redirect($this->uiPath('memory').'&'.http_build_query(['status'=>'saved',
                'installation_id'=>$memoryReturnScope['installation_id'],'playthrough_id'=>$memoryReturnScope['playthrough_id']]));
        }
        if($domain==='profile-bulk-switch'&&!$this->htmlRequest($r))return Response::json(200,['ok'=>true]+$result);
        if($domain==='provider-revise'&&!$this->htmlRequest($r))return Response::json(200,['ok'=>true]);
        if($domain==='narrator-profile-settings-import'&&!$this->htmlRequest($r))return Response::json(200,['ok'=>true]);
        if($domain==='configuration-revise'&&($v['prompt_text_editor']??'')==='1'&&!$this->htmlRequest($r))
            return Response::json(200,['ok'=>true,'revision'=>(int)$result['current_revision']]);
        if($domain==='global-settings-save')return$this->redirect($this->uiPath('world').'&status=saved');
        if(in_array($domain,['global-settings-import','global-settings-rollback'],true))return$this->redirect(
            $this->globalSettingsPageLocation($v,$domain==='global-settings-import'?'imported':'rolled-back'));
        if(in_array($domain,['memory-policy','memory-summarize'],true)){
            $query=['status'=>$domain==='memory-policy'?'saved':'summary-requested',
                'policy_installation_id'=>$scope['installation_id']];
            if($domain==='memory-summarize'&&($result['state']??'')==='dead')$query['status']='summary-failed';
            return $this->redirect($this->uiPath('memory').'&'.http_build_query($query));
        }
        if(in_array($domain,['memory-embedding-policy','memory-embedding-backfill'],true)){
            $query=['status'=>$domain==='memory-embedding-policy'?'embedding-saved':
                (($result['queued']??0)>0?'embeddings-queued':'embedding-backfill-empty'),
                'policy_installation_id'=>$scope['installation_id'],'queued'=>(int)($result['queued']??0)];
            return$this->redirect($this->uiPath('memory').'&'.http_build_query($query));
        }
        if(in_array($domain,['relationships','relationship-delete'],true))return $this->redirect($this->relationshipPageLocation($v,'saved'));
        if($domain==='narrative-generate')return$this->redirect($this->uiPath('narrative-autonomy').'?status=diary-requested');
        if($domain==='quickstart-save')return$this->redirect($this->webRoot().'/ui/quickstart.php?'.http_build_query(['installation_id'=>$scope['installation_id'],'core_profile_id'=>$this->need($v,'core_profile_id'),'status'=>'saved']));
        if(in_array($domain,['narrator-profile-create','narrator-profile-revise','narrator-profile-generate','narrator-profile-settings-import'],true)&&($v['embed']??'')==='1')
            return$this->redirect($this->webRoot().'/ui/narrator_management.php?'.http_build_query([
                'status'=>$domain==='narrator-profile-settings-import'?'imported':'saved','embed'=>'1','installation_id'=>$scope['installation_id']]));
        if(in_array($domain,['player-profile-create','player-profile-revise'],true)&&($v['embed']??'')==='1')
            return$this->redirect($this->uiPath('player').'?'.http_build_query(['status'=>'saved','embed'=>'1','installation_id'=>$scope['installation_id']]));
        if($domain==='profile-reset-biography')return$this->redirect($this->characterPageLocation($v,'saved'));
        if($domain==='profile-revise'&&trim((string)($v['npc_relationship_edits']??''))!==''){
            $batch=$this->jsonField($v,'npc_relationship_edits');
            return$this->redirect($this->relationshipPageLocation(array_replace($v,['relationship_page'=>'npc','playthrough_id'=>$batch['playthrough_id']]),'npc_relationships_saved'));
        }
        if($domain==='core-profile-save')return$this->redirect($this->webRoot().'/ui/core/core_profiles.php?'.http_build_query(['edit'=>$this->need($v,'core_profile_id'),'status'=>'saved']));
        if($domain==='core-profile-clone')return$this->redirect($this->webRoot().'/ui/core/core_profiles.php?'.http_build_query(['edit'=>(string)$result['core_profile_id'],'status'=>'cloned']));
        if($domain==='core-profile-settings-import')return$this->redirect($this->uiPath('profiles').'?'.http_build_query([
            'installation_id'=>$scope['installation_id'],'edit'=>(string)$result['core_profile_id'],'status'=>'imported']));
        if($domain==='player-profile-settings-import')return$this->redirect($this->uiPath('player').'?'.http_build_query([
            'installation_id'=>$scope['installation_id'],'status'=>'imported']));
        if($domain==='narrator-profile-settings-import')return$this->redirect($this->uiPath('narrator').'&'.http_build_query([
            'installation_id'=>$scope['installation_id'],'status'=>'imported']));
        if($domain==='connector-default-voice')return$this->redirect($this->uiPath('tts-studio').'?'.http_build_query(['configuration_id'=>$this->need($v,'configuration_id'),'status'=>'saved']));
        if(in_array($domain,['description-save','description-delete','description-reset'],true))return$this->redirect(
            $this->descriptionPageLocation($scope['installation_id']??(string)($v['installation_id']??''),'saved'));
        if(in_array($domain,['profile-import','profile-clone','profile-create','profile-revise','profile-toggle-favorite',
            'profile-toggle-lock','profile-rollback','profile-delete','profile-generate','profile-bulk-generate',
            'profile-bulk-unlock','profile-bulk-delete','profile-bulk-switch','profile-auto-lock'],true))
            return$this->redirect($this->characterPageLocation($v,'saved'));
        // Keep connector form actions inside their Configuration iframe and reopen the saved connector.
        if(in_array($domain,['providers','provider-revise','provider-rollback','provider-delete','provider-clone','provider-import',
            'tts-providers','stt-providers','connector-revise','connector-rollback','connector-delete','connector-clone','connector-import','connector-selection'],true)
            &&($v['embed']??null)==='1'){
            $query=['status'=>'saved','embed'=>'1'];
            if(($v['partial']??null)==='editor')$query['partial']='editor';
            $returnInstallation=$result['installation_id']??$scope['installation_id']??null;
            if(is_string($returnInstallation))$query['installation_id']=$returnInstallation;
            if(!in_array($domain,['provider-delete','connector-delete'],true)&&is_string($result['configuration_id']??null))$query['edit']=$result['configuration_id'];
            $page=str_starts_with($domain,'provider')?'providers'
                :($domain==='stt-providers'||($v['kind']??null)==='stt_provider'?'stt-connectors':'tts-connectors');
            return$this->redirect(explode('?',$this->uiPath($page),2)[0].'?'.http_build_query($query));
        }
        $target=match($domain){'prompts','prompt-clone','prompt-import'=>'prompts-actions','action-policies','action-policy-controls-create','action-policy-controls-revise'=>'action-editor','configuration-revise','configuration-rollback','configuration-delete'=>(($v['kind']??'')==='action_policy'?'action-editor':'prompts-actions'),'narratives','narrative-revise','narrative-delete','narrative-generate'=>'narrative-autonomy','configuration-backup','configuration-restore'=>'database-manager','retention'=>'backup-health','providers','provider-revise','provider-rollback','provider-delete','provider-clone','provider-import'=>'providers','tts-providers'=>'tts-connectors','stt-providers'=>'stt-connectors','connector-default-voice'=>'tts-studio','connector-selection','connector-revise','connector-rollback','connector-delete','connector-clone','connector-import'=>(($v['kind']??'')==='stt_provider'?'stt-connectors':'tts-connectors'),'core-profile-create','core-profile-revise','core-profile-default','core-profile-rollback','core-profile-delete'=>'profiles','profile-import','profile-clone','profile-create','profile-revise','profile-toggle-favorite','profile-toggle-lock','profile-rollback','profile-delete','profile-generate','profile-bulk-generate','profile-bulk-unlock','profile-bulk-delete','profile-bulk-switch','profile-auto-lock'=>'characters','player-profile-create','player-profile-revise','player-speech-style-generate'=>'player','narrator-profile-create','narrator-profile-revise','narrator-profile-generate'=>'narrator','profile-biography-revise','biography-template-revise','biography-import'=>'npc-biographies','description-save','description-delete','description-reset'=>'descriptions','memory-revise','memory-delete','memory-rebuild'=>'memory','relationship-delete'=>'relationships','knowledge','knowledge-revise','knowledge-delete'=>'knowledge','playthroughs','playthrough-import'=>'playthrough-form',default=>$domain};
        $joiner=str_contains($this->uiPath($target),'?')?'&':'?';
        return$this->redirect($this->uiPath($target).$joiner.'status=saved');
    }

    private function page(string $slug,Request $r):Response
    {
        $csrf=BrowserSession::parseCsrf($r->header('Cookie'))??'';$title=match($slug){'quickstart'=>'LORKHAN Dashboard','control-panel'=>'Control Panel',default=>ucwords(str_replace('-',' ',$slug))};$body=isset($r->query['status'])?'<p role="status">Changes saved.</p>':'';
        $scope=$this->fields(['installation_id'=>'Installation ID','profile_id'=>'Profile ID','playthrough_id'=>'Playthrough ID']);
        $body.=match($slug){
            'quickstart'=>$this->quickstart(),
            'roleplay'=>$this->hubHtml([
                ['characters','Characters','Create and manage TES3 character identities.'],
                ['profiles','Profiles','Configure roleplay profiles and revisions.'],
                ['memory','Memory','Create and review durable memories.'],
                ['relationships','Relationships','Manage actor disposition and affinity.'],
                ['world','World','Add Morrowind world information.'],
                ['knowledge','Knowledge','Manage scoped knowledge records.'],
                ['narrative-autonomy','Narrative','Configure narrator, diary, and summary records.'],
            ]),
            'configuration'=>$this->hubHtml([
                ['providers','Providers','Configure deterministic and live provider presets.'],
                ['ai-voice','AI & Voice','Configure LLM, TTS, and installation-global STT connectors.'],
                ['prompts-actions','Prompts & Actions','Manage prompts and negotiated action policies.'],
            ]),
            'control-panel'=>$this->hubHtml([
                ['traces','Events & Traces','Inspect scoped operational traces.'],
                ['playthroughs','Playthroughs','Create and manage playthrough state.'],
                ['jobs','Workers & Jobs','Review queue and worker health.'],
                ['backup-health','Operations','Run bounded retention operations.'],
                ['diagnostics','Diagnostics','Review server health and the action catalog.'],
            ]),
            'characters'=>$this->formHtml('profiles','Create a TES3 character',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Character name','content_json'=>'Character profile JSON'],true)),
            'profiles'=>$this->formHtml('profiles','Create profile',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Name','content_json'=>'Profile JSON'],true)),
            'providers'=>$this->formHtml('providers','Create deterministic mock provider',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Name','content_json'=>'Provider JSON'],true)),
            'ai-voice'=>$this->formHtml('providers','Create LLM or TTS connector preset',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Connector name','content_json'=>'Connector JSON'],true)),
            'prompts-actions'=>$this->formHtml('prompts','Create prompt',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Name','content_json'=>'Prompt JSON'],true)).$this->formHtml('action-policies','Create action policy',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Name','content_json'=>'Policy JSON'],true)),
            'world'=>$this->formHtml('knowledge','Add Morrowind world knowledge',$csrf,$scope.$this->input('title','Title').$this->area('content','Knowledge').$this->input('provenance','Provenance source')),
            'playthroughs'=>$this->formHtml('playthroughs','Create playthrough',$csrf,$this->fields(['installation_id'=>'Installation ID','profile_id'=>'Profile ID','name'=>'Name','content_json'=>'Playthrough JSON'],true)),
            'memory'=>$this->formHtml('memory','Create memory',$csrf,$scope.$this->select('tier','Tier',['recent','mid','long']).$this->area('content','Memory').$this->input('provenance','Provenance source')),
            'relationships'=>$this->formHtml('relationships','Save relationship',$csrf,$scope.$this->area('content_json','Actor identity JSON','{}').$this->input('disposition','Disposition','number','0').$this->input('affinity','Affinity','number','0').$this->input('reason','Reason','text','management')),
            'knowledge'=>$this->formHtml('knowledge','Create knowledge',$csrf,$scope.$this->input('title','Title').$this->area('content','Knowledge').$this->input('provenance','Provenance source')),
            'narrative-autonomy'=>$this->formHtml('narratives','Create narrative',$csrf,$scope.$this->select('kind','Narrative kind',['narrator','diary','summary']).$this->input('title','Title').$this->area('content','Narrative').$this->input('provenance','Provenance source')).'<section class="feature-status"><h2>Automatic diaries <span class="status-badge">Live</span></h2><p>Timer, sleep, and optional wait events queue diaries for eligible Player, Narrator, and nearby NPC profiles.</p></section>',
            'traces'=>'<section><h2>Events and traces</h2><p>Use the authenticated traces API with installation scope. Provider and prompt details remain redacted.</p></section>',
            'jobs'=>'<section><h2>Workers and jobs</h2><p>Queue and dead-letter counts are shown in diagnostics. Worker leases and retries are bounded.</p></section>',
            'backup-health'=>$this->formHtml('retention','Run bounded retention',$csrf,$this->input('days','Retention days','number','30').'<p>This removes expired operational metadata and never accepts a filesystem path.</p>'),
            'diagnostics'=>$this->quickstart().$this->actionCatalog(),
            default=>''};
        return$this->html(200,(new ManagementView($this->basePath))->page($title,$slug,$body));
    }

    private function quickstart():string
    {
        $d=$this->repository->diagnostics();$counts=$d['counts'];
        return'<div class="dashboard-container">'
            .'<section class="widget"><div class="widget-header"><h3>Current Playthrough</h3></div><div class="widget-content"><table class="widget-table"><caption>World Information</caption><thead><tr><th>State</th><th>Value</th></tr></thead><tbody>'
            .'<tr><td>Server</td><td><span class="server-online">Online</span></td></tr>'
            .'<tr><td>Database</td><td>'.$this->e((string)$d['database']['version']).'</td></tr>'
            .'<tr><td>Installations</td><td>'.$counts['installations'].'</td></tr>'
            .'<tr><td>Active Sessions</td><td>'.$counts['active_sessions'].'</td></tr>'
            .'<tr><td>Provider Mode</td><td>Deterministic mock</td></tr></tbody></table></div></section>'
            .'<section class="widget"><div class="widget-header"><h3>Getting Started</h3></div><div class="widget-content"><ol class="test-path">'
            .'<li><strong>Pair the client</strong><span>Import the native profile and keep the MAC key outside Lua.</span></li>'
            .'<li><strong>Create a profile</strong><span>Bind the Morrowind identity and playthrough state.</span></li>'
            .'<li><strong>Start OpenMW</strong><span>Load LORKHAN and open the in-game chat.</span></li>'
            .'<li><strong>Test dialogue</strong><span>Select an NPC and verify text, speech, and actions.</span></li>'
            .'</ol></div></section>'
            .'<section class="widget"><div class="widget-header"><h3>LORKHAN Stats</h3></div><div class="widget-content widget-stats">'
            .'<div class="stat-card"><span class="stat-value">'.$counts['memory_records'].'</span><span class="stat-label">Memories</span></div>'
            .'<div class="stat-card"><span class="stat-value">'.$counts['queued_jobs'].'</span><span class="stat-label">Queued Jobs</span></div>'
            .'<div class="stat-card"><span class="stat-value">'.$counts['dead_jobs'].'</span><span class="stat-label">Dead Jobs</span></div>'
            .'<div class="stat-card"><span class="stat-value">'.$counts['installations'].'</span><span class="stat-label">Clients</span></div>'
            .'</div></section></div>';
    }
    private function hubHtml(array $items):string{$html='<div class="options-container">';foreach($items as[$slug,$title,$description]){$html.='<a class="option-card" href="'.$this->basePath.'/'.$slug.'"><h2>'.$this->e($title).'</h2><p>'.$this->e($description).'</p></a>';}$html.='</div>';return$html;}
    private function formHtml(string $route,string $legend,string $csrf,string $fields):string{return'<form method="post" action="'.$this->basePath.'/forms/'.$route.'"><fieldset><legend>'.$this->e($legend).'</legend>'.$fields.'</fieldset><input type="hidden" name="_csrf" value="'.$this->e($csrf).'"><button type="submit">'.$this->e($legend).'</button></form>';}
    private function fields(array $items,bool $json=false):string{$s='';foreach($items as$n=>$l)$s.=$json&&$n==='content_json'?$this->area($n,$l,'{}'):$this->input($n,$l);return$s;}
    private function input(string $n,string $l,string $type='text',string $value=''):string{$id='f-'.$n;return'<label for="'.$id.'">'.$this->e($l).'</label><input id="'.$id.'" name="'.$n.'" type="'.$type.'" value="'.$this->e($value).'" required>';}
    private function area(string $n,string $l,string $value=''):string{$id='f-'.$n;return'<label for="'.$id.'">'.$this->e($l).'</label><textarea id="'.$id.'" name="'.$n.'" required>'.$this->e($value).'</textarea>';}
    private function select(string $n,string $l,array $values):string{$id='f-'.$n;$o='';foreach($values as$v)$o.='<option value="'.$this->e($v).'">'.$this->e(ucwords($v)).'</option>';return'<label for="'.$id.'">'.$this->e($l).'</label><select id="'.$id.'" name="'.$n.'">'.$o.'</select>';}

    /** Parse one bounded UTF-8 CHIM-format CSV upload without partially importing malformed rows. */
    private function descriptionCsvRows(Request $request):array
    {
        $file=$request->files['csv_file']??null;
        if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('description_csv_missing');
        $size=(int)($file['size']??0);if($size<1||$size>$this->maxJsonBytes)throw new InvalidArgumentException('description_csv_size');
        if(strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION))!=='csv')throw new InvalidArgumentException('description_csv_type');
        $handle=fopen((string)$file['tmp_name'],'rb');if($handle===false)throw new InvalidArgumentException('description_csv_unreadable');
        try{
            $header=fgetcsv($handle,65536,',','"','\\');if(!is_array($header))throw new InvalidArgumentException('description_csv_header');
            if(isset($header[0]))$header[0]=preg_replace('/^\xEF\xBB\xBF/', '',(string)$header[0])??(string)$header[0];
            $header=array_map(static fn(mixed$value):string=>strtolower(trim((string)$value)),$header);
            if($header!==['plugin','baseid','name','description'])throw new InvalidArgumentException('description_csv_header');
            $rows=[];
            while(($values=fgetcsv($handle,65536,',','"','\\'))!==false){
                if($values===[null]||count($values)===0)continue;if(count($values)!==4)throw new InvalidArgumentException('description_csv_columns');
                if(!mb_check_encoding(implode('',array_map('strval',$values)),'UTF-8'))throw new InvalidArgumentException('description_csv_encoding');
                $rows[]=['plugin'=>(string)$values[0],'baseid'=>(string)$values[1],'name'=>(string)$values[2],'description'=>(string)$values[3]];
                if(count($rows)>5000)throw new InvalidArgumentException('description_csv_rows');
            }
            return$rows;
        }finally{fclose($handle);}
    }

    /** Download an exact CHIM-compatible example without exposing installation identifiers. */
    private function exampleDescriptionsCsv():Response
    {
        return$this->csvResponse('example_descriptions.csv',[
            ['plugin'=>'Morrowind.esm','baseid'=>'iron dagger','name'=>'Iron Dagger','description'=>'A short iron blade with a plain crossguard and a leather-wrapped grip.'],
            ['plugin'=>'Morrowind.esm','baseid'=>'common_shirt_01','name'=>'Common Shirt','description'=>'A loose woven shirt with simple seams, muted cloth, and a narrow collar.'],
        ]);
    }

    private function exportDescriptionsCsv(string $installationId):Response
    {
        return$this->csvResponse('custom_descriptions_export_'.gmdate('Y-m-d_H-i-s').'.csv',$this->repository->customItemDescriptions($installationId));
    }

    /** Parse one bounded LORKHAN biography CSV before any profile revision is written. */
    private function biographyCsvRows(Request $request):array
    {
        $file=$request->files['csv_file']??null;
        if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('biography_csv_missing');
        $size=(int)($file['size']??0);if($size<1||$size>$this->maxJsonBytes)throw new InvalidArgumentException('biography_csv_size');
        if(strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION))!=='csv')throw new InvalidArgumentException('biography_csv_type');
        $handle=fopen((string)$file['tmp_name'],'rb');if($handle===false)throw new InvalidArgumentException('biography_csv_unreadable');
        try{
            $header=fgetcsv($handle,131072,',','"','\\');if(!is_array($header))throw new InvalidArgumentException('biography_csv_header');
            if(isset($header[0]))$header[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$header[0])??(string)$header[0];
            $header=array_map(static fn(mixed$value):string=>strtolower(trim((string)$value)),$header);
            if($header!==self::BIOGRAPHY_CSV_HEADER&&$header!==[...self::BIOGRAPHY_CSV_HEADER,'scope'])throw new InvalidArgumentException('biography_csv_header');
            $rows=[];
            while(($values=fgetcsv($handle,131072,',','"','\\'))!==false){
                if($values===[null]||count($values)===0)continue;
                if(count($values)!==count($header))throw new InvalidArgumentException('biography_csv_columns');
                if(!mb_check_encoding(implode('',array_map('strval',$values)),'UTF-8'))throw new InvalidArgumentException('biography_csv_encoding');
                $rows[]=array_combine($header,array_map('strval',$values));
                if(count($rows)>1000)throw new InvalidArgumentException('biography_csv_rows');
            }
        }finally{fclose($handle);}
        if($rows===[])throw new InvalidArgumentException('biography_csv_empty');return$rows;
    }

    private function exampleBiographiesCsv():Response
    {
        return$this->biographyCsvResponse('example_biographies.csv',[[
            'content_file'=>'Morrowind.esm','record_id'=>'fargoth','name'=>'Fargoth',
            'core'=>'A nervous Bosmer commoner who wants to recover his missing possessions.',
            'biography'=>'Fargoth lives in Seyda Neen and has had trouble with the local guards.',
            'appearance'=>'A slight Bosmer wearing common clothes.','personality'=>'Nervous, friendly, and grateful.',
            'relationships'=>'{}','occupation'=>'Commoner','skills'=>'Sneaking and light commerce.',
            'speech_style'=>'Hesitant and earnest.','goals'=>'Recover what was taken and stay out of trouble.',
            'oghma_tags'=>'Seyda Neen, Bosmer','voice_id'=>'','gender'=>'Male','race'=>'Wood Elf',
        ]]);
    }

    private function exportBiographiesCsv(string $installationId):Response
    {
        return$this->biographyCsvResponse('custom_biographies_export_'.gmdate('Y-m-d_H-i-s').'.csv',
            $this->repository->customBiographyTemplates($installationId),true);
    }

    /** Encode a round-trip-safe UTF-8 biography CSV in the exact LORKHAN field order. */
    private function biographyCsvResponse(string $filename,array $rows,bool $includeScope=false):Response
    {
        $stream=fopen('php://temp','w+b');if($stream===false)throw new RuntimeException('csv_unavailable');
        $header=$includeScope?[...self::BIOGRAPHY_CSV_HEADER,'scope']:self::BIOGRAPHY_CSV_HEADER;
        fwrite($stream,"\xEF\xBB\xBF");fputcsv($stream,$header,',','"','\\');
        foreach($rows as$row)fputcsv($stream,array_map(static fn(string$field):string=>(string)($row[$field]??''),$header),',','"','\\');
        rewind($stream);$body=stream_get_contents($stream);fclose($stream);if(!is_string($body))throw new RuntimeException('csv_unavailable');
        return new Response(200,$body,['Content-Type'=>'text/csv; charset=utf-8','Content-Disposition'=>'attachment; filename="'.$filename.'"','X-Content-Type-Options'=>'nosniff']);
    }

    /** Encode spreadsheet-safe UTF-8 CSV with the established CHIM column order. */
    private function csvResponse(string $filename,array $rows):Response
    {
        $stream=fopen('php://temp','w+b');if($stream===false)throw new RuntimeException('csv_unavailable');
        fwrite($stream,"\xEF\xBB\xBF");fputcsv($stream,['plugin','baseid','name','description'],',','"','\\');
        foreach($rows as$row)fputcsv($stream,[(string)($row['plugin']??''),(string)($row['baseid']??''),(string)($row['name']??''),(string)($row['description']??'')],',','"','\\');
        rewind($stream);$body=stream_get_contents($stream);fclose($stream);if(!is_string($body))throw new RuntimeException('csv_unavailable');
        return new Response(200,$body,['Content-Type'=>'text/csv; charset=utf-8','Content-Disposition'=>'attachment; filename="'.$filename.'"','X-Content-Type-Options'=>'nosniff']);
    }

    /** Parse one bounded UTF-8 CSV using CHIM's exact static Oghma column order. */
    private function oghmaCsvRows(Request $request,bool $dynamic=false):array
    {
        $file=$request->files['csv_file']??null;if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('oghma_csv_missing');
        $size=(int)($file['size']??0);if($size<1||$size>$this->maxJsonBytes)throw new InvalidArgumentException('oghma_csv_size');
        if(strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION))!=='csv')throw new InvalidArgumentException('oghma_csv_type');
        $handle=fopen((string)$file['tmp_name'],'rb');if($handle===false)throw new InvalidArgumentException('oghma_csv_unreadable');$rows=[];
        try{$header=fgetcsv($handle,131072,',','"','\\');if(!is_array($header))throw new InvalidArgumentException('oghma_csv_header');
            if(isset($header[0]))$header[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$header[0]);
            $expected=$dynamic?\LorkhanServer\Infrastructure\DynamicOghmaRepository::CSV_FIELDS:['topic','topic_desc','knowledge_class','topic_desc_basic','knowledge_class_basic','tags','category','aliases'];
            if($header!==$expected)throw new InvalidArgumentException('oghma_csv_header');
            while(($values=fgetcsv($handle,131072,',','"','\\'))!==false){if($values===[null]||count($values)===0)continue;if(count($values)!==count($expected))throw new InvalidArgumentException('oghma_csv_columns');
                if(!mb_check_encoding(implode('',array_map('strval',$values)),'UTF-8'))throw new InvalidArgumentException('oghma_csv_encoding');
                $rows[]=$dynamic?array_combine($expected,array_map('strval',$values)):['topic'=>(string)$values[0],'title'=>str_replace('_',' ',(string)$values[0]),'content'=>(string)$values[1],
                    'knowledge_class'=>(string)$values[2],'topic_desc_basic'=>(string)$values[3],'knowledge_class_basic'=>(string)$values[4],
                    'tags'=>(string)$values[5],'category'=>(string)$values[6],'aliases'=>(string)$values[7]];
                if(count($rows)>($dynamic?1000:5000))throw new InvalidArgumentException('oghma_csv_rows');}
        }finally{fclose($handle);}if($rows===[])throw new InvalidArgumentException('oghma_csv_empty');return$rows;
    }

    private function exampleOghmaCsv(bool $dynamic=false):Response
    {
        $stream=fopen('php://temp','w+b');if($stream===false)throw new RuntimeException('csv_unavailable');fwrite($stream,"\xEF\xBB\xBF");
        fputcsv($stream,$dynamic?\LorkhanServer\Infrastructure\DynamicOghmaRepository::CSV_FIELDS:['topic','topic_desc','knowledge_class','topic_desc_basic','knowledge_class_basic','tags','category','aliases'],',','"','\\');
        fputcsv($stream,$dynamic?['a1_1_findspymaster','10','caius_cosades','Caius has received the package.','','','','','main_quest']:['vivec_city','Advanced article.','scholar,dunmer','Basic article.','common','Vivec,Cantons','settlements','Vivec City'],',','"','\\');
        rewind($stream);$body=stream_get_contents($stream);fclose($stream);return new Response(200,(string)$body,['Content-Type'=>'text/csv; charset=utf-8','Content-Disposition'=>'attachment; filename="example_oghma.csv"','X-Content-Type-Options'=>'nosniff']);
    }

    private function resetDescriptions(array $values,array $scope):int
    {
        if(!hash_equals('Reset',$this->need($values,'confirm')))throw new InvalidArgumentException('confirmation_mismatch');
        return$this->service->resetItemDescriptions($scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'));
    }

    private function descriptionPageLocation(string $installationId,string $status,int $count=0):string
    {
        $query=['installation_id'=>$installationId,'status'=>$status];if($count>0)$query['count']=$count;
        return$this->uiPath('descriptions').'?'.http_build_query($query);
    }

    private function biographyPageLocation(array $values,string $status,int $count=0):string
    {
        $query=['installation_id'=>$this->need($values,'installation_id'),'status'=>$status];
        if($count>0)$query['count']=$count;if(($values['embed']??null)==='1')$query['embed']='1';
        return$this->webRoot().'/ui/core/npc_biographies.php?'.http_build_query($query);
    }

    /** Return to the selected Global Settings document without nesting the configuration hub inside its iframe. */
    private function globalSettingsPageLocation(array $values,string $status):string
    {
        $query=['installation_id'=>$this->need($values,'installation_id'),'status'=>$status];
        if(($values['embed']??null)==='1')$query['embed']='1';
        return$this->webRoot().'/ui/global_settings.php?'.http_build_query($query);
    }

    private function authenticatedSession(Request $r):?string{$t=BrowserSession::parse($r->header('Cookie'));return$t!==null&&$this->management->validate($t)?$t:null;}
    /** Start a local browser session transparently so the UI stays open while form writes remain CSRF-protected. */
    private function openBrowserSession(string $target):Response{$c=$this->management->createSession($this->sessionTtl);return$this->redirect($target,['Set-Cookie'=>[BrowserSession::cookie($c['session'],$this->sessionTtl,$this->webRoot()),BrowserSession::csrfCookie($c['csrf'],$this->sessionTtl,$this->webRoot())],'X-CSRF-Token'=>$c['csrf']]);}
    private function csrf(Request $r,string $session):void{$v=$this->form($r);$t=$r->header('X-CSRF-Token')??($v['_csrf']??null);if(!is_string($t)||!$this->management->validate($session,$t))throw new RuntimeException('unauthorized');}
    private function json(Request $r):array{if(strlen($r->body)>$this->maxJsonBytes)throw new InvalidArgumentException('payload_too_large');try{$v=json_decode($r->body,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new InvalidArgumentException('invalid_json');}if(!is_array($v)||array_is_list($v))throw new InvalidArgumentException('invalid_json');return$v;}
    private function form(Request $r):array{if($r->form!==[])return$r->form;if(strlen($r->body)>$this->maxJsonBytes)throw new InvalidArgumentException('payload_too_large');parse_str($r->body,$v);return is_array($v)?$v:[];}
    private function scopeQuery(Request $r):array{return['installation_id'=>$this->queryUuid($r,'installation_id'),'profile_id'=>$this->queryUuid($r,'profile_id'),'playthrough_id'=>$this->queryUuid($r,'playthrough_id')];}
    private function scopeForm(array $v):array{$out=[];foreach(['installation_id','profile_id','playthrough_id']as$k)if(isset($v[$k])){$value=trim((string)$v[$k]);if($value==='')continue;$this->uuid($value,$k);$out[$k]=$value;}return$out;}
    private function queryUuid(Request $r,string $k):string{$v=(string)($r->query[$k]??'');$this->uuid($v,$k);return$v;}
    private function uuid(string $v,string $k):void{if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$v)!==1)throw new InvalidArgumentException('invalid_'.$k);}
    /** Accept the legacy deterministic UUID shape used by persisted Core Profiles. */
    private function persistentUuid(string $v,string $k):void{if(preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D',$v)!==1)throw new InvalidArgumentException('invalid_'.$k);}
    private function jsonField(array $v,string $k):array{try{$d=json_decode((string)($v[$k]??'{}'),true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new InvalidArgumentException('invalid_'.$k);}if(!is_array($d)||($d!==[]&&array_is_list($d)))throw new InvalidArgumentException('invalid_'.$k);return$d;}
    private function need(array $v,string $k):string{$s=trim((string)($v[$k]??''));if($s==='')throw new InvalidArgumentException('invalid_'.$k);return$s;}
    private function path(string $p):string{if(!str_starts_with($p,$this->basePath))throw new RuntimeException('not_found');$v=substr($p,strlen($this->basePath));return$v===''?'/':$v;}
    private function singular(string $v):string{return match($v){'profiles'=>'profile','core-profiles'=>'core_profile','playthroughs'=>'playthrough','prompts'=>'prompt','providers'=>'provider','tts-providers'=>'tts_provider','stt-providers'=>'stt_provider','action-policies'=>'action_policy'};}

    /** Convert the labelled connector form into the strict revisioned connector document. */
    /** Old CLI/form clients may omit Name; current editors save it with the connector revision. */
    private function reviseConnectorForm(string $kind,array $values,array $content):array
    {
        $id=$this->need($values,'configuration_id');$reason=$this->need($values,'change_reason');
        return array_key_exists('name',$values)
            ?$this->service->reviseNamedConnector($kind,$id,$this->need($values,'name'),$content,$reason)
            :$this->service->revise($kind,$id,$content,$reason);
    }

    private function connectorFormContent(array $values,string $kind):array
    {
        if(!in_array($kind,['tts_provider','stt_provider'],true))throw new InvalidArgumentException('invalid_connector_kind');
        $driver=$this->need($values,'driver');$options=$this->jsonField($values,'options_json');
        foreach(['fallback_male','fallback_female']as$field){
            if(!array_key_exists($field,$values))continue;$value=trim((string)$values[$field]);
            if($value==='')unset($options[$field]);else{
                if(strlen($value)>512||!mb_check_encoding($value,'UTF-8'))throw new InvalidArgumentException('invalid_connector_'.$field);
                $options[$field]=$value;
            }
        }
        if(isset($values['option_fields_present'])){
            foreach(ConnectorCatalog::all($kind)as$definition)foreach(ConnectorCatalog::optionFields($kind,(string)$definition['driver'])as$field)unset($options[(string)$field['name']]);
        }
        if(isset($values['option_fields_present']))foreach(ConnectorCatalog::optionFields($kind,$driver)as$field){
            $name=(string)$field['name'];$key='option__'.$name;$type=(string)$field['type'];
            if($type==='multiselect'){
                $selected=$values[$key]??[];
                if(!is_array($selected)||!array_is_list($selected)||count($selected)>count($field['values']))throw new InvalidArgumentException('invalid_connector_option_'.$name);
                foreach($selected as$value)if(!is_string($value)||!in_array($value,$field['values'],true))throw new InvalidArgumentException('invalid_connector_option_'.$name);
                $options[$name]=array_values(array_unique($selected));continue;
            }
            if($type==='boolean'){
                $boolean=filter_var($values[$key]??false,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
                if($boolean===null)throw new InvalidArgumentException('invalid_connector_option_'.$name);
                $options[$name]=$boolean;continue;
            }
            if(!array_key_exists($key,$values))continue;$raw=trim((string)$values[$key]);if($raw===''){unset($options[$name]);continue;}
            if($type==='select'){if(!in_array($raw,$field['values'],true))throw new InvalidArgumentException('invalid_connector_option_'.$name);$options[$name]=$raw;continue;}
            if($type==='integer'||$type==='number'){$valid=filter_var($raw,$type==='integer'?FILTER_VALIDATE_INT:FILTER_VALIDATE_FLOAT);
                if($valid===false||$valid<$field['minimum']||$valid>$field['maximum'])throw new InvalidArgumentException('invalid_connector_option_'.$name);
                $options[$name]=$type==='integer'?(int)$valid:(float)$valid;continue;}
            if(strlen($raw)>($field['maxlength']??512)||!mb_check_encoding($raw,'UTF-8'))throw new InvalidArgumentException('invalid_connector_option_'.$name);$options[$name]=$raw;
        }
        $content=['driver'=>$driver,'endpoint'=>$this->need($values,'endpoint'),
            'model'=>trim((string)($values['model']??'')),'voice'=>trim((string)($values['voice']??'')),
            'language'=>trim((string)($values['language']??'en')),'timeout_ms'=>(int)($values['timeout_ms']??30000),
            'options'=>$options];
        if ($kind === 'tts_provider' && $driver === 'zonos_gradio' && array_key_exists('cached_voice_path', $values)) {
            $root=(string)($this->providerConfig['voice_storage_path']??'');
            $scope=\LorkhanServer\Application\ZonosGradioSpeechProvider::cacheScope($root,$content['endpoint'],$content['voice']);
            if ($scope === '' && $values['cached_voice_path'] !== '') throw new InvalidArgumentException('zonos_cache_requires_voice_sample');
            // A draft from another endpoint/sample must never redirect this voice's cached upload.
            if ($scope !== '' && ($values['cached_voice_scope']??'') === $scope) {
                $path=$values['cached_voice_path'];
                $edit=\LorkhanServer\Application\ZonosGradioSpeechProvider::validateCacheOverride(['scope'=>$scope,'path'=>$path,'edit_id'=>bin2hex(random_bytes(16))]);
                $savedOptions=isset($values['configuration_id'])
                    ?($this->repository->getRevisioned($kind,$this->need($values,'configuration_id'))['content']['options']??[]):$options;
                $current=\LorkhanServer\Application\ZonosGradioSpeechProvider::cachedVoicePath($root,$content['endpoint'],$content['voice'],$savedOptions);
                if ($path !== $current) $content['options']['cached_voice_override']=$edit;
                elseif (isset($savedOptions['cached_voice_override'])) $content['options']['cached_voice_override']=$savedOptions['cached_voice_override'];
            }
        }
        if(array_key_exists('credential',$values))$content['credential']=$values['credential'];
        return $content;
    }

    /** Convert the labelled LLM model-slot form into the strict server-owned provider document. */
    private function providerFormContent(array $values):array
    {
        $driver=$this->need($values,'driver');
        $model=$driver==='openai-compatible'&&($values['service']??'')==='player2'?'':$this->need($values,'model');
        if($driver==='mock')return['driver'=>'mock','model'=>$model,'mock_prefix'=>trim((string)($values['mock_prefix']??''))];
        $content=['driver'=>$driver,'model'=>$model];
        if($driver==='configured'&&isset($values['credential'])&&$values['credential']!=='__inherit__')$content['credential']=$values['credential'];
        if($driver==='openai-compatible')$content+=['endpoint'=>$this->need($values,'endpoint'),
            'credential'=>$values['credential']??'none'];
        if($driver==='openai-compatible'&&isset($values['service'])&&$values['service']!=='')$content['service']=$values['service'];
        if(isset($values['timeout_ms'])&&$values['timeout_ms']!==''){
            $timeout=filter_var($values['timeout_ms'],FILTER_VALIDATE_INT);
            if($timeout===false)throw new InvalidArgumentException('invalid_provider_timeout');
            $content['timeout_ms']=$timeout;
        }
        $options=[];
        foreach(LlmConnector::OPTION_RULES as$name=>$rule){
            $key='option_'.$name;if(!array_key_exists($key,$values)||($values[$key]===''&&$rule['type']!=='yaml'))continue;
            $raw=$values[$key];
            if($rule['type']==='yaml'){
                if(!is_string($raw))throw new InvalidArgumentException('invalid_provider_body_yaml');
                if($raw===''&&($values['body_yaml_present']??'1')==='0')continue;
                $options[$name]=$raw;
            }elseif($rule['type']==='boolean'){
                if(!in_array($raw,['true','false'],true))throw new InvalidArgumentException('invalid_provider_option_'.$name);
                $options[$name]=$raw==='true';
            }elseif($rule['type']==='string-list'){
                if(!is_string($raw))throw new InvalidArgumentException('invalid_provider_option_'.$name);
                $options[$name]=trim($raw)===''?[]:array_map('trim',explode(',',$raw));
            }else{
                $value=filter_var($raw,$rule['type']==='integer'?FILTER_VALIDATE_INT:FILTER_VALIDATE_FLOAT);
                if($value===false)throw new InvalidArgumentException('invalid_provider_option_'.$name);
                $options[$name]=$value;
            }
        }
        if($options!==[])$content['options']=$options;
        return LlmConnector::validate($content);
    }

    /** Restrict generic revision controls to the two JSON-backed management editors. */
    private function configurationKind(array $values):string
    {
        $kind=$this->need($values,'kind');
        if(!in_array($kind,['prompt','action_policy'],true))throw new InvalidArgumentException('invalid_configuration_kind');
        return$kind;
    }

    /** Apply labelled player-mood controls to Prompt Manager revisions. */
    private function reviseConfiguration(array $values,array $content):array
    {
        $kind=$this->configurationKind($values);
        if($kind==='prompt'&&($values['prompt_text_editor']??'')==='1'){
            $id=$this->need($values,'configuration_id');$current=$this->repository->getRevisioned('prompt',$id);
            $text=$this->repository->promptText($id);$custom=$values['custom_prompt']??null;
            $revision=filter_var($values['expected_revision']??null,FILTER_VALIDATE_INT);
            if($revision===false||$revision<1||!is_string($custom)||strlen($custom)>65536||!mb_check_encoding($custom,'UTF-8'))
                throw new InvalidArgumentException('invalid_prompt_editor');
            $content=$current['content'];$content['default_prompt']=(string)$text['default_prompt'];
            $content['custom_prompt']=trim($custom)===''?null:$custom;
            $content['instruction']=$content['custom_prompt']??$content['default_prompt'];
            $content=$this->promptFormContent($values,$content);
            return$this->service->revise('prompt',$id,$content,'management custom prompt',(int)$revision);
        }
        if($kind==='prompt')$content=$this->promptFormContent($values,$content);
        return$this->service->revise($kind,$this->need($values,'configuration_id'),$content,$this->need($values,'change_reason'));
    }

    /** Store labelled player-mood controls with the revisioned prompt document. */
    private function promptFormContent(array $values,array $content,bool $creating=false):array
    {
        unset($content['format']);
        if($creating&&array_key_exists('prompt_instruction',$values)){
            $instruction=$values['prompt_instruction'];
            if(!is_string($instruction)||trim($instruction)===''||strlen($instruction)>65536||!mb_check_encoding($instruction,'UTF-8'))
                throw new InvalidArgumentException('invalid_prompt_instruction');
            $content['instruction']=$instruction;$content['default_prompt']=$instruction;$content['custom_prompt']=null;
        }
        $defaults=PlayerMoodPolicy::defaultTemplates();$current=$content['player_mood_prompts']??$defaults;
        if(!is_array($current)||array_is_list($current))$current=$defaults;
        $hasMoodFields=false;$templates=[];
        foreach($defaults as$key=>$default){$field='player_mood_prompt_'.$key;
            if(array_key_exists($field,$values))$hasMoodFields=true;
            $templates[$key]=array_key_exists($field,$values)?(string)$values[$field]:(string)($current[$key]??$default);}
        if($hasMoodFields)$content['player_mood_prompts']=PlayerMoodPolicy::validateTemplates($templates);
        return$content;
    }

    /** Convert labelled action toggles into a complete policy over the immutable OpenMW action catalog. */
    private function actionPolicyFormContent(array $values):array
    {
        $tier=(string)($values['max_tier']??'');if(preg_match('/^[0-3]$/D',$tier)!==1)throw new InvalidArgumentException('invalid_max_tier');
        $selected=$values['allowed_actions']??[];if(!is_array($selected)||!array_is_list($selected)||count($selected)>128)throw new InvalidArgumentException('invalid_allowed_actions');
        $definitions=$this->repository->actionCatalogDefinitions();$known=array_column($definitions,'name');$enabled=[];
        foreach($selected as$name){if(!is_string($name)||!in_array($name,$known,true)||isset($enabled[$name]))throw new InvalidArgumentException('invalid_allowed_actions');$enabled[$name]=true;}
        $actions=[];foreach($definitions as$definition)$actions[$definition['name']]=$this->herikaActionRow($definition,isset($enabled[$definition['name']]));
        return['enabled'=>isset($values['enabled']),'max_tier'=>(int)$tier,'actions'=>$actions];
    }

    /** Materialize one immutable catalog definition as the complete Herika-compatible saved row. */
    private function herikaActionRow(array $definition,bool $enabled):array
    {
        return[
            'code_name'=>$definition['code_name'],'action_name'=>$definition['action_name'],
            'description'=>$definition['description'],'return_message'=>$definition['return_message'],
            'available_to_npc'=>$definition['available_to_npc'],
            'available_to_followers'=>$definition['available_to_followers'],
            'available_to_narrator'=>$definition['available_to_narrator'],'is_activated'=>$enabled,
            'parameters_json'=>$definition['parameters_json'],'metadata'=>$definition['metadata'],
            'game_function'=>$definition['game_function'],'import_version'=>$definition['import_version'],
            'script_proxy_program'=>$definition['script_proxy_program'],
        ];
    }

    /** Return one exact policy scope and the immutable OpenMW contracts used to edit it. */
    private function actionPolicyEditor(Request $request):array
    {
        $installation=$this->queryUuid($request,'installation_id');
        $profile=trim((string)($request->query['profile_id']??''));
        if($profile==='')$profile=null;else$this->uuid($profile,'profile_id');
        return['catalog'=>$this->repository->actionCatalogDefinitions(),
            'policies'=>$this->repository->actionPoliciesForEditor($installation,$profile)];
    }

    /** Create or atomically revise the policy document produced by the compact editor. */
    private function saveActionPolicyRevision(array $values):array
    {
        $keys=array_keys($values);sort($keys);$expected=['actions','change_reason','configuration_id','enabled',
            'expected_revision','installation_id','max_tier','profile_id'];sort($expected);
        $legacyExpected=$expected;$legacyExpected[]='name';sort($legacyExpected);
        if(($keys!==$expected&&$keys!==$legacyExpected)||!is_bool($values['enabled']??null)||!is_int($values['max_tier']??null)
            ||!is_array($values['actions']??null)||($values['actions']!==[]&&array_is_list($values['actions'])))
            throw new InvalidArgumentException('invalid_action_policy_editor');
        $installation=(string)$values['installation_id'];$this->uuid($installation,'installation_id');
        $profile=$values['profile_id'];
        if($profile!==null){if(!is_string($profile))throw new InvalidArgumentException('invalid_profile_id');$this->uuid($profile,'profile_id');}
        $configuration=$values['configuration_id'];$revision=$values['expected_revision'];
        if(($configuration===null)!==($revision===null))throw new InvalidArgumentException('invalid_expected_revision');
        $content=['enabled'=>$values['enabled'],'max_tier'=>$values['max_tier'],'actions'=>$values['actions']];
        // Clearing the last override restores inheritance; the stored policy's action map is optional.
        if($values['actions']===[])unset($content['actions']);
        $reason=$this->need($values,'change_reason');$name=$profile===null?'Action configuration':'NPC action override';
        if($configuration===null)return$this->service->createRevisioned('action_policy',['installation_id'=>$installation,
            'profile_id'=>$profile,'name'=>$name,'content'=>$content,'change_reason'=>$reason]);
        if(!is_string($configuration)||!is_int($revision))throw new InvalidArgumentException('invalid_expected_revision');
        $this->uuid($configuration,'configuration_id');$current=$this->repository->getRevisioned('action_policy',$configuration);
        if((string)$current['installation_id']!==$installation||($current['profile_id']??null)!==$profile)
            throw new InvalidArgumentException('action_policy_scope_mismatch');
        return$this->service->revise('action_policy',$configuration,$content,$reason,$revision);
    }

    /** Validate the document identifier before applying the repository's soft delete. */
    private function deleteKnowledgeDocument(array $values):void
    {
        $id=$this->need($values,'document_id');$this->uuid($id,'document_id');
        $this->repository->deleteKnowledge($id,gmdate('Y-m-d\TH:i:s\Z'));
    }

    /** Validate the narrative identifier before applying its soft delete. */
    private function deleteNarrativeDocument(array $values):void
    {
        $id=$this->need($values,'narrative_id');$this->uuid($id,'narrative_id');
        $this->repository->deleteNarrative($id,gmdate('Y-m-d\TH:i:s\Z'));
    }

    /** Download one portable NPC profile without installation IDs, revisions, bindings, or connector secrets. */
    private function exportProfile(string $profileId):Response
    {
        $this->uuid($profileId,'profile_id');$row=$this->repository->getRevisioned('profile',$profileId);
        $identity=$row['actor_identity']??[];
        if(is_string($identity)){
            try{$identity=json_decode($identity,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('not_found');}
        }
        if(!is_array($identity)||array_is_list($identity)||in_array($identity['kind']??'actor',['player','narrator'],true))throw new RuntimeException('not_found');
        $content=is_array($row['content']??null)?$row['content']:[];
        unset($content['portrait'],$content['routing'],$content['settings_overrides']);
        $document=['schema'=>'lorkhan.profile-export.v1','exported_at'=>gmdate('Y-m-d\TH:i:s\Z'),'name'=>(string)$row['name'],
            'actor_identity'=>$identity===[]?(object)[]:$identity,'content'=>$content===[]?(object)[]:$content];
        $filename=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$row['name']),'-_.');if($filename==='')$filename='lorkhan-profile';
        return new Response(200,json_encode($document,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",
             ['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="'.$filename.'.json"','X-Content-Type-Options'=>'nosniff']);
    }

    /** Clone an NPC profile in place without copying actor bindings or profile-owned portrait files. */
    private function cloneProfile(array $values):array
    {
        $profileId=$this->need($values,'profile_id');$this->uuid($profileId,'profile_id');
        $row=$this->repository->getRevisioned('profile',$profileId);$identity=$row['actor_identity']??[];
        if(is_string($identity))$identity=json_decode($identity,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($identity)||array_is_list($identity)||in_array($identity['kind']??'actor',['player','narrator'],true))
            throw new InvalidArgumentException('profile_not_cloneable');
        $name=$this->need($values,'name');
        if(strlen($name)>256||!mb_check_encoding($name,'UTF-8'))throw new InvalidArgumentException('invalid_name');
        $content=is_array($row['content']??null)?$row['content']:[];
        unset($content['portrait'],$content['routing'],$content['settings_overrides']);
        return$this->service->createRevisioned('profile',['installation_id'=>(string)$row['installation_id'],
            'name'=>$name,'actor_identity'=>$identity,'core_profile_id'=>(string)$row['core_profile_id'],'content'=>$content]);
    }

    /** Duplicate one Core Profile without changing slots, defaults, or existing NPC assignments. */
    private function cloneCoreProfile(array $values):array
    {
        $id=$this->need($values,'core_profile_id');$this->persistentUuid($id,'core_profile_id');
        $profile=$this->repository->getRevisioned('core_profile',$id);
        $name=trim((string)($values['name']??(mb_strcut((string)$profile['name'],0,123,'UTF-8').' copy')));
        if($name===''||strlen($name)>128||!mb_check_encoding($name,'UTF-8'))throw new InvalidArgumentException('invalid_name');
        return$this->service->createRevisioned('core_profile',[
            'installation_id'=>(string)$profile['installation_id'],'name'=>$name,'slot'=>null,'default_npc'=>false,
            'content'=>EffectiveSettingsResolver::validateCoreProfile($profile['content']),
        ]);
    }

    /** Download only the validated settings overrides from one Core Profile. */
    private function exportCoreProfileSettings(string $coreProfileId):Response
    {
        $this->uuid($coreProfileId,'core_profile_id');$row=$this->repository->getRevisioned('core_profile',$coreProfileId);
        $content=EffectiveSettingsResolver::validateCoreProfile(is_array($row['content']??null)?$row['content']:[]);
        $overrides=$this->portableCoreProfileOverrides($content['settings_overrides']);
        if($this->containsSecretKey($overrides))throw new RuntimeException('core_profile_settings_export_rejected');
        $document=['schema'=>'lorkhan.core-profile-settings.v2','exported_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'name'=>(string)$row['name'],'settings_overrides'=>$overrides===[]?(object)[]:$overrides];
        $filename=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$row['name']),'-_.');
        if($filename==='')$filename='lorkhan-core-profile';
        return new Response(200,json_encode($document,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",
            ['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="'.$filename.'-settings.json"','X-Content-Type-Options'=>'nosniff']);
    }

    /** Import a strict settings-only preset as a new unassigned Core Profile. */
    private function importCoreProfileSettings(array $values,array $scope):array
    {
        $document=$this->jsonField($values,'preset_json');$keys=array_keys($document);sort($keys);
        if($keys!==['exported_at','name','schema','settings_overrides']
            ||!in_array($document['schema']??null,['lorkhan.core-profile-settings.v1','lorkhan.core-profile-settings.v2'],true)
            ||!is_string($document['exported_at']??null)||strlen($document['exported_at'])>64
            ||!$this->objectArray($document['settings_overrides']??null)||$this->containsSecretKey($document))
            throw new InvalidArgumentException('invalid_core_profile_settings_preset');
        $name=trim((string)($document['name']??''));
        if($name===''||strlen($name)>128||!mb_check_encoding($name,'UTF-8'))throw new InvalidArgumentException('invalid_core_profile_settings_preset');
        $overrides=$this->portableCoreProfileOverrides($document['settings_overrides']);
        return$this->service->createRevisioned('core_profile',[
            'installation_id'=>$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),
            'name'=>$name,'default_npc'=>false,'slot'=>null,
            'content'=>['schema'=>'lorkhan.core-profile.v1','prompt'=>'','routing'=>[],'settings_overrides'=>$overrides],
        ]);
    }

    /** Keep portable Core Profile presets limited to explicit response, history, and diary behavior. */
    private function portableCoreProfileOverrides(array $overrides):array
    {
        $overrides=EffectiveSettingsResolver::validateSettingsOverrides($overrides);$diary=DiaryGenerationPolicy::defaults();
        return (isset($overrides['quest_comments']) ? ['quest_comments'=>$overrides['quest_comments']] : [])
            + (isset($overrides['bored_event']) ? ['bored_event'=>$overrides['bored_event']] : [])
            + (isset($overrides['rpg_comments']) ? ['rpg_comments'=>$overrides['rpg_comments']] : [])
            + (isset($overrides['oghma']) ? ['oghma'=>$overrides['oghma']] : [])
            + (isset($overrides['context']) ? ['context'=>$overrides['context']] : [])
            + (isset($overrides['prompt']) ? ['prompt'=>$overrides['prompt']] : [])
            + (isset($overrides['relationship']) ? ['relationship'=>array_intersect_key($overrides['relationship'],array_flip(['enabled','update_chance_percent']))] : [])
            + (isset($overrides['profile_evolution']) ? ['profile_evolution'=>$overrides['profile_evolution']] : []) + ['response'=>['max_words'=>(int)($overrides['response']['max_words']??0),'core_lang'=>(string)($overrides['response']['core_lang']??''),'lang_llm_xtts'=>($overrides['response']['lang_llm_xtts']??false)===true],
            'behavior'=>['rechat'=>($overrides['behavior']['rechat']??false)===true,
            'rechat_max_depth'=>(int)($overrides['behavior']['rechat_max_depth']??2),
            'rechat_probability_percent'=>(int)($overrides['behavior']['rechat_probability_percent']??50),
            'rechat_allow_actions'=>($overrides['behavior']['rechat_allow_actions']??false)===true]
                + array_intersect_key($overrides['behavior']??[],array_flip(['combat_bark_period_seconds','rechat_mode','open_rechat','rechat_strict_targeting','end_conversation_cooldown_seconds'])),
            'memory'=>['recent_turn_limit'=>(int)($overrides['memory']['recent_turn_limit']??20),'short_term_max_summaries'=>(int)($overrides['memory']['short_term_max_summaries']??10)]+array_intersect_key($overrides['memory']??[],array_flip(['short_term_enabled','mid_term_enabled','long_term_enabled','oghma_knowledge_tags'])),
            'diary'=>['enabled'=>($overrides['diary']['enabled']??false)===true,
                'automatic_enabled'=>($overrides['diary']['automatic_enabled']??false)===true,
                'automatic_wait_enabled'=>($overrides['diary']['automatic_wait_enabled']??false)===true,
                'automatic_interval_seconds'=>(int)($overrides['diary']['automatic_interval_seconds']??$diary['automatic_interval_seconds']),
                'include_in_context'=>($overrides['diary']['include_in_context']??true)===true,
                'latest_entry_in_context'=>($overrides['diary']['latest_entry_in_context']??false)===true,
                'context_turn_limit'=>(int)($overrides['diary']['context_turn_limit']??$diary['context_turn_limit']),
                'prompt'=>(string)($overrides['diary']['prompt']??$diary['prompt'])]];
    }

    /** Download the editable, ownership-free portion of the Player or Narrator singleton. */
    private function exportSpecialProfileSettings(string $profileId,string $kind):Response
    {
        $this->uuid($profileId,'profile_id');$row=$this->repository->getRevisioned('profile',$profileId);
        $identity=$row['actor_identity']??[];
        if(is_string($identity)){
            try{$identity=json_decode($identity,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('not_found');}
        }
        if(!is_array($identity)||array_is_list($identity))throw new RuntimeException('not_found');
        if(($identity['kind']??null)!==$kind)throw new RuntimeException('not_found');
        $settings=$this->portableSpecialProfileSettings(is_array($row['content']??null)?$row['content']:[],$kind);
        $schema=$kind==='player'?'lorkhan.player-profile-settings.v2':'lorkhan.narrator-profile-settings.v2';
        $document=['schema'=>$schema,'exported_at'=>gmdate('Y-m-d\TH:i:s\Z'),'settings'=>$settings];
        if($this->containsSecretKey($document))throw new RuntimeException($kind.'_profile_settings_export_rejected');
        return new Response(200,json_encode($document,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",
            ['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="lorkhan-'.$kind.'-settings.json"',
                'X-Content-Type-Options'=>'nosniff']);
    }

    /** Merge one strict portable preset into the selected installation singleton as a new revision. */
    private function importSpecialProfileSettings(array $values,array $scope,string $kind):array
    {
        $document=$this->jsonField($values,'preset_json');$keys=array_keys($document);sort($keys);
        $error='invalid_'.$kind.'_profile_settings_preset';
        $schema=$document['schema']??null;
        $validSchema=$kind==='player'
            ?in_array($schema,['lorkhan.player-profile-settings.v1','lorkhan.player-profile-settings.v2'],true)
            :in_array($schema,['lorkhan.narrator-profile-settings.v1','lorkhan.narrator-profile-settings.v2'],true);
        if($keys!==['exported_at','schema','settings']
            ||!$validSchema
            ||!is_string($document['exported_at']??null)||strlen($document['exported_at'])>64
            ||!$this->objectArray($document['settings']??null)||$this->containsSecretKey($document))
            throw new InvalidArgumentException($error);
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');
        $profile=$kind==='player'?$this->repository->playerProfileForInstallation($installation):$this->repository->narratorProfileForInstallation($installation);
        if($profile===null)throw new InvalidArgumentException($kind.'_profile_missing');
        $content=is_array($profile['content']??null)?$profile['content']:[];
        if($kind==='narrator'){
            // Validate supplied fields against the complete shape, but never apply absent defaults.
            $settings=$this->validatePortableSpecialProfileSettings(
                array_replace($this->portableSpecialProfileSettings($content,$kind),$document['settings']),$kind,$error,true);
            $settings=array_intersect_key($settings,$document['settings']);
        }else{
            $settings=$this->validatePortableSpecialProfileSettings($document['settings'],$kind,$error,
                $schema==='lorkhan.player-profile-settings.v2');
        }
        foreach($settings as$field=>$value){
            if($field==='latest_diary_context_enabled'){
                if($value===null)unset($content['diary']['latest_entry_in_context']);
                else $content['diary']['latest_entry_in_context']=$value;
            }elseif($field==='voice'){
                if($value['id']==='')unset($content['voice']);else$content['voice']=$value;
            }elseif(is_string($value)&&$value==='')unset($content[$field]);else$content[$field]=$value;
        }
        return$this->service->revise('profile',(string)$profile['profile_id'],$content,'imported portable '.$kind.' settings');
    }

    /** Build a complete portable field map so empty values can be cleared during a round trip. */
    private function portableSpecialProfileSettings(array $content,string $kind):array
    {
        if($kind==='player'){
            $settings=[];foreach(['appearance','biography','personality','speech_style','goals','notes']as$field)$settings[$field]=$content[$field]??'';
            $settings['biography_known_by_all']=($content['biography_known_by_all']??true)!==false;
        }else{
            $settings=[];foreach(['enabled','context_visibility','welcome_events','random_events','quest_events','book_events']as$field)
                $settings[$field]=($content[$field]??false)===true;
            $settings['welcome_cooldown_minutes']=(int)($content['welcome_cooldown_minutes']??10);
            $settings['random_chance_percent']=(int)($content['random_chance_percent']??15);
            $settings['random_cooldown_rounds']=(int)($content['random_cooldown_rounds']??2);
            $settings['bored_events']=($content['bored_events']??false)===true;
            $settings['bored_chance_percent']=(int)($content['bored_chance_percent']??25);
            $settings['quest_chance_percent']=(int)($content['quest_chance_percent']??10);
            $settings['quest_cooldown_minutes']=(int)($content['quest_cooldown_minutes']??3);
            $settings['narration_filters']=\LorkhanServer\Application\NarrationTextPolicy::validate($content['narration_filters']??[]);
            $settings['inline_narration_mode']=$content['inline_narration_mode']??'Disabled';
            foreach(['prompt_head','core','biography','personality','speech_style','goals','notes']as$field)$settings[$field]=$content[$field]??'';
            $settings['oghma_knowledge_tags']=$content['oghma_knowledge_tags']??'';
            $settings['latest_diary_context_enabled']=$content['diary']['latest_entry_in_context']??null;
            $settings['only_diary_access']=($content['only_diary_access']??false)===true;
            $settings['hide_from_context']=($content['hide_from_context']??true)===true;
            $voice=is_array($content['voice']??null)?$content['voice']:[];
            $settings['voice']=['id'=>$voice['id']??'','language'=>$voice['language']??'en'];
        }
        return$this->validatePortableSpecialProfileSettings($settings,$kind,'invalid_'.$kind.'_profile_settings_export',true);
    }

    /** Enforce the exact Player or Narrator preset keys and bounded scalar values. */
    private function validatePortableSpecialProfileSettings(array $settings,string $kind,string $error,bool $includeV2Fields=false):array
    {
        $textFields=$kind==='player'
            ?['appearance','biography','personality','speech_style','goals','notes']
            :['prompt_head','core','biography','personality','speech_style','goals','notes'];
        $expected=$textFields;
        if($kind==='player'&&$includeV2Fields)$expected[]='biography_known_by_all';
        $narratorV2=$kind==='narrator'&&$includeV2Fields;
        if($narratorV2&&array_key_exists('only_diary_access',$settings)){
            if(!is_bool($settings['only_diary_access']))throw new InvalidArgumentException($error);$expected[]='only_diary_access';
        }
        if($narratorV2&&array_key_exists('hide_from_context',$settings)){
            if(!is_bool($settings['hide_from_context']))throw new InvalidArgumentException($error);$expected[]='hide_from_context';
        }
        if($narratorV2&&array_key_exists('latest_diary_context_enabled',$settings)){
            if($settings['latest_diary_context_enabled']!==null&&!is_bool($settings['latest_diary_context_enabled']))throw new InvalidArgumentException($error);
            $expected[]='latest_diary_context_enabled';
        }
        if($narratorV2&&array_key_exists('oghma_knowledge_tags',$settings)){
            $tags=$settings['oghma_knowledge_tags'];
            if(!is_string($tags)||strlen($tags)>4096||!mb_check_encoding($tags,'UTF-8'))throw new InvalidArgumentException($error);
            $settings['oghma_knowledge_tags']=$this->npcKnowledgeTags($tags);$expected[]='oghma_knowledge_tags';
        }
        if($narratorV2&&array_key_exists('narration_filters',$settings)){
            $settings['narration_filters']=\LorkhanServer\Application\NarrationTextPolicy::validate($settings['narration_filters']);$expected[]='narration_filters';
        }
        if($kind==='narrator')$expected=array_merge($expected,
            ['enabled','inline_narration_mode','context_visibility','welcome_events','random_events','quest_events','book_events','voice'],
            $narratorV2?['welcome_cooldown_minutes','random_chance_percent','random_cooldown_rounds',
                'bored_events','bored_chance_percent','quest_chance_percent','quest_cooldown_minutes']:[]);
        $keys=array_keys($settings);sort($keys);sort($expected);if($keys!==$expected)throw new InvalidArgumentException($error);
        foreach($textFields as$field){$value=$settings[$field];
            if(!is_string($value)||strlen($value)>65_536||!mb_check_encoding($value,'UTF-8'))throw new InvalidArgumentException($error);}
        if($kind==='player'){
            if($includeV2Fields&&!is_bool($settings['biography_known_by_all']))throw new InvalidArgumentException($error);
            return$settings;
        }
        foreach(['enabled','context_visibility','welcome_events','random_events','quest_events','book_events']as$field)
            if(!is_bool($settings[$field]))throw new InvalidArgumentException($error);
        if(!$narratorV2){$settings+=['welcome_cooldown_minutes'=>10,'random_chance_percent'=>15,
            'random_cooldown_rounds'=>2,'bored_events'=>false,'bored_chance_percent'=>25,
            'quest_chance_percent'=>10,'quest_cooldown_minutes'=>3];}
        if(!is_bool($settings['bored_events']))throw new InvalidArgumentException($error);
        foreach(['welcome_cooldown_minutes'=>[1,1440],'random_chance_percent'=>[1,100],
            'random_cooldown_rounds'=>[0,10],'bored_chance_percent'=>[1,100],
            'quest_chance_percent'=>[1,100],'quest_cooldown_minutes'=>[1,60]]as$field=>$range)
            if(!is_int($settings[$field])||$settings[$field]<$range[0]||$settings[$field]>$range[1])
                throw new InvalidArgumentException($error);
        if(!is_string($settings['inline_narration_mode'])
            ||!in_array($settings['inline_narration_mode'],['Disabled','Narrator','NPC','Text Only'],true))throw new InvalidArgumentException($error);
        $voice=$settings['voice'];$voiceKeys=is_array($voice)?array_keys($voice):[];sort($voiceKeys);
        if(!is_array($voice)||array_is_list($voice)||$voiceKeys!==['id','language']
            ||!is_string($voice['id'])||strlen($voice['id'])>512||!mb_check_encoding($voice['id'],'UTF-8')
            ||!is_string($voice['language'])||$voice['language']===''||strlen($voice['language'])>35||!mb_check_encoding($voice['language'],'UTF-8'))
            throw new InvalidArgumentException($error);
        return$settings;
    }

    /** Download the complete portable Global Settings document without installation ownership or secrets. */
    private function exportGlobalSettings(string $configurationId):Response
    {
        $this->uuid($configurationId,'configuration_id');
        if($this->repository->resourceKind($configurationId)!=='global_settings')throw new RuntimeException('not_found');
        $row=$this->repository->getRevisioned('global_settings',$configurationId);
        $installation=(string)$row['installation_id'];
        $settings=EffectiveSettingsResolver::globalDocument(
            is_array($row['content']??null)?$row['content']:[],
            $this->repository->oghmaSettings($installation),
            $this->repository->translationPolicyForInstallation($installation)['content'],
            $this->repository->profileAutoLockEnabled($installation)
        );
        if($this->containsSecretKey($settings))throw new RuntimeException('global_settings_export_rejected');
        $memoryPolicies=[
            'summary'=>$this->repository->memorySummaryPolicyForInstallation($installation)['content']
                ??['schema'=>'lorkhan.memory-policy.v1','enabled'=>false,'provider_configuration_id'=>''],
            'embedding'=>$this->repository->memoryEmbeddingPolicyForInstallation($installation)['content']
                ??\LorkhanServer\Application\MemoryEmbeddingPolicy::defaults(),
        ];
        $memoryPolicies['summary']+=['summary_interval'=>0,'minimum_events'=>4];
        if($this->containsSecretKey($memoryPolicies))throw new RuntimeException('global_settings_export_rejected');
        $document=['schema'=>'lorkhan.global-settings-preset.v3','exported_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'name'=>(string)$row['name'],'settings'=>$settings,'memory_policies'=>$memoryPolicies];
        return new Response(200,json_encode($document,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",
            ['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="lorkhan-global-settings.json"',
                'X-Content-Type-Options'=>'nosniff']);
    }

    /** Apply one ownership-free Global Settings preset as a new revision of the selected installation singleton. */
    private function importGlobalSettings(array $values,array $scope):array
    {
        $document=$this->jsonField($values,'preset_json');$keys=array_keys($document);sort($keys);
        $withMemory=($document['schema']??null)==='lorkhan.global-settings-preset.v3';
        $expectedKeys=$withMemory?['exported_at','memory_policies','name','schema','settings']:['exported_at','name','schema','settings'];
        if($keys!==$expectedKeys
            ||!in_array($document['schema']??null,['lorkhan.global-settings-preset.v1','lorkhan.global-settings-preset.v2','lorkhan.global-settings-preset.v3'],true)
            ||!is_string($document['exported_at']??null)||strlen($document['exported_at'])>64
            ||!$this->objectArray($document['settings']??null)||$this->containsSecretKey($document))
            throw new InvalidArgumentException('invalid_global_settings_preset');
        $name=trim((string)($document['name']??''));
        if($name===''||strlen($name)>128||!mb_check_encoding($name,'UTF-8'))throw new InvalidArgumentException('invalid_global_settings_preset');
        if($withMemory){
            $policies=$document['memory_policies'];
            if(!$this->objectArray($policies))throw new InvalidArgumentException('invalid_memory_policies');
            $policyKeys=array_keys($policies);sort($policyKeys);
            if($policyKeys!==['embedding','summary']||!$this->objectArray($policies['summary'])||!$this->objectArray($policies['embedding']))throw new InvalidArgumentException('invalid_memory_policies');
            \LorkhanServer\Application\MemorySummaryPolicy::validate($policies['summary']);
            \LorkhanServer\Application\MemoryEmbeddingPolicy::validate($policies['embedding']);
        }
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');
        return$this->repository->transaction(function()use($document,$withMemory,$installation,$scope):array{
            $settings=EffectiveSettingsResolver::globalDocument(
                $document['settings'],
                $this->repository->oghmaSettings($installation),
                $this->repository->translationPolicyForInstallation($installation)['content'],
                $this->repository->profileAutoLockEnabled($installation)
            );
            if($withMemory){
                foreach($document['memory_policies']as$kind=>$policy){
                    $enabled=$policy['enabled'];unset($policy['enabled']);if($enabled)$policy['enabled']='1';
                    if($kind==='summary')$this->saveMemoryPolicy($policy,$scope);
                    else $this->saveMemoryEmbeddingPolicy($policy,$scope);
                }
            }
            $this->syncGlobalSettingsSidecars($settings,$installation,'imported portable Global Settings');
            $existing=$this->repository->globalSettingsForInstallation($installation);
            if($existing===null)return$this->service->createRevisioned('global_settings',[
                'installation_id'=>$installation,'name'=>'Global Settings','content'=>$settings,
                'change_reason'=>'imported portable Global Settings',
            ]);
            return$this->service->revise('global_settings',(string)$existing['configuration_id'],$settings,
                'imported portable Global Settings');
        });
    }

    /** Restore an earlier Global Settings document only inside its owning installation. */
    private function rollbackGlobalSettings(array $values,array $scope):array
    {
        $configurationId=$this->need($values,'configuration_id');$this->uuid($configurationId,'configuration_id');
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');
        $existing=$this->repository->globalSettingsForInstallation($installation);
        $revision=filter_var($values['revision']??null,FILTER_VALIDATE_INT);
        if($existing===null||!hash_equals((string)$existing['configuration_id'],$configurationId)
            ||$revision===false||$revision<1||$revision>=(int)$existing['current_revision'])
            throw new InvalidArgumentException('invalid_global_settings_revision');
        $settings=EffectiveSettingsResolver::globalDocument(
            $this->repository->revisionContent('global_settings',$configurationId,(int)$revision),
            $this->repository->oghmaSettings($installation),
            $this->repository->translationPolicyForInstallation($installation)['content'],
            $this->repository->profileAutoLockEnabled($installation)
        );
        $this->syncGlobalSettingsSidecars($settings,$installation,'management Global Settings restore');
        return$this->service->revise('global_settings',$configurationId,$settings,'management Global Settings restore');
    }

    /** Download a validated portable connector without credentials or a binding to the recipient's saved keys. */
    private function exportProvider(string $configurationId):Response
    {
        $this->uuid($configurationId,'configuration_id');$row=$this->repository->getRevisioned('provider',$configurationId);
        $content=is_array($row['content']??null)?$row['content']:[];
        if($this->containsSecretKey($content))throw new RuntimeException('provider_export_rejected');
        $content=LlmConnector::validate($content);
        if($content['driver']!=='mock')$content['credential']='none';
        $document=['schema'=>'lorkhan.provider-export.v1','exported_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'name'=>(string)$row['name'],'content'=>$content===[]?(object)[]:$content];
        $filename=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$row['name']),'-_.');if($filename==='')$filename='lorkhan-model-slot';
        return new Response(200,json_encode($document,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",
            ['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="'.$filename.'.json"','X-Content-Type-Options'=>'nosniff']);
    }

    /** Clone one installation-owned model slot without copying any server runtime secret. */
    private function cloneProvider(array $values):array
    {
        $id=$this->need($values,'configuration_id');$this->uuid($id,'configuration_id');$row=$this->repository->getRevisioned('provider',$id);
        return$this->service->createRevisioned('provider',['installation_id'=>(string)$row['installation_id'],
            'profile_id'=>$row['profile_id']??null,'name'=>$this->boundedConnectorName($values,'name'),'content'=>$row['content']??[]]);
    }

    /** Import one strict portable model-slot document into the explicitly selected installation. */
    private function importProvider(array $values,array $scope):array
    {
        $document=$this->jsonField($values,'provider_json');$keys=array_keys($document);sort($keys);
        if($keys!==['content','exported_at','name','schema']||($document['schema']??null)!=='lorkhan.provider-export.v1'
            ||!is_string($document['exported_at']??null)||strlen($document['exported_at'])>64
            ||!$this->objectArray($document['content']??null)||$this->containsSecretKey($document))throw new InvalidArgumentException('invalid_provider_export');
        $content=LlmConnector::validate($document['content']);
        // Imported endpoints must not silently acquire an existing local API key.
        if($content['driver']!=='mock')$content['credential']='none';
        $name=trim((string)($document['name']??''));if($name===''||strlen($name)>128||!mb_check_encoding($name,'UTF-8'))throw new InvalidArgumentException('invalid_provider_export');
        return$this->service->createRevisioned('provider',['installation_id'=>$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),
            'name'=>$name,'content'=>$content]);
    }

    /** Download one portable prompt without installation ownership, revision history, or secret-like fields. */
    private function exportPrompt(string $configurationId):Response
    {
        $this->uuid($configurationId,'configuration_id');$row=$this->repository->getRevisioned('prompt',$configurationId);
        $content=is_array($row['content']??null)?$row['content']:[];
        if($this->containsSecretKey($content))throw new RuntimeException('prompt_export_rejected');
        $document=['schema'=>'lorkhan.prompt-export.v1','exported_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'name'=>(string)$row['name'],'content'=>$content===[]?(object)[]:$content];
        $filename=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$row['name']),'-_.');if($filename==='')$filename='lorkhan-prompt';
        return new Response(200,json_encode($document,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",
            ['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="'.$filename.'.json"','X-Content-Type-Options'=>'nosniff']);
    }

    /** Clone one prompt into its existing installation while preserving revision independence. */
    private function clonePrompt(array $values):array
    {
        $id=$this->need($values,'configuration_id');$this->uuid($id,'configuration_id');$row=$this->repository->getRevisioned('prompt',$id);
        return$this->service->createRevisioned('prompt',['installation_id'=>(string)$row['installation_id'],
            'profile_id'=>$row['profile_id']??null,'name'=>$this->boundedConnectorName($values,'name'),'content'=>$row['content']??[]]);
    }

    /** Import one strict portable prompt into the explicitly selected installation. */
    private function importPrompt(array $values,array $scope):array
    {
        $document=$this->jsonField($values,'prompt_json');$keys=array_keys($document);sort($keys);
        if($keys!==['content','exported_at','name','schema']||($document['schema']??null)!=='lorkhan.prompt-export.v1'
            ||!is_string($document['exported_at']??null)||strlen($document['exported_at'])>64
            ||!$this->objectArray($document['content']??null)||$this->containsSecretKey($document))throw new InvalidArgumentException('invalid_prompt_export');
        $name=trim((string)($document['name']??''));if($name===''||strlen($name)>128||!mb_check_encoding($name,'UTF-8'))throw new InvalidArgumentException('invalid_prompt_export');
        return$this->service->createRevisioned('prompt',['installation_id'=>$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),
            'name'=>$name,'content'=>$document['content']]);
    }

    /** Download one portable speech connector without ownership, revisions, or credentials. */
    private function exportConnector(string $configurationId):Response
    {
        $this->uuid($configurationId,'configuration_id');$kind=$this->repository->resourceKind($configurationId);
        if(!in_array($kind,['tts_provider','stt_provider'],true))throw new RuntimeException('not_found');
        $row=$this->repository->getRevisioned($kind,$configurationId);$content=is_array($row['content']??null)?$row['content']:[];
        if($this->containsSecretKey($content))throw new RuntimeException('connector_export_rejected');
        $content['credential']='none';
        $document=['schema'=>'lorkhan.connector-export.v1','exported_at'=>gmdate('Y-m-d\TH:i:s\Z'),'kind'=>$kind,
            'name'=>(string)$row['name'],'content'=>$content===[]?(object)[]:$content];
        $filename=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$row['name']),'-_.');if($filename==='')$filename='lorkhan-connector';
        return new Response(200,json_encode($document,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",
            ['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="'.$filename.'.json"','X-Content-Type-Options'=>'nosniff']);
    }

    /** Change only a TTS preset's default voice from the provider voice browser. */
    private function reviseConnectorDefaultVoice(array $values):array
    {
        $id=$this->need($values,'configuration_id');$this->uuid($id,'configuration_id');
        $row=$this->repository->getRevisioned('tts_provider',$id);
        $content=is_array($row['content']??null)?$row['content']:[];
        if($content===[])throw new InvalidArgumentException('invalid_connector_content');
        $content['voice']=$this->need($values,'voice_id');
        $language=trim((string)($values['language']??''));
        if($language!=='')$content['language']=$language;
        return$this->service->revise('tts_provider',$id,$content,'TTS Studio default voice');
    }

    /** Clone a speech connector into the same installation with an explicit new name. */
    private function cloneConnector(array $values):array
    {
        $id=$this->need($values,'configuration_id');$this->uuid($id,'configuration_id');$kind=$this->speechConnectorKind($values);
        $row=$this->repository->getRevisioned($kind,$id);$name=$this->boundedConnectorName($values,'name');
        return$this->service->createRevisioned($kind,['installation_id'=>(string)$row['installation_id'],
            'profile_id'=>$row['profile_id']??null,'name'=>$name,'content'=>$row['content']??[]]);
    }

    /** Import a portable speech connector into the explicitly selected installation. */
    private function importConnector(array $values,array $scope):array
    {
        $kind=$this->speechConnectorKind($values);$document=$this->jsonField($values,'connector_json');$keys=array_keys($document);sort($keys);
        if($keys!==['content','exported_at','kind','name','schema']||($document['schema']??null)!=='lorkhan.connector-export.v1'
            ||($document['kind']??null)!==$kind||!is_string($document['exported_at']??null)||strlen($document['exported_at'])>64
            ||!$this->objectArray($document['content']??null)||$this->containsSecretKey($document))throw new InvalidArgumentException('invalid_connector_export');
        $name=trim((string)($document['name']??''));if($name===''||strlen($name)>128||!mb_check_encoding($name,'UTF-8'))throw new InvalidArgumentException('invalid_connector_export');
        // A portable endpoint must never acquire a credential already held by its destination.
        $document['content']['credential']='none';
        return$this->service->createRevisioned($kind,['installation_id'=>$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),
            'name'=>$name,'content'=>$document['content']]);
    }

    private function speechConnectorKind(array $values):string
    {
        $kind=$this->need($values,'kind');if(!in_array($kind,['tts_provider','stt_provider'],true))throw new InvalidArgumentException('invalid_connector_kind');return$kind;
    }

    private function boundedConnectorName(array $values,string $field):string
    {
        $name=$this->need($values,$field);if(strlen($name)>128||!mb_check_encoding($name,'UTF-8'))throw new InvalidArgumentException('invalid_'.$field);return$name;
    }

    /** Download scoped memories, relationships, and narratives for one local playthrough. */
    private function exportPlaythroughState(string $playthroughId):Response
    {
        $this->uuid($playthroughId,'playthrough_id');$row=$this->repository->getRevisioned('playthrough',$playthroughId);
        $document=$this->service->exportPlaythrough(['installation_id'=>(string)$row['installation_id'],
            'profile_id'=>(string)$row['profile_id'],'playthrough_id'=>$playthroughId]);
        $filename=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$row['name']),'-_.');if($filename==='')$filename='lorkhan-playthrough';
        return new Response(200,json_encode($document,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",
            ['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="'.$filename.'-backup.json"','X-Content-Type-Options'=>'nosniff']);
    }

    /** Create one immutable, secret-free installation configuration backup. */
    private function createConfigurationBackup(array $values,array $scope):array
    {
        if(!hash_equals('Backup',$this->need($values,'confirm')))throw new InvalidArgumentException('confirmation_mismatch');
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');
        $backupId=Uuid::v4();$now=gmdate('Y-m-d\TH:i:s\Z');$document=[
            'schema'=>'lorkhan.configuration-backup.v2','format_version'=>2,'backup_id'=>$backupId,'created_at'=>$now,
            'installation_id'=>$installation,'data'=>$this->repository->configurationBackupState($installation)];
        $json=json_encode($document,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
        if(strlen($json)>$this->maxJsonBytes)throw new InvalidArgumentException('backup_too_large');
        $root=$this->backupRoot();$path=$root.DIRECTORY_SEPARATOR.$backupId.'.json';$temporary=$path.'.tmp-'.bin2hex(random_bytes(8));
        if(is_file($path)||file_put_contents($temporary,$json,LOCK_EX)!==strlen($json)){@unlink($temporary);throw new RuntimeException('backup_write_failed');}
        chmod($temporary,0640);if(!rename($temporary,$path)){@unlink($temporary);throw new RuntimeException('backup_write_failed');}
        try{$this->repository->recordConfigurationBackup($backupId,hash('sha256',$json),strlen($json),$installation,$now);}
        catch(Throwable $error){@unlink($path);throw$error;}
        return['backup_id'=>$backupId,'byte_count'=>strlen($json),'content_sha256'=>hash('sha256',$json)];
    }

    /** Download only a stored backup whose fixed file still matches its database identity. */
    private function downloadConfigurationBackup(string $backupId):Response
    {
        $this->uuid($backupId,'backup_id');[$document,$json]=$this->readConfigurationBackup($backupId);
        return new Response(200,$json,['Content-Type'=>'application/json; charset=utf-8',
            'Content-Disposition'=>'attachment; filename="lorkhan-configuration-'.$document['installation_id'].'-'.$backupId.'.json"',
            'X-Content-Type-Options'=>'nosniff']);
    }

    /** Restore a selected server-owned backup only into its original installation. */
    private function restoreConfigurationBackup(array $values,array $scope):array
    {
        if(!hash_equals('Restore',$this->need($values,'confirm')))throw new InvalidArgumentException('confirmation_mismatch');
        $backupId=$this->need($values,'backup_id');$this->uuid($backupId,'backup_id');
        [$document]=$this->readConfigurationBackup($backupId);
        if(($scope['installation_id']??null)!==$document['installation_id'])throw new InvalidArgumentException('backup_scope_mismatch');
        $result=$this->repository->restoreConfigurationBackup($document,gmdate('Y-m-d\TH:i:s\Z'));
        $this->repository->markConfigurationBackupRestored($backupId,gmdate('Y-m-d\TH:i:s\Z'));return$result;
    }

    /** Read and fully validate one fixed-path configuration backup before download or restore. */
    private function readConfigurationBackup(string $backupId):array
    {
        $record=$this->repository->configurationBackupRecord($backupId);$scope=$record['scope']??[];
        if(($scope['kind']??null)!=='configuration'||!is_string($scope['installation_id']??null))throw new RuntimeException('not_found');
        $path=$this->backupRoot().DIRECTORY_SEPARATOR.$backupId.'.json';$bytes=(int)$record['byte_count'];
        if($bytes<1||$bytes>$this->maxJsonBytes||!is_file($path)||filesize($path)!==$bytes)throw new RuntimeException('backup_integrity_failed');
        $json=file_get_contents($path);if(!is_string($json)||strlen($json)!==$bytes||!hash_equals((string)$record['content_sha256'],hash('sha256',$json)))
            throw new RuntimeException('backup_integrity_failed');
        try{$document=json_decode($json,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('backup_integrity_failed');}
        $this->validateConfigurationBackup($document,$backupId,(string)$scope['installation_id']);return[$document,$json];
    }

    /** Enforce the bounded first-party backup schema and reject secret-bearing documents. */
    private function validateConfigurationBackup(mixed $document,string $backupId,string $installation):void
    {
        if(!$this->objectArray($document))throw new RuntimeException('backup_integrity_failed');$top=array_keys($document);sort($top);
        $format=$document['format_version']??null;$expectedSchema=$format===1?'lorkhan.configuration-backup.v1':'lorkhan.configuration-backup.v2';
        if($top!==['backup_id','created_at','data','format_version','installation_id','schema']
            ||!in_array($format,[1,2],true)||$document['schema']!==$expectedSchema
            ||$document['backup_id']!==$backupId||$document['installation_id']!==$installation||!is_string($document['created_at']))
            throw new RuntimeException('backup_integrity_failed');
        $data=$document['data']??null;if(!$this->objectArray($data)){throw new RuntimeException('backup_integrity_failed');}
        $dataKeys=array_keys($data);sort($dataKeys);$expectedDataKeys=$format===1
            ?['configurations','connector_selections','preferences','profiles']
            :['configurations','connector_selections','core_profiles','preferences','profiles'];
        if($dataKeys!==$expectedDataKeys)throw new RuntimeException('backup_integrity_failed');
        foreach(['profiles','configurations','connector_selections']as$key)if(!is_array($data[$key])||!array_is_list($data[$key])||count($data[$key])>2000)throw new RuntimeException('backup_integrity_failed');
        if(!$this->objectArray($data['preferences'])||array_keys($data['preferences'])!==['auto_lock_on_edit']||!is_bool($data['preferences']['auto_lock_on_edit']))throw new RuntimeException('backup_integrity_failed');

        $coreIds=[];if($format===2){if(!is_array($data['core_profiles'])||!array_is_list($data['core_profiles'])||count($data['core_profiles'])>100)throw new RuntimeException('backup_integrity_failed');
            $defaultCount=0;$slots=[];foreach($data['core_profiles']as$row){if(!$this->objectArray($row))throw new RuntimeException('backup_integrity_failed');$keys=array_keys($row);sort($keys);
                if($keys!==['content','core_profile_id','default_npc','label','slot']||!is_string($row['core_profile_id'])||!is_string($row['label'])||trim($row['label'])===''||strlen($row['label'])>128
                    ||!is_bool($row['default_npc'])||($row['slot']!==null&&(!is_int($row['slot'])||$row['slot']<1||$row['slot']>4))||!$this->objectArray($row['content']))throw new RuntimeException('backup_integrity_failed');
                $this->persistentUuid($row['core_profile_id'],'core_profile_id');if(isset($coreIds[$row['core_profile_id']]))throw new RuntimeException('backup_integrity_failed');$coreIds[$row['core_profile_id']]=true;
                if($row['default_npc'])$defaultCount++;if($row['slot']!==null){if(isset($slots[$row['slot']]))throw new RuntimeException('backup_integrity_failed');$slots[$row['slot']]=true;}}
            if($defaultCount!==1)throw new RuntimeException('backup_integrity_failed');}

        $profileIds=[];foreach($data['profiles']as$row){if(!$this->objectArray($row)){throw new RuntimeException('backup_integrity_failed');}$keys=array_keys($row);sort($keys);
            $expectedProfileKeys=$format===1?['actor_identity','content','name','profile_id']:['actor_identity','content','core_profile_id','name','profile_id'];
            if($keys!==$expectedProfileKeys||!is_string($row['profile_id'])||!is_string($row['name'])||trim($row['name'])===''||strlen($row['name'])>256
                ||($format===2&&($row['core_profile_id']!==null&&(!is_string($row['core_profile_id'])||!isset($coreIds[$row['core_profile_id']]))))
                ||!$this->objectArray($row['actor_identity'])||!$this->objectArray($row['content'])||array_key_exists('portrait',$row['content']))throw new RuntimeException('backup_integrity_failed');
            $this->uuid($row['profile_id'],'profile_id');if(isset($profileIds[$row['profile_id']]))throw new RuntimeException('backup_integrity_failed');$profileIds[$row['profile_id']]=true;}

        $configurationIds=[];$configurationKinds=[];$allowed=['prompt','provider','tts_provider','stt_provider','action_policy','global_settings','memory_policy','memory_embedding_policy','translation_policy'];
        foreach($data['configurations']as$row){if(!$this->objectArray($row)){throw new RuntimeException('backup_integrity_failed');}$keys=array_keys($row);sort($keys);
            if($keys!==['configuration_id','content','kind','name','profile_id']||!is_string($row['configuration_id'])||!in_array($row['kind']??null,$allowed,true)
                ||!is_string($row['name'])||trim($row['name'])===''||strlen($row['name'])>128||!$this->objectArray($row['content'])
                ||($row['profile_id']!==null&&(!is_string($row['profile_id'])||!isset($profileIds[$row['profile_id']]))))throw new RuntimeException('backup_integrity_failed');
            $this->uuid($row['configuration_id'],'configuration_id');if(isset($configurationIds[$row['configuration_id']]))throw new RuntimeException('backup_integrity_failed');
            $configurationIds[$row['configuration_id']]=true;$configurationKinds[$row['configuration_id']]=$row['kind'];}
        $selectionKinds=[];foreach($data['connector_selections']as$row){if(!$this->objectArray($row)){throw new RuntimeException('backup_integrity_failed');}$keys=array_keys($row);sort($keys);
            $kind=$row['provider_kind']??null;$id=$row['configuration_id']??null;if($keys!==['configuration_id','provider_kind']||!in_array($kind,['tts_provider','stt_provider'],true)
                ||!is_string($id)||($configurationKinds[$id]??null)!==$kind||isset($selectionKinds[$kind]))throw new RuntimeException('backup_integrity_failed');$selectionKinds[$kind]=true;}
        $memoryPolicies=0;
        foreach($data['configurations']as$row){
            if($row['kind']!=='memory_policy')continue;
            \LorkhanServer\Application\MemorySummaryPolicy::validate($row['content']);
            $provider=$row['content']['provider_configuration_id'];
            if(++$memoryPolicies>1||$row['profile_id']!==null
                ||($provider!==''&&($configurationKinds[$provider]??null)!=='provider'))throw new RuntimeException('backup_integrity_failed');
        }
        $embeddingPolicies=0;
        foreach($data['configurations']as$row){
            if($row['kind']!=='memory_embedding_policy')continue;
            \LorkhanServer\Application\MemoryEmbeddingPolicy::validate($row['content']);
            if(++$embeddingPolicies>1||$row['profile_id']!==null)throw new RuntimeException('backup_integrity_failed');
        }
        $translationPolicies=0;
        foreach($data['configurations']as$row){
            if($row['kind']!=='translation_policy')continue;
            TranslationPolicy::validate($row['content']);
            if(++$translationPolicies>1||$row['profile_id']!==null)throw new RuntimeException('backup_integrity_failed');
        }
        if($this->containsSecretKey($document))throw new RuntimeException('backup_integrity_failed');
    }

    private function backupRoot():string
    {
        $root=rtrim((string)($this->providerConfig['backup_storage_path']??'/var/lib/lorkhanserver/backups'),DIRECTORY_SEPARATOR);
        if($root===''||(!is_dir($root)&&!mkdir($root,0750,true)&&!is_dir($root)))throw new RuntimeException('backup_storage_unavailable');return$root;
    }
    private function objectArray(mixed $value):bool{return is_array($value)&&($value===[]||!array_is_list($value));}
    private function containsSecretKey(mixed $value):bool{if(!is_array($value))return false;foreach($value as$key=>$item){if(is_string($key)&&preg_match('/(?:api[_-]?key|secret|password|authorization|access[_-]?token|refresh[_-]?token)/i',$key)===1)return true;if($this->containsSecretKey($item))return true;}return false;}

    /** Validate a backup and remap it only to the explicitly selected local playthrough scope. */
    private function restorePlaythroughState(array $values,array $scope):array
    {
        foreach(['installation_id','profile_id','playthrough_id']as$field)if(!isset($scope[$field]))throw new InvalidArgumentException('invalid_'.$field);
        $playthrough=$this->repository->getRevisioned('playthrough',$scope['playthrough_id']);
        if((string)$playthrough['installation_id']!==$scope['installation_id']||(string)$playthrough['profile_id']!==$scope['profile_id'])
            throw new InvalidArgumentException('playthrough_scope_mismatch');
        $document=$this->jsonField($values,'playthrough_json');$document['scope']=$scope;
        return$this->service->restorePlaythrough($document);
    }

    /** Validate a portable profile document before assigning it a new local ID and installation scope. */
    private function profileImportDocument(array $values):array
    {
        $document=$this->jsonField($values,'profile_json');$keys=array_keys($document);sort($keys);
        if($keys!==['actor_identity','content','exported_at','name','schema']||($document['schema']??null)!=='lorkhan.profile-export.v1'
            ||!is_string($document['exported_at']??null)||strlen($document['exported_at'])>64||!is_string($document['name']??null))throw new InvalidArgumentException('invalid_profile_export');
        $identity=$document['actor_identity']??null;$content=$document['content']??null;
        if(!is_array($identity)||array_is_list($identity)||!is_array($content)||array_is_list($content))throw new InvalidArgumentException('invalid_profile_export');
        if(array_diff(array_keys($identity),['kind','display_name','record_id','content_file','refnum'])!==[])throw new InvalidArgumentException('invalid_profile_export');
        $kind=$identity['kind']??'actor';if(!in_array($kind,['actor','npc'],true))throw new InvalidArgumentException('invalid_profile_export');
        foreach(['display_name','record_id','content_file']as$field)if(isset($identity[$field])&&(!is_string($identity[$field])||strlen($identity[$field])>256||!mb_check_encoding($identity[$field],'UTF-8')))throw new InvalidArgumentException('invalid_profile_export');
        if(isset($identity['refnum'])){$refnum=$identity['refnum'];$validString=is_string($refnum)&&strlen($refnum)<=128;
            $refnumKeys=is_array($refnum)?array_keys($refnum):[];sort($refnumKeys);
            $validObject=is_array($refnum)&&!array_is_list($refnum)&&$refnumKeys===['content_file','index']&&is_int($refnum['index'])&&is_int($refnum['content_file'])&&$refnum['index']>=0&&$refnum['content_file']>=0;
            if(!$validString&&!$validObject)throw new InvalidArgumentException('invalid_profile_export');}
        $name=trim($document['name']);if($name===''||strlen($name)>256||!mb_check_encoding($name,'UTF-8'))throw new InvalidArgumentException('invalid_profile_export');
        $identity['kind']='actor';$identity['display_name']??=$name;
        unset($content['routing'],$content['settings_overrides'],$content['portrait']);
        return['name'=>$name,'actor_identity'=>$identity,'content'=>$content];
    }

    /** Build the stable OpenMW identity used when a profile is selected for an in-game actor. */
    private function profileIdentity(array $values):array
    {
        $identity=['kind'=>'actor','display_name'=>$this->need($values,'name')];
        foreach(['record_id','content_file']as$field){$value=trim((string)($values[$field]??''));if($value!=='')$identity[$field]=$value;}
        if(isset($values['refnum_index'],$values['refnum_content_file'])&&preg_match('/^[0-9]+$/D',(string)$values['refnum_index'])===1&&preg_match('/^[0-9]+$/D',(string)$values['refnum_content_file'])===1){
            $identity['refnum']=['index'=>(int)$values['refnum_index'],'content_file'=>(int)$values['refnum_content_file']];
        }else{$refnum=trim((string)($values['refnum']??''));if($refnum!=='')$identity['refnum']=$refnum;}
        return$identity;
    }

    /** Describe a reusable pre-activation template without impersonating a live TES3 actor. */
    private function templateIdentity(array $values):array
    {
        $identity=['kind'=>'template','display_name'=>$this->need($values,'name')];
        foreach(['record_id','content_file']as$field){$value=trim((string)($values[$field]??''));if($value!=='')$identity[$field]=$value;}
        return$identity;
    }

    /** Build the installation-scoped identity used for the human player's roleplay profile. */
    private function playerIdentity(array $values):array
    {
        return['kind'=>'player','display_name'=>$this->need($values,'name')];
    }

    /** Build the stable non-world identity used for player-local narrator delivery. */
    private function narratorIdentity(array $values):array
    {
        return['kind'=>'narrator','record_id'=>'lorkhan:narrator','refnum'=>['index'=>0,'content_file'=>0],
            'content_file'=>'LORKHAN','cell'=>['kind'=>'interior','name'=>'LORKHAN Narrator'],
            'display_name'=>$this->need($values,'name')];
    }

    /** Convert the focused narration form into an opt-in roleplay and routing document. */
    private function narratorContent(array $values):array
    {
        $content=$this->profileContent($values,true);$mode=(string)($values['inline_narration_mode']??'Disabled');
        if(array_key_exists('oghma_knowledge_tags',$values)){
            $tags=$values['oghma_knowledge_tags'];
            if(!is_string($tags)||strlen($tags)>4096||!mb_check_encoding($tags,'UTF-8'))throw new InvalidArgumentException('invalid_oghma_knowledge_tags');
            $content['oghma_knowledge_tags']=$this->npcKnowledgeTags($tags);
        }
        if(!in_array($mode,['Disabled','Narrator','NPC','Text Only'],true))throw new InvalidArgumentException('invalid_inline_narration_mode');
        $content['enabled']=isset($values['enabled']);$content['inline_narration_mode']=$mode;
        $content['context_visibility']=isset($values['context_visibility']);
        if(isset($values['narrator_diary_access_present']))$content['only_diary_access']=isset($values['only_diary_access']);
        if(isset($values['narrator_visibility_present']))$content['hide_from_context']=isset($values['hide_from_context']);
        $content['welcome_events']=isset($values['welcome_events']);$content['random_events']=isset($values['random_events']);
        $integer=static function(array$input,string$key,int$default,int$minimum,int$maximum):int{
            $value=filter_var($input[$key]??$default,FILTER_VALIDATE_INT);
            if($value===false||$value<$minimum||$value>$maximum)throw new InvalidArgumentException('invalid_'.$key);
            return(int)$value;
        };
        $content['welcome_cooldown_minutes']=$integer($values,'welcome_cooldown_minutes',10,1,1440);
        $content['random_chance_percent']=$integer($values,'random_chance_percent',15,1,100);
        $content['random_cooldown_rounds']=$integer($values,'random_cooldown_rounds',2,0,10);
        $content['bored_events']=isset($values['bored_events']);
        $content['bored_chance_percent']=$integer($values,'bored_chance_percent',25,1,100);
        $content['quest_events']=isset($values['quest_events']);$content['book_events']=isset($values['book_events']);
        $content['quest_chance_percent']=$integer($values,'quest_chance_percent',10,1,100);
        $content['quest_cooldown_minutes']=$integer($values,'quest_cooldown_minutes',3,1,60);
        if(isset($values['narration_filters_present'])){
            foreach(\LorkhanServer\Application\NarrationTextPolicy::defaults()as$field=>$_)$content['narration_filters'][$field]=isset($values[$field]);
        }
        if(array_key_exists('diary_interval_seconds',$values))$content['diary']=array_replace((array)($content['diary']??[]),$this->automaticDiaryFormContent($values));
        if(isset($values['latest_diary_context_present']))$content['diary']['latest_entry_in_context']=isset($values['latest_diary_context_enabled']);
        return$content;
    }

    /** Validate the manual request before it reaches database UUID and revision predicates. */
    private function requestMemorySummary(array $values,array $scope):array
    {
        $id=$this->need($values,'memory_id');$this->uuid($id,'memory_id');
        $revision=filter_var($values['base_revision']??null,FILTER_VALIDATE_INT);
        if($revision===false||$revision<1||$revision>2147483647)throw new InvalidArgumentException('invalid_memory_revision');
        return$this->repository->enqueueMemorySummary(
            $scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),$id,$revision);
    }

    /** Save the opt-in model policy without scheduling historical work or contacting a provider. */
    private function saveMemoryPolicy(array $values,array $scope):array
    {
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');
        $content=['schema'=>'lorkhan.memory-policy.v1','enabled'=>isset($values['enabled']),
            'provider_configuration_id'=>trim((string)($values['provider_configuration_id']??''))];
        foreach(['summary_interval','minimum_events']as$field)if(array_key_exists($field,$values)){
            $value=filter_var($values[$field],FILTER_VALIDATE_INT);
            if($value===false)throw new InvalidArgumentException('invalid_memory_'.$field);$content[$field]=$value;
        }
        $existing=$this->repository->memorySummaryPolicyForInstallation($installation);
        foreach(['summary_interval','minimum_events']as$field)if(!array_key_exists($field,$content)&&isset($existing['content'][$field]))$content[$field]=$existing['content'][$field];
        if($existing===null)return$this->service->createRevisioned('memory_policy',
            ['installation_id'=>$installation,'name'=>'Model memory','content'=>$content]);
        if($existing['content']===$content)return$existing;
        return$this->service->revise('memory_policy',$existing['configuration_id'],$content,
            trim((string)($values['change_reason']??'Memory policy update'))?:'Memory policy update');
    }

    /** Save the opt-in MiniMe policy without contacting its endpoint or scheduling historical work. */
    private function saveMemoryEmbeddingPolicy(array $values,array $scope):array
    {
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');
        $timeout=filter_var($values['timeout_ms']??null,FILTER_VALIDATE_INT);
        if($timeout===false)throw new InvalidArgumentException('invalid_memory_embedding_timeout');
        $content=\LorkhanServer\Application\MemoryEmbeddingPolicy::validate([
            'schema'=>\LorkhanServer\Application\MemoryEmbeddingPolicy::SCHEMA,
            'enabled'=>isset($values['enabled']),'endpoint'=>trim((string)($values['endpoint']??'')),
            'timeout_ms'=>$timeout,
        ]);
        $existing=$this->repository->memoryEmbeddingPolicyForInstallation($installation);
        if($existing===null)return$this->service->createRevisioned('memory_embedding_policy',[
            'installation_id'=>$installation,'name'=>'Semantic memory retrieval','content'=>$content]);
        if($existing['content']===$content)return$existing;
        return$this->service->revise('memory_embedding_policy',$existing['configuration_id'],$content,
            trim((string)($values['change_reason']??'Semantic memory policy update'))?:'Semantic memory policy update');
    }

    /** Queue one bounded page of missing current memory vectors only after an explicit browser action. */
    private function requestMemoryEmbeddingBackfill(array $values,array $scope):array
    {
        $limit=filter_var($values['limit']??100,FILTER_VALIDATE_INT);
        if($limit===false||$limit<1||$limit>500)throw new InvalidArgumentException('invalid_memory_embedding_limit');
        return$this->repository->enqueueMemoryEmbeddings(
            $scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),$limit);
    }

    /** Keep named preset catalogue changes separate from confirmed, revision-fenced profile Apply. */
    private function namedCoreProfilePreset(array $values,array $scope):Response
    {
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');
        $operation=$this->need($values,'operation');
        if($operation==='catalogue')return Response::json(200,['presets'=>$this->management->coreProfilePresets($installation)]);
        if(!in_array($operation,['export','import','save_new','overwrite','apply'],true))throw new InvalidArgumentException('invalid_preset_operation');
        $record=null;
        if(in_array($operation,['export','overwrite','apply'],true)){
            $record=$this->management->coreProfilePresetRecord($installation,$this->need($values,'preset_id'));
            if($operation!=='export'){
                $revision=filter_var($values['preset_revision']??null,FILTER_VALIDATE_INT);
                if($revision===false||$revision<1)throw new InvalidArgumentException('invalid_preset_revision');
                if($revision!==(int)$record['revision'])throw new RuntimeException('revision_conflict');
            }
        }
        if($operation==='export')return Response::json(200,['schema'=>'lorkhan.named-core-preset-file.v1',
            'name'=>$record['name'],'preset'=>$record['payload']]);
        if($operation==='import'){
            $document=$this->jsonField($values,'preset_json');$keys=array_keys($document);sort($keys);
            if($keys!==['name','preset','schema']||($document['schema']??null)!=='lorkhan.named-core-preset-file.v1'
                ||!is_string($document['name'])||!is_array($document['preset']))throw new InvalidArgumentException('invalid_named_core_preset');
            $payload=\LorkhanServer\Application\CoreProfilePreset::validate($document['preset']);
            $name=$values['preset_name']??$document['name'];
            if(!is_string($name))throw new InvalidArgumentException('invalid_preset_name');
            $id=$this->management->saveCoreProfilePreset($installation,$name,$payload);
        }else{
            $profileId=$this->need($values,'core_profile_id');$this->uuid($profileId,'core_profile_id');
            $profile=$this->repository->getRevisioned('core_profile',$profileId);
            if(($profile['installation_id']??null)!==$installation)throw new InvalidArgumentException('core_profile_scope_mismatch');
            if($operation==='apply'){
                if(($values['confirm']??'')!=='Apply')throw new InvalidArgumentException('confirmation_mismatch');
                $revision=filter_var($values['expected_revision']??null,FILTER_VALIDATE_INT);
                if($revision===false||$revision<1)throw new InvalidArgumentException('invalid_profile_revision');
                $content=\LorkhanServer\Application\CoreProfilePreset::apply($record['payload'],$profile['content']);
                $updated=$this->repository->revise('core_profile',(string)$profile['core_profile_id'],$content,
                    'Apply named Core Profile preset',gmdate(DATE_ATOM),$revision);
                return Response::json(200,['applied'=>true,'revision'=>(int)$updated['current_revision']]);
            }
            if($operation==='overwrite'&&($values['confirm']??'')!=='Overwrite')throw new InvalidArgumentException('confirmation_mismatch');
            $payload=\LorkhanServer\Application\CoreProfilePreset::capture($this->coreProfileContent($values));
            $id=$this->management->saveCoreProfilePreset($installation,
                $operation==='overwrite'?$record['name']:$this->need($values,'preset_name'),$payload,
                $operation==='overwrite'?$record['preset_id']:null,$operation==='overwrite'?(int)$record['revision']:0);
        }
        return Response::json(200,['preset_id'=>$id,'presets'=>$this->management->coreProfilePresets($installation)]);
    }

    /** Named presets capture unsaved controls; only confirmed Apply mutates active settings. */
    private function namedGlobalSettingsPreset(array $values,array $scope):Response
    {
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');
        $operation=$this->need($values,'operation');
        $id=trim((string)($values['preset_id']??''));
        if($operation==='apply'){
            if(($values['confirm']??'')!=='Apply')throw new InvalidArgumentException('confirmation_mismatch');
            $this->repository->transaction(function()use($installation,$scope,$id):void{
                $preset=$id==='default'?\LorkhanServer\Application\GlobalSettingsPreset::defaults():$this->management->globalSettingsPreset($installation,$id);
                $stored=$this->repository->globalSettingsForInstallation($installation);
                $settings=EffectiveSettingsResolver::globalDocument($stored['content']??[],
                    $this->repository->oghmaSettings($installation),$this->repository->translationPolicyForInstallation($installation)['content'],
                    $this->repository->profileAutoLockEnabled($installation));
                $summary=$this->repository->memorySummaryPolicyForInstallation($installation)['content']
                    ??['schema'=>'lorkhan.memory-policy.v1','enabled'=>false,'provider_configuration_id'=>''];
                $embedding=$this->repository->memoryEmbeddingPolicyForInstallation($installation)['content']
                    ??\LorkhanServer\Application\MemoryEmbeddingPolicy::defaults();
                $applied=\LorkhanServer\Application\GlobalSettingsPreset::apply($preset,$settings,$summary,$embedding);
                $document=['schema'=>'lorkhan.global-settings-preset.v3','exported_at'=>gmdate('c'),'name'=>'Named preset',
                    'settings'=>$applied['settings'],'memory_policies'=>['summary'=>$applied['summary'],'embedding'=>$applied['embedding']]];
                $this->importGlobalSettings(['preset_json'=>json_encode($document,JSON_THROW_ON_ERROR)],$scope);
            });
            return Response::json(200,['applied'=>true]);
        }
        if(!in_array($operation,['save_new','overwrite'],true))throw new InvalidArgumentException('invalid_preset_operation');
        if($operation==='overwrite'&&($values['confirm']??'')!=='Overwrite')throw new InvalidArgumentException('confirmation_mismatch');
        $summary=['schema'=>'lorkhan.memory-policy.v1','enabled'=>isset($values['memory_summary_enabled']),
            'provider_configuration_id'=>trim((string)($values['memory_summary_connector']??'')),
            'summary_interval'=>filter_var($values['memory_summary_interval']??0,FILTER_VALIDATE_INT),
            'minimum_events'=>filter_var($values['memory_summary_minimum_events']??4,FILTER_VALIDATE_INT)];
        $embedding=['schema'=>\LorkhanServer\Application\MemoryEmbeddingPolicy::SCHEMA,
            'enabled'=>isset($values['memory_embedding_enabled']),'endpoint'=>trim((string)($values['memory_embedding_endpoint']??'')),
            'timeout_ms'=>filter_var($values['memory_embedding_timeout']??1500,FILTER_VALIDATE_INT)];
        $payload=\LorkhanServer\Application\GlobalSettingsPreset::capture($this->globalSettingsContent($values),$summary,$embedding);
        $id=$this->management->saveGlobalSettingsPreset($installation,$this->need($values,'preset_name'),$payload,
            $operation==='overwrite'?$id:null,(int)($values['preset_revision']??0));
        return Response::json(200,['preset_id'=>$id,'presets'=>$this->management->globalSettingsPresets($installation)]);
    }

    /** Create or revise the one typed global-settings document owned by an installation. */
    private function saveGlobalSettings(array $values,array $scope):array
    {
        return $this->repository->transaction(function() use($values,$scope):array {
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');
        $content=$this->globalSettingsContent($values);
        if(isset($values['memory_settings_present'])){
            $summary=['schema'=>'lorkhan.memory-policy.v1','enabled'=>isset($values['memory_summary_enabled']),
                'provider_configuration_id'=>trim((string)($values['memory_summary_connector']??'')),
                'summary_interval'=>filter_var($values['memory_summary_interval']??0,FILTER_VALIDATE_INT),
                'minimum_events'=>filter_var($values['memory_summary_minimum_events']??4,FILTER_VALIDATE_INT)];
            \LorkhanServer\Application\MemorySummaryPolicy::validate($summary);
            $embedding=\LorkhanServer\Application\MemoryEmbeddingPolicy::validate([
                'schema'=>\LorkhanServer\Application\MemoryEmbeddingPolicy::SCHEMA,
                'enabled'=>isset($values['memory_embedding_enabled']),
                'endpoint'=>trim((string)($values['memory_embedding_endpoint']??'')),
                'timeout_ms'=>filter_var($values['memory_embedding_timeout']??1500,FILTER_VALIDATE_INT)]);
            $summaryValues=$summary;unset($summaryValues['enabled']);if($summary['enabled'])$summaryValues['enabled']='1';
            $embeddingValues=$embedding;unset($embeddingValues['enabled']);if($embedding['enabled'])$embeddingValues['enabled']='1';
            $this->saveMemoryPolicy($summaryValues,$scope);$this->saveMemoryEmbeddingPolicy($embeddingValues,$scope);
        }
        $this->syncGlobalSettingsSidecars($content,$installation,trim((string)($values['change_reason']??'management global settings'))?:'management global settings');
        $existing=$this->repository->globalSettingsForInstallation($installation);
        if($existing===null)return$this->service->createRevisioned('global_settings',['installation_id'=>$installation,'name'=>'Global Settings','content'=>$content]);
        return$this->service->revise('global_settings',(string)$existing['configuration_id'],$content,trim((string)($values['change_reason']??'management global settings'))?:'management global settings');
        });
    }

    /** Save the server-only translation sidecar without placing provider details in client settings. */
    private function saveTranslationPolicy(array $values,string $installation):array
    {
        $provider=strtolower(trim((string)($values['translation_provider']??'none')));
        $active=$provider==='deepl';
        $content=TranslationPolicy::validate([
            'schema'=>'lorkhan.translation-policy.v1','provider'=>$provider,
            'translate_text'=>$active&&isset($values['translation_text']),'translate_audio'=>$active&&isset($values['translation_audio']),
            'save_translated_text'=>$active&&isset($values['translation_save_text']),
            'source_language'=>trim((string)($values['translation_source_language']??'')),
            'target_language'=>trim((string)($values['translation_target_language']??'')),
            'endpoint'=>trim((string)($values['translation_endpoint_url']??TranslationPolicy::FREE_ENDPOINT)),
        ]);
        $existing=$this->repository->translationPolicyForInstallation($installation);
        if($existing['configuration_id']===null)return$this->service->createRevisioned('translation_policy',[
            'installation_id'=>$installation,'name'=>'NPC Output Translation','content'=>$content]);
        if($existing['content']===$content)return$existing;
        return$this->service->revise('translation_policy',(string)$existing['configuration_id'],$content,
            trim((string)($values['change_reason']??'management translation policy'))?:'management translation policy');
    }

    /** Keep legacy runtime sidecars synchronized with the authoritative v2 Global Settings revision. */
    private function syncGlobalSettingsSidecars(array $settings,string $installation,string $reason):void
    {
        $settings=EffectiveSettingsResolver::validateGlobalSettings($settings);$now=gmdate('Y-m-d\TH:i:s\Z');
        $this->repository->setProfileAutoLock($installation,$settings['profile_management']['auto_lock_profile'],$now);
        $oghma=$settings['oghma'];
        $this->repository->setOghmaSettings($installation,[
            'enabled'=>$oghma['enabled'],'knowledge_tags'=>$oghma['knowledge_tags'],
            'racial_context_enabled'=>$oghma['racial_context_enabled'],'location_context_enabled'=>$oghma['location_context_enabled'],
            'topic_count'=>$oghma['topic_count'],'result_limit'=>$oghma['result_limit'],
            'extractor_enabled'=>$oghma['extractor_enabled'],'extractor_timeout_ms'=>$oghma['extractor_timeout_ms'],
        ],$now);
        $translation=$settings['translation'];$existing=$this->repository->translationPolicyForInstallation($installation);
        if($existing['configuration_id']===null)$this->service->createRevisioned('translation_policy',[
            'installation_id'=>$installation,'name'=>'NPC Output Translation','content'=>$translation]);
        elseif($existing['content']!==$translation)$this->service->revise('translation_policy',(string)$existing['configuration_id'],$translation,$reason);
    }

    /** Create one typed Core Profile between installation defaults and NPC overrides. */
    private function createCoreProfile(array $values,array $scope):array
    {
        $slotRaw=trim((string)($values['slot']??''));$slot=$slotRaw===''?null:filter_var($slotRaw,FILTER_VALIDATE_INT);
        if($slot===false||($slot!==null&&($slot<1||$slot>4)))throw new InvalidArgumentException('invalid_core_profile_slot');
        return$this->service->createRevisioned('core_profile',[
            'installation_id'=>$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),
            'name'=>$this->need($values,'label'),'default_npc'=>isset($values['default_npc']),'slot'=>$slot,
            'content'=>$this->coreProfileContent($values),
        ]);
    }

    /** Save the typed Core Profile metadata and content represented by the copied Herika editor. */
    private function saveCoreProfile(array $values):array
    {
        $slotRaw=trim((string)($values['slot']??''));$slot=$slotRaw===''?null:filter_var($slotRaw,FILTER_VALIDATE_INT);
        if($slot===false||($slot!==null&&($slot<1||$slot>4)))throw new InvalidArgumentException('invalid_core_profile_slot');
        return$this->service->reviseCoreProfile($this->need($values,'core_profile_id'),$this->need($values,'label'),isset($values['default_npc']),$slot,
            $this->coreProfileContent($values),$this->need($values,'change_reason'));
    }

    /** Promote an existing Core Profile without duplicating or mutating its content revision. */
    private function makeDefaultCoreProfile(array $values):array
    {
        $id=$this->need($values,'core_profile_id');$profile=$this->repository->getRevisioned('core_profile',$id);
        return$this->repository->updateCoreProfileMetadata($id,(string)$profile['label'],true,
            $profile['slot']===null?null:(int)$profile['slot'],gmdate('Y-m-d\TH:i:s\Z'));
    }

    /** Convert Herika-style labelled controls into the bounded Core Profile revision document. */
    private function coreProfileContent(array $values):array
    {
        $routing=[];
        foreach(['prompt_configuration_id','llm_configuration_id','llm_fast_configuration_id','llm_powerful_configuration_id',
            'llm_experimental_configuration_id','llm_fallback_configuration_id','diary_generation_configuration_id','tts_configuration_id']as$field){
            $value=trim((string)($values[$field]??''));if($value==='')continue;$this->uuid($value,$field);$routing[$field]=$value;
        }
        $routing['llm_randomizer_enabled']=isset($values['llm_randomizer_enabled']);
        $routing['llm_fallback_enabled']=isset($values['llm_fallback_enabled']);

        $number=static function(array$input,string$key,int$default):int{$value=filter_var($input[$key]??$default,FILTER_VALIDATE_INT);
            if($value===false)throw new InvalidArgumentException('invalid_'.$key);return(int)$value;};
        $overrides=[
            'response'=>['max_words'=>$number($values,'setting_response_max_words',0),'core_lang'=>$values['setting_response_core_lang']??'','lang_llm_xtts'=>isset($values['setting_response_lang_llm_xtts'])],
            'behavior'=>['rechat'=>isset($values['setting_behavior_rechat']),
                'rechat_max_depth'=>$number($values,'setting_behavior_rechat_max_depth',2),
                'rechat_probability_percent'=>$number($values,'setting_behavior_rechat_probability_percent',50),
                'rechat_allow_actions'=>isset($values['setting_behavior_rechat_allow_actions'])]
                + (isset($values['setting_behavior_combat_bark_period_seconds'])
                    ? ['combat_bark_period_seconds'=>$number($values,'setting_behavior_combat_bark_period_seconds',20)] : []),
            'memory'=>['recent_turn_limit'=>$number($values,'setting_memory_recent_turn_limit',20),
                'short_term_max_summaries'=>$number($values,'setting_memory_short_term_max_summaries',10)]
                + (isset($values['memory_switches_present']) ? [
                    'short_term_enabled'=>isset($values['setting_memory_short_term_enabled']),
                    'mid_term_enabled'=>isset($values['setting_memory_mid_term_enabled']),
                    'long_term_enabled'=>isset($values['setting_memory_long_term_enabled']),
                ] : []),
            'diary'=>['enabled'=>isset($values['setting_diary_enabled']),
                'automatic_enabled'=>isset($values['setting_diary_automatic_enabled']),
                'automatic_wait_enabled'=>isset($values['setting_diary_automatic_wait_enabled']),
                'automatic_interval_seconds'=>$number($values,'setting_diary_automatic_interval_seconds',120),
                'include_in_context'=>isset($values['setting_diary_include_in_context']),
                'latest_entry_in_context'=>isset($values['setting_diary_latest_entry_in_context']),
                'context_turn_limit'=>$number($values,'setting_diary_context_turn_limit',20),
                'prompt'=>trim((string)($values['setting_diary_prompt']??DiaryGenerationPolicy::defaults()['prompt']))],
        ];

        if (isset($values['quest_comments_present'])) {
            $overrides['quest_comments']=['enabled'=>isset($values['setting_quest_comments_enabled']),
                'chance_percent'=>$number($values,'setting_quest_comments_chance_percent',10)];
            EffectiveSettingsResolver::validateSettingsOverrides(['quest_comments'=>$overrides['quest_comments']]);
        }
        if (isset($values['setting_bored_event_chance_percent'])) {
            $overrides['bored_event']=['chance_percent'=>$number($values,'setting_bored_event_chance_percent',50)];
            EffectiveSettingsResolver::validateSettingsOverrides(['bored_event'=>$overrides['bored_event']]);
        }
        if (isset($values['rpg_comments_present'])) {
            $overrides['rpg_comments']=['events'=>$values['profile_rpg_events']??[],
                'chance_percent'=>$number($values,'setting_rpg_comments_chance_percent',50)];
            EffectiveSettingsResolver::validateSettingsOverrides(['rpg_comments'=>$overrides['rpg_comments']]);
        }
        if (isset($values['profile_evolution_present'])) {
            $fields=$values['profile_evolution_fields']??[];
            if (!is_array($fields)) throw new InvalidArgumentException('invalid_profile_evolution_defaults');
            if ($fields===[] && !isset($values['profile_evolution_enabled'])) $fields=['personality','speech_style','goals'];
            $overrides['profile_evolution']=EffectiveSettingsResolver::profileEvolutionDefaults([
                'enabled'=>isset($values['profile_evolution_enabled']), 'fields'=>$fields,
                'history_limit'=>$number($values,'setting_profile_evolution_history_limit',50)]);
        }
        // Match the reference merge: preserve advanced overrides, with visible controls authoritative.
        if (array_key_exists('core_settings_overrides_json', $values)) {
            $advanced = EffectiveSettingsResolver::validateSettingsOverrides($this->jsonField($values, 'core_settings_overrides_json'));
            $supported = \LorkhanServer\Application\CoreProfilePreset::capture(['settings_overrides'=>$advanced])['settings_overrides'];
            $previous = isset($values['core_profile_id'])
                ? ($this->repository->getRevisioned('core_profile', $values['core_profile_id'])['content']['settings_overrides'] ?? []) : [];
            foreach ($advanced as $section => $fields) {
                foreach ($fields as $field => $value) {
                    // Retain old compatibility values, but never offer a newly saved inert control.
                    if (!array_key_exists($field, $supported[$section] ?? [])
                        && (!array_key_exists($field, $previous[$section] ?? []) || $previous[$section][$field] !== $value))
                        throw new InvalidArgumentException('unsupported_core_setting_override');
                }
            }
            foreach ($overrides as $section => $fields) {
                $advanced[$section] = array_replace($advanced[$section] ?? [], $fields);
            }
            $overrides = $advanced;
        }
        return['schema'=>'lorkhan.core-profile.v1','prompt'=>(string)($values['prompt']??''),
            'routing'=>$routing,'settings_overrides'=>$overrides];
    }

    /** Convert labelled management controls into the authoritative v2 Global Settings document. */
    private function globalSettingsContent(array $values):array
    {
        $integer=static function(array$input,string$key,int$default):int{$value=filter_var($input[$key]??$default,FILTER_VALIDATE_INT);if($value===false)throw new InvalidArgumentException('invalid_'.$key);return(int)$value;};
        $content=SettingsCatalog::globalDefaults();$client=&$content['client'];
        foreach(['prompt_head','emote_moods'] as $field)$content['prompt'][$field]=trim((string)($values[$field]??''));
        $events=$values['rpg_events']??[];if(!is_array($events))throw new InvalidArgumentException('invalid_rpg_comments');
        $content['rpg_comments']=['events'=>array_values($events),'chance_percent'=>$integer($values,'rpg_chance',50)];
        $client['behavior']['auto_greeting']=isset($values['auto_greeting']);
        $client['behavior']['boredom']=isset($values['boredom']);
        $client['behavior']['boredom_delay_seconds']=$integer($values,'boredom_delay_seconds',$client['behavior']['boredom_delay_seconds']);
        $client['behavior']['combat_barks']=isset($values['combat_barks']);
        $client['behavior']['combat_bark_period_seconds']=$integer($values,'combat_bark_period_seconds',$client['behavior']['combat_bark_period_seconds']);
        $client['behavior']['rechat_mode']=trim((string)($values['rechat_mode']??$client['behavior']['rechat_mode']));
        $client['behavior']['rechat_strict_targeting']=isset($values['rechat_strict_targeting']);
        $client['behavior']['open_rechat']=isset($values['open_rechat']);
        $client['behavior']['rechat_allow_actions']=isset($values['rechat_allow_actions']);
        $client['behavior']['end_conversation_cooldown_seconds']=$integer($values,'end_conversation_cooldown_seconds',$client['behavior']['end_conversation_cooldown_seconds']);
        $content['profile_management']['auto_lock_profile']=isset($values['auto_lock_profile']);
        $content['profile_management']['autofill_custom_profiles']=isset($values['autofill_custom_profiles']);
        $content['profile_management']['autofill_custom_profiles_trigger']=$integer($values,
            'autofill_custom_profiles_trigger',$content['profile_management']['autofill_custom_profiles_trigger']);
        $provider=strtolower(trim((string)($values['translation_provider']??'none')));$active=$provider==='deepl';
        $content['translation']=TranslationPolicy::validate(['schema'=>'lorkhan.translation-policy.v1','provider'=>$provider,
            'translate_text'=>$active&&isset($values['translation_text']),'translate_audio'=>$active&&isset($values['translation_audio']),
            'save_translated_text'=>$active&&isset($values['translation_save_text']),
            'source_language'=>trim((string)($values['translation_source_language']??'')),
            'target_language'=>trim((string)($values['translation_target_language']??'')),
            'endpoint'=>trim((string)($values['translation_endpoint_url']??TranslationPolicy::FREE_ENDPOINT))]);
        $content['oghma']=[
            'enabled'=>isset($values['oghma_enabled']),'topic_count'=>$integer($values,'oghma_topic_count',1),
            'result_limit'=>$integer($values,'oghma_result_limit',3),
            'racial_context_enabled'=>isset($values['oghma_racial_context_enabled']),
            'location_context_enabled'=>isset($values['oghma_location_context_enabled']),
            'extractor_fallback_enabled'=>isset($values['oghma_extractor_enabled']),
            'extractor_timeout_ms'=>$integer($values,'oghma_extractor_timeout_ms',1500),
            'knowledge_tags'=>$this->npcKnowledgeTags($values['oghma_knowledge_tags']??''),
            'extractor_enabled'=>isset($values['oghma_extractor_enabled']),
        ];
        foreach(SettingsCatalog::contextSectionDefaults()as$key=>$default)$content['context']['sections'][$key]=isset($values['context_section_'.$key]);
        foreach(SettingsCatalog::contextDetailDefaults()as$key=>$default)$content['context']['details'][$key]=isset($values['context_detail_'.$key]);
        if(isset($values['context_detail_npc_equipment_inventory'])&&!isset($values['context_detail_npc_equipment'])&&!isset($values['context_detail_npc_inventory'])){
            $content['context']['details']['npc_equipment']=true;$content['context']['details']['npc_inventory']=true;
        }
        foreach (['npc_moods_goals' => ['npc_moods', 'npc_goals'], 'npc_relationships_notes' => ['npc_relationships', 'npc_notes']] as $old => [$first, $second]) {
            if (isset($values['context_detail_'.$old]) && !isset($values['context_detail_'.$first]) && !isset($values['context_detail_'.$second]))
                $content['context']['details'][$first] = $content['context']['details'][$second] = true;
        }
        $content['context']['prompt_timestamp'] = isset($values['context_prompt_timestamp']);
        $content['context']['power_awareness_enabled'] = isset($values['context_power_awareness_enabled']);
        $content['context']['hide_ambient_combat'] = isset($values['context_hide_ambient_combat']);
        $content['context']['ground_items_descriptions_only'] = isset($values['context_ground_items_descriptions_only']);
        $content['context']['inventory_items_descriptions_only'] = isset($values['context_inventory_items_descriptions_only']);
        $eventTypes=$values['context_event_types']??[];if(!is_array($eventTypes))throw new InvalidArgumentException('invalid_context_event_types');
        $content['context']['event_types']=array_values($eventTypes);
        foreach(['location_blacklist','item_blacklist','magic_effects_blacklist']as$field){
            $raw=(string)($values['context_'.$field]??'');$content['context'][$field]=preg_split('/\R/u',$raw)?:[];
        }
        $content['relationship']=['enabled'=>isset($values['relationship_enabled']),
            'update_chance_percent'=>$integer($values,'relationship_update_chance_percent',0)];
        foreach(SettingsCatalog::systemRoutingFields()as$field){$value=trim((string)($values[$field]??''));
            if($value!=='')$this->uuid($value,$field);$content['system_routing'][$field]=$value;}
        return EffectiveSettingsResolver::validateGlobalSettings($content);
    }

    /** Convert labelled NPC editor fields into the bounded roleplay document consumed by prompts and TTS. */
    private function profileContent(array $values,bool $allowSpecialTtsRouting=false):array
    {
        $content=isset($values['base_content_json'])?$this->jsonField($values,'base_content_json'):[];
        foreach(['prompt_head','core','appearance','biography','personality','speech_style','occupation','skills','goals','relationships','emote_moods','gender','race','tags','notes']as$field){
            if(!array_key_exists($field,$values))continue;
            $value=trim((string)($values[$field]??''));if($value!=='')$content[$field]=$value;else unset($content[$field]);
        }
        if(array_key_exists('oghma_knowledge_tags',$values)){
            $tags=$values['oghma_knowledge_tags'];
            if(!is_string($tags)||strlen($tags)>4096||!mb_check_encoding($tags,'UTF-8'))
                throw new InvalidArgumentException('invalid_oghma_knowledge_tags');
            $tags=$this->npcKnowledgeTags($tags);
            unset($content['oghma_tags']);
            if($tags==='')unset($content['oghma_knowledge_tags']);else$content['oghma_knowledge_tags']=$tags;
        }
        if(array_key_exists('voice_id',$values)){$voice=trim((string)$values['voice_id']);$language=trim((string)($values['voice_language']??'en'));
            if($voice!=='')$content['voice']=['id'=>$voice,'language'=>$language===''?'en':$language];else unset($content['voice']);}
        // Only an explicit override-editor submission changes these leaves; ordinary saves preserve them.
        if ($allowSpecialTtsRouting) unset($content['settings_overrides']);
        elseif (array_key_exists('npc_settings_overrides_json', $values)) {
            $submitted = $this->jsonField($values, 'npc_settings_overrides_json');
            $catalog = SettingsCatalog::npcOverrideFields();
            foreach ($submitted as $section => $fields) {
                if (!isset($catalog[$section]) || !is_array($fields)
                    || array_diff(array_keys($fields), $catalog[$section]) !== [])
                    throw new InvalidArgumentException('invalid_npc_settings_override');
            }
            $submitted = EffectiveSettingsResolver::validateSettingsOverrides($submitted, true);
            $overrides = $content['settings_overrides'] ?? [];
            foreach ($catalog as $section => $fields) {
                foreach ($fields as $field) unset($overrides[$section][$field]);
                if (($overrides[$section] ?? null) === []) unset($overrides[$section]);
            }
            // Diary overrides keep their established NPC owner alongside the separate Auto Diary switches.
            $diary = $content['diary'] ?? [];
            foreach ($catalog['diary'] as $field) unset($diary[$field]);
            $diary = array_replace($diary, $submitted['diary'] ?? []);
            if ($diary === []) unset($content['diary']);
            else $content['diary'] = DiaryGenerationPolicy::validateOverrides($diary);
            unset($submitted['diary']);
            foreach ($submitted as $section => $fields)
                $overrides[$section] = array_replace($overrides[$section] ?? [], $fields);
            if ($overrides === []) unset($content['settings_overrides']);
            else $content['settings_overrides'] = $overrides;
        }
        if(array_key_exists('npc_relationship_locked',$values)){
            $value=$values['npc_relationship_locked'];
            if(!in_array($value,['inherit','0','1'],true))throw new InvalidArgumentException('invalid_npc_relationship_override');
            $relationship=is_array($content['relationship']??null)?$content['relationship']:[];
            if($value==='inherit')unset($relationship['locked']);else$relationship['locked']=$value==='1';
            if($relationship===[])unset($content['relationship']);else$content['relationship']=$relationship;
        }
        // NPC diary switches override only their own leaves; unrelated saves retain inheritance.
        foreach(['automatic_enabled','automatic_wait_enabled']as$field){
            $key='npc_diary_'.$field;if(!array_key_exists($key,$values))continue;
            $value=$values[$key];if(!in_array($value,['inherit','0','1'],true))throw new InvalidArgumentException('invalid_npc_diary_override');
            $diary=is_array($content['diary']??null)?$content['diary']:[];
            if($value==='inherit')unset($diary[$field]);else$diary[$field]=$value==='1';
            if($diary===[])unset($content['diary']);else$content['diary']=DiaryGenerationPolicy::validateOverrides($diary);
        }
        if($allowSpecialTtsRouting){
            $routing=[];$id=trim((string)($values['tts_configuration_id']??''));
            if($id==='__disabled__')$routing['tts_configuration_id']='';elseif($id!==''){$this->uuid($id,'tts_configuration_id');$routing['tts_configuration_id']=$id;}
            $autochat=trim((string)($values['player_autochat_configuration_id']??''));
            if($autochat==='__disabled__')$routing['player_autochat_configuration_id']='';
            elseif($autochat!==''){$this->uuid($autochat,'player_autochat_configuration_id');$routing['player_autochat_configuration_id']=$autochat;}
            if($routing===[])unset($content['routing']);else$content['routing']=$routing;
        }else unset($content['routing']);
        if(isset($content['oghma_knowledge_tags']))$content['oghma_knowledge_tags']=$this->npcKnowledgeTags($content['oghma_knowledge_tags']);
        if(array_key_exists('management_fields',$values))$content['management']=[
            'locked'=>isset($values['locked']),'favorite'=>isset($values['favorite'])];
        if(array_key_exists('dynamic_profile_fields_present',$values)){
            $requested=$values['dynamic_profile_fields']??[];if(!is_array($requested))throw new InvalidArgumentException('invalid_dynamic_profile_fields');
            foreach(EffectiveSettingsResolver::DYNAMIC_PROFILE_FIELDS as$field)if(isset($values['dynamic_profile_'.$field]))$requested[]=$field;
            $fields=[];foreach(EffectiveSettingsResolver::DYNAMIC_PROFILE_FIELDS as$field)if(in_array($field,$requested,true))$fields[]=$field;
            if(isset($values['dynamic_profile'])&&$fields===[])throw new InvalidArgumentException('invalid_dynamic_profile_fields');
            $content['dynamic_profile']=isset($values['dynamic_profile']);
            $content['dynamic_profile_fields']=$fields===[]?['personality','speech_style','goals']:$fields;
        }
        return$content;
    }

    /** Add the player-only biography audience flag to the shared bounded profile document. */
    private function playerContent(array $values):array
    {
        $content=$this->profileContent($values,true);
        if(array_key_exists('player_elevenlabs_present',$values)){
            $overrides=[];
            foreach(['model_id','speed','stability','similarity_boost','style','use_speaker_boost','v3_audio_tags']as$field){
                $value=trim((string)($values['tts_elevenlabs_'.$field]??''));if($value==='')continue;
                if(in_array($field,['speed','stability','similarity_boost','style'],true)){
                    if(!is_numeric($value))throw new InvalidArgumentException('invalid_player_tts_overrides');$value=(float)$value;
                }elseif($field==='use_speaker_boost'){
                    if(!in_array($value,['true','false'],true))throw new InvalidArgumentException('invalid_player_tts_overrides');$value=$value==='true';
                }
                $overrides[$field]=$value;
            }
            if($overrides===[])unset($content['player_elevenlabs']);
            else $content['player_elevenlabs']=\LorkhanServer\Application\CloudSpeechConnectorProvider::validatePlayerOverrides($overrides);
        }
        if(array_key_exists('biography_known_by_all',$values)){
            $value=$values['biography_known_by_all'];
            if(is_bool($value))$content['biography_known_by_all']=$value;
            elseif(in_array($value,['0','1'],true))$content['biography_known_by_all']=$value==='1';
            else throw new InvalidArgumentException('invalid_biography_visibility');
        }
        if(array_key_exists('diary_interval_seconds',$values))$content['diary']=$this->automaticDiaryFormContent($values);
        return$content;
    }

    /** Convert the shared Player and Narrator diary controls into bounded profile overrides. */
    private function automaticDiaryFormContent(array $values):array
    {
        $enabled=static fn(string$key):bool=>filter_var($values[$key]??false,FILTER_VALIDATE_BOOL);
        $interval=filter_var($values['diary_interval_seconds']??120,FILTER_VALIDATE_INT);
        if($interval===false)throw new InvalidArgumentException('invalid_diary_interval_seconds');
        return DiaryGenerationPolicy::validateOverrides(['enabled'=>$enabled('diary_enabled'),
            'automatic_enabled'=>$enabled('auto_diary_enabled'),
            'automatic_wait_enabled'=>$enabled('auto_diary_wait_enabled'),
            'automatic_interval_seconds'=>(int)$interval]);
    }

    /** Remove article-only markers before management forms write NPC access permissions. */
    private function npcKnowledgeTags(mixed $value):string
    {
        $tags=[];foreach(preg_split('/\s*[,|;]\s*/u',trim((string)$value))?:[]as$tag){$tag=trim($tag);
            if($tag===''||in_array(mb_strtolower($tag,'UTF-8'),['common','esoteric'],true)||in_array($tag,$tags,true))continue;
            $tags[]=$tag;}
        return implode(', ',$tags);
    }

    /** Apply the installation auto-lock preference when an NPC is created through management. */
    private function createNpcProfile(array $values,array $scope):array
    {
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');$content=$this->profileContent($values);
        if($this->repository->profileAutoLockEnabled($installation)){$management=is_array($content['management']??null)?$content['management']:[];$management['locked']=true;$management['favorite']=($management['favorite']??false)===true;$content['management']=$management;}
        $input=['installation_id'=>$installation,'name'=>$this->need($values,'name'),'actor_identity'=>$this->profileIdentity($values),'content'=>$content];
        if(isset($values['core_profile_id'])&&trim((string)$values['core_profile_id'])!==''){$this->uuid((string)$values['core_profile_id'],'core_profile_id');$input['core_profile_id']=(string)$values['core_profile_id'];}
        return$this->service->createRevisioned('profile',$input);
    }

    /** Explicit template reset is a reversible profile revision, never a save-game reset. */
    private function resetNpcBiography(array $values):array
    {
        $id=$this->need($values,'profile_id');$this->uuid($id,'profile_id');
        $expected=filter_var($values['base_revision']??null,FILTER_VALIDATE_INT);
        if($expected===false||$expected<1||($values['confirm_reset']??'')!=='1')throw new InvalidArgumentException('invalid_profile_reset');
        return$this->repository->transaction(function()use($id,$expected):array{
            $profile=$this->repository->getRevisioned('profile',$id);
            $content=$this->repository->biographyResetContent($profile);
            if($this->repository->profileAutoLockEnabled((string)$profile['installation_id']))$content['management']['locked']=true;
            return$this->service->revise('profile',$id,$content,'Reset biography from template',$expected);
        });
    }

    /** Configure the selected Core Profile and installation speech routes in one transaction. */
    private function saveQuickstart(array $values,array $scope):array
    {
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');
        $id=$this->need($values,'core_profile_id');$this->persistentUuid($id,'core_profile_id');
        $expected=filter_var($values['base_revision']??null,FILTER_VALIDATE_INT);
        if($expected===false||$expected<1)throw new InvalidArgumentException('invalid_core_profile_revision');
        return$this->repository->transaction(function()use($values,$installation,$id,$expected):array{
            $player2Routing=$this->repository->player2Routing();
            $player2=$player2Routing->state($installation);
            if(array_key_exists('player2_revision',$values)){
                $revision=filter_var($values['player2_revision'],FILTER_VALIDATE_INT);
                if($revision===false||$revision<0)throw new InvalidArgumentException('invalid_player2_revision');
                $player2=$player2Routing->save($installation,isset($values['player2_force_all_llm']),$revision,gmdate(DATE_ATOM));
            }
            $profile=$this->repository->getRevisioned('core_profile',$id);
            if($profile['installation_id']!==$installation)throw new InvalidArgumentException('invalid_core_profile');
            if((int)$profile['current_revision']!==$expected)throw new RuntimeException('revision_conflict');
            $preset=$values['settings_preset']??'';
            if(!in_array($preset,['','builtin:default','builtin:local_llm'],true))throw new InvalidArgumentException('invalid_quickstart_preset');
            $presetPlan=null;
            if($preset!==''){
                $presetPlan=$this->repository->applyQuickstartCorePreset($installation,$preset,$this->need($values,'setup_fingerprint'),gmdate(DATE_ATOM));
                ++$expected;$profile=$this->repository->getRevisioned('core_profile',$id);
                $stored=$this->repository->globalSettingsForInstallation($installation);
                $settings=EffectiveSettingsResolver::globalDocument($stored['content']??[],
                    $this->repository->oghmaSettings($installation),$this->repository->translationPolicyForInstallation($installation)['content'],
                    $this->repository->profileAutoLockEnabled($installation));
                $settings=\LorkhanServer\Application\GlobalSettingsPreset::applyBuiltIn($preset,$settings);
                if($stored===null)$this->service->createRevisioned('global_settings',['installation_id'=>$installation,'name'=>'Global Settings','content'=>$settings]);
                else $this->service->revise('global_settings',$stored['configuration_id'],$settings,'Quickstart global preset',(int)$stored['current_revision']);
                $summary=$this->repository->memorySummaryPolicyForInstallation($installation)['content']
                    ??['schema'=>'lorkhan.memory-policy.v1','enabled'=>false,'provider_configuration_id'=>''];
                $embedding=$this->repository->memoryEmbeddingPolicyForInstallation($installation)['content']
                    ??\LorkhanServer\Application\MemoryEmbeddingPolicy::defaults();
                $summaryFallback=(string)($values['llm_fast_configuration_id']??($profile['content']['routing']['llm_fast_configuration_id']??''));
                if($summaryFallback===''&&$player2['enabled'])$summaryFallback=$player2['configuration_id'];
                $memory=\LorkhanServer\Application\GlobalSettingsPreset::builtInMemory($preset,$summary,$embedding,$summaryFallback);
                foreach($memory as $kind=>$policy){
                    $enabled=$policy['enabled'];unset($policy['enabled']);if($enabled)$policy['enabled']='1';
                    if($kind==='summary')$this->saveMemoryPolicy($policy,['installation_id'=>$installation]);
                    else $this->saveMemoryEmbeddingPolicy($policy,['installation_id'=>$installation]);
                }
                $presetPlan=$this->repository->quickstartLocalRoutingPlan($installation);
            }
            $local=$preset==='builtin:local_llm'&&!$player2['enabled'];$localId=null;
            if($local){
                $timeout=filter_var($values['local_timeout']??null,FILTER_VALIDATE_INT);
                if($timeout===false)throw new InvalidArgumentException('invalid_local_llm_setup');
                $setup=['server_type'=>$this->need($values,'local_server'),'scope'=>$this->need($values,'local_scope'),
                    'endpoint'=>$this->need($values,'local_endpoint'),'model'=>$this->need($values,'local_model'),
                    'timeout_seconds'=>$timeout,'disable_streaming'=>isset($values['local_disable_streaming'])];
                // The optional key is flushed through the private key endpoint before submitting this form.
                if(($values['local_key_configured']??'')==='1')$setup['credential']='badge:'.\LorkhanServer\Application\QuickstartLocalLlm::CREDENTIAL;
                $saved=$this->repository->applyQuickstartLocalLlm($installation,$setup,$presetPlan['fingerprint'],gmdate(DATE_ATOM));
                $localId=$saved['configuration_id'];
                $profile=$this->repository->getRevisioned('core_profile',$id);
                if(in_array($id,array_column($saved['routing_plan']['core_profiles'],'core_profile_id'),true))++$expected;
                if((int)$profile['current_revision']!==$expected)throw new RuntimeException('revision_conflict');
            }
            $content=$profile['content'];
            foreach(['llm_configuration_id','llm_fast_configuration_id','llm_powerful_configuration_id','llm_experimental_configuration_id']as$field){
                if($player2['enabled'])continue;
                $connector=$localId??$this->need($values,$field);$this->uuid($connector,$field);
                $row=$this->repository->getRevisioned('provider',$connector);
                if($row['installation_id']!==$installation)throw new InvalidArgumentException('invalid_provider');
                $content['routing'][$field]=$connector;
            }
            foreach(['tts_provider','stt_provider']as$kind){
                $connector=trim((string)($values[$kind]??''));
                if($connector==='')continue;
                if(str_starts_with($connector,'service:'))$connector=$this->repository->ensureQuickstartSpeechConnector(
                    $installation,$kind,substr($connector,8),gmdate(DATE_ATOM));
                $this->service->selectConnector(['installation_id'=>$installation,'kind'=>$kind,'configuration_id'=>$connector]);
                if($kind==='tts_provider')$content['routing']['tts_configuration_id']=$connector;
            }
            $result=$this->service->revise('core_profile',$id,$content,'Quickstart connector selection',$expected);
            if(array_key_exists('player_name',$values)){
                $playerRevision=filter_var($values['player_revision']??null,FILTER_VALIDATE_INT);
                if($playerRevision===false||$playerRevision<1)throw new InvalidArgumentException('invalid_player_revision');
                $this->service->renamePlayer($installation,$this->need($values,'player_name'),$playerRevision);
            }
            return $result;
        });
    }

    /** Save a manual NPC revision and auto-lock it when that installation preference is enabled. */
    private function reviseNpcProfile(array $values):array
    {
        return $this->repository->transaction(function()use($values):array{
        $profileId=$this->need($values,'profile_id');$profile=$this->repository->getRevisioned('profile',$profileId);$identity=$profile['actor_identity']??[];
        if(is_string($identity))$identity=json_decode($identity,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($identity)||array_is_list($identity)||in_array($identity['kind']??'actor',['player','narrator'],true))throw new InvalidArgumentException('profile_not_editable');
        $content=$this->profileContent($values);if($this->repository->profileAutoLockEnabled((string)$profile['installation_id'])){
            $management=is_array($content['management']??null)?$content['management']:[];$management['locked']=true;$management['favorite']=($management['favorite']??false)===true;$content['management']=$management;}
        $batch=trim((string)($values['npc_relationship_edits']??''))===''?null:$this->jsonField($values,'npc_relationship_edits');
        if($batch!==null&&(!is_int($batch['profile_revision']??null)||$batch['profile_revision']<1))throw new InvalidArgumentException('invalid_expected_revision');
        if($batch!==null)$batch=$this->resolveNpcRelationshipPreviews($batch,['installation_id'=>(string)$profile['installation_id'],'profile_id'=>$profileId]);
        $revised=$this->service->revise('profile',$profileId,$content,$this->need($values,'change_reason'),$batch['profile_revision']??null);
        if(isset($values['core_profile_id'])&&trim((string)$values['core_profile_id'])!==''){
            $this->uuid((string)$values['core_profile_id'],'core_profile_id');$this->repository->assignCoreProfile($profileId,(string)$values['core_profile_id']);
            $revised=$this->repository->getRevisioned('profile',$profileId);
        }
        if($batch!==null)$this->saveNpcRelationships($batch,['installation_id'=>(string)$profile['installation_id'],'profile_id'=>$profileId]);
        return$revised;
        });
    }

    /** Resolve generated targets from scoped receipts, never from client-supplied actor identities. */
    private function resolveNpcRelationshipPreviews(array $batch,array $scope):array
    {
        $scope['playthrough_id']=$this->need($batch,'playthrough_id');$previews=[];
        foreach(['updates','additions'] as $mode){
            if(!is_array($batch[$mode]??null)||!array_is_list($batch[$mode]))throw new InvalidArgumentException('invalid_relationship_batch');
            foreach($batch[$mode] as &$edit){
                if(!is_array($edit)||array_key_exists('_preview_identity',$edit))throw new InvalidArgumentException('invalid_relationship_batch');
                if(!isset($edit['preview_job_id']))continue;
                $job=$this->need($edit,'preview_job_id');$this->uuid($job,'job_id');
                $preview=$previews[$job]??=$this->repository->relationshipBuildPreviewStatus($scope,$job);
                if($preview['state']!=='ready'||$preview['profile_revision']!==$batch['profile_revision'])throw new RuntimeException('relationship_revision_conflict');
                $key=$this->need($edit,'preview_target_key');$candidate=null;
                foreach($preview['relationships'] as $row)if(($row['target_key']??null)===$key){$candidate=$row;break;}
                if($candidate===null)throw new InvalidArgumentException('invalid_relationship_build_target');
                if($mode==='updates'){
                    if(($candidate['relationship_id']??null)!==($edit['relationship_id']??null))throw new InvalidArgumentException('invalid_relationship_build_target');
                }else{
                    if(!isset($candidate['actor_identity'])||isset($candidate['relationship_id'])||!empty($edit['actor_profile_id']))throw new InvalidArgumentException('invalid_relationship_build_target');
                    $edit['_preview_identity']=$candidate['actor_identity'];
                }
            }unset($edit);
        }
        return $batch;
    }

    /** Apply a staged editor batch inside the NPC revision transaction; omitted records remain untouched. */
    private function saveNpcRelationships(array $batch,array $scope):void
    {
        if(array_diff(array_keys($batch),['profile_revision','playthrough_id','updates','additions','deletes','clear_snapshot','clear_confirm'])!==[])
            throw new InvalidArgumentException('invalid_relationship_batch');
        $playthrough=$this->need($batch,'playthrough_id');$this->uuid($playthrough,'playthrough_id');$scope['playthrough_id']=$playthrough;
        if($this->repository->getRevisioned('playthrough',$playthrough)['installation_id']!==$scope['installation_id'])
            throw new InvalidArgumentException('invalid_relationship_scope');
        $count=0;
        foreach(['updates','additions','deletes']as$key){
            if(!is_array($batch[$key]??null)||!array_is_list($batch[$key]))throw new InvalidArgumentException('invalid_relationship_batch');
            $count+=count($batch[$key]);
        }
        if($count>200)throw new InvalidArgumentException('relationship_batch_too_large');
        if(isset($batch['clear_snapshot'])){
            if(($batch['clear_confirm']??null)!=='Clear')throw new InvalidArgumentException('confirmation_required');
            if(!is_string($batch['clear_snapshot'])||$batch['updates']!==[]||$batch['deletes']!==[])throw new InvalidArgumentException('invalid_relationship_batch');
            $this->repository->clearRelationships($scope,$batch['clear_snapshot'],gmdate('Y-m-d\TH:i:s\Z'));
        }
        $seen=[];
        foreach(['updates','deletes']as$mode)foreach($batch[$mode]as$edit){
            if(!is_array($edit))throw new InvalidArgumentException('invalid_relationship_batch');
            $id=$this->need($edit,'relationship_id');$this->uuid($id,'relationship_id');
            if(isset($seen[$id]))throw new InvalidArgumentException('duplicate_relationship_edit');$seen[$id]=true;
            if($mode==='updates')$this->saveRelationship($edit,$scope,[]);
            else$this->repository->deleteRelationship($id,gmdate('Y-m-d\TH:i:s\Z'),$this->relationshipRevision($edit),$scope);
        }
        foreach($batch['additions']as$edit){
            if(!is_array($edit)||isset($edit['relationship_id']))throw new InvalidArgumentException('invalid_relationship_batch');
            if(!isset($edit['_preview_identity'])){
                $target=$this->need($edit,'actor_profile_id');
                if($target===$scope['profile_id'])throw new InvalidArgumentException('invalid_actor_profile');
            }
            $this->saveRelationship($edit,$scope,$edit['_preview_identity']??[]);
        }
    }

    /** Toggle one card-level management flag without applying the installation edit auto-lock rule. */
    private function toggleNpcProfileManagement(array $values,string $field):array
    {
        if(!in_array($field,['favorite','locked'],true))throw new InvalidArgumentException('invalid_management_field');
        $profileId=$this->need($values,'profile_id');$profile=$this->repository->getRevisioned('profile',$profileId);
        $identity=$profile['actor_identity']??[];if(is_string($identity))$identity=json_decode($identity,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($identity)||array_is_list($identity)||in_array($identity['kind']??'actor',['player','narrator'],true))throw new InvalidArgumentException('profile_not_editable');
        $content=$profile['content']??[];if(is_string($content))$content=json_decode($content,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($content)||array_is_list($content))$content=[];
        $management=is_array($content['management']??null)&&!array_is_list($content['management'])?$content['management']:[];
        $other=$field==='favorite'?'locked':'favorite';$management[$field]=($management[$field]??false)!==true;$management[$other]=($management[$other]??false)===true;
        $content['management']=$management;
        return$this->service->revise('profile',$profileId,$content,'management '.$field.' toggle');
    }

    /** Apply typed-confirmation bulk NPC operations within one installation only. */
    private function bulkGenerateProfiles(array $values,array $scope):array
    {
        if(!hash_equals('Generate',$this->need($values,'confirm')))throw new InvalidArgumentException('confirmation_mismatch');
        return$this->repository->bulkEnqueueNpcProfileGeneration($scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'));
    }

    private function bulkUnlockProfiles(array $values,array $scope):int
    {
        if(!hash_equals('Unlock',$this->need($values,'confirm')))throw new InvalidArgumentException('confirmation_mismatch');
        return$this->repository->bulkUnlockNpcProfiles($scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),gmdate('Y-m-d\TH:i:s\Z'));
    }

    private function bulkDeleteProfiles(array $values,array $scope):int
    {
        if(!hash_equals('Delete',$this->need($values,'confirm')))throw new InvalidArgumentException('confirmation_mismatch');
        return$this->repository->bulkDeleteUnlockedNpcProfiles($scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),gmdate('Y-m-d\TH:i:s\Z'));
    }

    private function bulkSwitchProfiles(array $values,array $scope):array
    {
        if(!hash_equals('Switch',$this->need($values,'confirm')))throw new InvalidArgumentException('confirmation_mismatch');
        $source=$this->need($values,'source_profile_id');$target=$this->need($values,'target_profile_id');
        $this->uuid($source,'source_profile_id');$this->uuid($target,'target_profile_id');
        return$this->repository->bulkSwitchNpcCoreProfiles($scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),
            $source,$target,in_array($values['include_locked']??null,['1','true',true,1],true));
    }

    /** Exercise a saved connector without storing the fixed test phrase or generated media. */
    private function testConnector(array $values):string
    {
        $installation=$this->need($values,'installation_id');$this->uuid($installation,'installation_id');
        $configuration=$this->need($values,'configuration_id');$this->uuid($configuration,'configuration_id');
        $kind=$this->need($values,'kind');if(!in_array($kind,['tts_provider','stt_provider'],true))throw new InvalidArgumentException('invalid_connector_kind');
        $preset=$this->repository->getRevisioned($kind,$configuration);$token=new NeverCancelledToken();$started=microtime(true);
        if (($preset['installation_id'] ?? '') !== $installation) throw new InvalidArgumentException('invalid_provider_scope');
        if($kind==='tts_provider'){
            $voice=trim((string)($values['voice_id']??''));$context=$voice===''?[]:['voice'=>$voice];
            $audio=ProviderFactory::speechForPreset($this->providerConfig,$preset)->synthesize('Greetings, traveler. This is LORKHAN.',$token,$context);
            return strtoupper((string)$audio['codec']).' '.(int)$audio['duration_ms'].' ms in '.(int)round((microtime(true)-$started)*1000).' ms';
        }
        $result = $this->testSpeechToText($values);
        return $result['transcript'] . ' (' . $result['elapsed_ms'] . ' ms)';
    }

    /** Test only STT with a fixed owned sample, never requiring or calling a TTS connector. */
    private function testSpeechToText(array $values): array
    {
        $installation = $this->need($values, 'installation_id'); $this->uuid($installation, 'installation_id');
        $configuration = $this->need($values, 'configuration_id'); $this->uuid($configuration, 'configuration_id');
        $preset = $this->repository->getRevisioned('stt_provider', $configuration);
        if (($preset['kind'] ?? '') !== 'stt_provider') throw new InvalidArgumentException('invalid_connector_kind');
        if (($preset['installation_id'] ?? '') !== $installation) throw new InvalidArgumentException('invalid_provider_scope');
        $content = is_array($preset['content'] ?? null) ? $preset['content'] : [];
        if (($content['driver'] ?? '') === 'none') throw new InvalidArgumentException('stt_service_disabled');
        $started = microtime(true);
        try {
            $result = ProviderFactory::speechToTextForPreset($this->providerConfig, $preset)
                ->transcribe(SttTestSample::bytes(), 'wav', 'en', new NeverCancelledToken());
            $text = trim((string) ($result['text'] ?? ''));
            if ($text === '' || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > 4096) throw new RuntimeException('stt_test_failed');
        } catch (Throwable) { throw new RuntimeException('stt_test_failed'); }
        return ['transcript'=>$text, 'similarity_percent'=>SttTestSample::similarity($text),
            'elapsed_ms'=>(int) round((microtime(true)-$started)*1000), 'driver'=>(string) ($content['driver'] ?? '')];
    }

    /** List the connector and installed-voice choices the pronunciation preview strip may offer. */
    private function speechPreviewOptions(string $installation):array
    {
        $narrator=$this->repository->narratorProfileForInstallation($installation);
        return SpeechPreviewCatalog::options($this->repository->listRevisioned('tts_provider',$installation),
            $this->repository->connectorVoiceCatalog(),(string)($this->providerConfig['voice_storage_path']??''),
            (string)($this->repository->connectorForInstallation($installation,'tts_provider')['configuration_id']??''),
            SpeechPreviewCatalog::narratorVoice($narrator),SpeechPreviewCatalog::narratorConnector($narrator));
    }

    /** Authorize a saved diary before resolving its author, generating speech or reading cached audio. */
    private function diaryAudio(array $values,string $browserSession):Response
    {
        $installation=$this->need($values,'installation_id');$this->uuid($installation,'installation_id');
        $entry=$this->need($values,'narrative_id');$this->uuid($entry,'narrative_id');
        if(!$this->management->allowTtsPreview($browserSession))return Response::json(429,['error'=>'diary_audio_rate_limited']);
        try {
            $plan=$this->repository->diarySpeechPlan($installation,$entry);
            if(mb_strlen($plan['text'],'UTF-8')>65536)return Response::json(422,['error'=>'diary_audio_entry_too_long']);
            $signature=json_encode([$installation,$entry,$plan['profile_id'],$plan['text'],$plan['connector'],$plan['context']],JSON_THROW_ON_ERROR);
            $cache=new \LorkhanServer\Infrastructure\DiaryAudioCache(dirname((string)($this->providerConfig['media_storage_path']??'/var/lib/lorkhanserver/media')).'/diary-audio');
            $audio=$cache->remember($signature,fn()=>ProviderFactory::speechForPreset($this->providerConfig,$plan['connector'])
                ->synthesize($plan['text'],new NeverCancelledToken(),$plan['context']));
            return new Response(200,$audio['bytes'],['Content-Type'=>$audio['mime_type'],'Content-Disposition'=>'inline',
                'Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff','X-Diary-Audio-Cache'=>$audio['cached']?'hit':'miss']);
        } catch(Throwable $error) {
            $code=$error->getMessage();
            if($code==='diary_audio_entry_not_found')return Response::json(404,['error'=>$code]);
            if($code==='diary_audio_busy')return Response::json(409,['error'=>$code]);
            if(in_array($code,['diary_audio_empty_entry','diary_audio_connector_not_configured','diary_audio_voice_not_configured'],true))
                return Response::json(422,['error'=>$code]);
            return Response::json(502,['error'=>'diary_audio_failed']);
        }
    }

    /**
     * Speak exactly one bounded pronunciation field with an explicitly chosen connector and voice.
     * Nothing is queued, stored, or logged: the audio is streamed straight back to the browser and
     * any provider failure collapses into one opaque code so credentials never reach the page.
     */
    private function speechPreview(array $values,string $browserSession):Response
    {
        if(!$this->management->allowTtsPreview($browserSession))return Response::json(429,['error'=>'tts_preview_rate_limited']);
        $installation=$this->need($values,'installation_id');$this->uuid($installation,'installation_id');
        $configuration=$this->need($values,'configuration_id');$this->uuid($configuration,'configuration_id');
        $text=trim((string)($values['text']??''));
        if($text===''||!mb_check_encoding($text,'UTF-8')||mb_strlen($text,'UTF-8')>SpeechPreviewCatalog::MAX_TEXT_LENGTH
            ||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$text)===1)throw new InvalidArgumentException('invalid_tts_preview_text');
        $voice=trim((string)($values['voice']??''));
        $options=$this->speechPreviewOptions($installation);
        $offered=array_column($options['connectors'],'voices','id');
        if(!isset($offered[$configuration]))throw new InvalidArgumentException('invalid_tts_preview_connector');
        // A voice is only accepted for the connector that actually lists it, so a local sample
        // name can never be posted to a provider that needs its own voice id.
        if(!in_array($voice,$offered[$configuration],true))throw new InvalidArgumentException('invalid_tts_preview_voice');
        $preset=$this->repository->getRevisioned('tts_provider',$configuration);
        if(($preset['installation_id']??null)!==$installation)throw new InvalidArgumentException('invalid_provider_scope');
        $context=['voice'=>$voice];
        if(($preset['content']['driver']??'')==='omnivoice'&&isset($values['language'])){
            $language=strtolower(trim((string)$values['language']));
            if(preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D',$language)!==1)throw new InvalidArgumentException('invalid_voice_language');
            $context['language']=$language;
        }
        try{
            $audio=ProviderFactory::speechForPreset($this->providerConfig,$preset)
                ->synthesize($text,new NeverCancelledToken(),$context);
            $bytes=(string)($audio['bytes']??'');
            if($bytes===''||strlen($bytes)>self::MAX_PREVIEW_AUDIO_BYTES)throw new RuntimeException('tts_preview_failed');
        }catch(Throwable){return Response::json(502,['error'=>'tts_preview_failed']);}
        $mime=(string)($audio['mime_type']??'');
        if(!in_array($mime,self::PREVIEW_AUDIO_MIME_TYPES,true))$mime='application/octet-stream';
        return new Response(200,$bytes,['Content-Type'=>$mime,'Content-Disposition'=>'inline','X-Content-Type-Options'=>'nosniff']);
    }

    /** Exercise one saved LLM model slot without persisting the fixed diagnostic turn or its response. */
    private function testProvider(array $values,?array &$diagnostics=null):string
    {
        $installation=$this->need($values,'installation_id');$this->uuid($installation,'installation_id');
        $configuration=$this->need($values,'configuration_id');$this->uuid($configuration,'configuration_id');
        $preset=$this->repository->getRevisioned('provider',$configuration);
        if(($preset['installation_id']??null)!==$installation)throw new InvalidArgumentException('invalid_provider_scope');
        $slot=['configuration_id'=>$configuration,'revision'=>(int)($preset['current_revision']??0),'content'=>$preset['content']??[]];
        if($diagnostics!==null)$diagnostics=['connector'=>['name'=>(string)$preset['name'],'revision'=>$slot['revision'],
            'driver'=>(string)$slot['content']['driver'],'model'=>(string)$slot['content']['model']]];
        return$this->diagnoseProvider(ProviderFactory::dialogueForSlot($this->providerConfig,$slot),$diagnostics);
    }

    /** Run one explicitly requested Core Profile connector check and return only its redacted outcome. */
    private function runProfileConnectorTest(array $values):array
    {
        $installation=$this->need($values,'installation_id');$this->uuid($installation,'installation_id');
        $configuration=$this->need($values,'configuration_id');$this->uuid($configuration,'configuration_id');
        $kind=$this->need($values,'kind');if(!in_array($kind,['provider','tts_provider'],true))throw new InvalidArgumentException('invalid_connector_kind');
        $preset=$this->repository->getRevisioned($kind,$configuration);
        if(($preset['installation_id']??null)!==$installation)throw new InvalidArgumentException('invalid_provider_scope');
        $detail=$kind==='provider'
            ?$this->testProvider(['installation_id'=>$installation,'configuration_id'=>$configuration])
            :$this->testConnector(['installation_id'=>$installation,'configuration_id'=>$configuration,'kind'=>'tts_provider']);
        return['job_key'=>$kind.':'.$configuration,'kind'=>$kind,'configuration_id'=>$configuration,'status'=>'pass','message'=>$detail];
    }

    /** Validate one dialogue provider against the common utterance contract without saving its output. */
    private function diagnoseProvider(Provider $provider,?array &$diagnostics=null):string
    {
        $identity=['kind'=>'npc','display_name'=>'LORKHAN Test NPC','record_id'=>'lorkhan_test_npc','content_file'=>'LORKHAN'];
        $player=['kind'=>'player','display_name'=>'Player','record_id'=>'player'];$started=microtime(true);
        $turn=['payload'=>[
            'input'=>['mode'=>'text','text'=>'Reply with one brief in-character greeting.'],'speaker'=>$player,'target'=>$identity,'audience'=>[$identity],
        ]];
        if($diagnostics!==null&&$provider instanceof \LorkhanServer\Application\OpenAiCompatibleProvider){
            $result=$provider->completeStreaming($turn,new NeverCancelledToken(),static function(string $delta):void{},
                static function(string $stage,array $body)use(&$diagnostics):void{$diagnostics[$stage]=$body;});
            $diagnostics['usage']=$provider->reportedUsage();
        }else{
            $result=$provider->complete($turn,new NeverCancelledToken());
            if($diagnostics!==null){$diagnostics['input']=$turn;$diagnostics['response']=\LorkhanServer\Security\Redactor::value($result);}
        }
        $utterances=$result['utterances']??null;
        if(!is_array($utterances)||$utterances===[]||count($utterances)>4||!is_string($utterances[0]['text']??null)||trim($utterances[0]['text'])==='')
            throw new RuntimeException('provider_invalid_output');
        return count($utterances).' valid utterance'.(count($utterances)===1?'':'s').' in '.(int)round((microtime(true)-$started)*1000).' ms';
    }

    /** Keep form number parsing strict and separate new identities from revisioned edits. */
    private function saveRelationship(array $values,array $scope,array $identity):array
    {
        $input=$scope+['source_mode'=>'manual','reason'=>$values['reason']??'management'];
        if(array_key_exists('relationship_type',$values))$input['relationship_type']=$values['relationship_type'];
        if(array_key_exists('custom_info',$values)){
            $input['custom_info']=$values['custom_info'];
            if(is_string($input['custom_info']))$input['custom_info']=str_replace("\r\n","\n",$input['custom_info']);
        }
        if(array_key_exists('details',$values))$input['details']=$values['details'];
        foreach(['disposition','affinity'] as $field){
            $number=filter_var($values[$field]??null,FILTER_VALIDATE_INT);
            if($number===false)throw new InvalidArgumentException('invalid_relationship_value');
            $input[$field]=$number;
        }
        if(isset($values['relationship_id'])&&$values['relationship_id']!==''){
            $input['relationship_id']=$this->need($values,'relationship_id');
            $input['expected_revision']=$this->relationshipRevision($values);
        }else{
            if(isset($values['actor_profile_id'])&&$values['actor_profile_id']!==''){
                $id=$this->need($values,'actor_profile_id');$this->uuid($id,'actor_profile_id');
                $profile=$this->repository->getRevisioned('profile',$id);
                if($profile['installation_id']!==($scope['installation_id']??null))throw new InvalidArgumentException('invalid_actor_profile');
                $identity=is_array($profile['actor_identity'])?$profile['actor_identity']:json_decode($profile['actor_identity'],true,32,JSON_THROW_ON_ERROR);
            }
            $input['actor_identity']=$identity;
        }
        return $this->service->setRelationship($input);
    }

    /** Return to the same standalone page or iframe after saving or refreshing a conflict. */
    private function relationshipPageLocation(array $values,string $status):string
    {
        if(($values['relationship_page']??null)==='npc'){
            $profile=(string)($values['profile_id']??'');$playthrough=(string)($values['playthrough_id']??'');
            $this->uuid($profile,'profile_id');$this->uuid($playthrough,'playthrough_id');
            return $this->characterPageLocation($values,$status).'&'.http_build_query(['rel_profile'=>$profile,'rel_playthrough'=>$playthrough]);
        }
        $query=['status'=>$status];
        foreach(['installation_id','profile_id','playthrough_id','history_limit'] as $field)
            if(is_string($values[$field]??null))$query[$field]=$values[$field];
        if(($values['embed']??null)==='1')$query['embed']='1';
        return $this->webRoot().'/ui/relationship_logs.php?'.http_build_query($query);
    }

    /** Keep the current NPC filters visible after the explicit conversion request. */
    private function characterPageLocation(array $values,string $status,array $summary=[]):string
    {
        $query=['status'=>$status];
        foreach(['embed','fav','lock']as$field)if(($values['ui_'.$field]??null)==='1')$query[$field]='1';
        $search=mb_substr(trim((string)($values['ui_q']??'')),0,100);
        if($search!==''&&mb_check_encoding($search,'UTF-8'))$query['q']=$search;
        $state=(string)($values['ui_state']??'');if(in_array($state,['favorites','locked','unlocked','generated'],true))$query['state']=$state;
        $initial=strtoupper((string)($values['ui_initial']??''));if(preg_match('/^[A-Z]$/D',$initial)===1)$query['initial']=$initial;
        foreach(['profile','installation_id']as$field){$id=(string)($values['ui_'.$field]??'');
            if(preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D',$id)===1)$query[$field]=$id;}
        $page=filter_var($values['ui_page']??null,FILTER_VALIDATE_INT);
        if($page!==false&&$page>1&&$page<=100000)$query['page']=$page;
        foreach(['queued','skipped','no_text','existing','locked','no_connector','no_targets','pending']as$field)
            if(isset($summary[$field]))$query[$field]=(int)$summary[$field];
        return $this->uiPath('characters').'?'.http_build_query($query);
    }

    private function relationshipRevision(array $values):int
    {
        $revision=filter_var($values['expected_revision']??null,FILTER_VALIDATE_INT);
        if($revision===false||$revision<1)throw new InvalidArgumentException('invalid_relationship_revision');
        return $revision;
    }

    /** Rebuild one edited memory's deterministic retrieval fields before saving it. */
    private function reviseMemory(array $values):array
    {
        $id=$this->need($values,'memory_id');$this->uuid($id,'memory_id');$content=$this->need($values,'content');
        if(strlen($content)>16384)throw new InvalidArgumentException('invalid_content');
        return$this->repository->updateMemory($id,$content,DeterministicRetrieval::terms($content),
            DeterministicRetrieval::fakeVector($content),gmdate('Y-m-d\TH:i:s\Z'));
    }

    /** Map Herika-style Oghma controls onto the exact typed static knowledge fields. */
    private function knowledgeFormInput(array $values):array
    {
        $source=trim((string)($values['provenance']??'management'));if($source==='')$source='management';
        $topic=$this->need($values,'topic');$title=trim((string)($values['title']??str_replace('_',' ',$topic)));
        if($title==='')$title=str_replace('_',' ',$topic);
        return['topic'=>$topic,'title'=>$title,'aliases'=>(string)($values['aliases']??''),
            'content'=>$this->need($values,'content'),'knowledge_class'=>(string)($values['knowledge_class']??''),
            'topic_desc_basic'=>(string)($values['topic_desc_basic']??''),'knowledge_class_basic'=>(string)($values['knowledge_class_basic']??''),
            'tags'=>(string)($values['tags']??''),'category'=>(string)($values['category']??''),
            'provenance'=>['source'=>$source,'category'=>(string)($values['category']??'')]];
    }

    private function actionCatalog():string
    {
        $rows='';foreach($this->actions() as$action){$rows.='<tr><td><code>'.$this->e($action['name']).'</code></td><td>'.$action['tier'].'</td><td>'.$this->e($action['capability']).'</td><td>'.$this->e((string)($action['description']??'Catalog-backed typed action.')).'</td></tr>';}
        return'<section><h2>Negotiated action catalog</h2><div class="table-scroll"><table><thead><tr><th>Action</th><th>Tier</th><th>Capability</th><th>Purpose</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
    }
    private function actions():array{return[
        ['name'=>'inspect.report','tier'=>0,'capability'=>'action.inspect.report','description'=>'Read-only observation report.'],
        ['name'=>'inventory.inspect','tier'=>0,'capability'=>'action.inventory.inspect','description'=>'Read-only bounded NPC inventory report.'],
        ['name'=>'ai.follow','tier'=>1,'capability'=>'action.ai.follow','description'=>'Follow the player.'],
        ['name'=>'ai.stop','tier'=>1,'capability'=>'action.ai.stop','description'=>'Stop LORKHAN movement packages.'],
        ['name'=>'ai.approach','tier'=>1,'capability'=>'action.ai.approach','description'=>'Approach the addressed actor in the current cell.'],
        ['name'=>'ai.wait','tier'=>1,'capability'=>'action.ai.wait','description'=>'Wait in place for a bounded duration.'],
        ['name'=>'ai.travel','tier'=>1,'capability'=>'action.ai.travel','description'=>'Travel to a confirmed same-cell destination.'],
        ['name'=>'ai.escort','tier'=>1,'capability'=>'action.ai.escort','description'=>'Escort the player to a confirmed same-cell destination.'],
        ['name'=>'ai.face','tier'=>1,'capability'=>'action.ai.face','description'=>'Turn to face the player or a confirmed actor.'],
        ['name'=>'ai.wander','tier'=>1,'capability'=>'action.ai.wander','description'=>'Bounded local wandering.'],
        ['name'=>'combat.start','tier'=>2,'capability'=>'action.combat.start','description'=>'Start combat after player confirmation.'],
        ['name'=>'combat.stop','tier'=>1,'capability'=>'action.combat.stop','description'=>'Stop combat with the selected target.'],
        ['name'=>'animation.play','tier'=>1,'capability'=>'action.animation.play','description'=>'Play an allowlisted idle animation.'],
        ['name'=>'item.use','tier'=>2,'capability'=>'action.item.use','description'=>'Use an existing inventory item after confirmation.'],
        ['name'=>'item.equip','tier'=>2,'capability'=>'action.item.equip','description'=>'Equip an existing inventory item after confirmation.'],
        ['name'=>'item.unequip','tier'=>2,'capability'=>'action.item.unequip','description'=>'Unequip an occupied slot after confirmation.'],
    ];}
    private function redirect(string $to,array $headers=[]):Response{return new Response(303,'',$headers+['Location'=>$to,'Content-Type'=>'text/plain; charset=utf-8']);}
    /** Resolve legacy management slugs to the canonical sibling-style PHP page. */
    private function uiPath(string $slug):string{return$this->webRoot().(self::UI_PAGES[$slug]??self::UI_PAGES['quickstart']);}

    /** Validate and synchronize the one checked-in Oghma factory dataset. */
    private function syncBundledOghmaCatalog():array
    {
        $importer=$this->oghmaCatalogImporter??throw new RuntimeException('not_found');
        $base=dirname(__DIR__,2).'/data/oghma/morrowind-official';$versionFile=$base.'/active-catalog-version.txt';
        $version=is_file($versionFile)?trim((string)file_get_contents($versionFile)):'';
        if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',$version)!==1)throw new InvalidArgumentException('bundled_oghma_catalog_unavailable');
        $directory=$base.'/catalogs/'.$version;$articles=$directory.'/articles.json';$manifest=$directory.'/manifest.json';
        if(!is_file($articles)||!is_file($manifest))throw new InvalidArgumentException('bundled_oghma_catalog_unavailable');
        return$importer->apply($articles,$manifest,$version);
    }
    private function webRoot():string{return preg_replace('#/manage$#','',$this->basePath)?:'/LorkhanServer';}
    private function html(int $status,string $body):Response{return new Response($status,$body,['Content-Type'=>'text/html; charset=utf-8','Content-Security-Policy'=>"default-src 'none'; style-src 'self'; script-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'self'",'X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer']);}
    private function errorPage(string $e,int $status):Response{return$this->html($status,(new ManagementView($this->basePath))->error($e));}
    private function htmlRequest(Request $r):bool{return!str_contains($r->path,'/api/v1/')
        &&!(str_ends_with($r->path,'/forms/provider-revise')&&str_contains(strtolower($r->header('Accept')??''),'application/json'))
        &&!(str_ends_with($r->path,'/forms/provider-test')&&str_contains(strtolower($r->header('Accept')??''),'application/json'))
        &&!(str_ends_with($r->path,'/forms/relationship-preview')&&str_contains(strtolower($r->header('Accept')??''),'application/json'))
        &&!(str_ends_with($r->path,'/forms/player-speech-style-generate')&&str_contains(strtolower($r->header('Accept')??''),'application/json'))
        &&!((str_ends_with($r->path,'/forms/global-settings-preset')||str_ends_with($r->path,'/forms/core-profile-preset')||str_ends_with($r->path,'/forms/profile-bulk-switch')||str_ends_with($r->path,'/forms/configuration-revise')||str_ends_with($r->path,'/forms/narrator-prompt-save')||str_ends_with($r->path,'/forms/narrator-profile-settings-import'))
            &&str_contains(strtolower($r->header('Accept')??''),'application/json'));}
    private function style():string{return'<style>
:root{--bg:#100f12;--surface:#19171c;--surface-2:#211e24;--line:#3a3237;--line-hot:#856c36;--text:#e8e2d8;--muted:#9e978f;--accent:#bc9d5a;--accent-soft:rgba(188,157,90,.15);--good:#79bf87;--bad:#df7777;color-scheme:dark}
*{box-sizing:border-box}html{background:var(--bg)}body{margin:0;background:radial-gradient(circle at 50% -10%,rgba(107,87,44,.16),transparent 36rem),var(--bg);color:var(--text);font:14px/1.5 "Segoe UI",Arial,sans-serif;min-height:100vh}a{color:#cdb684}a:hover{color:#e4d8bd}button,input,textarea,select{font:inherit}button{cursor:pointer}.skip{position:fixed;left:-9999px;top:1rem;z-index:100}.skip:focus{left:1rem;background:#fff;color:#000;padding:.65rem 1rem}.app-header{position:sticky;top:0;z-index:20;display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:1.25rem;align-items:center;padding:.75rem 1.25rem;background:rgba(16,15,18,.96);border-bottom:1px solid var(--line);box-shadow:0 10px 28px rgba(0,0,0,.35);backdrop-filter:blur(12px)}.brand{display:flex;align-items:center;gap:.65rem;min-width:max-content}.brand strong{display:block;color:#fff4e6;font-size:1.1rem;letter-spacing:.16em}.brand small{display:block;color:var(--muted);font-size:.69rem;letter-spacing:.04em}.brand-mark{display:grid;place-items:center;width:2.45rem;height:2.45rem;border:1px solid var(--line-hot);border-radius:50%;background:linear-gradient(145deg,#2a211c,#171417);color:var(--accent);font:700 1.35rem Georgia,serif;box-shadow:inset 0 0 0 3px #171417,0 0 18px rgba(188,157,90,.12)}nav{display:flex;gap:.25rem;align-items:center;overflow-x:auto;padding:.15rem}nav a{flex:0 0 auto;padding:.48rem .62rem;border:1px solid transparent;border-radius:4px;color:#aaa3a0;text-decoration:none;font-size:.76rem;font-weight:650;letter-spacing:.025em}nav a:hover{background:#242127;color:#f5eee6;border-color:#373139}nav a[aria-current=page]{background:var(--accent-soft);border-color:rgba(188,157,90,.45);color:#d5c299}.logout{margin:0;padding:0;border:0;display:block}.quiet{padding:.45rem .7rem;background:#252228;border:1px solid var(--line);border-radius:4px;color:#bdb6b0}.quiet:hover{border-color:var(--line-hot);color:white}main{width:min(1180px,calc(100% - 2rem));margin:0 auto;padding:2.2rem 0 4rem}.page-heading{margin:0 0 1.35rem}.page-heading h1{margin:.15rem 0 0;color:#f4eee6;font:400 clamp(1.7rem,4vw,2.65rem)/1.1 Georgia,serif}.eyebrow{margin:0;color:var(--accent);font-size:.7rem;font-weight:750;letter-spacing:.16em;text-transform:uppercase}section,form{margin:0 0 1rem;padding:1.15rem;border:1px solid var(--line);border-radius:6px;background:linear-gradient(145deg,rgba(31,28,33,.96),rgba(22,20,24,.96));box-shadow:0 10px 28px rgba(0,0,0,.18)}section h2,legend{color:#efe8df;font:400 1.15rem Georgia,serif}section h2{margin:0 0 .75rem}section p{color:#b4ada6}.hero{display:flex;justify-content:space-between;gap:2rem;align-items:flex-start;padding:1.55rem;border-color:#4a382e;background:linear-gradient(120deg,rgba(63,51,26,.42),rgba(28,25,30,.97) 55%)}.hero h2{margin:.25rem 0 .55rem;font-size:1.55rem}.hero p{max-width:52rem;margin:.35rem 0}.status-pill{flex:0 0 auto;display:inline-flex;align-items:center;gap:.45rem;padding:.45rem .7rem;border:1px solid rgba(121,191,135,.35);border-radius:999px;background:rgba(121,191,135,.08);color:#a5ddb0;font-size:.75rem;font-weight:700}.status-pill i{width:.48rem;height:.48rem;border-radius:50%;background:var(--good);box-shadow:0 0 10px var(--good)}.stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.7rem;padding:0;border:0;background:none;box-shadow:none}.stat-grid article{padding:1rem;border:1px solid var(--line);border-radius:6px;background:var(--surface)}.stat-grid span,.stat-grid small{display:block;color:var(--muted);font-size:.72rem}.stat-grid strong{display:block;margin:.25rem 0;color:#f5eee8;font-size:1.35rem}.steps{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem;list-style:none;counter-reset:step;margin:.9rem 0 0;padding:0}.steps li{counter-increment:step;display:grid;grid-template-columns:2rem 1fr;gap:.1rem .65rem;padding:.85rem;border:1px solid #373139;border-radius:5px;background:#171519}.steps li:before{content:counter(step);grid-row:1/3;display:grid;place-items:center;width:1.8rem;height:1.8rem;border-radius:50%;background:var(--accent-soft);color:var(--accent);font-weight:800}.steps strong{font-size:.82rem}.steps span{color:var(--muted);font-size:.75rem}form{display:grid;gap:.6rem;max-width:54rem}fieldset{display:grid;gap:.55rem;padding:0;border:0}legend{margin-bottom:.45rem}label{color:#c8c0b7;font-size:.77rem;font-weight:700}input,textarea,select{width:100%;padding:.62rem .7rem;border:1px solid #494149;border-radius:4px;background:#121114;color:var(--text)}input:focus,textarea:focus,select:focus,button:focus-visible,a:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-color:var(--accent)}textarea{min-height:8.5rem;resize:vertical}button{justify-self:start;padding:.62rem .9rem;border:1px solid #8d733a;border-radius:4px;background:#655229;color:#fff7ef;font-weight:750}button:hover{background:#776131}form p{margin:.2rem 0;color:var(--muted);font-size:.76rem}dl{display:grid;grid-template-columns:max-content 1fr;gap:.4rem 1rem}dt{color:var(--muted)}dd{margin:0;color:#eee}div[role=alert]{padding:.7rem;border:1px solid rgba(223,119,119,.5);border-radius:4px;background:rgba(223,119,119,.09);color:#ffc0c0}p[role=status]{padding:.7rem;border-left:3px solid var(--good);background:rgba(121,191,135,.08);color:#b9e4c2}footer{padding:1rem;border-top:1px solid #29252b;color:#746e69;text-align:center;font-size:.7rem}.login{display:grid;place-items:center}.login main{display:grid;place-items:center;min-height:100vh;padding:1rem}.login-card{width:min(28rem,100%);padding:2rem;text-align:center;border-color:#4a382e}.login-card .brand-mark{margin:0 auto 1rem}.login-card h1{margin:.2rem 0;font:400 2.4rem Georgia,serif;letter-spacing:.16em}.login-card form{text-align:left;margin:1.4rem 0 0;padding:0;border:0;background:none;box-shadow:none}.login-card button{justify-self:stretch}body.login footer{display:none}
.table-scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:.78rem}th,td{padding:.65rem;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{color:#cbb9a7;font-size:.68rem;letter-spacing:.08em;text-transform:uppercase}code{color:#d9c8a3}
@media(max-width:920px){.app-header{grid-template-columns:1fr auto}.app-header nav{grid-column:1/-1;grid-row:2}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){main{width:min(100% - 1rem,1180px);padding-top:1.15rem}.app-header{padding:.65rem .75rem}.brand small{display:none}.hero{display:block}.status-pill{margin-top:1rem}.stat-grid,.steps{grid-template-columns:1fr}section,form{padding:.9rem}.page-heading h1{font-size:1.8rem}}
</style>';}
    private function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
