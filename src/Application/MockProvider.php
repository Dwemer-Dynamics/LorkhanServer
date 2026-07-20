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
        if (in_array('action.inspect.report',$capabilities,true)&&preg_match('/\binspect\b/i',$input)===1){
            $action=['name'=>'inspect.report','tier'=>0,'actor'=>$targetIdentity,'target'=>$speakerIdentity,'parameters'=>[]];
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
