<?php
declare(strict_types=1);

return [
    'environment' => 'production',
    'base_path' => '/ALMSIVIserver/api/v1',
    'database_dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=almsivi',
    'database_user' => 'almsivi_runtime',
    // public/index.php reads ALMSIVI_DATABASE_PASSWORD, ALMSIVI_PAIRING_TOKEN_HASH, and
    // ALMSIVI_MANAGEMENT_SECRET_HASH from a restrictive service EnvironmentFile. Only hashes are accepted.
    'database_password' => '',
    'pairing_token_hash' => '',
    'management_base_path' => '/ALMSIVIserver/manage',
    'management_secret_hash' => '',
    'browser_session_ttl_seconds' => 3600,
    'max_json_bytes' => 2 * 1024 * 1024,
    'max_context_bytes' => 128 * 1024,
    'events_page_size' => 100,
    'event_replay_limit' => 256,
    'events_max_wait_seconds' => 15,
    'rate_limit_requests' => 120,
    'rate_limit_window_seconds' => 60,
    // CHIM-compatible OpenRouter transport. Save ALMSIVI_LLM_API_KEY through API Keys or the service environment.
    'provider' => [
        'driver' => 'openai-compatible',
        'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
        'allowed_hosts' => ['openrouter.ai'],
        'model' => 'z-ai/glm-4.7',
        'api_key_env' => 'ALMSIVI_LLM_API_KEY',
        'timeout_ms' => 120_000,
        'disable_reasoning' => true,
    ],
    // DeepL endpoint selection is revisioned per installation; the API key remains in the credential store.
    'translation_timeout_ms' => 30_000,
    // OpenAI-compatible speech can be disabled, mocked for local plumbing tests, or sent to a vetted HTTPS host.
    'speech_provider' => [
        'driver' => 'mock',
        'timeout_ms' => 30_000,
        // 'endpoint' => 'https://api.openai.com/v1/audio/speech',
        // 'allowed_hosts' => ['api.openai.com'],
        // 'model' => 'gpt-4o-mini-tts',
        // 'voice' => 'alloy',
        // 'api_key_env' => 'ALMSIVI_TTS_API_KEY',
    ],
    // Push-to-talk recordings use this provider. The live adapter sends bounded PCM WAV multipart uploads.
    'stt_provider' => [
        'driver' => 'mock',
        'timeout_ms' => 30_000,
        // 'endpoint' => 'https://api.openai.com/v1/audio/transcriptions',
        // 'allowed_hosts' => ['api.openai.com'],
        // 'model' => 'gpt-4o-mini-transcribe',
        // 'api_key_env' => 'ALMSIVI_STT_API_KEY',
    ],
    // Must resolve outside public/. Production default: /var/lib/almsiviserver/media.
    'media_storage_path' => '/var/lib/almsiviserver/media',
    'voice_storage_path' => '/var/lib/almsiviserver/voices',
    'portrait_storage_path' => '/var/lib/almsiviserver/profile-portraits',
    'backup_storage_path' => '/var/lib/almsiviserver/backups',
    // Browser-managed provider credentials remain outside the web root and are never returned by the UI.
    'credential_storage_path' => '/var/lib/almsiviserver/credentials/provider-keys.json',
    'media_max_bytes' => 32 * 1024 * 1024,
    'media_quota_bytes' => 256 * 1024 * 1024,
    'worker' => [
        // The worker exits after bounded work/runtime and is restarted by its systemd timer.
        'lease_seconds' => 30,
        'batch_size' => 1,
        'max_jobs' => 100,
        'idle_exit_seconds' => 30,
        'max_runtime_seconds' => 300,
        // First-party handlers are source-controlled and registered by default; optionally narrow claims.
        'types' => null,
    ],
];
