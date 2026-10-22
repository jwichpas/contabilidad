<?php

declare(strict_types=1);

/**
 * Read-only connection configuration diagnostic for Neon DEV.
 *
 * No database connection, no SQL, no filesystem writes.
 * Never prints connection strings, hosts, users, passwords, tokens or
 * hashes. Reports solely PASS/FAIL for each deterministic guard.
 *
 * Usage: php scripts/phase2-neon-connection-diagnostic.php --check
 */
$root=dirname(__DIR__);
if(($argv[1]??null)!=='--check'||$argc!==2){
    fwrite(STDERR,"[USO] php scripts/phase2-neon-connection-diagnostic.php --check\n");
    exit(2);
}
$checks=[];
$add=static function(string $label,bool $valid,string $hint='')use(&$checks):void{
    $checks[]=[$label,$valid,$hint];
};
$dotenv=$root.'/.env';
$hasEnv=is_file($dotenv);
$add('Archivo privado backend/.env presente',$hasEnv,'Crear/configurar backend/.env en el equipo, sin subirlo al repositorio.');
$cacheFile=$root.'/bootstrap/cache/config.php';
$add('Configuración Laravel no cacheada',!is_file($cacheFile),
    'Si hay cache, ejecutar php artisan config:clear cuando sea seguro.');
$names=[];$repeated=[];
if($hasEnv){
    foreach(file($dotenv,FILE_IGNORE_NEW_LINES)?:[] as $line){
        if(!preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/',$line,$m))continue;
        $key=$m[1];
        if(!(str_starts_with($key,'DB_')||str_starts_with($key,'NEON_')
            ||in_array($key,['APP_ENV','APP_KEY','DATABASE_URL'],true)))continue;
        if(isset($names[$key]))$repeated[$key]=true;
        $names[$key]=true;
    }
}
$add('No hay variables DB/NEON/APP duplicadas',count($repeated)===0,
    'Dejar una sola definición activa por variable en backend/.env.');
$add('Dependencias Laravel instaladas',is_file($root.'/vendor/autoload.php'),
    'Ejecutar composer install según el README antes de validar.');
if(!is_file($root.'/vendor/autoload.php')){
    foreach($checks as [$label,$ok,$hint]){
        echo ($ok?'[PASS] ':'[FAIL] ').$label."\n";
        if(!$ok)echo '       Sugerencia: '.$hint."\n";
    }
    exit(1);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$host='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$admin=(array)config('database.connections.pgsql_migrations',[]);
$traffic=(array)config('database.connections.pgsql',[]);
$add('Entorno Laravel APP_ENV=local',$app->environment('local'),
    'Verificar APP_ENV=local, sin una variable de proceso sobrescribiéndolo.');
$add('DB_CONNECTION=pgsql',config('database.default')==='pgsql',
    'Configurar DB_CONNECTION=pgsql.');
$add('Declaración NEON_TEST_BRANCH_NAME correcta',
    env('NEON_TEST_BRANCH_NAME','')==='contabilidad-dev',
    'Configurar NEON_TEST_BRANCH_NAME=contabilidad-dev.');
$add('Declaración NEON_TEST_BRANCH_HOST del endpoint de desarrollo',
    env('NEON_TEST_BRANCH_HOST','')===$host,
    'Copiar la identidad de la rama DEV desde backend/.env.example (no production).');
$add('Conexión APP: host directo de la rama DEV',
    ($traffic['host']??null)===$host,
    'Revisar DB_HOST: no usar localhost, rama production ni sufijo -pooler.');
$add('Conexión ADMIN: host directo de la rama DEV',
    ($admin['host']??null)===$host,
    'Revisar DB_MIGRATIONS_HOST (host directo, no pooler).');
$add('APP/ADMIN: puerto 5432',
    (string)($traffic['port']??'')==='5432'
        &&(string)($admin['port']??'')==='5432',
    'Revisar DB_PORT y DB_MIGRATIONS_PORT.');
$add('APP/ADMIN: base neondb',
    ($traffic['database']??null)==='neondb'
        &&($admin['database']??null)==='neondb',
    'Revisar DB_DATABASE y DB_MIGRATIONS_DATABASE.');
$add('APP: rol de tráfico app_backend',
    ($traffic['username']??null)==='app_backend',
    'Revisar DB_USERNAME; nunca usar neondb_owner para tráfico del ERP.');
$add('ADMIN: rol propietario neondb_owner',
    ($admin['username']??null)==='neondb_owner',
    'Revisar DB_MIGRATIONS_USERNAME.');
$add('DB_URL/DATABASE_URL no intervienen',
    trim((string)($traffic['url']??''))==='',
    'Dejar DB_URL= y DATABASE_URL= vacíos; la URI prevalece sobre parámetros.');
$add('DB_MIGRATIONS_URL no interviene',
    trim((string)($admin['url']??''))==='',
    'Dejar DB_MIGRATIONS_URL= vacío para usar los parámetros por separado.');
$ssl=['require','verify-ca','verify-full'];
$add('SSL exigido en ambas conexiones',
    in_array((string)($traffic['sslmode']??''),$ssl,true)
        &&in_array((string)($admin['sslmode']??''),$ssl,true),
    'Revisar DB_SSLMODE y DB_MIGRATIONS_SSLMODE: require/verify-ca/verify-full.');
$add('Pooling desactivado para estos ensayos',
    !filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN),
    'Configurar NEON_USE_POOLING=false; no usar host -pooler.');
$add('Driver de caché Laravel configurado',
    is_string(config('cache.default')) && trim((string)config('cache.default'))!=='',
    'En backend/.env usar CACHE_STORE=file (o un cache store configurado).');
$add('Driver de sesión Laravel configurado',
    is_string(config('session.driver')) && trim((string)config('session.driver'))!=='',
    'En backend/.env usar SESSION_DRIVER=file (o un driver configurado).');
$add('Driver de cola Laravel configurado',
    is_string(config('queue.default')) && trim((string)config('queue.default'))!=='',
    'En backend/.env usar QUEUE_CONNECTION=sync durante desarrollo local.');
$add('Contraseña APP configurada (sin probar login)',
    is_string($traffic['password']??null)&&(string)$traffic['password']!=='',
    'Revisar DB_PASSWORD de app_backend en .env privado.');
$add('Contraseña ADMIN configurada (sin probar login)',
    is_string($admin['password']??null)&&(string)$admin['password']!=='',
    'Revisar DB_MIGRATIONS_PASSWORD en .env privado.');
$pass=(string)($traffic['password']??'');
$expected=(string)env('DB_APP_BACKEND_ROLE_PASSWORD','');
$add('Credencial de rol app_backend consistente para prueba HTTP',
    strlen($expected)>=20 && $pass!=='' && hash_equals($pass,$expected),
    'DB_APP_BACKEND_ROLE_PASSWORD debe coincidir con DB_PASSWORD. Nunca publicarlas.');
$key=(string)env('APP_KEY','');
$bytes=str_starts_with($key,'base64:')?base64_decode(substr($key,7),true):$key;
$add('APP_KEY válido de 32 bytes',
    is_string($bytes)&&strlen($bytes)===32,
    'Verificar APP_KEY local; generar mediante php artisan key:generate solo si corresponde.');
unset($key,$bytes,$pass,$expected);
$failed=0;
foreach($checks as [$label,$ok,$hint]){
    echo ($ok?'[PASS] ':'[FAIL] ').$label."\n";
    if(!$ok){$failed++;echo '       Sugerencia: '.$hint."\n";}
}
echo $failed===0
    ? "[PASS] Precondiciones locales coherentes; conexión/autenticación SQL NO verificadas.\n"
    : "[BLOQUEO] {$failed} condición(es) locales fallaron. No ejecutar --apply hasta corregirlas.\n";
echo "[SEGURIDAD] Diagnóstico sin SQL, conexiones, secretos ni escritura.\n";
exit($failed===0?0:1);
