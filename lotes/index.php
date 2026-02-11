<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Listado de Lotes
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

// Filtros
$filtroEstado = $_GET['estado'] ?? '';
$filtroProveedor = $_GET['proveedor'] ?? '';
$filtroFechaDesde = $_GET['fecha_desde'] ?? '';
$filtroFechaHasta = $_GET['fecha_hasta'] ?? '';
$busqueda = $_GET['q'] ?? '';
$pagina = max(1, intval($_GET['page'] ?? 1));

// Construir query
$where = ['1=1'];
$params = [];

if ($filtroEstado) {
    $where[] = "l.estado_proceso = :estado";
    $params['estado'] = $filtroEstado;
}

if ($filtroProveedor) {
    $where[] = "l.proveedor_id = :proveedor";
    $params['proveedor'] = $filtroProveedor;
}

if ($filtroFechaDesde) {
    $where[] = "l.fecha_entrada >= :fecha_desde";
    $params['fecha_desde'] = $filtroFechaDesde;
}

if ($filtroFechaHasta) {
    $where[] = "l.fecha_entrada <= :fecha_hasta";
    $params['fecha_hasta'] = $filtroFechaHasta;
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
    FROM lotes l 
    JOIN proveedores p ON l.proveedor_id = p.id 
    WHERE {$whereClause}
", $params)['total'];

// Paginación
$pagination = Helpers::paginate($total, ITEMS_PER_PAGE, $pagina);

// Obtener lotes
$lotes = $db->fetchAll("
    SELECT l.*, 
           p.nombre as proveedor, p.codigo as proveedor_codigo,
           v.nombre as variedad,
           ep.nombre as estado_producto,
           ef.nombre as estado_fermentacion,
           u.nombre as usuario
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    JOIN variedades v ON l.variedad_id = v.id
    JOIN estados_producto ep ON l.estado_producto_id = ep.id
    LEFT JOIN estados_fermentacion ef ON l.estado_fermentacion_id = ef.id
    JOIN usuarios u ON l.usuario_id = u.id
    WHERE {$whereClause}
    ORDER BY l.fecha_entrada DESC, l.created_at DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
", $params);

// Datos para filtros
$proveedores = $db->fetchAll("SELECT id, nombre, codigo FROM proveedores WHERE activo = 1 ORDER BY nombre");
$estados = ['RECEPCION', 'CALIDAD', 'PRE_SECADO', 'FERMENTACION', 'SECADO', 'CALIDAD_POST', 'EMPAQUETADO', 'ALMACENADO', 'DESPACHO', 'FINALIZADO', 'RECHAZADO'];
$estadoLabels = [
    'RECEPCION' => 'Recepción',
    'CALIDAD' => 'Verificación de Lote',
    'PRE_SECADO' => 'Pre-secado (Legado)',
    'FERMENTACION' => 'Fermentación',
    'SECADO' => 'Secado',
    'CALIDAD_POST' => 'Prueba de Corte',
    'EMPAQUETADO' => 'Empaquetado',
    'ALMACENADO' => 'Almacenado',
    'DESPACHO' => 'Despacho',
    'FINALIZADO' => 'Finalizado',
    'RECHAZADO' => 'Rechazado',
];

$pageTitle = 'Lotes / Recepción';
$pageSubtitle = 'Gestión de lotes de cacao';

ob_start();
?>

<!-- Filtros -->
<div class="card mb-6">
    <div class="card-body">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4">
            <div>
                <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" 
                       class="form-control" placeholder="Buscar código o proveedor...">
            </div>
            <div>
                <select name="estado" class="form-control form-select">
                    <option value="">Todos los estados</option>
                    <?php foreach ($estados as $estado): ?>
                        <option value="<?= $estado ?>" <?= $filtroEstado === $estado ? 'selected' : '' ?>>
                            <?= htmlspecialchars($estadoLabels[$estado] ?? ucfirst(strtolower(str_replace('_', ' ', $estado)))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <select name="proveedor" class="form-control form-select">
                    <option value="">Todos los proveedores</option>
                    <?php foreach ($proveedores as $prov): ?>
                        <option value="<?= $prov['id'] ?>" <?= $filtroProveedor == $prov['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($prov['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <input type="date" name="fecha_desde" value="<?= $filtroFechaDesde ?>" 
                       class="form-control" placeholder="Desde">
            </div>
            <div>
                <input type="date" name="fecha_hasta" value="<?= $filtroFechaHasta ?>" 
                       class="form-control" placeholder="Hasta">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary flex-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    Filtrar
                </button>
                <a href="<?= APP_URL ?>/lotes/index.php" class="btn btn-outline">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Acciones -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <p class="text-warmgray">
        Mostrando <span class="font-medium text-gray-900"><?= count($lotes) ?></span> de 
        <span class="font-medium text-gray-900"><?= $total ?></span> lotes
    </p>
    
    <div class="flex gap-3">
        <a href="<?= APP_URL ?>/lotes/crear.php" class="btn btn-primary">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nuevo Lote
        </a>
    </div>
</div>

<!-- Tabla de lotes -->
<div class="card">
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Fecha</th>
                    <th>Proveedor</th>
                    <th>Variedad</th>
                    <th>Estado Prod.</th>
                    <th>Peso (Kg)</th>
                    <th>Humedad</th>
                    <th>Proceso</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lotes)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-12">
                            <div class="text-warmgray">
                                <svg class="w-12 h-12 mx-auto mb-4 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                                <p>No se encontraron lotes</p>
                                <?php if ($busqueda || $filtroEstado || $filtroProveedor): ?>
                                    <a href="<?= APP_URL ?>/lotes/index.php" class="text-primary hover:underline mt-2 inline-block">
                                        Limpiar filtros
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($lotes as $lote): ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $lote['id'] ?>" 
                                   class="font-medium text-primary hover:underline">
                                    <?= htmlspecialchars($lote['codigo']) ?>
                                </a>
                            </td>
                            <td class="text-warmgray"><?= Helpers::formatDate($lote['fecha_entrada']) ?></td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <span class="w-7 h-7 bg-olive/30 rounded text-xs font-bold flex items-center justify-center text-primary">
                                        <?= htmlspecialchars($lote['proveedor_codigo']) ?>
                                    </span>
                                    <?= htmlspecialchars($lote['proveedor']) ?>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($lote['variedad']) ?></td>
                            <td>
                                <span class="badge badge-primary"><?= htmlspecialchars($lote['estado_producto']) ?></span>
                            </td>
                            <td class="font-medium"><?= number_format($lote['peso_inicial_kg'], 2, ',', '.') ?></td>
                            <td>
                                <?php if ($lote['humedad_inicial']): ?>
                                    <?= number_format($lote['humedad_inicial'], 1) ?>%
                                <?php else: ?>
                                    <span class="text-warmgray">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= Helpers::getEstadoProcesoBadge($lote['estado_proceso']) ?></td>
                            <td>
                                <div class="flex items-center justify-center gap-2">
                                    <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $lote['id'] ?>" 
                                       class="p-2 text-warmgray hover:text-primary hover:bg-olive/20 rounded-lg transition-colors"
                                       title="Ver detalles">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </a>
                                    <a href="<?= APP_URL ?>/lotes/editar.php?id=<?= $lote['id'] ?>" 
                                       class="p-2 text-warmgray hover:text-primary hover:bg-olive/20 rounded-lg transition-colors"
                                       title="Editar">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
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
                           class="btn btn-sm btn-outline">
                            Anterior
                        </a>
                    <?php endif; ?>
                    <?php if ($pagination['has_next']): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['current_page'] + 1])) ?>" 
                           class="btn btn-sm btn-primary">
                            Siguiente
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
