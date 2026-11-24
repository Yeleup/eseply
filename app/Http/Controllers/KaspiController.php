<?php

namespace App\Http\Controllers;

use App\Kaspi\KaspiActivity;
use App\Kaspi\KaspiCommand;
use App\Kaspi\KaspiDeadline;
use App\Kaspi\KaspiGateway;
use App\Kaspi\KaspiInput;
use App\Kaspi\KaspiResponse;
use App\Models\Organization;
use Carbon\CarbonImmutable;
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
        // The moment the request came in, before any work: the journal and the
        // statistics count by it.
        $receivedAt = now()->toImmutable();
        $input = KaspiInput::fromRequest($request);
        $deadline = KaspiDeadline::in((float) config('kaspi.lock_wait_seconds'));
        $organization = null;

        try {
            $organization = Str::isUuid($uid)
                ? Organization::query()->where('kaspi_uid', $uid)->first()
                : null;

            $body = $this->answer($organization, $input, $deadline, $gateway, $receivedAt);
        } catch (Throwable $exception) {
            // The database included: any failure is result 5, never a page of an error.
            report($exception);

            $body = KaspiResponse::error($input->responseTxnId);
        }

        try {
            if ($organization instanceof Organization) {
                KaspiActivity::record($organization, KaspiResponse::resultOf($body), $receivedAt, $deadline);
            }
        } finally {
            $this->restore($deadline);
        }

        return KaspiResponse::http($body);
    }

    private function answer(?Organization $organization, KaspiInput $input, KaspiDeadline $deadline, KaspiGateway $gateway, CarbonImmutable $receivedAt): string
    {
        if (! $organization instanceof Organization) {
            return KaspiResponse::error($input->responseTxnId);
        }

        if (! $input->isValid()) {
            // Which rule failed, never the values: the journal has no raw requests either.
            Log::info('Kaspi: запрос не прошёл проверку', [
                'organization_id' => $organization->getKey(),
                'rule' => $input->error,
            ]);

            return KaspiResponse::error($input->responseTxnId);
        }

        return $input->command === KaspiCommand::Check
            ? $gateway->check($organization, $input, $deadline, $receivedAt)
            : $gateway->pay($organization, $input, $deadline, $receivedAt);
    }

    private function restore(KaspiDeadline $deadline): void
    {
        try {
            $deadline->restore();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
