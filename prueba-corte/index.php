<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Listado de Pruebas de Corte
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

// Compatibilidad de esquema (instalaciones con columnas distintas)
$colsPrueba = array_column($db->fetchAll("SHOW COLUMNS FROM registros_prueba_corte"), 'Field');
$hasPrCol = static fn(string $name): bool => in_array($name, $colsPrueba, true);

$colCalidad = $hasPrCol('calidad_resultado')
    ? 'calidad_resultado'
    : ($hasPrCol('calidad_determinada') ? 'calidad_determinada' : ($hasPrCol('decision_lote') ? 'decision_lote' : null));
$colFechaPrueba = $hasPrCol('fecha_prueba') ? 'fecha_prueba' : ($hasPrCol('fecha') ? 'fecha' : null);
$colTotalGranos = $hasPrCol('total_granos') ? 'total_granos' : ($hasPrCol('granos_analizados') ? 'granos_analizados' : null);
$colPctFermentacion = $hasPrCol('porcentaje_fermentacion') ? 'porcentaje_fermentacion' : null;
$colAnalistaId = $hasPrCol('analista_id')
    ? 'analista_id'
    : ($hasPrCol('responsable_analisis_id') ? 'responsable_analisis_id' : ($hasPrCol('usuario_id') ? 'usuario_id' : null));

// Filtros
$filtroCalidad = $_GET['calidad'] ?? '';
$busqueda = $_GET['q'] ?? '';
$pagina = max(1, intval($_GET['page'] ?? 1));

// Construir query
$where = ['1=1'];
$params = [];

if ($filtroCalidad && $colCalidad) {
    $where[] = "rpc.{$colCalidad} = :calidad";
    $params['calidad'] = $filtroCalidad;
}

if ($busqueda) {
    $where[] = "(l.codigo LIKE :busqueda OR p.nombre LIKE :busqueda2)";
    $params['busqueda'] = "%{$busqueda}%";
    $params['busqueda2'] = "%{$busqueda}%";
}

$whereClause = implode(' AND ', $where);

// Contar total
$total = $db->fetch("
    SELECT COUNT(*) as total 
    FROM registros_prueba_corte rpc
    JOIN lotes l ON rpc.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    WHERE {$whereClause}
", $params)['total'];

// Paginación
$pagination = Helpers::paginate($total, ITEMS_PER_PAGE, $pagina);

if ($colAnalistaId) {
    $joinAnalista = "LEFT JOIN usuarios u ON rpc.{$colAnalistaId} = u.id";
} else {
    $joinAnalista = "LEFT JOIN usuarios u ON 1 = 0";
}

$fechaExpr = $colFechaPrueba ? "rpc.{$colFechaPrueba}" : "NULL";
$calidadExpr = $colCalidad ? "rpc.{$colCalidad}" : "NULL";
$totalExpr = $colTotalGranos ? "rpc.{$colTotalGranos}" : "0";
$pctFerExpr = $colPctFermentacion ? "rpc.{$colPctFermentacion}" : "0";

$mohososExpr = $hasPrCol('granos_mohosos') ? 'rpc.granos_mohosos' : ($hasPrCol('mohosos') ? 'rpc.mohosos' : '0');
$pizarraExpr = $hasPrCol('granos_pizarra') ? 'rpc.granos_pizarra' : ($hasPrCol('pizarrosos') ? 'rpc.pizarrosos' : '0');
$violetaExpr = $hasPrCol('granos_violetas') ? 'rpc.granos_violetas' : ($hasPrCol('violeta') ? 'rpc.violeta' : '0');
$germinadosExpr = $hasPrCol('granos_germinados') ? 'rpc.granos_germinados' : ($hasPrCol('germinados') ? 'rpc.germinados' : '0');
$danadosExpr = $hasPrCol('granos_danados')
    ? 'rpc.granos_danados'
    : ($hasPrCol('granos_dañados') ? 'rpc.granos_dañados' : ($hasPrCol('insectados') ? 'rpc.insectados' : '0'));

// Obtener registros
$registros = $db->fetchAll("
    SELECT rpc.*, 
           l.codigo as lote_codigo,
           p.nombre as proveedor,
           u.nombre as analista,
           {$fechaExpr} as fecha_prueba,
           {$calidadExpr} as calidad_resultado,
           {$totalExpr} as total_granos,
           {$pctFerExpr} as porcentaje_fermentacion,
           {$mohososExpr} as granos_mohosos,
           {$pizarraExpr} as granos_pizarra,
           {$violetaExpr} as granos_violetas,
           {$germinadosExpr} as granos_germinados,
           {$danadosExpr} as granos_danados
    FROM registros_prueba_corte rpc
    JOIN lotes l ON rpc.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    {$joinAnalista}
    WHERE {$whereClause}
    ORDER BY {$fechaExpr} DESC, rpc.id DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
", $params);

// Lotes listos para prueba de corte
$lotesParaPrueba = $db->fetchAll("
    SELECT l.id, l.codigo, p.nombre as proveedor
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    WHERE l.estado_proceso = 'CALIDAD_POST'
    AND NOT EXISTS (SELECT 1 FROM registros_prueba_corte rpc WHERE rpc.lote_id = l.id)
    ORDER BY l.fecha_entrada DESC
");

$pageTitle = 'Prueba de Corte';
$pageSubtitle = 'Control de calidad post-secado';

ob_start();
?>

<!-- Filtros -->
<div class="card mb-6">
    <div class="card-body">
        <form method="GET" class="flex flex-wrap items-center gap-4">
            <div class="flex-1 min-w-[200px]">
                <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" 
                       class="form-control" placeholder="Buscar lote o proveedor...">
            </div>
            <div class="w-48">
                <select name="calidad" class="form-control form-select">
                    <option value="">Todas las calidades</option>
                    <option value="PREMIUM" <?= $filtroCalidad === 'PREMIUM' ? 'selected' : '' ?>>Premium</option>
                    <option value="EXPORTACION" <?= $filtroCalidad === 'EXPORTACION' ? 'selected' : '' ?>>Exportación</option>
                    <option value="NACIONAL" <?= $filtroCalidad === 'NACIONAL' ? 'selected' : '' ?>>Nacional</option>
                    <option value="RECHAZADO" <?= $filtroCalidad === 'RECHAZADO' ? 'selected' : '' ?>>Rechazado</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                Filtrar
            </button>
            <?php if ($busqueda || $filtroCalidad): ?>
                <a href="<?= APP_URL ?>/prueba-corte/index.php" class="btn btn-outline">Limpiar</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Acciones -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <p class="text-warmgray">
        Mostrando <span class="font-medium text-gray-900"><?= count($registros) ?></span> de 
        <span class="font-medium text-gray-900"><?= $total ?></span> registros
    </p>
    
    <?php if (!empty($lotesParaPrueba)): ?>
        <div class="flex items-center gap-3">
            <select id="lote_select" class="form-control form-select w-56">
                <option value="">Seleccionar lote...</option>
                <?php foreach ($lotesParaPrueba as $lote): ?>
                    <option value="<?= $lote['id'] ?>"><?= htmlspecialchars($lote['codigo']) ?> - <?= htmlspecialchars($lote['proveedor']) ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="iniciarPrueba()" class="btn btn-primary">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Nueva Prueba
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Tabla -->
<div class="card">
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Lote</th>
                    <th>Proveedor</th>
                    <th>Fecha</th>
                    <th>Granos Analizados</th>
                    <th>% Fermentado</th>
                    <th>% Defectos</th>
                    <th>Calidad</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($registros)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-12">
                            <div class="text-warmgray">
                                <svg class="w-12 h-12 mx-auto mb-4 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                                </svg>
                                <p>No se encontraron registros de prueba de corte</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($registros as $reg): ?>
                        <?php 
                        $totalDefectos = ($reg['granos_mohosos'] ?? 0) + ($reg['granos_pizarra'] ?? 0) + 
                                        ($reg['granos_violetas'] ?? 0) + ($reg['granos_germinados'] ?? 0) + 
                                        ($reg['granos_danados'] ?? ($reg['granos_dañados'] ?? 0));
                        $pctDefectos = $reg['total_granos'] > 0 ? ($totalDefectos / $reg['total_granos']) * 100 : 0;
                        ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $reg['lote_id'] ?>" 
                                   class="font-medium text-primary hover:underline">
                                    <?= htmlspecialchars($reg['lote_codigo']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($reg['proveedor']) ?></td>
                            <td><?= Helpers::formatDate($reg['fecha_prueba']) ?></td>
                            <td class="text-center"><?= $reg['total_granos'] ?></td>
                            <td class="text-center">
                                <span class="<?= $reg['porcentaje_fermentacion'] >= 70 ? 'text-green-600 font-semibold' : '' ?>">
                                    <?= number_format($reg['porcentaje_fermentacion'], 1) ?>%
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="<?= $pctDefectos <= 5 ? 'text-green-600' : ($pctDefectos <= 10 ? 'text-gold' : 'text-red-600') ?> font-semibold">
                                    <?= number_format($pctDefectos, 1) ?>%
                                </span>
                            </td>
                            <td>
                                <?php
                                $badgeClass = match($reg['calidad_resultado']) {
                                    'PREMIUM' => 'badge-success',
                                    'EXPORTACION' => 'badge-primary',
                                    'NACIONAL' => 'badge-gold',
                                    'RECHAZADO' => 'badge-error',
                                    default => 'badge-secondary'
                                };
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= $reg['calidad_resultado'] ?></span>
                            </td>
                            <td>
                                <div class="flex items-center justify-center gap-2">
                                    <a href="<?= APP_URL ?>/prueba-corte/ver.php?id=<?= $reg['id'] ?>" 
                                       class="p-2 text-warmgray hover:text-primary hover:bg-olive/20 rounded-lg transition-colors"
                                       title="Ver detalle">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </a>
                                    <a href="<?= APP_URL ?>/reportes/prueba-corte.php?id=<?= $reg['id'] ?>" 
                                       class="p-2 text-warmgray hover:text-primary hover:bg-olive/20 rounded-lg transition-colors"
                                       title="Ver reporte">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="card-footer">
            <div class="flex items-center justify-between">
                <p class="text-sm text-warmgray">
                    Página <?= $pagination['current_page'] ?> de <?= $pagination['total_pages'] ?>
                </p>
                <div class="flex gap-2">
                    <?php if ($pagination['has_prev']): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['current_page'] - 1])) ?>" 
                           class="btn btn-sm btn-outline">Anterior</a>
                    <?php endif; ?>
                    <?php if ($pagination['has_next']): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['current_page'] + 1])) ?>" 
                           class="btn btn-sm btn-primary">Siguiente</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function iniciarPrueba() {
    const loteId = document.getElementById('lote_select').value;
    if (!loteId) {
        App.toast('Seleccione un lote', 'warning');
        return;
    }
    window.location.href = '<?= APP_URL ?>/prueba-corte/crear.php?lote_id=' + loteId;
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
