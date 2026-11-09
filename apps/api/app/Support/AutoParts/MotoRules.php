<?php

namespace App\Support\AutoParts;

/**
 * Règles fixes de la vente de motocyclettes : pièces d'identité acceptées,
 * points de contrôle vérifiés avec le client et accessoires remis.
 */
final class MotoRules
{
    public const ID_DOCUMENTS = [
        'cin' => 'Carte d’identification nationale (CIN)',
        'passport' => 'Passeport',
        'driver_license' => 'Permis de conduire',
        'nif_card' => 'Carte NIF avec photo',
    ];

    /** Contrôle de conformité fait avec le client avant la remise. */
    public const CHECKLIST = [
        'make_model' => 'Marque et modèle conformes',
        'frame_number' => 'Numéro de châssis conforme',
        'engine_number' => 'Numéro de moteur conforme',
        'color' => 'Couleur conforme',
        'lights' => 'Phare, feu arrière et feu stop',
        'indicators' => 'Clignotants',
        'horn' => 'Klaxon',
        'brakes' => 'Freins avant et arrière',
        'lubricant' => 'Huile et lubrifiant',
        'fuel' => 'Carburant',
        'tires' => 'Pneus',
        'mirrors' => 'Rétroviseurs',
        'start' => 'Démarrage et ralenti',
    ];

    /** Accessoires livrés avec chaque motocyclette : libellé et quantité. */
    public const ACCESSORIES = [
        'helmets' => ['label' => 'Casques', 'quantity' => 3],
        'raincoat' => ['label' => 'Pardessus', 'quantity' => 1],
        'vest' => ['label' => 'Gilet', 'quantity' => 1],
        'jersey' => ['label' => 'Maillot', 'quantity' => 1],
        'radio' => ['label' => 'Radio', 'quantity' => 1],
    ];

    public const STATUS_LABELS = [
        'submitted' => 'Dépôt à vérifier',
        'paid' => 'En attente de moto',
        'assigned' => 'Moto attribuée',
        'delivered' => 'Livrée',
        'cancelled' => 'Annulée',
    ];

    public const UNIT_STATUS_LABELS = [
        'in_stock' => 'Disponible',
        'reserved' => 'Attribuée',
        'delivered' => 'Livrée',
    ];
}
