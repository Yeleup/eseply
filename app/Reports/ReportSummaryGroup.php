<?php

namespace App\Reports;

enum ReportSummaryGroup: string
{
    case Controller = 'controller';
    case Region = 'region';
    case Street = 'street';

    public function label(): string
    {
        return match ($this) {
            self::Controller => 'По контроллерам',
            self::Region => 'По районам',
            self::Street => 'По улицам',
        };
    }

    public function heading(): string
    {
        return match ($this) {
            self::Controller => 'Контроллер',
            self::Region => 'Район',
            self::Street => 'Улица',
        };
    }
}
