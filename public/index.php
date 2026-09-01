<?php
declare(strict_types=1);

use LorkhanServer\Application\ActionPolicyValidator;
use LorkhanServer\Application\DeterministicClock;
use LorkhanServer\Application\FirstPartyJobHandlerFactory;
use LorkhanServer\Application\MorrowindVoiceCatalog;
use LorkhanServer\Application\ProductService;
use LorkhanServer\Application\PromptAssembler;
use LorkhanServer\Application\Provider;
use LorkhanServer\Application\ProviderFactory;
use LorkhanServer\Application\RechatCoordinator;
use LorkhanServer\Application\SpeechProvider;
use LorkhanServer\Application\Worker;
use LorkhanServer\Http\ManagementRouter;
use LorkhanServer\Http\Request;
use LorkhanServer\Http\Response;
use LorkhanServer\Http\Router;
use LorkhanServer\Infrastructure\ActionCatalogRepository;
use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\DefaultConnectorProvisioner;
use LorkhanServer\Infrastructure\EventLogRepository;
use LorkhanServer\Infrastructure\JobRepository;
use LorkhanServer\Infrastructure\ManagementRepository;
use LorkhanServer\Infrastructure\MediaStore;
use LorkhanServer\Infrastructure\OghmaCatalogImporter;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\ProviderAttemptRepository;
use LorkhanServer\Infrastructure\Repository;
use LorkhanServer\Protocol\Validator;
use LorkhanServer\Security\PairingToken;

// Map the mounted /LorkhanServer path when the PHP development server is used as the router.
if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($requestPath) && str_starts_with($requestPath, '/LorkhanServer/ui/')) {
        $relative = substr($requestPath, strlen('/LorkhanServer'));
        $uiRoot = realpath(__DIR__ . '/ui');
        $target = realpath(__DIR__ . $relative);
        if ($uiRoot !== false && $target !== false && str_starts_with($target, $uiRoot . DIRECTORY_SEPARATOR) && is_file($target)) {
            if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'php') {
                require $target;
            } else {
                $mime = match (strtolower(pathinfo($target, PATHINFO_EXTENSION))) {
                    'css' => 'text/css; charset=utf-8',
                    'js' => 'text/javascript; charset=utf-8',
                    'svg' => 'image/svg+xml',
                    'png' => 'image/png',
                    'ico' => 'image/x-icon',
                    'otf' => 'font/otf',
                    default => 'application/octet-stream',
                };
                header('Content-Type: ' . $mime);
                header('X-Content-Type-Options: nosniff');
                readfile($target);
            }
            return;
        }
    }
}

require dirname(__DIR__) . '/src/Autoload.php';

$configFile = getenv('LORKHAN_CONFIG') ?: dirname(__DIR__) . '/config/server.php';
try {
    if (!is_file($configFile)) throw new RuntimeException('Server configuration is unavailable.');
    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Server configuration is invalid.');
    $config['credential_storage_path'] ??= '/var/lib/lorkhanserver/credentials/provider-keys.json';
    $tokenHash = getenv('LORKHAN_PAIRING_TOKEN_HASH') ?: (string) ($config['pairing_token_hash'] ?? '');
    if (preg_match('/^[0-9a-f]{64}$/D', $tokenHash) !== 1) throw new RuntimeException('Pairing token hash is not configured.');
    $config['database_password'] = getenv('LORKHAN_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');
    $database = Connection::open($config);
    $providerConfig = is_array($config['provider'] ?? null) ? $config['provider'] : [];
    $provider = ProviderFactory::dialogue($config);
    if (isset($config['provider_factory'])) {
        if (($config['environment'] ?? 'production') !== 'test') throw new RuntimeException('Provider factory is test-only.');
        if (!is_callable($config['provider_factory'])) throw new RuntimeException('Provider factory is invalid.');
        $provider = ($config['provider_factory'])();
        if (!$provider instanceof Provider) throw new RuntimeException('Provider factory did not return a Provider.');
    }
    $speechProvider = ProviderFactory::speech($config);
    if (isset($config['speech_provider_factory'])) {
        if (($config['environment'] ?? 'production') !== 'test') throw new RuntimeException('Speech provider factory is test-only.');
        if (!is_callable($config['speech_provider_factory'])) throw new RuntimeException('Speech provider factory is invalid.');
        $speechProvider = ($config['speech_provider_factory'])();
        if (!$speechProvider instanceof SpeechProvider) throw new RuntimeException('Speech provider factory did not return a SpeechProvider.');
    }
    $products = new ProductRepository($database);
    $defaultConnectors = new DefaultConnectorProvisioner(
        $database,
        (string) ($config['voice_storage_path'] ?? '/var/lib/lorkhanserver/voices'),
    );
    $repository = new Repository($database, (int) ($config['event_replay_limit'] ?? 256),
        new ActionCatalogRepository($database), new ActionPolicyValidator(), $defaultConnectors);
    $router = new Router(
        $repository,
        new Validator(),
        $provider,
        $tokenHash,
        (string) ($config['base_path'] ?? '/LorkhanServer/api/v1'),
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
        MorrowindVoiceCatalog::bundled(),
        new RechatCoordinator($repository, $products),
        providerConfig: $config,
    );
    $request = Request::fromGlobals();
    if (str_starts_with($request->path, (string) ($config['management_base_path'] ?? '/LorkhanServer/manage'))) {
        $management = new ManagementRouter(new ManagementRepository($database), $products,
            new ProductService($products, new DeterministicClock()),
            (string) ($config['management_base_path'] ?? '/LorkhanServer/manage'),
            (int) ($config['max_json_bytes'] ?? 2_097_152), (int) ($config['browser_session_ttl_seconds'] ?? 3600), $config,
            new EventLogRepository($database),new OghmaCatalogImporter($database));
        $management->dispatch($request)->emit();
    } else {
        $response=$router->dispatch($request);$response->emit();
        if($request->method==='POST'&&str_ends_with($request->path,'/turns')&&function_exists('fastcgi_finish_request')){
            fastcgi_finish_request();
            // FastCGI can finish the response before this bounded fallback. Under mod_php the
            // persistent worker owns provider work so the accepted-turn response stays immediate.
            (new Worker(new JobRepository($database),FirstPartyJobHandlerFactory::registry($database,
                new MediaStore((string)($config['media_storage_path']??dirname(__DIR__).'/storage/media'),
                    (int)($config['media_max_bytes']??33_554_432),(int)($config['media_quota_bytes']??268_435_456)),
                provider:$provider,speechProvider:$speechProvider,providerTimeoutMs:(int)($providerConfig['timeout_ms']??1000),providerConfig:$config),
                'http-fallback:'.getmypid(),5,1,1,0,10,['turn.process'],static fn(int $microseconds):mixed=>null))->run();
        }
    }
} catch (Throwable) {
    Response::error(503, 'service_unavailable', '00000000-0000-4000-8000-000000000000', true)->emit();
}
