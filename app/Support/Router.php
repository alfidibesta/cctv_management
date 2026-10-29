<?php

declare(strict_types=1);

namespace Argus\Support;

/**
 * Tiny regex router with route parameters ({id}).
 */
final class Router
{
    /** @var array<int, array{method: string, regex: string, params: array<int,string>, handler: array, public: bool}> */
    private array $routes = [];

    public function get(string $path, array $handler, bool $public = false): void
    {
        $this->add('GET', $path, $handler, $public);
    }

    public function post(string $path, array $handler, bool $public = false): void
    {
        $this->add('POST', $path, $handler, $public);
    }

    public function add(string $method, string $path, array $handler, bool $public = false): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '/\{([a-z_]+)\}/i',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];

                return '([^/]+)';
            },
            $path
        ) ?? $path;

        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
            'public'  => $public,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = base_path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $path = '/' . trim(rawurldecode($path), '/');
        $method = strtoupper($method);

        // The setup wizard owns the app until config/config.php exists.
        if (Config::needsSetup() && !str_starts_with($path, '/setup')) {
            redirect('/setup');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method || !preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            // Reject state-changing requests without a valid CSRF token.
            if ($method === 'POST' && !Csrf::verify(Csrf::fromRequest())) {
                if (Auth::isAjax()) {
                    json_response(['error' => 'Your session expired. Reload the page and try again.'], 419);
                }
                redirect('/?error=csrf');
            }

            if (!$route['public']) {
                Auth::requireAuth();
            }

            array_shift($matches);
            $args = array_map(static fn (string $v): string => urldecode($v), $matches);

            [$class, $action] = $route['handler'];
            $controller = new $class();
            $controller->{$action}(...$args);

            return;
        }

        $this->notFound($path);
    }

    private function notFound(string $path): void
    {
        http_response_code(404);

        if (Auth::isAjax()) {
            json_response(['error' => 'Not found: ' . $path], 404);
        }

        if (!Config::needsSetup() && Auth::check()) {
            view('pages/error', ['code' => 404, 'message' => 'That page does not exist.']);
            return;
        }

        view('pages/error', ['code' => 404, 'message' => 'That page does not exist.'], 'layouts/guest');
    }
}
