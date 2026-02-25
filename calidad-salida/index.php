<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Listado de Calidad de salida
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();
$tablaExiste = (bool)$db->fetch("SHOW TABLES LIKE 'registros_calidad_salida'");

// Filtros
$filtroGrado = $_GET['grado'] ?? '';
$busqueda = trim((string)($_GET['q'] ?? ''));
$pagina = max(1, intval($_GET['page'] ?? 1));

$total = 0;
$pagination = Helpers::paginate(0, ITEMS_PER_PAGE, $pagina);
$registros = [];
$lotesDisponibles = [];

if ($tablaExiste) {
    $where = ['1=1'];
    $params = [];

    if ($filtroGrado !== '') {
        $where[] = 'rcs.grado_calidad = :grado';
        $params['grado'] = $filtroGrado;
    }

    if ($busqueda !== '') {
        $where[] = '(l.codigo LIKE :busqueda OR p.nombre LIKE :busqueda2)';
        $params['busqueda'] = "%{$busqueda}%";
        $params['busqueda2'] = "%{$busqueda}%";
    }

    $whereClause = implode(' AND ', $where);

    $total = (int)$db->fetch("\n        SELECT COUNT(*) AS total\n        FROM registros_calidad_salida rcs\n        JOIN lotes l ON l.id = rcs.lote_id\n        JOIN proveedores p ON p.id = l.proveedor_id\n        WHERE {$whereClause}\n    ", $params)['total'];

    $pagination = Helpers::paginate($total, ITEMS_PER_PAGE, $pagina);

    $registros = $db->fetchAll("\n        SELECT rcs.*,\n               l.codigo AS lote_codigo,\n               l.estado_proceso,\n               p.nombre AS proveedor,\n               p.codigo AS proveedor_codigo,\n               v.nombre AS variedad,\n               u.nombre AS usuario_nombre\n        FROM registros_calidad_salida rcs\n        JOIN lotes l ON l.id = rcs.lote_id\n        JOIN proveedores p ON p.id = l.proveedor_id\n        JOIN variedades v ON v.id = l.variedad_id\n        LEFT JOIN usuarios u ON u.id = rcs.usuario_id\n        WHERE {$whereClause}\n        ORDER BY rcs.fecha_registro DESC, rcs.id DESC\n        LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}\n    ", $params);

    $lotesDisponibles = $db->fetchAll("\n        SELECT l.id, l.codigo, p.nombre AS proveedor, p.codigo AS proveedor_codigo, v.nombre AS variedad\n        FROM lotes l\n        JOIN proveedores p ON p.id = l.proveedor_id\n        JOIN variedades v ON v.id = l.variedad_id\n        WHERE l.estado_proceso = 'CALIDAD_SALIDA'\n          AND NOT EXISTS (SELECT 1 FROM registros_calidad_salida rcs WHERE rcs.lote_id = l.id)\n        ORDER BY l.fecha_entrada DESC, l.id DESC\n    ");
}

$pageTitle = 'Calidad de salida';
$pageSubtitle = 'Validación final antes de empaquetado';

ob_start();
?>

<?php if (!$tablaExiste): ?>
    <div class="card border border-amber-200 bg-amber-50/70">
        <div class="card-body">
            <h3 class="text-lg font-semibold text-amber-900 mb-2">Módulo pendiente de base de datos</h3>
            <p class="text-sm text-amber-800">
                Para habilitar este módulo, ejecute el patch <code>database/patch_calidad_salida.sql</code> en la base de datos.
            </p>
        </div>
    </div>
<?php else: ?>

<!-- Filtros -->
<div class="card mb-6">
    <div class="card-body">
        <form method="GET" class="flex flex-wrap items-center gap-4">
            <div class="flex-1 min-w-[220px]">
                <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>"
                       class="form-control" placeholder="Buscar por lote o proveedor...">
            </div>
            <div class="w-56">
                <select name="grado" class="form-control form-select">
                    <option value="">Todos los grados</option>
                    <option value="GRADO_1" <?= $filtroGrado === 'GRADO_1' ? 'selected' : '' ?>>Grado 1</option>
                    <option value="GRADO_2" <?= $filtroGrado === 'GRADO_2' ? 'selected' : '' ?>>Grado 2</option>
                    <option value="GRADO_3" <?= $filtroGrado === 'GRADO_3' ? 'selected' : '' ?>>Grado 3</option>
                    <option value="NO_APLICA" <?= $filtroGrado === 'NO_APLICA' ? 'selected' : '' ?>>No aplica</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                Filtrar
            </button>
            <?php if ($busqueda !== '' || $filtroGrado !== ''): ?>
                <a href="<?= APP_URL ?>/calidad-salida/index.php" class="btn btn-outline">Limpiar</a>
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

    <?php if (!empty($lotesDisponibles)): ?>
        <div class="flex items-center gap-3">
            <select id="lote_select" class="form-control form-select w-72">
                <option value="">Seleccionar lote...</option>
                <?php foreach ($lotesDisponibles as $lote): ?>
                    <option value="<?= (int)$lote['id'] ?>">
                        <?= htmlspecialchars($lote['codigo']) ?> - <?= htmlspecialchars($lote['proveedor']) ?> (<?= htmlspecialchars($lote['variedad']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" onclick="iniciarCalidadSalida()" class="btn btn-primary">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m4 2a8 8 0 11-16 0 8 8 0 0116 0z"/>
                </svg>
                Nueva Ficha
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
                    <th>Variedad</th>
                    <th>Grado</th>
                    <th>Certificaciones</th>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($registros)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-12">
                            <div class="text-warmgray">
                                <svg class="w-12 h-12 mx-auto mb-4 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m4 2a8 8 0 11-16 0 8 8 0 0116 0z"/>
                                </svg>
                                <p>No se encontraron registros de calidad de salida</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($registros as $reg): ?>
                        <?php
                        $certLabelMap = [
                            'ORGANICA' => 'Orgánica',
                            'COMERCIO_JUSTO' => 'Comercio Justo',
                            'EUDR' => 'EUDR',
                            'OTRAS' => 'Otras',
                            'NO_APLICA' => 'No aplica',
                        ];
                        $certificaciones = [];
                        if (!empty($reg['certificaciones'])) {
                            $certificaciones = json_decode((string)$reg['certificaciones'], true) ?: [];
                        }
                        $certTexto = trim((string)($reg['certificaciones_texto'] ?? ''));
                        if ($certTexto !== '') {
                            $certificaciones = array_filter(array_map('trim', explode(',', $certTexto)));
                        }
                        if (empty($certificaciones)) {
                            $certificaciones = ['Sin registro'];
                        }
                        $certificaciones = array_map(static function ($item) use ($certLabelMap) {
                            $key = strtoupper(trim((string)$item));
                            return $certLabelMap[$key] ?? $item;
                        }, $certificaciones);
                        ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= (int)$reg['lote_id'] ?>"
                                   class="font-medium text-primary hover:underline">
                                    <?= htmlspecialchars($reg['lote_codigo']) ?>
                                </a>
                            </td>
                            <td>
                                <span class="font-semibold text-primary"><?= htmlspecialchars($reg['proveedor_codigo']) ?></span>
                                - <?= htmlspecialchars($reg['proveedor']) ?>
                            </td>
                            <td><?= htmlspecialchars($reg['variedad']) ?></td>
                            <td>
                                <?php
                                $gradoLabel = match ((string)$reg['grado_calidad']) {
                                    'GRADO_1' => 'Grado 1',
                                    'GRADO_2' => 'Grado 2',
                                    'GRADO_3' => 'Grado 3',
                                    default => 'No aplica',
                                };
                                ?>
                                <span class="badge badge-primary"><?= htmlspecialchars($gradoLabel) ?></span>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    <?php foreach ($certificaciones as $cert): ?>
                                        <span class="badge badge-primary"><?= htmlspecialchars($cert) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td><?= Helpers::formatDate($reg['fecha_registro']) ?></td>
                            <td><?= htmlspecialchars($reg['usuario_nombre'] ?? 'Sistema') ?></td>
                            <td>
                                <div class="flex items-center justify-center gap-2">
                                    <a href="<?= APP_URL ?>/calidad-salida/ver.php?id=<?= (int)$reg['id'] ?>"
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
                <p class="text-sm text-warmgray">Página <?= $pagination['current_page'] ?> de <?= $pagination['total_pages'] ?></p>
                <div class="flex gap-2">
                    <?php if ($pagination['has_prev']): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['current_page'] - 1])) ?>" class="btn btn-sm btn-outline">Anterior</a>
                    <?php endif; ?>
                    <?php if ($pagination['has_next']): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['current_page'] + 1])) ?>" class="btn btn-sm btn-primary">Siguiente</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function iniciarCalidadSalida() {
    const loteId = document.getElementById('lote_select').value;
    if (!loteId) {
        App.toast('Seleccione un lote para continuar', 'warning');
        return;
    }
    window.location.href = '<?= APP_URL ?>/calidad-salida/crear.php?lote_id=' + loteId;
}
</script>

<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
