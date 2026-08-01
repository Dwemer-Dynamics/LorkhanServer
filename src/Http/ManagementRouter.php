<?php

declare(strict_types=1);

namespace ALMSIVIserver\Http;

use ALMSIVIserver\Application\DeterministicRetrieval;
use ALMSIVIserver\Application\ProductService;
use ALMSIVIserver\Infrastructure\ManagementRepository;
use ALMSIVIserver\Infrastructure\ProductRepository;
use ALMSIVIserver\Security\BrowserSession;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ManagementRouter
{
    private const PAGES=['quickstart','roleplay','configuration','control-panel','characters','profiles','providers','ai-voice','prompts-actions','world','traces','memory','relationships','knowledge','playthroughs','narrative-autonomy','jobs','backup-health','diagnostics'];
    private const UI_PAGES=[
        'quickstart'=>'/ui/home.php',
        'roleplay'=>'/ui/events-memories.php',
        'configuration'=>'/ui/core/config_hub.php',
        'control-panel'=>'/ui/control_panel.php',
        'characters'=>'/ui/core/character_manager.php',
        'profiles'=>'/ui/core/core_profiles.php',
        'providers'=>'/ui/core/config_hub.php?tab=llm-page',
        'ai-voice'=>'/ui/core/config_hub.php?tab=tts-page',
        'prompts-actions'=>'/ui/core/config_hub.php?tab=prompts-page',
        'world'=>'/ui/core/config_hub.php?tab=globals-page',
        'traces'=>'/ui/request_logs.php',
        'memory'=>'/ui/events-memories.php?tab=memories-tab',
        'relationships'=>'/ui/events-memories.php?tab=relationships-tab',
        'knowledge'=>'/ui/core/config_hub.php?tab=knowledge-page',
        'playthroughs'=>'/ui/control_panel.php?tab=playthrough-page',
        'narrative-autonomy'=>'/ui/core/config_hub.php?tab=autonomy-page',
        'jobs'=>'/ui/control_panel.php?tab=jobs-page',
        'backup-health'=>'/ui/control_panel.php?tab=backup-page',
        'diagnostics'=>'/ui/control_panel.php?tab=health-page',
    ];

    public function __construct(private readonly ManagementRepository $management,private readonly ProductRepository $repository,
        private readonly ProductService $service,private readonly string $basePath='/ALMSIVIserver/manage',
        private readonly int $maxJsonBytes=2_097_152,private readonly int $sessionTtl=3600){}

    public function dispatch(Request $r):Response
    {
        try{
            $path=$this->path($r->path);
            if($r->method==='GET'&&in_array($path,['/','/login'],true))return$this->redirect($this->uiPath('quickstart'));
            if($r->method==='GET'&&in_array(ltrim($path,'/'),self::PAGES,true))return$this->redirect($this->uiPath(ltrim($path,'/')));
            $session=$this->authenticatedSession($r);
            if($session===null){if($r->method==='GET'&&$this->htmlRequest($r))return$this->openBrowserSession($r->path);throw new RuntimeException('unauthorized');}
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
        if($r->method==='GET'&&$path==='/api/v1/diagnostics')return Response::json(200,$this->repository->diagnostics());
        if($r->method==='GET'&&$path==='/api/v1/actions')return Response::json(200,['items'=>$this->actions()]);
        if($r->method==='GET'&&$path==='/api/v1/traces')return Response::json(200,['items'=>$this->repository->searchTraces($this->queryUuid($r,'installation_id'),(string)($r->query['q']??''))]);
        if($r->method==='GET'&&preg_match('#^/api/v1/traces/([0-9a-f-]{36})$#D',$path,$m))return Response::json(200,$this->repository->traceDetail($m[1]));
        if(preg_match('#^/api/v1/(profiles|playthroughs|prompts|providers|action-policies)$#D',$path,$m)){
            $kind=$this->singular($m[1]);if($r->method==='GET')return Response::json(200,['items'=>$this->repository->listRevisioned($kind,$this->queryUuid($r,'installation_id'))]);
            if($r->method==='POST')return Response::json(201,$this->service->createRevisioned($kind,$this->json($r)));
        }
        if($r->method==='POST'&&preg_match('#^/api/v1/(profiles|playthroughs|prompts|providers|action-policies)/([0-9a-f-]{36})/(revisions|rollback)$#D',$path,$m)){$b=$this->json($r);return$m[3]==='revisions'?Response::json(201,$this->service->revise($this->singular($m[1]),$m[2],$b['content']??[],$b['reason']??'updated')):Response::json(200,$this->service->rollback($this->singular($m[1]),$m[2],(int)($b['revision']??0),(string)($b['reason']??'rollback')));}
        if($r->method==='POST'&&$path==='/api/v1/memory')return Response::json(201,$this->service->createMemory($this->json($r)));
        if($r->method==='GET'&&$path==='/api/v1/memory/search')return Response::json(200,$this->service->searchMemory($this->scopeQuery($r),(string)($r->query['q']??''),(int)($r->query['limit']??10)));
        if($r->method==='PATCH'&&preg_match('#^/api/v1/memory/([0-9a-f-]{36})$#D',$path,$m)){$b=$this->json($r);$c=(string)($b['content']??'');if($c===''||strlen($c)>16384)throw new InvalidArgumentException('invalid_content');return Response::json(200,$this->repository->updateMemory($m[1],$c,DeterministicRetrieval::terms($c),DeterministicRetrieval::fakeVector($c),gmdate('Y-m-d\TH:i:s\Z')));}
        if($r->method==='DELETE'&&preg_match('#^/api/v1/memory/([0-9a-f-]{36})$#D',$path,$m)){$this->repository->deleteMemory($m[1],gmdate('Y-m-d\TH:i:s\Z'));return Response::json(200,['deleted'=>true]);}
        if($r->method==='POST'&&$path==='/api/v1/memory/rebuild')return Response::json(200,['rebuilt'=>$this->repository->rebuildMemories($this->json($r),gmdate('Y-m-d\TH:i:s\Z'))]);
        if($path==='/api/v1/relationships')return$r->method==='POST'?Response::json(201,$this->service->setRelationship($this->json($r))):Response::json(200,['items'=>$this->repository->relationships($this->scopeQuery($r))]);
        if($r->method==='POST'&&$path==='/api/v1/knowledge')return Response::json(201,$this->service->ingestKnowledge($this->json($r)));
        if($r->method==='GET'&&$path==='/api/v1/knowledge/search')return Response::json(200,$this->service->searchKnowledge($this->scopeQuery($r),(string)($r->query['q']??''),(int)($r->query['limit']??10)));
        if($r->method==='DELETE'&&preg_match('#^/api/v1/knowledge/([0-9a-f-]{36})$#D',$path,$m)){$this->repository->deleteKnowledge($m[1],gmdate('Y-m-d\TH:i:s\Z'));return Response::json(200,['deleted'=>true]);}
        if($path==='/api/v1/narratives')return$r->method==='POST'?Response::json(201,$this->service->createNarrative($this->json($r))):Response::json(200,['items'=>$this->repository->narratives($this->scopeQuery($r))]);
        if($r->method==='POST'&&$path==='/api/v1/autonomy')return Response::json(201,$this->service->scheduleAutonomy($this->json($r)));
        if($r->method==='GET'&&$path==='/api/v1/autonomy/due')return Response::json(200,['items'=>$this->service->dueAutonomy($this->scopeQuery($r))]);
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
        match($domain){
            'profiles'=>$this->service->createRevisioned('profile',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$content]),
            'providers'=>$this->service->createRevisioned('provider',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$content+['driver'=>'mock']]),
            'prompts'=>$this->service->createRevisioned('prompt',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$content]),
            'action-policies'=>$this->service->createRevisioned('action_policy',['installation_id'=>$scope['installation_id'],'name'=>$this->need($v,'name'),'content'=>$content]),
            'playthroughs'=>$this->service->createRevisioned('playthrough',['installation_id'=>$scope['installation_id'],'profile_id'=>$scope['profile_id'],'name'=>$this->need($v,'name'),'content'=>$content]),
            'memory'=>$this->service->createMemory($scope+['tier'=>$v['tier']??'recent','content'=>$this->need($v,'content'),'provenance'=>['source'=>$this->need($v,'provenance')]]),
            'relationships'=>$this->service->setRelationship($scope+['actor_identity'=>$content,'disposition'=>(int)($v['disposition']??0),'affinity'=>(int)($v['affinity']??0),'source_mode'=>'manual','reason'=>$v['reason']??'management']),
            'knowledge'=>$this->service->ingestKnowledge($scope+['title'=>$this->need($v,'title'),'content'=>$this->need($v,'content'),'provenance'=>['source'=>$this->need($v,'provenance')]]),
            'narratives'=>$this->service->createNarrative($scope+['kind'=>$v['kind']??'narrator','title'=>$this->need($v,'title'),'content'=>$this->need($v,'content'),'provenance'=>['source'=>$this->need($v,'provenance')]]),
            'autonomy'=>$this->service->scheduleAutonomy($scope+['kind'=>$v['kind']??'rechat','enabled'=>isset($v['enabled']),'interval_seconds'=>(int)($v['interval_seconds']??30),'cooldown_seconds'=>(int)($v['cooldown_seconds']??30)]+(isset($v['enabled'])?['current_session_id'=>$this->need($v,'current_session_id'),'confirmed_at'=>gmdate('Y-m-d\TH:i:s\Z')]:[])),
            'retention'=>$this->repository->prune((int)($v['days']??30),gmdate('Y-m-d\TH:i:s\Z')),
            default=>throw new RuntimeException('not_found')};
        $target=match($domain){'prompts','action-policies'=>'prompts-actions','narratives','autonomy'=>'narrative-autonomy','retention'=>'backup-health',default=>$domain};
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
                ['narrative-autonomy','Narrative & Autonomy','Configure narratives and autonomous schedules.'],
            ]),
            'configuration'=>$this->hubHtml([
                ['providers','Providers','Configure deterministic and live provider presets.'],
                ['ai-voice','AI & Voice','Configure LLM, TTS, and STT connectors.'],
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
            'ai-voice'=>$this->formHtml('providers','Create LLM, TTS, or STT connector preset',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Connector name','content_json'=>'Connector JSON'],true)),
            'prompts-actions'=>$this->formHtml('prompts','Create prompt',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Name','content_json'=>'Prompt JSON'],true)).$this->formHtml('action-policies','Create action policy',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Name','content_json'=>'Policy JSON'],true)),
            'world'=>$this->formHtml('knowledge','Add Morrowind world knowledge',$csrf,$scope.$this->input('title','Title').$this->area('content','Knowledge').$this->input('provenance','Provenance source')),
            'playthroughs'=>$this->formHtml('playthroughs','Create playthrough',$csrf,$this->fields(['installation_id'=>'Installation ID','profile_id'=>'Profile ID','name'=>'Name','content_json'=>'Playthrough JSON'],true)),
            'memory'=>$this->formHtml('memory','Create memory',$csrf,$scope.$this->select('tier','Tier',['recent','mid','long']).$this->area('content','Memory').$this->input('provenance','Provenance source')),
            'relationships'=>$this->formHtml('relationships','Save relationship',$csrf,$scope.$this->area('content_json','Actor identity JSON','{}').$this->input('disposition','Disposition','number','0').$this->input('affinity','Affinity','number','0').$this->input('reason','Reason','text','management')),
            'knowledge'=>$this->formHtml('knowledge','Create knowledge',$csrf,$scope.$this->input('title','Title').$this->area('content','Knowledge').$this->input('provenance','Provenance source')),
            'narrative-autonomy'=>$this->formHtml('narratives','Create narrative',$csrf,$scope.$this->select('kind','Narrative kind',['narrator','diary','summary']).$this->input('title','Title').$this->area('content','Narrative').$this->input('provenance','Provenance source')).$this->formHtml('autonomy','Save schedule',$csrf,$scope.$this->select('kind','Schedule kind',['rechat','boredom','greeting']).$this->input('interval_seconds','Interval seconds','number','30').$this->input('cooldown_seconds','Cooldown seconds','number','30').$this->input('current_session_id','Current session ID').'<label><input name="enabled" type="checkbox" value="1"> Enabled after current-session confirmation</label>'),
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

    private function authenticatedSession(Request $r):?string{$t=BrowserSession::parse($r->header('Cookie'));return$t!==null&&$this->management->validate($t)?$t:null;}
    /** Start a local browser session transparently so the UI stays open while form writes remain CSRF-protected. */
    private function openBrowserSession(string $target):Response{$c=$this->management->createSession($this->sessionTtl);return$this->redirect($target,['Set-Cookie'=>[BrowserSession::cookie($c['session'],$this->sessionTtl,$this->webRoot()),BrowserSession::csrfCookie($c['csrf'],$this->sessionTtl,$this->webRoot())],'X-CSRF-Token'=>$c['csrf']]);}
    private function csrf(Request $r,string $session):void{$v=$this->form($r);$t=$r->header('X-CSRF-Token')??($v['_csrf']??null);if(!is_string($t)||!$this->management->validate($session,$t))throw new RuntimeException('unauthorized');}
    private function json(Request $r):array{if(strlen($r->body)>$this->maxJsonBytes)throw new InvalidArgumentException('payload_too_large');try{$v=json_decode($r->body,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new InvalidArgumentException('invalid_json');}if(!is_array($v)||array_is_list($v))throw new InvalidArgumentException('invalid_json');return$v;}
    private function form(Request $r):array{if(strlen($r->body)>$this->maxJsonBytes)throw new InvalidArgumentException('payload_too_large');parse_str($r->body,$v);return is_array($v)?$v:[];}
    private function scopeQuery(Request $r):array{return['installation_id'=>$this->queryUuid($r,'installation_id'),'profile_id'=>$this->queryUuid($r,'profile_id'),'playthrough_id'=>$this->queryUuid($r,'playthrough_id')];}
    private function scopeForm(array $v):array{$out=[];foreach(['installation_id','profile_id','playthrough_id']as$k)if(isset($v[$k])){$this->uuid((string)$v[$k],$k);$out[$k]=(string)$v[$k];}return$out;}
    private function queryUuid(Request $r,string $k):string{$v=(string)($r->query[$k]??'');$this->uuid($v,$k);return$v;}
    private function uuid(string $v,string $k):void{if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$v)!==1)throw new InvalidArgumentException('invalid_'.$k);}
    private function jsonField(array $v,string $k):array{try{$d=json_decode((string)($v[$k]??'{}'),true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new InvalidArgumentException('invalid_'.$k);}if(!is_array($d)||array_is_list($d))throw new InvalidArgumentException('invalid_'.$k);return$d;}
    private function need(array $v,string $k):string{$s=trim((string)($v[$k]??''));if($s==='')throw new InvalidArgumentException('invalid_'.$k);return$s;}
    private function path(string $p):string{if(!str_starts_with($p,$this->basePath))throw new RuntimeException('not_found');$v=substr($p,strlen($this->basePath));return$v===''?'/':$v;}
    private function singular(string $v):string{return match($v){'profiles'=>'profile','playthroughs'=>'playthrough','prompts'=>'prompt','providers'=>'provider','action-policies'=>'action_policy'};}
    private function actionCatalog():string
    {
        $rows='';foreach($this->actions() as$action){$rows.='<tr><td><code>'.$this->e($action['name']).'</code></td><td>'.$action['tier'].'</td><td>'.$this->e($action['capability']).'</td><td>'.$this->e((string)($action['description']??'Catalog-backed typed action.')).'</td></tr>';}
        return'<section><h2>Negotiated action catalog</h2><div class="table-scroll"><table><thead><tr><th>Action</th><th>Tier</th><th>Capability</th><th>Purpose</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
    }
    private function actions():array{return[
        ['name'=>'inspect.report','tier'=>0,'capability'=>'action.inspect.report','description'=>'Read-only observation report.'],
        ['name'=>'ai.follow','tier'=>1,'capability'=>'action.ai.follow','description'=>'Follow the player.'],
        ['name'=>'ai.stop','tier'=>1,'capability'=>'action.ai.stop','description'=>'Stop ALMSIVI movement packages.'],
        ['name'=>'ai.wander','tier'=>1,'capability'=>'action.ai.wander','description'=>'Bounded local wandering.'],
        ['name'=>'combat.start','tier'=>2,'capability'=>'action.combat.start','description'=>'Start combat after player confirmation.'],
        ['name'=>'combat.stop','tier'=>1,'capability'=>'action.combat.stop','description'=>'Stop combat with the selected target.'],
        ['name'=>'animation.play','tier'=>1,'capability'=>'action.animation.play','description'=>'Play an allowlisted idle animation.'],
        ['name'=>'item.use','tier'=>2,'capability'=>'action.item.use','description'=>'Use an existing inventory item after confirmation.'],
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
