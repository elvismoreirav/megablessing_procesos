<?php
/**
 * Reporte Consolidado
 * Resumen ejecutivo de todos los procesos
 */

require_once __DIR__ . '/../bootstrap.php';

if (!Auth::check()) {
    redirect('/login.php');
}

$db = Database::getInstance();

// Filtros
$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fechaFin = $_GET['fecha_fin'] ?? date('Y-m-d');

// === ESTADÍSTICAS GENERALES ===

// Compatibilidad de esquema
$colsLotes = array_column($db->fetchAll("SHOW COLUMNS FROM lotes"), 'Field');
$hasLoteCol = static fn(string $name): bool => in_array($name, $colsLotes, true);

$exprFechaLote = $hasLoteCol('fecha_recepcion')
    ? 'l.fecha_recepcion'
    : ($hasLoteCol('fecha_entrada')
        ? 'l.fecha_entrada'
        : ($hasLoteCol('created_at') ? 'DATE(l.created_at)' : 'CURDATE()'));
$exprPesoRecepcion = $hasLoteCol('peso_recepcion_kg')
    ? 'l.peso_recepcion_kg'
    : ($hasLoteCol('peso_inicial_kg') ? 'l.peso_inicial_kg' : '0');
$exprPesoFinalLote = $hasLoteCol('peso_final_kg')
    ? 'l.peso_final_kg'
    : ($hasLoteCol('peso_actual_kg') ? 'l.peso_actual_kg' : 'NULL');

$colsFermentacion = array_column($db->fetchAll("SHOW COLUMNS FROM registros_fermentacion"), 'Field');
$hasFerCol = static fn(string $name): bool => in_array($name, $colsFermentacion, true);

$exprFerFechaInicio = $hasFerCol('fecha_inicio')
    ? 'rf.fecha_inicio'
    : ($hasFerCol('created_at') ? 'DATE(rf.created_at)' : 'CURDATE()');
$exprFerFechaFin = $hasFerCol('fecha_fin')
    ? 'rf.fecha_fin'
    : ($hasFerCol('fecha_salida')
        ? 'rf.fecha_salida'
        : ($hasFerCol('aprobado_secado') ? "CASE WHEN rf.aprobado_secado = 1 THEN DATE(rf.updated_at) ELSE NULL END" : 'NULL'));
$exprFerTempInicial = $hasFerCol('temperatura_inicial') ? 'rf.temperatura_inicial' : 'NULL';
$exprFerPesoInicial = $hasFerCol('peso_inicial')
    ? 'rf.peso_inicial'
    : ($hasFerCol('peso_lote_kg') ? 'rf.peso_lote_kg' : $exprPesoRecepcion);
$exprFerPesoFinal = $hasFerCol('peso_final') ? 'rf.peso_final' : $exprPesoFinalLote;
$condFerFinalizada = "({$exprFerFechaFin}) IS NOT NULL";

$joinFerAgg = '';
$exprFerVolteos = $hasFerCol('total_volteos') ? 'rf.total_volteos' : 'NULL';
$exprFerTempProm = $hasFerCol('temperatura') ? 'rf.temperatura' : 'NULL';
$tablaControlFer = $db->fetch("SHOW TABLES LIKE 'fermentacion_control_diario'");
if ($tablaControlFer) {
    $colsControl = array_column($db->fetchAll("SHOW COLUMNS FROM fermentacion_control_diario"), 'Field');
    $hasControlCol = static fn(string $name): bool => in_array($name, $colsControl, true);
    $fkControl = $hasControlCol('fermentacion_id')
        ? 'fermentacion_id'
        : ($hasControlCol('registro_fermentacion_id') ? 'registro_fermentacion_id' : null);
    $tempControlExpr = $hasControlCol('temp_masa')
        ? 'temp_masa'
        : ($hasControlCol('temperatura') ? 'temperatura' : null);
    if ($fkControl) {
        $aggTemp = $tempControlExpr ? "AVG({$tempControlExpr}) as temp_promedio," : "NULL as temp_promedio,";
        $joinFerAgg = "LEFT JOIN (
            SELECT {$fkControl} as rf_id,
                   SUM(CASE WHEN COALESCE(volteo, 0) IN (1, '1', 'SI', 'S', 'TRUE', 'true') THEN 1 ELSE 0 END) as total_volteos,
                   {$aggTemp}
                   COUNT(*) as total_controles
            FROM fermentacion_control_diario
            GROUP BY {$fkControl}
        ) fcd ON fcd.rf_id = rf.id";
        $exprFerVolteos = 'COALESCE(fcd.total_volteos, 0)';
        $exprFerTempProm = 'fcd.temp_promedio';
    }
}

$colsSecado = array_column($db->fetchAll("SHOW COLUMNS FROM registros_secado"), 'Field');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSecado, true);

$exprSecFechaInicio = $hasSecCol('fecha_inicio')
    ? 'rs.fecha_inicio'
    : ($hasSecCol('fecha')
        ? 'rs.fecha'
        : ($hasSecCol('fecha_carga')
            ? 'DATE(rs.fecha_carga)'
            : ($hasSecCol('created_at') ? 'DATE(rs.created_at)' : 'CURDATE()')));
$exprSecFechaFin = $hasSecCol('fecha_fin') ? 'rs.fecha_fin' : 'NULL';
$exprSecHumedadInicial = $hasSecCol('humedad_inicial') ? 'rs.humedad_inicial' : 'NULL';
$exprSecHumedadFinal = $hasSecCol('humedad_final') ? 'rs.humedad_final' : 'NULL';
$exprSecTipo = $hasSecCol('tipo_secado') ? 'rs.tipo_secado' : 'NULL';

$tablaSecadoras = $db->fetch("SHOW TABLES LIKE 'secadoras'") ? 'secadoras' : null;
$joinSecadoras = '';
if (!$hasSecCol('tipo_secado') && $tablaSecadoras) {
    $colsSecadoras = array_column($db->fetchAll("SHOW COLUMNS FROM {$tablaSecadoras}"), 'Field');
    $hasSecadoraCol = static fn(string $name): bool => in_array($name, $colsSecadoras, true);
    $joinSecadoras = "LEFT JOIN {$tablaSecadoras} s ON rs.secadora_id = s.id";
    if ($hasSecadoraCol('tipo')) {
        $exprSecTipo = "CASE WHEN s.tipo = 'SOLAR' THEN 'SOLAR' WHEN s.tipo IN ('INDUSTRIAL', 'ARTESANAL') THEN 'MECANICO' ELSE s.tipo END";
    }
}

$condSecFinalizado = $hasSecCol('fecha_fin')
    ? "rs.fecha_fin IS NOT NULL"
    : ($hasSecCol('humedad_final') ? "rs.humedad_final IS NOT NULL" : "1=0");

$colsPrueba = array_column($db->fetchAll("SHOW COLUMNS FROM registros_prueba_corte"), 'Field');
$hasPrCol = static fn(string $name): bool => in_array($name, $colsPrueba, true);

$exprPrTotal = $hasPrCol('total_granos')
    ? 'pc.total_granos'
    : ($hasPrCol('granos_analizados') ? 'pc.granos_analizados' : '100');
$exprPrFermentadosCount = $hasPrCol('granos_fermentados')
    ? 'pc.granos_fermentados'
    : ($hasPrCol('bien_fermentados') ? 'pc.bien_fermentados' : '0');
$exprPrPizarra = $hasPrCol('granos_pizarra')
    ? 'pc.granos_pizarra'
    : ($hasPrCol('pizarrosos') ? 'pc.pizarrosos' : '0');
$exprPrVioletas = $hasPrCol('granos_violetas')
    ? 'pc.granos_violetas'
    : ($hasPrCol('violeta') ? 'pc.violeta' : '0');
$exprPrMohosos = $hasPrCol('granos_mohosos')
    ? 'pc.granos_mohosos'
    : ($hasPrCol('mohosos') ? 'pc.mohosos' : '0');
$exprPrGerminados = $hasPrCol('granos_germinados')
    ? 'pc.granos_germinados'
    : ($hasPrCol('germinados') ? 'pc.germinados' : '0');
$exprPrDanados = $hasPrCol('granos_danados')
    ? 'pc.granos_danados'
    : ($hasPrCol('granos_dañados')
        ? 'pc.`granos_dañados`'
        : ($hasPrCol('insectados') ? 'pc.insectados' : '0'));
$exprPrDefectosCount = "({$exprPrPizarra} + {$exprPrVioletas} + {$exprPrMohosos} + {$exprPrGerminados} + {$exprPrDanados})";
$exprPrFermentacion = $hasPrCol('porcentaje_fermentacion')
    ? 'pc.porcentaje_fermentacion'
    : "(CASE WHEN {$exprPrTotal} > 0 THEN ({$exprPrFermentadosCount} / {$exprPrTotal}) * 100 ELSE 0 END)";
$exprPrDefectos = $hasPrCol('porcentaje_defectos')
    ? 'pc.porcentaje_defectos'
    : ($hasPrCol('defectos_totales')
        ? 'pc.defectos_totales'
        : "(CASE WHEN {$exprPrTotal} > 0 THEN ({$exprPrDefectosCount} / {$exprPrTotal}) * 100 ELSE 0 END)");
$exprPrCalidad = $hasPrCol('calidad_determinada')
    ? 'pc.calidad_determinada'
    : ($hasPrCol('calidad_resultado')
        ? 'pc.calidad_resultado'
        : ($hasPrCol('decision_lote')
            ? "(CASE pc.decision_lote
                    WHEN 'RECHAZADO' THEN 'RECHAZADO'
                    WHEN 'REPROCESO' THEN 'RECHAZADO'
                    WHEN 'APROBADO' THEN 'NACIONAL'
                    WHEN 'MEZCLA' THEN 'NACIONAL'
                    ELSE pc.decision_lote
                END)"
            : "'NACIONAL'"));

$tablaEstadosCalidad = $db->fetch("SHOW TABLES LIKE 'estados_calidad'") ? 'estados_calidad' : null;
$joinCalidadLote = '';
$exprCalidadNombre = 'NULL';
$exprCalidadColor = 'NULL';
if ($tablaEstadosCalidad && $hasLoteCol('estado_calidad_id')) {
    $joinCalidadLote = "LEFT JOIN {$tablaEstadosCalidad} ec ON l.estado_calidad_id = ec.id";
    $exprCalidadNombre = 'ec.nombre';
    $exprCalidadColor = 'ec.color';
} elseif ($hasLoteCol('calidad_final')) {
    $exprCalidadNombre = 'l.calidad_final';
    $exprCalidadColor = "(CASE l.calidad_final
        WHEN 'PREMIUM' THEN '#059669'
        WHEN 'EXPORTACION' THEN '#D97706'
        WHEN 'NACIONAL' THEN '#2563EB'
        WHEN 'RECHAZADO' THEN '#DC2626'
        ELSE NULL END)";
} elseif ($hasLoteCol('calidad')) {
    $exprCalidadNombre = 'l.calidad';
    $exprCalidadColor = "(CASE l.calidad
        WHEN 'PREMIUM' THEN '#059669'
        WHEN 'EXPORTACION' THEN '#D97706'
        WHEN 'NACIONAL' THEN '#2563EB'
        WHEN 'RECHAZADO' THEN '#DC2626'
        ELSE NULL END)";
}

// Lotes
$statsLotes = $db->fetch("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN estado_proceso = 'FINALIZADO' THEN 1 END) as finalizados,
        COUNT(CASE WHEN estado_proceso NOT IN ('FINALIZADO', 'RECHAZADO') THEN 1 END) as en_proceso,
        COUNT(CASE WHEN estado_proceso = 'RECHAZADO' THEN 1 END) as rechazados,
        COALESCE(SUM({$exprPesoRecepcion}), 0) as kg_recibidos,
        COALESCE(SUM(CASE WHEN estado_proceso = 'FINALIZADO' THEN {$exprPesoFinalLote} END), 0) as kg_finalizados
    FROM lotes l
    WHERE {$exprFechaLote} BETWEEN ? AND ?
", [$fechaInicio, $fechaFin]);

// Rendimiento promedio
$rendimiento = $db->fetch("
    SELECT AVG({$exprPesoFinalLote} / {$exprPesoRecepcion} * 100) as rendimiento
    FROM lotes l
    WHERE estado_proceso = 'FINALIZADO' 
    AND {$exprPesoRecepcion} > 0
    AND {$exprFechaLote} BETWEEN ? AND ?
", [$fechaInicio, $fechaFin]);

// Fermentación
$statsFermentacion = $db->fetch("
    SELECT 
        COUNT(rf.id) as total_procesos,
        AVG(DATEDIFF(COALESCE({$exprFerFechaFin}, CURDATE()), {$exprFerFechaInicio})) as dias_promedio,
        AVG({$exprFerVolteos}) as volteos_promedio,
        AVG({$exprFerTempInicial}) as temp_inicial_prom,
        AVG({$exprFerTempProm}) as temp_promedio
    FROM registros_fermentacion rf
    JOIN lotes l ON rf.lote_id = l.id
    {$joinFerAgg}
    WHERE {$exprFechaLote} BETWEEN ? AND ?
", [$fechaInicio, $fechaFin]);

// Secado
$statsSecado = $db->fetch("
    SELECT 
        COUNT(rs.id) as total_procesos,
        AVG(DATEDIFF(COALESCE({$exprSecFechaFin}, CURDATE()), {$exprSecFechaInicio})) as dias_promedio,
        AVG({$exprSecHumedadInicial}) as humedad_inicial_prom,
        AVG({$exprSecHumedadFinal}) as humedad_final_prom,
        COUNT(CASE WHEN {$exprSecHumedadFinal} <= 7 THEN 1 END) as lotes_optimos,
        COUNT(CASE WHEN ({$exprSecTipo}) = 'SOLAR' THEN 1 END) as solar,
        COUNT(CASE WHEN ({$exprSecTipo}) = 'MECANICO' THEN 1 END) as mecanico,
        COUNT(CASE WHEN ({$exprSecTipo}) = 'MIXTO' THEN 1 END) as mixto
    FROM registros_secado rs
    JOIN lotes l ON rs.lote_id = l.id
    {$joinSecadoras}
    WHERE {$exprFechaLote} BETWEEN ? AND ?
", [$fechaInicio, $fechaFin]);

// Prueba de Corte / Calidad
$statsCalidad = $db->fetch("
    SELECT 
        COUNT(*) as total_pruebas,
        AVG({$exprPrFermentacion}) as ferm_promedio,
        AVG({$exprPrDefectos}) as defectos_promedio,
        COUNT(CASE WHEN ({$exprPrCalidad}) = 'PREMIUM' THEN 1 END) as premium,
        COUNT(CASE WHEN ({$exprPrCalidad}) = 'EXPORTACION' THEN 1 END) as exportacion,
        COUNT(CASE WHEN ({$exprPrCalidad}) = 'NACIONAL' THEN 1 END) as nacional,
        COUNT(CASE WHEN ({$exprPrCalidad}) = 'RECHAZADO' THEN 1 END) as rechazado
    FROM registros_prueba_corte pc
    JOIN lotes l ON pc.lote_id = l.id
    WHERE {$exprFechaLote} BETWEEN ? AND ?
", [$fechaInicio, $fechaFin]);

// Top proveedores
$topProveedores = $db->fetchAll("
    SELECT 
        p.nombre,
        COUNT(l.id) as lotes,
        SUM({$exprPesoRecepcion}) as kg_total,
        AVG(CASE WHEN {$exprPesoFinalLote} > 0 AND {$exprPesoRecepcion} > 0 THEN {$exprPesoFinalLote} / {$exprPesoRecepcion} * 100 END) as rendimiento
    FROM proveedores p
    JOIN lotes l ON p.id = l.proveedor_id
    WHERE {$exprFechaLote} BETWEEN ? AND ?
    GROUP BY p.id, p.nombre
    ORDER BY kg_total DESC
    LIMIT 5
", [$fechaInicio, $fechaFin]);

// Lotes recientes
$lotesRecientes = $db->fetchAll("
    SELECT l.*, p.nombre as proveedor_nombre,
           {$exprCalidadNombre} as calidad_nombre,
           {$exprCalidadColor} as calidad_color,
           {$exprFechaLote} as fecha_recepcion,
           {$exprPesoRecepcion} as peso_recepcion_kg,
           {$exprPesoFinalLote} as peso_final_kg
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    {$joinCalidadLote}
    WHERE {$exprFechaLote} BETWEEN ? AND ?
    ORDER BY {$exprFechaLote} DESC
    LIMIT 10
", [$fechaInicio, $fechaFin]);

// Distribución por variedad
$porVariedad = $db->fetchAll("
    SELECT v.nombre, COUNT(l.id) as lotes, SUM({$exprPesoRecepcion}) as kg
    FROM variedades v
    JOIN lotes l ON v.id = l.variedad_id
    WHERE {$exprFechaLote} BETWEEN ? AND ?
    GROUP BY v.id, v.nombre
    ORDER BY kg DESC
", [$fechaInicio, $fechaFin]);

// Exportar CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_consolidado_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM
    
    // Resumen ejecutivo
    fputcsv($output, ['REPORTE CONSOLIDADO MEGABLESSING']);
    fputcsv($output, ['Período:', $fechaInicio, 'a', $fechaFin]);
    fputcsv($output, []);
    
    fputcsv($output, ['=== PRODUCCIÓN ===']);
    fputcsv($output, ['Total Lotes', $statsLotes['total']]);
    fputcsv($output, ['Lotes Finalizados', $statsLotes['finalizados']]);
    fputcsv($output, ['Lotes En Proceso', $statsLotes['en_proceso']]);
    fputcsv($output, ['Lotes Rechazados', $statsLotes['rechazados']]);
    fputcsv($output, ['Kg Recibidos', number_format($statsLotes['kg_recibidos'], 2)]);
    fputcsv($output, ['Kg Finalizados', number_format($statsLotes['kg_finalizados'], 2)]);
    fputcsv($output, ['Rendimiento Promedio %', number_format($rendimiento['rendimiento'] ?? 0, 1)]);
    fputcsv($output, []);
    
    fputcsv($output, ['=== FERMENTACIÓN ===']);
    fputcsv($output, ['Total Procesos', $statsFermentacion['total_procesos']]);
    fputcsv($output, ['Días Promedio', number_format($statsFermentacion['dias_promedio'] ?? 0, 1)]);
    fputcsv($output, ['Volteos Promedio', number_format($statsFermentacion['volteos_promedio'] ?? 0, 1)]);
    fputcsv($output, ['Temp. Inicial Prom. °C', number_format($statsFermentacion['temp_inicial_prom'] ?? 0, 1)]);
    fputcsv($output, []);
    
    fputcsv($output, ['=== SECADO ===']);
    fputcsv($output, ['Total Procesos', $statsSecado['total_procesos']]);
    fputcsv($output, ['Días Promedio', number_format($statsSecado['dias_promedio'] ?? 0, 1)]);
    fputcsv($output, ['Humedad Inicial Prom. %', number_format($statsSecado['humedad_inicial_prom'] ?? 0, 1)]);
    fputcsv($output, ['Humedad Final Prom. %', number_format($statsSecado['humedad_final_prom'] ?? 0, 1)]);
    fputcsv($output, ['Lotes Óptimos (≤7%)', $statsSecado['lotes_optimos']]);
    fputcsv($output, ['Secado Solar', $statsSecado['solar']]);
    fputcsv($output, ['Secado Mecánico', $statsSecado['mecanico']]);
    fputcsv($output, ['Secado Mixto', $statsSecado['mixto']]);
    fputcsv($output, []);
    
    fputcsv($output, ['=== CALIDAD ===']);
    fputcsv($output, ['Total Pruebas', $statsCalidad['total_pruebas']]);
    fputcsv($output, ['% Fermentación Prom.', number_format($statsCalidad['ferm_promedio'] ?? 0, 1)]);
    fputcsv($output, ['% Defectos Prom.', number_format($statsCalidad['defectos_promedio'] ?? 0, 1)]);
    fputcsv($output, ['Premium', $statsCalidad['premium']]);
    fputcsv($output, ['Exportación', $statsCalidad['exportacion']]);
    fputcsv($output, ['Nacional', $statsCalidad['nacional']]);
    fputcsv($output, ['Rechazado', $statsCalidad['rechazado']]);
    fputcsv($output, []);
    
    fputcsv($output, ['=== TOP 5 PROVEEDORES ===']);
    fputcsv($output, ['Proveedor', 'Lotes', 'Kg Total', 'Rendimiento %']);
    foreach ($topProveedores as $prov) {
        fputcsv($output, [$prov['nombre'], $prov['lotes'], number_format($prov['kg_total'], 2), number_format($prov['rendimiento'] ?? 0, 1)]);
    }
    
    fclose($output);
    exit;
}

$pageTitle = 'Reporte Consolidado';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Reporte Consolidado</h1>
            <p class="text-gray-600">Resumen ejecutivo de todos los procesos</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/reportes/" class="text-amber-600 hover:text-amber-700">
                <i class="fas fa-arrow-left mr-2"></i>Volver a Reportes
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <form method="GET" class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Inicio</label>
                <input type="date" name="fecha_inicio" value="<?= $fechaInicio ?>"
                       class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Fin</label>
                <input type="date" name="fecha_fin" value="<?= $fechaFin ?>"
                       class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500">
            </div>
            <button type="submit" class="bg-amber-600 text-white px-4 py-2 rounded-lg hover:bg-amber-700">
                <i class="fas fa-search mr-2"></i>Generar
            </button>
            <a href="?fecha_inicio=<?= date('Y-01-01') ?>&fecha_fin=<?= date('Y-m-d') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Este Año
            </a>
            <a href="?fecha_inicio=<?= $fechaInicio ?>&fecha_fin=<?= $fechaFin ?>&export=csv" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700">
                <i class="fas fa-file-csv mr-2"></i>Exportar CSV
            </a>
            <a href="/api/reportes/consolidado-pdf.php?fecha_inicio=<?= $fechaInicio ?>&fecha_fin=<?= $fechaFin ?>" target="_blank" class="bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700" title="Abrir versión para imprimir">
                <i class="fas fa-file-pdf mr-2"></i>Imprimir PDF
            </a>
        </form>
    </div>

    <!-- Producción General -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-amber-50 to-orange-50">
            <h2 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-chart-line text-amber-600 mr-2"></i>Resumen de Producción
            </h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
                <div class="text-center p-4 bg-blue-50 rounded-xl">
                    <p class="text-3xl font-bold text-blue-600"><?= number_format($statsLotes['total']) ?></p>
                    <p class="text-sm text-gray-600 mt-1">Lotes Totales</p>
                </div>
                <div class="text-center p-4 bg-green-50 rounded-xl">
                    <p class="text-3xl font-bold text-green-600"><?= number_format($statsLotes['finalizados']) ?></p>
                    <p class="text-sm text-gray-600 mt-1">Finalizados</p>
                </div>
                <div class="text-center p-4 bg-amber-50 rounded-xl">
                    <p class="text-3xl font-bold text-amber-600"><?= number_format($statsLotes['en_proceso']) ?></p>
                    <p class="text-sm text-gray-600 mt-1">En Proceso</p>
                </div>
                <div class="text-center p-4 bg-red-50 rounded-xl">
                    <p class="text-3xl font-bold text-red-600"><?= number_format($statsLotes['rechazados']) ?></p>
                    <p class="text-sm text-gray-600 mt-1">Rechazados</p>
                </div>
                <div class="text-center p-4 bg-purple-50 rounded-xl">
                    <p class="text-3xl font-bold text-purple-600"><?= number_format($statsLotes['kg_recibidos'], 0) ?></p>
                    <p class="text-sm text-gray-600 mt-1">Kg Recibidos</p>
                </div>
                <div class="text-center p-4 bg-teal-50 rounded-xl">
                    <p class="text-3xl font-bold text-teal-600"><?= number_format($statsLotes['kg_finalizados'], 0) ?></p>
                    <p class="text-sm text-gray-600 mt-1">Kg Finalizados</p>
                </div>
                <div class="text-center p-4 bg-indigo-50 rounded-xl">
                    <p class="text-3xl font-bold text-indigo-600"><?= number_format($rendimiento['rendimiento'] ?? 0, 1) ?>%</p>
                    <p class="text-sm text-gray-600 mt-1">Rendimiento</p>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Fermentación -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-orange-200 bg-gradient-to-r from-orange-50 to-red-50">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-fire text-orange-500 mr-2"></i>Fermentación
                </h2>
            </div>
            <div class="p-6 space-y-4">
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Procesos</span>
                    <span class="text-xl font-bold text-gray-900"><?= $statsFermentacion['total_procesos'] ?? 0 ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Días Promedio</span>
                    <span class="text-xl font-bold text-orange-600"><?= number_format($statsFermentacion['dias_promedio'] ?? 0, 1) ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Volteos Promedio</span>
                    <span class="text-xl font-bold text-gray-900"><?= number_format($statsFermentacion['volteos_promedio'] ?? 0, 1) ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Temp. Inicial Prom.</span>
                    <span class="text-xl font-bold text-red-600"><?= number_format($statsFermentacion['temp_inicial_prom'] ?? 0, 1) ?>°C</span>
                </div>
            </div>
        </div>

        <!-- Secado -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-amber-200 bg-gradient-to-r from-amber-50 to-yellow-50">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-sun text-amber-500 mr-2"></i>Secado
                </h2>
            </div>
            <div class="p-6 space-y-4">
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Procesos</span>
                    <span class="text-xl font-bold text-gray-900"><?= $statsSecado['total_procesos'] ?? 0 ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Días Promedio</span>
                    <span class="text-xl font-bold text-amber-600"><?= number_format($statsSecado['dias_promedio'] ?? 0, 1) ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Humedad Final Prom.</span>
                    <span class="text-xl font-bold <?= ($statsSecado['humedad_final_prom'] ?? 0) <= 7 ? 'text-green-600' : 'text-amber-600' ?>">
                        <?= number_format($statsSecado['humedad_final_prom'] ?? 0, 1) ?>%
                    </span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Lotes Óptimos (≤7%)</span>
                    <span class="text-xl font-bold text-green-600"><?= $statsSecado['lotes_optimos'] ?? 0 ?></span>
                </div>
                <div class="pt-2 border-t border-gray-100">
                    <div class="flex justify-between text-sm">
                        <span class="text-yellow-600"><i class="fas fa-sun mr-1"></i>Solar: <?= $statsSecado['solar'] ?? 0 ?></span>
                        <span class="text-blue-600"><i class="fas fa-cog mr-1"></i>Mecánico: <?= $statsSecado['mecanico'] ?? 0 ?></span>
                        <span class="text-teal-600"><i class="fas fa-exchange-alt mr-1"></i>Mixto: <?= $statsSecado['mixto'] ?? 0 ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Calidad -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-emerald-200 bg-gradient-to-r from-emerald-50 to-teal-50">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-certificate text-emerald-500 mr-2"></i>Calidad
                </h2>
            </div>
            <div class="p-6 space-y-4">
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Pruebas Realizadas</span>
                    <span class="text-xl font-bold text-gray-900"><?= $statsCalidad['total_pruebas'] ?? 0 ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">% Fermentación Prom.</span>
                    <span class="text-xl font-bold <?= ($statsCalidad['ferm_promedio'] ?? 0) >= 70 ? 'text-green-600' : 'text-amber-600' ?>">
                        <?= number_format($statsCalidad['ferm_promedio'] ?? 0, 1) ?>%
                    </span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">% Defectos Prom.</span>
                    <span class="text-xl font-bold <?= ($statsCalidad['defectos_promedio'] ?? 0) <= 5 ? 'text-green-600' : 'text-red-600' ?>">
                        <?= number_format($statsCalidad['defectos_promedio'] ?? 0, 1) ?>%
                    </span>
                </div>
                <div class="pt-2 border-t border-gray-100 grid grid-cols-4 gap-2 text-center">
                    <div>
                        <span class="block text-lg font-bold text-emerald-600"><?= $statsCalidad['premium'] ?? 0 ?></span>
                        <span class="text-xs text-gray-500">Premium</span>
                    </div>
                    <div>
                        <span class="block text-lg font-bold text-amber-600"><?= $statsCalidad['exportacion'] ?? 0 ?></span>
                        <span class="text-xs text-gray-500">Export.</span>
                    </div>
                    <div>
                        <span class="block text-lg font-bold text-blue-600"><?= $statsCalidad['nacional'] ?? 0 ?></span>
                        <span class="text-xs text-gray-500">Nacional</span>
                    </div>
                    <div>
                        <span class="block text-lg font-bold text-red-600"><?= $statsCalidad['rechazado'] ?? 0 ?></span>
                        <span class="text-xs text-gray-500">Rechaz.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Top Proveedores -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-purple-50 to-indigo-50">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-trophy text-purple-500 mr-2"></i>Top 5 Proveedores
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Proveedor</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Lotes</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Kg Total</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Rend. %</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($topProveedores as $i => $prov): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <span class="w-6 h-6 <?= $i === 0 ? 'bg-yellow-400 text-yellow-900' : ($i === 1 ? 'bg-gray-300 text-gray-700' : ($i === 2 ? 'bg-amber-600 text-white' : 'bg-gray-100 text-gray-600')) ?> rounded-full inline-flex items-center justify-center text-xs font-bold">
                                    <?= $i + 1 ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($prov['nombre']) ?></td>
                            <td class="px-4 py-3 text-center"><?= $prov['lotes'] ?></td>
                            <td class="px-4 py-3 text-right font-medium"><?= number_format($prov['kg_total'], 0) ?></td>
                            <td class="px-4 py-3 text-right">
                                <span class="<?= ($prov['rendimiento'] ?? 0) >= 35 ? 'text-green-600' : 'text-amber-600' ?>">
                                    <?= number_format($prov['rendimiento'] ?? 0, 1) ?>%
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topProveedores)): ?>
                        <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">Sin datos</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Distribución por Variedad -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-green-50 to-emerald-50">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-leaf text-green-500 mr-2"></i>Distribución por Variedad
                </h2>
            </div>
            <div class="p-6">
                <?php if (!empty($porVariedad)): 
                    $totalKg = array_sum(array_column($porVariedad, 'kg'));
                ?>
                <div class="space-y-4">
                    <?php foreach ($porVariedad as $var): 
                        $porcentaje = $totalKg > 0 ? ($var['kg'] / $totalKg * 100) : 0;
                    ?>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="font-medium text-gray-900"><?= htmlspecialchars($var['nombre']) ?></span>
                            <span class="text-gray-600"><?= number_format($var['kg'], 0) ?> kg (<?= number_format($porcentaje, 1) ?>%)</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3">
                            <div class="bg-gradient-to-r from-green-500 to-emerald-500 h-3 rounded-full" style="width: <?= $porcentaje ?>%"></div>
                        </div>
                        <div class="text-xs text-gray-500 mt-1"><?= $var['lotes'] ?> lotes</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-center text-gray-500 py-8">Sin datos de variedades</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Lotes Recientes -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-blue-50 to-indigo-50">
            <h2 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-clock text-blue-500 mr-2"></i>Últimos 10 Lotes
            </h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Proveedor</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Peso Rec.</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Peso Final</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Estado</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Calidad</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($lotesRecientes as $lote): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="/lotes/ver.php?id=<?= $lote['id'] ?>" class="font-mono text-amber-600 hover:text-amber-800">
                                <?= htmlspecialchars($lote['codigo']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-sm"><?= htmlspecialchars($lote['proveedor_nombre']) ?></td>
                        <td class="px-4 py-3 text-sm"><?= date('d/m/Y', strtotime($lote['fecha_recepcion'])) ?></td>
                        <td class="px-4 py-3 text-right"><?= number_format($lote['peso_recepcion_kg'], 1) ?> kg</td>
                        <td class="px-4 py-3 text-right"><?= $lote['peso_final_kg'] ? number_format($lote['peso_final_kg'], 1) . ' kg' : '-' ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php
                            $estadoColors = [
                                'RECEPCION' => 'bg-gray-100 text-gray-700',
                                'FERMENTACION' => 'bg-orange-100 text-orange-700',
                                'SECADO' => 'bg-amber-100 text-amber-700',
                                'CALIDAD_POST' => 'bg-blue-100 text-blue-700',
                                'EMPAQUETADO' => 'bg-purple-100 text-purple-700',
                                'FINALIZADO' => 'bg-green-100 text-green-700',
                                'RECHAZADO' => 'bg-red-100 text-red-700',
                            ];
                            $colorClass = $estadoColors[$lote['estado_proceso']] ?? 'bg-gray-100 text-gray-700';
                            ?>
                            <span class="<?= $colorClass ?> px-2 py-1 rounded-full text-xs font-medium">
                                <?= $lote['estado_proceso'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($lote['calidad_nombre']): ?>
                            <span class="px-2 py-1 rounded-full text-xs font-medium" style="background-color: <?= $lote['calidad_color'] ?>20; color: <?= $lote['calidad_color'] ?>">
                                <?= htmlspecialchars($lote['calidad_nombre']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($lotesRecientes)): ?>
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">No hay lotes en el período seleccionado</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
