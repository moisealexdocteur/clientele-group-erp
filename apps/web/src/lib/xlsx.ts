import { strToU8, zipSync } from 'fflate'

/*
 * Classeur Excel (.xlsx) minimal, produit dans le navigateur : feuilles,
 * en-têtes en gras, montants numériques à deux décimales, largeurs de
 * colonnes. Suffisant pour les rapports ; aucune formule n'est utilisée,
 * les totaux sont ceux calculés par le serveur.
 */

export type XlsxValue = string | number | null | undefined

export interface XlsxCell {
  value: XlsxValue
  /** bold : titre ou en-tête ; money : nombre à deux décimales. */
  style?: 'bold' | 'money' | 'bold-money'
}

export type XlsxRow = Array<XlsxValue | XlsxCell>

export interface XlsxSheet {
  name: string
  rows: XlsxRow[]
  widths?: number[]
}

/* Copie dans le domaine courant : fflate reconnaît les fichiers par instanceof Uint8Array. */
function bytes(text: string): Uint8Array {
  return new Uint8Array(strToU8(text))
}

const STYLE_INDEX = { default: 0, bold: 1, money: 2, 'bold-money': 3 } as const

function escapeXml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    // Caractères de contrôle interdits en XML.
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
}

function columnName(index: number): string {
  let name = ''
  let current = index + 1
  while (current > 0) {
    const remainder = (current - 1) % 26
    name = String.fromCharCode(65 + remainder) + name
    current = Math.floor((current - 1) / 26)
  }
  return name
}

/** Nom de feuille valide : 31 caractères au plus, sans : \ / ? * [ ]. */
export function sheetName(value: string): string {
  return value.replace(/[:\\/?*[\]]/g, ' ').trim().slice(0, 31) || 'Feuille'
}

function cellXml(raw: XlsxValue | XlsxCell, ref: string): string {
  const cell: XlsxCell = raw !== null && typeof raw === 'object' ? raw : { value: raw }
  const style = STYLE_INDEX[cell.style ?? 'default']
  const styleAttr = style ? ` s="${style}"` : ''
  if (cell.value === null || cell.value === undefined || cell.value === '') {
    return style ? `<c r="${ref}"${styleAttr}/>` : ''
  }
  if (typeof cell.value === 'number' && Number.isFinite(cell.value)) {
    return `<c r="${ref}"${styleAttr}><v>${cell.value}</v></c>`
  }
  return `<c r="${ref}"${styleAttr} t="inlineStr"><is><t xml:space="preserve">${escapeXml(String(cell.value))}</t></is></c>`
}

function sheetXml(sheet: XlsxSheet): string {
  const cols = sheet.widths?.length
    ? `<cols>${sheet.widths.map((width, index) => `<col min="${index + 1}" max="${index + 1}" width="${width}" customWidth="1"/>`).join('')}</cols>`
    : ''
  const rows = sheet.rows
    .map((row, rowIndex) => {
      const cells = row.map((cell, columnIndex) => cellXml(cell, `${columnName(columnIndex)}${rowIndex + 1}`)).join('')
      return `<row r="${rowIndex + 1}">${cells}</row>`
    })
    .join('')
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">${cols}<sheetData>${rows}</sheetData></worksheet>`
}

const STYLES = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>
<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="4">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
<xf numFmtId="4" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>
</cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>`

export function buildXlsx(sheets: XlsxSheet[], title: string): Uint8Array {
  const names = sheets.map((sheet) => sheetName(sheet.name))
  const files: Record<string, Uint8Array> = {
    '[Content_Types].xml': bytes(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
${names.map((_, index) => `<Override PartName="/xl/worksheets/sheet${index + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`).join('\n')}
</Types>`),
    '_rels/.rels': bytes(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
</Relationships>`),
    'docProps/core.xml': bytes(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/">
<dc:title>${escapeXml(title)}</dc:title><dc:creator>Clientèle Group ERP</dc:creator><dc:language>fr-HT</dc:language>
</cp:coreProperties>`),
    'xl/workbook.xml': bytes(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets>${names.map((name, index) => `<sheet name="${escapeXml(name)}" sheetId="${index + 1}" r:id="rId${index + 1}"/>`).join('')}</sheets>
</workbook>`),
    'xl/_rels/workbook.xml.rels': bytes(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
${names.map((_, index) => `<Relationship Id="rId${index + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet${index + 1}.xml"/>`).join('\n')}
<Relationship Id="rId${names.length + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>`),
    'xl/styles.xml': bytes(STYLES),
  }
  sheets.forEach((sheet, index) => {
    files[`xl/worksheets/sheet${index + 1}.xml`] = bytes(sheetXml(sheet))
  })
  return zipSync(files, { level: 6 })
}

/** Montant décimal transmis par l'API, converti en nombre pour une cellule. */
export function money(value: string | number | null | undefined, bold = false): XlsxCell {
  const amount = value === null || value === undefined || value === '' ? null : Number(value)
  return { value: amount !== null && Number.isFinite(amount) ? amount : null, style: bold ? 'bold-money' : 'money' }
}

export function bold(value: XlsxValue): XlsxCell {
  return { value, style: 'bold' }
}

/** Téléchargement d'un fichier produit dans le navigateur. */
export function downloadBytes(bytes: Uint8Array, filename: string, type: string): void {
  const blob = new Blob([bytes.slice().buffer], { type })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.append(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
