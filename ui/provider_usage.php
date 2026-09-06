<?php
declare(strict_types=1);
$pageTitle='Cost Breakdown';$topNavSection='control';$BODY_CLASS='hub-page cost-breakdown-shell';
require __DIR__.'/ui_bootstrap.php';require __DIR__.'/tmpl/control_reader.php';
$state=lorkhan_control_state($uiRepository->rows('installations'));
if(array_key_exists('installation_id',$_GET)&&$_GET['installation_id']==='')$state['installation']='';
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$filter=(string)($_GET['filter']??(isset($_GET['period'])?'period':'today'));
if(!in_array($filter,['today','date','week','period'],true))$filter='today';
$selectedDate=(string)($_GET['date']??$now->format('Y-m-d'));
$date=preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$selectedDate)?DateTimeImmutable::createFromFormat('!Y-m-d',$selectedDate,new DateTimeZone('UTC')):false;
if(!$date||$date->format('Y-m-d')!==$selectedDate||$date->format('Y')==='0000'){$selectedDate=$now->format('Y-m-d');$date=$now->setTime(0,0);}
$selectedWeek=(string)($_GET['week']??$now->format('o-\WW'));
$week=null;
if(preg_match('/^([0-9]{4})-W([0-9]{2})$/D',$selectedWeek,$parts)&&$parts[1]!=='0000'){
    $week=$now->setISODate((int)$parts[1],(int)$parts[2])->setTime(0,0);
    if($week->format('o-\WW')!==$selectedWeek)$week=null;
}
if(!$week){$selectedWeek=$now->format('o-\WW');$week=$now->setISODate((int)$now->format('o'),(int)$now->format('W'))->setTime(0,0);}
$until=null;
if($filter==='period')$periodLabel=$state['periods'][$state['period']];
else{
    $start=$filter==='week'?$week:($filter==='date'?$date:$now->setTime(0,0));
    $state['since']=$start->format(DATE_ATOM);$until=$start->modify($filter==='week'?'+1 week':'+1 day')->format(DATE_ATOM);
    $periodLabel=$filter==='week'?'Week: '.$selectedWeek:($filter==='date'?'Date: '.$selectedDate:'Date: Today ('.$now->format('Y-m-d').')');
}
$costUrl=static fn(array $changes=[]):string=>'?'.http_build_query(array_merge(['installation_id'=>$state['installation'],'filter'=>$filter,'date'=>$selectedDate,'week'=>$selectedWeek,'period'=>$state['period'],'embed'=>$embedded?'1':'0'],$changes));
$conditions=[];$params=[];
if($state['installation']!==''){$conditions[]="COALESCE(session.installation_id::text,j.payload->>'installation_id')=:installation";$params['installation']=$state['installation'];}
if($state['since']!==null){$conditions[]='a.started_at>=CAST(:since AS timestamptz)';$params['since']=$state['since'];}
if($until!==null){$conditions[]='a.started_at<CAST(:until AS timestamptz)';$params['until']=$until;}
$from='FROM provider_attempts a LEFT JOIN turns t ON t.turn_id=a.turn_id LEFT JOIN sessions session ON session.session_id=t.session_id LEFT JOIN durable_jobs j ON j.job_id=a.job_id';
$where=$conditions===[]?'':' WHERE '.implode(' AND ',$conditions);
// Only measured, numeric usage fields are aggregated. Missing values remain unknown.
$usage=[];
foreach(['prompt_tokens','completion_tokens','total_tokens','cost_usd']as$key)
    $usage[$key]="CASE WHEN jsonb_typeof(a.metadata#>'{usage,".$key."}')='number' AND (a.metadata#>>'{usage,".$key."}')::numeric>=0 THEN (a.metadata#>>'{usage,".$key."}')::numeric ELSE NULL END";
$summary="count(*)::int AS attempts,count(*) FILTER(WHERE a.state='succeeded')::int AS succeeded,"
    ."count(*) FILTER(WHERE a.state IN ('failed','cancelled'))::int AS failed,"
    .'count('.$usage['cost_usd'].')::int AS priced,sum('.$usage['cost_usd'].') AS cost_usd,'
    .'sum('.$usage['prompt_tokens'].') AS prompt_tokens,sum('.$usage['completion_tokens'].') AS completion_tokens,'
    .'sum('.$usage['total_tokens'].') AS total_tokens,count('.$usage['total_tokens'].')::int AS token_coverage,'
    .'avg(a.duration_ms) AS duration_ms';
$statement=$database->prepare('SELECT '.$summary.' '.$from.$where);$statement->execute($params);$totals=$statement->fetch(PDO::FETCH_ASSOC);
$statement=$database->prepare("SELECT a.provider_kind,a.provider_name,COALESCE(a.model,a.metadata->>'model','Not recorded') AS model,a.operation,".$summary.' '.$from.$where
    ." GROUP BY a.provider_kind,a.provider_name,COALESCE(a.model,a.metadata->>'model','Not recorded'),a.operation ORDER BY cost_usd DESC NULLS LAST,attempts DESC,a.provider_name LIMIT 100");
$statement->execute($params);$groups=$statement->fetchAll(PDO::FETCH_ASSOC);
if(($_GET['export']??'')==='1')lorkhan_control_export($groups,['provider_kind'=>'Type','provider_name'=>'Provider','model'=>'Model','operation'=>'Request Type','attempts'=>'Attempts','priced'=>'Attempts With Recorded Cost','cost_usd'=>'Recorded USD Cost','prompt_tokens'=>'Input Tokens','completion_tokens'=>'Output Tokens','total_tokens'=>'Total Tokens'],'lorkhan-cost-breakdown.csv');
$statement=$database->prepare('SELECT a.operation,sum('.$usage['cost_usd'].') AS cost_usd '.$from.$where.' GROUP BY a.operation ORDER BY cost_usd DESC NULLS LAST,a.operation');
$statement->execute($params);$chartRows=$statement->fetchAll(PDO::FETCH_ASSOC);
$additionalStylesheets=['herika-cost-breakdown.css?v='.(string)filemtime(__DIR__.'/css/herika-cost-breakdown.css')];
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
include __DIR__.'/tmpl/provider_usage.html.php';
include __DIR__.'/tmpl/footer.html';
