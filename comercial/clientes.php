<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Procesos Comerciales - Clientes
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();
requireModuleAccess('clientes');

$db = Database::getInstance();
$tablaClientesExiste = (bool)$db->fetch("SHOW TABLES LIKE 'clientes'");
$errors = [];
$filtroBusqueda = trim((string)($_GET['q'] ?? ''));
$filtroEstado = strtolower(trim((string)($_GET['estado'] ?? 'activos')));
if (!in_array($filtroEstado, ['todos', 'activos', 'inactivos'], true)) {
    $filtroEstado = 'activos';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablaClientesExiste) {
    verifyCSRF();
    $accion = trim((string)($_POST['action'] ?? ''));

    if ($accion === 'save') {
        $clienteId = (int)($_POST['id'] ?? 0);
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $rucTaxes = trim((string)($_POST['ruc_taxes'] ?? ''));
        $representante = trim((string)($_POST['representante_nombre'] ?? ''));
        $telefono = trim((string)($_POST['telefono'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $paginaWeb = trim((string)($_POST['pagina_web'] ?? ''));
        $pais = trim((string)($_POST['pais'] ?? ''));
        $direccion = trim((string)($_POST['direccion'] ?? ''));

        if ($nombre === '') {
            $errors[] = 'El nombre del cliente es obligatorio.';
        }

        if ($email !== '' && !Helpers::isValidEmail($email)) {
            $errors[] = 'El e-mail de contacto no es válido.';
        }

        if ($paginaWeb !== '' && filter_var($paginaWeb, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'La página web debe ser una URL válida.';
        }

        if ($rucTaxes !== '') {
            $duplicadoRuc = $db->fetch(
                "SELECT id FROM clientes WHERE ruc_taxes = :ruc AND id != :id LIMIT 1",
                ['ruc' => $rucTaxes, 'id' => $clienteId]
            );
            if ($duplicadoRuc) {
                $errors[] = 'Ya existe un cliente con el mismo RUC/Código de Taxes.';
            }
        }

        if (empty($errors)) {
            $data = [
                'nombre' => $nombre,
                'ruc_taxes' => $rucTaxes !== '' ? $rucTaxes : null,
                'representante_nombre' => $representante !== '' ? $representante : null,
                'telefono' => $telefono !== '' ? $telefono : null,
                'email' => $email !== '' ? $email : null,
                'pagina_web' => $paginaWeb !== '' ? $paginaWeb : null,
                'pais' => $pais !== '' ? $pais : null,
                'direccion' => $direccion !== '' ? $direccion : null,
            ];

            if ($clienteId > 0) {
                $db->update('clientes', $data, 'id = :id', ['id' => $clienteId]);
                setFlash('success', 'Cliente actualizado correctamente.');
            } else {
                $data['codigo'] = Helpers::generateClienteCode();
                $data['activo'] = 1;
                $data['usuario_id'] = Auth::id();
                $db->insert('clientes', $data);
                setFlash('success', 'Cliente registrado correctamente.');
            }

            redirect('/comercial/clientes.php');
        }
    }

    if ($accion === 'toggle') {
        $clienteId = (int)($_POST['id'] ?? 0);
        if ($clienteId > 0) {
            $db->query("UPDATE clientes SET activo = NOT activo WHERE id = ?", [$clienteId]);
            setFlash('success', 'Estado del cliente actualizado.');
        }
        redirect('/comercial/clientes.php');
    }
}

$clienteEdicion = null;
if ($tablaClientesExiste && isset($_GET['edit'])) {
    $clienteEdicion = $db->fetch("SELECT * FROM clientes WHERE id = ?", [(int)$_GET['edit']]);
}

$clienteFormValue = static function (string $key, string $default = '') use ($clienteEdicion): string {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists($key, $_POST)) {
        return trim((string)$_POST[$key]);
    }
    return isset($clienteEdicion[$key]) ? trim((string)$clienteEdicion[$key]) : $default;
};

$stats = [
    'total' => 0,
    'activos' => 0,
    'paises' => 0,
];
$clientes = [];
$siguienteCodigo = 'CLI0001';

if ($tablaClientesExiste) {
    $stats = [
        'total' => (int)($db->fetch("SELECT COUNT(*) AS total FROM clientes")['total'] ?? 0),
        'activos' => (int)($db->fetch("SELECT COUNT(*) AS total FROM clientes WHERE activo = 1")['total'] ?? 0),
        'paises' => (int)($db->fetch("SELECT COUNT(DISTINCT pais) AS total FROM clientes WHERE TRIM(COALESCE(pais, '')) <> ''")['total'] ?? 0),
    ];
    $siguienteCodigo = Helpers::generateClienteCode();

    $where = ['1=1'];
    $params = [];

    if ($filtroEstado === 'activos') {
        $where[] = 'c.activo = 1';
    } elseif ($filtroEstado === 'inactivos') {
        $where[] = 'c.activo = 0';
    }

    if ($filtroBusqueda !== '') {
        $where[] = '(c.codigo LIKE :q OR c.nombre LIKE :q OR c.ruc_taxes LIKE :q OR c.pais LIKE :q)';
        $params['q'] = '%' . $filtroBusqueda . '%';
    }

    $clientes = $db->fetchAll(
        "SELECT c.*,
                u.nombre AS usuario_nombre
         FROM clientes c
         LEFT JOIN usuarios u ON u.id = c.usuario_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY c.activo DESC, c.nombre ASC",
        $params
    );
}

$pageTitle = 'Clientes';
$pageSubtitle = 'Catálogo comercial de clientes y mercados de salida';

ob_start();
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-primary">Clientes</h1>
            <p class="text-warmgray">Registre y administre los clientes asociados a mercados de salida.</p>
        </div>
    </div>

    <?php if (!$tablaClientesExiste): ?>
        <div class="card border border-amber-200 bg-amber-50/70">
            <div class="card-body">
                <h3 class="text-lg font-semibold text-amber-900 mb-2">Módulo pendiente de base de datos</h3>
                <p class="text-sm text-amber-800">
                    Para habilitar este módulo ejecute el patch <code>database/patch_procesos_comerciales.sql</code>.
                </p>
            </div>
        </div>
    <?php else: ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <div>
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="card">
            <div class="card-body">
                <p class="text-sm text-warmgray">Total clientes</p>
                <p class="text-3xl font-bold text-primary mt-1"><?= number_format($stats['total']) ?></p>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <p class="text-sm text-warmgray">Clientes activos</p>
                <p class="text-3xl font-bold text-emerald-600 mt-1"><?= number_format($stats['activos']) ?></p>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <p class="text-sm text-warmgray">Países registrados</p>
                <p class="text-3xl font-bold text-cyan-700 mt-1"><?= number_format($stats['paises']) ?></p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div class="xl:col-span-1">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><?= $clienteEdicion ? 'Editar cliente' : 'Nuevo cliente' ?></h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="space-y-4">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?= (int)($clienteEdicion['id'] ?? 0) ?>">

                        <div class="form-group">
                            <label class="form-label required">Código cliente</label>
                            <input type="text" class="form-control bg-gray-50" readonly
                                   value="<?= htmlspecialchars((string)($clienteEdicion['codigo'] ?? $siguienteCodigo)) ?>">
                            <p class="text-xs text-warmgray mt-1">Se genera automáticamente al guardar.</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Nombre del cliente</label>
                            <input type="text" name="nombre" class="form-control" required
                                   value="<?= htmlspecialchars($clienteFormValue('nombre')) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">RUC/Código de Taxes</label>
                            <input type="text" name="ruc_taxes" class="form-control"
                                   value="<?= htmlspecialchars($clienteFormValue('ruc_taxes')) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Nombre representante</label>
                            <input type="text" name="representante_nombre" class="form-control"
                                   value="<?= htmlspecialchars($clienteFormValue('representante_nombre')) ?>">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-group">
                                <label class="form-label">Teléfono de contacto</label>
                                <input type="text" name="telefono" class="form-control"
                                       value="<?= htmlspecialchars($clienteFormValue('telefono')) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">E-mail de contacto</label>
                                <input type="email" name="email" class="form-control"
                                       value="<?= htmlspecialchars($clienteFormValue('email')) ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Página web</label>
                            <input type="url" name="pagina_web" class="form-control"
                                   placeholder="https://..."
                                   value="<?= htmlspecialchars($clienteFormValue('pagina_web')) ?>">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-group">
                                <label class="form-label">País</label>
                                <input type="text" name="pais" class="form-control"
                                       value="<?= htmlspecialchars($clienteFormValue('pais')) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Dirección</label>
                                <input type="text" name="direccion" class="form-control"
                                       value="<?= htmlspecialchars($clienteFormValue('direccion')) ?>">
                            </div>
                        </div>

                        <div class="flex items-center gap-3 pt-2">
                            <button type="submit" class="btn btn-primary">
                                <?= $clienteEdicion ? 'Actualizar cliente' : 'Guardar cliente' ?>
                            </button>
                            <?php if ($clienteEdicion): ?>
                                <a href="<?= APP_URL ?>/comercial/clientes.php" class="btn btn-outline">Cancelar</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="xl:col-span-2">
            <div class="card">
                <div class="card-header">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <h3 class="card-title">Listado de clientes</h3>
                        <form method="GET" class="flex flex-wrap items-center gap-3">
                            <input type="text" name="q" class="form-control min-w-[220px]"
                                   placeholder="Buscar por código, cliente, país o RUC..."
                                   value="<?= htmlspecialchars($filtroBusqueda) ?>">
                            <select name="estado" class="form-control form-select w-44">
                                <option value="activos" <?= $filtroEstado === 'activos' ? 'selected' : '' ?>>Activos</option>
                                <option value="inactivos" <?= $filtroEstado === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
                                <option value="todos" <?= $filtroEstado === 'todos' ? 'selected' : '' ?>>Todos</option>
                            </select>
                            <button type="submit" class="btn btn-primary">Filtrar</button>
                            <?php if ($filtroBusqueda !== '' || $filtroEstado !== 'activos'): ?>
                                <a href="<?= APP_URL ?>/comercial/clientes.php" class="btn btn-outline">Limpiar</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Cliente</th>
                                <th>Representante</th>
                                <th>Contacto</th>
                                <th>País</th>
                                <th>Estado</th>
                                <th>Registrado por</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($clientes)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-10 text-warmgray">No se encontraron clientes registrados.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($clientes as $cliente): ?>
                                    <tr>
                                        <td class="font-semibold text-primary"><?= htmlspecialchars((string)$cliente['codigo']) ?></td>
                                        <td>
                                            <div class="font-medium"><?= htmlspecialchars((string)$cliente['nombre']) ?></div>
                                            <?php if (!empty($cliente['ruc_taxes'])): ?>
                                                <div class="text-xs text-warmgray"><?= htmlspecialchars((string)$cliente['ruc_taxes']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string)($cliente['representante_nombre'] ?? '')) ?></td>
                                        <td>
                                            <div><?= htmlspecialchars((string)($cliente['telefono'] ?? '')) ?></div>
                                            <div class="text-xs text-warmgray"><?= htmlspecialchars((string)($cliente['email'] ?? '')) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars((string)($cliente['pais'] ?? '')) ?></td>
                                        <td>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= (int)$cliente['activo'] === 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-700' ?>">
                                                <?= (int)$cliente['activo'] === 1 ? 'Activo' : 'Inactivo' ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars((string)($cliente['usuario_nombre'] ?? 'Sistema')) ?></td>
                                        <td>
                                            <div class="flex items-center justify-center gap-2">
                                                <a href="<?= APP_URL ?>/comercial/clientes.php?edit=<?= (int)$cliente['id'] ?>"
                                                   class="btn btn-outline px-3 py-2">Editar</a>
                                                <form method="POST" onsubmit="return confirm('¿Desea cambiar el estado de este cliente?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="id" value="<?= (int)$cliente['id'] ?>">
                                                    <button type="submit" class="btn <?= (int)$cliente['activo'] === 1 ? 'btn-outline' : 'btn-secondary' ?> px-3 py-2">
                                                        <?= (int)$cliente['activo'] === 1 ? 'Inactivar' : 'Activar' ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
