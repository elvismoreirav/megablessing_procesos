<?php
/**
 * Gestión de Variedades - Megablessing
 * CRUD completo para variedades de cacao
 */

require_once __DIR__ . '/../bootstrap.php';

// Verificar autenticación y rol
if (!Auth::check()) {
    header('Location: /login.php');
    exit;
}

$user = Auth::user();
if (!in_array($user['rol'], ['admin', 'administrador', 'supervisor'])) {
    $_SESSION['error'] = 'No tiene permisos para acceder a esta sección';
    header('Location: /dashboard.php');
    exit;
}

$db = Database::getInstance()->getConnection();

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    header('Content-Type: application/json');
    
    try {
        switch ($action) {
            case 'create':
                $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
                $nombre = trim($_POST['nombre'] ?? '');
                $descripcion = trim($_POST['descripcion'] ?? '');
                
                if (empty($codigo) || empty($nombre)) {
                    throw new Exception('Código y nombre son obligatorios');
                }
                
                // Verificar código único
                $stmt = $db->prepare("SELECT id FROM variedades WHERE codigo = ?");
                $stmt->execute([$codigo]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya existe una variedad con este código');
                }
                
                $stmt = $db->prepare("
                    INSERT INTO variedades (codigo, nombre, descripcion, activo)
                    VALUES (?, ?, ?, 1)
                ");
                $stmt->execute([$codigo, $nombre, $descripcion]);
                
                echo json_encode(['success' => true, 'message' => 'Variedad creada exitosamente', 'id' => $db->lastInsertId()]);
                break;
                
            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
                $nombre = trim($_POST['nombre'] ?? '');
                $descripcion = trim($_POST['descripcion'] ?? '');
                
                if (!$id || empty($codigo) || empty($nombre)) {
                    throw new Exception('Datos incompletos');
                }
                
                // Verificar código único (excepto el actual)
                $stmt = $db->prepare("SELECT id FROM variedades WHERE codigo = ? AND id != ?");
                $stmt->execute([$codigo, $id]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya existe otra variedad con este código');
                }
                
                $stmt = $db->prepare("
                    UPDATE variedades 
                    SET codigo = ?, nombre = ?, descripcion = ?
                    WHERE id = ?
                ");
                $stmt->execute([$codigo, $nombre, $descripcion, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Variedad actualizada exitosamente']);
                break;
                
            case 'toggle':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID inválido');
                
                $stmt = $db->prepare("UPDATE variedades SET activo = NOT activo WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
                break;
                
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID inválido');
                
                // Verificar si tiene lotes asociados
                $stmt = $db->prepare("SELECT COUNT(*) FROM lotes WHERE variedad_id = ?");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('No se puede eliminar: tiene lotes asociados. Desactive la variedad en su lugar.');
                }
                
                $stmt = $db->prepare("DELETE FROM variedades WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Variedad eliminada']);
                break;
                
            case 'get':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID inválido');
                
                $stmt = $db->prepare("SELECT * FROM variedades WHERE id = ?");
                $stmt->execute([$id]);
                $variedad = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$variedad) throw new Exception('Variedad no encontrada');
                
                echo json_encode(['success' => true, 'data' => $variedad]);
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Obtener variedades para listado
$search = trim($_GET['search'] ?? '');
$estado_filter = $_GET['estado'] ?? '';

$where = [];
$params = [];

if ($search) {
    $where[] = "(codigo LIKE ? OR nombre LIKE ? OR descripcion LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($estado_filter !== '') {
    $where[] = "activo = ?";
    $params[] = (int)$estado_filter;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT v.*, 
           (SELECT COUNT(*) FROM lotes WHERE variedad_id = v.id) as total_lotes,
           (SELECT COALESCE(SUM(peso_recepcion), 0) FROM lotes WHERE variedad_id = v.id) as peso_total
    FROM variedades v
    $whereClause
    ORDER BY v.activo DESC, v.nombre ASC
");
$stmt->execute($params);
$variedades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(activo = 1) as activas
    FROM variedades
")->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Gestión de Variedades';
include __DIR__ . '/../templates/layouts/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex items-center gap-4 mb-4">
            <a href="/configuracion/" class="text-gray-500 hover:text-primary-green transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </a>
            <div>
                <h1 class="text-3xl font-bold text-primary-green">Gestión de Variedades</h1>
                <p class="text-warm-gray mt-1">Administre las variedades de cacao procesadas</p>
            </div>
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 p-4 text-center">
            <div class="text-3xl font-bold text-primary-green"><?= number_format($stats['total']) ?></div>
            <div class="text-sm text-warm-gray">Total Variedades</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 p-4 text-center">
            <div class="text-3xl font-bold text-emerald-600"><?= number_format($stats['activas']) ?></div>
            <div class="text-sm text-warm-gray">Activas</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 p-4 text-center">
            <div class="text-3xl font-bold text-amber-600"><?= number_format($stats['total'] - $stats['activas']) ?></div>
            <div class="text-sm text-warm-gray">Inactivas</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 p-4 text-center">
            <div class="text-3xl font-bold text-blue-600">
                <?= $db->query("SELECT COUNT(DISTINCT variedad_id) FROM lotes WHERE variedad_id IS NOT NULL")->fetchColumn() ?>
            </div>
            <div class="text-sm text-warm-gray">Con Lotes</div>
        </div>
    </div>

    <!-- Filtros y Acciones -->
    <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 p-6 mb-6">
        <div class="flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between">
            <form method="GET" class="flex flex-wrap gap-4 items-center flex-1">
                <div class="flex-1 min-w-[200px]">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Buscar por código, nombre o descripción..."
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-green focus:border-primary-green">
                </div>
                <select name="estado" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-green">
                    <option value="">Todos los estados</option>
                    <option value="1" <?= $estado_filter === '1' ? 'selected' : '' ?>>Activas</option>
                    <option value="0" <?= $estado_filter === '0' ? 'selected' : '' ?>>Inactivas</option>
                </select>
                <button type="submit" class="px-4 py-2 bg-primary-green text-white rounded-lg hover:bg-primary-green/90 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </button>
                <?php if ($search || $estado_filter !== ''): ?>
                <a href="/configuracion/variedades.php" class="px-4 py-2 text-gray-600 hover:text-gray-800 transition-colors">
                    Limpiar
                </a>
                <?php endif; ?>
            </form>
            
            <button onclick="openModal('create')" 
                    class="px-6 py-2 bg-primary-green text-white rounded-lg hover:bg-primary-green/90 transition-colors flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                </svg>
                Nueva Variedad
            </button>
        </div>
    </div>

    <!-- Grid de Variedades -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php if (empty($variedades)): ?>
        <div class="col-span-full bg-white rounded-xl shadow-sm border border-olive-green/20 p-12 text-center">
            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
            </svg>
            <p class="text-lg font-medium text-gray-700">No se encontraron variedades</p>
            <p class="text-sm text-gray-500">Agregue una nueva variedad o modifique los filtros de búsqueda</p>
        </div>
        <?php else: ?>
        <?php foreach ($variedades as $var): ?>
        <div class="bg-white rounded-xl shadow-sm border border-olive-green/20 overflow-hidden hover:shadow-md transition-shadow <?= !$var['activo'] ? 'opacity-60' : '' ?>">
            <div class="bg-gradient-to-r from-amber-600 to-amber-500 px-6 py-4">
                <div class="flex items-center justify-between">
                    <span class="text-2xl font-bold text-white"><?= htmlspecialchars($var['codigo']) ?></span>
                    <span class="px-2 py-1 rounded text-xs font-medium <?= $var['activo'] ? 'bg-white/20 text-white' : 'bg-red-100 text-red-800' ?>">
                        <?= $var['activo'] ? 'Activa' : 'Inactiva' ?>
                    </span>
                </div>
            </div>
            
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-2"><?= htmlspecialchars($var['nombre']) ?></h3>
                
                <?php if ($var['descripcion']): ?>
                <p class="text-sm text-gray-600 mb-4 line-clamp-2"><?= htmlspecialchars($var['descripcion']) ?></p>
                <?php endif; ?>
                
                <div class="grid grid-cols-2 gap-4 py-4 border-t border-gray-100">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-primary-green"><?= number_format($var['total_lotes']) ?></div>
                        <div class="text-xs text-gray-500">Lotes</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-amber-600"><?= number_format($var['peso_total'], 0) ?></div>
                        <div class="text-xs text-gray-500">kg Totales</div>
                    </div>
                </div>
                
                <div class="flex items-center gap-2 mt-4 pt-4 border-t border-gray-100">
                    <button onclick="toggleEstado(<?= $var['id'] ?>)" 
                            class="flex-1 px-3 py-2 text-sm font-medium rounded-lg transition-colors
                                   <?= $var['activo'] ? 'bg-gray-100 text-gray-700 hover:bg-gray-200' : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200' ?>">
                        <?= $var['activo'] ? 'Desactivar' : 'Activar' ?>
                    </button>
                    <button onclick="openModal('edit', <?= $var['id'] ?>)" 
                            class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Editar">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                    </button>
                    <?php if ($var['total_lotes'] == 0): ?>
                    <button onclick="deleteVariedad(<?= $var['id'] ?>, '<?= htmlspecialchars($var['nombre']) ?>')" 
                            class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Eliminar">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Info -->
    <div class="mt-6 text-sm text-gray-500 text-center">
        Mostrando <?= count($variedades) ?> variedad(es)
    </div>
</div>

<!-- Modal Crear/Editar -->
<div id="variedadModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md transform transition-all">
        <div class="bg-gradient-to-r from-amber-600 to-amber-500 px-6 py-4 rounded-t-2xl">
            <h3 id="modalTitle" class="text-xl font-bold text-white">Nueva Variedad</h3>
        </div>
        
        <form id="variedadForm" class="p-6">
            <input type="hidden" id="variedadId" name="id">
            <input type="hidden" id="formAction" name="action" value="create">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Código *</label>
                    <input type="text" id="codigo" name="codigo" required maxlength="10"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500 uppercase"
                           placeholder="Ej: CCN51">
                    <p class="text-xs text-gray-500 mt-1">Identificador único de la variedad</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label>
                    <input type="text" id="nombre" name="nombre" required maxlength="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500"
                           placeholder="Nombre completo de la variedad">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                    <textarea id="descripcion" name="descripcion" rows="3"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500"
                              placeholder="Características de la variedad, origen, notas..."></textarea>
                </div>
            </div>
            
            <div class="flex justify-end gap-3 mt-6 pt-6 border-t">
                <button type="button" onclick="closeModal()" 
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                    Cancelar
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors">
                    <span id="submitBtnText">Guardar</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Funciones del Modal
function openModal(mode, id = null) {
    const modal = document.getElementById('variedadModal');
    const form = document.getElementById('variedadForm');
    const title = document.getElementById('modalTitle');
    const action = document.getElementById('formAction');
    const submitBtn = document.getElementById('submitBtnText');
    
    form.reset();
    
    if (mode === 'create') {
        title.textContent = 'Nueva Variedad';
        action.value = 'create';
        submitBtn.textContent = 'Crear Variedad';
        document.getElementById('variedadId').value = '';
    } else if (mode === 'edit' && id) {
        title.textContent = 'Editar Variedad';
        action.value = 'update';
        submitBtn.textContent = 'Guardar Cambios';
        
        // Cargar datos
        fetch('/configuracion/variedades.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get&id=${id}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const v = data.data;
                document.getElementById('variedadId').value = v.id;
                document.getElementById('codigo').value = v.codigo;
                document.getElementById('nombre').value = v.nombre;
                document.getElementById('descripcion').value = v.descripcion || '';
            } else {
                showNotification(data.message, 'error');
            }
        });
    }
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeModal() {
    const modal = document.getElementById('variedadModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Envío del formulario
document.getElementById('variedadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('/configuracion/variedades.php', {
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
    if (!confirm('¿Cambiar el estado de esta variedad?')) return;
    
    fetch('/configuracion/variedades.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=toggle&id=${id}`
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

// Eliminar variedad
function deleteVariedad(id, nombre) {
    if (!confirm(`¿Está seguro de eliminar la variedad "${nombre}"?\n\nEsta acción no se puede deshacer.`)) return;
    
    fetch('/configuracion/variedades.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete&id=${id}`
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
document.getElementById('variedadModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php include __DIR__ . '/../templates/layouts/footer.php'; ?>
