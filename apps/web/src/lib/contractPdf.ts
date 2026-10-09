import { PDFDocument, StandardFonts, rgb, type PDFFont, type PDFImage, type PDFPage } from 'pdf-lib'
import type { CarRentalReservation, ContractSnapshot } from '../api/types'
import { accessoryLabels, categoryLabels, fuelLevelLabel, fuelTypeLabels, locationLabels, transmissionLabels } from './labels'
import { licenseIssuer } from './countries'
import { formatMoney } from './money'
import { formatDate, formatDateTime, rentalDays } from './time'
import { damageKindLabels, SKETCH_BODY, SKETCH_DETAILS, SKETCH_HEIGHT, SKETCH_WHEELS, SKETCH_WIDTH, type DamageMark } from './damageSketch'

/*
 * Contrat de location au format PDF A4, construit uniquement à partir de
 * la copie figée enregistrée par le serveur au moment de la signature
 * (identité du loueur, conditions générales, véhicule) et des données de
 * la réservation. Les signatures sont les images tracées au comptoir.
 */

export interface ContractImages {
  customerSignature: Uint8Array
  companySignature: Uint8Array
}

export const A4 = { width: 595.28, height: 841.89 }
export const MARGIN = 48
export const INK = rgb(0.07, 0.09, 0.15)
export const MUTED = rgb(0.38, 0.4, 0.45)
const LINE = rgb(0.82, 0.83, 0.86)
const ACCENT = rgb(0.06, 0.42, 0.74)

export class Writer {
  private page!: PDFPage
  private y = 0
  readonly pages: PDFPage[] = []

  constructor(
    private readonly doc: PDFDocument,
    readonly regular: PDFFont,
    readonly bold: PDFFont,
  ) {
    this.addPage()
  }

  private get width(): number {
    return A4.width - MARGIN * 2
  }

  addPage(): void {
    this.page = this.doc.addPage([A4.width, A4.height])
    this.pages.push(this.page)
    this.y = A4.height - MARGIN
  }

  ensure(height: number): void {
    if (this.y - height < MARGIN + 24) this.addPage()
  }

  /** Remplace les caractères absents de la police standard. */
  clean(text: string, font: PDFFont = this.regular): string {
    const supported = new Set(font.getCharacterSet())
    return Array.from(text.replace(/\t/g, '    ').replace(/ | /g, ' '))
      .map((char) => (supported.has(char.codePointAt(0) ?? 0) ? char : '?'))
      .join('')
  }

  wrap(text: string, font: PDFFont, size: number, width: number): string[] {
    const lines: string[] = []
    for (const paragraph of this.clean(text, font).split(/\r?\n/)) {
      if (!paragraph.trim()) {
        lines.push('')
        continue
      }
      let line = ''
      for (const word of paragraph.split(/\s+/)) {
        const candidate = line ? `${line} ${word}` : word
        if (font.widthOfTextAtSize(candidate, size) <= width) {
          line = candidate
          continue
        }
        if (line) lines.push(line)
        // Mot plus long que la ligne : coupé au caractère.
        let rest = word
        while (font.widthOfTextAtSize(rest, size) > width) {
          let cut = rest.length - 1
          while (cut > 1 && font.widthOfTextAtSize(rest.slice(0, cut), size) > width) cut -= 1
          lines.push(rest.slice(0, cut))
          rest = rest.slice(cut)
        }
        line = rest
      }
      lines.push(line)
    }
    return lines
  }

  text(text: string, options: { size?: number; font?: PDFFont; color?: ReturnType<typeof rgb>; gap?: number; x?: number; width?: number } = {}): void {
    const size = options.size ?? 9.5
    const font = options.font ?? this.regular
    const x = options.x ?? MARGIN
    const width = options.width ?? this.width
    const leading = size * 1.35
    for (const line of this.wrap(text, font, size, width)) {
      this.ensure(leading)
      this.page.drawText(line, { x, y: this.y - size, size, font, color: options.color ?? INK })
      this.y -= leading
    }
    this.y -= options.gap ?? 0
  }

  heading(text: string): void {
    this.ensure(40)
    this.y -= 10
    this.page.drawText(this.clean(text.toUpperCase(), this.bold), { x: MARGIN, y: this.y - 10, size: 10, font: this.bold, color: ACCENT })
    this.y -= 15
    this.page.drawLine({ start: { x: MARGIN, y: this.y }, end: { x: MARGIN + this.width, y: this.y }, thickness: 0.6, color: LINE })
    this.y -= 6
  }

  /** Tableau libellé - valeur sur deux colonnes. */
  facts(rows: Array<[string, string | null | undefined]>): void {
    const labelWidth = 150
    const valueWidth = this.width - labelWidth - 8
    for (const [label, raw] of rows) {
      const value = raw && raw.trim() ? raw : 'Non renseigné'
      const lines = this.wrap(value, this.regular, 9.5, valueWidth)
      const height = Math.max(1, lines.length) * 12.8 + 3
      this.ensure(height)
      this.page.drawText(this.clean(label, this.regular), { x: MARGIN, y: this.y - 9.5, size: 9, font: this.regular, color: MUTED })
      lines.forEach((line, index) => {
        this.page.drawText(line, { x: MARGIN + labelWidth + 8, y: this.y - 9.5 - index * 12.8, size: 9.5, font: this.regular, color: INK })
      })
      this.y -= height
    }
  }

  /** Conditions générales : une ligne qui commence par « Article » est en gras. */
  terms(text: string): void {
    for (const paragraph of text.split(/\r?\n/)) {
      const isTitle = /^\s*(article|art\.)\s*\d+/i.test(paragraph)
      if (isTitle) this.y -= 4
      this.text(paragraph, { size: 8.8, font: isTitle ? this.bold : this.regular, gap: 0 })
    }
  }

  /** Croquis des dommages à gauche, liste des marques à droite. */
  sketch(marks: DamageMark[]): void {
    const scale = 0.5
    const height = SKETCH_HEIGHT * scale
    const width = SKETCH_WIDTH * scale
    this.ensure(height + 16)
    const x = MARGIN
    const top = this.y - 4
    this.page.drawSvgPath(SKETCH_BODY, { x, y: top, scale, color: rgb(0.95, 0.95, 0.96), borderColor: MUTED, borderWidth: 1 })
    for (const path of SKETCH_DETAILS) this.page.drawSvgPath(path, { x, y: top, scale, borderColor: MUTED, borderWidth: 0.6 })
    for (const path of SKETCH_WHEELS) this.page.drawSvgPath(path, { x, y: top, scale, color: MUTED })
    marks.forEach((mark, index) => {
      const cx = x + mark.x * width
      const cy = top - mark.y * height
      this.page.drawCircle({ x: cx, y: cy, size: 5.5, color: rgb(0.77, 0.06, 0.12), borderColor: rgb(1, 1, 1), borderWidth: 1 })
      const label = String(index + 1)
      const size = 6
      this.page.drawText(label, { x: cx - this.bold.widthOfTextAtSize(label, size) / 2, y: cy - 2.2, size, font: this.bold, color: rgb(1, 1, 1) })
    })
    const listX = x + width + 24
    let listY = top - 10
    if (!marks.length) {
      this.page.drawText(this.clean('Aucun dommage marqué sur le croquis.'), { x: listX, y: listY, size: 9, font: this.regular, color: MUTED })
    }
    marks.forEach((mark, index) => {
      const text = this.clean(`${index + 1}. ${damageKindLabels[mark.kind]}${mark.note ? ` - ${mark.note}` : ''}`)
      for (const line of this.wrap(text, this.regular, 9, A4.width - MARGIN - listX)) {
        this.page.drawText(line, { x: listX, y: listY, size: 9, font: this.regular, color: INK })
        listY -= 12
      }
    })
    this.y = top - height - 12
  }

  signatures(blocks: Array<{ title: string; image: PDFImage; name: string; date: string }>): void {
    const boxWidth = (this.width - 24) / 2
    const boxHeight = 120
    this.ensure(boxHeight + 30)
    blocks.forEach((block, index) => {
      const x = MARGIN + index * (boxWidth + 24)
      const top = this.y
      this.page.drawText(this.clean(block.title, this.bold), { x, y: top - 10, size: 9.5, font: this.bold, color: INK })
      const scale = Math.min((boxWidth - 8) / block.image.width, 64 / block.image.height)
      this.page.drawImage(block.image, {
        x: x + 4,
        y: top - 20 - block.image.height * scale - 4,
        width: block.image.width * scale,
        height: block.image.height * scale,
      })
      this.page.drawLine({ start: { x, y: top - 92 }, end: { x: x + boxWidth, y: top - 92 }, thickness: 0.6, color: LINE })
      this.page.drawText(this.clean(block.name, this.regular), { x, y: top - 104, size: 9, font: this.regular, color: INK })
      this.page.drawText(this.clean(block.date, this.regular), { x, y: top - 116, size: 8.5, font: this.regular, color: MUTED })
    })
    this.y -= boxHeight + 10
  }

  /**
   * Tableau simple : en-tête en gras, colonnes de largeur relative, montants
   * alignés à droite. Une ligne trop longue est coupée dans sa colonne ; l'en-tête
   * est répété en haut de chaque nouvelle page.
   */
  table(columns: Array<{ label: string; weight: number; align?: 'left' | 'right' }>, rows: Array<{ cells: string[]; bold?: boolean }>): void {
    const size = 8.6
    const leading = 11.4
    const totalWeight = columns.reduce((total, column) => total + column.weight, 0)
    const widths = columns.map((column) => (this.width * column.weight) / totalWeight)
    const drawRow = (cells: string[], font: PDFFont, header = false): void => {
      const wrapped = cells.map((cell, index) => this.wrap(cell || '', font, size, widths[index] - 6))
      const height = Math.max(1, ...wrapped.map((lines) => lines.length)) * leading + 4
      if (this.y - height < MARGIN + 24) {
        this.addPage()
        if (!header) drawRow(columns.map((column) => column.label), this.bold, true)
      }
      let x = MARGIN
      wrapped.forEach((lines, index) => {
        lines.forEach((line, lineIndex) => {
          const lineWidth = font.widthOfTextAtSize(line, size)
          const left = columns[index].align === 'right' ? x + widths[index] - 3 - lineWidth : x + 3
          this.page.drawText(line, { x: left, y: this.y - size - lineIndex * leading, size, font, color: INK })
        })
        x += widths[index]
      })
      this.y -= height
      this.page.drawLine({ start: { x: MARGIN, y: this.y + 1 }, end: { x: MARGIN + this.width, y: this.y + 1 }, thickness: 0.4, color: LINE })
    }
    this.ensure(leading * 2 + 8)
    drawRow(columns.map((column) => column.label), this.bold, true)
    for (const row of rows) drawRow(row.cells, row.bold ? this.bold : this.regular)
    this.y -= 6
  }

  footer(reference: string): void {
    const total = this.pages.length
    this.pages.forEach((page, index) => {
      const label = this.clean(`${reference} - page ${index + 1} sur ${total}`)
      page.drawText(label, { x: MARGIN, y: MARGIN - 18, size: 8, font: this.regular, color: MUTED })
    })
  }
}

function when(value: string | null | undefined): string {
  return value ? formatDateTime(value) : ''
}

function vehicleLine(vehicle: ContractSnapshot['vehicle']): string {
  return [vehicle.make, vehicle.model, vehicle.model_year?.toString()].filter(Boolean).join(' ') || categoryLabels[vehicle.category]
}

function locationText(location: CarRentalReservation['pickup_location'], fallback: string): string {
  if (!location) return fallback
  const base = location.type === 'site' ? fallback : locationLabels[location.type]
  return location.detail ? `${base} - ${location.detail}` : base
}

export async function buildContractPdf(reservation: CarRentalReservation, images: ContractImages): Promise<Uint8Array> {
  const snapshot = reservation.contract?.snapshot
  const inspection = reservation.checkout_inspection
  if (!snapshot || !inspection) throw new Error('Les données signées du contrat sont incomplètes.')

  const doc = await PDFDocument.create()
  doc.setTitle(`Contrat de location ${reservation.number}`)
  doc.setAuthor(snapshot.lessor.name)
  doc.setSubject('Contrat de location de véhicule')
  doc.setCreator('Clientèle Group ERP')
  doc.setLanguage('fr-HT')

  const regular = await doc.embedFont(StandardFonts.Helvetica)
  const bold = await doc.embedFont(StandardFonts.HelveticaBold)
  const w = new Writer(doc, regular, bold)

  const { lessor, vehicle } = snapshot
  const site = reservation.site?.name ?? 'Bureau'
  const days = rentalDays(reservation.pickup_at, reservation.due_at)
  const rate = Number(reservation.daily_rate)
  const rentalAmount = Math.round(rate * days * 100) / 100
  const airport = Number(reservation.airport_fees_total_usd)

  // En-tête du loueur.
  w.text(lessor.name, { size: 14, font: bold })
  if (lessor.address) w.text(lessor.address, { size: 9, color: MUTED })
  const contact = [lessor.phone_numbers ? `Tél. ${lessor.phone_numbers}` : '', lessor.tax_identification_number ? `NIF ${lessor.tax_identification_number}` : '']
    .filter(Boolean).join(' - ')
  if (contact) w.text(contact, { size: 9, color: MUTED })
  if (lessor.representative) w.text(`Représentant : ${lessor.representative}`, { size: 9, color: MUTED })
  w.text('', { gap: 6 })
  w.text('CONTRAT DE LOCATION DE VÉHICULE', { size: 16, font: bold })
  w.text(`N° ${reservation.number} - signé le ${when(inspection.customer_signed_at ?? reservation.checked_out_at)}`, { size: 9.5, color: MUTED, gap: 4 })

  w.heading('Locataire')
  w.facts([
    ['Nom', reservation.customer?.display_name],
    ['Courriel', reservation.customer?.email ?? null],
    ['Téléphone', reservation.customer?.phone ?? null],
  ])

  w.heading('Conducteur')
  const license = reservation.driver_license
  w.facts([
    ['Conducteur principal', reservation.driver_full_name],
    ['Permis de conduire', license?.number ?? null],
    ['Délivré par', license ? licenseIssuer(license.country, license.subdivision) : null],
    ['Valable jusqu’au', license?.expires_at ? formatDate(license.expires_at) : null],
    ...(reservation.additional_driver
      ? [
          ['Conducteur additionnel', reservation.additional_driver.name] as [string, string],
          ['Permis du conducteur additionnel', reservation.additional_driver.license_number] as [string, string | null],
        ]
      : []),
  ])

  w.heading('Véhicule')
  w.facts([
    ['Véhicule', vehicleLine(vehicle)],
    ['Catégorie', categoryLabels[vehicle.category]],
    ['Plaque', vehicle.registration_number],
    ['Numéro de série', vehicle.vin],
    ['Couleur', vehicle.color],
    ['Carburant', vehicle.fuel_type ? fuelTypeLabels[vehicle.fuel_type] : null],
    ['Transmission', vehicle.transmission ? transmissionLabels[vehicle.transmission] : null],
    ['Cylindrée', vehicle.engine_displacement_cc ? `${vehicle.engine_displacement_cc} cm³` : null],
    ['Portes', vehicle.doors?.toString() ?? null],
  ])

  w.heading('Durée et conditions financières')
  w.facts([
    ['Départ', `${formatDateTime(reservation.pickup_at)} - ${locationText(reservation.pickup_location, site)}`],
    ['Retour prévu', `${formatDateTime(reservation.due_at)} - ${locationText(reservation.dropoff_location, site)}`],
    ['Durée facturée', `${days} jour${days > 1 ? 's' : ''}`],
    ['Tarif journalier', formatMoney(reservation.daily_rate, reservation.currency)],
    ['Montant de la location', formatMoney(rentalAmount, reservation.currency)],
    ...(airport > 0 ? [['Frais aéroport', formatMoney(airport, 'USD')] as [string, string]] : []),
    [
      'Kilométrage',
      reservation.kilometer_plan === 'unlimited'
        ? 'Illimité'
        : `${reservation.included_km ?? 0} km inclus${reservation.additional_km_rate ? `, puis ${formatMoney(reservation.additional_km_rate, reservation.currency)} par km` : ''}`,
    ],
    ['Dépôt de garantie retenu', formatMoney(reservation.checkout_requirements.held_security_deposit_usd, 'USD')],
  ])

  w.heading('Fiche de sortie')
  w.facts([
    ['Kilométrage au départ', inspection.odometer_km !== null ? `${inspection.odometer_km.toLocaleString('fr-FR')} km` : null],
    ['Carburant au départ', fuelLevelLabel(inspection.fuel_level_percent)],
    ['Accessoires remis', inspection.accessories.length ? inspection.accessories.map((key) => accessoryLabels[key]).join(', ') : 'Aucun'],
    ['Dommages constatés', inspection.damage_notes || 'Aucun dommage signalé'],
    ['Photos de l’état du véhicule', inspection.photo_urls.length ? `${inspection.photo_urls.length} photo${inspection.photo_urls.length > 1 ? 's' : ''} conservée${inspection.photo_urls.length > 1 ? 's' : ''} au dossier` : 'Aucune'],
    ['Contrôle effectué par', inspection.company_signer_name],
  ])
  w.sketch(inspection.damage_marks ?? [])

  w.heading('Conditions générales')
  w.terms(snapshot.terms)

  w.heading('Signatures')
  w.text('Le locataire reconnaît avoir reçu le véhicule dans l’état décrit dans la fiche de sortie, avoir lu les conditions générales ci-dessus et les accepter.', { size: 9, gap: 8 })
  const [customerImage, companyImage] = await Promise.all([
    doc.embedPng(images.customerSignature),
    doc.embedPng(images.companySignature),
  ])
  w.signatures([
    {
      title: 'Le locataire',
      image: customerImage,
      name: reservation.driver_full_name ?? reservation.customer?.display_name ?? '',
      date: when(inspection.customer_signed_at),
    },
    {
      title: 'Pour le loueur',
      image: companyImage,
      name: inspection.company_signer_name ?? lessor.name,
      date: when(inspection.company_signed_at),
    },
  ])

  w.footer(`Contrat ${reservation.number} - conditions ${snapshot.terms_sha256.slice(0, 12)}`)
  return doc.save()
}
