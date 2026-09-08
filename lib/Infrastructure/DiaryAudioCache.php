<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use RuntimeException;

/** Private bounded diary clips; requests must authorize the entry before consulting this cache. */
final class DiaryAudioCache
{
    public const MAX_AUDIO_BYTES = 33_554_432;
    private const MAX_BYTES = 268_435_456;
    private const MAX_FILES = 64;
    private const MAX_AGE = 604800;
    private const MIME_TYPES = ['audio/wav','audio/mpeg','audio/ogg','audio/webm','audio/flac','audio/mp4'];

    public function __construct(private readonly string $directory) {}

    /** Serialize generation without making another browser wait behind a provider timeout. */
    public function remember(string $signature, callable $generate): array
    {
        if (!is_dir($this->directory) && !mkdir($this->directory,0700,true) && !is_dir($this->directory)) {
            throw new RuntimeException('diary_audio_cache_unavailable');
        }
        $lock = fopen($this->directory.'/cache.lock','c');
        if ($lock === false) throw new RuntimeException('diary_audio_cache_unavailable');
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('diary_audio_busy');
            $files = [];
            foreach (glob($this->directory.'/*.audio') ?: [] as $file) {
                $modified = filemtime($file);
                if ($modified === false || $modified < time()-self::MAX_AGE) { unlink($file); continue; }
                $files[$file] = $modified;
            }
            $path = $this->directory.'/'.hash('sha256',$signature).'.audio';
            if (isset($files[$path])) {
                $stored = file_get_contents($path,false,null,0,self::MAX_AUDIO_BYTES+257);
                $separator = is_string($stored) ? strpos($stored,"\n") : false;
                if ($separator !== false && $separator < 128) {
                    $mime = substr($stored,0,$separator); $bytes = substr($stored,$separator+1);
                    if (in_array($mime,self::MIME_TYPES,true) && $bytes !== '' && strlen($bytes)<=self::MAX_AUDIO_BYTES) {
                        return ['bytes'=>$bytes,'mime_type'=>$mime,'cached'=>true];
                    }
                }
                unlink($path); unset($files[$path]);
            }
            $audio = $generate();
            $bytes = (string)($audio['bytes']??''); $mime = (string)($audio['mime_type']??'');
            if ($bytes === '' || strlen($bytes)>self::MAX_AUDIO_BYTES || !in_array($mime,self::MIME_TYPES,true)) {
                throw new RuntimeException('diary_audio_invalid_audio');
            }
            $stored = $mime."\n".$bytes;
            asort($files,SORT_NUMERIC);
            $total = array_sum(array_map(static fn(string $file):int=>(int)filesize($file),array_keys($files)));
            while ($files !== [] && (count($files)>=self::MAX_FILES || $total+strlen($stored)>self::MAX_BYTES)) {
                $oldest = (string)array_key_first($files); $total -= (int)filesize($oldest);
                unlink($oldest); unset($files[$oldest]);
            }
            if (file_put_contents($path,$stored)!==strlen($stored)) {
                @unlink($path); throw new RuntimeException('diary_audio_cache_unavailable');
            }
            chmod($path,0600);
            return ['bytes'=>$bytes,'mime_type'=>$mime,'cached'=>false];
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
