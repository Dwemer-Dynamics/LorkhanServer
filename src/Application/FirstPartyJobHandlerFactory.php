<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Infrastructure\FirstPartyJobRepository;
use ALMSIVIserver\Infrastructure\MediaStore;
use PDO;

/** Fixed first-party handler composition; does not load executable configuration. */
final class FirstPartyJobHandlerFactory
{
    /** @return list<JobHandler> */
    public static function handlers(PDO $db, MediaStore $mediaStore, ?DeterministicClock $clock = null,
        ?Provider $provider = null, ?SpeechProvider $speechProvider = null, int $providerTimeoutMs = 1000,
        array $providerConfig = [], ?SpeechToTextProvider $sttProvider = null): array
    {
        $clock ??= new DeterministicClock();
        $repository = new FirstPartyJobRepository($db);
        $products = new \ALMSIVIserver\Infrastructure\ProductRepository($db);
        $handlers = [new ProfileGenerateJobHandler($products,ProviderFactory::profileGeneration($providerConfig),
            new \ALMSIVIserver\Infrastructure\ProviderAttemptRepository($db),(int)($providerConfig['provider']['timeout_ms']??30_000))];
        if ($provider !== null) {
            $handlers[] = new TurnProcessJobHandler(new \ALMSIVIserver\Infrastructure\Repository($db,256,
                new \ALMSIVIserver\Infrastructure\ActionCatalogRepository($db),new ActionPolicyValidator()), $provider,
                $mediaStore, new \ALMSIVIserver\Infrastructure\ProviderAttemptRepository($db), $providerTimeoutMs,$providerConfig);
        }
        $handlers[] = new SpeechSynthesizeJobHandler(new \ALMSIVIserver\Infrastructure\Repository($db),$speechProvider,
            $mediaStore,new \ALMSIVIserver\Infrastructure\ProviderAttemptRepository($db),$products,$providerConfig,
            (int)($providerConfig['provider']['timeout_ms']??120_000));
        $handlers[] = new SttProcessJobHandler(new \ALMSIVIserver\Infrastructure\Repository($db),$sttProvider,$mediaStore,
            new \ALMSIVIserver\Infrastructure\ProviderAttemptRepository($db),$products,$providerConfig);
        return array_merge($handlers, [
            new MemoryDeriveJobHandler($repository, $clock),
            new MemoryConsolidateJobHandler($repository, $clock),
            new MemoryRebuildJobHandler($repository, $clock),
            new NarrativeJobHandler($repository, $clock),
            new MediaCleanupJobHandler($repository, $clock, $mediaStore),
            new RetentionJobHandler($repository, $clock),
            new ProviderReconciliationJobHandler($repository, $clock),
            new DialogueExpiryJobHandler(new \ALMSIVIserver\Infrastructure\Repository($db)),
        ]);
    }

    public static function registry(PDO $db, MediaStore $mediaStore, ?DeterministicClock $clock = null,
        ?Provider $provider = null, ?SpeechProvider $speechProvider = null, int $providerTimeoutMs = 1000,
        array $providerConfig = [], ?SpeechToTextProvider $sttProvider = null): JobHandlerRegistry
    {
        return new JobHandlerRegistry(self::handlers($db, $mediaStore, $clock, $provider, $speechProvider, $providerTimeoutMs,$providerConfig,$sttProvider));
    }

    /** @return list<string> */
    public static function jobTypes(): array
    {
        return [
            TurnProcessJobHandler::TYPE,
            SpeechSynthesizeJobHandler::TYPE,
            SttProcessJobHandler::TYPE,
            MemoryDeriveJobHandler::TYPE,
            MemoryConsolidateJobHandler::TYPE,
            MemoryRebuildJobHandler::TYPE,
            NarrativeJobHandler::SUMMARY_TYPE,
            NarrativeJobHandler::DIARY_TYPE,
            MediaCleanupJobHandler::TYPE,
            RetentionJobHandler::TYPE,
            ProviderReconciliationJobHandler::TYPE,
            DialogueExpiryJobHandler::TYPE,
            ProfileGenerateJobHandler::TYPE,
        ];
    }
}
