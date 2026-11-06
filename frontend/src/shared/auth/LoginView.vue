<script setup lang="ts">
import { ref, shallowRef } from 'vue';
import {
  AlertTriangle,
  Eye,
  EyeOff,
  KeyRound,
  Loader2,
  Lock,
  Mail,
  Moon,
  ShieldCheck,
  Sun,
} from 'lucide-vue-next';
import { useAuthSession } from './useAuthSession';

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

const { login, isLoading, authError, sessionNotice } = useAuthSession();

const email = ref('');
const password = ref('');
const showPassword = shallowRef(false);
const localError = shallowRef<string | null>(null);

async function handleLogin(): Promise<void> {
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
  <div class="login-page">
    <header class="login-topbar">
      <div class="login-brand" aria-label="ContaPerú ERP Contable">
        <span class="login-brand-mark">
          <ShieldCheck aria-hidden="true" />
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
          <p class="login-eyebrow">GESTIÓN CONTABLE PARA EMPRESAS PERUANAS</p>
          <h1 id="login-story-title">Tu información financiera, en orden.</h1>
          <p class="login-story-description">
            Ingresa a tu espacio de trabajo y continúa con la gestión de tu empresa.
          </p>
        </div>

        <div class="login-illustration" aria-hidden="true">
          <div class="login-orbit login-orbit-one" />
          <div class="login-orbit login-orbit-two" />
          <div class="login-ledger">
            <div class="login-ledger-top">
              <span class="login-ledger-symbol"><ShieldCheck /></span>
              <span class="login-ledger-title">
                <i />
                <i />
              </span>
              <span class="login-ledger-menu"><i /><i /><i /></span>
            </div>
            <div class="login-ledger-rule" />
            <div class="login-ledger-columns">
              <span /><span /><span /><span />
            </div>
            <div class="login-ledger-row"><i /><i /><b /></div>
            <div class="login-ledger-row"><i /><i /><b /></div>
            <div class="login-ledger-row"><i /><i /><b /></div>
            <div class="login-ledger-row"><i /><i /><b /></div>
            <div class="login-ledger-footer"><i /><i /></div>
          </div>
          <span class="login-illustration-accent" />
        </div>

        <div class="login-story-foot">
          <span class="login-story-foot-icon"><Lock aria-hidden="true" /></span>
          <span>Un espacio de trabajo para tu equipo contable.</span>
        </div>
      </section>

      <section class="login-panel" aria-labelledby="login-title">
        <div class="login-form-wrap">
          <div class="login-heading">
            <p class="login-heading-kicker">BIENVENIDO</p>
            <h2 id="login-title">Inicia sesión</h2>
            <p>Ingresa con tus credenciales institucionales para continuar.</p>
          </div>

          <div
            v-if="sessionNotice"
            class="login-notice"
            role="alert"
          >
            <AlertTriangle aria-hidden="true" />
            <p>{{ sessionNotice }}</p>
          </div>

          <div
            v-if="localError"
            class="login-error"
            role="alert"
          >
            <AlertTriangle aria-hidden="true" />
            <div>
              <strong>Error de autenticación</strong>
              <p>{{ localError }}</p>
            </div>
          </div>

          <form class="login-form" @submit.prevent="handleLogin">
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
              <KeyRound v-else aria-hidden="true" />
              <span>{{ isLoading ? 'Verificando acceso…' : 'Ingresar al sistema' }}</span>
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
      <span>Sesión de API: duración máxima de 12 horas</span>
    </footer>
  </div>
</template>

<style scoped>
.login-page {
  --login-navy: #071b4b;
  --login-blue: #003da5;
  --login-accent: #f2a23a;
  --login-white: #fff;
  display: flex;
  min-height: 100vh;
  min-height: 100svh;
  flex-direction: column;
  overflow: hidden;
  background: var(--bg);
  color: var(--ink);
  font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

.login-topbar {
  display: flex;
  width: min(1320px, calc(100% - 64px));
  min-height: 84px;
  align-items: center;
  justify-content: space-between;
  margin: 0 auto;
}

.login-brand {
  display: inline-flex;
  align-items: center;
  gap: 0.75rem;
}

.login-brand-mark {
  display: grid;
  width: 42px;
  height: 42px;
  place-items: center;
  border-radius: 12px;
  background: var(--brand);
  color: var(--brandtx);
  box-shadow: 0 5px 14px color-mix(in oklab, var(--brand) 20%, transparent);
}

.login-brand-mark svg {
  width: 22px;
  height: 22px;
  stroke-width: 1.8;
}

.login-brand-name {
  display: grid;
  gap: 0.05rem;
  line-height: 1.15;
}

.login-brand-name strong {
  color: var(--ink);
  font-size: 0.97rem;
  font-weight: 750;
  letter-spacing: -0.025em;
}

.login-brand-name > span {
  color: var(--mut);
  font-size: 0.7rem;
  font-weight: 550;
  letter-spacing: 0.01em;
}

.login-theme-toggle {
  display: inline-flex;
  min-height: 42px;
  align-items: center;
  gap: 0.55rem;
  padding: 0.5rem 0.8rem;
  border: 1px solid var(--line);
  border-radius: 9px;
  background: var(--surf);
  color: var(--mut);
  font: inherit;
  font-size: 0.75rem;
  font-weight: 600;
  transition: border-color 140ms ease, color 140ms ease, background 140ms ease;
}

.login-theme-toggle:hover {
  border-color: var(--line-strong);
  background: var(--soft);
  color: var(--ink);
}

.login-theme-toggle svg {
  width: 16px;
  height: 16px;
  color: var(--accent-text);
}

.login-main {
  display: grid;
  width: min(1180px, calc(100% - 64px));
  flex: 1;
  grid-template-columns: minmax(0, 1.03fr) minmax(400px, 0.97fr);
  align-items: stretch;
  gap: clamp(24px, 4vw, 58px);
  margin: 0 auto;
  padding: 18px 0 34px;
}

.login-story {
  position: relative;
  display: flex;
  min-height: 590px;
  flex-direction: column;
  justify-content: space-between;
  overflow: hidden;
  padding: clamp(34px, 5vw, 68px);
  border-radius: 22px;
  isolation: isolate;
  background:
    radial-gradient(ellipse at 90% 18%, color-mix(in oklab, var(--login-blue) 72%, transparent), transparent 45%),
    linear-gradient(145deg, var(--login-navy), color-mix(in oklab, var(--brand) 74%, var(--login-navy)));
  color: var(--login-white);
}

.login-story::before,
.login-story::after {
  position: absolute;
  z-index: -1;
  border: 1px solid rgb(255 255 255 / 0.12);
  border-radius: 50%;
  content: "";
  pointer-events: none;
}

.login-story::before {
  top: -180px;
  right: -195px;
  width: 460px;
  height: 460px;
}

.login-story::after {
  top: -128px;
  right: -142px;
  width: 350px;
  height: 350px;
}

.login-story-content {
  max-width: 470px;
}

.login-eyebrow,
.login-heading-kicker {
  margin: 0;
  color: var(--login-accent);
  font-size: 0.68rem;
  font-weight: 750;
  letter-spacing: 0.12em;
  line-height: 1.4;
}

.login-story h1 {
  max-width: 440px;
  margin: 1.1rem 0 0.9rem;
  color: var(--login-white);
  font-size: clamp(2.2rem, 4vw, 3.65rem);
  font-weight: 650;
  letter-spacing: -0.055em;
  line-height: 1.04;
}

.login-story-description {
  max-width: 390px;
  margin: 0;
  color: rgb(255 255 255 / 0.76);
  font-size: 0.98rem;
  line-height: 1.7;
}

.login-illustration {
  position: relative;
  display: grid;
  min-height: 238px;
  place-items: center;
  margin: 12px -15px 4px;
  perspective: 900px;
}

.login-orbit {
  position: absolute;
  border: 1px solid rgb(255 255 255 / 0.12);
  border-radius: 50%;
  transform: rotate(-16deg);
}

.login-orbit-one {
  width: min(92%, 480px);
  height: 174px;
}

.login-orbit-two {
  width: min(78%, 400px);
  height: 220px;
  transform: rotate(24deg);
}

.login-ledger {
  position: relative;
  width: min(82%, 384px);
  padding: 18px 20px 16px;
  transform: rotate(-4deg);
  border: 1px solid rgb(255 255 255 / 0.22);
  border-radius: 13px;
  background: linear-gradient(130deg, rgb(255 255 255 / 0.18), rgb(255 255 255 / 0.09));
  box-shadow: 0 25px 60px rgb(0 0 0 / 0.22);
  backdrop-filter: blur(14px);
}

.login-ledger-top {
  display: flex;
  align-items: center;
  gap: 11px;
  padding-bottom: 12px;
}

.login-ledger-symbol {
  display: grid;
  width: 31px;
  height: 31px;
  place-items: center;
  border-radius: 8px;
  background: var(--login-accent);
  color: var(--login-navy);
}

.login-ledger-symbol svg {
  width: 17px;
  height: 17px;
}

.login-ledger-title {
  display: grid;
  flex: 1;
  gap: 5px;
}

.login-ledger-title i,
.login-ledger-row i,
.login-ledger-row b,
.login-ledger-columns span,
.login-ledger-footer i {
  display: block;
  border-radius: 99px;
  background: rgb(255 255 255 / 0.68);
}

.login-ledger-title i:first-child {
  width: 82px;
  height: 6px;
}

.login-ledger-title i:last-child {
  width: 54px;
  height: 4px;
  opacity: 0.55;
}

.login-ledger-menu {
  display: flex;
  gap: 3px;
}

.login-ledger-menu i {
  width: 3px;
  height: 3px;
  border-radius: 50%;
  background: rgb(255 255 255 / 0.65);
}

.login-ledger-rule {
  height: 1px;
  margin-bottom: 12px;
  background: rgb(255 255 255 / 0.22);
}

.login-ledger-columns,
.login-ledger-row {
  display: grid;
  grid-template-columns: 1.1fr 1fr 0.55fr;
  align-items: center;
  gap: 12px;
}

.login-ledger-columns {
  grid-template-columns: 1.1fr 1fr 0.55fr 0.55fr;
  padding: 0 0 7px;
}

.login-ledger-columns span {
  height: 4px;
  opacity: 0.48;
}

.login-ledger-row {
  min-height: 25px;
  border-top: 1px solid rgb(255 255 255 / 0.12);
}

.login-ledger-row i,
.login-ledger-row b {
  height: 4px;
  opacity: 0.66;
}

.login-ledger-row i:nth-child(2) {
  width: 74%;
  opacity: 0.4;
}

.login-ledger-row b {
  width: 70%;
  justify-self: end;
  background: var(--login-accent);
  opacity: 0.82;
}

.login-ledger-footer {
  display: flex;
  justify-content: space-between;
  padding-top: 11px;
  border-top: 1px solid rgb(255 255 255 / 0.2);
}

.login-ledger-footer i {
  width: 42px;
  height: 5px;
  opacity: 0.75;
}

.login-ledger-footer i:last-child {
  width: 66px;
  background: var(--login-accent);
}

.login-illustration-accent {
  position: absolute;
  right: 12%;
  bottom: 18px;
  width: 12px;
  height: 12px;
  border: 2px solid var(--login-accent);
  border-radius: 50%;
  box-shadow: 0 0 0 7px rgb(242 162 58 / 0.12);
}

.login-story-foot {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  color: rgb(255 255 255 / 0.78);
  font-size: 0.76rem;
}

.login-story-foot-icon {
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  border: 1px solid rgb(255 255 255 / 0.24);
  border-radius: 8px;
  color: var(--login-accent);
}

.login-story-foot-icon svg {
  width: 15px;
  height: 15px;
}

.login-panel {
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 0;
  padding: clamp(28px, 5vw, 68px);
  border: 1px solid var(--line);
  border-radius: 22px;
  background: var(--surf);
  box-shadow: 0 14px 42px color-mix(in oklab, var(--ink) 5%, transparent);
}

.login-form-wrap {
  width: 100%;
  max-width: 390px;
}

.login-heading {
  margin-bottom: 2rem;
}

.login-heading-kicker {
  color: var(--accent-text);
}

.login-heading h2 {
  margin: 0.45rem 0 0.45rem;
  color: var(--ink);
  font-size: clamp(1.85rem, 3vw, 2.35rem);
  font-weight: 680;
  letter-spacing: -0.045em;
  line-height: 1.15;
}

.login-heading > p:last-child {
  margin: 0;
  color: var(--mut);
  font-size: 0.9rem;
  line-height: 1.6;
}

.login-form {
  display: grid;
  gap: 1.3rem;
}

.login-field {
  display: grid;
  gap: 0.48rem;
}

.login-field label {
  color: var(--ink);
  font-size: 0.8rem;
  font-weight: 650;
}

.login-label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.login-input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}

.login-input-icon {
  position: absolute;
  left: 14px;
  width: 17px;
  height: 17px;
  color: var(--mut);
  pointer-events: none;
}

.login-input-wrap input {
  width: 100%;
  min-height: 51px;
  padding: 0 44px;
  border: 1px solid var(--line-strong);
  border-radius: 9px;
  background: var(--surf);
  color: var(--ink);
  font: inherit;
  font-size: 0.88rem;
  transition: border-color 140ms ease, box-shadow 140ms ease, background 140ms ease;
}

.login-input-wrap input:hover:not(:disabled) {
  border-color: var(--brand);
}

.login-input-wrap input:focus {
  outline: none;
  border-color: var(--brand);
  box-shadow: 0 0 0 3px color-mix(in oklab, var(--brand) 18%, transparent);
}

.login-input-wrap input::placeholder {
  color: var(--mut);
  opacity: 0.78;
}

.login-input-wrap input:disabled {
  cursor: not-allowed;
  opacity: 0.64;
}

.login-password-toggle {
  position: absolute;
  right: 7px;
  display: grid;
  width: 38px;
  height: 38px;
  place-items: center;
  border: 0;
  border-radius: 7px;
  background: transparent;
  color: var(--mut);
}

.login-password-toggle:hover:not(:disabled) {
  background: var(--soft);
  color: var(--ink);
}

.login-password-toggle svg {
  width: 17px;
  height: 17px;
}

.login-submit {
  display: inline-flex;
  min-height: 52px;
  align-items: center;
  justify-content: center;
  gap: 0.65rem;
  margin-top: 0.3rem;
  padding: 0.75rem 1rem;
  border: 1px solid var(--brand);
  border-radius: 9px;
  background: var(--brand);
  color: var(--brandtx);
  font: inherit;
  font-size: 0.88rem;
  font-weight: 700;
  box-shadow: 0 7px 16px color-mix(in oklab, var(--brand) 20%, transparent);
  transition: transform 140ms ease, box-shadow 140ms ease, opacity 140ms ease;
}

.login-submit:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 10px 22px color-mix(in oklab, var(--brand) 25%, transparent);
}

.login-submit:active:not(:disabled) {
  transform: translateY(0);
}

.login-submit:disabled {
  cursor: wait;
  opacity: 0.65;
  box-shadow: none;
}

.login-submit svg {
  width: 17px;
  height: 17px;
}

.login-spinner {
  animation: login-spin 900ms linear infinite;
}

.login-notice,
.login-error {
  display: flex;
  align-items: flex-start;
  gap: 0.7rem;
  margin: -0.65rem 0 1.2rem;
  padding: 0.85rem 0.95rem;
  border: 1px solid var(--line);
  border-radius: 9px;
  background: var(--soft);
  color: var(--ink);
  font-size: 0.78rem;
  line-height: 1.5;
}

.login-error {
  border-color: color-mix(in oklab, var(--neg) 36%, var(--line));
  background: color-mix(in oklab, var(--neg) 8%, var(--surf));
  color: var(--neg);
}

.login-notice > svg,
.login-error > svg {
  width: 17px;
  height: 17px;
  flex: 0 0 auto;
  margin-top: 1px;
}

.login-notice p,
.login-error p {
  margin: 0;
}

.login-error strong {
  display: block;
  margin-bottom: 0.1rem;
  font-size: 0.8rem;
}

.login-security-note {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  margin-top: 1.65rem;
  padding-top: 1.1rem;
  border-top: 1px solid var(--line);
  color: var(--mut);
}

.login-security-note svg {
  width: 15px;
  height: 15px;
  flex: 0 0 auto;
  margin-top: 2px;
  color: var(--pos);
}

.login-security-note p {
  margin: 0;
  font-size: 0.72rem;
  line-height: 1.55;
}

.login-footer {
  display: flex;
  width: min(1180px, calc(100% - 64px));
  justify-content: space-between;
  gap: 1rem;
  margin: 0 auto;
  padding: 0.2rem 0 22px;
  color: var(--mut);
  font-size: 0.7rem;
}

.login-theme-toggle:focus-visible,
.login-password-toggle:focus-visible,
.login-submit:focus-visible {
  outline: 3px solid var(--focus-color);
  outline-offset: 3px;
}

@keyframes login-spin {
  to { transform: rotate(360deg); }
}

@media (max-width: 900px) {
  .login-main {
    width: min(740px, calc(100% - 40px));
    grid-template-columns: minmax(0, 1fr);
    gap: 18px;
    padding-top: 6px;
  }

  .login-story {
    min-height: 0;
    padding: 30px 34px;
    border-radius: 18px;
  }

  .login-story h1 {
    max-width: 560px;
    margin-top: 0.65rem;
    font-size: clamp(1.9rem, 6vw, 2.7rem);
  }

  .login-story-description {
    max-width: 560px;
    font-size: 0.88rem;
  }

  .login-illustration {
    position: absolute;
    right: 2%;
    bottom: -22px;
    width: 38%;
    min-height: 190px;
    opacity: 0.55;
  }

  .login-story-foot {
    display: none;
  }

  .login-panel {
    padding: 34px;
    border-radius: 18px;
  }

  .login-form-wrap {
    max-width: 560px;
  }
}

@media (max-width: 560px) {
  .login-topbar,
  .login-main,
  .login-footer {
    width: calc(100% - 32px);
  }

  .login-topbar {
    min-height: 70px;
  }

  .login-brand-mark {
    width: 38px;
    height: 38px;
    border-radius: 10px;
  }

  .login-theme-toggle {
    width: 42px;
    height: 42px;
    justify-content: center;
    padding: 0;
  }

  .login-theme-toggle span {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
  }

  .login-main {
    gap: 14px;
    padding-top: 2px;
    padding-bottom: 20px;
  }

  .login-story {
    min-height: 0;
    padding: 24px 23px;
    border-radius: 15px;
  }

  .login-eyebrow {
    font-size: 0.58rem;
  }

  .login-story h1 {
    max-width: 320px;
    margin: 0.55rem 0 0;
    font-size: clamp(1.6rem, 8vw, 2rem);
  }

  .login-story-description {
    display: none;
  }

  .login-illustration {
    display: none;
  }

  .login-panel {
    padding: 26px 21px;
    border-radius: 15px;
  }

  .login-heading {
    margin-bottom: 1.55rem;
  }

  .login-heading h2 {
    font-size: 1.8rem;
  }

  .login-form {
    gap: 1.1rem;
  }

  .login-input-wrap input {
    min-height: 49px;
    font-size: 0.84rem;
  }

  .login-submit {
    min-height: 50px;
  }

  .login-footer {
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    padding-bottom: 14px;
    text-align: center;
  }
}

@media (prefers-reduced-motion: reduce) {
  .login-page *,
  .login-page *::before,
  .login-page *::after {
    scroll-behavior: auto !important;
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}
</style>
