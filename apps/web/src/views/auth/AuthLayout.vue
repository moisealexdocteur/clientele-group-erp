<script setup lang="ts">
import { computed } from 'vue'
import { useAppStore } from '../../stores/app'
import { formatDayHeading, formatTime } from '../../lib/time'

const app = useAppStore()
const time = computed(() => formatTime(app.now))
const day = computed(() => formatDayHeading(app.now))
</script>

<template>
  <div class="auth">
    <section class="auth-context" aria-label="Heure locale">
      <img class="auth-logo" src="/brand/clientele-group-logo.webp" alt="Clientèle Group" width="160" height="50" />
      <p class="auth-clock">
        <span class="display display-xxl">{{ time }}</span>
        <span class="auth-day">{{ day }}, Cap-Haïtien</span>
      </p>
      <p class="auth-status" :class="app.serverStatus">
        <span aria-hidden="true"></span>{{ app.statusLabel }}
      </p>
    </section>

    <main class="auth-main">
      <div class="auth-card">
        <RouterView />
      </div>
      <p class="auth-version text-muted text-small">Clientèle Group ERP {{ app.version }}</p>
    </main>
  </div>
</template>

<style scoped>
.auth {
  display: grid;
  min-height: 100dvh;
}

.auth-context {
  display: grid;
  gap: 18px;
  align-content: start;
  padding: calc(24px + env(safe-area-inset-top)) var(--gutter) 8px;
}

.auth-logo {
  width: 128px;
  height: auto;
}

.auth-clock {
  display: grid;
  gap: 8px;
}

.auth-day {
  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--ink-2);
}

.auth-status {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: var(--text-sm);
  font-weight: 600;
  color: var(--ink-3);
}

.auth-status span {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: currentColor;
}

.auth-status.online {
  color: var(--success);
}

.auth-status.offline {
  color: var(--danger);
}

.auth-main {
  display: grid;
  align-content: start;
  gap: 16px;
  padding: 16px var(--gutter) calc(32px + env(safe-area-inset-bottom));
}

.auth-card {
  display: grid;
  gap: 20px;
  width: 100%;
  max-width: 480px;
  padding: 24px var(--gutter);
  border-radius: 22px;
  background: var(--surface);
}

@media (min-width: 900px) {
  .auth {
    grid-template-columns: minmax(0, 1.1fr) minmax(420px, 1fr);
  }

  .auth-context {
    position: relative;
    align-content: space-between;
    padding: 48px 56px;
    color: #fff;
    background: var(--ink);
    overflow: hidden;
  }

  /* Bande rouge Clientèle : un seul geste de marque, au bord de l'écran. */
  .auth-context::after {
    content: '';
    position: absolute;
    inset: 0 0 0 auto;
    width: 10px;
    background: var(--brand);
  }

  .auth-logo {
    width: 168px;
    padding: 10px 14px;
    border-radius: 12px;
    background: #fff;
  }

  .auth-clock .display {
    font-size: clamp(4.5rem, 9vw, 8.5rem);
  }

  .auth-day {
    color: rgba(255, 255, 255, 0.78);
    font-size: var(--text-xl);
  }

  .auth-status {
    color: rgba(255, 255, 255, 0.7);
  }

  .auth-status.online {
    color: #6fdc9f;
  }

  .auth-status.offline {
    color: #ff8a8d;
  }

  .auth-main {
    align-content: center;
    justify-items: center;
    padding: 48px;
  }

  .auth-card {
    padding: 36px;
  }
}
</style>
