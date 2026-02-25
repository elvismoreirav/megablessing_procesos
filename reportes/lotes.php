<?php
/**
 * Reporte de Lotes
 * Genera reportes completos de lotes con trazabilidad
 */

require_once __DIR__ . '/../bootstrap.php';

if (!Auth::check()) {
    redirect('/login.php');
}

$pageTitle = 'Reporte de Lotes';
$db = Database::getInstance();

// Filtros
$fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$estado = $_GET['estado'] ?? '';
$proveedor = $_GET['proveedor'] ?? '';
$calidad = $_GET['calidad'] ?? '';

// Construir query con filtros
$where = ["l.fecha_recepcion BETWEEN ? AND ?"];
$params = [$fechaDesde, $fechaHasta];

if ($estado) {
    $where[] = "l.estado_proceso = ?";
    $params[] = $estado;
}

if ($proveedor) {
    $where[] = "l.proveedor_id = ?";
    $params[] = $proveedor;
}

if ($calidad) {
    $where[] = "l.calidad_final = ?";
    $params[] = $calidad;
}

$whereClause = implode(' AND ', $where);

// Obtener datos de lotes
$lotes = $db->fetchAll(
    "SELECT l.*, 
            p.nombre as proveedor_nombre, p.cedula as proveedor_cedula,
            v.nombre as variedad_nombre,
            ef.nombre as estado_fermentacion_nombre,
            ec.nombre as estado_calidad_nombre
    FROM lotes l
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    LEFT JOIN estados_fermentacion ef ON l.estado_fermentacion_id = ef.id
    LEFT JOIN estados_calidad ec ON l.estado_calidad_id = ec.id
    WHERE {$whereClause}
    ORDER BY l.fecha_recepcion DESC",
    $params
);

// Obtener proveedores para filtro
$proveedores = $db->fetchAll("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre");

// Calcular estadísticas
$totalLotes = count($lotes);
$pesoTotal = array_sum(array_column($lotes, 'peso_recepcion_kg'));
$pesoFinalTotal = array_sum(array_filter(array_column($lotes, 'peso_final_kg')));

// Conteo por estado
$estadosCount = [];
foreach ($lotes as $l) {
    $est = $l['estado_proceso'];
    $estadosCount[$est] = ($estadosCount[$est] ?? 0) + 1;
}

// Rendimiento promedio
$lotesConPesoFinal = array_filter($lotes, fn($l) => $l['peso_final_kg'] > 0 && $l['peso_recepcion_kg'] > 0);
$rendimientoPromedio = 0;
if (count($lotesConPesoFinal) > 0) {
    $rendimientos = array_map(fn($l) => ($l['peso_final_kg'] / $l['peso_recepcion_kg']) * 100, $lotesConPesoFinal);
    $rendimientoPromedio = array_sum($rendimientos) / count($rendimientos);
}

// Manejar exportación
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_lotes_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Código', 'Proveedor', 'Cédula', 'Variedad', 'Fecha Recepción', 
                      'Peso Recepción (kg)', 'Peso Final (kg)', 'Estado', 'Calidad', 'Observaciones']);
    
    foreach ($lotes as $l) {
        fputcsv($output, [
            $l['codigo'],
            $l['proveedor_nombre'],
            $l['proveedor_cedula'],
            $l['variedad_nombre'],
            $l['fecha_recepcion'],
            $l['peso_recepcion_kg'],
            $l['peso_final_kg'] ?? '-',
            $l['estado_proceso'],
            $l['calidad_final'] ?? '-',
            $l['observaciones']
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
            <h1 class="text-2xl font-bold text-shalom-dark">Reporte de Lotes</h1>
            <p class="text-gray-600">Trazabilidad completa de lotes de cacao</p>
        </div>
        <div class="flex gap-3">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => '1'])) ?>" 
               class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Exportar CSV
            </a>
            <a href="/api/reportes/lotes-pdf.php?<?= http_build_query($_GET) ?>" target="_blank"
               class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700" title="Abrir versión para imprimir">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Imprimir PDF
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
        <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
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
                    <option value="RECEPCION" <?= $estado === 'RECEPCION' ? 'selected' : '' ?>>Recepción</option>
                    <option value="CALIDAD" <?= $estado === 'CALIDAD' ? 'selected' : '' ?>>Verificación de Lote</option>
                    <option value="PRE_SECADO" <?= $estado === 'PRE_SECADO' ? 'selected' : '' ?>>Pre-secado</option>
                    <option value="FERMENTACION" <?= $estado === 'FERMENTACION' ? 'selected' : '' ?>>Fermentación</option>
                    <option value="SECADO" <?= $estado === 'SECADO' ? 'selected' : '' ?>>Secado</option>
                    <option value="CALIDAD_POST" <?= $estado === 'CALIDAD_POST' ? 'selected' : '' ?>>Prueba de Corte</option>
                    <option value="CALIDAD_SALIDA" <?= $estado === 'CALIDAD_SALIDA' ? 'selected' : '' ?>>Calidad de salida</option>
                    <option value="EMPAQUETADO" <?= $estado === 'EMPAQUETADO' ? 'selected' : '' ?>>Empaquetado</option>
                    <option value="ALMACENADO" <?= $estado === 'ALMACENADO' ? 'selected' : '' ?>>Almacenado</option>
                    <option value="DESPACHO" <?= $estado === 'DESPACHO' ? 'selected' : '' ?>>Despacho</option>
                    <option value="FINALIZADO" <?= $estado === 'FINALIZADO' ? 'selected' : '' ?>>Finalizado</option>
                    <option value="RECHAZADO" <?= $estado === 'RECHAZADO' ? 'selected' : '' ?>>Rechazado</option>
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

    <!-- Estadísticas Principales -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-shalom-primary"><?= $totalLotes ?></div>
            <div class="text-sm text-gray-500">Total Lotes</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-blue-600"><?= number_format($pesoTotal, 0) ?></div>
            <div class="text-sm text-gray-500">kg Recibidos</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-green-600"><?= number_format($pesoFinalTotal, 0) ?></div>
            <div class="text-sm text-gray-500">kg Finalizados</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-amber-600"><?= number_format($rendimientoPromedio, 1) ?>%</div>
            <div class="text-sm text-gray-500">Rendimiento Prom.</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-3xl font-bold text-purple-600"><?= count($proveedores) ?></div>
            <div class="text-sm text-gray-500">Proveedores</div>
        </div>
    </div>

    <!-- Distribución por Estado -->
    <div class="bg-white rounded-xl shadow p-4 mb-6">
        <h3 class="text-sm font-medium text-gray-500 mb-3">Distribución por Estado</h3>
        <div class="flex flex-wrap gap-2">
            <?php
            $estadoColors = [
                'RECEPCION' => 'bg-gray-100 text-gray-800',
                'CALIDAD' => 'bg-indigo-100 text-indigo-800',
                'PRE_SECADO' => 'bg-yellow-100 text-yellow-700',
                'FERMENTACION' => 'bg-orange-100 text-orange-800',
                'SECADO' => 'bg-yellow-100 text-yellow-800',
                'CALIDAD_POST' => 'bg-purple-100 text-purple-800',
                'CALIDAD_SALIDA' => 'bg-emerald-100 text-emerald-800',
                'EMPAQUETADO' => 'bg-blue-100 text-blue-800',
                'ALMACENADO' => 'bg-slate-100 text-slate-800',
                'DESPACHO' => 'bg-cyan-100 text-cyan-800',
                'FINALIZADO' => 'bg-green-100 text-green-800',
                'RECHAZADO' => 'bg-red-100 text-red-800'
            ];
            $estadoLabels = [
                'RECEPCION' => 'Recepción',
                'CALIDAD' => 'Verificación de Lote',
                'PRE_SECADO' => 'Pre-secado',
                'FERMENTACION' => 'Fermentación',
                'SECADO' => 'Secado',
                'CALIDAD_POST' => 'Prueba de Corte',
                'CALIDAD_SALIDA' => 'Calidad de salida',
                'EMPAQUETADO' => 'Empaquetado',
                'ALMACENADO' => 'Almacenado',
                'DESPACHO' => 'Despacho',
                'FINALIZADO' => 'Finalizado',
                'RECHAZADO' => 'Rechazado',
            ];
            foreach ($estadosCount as $est => $count):
            $color = $estadoColors[$est] ?? 'bg-gray-100 text-gray-800';
            ?>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?= $color ?>">
                <?= htmlspecialchars($estadoLabels[$est] ?? $est) ?>: <?= $count ?>
            </span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tabla de Datos -->
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Proveedor</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Variedad</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Peso Rec.</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Peso Fin.</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rendimiento</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Calidad</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($lotes)): ?>
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                            No se encontraron lotes en el período seleccionado
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($lotes as $l): ?>
                    <?php
                    $rendimiento = ($l['peso_final_kg'] && $l['peso_recepcion_kg']) 
                        ? ($l['peso_final_kg'] / $l['peso_recepcion_kg']) * 100 
                        : null;
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="/lotes/ver.php?id=<?= $l['id'] ?>" class="font-medium text-shalom-primary hover:underline">
                                <?= e($l['codigo']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600"><?= e($l['proveedor_nombre']) ?></td>
                        <td class="px-4 py-3 text-sm"><?= e($l['variedad_nombre'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-sm"><?= date('d/m/Y', strtotime($l['fecha_recepcion'])) ?></td>
                        <td class="px-4 py-3 text-sm font-medium"><?= number_format($l['peso_recepcion_kg'], 1) ?> kg</td>
                        <td class="px-4 py-3 text-sm">
                            <?= $l['peso_final_kg'] ? number_format($l['peso_final_kg'], 1) . ' kg' : '-' ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($rendimiento !== null): ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $rendimiento >= 35 ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>">
                                <?= number_format($rendimiento, 1) ?>%
                            </span>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php $color = $estadoColors[$l['estado_proceso']] ?? 'bg-gray-100 text-gray-800'; ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $color ?>">
                                <?= e($l['estado_proceso']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($l['calidad_final']): ?>
                            <?php
                            $calidadColors = [
                                'PREMIUM' => 'bg-emerald-100 text-emerald-800',
                                'EXPORTACION' => 'bg-amber-100 text-amber-800',
                                'NACIONAL' => 'bg-blue-100 text-blue-800',
                                'RECHAZADO' => 'bg-red-100 text-red-800'
                            ];
                            $calidadColor = $calidadColors[$l['calidad_final']] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $calidadColor ?>">
                                <?= e($l['calidad_final']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Resumen por Proveedor -->
    <?php if (!empty($lotes)): ?>
    <div class="mt-8 bg-white rounded-xl shadow p-6">
        <h3 class="text-lg font-semibold text-shalom-dark mb-4">Resumen por Proveedor</h3>
        <?php
        $porProveedor = [];
        foreach ($lotes as $l) {
            $prov = $l['proveedor_nombre'] ?? 'Sin proveedor';
            if (!isset($porProveedor[$prov])) {
                $porProveedor[$prov] = ['count' => 0, 'peso_total' => 0, 'peso_final' => 0];
            }
            $porProveedor[$prov]['count']++;
            $porProveedor[$prov]['peso_total'] += $l['peso_recepcion_kg'] ?? 0;
            $porProveedor[$prov]['peso_final'] += $l['peso_final_kg'] ?? 0;
        }
        uasort($porProveedor, fn($a, $b) => $b['peso_total'] <=> $a['peso_total']);
        ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Proveedor</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Lotes</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Peso Recibido</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Peso Final</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Rendimiento</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach (array_slice($porProveedor, 0, 10, true) as $prov => $data): 
                    $rend = $data['peso_total'] > 0 ? ($data['peso_final'] / $data['peso_total']) * 100 : 0;
                    ?>
                    <tr>
                        <td class="px-4 py-2 font-medium"><?= e($prov) ?></td>
                        <td class="px-4 py-2"><?= $data['count'] ?></td>
                        <td class="px-4 py-2"><?= number_format($data['peso_total'], 1) ?> kg</td>
                        <td class="px-4 py-2"><?= number_format($data['peso_final'], 1) ?> kg</td>
                        <td class="px-4 py-2">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $rend >= 35 ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>">
                                <?= number_format($rend, 1) ?>%
                            </span>
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
