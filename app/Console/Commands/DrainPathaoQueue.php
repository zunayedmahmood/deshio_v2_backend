<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DrainPathaoQueue extends Command
{
    /**
     * Run the Pathao queue worker repeatedly for a short controlled window.
     *
     * This is designed for cPanel/shared hosting where a permanent queue worker
     * may not be available. It processes the database queue, waits, and retries
     * until the jobs table is cleared or the time limit is reached.
     */
    protected $signature = 'pathao:drain-queue
        {--duration=600 : Maximum runtime in seconds}
        {--interval=30 : Seconds to wait between worker attempts}
        {--queue=pathao,default : Comma-separated queues to process}';

    protected $description = 'Process Pathao queue jobs repeatedly until the queue is clear or the time limit is reached';

    public function handle(): int
    {
        $duration = max(30, min((int) $this->option('duration'), 1800));
        $interval = max(5, min((int) $this->option('interval'), 120));
        $queueOption = (string) $this->option('queue');
        $queueNames = collect(explode(',', $queueOption))
            ->map(fn ($queue) => trim($queue))
            ->filter()
            ->values()
            ->all();

        if (empty($queueNames)) {
            $queueNames = ['pathao', 'default'];
            $queueOption = implode(',', $queueNames);
        }

        $lock = Cache::lock('pathao_queue_drainer', $duration + 120);

        if (! $lock->get()) {
            $this->info('Pathao queue drainer is already running.');
            return 0;
        }

        $startedAt = now();
        $deadline = time() + $duration;

        try {
            $this->writeStatus([
                'status' => 'running',
                'started_at' => $startedAt->toISOString(),
                'duration' => $duration,
                'interval' => $interval,
                'queue' => $queueOption,
                'pending_jobs' => $this->pendingJobsCount($queueNames),
            ], $duration);

            while (time() <= $deadline) {
                $pendingBefore = $this->pendingJobsCount($queueNames);

                if ($pendingBefore <= 0) {
                    $this->info('No pending Pathao/default jobs. Queue is clear.');
                    $this->writeStatus([
                        'status' => 'completed',
                        'started_at' => $startedAt->toISOString(),
                        'finished_at' => now()->toISOString(),
                        'queue' => $queueOption,
                        'pending_jobs' => 0,
                    ], 300);
                    break;
                }

                $this->info("Processing {$pendingBefore} pending job(s) from [{$queueOption}]...");

                $this->call('queue:work', [
                    'connection' => 'database',
                    '--queue' => $queueOption,
                    '--stop-when-empty' => true,
                    '--sleep' => 1,
                    '--tries' => 10,
                    '--timeout' => 300,
                ]);

                $pendingAfter = $this->pendingJobsCount($queueNames);

                $this->writeStatus([
                    'status' => $pendingAfter > 0 ? 'running' : 'completed',
                    'started_at' => $startedAt->toISOString(),
                    'last_checked_at' => now()->toISOString(),
                    'queue' => $queueOption,
                    'pending_jobs' => $pendingAfter,
                ], $duration);

                if ($pendingAfter <= 0) {
                    $this->info('Pathao/default queue cleared.');
                    break;
                }

                $remaining = $deadline - time();
                if ($remaining <= 0) {
                    break;
                }

                $sleepFor = min($interval, $remaining);
                $this->info("{$pendingAfter} job(s) still pending/delayed. Checking again in {$sleepFor} second(s)...");
                sleep($sleepFor);
            }

            if ($this->pendingJobsCount($queueNames) > 0) {
                $this->writeStatus([
                    'status' => 'timeout',
                    'started_at' => $startedAt->toISOString(),
                    'finished_at' => now()->toISOString(),
                    'queue' => $queueOption,
                    'pending_jobs' => $this->pendingJobsCount($queueNames),
                    'message' => 'Time limit reached before queue was fully cleared.',
                ], 300);
            }

            return 0;
        } finally {
            optional($lock)->release();
        }
    }

    protected function pendingJobsCount(array $queueNames): int
    {
        try {
            return (int) DB::table('jobs')
                ->whereIn('queue', $queueNames)
                ->count();
        } catch (\Throwable $e) {
            $this->error('Unable to read jobs table: ' . $e->getMessage());
            return 0;
        }
    }

    protected function writeStatus(array $status, int $ttlSeconds): void
    {
        Cache::put('pathao_queue_drainer_status', $status, now()->addSeconds($ttlSeconds + 120));
    }
}
