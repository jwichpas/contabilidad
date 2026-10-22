<?php

declare(strict_types=1);

/**
 * Credenciales SOLO para 3 usuarios DEMO existentes en Neon PG18 DEV.
 *
 * --plan: sin conexion ni escritura
 * --verify: lectura de usuarios DEMO y sus roles, SIN hashes ni contrasenas
 * --reset=contabilidad-dev --user=admin|contador|auditor:
 *   cambia UNICAMENTE el hash de la cuenta DEMO elegida.
 *   Genera 128 bits aleatorios en la maquina del operador y muestra
 *   la contrasena SOLO en esa terminal, DESPUES de COMMIT.
 *
 * NO crea usuarios, empresas, migraciones, tablas ni tokens.
 * NO almacena contrasenas en GitHub/.env ni acepta secretos por CLI.
 */
$root=dirname(__DIR__);
$mode=$argv[1]??'--plan';
$roleArg=$argv[2]??null;
$known=[
    'admin'=>['id'=>'3000000000201','email'=>'demo.admin@contabilidad.test','name'=>'Administrador Demo'],
    'contador'=>['id'=>'3000000000202','email'=>'demo.contador@contabilidad.test','name'=>'Contador Demo'],
    'auditor'=>['id'=>'3000000000203','email'=>'demo.auditor@contabilidad.test','name'=>'Auditor Demo'],
];

if($mode==='--plan' && $argc===2){
    echo "[PLAN] Neon DEV: 3 usuarios DEMO existentes, acceso Laravel Sanctum.\n";
    echo "[PLAN] --verify inspecciona las cuentas SIN revelar hashes.\n";
    echo "[PLAN] --reset=contabilidad-dev --user=admin cambia solo su hash.\n";
    echo "[PLAN] Contrasena de alta entropia generada y revelada en su terminal luego de COMMIT.\n";
    echo "[SEGURIDAD] Nunca publique la contrasena; no se genera en GitHub ni en el chat.\n";
    exit(0);
}
$reset=$mode==='--reset=contabilidad-dev';
if((!$reset && !($mode==='--verify' && $argc===2))
    ||($reset && ($argc!==3
        ||!is_string($roleArg)
        ||!preg_match('/^--user=(admin|contador|auditor)$/D',$roleArg,$match)))){
    fwrite(STDERR,
        "[USO] php scripts/phase2-neon-demo-login.php --verify\n"
        ."[USO] php scripts/phase2-neon-demo-login.php --reset=contabilidad-dev --user=admin\n");
    exit(2);
}
$target=$reset?$known[$match[1]]:null;
if(!is_file($root.'/.env') || is_file($root.'/bootstrap/cache/config.php')
    || !is_file($root.'/vendor/autoload.php')){
    fwrite(STDERR,"[BLOQUEO] Requiere backend/.env, Composer y Laravel sin config cache.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$expectedHost='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$runtime=(array)config('database.connections.pgsql',[]);
$admin=(array)config('database.connections.pgsql_migrations',[]);
$runtimePassword=(string)($runtime['password']??'');
$rolePassword=(string)env('DB_APP_BACKEND_ROLE_PASSWORD','');
$safeHost=static function(array $cfg,string $user)use($expectedHost):bool{
    return ($cfg['host']??'')===$expectedHost
        &&(string)($cfg['port']??'')==='5432'
        &&($cfg['database']??'')==='neondb'
        &&($cfg['username']??'')===$user
        &&trim((string)($cfg['url']??''))===''
        &&in_array((string)($cfg['sslmode']??''),['require','verify-ca','verify-full'],true);
};
$valid=$app->environment('local')
    &&config('database.default')==='pgsql'
    &&env('NEON_TEST_BRANCH_NAME','')==='contabilidad-dev'
    &&env('NEON_TEST_BRANCH_HOST','')===$expectedHost
    &&!filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN)
    &&$safeHost($runtime,'app_backend')
    &&$safeHost($admin,'neondb_owner')
    &&strlen($rolePassword)>=20
    &&$runtimePassword!=='' &&hash_equals($runtimePassword,$rolePassword);
unset($runtimePassword,$rolePassword);
if(!$valid){
    fwrite(STDERR,"[BLOQUEO] Solo Neon PG18 DEV, rol app_backend, conexion DIRECTA con SSL.\n");
    fwrite(STDERR,"[AYUDA] php scripts/phase2-neon-connection-diagnostic.php --check\n");
    exit(1);
}

$owner=DB::connection('pgsql_migrations');
$appDb=DB::connection('pgsql');
$phase='preflight';
$committed=false;
$newPassword=null;
$started=false;
try{
    $identity=$owner->selectOne(<<<'SQL'
      SELECT current_database() AS db,
        current_user AS db_role,
        current_setting('server_version_num')::int AS pg,
        (SELECT count(*) FROM public.migrations)::int AS migrations,
        (SELECT count(*) FROM public.companies)::int AS companies,
        (SELECT count(*) FROM public.users)::int AS users,
        (SELECT count(*) FROM public.company_user)::int AS memberships,
        (SELECT count(*) FROM public.accounting_destination_rule_versions
         WHERE status='DRAFT')::int AS drafts,
        (SELECT count(*) FROM public.accounting_destination_rule_versions
         WHERE status='ACTIVE')::int AS active
      SQL);
    if(!$identity||$identity->db!=='neondb'||$identity->db_role!=='neondb_owner'
        ||intdiv((int)$identity->pg,10000)!==18
        ||(int)$identity->migrations!==22
        ||(int)$identity->companies!==3
        ||(int)$identity->users!==3||(int)$identity->memberships!==8
        ||(int)$identity->drafts!==3||(int)$identity->active!==0){
        throw new RuntimeException('Baseline DEV no coincide: detener sin cambios.');
    }
    $appRole=$appDb->selectOne('SELECT current_user AS db_role');
    if(!$appRole||$appRole->db_role!=='app_backend'){
        throw new RuntimeException('La conexion de escritura no utiliza app_backend.');
    }
    $accounts=$owner->select(
        'SELECT id,email,name,current_company_id FROM public.users ORDER BY id'
    );
    if(count($accounts)!==3)throw new RuntimeException('Cantidad inesperada de usuarios.');
    $companyIds=['3000000000101','3000000000102','3000000000103'];
    foreach($accounts as $i=>$account){
        $expected=array_values($known)[$i];
        if((string)$account->id!==$expected['id']
            ||(string)$account->email!==$expected['email']
            ||(string)$account->name!==$expected['name']
            ||!in_array((string)$account->current_company_id,$companyIds,true)){
            throw new RuntimeException('Identidades DEMO modificadas; no restablecer.');
        }
    }
    $members=$owner->select(<<<'SQL'
      SELECT u.email,cu.company_id,cu.role,cu.is_active
      FROM public.company_user cu
      JOIN public.users u ON u.id=cu.user_id
      ORDER BY u.email,cu.company_id
      SQL);
    if(count($members)!==8){
        throw new RuntimeException('Membresias DEMO fuera de baseline.');
    }
    foreach($members as $member){
        if(!$member->is_active
            ||!in_array((string)$member->company_id,$companyIds,true)
            ||!in_array((string)$member->email,array_column($known,'email'),true)){
            throw new RuntimeException('Membresia DEMO inesperada.');
        }
    }
    echo "[PASS] Neon PG18 DEV: 3 usuarios DEMO, 3 empresas, 8 membresias.\n";
    if(!$reset){
        foreach($known as $key=>$account){
            echo "[DEMO] ".$key." => ".$account['email']."\n";
        }
        echo "[PENDIENTE] Para iniciar sesion, genere una contrasena LOCAL solo para el usuario elegido.\n";
        exit(0);
    }

    $phase='actualizar_hash';
    // 128 bits de entropia (32 caracteres hex). El prefijo no es secreto.
    $newPassword='D3v!'.bin2hex(random_bytes(16));
    $hash=Hash::make($newPassword);

    $appDb->beginTransaction();
    $started=true;
    $appDb->selectOne("SELECT set_config('lock_timeout','5000',true)");
    $locked=$appDb->selectOne(
        'SELECT id FROM public.users WHERE id=? AND email=? FOR UPDATE',
        [$target['id'],$target['email']]
    );
    if(!$locked){
        throw new RuntimeException('Usuario DEMO inexistente o bloqueado.');
    }
    // Nunca crear registros, solo UPDATE del hash existente.
    $updated=$appDb->table('users')
        ->where('id',$target['id'])
        ->where('email',$target['email'])
        ->update(['password'=>$hash,'updated_at'=>now()]);
    if($updated!==1){
        throw new RuntimeException('Se esperaba modificar exactamente un usuario DEMO.');
    }
    $stored=$appDb->table('users')->where('id',$target['id'])->value('password');
    if(!is_string($stored)||!Hash::check($newPassword,$stored)){
        throw new RuntimeException('La nueva contrasena no coincide con el hash almacenado.');
    }
    $phase='commit';
    $appDb->commit();
    $started=false;
    $committed=true;

    echo "[PASS] Usuario DEMO actualizado; misma empresa, roles y 8 membresias.\n";
    echo "[ACCESO] Correo: ".$target['email']."\n";
    echo "[CREDENCIAL PRIVADA — COPIAR AHORA] ".$newPassword."\n";
    echo "[IMPORTANTE] Esta clave se muestra SOLO AQUI una vez. No la publique ni la suba a Git.\n";
    echo "[ACCESO] Ingrese en la pantalla 'ContaPeru ERP Contable' mediante Laravel Sanctum.\n";
}catch(\Throwable $e){
    $sqlstate=$e instanceof \Illuminate\Database\QueryException
        ?(string)($e->errorInfo[0]??'N/A')
        :'N/A';
    fwrite(STDERR,"[FAIL] Etapa=".$phase
        ."; codigo=".preg_replace('/[^A-Z0-9]/','',$sqlstate)
        ."; sin mostrar consultas ni secretos.\n");
}finally{
    if($started){
        try{$appDb->rollBack();echo "[ROLLBACK] No se modifico ningun usuario.\n";}
        catch(\Throwable){fwrite(STDERR,"[ALERTA] Verificar estado del usuario antes de reintentar.\n");}
    }
    $newPassword=null;
    $appDb->disconnect();
    $owner->disconnect();
}
exit($committed?0:1);
