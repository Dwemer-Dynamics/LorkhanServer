<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

/** Extracts safe visible text growth from a streamed structured response. */
final class StreamingDialogueText
{
    private const MAX_CHUNKS = DialoguePlanner::MAX_UTTERANCES;
    private const MIN_CHUNK_BYTES = 20;

    private string $content = '';
    private string $visible = '';
    private string $emitted = '';
    private int $chunkCount = 0;
    private array $spans = [];
    private array $chunkMoods = [];
    private array $chunkTones = [];

    /** Validated tones parallel to the chunks returned by the latest push. */
    public function chunkTones(): array { return $this->chunkTones; }

    /** Mood metadata for each text chunk returned by the latest push. */
    public function chunkMoods(): array { return $this->chunkMoods; }

    /** @return list<string> */
    public function push(string $contentDelta, bool $final = false): array
    {
        $this->content .= $contentDelta;
        $next = $this->extractVisibleText($this->content);
        if (str_starts_with($next, $this->visible)) {
            $this->visible = $next;
        }

        $chunks = [];
        $this->chunkMoods = [];
        $this->chunkTones = [];
        while ($this->chunkCount < self::MAX_CHUNKS) {
            $pending = substr($this->visible, strlen($this->emitted));
            if ($pending === '') break;
            $flushBytes = 0;
            if ($this->chunkCount === self::MAX_CHUNKS - 1) {
                if ($final) $flushBytes = strlen($pending);
            } elseif (preg_match_all('/[.!?](?:[\"\'\)\]]{0,2})(?:\s+|$)/u',$pending,$matches,PREG_OFFSET_CAPTURE)) {
                foreach($matches[0] as$match){
                    $candidate=$match[1]+strlen($match[0]);
                    if($candidate>=self::MIN_CHUNK_BYTES){$flushBytes=$candidate;break;}
                }
            }
            // Keep utterance boundaries even when a network chunk contains several short texts.
            if ($this->chunkCount < self::MAX_CHUNKS - 1) foreach ($this->spans as $span) {
                $boundary = $span['start'] - strlen($this->emitted);
                if ($boundary > 0 && ($flushBytes === 0 || $boundary < $flushBytes)) { $flushBytes = $boundary; break; }
            }
            if ($flushBytes === 0 && $final) $flushBytes = strlen($pending);
            if ($flushBytes === 0) break;
            $chunk = substr($pending, 0, $flushBytes);
            if (!mb_check_encoding($chunk, 'UTF-8')) break;
            $moods = [];
            foreach ($this->spans as $span) if ($span['end'] > strlen($this->emitted) && $span['start'] < strlen($this->emitted) + $flushBytes)
                $moods[] = $span['mood'];
            $mood = $moods !== [] && count(array_unique($moods, SORT_REGULAR)) === 1 ? $moods[0] : null;
            $tones = [];
            foreach ($this->spans as $span) if ($span['end'] > strlen($this->emitted) && $span['start'] < strlen($this->emitted) + $flushBytes)
                $tones[] = $span['tones'];
            $tone = $tones !== [] && count(array_unique($tones, SORT_REGULAR)) === 1 ? $tones[0] : null;
            $this->emitted .= $chunk;
            ++$this->chunkCount;
            $chunk=preg_replace('/\s+/u',' ',trim($chunk))??trim($chunk);
            if($chunk!=='') { $chunks[]=$chunk; $this->chunkMoods[]=$mood; $this->chunkTones[]=$tone; }
        }
        return $chunks;
    }

    private function extractVisibleText(string $json): string
    {
        $visible = '';
        $this->spans = [];
        $offset = 0;
        while (preg_match('/(?<!\\\\)"text"\s*:\s*"/u', $json, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1] + strlen($match[0][0]);
            $index = $start;
            $escaped = false;
            while ($index < strlen($json)) {
                $character = $json[$index];
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    break;
                }
                ++$index;
            }
            $raw = substr($json, $start, $index - $start);
            while ($raw !== '' && preg_match('/\\\\(?:u[0-9a-fA-F]{0,3})?$/D', $raw) === 1) {
                $raw = substr($raw, 0, -1);
            }
            try {
                $decoded = json_decode('"' . $raw . '"', true, 8, JSON_THROW_ON_ERROR);
                if (is_string($decoded)) {
                    $prefix = substr($json, $offset, $match[0][1] - $offset);
                    $mood = null; $tones = null;
                    if (preg_match('/\{\s*(?:"tones"\s*:\s*(\{[^{}]*\})\s*,\s*)?(?:"mood"\s*:\s*"([a-zA-Z -]{0,64})"\s*,\s*)?$/D', $prefix, $metadata) === 1) {
                        if (($metadata[1] ?? '') !== '') $tones = ZonosGradioSpeechProvider::validateTones(json_decode($metadata[1],true,4,JSON_THROW_ON_ERROR));
                        if (isset($metadata[2])) $mood = trim($metadata[2]);
                    }
                    $startOffset = strlen($visible) + ($visible === '' ? 0 : 1);
                    $visible .= ($visible === '' ? '' : "\n") . $decoded;
                    $this->spans[] = ['start'=>$startOffset, 'end'=>strlen($visible), 'mood'=>$mood, 'tones'=>$tones];
                }
            } catch (\JsonException) {
                // Wait for a later chunk that completes the JSON escape or string.
            }
            if ($index >= strlen($json)) break;
            $offset = $index + 1;
        }
        return $visible;
    }
}
