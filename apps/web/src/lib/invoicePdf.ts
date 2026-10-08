import { PDFDocument, StandardFonts } from 'pdf-lib'
import type { RentalInvoice } from '../api/types'
import { Writer } from './contractPdf'
import { fuelLevelLabel } from './labels'
import { formatMoney, formatRate } from './money'
import { formatDateTime } from './time'

/*
 * Facture de location au format PDF A4, construite uniquement à partir du
 * contenu figé par le serveur à l'émission. Elle ne mentionne ni la plaque
 * ni le permis : elle peut être envoyée au client par courriel.
 */

const methodLabels: Record<string, string> = {
  cash: 'Espèces',
  bank_transfer: 'Virement Sogebank',
  credit: 'Crédit accordé',
}

function when(value: string | null | undefined): string {
  return value ? formatDateTime(value) : ''
}

export async function buildInvoicePdf(invoice: RentalInvoice): Promise<Uint8Array> {
  const data = invoice.snapshot
  const currency = data.currency
  const doc = await PDFDocument.create()
  doc.setTitle(`Facture ${invoice.number}`)
  doc.setAuthor(data.lessor.name)
  doc.setSubject('Facture de location de véhicule')
  doc.setCreator('Clientèle Group ERP')
  doc.setLanguage('fr-HT')

  const w = new Writer(doc, await doc.embedFont(StandardFonts.Helvetica), await doc.embedFont(StandardFonts.HelveticaBold))

  w.text(data.lessor.name, { size: 14, font: w.bold })
  if (data.lessor.address) w.text(data.lessor.address, { size: 9 })
  const contact = [data.lessor.phone_numbers ? `Tél. ${data.lessor.phone_numbers}` : '', data.lessor.tax_identification_number ? `NIF ${data.lessor.tax_identification_number}` : '']
    .filter(Boolean).join(' - ')
  if (contact) w.text(contact, { size: 9 })
  w.text('', { gap: 6 })
  w.text('FACTURE', { size: 16, font: w.bold })
  w.text(`N° ${invoice.number} - émise le ${when(invoice.issued_at)} - réservation ${data.reservation_number}`, { size: 9.5, gap: 4 })

  w.heading('Client')
  w.facts([
    ['Nom', data.customer.name],
    ['Courriel', data.customer.email],
    ['Téléphone', data.customer.phone],
  ])

  w.heading('Location')
  w.facts([
    ['Véhicule', data.vehicle || null],
    ['Départ', when(data.pickup_at)],
    ['Retour prévu', when(data.due_at)],
    ['Retour effectif', when(data.returned_at)],
    ['Kilométrage', data.odometer_out_km !== null && data.odometer_in_km !== null
      ? `${data.odometer_out_km.toLocaleString('fr-FR')} km au départ, ${data.odometer_in_km.toLocaleString('fr-FR')} km au retour (${(data.odometer_in_km - data.odometer_out_km).toLocaleString('fr-FR')} km)`
      : null],
    ['Carburant', `${fuelLevelLabel(data.fuel_out_percent)} au départ, ${fuelLevelLabel(data.fuel_in_percent)} au retour`],
  ])

  w.heading('Détail')
  w.facts(data.lines.map((line) => [line.label, formatMoney(line.amount, currency)] as [string, string]))
  w.facts([['Total', formatMoney(data.totals.total, currency)]])

  w.heading('Règlements')
  const payments: Array<[string, string]> = data.payments.map((payment) => [
    [
      methodLabels[payment.method] ?? payment.method,
      payment.receipt_number ? `reçu ${payment.receipt_number}` : '',
      payment.date ? when(payment.date) : '',
    ].filter(Boolean).join(' - '),
    payment.original_currency && payment.original_amount && payment.exchange_rate_htg_per_usd
      ? `${formatMoney(payment.amount, payment.currency)} (${formatMoney(payment.original_amount, payment.original_currency)}, ${formatRate(payment.exchange_rate_htg_per_usd)})`
      : formatMoney(payment.amount, payment.currency),
  ])
  if (Number(data.totals.deposit_applied) > 0) payments.push(['Dépôt de garantie retenu', formatMoney(data.totals.deposit_applied, currency)])
  w.facts(payments.length ? payments : [['Aucun règlement', formatMoney(0, currency)]])
  if (data.other_currency_payments.length) {
    w.text('Paiements dans une autre devise, non convertis sur cette facture :', { size: 9, gap: 2 })
    w.facts(data.other_currency_payments.map((payment) => [
      `${methodLabels[payment.method] ?? payment.method}${payment.date ? ` - ${when(payment.date)}` : ''}`,
      formatMoney(payment.amount, payment.currency),
    ] as [string, string]))
  }

  w.heading('Solde')
  const totals: Array<[string, string]> = [
    ['Total de la facture', formatMoney(data.totals.total, currency)],
    ['Payé', formatMoney(data.totals.paid, currency)],
  ]
  if (Number(data.totals.deposit_applied) > 0) totals.push(['Dépôt retenu imputé', formatMoney(data.totals.deposit_applied, currency)])
  if (Number(data.totals.credit) > 0) totals.push(['Dont crédit accordé, restant dû', formatMoney(data.totals.credit, currency)])
  totals.push(['Solde dû', formatMoney(data.totals.balance_due, currency)])
  if (Number(data.totals.overpaid) > 0) totals.push(['Trop-perçu', formatMoney(data.totals.overpaid, currency)])
  w.facts(totals)

  if (Number(data.deposit.retained_usd) > 0 || Number(data.deposit.released_usd) > 0) {
    w.heading('Dépôt de garantie')
    w.facts([
      ['Retenu', formatMoney(data.deposit.retained_usd, 'USD')],
      ['Libéré', formatMoney(data.deposit.released_usd, 'USD')],
    ])
  }

  w.footer(`Facture ${invoice.number}`)
  return doc.save()
}
