<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Listado de Secado
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

// Compatibilidad de esquema (instalaciones con columnas distintas)
$colsSecado = array_column($db->fetchAll("SHOW COLUMNS FROM registros_secado"), 'Field');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSecado, true);

$fechaInicioExpr = $hasSecCol('fecha_inicio') ? 'rs.fecha_inicio' : ($hasSecCol('fecha') ? 'rs.fecha' : 'NULL');
$fechaFinExpr = $hasSecCol('fecha_fin') ? 'rs.fecha_fin' : 'NULL';
$campoCierre = $hasSecCol('fecha_fin')
    ? 'rs.fecha_fin'
    : ($hasSecCol('humedad_final') ? 'rs.humedad_final' : 'NULL');

// Filtros
$filtroEstado = $_GET['estado'] ?? '';
$busqueda = $_GET['q'] ?? '';
$pagina = max(1, intval($_GET['page'] ?? 1));

// Construir query
$where = ['1=1'];
$params = [];

if ($filtroEstado === 'activo') {
    $where[] = "{$campoCierre} IS NULL";
} elseif ($filtroEstado === 'finalizado') {
    $where[] = "{$campoCierre} IS NOT NULL";
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
    FROM registros_secado rs
    JOIN lotes l ON rs.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    WHERE {$whereClause}
", $params)['total'];

// Paginación
$pagination = Helpers::paginate($total, ITEMS_PER_PAGE, $pagina);

// Obtener registros
$registros = $db->fetchAll("
    SELECT rs.*, 
           {$fechaInicioExpr} as fecha_inicio,
           {$fechaFinExpr} as fecha_fin,
           l.codigo as lote_codigo,
           p.nombre as proveedor,
           s.nombre as secadora,
           u.nombre as responsable
    FROM registros_secado rs
    JOIN lotes l ON rs.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN secadoras s ON rs.secadora_id = s.id
    JOIN usuarios u ON rs.responsable_id = u.id
    WHERE {$whereClause}
    ORDER BY {$fechaInicioExpr} DESC, rs.id DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
", $params);

// Lotes listos para secado
$lotesParaSecado = $db->fetchAll("
    SELECT l.id, l.codigo, p.nombre as proveedor
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    WHERE l.estado_proceso IN ('PRE_SECADO', 'SECADO')
    AND EXISTS (SELECT 1 FROM fichas_registro fr WHERE fr.lote_id = l.id)
    AND NOT EXISTS (SELECT 1 FROM registros_secado rs WHERE rs.lote_id = l.id)
    ORDER BY l.fecha_entrada DESC
");

$pageTitle = 'Secado';
$pageSubtitle = 'Control de proceso de secado';

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
                <a href="<?= APP_URL ?>/secado/index.php" class="btn btn-outline">Limpiar</a>
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
    
    <?php if (!empty($lotesParaSecado)): ?>
        <div class="flex items-center gap-3">
            <select id="lote_select" class="form-control form-select w-56">
                <option value="">Seleccionar lote...</option>
                <?php foreach ($lotesParaSecado as $lote): ?>
                    <option value="<?= $lote['id'] ?>"><?= htmlspecialchars($lote['codigo']) ?> - <?= htmlspecialchars($lote['proveedor']) ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="iniciarSecado()" class="btn btn-primary">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Iniciar Secado
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
                    <th>Secadora</th>
                    <th>Fecha Inicio</th>
                    <th>Fecha Fin</th>
                    <th>Humedad Inicial</th>
                    <th>Humedad Final</th>
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
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                                </svg>
                                <p>No se encontraron registros de secado</p>
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
                            <td><?= $reg['secadora'] ? htmlspecialchars($reg['secadora']) : '<span class="text-warmgray">-</span>' ?></td>
                            <td><?= Helpers::formatDate($reg['fecha_inicio']) ?></td>
                            <td><?= $reg['fecha_fin'] ? Helpers::formatDate($reg['fecha_fin']) : '<span class="text-warmgray">-</span>' ?></td>
                            <td><?= $reg['humedad_inicial'] ? number_format($reg['humedad_inicial'], 1) . '%' : '-' ?></td>
                            <td>
                                <?php if ($reg['humedad_final']): ?>
                                    <span class="<?= $reg['humedad_final'] <= 7 ? 'text-green-600 font-semibold' : 'text-gold' ?>">
                                        <?= number_format($reg['humedad_final'], 1) ?>%
                                    </span>
                                <?php else: ?>
                                    <span class="text-warmgray">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($reg['fecha_fin']): ?>
                                    <span class="badge badge-success">Finalizado</span>
                                <?php else: ?>
                                    <span class="badge badge-gold">En proceso</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="flex items-center justify-center gap-2">
                                    <a href="<?= APP_URL ?>/secado/control.php?id=<?= $reg['id'] ?>" 
                                       class="p-2 text-warmgray hover:text-primary hover:bg-olive/20 rounded-lg transition-colors"
                                       title="Control de temperatura">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                    </a>
                                    <a href="<?= APP_URL ?>/secado/ver.php?id=<?= $reg['id'] ?>" 
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
function iniciarSecado() {
    const loteId = document.getElementById('lote_select').value;
    if (!loteId) {
        App.toast('Seleccione un lote', 'warning');
        return;
    }
    window.location.href = '<?= APP_URL ?>/secado/crear.php?lote_id=' + loteId;
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
