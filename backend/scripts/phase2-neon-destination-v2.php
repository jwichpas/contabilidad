<?php

declare(strict_types=1);

/**
 * F2.2 versioned routing, Neon contabilidad-dev only.
 * --plan: no Laravel/SQL; --verify: SELECT only; --apply=contabilidad-dev:
 * additive migration and immutable DRAFT routes, never active posting.
 */
$backend = dirname(__DIR__);
$host = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$migration = '2026_10_22_110000_create_accounting_destination_rules_v2';
$path = 'database/portable_migrations/'.$migration.'.php';
$version = 'PCGE_2019_CC_V2';
$mode = $argv[1] ?? '--plan';
if ($argc > 2 || ! in_array($mode, ['--plan', '--verify', '--apply=contabilidad-dev'], true)) {
    fwrite(STDERR, "[FAIL] Uso: php scripts/phase2-neon-destination-v2.php --plan|--verify|--apply=contabilidad-dev\n");
    exit(2);
}
if (! is_file($backend.'/'.$path)) {
    fwrite(STDERR, "[FAIL] Falta migracion F2.2.\n");
    exit(1);
}
if ($mode === '--plan') {
    echo "[PLAN] Mantener PCGE global v1 y 433 codigos legacy por empresa sin modificaciones.\n";
    echo "[PLAN] Version v2: 227 cuentas operativas x 2 centros = 454 rutas DRAFT por empresa.\n";
    echo "[PLAN] CC-ADM: 941100/791000; CC-VTA: 951100/791000.\n";
    echo "[PLAN] Crear solo tablas de reglas versionadas y rutas privadas, con FK intra-tenant y RLS.\n";
    echo "[PLAN] POST automatico NO se activa; faltan cost_center_id, motor y pruebas de asiento.\n";
    echo "[DRY-RUN] Sin conexiones a base de datos.\n";
    exit(0);
}
if (! is_file($backend.'/.env') || is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[BLOQUEO] Falta .env privado o hay config cacheada.\n");
    exit(1);
}
$seen = [];
foreach (file($backend.'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (! preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/', $line, $m)) continue;
    $key = $m[1];
    if (! str_starts_with($key, 'DB_') && ! str_starts_with($key, 'NEON_')
        && ! in_array($key, ['APP_ENV', 'APP_KEY', 'DATABASE_URL'], true)) continue;
    if (isset($seen[$key])) {
        fwrite(STDERR, "[BLOQUEO] Variable privada duplicada.\n");
        exit(1);
    }
    $seen[$key] = true;
}
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin = (array) config('database.connections.pgsql_migrations', []);
$runtime = (array) config('database.connections.pgsql', []);
if (! $app->environment('local') || config('database.default') !== 'pgsql'
    || env('NEON_TEST_BRANCH_NAME','') !== 'contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST','') !== $host
    || (string) ($admin['host'] ?? '') !== $host
    || (string) ($runtime['host'] ?? '') !== $host
    || (string) ($admin['port'] ?? '') !== '5432'
    || (string) ($runtime['port'] ?? '') !== '5432'
    || (string) ($admin['database'] ?? '') !== 'neondb'
    || (string) ($runtime['database'] ?? '') !== 'neondb'
    || (string) ($admin['username'] ?? '') !== 'neondb_owner'
    || (string) ($runtime['username'] ?? '') !== 'app_backend'
    || trim((string) ($admin['url'] ?? '')) !== ''
    || trim((string) ($runtime['url'] ?? '')) !== ''
    || ! in_array((string) ($admin['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || ! in_array((string) ($runtime['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "[BLOQUEO] Solo Neon contabilidad-dev PG18 directo, neondb, TLS.\n");
    exit(1);
}
$db = \Illuminate\Support\Facades\DB::connection('pgsql_migrations');
try {
    $base = $db->selectOne(<<<'SQL'
        SELECT current_user AS db_user, current_database() AS db_name,
          current_setting('server_version_num')::integer AS pg_version,
          (SELECT count(*) FROM public.migrations)::integer AS migrations,
          (SELECT count(*) FROM public.migrations WHERE migration=?)::integer AS migration_present,
          (SELECT count(*) FROM public.reference_data_versions)::integer AS global_versions,
          (SELECT count(*) FROM public.accounting_account_templates)::integer AS templates,
          (SELECT count(*) FROM public.companies)::integer AS companies,
          (SELECT count(*) FROM public.users)::integer AS users,
          (SELECT count(*) FROM public.company_user)::integer AS members,
          (SELECT count(*) FROM public.journal_entries)::integer AS entries,
          to_regclass('public.accounting_destination_rule_versions') IS NOT NULL AS schema_present
        SQL, [$migration]);
    if ($base === null || $base->db_user !== 'neondb_owner' || $base->db_name !== 'neondb'
        || intdiv((int)$base->pg_version,10000) !== 18
        || ! in_array((int)$base->migrations,[18,19],true)
        || (int)$base->global_versions !== 1 || (int)$base->templates !== 3401
        || (int)$base->companies !== 3 || (int)$base->users !== 3
        || (int)$base->members !== 8 || (int)$base->entries !== 0
        || ((int)$base->migration_present === 1) !== (bool)$base->schema_present
        || (int)$base->migration_present !== (int)$base->migrations - 18) {
        throw new RuntimeException('Estado de referencia no autorizado.');
    }
    $summary = $db->select(<<<'SQL'
        SELECT c.id, c.tax_id,
          (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id)::integer AS accounts,
          (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id AND a.is_operational)::integer AS operational,
          (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id AND a.has_automatic_destination AND a.is_operational AND a.is_active AND length(a.code)=6)::integer AS sources,
          (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id AND a.has_automatic_destination AND NOT a.is_operational)::integer AS structural,
          (SELECT count(*) FROM public.cost_centers cc WHERE cc.company_id=c.id AND cc.is_active)::integer AS centers
        FROM public.companies c ORDER BY c.tax_id
        SQL);
    $expectedTax=['20999990011','20999990012','20999990013'];
    foreach ($summary as $i=>$s) {
        if (($expectedTax[$i] ?? null) !== (string)$s->tax_id
            || (int)$s->accounts !== 2966 || (int)$s->operational !== 1214
            || (int)$s->sources !== 227 || (int)$s->structural !== 206
            || (int)$s->centers !== 2) {
            throw new RuntimeException('Matriz base cambiada para empresa '.$s->tax_id);
        }
    }
    if (count($summary) !== 3) throw new RuntimeException('Empresas incompletas.');

    // Snapshot sorted by NATURAL codes; IDs are only local FK bindings.
    $routes = $db->select(<<<'SQL'
        SELECT c.id AS company_id,c.tax_id,
          a.id AS source_account_id,a.code AS source_code,
          cc.id AS cost_center_id,cc.code AS center_code,
          d.id AS debit_id,d.code AS debit_code,d.is_operational AS debit_operational,d.is_active AS debit_active,
          h.id AS credit_id,h.code AS credit_code,h.is_operational AS credit_operational,h.is_active AS credit_active
        FROM public.companies c
        JOIN public.accounting_accounts a ON a.company_id=c.id
            AND a.is_operational AND a.is_active AND a.has_automatic_destination AND length(a.code)=6
        JOIN public.cost_centers cc ON cc.company_id=c.id AND cc.is_active
        LEFT JOIN public.accounting_accounts d ON d.company_id=c.id AND d.id=cc.destination_debit_account_id
        LEFT JOIN public.accounting_accounts h ON h.company_id=c.id AND h.id=cc.destination_credit_account_id
        ORDER BY c.tax_id,a.code,cc.code
        SQL);
    $grouped = [];
    foreach ($routes as $r) {
        $wantedDebit = match ($r->center_code) {
            'CC-ADM' => '941100',
            'CC-VTA' => '951100',
            default => null,
        };
        if ($wantedDebit === null || $r->debit_code !== $wantedDebit || $r->credit_code !== '791000'
            || ! $r->debit_operational || ! $r->credit_operational
            || ! $r->debit_active || ! $r->credit_active
            || strlen((string)$r->debit_code) !== 6 || strlen((string)$r->credit_code) !== 6) {
            throw new RuntimeException('Destino operativo de centro de costos ambiguo.');
        }
        $grouped[(string)$r->company_id][] = $r;
    }
    foreach ($summary as $s) {
        if (count($grouped[(string)$s->id] ?? []) !== 454) {
            throw new RuntimeException('Cobertura de rutas no es 227 x 2.');
        }
    }
    echo "[PASS] Precondiciones: 3 empresas, 227 fuentes operativas + 206 estructurales por empresa, 2 centros.\n";

    if ($mode === '--apply=contabilidad-dev' && (int)$base->migrations === 18) {
        $exit=\Illuminate\Support\Facades\Artisan::call('migrate', [
            '--database'=>'pgsql_migrations',
            '--path'=>$path,
            '--force'=>true,
            '--no-interaction'=>true,
        ]);
        if ($exit !== 0) throw new RuntimeException('Migracion v2 no se completo.');
        echo "[PASS] Migracion aditiva F2.2 registrada.\n";
    }
    if ($mode === '--verify' && ! $base->schema_present) {
        echo "[PENDIENTE] No existe la matriz versionada; ninguna escritura.\n";
        exit(1);
    }

    if ($mode === '--apply=contabilidad-dev') {
        $db->transaction(function () use ($db, $summary, $grouped, $version): void {
            foreach ($summary as $s) {
                $source = $grouped[(string)$s->id];
                $sha = hash('sha256', implode("\n", array_map(
                    static fn ($r): string => $r->source_code.'|'.$r->center_code.'|'.$r->debit_code.'|'.$r->credit_code,
                    $source
                )));
                $existing = $db->table('accounting_destination_rule_versions')
                    ->where('company_id', $s->id)->where('version_key', $version)->first();
                if ($existing !== null) {
                    if ($existing->matrix_sha256 !== $sha || $existing->status !== 'DRAFT'
                        || (int)$existing->source_count !== 227 || (int)$existing->route_count !== 454) {
                        throw new RuntimeException('Version existente difiere del snapshot.');
                    }
                    continue;
                }
                $id = $db->table('accounting_destination_rule_versions')->insertGetId([
                    'company_id'=>$s->id,'version_key'=>$version,'strategy'=>'COST_CENTER',
                    'status'=>'DRAFT','source_count'=>227,'route_count'=>454,'matrix_sha256'=>$sha,
                ]);
                foreach (array_chunk($source, 100) as $chunk) {
                    $db->table('accounting_destination_routes')->insert(array_map(
                        static fn ($r): array => [
                            'company_id'=>$r->company_id,'rule_version_id'=>$id,
                            'source_account_id'=>$r->source_account_id,'cost_center_id'=>$r->cost_center_id,
                            'destination_debit_account_id'=>$r->debit_id,
                            'destination_credit_account_id'=>$r->credit_id,
                        ], $chunk
                    ));
                }
            }
        });
        echo "[PASS] Rutas F2.2 registradas como DRAFT (sin activar POST).\n";
    }

    // Compare exact row mappings, not merely counts.
    $installed = $db->select(<<<'SQL'
        SELECT v.company_id,v.version_key,v.strategy,v.status,v.source_count,v.route_count,v.matrix_sha256,
               r.source_account_id,r.cost_center_id,r.destination_debit_account_id,r.destination_credit_account_id
        FROM public.accounting_destination_rule_versions v
        LEFT JOIN public.accounting_destination_routes r
          ON r.company_id=v.company_id AND r.rule_version_id=v.id
        WHERE v.version_key=?
        SQL,[$version]);
    $found=[];
    foreach ($installed as $x) {
        $key=(string)$x->company_id;
        $found[$key]['meta']=$x;
        $found[$key]['rows'][]=$x;
    }
    foreach ($summary as $s) {
        $key=(string)$s->id;
        $wanted=$grouped[$key];
        $sha=hash('sha256', implode("\n",array_map(
            static fn ($r): string => $r->source_code.'|'.$r->center_code.'|'.$r->debit_code.'|'.$r->credit_code,$wanted
        )));
        $record=$found[$key] ?? null;
        if ($record === null || count($record['rows']) !== 454
            || $record['meta']->matrix_sha256 !== $sha
            || $record['meta']->strategy !== 'COST_CENTER'
            || $record['meta']->status !== 'DRAFT'
            || (int)$record['meta']->source_count !== 227
            || (int)$record['meta']->route_count !== 454) {
            throw new RuntimeException('Version F2.2 ausente o inconsistente.');
        }
        $set=[];
        foreach ($record['rows'] as $r) {
            $name=$r->source_account_id.'|'.$r->cost_center_id;
            if (isset($set[$name])) throw new RuntimeException('Duplicado en rutas.');
            $set[$name]=$r->destination_debit_account_id.'|'.$r->destination_credit_account_id;
        }
        foreach ($wanted as $r) {
            if (($set[$r->source_account_id.'|'.$r->cost_center_id]??null)
                !== $r->debit_id.'|'.$r->credit_id) {
                throw new RuntimeException('Destino distinto del centro de costos.');
            }
        }
        echo '[PASS] '.$s->tax_id.': version DRAFT '.$version.', 227 fuentes, 454 rutas, SHA-256 verificado.'.PHP_EOL;
    }
    echo "[PENDIENTE] No activar POST automático: falta cost_center_id, doble asiento y pruebas.\n";
    $db->disconnect();
} catch (Throwable $e) {
    // No revelar errores SQL ni credenciales.
    fwrite(STDERR, "[BLOQUEO] F2.2: validacion o instalacion incompleta; no usar migrate general.\n");
    exit(1);
}
