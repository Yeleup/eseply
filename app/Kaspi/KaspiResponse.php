<?php

namespace App\Kaspi;

use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Answers to Kaspi, byte for byte as in the samples of the task and page 6 of
 * the protocol: JSON in UTF-8, `txn_id` a string as it came, `result` a
 * number, `sum` a number with two decimals.
 *
 * `json_encode()` cannot write `4250.00` as a number, so the body is put
 * together here and only the strings go through the encoder.
 */
final class KaspiResponse
{
    public const string ERROR_COMMENT = 'Приём оплат недоступен';

    public const string NOT_FOUND_COMMENT = 'Абонент не найден';

    public const string PENDING_COMMENT = 'Платёж в обработке';

    public static function checkFound(string $txnId, string $payerName, string $debt, string $debtDate): string
    {
        return '{"txn_id":'.self::string($txnId)
            .',"result":0'
            .',"sum":'.self::amount($debt)
            .',"comment":""'
            .',"fields":{'
            .'"field1":{"@name":"Абонент","#text":'.self::string($payerName).'},'
            .'"field2":{"@name":'.self::string('Долг на '.$debtDate).',"#text":'.self::string($debt).'}'
            .'}}';
    }

    public static function checkNotFound(string $txnId): string
    {
        return '{"txn_id":'.self::string($txnId).',"result":1,"comment":'.self::string(self::NOT_FOUND_COMMENT).'}';
    }

    public static function error(string $txnId): string
    {
        return '{"txn_id":'.self::string($txnId).',"result":5,"comment":'.self::string(self::ERROR_COMMENT).'}';
    }

    public static function payPosted(string $txnId, string $prvTxnId, string $sum): string
    {
        return '{"txn_id":'.self::string($txnId)
            .',"prv_txn_id":'.self::string($prvTxnId)
            .',"result":0'
            .',"sum":'.self::amount($sum)
            .',"comment":"OK"}';
    }

    public static function payPending(string $txnId): string
    {
        return '{"txn_id":'.self::string($txnId).',"result":4,"comment":'.self::string(self::PENDING_COMMENT).'}';
    }

    /**
     * The `result` of a body built here.
     */
    public static function resultOf(string $body): int
    {
        return preg_match('/"result":([0-9])/', $body, $matches) === 1 ? (int) $matches[1] : 5;
    }

    public static function http(string $body): Response
    {
        return new Response($body, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private static function string(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private static function amount(string $value): string
    {
        if (preg_match('/\A-?[0-9]+\.[0-9]{2}\z/', $value) !== 1) {
            throw new InvalidArgumentException('Сумма для Kaspi должна быть с двумя знаками после точки.');
        }

        return $value;
    }
}
