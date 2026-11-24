<?php

namespace App\Http\Controllers;

use App\Kaspi\KaspiCommand;
use App\Kaspi\KaspiDeadline;
use App\Kaspi\KaspiGateway;
use App\Kaspi\KaspiInput;
use App\Kaspi\KaspiResponse;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * `GET /kaspi/{uid}`: `check` and `pay` of Kaspi online for one organization.
 *
 * Every answer is JSON with HTTP 200; anything that is not a valid request to
 * a known organization gets result 5 and creates nothing.
 */
class KaspiController extends Controller
{
    public function __invoke(Request $request, string $uid, KaspiGateway $gateway): Response
    {
        $input = KaspiInput::fromRequest($request);

        $organization = Str::isUuid($uid)
            ? Organization::query()->where('kaspi_uid', $uid)->first()
            : null;

        if (! $organization instanceof Organization) {
            return KaspiResponse::http(KaspiResponse::error($input->responseTxnId));
        }

        if (! $input->isValid()) {
            // Which rule failed, never the values: the journal has no raw requests either.
            Log::info('Kaspi: запрос не прошёл проверку', [
                'organization_id' => $organization->getKey(),
                'rule' => $input->error,
            ]);

            return KaspiResponse::http(KaspiResponse::error($input->responseTxnId));
        }

        $deadline = KaspiDeadline::in((float) config('kaspi.lock_wait_seconds'));

        try {
            $body = $input->command === KaspiCommand::Check
                ? $gateway->check($organization, $input, $deadline)
                : $gateway->pay($organization, $input, $deadline);
        } catch (Throwable $exception) {
            report($exception);

            $body = KaspiResponse::error($input->responseTxnId);
        } finally {
            try {
                $deadline->restore();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return KaspiResponse::http($body);
    }
}
