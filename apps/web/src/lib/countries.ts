/*
 * Pays émetteurs de permis de conduire (codes ISO 3166-1 alpha-2) et
 * subdivisions lorsque le permis est délivré par un État ou une province.
 * Les noms sont produits en français par le navigateur.
 */

const CODES = (
  'AD AE AF AG AI AL AM AO AR AT AU AW AZ BA BB BD BE BF BG BH BI BJ BM BN BO BR BS BT BW BY BZ '
  + 'CA CD CF CG CH CI CL CM CN CO CR CU CV CW CY CZ DE DJ DK DM DO DZ EC EE EG ER ES ET FI FJ FR '
  + 'GA GB GD GE GF GH GI GM GN GP GQ GR GT GW GY HK HN HR HT HU ID IE IL IN IQ IR IS IT JM JO JP '
  + 'KE KG KH KM KN KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MG MK ML MM MN MQ MR '
  + 'MT MU MV MW MX MY MZ NA NE NG NI NL NO NP NZ OM PA PE PG PH PK PL PR PS PT PY QA RE RO RS RU '
  + 'RW SA SB SC SD SE SG SI SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TG TH TJ TL TM TN TO TR TT '
  + 'TW TZ UA UG US UY UZ VC VE VG VI VN VU WS YE ZA ZM ZW'
).split(' ')

/** Pays proposés en premier : clientèle habituelle de Cap-Haïtien. */
export const FREQUENT_COUNTRIES = ['HT', 'US', 'CA', 'DO', 'FR']

/** Doit rester identique à CarRentalController::LICENSE_SUBDIVISION_COUNTRIES. */
export const SUBDIVISION_COUNTRIES = ['US', 'CA', 'MX', 'AU', 'BR', 'IN']

let displayNames: Intl.DisplayNames | null = null

export function countryName(code: string): string {
  try {
    displayNames ??= new Intl.DisplayNames(['fr'], { type: 'region' })
    return displayNames.of(code) ?? code
  } catch {
    return code
  }
}

export interface CountryOption {
  code: string
  name: string
}

export function countryOptions(): { frequent: CountryOption[]; others: CountryOption[] } {
  const all = CODES.map((code) => ({ code, name: countryName(code) }))
  const collator = new Intl.Collator('fr')
  return {
    frequent: FREQUENT_COUNTRIES.map((code) => ({ code, name: countryName(code) })),
    others: all.filter((item) => !FREQUENT_COUNTRIES.includes(item.code)).sort((a, b) => collator.compare(a.name, b.name)),
  }
}

export function needsSubdivision(country: string): boolean {
  return SUBDIVISION_COUNTRIES.includes(country)
}

export function subdivisionLabel(country: string): string {
  if (country === 'CA') return 'Province ou territoire'
  if (country === 'US' || country === 'MX' || country === 'BR' || country === 'IN' || country === 'AU') return 'État ou territoire'
  return 'Subdivision'
}

/** Listes fermées pour les pays les plus fréquents ; saisie libre ailleurs. */
export const SUBDIVISIONS: Record<string, string[]> = {
  US: [
    'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'Californie', 'Caroline du Nord', 'Caroline du Sud', 'Colorado',
    'Connecticut', 'Dakota du Nord', 'Dakota du Sud', 'Delaware', 'District de Columbia', 'Floride', 'Géorgie',
    'Hawaï', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiane', 'Maine', 'Maryland',
    'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada',
    'New Hampshire', 'New Jersey', 'New York', 'Nouveau-Mexique', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvanie',
    'Porto Rico', 'Rhode Island', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginie', 'Virginie-Occidentale',
    'Washington', 'Wisconsin', 'Wyoming',
  ],
  CA: [
    'Alberta', 'Colombie-Britannique', 'Île-du-Prince-Édouard', 'Manitoba', 'Nouveau-Brunswick', 'Nouvelle-Écosse',
    'Nunavut', 'Ontario', 'Québec', 'Saskatchewan', 'Terre-Neuve-et-Labrador', 'Territoires du Nord-Ouest', 'Yukon',
  ],
}

export function licenseIssuer(country: string, subdivision: string | null | undefined): string {
  const name = countryName(country)
  return subdivision ? `${subdivision}, ${name}` : name
}
