<?php
declare(strict_types=1);

namespace Cjenik;

final class View
{
    public static function e(mixed $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Renderira app/templates/<name>.php. U predlosku su dostupni $e (escape) i varijable iz $vars.
     * @param array<string,mixed> $vars
     */
    public static function render(string $name, array $vars = []): string
    {
        $file = APP_ROOT . '/templates/' . $name . '.php';
        $e = static fn(mixed $s): string => self::e($s);
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $t) {
            ob_end_clean();
            throw $t;
        }
        return (string)ob_get_clean();
    }

    /** @param array<string,mixed> $vars */
    public static function page(string $layout, string $content, array $vars = []): string
    {
        return self::render($layout, $vars + ['content' => $content]);
    }
}
