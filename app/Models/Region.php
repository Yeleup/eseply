<?php

namespace App\Models;

use Database\Factories\RegionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'city_id',
    'name',
])]
class Region extends Model
{
    /** @use HasFactory<RegionFactory> */
    use HasFactory;

    public $timestamps = false;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function streets(): HasMany
    {
        return $this->hasMany(Street::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Region $region): void {
            if (! $region->city_id) {
                return;
            }

            $region->organization_id = City::query()
                ->whereKey($region->city_id)
                ->value('organization_id');
        });
    }
}
