<?php
/**
 * MEGABLESSING - Registro de Pago por Ficha
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();
$error = '';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('/fichas/index.php?vista=pagos');
}

$colsFichas = array_column($db->fetchAll("SHOW COLUMNS FROM fichas_registro"), 'Field');
$hasFichaCol = static fn(string $name): bool => in_array($name, $colsFichas, true);
$columnasPago = ['fecha_pago', 'tipo_comprobante', 'factura_compra', 'cantidad_comprada_unidad', 'cantidad_comprada', 'forma_pago'];
$faltantesPago = array_values(array_filter($columnasPago, static fn(string $col): bool => !$hasFichaCol($col)));
$columnasPrecio = ['precio_base_dia', 'diferencial_usd', 'precio_unitario_final', 'precio_total_pagar'];
$faltantesPrecio = array_values(array_filter($columnasPrecio, static fn(string $col): bool => !$hasFichaCol($col)));

$ficha = $db->fetchOne("
    SELECT f.*,
           l.codigo as lote_codigo,
           l.fecha_entrada as lote_fecha_entrada,
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
    redirect('/fichas/index.php?vista=pagos');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha_pago = trim((string)($_POST['fecha_pago'] ?? ''));
    $tipo_comprobante = strtoupper(trim((string)($_POST['tipo_comprobante'] ?? '')));
    $factura_compra = trim((string)($_POST['factura_compra'] ?? ''));
    $cantidad_comprada = is_numeric($_POST['cantidad_comprada'] ?? null) ? (float)$_POST['cantidad_comprada'] : null;
    $cantidad_comprada_unidad = strtoupper(trim((string)($_POST['cantidad_comprada_unidad'] ?? 'KG')));
    $forma_pago = strtoupper(trim((string)($_POST['forma_pago'] ?? '')));
    $precio_base_dia = is_numeric($_POST['precio_base_dia'] ?? null) ? (float)$_POST['precio_base_dia'] : null;
    $diferencial_usd = is_numeric($_POST['diferencial_usd'] ?? null) ? (float)$_POST['diferencial_usd'] : 0.0;
    $precio_unitario_final = is_numeric($_POST['precio_unitario_final'] ?? null) ? (float)$_POST['precio_unitario_final'] : null;
    $precio_total_pagar = is_numeric($_POST['precio_total_pagar'] ?? null) ? (float)$_POST['precio_total_pagar'] : null;

    if (!empty($faltantesPrecio)) {
        $error = 'Faltan columnas de precio en fichas_registro. Ejecute database/patch_fase_planta_fichas.sql';
    } elseif (!empty($faltantesPago)) {
        $error = 'Faltan columnas de pago en fichas_registro. Ejecute database/patch_registro_pagos_fichas.sql';
    } elseif ($fecha_pago === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_pago)) {
        $error = 'Debe ingresar una fecha de pago válida';
    } elseif (!in_array($tipo_comprobante, ['FACTURA', 'NOTA_COMPRA'], true)) {
        $error = 'Debe seleccionar un tipo de comprobante válido';
    } elseif ($factura_compra === '') {
        $error = 'Debe ingresar la factura asignada a la compra';
    } elseif ($cantidad_comprada === null || $cantidad_comprada <= 0) {
        $error = 'Debe ingresar una cantidad comprada mayor a 0';
    } elseif (!in_array($cantidad_comprada_unidad, ['LB', 'KG', 'QQ'], true)) {
        $error = 'Debe seleccionar una unidad válida para la cantidad comprada';
    } elseif (!in_array($forma_pago, ['EFECTIVO', 'TRANSFERENCIA', 'CHEQUE', 'OTROS'], true)) {
        $error = 'Debe seleccionar una forma de pago válida';
    } elseif ($precio_base_dia === null || $precio_base_dia < 0) {
        $error = 'Debe ingresar un precio base del día válido';
    }

    if (!$error && $precio_unitario_final === null) {
        $precio_unitario_final = $precio_base_dia + $diferencial_usd;
    }

    if (!$error && $precio_unitario_final < 0) {
        $error = 'El precio unitario final no puede ser negativo';
    }

    if (!$error) {
        $cantidadCompradaKg = Helpers::pesoToKg($cantidad_comprada, $cantidad_comprada_unidad);
        $precio_total_pagar = $precio_unitario_final * $cantidadCompradaKg;

        try {
            $dataPago = [];
            if ($hasFichaCol('precio_base_dia')) {
                $dataPago['precio_base_dia'] = $precio_base_dia;
            }
            if ($hasFichaCol('diferencial_usd')) {
                $dataPago['diferencial_usd'] = $diferencial_usd;
            }
            if ($hasFichaCol('precio_unitario_final')) {
                $dataPago['precio_unitario_final'] = $precio_unitario_final;
            }
            if ($hasFichaCol('precio_total_pagar')) {
                $dataPago['precio_total_pagar'] = $precio_total_pagar;
            }
            if ($hasFichaCol('fecha_pago')) {
                $dataPago['fecha_pago'] = $fecha_pago;
            }
            if ($hasFichaCol('tipo_comprobante')) {
                $dataPago['tipo_comprobante'] = $tipo_comprobante;
            }
            if ($hasFichaCol('factura_compra')) {
                $dataPago['factura_compra'] = $factura_compra;
            }
            if ($hasFichaCol('cantidad_comprada_unidad')) {
                $dataPago['cantidad_comprada_unidad'] = $cantidad_comprada_unidad;
            }
            if ($hasFichaCol('cantidad_comprada')) {
                $dataPago['cantidad_comprada'] = $cantidad_comprada;
            }
            if ($hasFichaCol('forma_pago')) {
                $dataPago['forma_pago'] = $forma_pago;
            }

            $db->update('fichas_registro', $dataPago, 'id = :id', ['id' => $id]);
            if (!empty($ficha['lote_id']) && (int)$ficha['lote_id'] > 0) {
                Helpers::registrarHistorial($ficha['lote_id'], 'ficha_pago_registrado', "Registro de pago completado en ficha #{$id}");
            }
            setFlash('success', 'Registro de pago guardado correctamente para la ficha #' . $id);
            redirect('/fichas/index.php?vista=pagos');
        } catch (Exception $e) {
            $error = 'Error al guardar el registro de pago: ' . $e->getMessage();
        }
    }
}

$formData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $ficha;
if (!isset($formData['cantidad_comprada_unidad']) || trim((string)$formData['cantidad_comprada_unidad']) === '') {
    $formData['cantidad_comprada_unidad'] = (string)($ficha['cantidad_comprada_unidad'] ?? 'KG');
}
if (!isset($formData['tipo_comprobante']) || trim((string)$formData['tipo_comprobante']) === '') {
    $formData['tipo_comprobante'] = (string)($ficha['tipo_comprobante'] ?? '');
}
$pesoFinalKg = Helpers::pesoToKg(
    (float)($formData['peso_final_registro'] ?? 0),
    (string)($formData['unidad_peso'] ?? 'KG')
);

$pageTitle = "Registro de Pago - Ficha #{$id}";
ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Registro de Pagos</h1>
            <p class="text-gray-600">Ficha #<?= (int)$id ?> · Lote <?= htmlspecialchars((string)($ficha['lote_codigo'] ?: 'Sin lote asignado')) ?></p>
        </div>
        <a href="<?= APP_URL ?>/fichas/index.php?vista=pagos" class="text-amber-600 hover:text-amber-700">
            <i class="fas fa-arrow-left mr-2"></i>Volver al listado de pagos
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
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Proveedor</p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars((string)($ficha['proveedor_nombre'] ?? '—')) ?></p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Variedad de cacao</p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars((string)($ficha['variedad_nombre'] ?? '—')) ?></p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Peso final equivalente</p>
                <p class="font-semibold text-gray-900"><?= number_format($pesoFinalKg, 2) ?> kg</p>
            </div>
        </div>
    </div>

    <form method="POST" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Fecha de pago <span class="text-red-500">*</span></label>
                <input type="date" name="fecha_pago"
                       value="<?= htmlspecialchars((string)($formData['fecha_pago'] ?? '')) ?>"
                       required
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de comprobante <span class="text-red-500">*</span></label>
                <?php $tipoComprobanteActual = strtoupper((string)($formData['tipo_comprobante'] ?? '')); ?>
                <select name="tipo_comprobante" required
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">Seleccione</option>
                    <option value="FACTURA" <?= $tipoComprobanteActual === 'FACTURA' ? 'selected' : '' ?>>Factura</option>
                    <option value="NOTA_COMPRA" <?= $tipoComprobanteActual === 'NOTA_COMPRA' ? 'selected' : '' ?>>Nota de compra</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Factura/Comprobante asignado <span class="text-red-500">*</span></label>
                <input type="text" name="factura_compra"
                       value="<?= htmlspecialchars((string)($formData['factura_compra'] ?? '')) ?>"
                       placeholder="Nro. de factura o comprobante"
                       required
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Cantidad comprada <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-3 gap-2">
                    <input type="number" name="cantidad_comprada" id="cantidad_comprada" step="0.01" min="0.01"
                           value="<?= htmlspecialchars((string)($formData['cantidad_comprada'] ?? '')) ?>"
                           required
                           class="col-span-2 w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    <?php $cantidadUnidadActual = strtoupper((string)($formData['cantidad_comprada_unidad'] ?? 'KG')); ?>
                    <select name="cantidad_comprada_unidad" id="cantidad_comprada_unidad"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="LB" <?= $cantidadUnidadActual === 'LB' ? 'selected' : '' ?>>LB</option>
                        <option value="KG" <?= $cantidadUnidadActual === 'KG' ? 'selected' : '' ?>>KG</option>
                        <option value="QQ" <?= $cantidadUnidadActual === 'QQ' ? 'selected' : '' ?>>QQ</option>
                    </select>
                </div>
                <p class="text-xs text-gray-500 mt-1">El sistema convierte automáticamente la cantidad a kg para calcular el total.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Forma de pago <span class="text-red-500">*</span></label>
                <?php $formaPagoActual = strtoupper((string)($formData['forma_pago'] ?? '')); ?>
                <select name="forma_pago" required
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">Seleccione</option>
                    <option value="EFECTIVO" <?= $formaPagoActual === 'EFECTIVO' ? 'selected' : '' ?>>Efectivo</option>
                    <option value="TRANSFERENCIA" <?= $formaPagoActual === 'TRANSFERENCIA' ? 'selected' : '' ?>>Transferencia</option>
                    <option value="CHEQUE" <?= $formaPagoActual === 'CHEQUE' ? 'selected' : '' ?>>Cheque</option>
                    <option value="OTROS" <?= $formaPagoActual === 'OTROS' ? 'selected' : '' ?>>Otros</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Calidad asignada</label>
                <input type="text" value="<?= htmlspecialchars((string)($ficha['calidad_asignada'] ?? '—')) ?>" readonly
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-700">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Peso final (referencia)</label>
                <input type="text" value="<?= number_format((float)($ficha['peso_final_registro'] ?? 0), 2) . ' ' . htmlspecialchars((string)($ficha['unidad_peso'] ?? 'KG')) ?>" readonly
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-700">
            </div>
        </div>

        <div class="border-t border-gray-100 pt-6">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Valores comerciales</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Precio base del día (USD)</label>
                    <input type="number" name="precio_base_dia" id="precio_base_dia" step="0.0001" min="0"
                           value="<?= htmlspecialchars((string)($formData['precio_base_dia'] ?? '')) ?>"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Descuentos o bonificaciones (USD)</label>
                    <input type="number" name="diferencial_usd" id="diferencial_usd" step="0.0001"
                           value="<?= htmlspecialchars((string)($formData['diferencial_usd'] ?? '0')) ?>"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    <p class="text-xs text-gray-500 mt-1">Usa negativo para descuento y positivo para bonificación.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Precio con diferenciales (USD/KG)</label>
                    <input type="number" name="precio_unitario_final" id="precio_unitario_final" step="0.0001" min="0"
                           value="<?= htmlspecialchars((string)($formData['precio_unitario_final'] ?? '')) ?>"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Precio a pagar (USD)</label>
                    <input type="number" name="precio_total_pagar" id="precio_total_pagar" step="0.01" min="0"
                           value="<?= htmlspecialchars((string)($formData['precio_total_pagar'] ?? '')) ?>"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="<?= APP_URL ?>/fichas/index.php?vista=pagos" class="px-6 py-2.5 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 transition-colors">
                Cancelar
            </a>
            <button type="submit" class="px-6 py-2.5 bg-amber-600 text-white rounded-xl hover:bg-amber-700 transition-colors">
                <i class="fas fa-save mr-2"></i>Guardar Registro de Pago
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cantidadInput = document.getElementById('cantidad_comprada');
    const cantidadUnidadSelect = document.getElementById('cantidad_comprada_unidad');
    const baseInput = document.getElementById('precio_base_dia');
    const diferencialInput = document.getElementById('diferencial_usd');
    const unitarioInput = document.getElementById('precio_unitario_final');
    const totalInput = document.getElementById('precio_total_pagar');

    function toNumber(input) {
        return parseFloat(input?.value || '0') || 0;
    }

    function pesoToKg(peso, unidad) {
        if (unidad === 'LB') return peso * 0.45359237;
        if (unidad === 'QQ') return peso * 45.36;
        return peso;
    }

    function calcularUnitario() {
        const base = toNumber(baseInput);
        const diferencial = toNumber(diferencialInput);
        const unitario = base + diferencial;
        if (unitario >= 0) {
            unitarioInput.value = unitario.toFixed(4);
        } else {
            unitarioInput.value = '0.0000';
        }
        calcularTotal();
    }

    function calcularTotal() {
        const unitario = toNumber(unitarioInput);
        const cantidad = toNumber(cantidadInput);
        const unidadCantidad = cantidadUnidadSelect?.value || 'KG';
        const cantidadKg = pesoToKg(cantidad, unidadCantidad);
        totalInput.value = (unitario * cantidadKg).toFixed(2);
    }

    baseInput?.addEventListener('input', calcularUnitario);
    diferencialInput?.addEventListener('input', calcularUnitario);
    unitarioInput?.addEventListener('input', calcularTotal);
    cantidadInput?.addEventListener('input', calcularTotal);
    cantidadUnidadSelect?.addEventListener('change', calcularTotal);

    calcularUnitario();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
