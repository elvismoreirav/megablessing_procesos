<?php
/**
 * Gestión de Estados
 * Administrar estados de fermentación y estados de calidad
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

if (!Auth::isAdmin() && !Auth::hasPermission('configuracion')) {
    setFlash('danger', 'No tiene permisos para acceder a esta sección.');
    redirect('/dashboard.php');
}

$db = Database::getInstance();
$message = '';
$error = '';

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tipo = $_POST['tipo'] ?? '';
    
    if ($tipo === 'fermentacion') {
        if ($action === 'create') {
            $nombre = trim($_POST['nombre'] ?? '');
            $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
            $descripcion = trim($_POST['descripcion'] ?? '');
            $orden = (int)($_POST['orden'] ?? 0);
            
            if (empty($nombre) || empty($codigo)) {
                $error = 'Nombre y código son requeridos';
            } else {
                $existing = $db->fetchOne("SELECT id FROM estados_fermentacion WHERE codigo = ?", [$codigo]);
                if ($existing) {
                    $error = 'Ya existe un estado con ese código';
                } else {
                    $db->query(
                        "INSERT INTO estados_fermentacion (nombre, codigo, descripcion, orden) VALUES (?, ?, ?, ?)",
                        [$nombre, $codigo, $descripcion, $orden]
                    );
                    $message = 'Estado de fermentación creado';
                }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $orden = (int)($_POST['orden'] ?? 0);
            
            if (empty($nombre) || $id <= 0) {
                $error = 'Datos inválidos';
            } else {
                $db->query(
                    "UPDATE estados_fermentacion SET nombre = ?, descripcion = ?, orden = ? WHERE id = ?",
                    [$nombre, $descripcion, $orden, $id]
                );
                $message = 'Estado actualizado';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $hasRegistros = $db->fetchOne("SELECT COUNT(*) as count FROM registros_fermentacion WHERE estado_id = ?", [$id]);
            if ($hasRegistros && $hasRegistros['count'] > 0) {
                $error = 'No se puede eliminar: estado en uso (' . $hasRegistros['count'] . ' registros)';
            } else {
                $db->query("DELETE FROM estados_fermentacion WHERE id = ?", [$id]);
                $message = 'Estado eliminado';
            }
        }
    } elseif ($tipo === 'calidad') {
        if ($action === 'create') {
            $nombre = trim($_POST['nombre'] ?? '');
            $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
            $descripcion = trim($_POST['descripcion'] ?? '');
            $color = trim($_POST['color'] ?? '#6B7280');
            
            if (empty($nombre) || empty($codigo)) {
                $error = 'Nombre y código son requeridos';
            } else {
                $existing = $db->fetchOne("SELECT id FROM estados_calidad WHERE codigo = ?", [$codigo]);
                if ($existing) {
                    $error = 'Ya existe un estado de calidad con ese código';
                } else {
                    $db->query(
                        "INSERT INTO estados_calidad (nombre, codigo, descripcion, color) VALUES (?, ?, ?, ?)",
                        [$nombre, $codigo, $descripcion, $color]
                    );
                    $message = 'Estado de calidad creado';
                }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $color = trim($_POST['color'] ?? '#6B7280');
            
            if (empty($nombre) || $id <= 0) {
                $error = 'Datos inválidos';
            } else {
                $db->query(
                    "UPDATE estados_calidad SET nombre = ?, descripcion = ?, color = ? WHERE id = ?",
                    [$nombre, $descripcion, $color, $id]
                );
                $message = 'Estado de calidad actualizado';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $hasLotes = $db->fetchOne("SELECT COUNT(*) as count FROM lotes WHERE estado_calidad_id = ?", [$id]);
            if ($hasLotes && $hasLotes['count'] > 0) {
                $error = 'No se puede eliminar: estado en uso (' . $hasLotes['count'] . ' lotes)';
            } else {
                $db->query("DELETE FROM estados_calidad WHERE id = ?", [$id]);
                $message = 'Estado de calidad eliminado';
            }
        }
    }
}

// Obtener estados
$estadosFermentacion = $db->fetchAll("
    SELECT ef.*, COUNT(rf.id) as registros_count
    FROM estados_fermentacion ef
    LEFT JOIN registros_fermentacion rf ON ef.id = rf.estado_id
    GROUP BY ef.id
    ORDER BY ef.orden, ef.nombre
");

$estadosCalidad = $db->fetchAll("
    SELECT ec.*, COUNT(l.id) as lotes_count
    FROM estados_calidad ec
    LEFT JOIN lotes l ON ec.id = l.estado_calidad_id
    GROUP BY ec.id
    ORDER BY ec.nombre
");

// Datos para edición
$editFermentacion = null;
$editCalidad = null;
if (isset($_GET['edit_ferm'])) {
    $editFermentacion = $db->fetchOne("SELECT * FROM estados_fermentacion WHERE id = ?", [(int)$_GET['edit_ferm']]);
}
if (isset($_GET['edit_cal'])) {
    $editCalidad = $db->fetchOne("SELECT * FROM estados_calidad WHERE id = ?", [(int)$_GET['edit_cal']]);
}

$pageTitle = 'Gestión de Estados';
ob_start();
?>

<div class="max-w-7xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-primary">Gestión de Estados</h1>
            <p class="text-warmgray">Configure los estados del proceso de fermentación y calidad</p>
        </div>
        <a href="/configuracion/"
           class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
            <i class="fas fa-arrow-left"></i>
            Volver a Configuración
        </a>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success" data-auto-dismiss>
        <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger" data-auto-dismiss>
        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <!-- Estados de Fermentación -->
        <div class="space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-fire text-white"></i>
                    </div>
                    <h2 class="text-lg font-semibold text-gray-900">
                        <?= $editFermentacion ? 'Editar Estado Fermentación' : 'Nuevo Estado Fermentación' ?>
                    </h2>
                </div>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="<?= $editFermentacion ? 'update' : 'create' ?>">
                    <input type="hidden" name="tipo" value="fermentacion">
                    <?php if ($editFermentacion): ?>
                    <input type="hidden" name="id" value="<?= $editFermentacion['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label>
                            <input type="text" name="nombre" required
                                   value="<?= htmlspecialchars($editFermentacion['nombre'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Código *</label>
                            <input type="text" name="codigo" required <?= $editFermentacion ? 'readonly' : '' ?>
                                   value="<?= htmlspecialchars($editFermentacion['codigo'] ?? '') ?>"
                                   placeholder="Ej: DIA1"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 <?= $editFermentacion ? 'bg-gray-100' : '' ?>">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-3 gap-4">
                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                            <input type="text" name="descripcion"
                                   value="<?= htmlspecialchars($editFermentacion['descripcion'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Orden</label>
                            <input type="number" name="orden" min="0"
                                   value="<?= $editFermentacion['orden'] ?? 0 ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                        </div>
                    </div>
                    
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-orange-600 text-white px-4 py-2 rounded-lg hover:bg-orange-700 transition-colors">
                            <i class="fas fa-save mr-2"></i><?= $editFermentacion ? 'Actualizar' : 'Guardar' ?>
                        </button>
                        <?php if ($editFermentacion): ?>
                        <a href="/configuracion/estados.php" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                            Cancelar
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Lista Estados Fermentación -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-gradient-to-r from-orange-50 to-red-50">
                    <h3 class="font-semibold text-gray-900">Estados de Fermentación (<?= count($estadosFermentacion) ?>)</h3>
                </div>
                <div class="divide-y divide-gray-100">
                    <?php foreach ($estadosFermentacion as $ef): ?>
                    <div class="px-4 py-3 hover:bg-gray-50 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="w-8 h-8 bg-orange-100 text-orange-700 rounded-lg flex items-center justify-center text-sm font-bold">
                                <?= $ef['orden'] ?>
                            </span>
                            <div>
                                <div class="font-medium text-gray-900"><?= htmlspecialchars($ef['nombre']) ?></div>
                                <div class="text-xs text-gray-500">
                                    <span class="font-mono bg-gray-100 px-1 rounded"><?= htmlspecialchars($ef['codigo']) ?></span>
                                    <?php if ($ef['descripcion']): ?>
                                    · <?= htmlspecialchars($ef['descripcion']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-500"><?= $ef['registros_count'] ?> usos</span>
                            <a href="?edit_ferm=<?= $ef['id'] ?>" class="text-blue-600 hover:text-blue-800">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php if ($ef['registros_count'] == 0): ?>
                            <form method="POST" class="inline" onsubmit="return (window.inlineConfirm ? inlineConfirm(event, '¿Eliminar este estado?', 'Eliminar estado') : confirm('¿Eliminar este estado?'))">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="tipo" value="fermentacion">
                                <input type="hidden" name="id" value="<?= $ef['id'] ?>">
                                <button type="submit" class="text-red-600 hover:text-red-800">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($estadosFermentacion)): ?>
                    <div class="px-4 py-6 text-center text-gray-500">
                        No hay estados de fermentación definidos
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Estados de Calidad -->
        <div class="space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-gradient-to-br from-emerald-500 to-teal-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-certificate text-white"></i>
                    </div>
                    <h2 class="text-lg font-semibold text-gray-900">
                        <?= $editCalidad ? 'Editar Estado Calidad' : 'Nuevo Estado Calidad' ?>
                    </h2>
                </div>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="<?= $editCalidad ? 'update' : 'create' ?>">
                    <input type="hidden" name="tipo" value="calidad">
                    <?php if ($editCalidad): ?>
                    <input type="hidden" name="id" value="<?= $editCalidad['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label>
                            <input type="text" name="nombre" required
                                   value="<?= htmlspecialchars($editCalidad['nombre'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Código *</label>
                            <input type="text" name="codigo" required <?= $editCalidad ? 'readonly' : '' ?>
                                   value="<?= htmlspecialchars($editCalidad['codigo'] ?? '') ?>"
                                   placeholder="Ej: PREMIUM"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 <?= $editCalidad ? 'bg-gray-100' : '' ?>">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-3 gap-4">
                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                            <input type="text" name="descripcion"
                                   value="<?= htmlspecialchars($editCalidad['descripcion'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Color</label>
                            <input type="color" name="color"
                                   value="<?= $editCalidad['color'] ?? '#10B981' ?>"
                                   class="w-full h-10 border border-gray-300 rounded-lg cursor-pointer">
                        </div>
                    </div>
                    
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 transition-colors">
                            <i class="fas fa-save mr-2"></i><?= $editCalidad ? 'Actualizar' : 'Guardar' ?>
                        </button>
                        <?php if ($editCalidad): ?>
                        <a href="/configuracion/estados.php" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                            Cancelar
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Lista Estados Calidad -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-gradient-to-r from-emerald-50 to-teal-50">
                    <h3 class="font-semibold text-gray-900">Estados de Calidad (<?= count($estadosCalidad) ?>)</h3>
                </div>
                <div class="divide-y divide-gray-100">
                    <?php foreach ($estadosCalidad as $ec): ?>
                    <div class="px-4 py-3 hover:bg-gray-50 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="w-8 h-8 rounded-lg flex items-center justify-center"
                                  style="background-color: <?= $ec['color'] ?>20; color: <?= $ec['color'] ?>">
                                <i class="fas fa-certificate"></i>
                            </span>
                            <div>
                                <div class="font-medium text-gray-900"><?= htmlspecialchars($ec['nombre']) ?></div>
                                <div class="text-xs text-gray-500">
                                    <span class="font-mono bg-gray-100 px-1 rounded"><?= htmlspecialchars($ec['codigo']) ?></span>
                                    <?php if ($ec['descripcion']): ?>
                                    · <?= htmlspecialchars($ec['descripcion']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-500"><?= $ec['lotes_count'] ?> lotes</span>
                            <a href="?edit_cal=<?= $ec['id'] ?>" class="text-blue-600 hover:text-blue-800">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php if ($ec['lotes_count'] == 0): ?>
                            <form method="POST" class="inline" onsubmit="return (window.inlineConfirm ? inlineConfirm(event, '¿Eliminar este estado?', 'Eliminar estado') : confirm('¿Eliminar este estado?'))">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="tipo" value="calidad">
                                <input type="hidden" name="id" value="<?= $ec['id'] ?>">
                                <button type="submit" class="text-red-600 hover:text-red-800">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($estadosCalidad)): ?>
                    <div class="px-4 py-6 text-center text-gray-500">
                        No hay estados de calidad definidos
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Información sobre estados del proceso -->
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
        <div class="flex items-start gap-3">
            <i class="fas fa-info-circle text-blue-600 mt-0.5"></i>
            <div class="text-sm text-blue-800">
                <p class="font-semibold mb-1">Sobre los Estados</p>
                <p class="mb-2"><strong>Estados de Fermentación:</strong> Definen las etapas del proceso de fermentación (Día 1 a Día 6). El código es único e identifica cada etapa.</p>
                <p><strong>Estados de Calidad:</strong> Clasifican el producto final según la prueba de corte (Premium, Exportación, Nacional, Rechazado). El color se usa para identificación visual.</p>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
