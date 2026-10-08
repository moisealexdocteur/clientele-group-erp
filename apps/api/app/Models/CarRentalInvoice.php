<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Facture de location, numérotée sur huit chiffres par société. Le
 * contenu est figé à l'émission (lignes, paiements, dépôt, solde).
 */
final class CarRentalInvoice extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'reservation_id',
        'invoice_number',
        'currency',
        'snapshot',
        'total',
        'balance_due',
        'file_id',
        'issued_by',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'total' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'issued_at' => 'immutable_datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CarRentalReservation::class, 'reservation_id');
    }

    public function formattedNumber(): string
    {
        return substr($this->invoice_number, 0, 4) . ' ' . substr($this->invoice_number, 4, 4);
    }
}
