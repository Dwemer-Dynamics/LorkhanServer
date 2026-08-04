<?php

declare(strict_types=1);

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'STT Connector';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page excluded-connector-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

$featureId = 'config.stt';
$connectorKind = 'stt';
$heading = 'STT Connector';
$subtitle = 'Speech-to-Text Setup Options.';
$summary = 'This page edits the single global STT connector. Switching services updates the active runtime provider instead of creating extra connector records.';
$serviceHelp = 'Choose the speech-to-text backend ALMSIVIserver would load globally.';
$nameHelp = 'This label is kept for migration and internal reference, even though only one STT connector would be used globally.';
$testingNote = 'Testing saves the current connector first so the modal uses the latest settings.';
$activeDriver = 'whisper';
$providers = [
    'Recommended' => [
        ['deepgram', 'Deepgram', 'DEEPGRAM', "Deepgram's Whisper Speech-to-Text"],
        ['parakeet', 'Parakeet', 'PARAKEET', 'parakeet-api-server'],
    ],
    'Other Services' => [
        ['whisper', 'Whisper', 'WHISPER', "OpenAI's Whisper"],
        ['localwhisper', 'Local Whisper', 'LOCALWHISPER', 'Local Whisper (Installed in DwemerDistro)'],
        ['gemini', 'Gemini', 'GEMINI', 'Google Gemini STT + Emotion Detection'],
        ['azure', 'Azure', 'AZURE', 'Azure Speech-to-Text'],
        ['inworld', 'Inworld', 'INWORLD', 'Inworld STT'],
    ],
    'System' => [
        ['none', 'Disabled', 'SYSTEM', 'Disabled'],
    ],
];
$fields = [
    ['text', 'Name', 'Global STT Connector', $nameHelp],
    ['select', 'Service', 'Whisper', $serviceHelp, ['Deepgram', 'Parakeet', 'Whisper', 'Local Whisper', 'Gemini', 'Azure', 'Inworld', 'Disabled']],
    ['select', 'API Badge', '-- None --', 'Cloud STT services require an API key from the API Keys page.', ['-- None --', 'OpenAI', 'Azure', 'Deepgram', 'Google', 'Inworld']],
];
$settingsTitle = "OpenAI's Whisper Settings";
$settingsFields = [
    ['text', 'Lang', 'en', 'Language to detect for STT.'],
    ['select', 'Translate', 'Disabled', 'Will try to translate to english.', ['Enabled', 'Disabled']],
];
$toolbar = ['Save', 'Test', 'Google Free STT'];

$additionalStylesheets = ['herika-excluded-connectors.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-excluded-connectors.css')];
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
require __DIR__ . '/tmpl/excluded_connector.php';
include dirname(__DIR__) . '/tmpl/footer.html';
