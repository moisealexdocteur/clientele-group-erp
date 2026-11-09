import { describe, expect, it } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import type { MotoOrderDetail } from '../api/types'
import { buildMotoCertificatePdf, buildMotoReceiptPdf, certificateFields } from './motoPdf'

const model = { id: 'm', make: 'Haojin', model: 'HJ-125A-D', label: 'Haojin HJ-125A-D', displacement_cc: 125, cylinders: 1, price: '1450.00', price_currency: 'USD' as const, is_active: true }

const order: MotoOrderDetail = {
  id: 'o1',
  number: '0000 0012',
  status: 'delivered',
  status_label: 'Livrée',
  site: { id: 's', name: 'Rue 4 A' },
  model,
  preferred_color: null,
  customer_name: 'Jean Baptiste',
  customer_phone: '+509 3700 0000',
  price: '1450.00',
  price_currency: 'USD',
  deposit_date: '2026-11-02',
  submitted_at: null,
  queue_position: null,
  unit: {
    id: 'u', status: 'delivered', status_label: 'Livrée', model, model_year: 2026, frame_number: 'LZL12P1A8THE50741', engine_number: 'HJ157FMI260550741',
    color: 'Rouge Sang', cylinders: 1, displacement_cc: 125, customs_document_available: false, site: { id: 'p', name: 'Pont Parois' }, order_id: 'o1', batch_id: 'b',
  },
  called_at: null,
  delivered_at: '2026-11-08T15:00:00-05:00',
  customs_document_pending: true,
  customer_email: null,
  customer_address: 'Rue 15, Cap-Haïtien',
  payment_amount: '191400.00',
  payment_currency: 'HTG',
  exchange_rate_htg_per_usd: '132.0000',
  amount_in_price_currency: '1450.00',
  bank_name: 'Sogebank',
  deposit_reference: 'SG-778',
  deposit_proof_url: null,
  verified_at: null,
  assigned_at: null,
  assignment_note: null,
  id_document_type: 'cin',
  id_document_label: 'Carte d’identification nationale (CIN)',
  id_document_number: '01-02-99',
  checklist: [{ key: 'horn', label: 'Klaxon', ok: true }],
  accessories: [{ key: 'helmets', label: 'Casques', quantity: 3, delivered: true }],
  customs_document_delivered: false,
  customs_document_delivered_at: null,
  receipt_number: '0000 0004',
  receipt_print_count: 0,
  certificate_print_count: 0,
  verification_url: null,
  cancellation_reason: null,
  cancelled_at: null,
  company: { display_name: 'Clientèle Auto Parts', name: 'Clientèle Auto Parts S.A.', address: null, phone_numbers: null, tax_identification_number: null },
}

describe('documents de vente de moto', () => {
  it('produit le reçu de vente au format Lettre', async () => {
    const pdf = await PDFDocument.load(await buildMotoReceiptPdf(order))
    expect(pdf.getTitle()).toBe('Reçu de vente 0000 0004')
    for (const page of pdf.getPages()) expect(page.getSize()).toEqual({ width: 612, height: 792 })
  })

  it('place les valeurs du certificat et applique le réglage d’alignement', async () => {
    const base = certificateFields(order)
    const moved = certificateFields(order, { x_mm: '10', y_mm: '5' })
    const frame = (fields: typeof base) => fields.find((field) => field.key === 'frame')!
    expect(frame(base).value).toBe('LZL12P1A8THE50741')
    expect(frame(moved).x - frame(base).x).toBeCloseTo(28.35, 1)
    // Vers le bas : la coordonnée PDF diminue.
    expect(frame(moved).y - frame(base).y).toBeCloseTo(-14.17, 1)
    const pdf = await PDFDocument.load(await buildMotoCertificatePdf(order))
    expect(pdf.getPageCount()).toBe(1)
    expect(pdf.getPage(0).getSize()).toEqual({ width: 612, height: 792 })
  })
})
