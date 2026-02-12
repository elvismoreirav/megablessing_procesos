<?php
/**
 * Gestión de Secadoras - Megablessing
 * CRUD completo para secadoras/tendales de secado
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
                $nombre = trim($_POST['nombre'] ?? '');
                $tipo = $_POST['tipo'] ?? 'INDUSTRIAL';
                $capacidad_qq = floatval($_POST['capacidad_qq'] ?? 0);
                $ubicacion = trim($_POST['ubicacion'] ?? '');
                
                if (empty($numero)) {
                    throw new Exception('El número/código de la secadora es obligatorio');
                }
                
                // Verificar número único
                $stmt = $db->prepare("SELECT id FROM secadoras WHERE numero = ?");
                $stmt->execute([$numero]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya existe una secadora con este número');
                }
                
                $stmt = $db->prepare("
                    INSERT INTO secadoras (numero, nombre, tipo, capacidad_qq, ubicacion, activo)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$numero, $nombre ?: null, $tipo, $capacidad_qq ?: null, $ubicacion ?: null]);
                
                echo json_encode(['success' => true, 'message' => 'Secadora creada exitosamente', 'id' => $db->lastInsertId()]);
                break;
                
            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                $numero = strtoupper(trim($_POST['numero'] ?? ''));
                $nombre = trim($_POST['nombre'] ?? '');
                $tipo = $_POST['tipo'] ?? 'INDUSTRIAL';
                $capacidad_qq = floatval($_POST['capacidad_qq'] ?? 0);
                $ubicacion = trim($_POST['ubicacion'] ?? '');
                
                if (!$id || empty($numero)) {
                    throw new Exception('Datos incompletos');
                }
                
                // Verificar número único (excepto el actual)
                $stmt = $db->prepare("SELECT id FROM secadoras WHERE numero = ? AND id != ?");
                $stmt->execute([$numero, $id]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya existe otra secadora con este número');
                }
                
                $stmt = $db->prepare("
                    UPDATE secadoras 
                    SET numero = ?, nombre = ?, tipo = ?, capacidad_qq = ?, ubicacion = ?
                    WHERE id = ?
                ");
                $stmt->execute([$numero, $nombre ?: null, $tipo, $capacidad_qq ?: null, $ubicacion ?: null, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Secadora actualizada exitosamente']);
                break;
                
            case 'toggle':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID inválido');
                
                $stmt = $db->prepare("UPDATE secadoras SET activo = NOT activo WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
                break;
                
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID inválido');
                
                // Verificar si tiene secados asociados
                $stmt = $db->prepare("SELECT COUNT(*) FROM registros_secado WHERE secadora_id = ?");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('No se puede eliminar: tiene registros de secado asociados. Desactive la secadora en su lugar.');
                }
                
                $stmt = $db->prepare("DELETE FROM secadoras WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Secadora eliminada']);
                break;
                
            case 'get':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID inválido');
                
                $stmt = $db->prepare("SELECT * FROM secadoras WHERE id = ?");
                $stmt->execute([$id]);
                $secadora = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$secadora) throw new Exception('Secadora no encontrada');
                
                echo json_encode(['success' => true, 'data' => $secadora]);
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Obtener secadoras para listado
$search = trim($_GET['search'] ?? '');
$tipo_filter = $_GET['tipo'] ?? '';
$estado_filter = $_GET['estado'] ?? '';

$where = [];
$params = [];

if ($search) {
    $where[] = "(numero LIKE ? OR nombre LIKE ? OR ubicacion LIKE ?)";
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
    SELECT s.*, 
           (SELECT COUNT(*) FROM registros_secado WHERE secadora_id = s.id) as total_secados,
           (SELECT COUNT(*) FROM registros_secado WHERE secadora_id = s.id AND estado = 'EN_PROCESO') as secados_activos
    FROM secadoras s
    $whereClause
    ORDER BY s.activo DESC, s.numero ASC
");
$stmt->execute($params);
$secadoras = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(activo = 1) as activos,
        SUM(tipo = 'INDUSTRIAL') as industrial,
        SUM(tipo = 'ARTESANAL') as artesanal,
        SUM(tipo = 'SOLAR') as solar,
        COALESCE(SUM(capacidad_qq), 0) as capacidad_total
    FROM secadoras
")->fetch(PDO::FETCH_ASSOC);

$enUso = $db->query("
    SELECT COUNT(DISTINCT secadora_id) 
    FROM registros_secado 
    WHERE estado = 'EN_PROCESO'
")->fetchColumn();

$pageTitle = 'Gestión de Secadoras';
ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
            <h1 class="text-3xl font-bold text-primary">Gestión de Secadoras</h1>
            <p class="text-warmgray mt-1">Administre las secadoras y tendales para el proceso de secado</p>
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
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-primary"><?= number_format($stats['total']) ?></div>
            <div class="text-sm text-warmgray">Total</div>
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
            <div class="text-3xl font-bold text-slate-600"><?= number_format($stats['industrial'] ?? 0) ?></div>
            <div class="text-sm text-warmgray">Industrial</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-amber-600"><?= number_format($stats['artesanal'] ?? 0) ?></div>
            <div class="text-sm text-warmgray">Artesanal</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-yellow-600"><?= number_format($stats['solar'] ?? 0) ?></div>
            <div class="text-sm text-warmgray">Solar</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-purple-600"><?= number_format($stats['capacidad_total'], 0) ?></div>
            <div class="text-sm text-warmgray">Cap. Total (qq)</div>
        </div>
    </div>

    <!-- Filtros y Acciones -->
    <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-6 mb-6">
        <div class="flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between">
            <form method="GET" class="flex flex-wrap gap-4 items-center flex-1">
                <div class="flex-1 min-w-[200px]">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Buscar por número, nombre o ubicación..."
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                </div>
                <select name="tipo" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary">
                    <option value="">Todos los tipos</option>
                    <option value="INDUSTRIAL" <?= $tipo_filter === 'INDUSTRIAL' ? 'selected' : '' ?>>Industrial</option>
                    <option value="ARTESANAL" <?= $tipo_filter === 'ARTESANAL' ? 'selected' : '' ?>>Artesanal</option>
                    <option value="SOLAR" <?= $tipo_filter === 'SOLAR' ? 'selected' : '' ?>>Solar</option>
                </select>
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
                <?php if ($search || $tipo_filter || $estado_filter !== ''): ?>
                <a href="/configuracion/secadoras.php" class="px-4 py-2 text-gray-600 hover:text-gray-800 transition-colors">
                    Limpiar
                </a>
                <?php endif; ?>
            </form>
            
            <button onclick="openModal('create')" 
                    class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                </svg>
                Nueva Secadora
            </button>
        </div>
    </div>

    <!-- Tabla de Secadoras -->
    <div class="bg-white rounded-xl shadow-sm border border-olive/20 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gradient-to-r from-teal-600 to-teal-500 text-white">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Número</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Nombre</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Tipo</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold">Capacidad (qq)</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Ubicación</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold">Secados</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold">Estado</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($secadoras)): ?>
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                            <p class="text-lg font-medium">No se encontraron secadoras</p>
                            <p class="text-sm">Agregue una nueva secadora o modifique los filtros</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($secadoras as $sec): ?>
                    <?php 
                    $enUsoActual = $sec['secados_activos'] > 0;
                    $tipoColors = [
                        'INDUSTRIAL' => 'bg-slate-100 text-slate-800',
                        'ARTESANAL' => 'bg-amber-100 text-amber-800',
                        'SOLAR' => 'bg-yellow-100 text-yellow-800'
                    ];
                    $tipoIcons = [
                        'INDUSTRIAL' => '🏭',
                        'ARTESANAL' => '🧺',
                        'SOLAR' => '☀️'
                    ];
                    ?>
                    <tr class="hover:bg-ivory-white/50 transition-colors <?= !$sec['activo'] ? 'opacity-60' : '' ?>">
                        <td class="px-6 py-4">
                            <span class="font-mono font-semibold text-primary"><?= htmlspecialchars($sec['numero']) ?></span>
                        </td>
                        <td class="px-6 py-4 text-gray-900">
                            <?= htmlspecialchars($sec['nombre'] ?: '-') ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium <?= $tipoColors[$sec['tipo']] ?? 'bg-gray-100 text-gray-800' ?>">
                                <span><?= $tipoIcons[$sec['tipo']] ?? '' ?></span>
                                <?= htmlspecialchars($sec['tipo']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right font-medium">
                            <?= $sec['capacidad_qq'] ? number_format($sec['capacidad_qq'], 1) : '-' ?>
                        </td>
                        <td class="px-6 py-4 text-gray-600 truncate max-w-xs" title="<?= htmlspecialchars($sec['ubicacion'] ?? '') ?>">
                            <?= htmlspecialchars($sec['ubicacion'] ?: '-') ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex flex-col items-center">
                                <span class="font-semibold text-primary"><?= number_format($sec['total_secados']) ?></span>
                                <?php if ($enUsoActual): ?>
                                <span class="text-xs text-blue-600">(<?= $sec['secados_activos'] ?> activo<?= $sec['secados_activos'] > 1 ? 's' : '' ?>)</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?php if ($enUsoActual): ?>
                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                                En Uso
                            </span>
                            <?php else: ?>
                            <button onclick="toggleEstado(<?= $sec['id'] ?>)" 
                                    class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium transition-colors
                                           <?= $sec['activo'] ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' : 'bg-red-100 text-red-800 hover:bg-red-200' ?>">
                                <span class="w-2 h-2 rounded-full <?= $sec['activo'] ? 'bg-emerald-500' : 'bg-red-500' ?>"></span>
                                <?= $sec['activo'] ? 'Disponible' : 'Inactiva' ?>
                            </button>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center justify-center gap-2">
                                <button onclick="openModal('edit', <?= $sec['id'] ?>)" 
                                        class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Editar">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <?php if ($sec['total_secados'] == 0): ?>
                                <button onclick="deleteSecadora(<?= $sec['id'] ?>, '<?= htmlspecialchars($sec['numero']) ?>')" 
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
        Mostrando <?= count($secadoras) ?> secadora(s)
    </div>
</div>

<!-- Modal Crear/Editar -->
<div id="secadoraModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md transform transition-all">
        <div class="bg-gradient-to-r from-teal-600 to-teal-500 px-6 py-4 rounded-t-2xl">
            <h3 id="modalTitle" class="text-xl font-bold text-white">Nueva Secadora</h3>
        </div>
        
        <form id="secadoraForm" class="p-6">
            <input type="hidden" id="secadoraId" name="id">
            <input type="hidden" id="formAction" name="action" value="create">
            
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Número/Código *</label>
                        <input type="text" id="numero" name="numero" required maxlength="20"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 uppercase"
                               placeholder="Ej: SEC-001">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
                        <select id="tipo" name="tipo" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            <option value="INDUSTRIAL">🏭 Industrial</option>
                            <option value="ARTESANAL">🧺 Artesanal</option>
                            <option value="SOLAR">☀️ Solar</option>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                    <input type="text" id="nombre" name="nombre" maxlength="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                           placeholder="Nombre descriptivo (opcional)">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Capacidad (quintales)</label>
                    <input type="number" id="capacidad_qq" name="capacidad_qq" step="0.1" min="0"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                           placeholder="Ej: 20">
                    <p class="text-xs text-gray-500 mt-1">1 quintal = 100 libras ≈ 45.36 kg</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ubicación</label>
                    <input type="text" id="ubicacion" name="ubicacion" maxlength="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                           placeholder="Ej: Tendal Norte">
                </div>
            </div>
            
            <div class="flex justify-end gap-3 mt-6 pt-6 border-t">
                <button type="button" onclick="closeModal()" 
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                    Cancelar
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors">
                    <span id="submitBtnText">Guardar</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Funciones del Modal
function openModal(mode, id = null) {
    const modal = document.getElementById('secadoraModal');
    const form = document.getElementById('secadoraForm');
    const title = document.getElementById('modalTitle');
    const action = document.getElementById('formAction');
    const submitBtn = document.getElementById('submitBtnText');
    
    form.reset();
    
    if (mode === 'create') {
        title.textContent = 'Nueva Secadora';
        action.value = 'create';
        submitBtn.textContent = 'Crear Secadora';
        document.getElementById('secadoraId').value = '';
    } else if (mode === 'edit' && id) {
        title.textContent = 'Editar Secadora';
        action.value = 'update';
        submitBtn.textContent = 'Guardar Cambios';
        
        // Cargar datos
        fetch('/configuracion/secadoras.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get&id=${id}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const s = data.data;
                document.getElementById('secadoraId').value = s.id;
                document.getElementById('numero').value = s.numero;
                document.getElementById('nombre').value = s.nombre || '';
                document.getElementById('tipo').value = s.tipo;
                document.getElementById('capacidad_qq').value = s.capacidad_qq || '';
                document.getElementById('ubicacion').value = s.ubicacion || '';
            } else {
                showNotification(data.message, 'error');
            }
        });
    }
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeModal() {
    const modal = document.getElementById('secadoraModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Envío del formulario
document.getElementById('secadoraForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('/configuracion/secadoras.php', {
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
        ? await App.confirm('¿Cambiar el estado de esta secadora?', 'Cambiar estado')
        : confirm('¿Cambiar el estado de esta secadora?');
    if (!confirmed) return;
    
    fetch('/configuracion/secadoras.php', {
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

// Eliminar secadora
async function deleteSecadora(id, numero) {
    const mensaje = `¿Está seguro de eliminar la secadora "${numero}"?\n\nEsta acción no se puede deshacer.`;
    const confirmed = window.App?.confirm
        ? await App.confirm(mensaje, 'Eliminar secadora')
        : confirm(mensaje);
    if (!confirmed) return;
    
    fetch('/configuracion/secadoras.php', {
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
document.getElementById('secadoraModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
