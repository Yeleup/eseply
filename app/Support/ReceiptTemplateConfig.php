<?php

namespace App\Support;

/**
 * Настройки шаблона квитанции, слитые с дефолтами и очищенные от
 * недопустимых значений. Единственный способ создать объект — фабрики,
 * поэтому потребители всегда получают валидный конфиг.
 */
final class ReceiptTemplateConfig
{
    /**
     * @param  array{
     *     blocks: list<array{type: string, enabled: bool}>,
     *     texts: array{title: string, footer_note: string, labels: array<string, string>},
     *     appearance: array{copies_per_page: int, font_size: string, density: string, borders: bool, show_logo: bool, show_qr: bool}
     * }  $settings
     */
    private function __construct(
        private readonly array $settings,
        private readonly ?string $logoUrl,
        private readonly ?string $qrUrl,
    ) {}

    public static function default(): self
    {
        return new self(ReceiptTemplateDefaults::settings(), null, null);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromSettings(array $settings, ?string $logoUrl = null, ?string $qrUrl = null): self
    {
        $defaults = ReceiptTemplateDefaults::settings();

        return new self([
            'blocks' => self::mergeBlocks($settings['blocks'] ?? null, $defaults['blocks']),
            'texts' => self::mergeTexts($settings['texts'] ?? null, $defaults['texts']),
            'appearance' => self::mergeAppearance($settings['appearance'] ?? null, $defaults['appearance']),
        ], $logoUrl, $qrUrl);
    }

    /**
     * @return array{
     *     blocks: list<array{type: string, enabled: bool}>,
     *     texts: array{title: string, footer_note: string, labels: array<string, string>},
     *     appearance: array{copies_per_page: int, font_size: string, density: string, borders: bool, show_logo: bool, show_qr: bool}
     * }
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * @return list<string>
     */
    public function enabledBlockTypes(): array
    {
        return array_values(array_column(
            array_filter($this->settings['blocks'], fn (array $block): bool => $block['enabled']),
            'type',
        ));
    }

    public function title(): string
    {
        return $this->settings['texts']['title'];
    }

    public function footerNote(): string
    {
        return $this->settings['texts']['footer_note'];
    }

    public function label(string $key): string
    {
        return $this->settings['texts']['labels'][$key]
            ?? ReceiptTemplateDefaults::labels()[$key]
            ?? $key;
    }

    public function copiesPerPage(): int
    {
        return $this->settings['appearance']['copies_per_page'];
    }

    /**
     * @return list<string>
     */
    public function copyTitles(): array
    {
        return $this->copiesPerPage() === 1
            ? ['Для абонента']
            : ['Для организации', 'Для абонента'];
    }

    public function copyCssClasses(): string
    {
        $appearance = $this->settings['appearance'];
        $classes = [];

        if ($appearance['font_size'] !== 'normal') {
            $classes[] = "receipt-font-{$appearance['font_size']}";
        }

        if ($appearance['density'] !== 'normal') {
            $classes[] = "receipt-density-{$appearance['density']}";
        }

        if (! $appearance['borders']) {
            $classes[] = 'receipt-no-borders';
        }

        return implode(' ', $classes);
    }

    public function showLogo(): bool
    {
        return $this->settings['appearance']['show_logo'];
    }

    public function showQr(): bool
    {
        return $this->settings['appearance']['show_qr'];
    }

    public function logoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function qrUrl(): ?string
    {
        return $this->qrUrl;
    }

    /**
     * @param  list<array{type: string, enabled: bool}>  $defaults
     * @return list<array{type: string, enabled: bool}>
     */
    private static function mergeBlocks(mixed $blocks, array $defaults): array
    {
        $defaultEnabled = array_column($defaults, 'enabled', 'type');
        $merged = [];

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;

            if (! is_string($type) || ! array_key_exists($type, $defaultEnabled) || array_key_exists($type, $merged)) {
                continue;
            }

            $merged[$type] = [
                'type' => $type,
                'enabled' => $type === 'header' || (bool) ($block['enabled'] ?? false),
            ];
        }

        foreach ($defaults as $default) {
            if (! array_key_exists($default['type'], $merged)) {
                $merged[$default['type']] = $default;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array{title: string, footer_note: string, labels: array<string, string>}  $defaults
     * @return array{title: string, footer_note: string, labels: array<string, string>}
     */
    private static function mergeTexts(mixed $texts, array $defaults): array
    {
        $texts = is_array($texts) ? $texts : [];
        $labels = [];

        foreach (is_array($texts['labels'] ?? null) ? $texts['labels'] : [] as $key => $value) {
            if (array_key_exists($key, ReceiptTemplateDefaults::labels()) && is_string($value) && trim($value) !== '') {
                $labels[$key] = trim($value);
            }
        }

        $title = $texts['title'] ?? null;
        $footerNote = $texts['footer_note'] ?? null;

        return [
            'title' => is_string($title) && trim($title) !== '' ? trim($title) : $defaults['title'],
            'footer_note' => is_string($footerNote) ? trim($footerNote) : $defaults['footer_note'],
            'labels' => $labels,
        ];
    }

    /**
     * @param  array{copies_per_page: int, font_size: string, density: string, borders: bool, show_logo: bool, show_qr: bool}  $defaults
     * @return array{copies_per_page: int, font_size: string, density: string, borders: bool, show_logo: bool, show_qr: bool}
     */
    private static function mergeAppearance(mixed $appearance, array $defaults): array
    {
        $appearance = is_array($appearance) ? $appearance : [];

        $copiesPerPage = $appearance['copies_per_page'] ?? null;
        $fontSize = $appearance['font_size'] ?? null;
        $density = $appearance['density'] ?? null;

        return [
            'copies_per_page' => in_array($copiesPerPage, ReceiptTemplateDefaults::COPIES_PER_PAGE, true)
                ? $copiesPerPage
                : $defaults['copies_per_page'],
            'font_size' => in_array($fontSize, ReceiptTemplateDefaults::FONT_SIZES, true)
                ? $fontSize
                : $defaults['font_size'],
            'density' => in_array($density, ReceiptTemplateDefaults::DENSITIES, true)
                ? $density
                : $defaults['density'],
            'borders' => (bool) ($appearance['borders'] ?? $defaults['borders']),
            'show_logo' => (bool) ($appearance['show_logo'] ?? $defaults['show_logo']),
            'show_qr' => (bool) ($appearance['show_qr'] ?? $defaults['show_qr']),
        ];
    }
}
