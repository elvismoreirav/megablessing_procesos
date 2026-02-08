<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Listado de Fermentaciones
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

// Compatibilidad de esquema: algunas instalaciones usan fecha_fin y otras fecha_salida.
$colFechaFin = $db->fetch("SHOW COLUMNS FROM registros_fermentacion LIKE 'fecha_fin'");
$colFechaSalida = $db->fetch("SHOW COLUMNS FROM registros_fermentacion LIKE 'fecha_salida'");
$campoFechaCierre = $colFechaFin ? 'rf.fecha_fin' : ($colFechaSalida ? 'rf.fecha_salida' : 'NULL');

// Filtros
$filtroEstado = $_GET['estado'] ?? '';
$busqueda = $_GET['q'] ?? '';
$pagina = max(1, intval($_GET['page'] ?? 1));

// Construir query
$where = ['1=1'];
$params = [];

if ($filtroEstado === 'activo') {
    $where[] = "{$campoFechaCierre} IS NULL";
} elseif ($filtroEstado === 'finalizado') {
    $where[] = "{$campoFechaCierre} IS NOT NULL";
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
    FROM registros_fermentacion rf
    JOIN lotes l ON rf.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    WHERE {$whereClause}
", $params)['total'];

// Paginación
$pagination = Helpers::paginate($total, ITEMS_PER_PAGE, $pagina);

// Obtener registros
$registros = $db->fetchAll("
    SELECT rf.*, 
           l.codigo as lote_codigo,
           p.nombre as proveedor,
           cf.nombre as cajon,
           u.nombre as responsable,
           {$campoFechaCierre} as fecha_cierre
    FROM registros_fermentacion rf
    JOIN lotes l ON rf.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN cajones_fermentacion cf ON rf.cajon_id = cf.id
    JOIN usuarios u ON rf.responsable_id = u.id
    WHERE {$whereClause}
    ORDER BY rf.fecha_inicio DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
", $params);

// Lotes en fermentación activa
$lotesEnFermentacion = $db->fetchAll("
    SELECT l.id, l.codigo 
    FROM lotes l
    WHERE l.estado_proceso = 'FERMENTACION'
    AND NOT EXISTS (SELECT 1 FROM registros_fermentacion rf WHERE rf.lote_id = l.id)
    ORDER BY l.fecha_entrada DESC
");

$pageTitle = 'Fermentación';
$pageSubtitle = 'Control de proceso de fermentación';

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
                <select name="estado" class="form-control form-select">
                    <option value="">Todos los estados</option>
                    <option value="activo" <?= $filtroEstado === 'activo' ? 'selected' : '' ?>>En proceso</option>
                    <option value="finalizado" <?= $filtroEstado === 'finalizado' ? 'selected' : '' ?>>Finalizados</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                Filtrar
            </button>
            <?php if ($busqueda || $filtroEstado): ?>
                <a href="<?= APP_URL ?>/fermentacion/index.php" class="btn btn-outline">Limpiar</a>
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
    
    <?php if (!empty($lotesEnFermentacion)): ?>
        <div class="flex items-center gap-3">
            <select id="lote_select" class="form-control form-select w-48">
                <option value="">Seleccionar lote...</option>
                <?php foreach ($lotesEnFermentacion as $lote): ?>
                    <option value="<?= $lote['id'] ?>"><?= htmlspecialchars($lote['codigo']) ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="iniciarFermentacion()" class="btn btn-primary">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Iniciar Fermentación
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
                    <th>Cajón</th>
                    <th>Fecha Inicio</th>
                    <th>Fecha Fin</th>
                    <th>Días</th>
                    <th>Volteos</th>
                    <th>Estado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($registros)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-12">
                            <div class="text-warmgray">
                                <svg class="w-12 h-12 mx-auto mb-4 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                                </svg>
                                <p>No se encontraron registros de fermentación</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($registros as $reg): ?>
                        <?php 
                        $fechaCierre = $reg['fecha_cierre'] ?? null;
                        $dias = $fechaCierre
                            ? (new DateTime($reg['fecha_inicio']))->diff(new DateTime($fechaCierre))->days 
                            : (new DateTime($reg['fecha_inicio']))->diff(new DateTime())->days;
                        ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $reg['lote_id'] ?>" 
                                   class="font-medium text-primary hover:underline">
                                    <?= htmlspecialchars($reg['lote_codigo']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($reg['proveedor']) ?></td>
                            <td><?= $reg['cajon'] ? htmlspecialchars($reg['cajon']) : '<span class="text-warmgray">-</span>' ?></td>
                            <td><?= Helpers::formatDate($reg['fecha_inicio']) ?></td>
                            <td><?= $fechaCierre ? Helpers::formatDate($fechaCierre) : '<span class="text-warmgray">-</span>' ?></td>
                            <td>
                                <span class="badge <?= $dias >= 6 ? 'badge-success' : 'badge-warning' ?>"><?= $dias ?> días</span>
                            </td>
                            <td><?= $reg['total_volteos'] ?? 0 ?></td>
                            <td>
                                <?php if ($fechaCierre): ?>
                                    <span class="badge badge-success">Finalizado</span>
                                <?php else: ?>
                                    <span class="badge badge-gold">En proceso</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="flex items-center justify-center gap-2">
                                    <a href="<?= APP_URL ?>/fermentacion/control.php?id=<?= $reg['id'] ?>" 
                                       class="p-2 text-warmgray hover:text-primary hover:bg-olive/20 rounded-lg transition-colors"
                                       title="Control diario">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                    </a>
                                    <a href="<?= APP_URL ?>/fermentacion/ver.php?id=<?= $reg['id'] ?>" 
                                       class="p-2 text-warmgray hover:text-primary hover:bg-olive/20 rounded-lg transition-colors"
                                       title="Ver detalle">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
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
function iniciarFermentacion() {
    const loteId = document.getElementById('lote_select').value;
    if (!loteId) {
        App.toast('Seleccione un lote', 'warning');
        return;
    }
    window.location.href = '<?= APP_URL ?>/fermentacion/crear.php?lote_id=' + loteId;
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
