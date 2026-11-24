<?php

namespace App\Kaspi;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Throwable;

/**
 * Parameters of one Kaspi request, checked exactly as they arrived.
 *
 * The query string is read raw: a repeated or an array parameter is an error,
 * which PHP's own parsing would hide by keeping the last value. Nothing is
 * trimmed and leading zeros stay, so the account is the one Kaspi sent.
 */
final readonly class KaspiInput
{
    public const string ACCOUNT_PATTERN = '/\A[0-9]{6,10}\z/';

    private const string TXN_ID_PATTERN = '/\A[0-9]{1,18}\z/';

    private const string SUM_PATTERN = '/\A([0-9]{1,12})\.([0-9]{2})\z/';

    private const string TXN_DATE_PATTERN = '/\A[0-9]{14}\z/';

    /**
     * @param  string  $responseTxnId  The txn_id to echo: as it came, or "" when missing or invalid.
     * @param  string|null  $error  Which rule failed; null for a valid request.
     * @param  string|null  $sum  Canonical sum of `pay` with two decimals, for example `4250.00`.
     * @param  CarbonImmutable|null  $txnDate  Kaspi date of `pay`, wall clock as sent, no time zone conversion.
     */
    private function __construct(
        public string $responseTxnId,
        public ?string $error = null,
        public ?KaspiCommand $command = null,
        public string $txnId = '',
        public string $account = '',
        public ?string $sum = null,
        public ?CarbonImmutable $txnDate = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        [$parameters, $isMalformed] = self::parameters($request);

        $responseTxnId = is_string($parameters['txn_id'] ?? null) && preg_match(self::TXN_ID_PATTERN, $parameters['txn_id']) === 1
            ? $parameters['txn_id']
            : '';

        if ($isMalformed) {
            return new self($responseTxnId, 'repeated_or_array_parameter');
        }

        $command = KaspiCommand::tryFrom((string) ($parameters['command'] ?? ''));

        if (! $command instanceof KaspiCommand) {
            return new self($responseTxnId, 'command');
        }

        if ($responseTxnId === '') {
            return new self($responseTxnId, 'txn_id');
        }

        $account = $parameters['account'] ?? null;

        if (! is_string($account) || preg_match(self::ACCOUNT_PATTERN, $account) !== 1) {
            return new self($responseTxnId, 'account');
        }

        if ($command === KaspiCommand::Check) {
            // The sum of `check` is a placeholder of the terminal and dataN are ours to ignore.
            return new self($responseTxnId, command: $command, txnId: $responseTxnId, account: $account);
        }

        $sum = self::sum($parameters['sum'] ?? null);

        if ($sum === null) {
            return new self($responseTxnId, 'sum');
        }

        $txnDate = self::txnDate($parameters['txn_date'] ?? null);

        if (! $txnDate instanceof CarbonImmutable) {
            return new self($responseTxnId, 'txn_date');
        }

        return new self($responseTxnId, null, $command, $responseTxnId, $account, $sum, $txnDate);
    }

    /**
     * The txn_id to echo for a request rejected before it is parsed in full.
     */
    public static function responseTxnIdOf(Request $request): string
    {
        return self::fromRequest($request)->responseTxnId;
    }

    public function isValid(): bool
    {
        return $this->error === null;
    }

    /**
     * @return array{0: array<string, string>, 1: bool}
     */
    private static function parameters(Request $request): array
    {
        $queryString = $request->server->get('QUERY_STRING');

        if (! is_string($queryString)) {
            $queryString = (string) parse_url($request->getRequestUri(), PHP_URL_QUERY);
        }

        $parameters = [];
        $isMalformed = false;

        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($rawKey);

            if (str_contains($key, '[') || str_contains($key, ']') || array_key_exists($key, $parameters)) {
                $isMalformed = true;

                // A repeated txn_id is not echoed: it is not clear which one came.
                if ($key === 'txn_id' || str_starts_with($key, 'txn_id[')) {
                    $parameters['txn_id'] = '';
                }

                continue;
            }

            $parameters[$key] = urldecode($rawValue);
        }

        return [$parameters, $isMalformed];
    }

    /**
     * The sum as a canonical decimal string, without a float on the way.
     */
    private static function sum(mixed $value): ?string
    {
        if (! is_string($value) || preg_match(self::SUM_PATTERN, $value, $matches) !== 1) {
            return null;
        }

        $integer = ltrim($matches[1], '0');
        $integer = $integer === '' ? '0' : $integer;

        if ($integer === '0' && $matches[2] === '00') {
            return null;
        }

        return $integer.'.'.$matches[2];
    }

    private static function txnDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::TXN_DATE_PATTERN, $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!YmdHis', $value, new DateTimeZone(config('app.timezone')));
        } catch (Throwable) {
            return null;
        }

        // A real date and time only: 20261131… or …246000 would roll over.
        if (! $date instanceof CarbonImmutable || $date->format('YmdHis') !== $value || $date->year < 2000) {
            return null;
        }

        return $date;
    }
}
