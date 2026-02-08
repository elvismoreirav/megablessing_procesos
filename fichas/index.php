<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Fichas de Registro - Listado
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

// Filtros
$filtroLote = $_GET['lote'] ?? '';
$filtroFecha = $_GET['fecha'] ?? '';
$filtroBusqueda = $_GET['buscar'] ?? '';

// Construir query con filtros
$where = ["1=1"];
$params = [];

if ($filtroLote) {
    $where[] = "f.lote_id = ?";
    $params[] = $filtroLote;
}

if ($filtroFecha) {
    $where[] = "DATE(f.fecha_entrada) = ?";
    $params[] = $filtroFecha;
}

if ($filtroBusqueda) {
    $where[] = "(f.codificacion LIKE ? OR f.producto LIKE ? OR l.codigo LIKE ?)";
    $busqueda = "%{$filtroBusqueda}%";
    $params[] = $busqueda;
    $params[] = $busqueda;
    $params[] = $busqueda;
}

$whereClause = implode(" AND ", $where);

// Obtener fichas con información relacionada
$fichas = $db->fetchAll("
    SELECT f.*,
           l.codigo as lote_codigo,
           l.peso_recibido_kg,
           p.nombre as proveedor_nombre,
           v.nombre as variedad_nombre,
           u.nombre as responsable_nombre
    FROM fichas_registro f
    INNER JOIN lotes l ON f.lote_id = l.id
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    LEFT JOIN usuarios u ON f.responsable_id = u.id
    WHERE {$whereClause}
    ORDER BY f.created_at DESC
", $params);

// Obtener lotes para filtro
$lotes = $db->fetchAll("SELECT id, codigo FROM lotes ORDER BY codigo DESC");

// Estadísticas
$totalFichas = count($fichas);
$fichasHoy = $db->fetchOne("SELECT COUNT(*) as count FROM fichas_registro WHERE DATE(created_at) = CURDATE()")['count'];
$fichasSemana = $db->fetchOne("SELECT COUNT(*) as count FROM fichas_registro WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")['count'];

$pageTitle = 'Fichas de Registro';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Fichas de Registro</h1>
            <p class="text-gray-600">Control de formularios de registro por lote</p>
        </div>
        <a href="/fichas/crear.php" 
           class="inline-flex items-center justify-center px-4 py-2 bg-amber-600 text-white rounded-xl hover:bg-amber-700 transition-colors">
            <i class="fas fa-plus mr-2"></i>Nueva Ficha
        </a>
    </div>

    <!-- Estadísticas -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Total Fichas</p>
                    <p class="text-2xl font-bold text-gray-900"><?= number_format($totalFichas) ?></p>
                </div>
                <div class="w-12 h-12 bg-amber-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-file-alt text-amber-600 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Hoy</p>
                    <p class="text-2xl font-bold text-green-600"><?= number_format($fichasHoy) ?></p>
                </div>
                <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-calendar-day text-green-600 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Última Semana</p>
                    <p class="text-2xl font-bold text-blue-600"><?= number_format($fichasSemana) ?></p>
                </div>
                <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-calendar-week text-blue-600 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <form method="GET" class="flex flex-wrap gap-4">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs text-gray-500 mb-1">Buscar</label>
                <input type="text" name="buscar" value="<?= htmlspecialchars($filtroBusqueda) ?>"
                       placeholder="Código, producto..."
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
            </div>
            
            <div class="w-48">
                <label class="block text-xs text-gray-500 mb-1">Lote</label>
                <select name="lote" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    <option value="">Todos los lotes</option>
                    <?php foreach ($lotes as $lote): ?>
                    <option value="<?= $lote['id'] ?>" <?= $filtroLote == $lote['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lote['codigo']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="w-40">
                <label class="block text-xs text-gray-500 mb-1">Fecha Entrada</label>
                <input type="date" name="fecha" value="<?= htmlspecialchars($filtroFecha) ?>"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
            </div>
            
            <div class="flex items-end gap-2">
                <button type="submit" class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 text-sm">
                    <i class="fas fa-search mr-1"></i>Filtrar
                </button>
                <a href="/fichas/" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm">
                    <i class="fas fa-times mr-1"></i>Limpiar
                </a>
            </div>
        </form>
    </div>

    <!-- Tabla de fichas -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <?php if (empty($fichas)): ?>
        <div class="p-12 text-center">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-file-alt text-gray-400 text-2xl"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No hay fichas registradas</h3>
            <p class="text-gray-500 mb-4">Comienza creando una nueva ficha de registro</p>
            <a href="/fichas/crear.php" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">
                <i class="fas fa-plus mr-2"></i>Nueva Ficha
            </a>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Lote</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Codificación</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Producto</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Proveedor/Ruta</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha Entrada</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Precio Final</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado Ferm.</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Responsable</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($fichas as $ficha): ?>
                    <tr class="hover:bg-amber-50/50 transition-colors">
                        <td class="px-4 py-3">
                            <span class="font-mono text-sm text-gray-600">#<?= $ficha['id'] ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <a href="/lotes/ver.php?id=<?= $ficha['lote_id'] ?>" class="text-amber-600 hover:text-amber-700 font-medium">
                                <?= htmlspecialchars($ficha['lote_codigo']) ?>
                            </a>
                            <?php if ($ficha['variedad_nombre']): ?>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($ficha['variedad_nombre']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($ficha['codificacion']): ?>
                            <span class="font-mono text-sm"><?= htmlspecialchars($ficha['codificacion']) ?></span>
                            <?php else: ?>
                            <span class="text-gray-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <?= htmlspecialchars($ficha['producto'] ?: '—') ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php if ($ficha['proveedor_ruta']): ?>
                            <span class="text-gray-700"><?= htmlspecialchars($ficha['proveedor_ruta']) ?></span>
                            <?php elseif ($ficha['proveedor_nombre']): ?>
                            <span class="text-gray-700"><?= htmlspecialchars($ficha['proveedor_nombre']) ?></span>
                            <?php else: ?>
                            <span class="text-gray-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <?php if ($ficha['fecha_entrada']): ?>
                            <?= date('d/m/Y', strtotime($ficha['fecha_entrada'])) ?>
                            <?php else: ?>
                            <span class="text-gray-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php if (isset($ficha['precio_total_pagar']) && $ficha['precio_total_pagar'] !== null): ?>
                            <span class="font-semibold text-emerald-700">$ <?= number_format((float)$ficha['precio_total_pagar'], 2) ?></span>
                            <?php else: ?>
                            <span class="text-gray-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($ficha['fermentacion_estado']): ?>
                            <?php
                            $estadoColor = match(strtolower($ficha['fermentacion_estado'])) {
                                'completa', 'terminada', 'finalizada' => 'bg-green-100 text-green-700',
                                'en proceso', 'activa' => 'bg-amber-100 text-amber-700',
                                'pendiente' => 'bg-gray-100 text-gray-700',
                                default => 'bg-blue-100 text-blue-700'
                            };
                            ?>
                            <span class="inline-flex px-2 py-1 text-xs rounded-full <?= $estadoColor ?>">
                                <?= htmlspecialchars($ficha['fermentacion_estado']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-gray-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <?= htmlspecialchars($ficha['responsable_nombre'] ?? '—') ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-center gap-2">
                                <a href="/fichas/ver.php?id=<?= $ficha['id'] ?>" 
                                   class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                   title="Ver detalle">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="/fichas/editar.php?id=<?= $ficha['id'] ?>" 
                                   class="p-2 text-amber-600 hover:bg-amber-50 rounded-lg transition-colors"
                                   title="Editar">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if (Auth::isAdmin()): ?>
                                <button onclick="confirmarEliminar(<?= $ficha['id'] ?>, '<?= htmlspecialchars($ficha['lote_codigo']) ?>')" 
                                        class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                        title="Eliminar">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de confirmación de eliminación -->
<div id="modalEliminar" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4 p-6">
        <div class="text-center">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-2">¿Eliminar ficha?</h3>
            <p class="text-gray-600 mb-6">Esta acción eliminará la ficha del lote <strong id="loteEliminar"></strong>. Esta acción no se puede deshacer.</p>
            <div class="flex gap-3 justify-center">
                <button onclick="cerrarModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                    Cancelar
                </button>
                <form id="formEliminar" method="POST" action="/fichas/eliminar.php" class="inline">
                    <input type="hidden" name="id" id="idEliminar">
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                        Eliminar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmarEliminar(id, lote) {
    document.getElementById('idEliminar').value = id;
    document.getElementById('loteEliminar').textContent = lote;
    document.getElementById('modalEliminar').classList.remove('hidden');
}

function cerrarModal() {
    document.getElementById('modalEliminar').classList.add('hidden');
}

document.getElementById('modalEliminar').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
