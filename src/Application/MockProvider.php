<?php
declare(strict_types=1);

namespace ALMSIVIserver\Application;

final class MockProvider implements Provider
{
    public function __construct(private readonly string $prefix = '') {}

    public function complete(array $turn, CancellationToken $cancellation): array
    {
        $cancellation->throwIfCancellationRequested();
        $payload = is_array($turn['payload'] ?? null) ? $turn['payload'] : [];
        $input = trim((string) ($payload['input']['text'] ?? $turn['turn']['text'] ?? ''));
        $targetIdentity = is_array($payload['target'] ?? null) ? $payload['target'] : ($turn['turn']['target'] ?? []);
        $speakerIdentity = is_array($payload['speaker'] ?? null) ? $payload['speaker'] : ($turn['turn']['speaker'] ?? []);
        $audience = is_array($payload['audience'] ?? null) ? $payload['audience'] : ($turn['turn']['audience'] ?? []);
        $candidates = array_values(array_filter(array_merge([$targetIdentity], $audience), 'is_array'));
        $groupRequested = count($candidates) > 1 && preg_match('/\[(?:group|multi)\]/i', $input) === 1;
        $utteranceCount = $groupRequested ? min(4, count($candidates)) : 1;
        $utterances = [];
        for ($index = 0; $index < $utteranceCount; ++$index) {
            $identity = $candidates[$index];
            $name = (string) ($identity['display_name'] ?? 'Companion');
            $text = $this->prefix . ($input === '' ? 'I am listening.' : sprintf('%s heard: %s', $name, $input));
            $utterances[] = ['speaker' => $identity, 'addressee' => $speakerIdentity, 'text' => mb_substr($text, 0, 4096)];
        }
        $action = null;
        $capabilities = $turn['_negotiated_capabilities'] ?? [];
        if (in_array('action.inventory.inspect',$capabilities,true)&&preg_match('/\b(?:check|inspect) (?:your )?inventory\b/i',$input)===1){
            $action=['name'=>'inventory.inspect','tier'=>0,'actor'=>$targetIdentity,'target'=>$speakerIdentity,'parameters'=>[]];
        } elseif (in_array('action.inspect.report',$capabilities,true)&&preg_match('/\binspect\b/i',$input)===1){
            $action=['name'=>'inspect.report','tier'=>0,'actor'=>$targetIdentity,'target'=>$speakerIdentity,'parameters'=>[]];
        } elseif (in_array('action.ai.approach',$capabilities,true)&&preg_match('/\b(?:come closer|approach me|come here)\b/i',$input)===1){
            $action=['name'=>'ai.approach','tier'=>1,'actor'=>$targetIdentity,'target'=>$speakerIdentity,'parameters'=>[]];
        } elseif (in_array('action.ai.wait',$capabilities,true)&&preg_match('/\b(?:wait here|stay here)\b/i',$input)===1){
            $action=['name'=>'ai.wait','tier'=>1,'actor'=>$targetIdentity,'target'=>$speakerIdentity,
                'parameters'=>['duration_seconds'=>3600]];
        } elseif (in_array('action.combat.stop',$capabilities,true)&&preg_match('/\b(?:stop fighting|stop combat|stand down)\b/i',$input)===1){
            $action=['name'=>'combat.stop','tier'=>1,'actor'=>$targetIdentity,'target'=>$speakerIdentity,'parameters'=>[]];
        } elseif (in_array('action.combat.start',$capabilities,true)&&preg_match('/\b(?:attack|fight)\b/i',$input)===1){
            $action=['name'=>'combat.start','tier'=>2,'actor'=>$targetIdentity,'target'=>$speakerIdentity,'parameters'=>[]];
        } elseif (in_array('action.ai.wander',$capabilities,true)&&preg_match('/\b(?:wander|roam|relax)\b/i',$input)===1){
            $action=['name'=>'ai.wander','tier'=>1,'actor'=>$targetIdentity,'target'=>$speakerIdentity,
                'parameters'=>['distance'=>512,'duration_seconds'=>3600]];
        } elseif (in_array('action.ai.stop',$capabilities,true)&&preg_match('/\b(?:stop following|stop moving)\b/i',$input)===1){
            $action=['name'=>'ai.stop','tier'=>1,'actor'=>$targetIdentity,'target'=>$speakerIdentity,'parameters'=>[]];
        } elseif (in_array('action.item.equip',$capabilities,true)
            && preg_match('/\[equip:([a-z0-9_. -]{1,128}):(helmet|cuirass|greaves|left_pauldron|right_pauldron|left_gauntlet|right_gauntlet|boots|shirt|pants|skirt|robe|left_ring|right_ring|amulet|belt|carried_right|carried_left|ammunition)\]/i',$input,$match)===1){
            $action=['name'=>'item.equip','tier'=>2,'actor'=>$targetIdentity,'target'=>$speakerIdentity,
                'parameters'=>['record_id'=>$match[1],'slot'=>strtolower($match[2])]];
        } elseif (in_array('action.item.unequip',$capabilities,true)
            && preg_match('/\[unequip:(helmet|cuirass|greaves|left_pauldron|right_pauldron|left_gauntlet|right_gauntlet|boots|shirt|pants|skirt|robe|left_ring|right_ring|amulet|belt|carried_right|carried_left|ammunition)\]/i',$input,$match)===1){
            $action=['name'=>'item.unequip','tier'=>2,'actor'=>$targetIdentity,'target'=>$speakerIdentity,
                'parameters'=>['slot'=>strtolower($match[1])]];
        } elseif (in_array('action.item.use',$capabilities,true)
            && preg_match('/\[use:([a-z0-9_. -]{1,128})\]/i',$input,$match)===1){
            $action=['name'=>'item.use','tier'=>2,'actor'=>$targetIdentity,'target'=>$speakerIdentity,
                'parameters'=>['record_id'=>$match[1]]];
        } elseif (in_array('action.animation.play',$capabilities,true)&&preg_match('/\b(?:gesture|animate|wave)\b/i',$input)===1){
            $action=['name'=>'animation.play','tier'=>1,'actor'=>$targetIdentity,'target'=>$speakerIdentity,'parameters'=>['group'=>'idle2']];
        } elseif (in_array('action.ai.follow', $capabilities, true) && preg_match('/\bfollow\b/i', $input) === 1) {
            $action = [
                'name' => 'ai.follow',
                'tier' => 1,
                'actor' => $targetIdentity,
                'target' => $speakerIdentity,
                'parameters' => ['distance' => 192],
            ];
        }
        $cancellation->throwIfCancellationRequested();
        return ['utterances' => $utterances, 'action' => $action];
    }
}
