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
$vistaActual = strtolower(trim((string)($_GET['vista'] ?? 'recepcion')));
$vistasPermitidas = ['recepcion', 'pagos', 'codificacion', 'etiqueta'];
if (!in_array($vistaActual, $vistasPermitidas, true)) {
    $vistaActual = 'recepcion';
}
$mostrarColumnaLote = $vistaActual !== 'recepcion';
$mostrarFiltroLote = $vistaActual !== 'recepcion';
if (!$mostrarFiltroLote) {
    $filtroLote = '';
}

$vistaConfig = [
    'recepcion' => [
        'titulo' => 'a. Recepción (Ficha de Recepción)',
        'descripcion' => 'Registro y verificación visual inicial del lote.'
    ],
    'pagos' => [
        'titulo' => 'b. Registro de Pagos (Ficha de pagos)',
        'descripcion' => 'Registro y consulta de pagos relacionados a cada ficha de recepción.'
    ],
    'codificacion' => [
        'titulo' => 'c. Codificación de Lote',
        'descripcion' => 'Fichas pendientes por codificación para trazabilidad.'
    ],
    'etiqueta' => [
        'titulo' => 'i. Imprimir Etiqueta (Etiquetado de registro)',
        'descripcion' => 'Fichas con codificación disponibles para impresión de etiqueta.'
    ],
];

$accionPrincipalHref = APP_URL . '/fichas/crear.php?etapa=recepcion';
$accionPrincipalLabel = 'Nueva Ficha de Recepción';
$accionPrincipalIcon = 'fa-plus';
$emptyStateTitulo = 'No hay fichas registradas';
$emptyStateDescripcion = 'Comienza creando una nueva ficha de recepción';
$emptyStateBoton = 'Nueva Ficha de Recepción';

if ($vistaActual === 'pagos') {
    $accionPrincipalHref = APP_URL . '/fichas/crear.php?etapa=recepcion';
    $accionPrincipalLabel = 'Nueva Ficha de Recepción';
    $accionPrincipalIcon = 'fa-plus';
    $emptyStateTitulo = 'No hay fichas de recepción registradas';
    $emptyStateDescripcion = 'Primero registre una ficha de recepción para habilitar la gestión de pagos.';
    $emptyStateBoton = 'Nueva Ficha de Recepción';
} elseif ($vistaActual === 'codificacion') {
    $accionPrincipalHref = APP_URL . '/fichas/index.php?vista=recepcion';
    $accionPrincipalLabel = 'Ir a Recepción';
    $accionPrincipalIcon = 'fa-arrow-right';
    $emptyStateTitulo = 'No hay fichas pendientes de codificación';
    $emptyStateDescripcion = 'Complete la recepción y luego registre la codificación del lote.';
    $emptyStateBoton = 'Ir a Recepción';
} elseif ($vistaActual === 'etiqueta') {
    $accionPrincipalHref = APP_URL . '/fichas/index.php?vista=codificacion';
    $accionPrincipalLabel = 'Ir a Codificación';
    $accionPrincipalIcon = 'fa-arrow-right';
    $emptyStateTitulo = 'No hay fichas disponibles para etiqueta';
    $emptyStateDescripcion = 'Debe existir una codificación registrada para imprimir la etiqueta.';
    $emptyStateBoton = 'Ir a Codificación';
}

// Compatibilidad de esquema para columnas de lotes
$colsLotes = array_column($db->fetchAll("SHOW COLUMNS FROM lotes"), 'Field');
$hasLoteCol = static fn(string $name): bool => in_array($name, $colsLotes, true);
$pesoRecibidoExpr = $hasLoteCol('peso_recibido_kg')
    ? 'l.peso_recibido_kg'
    : ($hasLoteCol('peso_inicial_kg') ? 'l.peso_inicial_kg' : 'NULL');

// Compatibilidad de esquema para columnas de fichas (registro de pago)
$colsFichas = array_column($db->fetchAll("SHOW COLUMNS FROM fichas_registro"), 'Field');
$hasFichaCol = static fn(string $name): bool => in_array($name, $colsFichas, true);
$camposPagoDisponibles = $hasFichaCol('fecha_pago')
    && $hasFichaCol('factura_compra')
    && $hasFichaCol('cantidad_comprada')
    && $hasFichaCol('forma_pago');
$pagoRegistradoExpr = $camposPagoDisponibles
    ? "(CASE WHEN f.fecha_pago IS NOT NULL
                AND TRIM(COALESCE(f.factura_compra, '')) <> ''
                AND f.cantidad_comprada IS NOT NULL
                AND f.cantidad_comprada > 0
                AND TRIM(COALESCE(f.forma_pago, '')) <> ''
             THEN 1 ELSE 0 END)"
    : "(CASE WHEN f.precio_total_pagar IS NOT NULL THEN 1 ELSE 0 END)";
$orderByClause = $vistaActual === 'pagos'
    ? 'pago_registrado ASC, f.created_at DESC'
    : 'f.created_at DESC';

// Construir query con filtros
$where = ["1=1"];
$params = [];

if ($vistaActual === 'codificacion') {
    $where[] = "(f.codificacion IS NULL OR TRIM(f.codificacion) = '')";
}

if ($vistaActual === 'etiqueta') {
    $where[] = "(f.codificacion IS NOT NULL AND TRIM(f.codificacion) <> '')";
}

if ($filtroLote) {
    $where[] = "f.lote_id = ?";
    $params[] = $filtroLote;
}

if ($filtroFecha) {
    $where[] = "DATE(f.fecha_entrada) = ?";
    $params[] = $filtroFecha;
}

if ($filtroBusqueda) {
    $where[] = "(f.codificacion LIKE ? OR f.producto LIKE ? OR l.codigo LIKE ? OR f.proveedor_ruta LIKE ?)";
    $busqueda = "%{$filtroBusqueda}%";
    $params[] = $busqueda;
    $params[] = $busqueda;
    $params[] = $busqueda;
    $params[] = $busqueda;
}

$whereClause = implode(" AND ", $where);

// Obtener fichas con información relacionada
$fichas = $db->fetchAll("
    SELECT f.*,
           l.codigo as lote_codigo,
           {$pesoRecibidoExpr} as peso_recibido_kg,
           p.nombre as proveedor_nombre,
           v.nombre as variedad_nombre,
           u.nombre as responsable_nombre,
           {$pagoRegistradoExpr} as pago_registrado
    FROM fichas_registro f
    LEFT JOIN lotes l ON f.lote_id = l.id
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    LEFT JOIN usuarios u ON f.responsable_id = u.id
    WHERE {$whereClause}
    ORDER BY {$orderByClause}
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
            <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($vistaConfig[$vistaActual]['titulo']) ?></h1>
            <p class="text-gray-600"><?= htmlspecialchars($vistaConfig[$vistaActual]['descripcion']) ?></p>
        </div>
        <a href="<?= $accionPrincipalHref ?>" 
           class="inline-flex items-center justify-center px-4 py-2 bg-amber-600 text-white rounded-xl hover:bg-amber-700 transition-colors">
            <i class="fas <?= htmlspecialchars($accionPrincipalIcon) ?> mr-2"></i><?= htmlspecialchars($accionPrincipalLabel) ?>
        </a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3">
        <div class="flex flex-wrap gap-2">
            <a href="<?= APP_URL ?>/fichas/index.php?vista=recepcion"
               class="px-3 py-2 rounded-lg text-sm font-medium transition-colors <?= $vistaActual === 'recepcion' ? 'bg-amber-100 text-amber-700' : 'bg-gray-50 text-gray-600 hover:bg-gray-100' ?>">
                a. Recepción
            </a>
            <a href="<?= APP_URL ?>/fichas/index.php?vista=pagos"
               class="px-3 py-2 rounded-lg text-sm font-medium transition-colors <?= $vistaActual === 'pagos' ? 'bg-amber-100 text-amber-700' : 'bg-gray-50 text-gray-600 hover:bg-gray-100' ?>">
                b. Registro de Pagos
            </a>
            <a href="<?= APP_URL ?>/fichas/index.php?vista=codificacion"
               class="px-3 py-2 rounded-lg text-sm font-medium transition-colors <?= $vistaActual === 'codificacion' ? 'bg-amber-100 text-amber-700' : 'bg-gray-50 text-gray-600 hover:bg-gray-100' ?>">
                c. Codificación
            </a>
            <a href="<?= APP_URL ?>/fichas/index.php?vista=etiqueta"
               class="px-3 py-2 rounded-lg text-sm font-medium transition-colors <?= $vistaActual === 'etiqueta' ? 'bg-amber-100 text-amber-700' : 'bg-gray-50 text-gray-600 hover:bg-gray-100' ?>">
                i. Imprimir Etiqueta
            </a>
        </div>
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
            <input type="hidden" name="vista" value="<?= htmlspecialchars($vistaActual) ?>">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs text-gray-500 mb-1">Buscar</label>
                <input type="text" name="buscar" value="<?= htmlspecialchars($filtroBusqueda) ?>"
                       placeholder="Código, producto..."
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
            </div>
            
            <?php if ($mostrarFiltroLote): ?>
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
            <?php endif; ?>
            
            <div class="w-40">
                <label class="block text-xs text-gray-500 mb-1">Fecha Entrada</label>
                <input type="date" name="fecha" value="<?= htmlspecialchars($filtroFecha) ?>"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
            </div>
            
            <div class="flex items-end gap-2">
                <button type="submit" class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 text-sm">
                    <i class="fas fa-search mr-1"></i>Filtrar
                </button>
                <a href="<?= APP_URL ?>/fichas/index.php?vista=<?= urlencode($vistaActual) ?>" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm">
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
            <h3 class="text-lg font-medium text-gray-900 mb-2"><?= htmlspecialchars($emptyStateTitulo) ?></h3>
            <p class="text-gray-500 mb-4"><?= htmlspecialchars($emptyStateDescripcion) ?></p>
            <a href="<?= $accionPrincipalHref ?>" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">
                <i class="fas <?= htmlspecialchars($accionPrincipalIcon) ?> mr-2"></i><?= htmlspecialchars($emptyStateBoton) ?>
            </a>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <?php if ($mostrarColumnaLote): ?>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Lote</th>
                        <?php endif; ?>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Codificación</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Producto</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Proveedor/Ruta</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha Entrada</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Precio Final</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= $vistaActual === 'pagos' ? 'Estado Pago' : 'Estado Ferm.' ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Responsable</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase sticky right-0 bg-gray-50 z-10">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($fichas as $ficha): ?>
                    <tr class="hover:bg-amber-50/50 transition-colors">
                        <?php
                        $rutaEdicion = APP_URL . '/fichas/editar.php?id=' . (int)$ficha['id'] . '&etapa=recepcion';
                        if ($vistaActual === 'pagos') {
                            $rutaEdicion = APP_URL . '/fichas/pago.php?id=' . (int)$ficha['id'];
                        } elseif ($vistaActual === 'codificacion') {
                            $rutaEdicion = APP_URL . '/fichas/codificacion.php?id=' . (int)$ficha['id'];
                        } elseif ($vistaActual === 'etiqueta') {
                            $rutaEdicion = APP_URL . '/fichas/etiqueta.php?id=' . (int)$ficha['id'];
                        }
                        ?>
                        <td class="px-4 py-3">
                            <span class="font-mono text-sm text-gray-600">#<?= $ficha['id'] ?></span>
                        </td>
                        <?php if ($mostrarColumnaLote): ?>
                        <td class="px-4 py-3">
                            <?php
                            $loteIdFila = (int)($ficha['lote_id'] ?? 0);
                            $loteCodigoFila = trim((string)($ficha['lote_codigo'] ?? ''));
                            ?>
                            <?php if ($loteIdFila > 0 && $loteCodigoFila !== ''): ?>
                            <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $loteIdFila ?>" class="text-amber-600 hover:text-amber-700 font-medium">
                                <?= htmlspecialchars($loteCodigoFila) ?>
                            </a>
                            <?php elseif ($loteIdFila > 0): ?>
                            <span class="text-gray-700 font-medium">Lote #<?= $loteIdFila ?></span>
                            <?php else: ?>
                            <span class="text-gray-400">Sin lote</span>
                            <?php endif; ?>
                            <?php if ($ficha['variedad_nombre']): ?>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($ficha['variedad_nombre']) ?></div>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
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
                            <?php if ($vistaActual === 'pagos'): ?>
                            <?php
                            $pagoRegistrado = ((int)($ficha['pago_registrado'] ?? 0)) === 1;
                            ?>
                            <span class="inline-flex px-2 py-1 text-xs rounded-full <?= $pagoRegistrado ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                <?= $pagoRegistrado ? 'Registrado' : 'Pendiente' ?>
                            </span>
                            <?php if ($pagoRegistrado && !empty($ficha['fecha_pago'])): ?>
                            <div class="text-xs text-gray-500 mt-1"><?= date('d/m/Y', strtotime((string)$ficha['fecha_pago'])) ?></div>
                            <?php endif; ?>
                            <?php elseif ($ficha['fermentacion_estado']): ?>
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
                        <td class="px-4 py-3 sticky right-0 bg-white">
                            <div class="flex items-center justify-center gap-2 flex-wrap">
                                <?php if ($vistaActual === 'pagos'): ?>
                                <a href="<?= $rutaEdicion ?>" 
                                   class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium text-emerald-700 bg-emerald-50 hover:bg-emerald-100 rounded-md transition-colors"
                                   title="Registrar pago">
                                    Registrar Pago
                                </a>
                                <a href="<?= APP_URL ?>/fichas/ver.php?id=<?= (int)$ficha['id'] ?>" 
                                   class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 rounded-md transition-colors"
                                   title="Ver detalle">
                                    Ver
                                </a>
                                <?php else: ?>
                                <a href="<?= APP_URL ?>/fichas/ver.php?id=<?= (int)$ficha['id'] ?>" 
                                   class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 rounded-md transition-colors"
                                   title="Ver detalle">
                                    Ver
                                </a>
                                <a href="<?= $rutaEdicion ?>" 
                                   class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium text-amber-700 bg-amber-50 hover:bg-amber-100 rounded-md transition-colors"
                                   title="<?= $vistaActual === 'pagos' ? 'Registrar pago' : ($vistaActual === 'codificacion' ? 'Codificar lote' : ($vistaActual === 'etiqueta' ? 'Imprimir etiqueta' : 'Editar')) ?>">
                                    <?= $vistaActual === 'codificacion' ? 'Codificar' : ($vistaActual === 'etiqueta' ? 'Imprimir' : 'Editar') ?>
                                </a>
                                <?php if ($vistaActual !== 'etiqueta'): ?>
                                <a href="<?= APP_URL ?>/fichas/etiqueta.php?id=<?= (int)$ficha['id'] ?>" 
                                   class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-md transition-colors"
                                   title="Imprimir etiqueta">
                                    Etiqueta
                                </a>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if (Auth::isAdmin()): ?>
                                <button onclick="confirmarEliminar(<?= $ficha['id'] ?>, '<?= htmlspecialchars((string)($ficha['lote_codigo'] ?: ('Ficha #' . (int)$ficha['id']))) ?>')" 
                                        class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-md transition-colors"
                                        title="Eliminar">
                                    Eliminar
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
                <form id="formEliminar" method="POST" action="<?= APP_URL ?>/fichas/eliminar.php" class="inline">
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
