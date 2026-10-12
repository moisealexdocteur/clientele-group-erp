import { describe, expect, it } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import { strFromU8, unzipSync } from 'fflate'
import type { CashSession, DailyCashReport } from '../api/types'
import { buildDailyReportPdf, buildDailyReportXlsx, reportFilename } from './dailyReport'
import { buildXlsx, money } from './xlsx'

const session: CashSession = {
  id: 's1',
  status: 'closed',
  site: { id: 'site', name: 'Pont Parois' },
  cash_register: { id: 'r1', code: 'CAR-01', name: 'Caisse location' },
  opened_at: '2026-10-15T08:00:00-04:00',
  opened_by: 'Agent',
  closed_at: '2026-10-15T18:00:00-04:00',
  closed_by: 'Agent',
  totals: {
    USD: { opening: '50.00', in: '640.00', out: '205.00', expected: '485.00' },
    HTG: { opening: '1000.00', in: '0.00', out: '0.00', expected: '1000.00' },
  },
  declared: { USD: '480.00', HTG: '1000.00' },
  variance: { USD: '-5.00', HTG: '0.00' },
  variance_note: 'Billet <manquant> & rendu',
  review_status: 'pending',
  reviewed_at: null,
  reviewed_by: null,
  review_note: null,
  report_print_count: 0,
  pending_cash_payments: 0,
  can_close: false,
  can_review: true,
  movements: [
    { id: 'm1', kind: 'rental_payment', label: 'Paiement de location', direction: 'in', currency: 'USD', amount: '390.00', occurred_at: '2026-10-15T09:00:00-04:00', receipt_number: '0000 0001', payment_id: 'p1', reservation_id: 'res', reservation_number: '0000 0042', recorded_by: 'Admin' },
    { id: 'm2', kind: 'deposit_refund', label: 'Dépôt de garantie rendu', direction: 'out', currency: 'USD', amount: '205.00', occurred_at: '2026-10-15T17:00:00-04:00', receipt_number: null, payment_id: null, reservation_id: 'res', reservation_number: '0000 0042', recorded_by: 'Admin' },
  ],
}

const report: DailyCashReport = {
  date: '2026-10-15',
  company: { name: 'Société Exemple S.A.', display_name: 'Car Rental', tax_identification_number: null },
  site: null,
  generated_at: '2026-10-15T19:00:00-04:00',
  generated_by: 'Admin',
  sessions: [session],
  totals: {
    USD: { opening: '50.00', in: '640.00', out: '205.00', expected: '485.00', declared: '480.00', variance: '-5.00' },
    HTG: { opening: '1000.00', in: '0.00', out: '0.00', expected: '1000.00', declared: '1000.00', variance: '0.00' },
  },
  movements_by_kind: [{ kind: 'rental_payment', label: 'Paiement de location', direction: 'in', currency: 'USD', count: 1, amount: '390.00' }],
  other_payments: [{ method: 'bank_transfer', currency: 'USD', count: 1, amount: '130.00' }],
  open_sessions: 0,
  pending_reviews: 1,
  reprints: 2,
}

describe('rapport journalier', () => {
  it('produit un PDF A4 lisible', async () => {
    const bytes = await buildDailyReportPdf(report)
    const pdf = await PDFDocument.load(bytes)
    expect(pdf.getPageCount()).toBeGreaterThanOrEqual(1)
    expect(pdf.getTitle()).toContain('Rapport journalier de caisse')
  })

  it('produit un classeur Excel avec trois feuilles et des montants numériques', () => {
    const files = unzipSync(buildDailyReportXlsx(report))
    expect(Object.keys(files)).toContain('xl/workbook.xml')
    const workbook = strFromU8(files['xl/workbook.xml'])
    expect(workbook).toContain('name="Synthèse"')
    expect(workbook).toContain('name="Mouvements"')
    const sessions = strFromU8(files['xl/worksheets/sheet2.xml'])
    expect(sessions).toContain('Billet &lt;manquant&gt; &amp; rendu')
    expect(sessions).toContain('<v>-5</v>')
    const movements = strFromU8(files['xl/worksheets/sheet3.xml'])
    expect(movements).toContain('<v>-205</v>')
    expect(reportFilename(report, 'xlsx')).toBe('Rapport-caisse-2026-10-15.xlsx')
  })

  it('nomme les colonnes au-delà de Z', () => {
    const row = Array.from({ length: 28 }, (_, index) => index)
    const files = unzipSync(buildXlsx([{ name: 'Feuille: test', rows: [row, [money('12.50')]] }], 'Test'))
    const sheet = strFromU8(files['xl/worksheets/sheet1.xml'])
    expect(sheet).toContain('r="AB1"')
    expect(sheet).toContain('<c r="A2" s="2"><v>12.5</v></c>')
    expect(strFromU8(files['xl/workbook.xml'])).toContain('name="Feuille  test"')
  })
})
