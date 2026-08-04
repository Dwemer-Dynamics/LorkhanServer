<?php

declare(strict_types=1);

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'ITT Connector';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page excluded-connector-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

$featureId = 'config.itt';
$connectorKind = 'itt';
$heading = 'ITT Connector';
$subtitle = 'Image-to-Text Setup Options.';
$summary = 'This page edits the single global ITT connector. It controls which vision-capable backend ALMSIVI uses for screenshots and image analysis.';
$serviceHelp = 'Choose the image-to-text backend ALMSIVIserver would load globally.';
$nameHelp = 'This label is retained for migration and future profile wiring, even though only one ITT connector would be used globally.';
$testingNote = 'Testing saves the current connector first so the modal uses the latest settings.';
$activeDriver = 'openrouter';
$providers = [
    'Recommended' => [
        ['openrouter', 'OpenRouter', 'openrouter', 'OpenRouter API'],
    ],
    'Local / Self-Hosted' => [
        ['custom', 'Custom', 'custom', 'Custom OpenAI-Compatible Service'],
        ['llamacpp', 'llama.cpp', 'llamacpp', 'LLama Server (Installed in DwemerDistro)'],
    ],
    'Other Services' => [
        ['openai', 'OpenAI', 'openai', 'OpenAI/OpenRouter'],
        ['google_openai', 'Google OpenAI', 'google_openai', 'Google OpenAI API'],
    ],
];
$fields = [
    ['text', 'Name', 'Global ITT Connector', $nameHelp],
    ['select', 'Service', 'OpenRouter', $serviceHelp, ['OpenRouter', 'Custom', 'llama.cpp', 'OpenAI', 'Google OpenAI']],
    ['url', 'URL', 'https://openrouter.ai/api/v1', 'Endpoint used for ITT providers with configurable HTTP URLs.'],
    ['select', 'API Badge', 'OpenRouter', 'Cloud ITT services require an API key from the API Keys page.', ['-- None --', 'OpenRouter', 'OpenAI', 'Google']],
];
$settingsTitle = 'OpenRouter API Settings';
$settingsFields = [
    ['text', 'Model', 'google/gemini-2.5-flash', 'Model to use'],
    ['number', 'Max Tokens', '4096', 'Maximum tokens to generate'],
    ['select', 'Detail', 'low', 'Low or high fidelity image understanding', ['low', 'high']],
    ['textarea', 'Vision Prompt', '', 'Prompt to send to the vision model.'],
    ['textarea', 'Prompt', '', 'Prompt for the AI NPC to follow when describing the scene.'],
];
$toolbar = ['Save', 'Test'];

$additionalStylesheets = ['herika-excluded-connectors.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-excluded-connectors.css')];
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
require __DIR__ . '/tmpl/excluded_connector.php';
include dirname(__DIR__) . '/tmpl/footer.html';
