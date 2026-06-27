<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Config\EnvLoader;

EnvLoader::loadFile(__DIR__ . '/../.env');
EnvLoader::require(['JWT_SECRET', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$uuid           = new \App\Infrastructure\UuidGenerator();
$jwtProvider    = new \App\Infrastructure\Security\JwtProvider();
$authMiddleware = new \App\Presentation\Middleware\AuthMiddleware($jwtProvider);

$customerRepo    = new \App\Infrastructure\Repository\PdoCustomerRepository();
$vehicleRepo     = new \App\Infrastructure\Repository\PdoVehicleRepository();
$partRepo        = new \App\Infrastructure\Repository\PdoPartRepository();
$serviceItemRepo = new \App\Infrastructure\Repository\PdoServiceItemRepository();
$orderRepo       = new \App\Infrastructure\Repository\PdoServiceOrderRepository($uuid);

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
    new \App\Application\UseCase\ServiceOrder\GetServiceOrderByClientUseCase($orderRepo),
    new \App\Application\UseCase\ServiceOrder\ListServiceOrdersUseCase($orderRepo),
    new \App\Application\UseCase\ServiceOrder\ReviewBudgetUseCase($orderRepo, $eventDispatcher),
);

$router = new \App\Presentation\Router\Router();
$router->setAuthMiddleware($authMiddleware);

// rotas públicas
$router->get('/api/health',                fn() => $healthController->check(),            requireAuth: false);
$router->post('/api/auth/login',           fn() => $authController->login(),              requireAuth: false);
$router->get('/api/service-orders/status', fn() => $orderController->statusByClient(),    requireAuth: false);
$router->post('/api/service-orders/{id}/approval', fn($p) => $orderController->reviewBudget($p), requireAuth: false);

// clientes
$router->get('/api/customers',             fn() => $customerController->index());
$router->get('/api/customers/{id}',        fn($p) => $customerController->show($p));
$router->post('/api/customers',            fn() => $customerController->store());
$router->put('/api/customers/{id}',        fn($p) => $customerController->update($p));
$router->delete('/api/customers/{id}',     fn($p) => $customerController->destroy($p));

// veículos
$router->get('/api/vehicles',              fn() => $vehicleController->index());
$router->get('/api/vehicles/{id}',         fn($p) => $vehicleController->show($p));
$router->post('/api/vehicles',             fn() => $vehicleController->store());
$router->put('/api/vehicles/{id}',         fn($p) => $vehicleController->update($p));
$router->delete('/api/vehicles/{id}',      fn($p) => $vehicleController->destroy($p));

// peças
$router->get('/api/parts',                 fn() => $partController->index());
$router->get('/api/parts/{id}',            fn($p) => $partController->show($p));
$router->post('/api/parts',                fn() => $partController->store());
$router->put('/api/parts/{id}',            fn($p) => $partController->update($p));
$router->patch('/api/parts/{id}/stock',    fn($p) => $partController->updateStock($p));
$router->delete('/api/parts/{id}',         fn($p) => $partController->destroy($p));

// catálogo de serviços
$router->get('/api/service-items/metrics', fn() => $serviceItemController->metrics());
$router->get('/api/service-items',         fn() => $serviceItemController->index());
$router->get('/api/service-items/{id}',    fn($p) => $serviceItemController->show($p));
$router->post('/api/service-items',        fn() => $serviceItemController->store());
$router->put('/api/service-items/{id}',    fn($p) => $serviceItemController->update($p));
$router->delete('/api/service-items/{id}', fn($p) => $serviceItemController->destroy($p));

// ordens de serviço
$router->get('/api/service-orders',               fn() => $orderController->index());
$router->get('/api/service-orders/{id}',          fn($p) => $orderController->show($p));
$router->post('/api/service-orders',              fn() => $orderController->store());
$router->post('/api/service-orders/{id}/items',   fn($p) => $orderController->addItems($p));
$router->patch('/api/service-orders/{id}/status', fn($p) => $orderController->changeStatus($p));

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (\App\Domain\Exception\DomainException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    http_response_code(500);
    echo json_encode([
        'error'   => 'Erro interno do servidor.',
        'message' => $debug ? $e->getMessage() : null,
    ]);
}
