<?php
namespace App\Core;

class Router
{
    private array $routes = [];

    public function register(string $method, string $pattern, $handler, array $middleware = []): void
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function get(string $p, $h, array $m = []): void { $this->register('GET', $p, $h, $m); }
    public function post(string $p, $h, array $m = []): void { $this->register('POST', $p, $h, $m); }
    public function put(string $p, $h, array $m = []): void { $this->register('PUT', $p, $h, $m); }
    public function delete(string $p, $h, array $m = []): void { $this->register('DELETE', $p, $h, $m); }
    public function patch(string $p, $h, array $m = []): void { $this->register('PATCH', $p, $h, $m); }

    public function dispatch(Request $request): void
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        if ($method === 'OPTIONS') {
            http_response_code(204);
            Response::corsHeaders();
            exit;
        }

        $matchedPathOnly = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                if ($route['method'] !== $method) {
                    $matchedPathOnly = true;
                    continue;
                }
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $request->setParams($params);

                foreach ($route['middleware'] as $middleware) {
                    $result = call_user_func($middleware, $request);
                    if ($result === false) {
                        return;
                    }
                }

                [$class, $methodName] = $route['handler'];
                $controller = new $class();
                $controller->{$methodName}($request);
                return;
            }
        }

        if ($matchedPathOnly) {
            Response::error('Method not allowed', 405);
        }
        Response::error('Not found: ' . $path, 404);
    }
}
