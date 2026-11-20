<script setup lang="ts">
import { computed } from 'vue';
import { formatExactLocalAmount } from '@/modules/accounting/utils/formatExactLocalAmount';
import type { PayrollParameters } from './payroll.types';

const props = defineProps<{ parameters?: PayrollParameters }>();
const sbs = 'https://www.sbs.gob.pe/app/spp/empleadores/comisiones_spp/Paginas/comision_prima.aspx';
const mtpe = 'https://cdn.www.gob.pe/uploads/document/file/5859785/5193674-13-mdpf-actualizado-2023-2-firmado-1-f-oom-ogpp-dpflcnl-y-dgppflit.pdf';
const administratorNames = { HA: 'Hábitat', IN: 'Integra', PR: 'Profuturo', RI: 'Prima' } as const;

function money(amount?: string | null) {
  return amount ? formatExactLocalAmount(amount, 'PEN').replace(/^PEN (.*,[0-9]{2})00$/, 'S/ $1') : 'Sin valor cargado';
}

const base = computed(() => [
  { title: 'Remuneración mínima vital', value: money(props.parameters?.minimum?.amount) },
  { title: 'Asignación familiar · 10% de la RMV', value: money(props.parameters?.family_allowance) },
  { title: 'Mínimo nocturno · RMV más 35%', value: money(props.parameters?.night_minimum) },
  { title: 'Unidad Impositiva Tributaria', value: money(props.parameters?.uit) },
]);
const pensionRows = computed(() => Object.entries(props.parameters?.afp?.administrators ?? {}).map(([code, rates]) => ({
  name: administratorNames[code as keyof typeof administratorNames] ?? code,
  ...rates,
})));
</script>

<template>
  <div v-if="parameters" class="min-w-0 space-y-4">
    <section aria-labelledby="payroll-legal-base" class="overflow-hidden rounded-lg border border-line bg-surf">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
        <h2 id="payroll-legal-base" class="font-semibold">Remuneraciones y umbrales</h2>
        <a v-if="parameters.minimum" :href="parameters.minimum.url" target="_blank" rel="noopener noreferrer"
          class="text-sm text-brand underline">{{ parameters.minimum.rule }} · Desde {{ parameters.minimum.from }}</a>
      </div>
      <dl class="grid grid-cols-2 gap-px bg-line xl:grid-cols-4">
        <div v-for="{ title, value } in base" :key="title" class="min-w-0 bg-surf p-4">
          <dt class="text-xs text-muted">{{ title }}</dt>
          <dd class="mt-1 font-semibold tabular-nums">{{ value }}</dd>
        </div>
      </dl>
      <ul class="space-y-2 border-t border-line px-4 py-3 text-sm">
        <li><strong>Horas extra.</strong> {{ parameters.overtime[0] }}% las dos primeras horas del día y {{ parameters.overtime[1] }}% las siguientes.</li>
        <li><strong>Horario nocturno.</strong> De 22:00 a 06:00. La validación de la ficha aplica a la jornada completa exclusivamente nocturna.</li>
        <li><strong>Asignación familiar.</strong> Activa el derecho acreditado en la ficha del trabajador. <a :href="mtpe" target="_blank" rel="noopener noreferrer" class="text-brand underline">Guía MTPE</a></li>
      </ul>
    </section>

    <section aria-labelledby="payroll-legal-deductions" class="space-y-3 rounded-lg border border-line bg-surf p-4">
      <h2 id="payroll-legal-deductions" class="font-semibold">Descuentos del trabajador</h2>
      <div class="grid gap-3 lg:grid-cols-2">
        <div class="space-y-2 rounded-md border border-line p-3 text-sm">
          <p><strong>ONP.</strong> {{ parameters.onp }}% sobre la remuneración asegurable.</p>
          <p><strong>AFP.</strong> Aporte al fondo de {{ parameters.afp_contribution }}%, prima hasta el tope SBS y comisión según el esquema de la ficha.</p>
          <p v-if="parameters.afp" class="text-muted">
            Prima {{ parameters.afp.insurance }}% · Tope {{ money(parameters.afp.insurance_ceiling) }} · {{ parameters.afp.source }}
          </p>
          <div v-if="pensionRows.length" class="overflow-auto">
            <table class="ui-table">
              <thead><tr><th scope="col">AFP</th><th scope="col">Flujo mensual</th><th scope="col">Saldo anual</th></tr></thead>
              <tbody><tr v-for="row in pensionRows" :key="row.name"><td>{{ row.name }}</td><td>{{ row.flow }}%</td><td>{{ row.balance }}%</td></tr></tbody>
            </table>
          </div>
          <p v-else class="text-muted">Registra la prima, el tope y la comisión del devengue en Asistencia.</p>
          <a :href="sbs" target="_blank" rel="noopener noreferrer" class="text-brand underline">Tasas publicadas por SBS</a>
        </div>
        <div class="space-y-2 rounded-md border border-line p-3 text-sm">
          <h3 class="font-semibold">Quinta categoría</h3>
          <p>Deducción anual de 7 UIT: <strong>{{ money(parameters.tax_deduction) }}</strong>.</p>
          <dl class="divide-y divide-line">
            <div v-for="[threshold, rate] in [['Hasta 5 UIT', '8%'], ['Por el exceso de 5 y hasta 20 UIT', '14%'], ['Por el exceso de 20 y hasta 35 UIT', '17%'], ['Por el exceso de 35 y hasta 45 UIT', '20%'], ['Por el exceso de 45 UIT', '30%']]"
              :key="rate" class="flex justify-between gap-2 py-1">
              <dt>{{ threshold }}</dt><dd class="font-medium tabular-nums">{{ rate }}</dd>
            </div>
          </dl>
          <p class="text-muted">Registra la retención del mes validada en Asistencia.</p>
          <a href="https://personas.sunat.gob.pe/trabajador-dependiente/como-me-deben-hacer-retencion-impuesto"
            target="_blank" rel="noopener noreferrer" class="text-brand underline">Cálculo mensual según SUNAT</a>
        </div>
      </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
      <section aria-labelledby="payroll-legal-employer" class="space-y-3 rounded-lg border border-line bg-surf p-4">
        <h2 id="payroll-legal-employer" class="font-semibold">Aportes del empleador</h2>
        <dl class="space-y-2 text-sm">
          <div><dt class="font-semibold">EsSalud y EPS</dt><dd>EsSalud {{ parameters.essalud }}%. El crédito EPS puede alcanzar el 25% del aporte correspondiente, sujeto a los topes legales.</dd></div>
          <div><dt class="font-semibold">Vida Ley</dt><dd>Cobertura desde el inicio de labores. La prima se determina con la póliza contratada.</dd></div>
          <div><dt class="font-semibold">SCTR</dt><dd>Coberturas de salud y pensiones para actividades de riesgo, según el alcance y las pólizas aplicables.</dd></div>
          <div><dt class="font-semibold">SENATI</dt><dd>0.75% sobre las remuneraciones afectas. La aplicación depende de la actividad y del promedio de trabajadores del año anterior.</dd></div>
          <div><dt class="font-semibold">SENCICO</dt><dd>0.20% sobre el valor de venta de ingresos facturados por actividades de construcción.</dd></div>
        </dl>
        <p class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-brand">
          <a class="underline" href="https://essalud.gob.pe/transparencia/pdf/informacion/ley%20_modern_ss_salud_26790.pdf" target="_blank" rel="noopener noreferrer">Ley EsSalud y EPS</a>
          <a class="underline" href="https://leyes.congreso.gob.pe/Documentos/Leyes/26272.pdf" target="_blank" rel="noopener noreferrer">Ley SENATI</a>
          <a class="underline" href="https://www.gob.pe/institucion/sencico/campa%C3%B1as/2860-contribucion-al-sencico" target="_blank" rel="noopener noreferrer">SENCICO</a>
        </p>
        <p class="text-xs text-muted">El costo de Planilla mensual incluye el aporte regular a EsSalud. EPS, seguros y aportes sectoriales requieren parametrización por empresa.</p>
      </section>
      <section aria-labelledby="payroll-legal-benefits" class="space-y-3 rounded-lg border border-line bg-surf p-4">
        <h2 id="payroll-legal-benefits" class="font-semibold">Beneficios y provisiones</h2>
        <dl class="space-y-2 text-sm">
          <div><dt class="font-semibold">Gratificaciones</dt><dd>Julio y diciembre. Bonificación extraordinaria de {{ parameters.gratification_bonus.essalud }}% para EsSalud o {{ parameters.gratification_bonus.eps }}% para EPS.</dd></div>
          <div><dt class="font-semibold">CTS</dt><dd>Mayo y noviembre. La base computable incorpora un sexto de la gratificación semestral percibida que corresponda.</dd></div>
          <div><dt class="font-semibold">Vacaciones</dt><dd>30 días calendario por año para el régimen general cuando se cumple el récord requerido.</dd></div>
        </dl>
        <p class="text-xs text-muted">En Asistencia puedes registrar bases y tiempo computables para estimar provisiones. El resultado se muestra por separado en Planilla mensual.</p>
        <a href="#gestion-de-planillas/beneficios" class="text-sm text-brand underline">Ir a CTS y gratificaciones</a>
      </section>
    </div>
  </div>
  <p v-else role="status" class="text-sm text-muted">Los parámetros del periodo no están disponibles.</p>
</template>
