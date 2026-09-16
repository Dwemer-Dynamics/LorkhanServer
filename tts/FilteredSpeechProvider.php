<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

/** Apply only server-owned presets after synthesis; provider audio and voice samples remain untouched. */
final class FilteredSpeechProvider implements SpeechProvider
{
    public function __construct(public readonly SpeechProvider $inner) {}

    public function synthesize(string $text, CancellationToken $cancellation, array $context = []): array
    {
        $preset=TtsFilterPresets::validate($context['tts_filter_preset']??'none');
        $audio=$this->inner->synthesize($text,$cancellation,$context);
        $cancellation->throwIfCancellationRequested();
        if($preset==='none')return $audio;
        $bytes=$audio['bytes']??null;
        if(!is_string($bytes)||strlen($bytes)>33_554_432||($audio['codec']??'')!=='wav')throw new \RuntimeException('tts_filter_invalid_audio');
        OpenAiCompatibleSpeechProvider::wavDurationMs($bytes);
        $directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'lorkhan-filter-'.bin2hex(random_bytes(16));
        if(!mkdir($directory,0700))throw new \RuntimeException('tts_filter_unavailable');
        $input=$directory.'/input.wav';$output=$directory.'/output.wav';$process=null;$pipes=[];
        try {
            if(file_put_contents($input,$bytes)!==strlen($bytes))throw new \RuntimeException('tts_filter_unavailable');
            $command=['ffmpeg','-nostdin','-hide_banner','-loglevel','error','-y','-i',$input,'-vn','-af',
                implode(',',TtsFilterPresets::catalog()[$preset]['filters']),'-acodec','pcm_s16le','-f','wav','-fs','33554432',$output];
            $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
            if(!is_resource($process))throw new \RuntimeException('tts_filter_unavailable');
            fclose($pipes[0]);unset($pipes[0]);
            stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
            $deadline=microtime(true)+15;
            do {
                $cancellation->throwIfCancellationRequested();
                stream_get_contents($pipes[1],8192);stream_get_contents($pipes[2],8192);
                $status=proc_get_status($process);
                if(!$status['running'])break;
                if(microtime(true)>=$deadline)throw new \RuntimeException('tts_filter_timeout');
                usleep(10000);
            }while(true);
            if($status['exitcode']!==0||!is_file($output)||filesize($output)>33_554_432)throw new \RuntimeException('tts_filter_failed');
            $filtered=file_get_contents($output);
            if(!is_string($filtered))throw new \RuntimeException('tts_filter_failed');
            $duration=OpenAiCompatibleSpeechProvider::wavDurationMs($filtered);
            return ['bytes'=>$filtered,'codec'=>'wav','mime_type'=>'audio/wav','duration_ms'=>$duration,
                'filter_identity'=>TtsFilterPresets::identity($preset)];
        } catch (OperationCancelled $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            // Match CHIM: a failed optional effect must never silence otherwise valid speech.
            error_log('LORKHAN TTS filter unavailable: '.$preset);
            return $audio;
        } finally {
            if(is_resource($process)) {
                if(proc_get_status($process)['running'])proc_terminate($process,9);
                foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
                proc_close($process);
            }
            if(is_file($input))unlink($input);if(is_file($output))unlink($output);rmdir($directory);
        }
    }
}
