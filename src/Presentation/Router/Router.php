<?php

declare(strict_types=1);

namespace App\Presentation\Router;

use App\Presentation\Middleware\AuthMiddleware;

class Router
{
    /** @var array<array{method: string, pattern: string, handler: callable, requireAuth: bool}> */
    private array $routes = [];

    private ?AuthMiddleware $authMiddleware = null;

    public function setAuthMiddleware(AuthMiddleware $middleware): void
    {
        $this->authMiddleware = $middleware;
    }

    public function get(string $pattern, callable $handler, bool $requireAuth = true): void
    {
        $this->addRoute('GET', $pattern, $handler, $requireAuth);
    }

    public function post(string $pattern, callable $handler, bool $requireAuth = true): void
    {
        $this->addRoute('POST', $pattern, $handler, $requireAuth);
    }

    public function put(string $pattern, callable $handler, bool $requireAuth = true): void
    {
        $this->addRoute('PUT', $pattern, $handler, $requireAuth);
    }

    public function delete(string $pattern, callable $handler, bool $requireAuth = true): void
    {
        $this->addRoute('DELETE', $pattern, $handler, $requireAuth);
    }

    public function patch(string $pattern, callable $handler, bool $requireAuth = true): void
    {
        $this->addRoute('PATCH', $pattern, $handler, $requireAuth);
    }

    private function addRoute(string $method, string $pattern, callable $handler, bool $requireAuth): void
    {
        $this->routes[] = [
            'method'      => $method,
            'pattern'     => $pattern,
            'handler'     => $handler,
            'requireAuth' => $requireAuth,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = rtrim($path, '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }

            $params = $this->matchPattern($route['pattern'], $path);

            if ($params !== null) {
                if ($route['requireAuth'] && $this->authMiddleware !== null) {
                    $this->authMiddleware->handle();
                }

                ($route['handler'])($params);
                return;
            }
        }

        http_response_code(404);
        echo json_encode(['error' => 'Rota não encontrada.']);
    }

    /** @return array<string, string>|null */
    private function matchPattern(string $pattern, string $path): ?array
    {
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return null;
    }
}
