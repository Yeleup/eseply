<?php

namespace App\Actions;

use App\Models\Receipt;
use App\Support\ReceiptTemplateDefaults;
use App\Support\ReceiptTemplateHtmlSanitizer;
use App\Support\ReceiptTemplateRenderer;
use App\Support\ReceiptTemplateVariables;
use Illuminate\Support\Carbon;

class BuildReceiptPrintViewData
{
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

        $template = $receipt->organization?->receiptTemplate;
        $generatedAt = now();

        $hasCustomTemplate = filled($template?->html);
        $html = ReceiptTemplateHtmlSanitizer::sanitizeHtml(
            $hasCustomTemplate ? (string) $template->html : ReceiptTemplateDefaults::html(),
        );
        $css = ReceiptTemplateHtmlSanitizer::sanitizeCss(
            $hasCustomTemplate ? (string) $template->css : ReceiptTemplateDefaults::css(),
        );

        $copiesPerPage = $template?->copies_per_page === 1 ? 1 : 2;
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
}
