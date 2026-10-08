<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\StoredFile;
use App\Support\AuditLogger;
use App\Support\CompanySiteAuthorizer;
use App\Support\FileVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Envoi et lecture des fichiers privés Car Rental.
 *
 * Le droit d'envoyer dépend de l'usage du fichier ; le droit de lire un
 * document d'identité ou bancaire est réservé aux rôles autorisés et à la
 * personne qui l'a envoyé.
 */
final class CarRentalFileController extends Controller
{
    /** @var array<string, string> */
    private const UPLOAD_PERMISSIONS = [
        StoredFile::PURPOSE_PAYMENT_PROOF => 'rental.payments.submit',
        StoredFile::PURPOSE_VEHICLE_PHOTO => 'rental.vehicles.manage',
        StoredFile::PURPOSE_DRIVER_LICENSE_FRONT => 'rental.reservations.manage',
        StoredFile::PURPOSE_DRIVER_LICENSE_BACK => 'rental.reservations.manage',
        StoredFile::PURPOSE_INSPECTION_PHOTO => 'rental.reservations.manage',
        StoredFile::PURPOSE_SIGNATURE => 'rental.reservations.manage',
        StoredFile::PURPOSE_RENTAL_CONTRACT => 'rental.reservations.manage',
    ];

    public function __construct(
        private readonly FileVault $vault,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
        private readonly AuditLogger $audit,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);

        $data = $request->validate([
            'purpose' => ['required', Rule::in(StoredFile::PURPOSES)],
            'site_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:' . FileVault::MAX_KILOBYTES],
        ], [
            'file.required' => 'Ajoutez un fichier.',
            'file.file' => 'Ajoutez un fichier valide.',
            'file.max' => 'Le fichier dépasse 10 Mo.',
            'file.uploaded' => 'Le fichier n’a pas pu être reçu. Il dépasse peut-être 10 Mo.',
        ]);

        if (! $access->allows(self::UPLOAD_PERMISSIONS[$data['purpose']])) {
            return response()->json(['message' => 'Votre rôle ne permet pas d’ajouter ce document.'], 403);
        }

        $site = $this->siteAuthorizer->siteFor($company, $access, $data['site_id']);
        $file = $this->vault->store($request->file('file'), $company, $data['purpose'], $site->id, $request->user());

        return response()->json(['data' => $this->payload($file)], 201);
    }

    public function show(Request $request, string $file): Response
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $user = $request->user();

        $model = StoredFile::query()
            ->where('company_id', $company->id)
            ->whereKey($file)
            ->first();

        abort_if($model === null, 404, 'Fichier introuvable.');

        if ($model->site_id !== null) {
            $this->siteAuthorizer->siteFor($company, $access, $model->site_id);
        }

        if (! $this->canRead($access, $model, $user?->id)) {
            return response()->json(['message' => 'Votre rôle ne permet pas de consulter ce document.'], 403);
        }

        if ($model->isSensitive()) {
            $this->audit->record(
                eventType: 'file.read',
                companyId: $company->id,
                actorId: $user?->id,
                actorType: $user === null ? 'SYSTEM' : 'USER',
                subjectType: StoredFile::class,
                subjectId: $model->id,
                metadata: ['purpose' => $model->purpose],
            );
        }

        abort_unless(Storage::disk($model->disk)->exists($model->path), 404, 'Fichier introuvable.');

        return Storage::disk($model->disk)->response($model->path, null, [
            'Content-Type' => $model->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function canRead(CompanyUserAccess $access, StoredFile $file, ?string $userId): bool
    {
        if ($userId !== null && $file->uploaded_by === $userId) {
            return true;
        }

        return match ($file->purpose) {
            StoredFile::PURPOSE_VEHICLE_PHOTO,
            StoredFile::PURPOSE_INSPECTION_PHOTO => $access->allows('rental.vehicles.read')
                || $access->allows('rental.reservations.read'),
            StoredFile::PURPOSE_PAYMENT_PROOF => $access->allows('rental.payments.approve')
                || $access->allows('rental.documents.sensitive'),
            default => $access->allows('rental.documents.sensitive'),
        };
    }

    /** @return array<string, mixed> */
    private function payload(StoredFile $file): array
    {
        return [
            'id' => $file->id,
            'purpose' => $file->purpose,
            'mime_type' => $file->mime_type,
            'size_bytes' => $file->size_bytes,
            'sha256' => $file->sha256,
            'url' => $file->apiUrl(),
        ];
    }

    private function company(Request $request): Company
    {
        $company = $request->attributes->get('clientele.company');
        abort_unless($company instanceof Company, 500, 'Contexte de société manquant.');

        return $company;
    }

    private function access(Request $request): CompanyUserAccess
    {
        $access = $request->attributes->get('clientele.company_access');
        abort_unless($access instanceof CompanyUserAccess, 500, 'Contexte d’accès manquant.');

        return $access;
    }
}
