import { describe, expect, it } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import type { CarRentalReservation } from '../api/types'
import { buildContractPdf } from './contractPdf'
import { countryName, licenseIssuer, needsSubdivision } from './countries'

const png = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), (c) => c.charCodeAt(0))

function reservation(terms: string): CarRentalReservation {
  return {
    id: 'r1',
    number: '0000 0042',
    state: 'checked_out',
    site_id: 's1',
    site: { id: 's1', code: 'PP', name: 'Pont Parois' },
    pickup_at: '2026-10-09T09:00:00-04:00',
    due_at: '2026-10-12T09:00:00-04:00',
    checked_out_at: '2026-10-09T09:05:00-04:00',
    returned_at: null,
    lock_version: 2,
    pickup_location: { type: 'site', detail: null },
    dropoff_location: { type: 'cap_haitien_airport', detail: null },
    airport_pickup_fee_usd: '0.00',
    airport_dropoff_fee_usd: '20.00',
    airport_fees_total_usd: '20.00',
    currency: 'USD',
    daily_rate: '120.00',
    minimum_security_deposit_usd: '500.00',
    kilometer_plan: 'limited',
    included_km: 300,
    additional_km_rate: null,
    driver_full_name: 'Client Exemple',
    driver_license_expires_at: '2028-01-01',
    driver_license_verified: true,
    vehicle: null,
    customer: { id: 'c1', display_name: 'Client Exemple', customer_type: 'individual', email: 'client@example.test', phone: null },
    payments: [],
    security_deposits: [],
    checkout_requirements: {
      contract_terms_configured: true,
      driver_license_verified: true,
      approved_rental_payment: true,
      minimum_security_deposit_configured: true,
      minimum_security_deposit_usd: '500.00',
      held_security_deposit_usd: '500.00',
      security_deposit_satisfied: true,
    },
    driver_license: { country: 'US', subdivision: 'Floride', number: 'F123-456', expires_at: '2028-01-01', front_url: null, back_url: null },
    additional_driver: null,
    checkout_inspection: {
      inspected_at: '2026-10-09T09:05:00-04:00',
      odometer_km: 15900,
      fuel_level_percent: 75,
      accessories: ['spare_tire', 'jack'],
      damage_notes: null,
      photo_urls: [],
      company_signer_name: 'Agent Comptoir',
      customer_signed_at: '2026-10-09T09:05:00-04:00',
      company_signed_at: '2026-10-09T09:05:00-04:00',
      customer_signature_url: '/s1',
      company_signature_url: '/s2',
    },
    contract: {
      issued_at: null,
      file_url: null,
      snapshot: {
        lessor: { name: 'Société Exemple S.A.', display_name: 'Exemple', representative: null, tax_identification_number: null, address: 'Pont Parois, Route Nationale 6', phone_numbers: null },
        terms,
        terms_sha256: 'a'.repeat(64),
        vehicle: { make: 'Suzuki', model: 'Jimny', model_year: 2024, registration_number: 'LO-01725', vin: null, color: 'Blanche', fuel_type: 'gasoline', transmission: 'manual', engine_displacement_cc: 1500, doors: 4, category: 'suv' },
        timezone: 'America/Port-au-Prince',
      },
    },
  }
}

describe('contrat PDF', () => {
  it('produit un PDF lisible, sur plusieurs pages si les conditions sont longues', async () => {
    const longTerms = Array.from({ length: 12 }, (_, index) => `Article ${index + 1} - Titre\n${'Texte du contrat avec « guillemets » et l’apostrophe ≥ symbole. '.repeat(30)}`).join('\n\n')
    const bytes = await buildContractPdf(reservation(longTerms), { customerSignature: png, companySignature: png })
    expect(new TextDecoder().decode(bytes.slice(0, 5))).toBe('%PDF-')
    const doc = await PDFDocument.load(bytes)
    expect(doc.getPageCount()).toBeGreaterThan(2)
    expect(doc.getTitle()).toBe('Contrat de location 0000 0042')
  })

  it('refuse de produire un contrat sans données signées', async () => {
    const value = reservation('Article 1')
    value.contract = { issued_at: null, file_url: null, snapshot: null }
    await expect(buildContractPdf(value, { customerSignature: png, companySignature: png })).rejects.toThrow()
  })
})

describe('pays émetteur du permis', () => {
  it('demande un État seulement pour les pays concernés', () => {
    expect(needsSubdivision('US')).toBe(true)
    expect(needsSubdivision('CA')).toBe(true)
    expect(needsSubdivision('HT')).toBe(false)
    expect(needsSubdivision('FR')).toBe(false)
  })

  it('affiche le pays en français', () => {
    expect(countryName('HT')).toBe('Haïti')
    expect(licenseIssuer('US', 'Floride')).toBe('Floride, États-Unis')
  })
})
