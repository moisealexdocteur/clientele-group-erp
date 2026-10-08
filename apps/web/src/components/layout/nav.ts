import type { RouteLocationRaw } from 'vue-router'
import type { IconName } from '../ui/icons'

export interface NavItem {
  to: RouteLocationRaw
  label: string
  icon: IconName
  /** Nom de route racine utilisé pour marquer l'onglet actif. */
  match: string
}
