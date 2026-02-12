<?php
/**
 * API: Generar reporte individual de lote en formato HTML para impresión/PDF
 * GET /api/reportes/lote-pdf.php?id=123
 */

require_once dirname(dirname(__DIR__)) . '/bootstrap.php';
require_once BASE_PATH . '/core/PdfReport.php';

if (!Auth::check()) {
    http_response_code(401);
    die('No autorizado');
}

$db = Database::getInstance();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    die('Lote no especificado');
}

// Compatibilidad de esquema
$colsLotes = Helpers::getTableColumns('lotes');
$hasLoteCol = static fn(string $name): bool => in_array($name, $colsLotes, true);
$colsFer = Helpers::getTableColumns('registros_fermentacion');
$hasFerCol = static fn(string $name): bool => in_array($name, $colsFer, true);
$colsFerCtrl = Helpers::getTableColumns('fermentacion_control_diario');
$hasFerCtrlCol = static fn(string $name): bool => in_array($name, $colsFerCtrl, true);
$colsSec = Helpers::getTableColumns('registros_secado');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSec, true);
$colsSecCtrl = Helpers::getTableColumns('secado_control_temperatura');
$hasSecCtrlCol = static fn(string $name): bool => in_array($name, $colsSecCtrl, true);
$colsPr = Helpers::getTableColumns('registros_prueba_corte');
$hasPrCol = static fn(string $name): bool => in_array($name, $colsPr, true);

$lotePesoExpr = $hasLoteCol('peso_inicial_kg')
    ? 'l.peso_inicial_kg'
    : ($hasLoteCol('peso_recibido_kg') ? 'l.peso_recibido_kg' : 'NULL');
$loteHumExpr = $hasLoteCol('humedad_inicial') ? 'l.humedad_inicial' : 'NULL';
$loteFechaExpr = $hasLoteCol('fecha_entrada')
    ? 'l.fecha_entrada'
    : ($hasLoteCol('fecha_recepcion') ? 'l.fecha_recepcion' : 'NULL');
$loteEstadoExpr = $hasLoteCol('estado_proceso')
    ? 'l.estado_proceso'
    : ($hasLoteCol('estado') ? 'l.estado' : "'N/D'");

$lote = $db->fetch("
    SELECT l.id, l.codigo,
           {$loteFechaExpr} as fecha_entrada,
           {$lotePesoExpr} as peso_inicial_kg,
           {$loteHumExpr} as humedad_inicial,
           {$loteEstadoExpr} as estado_proceso,
           p.nombre as proveedor_nombre,
           p.codigo as proveedor_codigo,
           v.nombre as variedad_nombre,
           ep.nombre as estado_producto_nombre
    FROM lotes l
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    LEFT JOIN estados_producto ep ON l.estado_producto_id = ep.id
    WHERE l.id = ?
    LIMIT 1
", [$id]);

if (!$lote) {
    http_response_code(404);
    die('Lote no encontrado');
}

$empresaData = $db->fetch("SELECT nombre, logo FROM empresa LIMIT 1");

// Fermentación (último registro)
$fermentacion = null;
$ferStats = null;
if (!empty($colsFer)) {
    $ferRespJoinCol = $hasFerCol('responsable_id')
        ? 'rf.responsable_id'
        : ($hasFerCol('operador_id') ? 'rf.operador_id' : 'NULL');
    $ferFechaInicioExpr = $hasFerCol('fecha_inicio')
        ? 'rf.fecha_inicio'
        : ($hasFerCol('fecha') ? 'rf.fecha' : 'NULL');
    $ferFechaFinExpr = $hasFerCol('fecha_fin')
        ? 'rf.fecha_fin'
        : ($hasFerCol('fecha_salida') ? 'rf.fecha_salida' : 'NULL');
    $ferTempExpr = $hasFerCol('temperatura_inicial') ? 'rf.temperatura_inicial' : 'NULL';
    $ferPhExpr = $hasFerCol('ph_inicial')
        ? 'rf.ph_inicial'
        : ($hasFerCol('ph_pulpa_inicial') ? 'rf.ph_pulpa_inicial' : 'NULL');
    $ferAprobExpr = $hasFerCol('aprobado_secado') ? 'rf.aprobado_secado' : '0';
    $ferObsExpr = $hasFerCol('observaciones_finales')
        ? 'rf.observaciones_finales'
        : ($hasFerCol('observaciones')
            ? 'rf.observaciones'
            : ($hasFerCol('observaciones_generales') ? 'rf.observaciones_generales' : 'NULL'));

    $fermentacion = $db->fetch("
        SELECT rf.id,
               {$ferFechaInicioExpr} as fecha_inicio,
               {$ferFechaFinExpr} as fecha_fin,
               {$ferTempExpr} as temperatura_inicial,
               {$ferPhExpr} as ph_inicial,
               {$ferAprobExpr} as aprobado_secado,
               {$ferObsExpr} as observaciones,
               u.nombre as responsable_nombre
        FROM registros_fermentacion rf
        LEFT JOIN usuarios u ON {$ferRespJoinCol} = u.id
        WHERE rf.lote_id = ?
        ORDER BY rf.id DESC
        LIMIT 1
    ", [$id]);

    if ($fermentacion && !empty($colsFerCtrl)) {
        $fkFerCtrlCol = $hasFerCtrlCol('fermentacion_id')
            ? 'fermentacion_id'
            : ($hasFerCtrlCol('registro_fermentacion_id') ? 'registro_fermentacion_id' : null);
        if ($fkFerCtrlCol) {
            $ferTempCtrlExpr = $hasFerCtrlCol('temperatura_masa')
                ? 'temperatura_masa'
                : ($hasFerCtrlCol('temperatura_am')
                    ? 'temperatura_am'
                    : ($hasFerCtrlCol('temp_masa')
                        ? 'temp_masa'
                        : ($hasFerCtrlCol('temp_am') ? 'temp_am' : 'NULL')));
            $ferStats = $db->fetch("
                SELECT COUNT(*) as total_controles,
                       AVG({$ferTempCtrlExpr}) as temp_promedio
                FROM fermentacion_control_diario
                WHERE {$fkFerCtrlCol} = ?
            ", [$fermentacion['id']]);
        }
    }
}

// Secado (último registro)
$secado = null;
$secStats = null;
if (!empty($colsSec)) {
    $secRespJoinCol = $hasSecCol('responsable_id')
        ? 'rs.responsable_id'
        : ($hasSecCol('operador_id') ? 'rs.operador_id' : 'NULL');
    $secFechaExpr = $hasSecCol('fecha')
        ? 'rs.fecha'
        : ($hasSecCol('fecha_inicio')
            ? 'rs.fecha_inicio'
            : ($hasSecCol('created_at') ? 'DATE(rs.created_at)' : 'NULL'));
    $secHumIniExpr = $hasSecCol('humedad_inicial') ? 'rs.humedad_inicial' : 'NULL';
    $secHumFinExpr = $hasSecCol('humedad_final') ? 'rs.humedad_final' : 'NULL';
    $secPesoExpr = $hasSecCol('peso_final')
        ? 'rs.peso_final'
        : ($hasSecCol('qq_cargados')
            ? '(rs.qq_cargados * 45.3592)'
            : ($hasSecCol('cantidad_total_qq') ? '(rs.cantidad_total_qq * 45.3592)' : 'NULL'));

    $secado = $db->fetch("
        SELECT rs.id,
               {$secFechaExpr} as fecha,
               {$secHumIniExpr} as humedad_inicial,
               {$secHumFinExpr} as humedad_final,
               {$secPesoExpr} as peso_final,
               u.nombre as responsable_nombre
        FROM registros_secado rs
        LEFT JOIN usuarios u ON {$secRespJoinCol} = u.id
        WHERE rs.lote_id = ?
        ORDER BY rs.id DESC
        LIMIT 1
    ", [$id]);

    if ($secado && !empty($colsSecCtrl)) {
        $fkSecCtrlCol = $hasSecCtrlCol('secado_id')
            ? 'secado_id'
            : ($hasSecCtrlCol('registro_secado_id') ? 'registro_secado_id' : null);
        if ($fkSecCtrlCol) {
            $secStats = $db->fetch("
                SELECT COUNT(*) as total_controles,
                       AVG(temperatura) as temp_promedio
                FROM secado_control_temperatura
                WHERE {$fkSecCtrlCol} = ?
            ", [$secado['id']]);
        }
    }
}

// Pruebas de corte
$pruebas = [];
$pruebasCount = 0;
$pruebasAprob = 0;
if (!empty($colsPr)) {
    $prFechaExpr = $hasPrCol('fecha')
        ? 'pc.fecha'
        : ($hasPrCol('fecha_prueba')
            ? 'pc.fecha_prueba'
            : ($hasPrCol('created_at') ? 'DATE(pc.created_at)' : 'NULL'));
    $prTipoExpr = $hasPrCol('tipo_prueba') ? 'pc.tipo_prueba' : "'POST_SECADO'";
    $prFerExpr = $hasPrCol('porcentaje_fermentacion')
        ? 'pc.porcentaje_fermentacion'
        : ($hasPrCol('bien_fermentados')
            ? 'pc.bien_fermentados'
            : ($hasPrCol('granos_bien_fermentados') ? 'pc.granos_bien_fermentados' : 'NULL'));
    $prDefExpr = $hasPrCol('defectos_totales')
        ? 'pc.defectos_totales'
        : '(COALESCE(pc.granos_violeta,0)+COALESCE(pc.granos_pizarrosos,0)+COALESCE(pc.granos_mohosos,0)+COALESCE(pc.granos_insectados,0)+COALESCE(pc.granos_germinados,0)+COALESCE(pc.granos_planos_vanos,0)+COALESCE(pc.granos_partidos,0)+COALESCE(pc.granos_multiples,0))';
    $prDecisionExpr = $hasPrCol('decision_lote') ? 'pc.decision_lote' : 'NULL';
    $prRespJoinCol = $hasPrCol('responsable_analisis_id')
        ? 'pc.responsable_analisis_id'
        : ($hasPrCol('responsable_id')
            ? 'pc.responsable_id'
            : ($hasPrCol('usuario_id') ? 'pc.usuario_id' : 'NULL'));
    $prOrderExpr = $hasPrCol('fecha')
        ? 'pc.fecha ASC'
        : ($hasPrCol('fecha_prueba') ? 'pc.fecha_prueba ASC' : 'pc.id ASC');

    $pruebas = $db->fetchAll("
        SELECT {$prFechaExpr} as fecha,
               {$prTipoExpr} as tipo_prueba,
               {$prFerExpr} as fermentacion,
               {$prDefExpr} as defectos,
               {$prDecisionExpr} as decision,
               u.nombre as responsable
        FROM registros_prueba_corte pc
        LEFT JOIN usuarios u ON {$prRespJoinCol} = u.id
        WHERE pc.lote_id = ?
        ORDER BY {$prOrderExpr}
    ", [$id]);

    $pruebasCount = count($pruebas);
    foreach ($pruebas as $pr) {
        if (strtoupper((string)($pr['decision'] ?? '')) === 'APROBADO') {
            $pruebasAprob++;
        }
    }
}

// Historial
$historialCount = 0;
if (!empty(Helpers::getTableColumns('lotes_historial'))) {
    $historialCount = (int)($db->fetch("
        SELECT COUNT(*) as total
        FROM lotes_historial
        WHERE lote_id = ?
    ", [$id])['total'] ?? 0);
}

// Cálculos base
$diasProceso = null;
if (!empty($lote['fecha_entrada'])) {
    $fechaBase = new DateTime((string)$lote['fecha_entrada']);
    $fechaNow = new DateTime();
    $diasProceso = $fechaBase->diff($fechaNow)->days;
}

$pdf = new PdfReport(
    'Reporte Individual de Lote',
    (string)($lote['codigo'] ?? ('Lote #' . $id))
);

if (!empty($empresaData['nombre'])) {
    $pdf->setEmpresa((string)$empresaData['nombre']);
}
if (!empty($empresaData['logo'])) {
    $logoPath = trim((string)$empresaData['logo']);
    $logoUrl = (preg_match('#^https?://#i', $logoPath) || str_starts_with($logoPath, 'data:image/'))
        ? $logoPath
        : rtrim(APP_URL, '/') . '/' . ltrim($logoPath, '/');
    $pdf->setLogoUrl($logoUrl);
}

$pdf->addStats([
    ['value' => htmlspecialchars((string)($lote['estado_proceso'] ?? 'N/D')), 'label' => 'Estado actual', 'color' => 'blue'],
    ['value' => $diasProceso !== null ? ($diasProceso . ' días') : 'N/R', 'label' => 'Tiempo en proceso', 'color' => 'amber'],
    ['value' => $lote['peso_inicial_kg'] !== null ? number_format((float)$lote['peso_inicial_kg'], 1) . ' kg' : 'N/R', 'label' => 'Peso inicial', 'color' => 'emerald'],
    ['value' => $pruebasCount > 0 ? (string)$pruebasCount : '0', 'label' => 'Pruebas de corte', 'color' => 'purple'],
    ['value' => (string)$pruebasAprob, 'label' => 'Pruebas aprobadas', 'color' => 'emerald'],
    ['value' => (string)$historialCount, 'label' => 'Eventos historial', 'color' => '']
]);

$pdf->addKeyValue([
    'Código de lote' => (string)($lote['codigo'] ?? 'N/D'),
    'Fecha de entrada' => !empty($lote['fecha_entrada']) ? date('d/m/Y', strtotime((string)$lote['fecha_entrada'])) : 'N/R',
    'Proveedor' => trim((string)($lote['proveedor_codigo'] ?? '')) !== ''
        ? ((string)$lote['proveedor_codigo'] . ' - ' . (string)($lote['proveedor_nombre'] ?? ''))
        : (string)($lote['proveedor_nombre'] ?? 'N/R'),
    'Variedad' => (string)($lote['variedad_nombre'] ?? 'N/R'),
    'Estado de producto' => (string)($lote['estado_producto_nombre'] ?? 'N/R'),
    'Humedad inicial' => $lote['humedad_inicial'] !== null ? number_format((float)$lote['humedad_inicial'], 1) . '%' : 'N/R'
], 'Información General');

if ($fermentacion) {
    $pdf->addKeyValue([
        'Inicio fermentación' => !empty($fermentacion['fecha_inicio']) ? date('d/m/Y', strtotime((string)$fermentacion['fecha_inicio'])) : 'N/R',
        'Fin fermentación' => !empty($fermentacion['fecha_fin']) ? date('d/m/Y', strtotime((string)$fermentacion['fecha_fin'])) : 'En proceso',
        'Responsable' => (string)($fermentacion['responsable_nombre'] ?? 'N/R'),
        'Temp. inicial' => $fermentacion['temperatura_inicial'] !== null ? number_format((float)$fermentacion['temperatura_inicial'], 1) . ' °C' : 'N/R',
        'pH inicial' => $fermentacion['ph_inicial'] !== null ? number_format((float)$fermentacion['ph_inicial'], 2) : 'N/R',
        'Aprobado para secado' => !empty($fermentacion['aprobado_secado']) ? 'Sí' : 'No',
        'Controles diarios' => isset($ferStats['total_controles']) ? (string)$ferStats['total_controles'] : '0',
        'Temp. promedio control' => isset($ferStats['temp_promedio']) && $ferStats['temp_promedio'] !== null ? number_format((float)$ferStats['temp_promedio'], 1) . ' °C' : 'N/R'
    ], 'Fermentación');
}

if ($secado) {
    $pdf->addKeyValue([
        'Fecha secado' => !empty($secado['fecha']) ? date('d/m/Y', strtotime((string)$secado['fecha'])) : 'N/R',
        'Responsable' => (string)($secado['responsable_nombre'] ?? 'N/R'),
        'Humedad inicial' => $secado['humedad_inicial'] !== null ? number_format((float)$secado['humedad_inicial'], 1) . '%' : 'N/R',
        'Humedad final' => $secado['humedad_final'] !== null ? number_format((float)$secado['humedad_final'], 1) . '%' : 'N/R',
        'Peso final' => $secado['peso_final'] !== null ? number_format((float)$secado['peso_final'], 2) . ' kg' : 'N/R',
        'Controles temperatura' => isset($secStats['total_controles']) ? (string)$secStats['total_controles'] : '0',
        'Temp. promedio control' => isset($secStats['temp_promedio']) && $secStats['temp_promedio'] !== null ? number_format((float)$secStats['temp_promedio'], 1) . ' °C' : 'N/R'
    ], 'Secado');
}

if (!empty($pruebas)) {
    $rows = [];
    foreach ($pruebas as $pr) {
        $decision = strtoupper((string)($pr['decision'] ?? ''));
        $decisionBadge = '-';
        if ($decision !== '') {
            $decisionBadge = PdfReport::badge(
                $decision,
                $decision === 'APROBADO' ? 'green' : ($decision === 'RECHAZADO' ? 'red' : 'yellow')
            );
        }

        $rows[] = [
            !empty($pr['fecha']) ? date('d/m/Y', strtotime((string)$pr['fecha'])) : '-',
            htmlspecialchars((string)($pr['tipo_prueba'] ?? 'N/R')),
            $pr['fermentacion'] !== null ? number_format((float)$pr['fermentacion'], 1) . '%' : 'N/R',
            $pr['defectos'] !== null ? number_format((float)$pr['defectos'], 1) . '%' : 'N/R',
            $decisionBadge,
            htmlspecialchars((string)($pr['responsable'] ?? 'N/R'))
        ];
    }

    $pdf->addTable(
        ['Fecha', 'Tipo', '% Fermentación', '% Defectos', 'Decisión', 'Responsable'],
        $rows,
        'Pruebas de Corte'
    );
}

$filename = 'lote_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($lote['codigo'] ?? $id)) . '_' . date('Ymd_His') . '.html';
$pdf->output($filename);
