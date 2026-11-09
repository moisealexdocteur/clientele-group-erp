<?php

namespace App\Support\AutoParts;

use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\MotoModel;
use App\Models\MotoOrder;
use App\Models\PartsProduct;
use App\Models\Site;
use App\Support\CompanySiteAuthorizer;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Lecture Auto Parts dans le contexte de société et d'adresse : un article
 * d'une autre société répond 404, une adresse hors de la portée de
 * l'utilisateur répond 403 ou 404.
 */
final class PartsLookup
{
    public function __construct(
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    public function productFor(Company $company, string $product, bool $lock = false): PartsProduct
    {
        $model = PartsProduct::query()
            ->where('company_id', $company->id)
            ->whereKey($product)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->first();

        abort_if($model === null, 404, 'Article introuvable.');

        return $model;
    }

    /** Commande de moto de la société, dans une adresse autorisée. */
    public function motoOrderFor(Company $company, CompanyUserAccess $access, string $order, bool $lock = false): MotoOrder
    {
        $model = MotoOrder::query()
            ->where('company_id', $company->id)
            ->whereKey($order)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->first();

        abort_if($model === null, 404, 'Commande introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        return $model;
    }

    public function motoModelFor(Company $company, string $model, string $field = 'model_id'): MotoModel
    {
        $record = MotoModel::query()->where('company_id', $company->id)->whereKey($model)->first();

        if ($record === null) {
            throw ValidationException::withMessages([$field => 'Modèle introuvable.']);
        }

        return $record;
    }

    /** Magasin ou entrepôt autorisé : seul un emplacement de stock reçoit des pièces. */
    public function stockSiteFor(Company $company, CompanyUserAccess $access, string $siteId, string $field = 'site_id'): Site
    {
        $site = $this->siteAuthorizer->siteFor($company, $access, $siteId);

        if (! in_array($site->kind, ['store', 'warehouse'], true)) {
            throw ValidationException::withMessages([
                $field => sprintf('« %s » est un bureau. Choisissez un magasin ou un entrepôt.', $site->name),
            ]);
        }

        return $site;
    }

    /** Magasin autorisé : les ventes se font au comptoir d'un magasin. */
    public function storeFor(Company $company, CompanyUserAccess $access, string $siteId, string $field = 'site_id'): Site
    {
        $site = $this->siteAuthorizer->siteFor($company, $access, $siteId);

        if ($site->kind !== 'store') {
            throw ValidationException::withMessages([
                $field => sprintf('« %s » n’est pas un magasin. Les ventes se font au comptoir d’un magasin.', $site->name),
            ]);
        }

        return $site;
    }

    /**
     * Emplacements de stock (magasins et entrepôts) visibles par l'utilisateur.
     *
     * @return Collection<int, Site>
     */
    public function stockSites(Company $company, CompanyUserAccess $access): Collection
    {
        $ids = $this->siteAuthorizer->activeSiteIdsFor($company, $access);

        return Site::query()
            ->where('company_id', $company->id)
            ->whereIn('id', $ids)
            ->whereIn('kind', ['store', 'warehouse'])
            ->orderByRaw("case when kind = 'store' then 0 else 1 end")
            ->orderBy('name')
            ->get();
    }
}
