<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Config\EnvLoader;

EnvLoader::loadFile(__DIR__ . '/../.env');
EnvLoader::require(['JWT_SECRET', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']);

$startedAt = microtime(true);
$appEnv    = (string)($_ENV['APP_ENV'] ?? 'local');
$appDebug  = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-Id, X-Webhook-Token');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

$uuid = new \App\Infrastructure\UuidGenerator();

// Observability wiring: correlation id first, so every log line of this request carries it.
$logger        = new \App\Infrastructure\Logging\JsonLogger('oficina-api', $appEnv);
$context       = new \App\Infrastructure\Context\RequestContext();
$correlationId = (new \App\Presentation\Middleware\CorrelationIdMiddleware($uuid))->apply($_SERVER);
$context->setCorrelationId($correlationId);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$jwtProvider    = \App\Infrastructure\Security\JwtProvider::fromEnv();
$authMiddleware = new \App\Presentation\Middleware\AuthMiddleware($jwtProvider, $context);

$customerRepo      = new \App\Infrastructure\Repository\PdoCustomerRepository();
$vehicleRepo       = new \App\Infrastructure\Repository\PdoVehicleRepository();
$partRepo          = new \App\Infrastructure\Repository\PdoPartRepository();
$serviceItemRepo   = new \App\Infrastructure\Repository\PdoServiceItemRepository();
$orderRepo         = new \App\Infrastructure\Repository\PdoServiceOrderRepository($uuid);
$statusHistoryRepo = new \App\Infrastructure\Repository\PdoServiceOrderStatusHistoryRepository();

$eventDispatcher = new \App\Infrastructure\Event\InMemoryEventDispatcher();

$mailer = new \App\Infrastructure\Notification\SmtpMailer(
    host: $_ENV['SMTP_HOST']         ?? '',
    port: (int)($_ENV['SMTP_PORT']   ?? 587),
    username: $_ENV['SMTP_USERNAME'] ?? '',
    password: $_ENV['SMTP_PASSWORD'] ?? '',
    from: $_ENV['MAIL_FROM']         ?? 'no-reply@oficina.local',
);

$statusNotifier = new \App\Infrastructure\Notification\StatusChangeEmailNotifier(
    $mailer,
    $_ENV['MAIL_TO'] ?? '',
);

// Subscribe the email notifier only when SMTP is configured; otherwise stay silent.
if (($_ENV['SMTP_HOST'] ?? '') !== '' && ($_ENV['MAIL_TO'] ?? '') !== '') {
    $eventDispatcher->subscribe(
        \App\Domain\Event\ServiceOrderStatusChangedEvent::class,
        static fn (\App\Domain\Event\ServiceOrderStatusChangedEvent $event) => $statusNotifier->handle($event),
    );
}

$statusHistorySubscriber = new \App\Infrastructure\Event\Subscriber\StatusHistorySubscriber(
    $statusHistoryRepo,
    $uuid,
    $context,
    $logger,
);

$newRelicSubscriber = new \App\Infrastructure\Event\Subscriber\NewRelicSubscriber(
    $context,
    $statusHistoryRepo,
    $appEnv,
);

$eventDispatcher->subscribe(
    \App\Domain\Event\ServiceOrderCreatedEvent::class,
    static fn (\App\Domain\Event\ServiceOrderCreatedEvent $e) => $statusHistorySubscriber->onCreated($e),
);
$eventDispatcher->subscribe(
    \App\Domain\Event\ServiceOrderCreatedEvent::class,
    static fn (\App\Domain\Event\ServiceOrderCreatedEvent $e) => $newRelicSubscriber->onCreated($e),
);
$eventDispatcher->subscribe(
    \App\Domain\Event\ServiceOrderStatusChangedEvent::class,
    static fn (\App\Domain\Event\ServiceOrderStatusChangedEvent $e) => $statusHistorySubscriber->onStatusChanged($e),
);
$eventDispatcher->subscribe(
    \App\Domain\Event\ServiceOrderStatusChangedEvent::class,
    static fn (\App\Domain\Event\ServiceOrderStatusChangedEvent $e) => $newRelicSubscriber->onStatusChanged($e),
);

$authController = new \App\Presentation\Controller\AuthController(
    new \App\Application\UseCase\Auth\AuthenticateUseCase($jwtProvider)
);

$healthController = new \App\Presentation\Controller\HealthController();

$customerController = new \App\Presentation\Controller\CustomerController($customerRepo, $uuid);

$vehicleController = new \App\Presentation\Controller\VehicleController($vehicleRepo, $customerRepo, $uuid);

$partController = new \App\Presentation\Controller\PartController($partRepo, $uuid);

$serviceItemController = new \App\Presentation\Controller\ServiceItemController($serviceItemRepo, $uuid);

$orderController = new \App\Presentation\Controller\ServiceOrderController(
    new \App\Application\UseCase\ServiceOrder\CreateServiceOrderUseCase(
        $customerRepo, $vehicleRepo, $orderRepo, $uuid
    ),
    new \App\Application\UseCase\ServiceOrder\AddItemsToServiceOrderUseCase(
        $orderRepo, $serviceItemRepo, $partRepo
    ),
    new \App\Application\UseCase\ServiceOrder\GetServiceOrderUseCase($orderRepo),
    new \App\Application\UseCase\ServiceOrder\ChangeServiceOrderStatusUseCase($orderRepo, $eventDispatcher),
    new \App\Application\UseCase\ServiceOrder\ListServiceOrdersByCustomerUseCase($orderRepo),
    new \App\Application\UseCase\ServiceOrder\ListServiceOrdersUseCase($orderRepo),
    new \App\Application\UseCase\ServiceOrder\ReviewBudgetUseCase($orderRepo, $eventDispatcher),
);

$router = new \App\Presentation\Router\Router();
$router->setAuthMiddleware($authMiddleware);

// Authorization matrix — docs/fase-3/CONTRATOS.md §5.

// públicas
$router->get('/api/health', fn() => $healthController->live(),  requireAuth: false);
$router->get('/api/ready',  fn() => $healthController->ready(), requireAuth: false);
$router->post('/api/auth/login', fn() => $authController->login(), requireAuth: false);
// Guarded by the mandatory X-Webhook-Token header inside the controller, not by the JWT.
$router->post('/api/service-orders/{id}/approval', fn($p) => $orderController->reviewBudget($p), requireAuth: false);

// ordens de serviço — a rota /me vem antes de /{id} para não ser capturada por ela
$router->get('/api/service-orders/me', fn($p, $c) => $orderController->mine($p, $c))
    ->requireRole('customer', 'admin');
$router->get('/api/service-orders/{id}', fn($p, $c) => $orderController->show($p, $c))
    ->requireRole('customer', 'admin');
$router->get('/api/service-orders', fn() => $orderController->index())->requireRole('admin');
$router->post('/api/service-orders', fn() => $orderController->store())->requireRole('admin');
$router->post('/api/service-orders/{id}/items', fn($p) => $orderController->addItems($p))->requireRole('admin');
$router->patch('/api/service-orders/{id}/status', fn($p) => $orderController->changeStatus($p))->requireRole('admin');

// clientes
$router->get('/api/customers',         fn() => $customerController->index())->requireRole('admin');
$router->get('/api/customers/{id}',    fn($p) => $customerController->show($p))->requireRole('admin');
$router->post('/api/customers',        fn() => $customerController->store())->requireRole('admin');
$router->put('/api/customers/{id}',    fn($p) => $customerController->update($p))->requireRole('admin');
$router->delete('/api/customers/{id}', fn($p) => $customerController->destroy($p))->requireRole('admin');

// veículos
$router->get('/api/vehicles',          fn() => $vehicleController->index())->requireRole('admin');
$router->get('/api/vehicles/{id}',     fn($p) => $vehicleController->show($p))->requireRole('admin');
$router->post('/api/vehicles',         fn() => $vehicleController->store())->requireRole('admin');
$router->put('/api/vehicles/{id}',     fn($p) => $vehicleController->update($p))->requireRole('admin');
$router->delete('/api/vehicles/{id}',  fn($p) => $vehicleController->destroy($p))->requireRole('admin');

// peças
$router->get('/api/parts',              fn() => $partController->index())->requireRole('admin');
$router->get('/api/parts/{id}',         fn($p) => $partController->show($p))->requireRole('admin');
$router->post('/api/parts',             fn() => $partController->store())->requireRole('admin');
$router->put('/api/parts/{id}',         fn($p) => $partController->update($p))->requireRole('admin');
$router->patch('/api/parts/{id}/stock', fn($p) => $partController->updateStock($p))->requireRole('admin');
$router->delete('/api/parts/{id}',      fn($p) => $partController->destroy($p))->requireRole('admin');

// catálogo de serviços
$router->get('/api/service-items/metrics', fn() => $serviceItemController->metrics())->requireRole('admin');
$router->get('/api/service-items',         fn() => $serviceItemController->index())->requireRole('admin');
$router->get('/api/service-items/{id}',    fn($p) => $serviceItemController->show($p))->requireRole('admin');
$router->post('/api/service-items',        fn() => $serviceItemController->store())->requireRole('admin');
$router->put('/api/service-items/{id}',    fn($p) => $serviceItemController->update($p))->requireRole('admin');
$router->delete('/api/service-items/{id}', fn($p) => $serviceItemController->destroy($p))->requireRole('admin');

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '/');

/**
 * One structured line per request (CONTRATOS.md §7), emitted even when the request dies on
 * an exit() inside a middleware — hence the shutdown function.
 */
$requestLogged = false;
$logRequest    = static function (string $level = 'info') use (
    &$requestLogged, $logger, $context, $method, $uri, $startedAt
): void {
    if ($requestLogged) {
        return;
    }
    $requestLogged = true;

    $logger->log($level, 'request.completed', [
        'correlation_id' => $context->getCorrelationId(),
        'method'         => $method,
        'path'           => (string)(parse_url($uri, PHP_URL_PATH) ?? $uri),
        'status'         => http_response_code() ?: 200,
        'duration_ms'    => round((microtime(true) - $startedAt) * 1000, 1),
        'customer_id'    => $context->getActor(),
        'role'           => $context->getRole(),
    ]);
};

register_shutdown_function(static fn () => $logRequest());

try {
    $router->dispatch($method, $uri);
} catch (\App\Domain\Exception\DomainException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    // Unhandled failure: the detail goes to the log, never to the client unless APP_DEBUG=true.
    $logger->error('request.failed', [
        'correlation_id'    => $context->getCorrelationId(),
        'method'            => $method,
        'path'              => (string)(parse_url($uri, PHP_URL_PATH) ?? $uri),
        'exception_class'   => $e::class,
        'exception_message' => $e->getMessage(),
        'file'              => $e->getFile(),
        'line'              => $e->getLine(),
    ]);

    http_response_code(500);
    echo json_encode([
        'error'   => 'Erro interno do servidor.',
        'message' => $appDebug ? $e->getMessage() : null,
    ]);

    $logRequest('error');
}

$logRequest();
