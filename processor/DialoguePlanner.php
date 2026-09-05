<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use DomainException;

final class DialoguePlanner
{
    public const MAX_UTTERANCES = 32;
    private const MAX_TOTAL_BYTES = 32_768;

    /** @param array<string,mixed> $turn @param array<string,mixed> $providerResult @return list<array<string,mixed>> */
    public function plan(array $turn, array $providerResult): array
    {
        $payload = $turn['payload'] ?? [];
        if (!is_array($payload) || array_is_list($payload)) throw new DomainException('provider_invalid_output');
        $player = $payload['speaker'] ?? null;
        $target = $payload['target'] ?? null;
        $audience = $payload['audience'] ?? [];
        if (!is_array($player) || !is_array($target) || !is_array($audience)) throw new DomainException('provider_invalid_output');

        $eligible = [];
        foreach (array_merge([$target], $audience) as $identity) {
            if (!is_array($identity) || array_is_list($identity)) throw new DomainException('provider_invalid_output');
            $eligible[$this->identityKey($identity)] ??= $identity;
        }
        $narratorEnabled=($turn['_narrator_profile']['content']['enabled']??false)===true;
        $narrator=$turn['_narrator_profile']['actor_identity']??null;
        if($narratorEnabled&&is_array($narrator)&&!array_is_list($narrator))$eligible[$this->identityKey($narrator)]=$narrator;
        if ($eligible === []) throw new DomainException('provider_invalid_output');

        $raw = $providerResult['utterances'] ?? null;
        if ($raw === null && isset($providerResult['text']) && is_string($providerResult['text'])) {
            $raw = [['text' => $providerResult['text']]];
        }
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_UTTERANCES) {
            throw new DomainException('provider_invalid_output');
        }

        $orderedSpeakers = array_values($eligible);
        $utterances = [];
        $totalBytes = 0;
        foreach ($raw as $index => $candidate) {
            if (!is_array($candidate) || array_is_list($candidate)) throw new DomainException('provider_invalid_output');
            $speaker = $candidate['speaker'] ?? $orderedSpeakers[$index % count($orderedSpeakers)];
            $rechat=$payload['context']['rechat']??null;
            $strictRechat=is_array($rechat)&&!array_is_list($rechat)&&($rechat['strict_targeting']??false)===true;
            $previousSpeaker=$strictRechat?($rechat['speaker']??null):null;
            $addressee = $candidate['addressee'] ?? ($strictRechat&&is_array($previousSpeaker)?$previousSpeaker:$player);
            if (!is_array($speaker) || !is_array($addressee) || !isset($eligible[$this->identityKey($speaker)])) {
                throw new DomainException('provider_speaker_not_allowed');
            }
            $allowedAddressees = $eligible + [$this->identityKey($player) => $player];
            if($strictRechat&&is_array($previousSpeaker)&&!array_is_list($previousSpeaker)) {
                $allowedAddressees[$this->identityKey($previousSpeaker)]=$previousSpeaker;
            }
            if (!isset($allowedAddressees[$this->identityKey($addressee)])) {
                throw new DomainException('provider_addressee_not_allowed');
            }
            if($strictRechat&&(!$this->sameIdentity($addressee,$previousSpeaker))) {
                throw new DomainException('provider_addressee_not_allowed');
            }
            $text = $candidate['text'] ?? null;
            if(is_string($text))$text=trim($text);
            if (!is_string($text) || $text === '' || !mb_check_encoding($text, 'UTF-8')
                || mb_strlen($text, 'UTF-8') > 4096 || strlen($text) > 16_384) {
                throw new DomainException('provider_invalid_output');
            }
            $totalBytes += strlen($text);
            if ($totalBytes > self::MAX_TOTAL_BYTES) throw new DomainException('provider_invalid_output');
            $variants=[];
            foreach(['_history_text','_subtitle','_tts_text']as$field){
                $value=$candidate[$field]??$text;if(is_string($value))$value=trim($value);
                if(!is_string($value)||$value===''||!mb_check_encoding($value,'UTF-8')
                    ||mb_strlen($value,'UTF-8')>4096||strlen($value)>16_384)
                    throw new DomainException('provider_invalid_output');
                $variants[$field]=$value;
            }
            $utterances[] = ['speaker' => $speaker, 'addressee' => $addressee,
                'audience' => array_values($eligible), 'text' => $text,
                'speech_enabled'=>($candidate['speech_enabled']??true)!==false,
                'index' => $index + 1, 'count' => count($raw)]+$variants;
        }
        return $utterances;
    }

    /** @param array<string,mixed> $identity */
    public function identityKey(array $identity): string
    {
        $record = strtolower((string) ($identity['record_id'] ?? ''));
        $content = strtolower((string) ($identity['content_file'] ?? ''));
        $refnum = $identity['refnum'] ?? [];
        $cell = $identity['cell'] ?? [];
        if ($record === '' || $content === '' || !is_array($refnum) || !is_array($cell)) {
            throw new DomainException('provider_invalid_identity');
        }
        return hash('sha256', json_encode([$identity['kind'] ?? null, $record, $content,
            $refnum['index'] ?? null, $refnum['content_file'] ?? null, $cell], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function sameIdentity(mixed $left,mixed $right):bool
    {
        return is_array($left)&&!array_is_list($left)&&is_array($right)&&!array_is_list($right)
            &&$this->identityKey($left)===$this->identityKey($right);
    }
}
