<?php
/**
 * Gestión de Cajones de Fermentación - Megablessing
 * CRUD completo para cajones/cajas de fermentación
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$rolActual = strtolower((string)(Auth::user()['rol'] ?? ''));
if (!Auth::isAdmin() && !Auth::hasPermission('configuracion') && $rolActual !== 'supervisor') {
    setFlash('danger', 'No tiene permisos para acceder a esta sección.');
    redirect('/dashboard.php');
}

$db = Database::getInstance()->getConnection();

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    header('Content-Type: application/json');
    
    try {
        switch ($action) {
            case 'create':
                $numero = strtoupper(trim($_POST['numero'] ?? ''));
                $capacidad_kg = floatval($_POST['capacidad_kg'] ?? 0);
                $material = trim($_POST['material'] ?? '');
                $ubicacion = trim($_POST['ubicacion'] ?? '');
                
                if (empty($numero)) {
                    throw new Exception('El número/código del cajón es obligatorio');
                }
                
                // Verificar número único
                $stmt = $db->prepare("SELECT id FROM cajones_fermentacion WHERE numero = ?");
                $stmt->execute([$numero]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya existe un cajón con este número');
                }
                
                $stmt = $db->prepare("
                    INSERT INTO cajones_fermentacion (numero, capacidad_kg, material, ubicacion, activo)
                    VALUES (?, ?, ?, ?, 1)
                ");
                $stmt->execute([$numero, $capacidad_kg ?: null, $material ?: null, $ubicacion ?: null]);
                
                echo json_encode(['success' => true, 'message' => 'Cajón creado exitosamente', 'id' => $db->lastInsertId()]);
                break;
                
            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                $numero = strtoupper(trim($_POST['numero'] ?? ''));
                $capacidad_kg = floatval($_POST['capacidad_kg'] ?? 0);
                $material = trim($_POST['material'] ?? '');
                $ubicacion = trim($_POST['ubicacion'] ?? '');
                
                if (!$id || empty($numero)) {
                    throw new Exception('Datos incompletos');
                }
                
                // Verificar número único (excepto el actual)
                $stmt = $db->prepare("SELECT id FROM cajones_fermentacion WHERE numero = ? AND id != ?");
                $stmt->execute([$numero, $id]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya existe otro cajón con este número');
                }
                
                $stmt = $db->prepare("
                    UPDATE cajones_fermentacion 
                    SET numero = ?, capacidad_kg = ?, material = ?, ubicacion = ?
                    WHERE id = ?
                ");
                $stmt->execute([$numero, $capacidad_kg ?: null, $material ?: null, $ubicacion ?: null, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Cajón actualizado exitosamente']);
                break;
                
            case 'toggle':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID inválido');
                
                $stmt = $db->prepare("UPDATE cajones_fermentacion SET activo = NOT activo WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
                break;
                
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID inválido');
                
                // Verificar si tiene fermentaciones asociadas
                $stmt = $db->prepare("SELECT COUNT(*) FROM registros_fermentacion WHERE cajon_id = ?");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('No se puede eliminar: tiene registros de fermentación asociados. Desactive el cajón en su lugar.');
                }
                
                $stmt = $db->prepare("DELETE FROM cajones_fermentacion WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Cajón eliminado']);
                break;
                
            case 'get':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID inválido');
                
                $stmt = $db->prepare("SELECT * FROM cajones_fermentacion WHERE id = ?");
                $stmt->execute([$id]);
                $cajon = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$cajon) throw new Exception('Cajón no encontrado');
                
                echo json_encode(['success' => true, 'data' => $cajon]);
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Obtener cajones para listado
$search = trim($_GET['search'] ?? '');
$estado_filter = $_GET['estado'] ?? '';

$where = [];
$params = [];

if ($search) {
    $where[] = "(numero LIKE ? OR material LIKE ? OR ubicacion LIKE ?)";
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
    SELECT c.*, 
           (SELECT COUNT(*) FROM registros_fermentacion WHERE cajon_id = c.id) as total_fermentaciones,
           (SELECT COUNT(*) FROM registros_fermentacion WHERE cajon_id = c.id AND estado = 'EN_PROCESO') as fermentaciones_activas
    FROM cajones_fermentacion c
    $whereClause
    ORDER BY c.activo DESC, c.numero ASC
");
$stmt->execute($params);
$cajones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(activo = 1) as activos,
        COALESCE(SUM(capacidad_kg), 0) as capacidad_total
    FROM cajones_fermentacion
")->fetch(PDO::FETCH_ASSOC);

$enUso = $db->query("
    SELECT COUNT(DISTINCT cajon_id) 
    FROM registros_fermentacion 
    WHERE estado = 'EN_PROCESO'
")->fetchColumn();

$pageTitle = 'Gestión de Cajones de Fermentación';
ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
            <h1 class="text-3xl font-bold text-primary">Cajones de Fermentación</h1>
            <p class="text-warmgray mt-1">Administre los cajones/cajas utilizados en el proceso de fermentación</p>
        </div>
        <a href="/configuracion/"
           class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Volver a Configuración
        </a>
    </div>

    <!-- Estadísticas -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-primary"><?= number_format($stats['total']) ?></div>
            <div class="text-sm text-warmgray">Total Cajones</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-emerald-600"><?= number_format($stats['activos']) ?></div>
            <div class="text-sm text-warmgray">Disponibles</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-blue-600"><?= number_format($enUso) ?></div>
            <div class="text-sm text-warmgray">En Uso</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-amber-600"><?= number_format($stats['activos'] - $enUso) ?></div>
            <div class="text-sm text-warmgray">Libres</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-purple-600"><?= number_format($stats['capacidad_total'], 0) ?></div>
            <div class="text-sm text-warmgray">Capacidad Total (kg)</div>
        </div>
    </div>

    <!-- Filtros y Acciones -->
    <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-6 mb-6">
        <div class="flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between">
            <form method="GET" class="flex flex-wrap gap-4 items-center flex-1">
                <div class="flex-1 min-w-[200px]">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Buscar por número, material o ubicación..."
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                </div>
                <select name="estado" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary">
                    <option value="">Todos los estados</option>
                    <option value="1" <?= $estado_filter === '1' ? 'selected' : '' ?>>Activos</option>
                    <option value="0" <?= $estado_filter === '0' ? 'selected' : '' ?>>Inactivos</option>
                </select>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </button>
                <?php if ($search || $estado_filter !== ''): ?>
                <a href="/configuracion/cajones.php" class="px-4 py-2 text-gray-600 hover:text-gray-800 transition-colors">
                    Limpiar
                </a>
                <?php endif; ?>
            </form>
            
            <button onclick="openModal('create')" 
                    class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                </svg>
                Nuevo Cajón
            </button>
        </div>
    </div>

    <!-- Grid de Cajones -->
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <?php if (empty($cajones)): ?>
        <div class="col-span-full bg-white rounded-xl shadow-sm border border-olive/20 p-12 text-center">
            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            <p class="text-lg font-medium text-gray-700">No se encontraron cajones</p>
            <p class="text-sm text-gray-500">Agregue un nuevo cajón o modifique los filtros de búsqueda</p>
        </div>
        <?php else: ?>
        <?php foreach ($cajones as $cajon): ?>
        <?php 
        $enUsoActual = $cajon['fermentaciones_activas'] > 0;
        $statusColor = !$cajon['activo'] ? 'bg-gray-400' : ($enUsoActual ? 'bg-blue-500' : 'bg-emerald-500');
        $statusText = !$cajon['activo'] ? 'Inactivo' : ($enUsoActual ? 'En Uso' : 'Libre');
        ?>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 overflow-hidden hover:shadow-md transition-all <?= !$cajon['activo'] ? 'opacity-60' : '' ?>">
            <div class="bg-gradient-to-r from-primary to-primary/80 px-4 py-3 flex items-center justify-between">
                <span class="text-xl font-bold text-white"><?= htmlspecialchars($cajon['numero']) ?></span>
                <span class="flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-white/20 text-white">
                    <span class="w-2 h-2 rounded-full <?= $statusColor ?>"></span>
                    <?= $statusText ?>
                </span>
            </div>
            
            <div class="p-4">
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Capacidad:</span>
                        <span class="font-medium"><?= $cajon['capacidad_kg'] ? number_format($cajon['capacidad_kg'], 0) . ' kg' : '-' ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Material:</span>
                        <span class="font-medium"><?= htmlspecialchars($cajon['material'] ?: '-') ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Ubicación:</span>
                        <span class="font-medium truncate max-w-[120px]" title="<?= htmlspecialchars($cajon['ubicacion'] ?: '') ?>">
                            <?= htmlspecialchars($cajon['ubicacion'] ?: '-') ?>
                        </span>
                    </div>
                </div>
                
                <div class="mt-4 pt-3 border-t border-gray-100 text-center">
                    <div class="text-2xl font-bold text-primary"><?= number_format($cajon['total_fermentaciones']) ?></div>
                    <div class="text-xs text-gray-500">Fermentaciones</div>
                </div>
                
                <div class="flex items-center gap-1 mt-4 pt-3 border-t border-gray-100">
                    <button onclick="toggleEstado(<?= $cajon['id'] ?>)" 
                            class="flex-1 px-2 py-1.5 text-xs font-medium rounded-lg transition-colors
                                   <?= $cajon['activo'] ? 'bg-gray-100 text-gray-700 hover:bg-gray-200' : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200' ?>">
                        <?= $cajon['activo'] ? 'Desactivar' : 'Activar' ?>
                    </button>
                    <button onclick="openModal('edit', <?= $cajon['id'] ?>)" 
                            class="p-1.5 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Editar">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                    </button>
                    <?php if ($cajon['total_fermentaciones'] == 0): ?>
                    <button onclick="deleteCajon(<?= $cajon['id'] ?>, '<?= htmlspecialchars($cajon['numero']) ?>')" 
                            class="p-1.5 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Eliminar">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
        Mostrando <?= count($cajones) ?> cajón(es)
    </div>
</div>

<!-- Modal Crear/Editar -->
<div id="cajonModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md transform transition-all">
        <div class="bg-gradient-to-r from-primary to-primary/80 px-6 py-4 rounded-t-2xl">
            <h3 id="modalTitle" class="text-xl font-bold text-white">Nuevo Cajón</h3>
        </div>
        
        <form id="cajonForm" class="p-6">
            <input type="hidden" id="cajonId" name="id">
            <input type="hidden" id="formAction" name="action" value="create">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Número/Código *</label>
                    <input type="text" id="numero" name="numero" required maxlength="20"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary uppercase"
                           placeholder="Ej: C-001">
                    <p class="text-xs text-gray-500 mt-1">Identificador único del cajón</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Capacidad (kg)</label>
                    <input type="number" id="capacidad_kg" name="capacidad_kg" step="0.01" min="0"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                           placeholder="Ej: 500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Material</label>
                    <select id="material" name="material"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                        <option value="">Seleccionar...</option>
                        <option value="Madera">Madera</option>
                        <option value="Madera de Laurel">Madera de Laurel</option>
                        <option value="Madera de Cedro">Madera de Cedro</option>
                        <option value="Plástico">Plástico</option>
                        <option value="Acero Inoxidable">Acero Inoxidable</option>
                        <option value="Fibra de Vidrio">Fibra de Vidrio</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ubicación</label>
                    <input type="text" id="ubicacion" name="ubicacion" maxlength="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                           placeholder="Ej: Área de Fermentación 1">
                </div>
            </div>
            
            <div class="flex justify-end gap-3 mt-6 pt-6 border-t">
                <button type="button" onclick="closeModal()" 
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                    Cancelar
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors">
                    <span id="submitBtnText">Guardar</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Funciones del Modal
function openModal(mode, id = null) {
    const modal = document.getElementById('cajonModal');
    const form = document.getElementById('cajonForm');
    const title = document.getElementById('modalTitle');
    const action = document.getElementById('formAction');
    const submitBtn = document.getElementById('submitBtnText');
    
    form.reset();
    
    if (mode === 'create') {
        title.textContent = 'Nuevo Cajón';
        action.value = 'create';
        submitBtn.textContent = 'Crear Cajón';
        document.getElementById('cajonId').value = '';
    } else if (mode === 'edit' && id) {
        title.textContent = 'Editar Cajón';
        action.value = 'update';
        submitBtn.textContent = 'Guardar Cambios';
        
        // Cargar datos
        fetch('/configuracion/cajones.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get&id=${id}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const c = data.data;
                document.getElementById('cajonId').value = c.id;
                document.getElementById('numero').value = c.numero;
                document.getElementById('capacidad_kg').value = c.capacidad_kg || '';
                document.getElementById('material').value = c.material || '';
                document.getElementById('ubicacion').value = c.ubicacion || '';
            } else {
                showNotification(data.message, 'error');
            }
        });
    }
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeModal() {
    const modal = document.getElementById('cajonModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Envío del formulario
document.getElementById('cajonForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('/configuracion/cajones.php', {
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
async function toggleEstado(id) {
    const confirmed = window.App?.confirm
        ? await App.confirm('¿Cambiar el estado de este cajón?', 'Cambiar estado')
        : confirm('¿Cambiar el estado de este cajón?');
    if (!confirmed) return;
    
    fetch('/configuracion/cajones.php', {
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

// Eliminar cajón
async function deleteCajon(id, numero) {
    const mensaje = `¿Está seguro de eliminar el cajón "${numero}"?\n\nEsta acción no se puede deshacer.`;
    const confirmed = window.App?.confirm
        ? await App.confirm(mensaje, 'Eliminar cajón')
        : confirm(mensaje);
    if (!confirmed) return;
    
    fetch('/configuracion/cajones.php', {
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
    if (window.App && typeof App.toast === 'function') {
        App.toast(message, type);
        return;
    }

    const colors = {
        success: 'bg-emerald-500',
        error: 'bg-red-500',
        info: 'bg-blue-500'
    };
    
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 ${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-300`;
    notification.textContent = message;
    notification.setAttribute('role', 'status');
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
document.getElementById('cajonModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
