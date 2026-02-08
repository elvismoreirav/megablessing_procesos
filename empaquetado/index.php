<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Listado de Empaquetado
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

// Filtros
$filtroEstado = $_GET['estado'] ?? '';
$filtroCalidad = $_GET['calidad'] ?? '';
$busqueda = $_GET['q'] ?? '';
$pagina = max(1, intval($_GET['page'] ?? 1));

// Construir query
$where = ['1=1'];
$params = [];

if ($filtroEstado === 'pendiente') {
    $where[] = "re.fecha_empaquetado IS NULL";
} elseif ($filtroEstado === 'completado') {
    $where[] = "re.fecha_empaquetado IS NOT NULL";
}

if ($filtroCalidad) {
    $where[] = "l.calidad_final = :calidad";
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
    FROM registros_empaquetado re
    JOIN lotes l ON re.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    WHERE {$whereClause}
", $params)['total'];

// Paginación
$pagination = Helpers::paginate($total, ITEMS_PER_PAGE, $pagina);

// Obtener registros
$registros = $db->fetchAll("
    SELECT re.*, 
           l.codigo as lote_codigo,
           l.calidad_final,
           p.nombre as proveedor,
           u.nombre as operador
    FROM registros_empaquetado re
    JOIN lotes l ON re.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    JOIN usuarios u ON re.operador_id = u.id
    WHERE {$whereClause}
    ORDER BY re.created_at DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
", $params);

// Lotes listos para empaquetado
$lotesParaEmpaquetar = $db->fetchAll("
    SELECT l.id, l.codigo, p.nombre as proveedor, l.calidad_final
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    WHERE l.estado_proceso = 'EMPAQUETADO'
    AND NOT EXISTS (SELECT 1 FROM registros_empaquetado re WHERE re.lote_id = l.id)
    ORDER BY l.fecha_entrada DESC
");

$pageTitle = 'Empaquetado';
$pageSubtitle = 'Gestión de empaque y despacho';

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
            <div class="w-40">
                <select name="estado" class="form-control form-select">
                    <option value="">Todos los estados</option>
                    <option value="pendiente" <?= $filtroEstado === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                    <option value="completado" <?= $filtroEstado === 'completado' ? 'selected' : '' ?>>Completado</option>
                </select>
            </div>
            <div class="w-40">
                <select name="calidad" class="form-control form-select">
                    <option value="">Todas las calidades</option>
                    <option value="PREMIUM" <?= $filtroCalidad === 'PREMIUM' ? 'selected' : '' ?>>Premium</option>
                    <option value="EXPORTACION" <?= $filtroCalidad === 'EXPORTACION' ? 'selected' : '' ?>>Exportación</option>
                    <option value="NACIONAL" <?= $filtroCalidad === 'NACIONAL' ? 'selected' : '' ?>>Nacional</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                Filtrar
            </button>
            <?php if ($busqueda || $filtroEstado || $filtroCalidad): ?>
                <a href="<?= APP_URL ?>/empaquetado/index.php" class="btn btn-outline">Limpiar</a>
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
    
    <?php if (!empty($lotesParaEmpaquetar)): ?>
        <div class="flex items-center gap-3">
            <select id="lote_select" class="form-control form-select w-64">
                <option value="">Seleccionar lote...</option>
                <?php foreach ($lotesParaEmpaquetar as $lote): ?>
                    <option value="<?= $lote['id'] ?>">
                        <?= htmlspecialchars($lote['codigo']) ?> - <?= htmlspecialchars($lote['proveedor']) ?>
                        (<?= $lote['calidad_final'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button onclick="iniciarEmpaquetado()" class="btn btn-primary">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                Nuevo Empaquetado
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
                    <th>Calidad</th>
                    <th class="text-center">Sacos</th>
                    <th class="text-right">Peso Total</th>
                    <th>Fecha Empaque</th>
                    <th>Estado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($registros)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-12">
                            <div class="text-warmgray">
                                <svg class="w-12 h-12 mx-auto mb-4 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                                <p>No se encontraron registros de empaquetado</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($registros as $reg): ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $reg['lote_id'] ?>" 
                                   class="font-medium text-primary hover:underline">
                                    <?= htmlspecialchars($reg['lote_codigo']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($reg['proveedor']) ?></td>
                            <td>
                                <?php
                                $badgeClass = match($reg['calidad_final']) {
                                    'PREMIUM' => 'badge-success',
                                    'EXPORTACION' => 'badge-primary',
                                    'NACIONAL' => 'badge-gold',
                                    default => 'badge-secondary'
                                };
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= $reg['calidad_final'] ?></span>
                            </td>
                            <td class="text-center font-medium"><?= $reg['numero_sacos'] ?? '-' ?></td>
                            <td class="text-right"><?= $reg['peso_total'] ? number_format($reg['peso_total'], 2) . ' kg' : '-' ?></td>
                            <td><?= $reg['fecha_empaquetado'] ? Helpers::formatDate($reg['fecha_empaquetado']) : '-' ?></td>
                            <td>
                                <?php if ($reg['fecha_empaquetado']): ?>
                                    <span class="badge badge-success">Completado</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Pendiente</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="flex items-center justify-center gap-2">
                                    <a href="<?= APP_URL ?>/empaquetado/ver.php?id=<?= $reg['id'] ?>" 
                                       class="p-2 text-warmgray hover:text-primary hover:bg-olive/20 rounded-lg transition-colors"
                                       title="Ver detalle">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </a>
                                    <?php if (!$reg['fecha_empaquetado']): ?>
                                    <a href="<?= APP_URL ?>/empaquetado/registrar.php?id=<?= $reg['id'] ?>" 
                                       class="p-2 text-warmgray hover:text-primary hover:bg-olive/20 rounded-lg transition-colors"
                                       title="Registrar empaque">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </a>
                                    <?php endif; ?>
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
function iniciarEmpaquetado() {
    const loteId = document.getElementById('lote_select').value;
    if (!loteId) {
        App.toast('Seleccione un lote', 'warning');
        return;
    }
    window.location.href = '<?= APP_URL ?>/empaquetado/crear.php?lote_id=' + loteId;
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
