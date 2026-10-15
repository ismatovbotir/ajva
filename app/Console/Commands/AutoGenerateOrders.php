<?php

namespace App\Console\Commands;

use App\Services\AutoOrders;
use Illuminate\Console\Command;

class AutoGenerateOrders extends Command
{
    protected $signature = 'orders:auto-generate';

    protected $description = 'Create the daily draft orders of the shops whose order rule run time has come (scheduled every 5 minutes)';

    public function handle(AutoOrders $auto): int
    {
        $report = $auto->run();

        foreach ($report as $row) {
            $this->line(sprintf('#%d %s: %s %s', $row['shop_id'], $row['shop'], $row['status'], (string) $row['note']));
        }
        if ($report === []) {
            $this->line('Nothing to do.');
        }

        return self::SUCCESS;
    }
}
