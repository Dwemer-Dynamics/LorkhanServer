<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use RuntimeException;

final class MorrowindGeographyCatalog
{
    /** @var array<string,array<string,mixed>> */
    private array $references;
    /** @var array<string,string> */
    private array $interiors;
    /** @var array<string,string> */
    private array $exteriors;
    /** @var list<string> */
    private array $localityClasses;

    public function __construct(private readonly string $path)
    {
        $raw=file_get_contents($path);
        if(!is_string($raw))throw new RuntimeException('morrowind_geography_catalog_unavailable');
        $document=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($document)||($document['schema']??null)!=='almsivi.morrowind-geography.v1'
            ||!is_array($document['npc_references']??null)||!is_array($document['interior_cells']??null)
            ||!is_array($document['exterior_cells']??null)||!is_array($document['canonical_regions']??null)
            ||!is_array($document['supplemental_locality_classes']??null))
            throw new RuntimeException('morrowind_geography_catalog_invalid');
        $this->references=$document['npc_references'];
        $this->interiors=$document['interior_cells'];
        $this->exteriors=$document['exterior_cells'];
        $this->localityClasses=array_values(array_unique(array_merge(
            $document['canonical_regions'],$document['supplemental_locality_classes']
        )));
    }

    public static function bundled():self
    {
        return new self(dirname(__DIR__,2).'/resources/oghma/morrowind-official/geography-v1.json');
    }

    /** Resolve immutable home locality from RefNum first, using the discovery cell only as a fallback. */
    public function resolve(array $identity,?string $referenceContentFile):?array
    {
        $recordId=strtolower(trim((string)($identity['record_id']??'')));
        $contentFile=strtolower(trim((string)($identity['content_file']??'')));
        $refnum=is_array($identity['refnum']??null)?$identity['refnum']:[];
        $index=$refnum['index']??null;
        if($referenceContentFile!==null&&is_int($index)){
            $key=strtolower(trim($referenceContentFile)).':'.$index;
            $row=$this->references[$key]??null;
            if(is_array($row)&&strtolower((string)($row['record_id']??''))===$recordId
                &&strtolower((string)($row['record_content_file']??''))===$contentFile){
                $tags=$this->validTags($row['locality_tags']??[]);
                if($tags!==[])return['tags'=>$tags,'source'=>'official_refnum','region'=>$row['region']??null];
            }
        }
        $cell=is_array($identity['cell']??null)?$identity['cell']:[];
        $kind=strtolower(trim((string)($cell['kind']??'')));
        $region=null;
        if($kind==='interior'){
            $name=strtolower(trim((string)($cell['name']??'')));
            if($name!=='')$region=$this->interiors[$name]??null;
        }elseif($kind==='exterior'&&is_int($cell['grid_x']??null)&&is_int($cell['grid_y']??null)){
            $region=$this->exteriors[$cell['grid_x'].','.$cell['grid_y']]??null;
        }
        return is_string($region)&&$region!==''
            ?['tags'=>[$region],'source'=>'current_cell_fallback','region'=>$region]
            :null;
    }

    /** @return list<string> */
    public function localityClasses():array{return$this->localityClasses;}

    /** @return list<string> */
    private function validTags(mixed $value):array
    {
        if(!is_array($value))return[];
        return array_values(array_filter(array_unique($value),fn(mixed $tag):bool=>
            is_string($tag)&&in_array($tag,$this->localityClasses,true)));
    }
}
