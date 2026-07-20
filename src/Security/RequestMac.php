<?php

declare(strict_types=1);

namespace ALMSIVIserver\Security;

use ALMSIVIserver\Http\Request;

final class RequestMac
{
    public const ALGORITHM = 'hmac-sha256-v1';
    public const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public static function canonicalTarget(Request $request): string
    {
        if ($request->query === []) {
            return $request->path;
        }
        return $request->path . '?' . http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function canonical(Request $request, string $installation, string $timestamp,
        string $nonce, string $contentType, string $bodyDigest): string
    {
        return implode("\n", [self::ALGORITHM, strtoupper($request->method), self::canonicalTarget($request),
            strtolower(trim($contentType)), $bodyDigest, $installation, $timestamp, $nonce]);
    }

    public static function sign(string $key, Request $request, string $installation, string $timestamp,
        string $nonce, string $contentType, string $bodyDigest): string
    {
        return hash_hmac('sha256', self::canonical($request, $installation, $timestamp, $nonce,
            $contentType, $bodyDigest), $key);
    }

    public static function bodyDigest(string $body): string
    {
        return hash('sha256', $body);
    }

    public static function verify(Request $request, string $key, int $clockSkewSeconds = 300): string|false
    {
        $installation=(string)($request->header('X-ALMSIVI-Installation-Id')??'');
        $timestamp=(string)($request->header('X-ALMSIVI-Timestamp')??'');
        $nonce=(string)($request->header('X-ALMSIVI-Nonce')??'');
        $digest=(string)($request->header('X-ALMSIVI-Content-SHA256')??'');
        $signature=(string)($request->header('X-ALMSIVI-Signature')??'');
        if($request->header('X-ALMSIVI-Auth')!==self::ALGORITHM
            ||preg_match('/^[0-9a-f-]{36}$/D',$installation)!==1
            ||preg_match('/^[0-9a-f]{32}$/D',$nonce)!==1||preg_match('/^[0-9a-f]{64}$/D',$digest)!==1
            ||preg_match('/^[0-9a-f]{64}$/D',$signature)!==1)return false;
        try{$instant=new \DateTimeImmutable($timestamp,new \DateTimeZone('UTC'));}catch(\Throwable){return false;}
        if($instant->format('Y-m-d\TH:i:s\Z')!==$timestamp||abs(time()-$instant->getTimestamp())>$clockSkewSeconds
            ||!hash_equals(self::bodyDigest($request->body),$digest))return false;
        $expected=self::sign($key,$request,$installation,$timestamp,$nonce,(string)($request->header('Content-Type')??''),$digest);
        return hash_equals($expected,$signature)?$installation:false;
    }
}
