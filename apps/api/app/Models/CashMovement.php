<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrée ou sortie d'espèces dans une session de caisse. */
final class CashMovement extends Model
{
    use HasUuids;

    public const KINDS = ['rental_payment', 'deposit_payment', 'deposit_refund'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'cash_session_id',
        'kind',
        'direction',
        'currency',
        'amount',
        'payment_id',
        'deposit_id',
        'reservation_id',
        'recorded_by',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CarRentalPayment::class, 'payment_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CarRentalReservation::class, 'reservation_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
