import { attachContract, uploadFile } from '../../api/carRental'
import { privateFileUrl } from '../../api/client'
import type { CarRentalReservation } from '../../api/types'
import { buildContractPdf } from '../../lib/contractPdf'

/*
 * Émet le contrat signé : construit le PDF à partir des données figées par
 * le serveur, l'enregistre comme document privé et le rattache à la
 * réservation. Peut être relancé tant qu'aucun contrat n'est rattaché.
 */

async function bytes(path: string): Promise<Uint8Array> {
  const url = await privateFileUrl(path)
  const response = await fetch(url)
  return new Uint8Array(await response.arrayBuffer())
}

export async function issueContract(reservation: CarRentalReservation): Promise<{ reservation: CarRentalReservation; sent: boolean }> {
  const inspection = reservation.checkout_inspection
  if (!reservation.contract?.snapshot || !inspection?.customer_signature_url || !inspection.company_signature_url) {
    throw new Error('Les signatures ou les données du contrat ne sont pas accessibles avec vos droits.')
  }

  const [customerSignature, companySignature] = await Promise.all([
    bytes(inspection.customer_signature_url),
    bytes(inspection.company_signature_url),
  ])
  const pdf = await buildContractPdf(reservation, { customerSignature, companySignature })
  const blob = new Blob([pdf as BlobPart], { type: 'application/pdf' })
  const uploaded = await uploadFile('rental_contract', reservation.site_id, blob, `contrat-${reservation.number.replace(/\s/g, '')}.pdf`)
  const result = await attachContract(reservation.id, uploaded.data.id)
  return { reservation: result.data, sent: Boolean(result.customer_notification_sent) }
}
