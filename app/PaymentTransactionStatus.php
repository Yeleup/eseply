<?php

namespace App;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

enum PaymentTransactionStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Pending => 'Ожидает оплаты',
            self::Completed => 'Оплачено',
            self::Failed => 'Ошибка',
            self::Cancelled => 'Отменено',
            self::Expired => 'Истекло',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
            self::Expired => 'gray',
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function shouldCreatePayment(): bool
    {
        return $this === self::Completed;
    }

    public static function fromXPaymentStatus(?string $status = null, ?string $event = null): self
    {
        $value = Str::of($status ?: $event ?: '')
            ->lower()
            ->replace(['payment.', '_'], ['', '-'])
            ->value();

        return match ($value) {
            'completed', 'complete', 'success', 'succeeded', 'paid' => self::Completed,
            'failed', 'failure', 'error', 'declined' => self::Failed,
            'cancelled', 'canceled', 'cancel' => self::Cancelled,
            'expired', 'qr-expired' => self::Expired,
            default => self::Pending,
        };
    }

    public static function labelFor(self|string|null $value): ?string
    {
        if ($value instanceof self) {
            return $value->getLabel();
        }

        return self::tryFrom((string) $value)?->getLabel();
    }

    public static function colorFor(self|string|null $value): string
    {
        if ($value instanceof self) {
            return $value->color();
        }

        return self::tryFrom((string) $value)?->color() ?? 'gray';
    }
}
