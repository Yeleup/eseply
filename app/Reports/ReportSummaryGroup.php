<?php

namespace App\Reports;

enum ReportSummaryGroup: string
{
    case Controller = 'controller';
    case Region = 'region';
    case Street = 'street';
    case City = 'city';

    public function label(): string
    {
        return match ($this) {
            self::Controller => 'По контроллерам',
            self::Region => 'По районам',
            self::Street => 'По улицам',
            self::City => 'По городам',
        };
    }

    public function heading(): string
    {
        return match ($this) {
            self::Controller => 'Контроллер',
            self::Region => 'Район',
            self::Street => 'Улица',
            self::City => 'Город',
        };
    }
}
