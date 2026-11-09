import { PDFDocument, StandardFonts, rgb } from 'pdf-lib'
import QRCode from 'qrcode'
import type { MotoOrderDetail } from '../api/types'
import { PAGE, Writer } from './contractPdf'
import { formatMoney, formatRate } from './money'
import { formatDate, formatDateTime } from './time'

/*
 * Documents de la vente d'une motocyclette, au format Lettre US (8,5 x 11 po) :
 * - le reçu de vente officiel, entièrement imprimé par l'ERP sur papier sécurisé ;
 * - les valeurs du certificat de vente du fournisseur, imprimées aux
 *   emplacements du formulaire pré-imprimé, avec un réglage d'alignement.
 */

const MM = 72 / 25.4

function idDocument(order: MotoOrderDetail): string | null {
  if (!order.id_document_number) return null
  return `${order.id_document_label ?? 'Pièce d’identité'} n° ${order.id_document_number}`
}

function motoLine(order: MotoOrderDetail): string {
  const unit = order.unit
  return [unit?.model?.make ?? order.model?.make, unit?.model?.model ?? order.model?.model, unit?.model_year ? String(unit.model_year) : null].filter(Boolean).join(' ')
}

export async function buildMotoReceiptPdf(order: MotoOrderDetail): Promise<Uint8Array> {
  const doc = await PDFDocument.create()
  doc.setTitle(`Reçu de vente ${order.receipt_number ?? ''}`)
  doc.setAuthor(order.company.name)
  doc.setSubject('Reçu de vente de motocyclette')
  doc.setCreator('Clientèle Group ERP')
  doc.setLanguage('fr-HT')
  const w = new Writer(doc, await doc.embedFont(StandardFonts.Helvetica), await doc.embedFont(StandardFonts.HelveticaBold))
  const unit = order.unit

  w.text(order.company.display_name, { size: 15, font: w.bold })
  if (order.company.name !== order.company.display_name) w.text(order.company.name, { size: 9.5 })
  if (order.company.address) w.text(order.company.address, { size: 9 })
  const contact = [order.company.phone_numbers ? `Tél. ${order.company.phone_numbers}` : '', order.company.tax_identification_number ? `NIF ${order.company.tax_identification_number}` : '']
    .filter(Boolean).join(' - ')
  if (contact) w.text(contact, { size: 9 })
  w.text('', { gap: 6 })
  w.text('REÇU DE VENTE DE MOTOCYCLETTE', { size: 16, font: w.bold })
  w.text(`N° ${order.receipt_number ?? ''} - ${order.delivered_at ? formatDateTime(order.delivered_at) : ''}, heure de Cap-Haïtien - commande ${order.number}`, { size: 9.5, gap: 4 })

  w.heading('Acheteur')
  w.facts([
    ['Nom complet', order.customer_name],
    ['Pièce d’identité', idDocument(order)],
    ['Téléphone', order.customer_phone],
    ['Adresse', order.customer_address],
  ])

  w.heading('Motocyclette')
  w.facts([
    ['Marque et modèle', motoLine(order)],
    ['Année', unit ? String(unit.model_year) : null],
    ['Couleur', unit?.color ?? null],
    ['Cylindrée', unit?.displacement_cc ? `${unit.displacement_cc} cm³` : null],
    ['Cylindres', unit ? String(unit.cylinders) : null],
    ['Numéro de châssis', unit?.frame_number ?? null],
    ['Numéro de moteur', unit?.engine_number ?? null],
  ])

  w.heading('Paiement')
  const converted = order.payment_currency !== order.price_currency && order.exchange_rate_htg_per_usd
  w.facts([
    ['Prix de vente', formatMoney(order.price, order.price_currency)],
    ['Réglé par', `Dépôt ${order.bank_name} n° ${order.deposit_reference} du ${order.deposit_date ? formatDate(`${order.deposit_date}T12:00:00`) : ''}`],
    ['Montant déposé', formatMoney(order.payment_amount, order.payment_currency)],
    ...(converted
      ? [
          ['Taux appliqué', formatRate(order.exchange_rate_htg_per_usd)] as [string, string],
          ['Équivalent', formatMoney(order.amount_in_price_currency, order.price_currency)] as [string, string],
        ]
      : []),
    ['Solde', 'Payé intégralement'],
  ])

  w.heading('Remis avec la motocyclette')
  w.facts([
    ...order.accessories.map((item): [string, string] => [`${item.label} (${item.quantity})`, item.delivered ? 'Remis' : 'Non remis']),
    ['Certificat de vente du fournisseur', 'Remis'],
    ['Document de dédouanement', order.customs_document_delivered
      ? `Remis${order.customs_document_delivered_at ? ` le ${formatDate(order.customs_document_delivered_at)}` : ''}`
      : 'Non disponible à la remise. Le client reviendra le récupérer.'],
  ])

  w.heading('Contrôle de conformité fait avec le client')
  w.text(order.checklist.filter((item) => item.ok).map((item) => item.label).join(', ') + '.', { size: 9 })

  w.heading('Conditions')
  w.text('Vente finale, sans garantie. Le client a vérifié la motocyclette et l’a acceptée dans l’état décrit ci-dessus.', { size: 9.5, font: w.bold })
  w.text('Ce reçu, avec le certificat de vente du fournisseur, sert à l’obtention du certificat d’assurance auprès de l’Office d’Assurance des Véhicules Contre Tiers.', { size: 9 })

  w.text('', { gap: 18 })
  w.text('Signature du client : ______________________________      Pour le vendeur : ______________________________', { size: 9 })
  w.text('', { gap: 10 })

  if (order.verification_url) {
    const png = await QRCode.toDataURL(order.verification_url, { margin: 0, errorCorrectionLevel: 'M', width: 240 })
    w.image(await doc.embedPng(png), 72)
    w.text('Scannez le code pour vérifier ce reçu.', { size: 8 })
  }

  w.footer(`Reçu de vente ${order.receipt_number ?? ''}`)
  return doc.save()
}

/** Champ du certificat : valeur et position en points depuis le coin inférieur gauche du formulaire. */
export interface CertificateField {
  key: string
  label: string
  value: string
  x: number
  y: number
  size: number
  align?: 'left' | 'center'
}

/**
 * Emplacements des valeurs sur le certificat de vente Novamat Motors
 * (formulaire Lettre US). Le réglage d'alignement de Configuration déplace
 * l'ensemble : x positif vers la droite, y positif vers le bas.
 */
export function certificateFields(order: MotoOrderDetail, offset: { x_mm: string; y_mm: string } = { x_mm: '0', y_mm: '0' }): CertificateField[] {
  const dx = Number(offset.x_mm || 0) * MM
  const dy = -Number(offset.y_mm || 0) * MM
  const unit = order.unit
  const fields: Array<Omit<CertificateField, 'x' | 'y'> & { x: number; y: number }> = [
    { key: 'customer', label: 'Acheteur', value: order.customer_name, x: 74, y: 380, size: 12 },
    { key: 'identity', label: 'Identifié au', value: idDocument(order) ?? '', x: 160, y: 343, size: 10.5 },
    { key: 'address', label: 'Adresse', value: order.customer_address ?? '', x: 134, y: 308, size: 10 },
    { key: 'phone', label: 'Tél.', value: order.customer_phone, x: 430, y: 308, size: 10 },
    { key: 'year', label: 'Année', value: unit ? String(unit.model_year) : '', x: 92, y: 226, size: 9.5, align: 'center' },
    { key: 'model', label: 'Modèle', value: unit?.model?.model ?? order.model?.model ?? '', x: 166, y: 226, size: 9.5, align: 'center' },
    { key: 'frame', label: 'Frame No', value: unit?.frame_number ?? '', x: 260, y: 226, size: 9, align: 'center' },
    { key: 'engine', label: 'Engine No', value: unit?.engine_number ?? '', x: 375, y: 226, size: 9, align: 'center' },
    { key: 'color', label: 'Color', value: unit?.color ?? '', x: 478, y: 226, size: 9, align: 'center' },
    { key: 'cylinders', label: 'Cyl', value: unit ? String(unit.cylinders) : '', x: 566, y: 226, size: 9.5, align: 'center' },
  ]
  return fields.map((field) => ({ ...field, x: field.x + dx, y: field.y + dy }))
}

/**
 * Valeurs seules, à imprimer sur le formulaire pré-imprimé du fournisseur.
 * Avec `trial`, chaque valeur porte son libellé et une croix de repère pour
 * régler l'alignement sur une feuille ordinaire posée sur le formulaire.
 */
export async function buildMotoCertificatePdf(order: MotoOrderDetail, offset?: { x_mm: string; y_mm: string }, trial = false): Promise<Uint8Array> {
  const doc = await PDFDocument.create()
  doc.setTitle(`Certificat de vente ${order.unit?.frame_number ?? ''}`)
  doc.setCreator('Clientèle Group ERP')
  doc.setLanguage('fr-HT')
  const font = await doc.embedFont(StandardFonts.Helvetica)
  const page = doc.addPage([PAGE.width, PAGE.height])
  const supported = new Set(font.getCharacterSet())
  const clean = (text: string) => Array.from(text).map((char) => (supported.has(char.codePointAt(0) ?? 0) ? char : '?')).join('')

  for (const field of certificateFields(order, offset)) {
    const text = clean(trial ? `${field.label} : ${field.value}` : field.value)
    if (!text) continue
    const width = font.widthOfTextAtSize(text, field.size)
    const x = field.align === 'center' ? field.x - width / 2 : field.x
    page.drawText(text, { x, y: field.y, size: field.size, font, color: rgb(0, 0, 0) })
    if (trial) {
      page.drawLine({ start: { x: field.x - 4, y: field.y }, end: { x: field.x + 4, y: field.y }, thickness: 0.5, color: rgb(0.8, 0, 0) })
      page.drawLine({ start: { x: field.x, y: field.y - 4 }, end: { x: field.x, y: field.y + 4 }, thickness: 0.5, color: rgb(0.8, 0, 0) })
    }
  }

  if (trial) {
    page.drawText(clean('ESSAI D’ALIGNEMENT - imprimer sur papier ordinaire et superposer au formulaire'), { x: 40, y: 760, size: 9, font, color: rgb(0.8, 0, 0) })
  }

  return doc.save()
}
