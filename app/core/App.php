<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Application kernel.
 *
 * Owns the router, loads route definition files, dispatches the incoming
 * request and sends the response.
 */
final class App
{
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
    }

    /**
     * Load one or more route files. Each file must return a callable that
     * receives the Router instance.
     */
    public function loadRoutes(string ...$files): self
    {
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $loader = require $file;

            if (is_callable($loader)) {
                $loader($this->router);
            }
        }

        return $this;
    }

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * Handle the current request and emit the response.
     */
    public function run(?Request $request = null): Response
    {
        $request ??= Request::capture();

        $response = SecurityHeaders::apply($this->router->dispatch($request));
        $response->send();

        return $response;
    }
}
