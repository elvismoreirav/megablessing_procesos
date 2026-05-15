<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Procesos Comerciales - Muestras
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();
requireModuleAccess('muestras');

$db = Database::getInstance();
$tablaMuestrasExiste = (bool)$db->fetch("SHOW TABLES LIKE 'muestras_comerciales'");
$tablaClientesExiste = (bool)$db->fetch("SHOW TABLES LIKE 'clientes'");
$tablaProveedoresExiste = (bool)$db->fetch("SHOW TABLES LIKE 'proveedores'");
$errors = [];

$productos = [
    'CACAO_GRANO' => 'Cacao en grano',
    'MANTECA_CACAO' => 'Manteca de cacao',
    'POLVO_CACAO' => 'Polvo de cacao',
    'NIBS_CACAO' => 'Nibs de cacao',
];

$variedades = [
    'FINO_AROMA' => 'Fino de Aroma',
    'CCN51_GRADO_1' => 'CCN51 Grado 1',
    'CCN51_GRADO_2' => 'CCN51 Grado 2',
    'CCN51_GRADO_3' => 'CCN51 Grado 3',
];

$vista = strtolower(trim((string)($_GET['vista'] ?? $_POST['vista'] ?? 'recibidas')));
if (!in_array($vista, ['recibidas', 'enviadas'], true)) {
    $vista = 'recibidas';
}
$tipoMuestra = $vista === 'enviadas' ? 'ENVIADA' : 'RECIBIDA';
$filtroBusqueda = trim((string)($_GET['q'] ?? ''));

$proveedores = $tablaProveedoresExiste
    ? $db->fetchAll("SELECT id, codigo, nombre FROM proveedores WHERE activo = 1 AND COALESCE(es_categoria, 0) = 0 ORDER BY nombre ASC")
    : [];
$clientesActivos = ($tablaClientesExiste && $tablaMuestrasExiste)
    ? $db->fetchAll("SELECT id, codigo, nombre FROM clientes WHERE activo = 1 ORDER BY nombre ASC")
    : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablaMuestrasExiste) {
    verifyCSRF();
    $accion = trim((string)($_POST['action'] ?? ''));
    $tipoPost = strtoupper(trim((string)($_POST['tipo_muestra'] ?? $tipoMuestra)));

    if ($accion === 'save') {
        $muestraId = (int)($_POST['id'] ?? 0);
        $producto = trim((string)($_POST['producto'] ?? ''));
        $volumen = (float)($_POST['volumen'] ?? 0);
        $unidadVolumen = strtoupper(trim((string)($_POST['unidad_volumen'] ?? 'KG')));
        $variedad = trim((string)($_POST['variedad'] ?? ''));
        $origen = trim((string)($_POST['origen'] ?? ''));
        $destino = trim((string)($_POST['destino'] ?? ''));
        $fechaRecepcion = trim((string)($_POST['fecha_recepcion'] ?? ''));
        $fechaEnvio = trim((string)($_POST['fecha_envio'] ?? ''));
        $fechaArribo = trim((string)($_POST['fecha_arribo'] ?? ''));
        $enlaceLaboratorio = trim((string)($_POST['enlace_resultados_laboratorio'] ?? ''));
        $observaciones = trim((string)($_POST['observaciones'] ?? ''));
        $proveedorId = (int)($_POST['proveedor_id'] ?? 0);
        $clienteId = (int)($_POST['cliente_id'] ?? 0);

        if (!isset($productos[$producto])) {
            $errors[] = 'Seleccione un producto válido.';
        }
        if (!isset($variedades[$variedad])) {
            $errors[] = 'Seleccione una variedad válida.';
        }
        if ($volumen <= 0) {
            $errors[] = 'El volumen debe ser mayor que cero.';
        }
        if (!in_array($unidadVolumen, ['KG', 'QQ'], true)) {
            $errors[] = 'La unidad de volumen no es válida.';
        }
        if ($origen === '') {
            $errors[] = 'El origen es obligatorio.';
        }
        if ($enlaceLaboratorio !== '' && filter_var($enlaceLaboratorio, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'El enlace de resultados de laboratorio debe ser una URL válida.';
        }

        $proveedorNombre = null;
        $clienteNombre = null;

        if ($tipoPost === 'RECIBIDA') {
            if ($proveedorId <= 0) {
                $errors[] = 'Seleccione el proveedor de la muestra recibida.';
            } else {
                $proveedor = $db->fetch("SELECT id, nombre FROM proveedores WHERE id = ?", [$proveedorId]);
                if (!$proveedor) {
                    $errors[] = 'El proveedor seleccionado no existe.';
                } else {
                    $proveedorNombre = (string)$proveedor['nombre'];
                }
            }
            if ($fechaRecepcion === '') {
                $errors[] = 'La fecha de recepción es obligatoria.';
            }
        } else {
            if ($clienteId <= 0) {
                $errors[] = 'Seleccione el cliente de la muestra enviada.';
            } else {
                $cliente = $db->fetch("SELECT id, nombre FROM clientes WHERE id = ?", [$clienteId]);
                if (!$cliente) {
                    $errors[] = 'El cliente seleccionado no existe.';
                } else {
                    $clienteNombre = (string)$cliente['nombre'];
                }
            }
            if ($destino === '') {
                $errors[] = 'El destino es obligatorio para muestras enviadas.';
            }
            if ($fechaEnvio === '') {
                $errors[] = 'La fecha de envío es obligatoria.';
            }
            if ($fechaEnvio !== '' && $fechaArribo !== '' && strtotime($fechaArribo) < strtotime($fechaEnvio)) {
                $errors[] = 'La fecha de arribo no puede ser anterior a la fecha de envío.';
            }
        }

        if (empty($errors)) {
            $data = [
                'tipo_muestra' => $tipoPost,
                'proveedor_id' => $tipoPost === 'RECIBIDA' ? $proveedorId : null,
                'proveedor_nombre' => $tipoPost === 'RECIBIDA' ? $proveedorNombre : null,
                'cliente_id' => $tipoPost === 'ENVIADA' ? $clienteId : null,
                'cliente_nombre' => $tipoPost === 'ENVIADA' ? $clienteNombre : null,
                'producto' => $producto,
                'volumen' => $volumen,
                'unidad_volumen' => $unidadVolumen,
                'variedad' => $variedad,
                'origen' => $origen,
                'destino' => $tipoPost === 'ENVIADA' ? $destino : null,
                'fecha_recepcion' => $tipoPost === 'RECIBIDA' ? $fechaRecepcion : null,
                'fecha_envio' => $tipoPost === 'ENVIADA' ? $fechaEnvio : null,
                'fecha_arribo' => $tipoPost === 'ENVIADA' && $fechaArribo !== '' ? $fechaArribo : null,
                'enlace_resultados_laboratorio' => $enlaceLaboratorio !== '' ? $enlaceLaboratorio : null,
                'observaciones' => $observaciones !== '' ? $observaciones : null,
                'usuario_id' => Auth::id(),
            ];

            if ($muestraId > 0) {
                $db->update('muestras_comerciales', $data, 'id = :id', ['id' => $muestraId]);
                setFlash('success', 'Muestra actualizada correctamente.');
            } else {
                $db->insert('muestras_comerciales', $data);
                setFlash('success', 'Muestra registrada correctamente.');
            }

            $redirectVista = $tipoPost === 'ENVIADA' ? 'enviadas' : 'recibidas';
            redirect('/comercial/muestras.php?vista=' . $redirectVista);
        }
    }

    if ($accion === 'delete') {
        $muestraId = (int)($_POST['id'] ?? 0);
        if ($muestraId > 0) {
            $db->delete('muestras_comerciales', 'id = :id', ['id' => $muestraId]);
            setFlash('success', 'Muestra eliminada correctamente.');
        }
        redirect('/comercial/muestras.php?vista=' . $vista);
    }
}

$muestraEdicion = null;
if ($tablaMuestrasExiste && isset($_GET['edit'])) {
    $muestraEdicion = $db->fetch("SELECT * FROM muestras_comerciales WHERE id = ?", [(int)$_GET['edit']]);
    if ($muestraEdicion) {
        $vista = $muestraEdicion['tipo_muestra'] === 'ENVIADA' ? 'enviadas' : 'recibidas';
        $tipoMuestra = (string)$muestraEdicion['tipo_muestra'];
    }
}

$muestraFormValue = static function (string $key, string $default = '') use ($muestraEdicion): string {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists($key, $_POST)) {
        return trim((string)$_POST[$key]);
    }
    return isset($muestraEdicion[$key]) ? trim((string)$muestraEdicion[$key]) : $default;
};

$muestraFormInt = static function (string $key, int $default = 0) use ($muestraEdicion): int {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists($key, $_POST)) {
        return (int)$_POST[$key];
    }
    return isset($muestraEdicion[$key]) ? (int)$muestraEdicion[$key] : $default;
};

$stats = [
    'total_vista' => 0,
    'mes_vista' => 0,
    'total_general' => 0,
];
$muestras = [];

if ($tablaMuestrasExiste) {
    $campoFechaVista = $tipoMuestra === 'RECIBIDA' ? 'fecha_recepcion' : 'fecha_envio';
    $stats = [
        'total_vista' => (int)($db->fetch("SELECT COUNT(*) AS total FROM muestras_comerciales WHERE tipo_muestra = ?", [$tipoMuestra])['total'] ?? 0),
        'mes_vista' => (int)($db->fetch("SELECT COUNT(*) AS total FROM muestras_comerciales WHERE tipo_muestra = ? AND {$campoFechaVista} >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)", [$tipoMuestra])['total'] ?? 0),
        'total_general' => (int)($db->fetch("SELECT COUNT(*) AS total FROM muestras_comerciales")['total'] ?? 0),
    ];

    $where = ['m.tipo_muestra = :tipo'];
    $params = ['tipo' => $tipoMuestra];
    if ($filtroBusqueda !== '') {
        $where[] = '(COALESCE(m.proveedor_nombre, \'\') LIKE :q
            OR COALESCE(m.cliente_nombre, \'\') LIKE :q
            OR m.origen LIKE :q
            OR COALESCE(m.destino, \'\') LIKE :q)';
        $params['q'] = '%' . $filtroBusqueda . '%';
    }

    $muestras = $db->fetchAll(
        "SELECT m.*,
                u.nombre AS usuario_nombre
         FROM muestras_comerciales m
         LEFT JOIN usuarios u ON u.id = m.usuario_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY COALESCE(m.fecha_envio, m.fecha_recepcion) DESC, m.id DESC",
        $params
    );
}

$pageTitle = 'Muestras';
$pageSubtitle = 'Registro de muestras recibidas y enviadas';

ob_start();
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-primary">Muestras</h1>
            <p class="text-warmgray">Controle las muestras recibidas de proveedores y enviadas a clientes.</p>
        </div>
    </div>

    <?php if (!$tablaMuestrasExiste || !$tablaClientesExiste): ?>
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

    <div class="flex flex-wrap items-center gap-3">
        <a href="<?= APP_URL ?>/comercial/muestras.php?vista=recibidas"
           class="btn <?= $vista === 'recibidas' ? 'btn-primary' : 'btn-outline' ?>">Muestras recibidas</a>
        <a href="<?= APP_URL ?>/comercial/muestras.php?vista=enviadas"
           class="btn <?= $vista === 'enviadas' ? 'btn-primary' : 'btn-outline' ?>">Muestras enviadas</a>
    </div>

    <?php if ($vista === 'enviadas' && empty($clientesActivos)): ?>
        <div class="card border border-amber-200 bg-amber-50/70">
            <div class="card-body">
                <p class="text-sm text-amber-800">
                    Primero registre clientes activos en <a href="<?= APP_URL ?>/comercial/clientes.php" class="underline font-medium">Clientes</a> para poder cargar muestras enviadas.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="card">
            <div class="card-body">
                <p class="text-sm text-warmgray">Total <?= $vista === 'recibidas' ? 'recibidas' : 'enviadas' ?></p>
                <p class="text-3xl font-bold text-primary mt-1"><?= number_format($stats['total_vista']) ?></p>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <p class="text-sm text-warmgray">Últimos 30 días</p>
                <p class="text-3xl font-bold text-cyan-700 mt-1"><?= number_format($stats['mes_vista']) ?></p>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <p class="text-sm text-warmgray">Total general</p>
                <p class="text-3xl font-bold text-emerald-600 mt-1"><?= number_format($stats['total_general']) ?></p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div class="xl:col-span-1">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><?= $muestraEdicion ? 'Editar muestra' : ($vista === 'recibidas' ? 'Nueva muestra recibida' : 'Nueva muestra enviada') ?></h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="space-y-4">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?= (int)($muestraEdicion['id'] ?? 0) ?>">
                        <input type="hidden" name="vista" value="<?= htmlspecialchars($vista) ?>">
                        <input type="hidden" name="tipo_muestra" value="<?= htmlspecialchars($tipoMuestra) ?>">

                        <?php if ($tipoMuestra === 'RECIBIDA'): ?>
                            <div class="form-group">
                                <label class="form-label required">Nombre de proveedor</label>
                                <select name="proveedor_id" class="form-control form-select" required>
                                    <option value="">Seleccione</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?= (int)$proveedor['id'] ?>" <?= $muestraFormInt('proveedor_id') === (int)$proveedor['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string)$proveedor['codigo']) ?> - <?= htmlspecialchars((string)$proveedor['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <div class="form-group">
                                <label class="form-label required">Nombre del cliente</label>
                                <select name="cliente_id" class="form-control form-select" required>
                                    <option value="">Seleccione</option>
                                    <?php foreach ($clientesActivos as $cliente): ?>
                                        <option value="<?= (int)$cliente['id'] ?>" <?= $muestraFormInt('cliente_id') === (int)$cliente['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string)$cliente['codigo']) ?> - <?= htmlspecialchars((string)$cliente['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label required">Producto</label>
                            <select name="producto" class="form-control form-select" required>
                                <option value="">Seleccione</option>
                                <?php foreach ($productos as $codigo => $label): ?>
                                    <option value="<?= htmlspecialchars($codigo) ?>" <?= $muestraFormValue('producto') === $codigo ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-group">
                                <label class="form-label required">Volumen</label>
                                <input type="number" step="0.01" min="0.01" name="volumen" class="form-control" required
                                       value="<?= htmlspecialchars($muestraFormValue('volumen')) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Unidad</label>
                                <select name="unidad_volumen" class="form-control form-select" required>
                                    <option value="KG" <?= $muestraFormValue('unidad_volumen', 'KG') === 'KG' ? 'selected' : '' ?>>KG</option>
                                    <option value="QQ" <?= $muestraFormValue('unidad_volumen', 'KG') === 'QQ' ? 'selected' : '' ?>>QQ</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Variedad</label>
                            <select name="variedad" class="form-control form-select" required>
                                <option value="">Seleccione</option>
                                <?php foreach ($variedades as $codigo => $label): ?>
                                    <option value="<?= htmlspecialchars($codigo) ?>" <?= $muestraFormValue('variedad') === $codigo ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Origen</label>
                            <input type="text" name="origen" class="form-control" required
                                   placeholder="Provincia o comunidad"
                                   value="<?= htmlspecialchars($muestraFormValue('origen')) ?>">
                        </div>

                        <?php if ($tipoMuestra === 'RECIBIDA'): ?>
                            <div class="form-group">
                                <label class="form-label required">Fecha de recepción</label>
                                <input type="date" name="fecha_recepcion" class="form-control" required
                                       value="<?= htmlspecialchars($muestraFormValue('fecha_recepcion', date('Y-m-d'))) ?>">
                            </div>
                        <?php else: ?>
                            <div class="form-group">
                                <label class="form-label required">Destino</label>
                                <input type="text" name="destino" class="form-control" required
                                       placeholder="Ciudad y país"
                                       value="<?= htmlspecialchars($muestraFormValue('destino')) ?>">
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-group">
                                    <label class="form-label required">Fecha de envío</label>
                                    <input type="date" name="fecha_envio" class="form-control" required
                                           value="<?= htmlspecialchars($muestraFormValue('fecha_envio', date('Y-m-d'))) ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Fecha de arribo</label>
                                    <input type="date" name="fecha_arribo" class="form-control"
                                           value="<?= htmlspecialchars($muestraFormValue('fecha_arribo')) ?>">
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label">Link para resultados de análisis de laboratorio</label>
                            <input type="url" name="enlace_resultados_laboratorio" class="form-control"
                                   placeholder="https://drive.google.com/..."
                                   value="<?= htmlspecialchars($muestraFormValue('enlace_resultados_laboratorio')) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Observaciones</label>
                            <textarea name="observaciones" class="form-control" rows="3"
                                      placeholder="Notas adicionales"><?= htmlspecialchars($muestraFormValue('observaciones')) ?></textarea>
                        </div>

                        <div class="flex items-center gap-3 pt-2">
                            <button type="submit" class="btn btn-primary">
                                <?= $muestraEdicion ? 'Actualizar muestra' : 'Guardar muestra' ?>
                            </button>
                            <?php if ($muestraEdicion): ?>
                                <a href="<?= APP_URL ?>/comercial/muestras.php?vista=<?= htmlspecialchars($vista) ?>" class="btn btn-outline">Cancelar</a>
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
                        <h3 class="card-title">Listado de <?= $vista === 'recibidas' ? 'muestras recibidas' : 'muestras enviadas' ?></h3>
                        <form method="GET" class="flex flex-wrap items-center gap-3">
                            <input type="hidden" name="vista" value="<?= htmlspecialchars($vista) ?>">
                            <input type="text" name="q" class="form-control min-w-[220px]"
                                   placeholder="Buscar por proveedor, cliente, origen o destino..."
                                   value="<?= htmlspecialchars($filtroBusqueda) ?>">
                            <button type="submit" class="btn btn-primary">Filtrar</button>
                            <?php if ($filtroBusqueda !== ''): ?>
                                <a href="<?= APP_URL ?>/comercial/muestras.php?vista=<?= htmlspecialchars($vista) ?>" class="btn btn-outline">Limpiar</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><?= $vista === 'recibidas' ? 'Proveedor' : 'Cliente' ?></th>
                                <th>Producto</th>
                                <th>Volumen</th>
                                <th>Variedad</th>
                                <th>Origen</th>
                                <th><?= $vista === 'recibidas' ? 'Fecha recepción' : 'Destino / envío' ?></th>
                                <th>Resultados</th>
                                <th>Registrado por</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($muestras)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-10 text-warmgray">No se encontraron muestras registradas.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($muestras as $muestra): ?>
                                    <tr>
                                        <td class="font-medium">
                                            <?= htmlspecialchars((string)($vista === 'recibidas' ? $muestra['proveedor_nombre'] : $muestra['cliente_nombre'])) ?>
                                        </td>
                                        <td><?= htmlspecialchars($productos[(string)$muestra['producto']] ?? (string)$muestra['producto']) ?></td>
                                        <td><?= number_format((float)$muestra['volumen'], 2) ?> <?= htmlspecialchars((string)$muestra['unidad_volumen']) ?></td>
                                        <td><?= htmlspecialchars($variedades[(string)$muestra['variedad']] ?? (string)$muestra['variedad']) ?></td>
                                        <td><?= htmlspecialchars((string)$muestra['origen']) ?></td>
                                        <td>
                                            <?php if ($vista === 'recibidas'): ?>
                                                <?= Helpers::formatDate((string)$muestra['fecha_recepcion']) ?>
                                            <?php else: ?>
                                                <div><?= htmlspecialchars((string)($muestra['destino'] ?? '')) ?></div>
                                                <div class="text-xs text-warmgray">
                                                    Envío: <?= Helpers::formatDate((string)$muestra['fecha_envio']) ?>
                                                    <?php if (!empty($muestra['fecha_arribo'])): ?>
                                                        · Arribo: <?= Helpers::formatDate((string)$muestra['fecha_arribo']) ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($muestra['enlace_resultados_laboratorio'])): ?>
                                                <a href="<?= htmlspecialchars((string)$muestra['enlace_resultados_laboratorio']) ?>"
                                                   target="_blank" rel="noopener noreferrer"
                                                   class="text-primary hover:underline">Ver enlace</a>
                                            <?php else: ?>
                                                <span class="text-warmgray">Sin enlace</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string)($muestra['usuario_nombre'] ?? 'Sistema')) ?></td>
                                        <td>
                                            <div class="flex items-center justify-center gap-2">
                                                <a href="<?= APP_URL ?>/comercial/muestras.php?vista=<?= htmlspecialchars($vista) ?>&edit=<?= (int)$muestra['id'] ?>"
                                                   class="btn btn-outline px-3 py-2">Editar</a>
                                                <form method="POST" onsubmit="return confirm('¿Desea eliminar esta muestra?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int)$muestra['id'] ?>">
                                                    <input type="hidden" name="vista" value="<?= htmlspecialchars($vista) ?>">
                                                    <button type="submit" class="btn btn-outline px-3 py-2">Eliminar</button>
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
