<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base controller.
 *
 * Controllers receive requests, validate/authorize (via middleware where
 * possible), delegate business logic to services, and return a Response.
 * Business rules do not belong here.
 */
abstract class Controller
{
    /**
     * Render a view inside a layout and return an HTML response.
     */
    protected function view(string $view, array $data = [], ?string $layout = 'layouts/app', int $status = 200): Response
    {
        return Response::html(View::render($view, $data, $layout), $status);
    }

    /**
     * Return a JSON response. Arrays get the standard success envelope;
     * a Response instance is passed through unchanged.
     */
    protected function json(mixed $data = null, string $message = 'Operation completed successfully.', int $status = 200): Response
    {
        if ($data instanceof Response) {
            return $data;
        }

        return Response::success($data, $message, $status);
    }

    protected function jsonError(string $message, string $code = 'INTERNAL_ERROR', int $status = 400, array $errors = []): Response
    {
        return Response::error($message, $code, $status, $errors);
    }

    protected function redirect(string $location, int $status = 302): Response
    {
        return Response::redirect($location, $status);
    }

    protected function noContent(): Response
    {
        return Response::noContent();
    }
}
