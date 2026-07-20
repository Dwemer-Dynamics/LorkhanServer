<?php
declare(strict_types=1);

use ALMSIVIserver\Application\ActionPolicyValidator;
use ALMSIVIserver\Application\DeterministicClock;
use ALMSIVIserver\Application\FirstPartyJobHandlerFactory;
use ALMSIVIserver\Application\MockProvider;
use ALMSIVIserver\Application\MockSpeechProvider;
use ALMSIVIserver\Application\MockSpeechToTextProvider;
use ALMSIVIserver\Application\ProductService;
use ALMSIVIserver\Application\PromptAssembler;
use ALMSIVIserver\Application\Provider;
use ALMSIVIserver\Application\SpeechProvider;
use ALMSIVIserver\Application\Worker;
use ALMSIVIserver\Http\ManagementRouter;
use ALMSIVIserver\Http\Request;
use ALMSIVIserver\Http\Response;
use ALMSIVIserver\Http\Router;
use ALMSIVIserver\Infrastructure\ActionCatalogRepository;
use ALMSIVIserver\Infrastructure\Connection;
use ALMSIVIserver\Infrastructure\JobRepository;
use ALMSIVIserver\Infrastructure\ManagementRepository;
use ALMSIVIserver\Infrastructure\MediaStore;
use ALMSIVIserver\Infrastructure\ProductRepository;
use ALMSIVIserver\Infrastructure\ProviderAttemptRepository;
use ALMSIVIserver\Infrastructure\Repository;
use ALMSIVIserver\Protocol\Validator;
use ALMSIVIserver\Security\PairingToken;

require dirname(__DIR__) . '/src/Autoload.php';

$configFile = getenv('ALMSIVI_CONFIG') ?: dirname(__DIR__) . '/config/server.php';
try {
    if (!is_file($configFile)) throw new RuntimeException('Server configuration is unavailable.');
    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Server configuration is invalid.');
    $tokenHash = getenv('ALMSIVI_PAIRING_TOKEN_HASH') ?: (string) ($config['pairing_token_hash'] ?? '');
    if (preg_match('/^[0-9a-f]{64}$/D', $tokenHash) !== 1) throw new RuntimeException('Pairing token hash is not configured.');
    $config['database_password'] = getenv('ALMSIVI_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');
    $database = Connection::open($config);
    if (($config['provider']['driver'] ?? 'mock') !== 'mock') throw new RuntimeException('Only the mock provider is available in this foundation.');
    $provider = new MockProvider((string) ($config['provider']['mock_prefix'] ?? ''));
    if (isset($config['provider_factory'])) {
        if (($config['environment'] ?? 'production') !== 'test') throw new RuntimeException('Provider factory is test-only.');
        if (!is_callable($config['provider_factory'])) throw new RuntimeException('Provider factory is invalid.');
        $provider = ($config['provider_factory'])();
        if (!$provider instanceof Provider) throw new RuntimeException('Provider factory did not return a Provider.');
    }
    $speechProvider = new MockSpeechProvider();
    if (isset($config['speech_provider_factory'])) {
        if (($config['environment'] ?? 'production') !== 'test') throw new RuntimeException('Speech provider factory is test-only.');
        if (!is_callable($config['speech_provider_factory'])) throw new RuntimeException('Speech provider factory is invalid.');
        $speechProvider = ($config['speech_provider_factory'])();
        if (!$speechProvider instanceof SpeechProvider) throw new RuntimeException('Speech provider factory did not return a SpeechProvider.');
    }
    $products = new ProductRepository($database);
    $router = new Router(
        new Repository($database, (int) ($config['event_replay_limit'] ?? 256),
            new ActionCatalogRepository($database), new ActionPolicyValidator()),
        new Validator(),
        $provider,
        $tokenHash,
        (string) ($config['base_path'] ?? '/ALMSIVIserver/api/v1'),
        (int) ($config['max_json_bytes'] ?? 2_097_152),
        (int) ($config['events_page_size'] ?? 100),
        (int) ($config['rate_limit_requests'] ?? 120),
        (int) ($config['rate_limit_window_seconds'] ?? 60),
        new MediaStore((string) ($config['media_storage_path'] ?? dirname(__DIR__) . '/storage/media'),
            (int) ($config['media_max_bytes'] ?? 33_554_432), (int) ($config['media_quota_bytes'] ?? 268_435_456)),
        $speechProvider,
        new ProviderAttemptRepository($database),
        (int) ($config['events_max_wait_seconds'] ?? 15),
        new ManagementRepository($database),
        $products,
        new PromptAssembler((int) ($config['max_context_bytes'] ?? 131_072)),
        new MockSpeechToTextProvider(),
    );
    $request = Request::fromGlobals();
    if (str_starts_with($request->path, (string) ($config['management_base_path'] ?? '/ALMSIVIserver/manage'))) {
        $setupHash = getenv('ALMSIVI_MANAGEMENT_SECRET_HASH') ?: (string) ($config['management_secret_hash'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/D', $setupHash) !== 1) throw new RuntimeException('Management secret hash is not configured.');
        $management = new ManagementRouter(new ManagementRepository($database), $products,
            new ProductService($products, new DeterministicClock()), $setupHash,
            (string) ($config['management_base_path'] ?? '/ALMSIVIserver/manage'),
            (int) ($config['max_json_bytes'] ?? 2_097_152), (int) ($config['browser_session_ttl_seconds'] ?? 3600));
        $management->dispatch($request)->emit();
    } else {
        $response=$router->dispatch($request);$response->emit();
        if($request->method==='POST'&&str_ends_with($request->path,'/turns')){
            if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
            (new Worker(new JobRepository($database),FirstPartyJobHandlerFactory::registry($database,
                new MediaStore((string)($config['media_storage_path']??dirname(__DIR__).'/storage/media'),
                    (int)($config['media_max_bytes']??33_554_432),(int)($config['media_quota_bytes']??268_435_456)),
                provider:$provider,speechProvider:$speechProvider,providerTimeoutMs:(int)($config['provider']['timeout_ms']??1000),sttProvider:new MockSpeechToTextProvider()),
                'http-fallback:'.getmypid(),5,1,1,0,10,['turn.process'],static fn(int $microseconds):mixed=>null))->run();
        }
    }
} catch (Throwable) {
    Response::error(503, 'service_unavailable', '00000000-0000-4000-8000-000000000000', true)->emit();
}
