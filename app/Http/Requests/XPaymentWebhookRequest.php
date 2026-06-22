<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class XPaymentWebhookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'event' => ['nullable'],
            'status' => ['nullable', 'string', 'max:255'],
            'merchant_order_id' => ['nullable', 'string', 'max:255'],
            'payment_id' => ['nullable', 'string', 'max:255'],
            'data' => ['nullable', 'array'],
            'data.event' => ['nullable'],
            'data.status' => ['nullable', 'string', 'max:255'],
            'data.merchant_order_id' => ['nullable', 'string', 'max:255'],
            'data.payment_id' => ['nullable', 'string', 'max:255'],
            'data.completed_at' => ['nullable', 'string', 'max:255'],
            'data.cancelled_at' => ['nullable', 'string', 'max:255'],
            'completed_at' => ['nullable', 'string', 'max:255'],
            'cancelled_at' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->merchantOrderId() !== null || $this->paymentId() !== null) {
                    return;
                }

                $validator->errors()->add(
                    'merchant_order_id',
                    'Webhook должен содержать merchant_order_id или payment_id.',
                );
            },
        ];
    }

    public function merchantOrderId(): ?string
    {
        return $this->stringValue('merchant_order_id')
            ?? $this->stringValue('data.merchant_order_id');
    }

    public function paymentId(): ?string
    {
        return $this->stringValue('payment_id')
            ?? $this->stringValue('data.payment_id');
    }

    private function stringValue(string $key): ?string
    {
        $value = data_get($this->all(), $key);

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }
}
