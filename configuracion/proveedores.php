<?php
/**
 * Gestión de Proveedores - Megablessing
 * CRUD completo para proveedores/rutas de cacao
 */

require_once __DIR__ . '/../bootstrap.php';

requireAuth();
if (!Auth::isAdmin() && !Auth::hasRole('Supervisor') && !Auth::hasPermission('configuracion')) {
    setFlash('danger', 'No tiene permisos para acceder a esta sección.');
    redirect('/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$csrfToken = generateCsrfToken();

$colsLotes = array_column(
    $db->query("SHOW COLUMNS FROM lotes")->fetchAll(PDO::FETCH_ASSOC),
    'Field'
);
$exprPesoRecepcion = in_array('peso_recepcion_kg', $colsLotes, true)
    ? 'peso_recepcion_kg'
    : (in_array('peso_inicial_kg', $colsLotes, true) ? 'peso_inicial_kg' : '0');

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    header('Content-Type: application/json');

    $requestToken = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!$requestToken || !verifyCsrfToken($requestToken)) {
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido. Recargue la página e intente de nuevo.']);
        exit;
    }
    
    try {
        switch ($action) {
            case 'create':
                $codigo = trim($_POST['codigo'] ?? '');
                $nombre = trim($_POST['nombre'] ?? '');
                $tipo = $_POST['tipo'] ?? 'MERCADO';
                $direccion = trim($_POST['direccion'] ?? '');
                $telefono = trim($_POST['telefono'] ?? '');
                $contacto = trim($_POST['contacto'] ?? '');
                
                if (empty($codigo) || empty($nombre)) {
                    throw new Exception('Código y nombre son obligatorios');
                }
                
                // Verificar código único
                $stmt = $db->prepare("SELECT id FROM proveedores WHERE codigo = ?");
                $stmt->execute([$codigo]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya existe un proveedor con este código');
                }
                
                $stmt = $db->prepare("
                    INSERT INTO proveedores (codigo, nombre, tipo, direccion, telefono, contacto, activo)
                    VALUES (?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$codigo, $nombre, $tipo, $direccion, $telefono, $contacto]);
                
                echo json_encode(['success' => true, 'message' => 'Proveedor creado exitosamente', 'id' => $db->lastInsertId()]);
                break;
                
            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                $codigo = trim($_POST['codigo'] ?? '');
                $nombre = trim($_POST['nombre'] ?? '');
                $tipo = $_POST['tipo'] ?? 'MERCADO';
                $direccion = trim($_POST['direccion'] ?? '');
                $telefono = trim($_POST['telefono'] ?? '');
                $contacto = trim($_POST['contacto'] ?? '');
                
                if (!$id || empty($codigo) || empty($nombre)) {
                    throw new Exception('Datos incompletos');
                }
                
                // Verificar código único (excepto el actual)
                $stmt = $db->prepare("SELECT id FROM proveedores WHERE codigo = ? AND id != ?");
                $stmt->execute([$codigo, $id]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya existe otro proveedor con este código');
                }
                
                $stmt = $db->prepare("
                    UPDATE proveedores 
                    SET codigo = ?, nombre = ?, tipo = ?, direccion = ?, telefono = ?, contacto = ?
                    WHERE id = ?
                ");
                $stmt->execute([$codigo, $nombre, $tipo, $direccion, $telefono, $contacto, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Proveedor actualizado exitosamente']);
                break;
                
            case 'toggle':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID inválido');
                
                $stmt = $db->prepare("UPDATE proveedores SET activo = NOT activo WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
                break;
                
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID inválido');
                
                // Verificar si tiene lotes asociados
                $stmt = $db->prepare("SELECT COUNT(*) FROM lotes WHERE proveedor_id = ?");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('No se puede eliminar: tiene lotes asociados. Desactive el proveedor en su lugar.');
                }
                
                $stmt = $db->prepare("DELETE FROM proveedores WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Proveedor eliminado']);
                break;
                
            case 'get':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID inválido');
                
                $stmt = $db->prepare("SELECT * FROM proveedores WHERE id = ?");
                $stmt->execute([$id]);
                $proveedor = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$proveedor) throw new Exception('Proveedor no encontrado');
                
                echo json_encode(['success' => true, 'data' => $proveedor]);
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Obtener proveedores para listado
$search = trim($_GET['search'] ?? '');
$tipo_filter = $_GET['tipo'] ?? '';
$estado_filter = $_GET['estado'] ?? '';

$where = [];
$params = [];

if ($search) {
    $where[] = "(codigo LIKE ? OR nombre LIKE ? OR contacto LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($tipo_filter) {
    $where[] = "tipo = ?";
    $params[] = $tipo_filter;
}
if ($estado_filter !== '') {
    $where[] = "activo = ?";
    $params[] = (int)$estado_filter;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT p.*, 
           (SELECT COUNT(*) FROM lotes WHERE proveedor_id = p.id) as total_lotes,
           (SELECT COALESCE(SUM({$exprPesoRecepcion}), 0) FROM lotes WHERE proveedor_id = p.id) as peso_total
    FROM proveedores p
    $whereClause
    ORDER BY p.activo DESC, p.nombre ASC
");
$stmt->execute($params);
$proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(activo = 1) as activos,
        SUM(tipo = 'MERCADO') as mercado,
        SUM(tipo = 'BODEGA') as bodega,
        SUM(tipo = 'RUTA') as ruta,
        SUM(tipo = 'PRODUCTOR') as productor
    FROM proveedores
")->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Gestión de Proveedores';
$pageSubtitle = 'Administre los proveedores y rutas de abastecimiento de cacao';
ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex items-center gap-4 mb-4">
            <a href="<?= APP_URL ?>/configuracion/index.php" class="text-gray-500 hover:text-primary-green transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </a>
            <div>
                <h1 class="text-3xl font-bold text-primary-green">Gestión de Proveedores</h1>
                <p class="text-warm-gray mt-1">Administre los proveedores y rutas de abastecimiento de cacao</p>
            </div>
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 p-4 text-center">
            <div class="text-3xl font-bold text-primary-green"><?= number_format($stats['total']) ?></div>
            <div class="text-sm text-warm-gray">Total</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 p-4 text-center">
            <div class="text-3xl font-bold text-emerald-600"><?= number_format($stats['activos']) ?></div>
            <div class="text-sm text-warm-gray">Activos</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 p-4 text-center">
            <div class="text-3xl font-bold text-blue-600"><?= number_format($stats['mercado'] ?? 0) ?></div>
            <div class="text-sm text-warm-gray">Mercado</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 p-4 text-center">
            <div class="text-3xl font-bold text-amber-600"><?= number_format($stats['bodega'] ?? 0) ?></div>
            <div class="text-sm text-warm-gray">Bodega</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 p-4 text-center">
            <div class="text-3xl font-bold text-purple-600"><?= number_format($stats['ruta'] ?? 0) ?></div>
            <div class="text-sm text-warm-gray">Ruta</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 p-4 text-center">
            <div class="text-3xl font-bold text-teal-600"><?= number_format($stats['productor'] ?? 0) ?></div>
            <div class="text-sm text-warm-gray">Productor</div>
        </div>
    </div>

    <!-- Filtros y Acciones -->
    <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 p-6 mb-6">
        <div class="flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between">
            <form method="GET" class="flex flex-wrap gap-4 items-center flex-1">
                <div class="flex-1 min-w-[200px]">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Buscar por código, nombre o contacto..."
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-green focus:border-primary-green">
                </div>
                <select name="tipo" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-green">
                    <option value="">Todos los tipos</option>
                    <option value="MERCADO" <?= $tipo_filter === 'MERCADO' ? 'selected' : '' ?>>Mercado</option>
                    <option value="BODEGA" <?= $tipo_filter === 'BODEGA' ? 'selected' : '' ?>>Bodega</option>
                    <option value="RUTA" <?= $tipo_filter === 'RUTA' ? 'selected' : '' ?>>Ruta</option>
                    <option value="PRODUCTOR" <?= $tipo_filter === 'PRODUCTOR' ? 'selected' : '' ?>>Productor</option>
                </select>
                <select name="estado" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-green">
                    <option value="">Todos los estados</option>
                    <option value="1" <?= $estado_filter === '1' ? 'selected' : '' ?>>Activos</option>
                    <option value="0" <?= $estado_filter === '0' ? 'selected' : '' ?>>Inactivos</option>
                </select>
                <button type="submit" class="px-4 py-2 bg-primary-green text-white rounded-lg hover:bg-primary-green/90 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </button>
                <?php if ($search || $tipo_filter || $estado_filter !== ''): ?>
                <a href="<?= APP_URL ?>/configuracion/proveedores.php" class="px-4 py-2 text-gray-600 hover:text-gray-800 transition-colors">
                    Limpiar
                </a>
                <?php endif; ?>
            </form>
            
            <button onclick="openModal('create')" 
                    class="px-6 py-2 bg-primary-green text-white rounded-lg hover:bg-primary-green/90 transition-colors flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                </svg>
                Nuevo Proveedor
            </button>
        </div>
    </div>

    <!-- Tabla de Proveedores -->
    <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gradient-to-r from-primary-green to-primary-green/80 text-white">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Código</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Nombre</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Tipo</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Contacto</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Teléfono</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold">Lotes</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold">Peso Total (kg)</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold">Estado</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($proveedores)): ?>
                    <tr>
                        <td colspan="9" class="px-6 py-12 text-center text-gray-500">
                            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            <p class="text-lg font-medium">No se encontraron proveedores</p>
                            <p class="text-sm">Agregue un nuevo proveedor o modifique los filtros de búsqueda</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($proveedores as $prov): ?>
                    <tr class="hover:bg-ivory-white/50 transition-colors <?= !$prov['activo'] ? 'opacity-60' : '' ?>">
                        <td class="px-6 py-4">
                            <span class="font-mono font-semibold text-primary-green"><?= htmlspecialchars($prov['codigo']) ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-medium text-gray-900"><?= htmlspecialchars($prov['nombre']) ?></div>
                            <?php if ($prov['direccion']): ?>
                            <div class="text-sm text-gray-500 truncate max-w-xs" title="<?= htmlspecialchars($prov['direccion']) ?>">
                                <?= htmlspecialchars($prov['direccion']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <?php
                            $tipoColors = [
                                'MERCADO' => 'bg-blue-100 text-blue-800',
                                'BODEGA' => 'bg-amber-100 text-amber-800',
                                'RUTA' => 'bg-purple-100 text-purple-800',
                                'PRODUCTOR' => 'bg-teal-100 text-teal-800'
                            ];
                            $color = $tipoColors[$prov['tipo']] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="px-3 py-1 rounded-full text-xs font-medium <?= $color ?>">
                                <?= htmlspecialchars($prov['tipo']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-gray-600">
                            <?= htmlspecialchars($prov['contacto'] ?: '-') ?>
                        </td>
                        <td class="px-6 py-4 text-gray-600">
                            <?= htmlspecialchars($prov['telefono'] ?: '-') ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="font-semibold text-primary-green"><?= number_format($prov['total_lotes']) ?></span>
                        </td>
                        <td class="px-6 py-4 text-right font-medium">
                            <?= number_format($prov['peso_total'], 2) ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <button onclick="toggleEstado(<?= $prov['id'] ?>)" 
                                    class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium transition-colors
                                           <?= $prov['activo'] ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' : 'bg-red-100 text-red-800 hover:bg-red-200' ?>">
                                <span class="w-2 h-2 rounded-full <?= $prov['activo'] ? 'bg-emerald-500' : 'bg-red-500' ?>"></span>
                                <?= $prov['activo'] ? 'Activo' : 'Inactivo' ?>
                            </button>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center justify-center gap-2">
                                <button onclick="openModal('edit', <?= $prov['id'] ?>)" 
                                        class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Editar">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <?php if ($prov['total_lotes'] == 0): ?>
                                <button onclick="deleteProveedor(<?= $prov['id'] ?>, '<?= htmlspecialchars($prov['nombre']) ?>')" 
                                        class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Eliminar">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Info -->
    <div class="mt-6 text-sm text-gray-500 text-center">
        Mostrando <?= count($proveedores) ?> proveedor(es)
    </div>
</div>

<!-- Modal Crear/Editar -->
<div id="proveedorModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg transform transition-all">
        <div class="bg-gradient-to-r from-primary-green to-primary-green/80 px-6 py-4 rounded-t-2xl">
            <h3 id="modalTitle" class="text-xl font-bold text-white">Nuevo Proveedor</h3>
        </div>
        
        <form id="proveedorForm" class="p-6">
            <input type="hidden" id="proveedorId" name="id">
            <input type="hidden" id="formAction" name="action" value="create">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Código *</label>
                        <input type="text" id="codigo" name="codigo" required maxlength="10"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-green focus:border-primary-green uppercase"
                               placeholder="Ej: PROV001">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
                        <select id="tipo" name="tipo" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-green focus:border-primary-green">
                            <option value="MERCADO">Mercado</option>
                            <option value="BODEGA">Bodega</option>
                            <option value="RUTA">Ruta</option>
                            <option value="PRODUCTOR">Productor</option>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label>
                    <input type="text" id="nombre" name="nombre" required maxlength="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-green focus:border-primary-green"
                           placeholder="Nombre del proveedor">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Persona de Contacto</label>
                    <input type="text" id="contacto" name="contacto" maxlength="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-green focus:border-primary-green"
                           placeholder="Nombre del contacto">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                    <input type="text" id="telefono" name="telefono" maxlength="50"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-green focus:border-primary-green"
                           placeholder="Ej: 0999123456">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Dirección</label>
                    <textarea id="direccion" name="direccion" rows="2"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-green focus:border-primary-green"
                              placeholder="Dirección completa"></textarea>
                </div>
            </div>
            
            <div class="flex justify-end gap-3 mt-6 pt-6 border-t">
                <button type="button" onclick="closeModal()" 
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                    Cancelar
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-primary-green text-white rounded-lg hover:bg-primary-green/90 transition-colors">
                    <span id="submitBtnText">Guardar</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const proveedoresUrl = '<?= APP_URL ?>/configuracion/proveedores.php';
const csrfToken = '<?= e($csrfToken) ?>';

// Funciones del Modal
function openModal(mode, id = null) {
    const modal = document.getElementById('proveedorModal');
    const form = document.getElementById('proveedorForm');
    const title = document.getElementById('modalTitle');
    const action = document.getElementById('formAction');
    const submitBtn = document.getElementById('submitBtnText');
    
    form.reset();
    
    if (mode === 'create') {
        title.textContent = 'Nuevo Proveedor';
        action.value = 'create';
        submitBtn.textContent = 'Crear Proveedor';
        document.getElementById('proveedorId').value = '';
    } else if (mode === 'edit' && id) {
        title.textContent = 'Editar Proveedor';
        action.value = 'update';
        submitBtn.textContent = 'Guardar Cambios';
        
        // Cargar datos
        fetch(proveedoresUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const p = data.data;
                document.getElementById('proveedorId').value = p.id;
                document.getElementById('codigo').value = p.codigo;
                document.getElementById('nombre').value = p.nombre;
                document.getElementById('tipo').value = p.tipo;
                document.getElementById('contacto').value = p.contacto || '';
                document.getElementById('telefono').value = p.telefono || '';
                document.getElementById('direccion').value = p.direccion || '';
            } else {
                showNotification(data.message, 'error');
                return;
            }
        });
    }
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeModal() {
    const modal = document.getElementById('proveedorModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Envío del formulario
document.getElementById('proveedorForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch(proveedoresUrl, {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            closeModal();
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(err => {
        showNotification('Error de conexión', 'error');
    });
});

// Toggle estado
function toggleEstado(id) {
    if (!confirm('¿Cambiar el estado de este proveedor?')) return;
    
    fetch(proveedoresUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=toggle&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showNotification(data.message, 'error');
        }
    });
}

// Eliminar proveedor
function deleteProveedor(id, nombre) {
    if (!confirm(`¿Está seguro de eliminar el proveedor "${nombre}"?\n\nEsta acción no se puede deshacer.`)) return;
    
    fetch(proveedoresUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showNotification(data.message, 'error');
        }
    });
}

// Notificaciones
function showNotification(message, type = 'info') {
    const colors = {
        success: 'bg-emerald-500',
        error: 'bg-red-500',
        info: 'bg-blue-500'
    };
    
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 ${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-300`;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.add('opacity-0', 'translate-x-full');
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Cerrar modal con ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

// Cerrar modal al hacer click fuera
document.getElementById('proveedorModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
