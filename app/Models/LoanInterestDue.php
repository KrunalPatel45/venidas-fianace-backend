<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanInterestDue extends Model
{
    protected $fillable = [
        'loan_id',
        'interest_month',
        'principal_snapshot',
        'interest_rate_snapshot',
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
            'interest_month' => 'date:Y-m-d',
            'principal_snapshot' => 'decimal:2',
            'interest_rate_snapshot' => 'decimal:3',
            'amount' => 'decimal:2',
            'paid_at' => 'date:Y-m-d',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
