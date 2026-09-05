<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Router;

use App\Infrastructure\Context\RequestContext;
use App\Infrastructure\Security\JwtProvider;
use App\Presentation\Middleware\AuthMiddleware;
use App\Presentation\Router\Router;
use PHPUnit\Framework\TestCase;

/** Covers the authorization matrix enforcement of CONTRATOS.md §5. */
class RouterTest extends TestCase
{
    private const SECRET = 'router-test-secret';

    private JwtProvider $jwt;

    protected function setUp(): void
    {
        $this->jwt = new JwtProvider(self::SECRET, 3600);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    private function router(?RequestContext $context = null): Router
    {
        $router = new Router();
        $router->setAuthMiddleware(new AuthMiddleware($this->jwt, $context));

        return $router;
    }

    /** @param array<string, mixed> $claims */
    private function authenticateAs(array $claims): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->jwt->generate($claims);
    }

    private function dispatch(Router $router, string $method, string $uri): string
    {
        ob_start();
        $router->dispatch($method, $uri);

        return (string)ob_get_clean();
    }

    public function testPublicRouteRunsWithoutToken(): void
    {
        $router = $this->router();
        $router->get('/api/health', fn () => print('{"status":"ok"}'), requireAuth: false);

        $this->assertSame('{"status":"ok"}', $this->dispatch($router, 'GET', '/api/health'));
    }

    public function testUnknownRouteReturns404(): void
    {
        $router = $this->router();
        $router->get('/api/health', fn () => print('ok'), requireAuth: false);

        $body = $this->dispatch($router, 'GET', '/api/nope');

        $this->assertSame(404, http_response_code());
        $this->assertStringContainsString("Rota n\\u00e3o encontrada", $body);
    }

    public function testAdminRouteAllowsAdminRole(): void
    {
        $this->authenticateAs(['sub' => 'admin', 'role' => 'admin']);

        $router = $this->router();
        $router->get('/api/customers', fn () => print('[]'))->requireRole('admin');

        $this->assertSame('[]', $this->dispatch($router, 'GET', '/api/customers'));
    }

    public function testAdminRouteRejectsCustomerRoleWith403(): void
    {
        $this->authenticateAs(['sub' => 'cust-1', 'role' => 'customer']);

        $router = $this->router();
        $router->get('/api/customers', fn () => print('[]'))->requireRole('admin');

        $body = $this->dispatch($router, 'GET', '/api/customers');

        $this->assertSame(403, http_response_code());
        $this->assertStringContainsString("Acesso negado", $body);
    }

    public function testClaimsArePassedToTheHandler(): void
    {
        $this->authenticateAs(['sub' => 'cust-42', 'role' => 'customer']);

        $seen   = null;
        $router = $this->router();
        $router->get('/api/service-orders/me', function (array $p, array $claims) use (&$seen): void {
            $seen = $claims;
        })->requireRole('customer', 'admin');

        $this->dispatch($router, 'GET', '/api/service-orders/me');

        $this->assertIsArray($seen);
        $this->assertSame('cust-42', $seen['sub']);
        $this->assertSame('customer', $seen['role']);
    }

    public function testPathParametersAreStillPassed(): void
    {
        $this->authenticateAs(['sub' => 'admin', 'role' => 'admin']);

        $seen   = null;
        $router = $this->router();
        $router->get('/api/service-orders/{id}', function (array $params) use (&$seen): void {
            $seen = $params['id'];
        })->requireRole('admin');

        $this->dispatch($router, 'GET', '/api/service-orders/abc-123');

        $this->assertSame('abc-123', $seen);
    }

    public function testAuthMiddlewarePublishesClaimsIntoTheRequestContext(): void
    {
        $this->authenticateAs(['sub' => 'cust-9', 'role' => 'customer']);

        $context = new RequestContext();
        $router  = $this->router($context);
        $router->get('/api/service-orders/me', fn () => print('[]'))->requireRole('customer', 'admin');

        $this->dispatch($router, 'GET', '/api/service-orders/me');

        $this->assertSame('cust-9', $context->getActor());
        $this->assertSame('customer', $context->getRole());
    }

    public function testRequireRoleWithoutARouteFails(): void
    {
        $this->expectException(\LogicException::class);
        (new Router())->requireRole('admin');
    }

    public function testTrailingSlashIsIgnored(): void
    {
        $router = $this->router();
        $router->get('/api/ready', fn () => print('ready'), requireAuth: false);

        $this->assertSame('ready', $this->dispatch($router, 'GET', '/api/ready/'));
    }
}
