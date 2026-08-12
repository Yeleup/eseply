<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ReceiptTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceiptTemplate>
 */
class ReceiptTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'logo_path' => null,
            'qr_path' => null,
            'html' => null,
            'css' => null,
            'copies_per_page' => 2,
        ];
    }
}
