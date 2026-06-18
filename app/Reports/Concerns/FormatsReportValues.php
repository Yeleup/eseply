<?php

namespace App\Reports\Concerns;

use App\ClientType;
use App\Models\BillingPeriod;
use App\Models\Client;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait FormatsReportValues
{
    protected function formatClientAddress(?Client $client): string
    {
        if (! $client) {
            return '-';
        }

        /** @var Collection<int, string> $parts */
        $parts = collect([
            $client->region?->name,
            $client->street?->name,
            filled($client->house) ? 'д. '.$client->house : null,
            filled($client->apartment) ? 'кв. '.$client->apartment : null,
        ])->filter(fn (?string $part): bool => filled($part));

        return $parts->isEmpty() ? '-' : $parts->implode(', ');
    }

    protected function clientTypeLabel(ClientType|string|null $clientType): string
    {
        return ClientType::labelFor($clientType) ?? (string) $clientType;
    }

    protected function billingTypeLabel(?string $billingType): string
    {
        return match ($billingType) {
            'meter' => 'По счётчику',
            'per_person' => 'На одного человека',
            'fixed' => 'Фиксированная сумма',
            default => (string) $billingType,
        };
    }

    protected function clientStatusLabel(?string $status): string
    {
        return match ($status) {
            'active' => 'Активный',
            'inactive' => 'Неактивный',
            default => (string) $status,
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function billingPeriodDateRange(BillingPeriod $billingPeriod): array
    {
        $startsOn = CarbonImmutable::instance($billingPeriod->starts_on)->startOfMonth();

        return [
            $startsOn->toDateString(),
            $startsOn->endOfMonth()->toDateString(),
        ];
    }

    protected function isDateInBillingPeriod(DateTimeInterface|string|null $date, ?BillingPeriod $billingPeriod): bool
    {
        if ($date === null || ! $billingPeriod instanceof BillingPeriod) {
            return false;
        }

        $checkedDate = $date instanceof DateTimeInterface
            ? CarbonImmutable::instance($date)
            : CarbonImmutable::parse($date);

        [$startsOn, $endsOn] = $this->billingPeriodDateRange($billingPeriod);

        return $checkedDate->betweenIncluded(
            CarbonImmutable::parse($startsOn)->startOfDay(),
            CarbonImmutable::parse($endsOn)->endOfDay(),
        );
    }

    /**
     * @param  list<string>  $headings
     * @return list<Cell>
     */
    protected function excelHeadingCells(array $headings): array
    {
        $style = (new Style)
            ->setFontBold()
            ->setBackgroundColor(Color::rgb(229, 231, 235));

        return array_map(
            fn (string $heading): StringCell => new StringCell($heading, $style),
            $headings,
        );
    }

    /**
     * @param  callable(): iterable<object>  $records
     * @param  callable(object): list<Cell>  $cells
     * @param  list<string>  $headings
     */
    protected function downloadXlsx(string $fileName, Options $options, array $headings, callable $records, callable $cells): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($options, $headings, $records, $cells): void {
                $writer = new Writer($options);
                $writer->openToFile('php://output');

                $writer->addRow(new Row($this->excelHeadingCells($headings)));

                foreach ($records() as $record) {
                    $writer->addRow(new Row($cells($record)));
                }

                $writer->close();
            },
            $fileName,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
    }
}
