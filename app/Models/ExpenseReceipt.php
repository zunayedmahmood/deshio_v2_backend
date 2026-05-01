<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ExpenseReceipt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'expense_id',
        'file_name',
        'file_path',
        'file_extension',
        'mime_type',
        'file_size',
        'original_name',
        'uploaded_by',
        'description',
        'is_primary',
        'metadata',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'is_primary' => 'boolean',
        'metadata' => 'array',
    ];

    // Relationships
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'uploaded_by');
    }

    // Accessors
    public function getUrlAttribute(): ?string
    {
        return $this->file_path ? Storage::url($this->file_path) : null;
    }

    public function getFullPathAttribute(): ?string
    {
        return $this->file_path ? Storage::path($this->file_path) : null;
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }

    // Business logic methods
    public function setPrimary(): bool
    {
        // Unset other primary receipts for this expense
        static::where('expense_id', $this->expense_id)
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        $this->is_primary = true;
        return $this->save();
    }

    public function deleteFile(): bool
    {
        if ($this->file_path && Storage::exists($this->file_path)) {
            return Storage::delete($this->file_path);
        }
        return false;
    }

    // Scopes
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeByExpense($query, $expenseId)
    {
        return $query->where('expense_id', $expenseId);
    }

    // Boot method
    protected static function boot()
    {
        parent::boot();

        // Delete file when receipt is deleted
        static::deleting(function ($receipt) {
            $receipt->deleteFile();
        });
    }
}
