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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codificacion = strtoupper(trim((string)($_POST['codificacion'] ?? '')));
    $codificacion = preg_replace('/\s+/', '-', $codificacion);

    if ($codificacion === '') {
        $error = 'Debe ingresar un codigo de lote';
    } elseif (!preg_match('/^[A-Z0-9\-]+$/', $codificacion)) {
        $error = 'La codificacion solo permite letras, numeros y guion (-)';
    } else {
        $existe = $db->fetchOne("SELECT id FROM fichas_registro WHERE codificacion = ? AND id <> ?", [$codificacion, $id]);
        if ($existe) {
            $error = 'Ya existe otra ficha con esta codificacion';
        }
    }

    if (!$error) {
        try {
            $db->update('fichas_registro', ['codificacion' => $codificacion], 'id = :id', ['id' => $id]);
            if (!empty($ficha['lote_id']) && (int)$ficha['lote_id'] > 0) {
                Helpers::registrarHistorial($ficha['lote_id'], 'ficha_codificada', "Codificacion registrada en ficha #{$id}: {$codificacion}");
            }
            setFlash('success', 'Codificación guardada correctamente para la ficha #' . $id);
            redirect('/fichas/index.php?vista=codificacion');
        } catch (Exception $e) {
            $error = 'Error al guardar la codificacion: ' . $e->getMessage();
        }
    }
}

$formData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $ficha;
$sugerido = '';
$loteProveedorId = (int)($ficha['lote_proveedor_id'] ?? 0);
$fechaBaseCodificacion = trim((string)($ficha['lote_fecha_entrada'] ?? $ficha['fecha_entrada'] ?? ''));
$estadoProductoRef = $ficha['lote_estado_producto_id'] ?? null;
$estadoFermentacionRef = $ficha['lote_estado_fermentacion_id'] ?? null;
if ($loteProveedorId > 0 && $fechaBaseCodificacion !== '' && !empty($estadoProductoRef)) {
    $sugerido = Helpers::generateLoteCode($loteProveedorId, $fechaBaseCodificacion, $estadoProductoRef, $estadoFermentacionRef);
}
if ($sugerido === '') {
    $sugerido = trim((string)($ficha['lote_codigo'] ?? ''));
}
$prefijoProveedor = Helpers::resolveProveedorLotePrefix(
    $loteProveedorId > 0 ? $loteProveedorId : ((string)($ficha['proveedor_nombre'] ?? ''))
);

$pageTitle = "Codificacion de Lote - Ficha #{$id}";
ob_start();
?>

<div class="max-w-3xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Codificacion de Lote</h1>
            <p class="text-gray-600">Ficha #<?= (int)$id ?> · Lote <?= htmlspecialchars((string)($ficha['lote_codigo'] ?: 'Sin lote asignado')) ?></p>
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

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Proveedor</p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars((string)($ficha['proveedor_nombre'] ?? '—')) ?></p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Tipo proveedor (prefijo)</p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars($prefijoProveedor !== '' ? $prefijoProveedor : '—') ?></p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Variedad</p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars((string)($ficha['variedad_nombre'] ?? '—')) ?></p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Codigo de lote base</p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars((string)($ficha['lote_codigo'] ?? '—')) ?></p>
            </div>
        </div>
    </div>

    <form method="POST" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Codigo de lote (codificacion) <span class="text-red-500">*</span></label>
            <input type="text" name="codificacion" id="codificacion"
                   value="<?= htmlspecialchars((string)($formData['codificacion'] ?? '')) ?>"
                   placeholder="Ejemplo: ES-17-02-26-SC-A"
                   required
                   class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500 font-mono tracking-wide uppercase">
            <p class="text-xs text-gray-500 mt-2">Formato recomendado: CAT-DD-MM-YY-ESTADO[-LETRA].</p>
            <p class="text-xs text-gray-500">Estados válidos: ES, SC, SM, BA. Si hay duplicados del mismo día/categoría, use sufijo A, B, C...</p>
        </div>

        <div class="flex items-center gap-3">
            <button type="button" id="usar_codigo_lote" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                Usar codigo de lote actual
            </button>
            <span class="text-xs text-gray-500">Sugerido: <?= htmlspecialchars($sugerido !== '' ? $sugerido : 'N/D') ?></span>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
            <a href="<?= APP_URL ?>/fichas/index.php?vista=codificacion" class="px-6 py-2.5 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 transition-colors">
                Cancelar
            </a>
            <button type="submit" class="px-6 py-2.5 bg-amber-600 text-white rounded-xl hover:bg-amber-700 transition-colors">
                <i class="fas fa-save mr-2"></i>Guardar Codificacion
            </button>
        </div>
    </form>
</div>

<script>
document.getElementById('usar_codigo_lote')?.addEventListener('click', function() {
    const input = document.getElementById('codificacion');
    if (!input) return;
    input.value = '<?= htmlspecialchars((string)$sugerido, ENT_QUOTES) ?>';
    input.dispatchEvent(new Event('input'));
    input.focus();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
