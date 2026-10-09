import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useSessionStore } from '../stores/session'

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
    /** Nom de la rubrique à marquer comme active dans la navigation. */
    navMatch?: string
    title?: string
  }
}

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    redirect: () => {
      const session = useSessionStore()
      if (!session.isAuthenticated) return { name: 'sign-in' }
      if (session.context) return { name: 'rental.today' }
      return { name: 'companies' }
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
      { path: '', name: 'system.companies', component: () => import('../views/system/CompaniesView.vue'), meta: { title: 'Sociétés', navMatch: 'system.companies' } },
      { path: 'societes/nouvelle', name: 'system.company.new', component: () => import('../views/system/CompanyNewView.vue'), meta: { title: 'Ajouter une société', navMatch: 'system.companies' } },
      { path: 'societes/:companyId', name: 'system.company', component: () => import('../views/system/CompanyDetailView.vue'), props: true, meta: { title: 'Société', navMatch: 'system.companies' } },
      { path: 'societes/:companyId/utilisateurs', name: 'system.users', component: () => import('../views/system/UsersView.vue'), props: true, meta: { title: 'Utilisateurs', navMatch: 'system.users' } },
      { path: 'societes/:companyId/utilisateurs/nouveau', name: 'system.user.new', component: () => import('../views/system/UserNewView.vue'), props: true, meta: { title: 'Ajouter un utilisateur', navMatch: 'system.users' } },
      { path: 'societes/:companyId/utilisateurs/:accessId', name: 'system.user', component: () => import('../views/system/UserDetailView.vue'), props: true, meta: { title: 'Utilisateur', navMatch: 'system.users' } },
    ],
  },
  {
    path: '/location',
    component: () => import('../views/rental/RentalLayout.vue'),
    meta: { auth: true, company: true },
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
      { path: 'vehicules/:vehicleId', name: 'rental.vehicle', component: () => import('../views/rental/VehicleDetailView.vue'), props: true, meta: { title: 'Véhicule', permission: 'rental.vehicles.read', navMatch: 'rental.vehicles' } },
    ],
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

  const permission = to.meta.permission
  if (permission && !session.can(permission)) {
    return { name: 'rental.today' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} - Clientèle Group ERP` : 'Clientèle Group ERP'
})
