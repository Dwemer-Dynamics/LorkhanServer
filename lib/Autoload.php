<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $yamlPrefix = 'Symfony\\Component\\Yaml\\';
    if (str_starts_with($class, $yamlPrefix)) {
        require_once __DIR__ . '/ThirdParty/SymfonyDeprecation/function.php';
        $path = __DIR__ . '/ThirdParty/SymfonyYaml/' . str_replace('\\', '/', substr($class, strlen($yamlPrefix))) . '.php';
        if (is_file($path)) require $path;
        return;
    }
    $prefix = 'LorkhanServer\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    // Resolve relocated feature classes without scanning directories on each request.
    static $features = [
        'Application\\CanonicalResponseNormalizer' => '/processor/CanonicalResponseNormalizer.php',
        'Application\\CloudSpeechConnectorProvider' => '/tts/CloudSpeechConnectorProvider.php',
        'Application\\CloudSpeechToTextConnectorProvider' => '/stt/CloudSpeechToTextConnectorProvider.php',
        'Application\\CloudVoiceLibrary' => '/tts/CloudVoiceLibrary.php',
        'Application\\ConnectorCatalog' => '/connector/ConnectorCatalog.php',
        'Application\\ParalinguisticSpeech' => '/tts/ParalinguisticSpeech.php',
        'Application\\DeepLTranslationProvider' => '/connector/DeepLTranslationProvider.php',
        'Application\\DialogueExpiryJobHandler' => '/service/DialogueExpiryJobHandler.php',
        'Application\\DialoguePlanner' => '/processor/DialoguePlanner.php',
        'Application\\DiaryGenerateJobHandler' => '/service/DiaryGenerateJobHandler.php',
        'Application\\EmbeddingProvider' => '/connector/EmbeddingProvider.php',
        'Application\\FirstPartyJobHandlerFactory' => '/service/FirstPartyJobHandlerFactory.php',
        'Application\\FirstPartyJobHandlers' => '/service/FirstPartyJobHandlers.php',
        'Application\\InlineNarrationRouter' => '/processor/InlineNarrationRouter.php',
        'Application\\InworldVoiceResolver' => '/tts/InworldVoiceResolver.php',
        'Application\\JobHandler' => '/service/JobHandler.php',
        'Application\\JobHandlerRegistry' => '/service/JobHandlerRegistry.php',
        'Application\\LlmConnector' => '/connector/LlmConnector.php',
        'Application\\LlmBodyParameters' => '/connector/LlmBodyParameters.php',
        'Application\\LocalSpeechConnectorProvider' => '/tts/LocalSpeechConnectorProvider.php',
        'Application\\LocalVoiceResolver' => '/tts/LocalVoiceResolver.php',
        'Application\\MemoryDeriveJobHandler' => '/service/MemoryDeriveJobHandler.php',
        'Application\\MemoryEmbedJobHandler' => '/service/MemoryEmbedJobHandler.php',
        'Application\\MemoryPromptSelection' => '/prompts/MemoryPromptSelection.php',
        'Application\\MemorySummaryJobHandler' => '/service/MemorySummaryJobHandler.php',
        'Application\\MiniMeEmbeddingProvider' => '/connector/MiniMeEmbeddingProvider.php',
        'Application\\MockOghmaTopicExtractor' => '/connector/MockOghmaTopicExtractor.php',
        'Application\\MockProfileGenerationProvider' => '/connector/MockProfileGenerationProvider.php',
        'Application\\MockProvider' => '/connector/MockProvider.php',
        'Application\\MockSpeechProvider' => '/tts/MockSpeechProvider.php',
        'Application\\MockSpeechToTextProvider' => '/stt/MockSpeechToTextProvider.php',
        'Application\\SttTestSample' => '/stt/SttTestSample.php',
        'Application\\NarrationTextPolicy' => '/prompts/NarrationTextPolicy.php',
        'Application\\NarratorEventPrompts' => '/prompts/NarratorEventPrompts.php',
        'Application\\OghmaTopicExtractor' => '/connector/OghmaTopicExtractor.php',
        'Application\\OpenAiCompatibleOghmaTopicExtractor' => '/connector/OpenAiCompatibleOghmaTopicExtractor.php',
        'Application\\OpenAiCompatibleProfileGenerationProvider' => '/connector/OpenAiCompatibleProfileGenerationProvider.php',
        'Application\\OpenAiCompatibleProvider' => '/connector/OpenAiCompatibleProvider.php',
        'Application\\OpenAiCompatibleSpeechProvider' => '/tts/OpenAiCompatibleSpeechProvider.php',
        'Application\\OpenAiCompatibleSpeechToTextProvider' => '/stt/OpenAiCompatibleSpeechToTextProvider.php',
        'Application\\PlayerMoodPolicy' => '/prompts/PlayerMoodPolicy.php',
        'Application\\PocketTtsSpeechProvider' => '/tts/PocketTtsSpeechProvider.php',
        'Application\\ProductService' => '/processor/ProductService.php',
        'Application\\ProfileGenerateJobHandler' => '/service/ProfileGenerateJobHandler.php',
        'Application\\ProfileGenerationProvider' => '/connector/ProfileGenerationProvider.php',
        'Application\\PromptAssembler' => '/prompts/PromptAssembler.php',
        'Application\\Provider' => '/connector/Provider.php',
        'Application\\ProviderFactory' => '/connector/ProviderFactory.php',
        'Application\\ReasoningOutputCleaner' => '/processor/ReasoningOutputCleaner.php',
        'Application\\RechatCoordinator' => '/processor/RechatCoordinator.php',
        'Application\\RelationshipBuildJobHandler' => '/service/RelationshipBuildJobHandler.php',
        'Application\\RelationshipConversionJobHandler' => '/service/RelationshipConversionJobHandler.php',
        'Application\\DatabaseCompactJobHandler' => '/service/DatabaseCompactJobHandler.php',
        'Application\\RelationshipEvaluateJobHandler' => '/service/RelationshipEvaluateJobHandler.php',
        'Application\\SpeechPreviewCatalog' => '/tts/SpeechPreviewCatalog.php',
        'Application\\SpeechProvider' => '/tts/SpeechProvider.php',
        'Application\\SpeechSynthesizeJobHandler' => '/service/SpeechSynthesizeJobHandler.php',
        'Application\\SpeechToTextProvider' => '/stt/SpeechToTextProvider.php',
        'Application\\StreamingDialogueText' => '/processor/StreamingDialogueText.php',
        'Application\\StreamingProvider' => '/connector/StreamingProvider.php',
        'Application\\SttProcessJobHandler' => '/service/SttProcessJobHandler.php',
        'Application\\TranslationProvider' => '/connector/TranslationProvider.php',
        'Application\\TurnProcessJobHandler' => '/service/TurnProcessJobHandler.php',
        'Application\\Worker' => '/service/Worker.php',
        'Application\\XttsCompatibleSpeechProvider' => '/tts/XttsCompatibleSpeechProvider.php',
        'Application\\XvaSynthSpeechProvider' => '/tts/XvaSynthSpeechProvider.php',
        'Application\\ZonosGradioSpeechProvider' => '/tts/ZonosGradioSpeechProvider.php',
    ];
    $path = isset($features[$relative])
        ? dirname(__DIR__) . $features[$relative]
        : __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
        return;
    }
    if (str_starts_with($relative, 'Application\\') && in_array(substr($relative, strlen('Application\\')), [
        'FirstPartyJobHandler', 'MemoryDeriveJobHandler', 'MemoryConsolidateJobHandler', 'MemoryRebuildJobHandler', 'NarrativeJobHandler',
        'MediaCleanupJobHandler', 'RetentionJobHandler', 'ProviderReconciliationJobHandler',
    ], true)) {
        require dirname(__DIR__) . '/service/FirstPartyJobHandlers.php';
    }
});
