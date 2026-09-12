<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use PDO;

final class ManagementUiRepository
{
    public function __construct(private readonly PDO $db) {}

    public function dynamicOghmaCatalog(string $installation,array $filters=[]):array{return(new DynamicOghmaRepository($this->db))->catalog($installation,$filters);}

    /** Return the effective Oghma catalog with bounded server-side search, category, order, and pagination. */
    public function oghmaCatalog(array $filters=[]):array
    {
        $search=mb_strcut(trim((string)($filters['search']??'')),0,100,'UTF-8');$category=trim((string)($filters['category']??''));
        $order=strtolower((string)($filters['order']??'asc'))==='desc'?'DESC':'ASC';$page=max(1,(int)($filters['page']??1));$pageSize=max(1,min(500,(int)($filters['page_size']??50)));
        $scope=['d.deleted_at IS NULL','d.profile_id IS NULL','d.playthrough_id IS NULL'];$where=[];$params=[];$installation=trim((string)($filters['installation_id']??''));
        if($installation!==''){$scope[]='d.installation_id=:installation';$params['installation']=$installation;}
        // The editor searches topic names and aliases by substring; runtime knowledge retrieval is separate.
        if($search!==''){$where[]="(d.topic ILIKE :topic_search OR COALESCE(d.aliases,'') ILIKE :alias_search)";$params['topic_search']=$params['alias_search']='%'.$search.'%';}
        if($category!==''){$where[]='d.category=:category';$params['category']=$category;}
        $columns='d.document_id,d.installation_id,d.profile_id,d.playthrough_id,d.topic,d.title,d.aliases,d.content,d.knowledge_class,d.topic_desc_basic,d.knowledge_class_basic,d.tags,d.category,d.provenance,d.created_at';
        // Custom articles override the factory catalog for the same canonical topic, newest custom row first,
        // so soft-deleting the override reveals the factory article again. Search and category run on the
        // resolved rows so an overridden factory article never resurfaces through a filter.
        $effective='WITH effective AS (SELECT DISTINCT ON (d.installation_id,lower(d.topic)) '.$columns
            .' FROM knowledge_documents d WHERE '.implode(' AND ',$scope)
            ." ORDER BY d.installation_id,lower(d.topic),(d.provenance->>'source' IS DISTINCT FROM 'factory-oghma') DESC,d.created_at DESC,d.document_id DESC) ";
        $base=' FROM effective d'.($where===[]?'':' WHERE '.implode(' AND ',$where));$count=$this->db->prepare($effective.'SELECT count(*)'.$base);$count->execute($params);$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/$pageSize));$page=min($page,$pages);
        $statement=$this->db->prepare($effective.'SELECT '.$columns.$base.' ORDER BY lower(d.topic) '.$order.',d.document_id LIMIT '.$pageSize.' OFFSET '.(($page-1)*$pageSize));$statement->execute($params);$rows=$statement->fetchAll();
        foreach($rows as&$row)$row['provenance']=json_decode((string)$row['provenance'],true,16,JSON_THROW_ON_ERROR);unset($row);
        return['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'page_size'=>$pageSize];
    }

    public function oghmaCategories():array{return$this->db->query("SELECT DISTINCT category FROM knowledge_documents WHERE deleted_at IS NULL AND category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);}

    public function oghmaCatalogStatus():?array{$row=$this->db->query("SELECT catalog_version,row_count,articles_sha256,activated_at FROM oghma_catalogs WHERE state='active'")->fetch();return$row===false?null:$row;}

    /** Return filterable Oghma retrieval decisions with extracted-topic and forced-context metadata. */
    public function oghmaAudit(array $filters=[]):array
    {
        $installation=trim((string)($filters['installation_id']??''));$search=mb_strcut(trim((string)($filters['search']??'')),0,100,'UTF-8');
        $matched=strtolower(trim((string)($filters['matched']??'all')));if(!in_array($matched,['all','matched','unmatched'],true))$matched='all';
        $extractor=strtolower(trim((string)($filters['extractor']??'all')));if(!in_array($extractor,[
            'all','grounded','no_match','fallback_succeeded','fallback_unresolved','fallback_failed',
            'fallback_disabled','fallback_unconfigured','disabled','ineligible','unavailable','not_run','legacy',
        ],true))$extractor='all';
        $page=max(1,(int)($filters['page']??1));$pageSize=max(25,min(100,(int)($filters['page_size']??50)));
        $where=["r.domain='knowledge'"];$params=[];
        if($installation!==''){$where[]='r.installation_id=:installation';$params['installation']=$installation;}
        if($search!==''){$where[]="(r.query ILIKE '%'||:search||'%' OR COALESCE(t.input_text,'') ILIKE '%'||:search||'%' OR COALESCE(t.target->>'display_name',p.name,'') ILIKE '%'||:search||'%' OR r.reasons::text ILIKE '%'||:search||'%')";$params['search']=$search;}
        if($matched==='matched')$where[]='cardinality(r.result_ids)>0';elseif($matched==='unmatched')$where[]='cardinality(r.result_ids)=0';
        if($extractor!=='all'){$where[]="COALESCE(r.reasons->'_context'->>'extractor_status','legacy')=:extractor";$params['extractor']=$extractor;}
        $from=' FROM retrieval_traces r LEFT JOIN profiles p ON p.profile_id=r.profile_id LEFT JOIN turns t ON t.turn_id=r.turn_id WHERE '.implode(' AND ',$where);
        $count=$this->db->prepare('SELECT count(*)'.$from);$count->execute($params);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$pageSize));$page=min($page,$pages);
        $sql="SELECT r.retrieval_trace_id,r.installation_id,r.profile_id,COALESCE(t.target->>'display_name',p.name) AS profile_name,t.input_kind AS input_kind,t.input_text AS input_text,r.playthrough_id,r.turn_id,r.query,cardinality(r.result_ids) AS result_count,to_json(r.result_ids) AS result_ids,r.scores,r.reasons,r.algorithm,r.created_at,(SELECT jsonb_agg(jsonb_build_object('id',d.document_id,'topic',d.topic,'category',d.category) ORDER BY d.topic) FROM knowledge_documents d WHERE d.document_id=ANY(r.result_ids)) AS selected_topics".$from.' ORDER BY r.created_at DESC,r.retrieval_trace_id DESC LIMIT :limit OFFSET :offset';
        $statement=$this->db->prepare($sql);foreach($params as$key=>$value)$statement->bindValue($key,$value);$statement->bindValue('limit',$pageSize,PDO::PARAM_INT);$statement->bindValue('offset',($page-1)*$pageSize,PDO::PARAM_INT);$statement->execute();
        $rows=array_map(fn(array$row):array=>$this->redactRow($row),$statement->fetchAll());
        return['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'page_size'=>$pageSize,
            'filters'=>['installation_id'=>$installation,'search'=>$search,'matched'=>$matched,'extractor'=>$extractor]];
    }

    /** Query the effective default/custom catalog with server-side filters and bounded pagination. */
    public function descriptionCatalog(string $installationId,array $filters=[]): array
    {
        $search=trim((string)($filters['search']??''));if(strlen($search)>100)$search=substr($search,0,100);
        $letter=strtoupper(trim((string)($filters['letter']??'')));if(preg_match('/^[A-Z]$/D',$letter)!==1)$letter='';
        $source=strtolower(trim((string)($filters['source']??'all')));if(!in_array($source,['all','default','custom','missing'],true))$source='all';
        $plugin=strtolower(trim((string)($filters['plugin']??'all')));if(!in_array($plugin,['all','active','inactive'],true))$plugin='all';
        $page=max(1,(int)($filters['page']??1));$pageSize=50;
        $cte=<<<'SQL'
WITH parameters AS (
    SELECT CAST(:installation AS uuid) AS installation_id,CAST(:search AS text) AS search,
        CAST(:letter AS text) AS letter,CAST(:source AS text) AS source,CAST(:plugin AS text) AS plugin
), custom AS (
    SELECT description_id::text,content_file,record_id,display_name,description,'custom'::text AS source,updated_at
    FROM item_descriptions,parameters WHERE item_descriptions.installation_id=parameters.installation_id AND deleted_at IS NULL
), effective AS (
    SELECT * FROM custom
    UNION ALL
    SELECT NULL::text,d.plugin,d.baseid,d.name,d.description,'default',NULL::timestamptz
    FROM descriptions d WHERE NOT EXISTS (
        SELECT 1 FROM custom c WHERE lower(c.content_file)=lower(d.plugin) AND lower(c.record_id)=lower(d.baseid)
    )
), catalog AS (
    SELECT e.description_id,e.content_file,e.record_id,e.display_name,e.description,e.source,e.updated_at,
        discovered.last_seen_at,manifest.active,manifest.load_order
    FROM effective e
    LEFT JOIN discovered_items discovered ON discovered.installation_id=(SELECT installation_id FROM parameters)
        AND discovered.content_file=lower(e.content_file) AND discovered.record_id=lower(e.record_id)
    LEFT JOIN content_manifest_files manifest ON manifest.installation_id=(SELECT installation_id FROM parameters)
        AND manifest.content_file=lower(e.content_file)
    UNION ALL
    SELECT NULL,item.content_file,item.record_id,item.display_name,NULL,'missing',NULL,item.last_seen_at,
        manifest.active,manifest.load_order
    FROM discovered_items item
    LEFT JOIN content_manifest_files manifest ON manifest.installation_id=item.installation_id
        AND manifest.content_file=item.content_file
    WHERE item.installation_id=(SELECT installation_id FROM parameters) AND NOT EXISTS (
        SELECT 1 FROM effective e WHERE lower(e.content_file)=item.content_file AND lower(e.record_id)=item.record_id
    )
)
SQL;
        $where=['(parameters.search = \'\' OR lower(COALESCE(catalog.display_name,\'\')||\' \'||catalog.content_file||\' \'||catalog.record_id) LIKE \'%\'||lower(parameters.search)||\'%\')',
            '(parameters.letter = \'\' OR upper(left(COALESCE(catalog.display_name,catalog.record_id),1))=parameters.letter)',
            '(parameters.source = \'all\' OR catalog.source=parameters.source)',
            "(parameters.plugin = 'all' OR (parameters.plugin = 'active' AND catalog.active IS TRUE) OR (parameters.plugin = 'inactive' AND catalog.active IS FALSE))"];
        $parameters=['installation'=>$installationId,'search'=>$search,'letter'=>$letter,'source'=>$source,'plugin'=>$plugin];
        $filter=' WHERE '.implode(' AND ',$where);
        $count=$this->db->prepare($cte.' SELECT count(*) FROM catalog CROSS JOIN parameters'.$filter);$count->execute($parameters);$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/$pageSize));$page=min($page,$pages);
        $statement=$this->db->prepare($cte.' SELECT catalog.* FROM catalog CROSS JOIN parameters'.$filter
            .' ORDER BY lower(COALESCE(display_name,record_id)),lower(content_file),lower(record_id) LIMIT :limit OFFSET :offset');
        foreach($parameters as$key=>$value)$statement->bindValue($key,$value);$statement->bindValue('limit',$pageSize,PDO::PARAM_INT);
        $statement->bindValue('offset',($page-1)*$pageSize,PDO::PARAM_INT);$statement->execute();
        return['items'=>$statement->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>$pages,'page_size'=>$pageSize,
            'filters'=>['search'=>$search,'letter'=>$letter,'source'=>$source,'plugin'=>$plugin]];
    }

    /** Summarize effective descriptions and discovered gaps for the selected installation. */
    public function descriptionSummary(string $installationId): array
    {
        $statement=$this->db->prepare(<<<'SQL'
WITH parameters AS (SELECT CAST(:installation AS uuid) AS installation_id), custom AS (
    SELECT content_file,record_id FROM item_descriptions,parameters WHERE item_descriptions.installation_id=parameters.installation_id AND deleted_at IS NULL
), defaults AS (
    SELECT d.plugin,d.baseid FROM descriptions d WHERE NOT EXISTS (
        SELECT 1 FROM custom c WHERE lower(c.content_file)=lower(d.plugin) AND lower(c.record_id)=lower(d.baseid)
    )
)
SELECT (SELECT count(*) FROM custom)::int AS custom_count,(SELECT count(*) FROM defaults)::int AS default_count,
    (SELECT count(*) FROM discovered_items item WHERE item.installation_id=(SELECT installation_id FROM parameters) AND NOT EXISTS (
        SELECT 1 FROM custom c WHERE lower(c.content_file)=item.content_file AND lower(c.record_id)=item.record_id
    ) AND NOT EXISTS (
        SELECT 1 FROM descriptions d WHERE lower(d.plugin)=item.content_file AND lower(d.baseid)=item.record_id
    ))::int AS missing_count
SQL);
        $statement->execute(['installation'=>$installationId]);return$statement->fetch()?:['custom_count'=>0,'default_count'=>0,'missing_count'=>0];
    }

    /** Return recently discovered canonical items with their effective description source. */
    public function discoveredDescriptionItems(string $installationId): array
    {
        $statement=$this->db->prepare(<<<'SQL'
SELECT item.content_file,item.record_id,item.display_name,item.observed_sources,item.last_seen_at,manifest.active,
    CASE WHEN custom.description_id IS NOT NULL THEN 'Custom'
         WHEN defaults.baseid IS NOT NULL THEN 'Default' ELSE 'Missing' END AS description_source
FROM discovered_items item
LEFT JOIN content_manifest_files manifest ON manifest.installation_id=item.installation_id AND manifest.content_file=item.content_file
LEFT JOIN item_descriptions custom ON custom.installation_id=item.installation_id
    AND lower(custom.content_file)=item.content_file AND lower(custom.record_id)=item.record_id AND custom.deleted_at IS NULL
LEFT JOIN descriptions defaults ON lower(defaults.plugin)=item.content_file AND lower(defaults.baseid)=item.record_id
WHERE item.installation_id=:installation ORDER BY item.last_seen_at DESC,item.content_file,item.record_id LIMIT 100
SQL);
        $statement->execute(['installation'=>$installationId]);return$statement->fetchAll();
    }

    /** Shared live player/calendar selection for the dashboard and full database snapshots. */
    public function currentDatabase(): ?array
    {
        return $this->one(
            "SELECT s.installation_id,s.playthrough_id,s.state,s.created_at,s.openmw_version,s.lua_api_revision,s.client_version,s.platform,"
            . "p.name AS profile_name,pt.name AS playthrough_name,latest.player_name,latest.dialogue_mode,"
            . "CASE WHEN loaded.received_at IS NOT NULL AND (latest.accepted_at IS NULL OR loaded.received_at>=latest.accepted_at) THEN loaded.calendar ELSE latest.calendar_data END AS calendar_data,"
            . "latest.player_stats,latest.player_attributes,latest.player_skills,latest.accepted_at AS observed_at,"
            . "GREATEST(s.created_at,latest.accepted_at) AS last_played,COALESCE(preference.llm_model_slot,'standard') AS model_slot "
            . "FROM sessions s LEFT JOIN profiles p ON p.profile_id=s.profile_id "
            . "LEFT JOIN playthroughs pt ON pt.playthrough_id=s.playthrough_id "
            . "LEFT JOIN installation_profile_preferences preference ON preference.installation_id=s.installation_id "
            . "LEFT JOIN LATERAL (SELECT t.accepted_at,COALESCE(t.context#>>'{player,display_name}',"
            . "CASE WHEN t.speaker->>'kind'='player' THEN t.speaker->>'display_name' END) AS player_name,"
            . "t.context->>'dialogueMode' AS dialogue_mode,t.context#>'{world,calendar}' AS calendar_data,"
            . "t.context#>'{playerState,stats}' AS player_stats,t.context#>'{playerState,attributes}' AS player_attributes,"
            . "t.context#>'{playerState,skills}' AS player_skills FROM turns t JOIN sessions history ON history.session_id=t.session_id "
            . "WHERE history.installation_id=s.installation_id AND history.playthrough_id=s.playthrough_id "
            . "ORDER BY t.accepted_at DESC,t.turn_id DESC LIMIT 1) latest ON true "
            . "LEFT JOIN LATERAL (SELECT e.payload->'loaded_save' AS calendar,e.received_at FROM source_events e JOIN sessions history ON history.session_id=e.session_id "
            . "WHERE history.installation_id=s.installation_id AND history.playthrough_id=s.playthrough_id AND history.profile_id=s.profile_id "
            . "AND e.event_kind='session.init' AND jsonb_exists(e.payload,'loaded_save') ORDER BY e.received_at DESC,e.source_event_id DESC LIMIT 1) loaded ON true "
            . "ORDER BY (s.state='active') DESC,s.created_at DESC LIMIT 1"
        );

    }

    /** Load the bounded datasets used by the server-style home dashboard. */
    public function dashboard(): array
    {
        $current=$this->currentDatabase();
        $scope = ['installation' => $current['installation_id'] ?? null, 'playthrough' => $current['playthrough_id'] ?? null];
        $eventScope = 'm.installation_id=:installation AND m.playthrough_id=:playthrough AND m.suppressed_at IS NULL';
        $dialogue = $this->all(
            "SELECT e.data AS text,to_timestamp(e.localts) AS emitted_at,"
            . "COALESCE(m.payload#>'{context,world,calendar}',m.payload->'calendar',t.context#>'{world,calendar}') AS calendar_data "
            . "FROM public.eventlog e JOIN lorkhan_internal.eventlog_metadata m ON m.rowid=e.rowid "
            . "LEFT JOIN turns t ON t.turn_id=m.turn_id WHERE ".$eventScope." AND e.type IN ('chat','inputtext') "
            . "ORDER BY e.localts DESC,e.rowid DESC LIMIT 5", $scope
        );
        // Match Herika's chat-only vocabulary window, removing speaker names and parenthetical context.
        $chat = $this->all("SELECT e.data FROM public.eventlog e JOIN lorkhan_internal.eventlog_metadata m ON m.rowid=e.rowid "
            . "WHERE ".$eventScope." AND e.type='chat' ORDER BY e.localts DESC,e.rowid DESC LIMIT 10000", $scope);
        $stopWords = array_fill_keys(explode(' ', 'the be to of and a in that have i it for not on with he as you do at this but his by from they we say her she or an will my one all would there their what so up out if about who get which go me when make can like time no just him know take people into year your good some could them see other than then now look only come its over think also back after use two how our work first well way even new want because any these give day most us im ive are was been had has yes ok okay oh ah hmm uh er um whats thats youre dont cant wont shouldnt couldnt wouldnt lets theres heres wheres whos nobodys everybodys talking talk said says tell told went gone coming going doing done being having getting putting taking making finding found made put took got goes came'), true);
        $frequencies = [];
        foreach ($chat as $row) {
            $text = preg_replace('/\([^)]*\)/u', '', (string) $row['data']) ?? '';
            $text = preg_replace('/^[^:]*:/u', '', $text) ?? '';
            $text = preg_replace("/[^\\w\\s']/u", '', mb_strtolower($text)) ?? '';
            $text = preg_replace("/\\s'|'(\\s|$)|('+)/u", ' ', $text) ?? '';
            foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (mb_strlen($word) > 2 && !isset($stopWords[$word])) $frequencies[$word] = ($frequencies[$word] ?? 0) + 1;
            }
        }
        arsort($frequencies);
        $words = [];
        foreach (array_slice($frequencies, 0, 100, true) as $word => $count) {
            $words[] = ['text' => (string) $word, 'count' => $count, 'size' => log($count * 5) * 8 + 20];
        }

        return [
            'database_version' => (string) $this->db->query('SHOW server_version')->fetchColumn(),
            'current' => $current,
            'dialogue' => $dialogue,
            'statistics' => $this->dashboardStatistics($scope),
            'latest_diary' => $this->all(
                "SELECT n.narrative_id,n.title,n.content,n.created_at,p.name AS author FROM narrative_records n "
                . "JOIN profiles p ON p.profile_id=n.profile_id AND p.installation_id=n.installation_id "
                . "WHERE n.installation_id=:installation AND n.playthrough_id=:playthrough AND n.deleted_at IS NULL AND n.kind='diary' "
                . "ORDER BY n.created_at DESC,n.narrative_id DESC LIMIT 1", $scope
            )[0] ?? null,
            'relationships' => $this->all(
                "SELECT p.name AS owner,COALESCE(r.actor_identity->>'display_name',r.actor_identity->>'record_id','Unknown') AS target,"
                . "a.before_value,a.after_value,a.reason,a.created_at FROM relationship_audit a "
                . "JOIN relationship_records r ON r.relationship_id=a.relationship_id JOIN profiles p ON p.profile_id=r.profile_id "
                . "WHERE r.installation_id=:installation AND r.playthrough_id=:playthrough AND r.deleted_at IS NULL "
                . "ORDER BY a.created_at DESC,a.audit_sequence DESC LIMIT 5", $scope
            ),
            'words' => $words,
        ];
    }

    /** Build the reference dashboard counts and drilldowns from scoped product records. */
    private function dashboardStatistics(array $scope): array
    {
        $events = $this->all("SELECT e.type,count(*)::int AS count FROM public.eventlog e "
            . "JOIN lorkhan_internal.eventlog_metadata m ON m.rowid=e.rowid WHERE m.installation_id=:installation "
            . "AND m.playthrough_id=:playthrough AND m.suppressed_at IS NULL GROUP BY e.type ORDER BY count(*) DESC,e.type", $scope);
        $eventCounts = array_column($events, 'count', 'type');
        $counts = $this->all("SELECT "
            . "(SELECT count(*) FROM public.oghma o JOIN lorkhan_internal.oghma_metadata m ON m.topic=o.topic "
            . "WHERE m.installation_id=:installation AND (m.playthrough_id IS NULL OR m.playthrough_id=:playthrough)) AS knowledge,"
            . "(SELECT count(*) FROM public.memory_summary summary JOIN lorkhan_internal.memory_summary_metadata m ON m.rowid=summary.rowid "
            . "JOIN memory_records record ON record.memory_id=m.memory_id WHERE record.installation_id=:installation "
            . "AND record.playthrough_id=:playthrough AND record.deleted_at IS NULL) AS memories,"
            . "(SELECT count(*) FROM narrative_records n WHERE n.installation_id=:installation AND n.playthrough_id=:playthrough "
            . "AND n.kind='diary' AND n.deleted_at IS NULL) AS diaries,"
            . "(SELECT count(DISTINCT b.title) FROM public.books b JOIN lorkhan_internal.book_metadata m ON m.rowid=b.rowid "
            . "WHERE m.installation_id=:installation AND m.playthrough_id=:playthrough AND b.content IS NOT NULL) AS books", $scope)[0];
        $periods = [];
        foreach (['24h' => 24, '72h' => 72, '1w' => 168, 'lifetime' => null] as $period => $hours) {
            // LLM attempts include background jobs, but never count STT/TTS as language requests.
            $periods[$period] = $this->all("SELECT count(*)::int AS total,count(*) FILTER(WHERE a.state='succeeded')::int AS success "
                . "FROM provider_attempts a LEFT JOIN turns t ON t.turn_id=a.turn_id LEFT JOIN sessions s ON s.session_id=t.session_id "
                . "LEFT JOIN durable_jobs j ON j.job_id=a.job_id WHERE a.provider_kind='llm' "
                . "AND COALESCE(s.installation_id::text,j.payload->>'installation_id')=:installation "
                . "AND COALESCE(s.playthrough_id::text,j.payload->>'playthrough_id')=:playthrough"
                . ($hours === null ? '' : " AND a.started_at>=CURRENT_TIMESTAMP-INTERVAL '".$hours." hours'"), $scope)[0];
        }
        $locations = $this->all("SELECT l.name,m.cell_key,l.region FROM public.locations l "
            . "JOIN lorkhan_internal.location_metadata m ON m.formid=l.formid WHERE m.installation_id=:installation "
            . "ORDER BY lower(l.name),m.cell_key", ['installation' => $scope['installation']]);
        $mods = $this->all("SELECT content_file,load_order FROM lorkhan_internal.content_manifest_files "
            . "WHERE installation_id=:installation AND active ORDER BY load_order", ['installation' => $scope['installation']]);
        return ['counts' => ['Total Events' => array_sum($eventCounts), 'Oghma Entries' => (int) $counts['knowledge'],
            'Memory Summaries' => (int) $counts['memories'], 'Diary Entries' => (int) $counts['diaries'],
            'Entity Deaths' => (int) ($eventCounts['death'] ?? 0), 'Items Found' => (int) ($eventCounts['itemfound'] ?? 0),
            'Books Read' => (int) $counts['books'], 'Player Messages' => (int) ($eventCounts['inputtext'] ?? 0)],
            'events' => $events, 'llm' => $periods, 'locations' => $locations, 'mods' => $mods];
    }

    /** Load the inspection tabs rendered directly inside the Roleplay PHP page. */
    public function roleplay(array $memoryScope=[]): array
    {
        return [
            'events' => $this->rows('events'),
            'responses' => $this->rows('responses'),
            'memories' => $this->rows('memories',null,$memoryScope),
            'relationships' => $this->rows('relationships'),
            'narratives' => $this->rows('narratives'),
            'knowledge' => $this->rows('knowledge'),
            'journal' => $this->rows('journal'),
            'books' => $this->rows('books'),
        ];
    }

    /** Return one of the allowlisted bounded datasets used by embedded management pages. */
    public function rows(string $view,?string $relationshipInstallationId=null,array $memoryScope=[]): array
    {
        $relationshipScoped=in_array($view,['relationships','relationship_logs','relationship_profiles'],true)&&$relationshipInstallationId!==null;
        $relationshipFilter=$relationshipScoped?' AND r.installation_id=:relationship_installation':'';
        $relationshipParams=$relationshipScoped?['relationship_installation'=>$relationshipInstallationId]:[];
        if($relationshipScoped&&in_array($view,['relationships','relationship_logs'],true))foreach(['profile_id','playthrough_id']as$key){
            if(!isset($memoryScope[$key]))continue;
            if(!is_string($memoryScope[$key])||!Uuid::isValid($memoryScope[$key]))throw new \InvalidArgumentException('invalid_relationship_scope');
            $relationshipFilter.=' AND r.'.$key.'=:relationship_'.$key;
            $relationshipParams['relationship_'.$key]=$memoryScope[$key];
        }
        // Review may need exact generated targets beyond the initial 100-row editor window.
        if($relationshipScoped&&$view==='relationships'&&isset($memoryScope['relationship_ids'])){
            $ids=$memoryScope['relationship_ids'];
            if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>20)throw new \InvalidArgumentException('invalid_relationship_scope');
            $placeholders=[];
            foreach($ids as $index=>$id){
                if(!is_string($id)||!Uuid::isValid($id))throw new \InvalidArgumentException('invalid_relationship_scope');
                $key='review_relationship_'.$index;$placeholders[]=':'.$key;$relationshipParams[$key]=$id;
            }
            $relationshipFilter.=' AND r.relationship_id IN ('.implode(',',$placeholders).')';
        }
        $memoryScoped=$view==='memories'&&isset($memoryScope['installation_id'],$memoryScope['playthrough_id']);
        $playthroughScoped=$view==='playthroughs'&&isset($memoryScope['installation_id']);
        $playthroughPage=$playthroughScoped&&isset($memoryScope['page']);
        $playthroughSelected=$playthroughScoped&&isset($memoryScope['selected_id'])&&Uuid::isValid((string)$memoryScope['selected_id']);
        $sql = match ($view) {
            'events' => "SELECT e.type,'chim-roleplay-event.v1' AS schema,m.request_id,m.turn_id,m.created_at AS occurred_at FROM public.eventlog e JOIN lorkhan_internal.eventlog_metadata m ON m.rowid=e.rowid WHERE m.suppressed_at IS NULL ORDER BY e.rowid DESC LIMIT 100",
            'request_logs' => "SELECT trace.prompt_trace_id,trace.request_id,trace.turn_id,trace.algorithm,trace.input_bytes,trace.truncated,"
                . "count(section.section_order)::int AS section_count,jsonb_object_agg(section.section_key,section.inclusion_reason ORDER BY section.section_order) AS sections,"
                . "trace.input_sha256,trace.created_at FROM prompt_traces trace JOIN prompt_trace_sections section ON section.prompt_trace_id=trace.prompt_trace_id "
                . "GROUP BY trace.prompt_trace_id ORDER BY trace.created_at DESC LIMIT 100",
            'responses' => "SELECT COALESCE(s.speaker,'Unknown') AS speaker,s.speech AS text,m.delivery_state,m.created_at AS emitted_at FROM public.speech s LEFT JOIN lorkhan_internal.speech_metadata m ON m.rowid=s.rowid ORDER BY s.rowid DESC LIMIT 100",
            'memory_policy' => "SELECT c.installation_id,c.configuration_id,c.current_revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.kind='memory_policy' AND c.deleted_at IS NULL ORDER BY c.installation_id LIMIT 100",
            'memory_embedding_policy' => "SELECT c.installation_id,c.configuration_id,c.current_revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.kind='memory_embedding_policy' AND c.deleted_at IS NULL ORDER BY c.installation_id LIMIT 100",
            'memories' => "SELECT metadata.memory_id,metadata.installation_id,metadata.profile_id,metadata.playthrough_id,metadata.tier,m.message AS content,"
                . "generated.content AS summary_content,COALESCE(event.payload#>'{context,world,calendar}',memory_turn.context#>'{world,calendar}',summary_calendar.calendar_data) AS calendar_data,"
                . "(source.derivation_key IS NOT NULL AND source.tier IN ('mid','long') AND source.deleted_at IS NULL "
                . "AND (source.expires_at IS NULL OR source.expires_at>clock_timestamp()) "
                . "AND source.provenance->>'source'='memory.consolidate' AND source.provenance->>'provider'='first-party' "
                . "AND source.provenance->>'model'='deterministic-extractive-v1') AS summarizable,"
                . "(SELECT r.content->'enabled'='true'::jsonb FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision "
                . "WHERE c.installation_id=metadata.installation_id AND c.kind='memory_policy' AND c.deleted_at IS NULL) AS summary_policy_enabled,"
                . "CASE WHEN EXISTS(SELECT 1 FROM memory_model_summaries s WHERE s.memory_id=source.memory_id AND s.memory_revision=source.current_revision) THEN 'summarized' "
                . "WHEN EXISTS(SELECT 1 FROM durable_jobs j WHERE j.job_type='memory.summarize' AND j.state IN ('queued','leased') AND j.payload->>'memory_id'=source.memory_id::text AND j.payload->>'memory_revision'=source.current_revision::text) THEN 'summary queued' ELSE '' END AS summary_state,"
                . "jsonb_build_object('speaker',m.speaker,'listener',m.listener,'event',m.event,'momentum',m.momentum) AS provenance,"
                . "source.current_revision,source.source_event_id,CASE WHEN source.source_event_id IS NULL THEN 'authored' "
                . "WHEN delivery.status='played' THEN 'played dialogue' ELSE COALESCE(event.event_kind,'derived') END AS eligibility,"
                . "(SELECT jsonb_agg(jsonb_build_object('revision',revision.revision,'reason',revision.change_reason,'created_at',revision.created_at) ORDER BY revision.revision DESC) "
                . "FROM memory_record_revisions revision WHERE revision.memory_id=source.memory_id) AS revisions,"
                . "to_timestamp(m.localts) AS occurred_at,source.updated_at FROM public.memory m "
                . "JOIN lorkhan_internal.memory_metadata metadata ON metadata.rowid=m.rowid "
                . "LEFT JOIN lorkhan_internal.memory_records source ON source.memory_id=metadata.memory_id "
                . "LEFT JOIN lorkhan_internal.memory_model_summaries generated ON generated.memory_id=source.memory_id AND generated.memory_revision=source.current_revision "
                . "LEFT JOIN lorkhan_internal.source_events event ON event.source_event_id=source.source_event_id "
                . "LEFT JOIN lorkhan_internal.turns memory_turn ON memory_turn.turn_id=event.turn_id "
                . "LEFT JOIN LATERAL (SELECT COALESCE(e.payload#>'{context,world,calendar}',t.context#>'{world,calendar}') AS calendar_data "
                . "FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(source.provenance->'source_event_ids')='array' "
                . "THEN source.provenance->'source_event_ids' ELSE '[]'::jsonb END) refs(id) "
                . "JOIN lorkhan_internal.source_events e ON e.source_event_id=CASE WHEN refs.id ~ '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$' THEN refs.id::uuid END "
                . "JOIN lorkhan_internal.sessions calendar_session ON calendar_session.session_id=e.session_id "
                . "LEFT JOIN lorkhan_internal.turns t ON t.turn_id=e.turn_id "
                . "WHERE e.installation_id=source.installation_id AND calendar_session.playthrough_id=source.playthrough_id "
                . "AND COALESCE(e.payload#>'{context,world,calendar}',t.context#>'{world,calendar}') IS NOT NULL "
                . "ORDER BY e.occurred_at DESC,e.source_event_id LIMIT 1) summary_calendar ON true "
                . "LEFT JOIN lorkhan_internal.dialogue_delivery_results delivery ON delivery.source_event_id=source.source_event_id "
                . "WHERE source.tier IN ('mid','long') AND source.deleted_at IS NULL "
                . ($memoryScoped?'AND metadata.installation_id=:memory_installation AND metadata.playthrough_id=:memory_playthrough ':'')
                . "ORDER BY m.localts DESC,m.rowid DESC LIMIT 100",
            'relationships' => "SELECT r.relationship_id,r.installation_id,r.profile_id,r.playthrough_id,r.actor_identity,"
                . "COALESCE(r.actor_identity->>'display_name',r.actor_identity->>'record_id','Unknown actor') AS actor,"
                . "p.name AS owner,t.name AS playthrough,r.disposition,r.affinity,r.relationship_type,r.custom_info,r.details,r.source_mode,r.revision,r.updated_at, "
                . "positive.delta AS strongest_positive_delta,positive.reason AS strongest_positive_reason,positive.created_at AS strongest_positive_at, "
                . "negative.delta AS strongest_negative_delta,negative.reason AS strongest_negative_reason,negative.created_at AS strongest_negative_at "
                . "FROM relationship_records r JOIN profiles p ON p.profile_id=r.profile_id JOIN playthroughs t ON t.playthrough_id=r.playthrough_id "
                . "LEFT JOIN LATERAL (SELECT (a.after_value->>'affinity')::int-COALESCE((a.before_value->>'affinity')::int,0) AS delta,a.reason,a.created_at "
                . "FROM relationship_audit a WHERE a.relationship_id=r.relationship_id AND a.mode='derived' AND jsonb_typeof(a.after_value->'affinity')='number' "
                . "AND (a.after_value->>'affinity')::int-COALESCE((a.before_value->>'affinity')::int,0)>0 ORDER BY delta DESC,a.audit_sequence DESC LIMIT 1) positive ON true "
                . "LEFT JOIN LATERAL (SELECT (a.after_value->>'affinity')::int-COALESCE((a.before_value->>'affinity')::int,0) AS delta,a.reason,a.created_at "
                . "FROM relationship_audit a WHERE a.relationship_id=r.relationship_id AND a.mode='derived' AND jsonb_typeof(a.after_value->'affinity')='number' "
                . "AND (a.after_value->>'affinity')::int-COALESCE((a.before_value->>'affinity')::int,0)<0 ORDER BY delta,a.audit_sequence DESC LIMIT 1) negative ON true "
                . "WHERE r.deleted_at IS NULL".$relationshipFilter." ORDER BY r.updated_at DESC,r.relationship_id LIMIT 100",
            'relationship_logs' => "SELECT a.audit_id,a.relationship_id,r.installation_id,r.profile_id,r.playthrough_id,"
                . "p.name AS owner,t.name AS playthrough,r.actor_identity,a.mode AS source_mode,a.before_value,a.after_value,a.reason,a.source_event_id,a.created_at "
                . "FROM relationship_audit a JOIN relationship_records r ON r.relationship_id=a.relationship_id "
                . "JOIN profiles p ON p.profile_id=r.profile_id JOIN playthroughs t ON t.playthrough_id=r.playthrough_id "
                . "WHERE true".$relationshipFilter." ORDER BY a.created_at DESC,a.audit_sequence DESC LIMIT 100",
            'relationship_profiles' => "SELECT r.profile_id,r.name,r.actor_identity FROM profiles r "
                . "WHERE r.deleted_at IS NULL".$relationshipFilter." ORDER BY r.name,r.profile_id LIMIT 500",
            'narratives' => "SELECT metadata.narrative_id,metadata.installation_id,metadata.profile_id,metadata.playthrough_id,d.tags AS kind,d.topic AS title,d.content,jsonb_build_object('location',d.location,'people',d.people,'tags',d.tags) AS provenance,to_timestamp(d.localts) AS created_at FROM public.diarylog d JOIN lorkhan_internal.diarylog_metadata metadata ON metadata.rowid=d.rowid ORDER BY d.localts DESC,d.rowid DESC LIMIT 100",
            'knowledge', 'worldknowledge' => "SELECT document_id,installation_id,profile_id,playthrough_id,topic,title,aliases,left(content,4000) AS content,knowledge_class,left(topic_desc_basic,4000) AS topic_desc_basic,knowledge_class_basic,tags,category,provenance,created_at FROM knowledge_documents WHERE deleted_at IS NULL ORDER BY lower(topic),document_id LIMIT 100",
            'journal' => "SELECT metadata.installation_id,session.profile_id,metadata.playthrough_id,q.id_quest AS quest_id,COALESCE(NULLIF(q.briefing2,''),NULLIF(q.briefing,''),q.data) AS journal_entry,NULL::text AS game_day,NULL::text AS game_month,NULL::text AS day_of_month,to_timestamp(q.localts) AS last_synced_at FROM public.questlog q JOIN lorkhan_internal.questlog_metadata metadata ON metadata.rowid=q.rowid LEFT JOIN lorkhan_internal.sessions session ON session.session_id=metadata.session_id ORDER BY q.localts DESC,q.rowid DESC LIMIT 100",
            'books' => "SELECT metadata.installation_id,session.profile_id,metadata.playthrough_id,b.title,metadata.record_id,b.content AS book_text,NULL::text AS is_scroll,NULL::text AS taught_skill,to_timestamp(b.localts) AS last_read_at FROM public.books b JOIN lorkhan_internal.book_metadata metadata ON metadata.rowid=b.rowid LEFT JOIN lorkhan_internal.sessions session ON session.session_id=metadata.session_id ORDER BY b.localts DESC,b.rowid DESC LIMIT 100",
            'descriptions' => "SELECT metadata.description_id,metadata.installation_id,d.plugin AS content_file,d.baseid AS record_id,d.name AS display_name,d.description,source.updated_at FROM public.combined_descriptions d JOIN lorkhan_internal.description_metadata metadata ON metadata.plugin=d.plugin AND metadata.baseid=d.baseid LEFT JOIN lorkhan_internal.item_descriptions source ON source.description_id=metadata.description_id ORDER BY lower(d.name),lower(d.plugin),lower(d.baseid) LIMIT 500",
            'discovered_items' => "SELECT item.installation_id,item.content_file,item.record_id,item.record_kind,item.display_name,item.reference_content_file,item.observed_sources,item.last_seen_at,item.observation_count,manifest.active,manifest.load_order,description.description_id FROM lorkhan_internal.discovered_items item LEFT JOIN lorkhan_internal.content_manifest_files manifest ON manifest.installation_id=item.installation_id AND manifest.content_file=item.content_file LEFT JOIN lorkhan_internal.item_descriptions description ON description.installation_id=item.installation_id AND lower(description.content_file)=item.content_file AND lower(description.record_id)=item.record_id AND description.deleted_at IS NULL ORDER BY item.last_seen_at DESC,item.content_file,item.record_id LIMIT 500",
            'characters' => "SELECT metadata.source_profile_id AS profile_id,metadata.installation_id,npc.npc_name AS name,metadata.source_revision AS current_revision,metadata.actor_identity,npc.extended_data->'lorkhan_profile' AS content,core_metadata.source_core_profile_id AS core_profile_id,core.label AS core_profile_label,(SELECT count(*)::int FROM lorkhan_internal.actor_profile_bindings binding WHERE binding.installation_id=metadata.installation_id AND binding.profile_id=metadata.source_profile_id) AS binding_count,current_revision.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM lorkhan_internal.profile_revisions history WHERE history.profile_id=metadata.source_profile_id) AS revisions FROM public.core_npc_master npc JOIN lorkhan_internal.npc_metadata metadata ON metadata.npc_id=npc.id LEFT JOIN public.core_profiles core ON core.id=npc.profile_id LEFT JOIN lorkhan_internal.core_profile_metadata core_metadata ON core_metadata.core_profile_id=core.id LEFT JOIN lorkhan_internal.profile_revisions current_revision ON current_revision.profile_id=metadata.source_profile_id AND current_revision.revision=metadata.source_revision ORDER BY npc.npc_name LIMIT 500",
            'core_profiles' => "SELECT metadata.source_core_profile_id AS core_profile_id,metadata.installation_id,profile.label,(profile.default_npc='1') AS default_npc,profile.slot,metadata.source_revision AS current_revision,profile.metadata AS content,current_revision.change_reason,current_revision.created_at,(SELECT count(*)::int FROM public.core_npc_master npc WHERE npc.profile_id=profile.id) AS profile_usage,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM lorkhan_internal.core_profile_revisions history WHERE history.core_profile_id=metadata.source_core_profile_id) AS revisions FROM public.core_profiles profile JOIN lorkhan_internal.core_profile_metadata metadata ON metadata.core_profile_id=profile.id LEFT JOIN lorkhan_internal.core_profile_revisions current_revision ON current_revision.core_profile_id=metadata.source_core_profile_id AND current_revision.revision=metadata.source_revision ORDER BY metadata.installation_id,(profile.default_npc='1') DESC,profile.slot NULLS LAST,lower(profile.label) LIMIT 100",
            'profiles' => "SELECT p.profile_id,p.installation_id,p.name,p.current_revision,p.actor_identity,r.content,(SELECT count(*)::int FROM actor_profile_bindings b WHERE b.installation_id=p.installation_id AND b.profile_id=p.profile_id) AS binding_count,r.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM profile_revisions history WHERE history.profile_id=p.profile_id) AS revisions FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('player','narrator') ORDER BY CASE WHEN p.actor_identity->>'kind'='template' THEN 0 ELSE 1 END,p.name LIMIT 500",
            'player' => "SELECT p.profile_id,p.installation_id,p.name,p.current_revision,p.actor_identity,r.content,"
                . "(SELECT count(*)::int FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE s.installation_id=p.installation_id AND t.speaker->>'kind'='player' AND btrim(t.input_text)<>'') AS input_count,r.created_at "
                . ",(SELECT jsonb_build_object('accepted_at',latest.accepted_at,'player',latest.context->'player','playerState',latest.context->'playerState','inventory',latest.context->'inventory','equipment',latest.context->'equipment','skills',latest.context->'skills','factions',latest.context->'factions','journal',latest.context->'journal') FROM turns latest JOIN sessions latest_session ON latest_session.session_id=latest.session_id WHERE latest_session.installation_id=p.installation_id ORDER BY latest.accepted_at DESC LIMIT 1) AS latest_context "
                . ",(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM profile_revisions history WHERE history.profile_id=p.profile_id) AS revisions "
                . "FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND p.actor_identity->>'kind'='player' ORDER BY p.created_at,p.profile_id LIMIT 100",
            'narrator' => "SELECT p.profile_id,p.installation_id,p.core_profile_id,p.name,p.current_revision,p.actor_identity,r.content,r.created_at "
                . ",(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM profile_revisions history WHERE history.profile_id=p.profile_id) AS revisions "
                . "FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND p.actor_identity->>'kind'='narrator' ORDER BY p.created_at,p.profile_id LIMIT 100",
            'observed_npcs' => "WITH recent_turns AS (SELECT s.installation_id,t.target,t.audience,t.context,t.accepted_at FROM turns t JOIN sessions s ON s.session_id=t.session_id ORDER BY t.accepted_at DESC LIMIT 100),"
                . "actors AS (SELECT installation_id,target AS actor,accepted_at FROM recent_turns UNION ALL SELECT r.installation_id,a.actor,r.accepted_at FROM recent_turns r CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(r.audience)='array' THEN r.audience ELSE '[]'::jsonb END) a(actor) UNION ALL SELECT r.installation_id,a.actor,r.accepted_at FROM recent_turns r CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(r.context->'nearbyActors'->'items')='array' THEN r.context->'nearbyActors'->'items' ELSE '[]'::jsonb END) a(actor)) "
                . "SELECT DISTINCT ON (a.installation_id,lower(a.actor->>'content_file'),lower(a.actor->>'record_id')) a.installation_id,a.actor->>'display_name' AS display_name,a.actor->>'record_id' AS record_id,a.actor->>'content_file' AS content_file,a.actor->'refnum' AS refnum,a.accepted_at AS last_seen_at,"
                . "(SELECT p.profile_id FROM profiles p WHERE p.installation_id=a.installation_id AND p.deleted_at IS NULL AND lower(COALESCE(p.actor_identity->>'record_id',''))=lower(a.actor->>'record_id') AND lower(COALESCE(p.actor_identity->>'content_file',''))=lower(a.actor->>'content_file') ORDER BY p.created_at LIMIT 1) AS profile_id "
                . "FROM actors a WHERE a.actor->>'kind'='npc' AND COALESCE(a.actor->>'record_id','')<>'' ORDER BY a.installation_id,lower(a.actor->>'content_file'),lower(a.actor->>'record_id'),a.accepted_at DESC LIMIT 100",
            'llm' => "SELECT metadata.configuration_id,metadata.installation_id,connector.label AS name,metadata.configuration_revision AS current_revision,connector.metadata AS content,0::int AS active_session_usage,(SELECT count(*)::int FROM lorkhan_internal.durable_jobs job WHERE job.job_type IN ('profile.generate','memory.summarize','relationship.evaluate','relationship.build','relationship.convert','narrative.generate') AND job.state IN ('queued','leased') AND job.payload->>'provider_configuration_id'=metadata.configuration_id::text) AS queued_job_usage,(SELECT count(*)::int FROM lorkhan_internal.configuration_sets c JOIN lorkhan_internal.configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.kind='memory_policy' AND c.deleted_at IS NULL AND r.content->>'provider_configuration_id'=metadata.configuration_id::text) AS memory_policy_usage,((SELECT count(*) FROM public.core_profiles profile WHERE connector.id IN (profile.llm_primary_id,profile.llm_secondary_id,profile.llm_tertiary_id,profile.llm_quaternary_id,profile.llm_formatter_id,profile.llm_fallback_id) OR profile.metadata#>>'{routing,oghma_configuration_id}'=metadata.configuration_id::text OR profile.metadata#>>'{routing,profile_generation_configuration_id}'=metadata.configuration_id::text OR profile.metadata#>>'{routing,relationship_configuration_id}'=metadata.configuration_id::text OR profile.metadata#>>'{routing,diary_generation_configuration_id}'=metadata.configuration_id::text OR profile.metadata#>>'{routing,player_autochat_configuration_id}'=metadata.configuration_id::text)+(SELECT count(*) FROM public.core_npc_master npc WHERE metadata.configuration_id::text IN (npc.extended_data#>>'{lorkhan_profile,routing,llm_configuration_id}',npc.extended_data#>>'{lorkhan_profile,routing,llm_fast_configuration_id}',npc.extended_data#>>'{lorkhan_profile,routing,llm_powerful_configuration_id}',npc.extended_data#>>'{lorkhan_profile,routing,llm_experimental_configuration_id}',npc.extended_data#>>'{lorkhan_profile,routing,llm_fallback_configuration_id}',npc.extended_data#>>'{lorkhan_profile,routing,oghma_configuration_id}',npc.extended_data#>>'{lorkhan_profile,routing,profile_generation_configuration_id}',npc.extended_data#>>'{lorkhan_profile,routing,relationship_configuration_id}',npc.extended_data#>>'{lorkhan_profile,routing,diary_generation_configuration_id}',npc.extended_data#>>'{lorkhan_profile,routing,player_autochat_configuration_id}'))+(SELECT count(*) FROM lorkhan_internal.configuration_sets global_settings JOIN lorkhan_internal.configuration_revisions global_revision ON global_revision.configuration_id=global_settings.configuration_id AND global_revision.revision=global_settings.current_revision WHERE global_settings.kind='global_settings' AND global_settings.deleted_at IS NULL AND global_settings.installation_id=metadata.installation_id AND metadata.configuration_id::text IN (global_revision.content#>>'{system_routing,oghma_configuration_id}',global_revision.content#>>'{system_routing,profile_generation_configuration_id}',global_revision.content#>>'{system_routing,relationship_configuration_id}')))::int AS profile_usage,current_revision.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM lorkhan_internal.configuration_revisions history WHERE history.configuration_id=metadata.configuration_id) AS revisions FROM public.core_llm_connector connector JOIN lorkhan_internal.llm_connector_metadata metadata ON metadata.connector_id=connector.id LEFT JOIN lorkhan_internal.configuration_revisions current_revision ON current_revision.configuration_id=metadata.configuration_id AND current_revision.revision=metadata.configuration_revision ORDER BY connector.label LIMIT 100",
            'tts' => "SELECT metadata.configuration_id,metadata.installation_id,connector.label AS name,metadata.configuration_revision AS current_revision,connector.metadata AS content,(selection.configuration_id IS NOT NULL) AS active,((SELECT count(*) FROM public.core_profiles profile WHERE profile.tts_connector_id=connector.id)+(SELECT count(*) FROM public.core_npc_master npc WHERE npc.extended_data#>>'{lorkhan_profile,routing,tts_configuration_id}'=metadata.configuration_id::text))::int AS profile_usage,current_revision.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM lorkhan_internal.configuration_revisions history WHERE history.configuration_id=metadata.configuration_id) AS revisions FROM public.core_tts_connector connector JOIN lorkhan_internal.tts_connector_metadata metadata ON metadata.connector_id=connector.id LEFT JOIN lorkhan_internal.configuration_revisions current_revision ON current_revision.configuration_id=metadata.configuration_id AND current_revision.revision=metadata.configuration_revision LEFT JOIN lorkhan_internal.installation_provider_selections selection ON selection.configuration_id=metadata.configuration_id AND selection.installation_id=metadata.installation_id AND selection.provider_kind='tts_provider' ORDER BY active DESC,connector.label LIMIT 100",
            'voice_catalog' => "SELECT v.configuration_id,v.voice_id,v.display_name,v.language,v.provider_status,v.custom_voice,v.discovered_at,c.installation_id,c.name AS connector_name FROM speech_connector_voices v JOIN configuration_sets c ON c.configuration_id=v.configuration_id WHERE c.kind='tts_provider' AND c.deleted_at IS NULL ORDER BY c.name,v.display_name,v.voice_id LIMIT 1024",
            'stt' => "SELECT c.configuration_id,c.installation_id,c.name,c.current_revision,r.content,(s.configuration_id IS NOT NULL) AS active,r.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM configuration_revisions history WHERE history.configuration_id=c.configuration_id) AS revisions FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision LEFT JOIN installation_provider_selections s ON s.configuration_id=c.configuration_id AND s.installation_id=c.installation_id AND s.provider_kind='stt_provider' WHERE c.kind='stt_provider' AND c.deleted_at IS NULL ORDER BY active DESC,c.name LIMIT 100",
            'prompts' => "SELECT metadata.source_configuration_id AS configuration_id,metadata.installation_id,COALESCE(configuration.name,prompt.prompt_key) AS name,prompt.prompt_key,metadata.source_revision AS current_revision,COALESCE(current_revision.content,'{}'::jsonb)||jsonb_build_object('instruction',COALESCE(NULLIF(prompt.custom_prompt,''),prompt.default_prompt),'default_prompt',prompt.default_prompt,'custom_prompt',prompt.custom_prompt,'description',prompt.description) AS content,((SELECT count(*) FROM public.core_profiles profile WHERE profile.metadata#>>'{routing,prompt_configuration_id}'=metadata.source_configuration_id::text)+(SELECT count(*) FROM public.core_npc_master npc WHERE npc.extended_data#>>'{lorkhan_profile,routing,prompt_configuration_id}'=metadata.source_configuration_id::text))::int AS profile_usage,current_revision.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM lorkhan_internal.configuration_revisions history WHERE history.configuration_id=metadata.source_configuration_id) AS revisions FROM public.prompts prompt JOIN lorkhan_internal.prompt_metadata metadata ON metadata.prompt_key=prompt.prompt_key LEFT JOIN lorkhan_internal.configuration_sets configuration ON configuration.configuration_id=metadata.source_configuration_id LEFT JOIN lorkhan_internal.configuration_revisions current_revision ON current_revision.configuration_id=metadata.source_configuration_id AND current_revision.revision=metadata.source_revision ORDER BY prompt.prompt_key LIMIT 100",
            'action_policies' => "SELECT c.configuration_id,c.installation_id,c.profile_id,p.name AS profile_name,c.name,c.current_revision,r.content,r.created_at,"
                . "(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM configuration_revisions history WHERE history.configuration_id=c.configuration_id) AS revisions "
                . "FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision LEFT JOIN profiles p ON p.profile_id=c.profile_id AND p.deleted_at IS NULL WHERE c.kind='action_policy' AND c.deleted_at IS NULL ORDER BY c.name LIMIT 100",
            'actions' => "SELECT action_name,tier,client_capability,enabled,description,parameter_schema,result_schema,server_owned,terminal_result_required,continuation_capable FROM lorkhan_internal.action_catalog ORDER BY tier,action_name LIMIT 100",
            'installations' => "SELECT installation_id,display_name,last_seen_at,created_at FROM installations WHERE revoked_at IS NULL ORDER BY last_seen_at DESC LIMIT 100",
            'global_settings' => "SELECT metadata.source_configuration_id AS configuration_id,metadata.installation_id,installation.display_name,configuration.name,max(metadata.source_revision) AS current_revision,jsonb_object_agg(metadata.setting_key,settings.value::jsonb ORDER BY metadata.setting_key) AS content,current_revision.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM lorkhan_internal.configuration_revisions history WHERE history.configuration_id=metadata.source_configuration_id) AS revisions FROM public.general_settings settings JOIN lorkhan_internal.general_setting_metadata metadata ON metadata.id=settings.id JOIN lorkhan_internal.installations installation ON installation.installation_id=metadata.installation_id JOIN lorkhan_internal.configuration_sets configuration ON configuration.configuration_id=metadata.source_configuration_id LEFT JOIN lorkhan_internal.configuration_revisions current_revision ON current_revision.configuration_id=metadata.source_configuration_id AND current_revision.revision=metadata.source_revision GROUP BY metadata.source_configuration_id,metadata.installation_id,installation.display_name,configuration.name,current_revision.created_at ORDER BY installation.display_name LIMIT 100",
            'profile_preferences' => "SELECT i.installation_id,i.display_name,COALESCE(p.auto_lock_on_edit,true) AS auto_lock_on_edit,p.updated_at FROM installations i LEFT JOIN installation_profile_preferences p ON p.installation_id=i.installation_id WHERE i.revoked_at IS NULL ORDER BY i.last_seen_at DESC LIMIT 100",
            'playthroughs' => "SELECT p.playthrough_id,p.installation_id,p.profile_id,p.name AS playthrough,pr.name AS profile,p.current_revision,p.content_fingerprint,p.created_at,"
                ."(SELECT count(*)::int FROM sessions s WHERE s.playthrough_id=p.playthrough_id) AS sessions,"
                ."(SELECT count(*)::int FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE s.playthrough_id=p.playthrough_id) AS turns,"
                ."(SELECT count(*)::int FROM dialogue_utterances d JOIN sessions s ON s.session_id=d.session_id WHERE s.playthrough_id=p.playthrough_id) AS responses,"
                ."(SELECT count(*)::int FROM memory_records m WHERE m.playthrough_id=p.playthrough_id AND m.deleted_at IS NULL) AS memories,"
                ."(SELECT count(*)::int FROM relationship_records rel WHERE rel.playthrough_id=p.playthrough_id AND rel.deleted_at IS NULL) AS relationships,"
                ."(SELECT count(*)::int FROM narrative_records n WHERE n.playthrough_id=p.playthrough_id AND n.deleted_at IS NULL) AS narratives,"
                ."(SELECT count(*)::int FROM knowledge_documents k WHERE k.playthrough_id=p.playthrough_id AND k.deleted_at IS NULL) AS knowledge_records,"
                ."(SELECT max(s.created_at) FROM sessions s WHERE s.playthrough_id=p.playthrough_id) AS last_session_at "
                ."FROM playthroughs p JOIN profiles pr ON pr.profile_id=p.profile_id WHERE p.deleted_at IS NULL"
                .($playthroughScoped?' AND p.installation_id=:playthrough_installation':'')
                .($playthroughSelected?' AND p.playthrough_id=:selected_playthrough':'')
                ." ORDER BY last_session_at DESC NULLS LAST,p.created_at DESC,p.playthrough_id DESC LIMIT ".($playthroughPage?'101 OFFSET '.((max(1,min(100000,(int)$memoryScope['page']))-1)*100):'100'),
            'active_sessions' => "SELECT s.session_id,s.installation_id,s.profile_id,s.playthrough_id,COALESCE(t.name,s.session_id::text) AS label FROM sessions s LEFT JOIN playthroughs t ON t.playthrough_id=s.playthrough_id WHERE s.state='active' ORDER BY s.created_at DESC LIMIT 50",
            'jobs' => "SELECT job_type,state,attempt_count,max_attempts,next_run_at,last_error_code,updated_at FROM durable_jobs ORDER BY updated_at DESC LIMIT 100",
            'response_queue' => "SELECT COALESCE(s.speaker,'Unknown') AS speaker,left(s.speech,1000) AS text,metadata.delivery_state,"
                . "CASE WHEN metadata.delivery_state IN ('emitted','pending') AND d.delivery_deadline_at<=clock_timestamp() THEN 'overdue' ELSE 'current' END AS queue_health,"
                . "metadata.created_at AS emitted_at,d.delivery_deadline_at,d.delivered_at FROM public.speech s JOIN lorkhan_internal.speech_metadata metadata ON metadata.rowid=s.rowid LEFT JOIN lorkhan_internal.dialogue_utterances d ON d.dialogue_message_id=metadata.dialogue_message_id ORDER BY s.rowid DESC LIMIT 100",
            'oghma_audit' => "SELECT domain,prompt_section,left(query,1000) AS query,cardinality(result_ids) AS result_count,reasons,algorithm,turn_id,created_at "
                . "FROM retrieval_traces ORDER BY created_at DESC LIMIT 100",
            'provider_usage' => "SELECT provider_kind,provider_name,COALESCE(model,'default') AS model,count(*)::int AS attempts,"
                . "count(*) FILTER(WHERE state='succeeded')::int AS succeeded,count(*) FILTER(WHERE state IN('failed','cancelled'))::int AS failed_or_cancelled,"
                . "COALESCE(sum(input_bytes),0)::bigint AS input_bytes,COALESCE(sum(output_bytes),0)::bigint AS output_bytes,"
                . "COALESCE(round(avg(duration_ms))::bigint,0) AS average_duration_ms FROM provider_attempts "
                . "GROUP BY provider_kind,provider_name,COALESCE(model,'default') ORDER BY provider_kind,provider_name,model LIMIT 100",
            'provider_attempts' => "SELECT provider_kind,provider_name,operation,state,duration_ms,error_code,started_at FROM provider_attempts ORDER BY started_at DESC LIMIT 100",
            'media_cache' => "SELECT m.media_id,COALESCE(d.speaker->>'display_name',d.speaker->>'name',d.speaker->>'record_id','Unknown') AS speaker,"
                . "m.codec,m.byte_count,m.duration_ms,COALESCE(d.delivery_state,'unlinked') AS delivery_state,"
                . "CASE WHEN m.deleted_at IS NOT NULL THEN 'deleted' WHEN m.expires_at<=clock_timestamp() THEN 'expired' ELSE 'available' END AS cache_state,"
                . "m.expires_at,m.created_at FROM media_objects m LEFT JOIN LATERAL (SELECT u.speaker,u.delivery_state FROM dialogue_utterances u "
                . "WHERE u.turn_id=m.turn_id ORDER BY u.utterance_index LIMIT 1) d ON true ORDER BY m.created_at DESC LIMIT 100",
            'backup_health' => "SELECT backup_id,state,format_version,byte_count,scope,created_at,restored_at FROM backup_records ORDER BY created_at DESC LIMIT 100",
            'database_manager' => "SELECT version,name,checksum,applied_at FROM lorkhan_internal.schema_migrations ORDER BY version DESC LIMIT 100",
            'diagnostics' => "SELECT category,action,scope,created_at FROM operational_audit ORDER BY created_at DESC LIMIT 100",
            default => [],
        };

        if ($sql === []) return [];
        return array_map(function (array $row) use ($view): array {
            // Strict LLM documents contain named key references, not secrets. A generic "token"
            // redaction would blank numeric token limits and erase them on the next editor save.
            if ($view === 'llm') $row['content'] = \LorkhanServer\Application\LlmConnector::validate(
                json_decode((string) $row['content'], true, 32, JSON_THROW_ON_ERROR));
            return $this->redactRow($row);
        }, $this->all($sql,$relationshipScoped?$relationshipParams:($memoryScoped?
            ['memory_installation'=>$memoryScope['installation_id'],'memory_playthrough'=>$memoryScope['playthrough_id']]:($playthroughScoped?['playthrough_installation'=>$memoryScope['installation_id']]+($playthroughSelected?['selected_playthrough'=>$memoryScope['selected_id']]:[]):[]))));
    }

    /** Search every global/imported biography before paging, preserving each template's identity and scope. */
    public function biographyCatalog(string $installationId,array $filters=[]):array
    {
        $search=mb_strcut(trim((string)($filters['search']??'')),0,100,'UTF-8');
        $letter=strtoupper(trim((string)($filters['letter']??'')));if(!preg_match('/^[A-Z]$/D',$letter))$letter='';
        $params=[];$conditions=[];$global="SELECT NULL::uuid AS profile_id,NULL::uuid AS installation_id,template.npc_name AS name,NULL::int AS current_revision,"
                . "jsonb_strip_nulls(jsonb_build_object('kind','template','record_id',COALESCE(NULLIF(template.refid,''),template.npc_name),'display_name',template.npc_name,'gender',template.gender,'race',template.race)) AS actor_identity,"
                . "jsonb_strip_nulls(jsonb_build_object('core',template.core,'biography',template.npc_static_bio,'oghma_tags',template.oghma_knowledge_tags,'appearance',template.appearance,'personality',template.personality,'relationships',template.relationships,'occupation',template.occupation,'skills',template.skills,'speech_style',template.speechstyle,'goals',template.goals,'voice',jsonb_strip_nulls(jsonb_build_object('id',template.voiceid)))) AS content,"
                . "CASE WHEN custom.npc_name IS NULL THEN 'factory' ELSE 'custom' END AS source "
                . "FROM public.combined_bio_templates template LEFT JOIN public.bio_templates_custom custom ON custom.npc_name=template.npc_name";
        $scoped="SELECT p.profile_id,p.installation_id,p.name,p.current_revision,p.actor_identity,r.content,'installation' AS source "
            ."FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision "
            ."WHERE p.deleted_at IS NULL AND p.actor_identity->>'kind'='template' AND p.installation_id=:installation";
        $catalog=$global;if($installationId!==''){$catalog.=' UNION ALL '.$scoped;$params['installation']=$installationId;}
        if($search!==''){$conditions[]='name ILIKE :search';$params['search']='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%';}
        if($letter!==''){$conditions[]='left(lower(name),1)=lower(:letter)';$params['letter']=$letter;}
        $query='WITH biographies AS ('.$catalog.') ';$where=$conditions===[]?'':' WHERE '.implode(' AND ',$conditions);
        $count=$this->db->prepare($query.'SELECT count(*) FROM biographies'.$where);$count->execute($params);$total=(int)$count->fetchColumn();
        $pageSize=50;$pages=max(1,(int)ceil($total/$pageSize));$page=max(1,min($pages,(int)($filters['page']??1)));
        $rows=$this->db->prepare($query.'SELECT * FROM biographies'.$where." ORDER BY lower(name),name,COALESCE(profile_id::text,'') LIMIT ".$pageSize.' OFFSET '.(($page-1)*$pageSize));
        $rows->execute($params);
        return ['rows'=>array_map(fn(array $row):array=>$this->redactRow($row),$rows->fetchAll()),'total'=>$total,'page'=>$page,'pages'=>$pages,'page_size'=>$pageSize,'search'=>$search,'letter'=>$letter];
    }

    /** Read an imported template by its stable profile ID and installation, never by display name. */
    public function installationBiographyTemplate(string $profileId,string $installationId):?array
    {
        if(!Uuid::isValid($profileId)||!Uuid::isValid($installationId))return null;
        $query=$this->db->prepare("SELECT p.profile_id,p.installation_id,p.current_revision,p.name AS npc_name,p.actor_identity->>'record_id' AS refid,"
            ."r.content->>'core' AS core,r.content->>'biography' AS npc_static_bio,r.content->>'oghma_knowledge_tags' AS oghma_knowledge_tags,"
            ."r.content->>'appearance' AS appearance,r.content->>'personality' AS personality,r.content->>'relationships' AS relationships,"
            ."r.content->>'occupation' AS occupation,r.content->>'skills' AS skills,r.content->>'speech_style' AS speechstyle,"
            ."r.content->>'goals' AS goals,r.content#>>'{voice,id}' AS voiceid,r.content->>'gender' AS gender,r.content->>'race' AS race,'installation' AS source "
            ."FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision "
            ."WHERE p.profile_id=:profile AND p.installation_id=:installation AND p.deleted_at IS NULL AND p.actor_identity->>'kind'='template'");
        $query->execute(['profile'=>$profileId,'installation'=>$installationId]);
        $row=$query->fetch();return $row===false?null:$row;
    }

    /** Return one effective factory-or-custom biography template for on-demand details and editing. */
    public function biographyTemplate(string $npcName):?array
    {
        $npcName=trim($npcName);
        if($npcName===''||strlen($npcName)>128)return null;
        $statement=$this->db->prepare("SELECT template.npc_name,template.oghma_knowledge_tags,template.core,template.npc_static_bio,"
            ."template.appearance,template.personality,template.relationships,template.occupation,template.skills,template.speechstyle,"
            ."template.goals,template.voiceid,template.gender,template.race,template.refid,"
            ."CASE WHEN custom.npc_name IS NULL THEN 'factory' ELSE 'custom' END AS source "
            ."FROM public.combined_bio_templates template LEFT JOIN public.bio_templates_custom custom ON custom.npc_name=template.npc_name "
            ."WHERE template.npc_name=:name");
        $statement->execute(['name'=>$npcName]);$row=$statement->fetch();
        return$row===false?null:$row;
    }

    private function count(string $table, ?string $where = null): int
    {
        $allowlisted = [
            'installations', 'sessions', 'turns', 'memory_records', 'relationship_records',
            'durable_jobs', 'dialogue_utterances', 'action_results', 'knowledge_documents',
            'provider_attempts',
        ];
        if (!in_array($table, $allowlisted, true)) return 0;
        $sql = 'SELECT count(*) FROM ' . $table . ($where === null ? '' : ' WHERE ' . $where);
        return (int) $this->db->query($sql)->fetchColumn();
    }

    private function one(string $sql): ?array
    {
        $row = $this->db->query($sql)->fetch();
        return $row === false ? null : $this->redactRow($row);
    }

    private function all(string $sql,array $parameters=[]): array
    {
        if($parameters!==[]){$statement=$this->db->prepare($sql);$statement->execute($parameters);return $statement->fetchAll();}
        return $this->db->query($sql)->fetchAll();
    }

    private function redactRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($key === 'custom_info') continue; // Player text is not a JSON document, even when it looks like one.
            if (!is_string($value) || ($value === '' || ($value[0] !== '{' && $value[0] !== '['))) continue;
            try {
                $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
                $row[$key] = $this->redactValue($decoded);
            } catch (\JsonException) {
                // Non-JSON text is displayed as text and escaped by the template.
            }
        }
        return $row;
    }

    private function redactValue(mixed $value, string $key = ''): mixed
    {
        if (preg_match('/(?:api[_-]?key|secret|password|authorization|token)/i', $key) === 1) return '[redacted]';
        if (!is_array($value)) return $value;
        foreach ($value as $childKey => &$childValue) {
            $childValue = $this->redactValue($childValue, (string) $childKey);
        }
        return $value;
    }
}
