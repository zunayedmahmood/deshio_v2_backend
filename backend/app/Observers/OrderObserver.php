<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\SalesTargetAggregationService;

class OrderObserver
{
    protected $aggregationService;

    public function __construct(SalesTargetAggregationService $aggregationService)
    {
        $this->aggregationService = $aggregationService;
    }

    public function created(Order $order): void
    {
        $this->aggregationService->syncOrderChange($order);
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged(['status', 'created_by', 'salesman_id', 'store_id', 'total_amount', 'order_date'])) {
            $this->aggregationService->syncOrderChange($order, $order->getOriginal());
        }
    }

    public function deleted(Order $order): void
    {
        $this->aggregationService->syncOrderChange($order, $order->toArray());
    }

    public function restored(Order $order): void
    {
        $this->aggregationService->syncOrderChange($order);
    }

    public function forceDeleted(Order $order): void
    {
        $this->aggregationService->syncOrderChange($order, $order->toArray());
    }
}