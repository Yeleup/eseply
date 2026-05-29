<?php

namespace App\Models;

use Database\Factories\StreetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'region_id',
    'name',
])]
class Street extends Model
{
    /** @use HasFactory<StreetFactory> */
    use HasFactory;

    public $timestamps = false;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Street $street): void {
            if (! $street->region_id) {
                return;
            }

            $street->organization_id = Region::query()
                ->whereKey($street->region_id)
                ->value('organization_id');
        });
    }
}
