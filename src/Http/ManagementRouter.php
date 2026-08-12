<?php

declare(strict_types=1);

namespace ALMSIVIserver\Http;

use ALMSIVIserver\Application\DeterministicRetrieval;
use ALMSIVIserver\Application\ConnectorCatalog;
use ALMSIVIserver\Application\NeverCancelledToken;
use ALMSIVIserver\Application\ProductService;
use ALMSIVIserver\Application\Provider;
use ALMSIVIserver\Application\ProviderFactory;
use ALMSIVIserver\Infrastructure\ManagementRepository;
use ALMSIVIserver\Infrastructure\EventLogRepository;
use ALMSIVIserver\Infrastructure\ProductRepository;
use ALMSIVIserver\Infrastructure\Uuid;
use ALMSIVIserver\Security\BrowserSession;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ManagementRouter
{
    private const PAGES=['quickstart','roleplay','configuration','control-panel','characters','profiles','player','npc-biographies','providers','ai-voice','prompts-actions','action-editor','world','descriptions','traces','memory','relationships','knowledge','playthroughs','narrative-autonomy','jobs','response-queue','oghma-audit','provider-usage','cache','backup-health','database-manager','server-logs','diagnostics'];
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
    ];

    public function __construct(private readonly ManagementRepository $management,private readonly ProductRepository $repository,
        private readonly ProductService $service,private readonly string $basePath='/ALMSIVIserver/manage',
        private readonly int $maxJsonBytes=2_097_152,private readonly int $sessionTtl=3600,
        private readonly array $providerConfig=[],private readonly ?EventLogRepository $eventLogRepository=null){ }

    public function dispatch(Request $r):Response
    {
        try{
            $path=$this->path($r->path);
            if($r->method==='GET'&&in_array($path,['/','/login'],true))return$this->redirect($this->uiPath('quickstart'));
            if($r->method==='GET'&&in_array(ltrim($path,'/'),self::PAGES,true))return$this->redirect($this->uiPath(ltrim($path,'/')));
            $session=$this->authenticatedSession($r);
            if($session===null){if($r->method==='GET'&&$this->htmlRequest($r))return$this->openBrowserSession($r->path);throw new RuntimeException('unauthorized');}
            if($r->method==='GET'&&preg_match('#^/exports/profiles/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportProfile($m[1]);
            if($r->method==='GET'&&preg_match('#^/exports/playthroughs/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportPlaythroughState($m[1]);
            if($r->method==='GET'&&preg_match('#^/exports/providers/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportProvider($m[1]);
            if($r->method==='GET'&&preg_match('#^/exports/prompts/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportPrompt($m[1]);
            if($r->method==='GET'&&preg_match('#^/exports/connectors/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->exportConnector($m[1]);
            if($r->method==='GET'&&preg_match('#^/exports/backups/([0-9a-f-]{36})\.json$#D',$path,$m))return$this->downloadConfigurationBackup($m[1]);
            if($r->method==='GET'&&$path==='/exports/descriptions/example.csv')return$this->exampleDescriptionsCsv();
            if($r->method==='GET'&&$path==='/exports/descriptions/custom.csv')return$this->exportDescriptionsCsv($this->queryUuid($r,'installation_id'));
            if($r->method==='GET'&&$path==='/exports/oghma/example.csv')return$this->exampleOghmaCsv();
            if(in_array($r->method,['POST','PUT','PATCH','DELETE'],true))$this->csrf($r,$session);
            if($r->method==='POST'&&$path==='/logout'){$this->management->revoke($session);return$this->redirect($this->uiPath('quickstart'),['Set-Cookie'=>['almsivi_management=; Path='.$this->webRoot().'; Max-Age=0; HttpOnly; SameSite=Strict','almsivi_csrf=; Path='.$this->webRoot().'; Max-Age=0; SameSite=Strict']]);}
            if(str_starts_with($path,'/api/v1/'))return$this->api($r,$path);
            if($r->method==='POST'&&preg_match('#^/forms/([a-z-]+)$#D',$path,$m))return$this->submit($m[1],$r);
            throw new RuntimeException('not_found');
        }catch(InvalidArgumentException $e){return$this->htmlRequest($r)?$this->errorPage($e->getMessage(),422):Response::json(422,['error'=>$e->getMessage()]);}
        catch(RuntimeException $e){$status=$e->getMessage()==='unauthorized'?401:404;if($status===401&&$this->htmlRequest($r))return$this->redirect($this->uiPath('quickstart'));return Response::json($status,['error'=>$e->getMessage()]);}
        catch(Throwable){return$this->htmlRequest($r)?$this->errorPage('internal_error',500):Response::json(500,['error'=>'internal_error']);}
    }

    private function api(Request $r,string $path):Response
    {
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
        if($r->method==='GET'&&$path==='/api/v1/diagnostics')return Response::json(200,$this->repository->diagnostics());
        if($r->method==='GET'&&$path==='/api/v1/actions')return Response::json(200,['items'=>$this->actions()]);
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
        $v=$this->form($r);$scope=$this->scopeForm($v);$content=$this->jsonField($v,'content_json');
        if($domain==='autonomy')throw new RuntimeException('not_found');
        if($domain==='connector-test'){
            $detail=$this->testConnector($v);
            $target=(($v['kind']??'')==='stt_provider'?'stt-connectors':'tts-connectors');
            $joiner=str_contains($this->uiPath($target),'?')?'&':'?';
            return$this->redirect($this->uiPath($target).$joiner.http_build_query(['status'=>'tested','detail'=>$detail]));
        }
        if($domain==='provider-test'){
            $detail=$this->testProvider($v);
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
        if($domain==='knowledge-import'){
            $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');$inputs=[];
            foreach($this->oghmaCsvRows($r)as$row)$inputs[]=['installation_id'=>$installation]+$row+['provenance'=>['source'=>'management-csv','category'=>$row['category']]];
            $saved=$this->service->importKnowledge($inputs);
            return$this->redirect($this->uiPath('knowledge').'&status=imported&count='.count($saved));
        }
        match($domain){
            'profiles'=>$this->service->createRevisioned('profile',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$content]),
            'profile-create'=>$this->createNpcProfile($v,$scope),
            'profile-template-create'=>$this->service->createRevisioned('profile',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'actor_identity'=>$this->templateIdentity($v),'content'=>$this->profileContent($v)]),
            'profile-revise'=>$this->reviseNpcProfile($v),
            'profile-toggle-favorite'=>$this->toggleNpcProfileManagement($v,'favorite'),
            'profile-toggle-lock'=>$this->toggleNpcProfileManagement($v,'locked'),
            'profile-import'=>$this->service->createRevisioned('profile',['installation_id'=>$scope['installation_id']]+$this->profileImportDocument($v)),
            'profile-clone'=>$this->cloneProfile($v),
            'core-profile-create'=>$this->createCoreProfile($v,$scope),
            'core-profile-save'=>$this->saveCoreProfile($v),
            'core-profile-revise'=>$this->service->revise('core_profile',$this->need($v,'core_profile_id'),$this->coreProfileContent($v),$this->need($v,'change_reason')),
            'core-profile-default'=>$this->makeDefaultCoreProfile($v),
            'core-profile-rollback'=>$this->service->rollback('core_profile',$this->need($v,'core_profile_id'),(int)($v['revision']??0),'management rollback'),
            'core-profile-delete'=>$this->service->deleteRevisioned('core_profile',$this->need($v,'core_profile_id')),
            'player-profile-create'=>$this->service->createRevisioned('profile',['installation_id'=>$scope['installation_id'],
                'name'=>$this->need($v,'name'),'actor_identity'=>$this->playerIdentity($v),'content'=>$this->profileContent($v)]),
            'player-profile-revise'=>$this->service->revise('profile',$this->need($v,'profile_id'),$this->profileContent($v),$this->need($v,'change_reason')),
            'player-speech-style-generate'=>$this->repository->enqueuePlayerSpeechStyleGeneration($this->need($v,'profile_id')),
            'narrator-profile-create'=>$this->service->createRevisioned('profile',['installation_id'=>$scope['installation_id'],
                'name'=>$this->need($v,'name'),'actor_identity'=>$this->narratorIdentity($v),'content'=>$this->narratorContent($v)]),
            'narrator-profile-revise'=>$this->service->revise('profile',$this->need($v,'profile_id'),$this->narratorContent($v),$this->need($v,'change_reason')),
            'narrator-profile-generate'=>$this->repository->enqueueNarratorProfileGeneration($this->need($v,'profile_id')),
            'global-settings-save'=>$this->saveGlobalSettings($v,$scope),
            'profile-biography-revise'=>$this->reviseNpcProfile($v),
            'profile-rollback'=>$this->service->rollback('profile',$this->need($v,'profile_id'),(int)($v['revision']??0),'management rollback'),
            'profile-delete'=>$this->service->deleteRevisioned('profile',$this->need($v,'profile_id')),
            'profile-generate'=>$this->repository->enqueueProfileGeneration($this->need($v,'profile_id')),
            'profile-bulk-generate'=>$this->bulkGenerateProfiles($v,$scope),
            'profile-bulk-unlock'=>$this->bulkUnlockProfiles($v,$scope),
            'profile-bulk-delete'=>$this->bulkDeleteProfiles($v,$scope),
            'profile-bulk-switch'=>$this->bulkSwitchProfiles($v,$scope),
            'profile-auto-lock'=>$this->repository->setProfileAutoLock($scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),isset($v['enabled']),gmdate('Y-m-d\TH:i:s\Z')),
            'providers'=>$this->service->createRevisioned('provider',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$this->providerFormContent($v)]),
            'provider-revise'=>$this->service->revise('provider',$this->need($v,'configuration_id'),$this->providerFormContent($v),$this->need($v,'change_reason')),
            'provider-rollback'=>$this->service->rollback('provider',$this->need($v,'configuration_id'),(int)($v['revision']??0),'management rollback'),
            'provider-delete'=>$this->service->deleteRevisioned('provider',$this->need($v,'configuration_id')),
            'provider-clone'=>$this->cloneProvider($v),
            'provider-import'=>$this->importProvider($v,$scope),
            'tts-providers'=>$this->service->createRevisioned('tts_provider',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$this->connectorFormContent($v,'tts_provider')]),
            'stt-providers'=>$this->service->createRevisioned('stt_provider',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$this->connectorFormContent($v,'stt_provider')]),
            'connector-revise'=>$this->service->revise($this->need($v,'kind'),$this->need($v,'configuration_id'),$this->connectorFormContent($v,$this->need($v,'kind')),$this->need($v,'change_reason')),
            'connector-rollback'=>$this->service->rollback($this->need($v,'kind'),$this->need($v,'configuration_id'),(int)($v['revision']??0),'management rollback'),
            'connector-delete'=>$this->service->deleteRevisioned($this->need($v,'kind'),$this->need($v,'configuration_id')),
            'connector-selection'=>$this->service->selectConnector(['installation_id'=>$scope['installation_id'],'kind'=>$this->need($v,'kind'),'configuration_id'=>$this->need($v,'configuration_id')]),
            'connector-default-voice'=>$this->reviseConnectorDefaultVoice($v),
            'connector-clone'=>$this->cloneConnector($v),
            'connector-import'=>$this->importConnector($v,$scope),
            'description-save'=>$this->service->saveItemDescription(['installation_id'=>$scope['installation_id'],'content_file'=>$this->need($v,'content_file'),'record_id'=>$this->need($v,'record_id'),'display_name'=>$this->need($v,'display_name'),'description'=>$this->need($v,'description')]),
            'description-delete'=>$this->service->deleteItemDescription($this->need($v,'description_id'),$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id')),
            'description-reset'=>$this->resetDescriptions($v,$scope),
            'prompts'=>$this->service->createRevisioned('prompt',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$content]),
            'prompt-clone'=>$this->clonePrompt($v),
            'prompt-import'=>$this->importPrompt($v,$scope),
            'action-policies'=>$this->service->createRevisioned('action_policy',['installation_id'=>$scope['installation_id'],'profile_id'=>$scope['profile_id']??null,'name'=>$this->need($v,'name'),'content'=>$content]),
            'action-policy-controls-create'=>$this->service->createRevisioned('action_policy',['installation_id'=>$scope['installation_id'],'profile_id'=>$scope['profile_id']??null,'name'=>$this->need($v,'name'),'content'=>$this->actionPolicyFormContent($v)]),
            'action-policy-controls-revise'=>$this->service->revise('action_policy',$this->need($v,'configuration_id'),$this->actionPolicyFormContent($v),$this->need($v,'change_reason')),
            'configuration-revise'=>$this->service->revise($this->configurationKind($v),$this->need($v,'configuration_id'),$content,$this->need($v,'change_reason')),
            'configuration-rollback'=>$this->service->rollback($this->configurationKind($v),$this->need($v,'configuration_id'),(int)($v['revision']??0),'management rollback'),
            'configuration-delete'=>$this->service->deleteRevisioned($this->configurationKind($v),$this->need($v,'configuration_id')),
            'playthroughs'=>$this->service->createRevisioned('playthrough',['installation_id'=>$scope['installation_id'],'profile_id'=>$scope['profile_id'],'name'=>$this->need($v,'name'),'content'=>$content]),
            'playthrough-import'=>$this->restorePlaythroughState($v,$scope),
            'memory'=>$this->service->createMemory($scope+['tier'=>$v['tier']??'recent','content'=>$this->need($v,'content'),'provenance'=>['source'=>$this->need($v,'provenance')]]),
            'memory-revise'=>$this->reviseMemory($v),
            'memory-delete'=>$this->repository->deleteMemory($this->need($v,'memory_id'),gmdate('Y-m-d\TH:i:s\Z')),
            'memory-rebuild'=>$this->repository->rebuildMemories($scope,gmdate('Y-m-d\TH:i:s\Z')),
            'relationships'=>$this->service->setRelationship($scope+['actor_identity'=>$content,'disposition'=>(int)($v['disposition']??0),'affinity'=>(int)($v['affinity']??0),'source_mode'=>'manual','reason'=>$v['reason']??'management']),
            'relationship-delete'=>$this->repository->deleteRelationship($this->need($v,'relationship_id'),gmdate('Y-m-d\TH:i:s\Z')),
            'knowledge'=>$this->service->ingestKnowledge($scope+$this->knowledgeFormInput($v)),
            'knowledge-revise'=>$this->service->updateKnowledge($this->need($v,'document_id'),$this->knowledgeFormInput($v)),
            'knowledge-delete'=>$this->deleteKnowledgeDocument($v),
            'narratives'=>$this->service->createNarrative($scope+['kind'=>$v['kind']??'narrator','title'=>$this->need($v,'title'),'content'=>$this->need($v,'content'),'provenance'=>['source'=>$this->need($v,'provenance')]]),
            'narrative-revise'=>$this->service->updateNarrative($this->need($v,'narrative_id'),['kind'=>$v['kind']??'narrator','title'=>$this->need($v,'title'),'content'=>$this->need($v,'content'),'provenance'=>['source'=>$this->need($v,'provenance')]]),
            'narrative-delete'=>$this->deleteNarrativeDocument($v),
            'configuration-backup'=>$this->createConfigurationBackup($v,$scope),
            'configuration-restore'=>$this->restoreConfigurationBackup($v,$scope),
            'retention'=>$this->repository->prune((int)($v['days']??30),gmdate('Y-m-d\TH:i:s\Z')),
            default=>throw new RuntimeException('not_found')};
        if($domain==='global-settings-save')return$this->redirect($this->uiPath('world').'&status=saved');
        if($domain==='core-profile-save')return$this->redirect($this->uiPath('profiles').'?'.http_build_query(['edit'=>$this->need($v,'core_profile_id'),'status'=>'saved']));
        if($domain==='connector-default-voice')return$this->redirect($this->uiPath('tts-studio').'?'.http_build_query(['configuration_id'=>$this->need($v,'configuration_id'),'status'=>'saved']));
        if(in_array($domain,['description-save','description-delete','description-reset'],true))return$this->redirect(
            $this->descriptionPageLocation($scope['installation_id']??(string)($v['installation_id']??''),'saved'));
        $target=match($domain){'prompts','prompt-clone','prompt-import'=>'prompts-actions','action-policies','action-policy-controls-create','action-policy-controls-revise'=>'action-editor','configuration-revise','configuration-rollback','configuration-delete'=>(($v['kind']??'')==='action_policy'?'action-editor':'prompts-actions'),'narratives','narrative-revise','narrative-delete'=>'narrative-autonomy','configuration-backup','configuration-restore'=>'database-manager','retention'=>'backup-health','providers','provider-revise','provider-rollback','provider-delete','provider-clone','provider-import'=>'providers','tts-providers'=>'tts-connectors','stt-providers'=>'stt-connectors','connector-default-voice'=>'tts-studio','connector-selection','connector-revise','connector-rollback','connector-delete','connector-clone','connector-import'=>(($v['kind']??'')==='stt_provider'?'stt-connectors':'tts-connectors'),'core-profile-create','core-profile-revise','core-profile-default','core-profile-rollback','core-profile-delete'=>'profiles','profile-import','profile-clone','profile-create','profile-revise','profile-toggle-favorite','profile-toggle-lock','profile-rollback','profile-delete','profile-generate','profile-bulk-generate','profile-bulk-unlock','profile-bulk-delete','profile-bulk-switch','profile-auto-lock'=>'characters','player-profile-create','player-profile-revise','player-speech-style-generate'=>'player','narrator-profile-create','narrator-profile-revise','narrator-profile-generate'=>'narrator','profile-biography-revise'=>'npc-biographies','description-save','description-delete','description-reset'=>'descriptions','memory-revise','memory-delete','memory-rebuild'=>'memory','relationship-delete'=>'relationships','knowledge','knowledge-revise','knowledge-delete'=>'knowledge','playthroughs','playthrough-import'=>'playthrough-form',default=>$domain};
        $joiner=str_contains($this->uiPath($target),'?')?'&':'?';
        return$this->redirect($this->uiPath($target).$joiner.'status=saved');
    }

    private function page(string $slug,Request $r):Response
    {
        $csrf=BrowserSession::parseCsrf($r->header('Cookie'))??'';$title=match($slug){'quickstart'=>'ALMSIVI Dashboard','control-panel'=>'Control Panel',default=>ucwords(str_replace('-',' ',$slug))};$body=isset($r->query['status'])?'<p role="status">Changes saved.</p>':'';
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
                ['narrative-autonomy','Narrative','Configure narrator, diary, and summary records. Autonomy is excluded.'],
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
            'narrative-autonomy'=>$this->formHtml('narratives','Create narrative',$csrf,$scope.$this->select('kind','Narrative kind',['narrator','diary','summary']).$this->input('title','Title').$this->area('content','Narrative').$this->input('provenance','Provenance source')).'<section class="feature-status"><h2>Autonomy <span class="status-badge">Excluded</span></h2><p>Timer-driven autonomy is not part of ALMSIVI. Rechat and bored-event handling remain explicit gameplay flows.</p></section>',
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
            .'<li><strong>Start OpenMW</strong><span>Load ALMSIVI and open the in-game chat.</span></li>'
            .'<li><strong>Test dialogue</strong><span>Select an NPC and verify text, speech, and actions.</span></li>'
            .'</ol></div></section>'
            .'<section class="widget"><div class="widget-header"><h3>ALMSIVI Stats</h3></div><div class="widget-content widget-stats">'
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
    private function oghmaCsvRows(Request $request):array
    {
        $file=$request->files['csv_file']??null;if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('oghma_csv_missing');
        $size=(int)($file['size']??0);if($size<1||$size>$this->maxJsonBytes)throw new InvalidArgumentException('oghma_csv_size');
        if(strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION))!=='csv')throw new InvalidArgumentException('oghma_csv_type');
        $handle=fopen((string)$file['tmp_name'],'rb');if($handle===false)throw new InvalidArgumentException('oghma_csv_unreadable');$rows=[];
        try{$header=fgetcsv($handle,131072,',','"','\\');if(!is_array($header))throw new InvalidArgumentException('oghma_csv_header');
            if(isset($header[0]))$header[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$header[0]);
            $expected=['topic','topic_desc','knowledge_class','topic_desc_basic','knowledge_class_basic','tags','category','aliases'];
            if($header!==$expected)throw new InvalidArgumentException('oghma_csv_header');
            while(($values=fgetcsv($handle,131072,',','"','\\'))!==false){if($values===[null]||count($values)===0)continue;if(count($values)!==8)throw new InvalidArgumentException('oghma_csv_columns');
                if(!mb_check_encoding(implode('',array_map('strval',$values)),'UTF-8'))throw new InvalidArgumentException('oghma_csv_encoding');
                $rows[]=['topic'=>(string)$values[0],'title'=>str_replace('_',' ',(string)$values[0]),'content'=>(string)$values[1],
                    'knowledge_class'=>(string)$values[2],'topic_desc_basic'=>(string)$values[3],'knowledge_class_basic'=>(string)$values[4],
                    'tags'=>(string)$values[5],'category'=>(string)$values[6],'aliases'=>(string)$values[7]];
                if(count($rows)>5000)throw new InvalidArgumentException('oghma_csv_rows');}
        }finally{fclose($handle);}if($rows===[])throw new InvalidArgumentException('oghma_csv_empty');return$rows;
    }

    private function exampleOghmaCsv():Response
    {
        $stream=fopen('php://temp','w+b');if($stream===false)throw new RuntimeException('csv_unavailable');fwrite($stream,"\xEF\xBB\xBF");
        fputcsv($stream,['topic','topic_desc','knowledge_class','topic_desc_basic','knowledge_class_basic','tags','category','aliases'],',','"','\\');
        fputcsv($stream,['vivec_city','Advanced article.','scholar,dunmer','Basic article.','common','Vivec,Cantons','settlements','Vivec City'],',','"','\\');
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
            if($type==='boolean'){$options[$name]=isset($values[$key]);continue;}
            if(!array_key_exists($key,$values))continue;$raw=trim((string)$values[$key]);if($raw===''){unset($options[$name]);continue;}
            if($type==='select'){if(!in_array($raw,$field['values'],true))throw new InvalidArgumentException('invalid_connector_option_'.$name);$options[$name]=$raw;continue;}
            if($type==='integer'||$type==='number'){$valid=filter_var($raw,$type==='integer'?FILTER_VALIDATE_INT:FILTER_VALIDATE_FLOAT);
                if($valid===false||$valid<$field['minimum']||$valid>$field['maximum'])throw new InvalidArgumentException('invalid_connector_option_'.$name);
                $options[$name]=$type==='integer'?(int)$valid:(float)$valid;continue;}
            if(strlen($raw)>512||!mb_check_encoding($raw,'UTF-8'))throw new InvalidArgumentException('invalid_connector_option_'.$name);$options[$name]=$raw;
        }
        return ['driver'=>$driver,'endpoint'=>$this->need($values,'endpoint'),
            'model'=>trim((string)($values['model']??'')),'voice'=>trim((string)($values['voice']??'')),
            'language'=>trim((string)($values['language']??'en')),'timeout_ms'=>(int)($values['timeout_ms']??30000),
            'options'=>$options];
    }

    /** Convert the labelled LLM model-slot form into the strict server-owned provider document. */
    private function providerFormContent(array $values):array
    {
        $driver=$this->need($values,'driver');$model=$this->need($values,'model');
        if($driver==='configured')return['driver'=>'configured','model'=>$model];
        if($driver==='mock')return['driver'=>'mock','model'=>$model,'mock_prefix'=>trim((string)($values['mock_prefix']??''))];
        throw new InvalidArgumentException('invalid_provider_driver');
    }

    /** Restrict generic revision controls to the two JSON-backed management editors. */
    private function configurationKind(array $values):string
    {
        $kind=$this->need($values,'kind');
        if(!in_array($kind,['prompt','action_policy'],true))throw new InvalidArgumentException('invalid_configuration_kind');
        return$kind;
    }

    /** Convert labelled action toggles into a complete policy over the immutable OpenMW action catalog. */
    private function actionPolicyFormContent(array $values):array
    {
        $tier=(string)($values['max_tier']??'');if(preg_match('/^[0-3]$/D',$tier)!==1)throw new InvalidArgumentException('invalid_max_tier');
        $selected=$values['allowed_actions']??[];if(!is_array($selected)||!array_is_list($selected)||count($selected)>128)throw new InvalidArgumentException('invalid_allowed_actions');
        $known=$this->repository->actionCatalogNames();$enabled=[];
        foreach($selected as$name){if(!is_string($name)||!in_array($name,$known,true)||isset($enabled[$name]))throw new InvalidArgumentException('invalid_allowed_actions');$enabled[$name]=true;}
        $actions=[];foreach($known as$name)$actions[$name]=isset($enabled[$name]);
        return['enabled'=>isset($values['enabled']),'max_tier'=>(int)$tier,'actions'=>$actions];
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
        $content=is_array($row['content']??null)?$row['content']:[];unset($content['portrait']);
        $document=['schema'=>'almsivi.profile-export.v1','exported_at'=>gmdate('Y-m-d\TH:i:s\Z'),'name'=>(string)$row['name'],
            'actor_identity'=>$identity===[]?(object)[]:$identity,'content'=>$content===[]?(object)[]:$content];
        $filename=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$row['name']),'-_.');if($filename==='')$filename='almsivi-profile';
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
        $content=is_array($row['content']??null)?$row['content']:[];unset($content['portrait']);
        return$this->service->createRevisioned('profile',['installation_id'=>(string)$row['installation_id'],
            'name'=>$name,'actor_identity'=>$identity,'core_profile_id'=>(string)$row['core_profile_id'],'content'=>$content]);
    }

    /** Download one portable model slot without installation ownership, revision history, endpoints, or credentials. */
    private function exportProvider(string $configurationId):Response
    {
        $this->uuid($configurationId,'configuration_id');$row=$this->repository->getRevisioned('provider',$configurationId);
        $content=is_array($row['content']??null)?$row['content']:[];
        if($this->containsSecretKey($content))throw new RuntimeException('provider_export_rejected');
        $document=['schema'=>'almsivi.provider-export.v1','exported_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'name'=>(string)$row['name'],'content'=>$content===[]?(object)[]:$content];
        $filename=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$row['name']),'-_.');if($filename==='')$filename='almsivi-model-slot';
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
        if($keys!==['content','exported_at','name','schema']||($document['schema']??null)!=='almsivi.provider-export.v1'
            ||!is_string($document['exported_at']??null)||strlen($document['exported_at'])>64
            ||!$this->objectArray($document['content']??null)||$this->containsSecretKey($document))throw new InvalidArgumentException('invalid_provider_export');
        $content=$document['content'];$driver=$content['driver']??null;$contentKeys=array_keys($content);sort($contentKeys);
        $minimal=['driver','model'];$withPrefix=['driver','mock_prefix','model'];sort($minimal);sort($withPrefix);
        if(!in_array($driver,['configured','mock'],true)||($driver==='configured'?$contentKeys!==$minimal:!in_array($contentKeys,[$minimal,$withPrefix],true)))throw new InvalidArgumentException('invalid_provider_export');
        $name=trim((string)($document['name']??''));if($name===''||strlen($name)>128||!mb_check_encoding($name,'UTF-8'))throw new InvalidArgumentException('invalid_provider_export');
        return$this->service->createRevisioned('provider',['installation_id'=>$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),
            'name'=>$name,'content'=>$this->providerFormContent($content)]);
    }

    /** Download one portable prompt without installation ownership, revision history, or secret-like fields. */
    private function exportPrompt(string $configurationId):Response
    {
        $this->uuid($configurationId,'configuration_id');$row=$this->repository->getRevisioned('prompt',$configurationId);
        $content=is_array($row['content']??null)?$row['content']:[];
        if($this->containsSecretKey($content))throw new RuntimeException('prompt_export_rejected');
        $document=['schema'=>'almsivi.prompt-export.v1','exported_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'name'=>(string)$row['name'],'content'=>$content===[]?(object)[]:$content];
        $filename=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$row['name']),'-_.');if($filename==='')$filename='almsivi-prompt';
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
        if($keys!==['content','exported_at','name','schema']||($document['schema']??null)!=='almsivi.prompt-export.v1'
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
        $document=['schema'=>'almsivi.connector-export.v1','exported_at'=>gmdate('Y-m-d\TH:i:s\Z'),'kind'=>$kind,
            'name'=>(string)$row['name'],'content'=>$content===[]?(object)[]:$content];
        $filename=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$row['name']),'-_.');if($filename==='')$filename='almsivi-connector';
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
        if($keys!==['content','exported_at','kind','name','schema']||($document['schema']??null)!=='almsivi.connector-export.v1'
            ||($document['kind']??null)!==$kind||!is_string($document['exported_at']??null)||strlen($document['exported_at'])>64
            ||!$this->objectArray($document['content']??null)||$this->containsSecretKey($document))throw new InvalidArgumentException('invalid_connector_export');
        $name=trim((string)($document['name']??''));if($name===''||strlen($name)>128||!mb_check_encoding($name,'UTF-8'))throw new InvalidArgumentException('invalid_connector_export');
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
        $filename=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$row['name']),'-_.');if($filename==='')$filename='almsivi-playthrough';
        return new Response(200,json_encode($document,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",
            ['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="'.$filename.'-backup.json"','X-Content-Type-Options'=>'nosniff']);
    }

    /** Create one immutable, secret-free installation configuration backup. */
    private function createConfigurationBackup(array $values,array $scope):array
    {
        if(!hash_equals('Backup',$this->need($values,'confirm')))throw new InvalidArgumentException('confirmation_mismatch');
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');
        $backupId=Uuid::v4();$now=gmdate('Y-m-d\TH:i:s\Z');$document=[
            'schema'=>'almsivi.configuration-backup.v2','format_version'=>2,'backup_id'=>$backupId,'created_at'=>$now,
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
            'Content-Disposition'=>'attachment; filename="almsivi-configuration-'.$document['installation_id'].'-'.$backupId.'.json"',
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
        $format=$document['format_version']??null;$expectedSchema=$format===1?'almsivi.configuration-backup.v1':'almsivi.configuration-backup.v2';
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

        $configurationIds=[];$configurationKinds=[];$allowed=['prompt','provider','tts_provider','stt_provider','action_policy','global_settings'];
        foreach($data['configurations']as$row){if(!$this->objectArray($row)){throw new RuntimeException('backup_integrity_failed');}$keys=array_keys($row);sort($keys);
            if($keys!==['configuration_id','content','kind','name','profile_id']||!is_string($row['configuration_id'])||!in_array($row['kind']??null,$allowed,true)
                ||!is_string($row['name'])||trim($row['name'])===''||strlen($row['name'])>128||!$this->objectArray($row['content'])
                ||($row['profile_id']!==null&&(!is_string($row['profile_id'])||!isset($profileIds[$row['profile_id']]))))throw new RuntimeException('backup_integrity_failed');
            $this->uuid($row['configuration_id'],'configuration_id');if(isset($configurationIds[$row['configuration_id']]))throw new RuntimeException('backup_integrity_failed');
            $configurationIds[$row['configuration_id']]=true;$configurationKinds[$row['configuration_id']]=$row['kind'];}
        $selectionKinds=[];foreach($data['connector_selections']as$row){if(!$this->objectArray($row)){throw new RuntimeException('backup_integrity_failed');}$keys=array_keys($row);sort($keys);
            $kind=$row['provider_kind']??null;$id=$row['configuration_id']??null;if($keys!==['configuration_id','provider_kind']||!in_array($kind,['tts_provider','stt_provider'],true)
                ||!is_string($id)||($configurationKinds[$id]??null)!==$kind||isset($selectionKinds[$kind]))throw new RuntimeException('backup_integrity_failed');$selectionKinds[$kind]=true;}
        if($this->containsSecretKey($document))throw new RuntimeException('backup_integrity_failed');
    }

    private function backupRoot():string
    {
        $root=rtrim((string)($this->providerConfig['backup_storage_path']??'/var/lib/almsiviserver/backups'),DIRECTORY_SEPARATOR);
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
        if($keys!==['actor_identity','content','exported_at','name','schema']||($document['schema']??null)!=='almsivi.profile-export.v1'
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
        return['kind'=>'narrator','record_id'=>'almsivi:narrator','refnum'=>['index'=>0,'content_file'=>0],
            'content_file'=>'ALMSIVI','cell'=>['kind'=>'interior','name'=>'ALMSIVI Narrator'],
            'display_name'=>$this->need($values,'name')];
    }

    /** Convert the focused narration form into an opt-in roleplay and routing document. */
    private function narratorContent(array $values):array
    {
        $content=$this->profileContent($values);$mode=(string)($values['inline_narration_mode']??'Disabled');
        if(!in_array($mode,['Disabled','Narrator','NPC','Text Only'],true))throw new InvalidArgumentException('invalid_inline_narration_mode');
        $content['enabled']=isset($values['enabled']);$content['inline_narration_mode']=$mode;
        $content['context_visibility']=isset($values['context_visibility']);
        $content['welcome_events']=isset($values['welcome_events']);$content['random_events']=isset($values['random_events']);
        $content['quest_events']=isset($values['quest_events']);$content['book_events']=isset($values['book_events']);
        return$content;
    }

    /** Create or revise the one typed global-settings document owned by an installation. */
    private function saveGlobalSettings(array $values,array $scope):array
    {
        $installation=$scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id');
        $this->repository->setProfileAutoLock($installation,isset($values['auto_lock_profile']),gmdate('Y-m-d\TH:i:s\Z'));
        $this->repository->setOghmaKnowledgeTags($installation,trim((string)($values['oghma_knowledge_tags']??'common')),gmdate('Y-m-d\TH:i:s\Z'));
        $content=$this->globalSettingsContent($values);$existing=$this->repository->globalSettingsForInstallation($installation);
        if($existing===null)return$this->service->createRevisioned('global_settings',['installation_id'=>$installation,'name'=>'Global Settings','content'=>$content]);
        return$this->service->revise('global_settings',(string)$existing['configuration_id'],$content,trim((string)($values['change_reason']??'management global settings'))?:'management global settings');
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
            'llm_experimental_configuration_id','llm_fallback_configuration_id','tts_configuration_id']as$field){
            $value=trim((string)($values[$field]??''));if($value==='')continue;$this->uuid($value,$field);$routing[$field]=$value;
        }
        foreach(['llm_randomizer_enabled','llm_fallback_enabled']as$field){
            $value=(string)($values[$field]??'inherit');
            if($value==='inherit')continue;if(!in_array($value,['0','1'],true))throw new InvalidArgumentException('invalid_'.$field);
            $routing[$field]=$value==='1';
        }

        $overrides=[];
        $booleanFields=[
            'behavior'=>['rechat','rechat_strict_targeting','open_rechat'],
            'narrator'=>['enabled','context_visibility'],
            'safety'=>['actions_enabled','allow_hostile','allow_creatures'],
        ];
        foreach($booleanFields as$section=>$fields)foreach($fields as$field){$key='setting_'.$section.'_'.$field;$value=(string)($values[$key]??'inherit');
            if($value==='inherit')continue;if(!in_array($value,['0','1'],true))throw new InvalidArgumentException('invalid_'.$key);
            $overrides[$section][$field]=$value==='1';}
        $integerFields=[
            'behavior'=>['rechat_max_depth','rechat_probability_percent','end_conversation_cooldown_seconds'],
            'memory'=>['recent_turn_limit','knowledge_limit'],
        ];
        foreach($integerFields as$section=>$fields)foreach($fields as$field){$key='setting_'.$section.'_'.$field;$raw=trim((string)($values[$key]??''));
            if($raw==='')continue;$value=filter_var($raw,FILTER_VALIDATE_INT);if($value===false)throw new InvalidArgumentException('invalid_'.$key);
            $overrides[$section][$field]=(int)$value;}
        $oghmaTags=trim((string)($values['setting_memory_oghma_knowledge_tags']??''));
        if($oghmaTags!=='')$overrides['memory']['oghma_knowledge_tags']=$oghmaTags;
        $rechatMode=trim((string)($values['setting_behavior_rechat_mode']??''));
        if($rechatMode!=='')$overrides['behavior']['rechat_mode']=$rechatMode;
        foreach(['name','inline_mode']as$field){$key='setting_narrator_'.$field;$value=trim((string)($values[$key]??''));if($value!=='')$overrides['narrator'][$field]=$value;}

        return['schema'=>'almsivi.core-profile.v1','prompt'=>(string)($values['prompt']??''),
            'routing'=>$routing,'settings_overrides'=>$overrides];
    }

    /** Convert labelled management controls into the strict client-settings protocol document. */
    private function globalSettingsContent(array $values):array
    {
        $integer=static function(array$input,string$key,int$default):int{$value=filter_var($input[$key]??$default,FILTER_VALIDATE_INT);if($value===false)throw new InvalidArgumentException('invalid_'.$key);return(int)$value;};
        $mode=(string)($values['narrator_inline_mode']??'Disabled');
        return['schema'=>'almsivi.client-settings.v1','behavior'=>[
            'auto_greeting'=>false,'rechat'=>isset($values['rechat']),
            'rechat_delay_seconds'=>$integer($values,'rechat_delay_seconds',45),'rechat_max_depth'=>$integer($values,'rechat_max_depth',2),
            'rechat_probability_percent'=>$integer($values,'rechat_probability_percent',50),
            'rechat_mode'=>trim((string)($values['rechat_mode']??'random')),
            'rechat_strict_targeting'=>isset($values['rechat_strict_targeting']),
            'open_rechat'=>isset($values['open_rechat']),'rechat_allow_actions'=>false,
            'end_conversation_cooldown_seconds'=>$integer($values,'end_conversation_cooldown_seconds',60),
            'boredom'=>false,'boredom_delay_seconds'=>180,
            'combat_barks'=>false,'combat_bark_period_seconds'=>20,
        ],'memory'=>['recent_turn_limit'=>$integer($values,'recent_turn_limit',20),'knowledge_limit'=>$integer($values,'knowledge_limit',5)],
        'narrator'=>['enabled'=>isset($values['narrator_enabled']),'name'=>trim((string)($values['narrator_name']??'The Narrator')),
            'context_visibility'=>isset($values['narrator_context_visibility']),'inline_mode'=>$mode,
            'welcome_events'=>false,'random_events'=>false,'quest_events'=>false,'book_events'=>false],
        'presentation'=>['show_status_hud'=>isset($values['show_status_hud']),'transcript_rows'=>$integer($values,'transcript_rows',8),
            'tts_volume_boost'=>$integer($values,'tts_volume_boost',3)],
        'safety'=>['actions_enabled'=>isset($values['actions_enabled']),'allow_hostile'=>isset($values['allow_hostile']),
            'allow_creatures'=>isset($values['allow_creatures'])]];
    }

    /** Convert labelled NPC editor fields into the bounded roleplay document consumed by prompts and TTS. */
    private function profileContent(array $values):array
    {
        $content=isset($values['base_content_json'])?$this->jsonField($values,'base_content_json'):[];
        foreach(['prompt_head','core','appearance','biography','personality','speech_style','occupation','skills','goals','relationships','emote_moods','gender','race','tags','notes']as$field){
            if(!array_key_exists($field,$values))continue;
            $value=trim((string)($values[$field]??''));if($value!=='')$content[$field]=$value;else unset($content[$field]);
        }
        if(array_key_exists('voice_id',$values)){$voice=trim((string)$values['voice_id']);$language=trim((string)($values['voice_language']??'en'));
            if($voice!=='')$content['voice']=['id'=>$voice,'language'=>$language===''?'en':$language];else unset($content['voice']);}
        $llmRoutingFields=['llm_configuration_id','llm_fast_configuration_id','llm_powerful_configuration_id',
            'llm_experimental_configuration_id','llm_fallback_configuration_id'];
        if(array_key_exists('llm_configuration_id',$values)||array_key_exists('tts_configuration_id',$values)
            ||array_key_exists('prompt_configuration_id',$values)||isset($values['llm_routing_fields'])){
            $routing=is_array($content['routing']??null)&&!array_is_list($content['routing'])?$content['routing']:[];
            foreach(array_merge($llmRoutingFields,['tts_configuration_id','prompt_configuration_id'])as$field){
                if(!array_key_exists($field,$values))continue;$id=trim((string)($values[$field]??''));
                if($id==='')unset($routing[$field]);elseif($id==='__disabled__')$routing[$field]='';else{$this->uuid($id,$field);$routing[$field]=$id;}
            }
            if(isset($values['llm_routing_fields'])){
                foreach(['llm_randomizer_enabled','llm_fallback_enabled']as$field){$value=(string)($values[$field]??'inherit');
                    if($value==='inherit')unset($routing[$field]);elseif(in_array($value,['0','1'],true))$routing[$field]=$value==='1';
                    else throw new InvalidArgumentException('invalid_'.$field);}
            }
            if($routing===[])unset($content['routing']);else$content['routing']=$routing;
        }
        if(array_filter(array_keys($values),static fn(string$key):bool=>str_starts_with($key,'setting_'))!==[]){
            $overrides=$this->profileSettingsOverrides($values);if($overrides===[])unset($content['settings_overrides']);else$content['settings_overrides']=$overrides;
        }
        if(array_key_exists('management_fields',$values))$content['management']=[
            'locked'=>isset($values['locked']),'favorite'=>isset($values['favorite'])];
        return$content;
    }

    /** Parse optional per-NPC setting values; absent keys continue to inherit from the Core Profile. */
    private function profileSettingsOverrides(array $values):array
    {
        $overrides=[];$booleanFields=['behavior'=>['rechat','rechat_strict_targeting','open_rechat'],
            'narrator'=>['enabled','context_visibility'],'safety'=>['actions_enabled','allow_hostile','allow_creatures']];
        foreach($booleanFields as$section=>$fields)foreach($fields as$field){$value=(string)($values['setting_'.$section.'_'.$field]??'inherit');
            if($value==='inherit')continue;if(!in_array($value,['0','1'],true))throw new InvalidArgumentException('invalid_setting_override');
            $overrides[$section][$field]=$value==='1';}
        $integerFields=['behavior'=>['rechat_max_depth','rechat_probability_percent','end_conversation_cooldown_seconds'],
            'memory'=>['recent_turn_limit','knowledge_limit']];
        foreach($integerFields as$section=>$fields)foreach($fields as$field){$raw=trim((string)($values['setting_'.$section.'_'.$field]??''));if($raw==='')continue;
            $value=filter_var($raw,FILTER_VALIDATE_INT);if($value===false)throw new InvalidArgumentException('invalid_setting_override');$overrides[$section][$field]=(int)$value;}
        $oghmaTags=trim((string)($values['setting_memory_oghma_knowledge_tags']??''));
        if($oghmaTags!=='')$overrides['memory']['oghma_knowledge_tags']=$oghmaTags;
        $rechatMode=trim((string)($values['setting_behavior_rechat_mode']??''));
        if($rechatMode!=='')$overrides['behavior']['rechat_mode']=$rechatMode;
        foreach(['name','inline_mode']as$field){$value=trim((string)($values['setting_narrator_'.$field]??''));if($value!=='')$overrides['narrator'][$field]=$value;}
        return$overrides;
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

    /** Save a manual NPC revision and auto-lock it when that installation preference is enabled. */
    private function reviseNpcProfile(array $values):array
    {
        $profileId=$this->need($values,'profile_id');$profile=$this->repository->getRevisioned('profile',$profileId);$identity=$profile['actor_identity']??[];
        if(is_string($identity))$identity=json_decode($identity,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($identity)||array_is_list($identity)||in_array($identity['kind']??'actor',['player','narrator'],true))throw new InvalidArgumentException('profile_not_editable');
        $content=$this->profileContent($values);if($this->repository->profileAutoLockEnabled((string)$profile['installation_id'])){
            $management=is_array($content['management']??null)?$content['management']:[];$management['locked']=true;$management['favorite']=($management['favorite']??false)===true;$content['management']=$management;}
        $revised=$this->service->revise('profile',$profileId,$content,$this->need($values,'change_reason'));
        if(isset($values['core_profile_id'])&&trim((string)$values['core_profile_id'])!==''){
            $this->uuid((string)$values['core_profile_id'],'core_profile_id');$this->repository->assignCoreProfile($profileId,(string)$values['core_profile_id']);
            $revised=$this->repository->getRevisioned('profile',$profileId);
        }
        return$revised;
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
        return$this->repository->bulkSwitchNpcProfileBindings($scope['installation_id']??throw new InvalidArgumentException('invalid_installation_id'),
            $source,$target,isset($values['include_locked']),gmdate('Y-m-d\TH:i:s\Z'));
    }

    /** Exercise a saved connector without storing the fixed test phrase or generated media. */
    private function testConnector(array $values):string
    {
        $installation=$this->need($values,'installation_id');$this->uuid($installation,'installation_id');
        $configuration=$this->need($values,'configuration_id');$this->uuid($configuration,'configuration_id');
        $kind=$this->need($values,'kind');if(!in_array($kind,['tts_provider','stt_provider'],true))throw new InvalidArgumentException('invalid_connector_kind');
        $preset=$this->repository->getRevisioned($kind,$configuration);$token=new NeverCancelledToken();$started=microtime(true);
        if($kind==='tts_provider'){
            $voice=trim((string)($values['voice_id']??''));$context=$voice===''?[]:['voice'=>$voice];
            $audio=ProviderFactory::speechForPreset($this->providerConfig,$preset)->synthesize('Greetings, traveler. This is ALMSIVI.',$token,$context);
            return strtoupper((string)$audio['codec']).' '.(int)$audio['duration_ms'].' ms in '.(int)round((microtime(true)-$started)*1000).' ms';
        }
        $speechPreset=$this->repository->connectorForInstallation($installation,'tts_provider');
        if($speechPreset===null)throw new InvalidArgumentException('active_tts_connector_required');
        $audio=ProviderFactory::speechForPreset($this->providerConfig,$speechPreset)->synthesize('Greetings, traveler. This is ALMSIVI.',$token);
        $result=ProviderFactory::speechToTextForPreset($this->providerConfig,$preset)->transcribe($audio['bytes'],$audio['codec'],'en',$token);
        return trim((string)$result['text']).' ('.(int)round((microtime(true)-$started)*1000).' ms)';
    }

    /** Exercise one saved LLM model slot without persisting the fixed diagnostic turn or its response. */
    private function testProvider(array $values):string
    {
        $installation=$this->need($values,'installation_id');$this->uuid($installation,'installation_id');
        $configuration=$this->need($values,'configuration_id');$this->uuid($configuration,'configuration_id');
        $preset=$this->repository->getRevisioned('provider',$configuration);
        if(($preset['installation_id']??null)!==$installation)throw new InvalidArgumentException('invalid_provider_scope');
        $slot=['configuration_id'=>$configuration,'revision'=>(int)($preset['current_revision']??0),'content'=>$preset['content']??[]];
        return$this->diagnoseProvider(ProviderFactory::dialogueForSlot($this->providerConfig,$slot));
    }

    /** Validate one dialogue provider against the common utterance contract without saving its output. */
    private function diagnoseProvider(Provider $provider):string
    {
        $identity=['kind'=>'npc','display_name'=>'ALMSIVI Test NPC','record_id'=>'almsivi_test_npc','content_file'=>'ALMSIVI'];
        $player=['kind'=>'player','display_name'=>'Player','record_id'=>'player'];$started=microtime(true);
        $result=$provider->complete(['payload'=>[
            'input'=>['mode'=>'text','text'=>'Reply with one brief in-character greeting.'],'speaker'=>$player,'target'=>$identity,'audience'=>[$identity],
        ]],new NeverCancelledToken());
        $utterances=$result['utterances']??null;
        if(!is_array($utterances)||$utterances===[]||count($utterances)>4||!is_string($utterances[0]['text']??null)||trim($utterances[0]['text'])==='')
            throw new RuntimeException('provider_invalid_output');
        return count($utterances).' valid utterance'.(count($utterances)===1?'':'s').' in '.(int)round((microtime(true)-$started)*1000).' ms';
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
            'topic_desc_basic'=>$this->need($values,'topic_desc_basic'),'knowledge_class_basic'=>(string)($values['knowledge_class_basic']??''),
            'tags'=>(string)($values['tags']??''),'category'=>$this->need($values,'category'),
            'provenance'=>['source'=>$source,'category'=>$this->need($values,'category')]];
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
        ['name'=>'ai.stop','tier'=>1,'capability'=>'action.ai.stop','description'=>'Stop ALMSIVI movement packages.'],
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
    private function webRoot():string{return preg_replace('#/manage$#','',$this->basePath)?:'/ALMSIVIserver';}
    private function html(int $status,string $body):Response{return new Response($status,$body,['Content-Type'=>'text/html; charset=utf-8','Content-Security-Policy'=>"default-src 'none'; style-src 'self'; script-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'self'",'X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer']);}
    private function errorPage(string $e,int $status):Response{return$this->html($status,(new ManagementView($this->basePath))->error($e));}
    private function htmlRequest(Request $r):bool{return!str_contains($r->path,'/api/v1/');}
    private function style():string{return'<style>
:root{--bg:#100f12;--surface:#19171c;--surface-2:#211e24;--line:#3a3237;--line-hot:#8d5b2e;--text:#e8e2d8;--muted:#9e978f;--accent:#f27c11;--accent-soft:rgba(242,124,17,.15);--good:#79bf87;--bad:#df7777;color-scheme:dark}
*{box-sizing:border-box}html{background:var(--bg)}body{margin:0;background:radial-gradient(circle at 50% -10%,rgba(121,72,30,.16),transparent 36rem),var(--bg);color:var(--text);font:14px/1.5 "Segoe UI",Arial,sans-serif;min-height:100vh}a{color:#d6ad7b}a:hover{color:#ffd3a2}button,input,textarea,select{font:inherit}button{cursor:pointer}.skip{position:fixed;left:-9999px;top:1rem;z-index:100}.skip:focus{left:1rem;background:#fff;color:#000;padding:.65rem 1rem}.app-header{position:sticky;top:0;z-index:20;display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:1.25rem;align-items:center;padding:.75rem 1.25rem;background:rgba(16,15,18,.96);border-bottom:1px solid var(--line);box-shadow:0 10px 28px rgba(0,0,0,.35);backdrop-filter:blur(12px)}.brand{display:flex;align-items:center;gap:.65rem;min-width:max-content}.brand strong{display:block;color:#fff4e6;font-size:1.1rem;letter-spacing:.16em}.brand small{display:block;color:var(--muted);font-size:.69rem;letter-spacing:.04em}.brand-mark{display:grid;place-items:center;width:2.45rem;height:2.45rem;border:1px solid var(--line-hot);border-radius:50%;background:linear-gradient(145deg,#2a211c,#171417);color:var(--accent);font:700 1.35rem Georgia,serif;box-shadow:inset 0 0 0 3px #171417,0 0 18px rgba(242,124,17,.12)}nav{display:flex;gap:.25rem;align-items:center;overflow-x:auto;padding:.15rem}nav a{flex:0 0 auto;padding:.48rem .62rem;border:1px solid transparent;border-radius:4px;color:#aaa3a0;text-decoration:none;font-size:.76rem;font-weight:650;letter-spacing:.025em}nav a:hover{background:#242127;color:#f5eee6;border-color:#373139}nav a[aria-current=page]{background:var(--accent-soft);border-color:rgba(242,124,17,.45);color:#ffb96f}.logout{margin:0;padding:0;border:0;display:block}.quiet{padding:.45rem .7rem;background:#252228;border:1px solid var(--line);border-radius:4px;color:#bdb6b0}.quiet:hover{border-color:var(--line-hot);color:white}main{width:min(1180px,calc(100% - 2rem));margin:0 auto;padding:2.2rem 0 4rem}.page-heading{margin:0 0 1.35rem}.page-heading h1{margin:.15rem 0 0;color:#f4eee6;font:400 clamp(1.7rem,4vw,2.65rem)/1.1 Georgia,serif}.eyebrow{margin:0;color:var(--accent);font-size:.7rem;font-weight:750;letter-spacing:.16em;text-transform:uppercase}section,form{margin:0 0 1rem;padding:1.15rem;border:1px solid var(--line);border-radius:6px;background:linear-gradient(145deg,rgba(31,28,33,.96),rgba(22,20,24,.96));box-shadow:0 10px 28px rgba(0,0,0,.18)}section h2,legend{color:#efe8df;font:400 1.15rem Georgia,serif}section h2{margin:0 0 .75rem}section p{color:#b4ada6}.hero{display:flex;justify-content:space-between;gap:2rem;align-items:flex-start;padding:1.55rem;border-color:#4a382e;background:linear-gradient(120deg,rgba(66,40,23,.42),rgba(28,25,30,.97) 55%)}.hero h2{margin:.25rem 0 .55rem;font-size:1.55rem}.hero p{max-width:52rem;margin:.35rem 0}.status-pill{flex:0 0 auto;display:inline-flex;align-items:center;gap:.45rem;padding:.45rem .7rem;border:1px solid rgba(121,191,135,.35);border-radius:999px;background:rgba(121,191,135,.08);color:#a5ddb0;font-size:.75rem;font-weight:700}.status-pill i{width:.48rem;height:.48rem;border-radius:50%;background:var(--good);box-shadow:0 0 10px var(--good)}.stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.7rem;padding:0;border:0;background:none;box-shadow:none}.stat-grid article{padding:1rem;border:1px solid var(--line);border-radius:6px;background:var(--surface)}.stat-grid span,.stat-grid small{display:block;color:var(--muted);font-size:.72rem}.stat-grid strong{display:block;margin:.25rem 0;color:#f5eee8;font-size:1.35rem}.steps{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem;list-style:none;counter-reset:step;margin:.9rem 0 0;padding:0}.steps li{counter-increment:step;display:grid;grid-template-columns:2rem 1fr;gap:.1rem .65rem;padding:.85rem;border:1px solid #373139;border-radius:5px;background:#171519}.steps li:before{content:counter(step);grid-row:1/3;display:grid;place-items:center;width:1.8rem;height:1.8rem;border-radius:50%;background:var(--accent-soft);color:var(--accent);font-weight:800}.steps strong{font-size:.82rem}.steps span{color:var(--muted);font-size:.75rem}form{display:grid;gap:.6rem;max-width:54rem}fieldset{display:grid;gap:.55rem;padding:0;border:0}legend{margin-bottom:.45rem}label{color:#c8c0b7;font-size:.77rem;font-weight:700}input,textarea,select{width:100%;padding:.62rem .7rem;border:1px solid #494149;border-radius:4px;background:#121114;color:var(--text)}input:focus,textarea:focus,select:focus,button:focus-visible,a:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-color:var(--accent)}textarea{min-height:8.5rem;resize:vertical}button{justify-self:start;padding:.62rem .9rem;border:1px solid #a15e26;border-radius:4px;background:#7f400e;color:#fff7ef;font-weight:750}button:hover{background:#9b4c0d}form p{margin:.2rem 0;color:var(--muted);font-size:.76rem}dl{display:grid;grid-template-columns:max-content 1fr;gap:.4rem 1rem}dt{color:var(--muted)}dd{margin:0;color:#eee}div[role=alert]{padding:.7rem;border:1px solid rgba(223,119,119,.5);border-radius:4px;background:rgba(223,119,119,.09);color:#ffc0c0}p[role=status]{padding:.7rem;border-left:3px solid var(--good);background:rgba(121,191,135,.08);color:#b9e4c2}footer{padding:1rem;border-top:1px solid #29252b;color:#746e69;text-align:center;font-size:.7rem}.login{display:grid;place-items:center}.login main{display:grid;place-items:center;min-height:100vh;padding:1rem}.login-card{width:min(28rem,100%);padding:2rem;text-align:center;border-color:#4a382e}.login-card .brand-mark{margin:0 auto 1rem}.login-card h1{margin:.2rem 0;font:400 2.4rem Georgia,serif;letter-spacing:.16em}.login-card form{text-align:left;margin:1.4rem 0 0;padding:0;border:0;background:none;box-shadow:none}.login-card button{justify-self:stretch}body.login footer{display:none}
.table-scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:.78rem}th,td{padding:.65rem;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{color:#cbb9a7;font-size:.68rem;letter-spacing:.08em;text-transform:uppercase}code{color:#ffbd7d}
@media(max-width:920px){.app-header{grid-template-columns:1fr auto}.app-header nav{grid-column:1/-1;grid-row:2}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){main{width:min(100% - 1rem,1180px);padding-top:1.15rem}.app-header{padding:.65rem .75rem}.brand small{display:none}.hero{display:block}.status-pill{margin-top:1rem}.stat-grid,.steps{grid-template-columns:1fr}section,form{padding:.9rem}.page-heading h1{font-size:1.8rem}}
</style>';}
    private function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
