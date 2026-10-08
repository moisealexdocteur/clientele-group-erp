<?php

namespace App\Support;

use App\Models\Company;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stockage contrôlé des fichiers privés.
 *
 * - Types et tailles limités selon l'usage.
 * - Empreinte SHA-256 calculée par le serveur.
 * - Chemin sans nom d'origine ni donnée personnelle.
 * - Journalisation sans contenu ni nom de fichier.
 */
final class FileVault
{
    public const MAX_KILOBYTES = 10240;

    /** @var array<string, array<int, string>> */
    private const ALLOWED_MIME_TYPES = [
        StoredFile::PURPOSE_PAYMENT_PROOF => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        StoredFile::PURPOSE_VEHICLE_PHOTO => ['image/jpeg', 'image/png', 'image/webp'],
        StoredFile::PURPOSE_DRIVER_LICENSE_FRONT => ['image/jpeg', 'image/png', 'image/webp'],
        StoredFile::PURPOSE_DRIVER_LICENSE_BACK => ['image/jpeg', 'image/png', 'image/webp'],
        StoredFile::PURPOSE_INSPECTION_PHOTO => ['image/jpeg', 'image/png', 'image/webp'],
        StoredFile::PURPOSE_SIGNATURE => ['image/png'],
        StoredFile::PURPOSE_RENTAL_CONTRACT => ['application/pdf'],
    ];

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    public function store(
        UploadedFile $file,
        Company $company,
        string $purpose,
        ?string $siteId,
        ?User $actor,
    ): StoredFile {
        $allowed = self::ALLOWED_MIME_TYPES[$purpose] ?? null;

        if ($allowed === null) {
            throw ValidationException::withMessages(['purpose' => 'Usage de fichier non pris en charge.']);
        }

        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Le fichier n’a pas pu être reçu. Réessayez.']);
        }

        // Le type est lu dans le contenu, pas dans le nom ou l'en-tête envoyé.
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => $this->typeMessage($allowed),
            ]);
        }

        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            throw ValidationException::withMessages(['file' => 'Le fichier dépasse 10 Mo.']);
        }

        $id = (string) Str::uuid();
        $path = sprintf('companies/%s/%s/%s.%s', $company->id, $purpose, $id, self::EXTENSIONS[$mime]);
        $sha256 = hash_file('sha256', $file->getRealPath());

        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        $stored = new StoredFile();
        $stored->forceFill([
            'id' => $id,
            'company_id' => $company->id,
            'site_id' => $siteId,
            'purpose' => $purpose,
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'sha256' => $sha256,
            'uploaded_by' => $actor?->id,
        ])->save();

        $this->audit->record(
            eventType: 'file.stored',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: StoredFile::class,
            subjectId: $stored->id,
            metadata: [
                'purpose' => $purpose,
                'mime_type' => $mime,
                'size_bytes' => $stored->size_bytes,
                'sha256' => $sha256,
            ],
        );

        return $stored;
    }

    /**
     * Retrouve un fichier déjà téléversé pour l'usage attendu, dans la même
     * société. Un fichier d'un autre usage ou d'une autre société est refusé.
     */
    public function find(Company $company, ?string $fileId, string $purpose, string $field): StoredFile
    {
        $file = $fileId === null ? null : StoredFile::query()
            ->where('company_id', $company->id)
            ->where('purpose', $purpose)
            ->whereKey($fileId)
            ->first();

        if ($file === null) {
            throw ValidationException::withMessages([
                $field => 'Le fichier joint est introuvable. Ajoutez-le de nouveau.',
            ]);
        }

        return $file;
    }

    /** @param array<int, string> $allowed */
    private function typeMessage(array $allowed): string
    {
        $labels = array_unique(array_map(
            static fn (string $mime): string => match ($mime) {
                'application/pdf' => 'PDF',
                'image/png' => 'PNG',
                'image/webp' => 'WebP',
                default => 'JPEG',
            },
            $allowed,
        ));

        return 'Format accepté : ' . implode(', ', $labels) . '.';
    }
}
