<?php

namespace App\Models;

use App\Support\ReceiptTemplateImageStorage;
use Database\Factories\ReceiptTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'logo_path',
    'qr_path',
    'html',
    'css',
    'copies_per_page',
])]
class ReceiptTemplate extends Model
{
    /** @use HasFactory<ReceiptTemplateFactory> */
    use HasFactory;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'copies_per_page' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (ReceiptTemplate $template): void {
            ReceiptTemplateImageStorage::delete($template->logo_path);
            ReceiptTemplateImageStorage::delete($template->qr_path);
        });
    }
}
