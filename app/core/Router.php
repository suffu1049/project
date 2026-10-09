<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP router.
 *
 * Supports:
 *  - GET/POST/PUT/PATCH/DELETE registration
 *  - Named path parameters, e.g. /requests/{id}
 *  - A middleware pipeline (auth/role/CSRF in later phases)
 *
 * Handlers may be:
 *  - "App\Controllers\HomeController@index"
 *  - [HomeController::class, 'index']
 *  - a callable
 */
final class Router
{
    /** @var array<int, array<string, mixed>> */
    private array $routes = [];

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function add(string $method, string $path, mixed $handler, array $middleware = []): void
    {
        $normalized = '/' . trim($path, '/');

        $this->routes[] = [
            'method'     => strtoupper($method),
            'path'       => $normalized,
            'regex'      => $this->compile($normalized),
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * Match the request and run the handler through its middleware.
     */
    public function dispatch(Request $request): Response
    {
        $path = $request->path();
        $method = $request->method();
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $pathMatched = true;

            if ($route['method'] !== $method) {
                continue;
            }

            $params = array_filter(
                $matches,
                static fn ($key) => is_string($key),
                ARRAY_FILTER_USE_KEY
            );

            return $this->runPipeline($request, $route, $params);
        }

        if ($pathMatched) {
            throw HttpException::methodNotAllowed();
        }

        throw HttpException::notFound();
    }

    /**
     * Build the middleware pipeline then invoke the handler.
     */
    private function runPipeline(Request $request, array $route, array $params): Response
    {
        $destination = fn (Request $req): Response => $this->invoke($route['handler'], $req, $params);

        $pipeline = array_reduce(
            array_reverse($route['middleware']),
            static function (callable $next, mixed $middleware): callable {
                return static function (Request $req) use ($next, $middleware): Response {
                    $instance = is_string($middleware) ? new $middleware() : $middleware;

                    if (!is_object($instance) || !method_exists($instance, 'handle')) {
                        throw new \RuntimeException('Invalid middleware.');
                    }

                    return $instance->handle($req, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }

    /**
     * Invoke a controller/callable and normalize the result to a Response.
     */
    private function invoke(mixed $handler, Request $request, array $params): Response
    {
        // "Class@method" string form.
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $action] = explode('@', $handler, 2);
            $handler = [$class, $action];
        }

        // [ClassName::class, 'method'] form — instantiate the controller.
        if (
            is_array($handler)
            && count($handler) === 2
            && is_string($handler[0])
            && class_exists($handler[0])
        ) {
            $handler = [new $handler[0](), $handler[1]];
        }

        if (!is_callable($handler)) {
            throw new HttpException(500, 'Route handler is not callable.', 'ROUTE_NOT_CALLABLE');
        }

        $result = $handler($request, ...array_values($params));

        return $this->normalize($result);
    }

    private function normalize(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        if (is_array($result)) {
            return Response::success($result);
        }

        if ($result === null) {
            return Response::noContent();
        }

        return Response::html((string) $result);
    }

    /**
     * Compile a route path into a regular expression with named groups.
     */
    private function compile(string $path): string
    {
        $pattern = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $path
        );

        return '#^' . $pattern . '$#';
    }
}
