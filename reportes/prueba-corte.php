<?php
/**
 * Reporte de Prueba de Corte
 * Genera reportes detallados del análisis de calidad
 */

require_once __DIR__ . '/../bootstrap.php';

if (!Auth::check()) {
    redirect('/login.php');
}

$pageTitle = 'Reporte de Prueba de Corte';
$db = Database::getInstance();

// Compatibilidad de esquema
$colsPrueba = array_column($db->fetchAll("SHOW COLUMNS FROM registros_prueba_corte"), 'Field');
$hasPrCol = static fn(string $name): bool => in_array($name, $colsPrueba, true);

$exprFechaPrueba = $hasPrCol('fecha_prueba')
    ? 'rpc.fecha_prueba'
    : ($hasPrCol('fecha')
        ? 'rpc.fecha'
        : ($hasPrCol('created_at') ? 'DATE(rpc.created_at)' : 'CURDATE()'));
$exprTotalGranos = $hasPrCol('total_granos')
    ? 'rpc.total_granos'
    : ($hasPrCol('granos_analizados') ? 'rpc.granos_analizados' : '100');

$exprGranosFermentados = $hasPrCol('granos_fermentados')
    ? 'rpc.granos_fermentados'
    : ($hasPrCol('bien_fermentados') ? 'rpc.bien_fermentados' : '0');
$exprGranosParcialmenteFermentados = $hasPrCol('granos_parcialmente_fermentados')
    ? 'rpc.granos_parcialmente_fermentados'
    : ($hasPrCol('violeta') ? 'rpc.violeta' : '0');
$exprGranosPizarra = $hasPrCol('granos_pizarra')
    ? 'rpc.granos_pizarra'
    : ($hasPrCol('pizarrosos') ? 'rpc.pizarrosos' : '0');
$exprGranosVioletas = $hasPrCol('granos_violetas')
    ? 'rpc.granos_violetas'
    : ($hasPrCol('violeta') ? 'rpc.violeta' : '0');
$exprGranosMohosos = $hasPrCol('granos_mohosos')
    ? 'rpc.granos_mohosos'
    : ($hasPrCol('mohosos') ? 'rpc.mohosos' : '0');
$exprGranosGerminados = $hasPrCol('granos_germinados')
    ? 'rpc.granos_germinados'
    : ($hasPrCol('germinados') ? 'rpc.germinados' : '0');
$exprGranosDanados = $hasPrCol('granos_danados')
    ? 'rpc.granos_danados'
    : ($hasPrCol('granos_dañados')
        ? 'rpc.`granos_dañados`'
        : ($hasPrCol('insectados') ? 'rpc.insectados' : '0'));

$exprDefectosBase = "({$exprGranosPizarra} + {$exprGranosVioletas} + {$exprGranosMohosos} + {$exprGranosGerminados} + {$exprGranosDanados})";
$exprPorcentajeFermentacion = $hasPrCol('porcentaje_fermentacion')
    ? 'rpc.porcentaje_fermentacion'
    : "(CASE WHEN {$exprTotalGranos} > 0 THEN ({$exprGranosFermentados} / {$exprTotalGranos}) * 100 ELSE 0 END)";
$exprPorcentajeDefectos = $hasPrCol('porcentaje_defectos')
    ? 'rpc.porcentaje_defectos'
    : ($hasPrCol('defectos_totales')
        ? 'rpc.defectos_totales'
        : "(CASE WHEN {$exprTotalGranos} > 0 THEN ({$exprDefectosBase} / {$exprTotalGranos}) * 100 ELSE 0 END)");

$exprCalidadDeterminada = $hasPrCol('calidad_determinada')
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

// Filtros
$fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$calidad = $_GET['calidad'] ?? '';

// Construir query con filtros
$where = ["{$exprFechaPrueba} BETWEEN ? AND ?"];
$params = [$fechaDesde, $fechaHasta];

if ($calidad) {
    $where[] = "({$exprCalidadDeterminada}) = ?";
    $params[] = $calidad;
}

$whereClause = implode(' AND ', $where);

// Obtener datos de pruebas de corte
$pruebas = $db->fetchAll(
    "SELECT rpc.*, l.codigo as lote_codigo,
            p.nombre as proveedor_nombre, v.nombre as variedad_nombre,
            {$exprFechaPrueba} as fecha_prueba,
            {$exprTotalGranos} as total_granos,
            {$exprGranosFermentados} as granos_fermentados,
            {$exprGranosParcialmenteFermentados} as granos_parcialmente_fermentados,
            {$exprGranosPizarra} as granos_pizarra,
            {$exprGranosVioletas} as granos_violetas,
            {$exprGranosMohosos} as granos_mohosos,
            {$exprGranosGerminados} as granos_germinados,
            {$exprGranosDanados} as granos_danados,
            {$exprPorcentajeFermentacion} as porcentaje_fermentacion,
            {$exprPorcentajeDefectos} as porcentaje_defectos,
            {$exprCalidadDeterminada} as calidad_determinada
    FROM registros_prueba_corte rpc
    INNER JOIN lotes l ON rpc.lote_id = l.id
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    WHERE {$whereClause}
    ORDER BY {$exprFechaPrueba} DESC",
    $params
);

// Calcular estadísticas
$totalPruebas = count($pruebas);

// Conteo por calidad
$calidadCount = ['PREMIUM' => 0, 'EXPORTACION' => 0, 'NACIONAL' => 0, 'RECHAZADO' => 0];
foreach ($pruebas as $p) {
    if (isset($calidadCount[$p['calidad_determinada']])) {
        $calidadCount[$p['calidad_determinada']]++;
    }
}

// Promedios
$promedioFermentacion = $totalPruebas > 0 
    ? array_sum(array_column($pruebas, 'porcentaje_fermentacion')) / $totalPruebas 
    : 0;

$promedioDefectos = $totalPruebas > 0 
    ? array_sum(array_column($pruebas, 'porcentaje_defectos')) / $totalPruebas 
    : 0;

// Tasa de aprobación (no rechazado)
$aprobados = $totalPruebas - $calidadCount['RECHAZADO'];
$tasaAprobacion = $totalPruebas > 0 ? ($aprobados / $totalPruebas) * 100 : 0;

// Manejar exportación
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_prueba_corte_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Lote', 'Proveedor', 'Variedad', 'Fecha', 'Total Granos', 
                      'Fermentados', 'Parciales', 'Pizarra', 'Violetas', 'Mohosos',
                      'Germinados', 'Dañados', '% Fermentación', '% Defectos', 'Calidad']);
    
    foreach ($pruebas as $p) {
        fputcsv($output, [
            $p['lote_codigo'],
            $p['proveedor_nombre'],
            $p['variedad_nombre'],
            $p['fecha_prueba'],
            $p['total_granos'],
            $p['granos_fermentados'],
            $p['granos_parcialmente_fermentados'],
            $p['granos_pizarra'],
            $p['granos_violetas'],
            $p['granos_mohosos'],
            $p['granos_germinados'],
            $p['granos_danados'],
            $p['porcentaje_fermentacion'] . '%',
            $p['porcentaje_defectos'] . '%',
            $p['calidad_determinada']
        ]);
    }
    
    fclose($output);
    exit;
}

ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Encabezado -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-shalom-dark">Reporte de Prueba de Corte</h1>
            <p class="text-gray-600">Análisis de calidad - Clasificación de 100 granos</p>
        </div>
        <div class="flex gap-3">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => '1'])) ?>" 
               class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Exportar CSV
            </a>
            <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Volver
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                <input type="date" name="fecha_desde" value="<?= e($fechaDesde) ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-shalom-primary focus:border-shalom-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                <input type="date" name="fecha_hasta" value="<?= e($fechaHasta) ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-shalom-primary focus:border-shalom-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Calidad</label>
                <select name="calidad" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-shalom-primary focus:border-shalom-primary">
                    <option value="">Todas</option>
                    <option value="PREMIUM" <?= $calidad === 'PREMIUM' ? 'selected' : '' ?>>Premium</option>
                    <option value="EXPORTACION" <?= $calidad === 'EXPORTACION' ? 'selected' : '' ?>>Exportación</option>
                    <option value="NACIONAL" <?= $calidad === 'NACIONAL' ? 'selected' : '' ?>>Nacional</option>
                    <option value="RECHAZADO" <?= $calidad === 'RECHAZADO' ? 'selected' : '' ?>>Rechazado</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-shalom-primary text-white rounded-lg hover:bg-shalom-dark">
                    Filtrar
                </button>
            </div>
        </form>
    </div>

    <!-- Distribución de Calidad -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-gradient-to-br from-emerald-500 to-green-600 rounded-xl p-5 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-4xl font-bold"><?= $calidadCount['PREMIUM'] ?></div>
                    <div class="text-sm opacity-90">Premium</div>
                </div>
                <div class="text-5xl opacity-30">⭐</div>
            </div>
            <div class="mt-2 text-xs opacity-75">≥80% fermentación, ≤3% defectos</div>
        </div>
        <div class="bg-gradient-to-br from-amber-500 to-yellow-600 rounded-xl p-5 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-4xl font-bold"><?= $calidadCount['EXPORTACION'] ?></div>
                    <div class="text-sm opacity-90">Exportación</div>
                </div>
                <div class="text-5xl opacity-30">🌍</div>
            </div>
            <div class="mt-2 text-xs opacity-75">≥70% fermentación, ≤5% defectos</div>
        </div>
        <div class="bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl p-5 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-4xl font-bold"><?= $calidadCount['NACIONAL'] ?></div>
                    <div class="text-sm opacity-90">Nacional</div>
                </div>
                <div class="text-5xl opacity-30">🏠</div>
            </div>
            <div class="mt-2 text-xs opacity-75">≥60% fermentación, ≤10% defectos</div>
        </div>
        <div class="bg-gradient-to-br from-red-500 to-rose-600 rounded-xl p-5 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-4xl font-bold"><?= $calidadCount['RECHAZADO'] ?></div>
                    <div class="text-sm opacity-90">Rechazado</div>
                </div>
                <div class="text-5xl opacity-30">✗</div>
            </div>
            <div class="mt-2 text-xs opacity-75">No cumple estándares</div>
        </div>
    </div>

    <!-- Métricas Generales -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-shalom-primary"><?= $totalPruebas ?></div>
            <div class="text-sm text-gray-500">Total Pruebas</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-green-600"><?= number_format($promedioFermentacion, 1) ?>%</div>
            <div class="text-sm text-gray-500">% Ferm. Promedio</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-red-600"><?= number_format($promedioDefectos, 1) ?>%</div>
            <div class="text-sm text-gray-500">% Defectos Prom.</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-blue-600"><?= number_format($tasaAprobacion, 1) ?>%</div>
            <div class="text-sm text-gray-500">Tasa Aprobación</div>
        </div>
    </div>

    <!-- Tabla de Datos -->
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Lote</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Proveedor</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Granos</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fermentados</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Parciales</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Defectos</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">% Ferm.</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">% Def.</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Calidad</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($pruebas)): ?>
                    <tr>
                        <td colspan="10" class="px-4 py-8 text-center text-gray-500">
                            No se encontraron pruebas de corte en el período seleccionado
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($pruebas as $p): ?>
                    <?php
                    $totalDefectos = $p['granos_pizarra'] + $p['granos_violetas'] + $p['granos_mohosos'] + 
                                     $p['granos_germinados'] + $p['granos_danados'];
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="/prueba-corte/ver.php?id=<?= $p['id'] ?>" class="font-medium text-shalom-primary hover:underline">
                                <?= e($p['lote_codigo']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600"><?= e($p['proveedor_nombre']) ?></td>
                        <td class="px-4 py-3 text-sm"><?= date('d/m/Y', strtotime($p['fecha_prueba'])) ?></td>
                        <td class="px-4 py-3 text-sm font-medium"><?= $p['total_granos'] ?></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                <?= $p['granos_fermentados'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                <?= $p['granos_parcialmente_fermentados'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                <?= $totalDefectos ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $p['porcentaje_fermentacion'] >= 70 ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>">
                                <?= number_format($p['porcentaje_fermentacion'], 1) ?>%
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $p['porcentaje_defectos'] <= 5 ? 'bg-green-100 text-green-800' : ($p['porcentaje_defectos'] <= 10 ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800') ?>">
                                <?= number_format($p['porcentaje_defectos'], 1) ?>%
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <?php
                            $calidadColors = [
                                'PREMIUM' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
                                'EXPORTACION' => 'bg-amber-100 text-amber-800 border-amber-300',
                                'NACIONAL' => 'bg-blue-100 text-blue-800 border-blue-300',
                                'RECHAZADO' => 'bg-red-100 text-red-800 border-red-300'
                            ];
                            $calidadColor = $calidadColors[$p['calidad_determinada']] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium border <?= $calidadColor ?>">
                                <?= e($p['calidad_determinada']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Análisis de Defectos -->
    <?php if (!empty($pruebas)): ?>
    <div class="mt-8 bg-white rounded-xl shadow p-6">
        <h3 class="text-lg font-semibold text-shalom-dark mb-4">Análisis de Defectos</h3>
        <?php
        $defectosTotales = [
            'Pizarra/Sin Fermentar' => array_sum(array_column($pruebas, 'granos_pizarra')),
            'Violetas' => array_sum(array_column($pruebas, 'granos_violetas')),
            'Mohosos' => array_sum(array_column($pruebas, 'granos_mohosos')),
            'Germinados' => array_sum(array_column($pruebas, 'granos_germinados')),
            'Dañados/Insectos' => array_sum(array_column($pruebas, 'granos_danados'))
        ];
        $totalGranosAnalizados = array_sum(array_column($pruebas, 'total_granos'));
        arsort($defectosTotales);
        ?>
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <?php 
            $defectoColors = [
                'Pizarra/Sin Fermentar' => 'bg-gray-500',
                'Violetas' => 'bg-purple-500',
                'Mohosos' => 'bg-stone-600',
                'Germinados' => 'bg-green-700',
                'Dañados/Insectos' => 'bg-red-600'
            ];
            foreach ($defectosTotales as $tipo => $cantidad): 
            $porcentaje = $totalGranosAnalizados > 0 ? ($cantidad / $totalGranosAnalizados) * 100 : 0;
            ?>
            <div class="bg-gray-50 rounded-lg p-4 text-center">
                <div class="w-10 h-10 mx-auto mb-2 rounded-full <?= $defectoColors[$tipo] ?? 'bg-gray-400' ?>"></div>
                <div class="text-2xl font-bold text-gray-800"><?= $cantidad ?></div>
                <div class="text-xs text-gray-500"><?= $tipo ?></div>
                <div class="text-xs text-gray-400"><?= number_format($porcentaje, 2) ?>%</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
