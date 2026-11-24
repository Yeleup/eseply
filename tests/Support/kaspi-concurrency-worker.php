<?php

/*
|--------------------------------------------------------------------------
| One side of a concurrency test
|--------------------------------------------------------------------------
|
| KaspiConcurrencyTest starts this script as separate PHP processes, so each
| has its own database connection, as concurrent requests in production do.
| The script boots the application, checks it talks to the very database the
| test verified, reports its connection id, waits for the common start signal
| and then does one thing: a Kaspi request through the HTTP kernel, a payment
| at the desk, a payment form of the panel through Livewire, or an artisan
| command. Before the statement the test waits for, it leaves a marker file.
| On request it kills its own database connection once — right before or
| right after a given statement — as a dropped connection would, so Laravel
| reconnects. It prints the result as JSON.
|
*/

use App\Actions\RecordManualPayment;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Payments\Pages\CreatePayment;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';

/** @var array{type: string, ready_file: string, go_file: string, reached_file: string, wait_for?: string|null, kill_before?: string|null, kill_after?: string|null, database: string} $spec */
$spec = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

// Booted the way tests boot the application; HTTP requests then go through
// the HTTP kernel, as in a feature test.
$app->make(ConsoleKernel::class)->bootstrap();
$kernel = $app->make(Kernel::class);

// The test verified this database before it wiped it; nothing else is touched.
if (! app()->environment('testing') || DB::scalar('select database()') !== $spec['database']) {
    fwrite(STDERR, 'Wrong database or environment.');
    exit(3);
}

if (filled($spec['wait_for'] ?? null)) {
    DB::connection()->beforeExecuting(function (string $query) use ($spec): void {
        if (str_contains(strtolower($query), $spec['wait_for'])) {
            touch($spec['reached_file']);
        }
    });
}

$connectionId = (int) DB::scalar('select connection_id()');
$killed = 0;

if (filled($spec['kill_before'] ?? null) || filled($spec['kill_after'] ?? null)) {
    // Another connection of this process kills the main one between two
    // statements and waits until the server has dropped it.
    config(['database.connections.concurrency_killer' => config('database.connections.'.config('database.default'))]);
    $killer = DB::connection('concurrency_killer');
    $killer->getPdo();

    $kill = function () use ($killer, $connectionId, &$killed): void {
        if ($killed > 0) {
            return;
        }

        $killed++;
        $killer->statement('kill connection '.$connectionId);

        $waitUntil = microtime(true) + 5;

        while ((int) $killer->scalar('select count(*) from information_schema.processlist where id = ?', [$connectionId]) > 0
            && microtime(true) < $waitUntil) {
            usleep(5000);
        }
    };

    if (filled($spec['kill_before'] ?? null)) {
        DB::connection()->beforeExecuting(function (string $query) use ($spec, $kill): void {
            if (str_contains(strtolower($query), $spec['kill_before'])) {
                $kill();
            }
        });
    }

    if (filled($spec['kill_after'] ?? null)) {
        $mainConnection = DB::connection()->getName();

        DB::listen(function (QueryExecuted $query) use ($spec, $kill, $mainConnection): void {
            if ($query->connectionName === $mainConnection && str_contains(strtolower($query->sql), $spec['kill_after'])) {
                $kill();
            }
        });
    }
}

file_put_contents($spec['ready_file'], (string) $connectionId);

$waitUntil = microtime(true) + 30;

while (! file_exists($spec['go_file'])) {
    if (microtime(true) > $waitUntil) {
        fwrite(STDERR, 'No start signal.');
        exit(2);
    }

    usleep(2000);
}

$startedAt = microtime(true);

$actAsOperator = function (array $spec): void {
    Livewire::actingAs(User::query()->findOrFail($spec['user_id']));
    Filament::setCurrentPanel('admin');
    Filament::setTenant(Organization::query()->findOrFail($spec['organization_id']));
    Filament::bootCurrentPanel();
};

// An exception of the step fails the side with its message, never a silent exit 0.
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
});

switch ($spec['type']) {
    case 'http':
        $request = Request::create($spec['uri'], 'GET', server: $spec['server']);
        $response = $kernel->handle($request);
        $result = ['status' => $response->getStatusCode(), 'body' => $response->getContent()];
        $kernel->terminate($request, $response);
        break;

    case 'manual':
        try {
            $payment = app(RecordManualPayment::class)->handle(
                Organization::query()->findOrFail($spec['organization_id']),
                (int) $spec['client_id'],
                ['amount' => $spec['amount'], 'paid_at' => $spec['paid_at']],
                null,
            );
            $result = ['payment_id' => $payment->getKey()];
        } catch (ValidationException $exception) {
            $result = ['error' => $exception->validator->errors()->first()];
        }
        break;

    case 'form_payments':
        $actAsOperator($spec);
        $page = Livewire::test(CreatePayment::class)
            ->fillForm(['client_id' => $spec['client_id'], 'amount' => $spec['amount'], 'paid_at' => $spec['paid_at']])
            ->call('create');
        $result = ['errors' => $page->errors()->all()];
        break;

    case 'form_card':
        $actAsOperator($spec);
        $page = Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => Client::query()->findOrFail($spec['client_id']),
            'pageClass' => EditClient::class,
        ])->callTableAction('create', data: ['amount' => $spec['amount'], 'paid_at' => $spec['paid_at']]);
        $result = ['errors' => $page->errors()->all()];
        break;

    case 'artisan':
        $result = ['exit' => Artisan::call($spec['command'])];
        break;

    default:
        fwrite(STDERR, 'Unknown step.');
        exit(2);
}

$result['seconds'] = round(microtime(true) - $startedAt, 3);

if (filled($spec['kill_before'] ?? null) || filled($spec['kill_after'] ?? null)) {
    $result['killed'] = $killed;
    $result['reconnected'] = (int) DB::scalar('select connection_id()') !== $connectionId;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
