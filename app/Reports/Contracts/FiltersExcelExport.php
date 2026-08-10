<?php

namespace App\Reports\Contracts;

use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report whose XLSX export repeats the filters applied to the on-screen detail table.
 *
 * Reports that do not implement this contract export the full detail list regardless
 * of the filters selected on the page.
 */
interface FiltersExcelExport
{
    /**
     * @param  array<string, array<string, mixed>>  $filters  Applied table filter state, keyed by filter name.
     */
    public function downloadFilteredExcel(Organization $organization, User $user, array $filters): StreamedResponse;
}
