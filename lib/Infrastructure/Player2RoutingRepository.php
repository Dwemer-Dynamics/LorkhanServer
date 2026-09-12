<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

/** Installation-owned, opt-in routing overlay; ordinary profile selections are never rewritten. */
final class Player2RoutingRepository
{
    public function __construct(private readonly PDO $db) {}

    public function state(string $installation,?int $revision=null):array
    {
        if($installation===''||$revision===0)return['revision'=>0,'enabled'=>false,'configuration_id'=>null];
        $query=$this->db->prepare('SELECT revision,enabled,configuration_id FROM player2_routing WHERE installation_id=:installation'.($revision===null?'':' AND revision=:revision').' ORDER BY revision DESC LIMIT 1');
        $query->execute(['installation'=>$installation]+($revision===null?[]:['revision'=>$revision]));$row=$query->fetch();
        if(!$row&&$revision!==null)throw new RuntimeException('player2_revision_not_found');
        return $row?['revision'=>(int)$row['revision'],'enabled'=>filter_var($row['enabled'],FILTER_VALIDATE_BOOL),'configuration_id'=>$row['configuration_id']]
            :['revision'=>0,'enabled'=>false,'configuration_id'=>null];
    }

    /** Active routing must not silently fall through after connector deletion or service repurposing. */
    public function assertNotActive(string $configuration):void
    {
        $query=$this->db->prepare('SELECT 1 FROM player2_routing p WHERE p.configuration_id=:id AND p.enabled
            AND NOT EXISTS(SELECT 1 FROM player2_routing newer WHERE newer.installation_id=p.installation_id AND newer.revision>p.revision)');
        $query->execute(['id'=>$configuration]);
        if($query->fetchColumn())throw new \InvalidArgumentException('provider_in_use');
    }

    /** Resolve the actual owned connector, failing explicitly if an enabled route was repurposed. */
    public function forcedConnector(string $installation,?int $revision=null):?array
    {
        $state=$this->state($installation,$revision);if(!$state['enabled'])return null;
        $connector=(new ProductRepository($this->db))->getRevisioned('provider',$state['configuration_id']);
        if($connector['installation_id']!==$installation||($connector['content']['service']??'')!=='player2')
            throw new RuntimeException('player2_connector_unavailable');
        return ['configuration_id'=>$connector['configuration_id'],'revision'=>(int)$connector['current_revision'],
            'content'=>$connector['content'],'policy_revision'=>$state['revision']];
    }

    /** Replace only effective LLM IDs; prompts, speech, opt-outs and stored selections remain intact. */
    public function apply(string $installation,array $routing,?int $revision=null):array
    {
        $connector=$this->forcedConnector($installation,$revision);if($connector===null)return$routing;
        foreach(\LorkhanServer\Application\SettingsCatalog::routingTypes()as$field=>$type){
            if($type==='uuid_or_empty'&&!in_array($field,['prompt_configuration_id','tts_configuration_id'],true))
                $routing[$field]=$connector['configuration_id'];
        }
        return$routing;
    }

    /** Serialize Quickstart saves and create only a dedicated connector, never adopt one by its label. */
    public function save(string $installation,bool $enabled,int $expectedRevision,string $now):array
    {
        $products=new ProductRepository($this->db);
        return $products->transaction(function()use($products,$installation,$enabled,$expectedRevision,$now):array{
            $lock=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:installation FOR UPDATE');
            $lock->execute(['installation'=>$installation]);if(!$lock->fetchColumn())throw new RuntimeException('not_found');
            $state=$this->state($installation);
            if($state['revision']!==$expectedRevision)throw new RuntimeException('revision_conflict');
            $id=$state['configuration_id'];
            if($enabled){
                $existing=null;
                if($id!==null){
                    try{$existing=$products->getRevisioned('provider',$id);}
                    catch(RuntimeException $error){if($error->getMessage()!=='not_found')throw $error;}
                }
                if($existing===null||($existing['content']['service']??'')!=='player2'){
                    $names=$this->db->prepare("SELECT lower(name) FROM configuration_sets WHERE installation_id=:installation AND kind='provider'");
                    $names->execute(['installation'=>$installation]);$used=$names->fetchAll(PDO::FETCH_COLUMN);$name='Player2 Local';$suffix=2;
                    while(in_array(strtolower($name),$used,true))$name='Player2 Local '.$suffix++;
                    $connector=$products->createRevisioned('provider',['installation_id'=>$installation,'name'=>$name,'content'=>[
                        'driver'=>'openai-compatible','service'=>'player2','endpoint'=>'http://127.0.0.1:4315/v1/chat/completions',
                        'model'=>'player2-app-selected','credential'=>'none','timeout_ms'=>30000]],$now);
                    $id=$connector['configuration_id'];
                }
            }
            if($state['enabled']===$enabled&&$state['configuration_id']===$id)return$state;
            $this->db->prepare('INSERT INTO player2_routing(installation_id,revision,enabled,configuration_id,created_at) VALUES(:installation,:revision,:enabled,:connector,:now)')
                ->execute(['installation'=>$installation,'revision'=>$expectedRevision+1,'enabled'=>$enabled?'true':'false','connector'=>$id,'now'=>$now]);
            return$this->state($installation);
        });
    }
}
