<?php
/**
 * Reporte de Secado
 * Genera reportes detallados del proceso de secado
 */

require_once __DIR__ . '/../bootstrap.php';

if (!Auth::check()) {
    redirect('/login.php');
}

$pageTitle = 'Reporte de Secado';
$db = Database::getInstance();

// Compatibilidad de esquema
$colsSecado = array_column($db->fetchAll("SHOW COLUMNS FROM registros_secado"), 'Field');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSecado, true);

$colsLotes = array_column($db->fetchAll("SHOW COLUMNS FROM lotes"), 'Field');
$hasLoteCol = static fn(string $name): bool => in_array($name, $colsLotes, true);

$tablaSecadoras = $db->fetch("SHOW TABLES LIKE 'secadoras'") ? 'secadoras' : null;
$colsSecadoras = $tablaSecadoras ? array_column($db->fetchAll("SHOW COLUMNS FROM {$tablaSecadoras}"), 'Field') : [];
$hasSecadoraCol = static fn(string $name): bool => in_array($name, $colsSecadoras, true);

$exprFechaInicio = $hasSecCol('fecha_inicio')
    ? 'rs.fecha_inicio'
    : ($hasSecCol('fecha')
        ? 'rs.fecha'
        : ($hasSecCol('fecha_carga')
            ? 'DATE(rs.fecha_carga)'
            : ($hasSecCol('created_at') ? 'DATE(rs.created_at)' : 'CURDATE()')));
$exprFechaFin = $hasSecCol('fecha_fin') ? 'rs.fecha_fin' : 'NULL';
$exprEstadoActivo = $hasSecCol('fecha_fin')
    ? "rs.fecha_fin IS NULL"
    : ($hasSecCol('humedad_final') ? "rs.humedad_final IS NULL" : "1=1");
$exprEstadoFinalizado = $hasSecCol('fecha_fin')
    ? "rs.fecha_fin IS NOT NULL"
    : ($hasSecCol('humedad_final') ? "rs.humedad_final IS NOT NULL" : "1=0");

$exprPesoRecepcion = $hasLoteCol('peso_recepcion_kg')
    ? 'l.peso_recepcion_kg'
    : ($hasLoteCol('peso_inicial_kg') ? 'l.peso_inicial_kg' : 'NULL');
$exprPesoInicial = $hasSecCol('peso_inicial')
    ? 'rs.peso_inicial'
    : ($hasSecCol('qq_cargados') ? '(rs.qq_cargados * 45.36)' : $exprPesoRecepcion);
$exprPesoFinal = $hasSecCol('peso_final')
    ? 'rs.peso_final'
    : ($hasLoteCol('peso_final_kg') ? 'l.peso_final_kg' : ($hasLoteCol('peso_actual_kg') ? 'l.peso_actual_kg' : 'NULL'));

if ($tablaSecadoras) {
    $joinSecadora = "LEFT JOIN {$tablaSecadoras} s ON rs.secadora_id = s.id";
    $exprSecadoraCodigo = $hasSecadoraCol('codigo')
        ? 's.codigo'
        : ($hasSecadoraCol('numero')
            ? 's.numero'
            : ($hasSecadoraCol('nombre') ? 's.nombre' : 'NULL'));
} else {
    $joinSecadora = '';
    $exprSecadoraCodigo = "NULL";
}

$exprTipoSecado = $hasSecCol('tipo_secado')
    ? 'rs.tipo_secado'
    : (($tablaSecadoras && $hasSecadoraCol('tipo'))
        ? "CASE WHEN s.tipo = 'SOLAR' THEN 'SOLAR' WHEN s.tipo IN ('INDUSTRIAL', 'ARTESANAL') THEN 'MECANICO' ELSE s.tipo END"
        : "NULL");

// Filtros
$fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$estado = $_GET['estado'] ?? '';
$tipoSecado = $_GET['tipo_secado'] ?? '';

// Construir query con filtros
$where = ["{$exprFechaInicio} BETWEEN ? AND ?"];
$params = [$fechaDesde, $fechaHasta];

if ($estado === 'activo') {
    $where[] = $exprEstadoActivo;
} elseif ($estado === 'finalizado') {
    $where[] = $exprEstadoFinalizado;
}

if ($tipoSecado) {
    $where[] = "({$exprTipoSecado}) = ?";
    $params[] = $tipoSecado;
}

$whereClause = implode(' AND ', $where);

// Obtener datos de secado
$secados = $db->fetchAll(
    "SELECT rs.*, l.codigo as lote_codigo, {$exprPesoRecepcion} as peso_recepcion_kg,
            p.nombre as proveedor_nombre, v.nombre as variedad_nombre,
            {$exprSecadoraCodigo} as secadora_codigo,
            {$exprTipoSecado} as tipo_secado,
            {$exprFechaInicio} as fecha_inicio,
            {$exprFechaFin} as fecha_fin,
            {$exprPesoInicial} as peso_inicial,
            {$exprPesoFinal} as peso_final,
            DATEDIFF(COALESCE({$exprFechaFin}, CURDATE()), {$exprFechaInicio}) as dias_proceso
    FROM registros_secado rs
    INNER JOIN lotes l ON rs.lote_id = l.id
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    {$joinSecadora}
    WHERE {$whereClause}
    ORDER BY {$exprFechaInicio} DESC",
    $params
);

// Calcular estadísticas
$totalSecados = count($secados);
$secadosActivos = array_filter($secados, fn($s) => !$s['fecha_fin']);
$secadosFinalizados = array_filter($secados, fn($s) => $s['fecha_fin']);

$promedioDias = $totalSecados > 0 
    ? array_sum(array_column($secados, 'dias_proceso')) / $totalSecados 
    : 0;

$promedioHumedadFinal = 0;
$finalizadosConHumedad = array_filter($secados, fn($s) => $s['fecha_fin'] && $s['humedad_final']);
if (count($finalizadosConHumedad) > 0) {
    $promedioHumedadFinal = array_sum(array_column($finalizadosConHumedad, 'humedad_final')) / count($finalizadosConHumedad);
}

$lotesOptimos = count(array_filter($secados, fn($s) => $s['humedad_final'] && $s['humedad_final'] <= 7));

// Contar por tipo de secado
$tiposCount = ['SOLAR' => 0, 'MECANICO' => 0, 'MIXTO' => 0];
foreach ($secados as $s) {
    if (isset($tiposCount[$s['tipo_secado']])) {
        $tiposCount[$s['tipo_secado']]++;
    }
}

// Manejar exportación
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_secado_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Lote', 'Proveedor', 'Variedad', 'Tipo Secado', 'Secadora', 'Fecha Inicio', 
                      'Fecha Fin', 'Días', 'Peso Inicial', 'Peso Final', 'Humedad Inicial', 'Humedad Final']);
    
    foreach ($secados as $s) {
        fputcsv($output, [
            $s['lote_codigo'],
            $s['proveedor_nombre'],
            $s['variedad_nombre'],
            $s['tipo_secado'],
            $s['secadora_codigo'] ?? '-',
            $s['fecha_inicio'],
            $s['fecha_fin'] ?? 'En proceso',
            $s['dias_proceso'],
            $s['peso_inicial'],
            $s['peso_final'] ?? '-',
            $s['humedad_inicial'] . '%',
            $s['humedad_final'] ? $s['humedad_final'] . '%' : '-'
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
            <h1 class="text-2xl font-bold text-shalom-dark">Reporte de Secado</h1>
            <p class="text-gray-600">Análisis detallado del proceso de secado</p>
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
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
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
                <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                <select name="estado" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-shalom-primary focus:border-shalom-primary">
                    <option value="">Todos</option>
                    <option value="activo" <?= $estado === 'activo' ? 'selected' : '' ?>>En Proceso</option>
                    <option value="finalizado" <?= $estado === 'finalizado' ? 'selected' : '' ?>>Finalizado</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tipo Secado</label>
                <select name="tipo_secado" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-shalom-primary focus:border-shalom-primary">
                    <option value="">Todos</option>
                    <option value="SOLAR" <?= $tipoSecado === 'SOLAR' ? 'selected' : '' ?>>Solar</option>
                    <option value="MECANICO" <?= $tipoSecado === 'MECANICO' ? 'selected' : '' ?>>Mecánico</option>
                    <option value="MIXTO" <?= $tipoSecado === 'MIXTO' ? 'selected' : '' ?>>Mixto</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-shalom-primary text-white rounded-lg hover:bg-shalom-dark">
                    Filtrar
                </button>
            </div>
        </form>
    </div>

    <!-- Estadísticas -->
    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-shalom-primary"><?= $totalSecados ?></div>
            <div class="text-sm text-gray-500">Total</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-blue-600"><?= count($secadosActivos) ?></div>
            <div class="text-sm text-gray-500">En Proceso</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-green-600"><?= count($secadosFinalizados) ?></div>
            <div class="text-sm text-gray-500">Finalizados</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-amber-600"><?= number_format($promedioDias, 1) ?></div>
            <div class="text-sm text-gray-500">Días Promedio</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-cyan-600"><?= number_format($promedioHumedadFinal, 1) ?>%</div>
            <div class="text-sm text-gray-500">Humedad Prom.</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-emerald-600"><?= $lotesOptimos ?></div>
            <div class="text-sm text-gray-500">Óptimos (≤7%)</div>
        </div>
    </div>

    <!-- Distribución por Tipo -->
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-gradient-to-r from-yellow-400 to-orange-500 rounded-lg p-4 text-white text-center">
            <div class="text-4xl font-bold"><?= $tiposCount['SOLAR'] ?></div>
            <div class="text-sm opacity-90">Solar</div>
        </div>
        <div class="bg-gradient-to-r from-blue-500 to-indigo-600 rounded-lg p-4 text-white text-center">
            <div class="text-4xl font-bold"><?= $tiposCount['MECANICO'] ?></div>
            <div class="text-sm opacity-90">Mecánico</div>
        </div>
        <div class="bg-gradient-to-r from-teal-500 to-emerald-600 rounded-lg p-4 text-white text-center">
            <div class="text-4xl font-bold"><?= $tiposCount['MIXTO'] ?></div>
            <div class="text-sm opacity-90">Mixto</div>
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
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tipo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Secadora</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha Inicio</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Días</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Peso Ini.</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Peso Fin</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Hum. Final</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($secados)): ?>
                    <tr>
                        <td colspan="10" class="px-4 py-8 text-center text-gray-500">
                            No se encontraron registros de secado en el período seleccionado
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($secados as $s): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="/secado/ver.php?id=<?= $s['id'] ?>" class="font-medium text-shalom-primary hover:underline">
                                <?= e($s['lote_codigo']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600"><?= e($s['proveedor_nombre']) ?></td>
                        <td class="px-4 py-3">
                            <?php
                            $tipoColors = [
                                'SOLAR' => 'bg-yellow-100 text-yellow-800',
                                'MECANICO' => 'bg-blue-100 text-blue-800',
                                'MIXTO' => 'bg-teal-100 text-teal-800'
                            ];
                            $color = $tipoColors[$s['tipo_secado']] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $color ?>">
                                <?= e($s['tipo_secado']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm"><?= e($s['secadora_codigo'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-sm"><?= date('d/m/Y', strtotime($s['fecha_inicio'])) ?></td>
                        <td class="px-4 py-3 text-sm font-medium"><?= $s['dias_proceso'] ?></td>
                        <td class="px-4 py-3 text-sm"><?= number_format($s['peso_inicial'], 1) ?> kg</td>
                        <td class="px-4 py-3 text-sm">
                            <?= $s['peso_final'] ? number_format($s['peso_final'], 1) . ' kg' : '-' ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($s['humedad_final']): ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $s['humedad_final'] <= 7 ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>">
                                <?= number_format($s['humedad_final'], 1) ?>%
                            </span>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($s['fecha_fin']): ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                Finalizado
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                En Proceso
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
