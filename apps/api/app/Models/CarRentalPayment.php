<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class CarRentalPayment extends Model
{
    use HasUuids;

    public const METHODS = ['cash', 'bank_transfer'];

    public const STATUSES = ['submitted', 'approved', 'rejected', 'reversed'];

    public const KINDS = ['rental', 'security_deposit'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'reservation_id',
        'cash_register_id',
        'approved_by',
        'payment_kind',
        'method',
        'status',
        'currency',
        'amount',
        'bank_name',
        'bank_reference',
        'proof_storage_key',
        'proof_sha256',
        'submitted_at',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'bank_reference' => 'encrypted',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CarRentalReservation::class, 'reservation_id');
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function setBankReference(?string $reference): void
    {
        $this->bank_reference = $reference === null || trim($reference) === ''
            ? null
            : Str::upper(trim($reference));
    }
}
