<script setup lang="ts">
import { ref, shallowRef } from 'vue';
import {
  AlertTriangle,
  CircleCheck,
  Eye,
  EyeOff,
  ArrowRight,
  BookOpen,
  Loader2,
  Lock,
  Mail,
  Moon,
  ShieldCheck,
  Sun,
} from 'lucide-vue-next';
import { useAuthSession } from './useAuthSession';
import LoginAccountingVisual from './LoginAccountingVisual.vue';

const props = withDefaults(
  defineProps<{
    isDarkMode?: boolean;
  }>(),
  {
    isDarkMode: false,
  },
);

const emit = defineEmits<{
  (e: 'login-success'): void;
  (e: 'toggle-dark'): void;
}>();

const { login, isLoading, authError, sessionNotice, logoutStatus } = useAuthSession();

const email = ref('');
const password = ref('');
const showPassword = shallowRef(false);
const localError = shallowRef<string | null>(null);

async function handleLogin(): Promise<void> {
  if (isLoading.value) return;
  localError.value = null;

  const trimmedEmail = email.value.trim().toLowerCase();
  const rawPassword = password.value;

  if (!trimmedEmail) {
    localError.value = 'Por favor ingrese su correo electrónico institucional.';
    return;
  }

  if (!rawPassword) {
    localError.value = 'Por favor ingrese su contraseña de acceso.';
    return;
  }

  const success = await login(trimmedEmail, rawPassword);
  if (success) {
    emit('login-success');
  } else {
    localError.value =
      authError.value ?? 'Credenciales no autorizadas o cuenta bloqueada temporalmente.';
  }
}
</script>

<template>
  <div class="login-page" :data-login-theme="props.isDarkMode ? 'dark' : 'light'">
    <header class="login-topbar">
      <div class="login-brand" aria-label="ContaPerú ERP Contable">
        <span class="login-brand-mark">
          <BookOpen aria-hidden="true" />
        </span>
        <span class="login-brand-name">
          <strong>ContaPerú</strong>
          <span>ERP Contable</span>
        </span>
      </div>

      <button
        id="th"
        type="button"
        class="login-theme-toggle"
        :aria-label="props.isDarkMode ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
        @click="emit('toggle-dark')"
      >
        <Moon v-if="!props.isDarkMode" aria-hidden="true" />
        <Sun v-else aria-hidden="true" />
        <span>{{ props.isDarkMode ? 'Modo claro' : 'Modo oscuro' }}</span>
      </button>
    </header>

    <main class="login-main">
      <section class="login-story" aria-labelledby="login-story-title">
        <div class="login-story-content">
          <h1 id="login-story-title">Tu información financiera, en orden.</h1>
          <p class="login-story-description">
            Ingresa a tu espacio de trabajo y continúa con la gestión de tu empresa.
          </p>
        </div>

        <LoginAccountingVisual :key="String(props.isDarkMode)" class="login-accounting-visual" />

        <div class="login-story-foot">
          <span class="login-story-foot-icon"><Lock aria-hidden="true" /></span>
          <span>Un espacio de trabajo para tu equipo contable.</span>
        </div>
      </section>

      <section class="login-panel" aria-labelledby="login-title">
        <div class="login-form-wrap">
          <div class="login-heading">
            <h2 id="login-title">Inicia sesión</h2>
            <p>Ingresa con tus credenciales institucionales para continuar.</p>
          </div>

          <div
            v-if="sessionNotice"
            class="login-notice"
            :role="logoutStatus === 'pending' || logoutStatus === 'revoked' ? 'status' : 'alert'"
          >
            <Loader2 v-if="logoutStatus === 'pending'" class="login-spinner" aria-hidden="true" />
            <CircleCheck v-else-if="logoutStatus === 'revoked'" aria-hidden="true" />
            <AlertTriangle v-else aria-hidden="true" />
            <p>{{ sessionNotice }}</p>
          </div>

          <div
            v-if="localError"
            id="login-error"
            class="login-error"
            role="alert"
          >
            <AlertTriangle aria-hidden="true" />
            <div>
              <strong>Error de autenticación</strong>
              <p>{{ localError }}</p>
            </div>
          </div>

          <form class="login-form" :aria-busy="isLoading" @submit.prevent="handleLogin">
            <div class="login-field">
              <label for="login-email">Correo institucional</label>
              <div class="login-input-wrap">
                <Mail class="login-input-icon" aria-hidden="true" />
                <input
                  id="login-email"
                  v-model="email"
                  type="email"
                  required
                  autocomplete="username"
                  inputmode="email"
                  autocapitalize="none"
                  :spellcheck="false"
                  :aria-describedby="localError ? 'login-error' : undefined"
                  placeholder="usuario@empresa.com.pe"
                  :disabled="isLoading"
                />
              </div>
            </div>

            <div class="login-field">
              <div class="login-label-row">
                <label for="login-password">Contraseña</label>
              </div>
              <div class="login-input-wrap">
                <Lock class="login-input-icon" aria-hidden="true" />
                <input
                  id="login-password"
                  v-model="password"
                  :type="showPassword ? 'text' : 'password'"
                  required
                  autocomplete="current-password"
                  :aria-describedby="localError ? 'login-error' : undefined"
                  placeholder="Ingresa tu contraseña"
                  :disabled="isLoading"
                />
                <button
                  type="button"
                  class="login-password-toggle"
                  :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                  :aria-pressed="showPassword"
                  :disabled="isLoading"
                  @click="showPassword = !showPassword"
                >
                  <EyeOff v-if="showPassword" aria-hidden="true" />
                  <Eye v-else aria-hidden="true" />
                </button>
              </div>
            </div>

            <button
              type="submit"
              class="login-submit"
              :disabled="isLoading"
            >
              <Loader2 v-if="isLoading" class="login-spinner" aria-hidden="true" />
              <span>{{ isLoading ? 'Verificando acceso…' : 'Ingresar al sistema' }}</span>
              <ArrowRight v-if="!isLoading" aria-hidden="true" />
            </button>
          </form>

          <div class="login-security-note">
            <ShieldCheck aria-hidden="true" />
            <p>Acceso restringido a personal autorizado. La aplicación registra eventos de autenticación.</p>
          </div>
        </div>
      </section>
    </main>

    <footer class="login-footer">
      <span>© 2026 ContaPerú · República del Perú</span>
      <span>Gestión contable para empresas peruanas</span>
    </footer>
  </div>
</template>

<style scoped>
/* Login: composición editorial de libro contable; paleta compartida, formulario prioritario. */
.login-page {
  --login-story-bg: var(--color-violet-50);
  --login-story-ink: var(--color-violet-950);
  --login-story-muted: var(--color-violet-800);
  --login-art-paper: var(--color-white);
  --login-art-line: var(--color-violet-200);
  --login-art-accent: var(--color-violet-600);
  display: flex;
  min-height: 100svh;
  flex-direction: column;
  background: var(--bg);
  color: var(--ink);
  font-family: var(--font-sans);
}
.login-page[data-login-theme="dark"] {
  --login-story-bg: var(--color-zinc-900);
  --login-story-ink: var(--color-zinc-50);
  --login-story-muted: var(--color-zinc-300);
  --login-art-paper: var(--color-zinc-950);
  --login-art-line: var(--color-zinc-600);
  --login-art-accent: var(--color-violet-200);
}
.login-topbar, .login-main, .login-footer {
  width: min(1240px, calc(100% - 96px));
  margin-inline: auto;
}
.login-topbar {
  display: flex;
  min-height: 96px;
  align-items: center;
  justify-content: space-between;
}
.login-brand {
  display: flex;
  align-items: center;
  gap: 12px;
}
.login-brand-mark {
  display: grid;
  width: 42px;
  height: 42px;
  place-items: center;
  color: var(--nav-active);
}
.login-brand-mark svg {
  width: 32px;
  height: 32px;
  stroke-width: 1.6;
}
.login-brand-name {
  display: grid;
  gap: 2px;
  line-height: 1.2;
}
.login-brand-name strong {
  font-size: 18px;
  font-weight: 650;
  letter-spacing: -0.025em;
}
.login-brand-name > span {
  color: var(--mut);
  font-size: 12px;
}
.login-theme-toggle {
  display: flex;
  min-height: 44px;
  align-items: center;
  gap: 9px;
  padding: 10px 12px;
  border: 1px solid var(--line);
  border-radius: var(--radius-control);
  background: var(--surf);
  color: var(--ink);
  font: inherit;
  font-size: 13px;
}
.login-theme-toggle svg {
  width: 17px;
  height: 17px;
}
.login-theme-toggle:hover {
  background: var(--hover);
}
.login-main {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  flex: 1;
  align-items: center;
  gap: clamp(48px, 7vw, 112px);
  padding-block: 32px 56px;
}
.login-story {
  display: flex;
  min-width: 0;
  flex-direction: column;
  padding: clamp(28px, 3.5vw, 48px);
  border-radius: 16px;
  background: var(--login-story-bg);
  color: var(--login-story-ink);
  transition: background-color 220ms ease, color 220ms ease;
}
.login-story h1 {
  max-width: 540px;
  margin: 0 0 24px;
  color: inherit;
  font-size: clamp(36px, 3.7vw, 52px);
  font-weight: 600;
  letter-spacing: -0.04em;
  line-height: 1.08;
  text-wrap: balance;
  overflow-wrap: break-word;
}
.login-story-description {
  max-width: 350px;
  margin: 0;
  color: var(--login-story-muted);
  font-size: 16px;
  line-height: 1.75;
}
.login-story-foot {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--login-story-muted);
  font-size: 12px;
}
.login-story-foot-icon svg {
  width: 15px;
  height: 15px;
}
.login-panel {
  min-width: 0;
  padding: clamp(32px, 4vw, 56px);
  border: 1px solid var(--line);
  border-radius: 16px;
  background: var(--surf);
}
.login-form-wrap {
  max-width: 390px;
  margin-inline: auto;
}
.login-heading {
  margin-bottom: 32px;
}
.login-heading h2 {
  margin: 0 0 12px;
  font-size: clamp(28px, 3vw, 36px);
  font-weight: 600;
  letter-spacing: -0.035em;
  line-height: 1.15;
}
.login-heading p {
  margin: 0;
  color: var(--mut);
  font-size: 14px;
  line-height: 1.65;
}
.login-form {
  display: grid;
  gap: 24px;
}
.login-field {
  display: grid;
  gap: 9px;
}
.login-field label {
  color: var(--ink);
  font-size: 13px;
  font-weight: 600;
}
.login-input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.login-input-icon {
  position: absolute;
  left: 15px;
  width: 18px;
  height: 18px;
  color: var(--mut);
  pointer-events: none;
}
.login-input-wrap input {
  width: 100%;
  min-width: 0;
  min-height: 52px;
  padding: 12px 48px;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius-control);
  background: var(--surf);
  color: var(--ink);
  caret-color: var(--nav-active);
  font: inherit;
  font-size: 16px;
  transition: border-color 150ms ease;
}
.login-input-wrap input::placeholder {
  color: var(--mut);
  opacity: 1;
}
.login-input-wrap input:hover:not(:disabled) {
  border-color: var(--nav-active);
}
.login-input-wrap input:focus {
  outline: 2px solid var(--focus-color);
  outline-offset: 2px;
  border-color: var(--focus-color);
  box-shadow: none;
}
.login-input-wrap input:disabled {
  cursor: not-allowed;
  opacity: 0.65;
}
.login-password-toggle {
  position: absolute;
  right: 4px;
  display: grid;
  width: 44px;
  height: 44px;
  place-items: center;
  border: 0;
  border-radius: var(--radius-control);
  background: transparent;
  color: var(--mut);
}
.login-password-toggle:hover:not(:disabled) {
  background: var(--soft);
  color: var(--ink);
}
.login-password-toggle svg {
  width: 18px;
  height: 18px;
}
.login-submit {
  display: flex;
  min-height: 52px;
  align-items: center;
  justify-content: center;
  gap: 12px;
  margin-top: 4px;
  padding: 14px 20px;
  border: 0;
  border-radius: var(--radius-control);
  background: var(--brand);
  color: var(--brandtx);
  font: inherit;
  font-size: 14px;
  font-weight: 600;
  transition: background 150ms ease, transform 150ms ease;
}
.login-submit svg {
  width: 18px;
  height: 18px;
}
.login-submit:hover:not(:disabled) {
  background: var(--color-violet-700);
}
.login-submit:active:not(:disabled) {
  transform: translateY(1px);
}
.login-submit:disabled {
  cursor: wait;
  opacity: 0.7;
}
.login-spinner {
  animation: login-spin 900ms linear infinite;
}
.login-notice, .login-error {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-bottom: 24px;
  padding: 14px;
  border: 1px solid var(--line);
  border-radius: var(--radius-control);
  background: var(--soft);
  color: var(--ink);
  font-size: 13px;
  line-height: 1.6;
  overflow-wrap: anywhere;
}
.login-error {
  border-color: var(--neg);
  background: var(--surf);
  color: var(--neg);
}
.login-notice > svg, .login-error > svg {
  width: 18px;
  height: 18px;
  flex: 0 0 auto;
  margin-top: 2px;
}
.login-notice p, .login-error p {
  margin: 0;
}
.login-error strong {
  display: block;
  margin-bottom: 3px;
  font-weight: 600;
}
.login-security-note {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-top: 28px;
  padding-top: 24px;
  border-top: 1px solid var(--line);
  color: var(--mut);
}
.login-security-note svg {
  width: 17px;
  height: 17px;
  flex: 0 0 auto;
  margin-top: 2px;
}
.login-security-note p {
  margin: 0;
  font-size: 12px;
  line-height: 1.65;
}
.login-footer {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding-block: 16px 24px;
  color: var(--mut);
  font-size: 12px;
}
.login-theme-toggle:focus-visible, .login-password-toggle:focus-visible, .login-submit:focus-visible {
  outline: 2px solid var(--focus-color);
  outline-offset: 3px;
}
@keyframes login-spin { to { transform: rotate(360deg); } }
@media (max-width: 1000px) {
  .login-topbar, .login-main, .login-footer {
    width: calc(100% - 48px);
  }
  .login-main {
    gap: 32px;
  }
  .login-panel {
    padding: 32px 24px;
  }
  .login-story h1 {
    font-size: 42px;
  }
}
@media (max-width: 760px) {
  .login-topbar, .login-main, .login-footer {
    width: min(480px, calc(100% - 40px));
  }
  .login-topbar {
    min-height: 84px;
  }
  .login-main {
    grid-template-columns: minmax(0, 1fr);
    align-content: center;
    gap: 0;
    padding-block: 16px 32px;
  }
  .login-story {
    padding: 24px;
    margin-bottom: 16px;
  }
  .login-story h1 {
    max-width: 420px;
    margin: 0;
    font-size: clamp(26px, 6.5vw, 34px);
    line-height: 1.15;
  }
  .login-story-description, .login-story-foot {
    display: none;
  }
  .login-accounting-visual {
    max-width: 280px;
    margin-block: 8px 0;
  }
  .login-panel {
    padding: 28px 24px;
  }
  .login-heading {
    margin-bottom: 24px;
  }
  .login-heading h2 {
    font-size: 28px;
  }
  .login-form {
    gap: 20px;
  }
  .login-theme-toggle {
    width: 44px;
    justify-content: center;
    padding: 0;
  }
  .login-theme-toggle span {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    overflow: clip;
    clip-path: inset(50%);
    white-space: nowrap;
  }
  .login-footer {
    flex-direction: column;
    gap: 4px;
    font-size: 11px;
  }
}
@media (max-width: 380px) {
  .login-topbar, .login-main, .login-footer {
    width: calc(100% - 32px);
  }
  .login-panel {
    padding: 24px 18px;
  }
  .login-heading h2 {
    font-size: 26px;
  }
}
@media (prefers-reduced-motion: reduce) {
  .login-page *, .login-page *::before, .login-page *::after {
    animation: none !important;
    transition: none !important;
    scroll-behavior: auto !important;
  }
}
</style>
