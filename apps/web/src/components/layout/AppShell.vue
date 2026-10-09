<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAppStore } from '../../stores/app'
import { useSessionStore } from '../../stores/session'
import { formatDate, formatTime } from '../../lib/time'
import { initials } from '../../lib/text'
import { formatRate } from '../../lib/money'
import AppIcon from '../ui/AppIcon.vue'
import type { NavItem } from './nav'
import SheetDialog from '../ui/SheetDialog.vue'

/*
 * Coquille de l'application authentifiée.
 * - Téléphone : barre supérieure compacte et onglets en bas, à portée du pouce.
 * - Tablette paysage et ordinateur (960 px et plus) : rail de navigation à gauche.
 */
const props = defineProps<{
  area: string
  nav: NavItem[]
}>()

const app = useAppStore()
const session = useSessionStore()
const router = useRouter()
const accountOpen = ref(false)

const contextName = computed(() => session.context?.company.name ?? props.area)
const clockDate = computed(() => formatDate(app.now))
const clockTime = computed(() => formatTime(app.now))
const rate = computed(() => session.context?.exchange_rate ?? null)

async function openRates(): Promise<void> {
  accountOpen.value = false
  await router.push({ name: 'system.rates' })
}

function isActive(item: NavItem): boolean {
  const current = router.currentRoute.value
  return current.matched.some((record) => record.name === item.match) || current.meta.navMatch === item.match
}

async function changeCompany(): Promise<void> {
  accountOpen.value = false
  session.leaveCompany()
  await router.push({ name: 'companies' })
}

async function openConfiguration(): Promise<void> {
  accountOpen.value = false
  await router.push({ name: 'system.companies' })
}

async function signOut(): Promise<void> {
  accountOpen.value = false
  await session.logout()
  await router.replace({ name: 'sign-in' })
}
</script>

<template>
  <div class="shell">
    <a class="skip-link" href="#contenu">Aller au contenu</a>

    <aside class="rail" aria-label="Navigation principale">
      <RouterLink class="rail-brand" :to="{ name: 'home' }">
        <img src="/brand/clientele-group-logo.webp" alt="Clientèle Group" width="128" height="40" />
      </RouterLink>
      <button class="rail-context" type="button" @click="accountOpen = true">
        <span class="rail-context-label text-muted text-small">{{ area }}</span>
        <strong>{{ contextName }}</strong>
      </button>
      <nav class="rail-nav">
        <RouterLink
          v-for="item in nav"
          :key="item.label"
          :to="item.to"
          class="rail-link"
          :class="{ active: isActive(item) }"
          :aria-current="isActive(item) ? 'page' : undefined"
        >
          <AppIcon :name="item.icon" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>
      <div class="rail-footer">
        <component
          :is="session.canManageRates ? RouterLink : 'div'"
          v-if="session.context"
          class="rail-rate"
          :class="{ warning: !rate || rate.below_brh }"
          :to="session.canManageRates ? { name: 'system.rates' } : undefined"
        >
          <span class="text-small">{{ rate ? formatRate(rate.rate_htg_per_usd) : 'Taux HTG/USD non défini' }}</span>
          <span v-if="rate?.below_brh" class="text-small">Sous la référence BRH</span>
        </component>
        <p class="rail-clock">
          <span class="display display-sm">{{ clockTime }}</span>
          <span class="text-muted text-small">{{ clockDate }}, Cap-Haïtien</span>
        </p>
        <button class="rail-account" type="button" @click="accountOpen = true">
          <span class="avatar" aria-hidden="true">{{ initials(session.user?.name) }}</span>
          <span class="rail-account-name">{{ session.user?.name }}</span>
          <span class="status-dot" :class="app.serverStatus" :title="app.statusLabel"></span>
        </button>
      </div>
    </aside>

    <header class="topbar">
      <RouterLink class="topbar-brand" :to="{ name: 'home' }" aria-label="Accueil">
        <img src="/brand/clientele-group-logo.webp" alt="Clientèle Group" width="96" height="30" />
      </RouterLink>
      <button class="topbar-context" type="button" @click="accountOpen = true">
        <span class="topbar-company">{{ contextName }}</span>
        <span class="topbar-time">{{ clockTime }}</span>
      </button>
      <button class="topbar-account" type="button" aria-label="Compte et société" @click="accountOpen = true">
        <span class="avatar" aria-hidden="true">{{ initials(session.user?.name) }}</span>
        <span class="status-dot" :class="app.serverStatus"></span>
      </button>
    </header>

    <p v-if="!app.browserOnline" class="offline-banner" role="status">
      Hors ligne. Les données affichées peuvent être anciennes et l’enregistrement est indisponible.
    </p>
    <p v-else-if="app.serverStatus === 'offline'" class="offline-banner" role="status">
      Le serveur ne répond pas. Réessayez dans un instant.
      <button type="button" class="btn btn-ghost" @click="app.verifyServer()">Réessayer</button>
    </p>

    <main id="contenu" class="content" tabindex="-1">
      <slot />
    </main>

    <nav class="tabbar" aria-label="Navigation principale">
      <RouterLink
        v-for="item in nav"
        :key="item.label"
        :to="item.to"
        class="tab"
        :class="{ active: isActive(item) }"
        :aria-current="isActive(item) ? 'page' : undefined"
      >
        <AppIcon :name="item.icon" />
        <span>{{ item.label }}</span>
      </RouterLink>
    </nav>

    <SheetDialog :open="accountOpen" title="Compte et société" @close="accountOpen = false">
      <dl class="facts">
        <div>
          <dt>Utilisateur</dt>
          <dd>{{ session.user?.name }}</dd>
        </div>
        <div v-if="session.context">
          <dt>Société active</dt>
          <dd>{{ session.context.company.name }}</dd>
        </div>
        <div v-if="session.context">
          <dt>Taux HTG/USD</dt>
          <dd>{{ rate ? formatRate(rate.rate_htg_per_usd) : 'Non défini' }}<template v-if="rate?.below_brh"> - sous la référence BRH</template></dd>
        </div>
        <div>
          <dt>Serveur</dt>
          <dd>{{ app.statusLabel }}</dd>
        </div>
        <div>
          <dt>Heure de Cap-Haïtien</dt>
          <dd>{{ clockDate }}, {{ clockTime }}</dd>
        </div>
        <div>
          <dt>Version</dt>
          <dd>{{ app.version }}</dd>
        </div>
      </dl>
      <div class="account-actions">
        <button v-if="session.canManageRates && !session.isOwner" class="btn btn-secondary btn-block" type="button" @click="openRates">Taux de change</button>
        <button v-if="session.companies.length > 1 || session.isOwner" class="btn btn-secondary btn-block" type="button" @click="changeCompany">
          Changer de société
        </button>
        <button v-if="session.isOwner" class="btn btn-secondary btn-block" type="button" @click="openConfiguration">
          Configuration système
        </button>
        <button class="btn btn-danger btn-block" type="button" @click="signOut">Fermer la session</button>
      </div>
    </SheetDialog>
  </div>
</template>

<style scoped>
.shell {
  min-height: 100dvh;
}

.skip-link {
  position: absolute;
  left: -9999px;
}

.skip-link:focus {
  left: 12px;
  top: 12px;
  z-index: 60;
  padding: 10px 14px;
  border-radius: 4px;
  background: var(--surface);
}

/* --- Téléphone et tablette portrait --- */

.rail {
  display: none;
}

.topbar {
  position: sticky;
  top: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  gap: 10px;
  height: calc(var(--header-h) + env(safe-area-inset-top));
  padding: env(safe-area-inset-top) var(--gutter) 0;
  background: var(--surface);
  backdrop-filter: saturate(1.4) blur(12px);
  border-bottom: 1px solid var(--line);
}

.topbar-brand img {
  width: auto;
  height: 40px;
  object-fit: contain;
}

.topbar-context {
  display: flex;
  flex: 1;
  align-items: baseline;
  justify-content: flex-end;
  gap: 10px;
  min-width: 0;
  min-height: var(--tap);
  padding: 0;
  border: 0;
  background: transparent;
  cursor: pointer;
}

.topbar-company {
  overflow: hidden;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.topbar-time {
  flex: none;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.topbar-account {
  position: relative;
  flex: none;
  width: var(--tap);
  height: var(--tap);
  padding: 0;
  border: 0;
  background: transparent;
  cursor: pointer;
}

.avatar {
  display: inline-grid;
  place-items: center;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  color: #fff;
  background: var(--ink);
  font-size: var(--text-sm);
  font-weight: 600;
}

.status-dot {
  position: absolute;
  right: 4px;
  bottom: 6px;
  width: 12px;
  height: 12px;
  border: 2px solid var(--bg);
  border-radius: 50%;
  background: var(--ink-3);
}

.status-dot.online {
  background: var(--success);
}

.status-dot.offline {
  background: var(--danger);
}

.offline-banner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 12px;
  margin: 0;
  padding: 10px var(--gutter);
  color: var(--warning);
  background: var(--warning-soft);
  font-size: var(--text-sm);
  font-weight: 600;
}

.content {
  width: 100%;
  max-width: var(--content-max);
  margin: 0 auto;
  padding: 20px var(--gutter) calc(var(--tabbar-h) + 32px + env(safe-area-inset-bottom));
  outline: none;
}

.tabbar {
  position: fixed;
  z-index: 20;
  left: 0;
  right: 0;
  bottom: 0;
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: 1fr;
  height: calc(var(--tabbar-h) + env(safe-area-inset-bottom));
  padding-bottom: env(safe-area-inset-bottom);
  border-top: 1px solid var(--line);
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: saturate(1.4) blur(12px);
}

.tab {
  display: grid;
  justify-items: center;
  align-content: center;
  gap: 4px;
  min-width: 0;
  color: var(--ink-3);
  font-size: 0.75rem;
  font-weight: 600;
  text-decoration: none;
  -webkit-tap-highlight-color: transparent;
}

.tab span {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tab.active {
  color: var(--brand);
}

.account-actions {
  display: grid;
  gap: 10px;
}

/* --- Tablette paysage et ordinateur --- */

@media (min-width: 960px) {
  .shell {
    display: grid;
    grid-template-columns: var(--rail-w) minmax(0, 1fr);
  }

  .topbar,
  .tabbar {
    display: none;
  }

  .rail {
    grid-row: 1 / span 3;
    grid-column: 1;
    position: sticky;
    top: 0;
    display: flex;
    flex-direction: column;
    gap: 20px;
    height: 100dvh;
    padding: 24px 16px 20px;
    border-right: 1px solid var(--line);
    background: var(--surface);
  }

  .rail-brand img {
    width: 128px;
    height: auto;
    margin-left: 8px;
  }

  .rail-context {
    display: grid;
    gap: 2px;
    padding: 12px;
    border: 0;
    border-radius: 4px;
    background: var(--surface-sunken);
    text-align: left;
    cursor: pointer;
  }

  .rail-nav {
    display: grid;
    gap: 4px;
  }

  .rail-link {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: var(--tap);
    padding: 0 12px;
    border-radius: 4px;
    color: var(--ink-2);
    font-weight: 600;
    text-decoration: none;
  }

  .rail-link:hover {
    background: var(--bg);
  }

  .rail-link.active {
    color: var(--ink);
    background: var(--brand-soft);
    box-shadow: inset 4px 0 0 var(--brand);
  }

  .rail-footer {
    display: grid;
    gap: 14px;
    margin-top: auto;
  }

  .rail-clock {
    display: grid;
    gap: 6px;
    padding: 0 12px;
  }

  .rail-rate {
    display: grid;
    gap: 2px;
    margin: 0 4px 8px;
    padding: 8px;
    border-radius: var(--radius-control);
    color: var(--ink);
    text-decoration: none;
  }

  .rail-rate:hover {
    background: var(--surface-hover);
  }

  .rail-rate.warning {
    color: var(--warning);
    background: var(--warning-soft);
  }

  .rail-account {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 56px;
    padding: 8px;
    border: 1px solid var(--line);
    border-radius: 4px;
    background: var(--surface);
    text-align: left;
    cursor: pointer;
  }

  .rail-account .status-dot {
    position: static;
    margin-left: auto;
    border-color: var(--surface);
  }

  .rail-account-name {
    overflow: hidden;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .content {
    padding: 36px 40px 56px;
  }

  .offline-banner,
  .content {
    grid-column: 2;
  }
}
</style>

<style>
/* Impression depuis un écran de l'application : seul le contenu de la page est imprimé. */
@media print {
  .shell .rail,
  .shell .topbar,
  .shell .tabbar,
  .shell .offline-banner,
  .shell .skip-link {
    display: none !important;
  }

  .shell {
    display: block !important;
    min-height: 0 !important;
  }

  .shell .content {
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
  }

  body {
    background: #ffffff !important;
  }
}
</style>
