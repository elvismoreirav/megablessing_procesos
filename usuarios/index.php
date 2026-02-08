<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Gestión de Usuarios
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

if (!Auth::isAdmin() && !Auth::hasPermission('configuracion')) {
    setFlash('danger', 'No tiene permisos para acceder a esta sección.');
    redirect('/dashboard.php');
}

$db = Database::getInstance();
$currentUserId = (int) Auth::id();

$roles = $db->fetchAll("
    SELECT id, nombre, descripcion, activo
    FROM roles
    ORDER BY nombre ASC
");

$rolesActivos = array_values(array_filter($roles, static fn(array $rol): bool => (int)($rol['activo'] ?? 1) === 1));
$rolIdsActivos = array_map(static fn(array $rol): int => (int)$rol['id'], $rolesActivos);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();

    $action = $_POST['action'] ?? '';
    $redirectQuery = '';

    try {
        if ($action === 'create') {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $rolId = (int)($_POST['rol_id'] ?? 0);
            $password = (string)($_POST['password'] ?? '');
            $activo = isset($_POST['activo']) ? 1 : 0;

            if ($nombre === '' || $email === '' || $password === '' || $rolId <= 0) {
                throw new Exception('Nombre, email, rol y contraseña son obligatorios.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('El correo electrónico no es válido.');
            }
            if (strlen($password) < 6) {
                throw new Exception('La contraseña debe tener al menos 6 caracteres.');
            }
            if (!in_array($rolId, $rolIdsActivos, true)) {
                throw new Exception('Seleccione un rol válido.');
            }

            $existente = $db->fetch("SELECT id FROM usuarios WHERE email = ?", [$email]);
            if ($existente) {
                throw new Exception('Ya existe un usuario con ese correo.');
            }

            $db->insert('usuarios', [
                'nombre' => $nombre,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'rol_id' => $rolId,
                'activo' => $activo,
            ]);

            setFlash('success', 'Usuario creado correctamente.');
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $rolId = (int)($_POST['rol_id'] ?? 0);
            $activo = isset($_POST['activo']) ? 1 : 0;

            if ($id <= 0 || $nombre === '' || $email === '' || $rolId <= 0) {
                throw new Exception('Datos incompletos para actualizar.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('El correo electrónico no es válido.');
            }
            if (!in_array($rolId, $rolIdsActivos, true)) {
                throw new Exception('Seleccione un rol válido.');
            }
            if ($id === $currentUserId && $activo === 0) {
                throw new Exception('No puede desactivar su propio usuario.');
            }

            $existente = $db->fetch("SELECT id FROM usuarios WHERE email = ? AND id != ?", [$email, $id]);
            if ($existente) {
                throw new Exception('Ya existe otro usuario con ese correo.');
            }

            $db->update(
                'usuarios',
                [
                    'nombre' => $nombre,
                    'email' => $email,
                    'rol_id' => $rolId,
                    'activo' => $activo,
                ],
                'id = :id',
                ['id' => $id]
            );

            if ($id === $currentUserId) {
                $user = $db->fetch(
                    "SELECT u.id, u.nombre, u.email, u.rol_id, r.nombre as rol_nombre, r.permisos
                     FROM usuarios u
                     JOIN roles r ON r.id = u.rol_id
                     WHERE u.id = ?",
                    [$id]
                );
                if ($user) {
                    $_SESSION['user_name'] = $user['nombre'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_rol'] = $user['rol_nombre'];
                    $_SESSION['user_rol_id'] = $user['rol_id'];
                    $_SESSION['user_permisos'] = json_decode($user['permisos'] ?? '{}', true) ?: [];
                }
            }

            $redirectQuery = '?edit=' . $id;
            setFlash('success', 'Usuario actualizado correctamente.');
        } elseif ($action === 'change_password') {
            $id = (int)($_POST['id'] ?? 0);
            $newPassword = (string)($_POST['new_password'] ?? '');

            if ($id <= 0) {
                throw new Exception('Usuario inválido.');
            }
            if (strlen($newPassword) < 6) {
                throw new Exception('La nueva contraseña debe tener al menos 6 caracteres.');
            }

            $db->update(
                'usuarios',
                ['password' => password_hash($newPassword, PASSWORD_DEFAULT)],
                'id = :id',
                ['id' => $id]
            );

            $redirectQuery = '?edit=' . $id;
            setFlash('success', 'Contraseña actualizada correctamente.');
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Usuario inválido.');
            }
            if ($id === $currentUserId) {
                throw new Exception('No puede cambiar el estado de su propio usuario.');
            }

            $db->query(
                "UPDATE usuarios SET activo = CASE WHEN activo = 1 THEN 0 ELSE 1 END WHERE id = ?",
                [$id]
            );

            setFlash('success', 'Estado del usuario actualizado.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Usuario inválido.');
            }
            if ($id === $currentUserId) {
                throw new Exception('No puede eliminar su propio usuario.');
            }

            try {
                $db->query("DELETE FROM usuarios WHERE id = ?", [$id]);
                setFlash('success', 'Usuario eliminado correctamente.');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new Exception('No se puede eliminar: el usuario tiene registros asociados. Desactívelo en su lugar.');
                }
                throw $e;
            }
        } else {
            throw new Exception('Acción no válida.');
        }
    } catch (Exception $e) {
        setFlash('danger', $e->getMessage());
    }

    redirect('/usuarios/index.php' . $redirectQuery);
}

$filtroTexto = trim((string)($_GET['q'] ?? ''));
$filtroRol = (int)($_GET['rol_id'] ?? 0);
$filtroEstadoRaw = $_GET['estado'] ?? '';
$filtroEstado = ($filtroEstadoRaw === '1' || $filtroEstadoRaw === '0') ? $filtroEstadoRaw : '';
$pagina = max(1, (int)($_GET['page'] ?? 1));

$where = ['1=1'];
$params = [];

if ($filtroTexto !== '') {
    $where[] = '(u.nombre LIKE :q OR u.email LIKE :q)';
    $params['q'] = '%' . $filtroTexto . '%';
}
if ($filtroRol > 0) {
    $where[] = 'u.rol_id = :rol_id';
    $params['rol_id'] = $filtroRol;
}
if ($filtroEstado !== '') {
    $where[] = 'u.activo = :activo';
    $params['activo'] = (int)$filtroEstado;
}

$whereClause = implode(' AND ', $where);

$total = (int)($db->fetch(
    "SELECT COUNT(*) as total
     FROM usuarios u
     LEFT JOIN roles r ON r.id = u.rol_id
     WHERE {$whereClause}",
    $params
)['total'] ?? 0);

$pagination = Helpers::paginate($total, ITEMS_PER_PAGE, $pagina);
$perPage = max(1, (int)$pagination['per_page']);
$offset = max(0, (int)$pagination['offset']);

$usuarios = $db->fetchAll(
    "SELECT u.*, r.nombre as rol_nombre,
            (SELECT COUNT(*) FROM lotes_historial h WHERE h.usuario_id = u.id) as acciones_count
     FROM usuarios u
     LEFT JOIN roles r ON r.id = u.rol_id
     WHERE {$whereClause}
     ORDER BY u.created_at DESC, u.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$stats = $db->fetch(
    "SELECT COUNT(*) as total,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activos,
            SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as inactivos
     FROM usuarios"
);

$editUsuario = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editUsuario = $db->fetch(
        "SELECT u.*, r.nombre as rol_nombre
         FROM usuarios u
         LEFT JOIN roles r ON r.id = u.rol_id
         WHERE u.id = ?",
        [$editId]
    );
}

$roleBadgeClass = static function (?string $rolNombre): string {
    $rol = strtolower(trim((string)$rolNombre));
    if (str_contains($rol, 'admin')) return 'bg-purple-100 text-purple-700';
    if (str_contains($rol, 'super')) return 'bg-blue-100 text-blue-700';
    if (str_contains($rol, 'calidad')) return 'bg-emerald-100 text-emerald-700';
    if (str_contains($rol, 'oper')) return 'bg-amber-100 text-amber-700';
    return 'bg-gray-100 text-gray-700';
};

$pageTitle = 'Gestión de Usuarios';
$pageSubtitle = 'Administración de usuarios y accesos';
ob_start();
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Gestión de Usuarios</h1>
            <p class="text-gray-600">Cree, edite y controle el estado de usuarios del sistema</p>
        </div>
        <a href="<?= APP_URL ?>/configuracion/index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            <i class="fas fa-arrow-left mr-2"></i>Volver a Configuración
        </a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-sm text-gray-500">Total Usuarios</p>
            <p class="text-3xl font-bold text-gray-900"><?= number_format((int)($stats['total'] ?? 0)) ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-sm text-gray-500">Usuarios Activos</p>
            <p class="text-3xl font-bold text-green-600"><?= number_format((int)($stats['activos'] ?? 0)) ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-sm text-gray-500">Usuarios Inactivos</p>
            <p class="text-3xl font-bold text-gray-500"><?= number_format((int)($stats['inactivos'] ?? 0)) ?></p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">
                    <?= $editUsuario ? 'Editar Usuario' : 'Nuevo Usuario' ?>
                </h2>

                <form method="POST" class="space-y-4">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editUsuario ? 'update' : 'create' ?>">
                    <?php if ($editUsuario): ?>
                    <input type="hidden" name="id" value="<?= (int)$editUsuario['id'] ?>">
                    <?php endif; ?>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                        <input type="text" name="nombre" required
                               value="<?= e($editUsuario['nombre'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Correo</label>
                        <input type="email" name="email" required
                               value="<?= e($editUsuario['email'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                    </div>

                    <?php if (!$editUsuario): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label>
                        <input type="password" name="password" minlength="6" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                        <p class="text-xs text-gray-500 mt-1">Mínimo 6 caracteres.</p>
                    </div>
                    <?php endif; ?>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rol</label>
                        <select name="rol_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                            <option value="">Seleccione...</option>
                            <?php foreach ($rolesActivos as $rol): ?>
                            <option value="<?= (int)$rol['id'] ?>" <?= ((int)($editUsuario['rol_id'] ?? 0) === (int)$rol['id']) ? 'selected' : '' ?>>
                                <?= e($rol['nombre']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="activo" value="1"
                               <?= ((int)($editUsuario['activo'] ?? 1) === 1) ? 'checked' : '' ?>
                               <?= ($editUsuario && (int)$editUsuario['id'] === $currentUserId) ? 'disabled' : '' ?>>
                        <span class="text-sm text-gray-700">Usuario activo</span>
                    </label>

                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 inline-flex justify-center items-center px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark">
                            <i class="fas fa-save mr-2"></i><?= $editUsuario ? 'Guardar Cambios' : 'Crear Usuario' ?>
                        </button>
                        <?php if ($editUsuario): ?>
                        <a href="<?= APP_URL ?>/usuarios/index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                            Cancelar
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if ($editUsuario): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-md font-semibold text-gray-900 mb-3">Cambiar Contraseña</h3>
                <form method="POST" class="space-y-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="id" value="<?= (int)$editUsuario['id'] ?>">
                    <div>
                        <input type="password" name="new_password" minlength="6" required
                               placeholder="Nueva contraseña"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                    </div>
                    <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-800">
                        <i class="fas fa-key mr-2"></i>Actualizar Contraseña
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <input type="text" name="q" value="<?= e($filtroTexto) ?>" placeholder="Buscar por nombre o correo..."
                           class="md:col-span-2 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">

                    <select name="rol_id" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                        <option value="">Todos los roles</option>
                        <?php foreach ($rolesActivos as $rol): ?>
                        <option value="<?= (int)$rol['id'] ?>" <?= $filtroRol === (int)$rol['id'] ? 'selected' : '' ?>>
                            <?= e($rol['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="estado" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                        <option value="">Todos los estados</option>
                        <option value="1" <?= $filtroEstado === '1' ? 'selected' : '' ?>>Activos</option>
                        <option value="0" <?= $filtroEstado === '0' ? 'selected' : '' ?>>Inactivos</option>
                    </select>

                    <div class="md:col-span-4 flex gap-2">
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark">
                            <i class="fas fa-search mr-2"></i>Filtrar
                        </button>
                        <a href="<?= APP_URL ?>/usuarios/index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                            Limpiar
                        </a>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
                    <p class="text-sm text-gray-600">
                        Mostrando <span class="font-semibold text-gray-900"><?= count($usuarios) ?></span> de
                        <span class="font-semibold text-gray-900"><?= $total ?></span> usuarios
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Usuario</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rol</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Estado</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Último Acceso</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($usuarios)): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-gray-500">No se encontraron usuarios.</td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($usuarios as $usr): ?>
                            <?php $esActual = (int)$usr['id'] === $currentUserId; ?>
                            <tr class="<?= $esActual ? 'bg-amber-50/50' : 'hover:bg-gray-50' ?>">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">
                                        <?= e($usr['nombre']) ?>
                                        <?php if ($esActual): ?>
                                        <span class="text-xs text-amber-700">(sesión actual)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-xs text-gray-500"><?= e($usr['email']) ?></div>
                                    <div class="text-xs text-gray-400">Acciones lote: <?= number_format((int)($usr['acciones_count'] ?? 0)) ?></div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?= $roleBadgeClass($usr['rol_nombre'] ?? null) ?>">
                                        <?= e($usr['rol_nombre'] ?? 'Sin rol') ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php if ((int)$usr['activo'] === 1): ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">Activo</span>
                                    <?php else: ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?= $usr['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($usr['ultimo_acceso'])) : 'Nunca' ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-center gap-2">
                                        <a href="<?= APP_URL ?>/usuarios/index.php?edit=<?= (int)$usr['id'] ?>"
                                           class="inline-flex items-center px-2 py-1 text-xs border border-blue-200 text-blue-700 rounded hover:bg-blue-50">
                                            Editar
                                        </a>

                                        <?php if (!$esActual): ?>
                                        <form method="POST" class="inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$usr['id'] ?>">
                                            <button type="submit"
                                                    class="inline-flex items-center px-2 py-1 text-xs border border-amber-200 text-amber-700 rounded hover:bg-amber-50">
                                                <?= (int)$usr['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                                            </button>
                                        </form>

                                        <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este usuario?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$usr['id'] ?>">
                                            <button type="submit"
                                                    class="inline-flex items-center px-2 py-1 text-xs border border-red-200 text-red-700 rounded hover:bg-red-50">
                                                Eliminar
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (($pagination['total_pages'] ?? 0) > 1): ?>
                <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
                    <p class="text-sm text-gray-500">Página <?= (int)$pagination['current_page'] ?> de <?= (int)$pagination['total_pages'] ?></p>
                    <div class="flex gap-2">
                        <?php if (!empty($pagination['has_prev'])): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => (int)$pagination['current_page'] - 1])) ?>"
                           class="px-3 py-1 border border-gray-300 rounded text-sm hover:bg-gray-50">Anterior</a>
                        <?php endif; ?>
                        <?php if (!empty($pagination['has_next'])): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => (int)$pagination['current_page'] + 1])) ?>"
                           class="px-3 py-1 border border-gray-300 rounded text-sm hover:bg-gray-50">Siguiente</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';

