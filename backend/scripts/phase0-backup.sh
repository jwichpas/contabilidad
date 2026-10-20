#!/usr/bin/env bash
set -euo pipefail
umask 077

# Fase 0: exportación NO destructiva del esquema de aplicación (public).
# No crea bases ni altera tablas. Tampoco ejecuta una restauración.
project_ref='bnjwxphwtkhblvhsnylj'
host="${DB_MIGRATIONS_HOST:-}"
user="${DB_MIGRATIONS_USERNAME:-}"
port="${DB_MIGRATIONS_PORT:-5432}"
database="${DB_MIGRATIONS_DATABASE:-postgres}"
password="${DB_MIGRATIONS_PASSWORD:-}"

if [[ "$host $user" != *"$project_ref"* ]]; then
  echo 'ERROR: conexión administrativa no identifica contabilidad-database.' >&2
  exit 2
fi
if [[ -z "$password" ]]; then
  echo 'ERROR: DB_MIGRATIONS_PASSWORD debe definirse en el entorno (nunca en Git).' >&2
  exit 2
fi
for cmd in pg_dump pg_restore sha256sum; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "ERROR: falta la herramienta $cmd." >&2
    exit 2
  fi
done

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
backup_dir="${BACKUP_DIR:-${HOME:?}/contabilidad-backups}"
mkdir -p "$backup_dir"
backup_dir="$(cd "$backup_dir" && pwd -P)"
case "$backup_dir" in
  "$repo_dir"|"$repo_dir"/*)
    echo 'ERROR: el respaldo debe almacenarse fuera del repositorio.' >&2
    exit 2
    ;;
esac
chmod 700 "$backup_dir"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
archive="$backup_dir/contabilidad-public-$stamp.dump"
if [[ -e "$archive" ]]; then
  echo 'ERROR: ya existe un respaldo con este nombre; no se sobrescribirá.' >&2
  exit 2
fi
tmp="$(mktemp "$backup_dir/.phase0-XXXXXX")"
trap 'rm -f "$tmp" "$tmp.list"' EXIT

PGHOST="$host" PGPORT="$port" PGDATABASE="$database" PGUSER="$user" \
  PGPASSWORD="$password" PGSSLMODE="${DB_MIGRATIONS_SSLMODE:-require}" \
  pg_dump --no-password --schema=public --format=custom --no-owner --file="$tmp"

pg_restore --list "$tmp" > "$tmp.list"
if [[ ! -s "$tmp" || ! -s "$tmp.list" ]]; then
  echo 'ERROR: el archivo del respaldo o su índice están vacíos.' >&2
  exit 1
fi
mv "$tmp" "$archive"
mv "$tmp.list" "$archive.list"
sha256sum "$archive" > "$archive.sha256"
chmod 600 "$archive" "$archive.list" "$archive.sha256"
echo "Respaldo de public exportado (NO restaurado): $archive"
echo 'Verificado: pg_restore --list y SHA-256. Falta prueba de recuperación completa.'
