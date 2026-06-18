<?php

namespace App\Reports;

use App\ClientType;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Reports\Contracts\OrganizationReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NewClientAccountsReport implements OrganizationReport
{
    public function slug(): string
    {
        return 'new-client-accounts';
    }

    public function title(): string
    {
        return 'Новые лицевые счета';
    }

    public function description(): ?string
    {
        return 'Абоненты, созданные в текущем открытом или ошибочном расчётном месяце выбранной организации.';
    }

    public function table(Table $table, Organization $organization, User $user): Table
    {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return $table
            ->query($this->query($organization, $user, $billingPeriod))
            ->columns([
                TextColumn::make('account_number')
                    ->label('Лицевой счёт')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('ФИО / Наименование')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client_address')
                    ->label('Адрес')
                    ->state(fn (Client $record): string => $this->formatAddress($record)),
                TextColumn::make('client_type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (ClientType|string|null $state): string => ClientType::labelFor($state) ?? (string) $state),
                TextColumn::make('billing_type')
                    ->label('Тип начисления')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $this->billingTypeLabel($state)),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray')
                    ->formatStateUsing(fn (?string $state): string => $this->statusLabel($state)),
                TextColumn::make('residents_count')
                    ->label('Кол. проживающих')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('current_billing_period_for_report')
                    ->label('Период')
                    ->state(fn (): string => $billingPeriod?->label ?? '-'),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading($billingPeriod instanceof BillingPeriod ? 'Нет новых лицевых счетов' : 'Расчётный месяц не открыт')
            ->emptyStateDescription($billingPeriod instanceof BillingPeriod
                ? 'В текущем расчётном месяце новые лицевые счета не создавались.'
                : 'Откройте расчётный месяц, чтобы увидеть новые лицевые счета.')
            ->striped();
    }

    public function downloadExcel(Organization $organization, User $user): StreamedResponse
    {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return response()->streamDownload(
            function () use ($organization, $user, $billingPeriod): void {
                $writer = new Writer($this->excelOptions());
                $writer->openToFile('php://output');

                $writer->addRow(new Row($this->excelHeadingCells()));

                foreach ($this->query($organization, $user, $billingPeriod)->lazy(500) as $client) {
                    $writer->addRow(new Row($this->excelCells($client, $billingPeriod)));
                }

                $writer->close();
            },
            $this->excelFileName($organization, $billingPeriod),
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
    }

    /**
     * @return Builder<Client>
     */
    private function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        $query = Client::query()
            ->with([
                'region',
                'street',
                'utilityService',
            ])
            ->visibleToOrganizationMember($user, $organization)
            ->orderBy('account_number')
            ->orderBy('id');

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->where('clients.id', 0);
        }

        $periodStartsAt = BillingPeriod::periodStart($billingPeriod->starts_on)->startOfDay();
        $periodEndsAt = $periodStartsAt->endOfMonth()->endOfDay();

        return $query->whereBetween('clients.created_at', [$periodStartsAt, $periodEndsAt]);
    }

    private function formatAddress(Client $client): string
    {
        /** @var Collection<int, string> $parts */
        $parts = collect([
            $client->region?->name,
            $client->street?->name,
            filled($client->house) ? 'д. '.$client->house : null,
            filled($client->apartment) ? 'кв. '.$client->apartment : null,
        ])->filter(fn (?string $part): bool => filled($part));

        return $parts->isEmpty() ? '-' : $parts->implode(', ');
    }

    private function excelFileName(Organization $organization, ?BillingPeriod $billingPeriod): string
    {
        return sprintf(
            'new-client-accounts-%d-%s-%s.xlsx',
            $organization->getKey(),
            $billingPeriod?->code ?? 'no-open-period',
            today()->format('Y-m-d'),
        );
    }

    private function excelOptions(): Options
    {
        $options = new Options;
        $options->setColumnWidth(16, 1);
        $options->setColumnWidth(28, 2);
        $options->setColumnWidth(36, 3);
        $options->setColumnWidth(18, 4);
        $options->setColumnWidth(22, 5);
        $options->setColumnWidth(14, 6);
        $options->setColumnWidth(18, 7);
        $options->setColumnWidth(20, 8);
        $options->setColumnWidth(14, 9);
        $options->setColumnWidth(18, 10);

        return $options;
    }

    /**
     * @return list<Cell>
     */
    private function excelHeadingCells(): array
    {
        $style = (new Style)
            ->setFontBold()
            ->setBackgroundColor(Color::rgb(229, 231, 235));

        return array_map(
            fn (string $heading): StringCell => new StringCell($heading, $style),
            [
                'Лицевой счёт',
                'ФИО / Наименование',
                'Адрес',
                'Тип',
                'Тип начисления',
                'Статус',
                'Кол. проживающих',
                'Телефон',
                'Период',
                'Создан',
            ],
        );
    }

    /**
     * @return list<Cell>
     */
    private function excelCells(Client $client, ?BillingPeriod $billingPeriod): array
    {
        return [
            new StringCell((string) $client->account_number, null),
            new StringCell((string) $client->name, null),
            new StringCell($this->formatAddress($client), (new Style)->setShouldWrapText()),
            new StringCell(ClientType::labelFor($client->client_type) ?? '', null),
            new StringCell($this->billingTypeLabel($client->billing_type), null),
            new StringCell($this->statusLabel($client->status), null),
            $client->residents_count === null
                ? new EmptyCell(null, null)
                : new NumericCell($client->residents_count, null),
            new StringCell((string) ($client->phone ?? ''), null),
            new StringCell($billingPeriod?->label ?? '', null),
            new StringCell($client->created_at?->format('d.m.Y H:i') ?? '', null),
        ];
    }

    private function billingTypeLabel(?string $billingType): string
    {
        return match ($billingType) {
            'meter' => 'По счётчику',
            'per_person' => 'На одного человека',
            'fixed' => 'Фиксированная сумма',
            default => (string) $billingType,
        };
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'active' => 'Активный',
            'inactive' => 'Неактивный',
            default => (string) $status,
        };
    }
}
