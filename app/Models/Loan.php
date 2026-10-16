<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    protected $fillable = [
        'loan_number',
        'borrower_id',
        'loan_type',
        'principal_amount',
        'outstanding_principal',
        'monthly_interest_rate',
        'status',
        'disbursed_at',
        'disbursement_method',
        'purpose',
        'notes',
        'created_by',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'outstanding_principal' => 'decimal:2',
            'monthly_interest_rate' => 'decimal:3',
            'disbursed_at' => 'date:Y-m-d',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function interestDues(): HasMany
    {
        return $this->hasMany(LoanInterestDue::class);
    }

    public function principalRepayments(): HasMany
    {
        return $this->hasMany(LoanPrincipalRepayment::class);
    }
}
