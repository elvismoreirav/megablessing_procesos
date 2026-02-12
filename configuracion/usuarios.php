<?php
/**
 * Gestión de Usuarios
 * CRUD completo para administrar usuarios del sistema
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
$currentUserId = (int) Auth::id();

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rol = trim($_POST['rol'] ?? 'operador');
        $password = $_POST['password'] ?? '';
        $activo = isset($_POST['activo']) ? 1 : 0;
        
        if (empty($nombre) || empty($email) || empty($password)) {
            $error = 'Nombre, email y contraseña son requeridos';
        } elseif (strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email inválido';
        } else {
            $existing = $db->fetchOne("SELECT id FROM usuarios WHERE email = ?", [$email]);
            if ($existing) {
                $error = 'Ya existe un usuario con ese email';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $db->query(
                    "INSERT INTO usuarios (nombre, email, password, rol, activo) VALUES (?, ?, ?, ?, ?)",
                    [$nombre, $email, $passwordHash, $rol, $activo]
                );
                $message = 'Usuario creado exitosamente';
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rol = trim($_POST['rol'] ?? 'operador');
        $activo = isset($_POST['activo']) ? 1 : 0;
        
        if (empty($nombre) || empty($email) || $id <= 0) {
            $error = 'Datos inválidos';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email inválido';
        } else {
            // No permitir desactivar al usuario actual
            if ($id == $currentUserId && !$activo) {
                $error = 'No puede desactivar su propio usuario';
            } else {
                $existing = $db->fetchOne("SELECT id FROM usuarios WHERE email = ? AND id != ?", [$email, $id]);
                if ($existing) {
                    $error = 'Ya existe otro usuario con ese email';
                } else {
                    $db->query(
                        "UPDATE usuarios SET nombre = ?, email = ?, rol = ?, activo = ? WHERE id = ?",
                        [$nombre, $email, $rol, $activo, $id]
                    );
                    $message = 'Usuario actualizado exitosamente';
                }
            }
        }
    } elseif ($action === 'change_password') {
        $id = (int)($_POST['id'] ?? 0);
        $password = $_POST['new_password'] ?? '';
        
        if (empty($password) || strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $db->query("UPDATE usuarios SET password = ? WHERE id = ?", [$passwordHash, $id]);
            $message = 'Contraseña actualizada';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id == $currentUserId) {
            $error = 'No puede eliminar su propio usuario';
        } else {
            // Verificar si tiene registros asociados
            $hasHistorial = $db->fetchOne("SELECT COUNT(*) as count FROM historial_lotes WHERE usuario_id = ?", [$id]);
            if ($hasHistorial && $hasHistorial['count'] > 0) {
                $error = 'No se puede eliminar: usuario tiene ' . $hasHistorial['count'] . ' acciones en el historial. Desactívelo en su lugar.';
            } else {
                $db->query("DELETE FROM usuarios WHERE id = ?", [$id]);
                $message = 'Usuario eliminado';
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id == $currentUserId) {
            $error = 'No puede desactivar su propio usuario';
        } else {
            $db->query("UPDATE usuarios SET activo = NOT activo WHERE id = ?", [$id]);
            $message = 'Estado actualizado';
        }
    }
}

// Obtener usuarios
$usuarios = $db->fetchAll("
    SELECT u.*, 
           COUNT(h.id) as acciones_count,
           MAX(h.fecha) as ultima_accion
    FROM usuarios u
    LEFT JOIN historial_lotes h ON u.id = h.usuario_id
    GROUP BY u.id
    ORDER BY u.nombre
");

// Usuario para editar
$editUsuario = null;
if (isset($_GET['edit'])) {
    $editUsuario = $db->fetchOne("SELECT * FROM usuarios WHERE id = ?", [(int)$_GET['edit']]);
}

// Roles disponibles
$roles = [
    'admin' => ['nombre' => 'Administrador', 'descripcion' => 'Acceso total al sistema', 'color' => 'purple'],
    'supervisor' => ['nombre' => 'Supervisor', 'descripcion' => 'Supervisión de procesos y reportes', 'color' => 'blue'],
    'operador' => ['nombre' => 'Operador', 'descripcion' => 'Registro de datos de proceso', 'color' => 'green'],
    'consulta' => ['nombre' => 'Consulta', 'descripcion' => 'Solo visualización de datos', 'color' => 'gray'],
];

$pageTitle = 'Gestión de Usuarios';
ob_start();
?>

<div class="max-w-7xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-primary">Gestión de Usuarios</h1>
            <p class="text-warmgray">Administre los usuarios del sistema</p>
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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Formulario -->
        <div class="lg:col-span-1 space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">
                    <?= $editUsuario ? 'Editar Usuario' : 'Nuevo Usuario' ?>
                </h2>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="<?= $editUsuario ? 'update' : 'create' ?>">
                    <?php if ($editUsuario): ?>
                    <input type="hidden" name="id" value="<?= $editUsuario['id'] ?>">
                    <?php endif; ?>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nombre Completo *</label>
                        <input type="text" name="nombre" required
                               value="<?= htmlspecialchars($editUsuario['nombre'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                        <input type="email" name="email" required
                               value="<?= htmlspecialchars($editUsuario['email'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    </div>
                    
                    <?php if (!$editUsuario): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña *</label>
                        <input type="password" name="password" required minlength="6"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                        <p class="text-xs text-gray-500 mt-1">Mínimo 6 caracteres</p>
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rol</label>
                        <select name="rol" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                            <?php foreach ($roles as $rolKey => $rolData): ?>
                            <option value="<?= $rolKey ?>" <?= ($editUsuario['rol'] ?? 'operador') == $rolKey ? 'selected' : '' ?>>
                                <?= $rolData['nombre'] ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex items-center">
                        <input type="checkbox" name="activo" id="activo"
                               <?= ($editUsuario['activo'] ?? 1) ? 'checked' : '' ?>
                               <?= $editUsuario && $editUsuario['id'] == $currentUserId ? 'disabled' : '' ?>
                               class="h-4 w-4 text-amber-600 focus:ring-amber-500 border-gray-300 rounded">
                        <label for="activo" class="ml-2 text-sm text-gray-700">Usuario activo</label>
                    </div>
                    
                    <div class="flex gap-2 pt-2">
                        <button type="submit" class="flex-1 bg-amber-600 text-white px-4 py-2 rounded-lg hover:bg-amber-700 transition-colors">
                            <i class="fas fa-save mr-2"></i><?= $editUsuario ? 'Actualizar' : 'Crear Usuario' ?>
                        </button>
                        <?php if ($editUsuario): ?>
                        <a href="/configuracion/usuarios.php" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                            Cancelar
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Cambiar contraseña -->
            <?php if ($editUsuario): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-semibold text-gray-900 mb-4">Cambiar Contraseña</h3>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="id" value="<?= $editUsuario['id'] ?>">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nueva Contraseña</label>
                        <input type="password" name="new_password" required minlength="6"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    </div>
                    
                    <button type="submit" class="w-full bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-key mr-2"></i>Actualizar Contraseña
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Roles Info -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-semibold text-gray-900 mb-4">Roles del Sistema</h3>
                <div class="space-y-3">
                    <?php foreach ($roles as $rolKey => $rolData): 
                        $colorClasses = [
                            'purple' => 'bg-purple-100 text-purple-700',
                            'blue' => 'bg-blue-100 text-blue-700',
                            'green' => 'bg-green-100 text-green-700',
                            'gray' => 'bg-gray-100 text-gray-700',
                        ];
                    ?>
                    <div class="flex items-start gap-3">
                        <span class="<?= $colorClasses[$rolData['color']] ?> px-2 py-1 rounded text-xs font-medium">
                            <?= $rolData['nombre'] ?>
                        </span>
                        <p class="text-xs text-gray-600"><?= $rolData['descripcion'] ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Lista de Usuarios -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-purple-50 to-indigo-50">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Usuarios Registrados
                        <span class="text-sm font-normal text-gray-500">(<?= count($usuarios) ?>)</span>
                    </h2>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Usuario</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rol</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Acciones</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Estado</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Opciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($usuarios as $usr): 
                                $rolInfo = $roles[$usr['rol']] ?? ['nombre' => $usr['rol'], 'color' => 'gray'];
                                $colorClasses = [
                                    'purple' => 'bg-purple-100 text-purple-700',
                                    'blue' => 'bg-blue-100 text-blue-700',
                                    'green' => 'bg-green-100 text-green-700',
                                    'gray' => 'bg-gray-100 text-gray-700',
                                ];
                                $isCurrentUser = $usr['id'] == $currentUserId;
                            ?>
                            <tr class="hover:bg-gray-50 <?= $isCurrentUser ? 'bg-amber-50/50' : '' ?>">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center">
                                            <span class="text-gray-600 font-medium">
                                                <?= strtoupper(substr($usr['nombre'], 0, 2)) ?>
                                            </span>
                                        </div>
                                        <div>
                                            <div class="font-medium text-gray-900">
                                                <?= htmlspecialchars($usr['nombre']) ?>
                                                <?php if ($isCurrentUser): ?>
                                                <span class="text-xs text-amber-600">(tú)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($usr['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="<?= $colorClasses[$rolInfo['color']] ?> px-2 py-1 rounded text-xs font-medium">
                                        <?= $rolInfo['nombre'] ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="text-sm font-medium text-gray-900"><?= $usr['acciones_count'] ?></span>
                                    <?php if ($usr['ultima_accion']): ?>
                                    <div class="text-xs text-gray-500">
                                        Última: <?= date('d/m/Y', strtotime($usr['ultima_accion'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php if (!$isCurrentUser): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= $usr['id'] ?>">
                                        <button type="submit" class="<?= $usr['activo'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?> px-2 py-1 rounded-full text-xs font-medium hover:opacity-80">
                                            <?= $usr['activo'] ? 'Activo' : 'Inactivo' ?>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs font-medium">
                                        Activo
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <a href="?edit=<?= $usr['id'] ?>" class="text-blue-600 hover:text-blue-800" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (!$isCurrentUser && $usr['acciones_count'] == 0): ?>
                                        <form method="POST" class="inline" onsubmit="return (window.inlineConfirm ? inlineConfirm(event, '¿Eliminar este usuario?', 'Eliminar usuario') : confirm('¿Eliminar este usuario?'))">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $usr['id'] ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-800" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($usuarios)): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                    No hay usuarios registrados
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
