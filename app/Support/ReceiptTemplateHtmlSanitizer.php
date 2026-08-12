<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Санитизация HTML/CSS шаблона квитанции. Вызывается при сохранении шаблона
 * и повторно при рендере печати: в БД и в печать попадает только чистый HTML.
 */
final class ReceiptTemplateHtmlSanitizer
{
    public const MAX_HTML_BYTES = 65536;

    public const MAX_CSS_BYTES = 32768;

    private const ALLOWED_ELEMENTS = [
        'div' => ['class', 'style'],
        'section' => ['class', 'style'],
        'header' => ['class', 'style'],
        'footer' => ['class', 'style'],
        'p' => ['class', 'style'],
        'span' => ['class', 'style'],
        'h1' => ['class', 'style'],
        'h2' => ['class', 'style'],
        'h3' => ['class', 'style'],
        'h4' => ['class', 'style'],
        'h5' => ['class', 'style'],
        'h6' => ['class', 'style'],
        'strong' => ['class', 'style'],
        'em' => ['class', 'style'],
        'u' => ['class', 'style'],
        's' => ['class', 'style'],
        'small' => ['class', 'style'],
        'br' => [],
        'hr' => ['class', 'style'],
        'table' => ['class', 'style'],
        'thead' => ['class', 'style'],
        'tbody' => ['class', 'style'],
        'tfoot' => ['class', 'style'],
        'tr' => ['class', 'style'],
        'td' => ['class', 'style', 'colspan', 'rowspan'],
        'th' => ['class', 'style', 'colspan', 'rowspan'],
        'ul' => ['class', 'style'],
        'ol' => ['class', 'style'],
        'li' => ['class', 'style'],
        'dl' => ['class', 'style'],
        'dt' => ['class', 'style'],
        'dd' => ['class', 'style'],
        'img' => ['src', 'alt', 'width', 'height', 'class', 'style'],
    ];

    public static function sanitizeHtml(string $html): string
    {
        $config = (new HtmlSanitizerConfig)
            ->allowRelativeMedias()
            ->withMaxInputLength(self::MAX_HTML_BYTES);

        foreach (self::ALLOWED_ELEMENTS as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }

        $clean = (new HtmlSanitizer($config))->sanitize($html);
        $clean = self::restrictImageSources($clean);

        return self::restrictStyleAttributes($clean);
    }

    public static function sanitizeCss(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $css = self::resolveCssHexEscapes($css);
        $css = (string) preg_replace('/@import[^;]*;?/i', '', $css);
        $css = (string) preg_replace('/url\s*\([^)]*\)/i', 'none', $css);
        $css = (string) preg_replace('/expression\s*\([^)]*\)/i', '', $css);
        $css = (string) preg_replace('/behavior\s*:[^;}]*/i', '', $css);
        $css = str_ireplace('javascript:', '', $css);

        /*
         * Валидный CSS не содержит угловых скобок: селекторы, значения и
         * @-правила обходятся без "<"/">" . Строка `$templateCss` выводится
         * сырым текстом внутри <style>...</style> (print.blade,
         * bulk-print.blade, previewHtml) — без этого фильтра сохранённый CSS
         * вида "...}</style><script>...</script>" закрывает тег style и
         * исполняется браузером как хранимый XSS.
         */
        return str_replace(['<', '>'], '', $css);
    }

    /**
     * Симфони-санитайзер уже отфильтровал схемы, но абсолютные и data-URL
     * могли выжить в зависимости от конфигурации — оставляем только
     * относительные пути внутри /storage/. Значение декодируется как из
     * HTML-сущностей, так и из URL-кодирования (в цикле, чтобы поймать
     * двойное кодирование вида %252e), иначе traversal вида
     * /storage/%2e%2e/../etc/passwd или /storage/%252e%252e/... проходит
     * проверку. Легитимные пути логотипа/QR символов "%" не содержат, поэтому
     * любой оставшийся "%" после декодирования — повод отклонить src.
     */
    private static function restrictImageSources(string $html): string
    {
        return (string) preg_replace_callback(
            '/(<img\b[^>]*\bsrc=")([^"]*)(")/iu',
            function (array $matches): string {
                $src = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5);
                $src = self::fullyUrlDecode($src);

                if (
                    str_starts_with($src, '/storage/')
                    && ! str_contains($src, '..')
                    && ! str_contains($src, '%')
                ) {
                    return $matches[0];
                }

                return $matches[1].$matches[3];
            },
            $html,
        );
    }

    /**
     * Декодирует percent-encoding в цикле до стабилизации строки, чтобы
     * поймать двойное (и более) URL-кодирование. Ограничено пятью
     * итерациями — легитимные пути не требуют более одного декодирования,
     * лимит защищает только от искусственно вложенного кодирования.
     */
    private static function fullyUrlDecode(string $value): string
    {
        for ($i = 0; $i < 5; $i++) {
            $decoded = rawurldecode($value);

            if ($decoded === $value) {
                break;
            }

            $value = $decoded;
        }

        return $value;
    }

    /**
     * CSS позволяет экранировать любой символ шестнадцатеричным кодом
     * (\75 === "u"), из-за чего "\75rl(...)" эквивалентно "url(...)", но не
     * матчится регэкспами ниже без предварительного разворачивания. Разворачиваем
     * каждый \XX{1,6}[пробел] в реальный символ, затем убираем одиночные
     * обратные слэши (символьное экранирование вроде "\." не несёт риска
     * для этого фильтра, но не должно засорять вывод).
     */
    private static function resolveCssHexEscapes(string $css): string
    {
        $css = (string) preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})\s?/',
            static function (array $matches): string {
                $char = mb_chr((int) hexdec($matches[1]), 'UTF-8');

                return $char !== false ? $char : '';
            },
            $css,
        );

        return str_replace('\\', '', $css);
    }

    /**
     * У symfony/html-sanitizer нет CSS-санитайзера для значений атрибута
     * style — опасные конструкции (url(javascript:...), expression(...),
     * behavior:...) проходят насквозь. Прогоняем значение через
     * sanitizeCss() как список деклараций; пустое после фильтра значение —
     * атрибут убирается целиком.
     */
    private static function restrictStyleAttributes(string $html): string
    {
        return (string) preg_replace_callback(
            '/\bstyle=(["\'])(.*?)\1/is',
            function (array $matches): string {
                $decoded = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5);
                $filtered = trim(self::sanitizeCss($decoded));

                if ($filtered === '') {
                    return '';
                }

                return 'style="'.htmlspecialchars($filtered, ENT_QUOTES, 'UTF-8').'"';
            },
            $html,
        );
    }
}
