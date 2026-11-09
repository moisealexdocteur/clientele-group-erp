import { createRouter, createWebHistory, type RouteLocationRaw, type RouteRecordRaw } from 'vue-router'
import { useSessionStore } from '../stores/session'
import type { CompanyActivity } from '../api/types'

/*
 * Navigation par adresse : chaque écran a une URL, le bouton Retour du
 * téléphone fonctionne et une réservation ou un véhicule peut être rouvert
 * directement. Les droits sont vérifiés ici pour l'affichage ; le serveur
 * reste la seule autorité qui les applique.
 */

declare module 'vue-router' {
  interface RouteMeta {
    /** Écran de connexion, inaccessible une fois connecté. */
    guest?: boolean
    /** Demande une session active. */
    auth?: boolean
    /** Demande une société active. */
    company?: boolean
    /** Réservé au propriétaire du système. */
    owner?: boolean
    /** Zone Configuration : propriétaire, ou personne désignée pour le taux. */
    configuration?: boolean
    /** Écran du taux : propriétaire ou personne désignée. */
    rates?: boolean
    /** Permission de société requise. */
    permission?: string
    /** Activité de société requise (Car Rental ou Auto Parts). */
    activity?: CompanyActivity
    /** Nom de la rubrique à marquer comme active dans la navigation. */
    navMatch?: string
    title?: string
  }
}

/** Premier écran de la société active, selon son activité et le rôle. */
function homeRoute(): RouteLocationRaw {
  const session = useSessionStore()
  if (!session.context) return { name: 'companies' }
  if ((session.context.company.activity ?? 'car_rental') === 'auto_parts') {
    if (session.can('parts.sales.create')) return { name: 'parts.sale' }
    if (session.can('parts.catalog.read')) return { name: 'parts.products' }
    if (session.can('parts.motos.read')) return { name: 'parts.motos' }
    return { name: 'cash' }
  }
  return { name: 'rental.today' }
}

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    redirect: () => {
      const session = useSessionStore()
      if (!session.isAuthenticated) return { name: 'sign-in' }
      return homeRoute()
    },
  },
  {
    path: '/connexion',
    component: () => import('../views/auth/AuthLayout.vue'),
    meta: { guest: true },
    children: [
      { path: '', name: 'sign-in', component: () => import('../views/auth/SignInView.vue'), meta: { title: 'Connexion' } },
      { path: 'code', name: 'sign-in.verify', component: () => import('../views/auth/VerifyView.vue'), meta: { title: 'Vérification' } },
      { path: 'mot-de-passe', name: 'password.request', component: () => import('../views/auth/PasswordRequestView.vue'), meta: { title: 'Mot de passe oublié' } },
      { path: 'mot-de-passe/code', name: 'password.reset', component: () => import('../views/auth/PasswordResetView.vue'), meta: { title: 'Nouveau mot de passe' } },
    ],
  },
  {
    path: '/societes',
    name: 'companies',
    component: () => import('../views/CompanyChoiceView.vue'),
    meta: { auth: true, title: 'Choisir une société' },
  },
  {
    path: '/configuration',
    component: () => import('../views/system/SystemLayout.vue'),
    meta: { auth: true, configuration: true },
    children: [
      { path: 'taux', name: 'system.rates', component: () => import('../views/system/RatesView.vue'), meta: { title: 'Taux de change', navMatch: 'system.rates', rates: true } },
      { path: 'courriel', name: 'system.mail', component: () => import('../views/system/MailView.vue'), meta: { title: 'Serveur de courriel', navMatch: 'system.mail' } },
      { path: '', name: 'system.companies', component: () => import('../views/system/CompaniesView.vue'), meta: { title: 'Sociétés', navMatch: 'system.companies' } },
      { path: 'societes/nouvelle', name: 'system.company.new', component: () => import('../views/system/CompanyNewView.vue'), meta: { title: 'Ajouter une société', navMatch: 'system.companies' } },
      { path: 'societes/:companyId', name: 'system.company', component: () => import('../views/system/CompanyDetailView.vue'), props: true, meta: { title: 'Société', navMatch: 'system.companies' } },
      { path: 'societes/:companyId/utilisateurs', name: 'system.users', component: () => import('../views/system/UsersView.vue'), props: true, meta: { title: 'Utilisateurs', navMatch: 'system.users' } },
      { path: 'societes/:companyId/utilisateurs/nouveau', name: 'system.user.new', component: () => import('../views/system/UserNewView.vue'), props: true, meta: { title: 'Ajouter un utilisateur', navMatch: 'system.users' } },
      { path: 'societes/:companyId/utilisateurs/employe-existant', name: 'system.user.attach', component: () => import('../views/system/UserAttachView.vue'), props: true, meta: { title: 'Ajouter un employé existant', navMatch: 'system.users' } },
      { path: 'societes/:companyId/utilisateurs/:accessId', name: 'system.user', component: () => import('../views/system/UserDetailView.vue'), props: true, meta: { title: 'Utilisateur', navMatch: 'system.users' } },
    ],
  },
  {
    path: '/location',
    component: () => import('../views/WorkspaceLayout.vue'),
    meta: { auth: true, company: true, activity: 'car_rental' },
    children: [
      { path: '', name: 'rental.today', component: () => import('../views/rental/TodayView.vue'), meta: { title: 'Aujourd’hui' } },
      { path: 'reservations', name: 'rental.reservations', component: () => import('../views/rental/ReservationsView.vue'), meta: { title: 'Réservations', permission: 'rental.reservations.read', navMatch: 'rental.reservations' } },
      { path: 'reservations/nouvelle', name: 'rental.reservation.new', component: () => import('../views/rental/ReservationNewView.vue'), meta: { title: 'Nouvelle réservation', permission: 'rental.reservations.create', navMatch: 'rental.reservations' } },
      { path: 'reservations/:reservationId/mise-en-circulation', name: 'rental.reservation.checkout', component: () => import('../views/rental/CheckoutView.vue'), props: true, meta: { title: 'Mise en circulation', permission: 'rental.reservations.manage', navMatch: 'rental.reservations' } },
      { path: 'reservations/:reservationId/retour', name: 'rental.reservation.return', component: () => import('../views/rental/ReturnView.vue'), props: true, meta: { title: 'Retour du véhicule', permission: 'rental.reservations.manage', navMatch: 'rental.reservations' } },
      { path: 'reservations/:reservationId/modifier', name: 'rental.reservation.edit', component: () => import('../views/rental/ReservationEditView.vue'), props: true, meta: { title: 'Modifier la réservation', permission: 'rental.reservations.manage', navMatch: 'rental.reservations' } },
      { path: 'reservations/:reservationId', name: 'rental.reservation', component: () => import('../views/rental/ReservationDetailView.vue'), props: true, meta: { title: 'Réservation', permission: 'rental.reservations.read', navMatch: 'rental.reservations' } },
      { path: 'planning', name: 'rental.planning', component: () => import('../views/rental/PlanningView.vue'), meta: { title: 'Planning', permission: 'rental.calendar.read', navMatch: 'rental.planning' } },
      { path: 'vehicules', name: 'rental.vehicles', component: () => import('../views/rental/VehiclesView.vue'), meta: { title: 'Véhicules', permission: 'rental.vehicles.read', navMatch: 'rental.vehicles' } },
      { path: 'vehicules/nouveau', name: 'rental.vehicle.new', component: () => import('../views/rental/VehicleNewView.vue'), meta: { title: 'Ajouter un véhicule', permission: 'rental.vehicles.manage', navMatch: 'rental.vehicles' } },
      // Anciennes adresses de la caisse, conservées pour les favoris.
      { path: 'caisse/:rest(.*)*', redirect: (to) => `/caisse/${([] as string[]).concat(to.params.rest ?? []).join('/')}` },
      { path: 'vehicules/:vehicleId', name: 'rental.vehicle', component: () => import('../views/rental/VehicleDetailView.vue'), props: true, meta: { title: 'Véhicule', permission: 'rental.vehicles.read', navMatch: 'rental.vehicles' } },
    ],
  },
  {
    // Caisse commune à toutes les activités.
    path: '/caisse',
    component: () => import('../views/WorkspaceLayout.vue'),
    meta: { auth: true, company: true },
    children: [
      { path: '', name: 'cash', component: () => import('../views/cash/CashView.vue'), meta: { title: 'Caisse', navMatch: 'cash' } },
      { path: 'sessions/:sessionId', name: 'cash.session', component: () => import('../views/cash/CashSessionView.vue'), props: true, meta: { title: 'Session de caisse', navMatch: 'cash' } },
      { path: 'rapport-journalier', name: 'cash.report', component: () => import('../views/cash/DailyReportView.vue'), meta: { title: 'Rapport journalier', permission: 'cash.reports.read', navMatch: 'cash' } },
    ],
  },
  {
    path: '/pieces',
    component: () => import('../views/WorkspaceLayout.vue'),
    meta: { auth: true, company: true, activity: 'auto_parts' },
    children: [
      { path: '', name: 'parts.sale', component: () => import('../views/parts/CounterSaleView.vue'), meta: { title: 'Vente au comptoir', permission: 'parts.sales.create', navMatch: 'parts.sale' } },
      { path: 'ventes', name: 'parts.sales', component: () => import('../views/parts/SalesView.vue'), meta: { title: 'Ventes du jour', permission: 'parts.sales.read', navMatch: 'parts.sale' } },
      { path: 'motos', name: 'parts.motos', component: () => import('../views/parts/motos/MotosView.vue'), meta: { title: 'Motos', permission: 'parts.motos.read', navMatch: 'parts.motos' } },
      { path: 'motos/commandes/nouvelle', name: 'parts.moto.order.new', component: () => import('../views/parts/motos/MotoOrderNewView.vue'), meta: { title: 'Nouvelle commande de moto', permission: 'parts.motos.orders.create', navMatch: 'parts.motos' } },
      { path: 'motos/commandes/:orderId', name: 'parts.moto.order', component: () => import('../views/parts/motos/MotoOrderDetailView.vue'), props: true, meta: { title: 'Commande de moto', permission: 'parts.motos.read', navMatch: 'parts.motos' } },
      { path: 'motos/commandes/:orderId/remise', name: 'parts.moto.delivery', component: () => import('../views/parts/motos/MotoDeliveryView.vue'), props: true, meta: { title: 'Remettre la moto', permission: 'parts.motos.deliver', navMatch: 'parts.motos' } },
      { path: 'motos/lots/nouveau', name: 'parts.moto.batch.new', component: () => import('../views/parts/motos/MotoBatchNewView.vue'), meta: { title: 'Réception d’un lot', permission: 'parts.motos.receive', navMatch: 'parts.motos' } },
      { path: 'motos/stock', name: 'parts.moto.stock', component: () => import('../views/parts/motos/MotoStockView.vue'), meta: { title: 'Stock de motos', permission: 'parts.motos.read', navMatch: 'parts.motos' } },
      { path: 'motos/verifier-recu', name: 'parts.moto.receipt.check', component: () => import('../views/parts/motos/MotoReceiptCheckView.vue'), meta: { title: 'Vérifier un reçu', permission: 'parts.motos.read', navMatch: 'parts.motos' } },
      { path: 'clients', name: 'parts.customers', component: () => import('../views/parts/customers/CustomersView.vue'), meta: { title: 'Clients', permission: 'parts.customers.read', navMatch: 'parts.customers' } },
      { path: 'clients/:customerId', name: 'parts.customer', component: () => import('../views/parts/customers/CustomerView.vue'), props: true, meta: { title: 'Client', permission: 'parts.customers.read', navMatch: 'parts.customers' } },
      { path: 'motos/modeles', name: 'parts.moto.models', component: () => import('../views/parts/motos/MotoModelsView.vue'), meta: { title: 'Modèles et prix', permission: 'parts.motos.read', navMatch: 'parts.motos' } },
      { path: 'articles', name: 'parts.products', component: () => import('../views/parts/ProductsView.vue'), meta: { title: 'Articles', permission: 'parts.catalog.read', navMatch: 'parts.products' } },
      { path: 'articles/nouveau', name: 'parts.product.new', component: () => import('../views/parts/ProductNewView.vue'), meta: { title: 'Ajouter un article', permission: 'parts.catalog.manage', navMatch: 'parts.products' } },
      { path: 'articles/:productId', name: 'parts.product', component: () => import('../views/parts/ProductDetailView.vue'), props: true, meta: { title: 'Article', permission: 'parts.catalog.read', navMatch: 'parts.products' } },
      { path: 'stock', name: 'parts.stock', component: () => import('../views/parts/StockView.vue'), meta: { title: 'Stock', permission: 'parts.stock.read', navMatch: 'parts.stock' } },
    ],
  },
  {
    path: '/pieces/recu/:saleId',
    name: 'parts.receipt',
    component: () => import('../views/receipts/SaleReceiptView.vue'),
    props: true,
    meta: { auth: true, company: true, activity: 'auto_parts', permission: 'parts.sales.read', title: 'Reçu de vente' },
  },
  {
    path: '/recu/:paymentId',
    name: 'receipt',
    component: () => import('../views/receipts/ReceiptView.vue'),
    props: true,
    meta: { auth: true, company: true, permission: 'rental.reservations.read', title: 'Reçu' },
  },
  {
    // Page publique ouverte par le QR d'un reçu.
    path: '/verification/recu/:code/:number',
    name: 'receipt.verify',
    component: () => import('../views/receipts/VerifyReceiptView.vue'),
    props: true,
    meta: { title: 'Vérification d’un reçu' },
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('../views/NotFoundView.vue'),
    meta: { title: 'Page introuvable' },
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior(to, _from, saved) {
    if (saved) return saved
    if (to.hash) return { el: to.hash }
    return { top: 0 }
  },
})

router.beforeEach(async (to) => {
  const session = useSessionStore()
  await session.restore()

  const needsAuth = to.matched.some((record) => record.meta.auth)
  const guestOnly = to.matched.some((record) => record.meta.guest)
  const needsCompany = to.matched.some((record) => record.meta.company)
  const ownerOnly = to.matched.some((record) => record.meta.owner)

  if (needsAuth && !session.isAuthenticated) {
    return { name: 'sign-in', query: to.fullPath !== '/' ? { suite: to.fullPath } : {} }
  }

  if (guestOnly && session.isAuthenticated) {
    return { name: 'home' }
  }

  if (ownerOnly && !session.isOwner) {
    return { name: 'home' }
  }

  // Configuration : le propriétaire voit tout ; une personne désignée voit seulement le taux.
  const inConfiguration = to.matched.some((record) => record.meta.configuration)
  if (inConfiguration && !session.isOwner && !(to.meta.rates && session.canManageRates)) {
    return session.canManageRates ? { name: 'system.rates' } : { name: 'home' }
  }

  if (needsCompany && !session.context) {
    return { name: 'companies', query: { suite: to.fullPath } }
  }

  // Écrans d'une autre activité que celle de la société active.
  const activity = to.matched.map((record) => record.meta.activity).find(Boolean)
  if (activity && (session.context?.company.activity ?? 'car_rental') !== activity) {
    return homeRoute()
  }

  const permission = to.meta.permission
  if (permission && !session.can(permission)) {
    return homeRoute()
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} - Clientèle Group ERP` : 'Clientèle Group ERP'
})
