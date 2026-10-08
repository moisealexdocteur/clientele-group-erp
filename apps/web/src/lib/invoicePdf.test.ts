import { describe, expect, it } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import type { RentalInvoice } from '../api/types'
import { buildInvoicePdf } from './invoicePdf'

const invoice: RentalInvoice = {
  id: 'i1',
  number: '0000 0001',
  issued_at: '2026-11-05T11:00:00-05:00',
  currency: 'USD',
  total: '455.00',
  balance_due: '20.00',
  file_url: null,
  snapshot: {
    lessor: { name: 'Société Exemple S.A.', tax_identification_number: null, address: 'Pont Parois', phone_numbers: null },
    customer: { name: 'Client Exemple', email: 'client@example.test', phone: null },
    reservation_number: '0000 0042',
    vehicle: 'Suzuki Jimny 2024',
    pickup_at: '2026-11-02T10:00:00-05:00',
    due_at: '2026-11-05T10:00:00-05:00',
    returned_at: '2026-11-05T10:30:00-05:00',
    odometer_out_km: 150,
    odometer_in_km: 500,
    fuel_out_percent: 100,
    fuel_in_percent: 75,
    currency: 'USD',
    lines: [
      { label: 'Location : 3 jours × 130.00 USD', amount: '390.00' },
      { label: 'Frais aéroport', amount: '20.00' },
      { label: 'Frais de nettoyage', amount: '20.00' },
      { label: 'Kilométrage supplémentaire : 50 km', amount: '25.00' },
    ],
    payments: [{ method: 'cash', currency: 'USD', amount: '390.00', date: '2026-11-02T09:00:00-05:00' }],
    other_currency_payments: [{ method: 'cash', currency: 'HTG', amount: '1000.00', date: null }],
    totals: { total: '455.00', paid: '390.00', credit: '0.00', deposit_applied: '45.00', balance_due: '20.00', overpaid: '0.00' },
    deposit: { retained_usd: '45.00', released_usd: '205.00' },
    timezone: 'America/Port-au-Prince',
  },
}

describe('facture PDF', () => {
  it('produit un PDF titré avec le numéro de facture', async () => {
    const bytes = await buildInvoicePdf(invoice)
    expect(new TextDecoder().decode(bytes.slice(0, 5))).toBe('%PDF-')
    const doc = await PDFDocument.load(bytes)
    expect(doc.getTitle()).toBe('Facture 0000 0001')
    expect(doc.getPageCount()).toBeGreaterThanOrEqual(1)
  })
})
