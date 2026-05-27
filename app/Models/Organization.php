<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Filament\Models\Contracts\HasCurrentTenantLabel;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'bin_iin', 'phone', 'address', 'bank', 'iban', 'note'])]
class Organization extends Model implements HasCurrentTenantLabel, HasName
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function utilityServices(): HasMany
    {
        return $this->hasMany(UtilityService::class);
    }

    public function tariffCategories(): HasMany
    {
        return $this->hasMany(TariffCategory::class);
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class);
    }

    public function normatives(): HasMany
    {
        return $this->hasMany(Normative::class);
    }

    public function accruals(): HasMany
    {
        return $this->hasMany(Accrual::class);
    }

    public function meters(): HasMany
    {
        return $this->hasMany(Meter::class);
    }

    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getCurrentTenantLabel(): string
    {
        return 'Организация';
    }
}
