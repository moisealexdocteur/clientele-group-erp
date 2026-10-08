import { attachInvoiceFile, uploadFile } from '../../api/carRental'
import type { CarRentalReservation } from '../../api/types'
import { buildInvoicePdf } from '../../lib/invoicePdf'

/*
 * Construit le PDF de la facture émise, l'enregistre comme document privé
 * et le rattache. Le serveur l'envoie ensuite au client par courriel.
 */
export async function issueInvoicePdf(reservation: CarRentalReservation) {
  const invoice = reservation.invoice
  if (!invoice) throw new Error('Émettez d’abord la facture.')
  const pdf = await buildInvoicePdf(invoice)
  const blob = new Blob([pdf as BlobPart], { type: 'application/pdf' })
  const uploaded = await uploadFile('rental_invoice', reservation.site_id, blob, `facture-${invoice.number.replace(/\s/g, '')}.pdf`)
  return attachInvoiceFile(reservation.id, uploaded.data.id)
}
