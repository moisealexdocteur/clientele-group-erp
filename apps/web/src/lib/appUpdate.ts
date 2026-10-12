/*
 * Mise à jour de la PWA après un déploiement. Un poste de caisse ou un
 * kiosque ne doit jamais rester sur une ancienne version gardée par le
 * navigateur : dès que le serveur annonce une autre version, le service
 * worker est mis à jour et la page se recharge une seule fois.
 */

const RELOAD_KEY = 'clientele.erp.reloaded-for'

function remember(version: string): boolean {
  try {
    if (sessionStorage.getItem(RELOAD_KEY) === version) return false
    sessionStorage.setItem(RELOAD_KEY, version)
    return true
  } catch {
    // Stockage indisponible : on recharge quand même une fois par affichage.
    return true
  }
}

/** Vrai si la version du serveur diffère de celle de la page affichée. */
export function isOutdated(serverVersion: string | null | undefined, appVersion: string): boolean {
  return Boolean(serverVersion) && serverVersion !== appVersion
}

export async function reloadIfOutdated(serverVersion: string | null | undefined, appVersion = __APP_VERSION__): Promise<boolean> {
  if (!isOutdated(serverVersion, appVersion) || !remember(String(serverVersion))) return false
  try {
    const registration = await navigator.serviceWorker?.getRegistration()
    await registration?.update()
  } catch {
    // La mise à jour du service worker n'empêche pas le rechargement.
  }
  window.location.reload()
  return true
}
