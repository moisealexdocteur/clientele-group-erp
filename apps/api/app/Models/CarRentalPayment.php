<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class CarRentalPayment extends Model
{
    use HasUuids;

    /** Le crédit est réservé à l'administration (permission rental.payments.credit). */
    public const METHODS = ['cash', 'bank_transfer', 'credit'];

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
        'proof_file_id',
        'submitted_at',
        'approved_at',
        'exchange_rate_htg_per_usd',
        'amount_in_reservation_currency',
        'receipt_number',
        'receipt_issued_at',
        'receipt_print_count',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'bank_reference' => 'encrypted',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'exchange_rate_htg_per_usd' => 'decimal:4',
            'amount_in_reservation_currency' => 'decimal:2',
            'receipt_issued_at' => 'immutable_datetime',
            'receipt_print_count' => 'integer',
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
