/*
 * Lubrifiants de départ pour la recette d'Auto Parts, demandés par le
 * propriétaire le 9 novembre 2026 : vente à la caisse (gros), 10 caisses en
 * stock, prix de test de 25 000 HTG par caisse, à corriger ensuite.
 *
 * Marques citées par le propriétaire. Le grade et la contenance ne sont
 * indiqués que s'ils sont connus (Motex-T SAE 40, d'après son étiquette) ;
 * sinon ils sont à préciser dans la fiche de l'article. Ursa est la gamme
 * d'huiles moteur diesel de Texaco ; Havoline, sa gamme automobile.
 */
export const STARTER_LUBRICANT_PRICE = '25000.00'
export const STARTER_LUBRICANT_QUANTITY = 10

export interface StarterProduct {
  sku: string
  name: string
  brand: string
}

const TO_CONFIRM = 'Article de test : prix, grade et contenance de la caisse à confirmer.'

export const STARTER_LUBRICANTS: StarterProduct[] = [
  { sku: 'LUB-MOTEXT-SAE40', name: 'Huile moteur Motex-T SAE 40', brand: 'Motex-T' },
  { sku: 'LUB-MILLEC-RALLYE', name: 'Huile moteur Mille C Rallye', brand: 'Mille C' },
  { sku: 'LUB-AMERICAN-MOTEUR', name: 'Huile moteur American', brand: 'American' },
  { sku: 'LUB-LION-MOTEUR', name: 'Huile moteur Lion', brand: 'Lion' },
  { sku: 'LUB-CASTROL-MOTEUR', name: 'Huile moteur Castrol', brand: 'Castrol' },
  { sku: 'LUB-HAVOLINE-MOTEUR', name: 'Huile moteur Havoline', brand: 'Havoline' },
  { sku: 'LUB-URSA-DIESEL', name: 'Huile moteur diesel Texaco Ursa', brand: 'Texaco Ursa' },
  { sku: 'LUB-CASTROL-FREIN', name: 'Liquide de frein Castrol', brand: 'Castrol' },
  { sku: 'LUB-CASTROL-TRANSMISSION', name: 'Huile de transmission Castrol', brand: 'Castrol' },
  { sku: 'LUB-HAVOLINE-TRANSMISSION', name: 'Huile de transmission Havoline', brand: 'Havoline' },
]

/** Données d'un article de départ pour la création, prix de gros égal au prix de test. */
export function starterProductInput(product: StarterProduct) {
  return {
    sku: product.sku,
    barcode: null,
    name: product.name,
    category: 'Lubrifiants',
    brand: product.brand,
    unit: 'caisse',
    retail_price_htg: STARTER_LUBRICANT_PRICE,
    wholesale_price_htg: STARTER_LUBRICANT_PRICE,
    reorder_level: 2,
    notes: TO_CONFIRM,
  }
}
