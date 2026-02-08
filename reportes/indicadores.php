<?php
/**
 * Indicadores - KPIs y Métricas
 * Panel de indicadores clave de rendimiento
 */

require_once __DIR__ . '/../bootstrap.php';

if (!Auth::check()) {
    redirect('/login.php');
}

$pageTitle = 'Indicadores de Rendimiento';
$db = Database::getInstance();

// Período de análisis
$fechaDesde = $_GET['fecha_desde'] ?? date('Y-01-01');
$fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

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
$ferTieneCierre = $hasFerCol('fecha_fin') || $hasFerCol('fecha_salida') || $hasFerCol('aprobado_secado');
$condFerFinalizada = $ferTieneCierre ? "({$exprFerFechaFin}) IS NOT NULL" : "1=0";
$condFerActiva = $ferTieneCierre ? "({$exprFerFechaFin}) IS NULL" : "1=1";

$exprFerTempInicial = $hasFerCol('temperatura_inicial') ? 'rf.temperatura_inicial' : 'NULL';
$exprFerPesoInicial = $hasFerCol('peso_inicial')
    ? 'rf.peso_inicial'
    : ($hasFerCol('peso_lote_kg') ? 'rf.peso_lote_kg' : $exprPesoRecepcion);
$exprFerPesoFinal = $hasFerCol('peso_final') ? 'rf.peso_final' : $exprPesoFinalLote;

$tablaControlFer = $db->fetch("SHOW TABLES LIKE 'fermentacion_control_diario'");
$joinVolteos = '';
$exprFerVolteos = $hasFerCol('total_volteos') ? 'rf.total_volteos' : '0';
if (!$hasFerCol('total_volteos') && $tablaControlFer) {
    $colsControl = array_column($db->fetchAll("SHOW COLUMNS FROM fermentacion_control_diario"), 'Field');
    $hasControlCol = static fn(string $name): bool => in_array($name, $colsControl, true);
    $fkControl = $hasControlCol('fermentacion_id')
        ? 'fermentacion_id'
        : ($hasControlCol('registro_fermentacion_id') ? 'registro_fermentacion_id' : null);
    if ($fkControl) {
        $joinVolteos = "LEFT JOIN (
            SELECT {$fkControl} as rf_id,
                   SUM(CASE WHEN COALESCE(volteo, 0) IN (1, '1', 'SI', 'S', 'TRUE', 'true') THEN 1 ELSE 0 END) as total_volteos
            FROM fermentacion_control_diario
            GROUP BY {$fkControl}
        ) fcd ON fcd.rf_id = rf.id";
        $exprFerVolteos = 'COALESCE(fcd.total_volteos, 0)';
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
$secTieneCierre = $hasSecCol('fecha_fin') || $hasSecCol('humedad_final');
$condSecFinalizado = $hasSecCol('fecha_fin')
    ? "rs.fecha_fin IS NOT NULL"
    : ($hasSecCol('humedad_final') ? "rs.humedad_final IS NOT NULL" : "1=0");

$exprSecHumedadInicial = $hasSecCol('humedad_inicial') ? 'rs.humedad_inicial' : 'NULL';
$exprSecHumedadFinal = $hasSecCol('humedad_final') ? 'rs.humedad_final' : 'NULL';

$tablaSecadoras = $db->fetch("SHOW TABLES LIKE 'secadoras'") ? 'secadoras' : null;
$joinSecadoras = '';
$exprSecTipo = $hasSecCol('tipo_secado') ? 'rs.tipo_secado' : "NULL";
if (!$hasSecCol('tipo_secado') && $tablaSecadoras) {
    $colsSecadoras = array_column($db->fetchAll("SHOW COLUMNS FROM {$tablaSecadoras}"), 'Field');
    $hasSecadoraCol = static fn(string $name): bool => in_array($name, $colsSecadoras, true);
    $joinSecadoras = "LEFT JOIN {$tablaSecadoras} s ON rs.secadora_id = s.id";
    if ($hasSecadoraCol('tipo')) {
        $exprSecTipo = "CASE WHEN s.tipo = 'SOLAR' THEN 'SOLAR' WHEN s.tipo IN ('INDUSTRIAL', 'ARTESANAL') THEN 'MECANICO' ELSE s.tipo END";
    }
}

$colsPrueba = array_column($db->fetchAll("SHOW COLUMNS FROM registros_prueba_corte"), 'Field');
$hasPrCol = static fn(string $name): bool => in_array($name, $colsPrueba, true);

$exprPrTotal = $hasPrCol('total_granos')
    ? 'rpc.total_granos'
    : ($hasPrCol('granos_analizados') ? 'rpc.granos_analizados' : '100');
$exprPrFermentadosCount = $hasPrCol('granos_fermentados')
    ? 'rpc.granos_fermentados'
    : ($hasPrCol('bien_fermentados') ? 'rpc.bien_fermentados' : '0');
$exprPrPizarra = $hasPrCol('granos_pizarra')
    ? 'rpc.granos_pizarra'
    : ($hasPrCol('pizarrosos') ? 'rpc.pizarrosos' : '0');
$exprPrVioletas = $hasPrCol('granos_violetas')
    ? 'rpc.granos_violetas'
    : ($hasPrCol('violeta') ? 'rpc.violeta' : '0');
$exprPrMohosos = $hasPrCol('granos_mohosos')
    ? 'rpc.granos_mohosos'
    : ($hasPrCol('mohosos') ? 'rpc.mohosos' : '0');
$exprPrGerminados = $hasPrCol('granos_germinados')
    ? 'rpc.granos_germinados'
    : ($hasPrCol('germinados') ? 'rpc.germinados' : '0');
$exprPrDanados = $hasPrCol('granos_danados')
    ? 'rpc.granos_danados'
    : ($hasPrCol('granos_dañados')
        ? 'rpc.`granos_dañados`'
        : ($hasPrCol('insectados') ? 'rpc.insectados' : '0'));
$exprPrDefectosCount = "({$exprPrPizarra} + {$exprPrVioletas} + {$exprPrMohosos} + {$exprPrGerminados} + {$exprPrDanados})";

$exprPrFermentacion = $hasPrCol('porcentaje_fermentacion')
    ? 'rpc.porcentaje_fermentacion'
    : "(CASE WHEN {$exprPrTotal} > 0 THEN ({$exprPrFermentadosCount} / {$exprPrTotal}) * 100 ELSE 0 END)";
$exprPrDefectos = $hasPrCol('porcentaje_defectos')
    ? 'rpc.porcentaje_defectos'
    : ($hasPrCol('defectos_totales')
        ? 'rpc.defectos_totales'
        : "(CASE WHEN {$exprPrTotal} > 0 THEN ({$exprPrDefectosCount} / {$exprPrTotal}) * 100 ELSE 0 END)");

$exprPrCalidad = $hasPrCol('calidad_determinada')
    ? 'rpc.calidad_determinada'
    : ($hasPrCol('calidad_resultado')
        ? 'rpc.calidad_resultado'
        : ($hasPrCol('decision_lote')
            ? "(CASE rpc.decision_lote
                    WHEN 'RECHAZADO' THEN 'RECHAZADO'
                    WHEN 'REPROCESO' THEN 'RECHAZADO'
                    WHEN 'APROBADO' THEN 'NACIONAL'
                    WHEN 'MEZCLA' THEN 'NACIONAL'
                    ELSE rpc.decision_lote
                END)"
            : "'NACIONAL'"));

// ========== INDICADORES DE PRODUCCIÓN ==========

// Total de lotes y pesos
$produccion = $db->fetchOne(
    "SELECT 
        COUNT(*) as total_lotes,
        SUM({$exprPesoRecepcion}) as peso_total_recibido,
        SUM(CASE WHEN {$exprPesoFinalLote} > 0 THEN {$exprPesoFinalLote} ELSE 0 END) as peso_total_final,
        AVG(CASE WHEN {$exprPesoFinalLote} > 0 AND {$exprPesoRecepcion} > 0
            THEN ({$exprPesoFinalLote} / {$exprPesoRecepcion}) * 100 ELSE NULL END) as rendimiento_promedio
    FROM lotes l
    WHERE {$exprFechaLote} BETWEEN ? AND ?",
    [$fechaDesde, $fechaHasta]
);

// Lotes por estado
$estadosLotes = $db->fetchAll(
    "SELECT estado_proceso, COUNT(*) as cantidad
    FROM lotes l
    WHERE {$exprFechaLote} BETWEEN ? AND ?
    GROUP BY estado_proceso",
    [$fechaDesde, $fechaHasta]
);

// ========== INDICADORES DE FERMENTACIÓN ==========

$fermentacion = $db->fetchOne(
    "SELECT 
        COUNT(*) as total_fermentaciones,
        AVG(DATEDIFF(COALESCE({$exprFerFechaFin}, CURDATE()), {$exprFerFechaInicio})) as dias_promedio,
        AVG({$exprFerVolteos}) as volteos_promedio,
        AVG({$exprFerTempInicial}) as temp_inicial_promedio,
        COUNT(CASE WHEN {$condFerFinalizada} THEN 1 END) as finalizadas,
        COUNT(CASE WHEN {$condFerActiva} THEN 1 END) as activas
    FROM registros_fermentacion rf
    INNER JOIN lotes l ON rf.lote_id = l.id
    {$joinVolteos}
    WHERE {$exprFechaLote} BETWEEN ? AND ?",
    [$fechaDesde, $fechaHasta]
);

// Pérdida de peso en fermentación
$perdidaFermentacion = $db->fetchOne(
    "SELECT 
        AVG(CASE WHEN {$exprFerPesoFinal} > 0 AND {$exprFerPesoInicial} > 0
            THEN (({$exprFerPesoInicial} - {$exprFerPesoFinal}) / {$exprFerPesoInicial}) * 100 ELSE NULL END) as perdida_promedio
    FROM registros_fermentacion rf
    INNER JOIN lotes l ON rf.lote_id = l.id
    WHERE {$exprFechaLote} BETWEEN ? AND ? AND {$condFerFinalizada}",
    [$fechaDesde, $fechaHasta]
);

// ========== INDICADORES DE SECADO ==========

$secado = $db->fetchOne(
    "SELECT 
        COUNT(*) as total_secados,
        AVG(DATEDIFF(COALESCE({$exprSecFechaFin}, CURDATE()), {$exprSecFechaInicio})) as dias_promedio,
        AVG({$exprSecHumedadInicial}) as humedad_inicial_promedio,
        AVG(CASE WHEN {$condSecFinalizado} THEN {$exprSecHumedadFinal} ELSE NULL END) as humedad_final_promedio,
        COUNT(CASE WHEN {$condSecFinalizado} AND {$exprSecHumedadFinal} <= 7 THEN 1 END) as optimos,
        COUNT(CASE WHEN {$condSecFinalizado} THEN 1 END) as finalizados
    FROM registros_secado rs
    INNER JOIN lotes l ON rs.lote_id = l.id
    {$joinSecadoras}
    WHERE {$exprFechaLote} BETWEEN ? AND ?",
    [$fechaDesde, $fechaHasta]
);

// Secado por tipo
$secadoPorTipo = $db->fetchAll(
    "SELECT COALESCE({$exprSecTipo}, 'N/D') as tipo_secado, COUNT(*) as cantidad,
            AVG(DATEDIFF(COALESCE({$exprSecFechaFin}, CURDATE()), {$exprSecFechaInicio})) as dias_prom
    FROM registros_secado rs
    INNER JOIN lotes l ON rs.lote_id = l.id
    {$joinSecadoras}
    WHERE {$exprFechaLote} BETWEEN ? AND ?
    GROUP BY tipo_secado",
    [$fechaDesde, $fechaHasta]
);

// ========== INDICADORES DE CALIDAD ==========

$calidad = $db->fetchOne(
    "SELECT 
        COUNT(*) as total_pruebas,
        AVG({$exprPrFermentacion}) as fermentacion_promedio,
        AVG({$exprPrDefectos}) as defectos_promedio,
        COUNT(CASE WHEN ({$exprPrCalidad}) = 'PREMIUM' THEN 1 END) as premium,
        COUNT(CASE WHEN ({$exprPrCalidad}) = 'EXPORTACION' THEN 1 END) as exportacion,
        COUNT(CASE WHEN ({$exprPrCalidad}) = 'NACIONAL' THEN 1 END) as nacional,
        COUNT(CASE WHEN ({$exprPrCalidad}) = 'RECHAZADO' THEN 1 END) as rechazado
    FROM registros_prueba_corte rpc
    INNER JOIN lotes l ON rpc.lote_id = l.id
    WHERE {$exprFechaLote} BETWEEN ? AND ?",
    [$fechaDesde, $fechaHasta]
);

// Tasa de aprobación
$tasaAprobacion = $calidad['total_pruebas'] > 0 
    ? (($calidad['total_pruebas'] - $calidad['rechazado']) / $calidad['total_pruebas']) * 100 
    : 0;

// ========== TOP PROVEEDORES ==========

$topProveedores = $db->fetchAll(
    "SELECT p.nombre, COUNT(l.id) as lotes, SUM({$exprPesoRecepcion}) as peso_total,
            AVG(CASE WHEN {$exprPesoFinalLote} > 0 AND {$exprPesoRecepcion} > 0
                THEN ({$exprPesoFinalLote} / {$exprPesoRecepcion}) * 100 END) as rendimiento
    FROM lotes l
    INNER JOIN proveedores p ON l.proveedor_id = p.id
    WHERE {$exprFechaLote} BETWEEN ? AND ?
    GROUP BY p.id, p.nombre
    ORDER BY peso_total DESC
    LIMIT 5",
    [$fechaDesde, $fechaHasta]
);

ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Encabezado -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-shalom-dark">Indicadores de Rendimiento</h1>
            <p class="text-gray-600">KPIs y métricas de todos los procesos</p>
        </div>
        <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Volver
        </a>
    </div>

    <!-- Filtros de Período -->
    <div class="bg-white rounded-xl shadow p-4 mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                <input type="date" name="fecha_desde" value="<?= e($fechaDesde) ?>" 
                       class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-shalom-primary focus:border-shalom-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                <input type="date" name="fecha_hasta" value="<?= e($fechaHasta) ?>" 
                       class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-shalom-primary focus:border-shalom-primary">
            </div>
            <button type="submit" class="px-6 py-2 bg-shalom-primary text-white rounded-lg hover:bg-shalom-dark">
                Actualizar
            </button>
            <div class="ml-auto flex gap-2">
                <a href="?fecha_desde=<?= date('Y-m-01') ?>&fecha_hasta=<?= date('Y-m-d') ?>" 
                   class="px-3 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200">Este Mes</a>
                <a href="?fecha_desde=<?= date('Y-01-01') ?>&fecha_hasta=<?= date('Y-m-d') ?>" 
                   class="px-3 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200">Este Año</a>
            </div>
        </form>
    </div>

    <!-- ========== PRODUCCIÓN ========== -->
    <div class="mb-8">
        <h2 class="text-lg font-semibold text-shalom-dark mb-4 flex items-center">
            <span class="w-8 h-8 bg-shalom-primary rounded-lg flex items-center justify-center mr-3">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
            </span>
            Indicadores de Producción
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Total Lotes</div>
                <div class="text-3xl font-bold text-shalom-primary"><?= number_format($produccion['total_lotes'] ?? 0) ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Peso Recibido</div>
                <div class="text-3xl font-bold text-blue-600"><?= number_format($produccion['peso_total_recibido'] ?? 0, 0) ?></div>
                <div class="text-xs text-gray-400">kg</div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Peso Final</div>
                <div class="text-3xl font-bold text-green-600"><?= number_format($produccion['peso_total_final'] ?? 0, 0) ?></div>
                <div class="text-xs text-gray-400">kg</div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Rendimiento Promedio</div>
                <div class="text-3xl font-bold text-amber-600"><?= number_format($produccion['rendimiento_promedio'] ?? 0, 1) ?>%</div>
                <div class="text-xs text-gray-400">peso final / recibido</div>
            </div>
        </div>
    </div>

    <!-- ========== FERMENTACIÓN ========== -->
    <div class="mb-8">
        <h2 class="text-lg font-semibold text-shalom-dark mb-4 flex items-center">
            <span class="w-8 h-8 bg-orange-500 rounded-lg flex items-center justify-center mr-3">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                </svg>
            </span>
            Indicadores de Fermentación
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Total Procesos</div>
                <div class="text-3xl font-bold text-orange-600"><?= number_format($fermentacion['total_fermentaciones'] ?? 0) ?></div>
                <div class="text-xs text-gray-400"><?= $fermentacion['activas'] ?? 0 ?> activas</div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Días Promedio</div>
                <div class="text-3xl font-bold text-blue-600"><?= number_format($fermentacion['dias_promedio'] ?? 0, 1) ?></div>
                <div class="text-xs text-gray-400">días por lote</div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Volteos Promedio</div>
                <div class="text-3xl font-bold text-purple-600"><?= number_format($fermentacion['volteos_promedio'] ?? 0, 1) ?></div>
                <div class="text-xs text-gray-400">por proceso</div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Temp. Inicial Prom.</div>
                <div class="text-3xl font-bold text-red-600"><?= number_format($fermentacion['temp_inicial_promedio'] ?? 0, 1) ?>°</div>
                <div class="text-xs text-gray-400">Celsius</div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Pérdida de Peso</div>
                <div class="text-3xl font-bold text-gray-600"><?= number_format($perdidaFermentacion['perdida_promedio'] ?? 0, 1) ?>%</div>
                <div class="text-xs text-gray-400">promedio</div>
            </div>
        </div>
    </div>

    <!-- ========== SECADO ========== -->
    <div class="mb-8">
        <h2 class="text-lg font-semibold text-shalom-dark mb-4 flex items-center">
            <span class="w-8 h-8 bg-yellow-500 rounded-lg flex items-center justify-center mr-3">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
            </span>
            Indicadores de Secado
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Total Procesos</div>
                <div class="text-3xl font-bold text-yellow-600"><?= number_format($secado['total_secados'] ?? 0) ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Días Promedio</div>
                <div class="text-3xl font-bold text-blue-600"><?= number_format($secado['dias_promedio'] ?? 0, 1) ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Humedad Inicial</div>
                <div class="text-3xl font-bold text-cyan-600"><?= number_format($secado['humedad_inicial_promedio'] ?? 0, 1) ?>%</div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Humedad Final</div>
                <div class="text-3xl font-bold text-emerald-600"><?= number_format($secado['humedad_final_promedio'] ?? 0, 1) ?>%</div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Tasa Óptima (≤7%)</div>
                <?php $tasaOptima = $secado['finalizados'] > 0 ? ($secado['optimos'] / $secado['finalizados']) * 100 : 0; ?>
                <div class="text-3xl font-bold <?= $tasaOptima >= 80 ? 'text-green-600' : 'text-amber-600' ?>"><?= number_format($tasaOptima, 0) ?>%</div>
            </div>
        </div>
        
        <!-- Secado por tipo -->
        <?php if (!empty($secadoPorTipo)): ?>
        <div class="mt-4 grid grid-cols-3 gap-4">
            <?php foreach ($secadoPorTipo as $tipo): ?>
            <div class="bg-gray-50 rounded-lg p-4 text-center">
                <div class="text-lg font-bold text-gray-700"><?= $tipo['cantidad'] ?></div>
                <div class="text-sm text-gray-500"><?= $tipo['tipo_secado'] ?></div>
                <div class="text-xs text-gray-400"><?= number_format($tipo['dias_prom'], 1) ?> días prom.</div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ========== CALIDAD ========== -->
    <div class="mb-8">
        <h2 class="text-lg font-semibold text-shalom-dark mb-4 flex items-center">
            <span class="w-8 h-8 bg-purple-500 rounded-lg flex items-center justify-center mr-3">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                </svg>
            </span>
            Indicadores de Calidad
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Total Pruebas</div>
                <div class="text-3xl font-bold text-purple-600"><?= number_format($calidad['total_pruebas'] ?? 0) ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">% Fermentación Prom.</div>
                <div class="text-3xl font-bold text-green-600"><?= number_format($calidad['fermentacion_promedio'] ?? 0, 1) ?>%</div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">% Defectos Prom.</div>
                <div class="text-3xl font-bold text-red-600"><?= number_format($calidad['defectos_promedio'] ?? 0, 1) ?>%</div>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <div class="text-sm text-gray-500 mb-1">Tasa de Aprobación</div>
                <div class="text-3xl font-bold <?= $tasaAprobacion >= 90 ? 'text-green-600' : 'text-amber-600' ?>"><?= number_format($tasaAprobacion, 0) ?>%</div>
            </div>
        </div>
        
        <!-- Distribución de calidad -->
        <div class="grid grid-cols-4 gap-4">
            <div class="bg-gradient-to-br from-emerald-500 to-green-600 rounded-xl p-4 text-white text-center">
                <div class="text-3xl font-bold"><?= $calidad['premium'] ?? 0 ?></div>
                <div class="text-sm opacity-90">Premium</div>
            </div>
            <div class="bg-gradient-to-br from-amber-500 to-yellow-600 rounded-xl p-4 text-white text-center">
                <div class="text-3xl font-bold"><?= $calidad['exportacion'] ?? 0 ?></div>
                <div class="text-sm opacity-90">Exportación</div>
            </div>
            <div class="bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl p-4 text-white text-center">
                <div class="text-3xl font-bold"><?= $calidad['nacional'] ?? 0 ?></div>
                <div class="text-sm opacity-90">Nacional</div>
            </div>
            <div class="bg-gradient-to-br from-red-500 to-rose-600 rounded-xl p-4 text-white text-center">
                <div class="text-3xl font-bold"><?= $calidad['rechazado'] ?? 0 ?></div>
                <div class="text-sm opacity-90">Rechazado</div>
            </div>
        </div>
    </div>

    <!-- ========== TOP PROVEEDORES ========== -->
    <?php if (!empty($topProveedores)): ?>
    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-semibold text-shalom-dark mb-4">Top 5 Proveedores por Volumen</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b">
                        <th class="text-left py-2 text-sm font-medium text-gray-500">#</th>
                        <th class="text-left py-2 text-sm font-medium text-gray-500">Proveedor</th>
                        <th class="text-left py-2 text-sm font-medium text-gray-500">Lotes</th>
                        <th class="text-left py-2 text-sm font-medium text-gray-500">Peso Total</th>
                        <th class="text-left py-2 text-sm font-medium text-gray-500">Rendimiento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topProveedores as $i => $prov): ?>
                    <tr class="border-b">
                        <td class="py-3">
                            <span class="w-6 h-6 inline-flex items-center justify-center rounded-full bg-shalom-primary text-white text-xs font-bold">
                                <?= $i + 1 ?>
                            </span>
                        </td>
                        <td class="py-3 font-medium"><?= e($prov['nombre']) ?></td>
                        <td class="py-3"><?= $prov['lotes'] ?></td>
                        <td class="py-3"><?= number_format($prov['peso_total'], 0) ?> kg</td>
                        <td class="py-3">
                            <?php if ($prov['rendimiento']): ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $prov['rendimiento'] >= 35 ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>">
                                <?= number_format($prov['rendimiento'], 1) ?>%
                            </span>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
