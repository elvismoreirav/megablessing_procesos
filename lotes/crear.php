<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Crear Nuevo Lote
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

// Datos para el formulario
$colsProveedores = array_column($db->fetchAll("SHOW COLUMNS FROM proveedores"), 'Field');
$filtroProveedorReal = in_array('es_categoria', $colsProveedores, true)
    ? ' AND (es_categoria = 0 OR es_categoria IS NULL)'
    : '';
$proveedores = $db->fetchAll("SELECT id, nombre, codigo FROM proveedores WHERE activo = 1{$filtroProveedorReal} ORDER BY nombre");
$variedades = $db->fetchAll("SELECT id, nombre FROM variedades WHERE activo = 1 ORDER BY nombre");
$estadosProducto = $db->fetchAll("SELECT id, nombre, codigo FROM estados_producto WHERE activo = 1 ORDER BY id");
$estadosFermentacion = $db->fetchAll("SELECT id, nombre, codigo FROM estados_fermentacion WHERE activo = 1 ORDER BY id");

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();

    $errors = [];

    // Validaciones
    $proveedorId = intval($_POST['proveedor_id'] ?? 0);
    $variedadId = intval($_POST['variedad_id'] ?? 0);
    $estadoProductoId = intval($_POST['estado_producto_id'] ?? 0);
    $estadoFermentacionId = intval($_POST['estado_fermentacion_id'] ?? 0) ?: null;
    $fechaEntrada = $_POST['fecha_entrada'] ?? '';
    $pesoInicialKg = floatval(str_replace(',', '.', $_POST['peso_inicial_kg'] ?? 0));
    $humedadInicial = !empty($_POST['humedad_inicial']) ? floatval(str_replace(',', '.', $_POST['humedad_inicial'])) : null;
    $precioKg = !empty($_POST['precio_kg']) ? floatval(str_replace(',', '.', $_POST['precio_kg'])) : null;
    $observaciones = trim($_POST['observaciones'] ?? '');

    if (!$proveedorId) $errors[] = 'Seleccione un proveedor';
    if (!$variedadId) $errors[] = 'Seleccione una variedad';
    if (!$estadoProductoId) $errors[] = 'Seleccione el estado del producto';
    if (!$fechaEntrada) $errors[] = 'Ingrese la fecha de entrada';
    if ($pesoInicialKg <= 0) $errors[] = 'Ingrese un peso válido';
    if ($humedadInicial !== null && ($humedadInicial < 0 || $humedadInicial > 100)) {
        $errors[] = 'La humedad debe estar entre 0 y 100%';
    }

    if (empty($errors)) {
        // Obtener código del proveedor
        $proveedor = $db->fetch("SELECT codigo FROM proveedores WHERE id = ?", [$proveedorId]);

        // Generar código del lote
        // NOTA: Helpers::generateLoteCode debe resolver IDs a códigos y usar NF si no hay fermentación.
        $codigo = Helpers::generateLoteCode($proveedor['codigo'] ?? 'XX', $fechaEntrada, $estadoProductoId, $estadoFermentacionId);

        // Calcular peso en quintales
        $pesoInicialQQ = Helpers::kgToQQ($pesoInicialKg);

        try {
            $loteId = $db->insert('lotes', [
                'codigo' => $codigo,
                'proveedor_id' => $proveedorId,
                'variedad_id' => $variedadId,
                'estado_producto_id' => $estadoProductoId,
                'estado_fermentacion_id' => $estadoFermentacionId,
                'fecha_entrada' => $fechaEntrada,
                'peso_inicial_kg' => $pesoInicialKg,
                'peso_inicial_qq' => $pesoInicialQQ,
                'peso_actual_kg' => $pesoInicialKg,
                'peso_actual_qq' => $pesoInicialQQ,
                'humedad_inicial' => $humedadInicial,
                'precio_kg' => $precioKg,
                'observaciones' => $observaciones,
                'estado_proceso' => 'RECEPCION',
                'usuario_id' => $_SESSION['user_id'] ?? null
            ]);

            // Registrar en historial (acción, descripción, datos anteriores, datos nuevos)
            Helpers::registrarHistorial($loteId, 'RECEPCION', 'Lote creado con código: ' . $codigo, null, [
                'codigo' => $codigo,
                'proveedor_id' => $proveedorId,
                'variedad_id' => $variedadId,
                'estado_producto_id' => $estadoProductoId,
                'estado_fermentacion_id' => $estadoFermentacionId,
                'fecha_entrada' => $fechaEntrada,
                'peso_inicial_kg' => $pesoInicialKg,
                'humedad_inicial' => $humedadInicial,
                'precio_kg' => $precioKg,
                'observaciones' => $observaciones,
            ]);

            setFlash('success', 'Lote creado exitosamente con código: ' . $codigo . '. Complete primero la ficha de registro.');
            redirect('/fichas/crear.php?etapa=recepcion&lote_id=' . $loteId);

        } catch (Exception $e) {
            $errors[] = 'Error al crear el lote: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Nuevo Lote';
$pageSubtitle = 'Registrar entrada de cacao';

ob_start();
?>

<div class="max-w-4xl mx-auto">
    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-sm text-warmgray mb-6">
        <a href="<?= APP_URL ?>/lotes/index.php" class="hover:text-primary">Lotes</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        <span class="text-primary font-medium">Nuevo Lote</span>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-6">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="font-medium">Por favor corrija los siguientes errores:</p>
                <ul class="list-disc list-inside mt-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">
        <?= csrfField() ?>

        <!-- Información Principal -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    Información del Lote
                </h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Proveedor -->
                    <div class="form-group">
                        <label class="form-label required">Proveedor</label>
                        <select name="proveedor_id" class="form-control form-select" required>
                            <option value="">Seleccione un proveedor</option>
                            <?php foreach ($proveedores as $prov): ?>
                                <option value="<?= (int)$prov['id'] ?>"
                                        data-codigo="<?= htmlspecialchars($prov['codigo']) ?>"
                                        <?= (($_POST['proveedor_id'] ?? '') == $prov['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($prov['codigo']) ?> - <?= htmlspecialchars($prov['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Fecha de entrada -->
                    <div class="form-group">
                        <label class="form-label required">Fecha de Entrada</label>
                        <input type="date" name="fecha_entrada" class="form-control" required
                               value="<?= $_POST['fecha_entrada'] ?? date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                    </div>

                    <!-- Variedad -->
                    <div class="form-group">
                        <label class="form-label required">Variedad</label>
                        <select name="variedad_id" class="form-control form-select" required>
                            <option value="">Seleccione variedad</option>
                            <?php foreach ($variedades as $var): ?>
                                <option value="<?= (int)$var['id'] ?>" <?= (($_POST['variedad_id'] ?? '') == $var['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($var['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Estado del Producto -->
                    <div class="form-group">
                        <label class="form-label required">Estado del Producto</label>
                        <select name="estado_producto_id" class="form-control form-select" required>
                            <option value="">Seleccione estado</option>
                            <?php foreach ($estadosProducto as $ep): ?>
                                <option value="<?= (int)$ep['id'] ?>"
                                        data-codigo="<?= htmlspecialchars($ep['codigo']) ?>"
                                        <?= (($_POST['estado_producto_id'] ?? '') == $ep['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ep['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Estado de Fermentación -->
                    <div class="form-group">
                        <label class="form-label">Estado de Fermentación</label>
                        <select name="estado_fermentacion_id" class="form-control form-select">
                            <option value="">Sin fermentación previa</option>
                            <?php foreach ($estadosFermentacion as $ef): ?>
                                <option value="<?= (int)$ef['id'] ?>" <?= (($_POST['estado_fermentacion_id'] ?? '') == $ef['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ef['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint">Si el cacao ya viene fermentado del proveedor</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pesos y Medidas -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/>
                    </svg>
                    Pesos y Medidas
                </h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Peso en Kg -->
                    <div class="form-group">
                        <label class="form-label required">Peso Inicial (Kg)</label>
                        <div class="relative">
                            <input type="number" name="peso_inicial_kg" class="form-control pr-12" required
                                   id="peso_kg" step="0.01" min="0.01"
                                   value="<?= htmlspecialchars($_POST['peso_inicial_kg'] ?? '') ?>" placeholder="0.00">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-warmgray font-medium">Kg</span>
                        </div>
                    </div>

                    <!-- Peso en Quintales (calculado) -->
                    <div class="form-group">
                        <label class="form-label">Peso en Quintales</label>
                        <div class="relative">
                            <input type="text" class="form-control pr-12 bg-gray-50" readonly
                                   id="peso_qq" value="0.00" placeholder="0.00">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-warmgray font-medium">QQ</span>
                        </div>
                        <p class="form-hint">Calculado automáticamente (1 QQ = 45.36 Kg)</p>
                    </div>

                    <!-- Humedad inicial -->
                    <div class="form-group">
                        <label class="form-label">Humedad Inicial (%)</label>
                        <div class="relative">
                            <input type="number" name="humedad_inicial" class="form-control pr-10"
                                   step="0.1" min="0" max="100"
                                   value="<?= htmlspecialchars($_POST['humedad_inicial'] ?? '') ?>" placeholder="0.0">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-warmgray font-medium">%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Observaciones -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h6m-6 4h8M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"/>
                    </svg>
                    Observaciones de Recepción
                </h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="3"
                              placeholder="Notas adicionales sobre la recepción del lote..."><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
                    <p class="form-hint">El precio unitario y precio final se registran en el proceso de Planta (Ficha de registro).</p>
                </div>
            </div>
        </div>

        <!-- Código generado (preview) -->
        <div class="card bg-olive/10 border-olive">
            <div class="card-body">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-primary rounded-xl flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-warmgray">Código del Lote (se generará automáticamente)</p>
                        <p class="text-xl font-bold text-primary" id="codigo_preview">XX-DD-MM-AA-EC-NF</p>
                        <p class="text-xs text-warmgray mt-1">Proveedor-Día-Mes-Año-Estado-Fermentado</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Botones -->
        <div class="flex items-center justify-end gap-4">
            <a href="<?= APP_URL ?>/lotes/index.php" class="btn btn-outline">
                Cancelar
            </a>
            <button type="submit" class="btn btn-primary">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Crear Lote
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const pesoKgInput = document.getElementById('peso_kg');
    const pesoQQInput = document.getElementById('peso_qq');
    const proveedorSelect = document.querySelector('select[name="proveedor_id"]');
    const fechaInput = document.querySelector('input[name="fecha_entrada"]');
    const estadoFermentacionSelect = document.querySelector('select[name="estado_fermentacion_id"]');
    const estadoProductoSelect = document.querySelector('select[name="estado_producto_id"]');
    const codigoPreview = document.getElementById('codigo_preview');

    function updateQQ() {
        const kg = parseFloat(pesoKgInput.value) || 0;
        const qq = kg / 45.36;
        pesoQQInput.value = qq.toFixed(2);
    }

    function updateCodigoPreview() {
        const proveedorOption = proveedorSelect.options[proveedorSelect.selectedIndex];
        const proveedorCodigo = proveedorOption?.dataset?.codigo || 'XX';

        const fecha = fechaInput.value ? new Date(fechaInput.value) : new Date();
        const dia = String(fecha.getDate()).padStart(2, '0');
        const mes = String(fecha.getMonth() + 1).padStart(2, '0');
        const anio = String(fecha.getFullYear()).slice(-2);

        const fermentado = estadoFermentacionSelect.value ? 'F' : 'NF';

        const estadoOption = estadoProductoSelect.options[estadoProductoSelect.selectedIndex];
        const estadoCodigo = estadoOption?.dataset?.codigo || 'EC';

        codigoPreview.textContent = `${proveedorCodigo}-${dia}-${mes}-${anio}-${estadoCodigo}-${fermentado}`;
    }

    pesoKgInput.addEventListener('input', updateQQ);

    proveedorSelect.addEventListener('change', updateCodigoPreview);
    fechaInput.addEventListener('change', updateCodigoPreview);
    estadoFermentacionSelect.addEventListener('change', updateCodigoPreview);
    estadoProductoSelect.addEventListener('change', updateCodigoPreview);

    updateQQ();
    updateCodigoPreview();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
