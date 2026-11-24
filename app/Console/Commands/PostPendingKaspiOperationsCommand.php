<?php

namespace App\Console\Commands;

use App\Kaspi\KaspiActivity;
use App\Kaspi\PostPendingKaspiOperations;
use Illuminate\Console\Command;

class PostPendingKaspiOperationsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'kaspi:post-pending';

    /**
     * @var string
     */
    protected $description = 'Провести оплаты Kaspi «в обработке», принятые больше минуты назад (на случай потерянного задания)';

    public function handle(PostPendingKaspiOperations $postPending): int
    {
        $posted = $postPending->pickUp();
        $postPending->foldRetries();
        KaspiActivity::prune();

        $this->info("Проведено оплат Kaspi: {$posted}");

        return self::SUCCESS;
    }
}
