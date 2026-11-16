<?php

declare(strict_types=1);

/**
 * F5/F7: 14 asientos reales de PRUEBA, solo PCGE Docker local.
 * CLI: --plan (solo lectura), --probe (POST HTTP+ROLLBACK), --apply (POST HTTP+COMMIT),
 *      --verify (solo lectura sobre asientos ya persistidos).
 * Ejecutar exclusivamente desde cargar-ciclo-contable-pg18-local.ps1.
 * NO genera facturas SUNAT ni un Kardex real. No ejecuta migraciones.
 */

$mode = $argv[1] ?? '';
if ($argc !== 2 || ! in_array($mode, ['--plan', '--probe', '--apply', '--verify'], true)) {
    fwrite(STDERR, "[USO] --plan | --probe | --apply | --verify (usar wrapper PowerShell local)\n");
    exit(2);
}
$root = dirname(__DIR__);
if (! is_file($root.'/vendor/autoload.php') || is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[BLOQUEO] Requiere Composer y ausencia de bootstrap/cache/config.php.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CashFlowArea;
use App\Models\Entity;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// Códigos PCGE 2019 normalizados a 6 dígitos. Confirmarlos en --plan.
// Son cuentas de PRUEBA, no una configuración automática de NIIF por empresa.
$mapping = [
    '10_EFECTIVO' => '104100',
    '12_CXC' => '121200',
    '20_MERCADERIAS' => '201110',
    '33_PPE' => '336110',
    '39_DEP_ACUM' => '395270',
    '40_IGV' => '401110',
    '40_RENTA' => '401710',
    '42_CXP' => '421200',
    '45_PRESTAMO' => '451100',
    '45_INTERES' => '455110',
    '50_CAPITAL' => '501100',
    '60_COMPRAS' => '601100',
    '61_VARIACION' => '611100',
    '63_ALQUILER' => '635200',
    '67_INTERESES' => '673110',
    '68_DEPRECIACION' => '684150',
    '69_COSTO_VENTAS' => '691210',
    '70_VENTAS' => '701200',
    '88_IMP_RENTA' => '881000',
];

$fixturePath = $root.'/tests/Fixtures/financial_lifecycle_2026.json';
if (! is_file($fixturePath)
    || hash_file('sha256', $fixturePath) !== 'dd7a39c2ad90596b8cc5587c8d937fbb8fb719dd43b84106fff063bcbf9ca1cb') {
    fwrite(STDERR, "[BLOQUEO] Fixture F5 versionada ausente o modificada.\n");
    exit(1);
}
$fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$entries = $fixture['entries'] ?? [];
if (count($entries) !== 14 || count(array_unique(array_column($entries, 'id'))) !== 14) {
    fwrite(STDERR, "[BLOQUEO] Se esperaban 14 identificadores unicos en la fixture.\n");
    exit(1);
}

$runtime = (array) config('database.connections.pgsql', []);
$expected = ['host'=>'127.0.0.1','port'=>'55433','database'=>'contabilidad_local',
    'username'=>'app_backend','sslmode'=>'disable'];
foreach ($expected as $key => $value) {
    if ((string)($runtime[$key] ?? '') !== $value) {
        fwrite(STDERR, "[BLOQUEO] DB {$key} incompatible con Docker local.\n");
        exit(1);
    }
}
if (config('database.default') !== 'pgsql' || ! app()->environment('local')
    || trim((string)($runtime['url'] ?? '')) !== '' || getenv('CONTABILIDAD_PG18_DOCKER_VERIFIED') !== '1') {
    fwrite(STDERR, "[BLOQUEO] Requiere wrapper de laboratorio Docker; nunca Neon/produccion.\n");
    exit(1);
}
if ($mode === '--apply') {
    $backup = getenv('CONTABILIDAD_PG18_BACKUP_PATH') ?: '';
    if ($backup === '' || ! is_file($backup) || filesize($backup) < 1024) {
        fwrite(STDERR, "[BLOQUEO] --apply requiere respaldo Docker confirmado.\n");
        exit(1);
    }
}

$db = DB::connection('pgsql');
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$tenant = app(TenantContext::class);
$phase = 'identidad';
$outer = false;
$committed = false;
$completed = false;
$check = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$marker = static fn (string $id): string => '[F5-2026-'.$id.'] ';
try {
    $identity = $db->selectOne('SELECT current_database() AS db, current_user AS actor, current_setting(\'server_version_num\')::integer AS pg');
    $check($identity && $identity->db === 'contabilidad_local'
        && $identity->actor === 'app_backend' && intdiv((int)$identity->pg, 10000) === 18,
        'La conexion real no es app_backend en PostgreSQL 18 Docker local.');
    $company = \App\Models\Company::query()->where('tax_id', '20999990101')
        ->where('business_name', 'EMPRESA DEMO PCGE 2019 - SOLO DOCKER')->first();
    $actor = User::query()->where('email', 'demo.pg18@example.test')->first();
    $check($company !== null && $actor !== null, 'No existe la empresa PCGE Docker o el usuario de laboratorio.');
    $companyId = (int)$company->id;
    // Las arrow functions capturan variables automaticamente; las funciones tradicionales requieren use (...).
    $membership = $tenant->run($companyId, static fn (): bool =>
        $db->table('company_user')->where('company_id', $companyId)
            ->where('user_id', $actor->id)->where('role', 'ADMIN')
            ->where('is_active', true)->exists());
    $check($membership, 'Usuario DEMO sin membresia ADMIN.');

    $phase = 'preflight tenant';
    $data = $tenant->run($companyId, static function () use ($db,$mapping,$entries,$marker,$check,$companyId): array {
        $accounts = $db->table('accounting_accounts')->whereIn('code', array_values($mapping))
            ->where('is_operational', true)->where('is_active', true)
            ->get()->keyBy('code');
        $missing = [];
        foreach ($mapping as $key => $code) {
            $a = $accounts->get($code);
            if ($a === null || strlen($code) !== 6) $missing[] = "{$key}={$code}";
        }
        $check($missing === [], 'Cuentas no operativas o ausentes: '.implode(', ', $missing)
            .'. Revisar catalogo PCGE y adaptar mapeo antes de POST.');
        $fp = $db->table('fiscal_periods')->where('year', 2026)->where('month', 1)->first();
        $check($fp === null || $fp->status === 'OPEN', 'Enero 2026 no esta abierto.');
        $historical = $db->table('journal_entries')
            ->whereBetween('entry_date', ['2026-01-01','2026-01-31'])->count();
        $found = $db->table('journal_entries')->where('description','like','[F5-2026-%')->count();
        $client = $db->table('entities')->where('tax_id','20999990127')->where('is_client',true)->where('is_active',true)->first();
        $supplier = $db->table('entities')->where('tax_id','20999990119')->where('is_supplier',true)->where('is_active',true)->first();
        $check($client !== null && $supplier !== null, 'No existen cliente/proveedor sinteticos de Docker.');
        $companyAreas = $db->table('company_areas')->where('is_active',true)->get()->keyBy('code');
        $centers = $db->table('cost_centers')->where('is_active',true)->orderBy('id')->get();
        $areas = $db->table('cash_flow_areas')->where('is_active',true)->get()->keyBy('code');
        $check($areas->has('OPE-IN') && $areas->has('OPE-OUT'), 'Faltan areas de flujo operativo del laboratorio.');
        $check($companyAreas->has('ADM-DEMO') && $companyAreas->has('COM-DEMO'), 'Faltan areas organizativas del laboratorio.');
        $sub = $db->table('company_subdiaries')->where('is_active',true)->pluck('code')->all();
        foreach ($entries as $entry) $check(in_array($entry['subdiary'], $sub, true), 'Falta subdiario '.$entry['subdiary']);
        $currency = $db->table('company_settings as s')->join('currencies as c','c.id','=','s.base_currency_id')
            ->where('s.company_id', $companyId)->value('c.code');
        $check($currency === 'PEN', 'Se requiere moneda funcional PEN.');
        $secondary = $db->table('company_currencies as cc')->join('currencies as c','c.id','=','cc.currency_id')
            ->where('cc.company_id',$companyId)->where('cc.is_active',true)->where('cc.is_base',false)->pluck('c.code')->all();
        $check(count($secondary) === 1 && $secondary[0] === 'USD', 'Se requiere PEN base/USD secundaria.');
        foreach ($mapping as $name => $code) {
            $a=$accounts->get($code);
            echo sprintf("[CUENTA] %-19s %6s %-47s anexo=%s CC=%s flujo=%s\n", $name,$code,
                mb_substr((string)$a->name,0,47), (string)$a->entity_requirement_type,
                $a->requires_cost_center?'SI':'NO', $a->requires_cash_flow_area?'SI':'NO');
        }
        return compact('accounts','historical','found','client','supplier','companyAreas','centers','areas');
    });
    $check(($mode === '--verify') ? ($data['found'] === 14) : ($data['historical'] === 0 && $data['found'] === 0),
        $mode === '--verify' ? 'No se encuentran los 14 asientos marcados.'
            : 'Ya hay movimientos de enero o de esta fixture: no duplicar ni mezclar datos.');
    echo '[PASS] Docker local y empresa ficticia verificados. Enero 2026: '
        .$data['historical'].' asientos; marcadores F5: '.$data['found'].".\n";
    if ($mode === '--plan') {
        echo "[PLAN] Se registrarian 14 vouchers ENERO 2026 via Laravel HTTP en un mismo COMMIT.\n";
        echo "[PLAN] Areas FIN-IN/FIN-OUT y anexos ficticios solo si se requieren.\n";
        echo "[AVISO] Sin facturas SUNAT, Kardex, NIIF 9 automatizada ni calculo fiscal real.\n";
        exit(0);
    }

    // Nunca se escriben ajustes directos en journal_entries; solo el POST del ERP.
    if ($mode === '--verify') {
        $tenant->run($companyId, static function () use ($db,$check,$entries,$marker,$mapping): void {
            $posted=$db->table('journal_entries')->where('description','like','[F5-2026-%')->get();
            $check($posted->count()===14, 'Conteo final de asientos !=14');
            foreach ($entries as $e) {
                $description=$marker($e['id']).$e['description'];
                $check($posted->where('description',$description)->count()===1, 'No existe voucher correcto '.$e['id']);
            }
            $totals=$db->table('journal_entry_lines as l')
                ->join('journal_entries as j',function($join){$join->on('j.id','=','l.journal_entry_id')->on('j.company_id','=','l.company_id');})
                ->where('j.description','like','[F5-2026-%')
                ->selectRaw('SUM(l.debit_local) AS debit, SUM(l.credit_local) AS credit')
                ->first();
            $check($totals && bccomp((string)$totals->debit,(string)$totals->credit,4)===0,
                'Asientos persistidos con descuadre Debe/Haber.');
            echo "[PASS] 14 asientos persistidos y partida doble contable conciliada.\n";
        });
        exit(0);
    }

    $phase = 'transaccion';
    $db->beginTransaction();
    $outer = true;
    $db->selectOne("SELECT set_config('statement_timeout','90000',true)");
    Sanctum::actingAs($actor, ['*'], 'sanctum');
    $request = static function (string $method, string $uri, ?array $payload=null,?string $idem=null)
        use ($kernel, $companyId): array {
        $headers=['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','HTTP_X_COMPANY_ID'=>(string)$companyId];
        if ($idem !== null) $headers['HTTP_IDEMPOTENCY_KEY']=$idem;
        $response=$kernel->handle(\Illuminate\Http\Request::create($uri,$method,[],[],[], $headers,
            $payload===null?null:json_encode($payload,JSON_THROW_ON_ERROR)));
        return [$response->getStatusCode(),json_decode((string)$response->getContent(),true,512,JSON_THROW_ON_ERROR)];
    };
    $ids=[];
    $report=[];
    $tenant->run($companyId, static function () use ($db,$data,$check,$entries,$mapping,$marker,$request,&$ids,&$report): void {
        $flow=$data['areas'];
        foreach ([['FIN-IN','Desembolso prestamo (test)','INFLOW'],['FIN-OUT','Pago principal prestamo (test)','OUTFLOW']] as [$code,$name,$direction]) {
            if (!$flow->has($code)) {
                CashFlowArea::query()->create(['code'=>$code,'name'=>$name,
                    'activity_category'=>'FINANCING','flow_direction'=>$direction,'is_active'=>true]);
            }
        }
        $flow=$db->table('cash_flow_areas')->where('is_active',true)->get()->keyBy('code');
        $roles = [
            'CUSTOMER'=> (int)$data['client']->id,
            'SUPPLIER'=> (int)$data['supplier']->id,
            'ANY_THIRD_PARTY'=>(int)$data['supplier']->id,
        ];
        foreach ([
            ['FINANCIAL_INSTITUTION','20999990135','BANCO FICTICIO - F5 DOCKER','is_financial_institution'],
            ['TAX_AUTHORITY','20999990143','AUTORIDAD FICTICIA - F5 DOCKER','is_tax_authority'],
            ['SHAREHOLDER','20999990151','SOCIO FICTICIO - F5 DOCKER','is_shareholder'],
        ] as [$type,$tax,$name,$roleField]) {
            $entity=Entity::query()->where('tax_id',$tax)->first();
            if ($entity===null) {
                $entity=Entity::query()->create(['identity_document_type_code'=>'6','tax_id'=>$tax,
                    'legal_name'=>$name,'country_code'=>'PER',$roleField=>true,'is_active'=>true]);
            }
            $check((bool)$entity->{$roleField} && (bool)$entity->is_active,'Tercero ficticio incompatible '.$type);
            $roles[$type]=(int)$entity->id;
        }
        $center=$data['centers']->first();
        foreach ($entries as $entry) {
            $localTotal='0.0000';
            foreach ($entry['lines'] as $line) $localTotal=bcadd($localTotal,(string)$line['debit'],4);
            $date=$entry['date'];
            // Si hay tipo de cambio historico verificado, se respeta: nunca se sobrescribe.
            $fx=$db->table('exchange_rates')->where('rate_date',$date)
                ->where('source_currency','USD')->where('target_currency','PEN')->first();
            $rate=$fx ? (string)$fx->sell_rate : '3.750000'; // Solo para laboratorio, no tasa SUNAT.
            $check(bccomp($rate,'0',6)>0, 'Tipo de cambio invalido '.$date);
            $target=bcdiv($localTotal,$rate,4);
            $lines=[];$usedDebit='0.0000';$usedCredit='0.0000';
            $lastDebit=array_key_last(array_filter($entry['lines'],static fn($l)=>bccomp((string)$l['debit'],'0',4)>0));
            $lastCredit=array_key_last(array_filter($entry['lines'],static fn($l)=>bccomp((string)$l['credit'],'0',4)>0));
            foreach ($entry['lines'] as $index=>$item) {
                $key=$item['account'];$account=$data['accounts']->get($mapping[$key]);
                $code=$mapping[$key];
                $line=['accounting_account_id'=>(int)$account->id,
                    'description'=>$entry['id'].' - '.$key,
                    'debit_local'=>(string)$item['debit'],'credit_local'=>(string)$item['credit'],
                    'debit_foreign'=>'0.0000','credit_foreign'=>'0.0000'];
                $isDebit=bccomp((string)$item['debit'],'0',4)>0;
                $local=$isDebit?(string)$item['debit']:(string)$item['credit'];
                if ($isDebit) {
                    $amount= $index===$lastDebit ? bcsub($target,$usedDebit,4) : bcdiv($local,$rate,4);
                    $usedDebit=bcadd($usedDebit,$amount,4);$line['debit_foreign']=$amount;
                } else {
                    $amount= $index===$lastCredit ? bcsub($target,$usedCredit,4) : bcdiv($local,$rate,4);
                    $usedCredit=bcadd($usedCredit,$amount,4);$line['credit_foreign']=$amount;
                }
                $check(bccomp($amount,'0',4)>0,'Monto en moneda extranjera no positivo '.$entry['id']);
                $type=(string)$account->entity_requirement_type;
                if ($type!=='NONE' || (bool)$account->requires_auxiliary) {
                    $role=$type==='NONE'?'ANY_THIRD_PARTY':$type;
                    $check(isset($roles[$role]), 'Tipo de tercero sin demo '.$role);
                    $line['entity_id']=$roles[$role];
                }
                if ((bool)$account->requires_cost_center || (bool)$account->has_automatic_destination) {
                    $check($center!==null,'Se requiere centro de costos en '.$code);
                    $line['cost_center_id']=(int)$center->id;
                }
                if ((bool)$account->requires_company_area) {
                    $area=str_starts_with($code,'70') || str_starts_with($code,'69')?'COM-DEMO':'ADM-DEMO';
                    $line['company_area_id']=(int)$data['companyAreas']->get($area)->id;
                }
                if ((bool)$account->requires_cash_flow_area) {
                    $flowCode='OPE-IN';
                    if ($entry['id']==='J01') $flowCode='OPE-IN';
                    elseif (in_array($entry['id'],['J02','J09'],true)) $flowCode=$isDebit?'FIN-IN':'FIN-OUT';
                    else $flowCode=$isDebit?'OPE-IN':'OPE-OUT';
                    $check($flow->has($flowCode),'Area de flujo inexistente '.$flowCode);
                    $line['cash_flow_area_id']=(int)$flow->get($flowCode)->id;
                }
                $lines[]=$line;
            }
            $check(bccomp($usedDebit,$usedCredit,4)===0,'Moneda extranjera descuadrada '.$entry['id']);
            $payload=['entry_date'=>$date,'entry_type'=>$entry['type'],
                'subdiary_code'=>$entry['subdiary'],'currency'=>'PEN',
                'exchange_rate_type'=>'SELL','description'=>$marker($entry['id']).$entry['description'],
                'lines'=>$lines];
            if (!$fx) {
                // Tasa totalmente simulada y solo en Docker. El POST mantiene el sistema bimoneda.
                $payload['exchange_rates']=[['currency'=>'USD','buy_rate'=>$rate,'sell_rate'=>$rate,'rate'=>$rate]];
            }
            [$status,$body]=$request('POST','/api/v1/accounting/journal-entries',$payload,'f5-docker-202601-'.$entry['id'].'-v1');
            $check($status===201,'POST '.$entry['id'].' => '.$status.' '.substr((string)($body['message']??''),0,220));
            $id=(int)($body['data']['id']??0);
            $check($id>0,'No se obtuvo ID del voucher '.$entry['id']);
            $returnedLines=$body['data']['lines']??[];
            $check(count($returnedLines)>=count($entry['lines']), 'Partidas incompletas '.$entry['id']);
            foreach ($entry['lines'] as $lineIndex=>$expectedLine) {
                $actualLine=$returnedLines[$lineIndex]??[];
                $expectedAccount=$data['accounts']->get($mapping[$expectedLine['account']]);
                $check((int)($actualLine['accounting_account_id']??0)===(int)$expectedAccount->id
                    && bccomp((string)($actualLine['debit_local']??'-999'),(string)$expectedLine['debit'],4)===0
                    && bccomp((string)($actualLine['credit_local']??'-999'),(string)$expectedLine['credit'],4)===0,
                    'Cuenta o importe inesperado en '.$entry['id'].' partida '.($lineIndex+1));
            }
            $ids[]=$id;
            echo '[POST] '.$entry['id'].' -> asiento #'.$id." (201)\n";
        }
        $check(count($ids)===14,'No se registraron 14 vouchers.');
        $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
        $saved=$db->table('journal_entries')->whereIn('id',$ids)->get();
        $check($saved->count()===14,'No se encuentran los 14 vouchers en PostgreSQL.');
        [$status,$body]=$request('GET','/api/v1/accounting/reports/financial-snapshot?as_of=2026-01-31');
        $check($status===200,'No se obtuvo reporte ESF real HTTP: '.$status);
        $report['esf']=$body['data'];
        [$status,$body]=$request('GET','/api/v1/accounting/reports/cash-flow?from=2026-01-01&to=2026-01-31');
        $check($status===200,'No se obtuvo EFE real HTTP: '.$status);
        $report['efe']=$body['data'];
        // Control primario del motor y del importe; los errores de presentación quedan documentados.
        $totals=$db->table('journal_entry_lines as l')
            ->join('journal_entries as j',function($join){$join->on('j.id','=','l.journal_entry_id')->on('j.company_id','=','l.company_id');})
            ->whereIn('j.id',$ids)
            ->selectRaw('SUM(l.debit_local) AS debit,SUM(l.credit_local) AS credit')
            ->first();
        $check(bccomp((string)$totals->debit,(string)$totals->credit,4)===0,'Partida doble de la carga descuadrada');
    });
    $esf=$report['esf']['totals']??[];
    $efe=$report['efe']['totals']??[];
    echo '[REPORTE ESF] activo='.($esf['assets']??'?').' pasivo='.($esf['liabilities']??'?')
        .' patrimonio='.($esf['equity']??'?').' resultado='.($esf['result']??'?')
        .' diferencia='.($esf['difference']??'?')."\n";
    echo '[REPORTE EFE] inicial='.($efe['opening_local']??'?')
        .' operacion='.($efe['operating_local']??'?')
        .' financiacion='.($efe['financing_local']??'?')
        .' final='.($efe['closing_local']??'?')."\n";
    $reference=[
        'assets'=>'94340.0000','liabilities'=>'30490.7000','equity'=>'60000.0000',
        'result'=>'3849.3000','difference'=>'0.0000',
    ];
    foreach ($reference as $key=>$wanted) {
        if (!isset($esf[$key]) || bccomp((string)$esf[$key],$wanted,4)!==0) {
            echo '[GAP EEFF] '.$key.' ERP='.($esf[$key]??'SIN DATO').' referencia='.$wanted."\n";
        }
    }
    $expectedCash=['opening_local'=>'40000.0000','operating_local'=>'2560.0000',
        'financing_local'=>'20000.0000','closing_local'=>'62560.0000'];
    foreach ($expectedCash as $key=>$wanted) {
        if (!isset($efe[$key]) || bccomp((string)$efe[$key],$wanted,4)!==0) {
            echo '[GAP EFE] '.$key.' ERP='.($efe[$key]??'SIN DATO').' referencia='.$wanted."\n";
        }
    }
    if (($report['efe']['classification_complete'] ?? false) !== true
        || ($report['efe']['is_reconciled'] ?? false) !== true) {
        echo "[GAP EFE] Clasificacion completa / conciliacion pendiente de comprobar.\n";
    }
    $completed=true;
    if ($mode==='--probe') {
        echo "[PROBE PASS] POST reales ejecutados pero todo se revertira (ROLLBACK).\n";
    } else {
        $db->commit();$outer=false;$committed=true;
        echo "[COMMIT] 14 vouchers persistidos solo en EMPRESA DEMO PCGE 2019 Docker.\n";
        echo "[IMPORTANTE] Ventas/compras registradas como asientos manuales; no existen facturas SUNAT ni Kardex.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR,'[BLOQUEO] '.$phase.' '.get_class($e).': '.substr($e->getMessage(),0,450)."\n");
} finally {
    if ($outer) {
        try {$db->rollBack();echo "[ROLLBACK] No se conservaron los movimientos del ensayo.\n";}
        catch (\Throwable $e) {fwrite(STDERR,"[ERROR] No se pudo confirmar ROLLBACK; revisar Docker inmediatamente.\n");}
    }
}
exit($completed && ($committed || $mode === '--probe') ? 0 : 1);
