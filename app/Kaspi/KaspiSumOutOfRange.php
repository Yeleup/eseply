<?php

namespace App\Kaspi;

use RuntimeException;

/**
 * The payment does not fit the totals of the subscriber's month: the sum of
 * its payments or the balance would leave the range of the money columns
 * (12 digits before the point). It is never posted automatically, so it can
 * neither stay in processing forever nor break the closing of the month.
 */
class KaspiSumOutOfRange extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Сумма не помещается в итоги абонента за месяц (больше 999 999 999 999,99 ₸), оплата не проведена');
    }
}
