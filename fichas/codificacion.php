<?php
/**
 * MEGABLESSING - Codificacion de Lote por Ficha
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();
$error = '';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('/fichas/index.php?vista=codificacion');
}

$ficha = $db->fetchOne("
    SELECT f.*,
           l.codigo as lote_codigo,
           l.proveedor_id as lote_proveedor_id,
           l.fecha_entrada as lote_fecha_entrada,
           l.estado_producto_id as lote_estado_producto_id,
           l.estado_fermentacion_id as lote_estado_fermentacion_id,
           COALESCE(NULLIF(TRIM(p.nombre), ''), NULLIF(TRIM(f.proveedor_ruta), '')) as proveedor_nombre,
           v.nombre as variedad_nombre
    FROM fichas_registro f
    LEFT JOIN lotes l ON f.lote_id = l.id
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    WHERE f.id = ?
", [$id]);

if (!$ficha) {
    setFlash('error', 'Ficha no encontrada');
    redirect('/fichas/index.php?vista=codificacion');
}

$sugerido = Helpers::generateFichaRegistroCode();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codificacion = Helpers::normalizeFichaRegistroCode($_POST['codificacion'] ?? '');

    if ($codificacion === '') {
        $error = 'Debe ingresar el código de la ficha de registro';
    } elseif (!Helpers::isValidFichaRegistroCode($codificacion)) {
        $error = 'La codificación debe tener el formato FREG-001';
    } else {
        $existe = $db->fetchOne(
            "SELECT id FROM fichas_registro WHERE codificacion = ? AND id <> ?",
            [$codificacion, $id]
        );
        if ($existe) {
            $error = 'Ya existe otra ficha con esta codificación';
        }
    }

    if (!$error) {
        try {
            $db->update('fichas_registro', ['codificacion' => $codificacion], 'id = :id', ['id' => $id]);
            if (!empty($ficha['lote_id']) && (int)$ficha['lote_id'] > 0) {
                Helpers::registrarHistorial($ficha['lote_id'], 'ficha_codificada', "Codificacion registrada en ficha #{$id}: {$codificacion}");
            }
            setFlash('success', 'Codificación de ficha guardada correctamente para la ficha #' . $id);
            redirect('/fichas/index.php?vista=codificacion');
        } catch (Exception $e) {
            $error = 'Error al guardar la codificacion: ' . $e->getMessage();
        }
    }
}

$formData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $ficha;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && trim((string)($formData['codificacion'] ?? '')) === '') {
    $formData['codificacion'] = $sugerido;
}
$codificacionActual = trim((string)($ficha['codificacion'] ?? ''));
$prefijoProveedor = Helpers::resolveProveedorLotePrefix(
    (int)($ficha['lote_proveedor_id'] ?? 0) > 0 ? (int)$ficha['lote_proveedor_id'] : ((string)($ficha['proveedor_nombre'] ?? ''))
);
$tipoEntregaFicha = strtoupper(trim((string)($ficha['tipo_entrega'] ?? '')));
$esEntregaRuta = $tipoEntregaFicha === 'RUTAS';
$proveedorRutaCompuesto = Helpers::parseProveedorRutaCompuesta((string)($ficha['proveedor_ruta'] ?? ''));
$rutaEntregaTexto = trim((string)($proveedorRutaCompuesto['ruta'] ?? ''));
$proveedoresRuta = array_values(array_filter(array_map(
    static fn(string $nombre): string => trim($nombre),
    (array)($proveedorRutaCompuesto['proveedores'] ?? [])
)));
if (empty($proveedoresRuta)) {
    $proveedorNombreFicha = trim((string)($ficha['proveedor_nombre'] ?? ''));
    if ($proveedorNombreFicha !== '') {
        $proveedoresRuta[] = $proveedorNombreFicha;
    }
}
$proveedoresRutaTexto = !empty($proveedoresRuta) ? implode(', ', $proveedoresRuta) : '—';
$proveedorPrincipalTexto = $esEntregaRuta ? $proveedoresRutaTexto : trim((string)($ficha['proveedor_nombre'] ?? '—'));
$codigoBaseTexto = trim((string)($ficha['lote_codigo'] ?? ''));

$pageTitle = "Codificacion de Ficha - Ficha #{$id}";
ob_start();
?>

<div class="max-w-5xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Codificación de Ficha de Registro</h1>
            <p class="text-gray-600">Ficha #<?= (int)$id ?> · Lote <?= htmlspecialchars((string)($ficha['lote_codigo'] ?: 'Sin lote asignado')) ?></p>
            <?php if ($esEntregaRuta): ?>
            <p class="text-sm text-gray-500 mt-1">
                Ruta: <?= htmlspecialchars($rutaEntregaTexto !== '' ? $rutaEntregaTexto : 'No aplica') ?> · Proveedores: <?= htmlspecialchars($proveedoresRutaTexto) ?>
            </p>
            <?php endif; ?>
        </div>
        <a href="<?= APP_URL ?>/fichas/index.php?vista=codificacion" class="text-amber-600 hover:text-amber-700">
            <i class="fas fa-arrow-left mr-2"></i>Volver al listado
        </a>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 rounded-xl p-4">
        <div class="flex items-center gap-3">
            <i class="fas fa-exclamation-circle text-red-600"></i>
            <span class="text-red-800"><?= htmlspecialchars($error) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
        <div class="flex items-center gap-3">
            <i class="fas fa-info-circle text-blue-600"></i>
            <span class="text-blue-800">El lote mantiene su propia codificación de trazabilidad. Esta ficha debe usar un correlativo independiente con formato <strong>FREG-001</strong>, lo que permite registrar varias fichas dentro del mismo lote sin confusión.</span>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-<?= $esEntregaRuta ? '5' : '4' ?> gap-4">
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500"><?= $esEntregaRuta ? 'Proveedores' : 'Proveedor' ?></p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars($proveedorPrincipalTexto !== '' ? $proveedorPrincipalTexto : '—') ?></p>
            </div>
            <?php if ($esEntregaRuta): ?>
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Ruta de entrega</p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars($rutaEntregaTexto !== '' ? $rutaEntregaTexto : 'No aplica') ?></p>
            </div>
            <?php endif; ?>
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Tipo proveedor (prefijo)</p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars($prefijoProveedor !== '' ? $prefijoProveedor : '—') ?></p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Variedad</p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars((string)($ficha['variedad_nombre'] ?? '—')) ?></p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Código de lote base</p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars($codigoBaseTexto !== '' ? $codigoBaseTexto : '—') ?></p>
                <p class="text-[11px] text-gray-500 mt-1">Este código pertenece al lote, no a la ficha.</p>
            </div>
        </div>
    </div>

    <form method="POST" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Código de ficha de registro <span class="text-red-500">*</span></label>
            <input type="text" name="codificacion" id="codificacion"
                   value="<?= htmlspecialchars(Helpers::normalizeFichaRegistroCode((string)($formData['codificacion'] ?? ''))) ?>"
                   placeholder="Ejemplo: FREG-001"
                   required
                   class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500 font-mono tracking-wide uppercase">
            <p class="text-xs text-gray-500 mt-2">Formato obligatorio: <span class="font-mono">FREG-001</span>. El consecutivo identifica la ficha, no el lote.</p>
            <?php if ($codigoBaseTexto !== ''): ?>
            <p class="text-xs text-blue-700 mt-1">Referencia visual: lote <?= htmlspecialchars($codigoBaseTexto) ?> · ficha sugerida <?= htmlspecialchars($sugerido) ?>.</p>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-3">
            <button type="button" id="usar_codigo_lote" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                Usar consecutivo sugerido
            </button>
            <span class="text-xs text-gray-500">Sugerido: <?= htmlspecialchars($sugerido) ?></span>
            <?php if ($codificacionActual !== ''): ?>
            <span class="text-xs text-gray-500">Actual: <?= htmlspecialchars($codificacionActual) ?></span>
            <?php endif; ?>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
            <a href="<?= APP_URL ?>/fichas/index.php?vista=codificacion" class="px-6 py-2.5 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 transition-colors">
                Cancelar
            </a>
            <button type="submit" class="px-6 py-2.5 bg-amber-600 text-white rounded-xl hover:bg-amber-700 transition-colors">
                <i class="fas fa-save mr-2"></i>Guardar Codificación
            </button>
        </div>
    </form>
</div>

<script>
document.getElementById('usar_codigo_lote')?.addEventListener('click', function() {
    const input = document.getElementById('codificacion');
    if (!input) return;
    input.value = '<?= htmlspecialchars($sugerido, ENT_QUOTES) ?>';
    input.dispatchEvent(new Event('input'));
    input.focus();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
