#!/usr/bin/env bash
# F1.14 — Two-connection journal lock test. Local ephemeral Docker ONLY.
# Invoke: bash scripts/phase1-journal-concurrency-docker.sh
set -Eeuo pipefail
umask 077
# Optional F2.3 extension: two concurrent connections test the same
# fiscal-period FOR UPDATE used by posting and closing. Docker ONLY.
if [[ $# -gt 1 || ( $# -eq 1 && "${1:-}" != '--f23' ) ]]; then
  echo '[USO] bash scripts/phase1-journal-concurrency-docker.sh [--f23]' >&2
  exit 2
fi
if [[ "${1:-}" == '--f23' ]]; then
  export F23_PERIOD_CONCURRENCY=1
fi

backend="$(cd "$(dirname "$0")/.." && pwd)"
cd "$backend"
if [[ ! -f vendor/autoload.php || -f bootstrap/cache/config.php ]]; then
  echo '[BLOQUEO] Requiere Composer y configuracion no cacheada.' >&2; exit 1
fi
command -v docker >/dev/null || { echo '[BLOQUEO] Falta Docker.' >&2; exit 1; }
command -v php >/dev/null || { echo '[BLOQUEO] Falta PHP.' >&2; exit 1; }
if [[ "$(docker info --format '{{.OSType}}' 2>/dev/null)" != linux ]]; then
  echo '[BLOQUEO] Docker debe ejecutar contenedores Linux.' >&2; exit 1
fi
suffix="$(php -r 'echo bin2hex(random_bytes(7));')"
dockerName="f114-$suffix"
adminPassword="$(php -r 'echo bin2hex(random_bytes(24));')"
appPassword="$(php -r 'echo bin2hex(random_bytes(24));')"
export F1_GUARD_TOKEN="$(php -r 'echo bin2hex(random_bytes(16));')"
started=0
cleanup() {
  local status=$?
  trap - EXIT HUP INT TERM
  if [[ "$started" == 1 ]]; then
    if docker rm -f "$dockerName" >/dev/null 2>&1; then
      echo '[LIMPIEZA] Docker PostgreSQL efimero eliminado.'
    else
      echo '[WARN] No se pudo confirmar limpieza del contenedor Docker.' >&2
      status=1
    fi
  fi
  exit "$status"
}
trap cleanup EXIT HUP INT TERM

echo '[AISLAMIENTO] PostgreSQL 18 temporal, puerto solo loopback.'
docker run --detach --name "$dockerName" \
  --publish '127.0.0.1::5432' \
  --env "POSTGRES_PASSWORD=$adminPassword" \
  --env 'POSTGRES_DB=contabilidad_ci' postgres:18-alpine >/dev/null
started=1
portLine="$(docker port "$dockerName" '5432/tcp')"
if [[ ! "$portLine" =~ ^127\.0\.0\.1:([0-9]{2,5})$ ]]; then
  echo '[BLOQUEO] Puerto de Docker no es loopback.' >&2; exit 1
fi
port="${BASH_REMATCH[1]}"
if [[ "$port" == 5432 ]]; then
  echo '[BLOQUEO] Puerto Docker no es dinamico.' >&2; exit 1
fi
ready=false
for _ in $(seq 1 45); do
  if docker exec "$dockerName" pg_isready -U postgres -d contabilidad_ci -h 127.0.0.1 >/dev/null 2>&1; then
    ready=true; break
  fi
  sleep 1
done
if [[ "$ready" != true ]]; then
  echo '[FAIL] PostgreSQL Docker no respondio.' >&2; exit 1
fi
docker exec "$dockerName" psql -U postgres -d contabilidad_ci -X \
  -v ON_ERROR_STOP=1 -q \
  -c "CREATE TABLE public.f1_ci_runtime_guard(token text NOT NULL);
      INSERT INTO public.f1_ci_runtime_guard(token) VALUES ('$F1_GUARD_TOKEN');
      CREATE ROLE anon NOLOGIN; CREATE ROLE authenticated NOLOGIN;" >/dev/null

# Environment variables override any real .env file in this process.
export APP_ENV=testing APP_DEBUG=false
export APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="$port"
export DB_DATABASE=contabilidad_ci DB_USERNAME=postgres
export DB_PASSWORD="$adminPassword" DB_URL='' DATABASE_URL=''
export DB_SSLMODE=disable DB_EMULATE_PREPARES=true
export DB_MIGRATIONS_HOST=127.0.0.1 DB_MIGRATIONS_PORT="$port"
export DB_MIGRATIONS_DATABASE=contabilidad_ci DB_MIGRATIONS_USERNAME=postgres
export DB_MIGRATIONS_PASSWORD="$adminPassword"
export DB_MIGRATIONS_SSLMODE=disable DB_MIGRATIONS_URL=''
export DB_APP_BACKEND_ROLE_PASSWORD="$appPassword"
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync

migrations=(
 '0001_01_00_000000_setup_extensions_and_app_backend_role'
 '0001_01_01_000000_create_companies_table'
 '0001_01_01_000001_create_users_and_company_user_tables'
 '0001_01_02_000000_create_accounting_accounts_table'
 '0001_01_02_000001_create_cost_centers_and_cash_flow_areas_tables'
 '0001_01_02_000002_create_currencies_and_sunat_tables'
 '0001_01_02_000003_create_accounting_standards_and_rubrics_tables'
 '0001_01_02_000004_create_company_settings_areas_and_entities_tables'
 '0001_01_03_000000_create_fiscal_periods_and_exchange_rates_tables'
 '0001_01_04_000000_create_journal_entries_tables'
 '0001_01_05_000000_create_accounting_account_templates_table'
 '0001_01_06_000000_create_audit_logs_table'
 '0001_01_07_000000_create_company_currencies_table'
 '0001_01_08_000000_optimize_rls_policies_and_tenant_function'
 '2026_10_21_010000_harden_postgres_function_search_path'
 '2026_10_21_020000_close_global_audit_log_read_leak'
)
serverVersion="$(docker exec "$dockerName" psql -U postgres -d contabilidad_ci \
 -X -A -t -v ON_ERROR_STOP=1 -c 'SHOW server_version_num')"
if [[ ! "$serverVersion" == 18???? ]]; then
  echo '[BLOQUEO] PostgreSQL no es 18.' >&2; exit 1
fi
echo '[MIGRACIONES] 16 migraciones portables solamente en Docker.'
for migration in "${migrations[@]}"; do
  if [[ ! -f "database/migrations/$migration.php" ]]; then
    echo '[FAIL] Inventario de schema cambiado.' >&2; exit 1
  fi
  if ! php artisan migrate --database=pgsql_migrations \
    --path="database/migrations/$migration.php" --force --no-interaction >/dev/null; then
    echo "[FAIL] Migracion Docker $migration" >&2; exit 1
  fi
  count="$(docker exec "$dockerName" psql -U postgres -d contabilidad_ci \
    -X -A -t -v ON_ERROR_STOP=1 \
    -c "SELECT count(*) FROM public.migrations WHERE migration='$migration'")"
  if [[ "$count" != 1 ]]; then
    echo '[FAIL] Migration sin registro.' >&2; exit 1
  fi
done
docker exec "$dockerName" psql -U postgres -d contabilidad_ci -X \
 -v ON_ERROR_STOP=1 -q \
 -c 'GRANT SELECT ON TABLE public.f1_ci_runtime_guard TO app_backend' >/dev/null

export DB_USERNAME=app_backend DB_PASSWORD="$appPassword"
php -l scripts/phase1-journal-concurrency-docker.php
php scripts/phase1-journal-concurrency-docker.php --probe=ephemeral-docker
echo '[PASS] F1.14: DOS sesiones, bloqueo FOR UPDATE, 55P03 y correlativos consecutivos.'
if [[ "${F23_PERIOD_CONCURRENCY:-}" == 1 ]]; then
  echo '[PASS] F2.3: concurrencia close/POST/reopen sobre dos conexiones verificada solo en Docker.'
fi
