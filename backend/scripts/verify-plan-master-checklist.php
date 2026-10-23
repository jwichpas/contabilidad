<?php

declare(strict_types=1);

/**
 * Read-only consistency gate for the canonical 32-item master-plan checklist.
 *
 * php backend/scripts/verify-plan-master-checklist.php --check
 *
 * Never connects to Neon. Does not update Markdown automatically:
 * changing acceptance status always requires reviewed evidence.
 */
$mode = $argv[1] ?? '--check';
if ($argc !== 2 || $mode !== '--check') {
    fwrite(STDERR, "[USO] php backend/scripts/verify-plan-master-checklist.php --check\n");
    exit(2);
}

$path = dirname(__DIR__, 2).'/README-PLAN-MAESTRO.md';
$markdown = @file_get_contents($path);
if (! is_string($markdown) || $markdown === '') {
    fwrite(STDERR, "[FAIL] No se encuentra el Plan Maestro.\n");
    exit(1);
}

$start = strpos($markdown, '## 10. Checklist de seguimiento');
$end = strpos($markdown, '## 11. Referencias y control documental');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "[FAIL] Secciones 10 y 11 ausentes o fuera de orden.\n");
    exit(1);
}

$section = substr($markdown, $start, $end - $start);
preg_match_all('/^- \[([x ])\] \*\*(F[0-8]\.\d+)\*\*/m', $section, $matches, PREG_SET_ORDER);
$all = []; $approved = []; $pending = [];
foreach ($matches as $match) {
    $id = $match[2];
    if (isset($all[$id])) {
        fwrite(STDERR, "[FAIL] Actividad repetida: {$id}\n");
        exit(1);
    }
    $all[$id] = true;
    if ($match[1] === 'x') $approved[] = $id;
    else $pending[] = $id;
}
$total = count($all);
$done = count($approved);
$remaining = count($pending);

if ($total !== 32) {
    fwrite(STDERR, "[FAIL] El checklist debe conservar 32 actividades; hay {$total}.\n");
    exit(1);
}

$failures = [];
$mustContain = static function (bool $yes, string $why) use (&$failures): void {
    if (! $yes) $failures[] = $why;
};

$mustContain(
    preg_match('/Avance verificable:\s*'. $done .'\/'. $total .' actividades completas/', $markdown) === 1,
    'Encabezado de avance no coincide con las casillas'
);
$mustContain(
    preg_match('/Estado reconciliado[^\n]*'. $done .'\/'. $total .' actividades aprobadas/', $section) === 1,
    'Estado reconciliado de la seccion 10 desactualizado'
);
$mustContain(
    str_contains($section, '| Actividades aprobadas | **'.$done.'/'.$total),
    'Resumen de aprobadas no coincide'
);
$mustContain(
    str_contains($section, '| Actividades pendientes | **'.$remaining.'/'.$total),
    'Resumen de pendientes no coincide'
);
$phase3Done = count(array_filter($approved, static fn(string $id): bool => str_starts_with($id, 'F3.')));
$mustContain(
    str_contains($section, '| Fase de empresas F3 | **'.$phase3Done.'/3 completa'),
    'Resumen F3 no coincide con las casillas F3.1-F3.3'
);
$mustContain(
    str_contains($section, 'F3.4 · Primer asiento HTTP reversible')
       && str_contains($section, 'F3.5 · Primer asiento HTTP persistente'),
    'Faltan F3.4/F3.5 en la tabla de subhitos tecnicos'
);

$evidenceStart = strpos($section, '### Evidencias que justifican');
$policyStart = strpos($section, '**Política documental:**');
if ($evidenceStart === false || $policyStart === false || $policyStart <= $evidenceStart) {
    $failures[] = 'Tabla de evidencias ausente';
} else {
    $evidence = substr($section, $evidenceStart, $policyStart - $evidenceStart);
    preg_match_all('/^\| (F\d\.\d+) \|/m', $evidence, $rows);
    $documented = $rows[1];
    sort($documented);
    $expected = $approved;
    sort($expected);
    $mustContain($documented === $expected, 'La tabla de evidencias no corresponde a las casillas aprobadas');
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
echo "[PASS] Plan Maestro: {$done}/{$total} aprobadas, {$remaining}/{$total} pendientes.\n";
echo "[PASS] Tabla de evidencias, fase F3 y subhitos F3.4/F3.5 reconciliados.\n";
echo "[INFO] Esta prueba solo valida coherencia documental, NO certifica funcionamiento.\n";
