<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F3.1 SUNAT/INEI reference snapshots supplied for the 2026-10-22 release.
 * Additive: keeps 3 DEV companies, January OPEN, PCGE, and accounting data.
 * Install exclusively via reviewed Artisan --path, never migrate:fresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db=DB::connection('pgsql_migrations');
        $cfg=$db->getConfig();
        $host='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
        $who=$db->selectOne("SELECT current_user AS actor,current_database() AS db,current_setting('server_version_num')::integer AS pg");
        if (!app()->environment('local')
            || env('NEON_TEST_BRANCH_NAME')!=='contabilidad-dev'
            || env('NEON_TEST_BRANCH_HOST')!==$host
            || ($cfg['host']??'')!==$host
            || ($cfg['database']??'')!=='neondb'
            || ($cfg['username']??'')!=='neondb_owner'
            || (string)($cfg['port']??'')!=='5432'
            || trim((string)($cfg['url']??''))!==''
            || !in_array((string)($cfg['sslmode']??''),['require','verify-ca','verify-full'],true)
            || $who?->actor!=='neondb_owner'||$who?->db!=='neondb'
            || intdiv((int)($who?->pg??0),10000)!==18) {
            throw new RuntimeException('Catálogos SUNAT: solo migrador Neon PG18 DEV/SSL.');
        }

        $before=$db->selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM public.migrations)::integer AS migrations,
                   (SELECT count(*) FROM public.companies)::integer AS companies,
                   (SELECT count(*) FROM public.users)::integer AS users,
                   (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
                   (SELECT count(*) FROM public.journal_entries)::integer AS journals,
                   (SELECT count(*) FROM public.company_economic_activities)::integer AS ciiu,
                   (SELECT count(*) FROM public.accounting_account_templates)::integer AS pcge
            SQL);
        if (!$before || (int)$before->migrations!==23||(int)$before->companies!==3
            ||(int)$before->users!==3||(int)$before->periods!==1
            ||(int)$before->journals!==0||(int)$before->ciiu!==0
            ||(int)$before->pcge!==3401
            ||Schema::connection('pgsql_migrations')->hasTable('sunat_ruc_contributor_types')
            ||Schema::connection('pgsql_migrations')->hasTable('sunat_ciiu_rev4_classes')
            ||Schema::connection('pgsql_migrations')->hasTable('sunat_ruc_tax_codes')) {
            throw new RuntimeException('Baseline de DEV no coincide; no instalar catálogos automáticamente.');
        }

        $schema=Schema::connection('pgsql_migrations');
        $schema->create('sunat_ruc_contributor_types',function(Blueprint $t):void {
            $t->string('code',2)->primary();
            $t->string('name',250);
            $t->boolean('is_active')->default(true);
        });
        $schema->create('sunat_ciiu_rev4_classes',function(Blueprint $t):void {
            $t->string('code',4)->primary();
            $t->string('division',2);
            $t->string('group_code',2);
            $t->string('class_number',2);
            $t->string('name',500);
            $t->boolean('is_active')->default(true);
            $t->index('division','idx_sunat_ciiu_division');
        });
        $schema->create('sunat_ruc_tax_codes',function(Blueprint $t):void {
            $t->string('code',4)->primary();
            $t->string('short_name',20);
            $t->string('name',250);
            $t->boolean('is_active')->default(true);
        });
        $schema->create('sunat_ruc_reference_versions',function(Blueprint $t):void {
            $t->string('dataset',60)->primary();
            $t->string('version',32);
            $t->string('source_file',255);
            $t->char('sha256',64);
            $t->unsignedInteger('row_count');
            $t->timestampTz('installed_at')->useCurrent();
        });

        $datasets=[
            ['sunat_contributor_types_2026_10_22.json','sunat_tipo_contribuyente','sunat_ruc_contributor_types',51],
            ['sunat_ciiu_rev4_2026_10_22.json','sunat_ciiu_rev4','sunat_ciiu_rev4_classes',417],
            ['sunat_tax_codes_2026_10_22.json','sunat_codigos_tributos','sunat_ruc_tax_codes',38],
        ];
        foreach($datasets as [$file,$dataset,$table,$count]){
            $path=database_path('reference_data/'.$file);
            if(!is_file($path))throw new RuntimeException('Archivo de referencia requerido no disponible.');
            $raw=file_get_contents($path);
            $decoded=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
            if(($decoded['dataset']??null)!==$dataset
                ||($decoded['version']??null)!=='2026-10-22-v1'
                ||count($decoded['rows']??[])!==$count) {
                throw new RuntimeException('Dataset SUNAT no coincide con la versión revisada.');
            }
            $records=[];$seen=[];
            foreach($decoded['rows'] as $entry){
                $code=(string)($entry['code']??'');
                if(isset($seen[$code])||!preg_match(
                    $table==='sunat_ruc_contributor_types'?'/^\d{2}$/D':'/^\d{4}$/D',
                    $code))throw new RuntimeException('Código SUNAT duplicado o inválido.');
                $seen[$code]=true;
                if($table==='sunat_ciiu_rev4_classes'){
                    $records[]=['code'=>$code,'division'=>$entry['division'],
                        'group_code'=>$entry['group'],'class_number'=>$entry['class_number'],
                        'name'=>$entry['name'],'is_active'=>true];
                }elseif($table==='sunat_ruc_tax_codes'){
                    $records[]=['code'=>$code,'short_name'=>$entry['short_name'],
                        'name'=>$entry['name'],'is_active'=>true];
                }else{
                    $records[]=['code'=>$code,'name'=>$entry['name'],'is_active'=>true];
                }
            }
            foreach(array_chunk($records,100) as $chunk){
                $db->table($table)->insert($chunk);
            }
            $db->table('sunat_ruc_reference_versions')->insert([
                'dataset'=>$dataset,'version'=>'2026-10-22-v1',
                'source_file'=>$file,'sha256'=>hash('sha256',(string)$raw),
                'row_count'=>$count,'installed_at'=>now(),
            ]);
        }

        $schema->table('company_settings',function(Blueprint $t):void {
            $t->string('sunat_contributor_type_code',2)->nullable();
            $t->foreign('sunat_contributor_type_code','fk_company_sunat_contributor_type')
                ->references('code')->on('sunat_ruc_contributor_types')->restrictOnDelete();
        });
        $schema->table('company_economic_activities',function(Blueprint $t):void {
            $t->foreign('ciiu_code','fk_company_economic_official_ciiu')
                ->references('code')->on('sunat_ciiu_rev4_classes')->restrictOnDelete();
        });
        $schema->create('company_tax_codes',function(Blueprint $t):void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('tax_code',4);
            $t->foreign('tax_code','fk_company_tax_official_code')
                ->references('code')->on('sunat_ruc_tax_codes')->restrictOnDelete();
            $t->unique(['company_id','tax_code'],'uq_company_tax_codes');
        });
        $db->unprepared(<<<'SQL'
            REVOKE ALL ON public.sunat_ruc_contributor_types,
                public.sunat_ciiu_rev4_classes,public.sunat_ruc_tax_codes,
                public.sunat_ruc_reference_versions FROM PUBLIC;
            GRANT SELECT ON public.sunat_ruc_contributor_types,
                public.sunat_ciiu_rev4_classes,public.sunat_ruc_tax_codes
                TO app_backend;
            ALTER TABLE public.company_tax_codes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_tax_codes FORCE ROW LEVEL SECURITY;
            CREATE POLICY company_tax_codes_tenant_policy ON public.company_tax_codes
                FOR ALL TO app_backend
                USING (company_id=public.app_current_company_id())
                WITH CHECK (company_id=public.app_current_company_id());
            GRANT SELECT,INSERT,UPDATE,DELETE ON public.company_tax_codes TO app_backend;
            GRANT USAGE,SELECT ON SEQUENCE public.company_tax_codes_id_seq TO app_backend;
            SQL);
    }

    public function down():void
    {
        throw new RuntimeException('No borrar catálogos SUNAT o referencias históricas automáticamente.');
    }
};
