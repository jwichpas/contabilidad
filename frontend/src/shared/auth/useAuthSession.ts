import { computed, shallowRef } from 'vue';
import { asCompanyId, type CompanyId } from '@/modules/accounting/types/accountingAccount.types';
import { COMPANY_ONBOARDING_DRAFT_KEY } from '@/modules/company/types/companyOnboarding.types';
import { isCurrentBearer, reportUnauthorizedBearer, setUnauthorizedHandler } from './sessionInvalidation';

export interface AuthUser {
  readonly id: number;
  readonly uuid: string;
  readonly name: string;
  readonly email: string;
  readonly current_company_id: number;
}

export interface UserCompany {
  readonly id: CompanyId;
  readonly uuid: string;
  readonly tax_id: string;
  readonly business_name: string;
  readonly legal_address: string;
  readonly fiscal_regime?: string;
  readonly role?: string;
}

export interface LoginResponseData {
  readonly token: string;
  readonly user: AuthUser;
  readonly companies?: readonly UserCompany[];
}

const STORAGE_TOKEN_KEY = 'erp_auth_token_iso27001';
const STORAGE_USER_KEY = 'erp_auth_user_iso27001';
const STORAGE_COMPANIES_KEY = 'erp_auth_companies_iso27001';
const STORAGE_DRAFT_OWNER_KEY = 'erp_company_onboarding_draft_owner';

const accessToken = shallowRef<string | null>(sessionStorage.getItem(STORAGE_TOKEN_KEY));
const currentUser = shallowRef<AuthUser | null>(
  (() => {
    const raw = sessionStorage.getItem(STORAGE_USER_KEY);
    if (!raw) return null;
    try {
      return JSON.parse(raw) as AuthUser;
    } catch {
      return null;
    }
  })(),
);

const userCompanies = shallowRef<readonly UserCompany[]>(
  (() => {
    const raw = sessionStorage.getItem(STORAGE_COMPANIES_KEY);
    if (!raw) return [];
    try {
      const parsed = JSON.parse(raw) as UserCompany[];
      return parsed.map((c) => ({ ...c, id: asCompanyId(c.id) }));
    } catch {
      return [];
    }
  })(),
);

const activeCompanyId = shallowRef<CompanyId>(
  currentUser.value?.current_company_id
    ? asCompanyId(currentUser.value.current_company_id)
    : asCompanyId(1),
);

const isLoading = shallowRef(false);
const authError = shallowRef<string | null>(null);
const isLiveConnected = shallowRef(false);
const lastAuditTimestamp = shallowRef<string | null>(null);
const sessionNotice = shallowRef<string | null>(null);

/**
 * El vencimiento de Bearer es distinto del logout deliberado.
 * Un borrador puede conservarse solo para el mismo usuario autenticado.
 */
function clearLocalSession(expired: boolean): void {
  const previousUserId = currentUser.value?.id;
  if (expired && previousUserId && sessionStorage.getItem(COMPANY_ONBOARDING_DRAFT_KEY)) {
    sessionStorage.setItem(STORAGE_DRAFT_OWNER_KEY, String(previousUserId));
  } else if (!expired) {
    sessionStorage.removeItem(COMPANY_ONBOARDING_DRAFT_KEY);
    sessionStorage.removeItem(STORAGE_DRAFT_OWNER_KEY);
  }

  accessToken.value = null;
  currentUser.value = null;
  userCompanies.value = [];
  activeCompanyId.value = asCompanyId(1);
  isLiveConnected.value = false;
  lastAuditTimestamp.value = null;
  isLoading.value = false;
  sessionStorage.removeItem(STORAGE_TOKEN_KEY);
  sessionStorage.removeItem(STORAGE_USER_KEY);
  sessionStorage.removeItem(STORAGE_COMPANIES_KEY);
  sessionNotice.value = expired
    ? 'Tu sesión de 12 horas venció o fue revocada. Inicia sesión nuevamente para continuar.'
    : null;
}

// Una respuesta tardía de un token anterior no puede cerrar una sesión nueva.
setUnauthorizedHandler((requestToken) => {
  if (isCurrentBearer(accessToken.value, requestToken)) {
    clearLocalSession(true);
  }
});

/**
 * Gestor de Sesión y Autenticación Segura (ISO/IEC 27001:2023 Control 8.5).
 * Maneja tokens de portador (Sanctum Bearer) en memoria y sessionStorage,
 * con revocación explícita y auditoría de eventos.
 */
export function useAuthSession() {
  const isAuthenticated = computed(() => accessToken.value !== null && accessToken.value.trim() !== '');

  /**
   * Inicio de sesión seguro contra /api/v1/auth/login
   */
  async function login(email: string, password: string): Promise<boolean> {
    isLoading.value = true;
    authError.value = null;

    try {
      const response = await fetch('/api/v1/auth/login', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          email,
          password,
          device_name: 'erp-spa-iso27001',
        }),
      });

      if (!response.ok) {
        const errorJson = await response.json().catch(() => ({}));
        throw new Error(errorJson.message ?? 'Credenciales de acceso incorrectas.');
      }

      const json = await response.json();
      const data = json.data as LoginResponseData;

      // Nunca exponer el borrador de una persona a otro usuario.
      const draftOwner = sessionStorage.getItem(STORAGE_DRAFT_OWNER_KEY);
      if (sessionStorage.getItem(COMPANY_ONBOARDING_DRAFT_KEY)
        && draftOwner !== String(data.user.id)) {
        sessionStorage.removeItem(COMPANY_ONBOARDING_DRAFT_KEY);
      }
      sessionStorage.removeItem(STORAGE_DRAFT_OWNER_KEY);
      accessToken.value = data.token;
      currentUser.value = data.user;
      sessionNotice.value = null;
      activeCompanyId.value = asCompanyId(data.user.current_company_id ?? 1);
      isLiveConnected.value = true;
      lastAuditTimestamp.value = new Date().toISOString();

      if (data.companies && Array.isArray(data.companies)) {
        const parsed = data.companies.map((c) => ({
          ...c,
          id: asCompanyId(c.id),
        }));
        userCompanies.value = parsed;
        sessionStorage.setItem(STORAGE_COMPANIES_KEY, JSON.stringify(parsed));
      } else {
        userCompanies.value = [];
        sessionStorage.removeItem(STORAGE_COMPANIES_KEY);
      }

      sessionStorage.setItem(STORAGE_TOKEN_KEY, data.token);
      sessionStorage.setItem(STORAGE_USER_KEY, JSON.stringify(data.user));

      return true;
    } catch (e) {
      authError.value = e instanceof Error ? e.message : 'Error al autenticar.';
      isLiveConnected.value = false;
      return false;
    } finally {
      isLoading.value = false;
    }
  }

  /**
   * Consulta las empresas asignadas al usuario autenticado desde PostgreSQL
   */
  async function fetchUserCompanies(): Promise<readonly UserCompany[]> {
    const token = accessToken.value;
    if (!token) return [];

    try {
      const response = await fetch('/api/v1/tenant/companies', {
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      if (accessToken.value !== token) return userCompanies.value;
      if (response.ok) {
        const json = await response.json();
        const companies = (json.data as UserCompany[]).map((c) => ({
          ...c,
          id: asCompanyId(c.id),
        }));
        userCompanies.value = companies;
        sessionStorage.setItem(STORAGE_COMPANIES_KEY, JSON.stringify(companies));
        isLiveConnected.value = true;
        return companies;
      }
      if (response.status === 401) {
        reportUnauthorizedBearer(token);
        return [];
      }
      isLiveConnected.value = false;
    } catch {
      // Un problema de red no implica que el Bearer haya caducado.
      isLiveConnected.value = false;
    }

    return userCompanies.value;
  }

  /**
   * Incorpora al selector la empresa recién confirmada por el backend.
   * No crea una empresa localmente: exige respuesta del endpoint POST autenticado.
   */
  function registerCreatedCompany(company: UserCompany): void {
    if (!isAuthenticated.value) return;
    const rest = userCompanies.value.filter(existing => existing.id !== company.id);
    userCompanies.value = [...rest, company];
    sessionStorage.setItem(STORAGE_COMPANIES_KEY, JSON.stringify(userCompanies.value));
  }

  /**
   * Cierre de sesión y revocación del token en base de datos
   */
  async function logout(): Promise<void> {
    const token = accessToken.value;
    if (token) {
      try {
        await fetch('/api/v1/auth/logout', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
            'X-Requested-With': 'XMLHttpRequest',
          },
        });
      } catch {
        // Silencioso ante fallo de red durante logout
      }
    }

    clearLocalSession(false);
  }

  /**
   * Conmutación de tenant activo con validación en servidor
   */
  async function switchCompany(newCompanyId: CompanyId): Promise<boolean> {
    const token = accessToken.value;
    if (!token) return false;

    isLoading.value = true;
    try {
      const response = await fetch('/api/v1/tenant/switch-company', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
          'X-Requested-With': 'XMLHttpRequest',
          'X-Company-Id': String(newCompanyId),
        },
        body: JSON.stringify({ company_id: Number(newCompanyId) }),
      });

      if (accessToken.value !== token) return false;
      if (response.status === 401) {
        reportUnauthorizedBearer(token);
        return false;
      }
      if (!response.ok) {
        throw new Error('No fue posible conmutar la empresa activa.');
      }

      activeCompanyId.value = newCompanyId;
      if (currentUser.value) {
        currentUser.value = {
          ...currentUser.value,
          current_company_id: Number(newCompanyId),
        };
        sessionStorage.setItem(STORAGE_USER_KEY, JSON.stringify(currentUser.value));
      }
      return true;
    } catch (e) {
      authError.value = e instanceof Error ? e.message : 'Error al conmutar empresa.';
      return false;
    } finally {
      isLoading.value = false;
    }
  }

  /**
   * Inicialización de la sesión de trabajo (ISO/IEC 27001:2023 Control 8.5).
   * Verifica si existe una sesión previa activa en sessionStorage.
   * Si no existe, no realiza bypass automático: requiere que el usuario ingrese sus credenciales.
   */
  async function ensureAuthenticated(): Promise<boolean> {
    if (isAuthenticated.value && accessToken.value) {
      // El caché no demuestra que el Bearer continúe vigente en Sanctum.
      await fetchUserCompanies();
      return isAuthenticated.value;
    }

    isLiveConnected.value = false;
    return false;
  }

  return {
    accessToken,
    currentUser,
    userCompanies,
    activeCompanyId,
    isAuthenticated,
    isLoading,
    authError,
    isLiveConnected,
    lastAuditTimestamp,
    sessionNotice,
    login,
    logout,
    switchCompany,
    ensureAuthenticated,
    fetchUserCompanies,
    registerCreatedCompany,
  };
}
