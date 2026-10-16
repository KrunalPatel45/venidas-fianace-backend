<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountOpeningFee extends Model
{
    protected $fillable = [
        'society_account_id',
        'amount',
        'status',
        'paid_at',
        'payment_method',
        'payment_reference',
        'receipt_number',
        'received_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            SocietyAccount::class,
            'society_account_id'
        );
    }
}
