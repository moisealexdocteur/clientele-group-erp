import { PDFDocument, StandardFonts } from 'pdf-lib'
import type { CashSession, Currency, DailyCashReport } from '../api/types'
import { Writer } from './contractPdf'
import { formatMoney } from './money'
import { formatDate, formatDateTime, formatTime } from './time'
import { bold, buildXlsx, money, type XlsxRow } from './xlsx'

/*
 * Rapport journalier de caisse : mêmes données à l'écran, en PDF A4 et en
 * Excel. Aucun nom ni coordonnée de client : réservations et reçus sont
 * identifiés par leur numéro.
 */

export const CURRENCIES: Currency[] = ['USD', 'HTG']

export const paymentMethodLabels: Record<string, string> = {
  cash: 'Espèces',
  bank_transfer: 'Virement Sogebank',
  credit: 'Crédit accordé',
}

export const reviewLabels: Record<CashSession['review_status'], string> = {
  none: 'Sans écart',
  pending: 'Écart à approuver',
  approved: 'Écart approuvé',
}

export function sessionStateLabel(session: CashSession): string {
  if (session.status === 'open') return 'Ouverte'
  return reviewLabels[session.review_status]
}

export function reportTitle(report: DailyCashReport): string {
  return `Rapport journalier de caisse - ${formatDate(`${report.date}T12:00:00`)}`
}

export function reportFilename(report: DailyCashReport, extension: 'pdf' | 'xlsx'): string {
  return `Rapport-caisse-${report.date}.${extension}`
}

function signed(value: string, currency: Currency): string {
  const amount = Number(value)
  return `${amount > 0 ? '+' : ''}${formatMoney(value, currency)}`
}

export async function buildDailyReportPdf(report: DailyCashReport): Promise<Uint8Array> {
  const doc = await PDFDocument.create()
  doc.setTitle(reportTitle(report))
  doc.setAuthor(report.company.name)
  doc.setCreator('Clientèle Group ERP')
  doc.setLanguage('fr-HT')
  const w = new Writer(doc, await doc.embedFont(StandardFonts.Helvetica), await doc.embedFont(StandardFonts.HelveticaBold))

  w.text(report.company.name, { size: 13, font: w.bold })
  if (report.company.tax_identification_number) w.text(`NIF ${report.company.tax_identification_number}`, { size: 9 })
  w.text('', { gap: 4 })
  w.text('RAPPORT JOURNALIER DE CAISSE', { size: 15, font: w.bold })
  w.text(`${formatDate(`${report.date}T12:00:00`)} - heure de Cap-Haïtien - ${report.site ?? 'Toutes les adresses autorisées'}`, { size: 9.5 })
  w.text(`Établi le ${formatDateTime(report.generated_at)}${report.generated_by ? ` par ${report.generated_by}` : ''}`, { size: 8.5, gap: 4 })

  w.heading('Espèces par devise')
  w.table(
    [
      { label: 'Devise', weight: 1 },
      { label: 'Fond', weight: 2, align: 'right' },
      { label: 'Entrées', weight: 2, align: 'right' },
      { label: 'Sorties', weight: 2, align: 'right' },
      { label: 'Attendu', weight: 2, align: 'right' },
      { label: 'Compté', weight: 2, align: 'right' },
      { label: 'Écart', weight: 2, align: 'right' },
    ],
    CURRENCIES.map((currency) => {
      const total = report.totals[currency]
      return {
        cells: [currency, formatMoney(total.opening, currency), formatMoney(total.in, currency), formatMoney(total.out, currency), formatMoney(total.expected, currency), formatMoney(total.declared, currency), signed(total.variance, currency)],
      }
    }),
  )
  const notes: string[] = []
  if (report.open_sessions) notes.push(`${report.open_sessions} session(s) encore ouverte(s) : le compté et l'écart ne les incluent pas.`)
  if (report.pending_reviews) notes.push(`${report.pending_reviews} écart(s) en attente d'approbation.`)
  notes.push(`Réimpressions de reçus et de rapports : ${report.reprints}.`)
  for (const note of notes) w.text(note, { size: 8.8 })

  w.heading('Sessions de caisse')
  if (!report.sessions.length) w.text('Aucune session ouverte ce jour.', { size: 9 })
  else {
    w.table(
      [
        { label: 'Caisse', weight: 3 },
        { label: 'Ouverture', weight: 3 },
        { label: 'Clôture', weight: 3 },
        { label: 'Attendu', weight: 3, align: 'right' },
        { label: 'Compté', weight: 3, align: 'right' },
        { label: 'Écart', weight: 3, align: 'right' },
        { label: 'État', weight: 3 },
      ],
      report.sessions.map((session) => ({
        cells: [
          `${session.cash_register.name ?? ''}\n${session.site.name ?? ''}`,
          `${session.opened_at ? formatTime(session.opened_at) : ''}\n${session.opened_by ?? ''}`,
          session.closed_at ? `${formatTime(session.closed_at)}\n${session.closed_by ?? ''}` : '-',
          CURRENCIES.map((currency) => formatMoney(session.totals[currency].expected, currency)).join('\n'),
          session.declared ? CURRENCIES.map((currency) => formatMoney(session.declared?.[currency], currency)).join('\n') : '-',
          session.variance ? CURRENCIES.map((currency) => signed(session.variance?.[currency] ?? '0', currency)).join('\n') : '-',
          sessionStateLabel(session),
        ],
      })),
    )
    for (const session of report.sessions.filter((item) => item.variance_note)) {
      w.text(`${session.cash_register.name} - écart : ${session.variance_note}${session.review_note ? ` Décision : ${session.review_note}` : ''}`, { size: 8.8 })
    }
  }

  w.heading('Mouvements d’espèces par nature')
  if (!report.movements_by_kind.length) w.text('Aucun mouvement d’espèces.', { size: 9 })
  else {
    w.table(
      [
        { label: 'Nature', weight: 5 },
        { label: 'Sens', weight: 2 },
        { label: 'Nombre', weight: 2, align: 'right' },
        { label: 'Montant', weight: 3, align: 'right' },
      ],
      report.movements_by_kind.map((row) => ({
        cells: [row.label, row.direction === 'in' ? 'Entrée' : 'Sortie', String(row.count), formatMoney(row.amount, row.currency)],
      })),
    )
  }

  w.heading('Autres règlements approuvés')
  if (!report.other_payments.length) w.text('Aucun virement ni crédit approuvé ce jour.', { size: 9 })
  else {
    w.table(
      [
        { label: 'Mode', weight: 5 },
        { label: 'Nombre', weight: 2, align: 'right' },
        { label: 'Montant', weight: 3, align: 'right' },
      ],
      report.other_payments.map((row) => ({
        cells: [paymentMethodLabels[row.method] ?? row.method, String(row.count), formatMoney(row.amount, row.currency)],
      })),
    )
  }

  w.heading('Détail des mouvements')
  const movements = report.sessions.flatMap((session) => (session.movements ?? []).map((movement) => ({ session, movement })))
  if (!movements.length) w.text('Aucun mouvement.', { size: 9 })
  else {
    w.table(
      [
        { label: 'Heure', weight: 2 },
        { label: 'Caisse', weight: 3 },
        { label: 'Nature', weight: 4 },
        { label: 'Reçu', weight: 2 },
        { label: 'Réservation', weight: 2 },
        { label: 'Montant', weight: 3, align: 'right' },
      ],
      movements.map(({ session, movement }) => ({
        cells: [
          movement.occurred_at ? formatTime(movement.occurred_at) : '',
          session.cash_register.name ?? '',
          movement.label,
          movement.receipt_number ?? '-',
          movement.reservation_number ?? '-',
          `${movement.direction === 'out' ? '-' : ''}${formatMoney(movement.amount, movement.currency)}`,
        ],
      })),
    )
  }

  w.text('', { gap: 16 })
  w.text('Vérifié par : ____________________________    Signature : ____________________', { size: 9 })
  w.footer(reportTitle(report))

  return doc.save()
}

export function buildDailyReportXlsx(report: DailyCashReport): Uint8Array {
  const summary: XlsxRow[] = [
    [bold(report.company.name)],
    [bold('Rapport journalier de caisse')],
    ['Date', formatDate(`${report.date}T12:00:00`)],
    ['Adresse', report.site ?? 'Toutes les adresses autorisées'],
    ['Fuseau', 'Heure de Cap-Haïtien'],
    ['Établi le', formatDateTime(report.generated_at)],
    ['Établi par', report.generated_by ?? ''],
    [],
    [bold('Devise'), bold('Fond'), bold('Entrées'), bold('Sorties'), bold('Attendu'), bold('Compté'), bold('Écart')],
    ...CURRENCIES.map((currency): XlsxRow => {
      const total = report.totals[currency]
      return [currency, money(total.opening), money(total.in), money(total.out), money(total.expected), money(total.declared), money(total.variance)]
    }),
    [],
    ['Sessions encore ouvertes', report.open_sessions],
    ['Écarts à approuver', report.pending_reviews],
    ['Réimpressions', report.reprints],
    [],
    [bold('Mouvements par nature'), bold('Sens'), bold('Devise'), bold('Nombre'), bold('Montant')],
    ...report.movements_by_kind.map((row): XlsxRow => [row.label, row.direction === 'in' ? 'Entrée' : 'Sortie', row.currency, row.count, money(row.amount)]),
    [],
    [bold('Autres règlements'), bold('Devise'), bold('Nombre'), bold('Montant')],
    ...report.other_payments.map((row): XlsxRow => [paymentMethodLabels[row.method] ?? row.method, row.currency, row.count, money(row.amount)]),
  ]

  const sessions: XlsxRow[] = [
    ['Caisse', 'Adresse', 'Ouverte le', 'Ouverte par', 'Clôturée le', 'Clôturée par', 'Fond USD', 'Fond HTG', 'Attendu USD', 'Attendu HTG', 'Compté USD', 'Compté HTG', 'Écart USD', 'Écart HTG', 'État', 'Explication', 'Décision'].map(bold),
    ...report.sessions.map((session): XlsxRow => [
      session.cash_register.name,
      session.site.name,
      session.opened_at ? formatDateTime(session.opened_at) : '',
      session.opened_by,
      session.closed_at ? formatDateTime(session.closed_at) : '',
      session.closed_by,
      money(session.totals.USD.opening),
      money(session.totals.HTG.opening),
      money(session.totals.USD.expected),
      money(session.totals.HTG.expected),
      money(session.declared?.USD),
      money(session.declared?.HTG),
      money(session.variance?.USD),
      money(session.variance?.HTG),
      sessionStateLabel(session),
      session.variance_note,
      session.review_note,
    ]),
  ]

  const movements: XlsxRow[] = [
    ['Date et heure', 'Caisse', 'Nature', 'Sens', 'Devise', 'Montant', 'Reçu', 'Réservation', 'Enregistré par'].map(bold),
    ...report.sessions.flatMap((session) =>
      (session.movements ?? []).map((movement): XlsxRow => [
        movement.occurred_at ? formatDateTime(movement.occurred_at) : '',
        session.cash_register.name,
        movement.label,
        movement.direction === 'in' ? 'Entrée' : 'Sortie',
        movement.currency,
        money(movement.direction === 'out' ? `-${movement.amount}` : movement.amount),
        movement.receipt_number,
        movement.reservation_number,
        movement.recorded_by,
      ]),
    ),
  ]

  return buildXlsx(
    [
      { name: 'Synthèse', rows: summary, widths: [30, 18, 16, 16, 16, 16, 16] },
      { name: 'Sessions', rows: sessions, widths: [22, 22, 24, 20, 24, 20, 12, 14, 12, 14, 12, 14, 12, 14, 18, 40, 40] },
      { name: 'Mouvements', rows: movements, widths: [24, 22, 28, 10, 8, 14, 12, 14, 20] },
    ],
    reportTitle(report),
  )
}
