<?php

declare(strict_types=1);

namespace LORKHANserver\Application;

use LORKHANserver\Infrastructure\FirstPartyJobRepository;
use LORKHANserver\Infrastructure\MediaStore;
use PDO;

/** Fixed first-party handler composition; does not load executable configuration. */
final class FirstPartyJobHandlerFactory
{
    /** @return list<JobHandler> */
    public static function handlers(PDO $db, MediaStore $mediaStore, ?DeterministicClock $clock = null,
        ?Provider $provider = null, ?SpeechProvider $speechProvider = null, int $providerTimeoutMs = 1000,
        array $providerConfig = [], ?SpeechToTextProvider $sttProvider = null,
        ?TranslationProvider $translationProvider = null): array
    {
        $clock ??= new DeterministicClock();
        $repository = new FirstPartyJobRepository($db);
        $products = new \LORKHANserver\Infrastructure\ProductRepository($db);
        $handlers = [new ProfileGenerateJobHandler($products,null,
            new \LORKHANserver\Infrastructure\ProviderAttemptRepository($db),(int)($providerConfig['provider']['timeout_ms']??30_000),$providerConfig)];
        if ($provider !== null) {
            $handlers[] = new TurnProcessJobHandler(new \LORKHANserver\Infrastructure\Repository($db,256,
                new \LORKHANserver\Infrastructure\ActionCatalogRepository($db),new ActionPolicyValidator()), $provider,
                $mediaStore, new \LORKHANserver\Infrastructure\ProviderAttemptRepository($db), $providerTimeoutMs,$providerConfig,$translationProvider);
        }
        $handlers[] = new SpeechSynthesizeJobHandler(new \LORKHANserver\Infrastructure\Repository($db),$speechProvider,
            $mediaStore,new \LORKHANserver\Infrastructure\ProviderAttemptRepository($db),$products,$providerConfig,
            (int)($providerConfig['provider']['timeout_ms']??120_000));
        $handlers[] = new SttProcessJobHandler(new \LORKHANserver\Infrastructure\Repository($db),$sttProvider,$mediaStore,
            new \LORKHANserver\Infrastructure\ProviderAttemptRepository($db),$products,$providerConfig);
        return array_merge($handlers, [
            new RelationshipBuildJobHandler(new \LORKHANserver\Infrastructure\RelationshipBuildRepository($db),$products,
                new \LORKHANserver\Infrastructure\ProviderAttemptRepository($db),$providerConfig),
            new RelationshipConversionJobHandler(new \LORKHANserver\Infrastructure\RelationshipConversionRepository($db),$products,
                new \LORKHANserver\Infrastructure\ProviderAttemptRepository($db),$providerConfig),
            new RelationshipEvaluateJobHandler(new \LORKHANserver\Infrastructure\RelationshipEvaluationRepository($db),$products,
                new \LORKHANserver\Infrastructure\ProviderAttemptRepository($db),$providerConfig),
            new DiaryGenerateJobHandler($repository,$products,new \LORKHANserver\Infrastructure\ProviderAttemptRepository($db),$providerConfig),
            new MemorySummaryJobHandler(new \LORKHANserver\Infrastructure\MemorySummaryRepository($db),$products,
                new \LORKHANserver\Infrastructure\ProviderAttemptRepository($db),$providerConfig),
            new MemoryEmbedJobHandler(new \LORKHANserver\Infrastructure\MemoryEmbeddingRepository($db),
                new \LORKHANserver\Infrastructure\ProviderAttemptRepository($db)),
            new MemoryDeriveJobHandler($repository, $clock),
            new MemoryConsolidateJobHandler($repository, $clock),
            new MemoryRebuildJobHandler($repository, $clock),
            new NarrativeJobHandler($repository, $clock),
            new MediaCleanupJobHandler($repository, $clock, $mediaStore),
            new RetentionJobHandler($repository, $clock),
            new ProviderReconciliationJobHandler($repository, $clock),
            new DialogueExpiryJobHandler(new \LORKHANserver\Infrastructure\Repository($db)),
        ]);
    }

    public static function registry(PDO $db, MediaStore $mediaStore, ?DeterministicClock $clock = null,
        ?Provider $provider = null, ?SpeechProvider $speechProvider = null, int $providerTimeoutMs = 1000,
        array $providerConfig = [], ?SpeechToTextProvider $sttProvider = null,
        ?TranslationProvider $translationProvider = null): JobHandlerRegistry
    {
        return new JobHandlerRegistry(self::handlers($db, $mediaStore, $clock, $provider, $speechProvider, $providerTimeoutMs,
            $providerConfig,$sttProvider,$translationProvider));
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
            MemorySummaryJobHandler::TYPE,
            MemoryEmbedJobHandler::TYPE,
            RelationshipEvaluateJobHandler::TYPE,
            RelationshipBuildJobHandler::TYPE,
            RelationshipConversionJobHandler::TYPE,
            MemoryRebuildJobHandler::TYPE,
            NarrativeJobHandler::SUMMARY_TYPE,
            NarrativeJobHandler::DIARY_TYPE,
            DiaryGenerateJobHandler::TYPE,
            MediaCleanupJobHandler::TYPE,
            RetentionJobHandler::TYPE,
            ProviderReconciliationJobHandler::TYPE,
            DialogueExpiryJobHandler::TYPE,
            ProfileGenerateJobHandler::TYPE,
        ];
    }
}
