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
    private const PAGES=['quickstart','profiles','providers','prompts-actions','traces','memory','relationships','knowledge','playthroughs','narrative-autonomy','jobs','backup-health'];

    public function __construct(private readonly ManagementRepository $management,private readonly ProductRepository $repository,
        private readonly ProductService $service,private readonly string $setupSecretHash,private readonly string $basePath='/ALMSIVIserver/manage',
        private readonly int $maxJsonBytes=2_097_152,private readonly int $sessionTtl=3600){}

    public function dispatch(Request $r):Response
    {
        try{
            $path=$this->path($r->path);
            if($r->method==='GET'&&$path==='/login')return$this->loginPage();
            if($r->method==='POST'&&$path==='/login')return$this->login($r);
            $session=$this->authenticate($r);
            if(in_array($r->method,['POST','PUT','PATCH','DELETE'],true))$this->csrf($r,$session);
            if($r->method==='POST'&&$path==='/logout'){$this->management->revoke($session);return$this->redirect($this->basePath.'/login',['Set-Cookie'=>['almsivi_management=; Path='.$this->basePath.'; Max-Age=0; HttpOnly; SameSite=Strict','almsivi_csrf=; Path='.$this->basePath.'; Max-Age=0; SameSite=Strict']]);}
            if($r->method==='GET'&&$path==='/')return$this->redirect($this->basePath.'/quickstart');
            if(str_starts_with($path,'/api/v1/'))return$this->api($r,$path);
            if($r->method==='POST'&&preg_match('#^/forms/([a-z-]+)$#D',$path,$m))return$this->submit($m[1],$r);
            if($r->method==='GET'&&in_array(ltrim($path,'/'),self::PAGES,true))return$this->page(ltrim($path,'/'),$r);
            throw new RuntimeException('not_found');
        }catch(InvalidArgumentException $e){return$this->htmlRequest($r)?$this->errorPage($e->getMessage(),422):Response::json(422,['error'=>$e->getMessage()]);}
        catch(RuntimeException $e){$status=$e->getMessage()==='unauthorized'?401:404;if($status===401&&$this->htmlRequest($r))return$this->redirect($this->basePath.'/login');return Response::json($status,['error'=>$e->getMessage()]);}
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
        return$this->redirect($this->basePath.'/'.$target.'?status=saved');
    }

    private function page(string $slug,Request $r):Response
    {
        $csrf=BrowserSession::parseCsrf($r->header('Cookie'))??'';$title=ucwords(str_replace('-',' ',$slug));$body=isset($r->query['status'])?'<p role="status">Changes saved.</p>':'';
        $scope=$this->fields(['installation_id'=>'Installation ID','profile_id'=>'Profile ID','playthrough_id'=>'Playthrough ID']);
        $body.=match($slug){
            'quickstart'=>$this->quickstart(),
            'profiles'=>$this->formHtml('profiles','Create profile',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Name','content_json'=>'Profile JSON'],true)),
            'providers'=>$this->formHtml('providers','Create deterministic mock provider',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Name','content_json'=>'Provider JSON'],true)),
            'prompts-actions'=>$this->formHtml('prompts','Create prompt',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Name','content_json'=>'Prompt JSON'],true)).$this->formHtml('action-policies','Create action policy',$csrf,$this->fields(['installation_id'=>'Installation ID','name'=>'Name','content_json'=>'Policy JSON'],true)),
            'playthroughs'=>$this->formHtml('playthroughs','Create playthrough',$csrf,$this->fields(['installation_id'=>'Installation ID','profile_id'=>'Profile ID','name'=>'Name','content_json'=>'Playthrough JSON'],true)),
            'memory'=>$this->formHtml('memory','Create memory',$csrf,$scope.$this->select('tier','Tier',['recent','mid','long']).$this->area('content','Memory').$this->input('provenance','Provenance source')),
            'relationships'=>$this->formHtml('relationships','Save relationship',$csrf,$scope.$this->area('content_json','Actor identity JSON','{}').$this->input('disposition','Disposition','number','0').$this->input('affinity','Affinity','number','0').$this->input('reason','Reason','text','management')),
            'knowledge'=>$this->formHtml('knowledge','Create knowledge',$csrf,$scope.$this->input('title','Title').$this->area('content','Knowledge').$this->input('provenance','Provenance source')),
            'narrative-autonomy'=>$this->formHtml('narratives','Create narrative',$csrf,$scope.$this->select('kind','Narrative kind',['narrator','diary','summary']).$this->input('title','Title').$this->area('content','Narrative').$this->input('provenance','Provenance source')).$this->formHtml('autonomy','Save schedule',$csrf,$scope.$this->select('kind','Schedule kind',['rechat','boredom','greeting']).$this->input('interval_seconds','Interval seconds','number','30').$this->input('cooldown_seconds','Cooldown seconds','number','30').$this->input('current_session_id','Current session ID').'<label><input name="enabled" type="checkbox" value="1"> Enabled after current-session confirmation</label>'),
            'traces'=>'<section><h2>Events and traces</h2><p>Use the authenticated traces API with installation scope. Provider and prompt details remain redacted.</p></section>',
            'jobs'=>'<section><h2>Workers and jobs</h2><p>Queue and dead-letter counts are shown in diagnostics. Worker leases and retries are bounded.</p></section>',
            'backup-health'=>$this->formHtml('retention','Run bounded retention',$csrf,$this->input('days','Retention days','number','30').'<p>This removes expired operational metadata and never accepts a filesystem path.</p>'),
            default=>''};
        $nav='';foreach(self::PAGES as$v)$nav.='<a '.($v===$slug?'aria-current="page" ':'').'href="'.$this->basePath.'/'.$v.'">'.$this->e(ucwords(str_replace('-',' ',$v))).'</a> ';
        return$this->html(200,'<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>'.$this->e($title).'</title>'.$this->style().'</head><body><a class="skip" href="#main">Skip to content</a><header><h1>'.$this->e($title).'</h1><nav aria-label="Management">'.$nav.'</nav><form method="post" action="'.$this->basePath.'/logout"><input type="hidden" name="_csrf" value="'.$this->e($csrf).'"><button>Sign out</button></form></header><main id="main">'.$body.'</main></body></html>');
    }

    private function quickstart():string{$d=$this->repository->diagnostics();return'<section><h2>Server status</h2><dl><dt>Database</dt><dd>'.$this->e((string)$d['database']['version']).'</dd><dt>Active sessions</dt><dd>'.$d['counts']['active_sessions'].'</dd><dt>Queued jobs</dt><dd>'.$d['counts']['queued_jobs'].'</dd><dt>Dead jobs</dt><dd>'.$d['counts']['dead_jobs'].'</dd></dl><p>Provider mode is deterministic mock. Turn acceptance is durable and provider processing is worker-owned.</p></section>';}
    private function formHtml(string $route,string $legend,string $csrf,string $fields):string{return'<form method="post" action="'.$this->basePath.'/forms/'.$route.'"><fieldset><legend>'.$this->e($legend).'</legend>'.$fields.'</fieldset><input type="hidden" name="_csrf" value="'.$this->e($csrf).'"><button type="submit">'.$this->e($legend).'</button></form>';}
    private function fields(array $items,bool $json=false):string{$s='';foreach($items as$n=>$l)$s.=$json&&$n==='content_json'?$this->area($n,$l,'{}'):$this->input($n,$l);return$s;}
    private function input(string $n,string $l,string $type='text',string $value=''):string{$id='f-'.$n;return'<label for="'.$id.'">'.$this->e($l).'</label><input id="'.$id.'" name="'.$n.'" type="'.$type.'" value="'.$this->e($value).'" required>';}
    private function area(string $n,string $l,string $value=''):string{$id='f-'.$n;return'<label for="'.$id.'">'.$this->e($l).'</label><textarea id="'.$id.'" name="'.$n.'" required>'.$this->e($value).'</textarea>';}
    private function select(string $n,string $l,array $values):string{$id='f-'.$n;$o='';foreach($values as$v)$o.='<option value="'.$this->e($v).'">'.$this->e(ucwords($v)).'</option>';return'<label for="'.$id.'">'.$this->e($l).'</label><select id="'.$id.'" name="'.$n.'">'.$o.'</select>';}

    private function login(Request $r):Response{$v=$this->form($r);$secret=(string)($v['setup_secret']??'');if($this->setupSecretHash===''||!hash_equals($this->setupSecretHash,hash('sha256',$secret)))return$this->loginPage('The setup secret was not accepted.',401);$c=$this->management->createSession($this->sessionTtl);return$this->redirect($this->basePath.'/quickstart',['Set-Cookie'=>[BrowserSession::cookie($c['session'],$this->sessionTtl,$this->basePath),BrowserSession::csrfCookie($c['csrf'],$this->sessionTtl,$this->basePath)],'X-CSRF-Token'=>$c['csrf']]);}
    private function loginPage(?string $error=null,int $status=200):Response{return$this->html($status,'<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>ALMSIVIserver login</title></head><body><main><h1>ALMSIVIserver management</h1>'.($error===null?'':'<div role="alert"><p>'.$this->e($error).'</p></div>').'<form method="post"><label for="setup_secret">Setup secret</label><input id="setup_secret" name="setup_secret" type="password" required autocomplete="current-password"><button>Sign in</button></form></main></body></html>');}
    private function authenticate(Request $r):string{$t=BrowserSession::parse($r->header('Cookie'));if($t===null||!$this->management->validate($t))throw new RuntimeException('unauthorized');return$t;}
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
    private function actions():array{return[['name'=>'inspect.report','tier'=>0,'capability'=>'action.inspect.report','description'=>'Read-only observation report.'],['name'=>'ai.follow','tier'=>1,'capability'=>'action.ai.follow','parameters'=>['distance'=>192]]];}
    private function redirect(string $to,array $headers=[]):Response{return new Response(303,'',$headers+['Location'=>$to,'Content-Type'=>'text/plain; charset=utf-8']);}
    private function html(int $status,string $body):Response{return new Response($status,$body,['Content-Type'=>'text/html; charset=utf-8','Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",'X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer']);}
    private function errorPage(string $e,int $status):Response{return$this->html($status,'<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Management error</title></head><body><main><h1>Request could not be saved</h1><div role="alert"><p>'.$this->e($e).'</p></div><a href="'.$this->basePath.'/quickstart">Return to management</a></main></body></html>');}
    private function htmlRequest(Request $r):bool{return!str_contains($r->path,'/api/v1/');}
    private function style():string{return'<style>body{font:1rem system-ui;max-width:76rem;margin:auto;padding:1rem;line-height:1.5}nav{display:flex;gap:.75rem;flex-wrap:wrap}form{display:grid;gap:.5rem;max-width:48rem;margin:1.5rem 0;padding:1rem;border:1px solid #888}label{font-weight:600}input,textarea,select,button{font:inherit;padding:.45rem}textarea{min-height:7rem}:focus{outline:3px solid #005fcc}.skip{position:absolute;left:-9999px}.skip:focus{left:1rem}</style>';}
    private function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
