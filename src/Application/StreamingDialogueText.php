<?php

declare(strict_types=1);

namespace LORKHANserver\Application;

/** Extracts safe visible text growth from a streamed structured response. */
final class StreamingDialogueText
{
    private const MAX_CHUNKS = DialoguePlanner::MAX_UTTERANCES;
    private const MIN_CHUNK_BYTES = 20;

    private string $content = '';
    private string $visible = '';
    private string $emitted = '';
    private int $chunkCount = 0;

    /** @return list<string> */
    public function push(string $contentDelta, bool $final = false): array
    {
        $this->content .= $contentDelta;
        $next = $this->extractVisibleText($this->content);
        if (str_starts_with($next, $this->visible)) {
            $this->visible = $next;
        }

        $chunks = [];
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
            if ($flushBytes === 0 && $final) $flushBytes = strlen($pending);
            if ($flushBytes === 0) break;
            $chunk = substr($pending, 0, $flushBytes);
            if (!mb_check_encoding($chunk, 'UTF-8')) break;
            $this->emitted .= $chunk;
            ++$this->chunkCount;
            $chunk=preg_replace('/\s+/u',' ',trim($chunk))??trim($chunk);
            if($chunk!=='')$chunks[]=$chunk;
        }
        return $chunks;
    }

    private function extractVisibleText(string $json): string
    {
        $visible = '';
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
                if (is_string($decoded)) $visible .= ($visible === '' ? '' : "\n") . $decoded;
            } catch (\JsonException) {
                // Wait for a later chunk that completes the JSON escape or string.
            }
            if ($index >= strlen($json)) break;
            $offset = $index + 1;
        }
        return $visible;
    }
}
