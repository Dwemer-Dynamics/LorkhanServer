<?php

declare(strict_types=1);

namespace ALMSIVIserver\Infrastructure;

use ALMSIVIserver\Application\DeterministicRetrieval;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class OghmaCatalogImporter
{
    private const FORMAT = 'almsivi.morrowind-oghma-catalog.v1';
    private const MAX_ARTICLES_BYTES = 33_554_432;
    private const MAX_MANIFEST_BYTES = 262_144;
    private const LOCK_ID = 4_684_566_698_006_905;
    private const CATEGORIES = ['alchemy','artifacts','books','creatures','cultures','diseases','equipment','factions',
        'figures','history','ingredients','locations','lore','magic','races','regions','religion','settlements',
        'ascadian_isles','ashlands','azuras_coast','bitter_coast','grazelands','molag_amur','mournhold',
        'red_mountain','sheogorad','solstheim','west_gash','locationother'];
    private const FIELDS = ['topic','title','aliases','topic_desc','knowledge_class','topic_desc_basic',
        'knowledge_class_basic','tags','category'];

    public function __construct(private readonly PDO $db) {}

    /** Validate a reviewed package and report the active-catalog delta without writes. */
    public function plan(string $articlesPath,string $manifestPath,string $catalogVersion):array
    {
        $package=$this->loadPackage($articlesPath,$manifestPath,$catalogVersion);
        $active=$this->activeCatalog();
        $current=$active===null?[]:$this->entries((string)$active['catalog_id']);
        $incoming=[];foreach($package['rows']as$row)$incoming[$row['topic']]=$row;
        $inserted=$changed=$unchanged=0;
        foreach($incoming as$topic=>$row){
            if(!isset($current[$topic]))++$inserted;
            elseif($current[$topic]===$row)++$unchanged;
            else++$changed;
        }
        return['schema'=>'almsivi.oghma-catalog-plan.v1','valid'=>$package['errors']===[],
            'catalog_version'=>$catalogVersion,'row_count'=>count($package['rows']),
            'inserted'=>$inserted,'changed'=>$changed,'unchanged'=>$unchanged,
            'missing_from_import'=>count(array_diff_key($current,$incoming)),
            'articles_sha256'=>$package['articles_sha256'],'manifest_sha256'=>$package['manifest_sha256'],
            'invalid_count'=>count($package['errors']),'errors'=>$package['errors']];
    }

    /** Synchronize the checked-in dataset and atomically replace only tracked factory documents. */
    public function apply(string $articlesPath,string $manifestPath,string $catalogVersion):array
    {
        $package=$this->loadPackage($articlesPath,$manifestPath,$catalogVersion);
        if($package['errors']!==[])throw new InvalidArgumentException('invalid_oghma_catalog: '.implode('; ',$package['errors']));
        $plan=$this->plan($articlesPath,$manifestPath,$catalogVersion);
        return$this->transaction(function()use($package,$catalogVersion,$plan):array{
            $this->db->exec('SELECT pg_advisory_xact_lock('.self::LOCK_ID.')');
            $current=$this->activeCatalog();
            $catalogCount=(int)$this->db->query('SELECT count(*) FROM oghma_catalogs')->fetchColumn();
            if($current!==null&&$catalogCount===1&&$current['catalog_version']===$catalogVersion
                &&hash_equals((string)$current['articles_sha256'],$package['articles_sha256'])
                &&hash_equals((string)$current['manifest_sha256'],$package['manifest_sha256'])){
                $projected=$this->projectAllInstallations((string)$current['catalog_id']);
                return$plan+['applied'=>false,'idempotent'=>true,'catalog_id'=>$current['catalog_id'],'projected_installations'=>$projected];
            }
            $this->db->exec('DELETE FROM knowledge_documents d USING oghma_factory_documents f WHERE f.document_id=d.document_id');
            $this->db->exec('DELETE FROM oghma_catalogs');
            $catalogId=Uuid::v4();$now=gmdate('Y-m-d\TH:i:s\Z');
            $m=$package['manifest'];
            $statement=$this->db->prepare("INSERT INTO oghma_catalogs(catalog_id,catalog_version,format_version,articles_sha256,manifest_sha256,ontology_sha256,topic_seeds_sha256,generator_sha256,builder_sha256,official_content_sha256,row_count,state,previous_catalog_id,imported_at,activated_at) VALUES(:id,:version,:format,:articles,:manifest,:ontology,:seeds,:generator,:builder,CAST(:official AS jsonb),:rows,'active',NULL,:now,:now)");
            $statement->execute(['id'=>$catalogId,'version'=>$catalogVersion,'format'=>$m['format'],'articles'=>$package['articles_sha256'],
                'manifest'=>$package['manifest_sha256'],'ontology'=>$m['ontology_sha256'],'seeds'=>$m['topic_seeds_sha256'],
                'generator'=>$m['generator_sha256'],'builder'=>$m['builder_sha256'],
                'official'=>json_encode($m['official_content_sha256'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
                'rows'=>count($package['rows']),'now'=>$now]);
            $insert=$this->db->prepare('INSERT INTO oghma_catalog_entries(catalog_id,topic,title,aliases,topic_desc,knowledge_class,topic_desc_basic,knowledge_class_basic,tags,category,mod_source) VALUES(:catalog,:topic,:title,:aliases,:topic_desc,:knowledge_class,:topic_desc_basic,:knowledge_class_basic,:tags,:category,:mod_source)');
            foreach($package['rows']as$row)$insert->execute(['catalog'=>$catalogId]+$row);
            $projected=$this->projectAllInstallations($catalogId);
            return$plan+['applied'=>true,'idempotent'=>false,'catalog_id'=>$catalogId,'projected_installations'=>$projected];
        });
    }

    /** Synchronize the bundled current dataset and repair installation projections idempotently. */
    public function provision(string $articlesPath,string $manifestPath,string $catalogVersion):array
    {
        return$this->apply($articlesPath,$manifestPath,$catalogVersion);
    }

    /** Attach the active catalog to one newly-created installation without bundled-file access. */
    public function provisionInstallation(string $installationId):array
    {
        if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',$installationId)!==1)throw new InvalidArgumentException('invalid_installation_id');
        $active=$this->activeCatalog();
        if($active===null)return['schema'=>'almsivi.oghma-installation-provision.v1','status'=>'skipped','reason'=>'no_active_catalog'];
        return$this->transaction(function()use($installationId,$active):array{
            $this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))')->execute(['key'=>'oghma-catalog:'.$installationId]);
            $count=$this->projectInstallation((string)$active['catalog_id'],$installationId);
            return['schema'=>'almsivi.oghma-installation-provision.v1','status'=>'ready','catalog_version'=>$active['catalog_version'],'row_count'=>$count];
        });
    }

    public function status():array
    {
        $current=$this->activeCatalog();if($current!==null)$current['row_count']=(int)$current['row_count'];
        return['schema'=>'almsivi.oghma-current-dataset-status.v1','current_dataset'=>$current];
    }

    private function loadPackage(string $articlesPath,string $manifestPath,string $catalogVersion):array
    {
        $catalogVersion=$this->validateVersion($catalogVersion);$errors=[];
        $articlesRaw=$this->readUtf8File($articlesPath,self::MAX_ARTICLES_BYTES,'articles');
        $manifestRaw=$this->readUtf8File($manifestPath,self::MAX_MANIFEST_BYTES,'manifest');
        try{$rows=json_decode($articlesRaw,true,128,JSON_THROW_ON_ERROR);$manifest=json_decode($manifestRaw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable$error){throw new InvalidArgumentException('invalid_oghma_json: '.$error->getMessage(),0,$error);}
        if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('articles_must_be_array');
        if(!is_array($manifest)||array_is_list($manifest))throw new InvalidArgumentException('manifest_must_be_object');
        if(($manifest['format']??null)!==self::FORMAT)$errors[]='manifest format is invalid';
        if(($manifest['catalog_version']??null)!==$catalogVersion)$errors[]='manifest catalog_version does not match';
        $articlesSha=hash('sha256',$articlesRaw);$manifestSha=hash('sha256',$manifestRaw);
        if(!hash_equals((string)($manifest['articles_sha256']??''),$articlesSha))$errors[]='articles sha256 does not match manifest';
        if(($manifest['row_count']??null)!==count($rows))$errors[]='manifest row_count does not match articles';
        foreach(['ontology_sha256','topic_seeds_sha256','generator_sha256','builder_sha256']as$field)if(preg_match('/^[0-9a-f]{64}$/D',(string)($manifest[$field]??''))!==1)$errors[]="manifest {$field} is invalid";
        $contentFiles=[];$contentHashes=$manifest['official_content_sha256']??null;
        if(!is_array($contentHashes)||array_is_list($contentHashes)||$contentHashes===[]||count($contentHashes)>64)$errors[]='official content hashes are invalid';
        else foreach($contentHashes as$file=>$sha){$originalFile=(string)$file;$file=trim($originalFile);$key=mb_strtolower($file,'UTF-8');
            if(preg_match('/^[^\/\\\x00]{1,256}\.(?:esm|esp|omwaddon)$/iD',$file)!==1
                ||$file!==$originalFile||preg_match('/^[0-9a-f]{64}$/D',(string)$sha)!==1||isset($contentFiles[$key]))$errors[]='official content hashes are invalid';
            else$contentFiles[$key]=$file;}
        if(count($rows)<1)$errors[]='article catalog is empty';
        $normalized=[];$topics=[];$aliasOwners=[];
        foreach($rows as$index=>$row){
            if(!is_array($row)||array_is_list($row)){$errors[]="article {$index} is not an object";continue;}
            if(array_diff(self::FIELDS,array_keys($row))!==[]){$errors[]="article {$index} is missing required fields";continue;}
            $topic=trim((string)$row['topic']);$title=trim((string)$row['title']);$category=trim((string)$row['category']);
            $modSource=array_key_exists('mod_source',$row)?trim((string)$row['mod_source']):'';
            $normalizedModSource=null;$modSourceKey=mb_strtolower($modSource,'UTF-8');
            if($modSource!==''&&preg_match('/^[^\/\\\x00]{1,256}\.(?:esm|esp|omwaddon)$/iD',$modSource)!==1)$errors[]="article {$topic} mod_source is invalid";
            elseif($modSource!==''&&!isset($contentFiles[$modSourceKey]))$errors[]="article {$topic} mod_source is absent from official content hashes";
            elseif($modSource!=='')$normalizedModSource=$contentFiles[$modSourceKey];
            foreach(['aliases','knowledge_class','knowledge_class_basic','tags']as$field)if(!is_array($row[$field])||!array_is_list($row[$field]))$errors[]="article {$topic} {$field} must be an array";
            if($topic===''||strlen($topic)>256||isset($topics[mb_strtolower($topic,'UTF-8')]))$errors[]="article {$index} topic is invalid or duplicate";
            if($title===''||strlen($title)>256||!in_array($category,self::CATEGORIES,true))$errors[]="article {$topic} title or category is invalid";
            $advancedText=trim((string)$row['topic_desc']);$basicText=trim((string)$row['topic_desc_basic']);
            if($advancedText===''||strlen($advancedText)>131072||!mb_check_encoding($advancedText,'UTF-8'))$errors[]="article {$topic} topic_desc is invalid";
            if(strlen($basicText)>131072||!mb_check_encoding($basicText,'UTF-8'))$errors[]="article {$topic} topic_desc_basic is invalid";
            if(is_array($row['knowledge_class'])&&is_array($row['knowledge_class_basic'])){
                if(count($row['knowledge_class'])!==count(array_unique($row['knowledge_class'])))$errors[]="article {$topic} knowledge_class contains duplicates";
                if(count($row['knowledge_class_basic'])!==count(array_unique($row['knowledge_class_basic'])))$errors[]="article {$topic} knowledge_class_basic contains duplicates";
                $overlap=array_values(array_intersect($row['knowledge_class'],$row['knowledge_class_basic']));
                if($overlap!==[])$errors[]="article {$topic} advanced and basic classes overlap";
                if(($basicText==='')!==($row['knowledge_class_basic']===[]))$errors[]="article {$topic} basic prose and classes must both be empty or populated";
            }
            $topics[mb_strtolower($topic,'UTF-8')]=true;
            $flat=[];foreach(['aliases','knowledge_class','knowledge_class_basic','tags']as$field){$values=[];foreach($row[$field]as$value){$value=trim((string)$value);if($value===''||strlen($value)>256||!mb_check_encoding($value,'UTF-8')){$errors[]="article {$topic} {$field} contains an invalid value";continue;}if(in_array($field,['knowledge_class','knowledge_class_basic'],true)&&preg_match('/^!?[a-z0-9_]+$/D',$value)!==1)$errors[]="article {$topic} {$field} contains an invalid class";if($field==='aliases')$value=self::serializeAliasName($value);if(!in_array($value,$values,true))$values[]=$value;}$flat[$field]=implode($field==='aliases'?', ':',',$values);}
            foreach([$topic,...$row['aliases']]as$alias){$aliasKey=preg_replace('/[^a-z0-9]+/','',mb_strtolower((string)$alias,'UTF-8'));if($aliasKey==='')continue;$owner=$aliasOwners[$aliasKey]??null;if($owner!==null&&$owner!==$topic)$errors[]="alias {$alias} collides between {$owner} and {$topic}";$aliasOwners[$aliasKey]=$topic;}
            $normalized[]=['topic'=>$topic,'title'=>$title,'aliases'=>$flat['aliases'],'topic_desc'=>$advancedText,
                'knowledge_class'=>$flat['knowledge_class'],'topic_desc_basic'=>$basicText,
                'knowledge_class_basic'=>$flat['knowledge_class_basic'],'tags'=>$flat['tags'],'category'=>$category,
                'mod_source'=>$normalizedModSource];
        }
        return['rows'=>$normalized,'manifest'=>$manifest,'articles_sha256'=>$articlesSha,'manifest_sha256'=>$manifestSha,
            'errors'=>array_slice(array_values(array_unique($errors)),0,100)];
    }

    private function projectAllInstallations(string $catalogId):int
    {
        $ids=$this->db->query('SELECT installation_id FROM installations WHERE revoked_at IS NULL ORDER BY installation_id')->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as$id)$this->projectInstallation($catalogId,(string)$id);
        return count($ids);
    }

    private function projectInstallation(string $catalogId,string $installationId):int
    {
        $delete=$this->db->prepare('DELETE FROM knowledge_documents d USING oghma_factory_documents f WHERE f.installation_id=:installation AND f.document_id=d.document_id');$delete->execute(['installation'=>$installationId]);
        $entries=$this->entries($catalogId);$insertDocument=$this->db->prepare("INSERT INTO knowledge_documents(document_id,installation_id,profile_id,playthrough_id,title,content,content_sha256,lexical_terms,provenance,created_at,topic,aliases,topic_desc_basic,knowledge_class,knowledge_class_basic,tags,category) VALUES(:id,:installation,NULL,NULL,:title,:content,:sha,CAST(:terms AS text[]),CAST(:provenance AS jsonb),:now,:topic,:aliases,:basic,:advanced_class,:basic_class,:tags,:category)");
        $insertOwner=$this->db->prepare('INSERT INTO oghma_factory_documents(installation_id,topic,document_id,catalog_id) VALUES(:installation,:topic,:document,:catalog)');$now=gmdate('Y-m-d\TH:i:s\Z');
        $catalog=$this->catalogById($catalogId)??throw new RuntimeException('oghma_catalog_missing');
        foreach($entries as$row){$id=Uuid::v4();$search=implode(' ',[$row['topic'],$row['title'],$row['aliases'],$row['topic_desc'],$row['topic_desc_basic'],$row['tags']]);
            $provenance=['source'=>'factory-oghma','catalog_id'=>$catalogId,'catalog_version'=>$catalog['catalog_version'],'category'=>$row['category'],'temporal_anchor'=>'3E 427'];
            if($row['mod_source']!==null)$provenance['mod_source']=$row['mod_source'];
            $provenance=json_encode($provenance,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            $insertDocument->execute(['id'=>$id,'installation'=>$installationId,'title'=>$row['title'],'content'=>$row['topic_desc'],'sha'=>hash('sha256',$row['topic_desc']),
                'terms'=>$this->pgArray(DeterministicRetrieval::terms($search)),'provenance'=>$provenance,'now'=>$now,'topic'=>$row['topic'],'aliases'=>$row['aliases'],
                'basic'=>$row['topic_desc_basic'],'advanced_class'=>$row['knowledge_class'],'basic_class'=>$row['knowledge_class_basic'],'tags'=>$row['tags'],'category'=>$row['category']]);
            $insertOwner->execute(['installation'=>$installationId,'topic'=>$row['topic'],'document'=>$id,'catalog'=>$catalogId]);}
        return count($entries);
    }

    private function entries(string $catalogId):array
    {
        $s=$this->db->prepare('SELECT topic,title,aliases,topic_desc,knowledge_class,topic_desc_basic,knowledge_class_basic,tags,category,mod_source FROM oghma_catalog_entries WHERE catalog_id=:id ORDER BY topic');$s->execute(['id'=>$catalogId]);$out=[];foreach($s->fetchAll()as$row)$out[$row['topic']]=$row;return$out;
    }
    private function activeCatalog():?array{$r=$this->db->query("SELECT * FROM oghma_catalogs WHERE state='active'")->fetch();return$r===false?null:$r;}
    private function catalogById(string $id):?array{$s=$this->db->prepare('SELECT * FROM oghma_catalogs WHERE catalog_id=:id');$s->execute(['id'=>$id]);$r=$s->fetch();return$r===false?null:$r;}
    /** Protect commas inside one alias before joining the comma-separated storage field. */
    private static function serializeAliasName(string $value):string{return preg_replace('/\s*,\s*/u','_',$value)??$value;}
    private function readUtf8File(string $path,int $maxBytes,string $label):string{if(!is_file($path)||!is_readable($path))throw new InvalidArgumentException("{$label} file is unavailable");$size=filesize($path);if($size===false||$size<1||$size>$maxBytes)throw new InvalidArgumentException("{$label} file size is invalid");$value=file_get_contents($path);if($value===false||!mb_check_encoding($value,'UTF-8'))throw new InvalidArgumentException("{$label} must be valid UTF-8");return str_starts_with($value,"\xEF\xBB\xBF")?substr($value,3):$value;}
    private function validateVersion(string $version):string{$version=trim($version);if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',$version)!==1)throw new InvalidArgumentException('invalid_oghma_catalog_version');return$version;}
    private function pgArray(array $values):string{return'{'.implode(',',array_map(static fn(string$v):string=>'"'.str_replace(['\\','"'],['\\\\','\\"'],$v).'"',$values)).'}';}
    private function transaction(callable $callback):mixed{$owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();try{$result=$callback();if($owns)$this->db->commit();return$result;}catch(Throwable$error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw$error;}}
}
