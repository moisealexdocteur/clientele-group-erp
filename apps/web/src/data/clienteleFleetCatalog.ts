export type FleetCatalogRegistrationStatus = 'demonstration' | 'location' | 'normal'
export type FleetCatalogCategory = 'suv' | 'mid_suv' | 'pickup'

export interface FleetCatalogReferencePhoto {
  key: string
  url: string
  alt: string
  label: string
  sourceUrl: string
}

export interface FleetCatalogVehicle {
  registrationNumber: string
  registrationStatus: FleetCatalogRegistrationStatus
  category: FleetCatalogCategory
  make: string
  model: string
  requiresReview: boolean
  reviewMessage?: string
  referencePhoto: FleetCatalogReferencePhoto | null
  sourceUrl: string
}

const CLIENTELE_FLEET_PHOTO_POST = 'https://www.tiktok.com/@clientele_group/photo/7618667507980782866?lang=fr'
const CLIENTELE_FLEET_VIDEO = 'https://www.tiktok.com/@clientele_group/video/7679127374331481352?lang=fr'
const CLIENTELE_FLEET_SECOND_VIDEO = 'https://www.tiktok.com/@sully.simon68/video/7686455534647659797?lang=fr'

/*
 * Catalogue de préremplissage issu des publications publiques fournies par
 * le propriétaire. Les entrées « à vérifier » ne sont jamais créées seules :
 * l'utilisateur doit contrôler la plaque sur le véhicule et saisir les
 * données opérationnelles réelles avant l'enregistrement.
 */
export const clienteleFleetCatalog: FleetCatalogVehicle[] = [
  {
    registrationNumber: 'AA-85177',
    registrationStatus: 'normal',
    category: 'pickup',
    make: 'Nissan',
    model: 'Frontier',
    requiresReview: false,
    referencePhoto: {
      key: 'nissan-frontier-aa-85177',
      url: '/fleet/nissan-frontier-aa-85177.jpg',
      alt: 'Nissan Frontier bleu, plaque AA-85177',
      label: 'Photo de référence — publication Clientèle Group',
      sourceUrl: CLIENTELE_FLEET_PHOTO_POST,
    },
    sourceUrl: CLIENTELE_FLEET_PHOTO_POST,
  },
  {
    registrationNumber: 'LO-01727',
    registrationStatus: 'location',
    category: 'suv',
    make: 'Suzuki',
    model: 'Jimny',
    requiresReview: false,
    referencePhoto: {
      key: 'suzuki-jimny-lo-01727',
      url: '/fleet/suzuki-jimny-lo-01727.jpg',
      alt: 'Suzuki Jimny noir, plaque LO-01727',
      label: 'Photo de référence — publication Clientèle Group',
      sourceUrl: CLIENTELE_FLEET_VIDEO,
    },
    sourceUrl: CLIENTELE_FLEET_VIDEO,
  },
  {
    registrationNumber: 'DM-00849',
    registrationStatus: 'demonstration',
    category: 'pickup',
    make: 'Great Wall',
    model: 'Poer',
    requiresReview: false,
    referencePhoto: {
      key: 'great-wall-poer-dm-00849',
      url: '/fleet/great-wall-poer-dm-00849.jpg',
      alt: 'Great Wall Poer blanche, plaque DM-00849',
      label: 'Photo de référence — publication Clientèle Group',
      sourceUrl: CLIENTELE_FLEET_PHOTO_POST,
    },
    sourceUrl: CLIENTELE_FLEET_PHOTO_POST,
  },
  {
    registrationNumber: 'LO-01724',
    registrationStatus: 'location',
    category: 'suv',
    make: 'Suzuki',
    model: 'Jimny',
    requiresReview: true,
    reviewMessage: 'Vérifiez la plaque sur le véhicule et la carte grise avant l’enregistrement.',
    referencePhoto: null,
    sourceUrl: CLIENTELE_FLEET_VIDEO,
  },
  {
    registrationNumber: 'DM-00437',
    registrationStatus: 'demonstration',
    category: 'suv',
    make: 'BAIC',
    model: 'BJ40',
    requiresReview: true,
    reviewMessage: 'Vérifiez le modèle et la plaque sur le véhicule et la carte grise avant l’enregistrement.',
    referencePhoto: null,
    sourceUrl: CLIENTELE_FLEET_VIDEO,
  },
  {
    registrationNumber: 'DM-00835',
    registrationStatus: 'demonstration',
    category: 'pickup',
    make: 'Great Wall',
    model: 'Poer',
    requiresReview: true,
    reviewMessage: 'Vérifiez la plaque et le modèle sur le véhicule et la carte grise avant l’enregistrement.',
    referencePhoto: null,
    sourceUrl: CLIENTELE_FLEET_SECOND_VIDEO,
  },
]
