<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Registrar Calidad de salida
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();
$tablaExiste = (bool)$db->fetch("SHOW TABLES LIKE 'registros_calidad_salida'");
$errors = [];

$normalizar = static function (string $valor): string {
    $valor = strtoupper(trim($valor));
    $valor = strtr($valor, [
        'Á' => 'A',
        'É' => 'E',
        'Í' => 'I',
        'Ó' => 'O',
        'Ú' => 'U',
    ]);
    return preg_replace('/\s+/', ' ', $valor);
};

$esCCN51 = static function (string $variedad) use ($normalizar): bool {
    $norm = $normalizar($variedad);
    return strpos($norm, 'CCN51') !== false || strpos($norm, 'CCN-51') !== false || strpos($norm, 'CCN 51') !== false;
};

$esFinoAroma = static function (string $variedad) use ($normalizar): bool {
    $norm = $normalizar($variedad);
    return strpos($norm, 'FINO') !== false || strpos($norm, 'AROMA') !== false || strpos($norm, 'NACIONAL') !== false;
};

$normalizarEstadoProducto = static function (?string $codigo, ?string $nombre) use ($normalizar): string {
    $cod = strtoupper(trim((string)$codigo));
    if (in_array($cod, ['SC', 'SS', 'BA'], true)) {
        return $cod;
    }
    if ($cod === 'ES') {
        return 'BA';
    }

    $nom = $normalizar((string)$nombre);
    if (strpos($nom, 'SEMI') !== false) {
        return 'SS';
    }
    if (strpos($nom, 'SECO') !== false) {
        return 'SC';
    }
    if (strpos($nom, 'BABA') !== false || strpos($nom, 'ESCURRIDO') !== false) {
        return 'BA';
    }

    return $cod !== '' ? $cod : trim((string)$nombre);
};

$normalizarEstadoFermentacion = static function (?string $codigo, ?string $nombre) use ($normalizar): string {
    $cod = strtoupper(trim((string)$codigo));
    if (in_array($cod, ['F', 'SF'], true)) {
        return $cod;
    }

    $nom = $normalizar((string)$nombre);
    if ($nom === '') {
        return 'SF';
    }
    if (strpos($nom, 'SIN') !== false) {
        return 'SF';
    }

    return 'F';
};

$opcionesCertificaciones = [
    'ORGANICA' => 'Orgánica',
    'COMERCIO_JUSTO' => 'Comercio Justo',
    'EUDR' => 'EUDR',
    'OTRAS' => 'Otras',
    'NO_APLICA' => 'No aplica',
];

$fetchLoteInfo = static function (Database $db, int $loteId) {
    return $db->fetch("\n        SELECT l.id, l.codigo, l.fecha_entrada, l.estado_proceso,\n               p.nombre AS proveedor, p.codigo AS proveedor_codigo,\n               p.tipo AS proveedor_tipo, p.categoria AS proveedor_categoria,\n               v.nombre AS variedad,\n               ep.nombre AS estado_producto, ep.codigo AS estado_producto_codigo,\n               ef.nombre AS estado_fermentacion, ef.codigo AS estado_fermentacion_codigo,\n               fr.id AS ficha_id, fr.codificacion AS ficha_codificacion\n        FROM lotes l\n        JOIN proveedores p ON p.id = l.proveedor_id\n        JOIN variedades v ON v.id = l.variedad_id\n        JOIN estados_producto ep ON ep.id = l.estado_producto_id\n        LEFT JOIN estados_fermentacion ef ON ef.id = l.estado_fermentacion_id\n        LEFT JOIN fichas_registro fr ON fr.id = (\n            SELECT fr2.id\n            FROM fichas_registro fr2\n            WHERE fr2.lote_id = l.id\n            ORDER BY fr2.id DESC\n            LIMIT 1\n        )\n        WHERE l.id = :id\n          AND l.estado_proceso = 'CALIDAD_SALIDA'\n    ", ['id' => $loteId]);
};

$loteId = (int)($_GET['lote_id'] ?? $_POST['lote_id'] ?? 0);
$loteInfo = null;

if ($tablaExiste && $loteId > 0) {
    $loteInfo = $fetchLoteInfo($db, $loteId);

    if (!$loteInfo) {
        setFlash('warning', 'Lote no válido para registrar calidad de salida.');
        redirect('/calidad-salida/index.php');
    }

    $registroExistente = $db->fetch("SELECT id FROM registros_calidad_salida WHERE lote_id = :lote_id", ['lote_id' => $loteId]);
    if ($registroExistente) {
        setFlash('info', 'Este lote ya tiene una ficha de calidad de salida registrada.');
        redirect('/calidad-salida/ver.php?id=' . (int)$registroExistente['id']);
    }
}

$lotesDisponibles = [];
if ($tablaExiste) {
    $lotesDisponibles = $db->fetchAll("\n        SELECT l.id, l.codigo, p.nombre AS proveedor, p.codigo AS proveedor_codigo, v.nombre AS variedad\n        FROM lotes l\n        JOIN proveedores p ON p.id = l.proveedor_id\n        JOIN variedades v ON v.id = l.variedad_id\n        WHERE l.estado_proceso = 'CALIDAD_SALIDA'\n          AND NOT EXISTS (SELECT 1 FROM registros_calidad_salida rcs WHERE rcs.lote_id = l.id)\n        ORDER BY l.fecha_entrada DESC, l.id DESC\n    ");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablaExiste) {
    verifyCSRF();

    $loteId = (int)($_POST['lote_id'] ?? 0);
    $fechaRegistro = trim((string)($_POST['fecha_registro'] ?? ''));
    $fichasConforman = trim((string)($_POST['fichas_conforman_lote'] ?? ''));
    $gradoCalidad = trim((string)($_POST['grado_calidad'] ?? ''));
    $certificacionesInput = $_POST['certificaciones'] ?? [];
    $otraCertificacion = trim((string)($_POST['otra_certificacion'] ?? ''));
    $observaciones = trim((string)($_POST['observaciones'] ?? ''));

    if (!is_array($certificacionesInput)) {
        $certificacionesInput = [];
    }

    $loteInfo = $fetchLoteInfo($db, $loteId);

    if (!$loteId || !$loteInfo) {
        $errors[] = 'Debe seleccionar un lote válido en estado Calidad de salida.';
    }

    if ($fechaRegistro === '') {
        $errors[] = 'La fecha de registro es obligatoria.';
    }

    if ($fichasConforman === '') {
        $errors[] = 'Debe indicar las fichas de registro que conforman el lote.';
    }

    $certificacionesSeleccionadas = array_values(array_intersect(array_keys($opcionesCertificaciones), $certificacionesInput));
    if (in_array('NO_APLICA', $certificacionesSeleccionadas, true)) {
        $certificacionesSeleccionadas = ['NO_APLICA'];
        $otraCertificacion = '';
    }
    if (empty($certificacionesSeleccionadas)) {
        $errors[] = 'Seleccione al menos una certificación del lote.';
    }

    if ($loteInfo) {
        $variedadNombre = (string)$loteInfo['variedad'];
        $variedadEsCCN51 = $esCCN51($variedadNombre);
        $variedadEsFinoAroma = $esFinoAroma($variedadNombre);

        if ($variedadEsCCN51) {
            if (!in_array($gradoCalidad, ['GRADO_1', 'GRADO_2', 'GRADO_3'], true)) {
                $errors[] = 'Para la variedad CCN51 debe seleccionar grado de calidad 1, 2 o 3.';
            }
        } else {
            $gradoCalidad = 'NO_APLICA';
        }

        if (!in_array('NO_APLICA', $certificacionesSeleccionadas, true) && !$variedadEsCCN51 && !$variedadEsFinoAroma) {
            $errors[] = 'Las certificaciones aplican para lotes CCN51 o Fino de Aroma.';
        }
    }

    if (!in_array('NO_APLICA', $certificacionesSeleccionadas, true) && in_array('OTRAS', $certificacionesSeleccionadas, true) && $otraCertificacion === '') {
        $errors[] = 'Si selecciona "Otras", detalle la certificación adicional.';
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $registroExistente = $db->fetch("SELECT id FROM registros_calidad_salida WHERE lote_id = :lote_id", ['lote_id' => $loteId]);
            if ($registroExistente) {
                throw new Exception('Este lote ya tiene una ficha de calidad de salida registrada.');
            }

            $categoriaProveedor = trim((string)($loteInfo['proveedor_categoria'] ?? ''));
            if ($categoriaProveedor === '') {
                $categoriaProveedor = trim((string)($loteInfo['proveedor_tipo'] ?? ''));
            }

            $estadoProducto = $normalizarEstadoProducto(
                (string)($loteInfo['estado_producto_codigo'] ?? ''),
                (string)($loteInfo['estado_producto'] ?? '')
            );
            $estadoFermentacion = $normalizarEstadoFermentacion(
                (string)($loteInfo['estado_fermentacion_codigo'] ?? ''),
                (string)($loteInfo['estado_fermentacion'] ?? '')
            );

            $certificacionesTexto = [];
            foreach ($certificacionesSeleccionadas as $cert) {
                $certificacionesTexto[] = $opcionesCertificaciones[$cert] ?? $cert;
            }
            if (in_array('OTRAS', $certificacionesSeleccionadas, true) && $otraCertificacion !== '') {
                $certificacionesTexto[] = $otraCertificacion;
            }

            $registroId = (int)$db->insert('registros_calidad_salida', [
                'lote_id' => $loteId,
                'fecha_registro' => $fechaRegistro,
                'fichas_conforman_lote' => $fichasConforman,
                'categoria_proveedor' => $categoriaProveedor,
                'fecha_entrada' => $loteInfo['fecha_entrada'],
                'variedad' => $loteInfo['variedad'],
                'grado_calidad' => $gradoCalidad,
                'estado_producto' => $estadoProducto,
                'estado_fermentacion' => $estadoFermentacion,
                'certificaciones' => json_encode($certificacionesSeleccionadas, JSON_UNESCAPED_UNICODE),
                'certificaciones_texto' => implode(', ', $certificacionesTexto),
                'otra_certificacion' => $otraCertificacion !== '' ? $otraCertificacion : null,
                'observaciones' => $observaciones !== '' ? $observaciones : null,
                'usuario_id' => Auth::id(),
            ]);

            $db->update('lotes', [
                'estado_proceso' => 'EMPAQUETADO',
            ], 'id = :id', ['id' => $loteId]);

            Helpers::logHistory(
                $loteId,
                'CALIDAD_SALIDA',
                'Calidad de salida registrada. Certificaciones: ' . implode(', ', $certificacionesTexto)
            );

            Helpers::logHistory(
                $loteId,
                'EMPAQUETADO',
                'Lote habilitado para empaquetado tras registrar calidad de salida.'
            );

            $db->commit();

            setFlash('success', 'Calidad de salida registrada. El lote fue enviado a Empaquetado.');
            redirect('/calidad-salida/ver.php?id=' . $registroId);
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

$fechaRegistroDefault = $_POST['fecha_registro'] ?? date('Y-m-d');
$valorFichas = $_POST['fichas_conforman_lote'] ?? '';
if ($valorFichas === '' && $loteInfo && !empty($loteInfo['ficha_id'])) {
    $valorFichas = 'RE-' . str_pad((string)$loteInfo['ficha_id'], 5, '0', STR_PAD_LEFT);
}

$variedadActual = (string)($loteInfo['variedad'] ?? '');
$variedadEsCCN51 = $loteInfo ? $esCCN51($variedadActual) : false;
$variedadEsFinoAroma = $loteInfo ? $esFinoAroma($variedadActual) : false;
$estadoProductoLote = $loteInfo
    ? $normalizarEstadoProducto((string)($loteInfo['estado_producto_codigo'] ?? ''), (string)($loteInfo['estado_producto'] ?? ''))
    : '';
$estadoFermentacionLote = $loteInfo
    ? $normalizarEstadoFermentacion((string)($loteInfo['estado_fermentacion_codigo'] ?? ''), (string)($loteInfo['estado_fermentacion'] ?? ''))
    : '';

$pageTitle = 'Registrar Calidad de salida';
$pageSubtitle = 'Validación final del lote previo a empaquetado';

ob_start();
?>

<?php if (!$tablaExiste): ?>
    <div class="card border border-amber-200 bg-amber-50/70">
        <div class="card-body">
            <h3 class="text-lg font-semibold text-amber-900 mb-2">Módulo pendiente de base de datos</h3>
            <p class="text-sm text-amber-800 mb-4">
                Para habilitar este formulario, ejecute el patch <code>database/patch_calidad_salida.sql</code>.
            </p>
            <a href="<?= APP_URL ?>/calidad-salida/index.php" class="btn btn-outline">Volver al listado</a>
        </div>
    </div>
<?php else: ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-6">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div>
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (($_GET['from'] ?? '') === 'prueba-corte'): ?>
    <div class="card mb-6 border border-emerald-200 bg-emerald-50/60">
        <div class="card-body">
            <p class="text-sm font-medium text-emerald-800">
                Prueba de corte completada. Continúe con esta ficha para habilitar el lote en Empaquetado.
            </p>
        </div>
    </div>
<?php endif; ?>

<form method="POST" class="max-w-5xl">
    <?= csrfField() ?>

    <!-- Selección de lote -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Información del lote</h3>
        </div>
        <div class="card-body">
            <?php if ($loteInfo): ?>
                <input type="hidden" name="lote_id" value="<?= (int)$loteInfo['id'] ?>">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="form-label">Lote</label>
                        <div class="form-control bg-olive/10 font-semibold text-primary"><?= htmlspecialchars($loteInfo['codigo']) ?></div>
                    </div>
                    <div>
                        <label class="form-label">Proveedor</label>
                        <div class="form-control bg-olive/10">
                            <span class="font-semibold text-primary"><?= htmlspecialchars($loteInfo['proveedor_codigo']) ?></span>
                            - <?= htmlspecialchars($loteInfo['proveedor']) ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Categoría proveedor</label>
                        <div class="form-control bg-olive/10">
                            <?= htmlspecialchars($loteInfo['proveedor_categoria'] ?: $loteInfo['proveedor_tipo']) ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Fecha de entrada</label>
                        <div class="form-control bg-olive/10"><?= Helpers::formatDate($loteInfo['fecha_entrada']) ?></div>
                    </div>
                    <div>
                        <label class="form-label">Variedad</label>
                        <div class="form-control bg-olive/10 font-semibold"><?= htmlspecialchars($loteInfo['variedad']) ?></div>
                    </div>
                    <div>
                        <label class="form-label">Estado del producto</label>
                        <div class="form-control bg-olive/10">
                            <?= htmlspecialchars($estadoProductoLote) ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Estado de fermentación</label>
                        <div class="form-control bg-olive/10">
                            <?= htmlspecialchars($estadoFermentacionLote) ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label class="form-label required">Seleccionar lote</label>
                    <select class="form-control form-select" onchange="if (this.value) { window.location.href = '<?= APP_URL ?>/calidad-salida/crear.php?lote_id=' + this.value; }">
                        <option value="">-- Seleccione un lote --</option>
                        <?php foreach ($lotesDisponibles as $lote): ?>
                            <option value="<?= (int)$lote['id'] ?>" <?= ($loteId === (int)$lote['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lote['codigo']) ?> - <?= htmlspecialchars($lote['proveedor']) ?> (<?= htmlspecialchars($lote['variedad']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-warmgray mt-1">Solo se muestran lotes en estado Calidad de salida.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($loteInfo): ?>
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Ficha de Calidad de salida</h3>
        </div>
        <div class="card-body space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="form-group">
                    <label class="form-label required">Fecha de registro</label>
                    <input type="date" name="fecha_registro" class="form-control" required
                           value="<?= htmlspecialchars($fechaRegistroDefault) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label required">Fichas de registro que conforman el lote</label>
                    <input type="text" name="fichas_conforman_lote" class="form-control" required
                           placeholder="Ej: RE-00012; RE-00031"
                           value="<?= htmlspecialchars($valorFichas) ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label <?= $variedadEsCCN51 ? 'required' : '' ?>">Grado de calidad</label>
                <select name="grado_calidad" class="form-control form-select" <?= $variedadEsCCN51 ? 'required' : '' ?> <?= $variedadEsCCN51 ? '' : 'disabled' ?>>
                    <option value="">Seleccione</option>
                    <option value="GRADO_1" <?= ($_POST['grado_calidad'] ?? '') === 'GRADO_1' ? 'selected' : '' ?>>Grado 1</option>
                    <option value="GRADO_2" <?= ($_POST['grado_calidad'] ?? '') === 'GRADO_2' ? 'selected' : '' ?>>Grado 2</option>
                    <option value="GRADO_3" <?= ($_POST['grado_calidad'] ?? '') === 'GRADO_3' ? 'selected' : '' ?>>Grado 3</option>
                </select>
                <?php if ($variedadEsCCN51): ?>
                    <p class="text-xs text-warmgray mt-1">Para CCN51 es obligatorio seleccionar grado de calidad.</p>
                <?php else: ?>
                    <input type="hidden" name="grado_calidad" value="NO_APLICA">
                    <p class="text-xs text-warmgray mt-1">No aplica grado 1/2/3 para esta variedad.</p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label required">Certificaciones del lote</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <?php
                    $certSel = $_POST['certificaciones'] ?? [];
                    if (!is_array($certSel)) {
                        $certSel = [];
                    }
                    foreach ($opcionesCertificaciones as $key => $label):
                    ?>
                        <label class="flex items-center gap-2 p-3 border rounded-lg hover:bg-olive/10">
                            <input type="checkbox" name="certificaciones[]" value="<?= $key ?>" class="w-4 h-4"
                                   <?= in_array($key, $certSel, true) ? 'checked' : '' ?> onchange="toggleOtraCertificacion()">
                            <span class="text-sm font-medium"><?= htmlspecialchars($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-warmgray mt-2">Puede seleccionar una o varias certificaciones, o marcar "No aplica".</p>
            </div>

            <div class="form-group" id="otra_certificacion_group" style="display: none;">
                <label class="form-label required">Detalle de "Otras" certificaciones</label>
                <input type="text" name="otra_certificacion" class="form-control"
                       placeholder="Detalle certificación adicional"
                       value="<?= htmlspecialchars($_POST['otra_certificacion'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Observaciones</label>
                <textarea name="observaciones" class="form-control" rows="3"
                          placeholder="Notas adicionales de calidad de salida"><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
            </div>

            <div class="p-4 rounded-lg border border-blue-200 bg-blue-50">
                <p class="text-sm text-blue-800">
                    Al guardar esta ficha, el lote se moverá automáticamente al módulo <strong>Empaquetado</strong>.
                </p>
                <p class="text-xs text-blue-700 mt-1">
                    <?php if ($variedadEsCCN51): ?>
                        Variedad detectada: CCN51 (requiere grado de calidad y certificaciones).
                    <?php elseif ($variedadEsFinoAroma): ?>
                        Variedad detectada: Fino de Aroma (requiere certificaciones; grado no aplica).
                    <?php else: ?>
                        Variedad detectada: <?= htmlspecialchars($variedadActual) ?>.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-4">
        <button type="submit" class="btn btn-primary">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            Guardar y enviar a Empaquetado
        </button>
        <a href="<?= APP_URL ?>/calidad-salida/index.php" class="btn btn-outline">Cancelar</a>
    </div>
    <?php endif; ?>
</form>

<script>
function toggleOtraCertificacion() {
    const checks = Array.from(document.querySelectorAll('input[name="certificaciones[]"]'));
    const otra = checks.find((el) => el.value === 'OTRAS');
    const noAplica = checks.find((el) => el.value === 'NO_APLICA');
    const group = document.getElementById('otra_certificacion_group');
    if (!group || !otra) return;

    if (noAplica?.checked) {
        checks.forEach((el) => {
            if (el.value !== 'NO_APLICA') {
                el.checked = false;
            }
        });
    } else if (checks.some((el) => el.checked && el.value !== 'NO_APLICA') && noAplica) {
        noAplica.checked = false;
    }

    group.style.display = otra.checked && !noAplica?.checked ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', toggleOtraCertificacion);
</script>

<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
