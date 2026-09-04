<?php

declare(strict_types=1);

namespace App\Presentation\Router;

use App\Presentation\Middleware\AuthMiddleware;

/**
 * Minimal routing table with authentication and role authorization.
 *
 * The authorization matrix lives in docs/fase-3/CONTRATOS.md §5 and is applied in
 * public/index.php via ->requireRole(...). The JWT is revalidated locally on every
 * protected route (defence in depth) — headers injected by the API Gateway are never trusted.
 */
class Router
{
    /**
     * @var array<array{
     *     method: string, pattern: string, handler: callable,
     *     requireAuth: bool, roles: array<int, string>|null
     * }>
     */
    private array $routes = [];

    private ?AuthMiddleware $authMiddleware = null;

    public function setAuthMiddleware(AuthMiddleware $middleware): void
    {
        $this->authMiddleware = $middleware;
    }

    public function get(string $pattern, callable $handler, bool $requireAuth = true): self
    {
        return $this->addRoute('GET', $pattern, $handler, $requireAuth);
    }

    public function post(string $pattern, callable $handler, bool $requireAuth = true): self
    {
        return $this->addRoute('POST', $pattern, $handler, $requireAuth);
    }

    public function put(string $pattern, callable $handler, bool $requireAuth = true): self
    {
        return $this->addRoute('PUT', $pattern, $handler, $requireAuth);
    }

    public function delete(string $pattern, callable $handler, bool $requireAuth = true): self
    {
        return $this->addRoute('DELETE', $pattern, $handler, $requireAuth);
    }

    public function patch(string $pattern, callable $handler, bool $requireAuth = true): self
    {
        return $this->addRoute('PATCH', $pattern, $handler, $requireAuth);
    }

    /**
     * Restricts the last registered route to the given roles (claim `role` of the JWT).
     * Implies authentication.
     */
    public function requireRole(string ...$roles): self
    {
        $index = array_key_last($this->routes);

        if ($index === null) {
            throw new \LogicException('requireRole() must be called right after a route definition.');
        }

        $this->routes[$index]['roles']       = array_values($roles);
        $this->routes[$index]['requireAuth'] = true;

        return $this;
    }

    private function addRoute(string $method, string $pattern, callable $handler, bool $requireAuth): self
    {
        $this->routes[] = [
            'method'      => $method,
            'pattern'     => $pattern,
            'handler'     => $handler,
            'requireAuth' => $requireAuth,
            'roles'       => null,
        ];

        return $this;
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

            if ($params === null) {
                continue;
            }

            /** @var array<string, mixed> $claims */
            $claims = [];

            if ($route['requireAuth'] && $this->authMiddleware !== null) {
                $claims = $this->authMiddleware->handle();

                if ($route['roles'] !== null && !$this->hasRole($claims, $route['roles'])) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Acesso negado.']);
                    return;
                }
            }

            // Claims are handed to the handler so controllers can scope data to the token subject.
            ($route['handler'])($params, $claims);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Rota não encontrada.']);
    }

    /**
     * @param array<string, mixed>  $claims
     * @param array<int, string>    $roles
     */
    private function hasRole(array $claims, array $roles): bool
    {
        $role = $claims['role'] ?? null;

        return is_string($role) && in_array($role, $roles, true);
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
