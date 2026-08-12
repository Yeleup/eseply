<?php

namespace App\Support;

/**
 * Подстановка плейсхолдеров {{key}} в HTML-шаблон квитанции. Скалярные
 * значения экранируются; фрагменты вставляются как HTML — их генерирует
 * только код проекта. Неизвестные ключи заменяются пустой строкой.
 */
final class ReceiptTemplateRenderer
{
    /**
     * @param  array<string, string>  $values
     * @param  array<string, string>  $fragments
     */
    public static function render(string $html, array $values, array $fragments): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/',
            function (array $matches) use ($values, $fragments): string {
                $key = $matches[1];

                if (array_key_exists($key, $fragments)) {
                    return $fragments[$key];
                }

                if (array_key_exists($key, $values)) {
                    return e($values[$key]);
                }

                return '';
            },
            $html,
        );
    }
}
