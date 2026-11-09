/*
 * Modèles Haojin vendus en Haïti par Novamat Motors S.A., distributeur de la
 * marque (Guangzhou Haojin Motorcycle Co., Ltd.). Seuls les modèles confirmés
 * par une source sont listés ; un modèle s'ajoute ici quand Novamat le confirme.
 *
 * La cylindrée n'est indiquée que si une source la donne. Le chiffre du nom
 * (125, 150) n'est pas une preuve : le moteur de l'HJ-125A-D est un 157FMI,
 * à vérifier sur la fiche du fournisseur avant de compléter.
 *
 * Prix par défaut décidé par le propriétaire : 200 000 HTG pour toutes les
 * motos, modifiable ensuite modèle par modèle.
 */
export const HAOJIN_DEFAULT_PRICE = { price: '200000.00', price_currency: 'HTG' as const }

export interface HaojinReference {
  model: string
  displacement_cc: number | null
  cylinders: number
  source: string
}

export const HAOJIN_HAITI_MODELS: HaojinReference[] = [
  { model: 'HJ-125A-D', displacement_cc: null, cylinders: 1, source: 'Certificat de vente Novamat Motors 2026' },
  { model: 'HJ-150-28', displacement_cc: null, cylinders: 1, source: 'Catalogue en ligne Novamat Motors' },
]
