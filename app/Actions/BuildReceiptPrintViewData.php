<?php

namespace App\Actions;

use App\Models\Organization;
use App\Models\Receipt;
use App\Support\ReceiptTemplateDefaults;
use App\Support\ReceiptTemplateHtmlSanitizer;
use App\Support\ReceiptTemplateRenderer;
use App\Support\ReceiptTemplateVariables;
use Illuminate\Support\Carbon;

class BuildReceiptPrintViewData
{
    /**
     * Кэш санитизированного шаблона по organization_id в пределах одного
     * запроса: массовая печать вызывает handle() по каждой квитанции одной
     * и той же организации, а html/css/copiesPerPage у них идентичны.
     * Экшен инъектится в метод контроллера (не singleton), поэтому
     * instance-свойство не переживает запрос и безопасно под Octane.
     *
     * @var array<int, array{html: string, css: string, copiesPerPage: int}>
     */
    private array $templateCache = [];

    public function __construct(
        private readonly BuildReceiptMeterReadingLines $buildReceiptMeterReadingLines,
    ) {}

    /**
     * @return array{
     *     receipt: Receipt,
     *     generatedAt: Carbon,
     *     copiesPerPage: int,
     *     renderedCopies: array<string, string>,
     *     templateCss: string
     * }
     */
    public function handle(Receipt $receipt): array
    {
        $receipt->loadMissing([
            'billingPeriod',
            'client.region',
            'client.street',
            'organization.utilityService',
            'organization.receiptTemplate',
        ]);

        $generatedAt = now();

        ['html' => $html, 'css' => $css, 'copiesPerPage' => $copiesPerPage] = $this->resolveTemplate($receipt->organization);

        $copyTitles = $copiesPerPage === 1
            ? ['Для абонента']
            : ['Для организации', 'Для абонента'];

        $fragments = ReceiptTemplateVariables::fragments(
            $receipt,
            $this->buildReceiptMeterReadingLines->handle($receipt),
            $generatedAt,
        );

        $renderedCopies = [];

        foreach ($copyTitles as $copyTitle) {
            $renderedCopies[$copyTitle] = ReceiptTemplateRenderer::render(
                $html,
                ReceiptTemplateVariables::values($receipt, $copyTitle, $generatedAt),
                $fragments,
            );
        }

        return [
            'receipt' => $receipt,
            'generatedAt' => $generatedAt,
            'copiesPerPage' => $copiesPerPage,
            'renderedCopies' => $renderedCopies,
            'templateCss' => $css,
        ];
    }

    /**
     * Санитизированные html/css и copiesPerPage не зависят от квитанции —
     * только от шаблона организации, поэтому вычисляются один раз за запрос
     * и кэшируются по organization_id. Санитизация всё равно повторяется
     * при каждом обращении к новой организации (defense-in-depth), просто
     * не на каждую квитанцию батча.
     *
     * @return array{html: string, css: string, copiesPerPage: int}
     */
    private function resolveTemplate(?Organization $organization): array
    {
        $cacheKey = $organization?->getKey() ?? 0;

        if (array_key_exists($cacheKey, $this->templateCache)) {
            return $this->templateCache[$cacheKey];
        }

        $template = $organization?->receiptTemplate;
        $hasCustomTemplate = filled($template?->html);

        return $this->templateCache[$cacheKey] = [
            'html' => ReceiptTemplateHtmlSanitizer::sanitizeHtml(
                $hasCustomTemplate ? (string) $template->html : ReceiptTemplateDefaults::html(),
            ),
            'css' => ReceiptTemplateHtmlSanitizer::sanitizeCss(
                $hasCustomTemplate ? (string) $template->css : ReceiptTemplateDefaults::css(),
            ),
            'copiesPerPage' => $template?->copies_per_page === 1 ? 1 : 2,
        ];
    }
}
