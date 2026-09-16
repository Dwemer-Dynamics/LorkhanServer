<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use PDO;
use DomainException;
use LorkhanServer\Protocol\Validator;
use LorkhanServer\Protocol\ValidationException;

/** Persist bounded book snapshots and game-side receipts independently of model actions. */
final class PhysicalDiaryRepository
{
    public function __construct(private readonly PDO $db) {}

    public function claim(array $message): ?array
    {
        $repository=new Repository($this->db);
        return $repository->transaction(function()use($repository,$message):?array{
            $session=$repository->session($message['session_id'],$message['generation'],true);
            $this->capability($session);
            // Filter opt-outs before the bounded scan, and rotate already-attempted NPCs behind new recipients.
            $query=$this->db->prepare("WITH candidates AS (
                SELECT p.profile_id,p.name,COALESCE(b.actor_identity,p.actor_identity) AS actor_identity
                FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision
                LEFT JOIN core_profiles c ON c.core_profile_id=COALESCE(p.core_profile_id,
                    (SELECT dc.core_profile_id FROM core_profiles dc WHERE dc.installation_id=p.installation_id AND dc.default_npc=true AND dc.deleted_at IS NULL LIMIT 1)) AND c.deleted_at IS NULL
                LEFT JOIN core_profile_revisions cr ON cr.core_profile_id=c.core_profile_id AND cr.revision=c.current_revision
                LEFT JOIN LATERAL (SELECT ab.actor_identity FROM actor_profile_bindings ab WHERE ab.installation_id=p.installation_id
                    AND ab.playthrough_id=:playthrough AND ab.profile_id=p.profile_id LIMIT 1) b ON true
                WHERE p.installation_id=:installation AND p.deleted_at IS NULL AND ".ProfileScopeSql::matches('p',':playthrough')." AND p.actor_identity->>'kind' IN ('npc','creature')
                AND (SELECT count(*) FROM actor_profile_bindings ab WHERE ab.installation_id=p.installation_id
                    AND ab.playthrough_id=:playthrough AND ab.profile_id=p.profile_id)<=1
                AND COALESCE(r.content->'diary'->>'materialize_enabled',r.content->'settings_overrides'->'diary'->>'materialize_enabled',
                    cr.content->'settings_overrides'->'diary'->>'materialize_enabled','false')='true'
                AND EXISTS(SELECT 1 FROM narrative_records n JOIN durable_jobs j ON j.payload->>'narrative_id'=n.narrative_id::text
                    AND j.job_type='narrative.generate' AND j.state='succeeded'
                    AND j.payload->>'installation_id'=n.installation_id::text AND j.payload->>'profile_id'=n.profile_id::text
                    AND j.payload->>'playthrough_id'=n.playthrough_id::text
                    WHERE n.profile_id=p.profile_id AND n.playthrough_id=:playthrough AND n.installation_id=p.installation_id
                    AND n.kind='diary' AND n.deleted_at IS NULL AND trim(n.content)<>''
                    AND NOT EXISTS(SELECT 1 FROM timeline_invalidated_turns i
                        WHERE COALESCE(j.payload->'source_turn_ids','[]'::jsonb) @> jsonb_build_array(i.turn_id::text))))
                SELECT * FROM candidates WHERE jsonb_exists(actor_identity,'refnum') AND jsonb_exists(actor_identity,'cell')
                ORDER BY (SELECT max(d.checked_at) FROM physical_diary_deliveries d
                    WHERE d.session_id=:session AND d.profile_id=candidates.profile_id) NULLS FIRST,profile_id LIMIT 100");
            $query->execute(['installation'=>$session['installation_id'],'playthrough'=>$session['playthrough_id'],'session'=>$session['session_id']]);
            foreach($query->fetchAll()as$profile){
                $settings=(new ProductRepository($this->db))->effectiveSettingsForProfile($session['installation_id'],$profile['profile_id']);
                if(($settings['settings']['diary']['materialize_enabled']??false)!==true)continue;
                $book=$this->snapshot($session,$profile);
                if($book===null)continue;
                $existing=$this->db->prepare('SELECT * FROM physical_diary_deliveries WHERE session_id=:session AND book_id=:book ORDER BY created_at DESC,delivery_id DESC LIMIT 1 FOR UPDATE');
                $existing->execute(['session'=>$session['session_id'],'book'=>$book['book_id']]);$prior=$existing->fetch();
                if($prior){
                    $this->db->prepare('UPDATE physical_diary_deliveries SET checked_at=clock_timestamp() WHERE delivery_id=:id')->execute(['id'=>$prior['delivery_id']]);
                    $same=json_decode($prior['snapshot'],true,32,JSON_THROW_ON_ERROR)==$book;
                    if($same&&$prior['state']==='delivered')return ['delivery_id'=>$prior['delivery_id']]+$book;
                    if($same&&$prior['state']==='succeeded')continue;
                    if($same&&$prior['state']==='failed'&&($prior['reason_code']!=='target_unavailable'
                        ||new \DateTimeImmutable($prior['retry_after'])>new \DateTimeImmutable()))continue;
                    if($prior['state']==='delivered')$this->db->prepare("UPDATE physical_diary_deliveries SET state='superseded',completed_at=clock_timestamp() WHERE delivery_id=:id")
                        ->execute(['id'=>$prior['delivery_id']]);
                }
                $id=Uuid::v4();
                $this->db->prepare('INSERT INTO physical_diary_deliveries(delivery_id,book_id,installation_id,profile_id,playthrough_id,session_id,generation,snapshot,content_hash)
                    VALUES(:id,:book,:installation,:profile,:playthrough,:session,:generation,CAST(:snapshot AS jsonb),:hash)')->execute([
                    'id'=>$id,'book'=>$book['book_id'],'installation'=>$session['installation_id'],'profile'=>$profile['profile_id'],
                    'playthrough'=>$session['playthrough_id'],'session'=>$session['session_id'],'generation'=>$session['generation'],
                    'snapshot'=>json_encode($book,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'hash'=>$book['content_hash']]);
                return ['delivery_id'=>$id]+$book;
            }
            return null;
        });
    }

    public function complete(array $message): bool
    {
        $repository=new Repository($this->db);
        return $repository->transaction(function()use($repository,$message):bool{
            $session=$repository->session($message['session_id'],$message['generation'],true);$this->capability($session);
            $query=$this->db->prepare('SELECT * FROM physical_diary_deliveries WHERE delivery_id=:id FOR UPDATE');
            $query->execute(['id'=>$message['delivery_id']]);$row=$query->fetch();
            if(!$row)throw new \OutOfBoundsException('diary_book_not_found');
            if($row['installation_id']!==$session['installation_id']||$row['session_id']!==$session['session_id']
                ||(int)$row['generation']!==$message['generation'])throw new DomainException('stale_generation');
            if($row['book_id']!==$message['book_id']||$row['content_hash']!==$message['content_hash'])throw new DomainException('diary_book_mismatch');
            $canonical=$message;unset($canonical['request_id']);ksort($canonical);$fingerprint=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR));
            if($row['result_message_id']!==null){
                if($row['result_message_id']===$message['message_id']&&hash_equals($row['result_fingerprint'],$fingerprint))return true;
                throw new DomainException('diary_book_terminal');
            }
            if($row['state']!=='delivered')throw new DomainException('diary_book_superseded');
            $this->db->prepare("UPDATE physical_diary_deliveries SET state=:state,result_message_id=:message,
                result_fingerprint=:fingerprint,reason_code=:reason,completed_at=clock_timestamp(),
                retry_after=clock_timestamp()+interval '30 seconds' WHERE delivery_id=:id")->execute([
                'state'=>$message['status'],'message'=>$message['message_id'],'fingerprint'=>$fingerprint,
                'reason'=>$message['reason_code'],'id'=>$message['delivery_id']]);
            return false;
        });
    }

    private function capability(array $session): void
    {
        if(!in_array('diary.books.v1',$session['capabilities'],true))throw new DomainException('diary_books_unsupported');
    }

    /** CHIM-style latest-five-entry diary, plain text only; native rendering escapes book markup. */
    private function snapshot(array $session,array $profile): ?array
    {
        $target=json_decode($profile['actor_identity'],true,32,JSON_THROW_ON_ERROR);
        // Reuse strict identity validation through the existing controls query envelope.
        try{(new Validator())->validate(['schema'=>'lorkhan.controls.query.v1','message_id'=>Uuid::v4(),
            'request_id'=>Uuid::v4(),'session_id'=>$session['session_id'],'generation'=>(int)$session['generation'],
            'target'=>$target],'lorkhan.controls.query.v1');}catch(ValidationException){return null;}
        $query=$this->db->prepare("SELECT n.title,n.content FROM narrative_records n WHERE n.installation_id=:installation
            AND n.profile_id=:profile AND n.playthrough_id=:playthrough AND n.kind='diary' AND n.deleted_at IS NULL
            AND EXISTS(SELECT 1 FROM durable_jobs j WHERE j.job_type='narrative.generate' AND j.state='succeeded'
                AND j.payload->>'narrative_id'=n.narrative_id::text AND j.payload->>'installation_id'=n.installation_id::text
                AND j.payload->>'profile_id'=n.profile_id::text AND j.payload->>'playthrough_id'=n.playthrough_id::text
                AND NOT EXISTS(SELECT 1 FROM timeline_invalidated_turns i
                    WHERE COALESCE(j.payload->'source_turn_ids','[]'::jsonb) @> jsonb_build_array(i.turn_id::text)))
            ORDER BY n.created_at DESC,n.narrative_id DESC LIMIT 5");
        $query->execute(['installation'=>$session['installation_id'],'profile'=>$profile['profile_id'],'playthrough'=>$session['playthrough_id']]);
        $parts=[];foreach(array_reverse($query->fetchAll())as$entry){
            $text=trim($entry['content']);if($text==='')continue;
            $heading=trim($entry['title']);$parts[]=($heading!==''?'['.$heading."]\n":'').$text;
        }
        $content=trim(implode("\n\n",$parts));if($content==='')return null;
        if(mb_strlen($content,'UTF-8')>1800){
            $content=mb_substr($content,-1800,null,'UTF-8');$break=mb_strpos($content,"\n\n",0,'UTF-8');
            if($break!==false)$content=mb_substr($content,$break+2,null,'UTF-8');
            $content="Earlier pages omitted.\n\n".ltrim($content);
        }
        $title=mb_strcut(trim($profile['name']),0,120,'UTF-8')."'s Diary";
        return ['book_id'=>Uuid::deterministicV4('physical-diary:'.$session['installation_id'].':'.$session['playthrough_id'].':'.$profile['profile_id']),
            'target'=>$target,'title'=>$title,'content'=>$content,'content_hash'=>hash('sha256',$content)];
    }
}
