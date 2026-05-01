<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Services\SalesTargetAggregationService;

class BackfillSalesTargets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sales-targets:backfill';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfills sales target data for existing confirmed/completed POS orders.';

    /**
     * Execute the console command.
     */
    public function handle(SalesTargetAggregationService $service)
    {
        $this->info('Starting sales targets backfill...');

        $orders = Order::whereIn('status', ['confirmed', 'completed', 'delivered'])->get();
        $total = $orders->count();
        $processed = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($orders as $order) {
            $service->syncOrderChange($order);
            $processed++;
            $bar->advance();
        }

        $bar->finish();
        
        $this->newLine();
        $this->info("Successfully processed {$processed} out of {$total} orders.");
    }
}
