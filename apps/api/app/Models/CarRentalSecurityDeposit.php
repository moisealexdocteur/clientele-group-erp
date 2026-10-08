<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CarRentalSecurityDeposit extends Model
{
    use HasUuids;

    public const METHODS = ['cash', 'bank_transfer', 'passport_hold'];

    public const STATUSES = ['required', 'held', 'partially_applied', 'released', 'forfeited'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'reservation_id',
        'payment_id',
        'method',
        'status',
        'currency',
        'amount',
        'held_at',
        'released_at',
        'applied_amount',
        'settlement_note',
        'settled_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'held_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'applied_amount' => 'decimal:2',
            'settlement_note' => 'encrypted',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CarRentalReservation::class, 'reservation_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CarRentalPayment::class, 'payment_id');
    }
}
