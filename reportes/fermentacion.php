<?php
/**
 * Reporte de Fermentación
 * Genera reportes detallados del proceso de fermentación
 */

require_once __DIR__ . '/../bootstrap.php';

if (!Auth::check()) {
    redirect('/login.php');
}

$pageTitle = 'Reporte de Fermentación';
$db = Database::getInstance();

// Compatibilidad de esquema
$colsFermentacion = array_column($db->fetchAll("SHOW COLUMNS FROM registros_fermentacion"), 'Field');
$hasRfCol = static fn(string $name): bool => in_array($name, $colsFermentacion, true);

$colsLotes = array_column($db->fetchAll("SHOW COLUMNS FROM lotes"), 'Field');
$hasLoteCol = static fn(string $name): bool => in_array($name, $colsLotes, true);

$tablaCajones = $db->fetch("SHOW TABLES LIKE 'cajones_fermentacion'") ? 'cajones_fermentacion'
    : ($db->fetch("SHOW TABLES LIKE 'cajones'") ? 'cajones' : null);
$colsCajones = $tablaCajones ? array_column($db->fetchAll("SHOW COLUMNS FROM {$tablaCajones}"), 'Field') : [];
$hasCajonCol = static fn(string $name): bool => in_array($name, $colsCajones, true);

$campoFechaFin = $hasRfCol('fecha_fin') ? 'rf.fecha_fin' : ($hasRfCol('fecha_salida') ? 'rf.fecha_salida' : null);
$exprFechaFin = $campoFechaFin ?: ($hasRfCol('aprobado_secado') ? "CASE WHEN rf.aprobado_secado = 1 THEN DATE(rf.updated_at) ELSE NULL END" : "NULL");
$exprEstadoActivo = $campoFechaFin
    ? "{$campoFechaFin} IS NULL"
    : ($hasRfCol('aprobado_secado') ? "(rf.aprobado_secado = 0 OR rf.aprobado_secado IS NULL)" : "1=1");
$exprEstadoFinalizado = $campoFechaFin
    ? "{$campoFechaFin} IS NOT NULL"
    : ($hasRfCol('aprobado_secado') ? "rf.aprobado_secado = 1" : "1=0");

$exprPesoInicial = $hasRfCol('peso_inicial')
    ? 'rf.peso_inicial'
    : ($hasRfCol('peso_lote_kg') ? 'rf.peso_lote_kg' : ($hasLoteCol('peso_recepcion_kg') ? 'l.peso_recepcion_kg' : '0'));
$exprPesoFinal = $hasRfCol('peso_final')
    ? 'rf.peso_final'
    : ($hasLoteCol('peso_final_kg') ? 'l.peso_final_kg' : ($hasLoteCol('peso_actual_kg') ? 'l.peso_actual_kg' : 'NULL'));
$exprPesoRecepcion = $hasLoteCol('peso_recepcion_kg')
    ? 'l.peso_recepcion_kg'
    : ($hasLoteCol('peso_inicial_kg') ? 'l.peso_inicial_kg' : 'NULL');
$exprTempInicial = $hasRfCol('temperatura_inicial') ? 'rf.temperatura_inicial' : 'NULL';
$exprPhInicial = $hasRfCol('ph_inicial') ? 'rf.ph_inicial' : ($hasRfCol('ph_pulpa_inicial') ? 'rf.ph_pulpa_inicial' : 'NULL');

if ($tablaCajones) {
    $joinCajones = "LEFT JOIN {$tablaCajones} c ON rf.cajon_id = c.id";
    $exprCajonCodigo = $hasCajonCol('codigo')
        ? 'c.codigo'
        : ($hasCajonCol('nombre')
            ? ($hasCajonCol('numero') ? "COALESCE(c.nombre, CONCAT('Cajón ', c.numero))" : 'c.nombre')
            : ($hasCajonCol('numero') ? "CONCAT('Cajón ', c.numero)" : "NULL"));
} else {
    $joinCajones = '';
    $exprCajonCodigo = "NULL";
}

$tablaControl = $db->fetch("SHOW TABLES LIKE 'fermentacion_control_diario'");
$joinVolteos = "LEFT JOIN (SELECT NULL as rf_id, 0 as total_volteos) fcd ON 1 = 0";
if ($tablaControl) {
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
    }
}

// Filtros
$fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$estado = $_GET['estado'] ?? '';
$proveedor = $_GET['proveedor'] ?? '';

// Construir query con filtros
$where = ["rf.fecha_inicio BETWEEN ? AND ?"];
$params = [$fechaDesde, $fechaHasta];

if ($estado === 'activo') {
    $where[] = $exprEstadoActivo;
} elseif ($estado === 'finalizado') {
    $where[] = $exprEstadoFinalizado;
}

if ($proveedor) {
    $where[] = "l.proveedor_id = ?";
    $params[] = $proveedor;
}

$whereClause = implode(' AND ', $where);

// Obtener datos de fermentación
$fermentaciones = $db->fetchAll(
    "SELECT rf.*, l.codigo as lote_codigo, {$exprPesoRecepcion} as peso_recepcion_kg,
            p.nombre as proveedor_nombre, v.nombre as variedad_nombre,
            {$exprCajonCodigo} as cajon_codigo,
            {$exprFechaFin} as fecha_fin,
            {$exprPesoInicial} as peso_inicial,
            {$exprPesoFinal} as peso_final,
            COALESCE(fcd.total_volteos, 0) as total_volteos,
            {$exprTempInicial} as temperatura_inicial,
            {$exprPhInicial} as ph_inicial,
            DATEDIFF(COALESCE({$exprFechaFin}, CURDATE()), rf.fecha_inicio) as dias_proceso
    FROM registros_fermentacion rf
    INNER JOIN lotes l ON rf.lote_id = l.id
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    {$joinCajones}
    {$joinVolteos}
    WHERE {$whereClause}
    ORDER BY rf.fecha_inicio DESC",
    $params
);

// Obtener proveedores para filtro
$proveedores = $db->fetchAll("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre");

// Calcular estadísticas
$totalFermentaciones = count($fermentaciones);
$fermentacionesActivas = array_filter($fermentaciones, fn($f) => !$f['fecha_fin']);
$fermentacionesFinalizadas = array_filter($fermentaciones, fn($f) => $f['fecha_fin']);

$promedioDias = $totalFermentaciones > 0 
    ? array_sum(array_column($fermentaciones, 'dias_proceso')) / $totalFermentaciones 
    : 0;

$promedioVolteos = $totalFermentaciones > 0 
    ? array_sum(array_column($fermentaciones, 'total_volteos')) / $totalFermentaciones 
    : 0;

// Manejar exportación
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_fermentacion_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel UTF-8
    
    fputcsv($output, ['Lote', 'Proveedor', 'Variedad', 'Cajón', 'Fecha Inicio', 'Fecha Fin', 
                      'Días', 'Peso Inicial', 'Peso Final', 'Volteos', 'Temp. Inicial', 'pH Inicial']);
    
    foreach ($fermentaciones as $f) {
        fputcsv($output, [
            $f['lote_codigo'],
            $f['proveedor_nombre'],
            $f['variedad_nombre'],
            $f['cajon_codigo'],
            $f['fecha_inicio'],
            $f['fecha_fin'] ?? 'En proceso',
            $f['dias_proceso'],
            $f['peso_inicial'],
            $f['peso_final'] ?? '-',
            $f['total_volteos'],
            $f['temperatura_inicial'],
            $f['ph_inicial']
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
            <h1 class="text-2xl font-bold text-shalom-dark">Reporte de Fermentación</h1>
            <p class="text-gray-600">Análisis detallado del proceso de fermentación</p>
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
                <label class="block text-sm font-medium text-gray-700 mb-1">Proveedor</label>
                <select name="proveedor" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-shalom-primary focus:border-shalom-primary">
                    <option value="">Todos</option>
                    <?php foreach ($proveedores as $prov): ?>
                    <option value="<?= $prov['id'] ?>" <?= $proveedor == $prov['id'] ? 'selected' : '' ?>>
                        <?= e($prov['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
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
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-shalom-primary"><?= $totalFermentaciones ?></div>
            <div class="text-sm text-gray-500">Total</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-blue-600"><?= count($fermentacionesActivas) ?></div>
            <div class="text-sm text-gray-500">En Proceso</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-green-600"><?= count($fermentacionesFinalizadas) ?></div>
            <div class="text-sm text-gray-500">Finalizados</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-orange-600"><?= number_format($promedioDias, 1) ?></div>
            <div class="text-sm text-gray-500">Días Promedio</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-purple-600"><?= number_format($promedioVolteos, 1) ?></div>
            <div class="text-sm text-gray-500">Volteos Prom.</div>
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
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cajón</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha Inicio</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Días</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Peso Inicial</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Peso Final</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Volteos</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($fermentaciones)): ?>
                    <tr>
                        <td colspan="10" class="px-4 py-8 text-center text-gray-500">
                            No se encontraron registros de fermentación en el período seleccionado
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($fermentaciones as $f): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="/fermentacion/ver.php?id=<?= $f['id'] ?>" class="font-medium text-shalom-primary hover:underline">
                                <?= e($f['lote_codigo']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600"><?= e($f['proveedor_nombre']) ?></td>
                        <td class="px-4 py-3 text-sm"><?= e($f['cajon_codigo'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-sm"><?= date('d/m/Y', strtotime($f['fecha_inicio'])) ?></td>
                        <td class="px-4 py-3 text-sm font-medium"><?= $f['dias_proceso'] ?> días</td>
                        <td class="px-4 py-3 text-sm"><?= number_format($f['peso_inicial'], 1) ?> kg</td>
                        <td class="px-4 py-3 text-sm">
                            <?= $f['peso_final'] ? number_format($f['peso_final'], 1) . ' kg' : '-' ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                <?= $f['total_volteos'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($f['fecha_fin']): ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                Finalizado
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                En Proceso
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <a href="/fermentacion/ver.php?id=<?= $f['id'] ?>" 
                               class="text-shalom-primary hover:text-shalom-dark text-sm">
                                Ver detalle
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Resumen por Proveedor -->
    <?php if (!empty($fermentaciones)): ?>
    <div class="mt-8 bg-white rounded-xl shadow p-6">
        <h3 class="text-lg font-semibold text-shalom-dark mb-4">Resumen por Proveedor</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <?php
            $porProveedor = [];
            foreach ($fermentaciones as $f) {
                $prov = $f['proveedor_nombre'] ?? 'Sin proveedor';
                if (!isset($porProveedor[$prov])) {
                    $porProveedor[$prov] = ['count' => 0, 'peso_total' => 0, 'volteos' => 0];
                }
                $porProveedor[$prov]['count']++;
                $porProveedor[$prov]['peso_total'] += $f['peso_inicial'] ?? 0;
                $porProveedor[$prov]['volteos'] += $f['total_volteos'] ?? 0;
            }
            arsort($porProveedor);
            foreach (array_slice($porProveedor, 0, 6, true) as $prov => $data):
            ?>
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="font-medium text-shalom-dark"><?= e($prov) ?></div>
                <div class="mt-2 grid grid-cols-3 gap-2 text-center">
                    <div>
                        <div class="text-lg font-bold text-shalom-primary"><?= $data['count'] ?></div>
                        <div class="text-xs text-gray-500">Lotes</div>
                    </div>
                    <div>
                        <div class="text-lg font-bold text-blue-600"><?= number_format($data['peso_total'], 0) ?></div>
                        <div class="text-xs text-gray-500">kg Total</div>
                    </div>
                    <div>
                        <div class="text-lg font-bold text-purple-600"><?= $data['volteos'] ?></div>
                        <div class="text-xs text-gray-500">Volteos</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
