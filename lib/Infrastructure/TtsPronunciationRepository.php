<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use InvalidArgumentException;
use PDO;

/** Store and apply CHIM-compatible pronunciation rules without changing written dialogue. */
final class TtsPronunciationRepository
{
    private ?array $cachedRows = null;
    private float $cachedAt = 0.0;

    public function __construct(private readonly PDO $db) {}

    /** @return list<array<string,mixed>> */
    public function rows(): array
    {
        $now=microtime(true);
        if($this->cachedRows!==null&&$now-$this->cachedAt<1.0)return$this->cachedRows;
        $statement=$this->db->query('SELECT id,source_text,spoken_text,npc_names,races,oghma_tags,is_builtin,enabled,created_at,updated_at '
            .'FROM core_tts_pronunciation ORDER BY is_builtin DESC,lower(source_text),id LIMIT 1024');
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as&$row){$row['id']=(int)$row['id'];$row['is_builtin']=$this->boolean($row['is_builtin']);$row['enabled']=$this->boolean($row['enabled']);}
        unset($row);$this->cachedRows=$rows;$this->cachedAt=$now;return$rows;
    }

    public function saveCustom(?int $id,string $source,string $spoken,string $npcNames,string $races,string $oghmaTags,bool $enabled):int
    {
        $source=trim($source);$spoken=trim($spoken);
        if($source===''||$spoken===''||mb_strlen($source,'UTF-8')>120||mb_strlen($spoken,'UTF-8')>240)
            throw new InvalidArgumentException('invalid_pronunciation');
        $parameters=['source'=>$source,'spoken'=>$spoken,'names'=>$this->scope($npcNames,120),
            'races'=>$this->scope($races,120),'tags'=>$this->scope($oghmaTags,64,true),'enabled'=>$enabled?'true':'false'];
        if($id!==null&&$id>0){
            $parameters['id']=$id;$statement=$this->db->prepare('UPDATE core_tts_pronunciation SET source_text=:source,spoken_text=:spoken,'
                .'npc_names=:names,races=:races,oghma_tags=:tags,enabled=:enabled,updated_at=CURRENT_TIMESTAMP '
                .'WHERE id=:id AND is_builtin=false RETURNING id');
        }else{
            $statement=$this->db->prepare('INSERT INTO core_tts_pronunciation(source_text,spoken_text,npc_names,races,oghma_tags,is_builtin,enabled) '
                .'VALUES(:source,:spoken,:names,:races,:tags,false,:enabled) RETURNING id');
        }
        $statement->execute($parameters);$saved=$statement->fetchColumn();
        if($saved===false)throw new InvalidArgumentException('pronunciation_not_editable');
        $this->invalidate();return(int)$saved;
    }

    public function setEnabled(int $id,bool $enabled):void
    {
        if($id<1)throw new InvalidArgumentException('invalid_pronunciation');
        $statement=$this->db->prepare('UPDATE core_tts_pronunciation SET enabled=:enabled,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute(['id'=>$id,'enabled'=>$enabled?'true':'false']);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('pronunciation_not_found');
        $this->invalidate();
    }

    /** A shipped entry keeps its term and access scope; users can tune only its spoken text and enabled state. */
    public function saveBuiltin(int $id,string $spoken,bool $enabled):void
    {
        $spoken=trim($spoken);
        if($id<1||$spoken===''||!mb_check_encoding($spoken,'UTF-8')||mb_strlen($spoken,'UTF-8')>240)
            throw new InvalidArgumentException('invalid_pronunciation');
        $statement=$this->db->prepare('UPDATE core_tts_pronunciation SET spoken_text=:spoken,enabled=:enabled,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND is_builtin=true');
        $statement->execute(['id'=>$id,'spoken'=>$spoken,'enabled'=>$enabled?'true':'false']);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('pronunciation_not_editable');
        $this->invalidate();
    }

    public function deleteEntry(int $id):void
    {
        if($id<1)throw new InvalidArgumentException('invalid_pronunciation');
        $statement=$this->db->prepare('DELETE FROM core_tts_pronunciation WHERE id=:id');$statement->execute(['id'=>$id]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('pronunciation_not_editable');
        $this->invalidate();
    }

    /** Apply the most specific enabled rule for each whole term in one speaker scope. */
    public function apply(string $text,array $context=[]):string
    {
        if($text==='')return$text;
        $scope=is_array($context['pronunciation_scope']??null)?$context['pronunciation_scope']:[];
        $npcName=trim((string)($scope['npc_name']??''));$race=trim((string)($scope['race']??''));
        $tags=$this->values($scope['oghma_tags']??[],64,true);$resolved=[];
        foreach($this->rows()as$row){
            if(!$row['enabled']||!$this->allows($row,$npcName,$race,$tags))continue;
            $source=trim((string)$row['source_text']);$spoken=trim((string)$row['spoken_text']);
            if($source===''||$spoken==='')continue;
            $key=mb_strtolower($source,'UTF-8');$specificity=(int)($row['npc_names']!=='')+(int)($row['races']!=='')+(int)($row['oghma_tags']!=='');
            $priority=($row['is_builtin']?0:10)+$specificity;
            if(isset($resolved[$key])&&$resolved[$key]['priority']>$priority)continue;
            $resolved[$key]=['source'=>$source,'spoken'=>$spoken,'priority'=>$priority];
        }
        uasort($resolved,static fn(array$a,array$b):int=>mb_strlen($b['source'],'UTF-8')<=>mb_strlen($a['source'],'UTF-8'));
        if($resolved===[])return$text;$patterns=[];$replacements=[];
        foreach(array_slice($resolved,0,256)as$key=>$entry){$patterns[]=preg_quote($entry['source'],'~');$replacements[$key]=$entry['spoken'];}
        $pattern='~(?<![\p{L}\p{N}_])(?:'.implode('|',$patterns).')(?![\p{L}\p{N}_])~iu';
        $replaced=preg_replace_callback($pattern,static function(array$match)use($replacements):string{
            $matched=(string)($match[0]??'');return$replacements[mb_strtolower($matched,'UTF-8')]??$matched;
        },$text);
        return is_string($replaced)?$replaced:$text;
    }

    private function allows(array $row,string $npcName,string $race,array $tags):bool
    {
        $names=$this->values((string)$row['npc_names'],120);if($names!==[]&&!$this->matches($npcName,$names))return false;
        $races=$this->values((string)$row['races'],120);if($races!==[]&&!$this->matches($race,$races))return false;
        $required=$this->values((string)$row['oghma_tags'],64,true);
        return$required===[]||in_array('knowall',$tags,true)||array_intersect($required,$tags)!==[];
    }

    private function matches(string $value,array $allowed):bool
    {
        $value=mb_strtolower(trim($value),'UTF-8');if($value==='')return false;
        foreach($allowed as$item)if($value===mb_strtolower($item,'UTF-8'))return true;return false;
    }

    private function scope(string $value,int $max,bool $lower=false):string{return implode(', ',$this->values($value,$max,$lower));}

    /** @return list<string> */
    private function values(mixed $value,int $max,bool $lower=false):array
    {
        $items=is_array($value)?$value:explode(',',(string)$value);$resolved=[];
        foreach($items as$item){if(!is_string($item))continue;$item=trim($item);if($lower)$item=mb_strtolower($item,'UTF-8');
            if($item===''||mb_strlen($item,'UTF-8')>$max)continue;$resolved[mb_strtolower($item,'UTF-8')]=$item;}
        return array_slice(array_values($resolved),0,32);
    }

    private function boolean(mixed $value):bool{return is_bool($value)?$value:in_array(strtolower(trim((string)$value)),['1','t','true','yes','on'],true);}
    private function invalidate():void{$this->cachedRows=null;$this->cachedAt=0.0;}
}
