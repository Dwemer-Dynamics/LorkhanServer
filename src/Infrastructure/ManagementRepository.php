<?php

declare(strict_types=1);

namespace ALMSIVIserver\Infrastructure;

use ALMSIVIserver\Http\Request;
use ALMSIVIserver\Security\BrowserSession;
use ALMSIVIserver\Security\RequestMac;
use PDO;
use RuntimeException;

final class ManagementRepository
{
    public function __construct(private readonly PDO $db) {}

    public function createSession(int $ttlSeconds): array
    {
        $session=BrowserSession::token();$csrf=BrowserSession::token();
        $expires=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('+'.$ttlSeconds.' seconds')->format('Y-m-d\TH:i:s\Z');
        $this->db->prepare('INSERT INTO browser_sessions (session_hash,csrf_hash,expires_at) VALUES (:session,:csrf,:expires)')->execute(['session'=>BrowserSession::hash($session),'csrf'=>BrowserSession::hash($csrf),'expires'=>$expires]);
        return ['session'=>$session,'csrf'=>$csrf,'expires_at'=>$expires];
    }

    public function validate(string $session,?string $csrf=null):bool
    {
        $stmt=$this->db->prepare('SELECT csrf_hash FROM browser_sessions WHERE session_hash=:session AND revoked_at IS NULL AND expires_at>clock_timestamp()');$stmt->execute(['session'=>BrowserSession::hash($session)]);$hash=$stmt->fetchColumn();if($hash===false)return false;if($csrf!==null&&!hash_equals((string)$hash,BrowserSession::hash($csrf)))return false;$this->db->prepare('UPDATE browser_sessions SET last_seen_at=clock_timestamp() WHERE session_hash=:session')->execute(['session'=>BrowserSession::hash($session)]);return true;
    }

    public function revoke(string $session):void{$this->db->prepare('UPDATE browser_sessions SET revoked_at=clock_timestamp() WHERE session_hash=:session')->execute(['session'=>BrowserSession::hash($session)]);}

    public function rotatePairingToken(string $installationId,string $_unused,int $overlapSeconds):array
    {
        $key=random_bytes(32);$hash=hash('sha256',$key);
        $this->db->beginTransaction();try{$this->db->prepare("UPDATE pairing_tokens SET state='overlap',valid_until=clock_timestamp()+(:seconds||' seconds')::interval WHERE installation_id=:installation AND state='active'")->execute(['seconds'=>(string)$overlapSeconds,'installation'=>$installationId]);$id=Uuid::v4();$insert=$this->db->prepare("INSERT INTO pairing_tokens (pairing_token_id,installation_id,token_hash,mac_key,state) VALUES (:id,:installation,:hash,:key,'active')");$insert->bindValue(':id',$id);$insert->bindValue(':installation',$installationId);$insert->bindValue(':hash',$hash);$insert->bindValue(':key',$key,\PDO::PARAM_LOB);$insert->execute();$this->db->prepare('UPDATE installations SET token_fingerprint=:hash WHERE installation_id=:installation')->execute(['hash'=>$hash,'installation'=>$installationId]);$this->audit('security','pairing.rotate',['installation_id'=>$installationId],['overlap_seconds'=>$overlapSeconds]);$this->db->commit();return ['pairing_token_id'=>$id,'overlap_seconds'=>$overlapSeconds,'mac_key'=>rtrim(strtr(base64_encode($key),'+/','-_'),'=')];}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }
    public function revokePairingToken(string $id):void{$stmt=$this->db->prepare("UPDATE pairing_tokens SET state='revoked',revoked_at=clock_timestamp() WHERE pairing_token_id=:id AND state<>'revoked'");$stmt->execute(['id'=>$id]);if($stmt->rowCount()!==1)throw new RuntimeException('not_found');$this->audit('security','pairing.revoke',['pairing_token_id'=>$id],[]);}
    public function verifyPairing(string $tokenHash):bool{$s=$this->db->prepare("SELECT 1 FROM pairing_tokens WHERE token_hash=:hash AND (state='active' OR (state='overlap' AND valid_until>clock_timestamp())) LIMIT 1");$s->execute(['hash'=>$tokenHash]);return(bool)$s->fetchColumn();}
    public function authorizePairing(?string $authorization):?bool{return $this->pairingPrincipal($authorization)!==false;}
    public function pairingPrincipal(?string $authorization):string|false|null{return false;}

    public function requestMacPrincipal(Request $request, int $clockSkewSeconds = 300): string|false|null
    {
        $count=(int)$this->db->query('SELECT count(*) FROM pairing_tokens')->fetchColumn();if($count===0)return null;
        $installation=(string)($request->header('X-ALMSIVI-Installation-Id')??'');
        $timestamp=(string)($request->header('X-ALMSIVI-Timestamp')??'');
        $nonce=(string)($request->header('X-ALMSIVI-Nonce')??'');
        $digest=(string)($request->header('X-ALMSIVI-Content-SHA256')??'');
        $signature=(string)($request->header('X-ALMSIVI-Signature')??'');
        $algorithm=(string)($request->header('X-ALMSIVI-Auth')??'');
        if($algorithm!==RequestMac::ALGORITHM||preg_match('/^[0-9a-f-]{36}$/D',$installation)!==1
            ||preg_match('/^[0-9a-f]{32}$/D',$nonce)!==1||preg_match('/^[0-9a-f]{64}$/D',$digest)!==1
            ||preg_match('/^[0-9a-f]{64}$/D',$signature)!==1)return false;
        try{$instant=new \DateTimeImmutable($timestamp,new \DateTimeZone('UTC'));}catch(\Throwable){return false;}
        if($instant->format('Y-m-d\TH:i:s\Z')!==$timestamp||abs(time()-$instant->getTimestamp())>$clockSkewSeconds)return false;
        if(!hash_equals(RequestMac::bodyDigest($request->body),$digest))return false;
        $s=$this->db->prepare("SELECT pairing_token_id,mac_key FROM pairing_tokens WHERE installation_id=:installation AND (state='active' OR(state='overlap' AND valid_until>clock_timestamp())) ORDER BY state='active' DESC");
        $s->execute(['installation'=>$installation]);
        foreach($s->fetchAll() as $row){$key=is_resource($row['mac_key'])?stream_get_contents($row['mac_key']):(string)$row['mac_key'];$expected=RequestMac::sign($key,$request,$installation,$timestamp,$nonce,(string)($request->header('Content-Type')??''),$digest);if(!hash_equals($expected,$signature))continue;
            try{$insert=$this->db->prepare('INSERT INTO request_mac_nonces(pairing_token_id,nonce,request_timestamp) VALUES (:token,:nonce,:instant)');$insert->execute(['token'=>$row['pairing_token_id'],'nonce'=>$nonce,'instant'=>$timestamp]);}catch(\PDOException $error){if($error->getCode()==='23505')return false;throw$error;}
            $this->db->prepare("DELETE FROM request_mac_nonces WHERE created_at<clock_timestamp()-interval '10 minutes'")->execute();return$installation;}
        return false;
    }

    public function audit(string $category,string $action,array $scope,array $detail):void{$scope=(object)$scope;$detail=(object)$detail;$this->db->prepare('INSERT INTO operational_audit (audit_id,category,action,scope,detail) VALUES (:id,:category,:action,CAST(:scope AS jsonb),CAST(:detail AS jsonb))')->execute(['id'=>Uuid::v4(),'category'=>$category,'action'=>$action,'scope'=>json_encode($scope,JSON_THROW_ON_ERROR),'detail'=>json_encode($detail,JSON_THROW_ON_ERROR)]);}
}
