<?php

namespace App\Services;

use App\Models\EmployeeDailySale;
use App\Models\Order;
use Carbon\Carbon;

class SalesTargetAggregationService
{
    private const TIMEZONE = 'Asia/Dhaka';

    /**
     * Recompute affected daily sales rows after an order lifecycle change.
     */
    public function syncOrderChange(Order $order, ?array $old = null): void
    {
        $newDims = $this->dimsFromCurrentOrder($order);
        $oldDims = $this->dimsFromOldData($old);

        if ($oldDims !== null) {
            $this->recomputeDailySales((int) $oldDims['employee_id'], (int) $oldDims['store_id'], (string) $oldDims['sales_date']);
        }

        if ($newDims !== null) {
            $isSame = $oldDims !== null
                && (int) $oldDims['employee_id'] === (int) $newDims['employee_id']
                && (int) $oldDims['store_id'] === (int) $newDims['store_id']
                && (string) $oldDims['sales_date'] === (string) $newDims['sales_date'];

            if (!$isSame) {
                $this->recomputeDailySales((int) $newDims['employee_id'], (int) $newDims['store_id'], (string) $newDims['sales_date']);
            }
        }
    }

    public function recomputeDailySales(int $employeeId, int $storeId, string $salesDate): void
    {
        $query = Order::query()
            ->whereNull('deleted_at')
            ->where('order_type', 'counter')
            ->where(function ($q) use ($employeeId) {
                $q->where('salesman_id', $employeeId)
                  ->orWhere(function ($q2) use ($employeeId) {
                      $q2->whereNull('salesman_id')->where('created_by', $employeeId);
                  });
            })
            ->where('store_id', $storeId)
            ->whereIn('status', ['confirmed', 'completed', 'delivered'])
            ->whereDate('order_date', $salesDate);

        $orderCount = (int) $query->count();
        $totalSales = round((float) $query->sum('total_amount'), 2, PHP_ROUND_HALF_UP);

        if ($orderCount === 0 || $totalSales <= 0) {
            EmployeeDailySale::query()
                ->where('employee_id', $employeeId)
                ->where('store_id', $storeId)
                ->whereDate('sales_date', $salesDate)
                ->delete();
            return;
        }

        EmployeeDailySale::query()->updateOrCreate(
            [
                'employee_id' => $employeeId,
                'sales_date' => $salesDate,
            ],
            [
                'store_id' => $storeId,
                'order_count' => $orderCount,
                'total_sales_amount' => $totalSales,
                'last_computed_at' => now(),
            ]
        );
    }

    private function dimsFromCurrentOrder(Order $order): ?array
    {
        if (!$this->isCountableStatus($order->status) || $order->order_type !== 'counter') {
            return null;
        }

        $employeeId = (int) ($order->salesman_id ?? $order->created_by ?? 0);
        $storeId = (int) ($order->store_id ?? 0);
        $salesDate = $this->normalizeDate($order->order_date);

        if ($employeeId <= 0 || $storeId <= 0 || !$salesDate) {
            return null;
        }

        return [
            'employee_id' => $employeeId,
            'store_id' => $storeId,
            'sales_date' => $salesDate,
        ];
    }

    private function dimsFromOldData(?array $old): ?array
    {
        if (!$old) {
            return null;
        }

        $status = $old['status'] ?? null;
        if (!$status || !$this->isCountableStatus((string) $status) || (($old['order_type'] ?? null) !== 'counter')) {
            return null;
        }

        $employeeId = (int) ($old['salesman_id'] ?? $old['created_by'] ?? 0);
        $storeId = (int) ($old['store_id'] ?? 0);
        $salesDate = $this->normalizeDate($old['order_date'] ?? null);

        if ($employeeId <= 0 || $storeId <= 0 || !$salesDate) {
            return null;
        }

        return [
            'employee_id' => $employeeId,
            'store_id' => $storeId,
            'sales_date' => $salesDate,
        ];
    }

    private function normalizeDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return Carbon::parse($value, self::TIMEZONE)->toDateString();
    }

    private function isCountableStatus(string $status): bool
    {
        return in_array($status, ['confirmed', 'completed', 'delivered'], true);
    }
}