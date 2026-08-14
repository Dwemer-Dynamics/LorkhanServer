<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

/** Deterministic extractor used by existing unit and integration test configurations. */
final class MockOghmaTopicExtractor implements OghmaTopicExtractor
{
    public function extract(string $context,int $limit,CancellationToken $cancellation):array
    {
        $cancellation->throwIfCancellationRequested();$limit=max(1,min(3,$limit));
        if(preg_match('/\[oghma:([^\]]+)\]/iu',$context,$match)!==1)return[];
        $topics=[];foreach(preg_split('/\s*[,;|]\s*/u',$match[1])?:[]as$topic){$topic=trim($topic);
            if($topic!==''&&mb_strlen($topic,'UTF-8')<=128&&!in_array(mb_strtolower($topic,'UTF-8'),array_map(static fn(string$value):string=>mb_strtolower($value,'UTF-8'),$topics),true))$topics[]=$topic;
            if(count($topics)>=$limit)break;}
        return$topics;
    }
}
