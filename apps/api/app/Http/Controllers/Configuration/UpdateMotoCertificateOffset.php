<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Réglage d'alignement de l'impression sur le certificat de vente du
 * fournisseur, en millimètres : positif vers la droite et vers le bas.
 * Propriétaire seulement, journalisé.
 */
final class UpdateMotoCertificateOffset extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function __invoke(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'x_mm' => ['required', 'numeric', 'between:-30,30', 'regex:/\A-?\d{1,2}(\.\d)?\z/'],
            'y_mm' => ['required', 'numeric', 'between:-30,30', 'regex:/\A-?\d{1,2}(\.\d)?\z/'],
        ], [
            'x_mm.between' => 'Saisissez un décalage entre -30 et 30 mm.',
            'y_mm.between' => 'Saisissez un décalage entre -30 et 30 mm.',
            'x_mm.regex' => 'Une décimale au plus, par exemple 2.5.',
            'y_mm.regex' => 'Une décimale au plus, par exemple -1.5.',
        ]);

        $company->forceFill([
            'moto_certificate_offset_x_mm' => (string) $data['x_mm'],
            'moto_certificate_offset_y_mm' => (string) $data['y_mm'],
        ])->save();

        $this->audit->record(
            eventType: 'configuration.moto_certificate_offset_changed',
            companyId: $company->id,
            actorId: $request->user()?->id,
            actorType: 'USER',
            subjectType: Company::class,
            subjectId: $company->id,
            metadata: ['x_mm' => (string) $data['x_mm'], 'y_mm' => (string) $data['y_mm']],
        );

        return response()->json(['data' => [
            'x_mm' => (string) $company->moto_certificate_offset_x_mm,
            'y_mm' => (string) $company->moto_certificate_offset_y_mm,
        ]]);
    }
}
