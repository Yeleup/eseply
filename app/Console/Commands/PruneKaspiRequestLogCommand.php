<?php

namespace App\Console\Commands;

use App\Kaspi\KaspiActivity;
use Illuminate\Console\Command;

class PruneKaspiRequestLogCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'kaspi:prune-request-log';

    /**
     * @var string
     */
    protected $description = 'Удалить порциями строки журнала запросов Kaspi старше 30 дней (последний запрос организации остаётся)';

    public function handle(): int
    {
        $deleted = KaspiActivity::prune();

        $this->info("Удалено строк журнала запросов Kaspi: {$deleted}");

        return self::SUCCESS;
    }
}
