<?php

namespace App\Console\Commands;

use App\Http\Controllers\ShipmentController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class ProcessPathaoBulkBatches extends Command
{
    protected $signature = 'pathao:bulk-tick {--max=19 : Maximum due Pathao orders to attempt in this run}';

    protected $description = 'Process due Pathao bulk-batch items at the safe 19 orders/minute rate.';

    public function handle(): int
    {
        $max = max(1, min(19, (int) $this->option('max')));
        $controller = app(ShipmentController::class);

        for ($i = 0; $i < $max; $i++) {
            $response = $controller->runPathaoQueueTick(new Request());
            $payload = method_exists($response, 'getData') ? $response->getData(true) : null;

            if (($payload['data'] ?? null) === null) {
                $this->info('No due Pathao bulk item found.');
                break;
            }

            $data = $payload['data'];
            $this->line(sprintf(
                '%s: %s/%s processed, success=%s, failed=%s',
                $data['batch_code'] ?? 'Pathao batch',
                $data['processed'] ?? 0,
                $data['total'] ?? 0,
                $data['success'] ?? 0,
                $data['failed'] ?? 0
            ));

            if (($data['status'] ?? null) === 'completed') {
                continue;
            }

            // Match frontend/backend spacing. This command can be run every minute
            // and will still stay safely under Pathao's 20/minute cap.
            usleep(3158000);
        }

        return self::SUCCESS;
    }
}
