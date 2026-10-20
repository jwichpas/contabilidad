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
  <div
    class="min-h-screen w-full flex flex-col justify-center items-center bg-bg text-foreground p-4 transition-colors duration-200 relative overflow-hidden font-sans"
  >
    <!-- Elementos decorativos sutiles de fondo con gradientes oficiales -->
    <div
      class="absolute -top-40 -right-40 w-96 h-96 rounded-full bg-brand/10 blur-3xl pointer-events-none"
    />
    <div
      class="absolute -bottom-40 -left-40 w-96 h-96 rounded-full bg-soft/40 blur-3xl pointer-events-none"
    />

    <!-- Botón flotante para alternar tema -->
    <div class="absolute top-6 right-6 z-10">
      <button
        id="th"
        type="button"
        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md border border-accent bg-surf backdrop-blur text-xs font-medium text-foreground hover:bg-soft transition-colors shadow-xs"
        @click="emit('toggle-dark')"
      >
        <Moon v-if="!props.isDarkMode" class="w-3.5 h-3.5 text-accent" />
        <Sun v-else class="w-3.5 h-3.5 text-accent" />
        <span>{{ props.isDarkMode ? 'Modo Claro' : 'Modo Oscuro' }}</span>
      </button>
    </div>

    <!-- Contenedor Principal de la Tarjeta de Acceso -->
    <div class="w-full max-w-md relative z-10">
      <div
        class="panel bg-surf border border-line p-8 sm:p-10 shadow-card"
      >
        <!-- Encabezado y Marca -->
        <div class="text-center mb-8">
          <div
            class="inline-flex items-center justify-center w-12 h-12 rounded-lg bg-brand text-white shadow-xs mb-3"
          >
            <ShieldCheck class="w-7 h-7" />
          </div>

          <h1
            class="text-xl font-semibold tracking-tight text-foreground flex items-center justify-center gap-2"
          >
            <span>ContaPerú · ERP Contable</span>
          </h1>

          <p class="text-xs text-muted mt-1">
            Sistema Multi-Tenant Bimoneda · NIIF 18/19 & SUNAT 2026
          </p>

        </div>

        <!-- Sesión caducada/revocada por HTTP 401; no muestra tokens ni secretos. -->
        <div
          v-if="sessionNotice"
          class="mb-5 flex items-start gap-2.5 rounded-md border border-line bg-soft p-3 text-xs text-foreground"
          role="alert"
        >
          <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-muted" />
          <p>{{ sessionNotice }}</p>
        </div>

        <!-- Alerta de Error de Acceso -->
        <div
          v-if="localError"
          class="mb-5 p-3 rounded-md bg-neg/10 border border-neg/30 text-neg flex items-start gap-2.5 text-xs"
          role="alert"
        >
          <AlertTriangle class="w-4 h-4 text-neg shrink-0 mt-0.5" />
          <div class="flex-1 leading-relaxed">
            <p class="font-semibold">Error de autenticación</p>
            <p class="mt-0.5 text-neg opacity-90">{{ localError }}</p>
          </div>
        </div>

        <!-- Formulario de Credenciales -->
        <form class="space-y-4" @submit.prevent="handleLogin">
          <!-- Campo: Correo Electrónico -->
          <div>
            <label
              for="login-email"
              class="block text-xs font-semibold text-muted mb-1"
            >
              Correo Institucional
            </label>
            <div class="relative">
              <div
                class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-muted"
              >
                <Mail class="w-4 h-4" />
              </div>
              <input
                id="login-email"
                v-model="email"
                type="email"
                required
                autocomplete="username"
                placeholder="usuario@empresa.com.pe"
                class="w-full pl-9 pr-3 py-2 rounded-md text-xs bg-surf border border-line text-foreground placeholder-muted focus:outline-none focus:ring-1 focus:ring-brand transition-all"
                :disabled="isLoading"
              />
            </div>
          </div>

          <!-- Campo: Contraseña -->
          <div>
            <div class="flex items-center justify-between mb-1">
              <label
                for="login-password"
                class="block text-xs font-semibold text-muted"
              >
                Contraseña
              </label>
              <span class="text-[10px] text-muted font-mono">
                Auditado (8.15)
              </span>
            </div>
            <div class="relative">
              <div
                class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-muted"
              >
                <Lock class="w-4 h-4" />
              </div>
              <input
                id="login-password"
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                required
                autocomplete="current-password"
                placeholder="••••••••••••"
                class="w-full pl-9 pr-10 py-2 rounded-md text-xs bg-surf border border-line text-foreground placeholder-muted focus:outline-none focus:ring-1 focus:ring-brand transition-all font-mono"
                :disabled="isLoading"
              />
              <button
                type="button"
                class="absolute inset-y-0 right-0 pr-3 flex items-center text-muted hover:text-foreground transition-colors cursor-pointer"
                tabindex="-1"
                @click="showPassword = !showPassword"
              >
                <EyeOff v-if="showPassword" class="w-4 h-4" />
                <Eye v-else class="w-4 h-4" />
              </button>
            </div>
          </div>

          <p class="text-[11px] leading-relaxed text-muted">
            Por seguridad, la sesión de API tiene una duración máxima de 12 horas.
          </p>

          <!-- Botón de Envío -->
          <button
            type="submit"
            :disabled="isLoading"
            class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-md text-xs font-semibold text-white bg-brand hover:opacity-95 focus:outline-none focus:ring-2 focus:ring-brand transition-all disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
          >
            <Loader2 v-if="isLoading" class="w-4 h-4 animate-spin text-white" />
            <KeyRound v-else class="w-4 h-4 text-white" />
            <span>{{ isLoading ? 'Verificando con Servidor...' : 'Ingresar al Sistema' }}</span>
          </button>
        </form>

        <!-- Pie de Información de Seguridad ISO 27001 -->
        <div class="mt-4 text-center">
          <p class="text-[10px] text-muted leading-relaxed">
            Acceso restringido a personal autorizado. La aplicación registra eventos de autenticación.
          </p>
        </div>
      </div>
    </div>

    <!-- Footer Copyright -->
    <div class="mt-6 text-center text-xs text-muted relative z-10">
      &copy; 2026 ERP Contable · República del Perú
    </div>
  </div>
</template>
