/*
 * Lubrifiants de départ d'Auto Parts, d'après les marques citées par le
 * propriétaire et les photos du stock du 9 novembre 2026. Vente en gros à la
 * caisse ou au seau, au comptant seulement. Prix de test de 25 000 HTG par
 * unité de vente, à corriger. Les quantités des articles vus sur les photos
 * sont des comptes visuels partiels : à confirmer par un inventaire.
 *
 * Classement : moteur essence, diesel ou universel ; moto ; camion ;
 * voiture. Le grade et la contenance ne sont indiqués que s'ils sont lisibles
 * sur l'emballage ; sinon ils sont à préciser dans la fiche de l'article.
 */
export const STARTER_LUBRICANT_PRICE = '25000.00'

export type LubricantCategory =
  | 'Huile moteur essence'
  | 'Huile moteur diesel'
  | 'Huile moteur universelle'
  | 'Huile moto 2 temps'
  | 'Huile hydraulique'
  | 'Huile de transmission'
  | 'Liquide de frein'
  | 'Liquide de refroidissement'

export interface StarterProduct {
  sku: string
  name: string
  brand: string
  category: LubricantCategory
  /** Véhicules visés, imprimés dans les notes de l'article. */
  vehicles: string
  unit: 'caisse' | 'seau' | 'gallon'
  quantity: number
  /** Quantité comptée sur une photo partielle du stock. */
  estimated: boolean
}

export const STARTER_LUBRICANTS: StarterProduct[] = [
  { sku: 'LUB-MOTEXT-SAE40', name: 'Huile moteur Motex-T SAE 40', brand: 'Motex-T', category: 'Huile moteur universelle', vehicles: 'Voiture, camion', unit: 'caisse', quantity: 140, estimated: true },
  { sku: 'LUB-LION-MOTEUR', name: 'Huile moteur Lion Oils & Lubricants', brand: 'Lion', category: 'Huile moteur universelle', vehicles: 'Voiture, camion', unit: 'caisse', quantity: 50, estimated: true },
  { sku: 'LUB-OVERALL-MOTEUR', name: 'Huile moteur Overall', brand: 'Overall', category: 'Huile moteur universelle', vehicles: 'Voiture, camion', unit: 'caisse', quantity: 30, estimated: true },
  { sku: 'LUB-MULTIOIL-40', name: 'Huile moteur Multi Oil 40 - caisse de 24 bouteilles', brand: 'Multi Oil', category: 'Huile moteur universelle', vehicles: 'Voiture, camion', unit: 'caisse', quantity: 35, estimated: true },
  { sku: 'LUB-PETROIL-SAE40', name: 'Huile moteur Petroil SAE 40 (API SA)', brand: 'Petroil', category: 'Huile moteur essence', vehicles: 'Voiture', unit: 'caisse', quantity: 50, estimated: true },
  { sku: 'LUB-ATLANTICX-2T', name: 'Huile moteur Atlantic X 2T API TC - caisse de 12 x 1 L', brand: 'Atlantic', category: 'Huile moto 2 temps', vehicles: 'Motocyclette', unit: 'caisse', quantity: 45, estimated: true },
  { sku: 'LUB-URSA-DIESEL', name: 'Huile moteur diesel Chevron Ursa HD SAE 40 - seau 5 gal (18,9 L)', brand: 'Chevron Ursa', category: 'Huile moteur diesel', vehicles: 'Camion', unit: 'seau', quantity: 20, estimated: true },
  { sku: 'LUB-SUPERS-AW68', name: 'Huile hydraulique Super S AW68 - seau 5 gal (18,9 L)', brand: 'Super S', category: 'Huile hydraulique', vehicles: 'Camion, engin', unit: 'seau', quantity: 25, estimated: true },
  { sku: 'LUB-FREEZETONE-ANTIGEL', name: 'Liquide de refroidissement Freezetone - caisse de gallons', brand: 'Freezetone', category: 'Liquide de refroidissement', vehicles: 'Voiture, camion', unit: 'caisse', quantity: 40, estimated: true },
  { sku: 'LUB-MILLEC-RALLYE', name: 'Huile moteur Mille C Rallye', brand: 'Mille C', category: 'Huile moteur universelle', vehicles: 'Voiture', unit: 'caisse', quantity: 10, estimated: false },
  { sku: 'LUB-AMERICAN-MOTEUR', name: 'Huile moteur American', brand: 'American', category: 'Huile moteur universelle', vehicles: 'Voiture', unit: 'caisse', quantity: 10, estimated: false },
  { sku: 'LUB-CASTROL-MOTEUR', name: 'Huile moteur Castrol', brand: 'Castrol', category: 'Huile moteur essence', vehicles: 'Voiture', unit: 'caisse', quantity: 10, estimated: false },
  { sku: 'LUB-HAVOLINE-MOTEUR', name: 'Huile moteur Havoline', brand: 'Havoline', category: 'Huile moteur essence', vehicles: 'Voiture', unit: 'caisse', quantity: 10, estimated: false },
  { sku: 'LUB-CASTROL-FREIN', name: 'Liquide de frein Castrol', brand: 'Castrol', category: 'Liquide de frein', vehicles: 'Voiture, moto, camion', unit: 'caisse', quantity: 10, estimated: false },
  { sku: 'LUB-CASTROL-TRANSMISSION', name: 'Huile de transmission Castrol', brand: 'Castrol', category: 'Huile de transmission', vehicles: 'Voiture, camion', unit: 'caisse', quantity: 10, estimated: false },
  { sku: 'LUB-HAVOLINE-TRANSMISSION', name: 'Huile de transmission Havoline', brand: 'Havoline', category: 'Huile de transmission', vehicles: 'Voiture, camion', unit: 'caisse', quantity: 10, estimated: false },
]

/** Données de création : prix de détail et de gros égaux au prix de test. */
export function starterProductInput(product: StarterProduct) {
  const notes = [
    `Usage : ${product.vehicles}.`,
    product.estimated ? 'Stock initial compté sur photo : à confirmer par inventaire.' : 'Stock initial de test.',
    'Prix de test, grade et contenance à confirmer.',
  ].join(' ')
  return {
    sku: product.sku,
    barcode: null,
    name: product.name,
    category: product.category,
    brand: product.brand,
    unit: product.unit,
    retail_price_htg: STARTER_LUBRICANT_PRICE,
    wholesale_price_htg: STARTER_LUBRICANT_PRICE,
    reorder_level: product.unit === 'seau' ? 4 : 5,
    notes,
  }
}
