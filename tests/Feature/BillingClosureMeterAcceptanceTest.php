<?php

use App\Actions\AcceptBillingClosureMeterReadings;
use App\Actions\CloseBillingMonth;
use App\BillingPeriodStatus;
use App\Filament\Resources\Accruals\Pages\ListAccruals;
use App\Filament\Resources\BillingPeriods\Pages\ListBillingPeriodClosureErrors;
use App\Filament\Resources\BillingPeriods\Pages\ListBillingPeriods;
use App\Jobs\CloseBillingMonthJob;
use App\Models\Accrual;
use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\Tariff;
use App\Models\User;
use App\OrganizationMemberRole;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->meter = Meter::factory()->for($this->organization)->create(['initial_reading' => 100]);
    $this->period = billingPeriodFor($this->organization);
    Tariff::factory()->for($this->organization)->create([
        'utility_service_id' => $this->meter->utility_service_id,
        'client_type' => $this->meter->client->client_type,
        'unit_price' => 10,
        'starts_on' => '2020-01-01',
    ]);
    $this->operator = User::factory()->create();
    $this->operator->organizations()->attach($this->organization, ['role' => OrganizationMemberRole::Operator->value]);
    Livewire::actingAs($this->operator);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->organization);
    Filament::bootCurrentPanel();
});

test('missing readings are carried forward including visits and multiple meters of one client', function () {
    $this->meter->readings()->create(['billing_period_id' => $this->period->id, 'current_reading' => 140]);
    app(CloseBillingMonth::class)->handle($this->organization, $this->period);
    $this->period = BillingPeriod::openNextFor($this->organization);
    $visit = $this->meter->readings()->create([
        'billing_period_id' => $this->period->id, 'current_reading' => null, 'note' => 'Нет доступа',
    ]);
    $secondMeter = Meter::factory()->for($this->organization)->for($this->meter->client)->create(['initial_reading' => 50]);
    app(CloseBillingMonth::class)->handle($this->organization, $this->period);

    Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $this->period->id])
        ->callAction('acceptMissingReadings')->assertHasNoActionErrors();

    expect($visit->refresh()->current_reading)->toBe(140)
        ->and($visit->previous_reading)->toBe(140)
        ->and($visit->consumption)->toBe(0)
        ->and($visit->note)->toBe('Нет доступа')
        ->and($visit->closure_accepted_by_user_id)->toBe($this->operator->id)
        ->and($secondMeter->readings()->firstOrFail()->current_reading)->toBe(50);
    app(CloseBillingMonth::class)->handle($this->organization, $this->period);
    expect($this->period->refresh()->status)->toBe(BillingPeriodStatus::Closed);
});

test('operator accepts negative consumption unchanged and closes with a reduced balance', function () {
    $reading = $this->meter->readings()->create(['billing_period_id' => $this->period->id, 'current_reading' => 90]);
    app(CloseBillingMonth::class)->handle($this->organization, $this->period);
    expect($this->period->refresh()->status)->toBe(BillingPeriodStatus::Failed);
    $error = $this->period->closureErrors()->firstOrFail();

    Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $this->period->id])
        ->callAction(TestAction::make('acceptMeterReading')->table($error))->assertHasNoActionErrors();

    expect($reading->refresh()->consumption)->toBe(-10)
        ->and($reading->current_reading)->toBe(90)
        ->and($reading->previous_reading)->toBe(100)
        ->and($reading->closure_accepted_by_user_id)->toBe($this->operator->id)
        ->and($reading->closure_accepted_at)->not->toBeNull();
    app(CloseBillingMonth::class)->handle($this->organization, $this->period);
    $accrual = Accrual::query()->where('billing_period_id', $this->period->id)->firstOrFail();
    expect($this->period->refresh()->status)->toBe(BillingPeriodStatus::Closed)
        ->and((float) $accrual->amount)->toBe(-100.0)
        ->and((float) $accrual->closing_balance)->toBe(-100.0)
        ->and((float) Receipt::query()->where('billing_period_id', $this->period->id)->firstOrFail()->amount)->toBe(-100.0);
});

test('changing an accepted reading revokes acceptance but editing its note does not', function () {
    $reading = $this->meter->readings()->create(['billing_period_id' => $this->period->id, 'current_reading' => 90]);
    app(AcceptBillingClosureMeterReadings::class)->handle($this->organization, $this->period, $this->operator, 'negative_meter_consumption');
    $reading->refresh()->update(['note' => 'Исправление оператора']);
    expect($reading->refresh()->closure_accepted_at)->not->toBeNull();
    $reading->update(['current_reading' => 80]);
    expect($reading->refresh()->closure_accepted_at)->toBeNull();
    app(CloseBillingMonth::class)->handle($this->organization, $this->period);
    expect($this->period->refresh()->status)->toBe(BillingPeriodStatus::Failed);
});

test('bulk negative acceptance covers meters beyond the first reported error', function () {
    $second = Meter::factory()->for($this->organization)->for($this->meter->client)->create(['initial_reading' => 200]);
    foreach ([$this->meter, $second] as $meter) {
        $meter->readings()->create(['billing_period_id' => $this->period->id, 'current_reading' => 90]);
    }
    app(CloseBillingMonth::class)->handle($this->organization, $this->period);
    Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $this->period->id])
        ->callAction('acceptNegativeConsumptions')->assertHasNoActionErrors();
    app(CloseBillingMonth::class)->handle($this->organization, $this->period);
    expect($this->period->refresh()->status)->toBe(BillingPeriodStatus::Closed);
});

test('acceptance refuses a locked billing period', function (BillingPeriodStatus $status) {
    $this->period->update(['status' => $status]);
    expect(fn () => app(AcceptBillingClosureMeterReadings::class)->handle($this->organization, $this->period, $this->operator, 'missing_meter_reading'))
        ->toThrow(ValidationException::class);
    expect(MeterReading::query()->count())->toBe(0);
})->with([BillingPeriodStatus::Processing, BillingPeriodStatus::Closed]);

test('acceptance refuses controllers and outsiders', function (bool $isController) {
    $user = User::factory()->create();
    if ($isController) {
        $user->organizations()->attach($this->organization, ['role' => OrganizationMemberRole::Controller->value]);
    }
    expect(fn () => app(AcceptBillingClosureMeterReadings::class)->handle($this->organization, $this->period, $user, 'missing_meter_reading'))
        ->toThrow(HttpException::class);
    expect(MeterReading::query()->count())->toBe(0);
})->with([true, false]);

test('a stale missing reading error never overwrites an entered reading', function () {
    app(CloseBillingMonth::class)->handle($this->organization, $this->period);
    $error = $this->period->closureErrors()->firstOrFail();
    $reading = $this->meter->readings()->create(['billing_period_id' => $this->period->id, 'current_reading' => 120]);
    Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $this->period->id])
        ->callAction(TestAction::make('acceptMeterReading')->table($error))->assertHasNoActionErrors();
    expect($reading->refresh()->current_reading)->toBe(120)->and($reading->consumption)->toBe(20);
});

test('closure can be queued again directly from the error report', function () {
    Queue::fake();
    app(CloseBillingMonth::class)->handle($this->organization, $this->period);
    Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $this->period->id])
        ->callAction('acceptMissingReadings')
        ->callAction('closeBillingMonth')->assertHasNoActionErrors();
    expect($this->period->refresh()->status)->toBe(BillingPeriodStatus::Processing);
    Queue::assertPushed(CloseBillingMonthJob::class);
});

test('acceptance cannot change a meter or period from another organization', function () {
    Filament::setTenant(null);
    $otherOrganization = Organization::factory()->create();
    $otherMeter = Meter::factory()->for($otherOrganization)->create();
    $otherPeriod = billingPeriodFor($otherOrganization);
    Filament::setTenant($this->organization);

    expect(app(AcceptBillingClosureMeterReadings::class)->handle(
        $this->organization, $this->period, $this->operator, 'missing_meter_reading', $otherMeter->id,
    ))->toBe(0);
    expect(fn () => app(AcceptBillingClosureMeterReadings::class)->handle(
        $this->organization, $otherPeriod, $this->operator, 'missing_meter_reading',
    ))->toThrow(ModelNotFoundException::class);
    expect($otherMeter->readings()->count())->toBe(0);
});

test('repeating acceptance keeps the original confirmation and consumption', function () {
    $reading = $this->meter->readings()->create(['billing_period_id' => $this->period->id, 'current_reading' => 90]);
    $action = app(AcceptBillingClosureMeterReadings::class);
    expect($action->handle($this->organization, $this->period, $this->operator, 'negative_meter_consumption'))->toBe(1);
    $acceptedAt = $reading->refresh()->closure_accepted_at;
    $this->travel(1)->hour();
    expect($action->handle($this->organization, $this->period, $this->operator, 'negative_meter_consumption'))->toBe(0)
        ->and($reading->refresh()->closure_accepted_at->equalTo($acceptedAt))->toBeTrue()
        ->and($reading->consumption)->toBe(-10);
});

test('acceptance buttons follow the reported error codes and disappear after resolution', function () {
    $page = Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $this->period->id])
        ->assertActionHidden('acceptMissingReadings')
        ->assertActionHidden('acceptNegativeConsumptions');
    app(CloseBillingMonth::class)->handle($this->organization, $this->period);
    $page->call('$refresh')->assertActionVisible('acceptMissingReadings')
        ->assertActionHidden('acceptNegativeConsumptions')
        ->callAction('acceptMissingReadings')->assertActionHidden('acceptMissingReadings');
    $this->meter->readings()->firstOrFail()->update(['current_reading' => 90]);
    app(CloseBillingMonth::class)->handle($this->organization, $this->period);
    $page->call('$refresh')->assertActionHidden('acceptMissingReadings')
        ->assertActionVisible('acceptNegativeConsumptions')
        ->callAction('acceptNegativeConsumptions')->assertActionHidden('acceptNegativeConsumptions');
});

test('billing pages poll and reflect a completed background closure', function () {
    $this->meter->readings()->create(['billing_period_id' => $this->period->id, 'current_reading' => 110]);
    $period = app(CloseBillingMonth::class)->claim($this->organization, $this->period, $this->operator);
    $accruals = Livewire::test(ListAccruals::class)->assertSee('wire:poll.5s', false);
    $periods = Livewire::test(ListBillingPeriods::class)->assertSee('wire:poll.5s', false);
    $errors = Livewire::test(ListBillingPeriodClosureErrors::class, ['record' => $period->id])->assertSee('wire:poll.5s', false);
    app(CloseBillingMonth::class)->run($this->organization, $period, $this->operator);
    $accrual = Accrual::query()->where('billing_period_id', $period->id)->firstOrFail();
    $accruals->call('$refresh')->assertCanSeeTableRecords([$accrual])->assertDontSee('Идёт расчёт начислений');
    $periods->call('$refresh')->assertTableColumnStateSet('status', BillingPeriodStatus::Closed, $period);
    $errors->call('$refresh')->assertSee('Месяц закрыт')->assertDontSee('Закрытие завершилось ошибкой.')
        ->assertActionDisabled('closeBillingMonth');
});
