<?php

declare(strict_types=1);

namespace LORKHANserver\Application;

use LORKHANserver\Infrastructure\ProductRepository;
use LORKHANserver\Infrastructure\Repository;
use DomainException;

/** Resolve Herika-compatible rechat mode, budget, and next responder on the server. */
final class RechatCoordinator
{
    public function __construct(
        private readonly Repository $repository,
        private readonly ProductRepository $products,
    ) {}

    /** @param array<string,mixed> $message @return array<string,mixed> */
    public function resolve(array $message): array
    {
        if (($message['payload']['ui_source'] ?? null) !== 'lorkhan_rechat') return $message;

        $context = $message['payload']['context'] ?? null;
        $rechat = is_array($context) && !array_is_list($context) ? ($context['rechat'] ?? null) : null;
        if (!is_array($rechat) || array_is_list($rechat)) throw new DomainException('invalid_rechat_context');

        $chainId = (string) ($rechat['chain_id'] ?? '');
        $depth = $rechat['rechat_depth'] ?? $rechat['depth'] ?? null;
        $previousSpeaker = $rechat['speaker'] ?? $rechat['previous_speaker'] ?? null;
        $listener = $rechat['listener_hint'] ?? null;
        $targetHint = $rechat['rechat_target_hint'] ?? null;
        $originTurnId = (string) ($rechat['origin_turn_id'] ?? '');
        $originLine = trim((string) ($rechat['origin_line'] ?? $message['payload']['input']['text'] ?? ''));
        if (!is_int($depth) || $depth < 1 || $depth > 32 || !$this->identity($previousSpeaker)
            || $chainId === '' || !$this->uuid($originTurnId) || $originLine === '') {
            throw new DomainException('invalid_rechat_context');
        }
        $participantIdentities = $this->participantIdentities($message, $previousSpeaker, $listener, $targetHint);
        $participantStates = $this->participantStates($rechat['participant_states'] ?? null, $participantIdentities);
        if ($participantStates !== null) {
            $speakerState = $participantStates[$this->identityKey($previousSpeaker)] ?? null;
            if ($speakerState === null) throw new DomainException('invalid_rechat_context');
            if ($this->stateBlocked($speakerState, false)) throw new DomainException('rechat_no_responder');
        }

        $existing = $this->repository->rechatChain(
            $chainId,
            (string) $message['session_id'],
            (int) $message['generation'],
        );
        $global = $this->products->globalSettingsForInstallation((string) $message['installation_id']);
        $globalSettings = is_array($global['content'] ?? null) ? $global['content'] : EffectiveSettingsResolver::defaults();
        $behavior = is_array($globalSettings['behavior'] ?? null) ? $globalSettings['behavior'] : [];
        $configuredMode = (string) ($existing['configured_mode'] ?? $behavior['rechat_mode'] ?? 'random');
        if (!in_array($configuredMode, ['tight', 'conversational', 'group', 'random'], true)) {
            throw new DomainException('invalid_rechat_context');
        }
        $mode = (string) ($existing['mode'] ?? $this->resolvedMode($configuredMode, $chainId));
        if ($existing === null && ($behavior['open_rechat'] ?? true) !== true) $mode = 'tight';

        $participants = $this->participants($message, $previousSpeaker, $listener, $targetHint, $participantStates);
        $selected = $this->selectResponder($mode, $participants, $listener, $targetHint, $chainId, $depth);
        if ($selected === null) throw new DomainException('rechat_no_responder');

        $effective = $this->products->effectiveSettingsForActor(
            (string) $message['installation_id'],
            (string) $message['playthrough_id'],
            $selected,
        );
        $selectedBehavior = is_array($effective['settings']['behavior'] ?? null) ? $effective['settings']['behavior'] : $behavior;
        if (($selectedBehavior['rechat'] ?? false) !== true) throw new DomainException('rechat_no_responder');
        $cooldown = max(0, min(300, (int) ($selectedBehavior['end_conversation_cooldown_seconds'] ?? 60)));
        if ($existing === null && $this->repository->rechatCooldownActive(
            (string) $message['installation_id'],
            (string) $message['playthrough_id'],
            $cooldown,
        )) {
            throw new DomainException('rechat_cooldown');
        }
        $maxRounds = max(1, min(20, (int) ($existing['max_depth'] ?? $selectedBehavior['rechat_max_depth'] ?? 2)));
        $probability = max(0, min(100, (int) ($existing['probability_percent'] ?? $selectedBehavior['rechat_probability_percent'] ?? 50)));
        $roundBudget = (int) ($existing['round_budget'] ?? $this->preRollBudget($chainId, $maxRounds, $probability));
        if ($roundBudget < 1 || $depth > $roundBudget) throw new DomainException('rechat_complete');

        $strict = (bool) ($existing['strict_targeting'] ?? $selectedBehavior['rechat_strict_targeting'] ?? false);
        $message['payload']['target'] = $selected;
        $resolvedRechat = [
            'speaker' => $previousSpeaker,
            'listener_hint' => $this->identity($listener) ? $listener : null,
            'rechat_target_hint' => $this->identity($targetHint) ? $targetHint : null,
            'origin_line' => $originLine,
            'rechat_depth' => $depth,
            'chain_id' => $chainId,
            'origin_turn_id' => $originTurnId,
            'configured_mode' => $configuredMode,
            'mode' => $mode,
            'round_budget' => $roundBudget,
            'max_depth' => $maxRounds,
            'probability_percent' => $probability,
            'strict_targeting' => $strict,
            'selected_responder' => $selected,
            'participants' => $participants,
            'is_final_round' => $depth >= $roundBudget,
        ];
        if ($participantStates !== null) {
            $resolvedRechat['participant_states'] = [];
            foreach ($participantStates as $key => $state) {
                $resolvedRechat['participant_states'][] = ['identity' => $participantIdentities[$key], 'state' => $state];
            }
        }
        $message['payload']['context']['rechat'] = $resolvedRechat;
        unset($message['payload']['action_request']);
        return $message;
    }

    /** @return list<array<string,mixed>> */
    private function participants(array $message, mixed $previousSpeaker, mixed $listener, mixed $targetHint,
        ?array $participantStates): array
    {
        $values = [];
        foreach (array_merge(
            is_array($message['payload']['audience'] ?? null) ? $message['payload']['audience'] : [],
            [$message['payload']['target'] ?? null, $listener, $targetHint]
        ) as $identity) {
            if (!$this->identity($identity) || in_array(strtolower((string) $identity['kind']), ['player', 'narrator'], true)
                || $this->sameIdentity($identity, $previousSpeaker)) continue;
            if ($participantStates !== null) {
                $state = $participantStates[$this->identityKey($identity)] ?? null;
                $directlyAddressed = $this->sameIdentity($identity, $listener)
                    || $this->sameIdentity($identity, $targetHint);
                if ($state === null || $this->stateBlocked($state, $directlyAddressed)) continue;
            }
            $values[$this->identityKey($identity)] ??= $identity;
        }
        return array_values($values);
    }

    private function selectResponder(string $mode, array $participants, mixed $listener, mixed $targetHint,
        string $chainId, int $depth): ?array
    {
        $byKey = [];
        foreach ($participants as $participant) $byKey[$this->identityKey($participant)] = $participant;
        $eligibleHint = fn(mixed $value): ?array => $this->identity($value)
            ? ($byKey[$this->identityKey($value)] ?? null) : null;
        $listenerActor = $eligibleHint($listener);
        $targetActor = $eligibleHint($targetHint);

        $ordered = match ($mode) {
            'tight' => array_values(array_filter([$listenerActor])),
            'conversational' => array_values(array_filter(array_merge([$targetActor, $listenerActor], $participants))),
            'group' => array_values(array_filter(array_merge(
                array_filter($participants, fn(array $candidate): bool => !$this->sameIdentity($candidate, $listenerActor)
                    && !$this->sameIdentity($candidate, $targetActor)),
                [$targetActor, $listenerActor],
            ))),
            default => throw new DomainException('invalid_rechat_context'),
        };
        $unique = [];
        foreach ($ordered as $candidate) $unique[$this->identityKey($candidate)] ??= $candidate;
        if ($unique === []) return null;
        $values = array_values($unique);
        return $values[$this->stableNumber($chainId . ':responder:' . $depth, count($values))];
    }

    /** @return array<string,array<string,mixed>> */
    private function participantIdentities(array $message, mixed $previousSpeaker, mixed $listener, mixed $targetHint): array
    {
        $identities = [];
        foreach (array_merge(
            [$previousSpeaker],
            is_array($message['payload']['audience'] ?? null) ? $message['payload']['audience'] : [],
            [$message['payload']['target'] ?? null, $listener, $targetHint]
        ) as $identity) {
            if ($this->identity($identity)) $identities[$this->identityKey($identity)] ??= $identity;
        }
        return $identities;
    }

    /** @param array<string,array<string,mixed>> $participantIdentities @return null|array<string,string> */
    private function participantStates(mixed $value, array $participantIdentities): ?array
    {
        if ($value === null) return null;
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 13) {
            throw new DomainException('invalid_rechat_context');
        }
        $states = [];
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row) || count($row) !== 2
                || !array_key_exists('identity', $row) || !array_key_exists('state', $row)
                || !$this->identity($row['identity'] ?? null)
                || in_array(strtolower((string) $row['identity']['kind']), ['player', 'narrator'], true)
                || !in_array($row['state'] ?? null, ['active', 'busy', 'sleeping', 'unconscious', 'inactive'], true)) {
                throw new DomainException('invalid_rechat_context');
            }
            $key = $this->identityKey($row['identity']);
            if (!isset($participantIdentities[$key]) || isset($states[$key])) {
                throw new DomainException('invalid_rechat_context');
            }
            $states[$key] = $row['state'];
        }
        return $states;
    }

    private function stateBlocked(string $state, bool $directlyAddressed): bool
    {
        return in_array($state, ['busy', 'unconscious', 'inactive'], true)
            || ($state === 'sleeping' && !$directlyAddressed);
    }

    private function resolvedMode(string $configuredMode, string $chainId): string
    {
        if ($configuredMode !== 'random') return $configuredMode;
        return ['tight', 'conversational', 'group'][$this->stableNumber($chainId . ':mode', 3)];
    }

    private function preRollBudget(string $chainId, int $maxRounds, int $probability): int
    {
        $budget = 0;
        for ($round = 1; $round <= $maxRounds; $round++) {
            if ($this->stableNumber($chainId . ':round:' . $round, 100) + 1 > $probability) break;
            $budget++;
        }
        return $budget;
    }

    private function stableNumber(string $seed, int $modulus): int
    {
        return (int) (hexdec(substr(hash('sha256', $seed), 0, 7)) % $modulus);
    }

    private function identity(mixed $value): bool
    {
        return is_array($value) && !array_is_list($value)
            && is_string($value['kind'] ?? null) && is_string($value['record_id'] ?? null)
            && is_string($value['content_file'] ?? null);
    }

    private function sameIdentity(mixed $left, mixed $right): bool
    {
        return $this->identity($left) && $this->identity($right)
            && $this->identityKey($left) === $this->identityKey($right);
    }

    private function identityKey(array $identity): string
    {
        return hash('sha256', json_encode([
            strtolower((string) ($identity['kind'] ?? '')),
            strtolower((string) ($identity['record_id'] ?? '')),
            strtolower((string) ($identity['content_file'] ?? '')),
            $identity['refnum'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) === 1;
    }
}
