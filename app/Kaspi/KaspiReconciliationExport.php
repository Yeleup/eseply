<?php

namespace App\Kaspi;

use App\Models\KaspiOperation;
use App\Models\Organization;
use App\Reports\Concerns\FormatsReportValues;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Writer\XLSX\Options;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kaspi payments of one day by the Kaspi date, as in Kaspi's register: posted
 * and in processing, one row per txn_id. Kaspi numbers and accounts are text,
 * so a spreadsheet never turns them into numbers; the rows are read and
 * written chunk by chunk.
 */
final class KaspiReconciliationExport
{
    use FormatsReportValues;

    public const int CHUNK_SIZE = 500;

    /**
     * @return Builder<KaspiOperation>
     */
    public function query(Organization $organization, CarbonImmutable $day): Builder
    {
        return KaspiOperation::query()
            ->where('organization_id', $organization->getKey())
            ->where('txn_date', '>=', $day->startOfDay())
            ->where('txn_date', '<', $day->startOfDay()->addDay())
            ->where('command', KaspiCommand::Pay->value)
            ->whereIn('state', [KaspiOperationState::Posted->value, KaspiOperationState::Pending->value]);
    }

    /**
     * The rows of the day, chunk by chunk, each operation exactly once.
     *
     * The set is fixed when the export starts — operations accepted while it
     * runs have a larger id and stay out — and the chunks follow the key
     * `(txn_date, id)` instead of an offset, so a new operation with an
     * earlier Kaspi date cannot shift a chunk and repeat a row.
     *
     * @return iterable<KaspiOperation>
     */
    public function rows(Organization $organization, CarbonImmutable $day, int $chunkSize = self::CHUNK_SIZE): iterable
    {
        $lastIdAtStart = $this->query($organization, $day)->max('id');

        if ($lastIdAtStart === null) {
            return;
        }

        $lastTxnDate = null;
        $lastId = 0;

        while (true) {
            $chunk = $this->query($organization, $day)
                ->where('id', '<=', $lastIdAtStart)
                ->when($lastTxnDate !== null, fn (Builder $query): Builder => $query->where(
                    fn (Builder $query): Builder => $query
                        ->where('txn_date', '>', $lastTxnDate)
                        ->orWhere(fn (Builder $query): Builder => $query
                            ->where('txn_date', $lastTxnDate)
                            ->where('id', '>', $lastId)),
                ))
                ->with('client:id,name')
                ->orderBy('txn_date')
                ->orderBy('id')
                ->limit($chunkSize)
                ->get();

            foreach ($chunk as $operation) {
                yield $operation;
            }

            if ($chunk->count() < $chunkSize) {
                return;
            }

            $last = $chunk->last();
            $lastTxnDate = $last->txn_date;
            $lastId = (int) $last->getKey();
        }
    }

    public function download(Organization $organization, CarbonImmutable $day): StreamedResponse
    {
        return $this->downloadXlsx(
            sprintf('kaspi-%d-%s.xlsx', $organization->getKey(), $day->format('Y-m-d')),
            $this->options(),
            ['Номер Kaspi', 'Дата Kaspi', 'Лицевой счёт', 'Абонент', 'Сумма', 'Наш номер оплаты', 'Состояние'],
            fn (): iterable => $this->rows($organization, $day),
            fn (KaspiOperation $operation): array => $this->cells($operation),
        );
    }

    /**
     * @return list<Cell>
     */
    private function cells(KaspiOperation $operation): array
    {
        return [
            new StringCell($operation->txn_id, null),
            new StringCell((string) $operation->txn_date?->format('d.m.Y H:i:s'), null),
            new StringCell($operation->account, null),
            new StringCell((string) $operation->client?->name, null),
            new NumericCell((float) $operation->sum, null),
            new StringCell($operation->payment_id === null ? '' : (string) $operation->payment_id, null),
            new StringCell($operation->state->getLabel(), null),
        ];
    }

    private function options(): Options
    {
        $options = new Options;
        $options->setColumnWidth(22, 1);
        $options->setColumnWidth(20, 2);
        $options->setColumnWidth(14, 3);
        $options->setColumnWidth(34, 4);
        $options->setColumnWidth(14, 5);
        $options->setColumnWidth(18, 6);
        $options->setColumnWidth(16, 7);

        return $options;
    }
}
