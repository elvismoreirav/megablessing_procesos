<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Ver detalle de Calidad de salida
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();
$tablaExiste = (bool)$db->fetch("SHOW TABLES LIKE 'registros_calidad_salida'");

if (!$tablaExiste) {
    setFlash('warning', 'Falta ejecutar el patch para habilitar Calidad de salida.');
    redirect('/calidad-salida/index.php');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('error', 'ID de registro no válido.');
    redirect('/calidad-salida/index.php');
}

$registro = $db->fetch("\n    SELECT rcs.*,\n           l.codigo AS lote_codigo, l.estado_proceso,\n           p.nombre AS proveedor, p.codigo AS proveedor_codigo,\n           v.nombre AS variedad,\n           u.nombre AS usuario_nombre\n    FROM registros_calidad_salida rcs\n    JOIN lotes l ON l.id = rcs.lote_id\n    JOIN proveedores p ON p.id = l.proveedor_id\n    JOIN variedades v ON v.id = l.variedad_id\n    LEFT JOIN usuarios u ON u.id = rcs.usuario_id\n    WHERE rcs.id = :id\n", ['id' => $id]);

if (!$registro) {
    setFlash('error', 'Registro de calidad de salida no encontrado.');
    redirect('/calidad-salida/index.php');
}

$certificaciones = [];
if (!empty($registro['certificaciones'])) {
    $certificaciones = json_decode((string)$registro['certificaciones'], true) ?: [];
}

if (empty($certificaciones) && !empty($registro['certificaciones_texto'])) {
    $certificaciones = array_filter(array_map('trim', explode(',', (string)$registro['certificaciones_texto'])));
}

$labelsCert = [
    'ORGANICA' => 'Orgánica',
    'COMERCIO_JUSTO' => 'Comercio Justo',
    'EUDR' => 'EUDR',
    'OTRAS' => 'Otras',
    'NO_APLICA' => 'No aplica',
];

$certificacionesRender = [];
foreach ($certificaciones as $cert) {
    $key = strtoupper(trim((string)$cert));
    $certificacionesRender[] = $labelsCert[$key] ?? $cert;
}
if (empty($certificacionesRender)) {
    $certificacionesRender[] = 'Sin registro';
}

$gradoLabel = match ((string)$registro['grado_calidad']) {
    'GRADO_1' => 'Grado 1',
    'GRADO_2' => 'Grado 2',
    'GRADO_3' => 'Grado 3',
    default => 'No aplica',
};

$tablaEmpaquetadoExiste = Helpers::ensureEmpaquetadoTable();
$empaquetadoExistente = null;
if ($tablaEmpaquetadoExiste) {
    $empaquetadoExistente = $db->fetch("SELECT id, fecha_empaquetado FROM registros_empaquetado WHERE lote_id = :lote_id ORDER BY id DESC LIMIT 1", ['lote_id' => $registro['lote_id']]);
}

$accionSiguiente = [
    'titulo' => 'Siguiente paso',
    'descripcion' => 'Este lote ya registró calidad de salida.',
    'url' => APP_URL . '/empaquetado/index.php',
    'label' => 'Ir a Empaquetado',
    'disabled' => false,
];

if (!$tablaEmpaquetadoExiste) {
    $accionSiguiente = [
        'titulo' => 'Módulo de Empaquetado no disponible',
        'descripcion' => 'No se pudo crear automáticamente la tabla de empaquetado. Ejecute el patch de base de datos del módulo.',
        'url' => '#',
        'label' => 'Patch pendiente',
        'disabled' => true,
    ];
} elseif ($empaquetadoExistente) {
    $accionSiguiente = [
        'titulo' => 'Empaquetado ya iniciado',
        'descripcion' => 'El lote ya tiene un registro de empaquetado.',
        'url' => APP_URL . '/empaquetado/registrar.php?id=' . (int)$empaquetadoExistente['id'],
        'label' => 'Ver empaquetado',
        'disabled' => false,
    ];
} elseif ((string)$registro['estado_proceso'] === 'EMPAQUETADO') {
    $accionSiguiente = [
        'titulo' => 'Lote listo para empaquetado',
        'descripcion' => 'Puede iniciar la ficha de empaquetado ahora mismo.',
        'url' => APP_URL . '/empaquetado/crear.php?lote_id=' . (int)$registro['lote_id'],
        'label' => 'Iniciar empaquetado',
        'disabled' => false,
    ];
}

$pageTitle = 'Calidad de salida: ' . $registro['lote_codigo'];
$pageSubtitle = 'Detalle del registro';

ob_start();
?>

<div class="max-w-5xl mx-auto">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h2 class="text-2xl font-bold text-primary"><?= htmlspecialchars($registro['lote_codigo']) ?></h2>
            <p class="text-warmgray"><?= htmlspecialchars($registro['proveedor']) ?> · <?= htmlspecialchars($registro['variedad']) ?></p>
        </div>
        <a href="<?= APP_URL ?>/calidad-salida/index.php" class="btn btn-outline">Volver al listado</a>
    </div>

    <div class="card mb-6 border border-emerald-200 bg-emerald-50/70">
        <div class="card-body">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Flujo guiado</p>
            <h3 class="text-lg font-semibold text-emerald-900 mt-1"><?= htmlspecialchars($accionSiguiente['titulo']) ?></h3>
            <p class="text-sm text-emerald-800 mt-1"><?= htmlspecialchars($accionSiguiente['descripcion']) ?></p>
            <div class="mt-4">
                <a href="<?= htmlspecialchars($accionSiguiente['url']) ?>"
                   class="btn <?= $accionSiguiente['disabled'] ? 'btn-outline opacity-50 cursor-not-allowed pointer-events-none' : 'btn-primary' ?>">
                    <?= htmlspecialchars($accionSiguiente['label']) ?>
                </a>
            </div>
        </div>
    </div>

    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Datos de salida</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="form-label">Categoría proveedor</label>
                    <div class="form-control bg-olive/10"><?= htmlspecialchars($registro['categoria_proveedor']) ?></div>
                </div>
                <div>
                    <label class="form-label">Fichas que conforman el lote</label>
                    <div class="form-control bg-olive/10"><?= htmlspecialchars($registro['fichas_conforman_lote']) ?></div>
                </div>
                <div>
                    <label class="form-label">Fecha de entrada</label>
                    <div class="form-control bg-olive/10"><?= Helpers::formatDate($registro['fecha_entrada']) ?></div>
                </div>
                <div>
                    <label class="form-label">Fecha de registro</label>
                    <div class="form-control bg-olive/10"><?= Helpers::formatDate($registro['fecha_registro']) ?></div>
                </div>
                <div>
                    <label class="form-label">Variedad</label>
                    <div class="form-control bg-olive/10 font-semibold"><?= htmlspecialchars($registro['variedad']) ?></div>
                </div>
                <div>
                    <label class="form-label">Grado de calidad</label>
                    <div class="form-control bg-olive/10"><?= htmlspecialchars($gradoLabel) ?></div>
                </div>
                <div>
                    <label class="form-label">Estado del producto</label>
                    <div class="form-control bg-olive/10"><?= htmlspecialchars($registro['estado_producto']) ?></div>
                </div>
                <div>
                    <label class="form-label">Estado de fermentación</label>
                    <div class="form-control bg-olive/10"><?= htmlspecialchars($registro['estado_fermentacion']) ?></div>
                </div>
            </div>

            <div class="mt-6">
                <label class="form-label">Certificaciones</label>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($certificacionesRender as $cert): ?>
                        <span class="badge badge-primary"><?= htmlspecialchars($cert) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($registro['otra_certificacion'])): ?>
                    <p class="text-sm text-warmgray mt-2">Detalle otras: <?= htmlspecialchars($registro['otra_certificacion']) ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($registro['observaciones'])): ?>
            <div class="mt-6">
                <label class="form-label">Observaciones</label>
                <div class="form-control bg-olive/10 min-h-[90px]"><?= nl2br(htmlspecialchars($registro['observaciones'])) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div>
                    <p class="text-warmgray">Lote</p>
                    <p class="font-semibold"><?= htmlspecialchars($registro['lote_codigo']) ?></p>
                </div>
                <div>
                    <p class="text-warmgray">Estado actual del lote</p>
                    <p><?= Helpers::getEstadoProcesoBadge($registro['estado_proceso']) ?></p>
                </div>
                <div>
                    <p class="text-warmgray">Registrado por</p>
                    <p class="font-semibold"><?= htmlspecialchars($registro['usuario_nombre'] ?? 'Sistema') ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
