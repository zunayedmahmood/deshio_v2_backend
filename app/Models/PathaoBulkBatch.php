<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PathaoBulkBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_code',
        'created_by',
        'store_id',
        'status',
        'total_shipments',
        'processed_count',
        'success_count',
        'failed_count',
        'shipment_ids',
        'results',
        'started_at',
        'completed_at',
        'error_summary',
    ];

    protected $casts = [
        'shipment_ids' => 'array',
        'results' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($batch) {
            if (empty($batch->batch_code)) {
                $batch->batch_code = static::generateBatchCode();
            }
        });
    }

    /**
     * Generate unique batch code
     */
    public static function generateBatchCode(): string
    {
        $prefix = 'PB';
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(6));
        return "{$prefix}-{$date}-{$random}";
    }

    // Relationships
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the shipments associated with this batch
     */
    public function shipments()
    {
        return Shipment::whereIn('id', $this->shipment_ids ?? []);
    }

    // Status helpers
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Mark batch as started/processing
     */
    public function markAsProcessing()
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    /**
     * Check if all shipments have been processed
     */
    public function checkCompletion()
    {
        if ($this->processed_count >= $this->total_shipments) {
            $this->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
            return true;
        }
        return false;
    }

    /**
     * Record result for a single shipment
     */
    public function recordShipmentResult(int $shipmentId, bool $success, string $message, ?string $consignmentId = null)
    {
        $results = $this->results ?? [];
        
        $results[$shipmentId] = [
            'success' => $success,
            'message' => $message,
            'consignment_id' => $consignmentId,
            'processed_at' => now()->toISOString(),
        ];

        $this->results = $results;
        $this->processed_count = count($results);
        $this->success_count = collect($results)->where('success', true)->count();
        $this->failed_count = collect($results)->where('success', false)->count();
        $this->save();

        // Check if batch is complete
        $this->checkCompletion();
    }

    /**
     * Cancel the batch
     */
    public function cancel()
    {
        if ($this->isProcessing() || $this->isPending()) {
            $this->update([
                'status' => 'cancelled',
                'completed_at' => now(),
            ]);
            return true;
        }
        return false;
    }

    /**
     * Get progress percentage
     */
    public function getProgressAttribute(): float
    {
        if ($this->total_shipments === 0) {
            return 0;
        }
        return round(($this->processed_count / $this->total_shipments) * 100, 2);
    }

    /**
     * Get summary of results
     */
    public function getSummary(): array
    {
        return [
            'batch_code' => $this->batch_code,
            'status' => $this->status,
            'total' => $this->total_shipments,
            'processed' => $this->processed_count,
            'success' => $this->success_count,
            'failed' => $this->failed_count,
            'pending' => $this->total_shipments - $this->processed_count,
            'progress' => $this->progress,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'duration_seconds' => $this->started_at && $this->completed_at
                ? $this->completed_at->diffInSeconds($this->started_at)
                : null,
        ];
    }

    /**
     * Get detailed results with shipment info
     */
    public function getDetailedResults(): array
    {
        $results = $this->results ?? [];
        $shipmentIds = array_keys($results);
        
        if (empty($shipmentIds)) {
            return [];
        }

        $shipments = Shipment::whereIn('id', $shipmentIds)
            ->with('order:id,order_number')
            ->get()
            ->keyBy('id');

        $detailed = [];
        foreach ($results as $shipmentId => $result) {
            $shipment = $shipments->get($shipmentId);
            $detailed[] = [
                'shipment_id' => $shipmentId,
                'shipment_number' => $shipment?->shipment_number,
                'order_number' => $shipment?->order?->order_number,
                'success' => $result['success'],
                'message' => $result['message'],
                'consignment_id' => $result['consignment_id'] ?? null,
                'processed_at' => $result['processed_at'],
            ];
        }

        return $detailed;
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}