<?php

namespace App\Filament\Support;

use App\Models\Payment;
use App\PaymentMethod;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Collection;

/**
 * Parts every payment table shares: the payment method with its origin, and a
 * bulk delete that never touches a payment from an integration.
 */
final class PaymentTableParts
{
    /**
     * Method badge with the Kaspi number of an integration payment, or the
     * note that an old Kaspi payment was entered by hand.
     */
    public static function methodColumn(): TextColumn
    {
        return TextColumn::make('method')
            ->label('Способ')
            ->badge()
            ->formatStateUsing(fn (mixed $state): string => PaymentMethod::labelFor($state) ?? (string) $state)
            ->color(fn (mixed $state): string => PaymentMethod::colorFor($state))
            ->description(fn (Payment $record): ?string => $record->methodNote());
    }

    /**
     * A selection with an integration payment is not deleted at all, so the
     * operator never gets a half-deleted selection and sees why.
     */
    public static function deleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->before(function (DeleteBulkAction $action, Collection $records): void {
                $integrationPayments = $records->filter(
                    fn (mixed $record): bool => $record instanceof Payment && $record->isFromIntegration(),
                );

                if ($integrationPayments->isEmpty()) {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->title('Оплаты не удалены')
                    ->body(sprintf(
                        'В выборке оплат из Kaspi: %d. Оплату из интеграции нельзя удалить, поэтому не удалена ни одна оплата. Снимите такие оплаты с выборки; ошибку в них исправляют корректировкой сальдо.',
                        $integrationPayments->count(),
                    ))
                    ->persistent()
                    ->send();

                $action->cancel();
            });
    }
}
