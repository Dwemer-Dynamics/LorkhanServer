<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

/** Private review artifacts and decisions; never edits NPC profiles or publishes provider voices. */
final class VoiceDesignReview
{
    public function __construct(public readonly string $root) {}

    public function document():array
    {
        $path=$this->root.'/candidates.json';
        if(!is_file($path))return ['characters'=>[]];
        return json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    }

    public function decisions():array
    {
        $path=$this->root.'/decisions.json';
        return is_file($path)?json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR):[];
    }

    public function choose(string $character,string $candidate):void
    {
        $row=null;
        foreach($this->document()['characters'] as $entry)if($entry['key']===$character)$row=$entry;
        if($row===null||!in_array($candidate,['none','pending',...array_column($row['candidates']??[],'id')],true))
            throw new \InvalidArgumentException('invalid_review_choice');
        $lock=fopen($this->root.'/decisions.lock','c');
        if($lock===false||!flock($lock,LOCK_EX))throw new \RuntimeException('review_unavailable');
        try{
            $decisions=$this->decisions();
            $decisions[$character]=['candidate'=>$candidate,'updated_at'=>gmdate('c')];
            self::writeJson($this->root.'/decisions.json',$decisions);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    public static function writeJson(string $path,array $data):void
    {
        $temp=tempnam(dirname($path),'.review-');
        if($temp===false)throw new \RuntimeException('review_unavailable');
        try{
            if(file_put_contents($temp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false
                ||!chmod($temp,0660)||!rename($temp,$path))throw new \RuntimeException('review_unavailable');
        }finally{if(is_file($temp))unlink($temp);}
    }
}
