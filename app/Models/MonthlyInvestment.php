<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyInvestment extends Model
{
    protected $fillable = [
        'society_account_id',
        'investment_month',
        'amount',
        'status',
        'payment_method',
        'paid_at',
        'receipt_number',
        'received_by',
    ];

    protected function casts(): array
    {
        return [
            'investment_month' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'paid_at' => 'date:Y-m-d',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            SocietyAccount::class,
            'society_account_id'
        );
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'received_by'
        );
    }
}