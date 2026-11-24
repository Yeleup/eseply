<?php

/*
|--------------------------------------------------------------------------
| One side of a concurrency test
|--------------------------------------------------------------------------
|
| KaspiConcurrencyTest starts this script as separate PHP processes, so each
| has its own database connection, as concurrent requests in production do.
| The script boots the application, says it is ready, waits for the common
| start signal and then does one thing: a Kaspi request through the HTTP
| kernel, a payment at the desk, or an artisan command. It prints the result
| as JSON.
|
*/

use App\Actions\RecordManualPayment;
use App\Models\Organization;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';

/** @var array{type: string, ready_file: string, go_file: string} $spec */
$spec = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

// Booted the way tests boot the application; HTTP requests then go through
// the HTTP kernel, as in a feature test.
$app->make(ConsoleKernel::class)->bootstrap();
$kernel = $app->make(Kernel::class);

touch($spec['ready_file']);

$waitUntil = microtime(true) + 30;

while (! file_exists($spec['go_file'])) {
    if (microtime(true) > $waitUntil) {
        fwrite(STDERR, 'No start signal.');
        exit(2);
    }

    usleep(2000);
}

$startedAt = microtime(true);

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

    case 'artisan':
        $result = ['exit' => Artisan::call($spec['command'])];
        break;

    default:
        fwrite(STDERR, 'Unknown step.');
        exit(2);
}

$result['seconds'] = round(microtime(true) - $startedAt, 3);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
