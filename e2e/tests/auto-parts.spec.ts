import { readFileSync } from 'node:fs'
import { expect, test, type Page } from '@playwright/test'
import { apiCall, capture, savePdf, signIn } from './helpers'

/*
 * Recette Auto Parts sur téléphone, avec la vraie API et PostgreSQL :
 * 1. configuration de la société (API, comme Configuration) ;
 * 2. modèles Haojin, réception d'un lot de motos ;
 * 3. commande payée par dépôt, vérification, attribution, appel, remise ;
 * 4. reçu de vente et certificat Novamat (PDF Lettre) ;
 * 5. lubrifiants de test, réception de marchandises ;
 * 6. caisse, vente au comptoir en gros, reçu de caisse 80 mm.
 */

test.describe.configure({ mode: 'serial' })

let companyId = ''
let storeId = ''
let warehouseId = ''
let orderId = ''

test('configuration de la société de recette', async ({ request }) => {
  const suffix = Date.now().toString(36).toUpperCase().slice(-5)
  const company = await apiCall(request, 'POST', '/system/configuration/companies', {
    code: `AP-${suffix}`,
    legal_name: 'Clientèle Auto Parts S.A.',
    display_name: 'Clientèle Auto Parts',
    base_currency: 'HTG',
    activity: 'auto_parts',
  })
  companyId = company.data.id
  const store = await apiCall(request, 'POST', `/system/configuration/companies/${companyId}/sites`, { code: 'RUE-4A', name: 'Rue 4 A, Cap-Haïtien', address: 'Rue 4 A, Cap-Haïtien', kind: 'store' })
  storeId = store.data.id
  const warehouse = await apiCall(request, 'POST', `/system/configuration/companies/${companyId}/sites`, { code: 'PONT-PAROIS', name: 'Pont Parois, Route Nationale 6', address: 'Route Nationale 6, Pont Parois', kind: 'warehouse' })
  warehouseId = warehouse.data.id
  await apiCall(request, 'POST', `/system/configuration/companies/${companyId}/cash-registers`, { site_id: storeId, code: 'CAISSE-1', name: 'Caisse 1' })
  await apiCall(request, 'POST', '/exchange-rates', { rate_htg_per_usd: '135.0000', note: 'Taux de recette' }).catch(() => undefined)
  expect(companyId).not.toBe('')
})

test('modèles Haojin et photo générique', async ({ page }) => {
  await signIn(page, companyId)
  await page.goto('/pieces/motos/modeles')
  await page.getByRole('button', { name: /Importer les modèles Haojin/ }).click()
  await expect(page.getByText('Haojin HJ-125A-D', { exact: true })).toBeVisible()
  await expect(page.getByText('Haojin HJ-250ZH-10', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: /Importer les modèles Haojin/ })).toHaveCount(0)

  // Photo générique du HJ-125A-D (illustration de recette, sans plaque).
  await page.getByText('Haojin HJ-125A-D', { exact: true }).click()
  await page.locator('input[type=file]:not([capture])').first().setInputFiles({ name: 'hj125ad.png', mimeType: 'image/png', buffer: motoPng() })
  await expect(page.getByText('Photo du modèle enregistrée.')).toBeVisible()
  await capture(page, 'modele-photo')
  await page.getByRole('button', { name: 'Annuler' }).click()
  await capture(page, 'modeles')
})

test('réception d’un lot de motos avec couleurs et numéros', async ({ page }) => {
  await signIn(page, companyId)
  await page.goto('/pieces/motos/lots/nouveau')
  await page.getByLabel('Emplacement').selectOption(warehouseId)
  await page.getByLabel('Référence du bon ou de la facture du fournisseur').fill('NOVAMAT-BL-0001')

  const units = [
    { model: 'Haojin HJ-125A-D', frame: 'LZL12P1A8THE50741', engine: 'HJ157FMI260550741', color: 'Rouge Sang', customs: false },
    { model: 'Haojin HJ-125A-D', frame: 'LZL12P1A8THE50742', engine: 'HJ157FMI260550742', color: 'Noir', customs: true },
    { model: 'Haojin HJ-200GY-3', frame: 'LZL20P1A8THE60001', engine: 'HJ163FML260060001', color: 'Rouge et blanc', customs: true },
  ]
  for (const [index, unit] of units.entries()) {
    if (index > 0) await page.getByRole('button', { name: 'Ajouter une moto' }).click()
    const block = page.locator('fieldset').filter({ hasText: `Moto ${index + 1}` })
    await block.getByLabel('Modèle').selectOption({ label: unit.model })
    await block.getByLabel('N° de châssis (Frame No)').fill(unit.frame)
    await block.getByLabel('N° de moteur (Engine No)').fill(unit.engine)
    await block.getByLabel('Couleur').fill(unit.color)
    const customs = block.getByLabel('Document de dédouanement reçu')
    if ((await customs.isChecked()) !== unit.customs) await customs.click()
  }
  await expect(page.locator('#moto-colors option[value="Rouge Sang"]')).toHaveCount(1)
  await capture(page, 'reception-lot')
  await page.getByRole('button', { name: 'Enregistrer 3 motos' }).click()
  await expect(page.getByText(/3 motos reçues/)).toBeVisible()

  await page.goto('/pieces/motos/modeles')
  await expect(page.getByText('Stock : Pont Parois, Route Nationale 6').first()).toBeVisible()
  await expect(page.getByText('200 000,00 HTG').first()).toBeVisible()
  await capture(page, 'motos-disponibles')
})

test('commande payée par dépôt, vérification et attribution', async ({ page }) => {
  await signIn(page, companyId)
  await page.goto('/pieces/motos/commandes/nouvelle')
  const modelSelect = page.getByLabel('Modèle')
  await modelSelect.selectOption((await modelSelect.locator('option', { hasText: 'HJ-125A-D' }).getAttribute('value')) ?? '')
  await page.getByLabel('Couleur souhaitée').fill('Rouge Sang')
  await page.getByLabel('Retrait au magasin').selectOption(storeId)
  await page.getByLabel('Nom complet').fill('Client Recette')
  await page.getByLabel('Téléphone').fill('+509 3700 0000')
  await page.getByLabel('Adresse').fill('Rue 15, Cap-Haïtien')
  await expect(page.getByLabel('Montant déposé (HTG)')).toHaveValue('200000.00')
  await page.getByLabel('N° de la fiche de dépôt').fill('SG-RECETTE-001')
  await capture(page, 'nouvelle-commande')
  await page.getByRole('button', { name: 'Enregistrer la commande' }).click()
  await expect(page).toHaveURL(/\/pieces\/motos\/commandes\/[0-9a-f-]{36}$/)
  orderId = page.url().split('/').pop() ?? ''

  await page.getByRole('button', { name: 'Vérifier le dépôt' }).click()
  await page.getByRole('button', { name: 'Le dépôt est sur le relevé' }).click()
  await expect(page.getByText('LZL12P1A8THE50741').first()).toBeVisible()
  await page.getByRole('button', { name: 'Client appelé' }).click()
  await expect(page.getByText(/Client appelé le/)).toBeVisible()
  await capture(page, 'commande-attribuee')
})

test('remise de la moto, reçu de vente et certificat', async ({ page }) => {
  await signIn(page, companyId)
  await page.goto(`/pieces/motos/commandes/${orderId}/remise`)
  await page.getByText('Le client présente la fiche de dépôt originale, au même numéro.').click()
  await page.getByLabel('Pièce présentée').selectOption('cin')
  await page.getByRole('textbox', { name: /^Numéro/ }).fill('01-23-45-6789-00-12345')
  const unchecked = page.locator('.checks input[type=checkbox]:not(:checked):not(:disabled)')
  while ((await unchecked.count()) > 0) await unchecked.first().check()
  await page.getByText('Le client a vérifié la moto et accepte la vente finale, sans garantie.').click()
  await capture(page, 'remise')
  await page.getByRole('button', { name: 'Remettre la moto et émettre le reçu' }).click()
  await expect(page.getByText(/Moto remise\. Reçu n°/)).toBeVisible()

  const receipt = await savePdf(page, 'Reçu de vente (PDF)', 'recu-vente-moto.pdf')
  const certificate = await savePdf(page, 'Certificat du fournisseur (PDF)', 'certificat-novamat.pdf')
  await savePdf(page, 'Essai d’alignement du certificat', 'certificat-essai-alignement.pdf')
  expect(readFileSync(receipt).subarray(0, 4).toString()).toBe('%PDF')
  expect(readFileSync(certificate).subarray(0, 4).toString()).toBe('%PDF')
  await capture(page, 'commande-livree')

  // Contrôle d'un reçu présenté au comptoir : le vrai, puis un faux code.
  const number = ((await page.getByText(/Reçu n° \d{4} \d{4}/).first().textContent()) ?? '').match(/\d{4} \d{4}/)?.[0] ?? ''
  const code = ((await page.locator('.security-code').textContent()) ?? '').trim()
  expect(code).toMatch(/^[0-9A-F]{4}-[0-9A-F]{4}$/)
  await page.goto('/pieces/motos/verifier-recu')
  await page.getByLabel('N° du reçu').fill(number)
  await page.getByLabel('Code de vérification').fill(code)
  await page.getByRole('button', { name: 'Vérifier', exact: true }).click()
  await expect(page.getByText(/Reçu authentique/)).toBeVisible()
  await expect(page.getByText('LZL12P1A8THE50741')).toBeVisible()
  await capture(page, 'recu-authentique')
  await page.getByLabel('Code de vérification').fill(code.startsWith('0') ? '1111-1111' : '0000-0000')
  await page.getByRole('button', { name: 'Vérifier', exact: true }).click()
  await expect(page.getByText('Reçu non authentique')).toBeVisible()
  await capture(page, 'recu-faux')

  // Le document de dédouanement manquait : il reste à remettre.
  await page.goto('/pieces/motos?vue=dedouanement')
  await capture(page, 'dedouanement-a-remettre')
})

test('import du bon Novamat en PDF et stock par modèle', async ({ page }) => {
  await signIn(page, companyId)
  await page.goto('/pieces/motos/lots/nouveau')
  await page.getByLabel('Emplacement').selectOption(warehouseId)
  await page.locator('input[type=file][accept="application/pdf"]').setInputFiles('fixtures/bon-novamat-recette.pdf')
  await expect(page.getByText(/4 motos lues/)).toBeVisible()
  await expect(page.getByText('HJ-125A-D Bleu : 2')).toBeVisible()
  await capture(page, 'import-bon-novamat')
  await page.getByRole('button', { name: 'Enregistrer 4 motos' }).click()
  await expect(page.getByText(/4 motos reçues/)).toBeVisible()

  await page.goto('/pieces/motos')
  await expect(page.getByText(/motos disponibles à la vente/)).toBeVisible()
  await page.goto('/pieces/motos/stock')
  await expect(page.locator('.total-label', { hasText: 'Disponibles à la vente' })).toBeVisible()
  await capture(page, 'stock-par-modele')

  // Un modèle inutilisé se supprime.
  await page.goto('/pieces/motos/modeles')
  await page.getByText('Haojin HJ-250ZH-10', { exact: true }).click()
  await page.getByRole('button', { name: 'Supprimer', exact: true }).click()
  await page.getByRole('button', { name: 'Supprimer', exact: true }).last().click()
  await expect(page.getByText(/HJ-250ZH-10 supprimé/)).toBeVisible()
})

test('base clientèle', async ({ page }) => {
  await signIn(page, companyId)
  await page.goto('/pieces/clients')
  await expect(page.getByText('Client Recette')).toBeVisible()
  await page.getByText('Client Recette').click()
  await expect(page.getByText('Rue 15, Cap-Haïtien')).toBeVisible()
  await capture(page, 'fiche-client')
})

test('lubrifiants de test et réception de marchandises', async ({ page }) => {
  await signIn(page, companyId)
  await page.goto('/pieces/articles')
  await page.getByRole('button', { name: /Lubrifiants de départ/ }).click()
  await page.getByLabel('Emplacement du stock').selectOption(storeId)
  await capture(page, 'lubrifiants-dialogue')
  await page.getByRole('button', { name: 'Ajouter', exact: true }).click()
  await expect(page.getByText(/16 lubrifiants ajoutés/)).toBeVisible()
  await expect(page.getByText('Huile moteur Motex-T SAE 40')).toBeVisible()
  await capture(page, 'articles-lubrifiants')

  // Réception d'un complément de marchandises au magasin : 5 caisses de Castrol.
  await page.getByText('Huile moteur Castrol', { exact: true }).click()
  await page.getByRole('button', { name: 'Entrée de stock' }).click()
  await page.getByLabel('Emplacement').selectOption(storeId)
  await page.getByLabel('Quantité reçue').fill('5')
  await page.getByLabel('Référence du bon ou de la facture').fill('FOURNISSEUR-LUB-0001')
  await capture(page, 'entree-de-stock')
  await page.locator('#stock-action-form').evaluate((form: HTMLFormElement) => form.requestSubmit())
  await expect(page.getByText('15', { exact: true }).first()).toBeVisible()
  await capture(page, 'article-castrol-15-caisses')

  await page.goto('/pieces/stock')
  await capture(page, 'mouvements-de-stock')
})

test('caisse, vente au comptoir en gros et reçu de caisse', async ({ page }) => {
  await signIn(page, companyId)
  await page.goto('/caisse')
  await page.getByRole('button', { name: 'Ouvrir la caisse' }).first().click()
  await page.getByLabel('Fond HTG').fill('5000')
  await page.getByLabel('Fond USD').fill('0')
  await page.locator('#open-form').evaluate((form: HTMLFormElement) => form.requestSubmit())
  await expect(page.getByText(/Ouverte/).first()).toBeVisible()

  await page.goto('/pieces')
  await page.getByRole('button', { name: 'Gros' }).click()
  await page.getByLabel('Rechercher un article').fill('Motex')
  await page.getByText('Huile moteur Motex-T SAE 40').first().click()
  await page.getByRole('button', { name: 'Augmenter' }).click()
  await capture(page, 'panier')
  await page.locator('.action-bar').getByRole('button', { name: 'Encaisser' }).click()
  await page.getByLabel('Montant reçu (HTG)').fill('50000')
  await page.getByLabel('Nom du client').fill('Garage Recette')
  await page.getByLabel('Téléphone du client').fill('+509 3900 1234')
  await page.getByRole('button', { name: /Encaisser 50 000,00 HTG/ }).click()
  await expect(page).toHaveURL(/\/pieces\/recu\//)
  await capture(page, 'recu-caisse')
})

test('client du comptoir enregistré', async ({ page }) => {
  await signIn(page, companyId)
  await page.goto('/pieces/clients')
  await page.getByLabel('Rechercher un client').fill('+509 3900 1234')
  await expect(page.getByText('Garage Recette')).toBeVisible()
  await capture(page, 'clients')
})

/* Illustration PNG de recette générée à la volée (silhouette, pas de photo réelle). */
function motoPng(): Buffer {
  return Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64')
}

export type { Page }
