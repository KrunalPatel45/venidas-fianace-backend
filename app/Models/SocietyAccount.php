<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SocietyAccount extends Model
{
    protected $fillable = [
        'user_id',
        'account_number',
        'monthly_amount',
        'opening_fee_amount',
        'opened_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'date',
            'monthly_amount' => 'decimal:2',
            'opening_fee_amount' => 'decimal:2',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function openingFee(): HasOne
    {
        return $this->hasOne(
            AccountOpeningFee::class,
            'society_account_id'
        );
    }
}
