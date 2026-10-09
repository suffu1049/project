<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP template renderer.
 *
 * A view is rendered into a string, then optionally injected into a layout
 * as `$content`. Data is scoped to the template via a closure so view
 * variables never leak into the global scope.
 */
final class View
{
    /**
     * Render a view, optionally wrapped in a layout.
     *
     * @param string      $view   e.g. "home" or "errors/404"
     * @param array       $data   variables exposed to the template
     * @param string|null $layout layout name, or null for a bare fragment
     */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::renderFile(self::resolve($view), $data);

        if ($layout === null) {
            return $content;
        }

        return self::renderFile(self::resolve($layout), array_merge($data, ['content' => $content]));
    }

    /**
     * Resolve a dotted/slashed view name to an absolute template path.
     * Supports both "errors/404" and "errors.404".
     */
    public static function resolve(string $view): string
    {
        $relative = str_replace('.', '/', $view);
        $file = view_path($relative . '.php');

        if (!is_file($file)) {
            throw new HttpException(500, 'View not found: ' . $view, 'VIEW_NOT_FOUND');
        }

        return $file;
    }

    private static function renderFile(string $file, array $data): string
    {
        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            require $__file;

            return (string) ob_get_clean();
        };

        return $render($file, $data);
    }
}
