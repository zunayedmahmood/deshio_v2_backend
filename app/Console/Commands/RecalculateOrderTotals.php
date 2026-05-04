<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

class RecalculateOrderTotals extends Command
{
    protected $signature = 'orders:recalculate-totals
        {order? : Order ID or order number. Leave empty to scan all orders.}
        {--dry-run : Show changes without saving.}
        {--fix-duplicated-item-discount : If order.discount_amount is clearly the same as item discounts, reset it to 0 before recalculating.}';

    protected $description = 'Recalculate order subtotal, total, paid and due amounts using net item totals.';

    public function handle(): int
    {
        $identifier = $this->argument('order');
        $dryRun = (bool) $this->option('dry-run');
        $fixDuplicatedItemDiscount = (bool) $this->option('fix-duplicated-item-discount');

        $query = Order::with(['items', 'payments']);

        if ($identifier) {
            $query->where(function ($q) use ($identifier) {
                if (is_numeric($identifier)) {
                    $q->where('id', (int) $identifier);
                }
                $q->orWhere('order_number', $identifier);
            });
        }

        $orders = $query->orderBy('id')->get();

        if ($orders->isEmpty()) {
            $this->error('No matching orders found.');
            return self::FAILURE;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Checking ' . $orders->count() . ' order(s)...');

        foreach ($orders as $order) {
            $grossSubtotal = round((float) $order->items->sum(function ($item) {
                return (float) $item->unit_price * (int) $item->quantity;
            }), 2);

            $itemDiscountTotal = round((float) $order->items->sum('discount_amount'), 2);
            $netSubtotal = round((float) $order->items->sum('total_amount'), 2);
            $currentSubtotal = round((float) $order->subtotal, 2);
            $currentDiscount = round((float) $order->discount_amount, 2);
            $currentTotal = round((float) $order->total_amount, 2);
            $currentDue = round((float) $order->outstanding_amount, 2);

            $willResetDuplicatedDiscount = false;
            if ($fixDuplicatedItemDiscount && $itemDiscountTotal > 0) {
                $discountMatchesItems = abs($currentDiscount - $itemDiscountTotal) < 0.01;
                $subtotalAlreadyNet = abs($currentSubtotal - $netSubtotal) < 0.01;
                if ($discountMatchesItems && $subtotalAlreadyNet) {
                    $willResetDuplicatedDiscount = true;
                }
            }

            $orderDiscountForCalculation = $willResetDuplicatedDiscount ? 0 : $currentDiscount;
            $expectedTotal = max(0, round($netSubtotal - $orderDiscountForCalculation + (float) $order->shipping_amount, 2));
            $paid = max(round((float) $order->paid_amount, 2), round((float) $order->getTotalPaidAmount(), 2));
            $expectedDue = max(0, round($expectedTotal - $paid, 2));

            $this->line(sprintf(
                '%s | gross %.2f | item discount %.2f | net %.2f | order discount %.2f%s | total %.2f -> %.2f | due %.2f -> %.2f',
                $order->order_number,
                $grossSubtotal,
                $itemDiscountTotal,
                $netSubtotal,
                $currentDiscount,
                $willResetDuplicatedDiscount ? ' reset-to-0' : '',
                $currentTotal,
                $expectedTotal,
                $currentDue,
                $expectedDue
            ));

            if (! $dryRun) {
                if ($willResetDuplicatedDiscount) {
                    $order->discount_amount = 0;
                    $order->save();
                    $order->refresh()->load(['items', 'payments']);
                }

                $order->calculateTotals();
            }
        }

        $this->info($dryRun ? 'Dry run complete. No data changed.' : 'Recalculation complete.');
        return self::SUCCESS;
    }
}
