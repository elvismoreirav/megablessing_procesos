<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Editar Lote
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Lote no especificado');
    redirect('/lotes/index.php');
}

// Obtener lote
$lote = $db->fetch("SELECT * FROM lotes WHERE id = ?", [$id]);
if (!$lote) {
    setFlash('error', 'Lote no encontrado');
    redirect('/lotes/index.php');
}

// Datos para el formulario
$colsProveedores = array_column($db->fetchAll("SHOW COLUMNS FROM proveedores"), 'Field');
$filtroProveedorReal = in_array('es_categoria', $colsProveedores, true)
    ? ' AND (es_categoria = 0 OR es_categoria IS NULL)'
    : '';
$proveedores = $db->fetchAll("SELECT id, nombre, codigo FROM proveedores WHERE activo = 1{$filtroProveedorReal} ORDER BY nombre");
$variedades = $db->fetchAll("SELECT id, nombre FROM variedades WHERE activo = 1 ORDER BY nombre");
$estadosProducto = $db->fetchAll("SELECT id, nombre FROM estados_producto WHERE activo = 1 ORDER BY id");
$estadosFermentacion = $db->fetchAll("SELECT id, nombre FROM estados_fermentacion WHERE activo = 1 ORDER BY id");
$cajones = $db->fetchAll("SELECT id, nombre FROM cajones_fermentacion WHERE activo = 1 ORDER BY nombre");
$secadoras = $db->fetchAll("SELECT id, nombre FROM secadoras WHERE activo = 1 ORDER BY nombre");

// Estados del proceso
$estadosProceso = [
    'RECEPCION' => 'Recepción',
    'CALIDAD' => 'Verificación de Lote',
    'PRE_SECADO' => 'Pre-secado (Legado)',
    'FERMENTACION' => 'Fermentación',
    'SECADO' => 'Secado',
    'CALIDAD_POST' => 'Prueba de Corte',
    'EMPAQUETADO' => 'Empaquetado',
    'ALMACENADO' => 'Almacenado',
    'DESPACHO' => 'Despacho',
    'FINALIZADO' => 'Finalizado',
    'RECHAZADO' => 'Rechazado'
];

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    
    $errors = [];
    
    // Validaciones
    $variedadId = intval($_POST['variedad_id'] ?? 0);
    $estadoProductoId = intval($_POST['estado_producto_id'] ?? 0);
    $estadoFermentacionId = intval($_POST['estado_fermentacion_id'] ?? 0) ?: null;
    $pesoActualKg = floatval(str_replace(',', '.', $_POST['peso_actual_kg'] ?? 0));
    $humedadInicial = !empty($_POST['humedad_inicial']) ? floatval(str_replace(',', '.', $_POST['humedad_inicial'])) : null;
    $humedadFinal = !empty($_POST['humedad_final']) ? floatval(str_replace(',', '.', $_POST['humedad_final'])) : null;
    $precioKg = !empty($_POST['precio_kg']) ? floatval(str_replace(',', '.', $_POST['precio_kg'])) : null;
    $estadoProceso = $_POST['estado_proceso'] ?? $lote['estado_proceso'];
    $cajonFermentacionId = intval($_POST['cajon_fermentacion_id'] ?? 0) ?: null;
    $secadoraId = intval($_POST['secadora_id'] ?? 0) ?: null;
    $observaciones = trim($_POST['observaciones'] ?? '');
    
    if (!$variedadId) $errors[] = 'Seleccione una variedad';
    if (!$estadoProductoId) $errors[] = 'Seleccione el estado del producto';
    if ($pesoActualKg <= 0) $errors[] = 'Ingrese un peso válido';
    if ($humedadInicial !== null && ($humedadInicial < 0 || $humedadInicial > 100)) {
        $errors[] = 'La humedad inicial debe estar entre 0 y 100%';
    }
    if ($humedadFinal !== null && ($humedadFinal < 0 || $humedadFinal > 100)) {
        $errors[] = 'La humedad final debe estar entre 0 y 100%';
    }
    
    if (empty($errors)) {
        // Calcular peso en quintales
        $pesoActualQQ = Helpers::kgToQQ($pesoActualKg);
        
        try {
            // Detectar cambios para el historial
            $cambios = [];
            if ($estadoProceso !== $lote['estado_proceso']) {
                $cambios[] = "Estado: {$lote['estado_proceso']} → {$estadoProceso}";
            }
            if ($pesoActualKg != $lote['peso_actual_kg']) {
                $cambios[] = "Peso: {$lote['peso_actual_kg']} → {$pesoActualKg} Kg";
            }
            
            $db->update('lotes', [
                'variedad_id' => $variedadId,
                'estado_producto_id' => $estadoProductoId,
                'estado_fermentacion_id' => $estadoFermentacionId,
                'peso_actual_kg' => $pesoActualKg,
                'peso_actual_qq' => $pesoActualQQ,
                'humedad_inicial' => $humedadInicial,
                'humedad_final' => $humedadFinal,
                'precio_kg' => $precioKg,
                'estado_proceso' => $estadoProceso,
                'cajon_fermentacion_id' => $cajonFermentacionId,
                'secadora_id' => $secadoraId,
                'observaciones' => $observaciones
            ], 'id = :where_id', ['where_id' => $id]);
            
            // Registrar en historial
            if (!empty($cambios)) {
                Helpers::logHistory($id, 'MODIFICACION', implode('; ', $cambios), $_SESSION['user_id']);
            }
            
            setFlash('success', 'Lote actualizado correctamente');
            redirect('/lotes/ver.php?id=' . $id);
            
        } catch (Exception $e) {
            $errors[] = 'Error al actualizar el lote: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Editar Lote';
$pageSubtitle = $lote['codigo'];

ob_start();
?>

<div class="max-w-4xl mx-auto">
    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-sm text-warmgray mb-6">
        <a href="<?= APP_URL ?>/lotes/index.php" class="hover:text-primary">Lotes</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $id ?>" class="hover:text-primary"><?= htmlspecialchars($lote['codigo']) ?></a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        <span class="text-primary font-medium">Editar</span>
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

    <!-- Código del lote (no editable) -->
    <div class="card mb-6 bg-gradient-to-r from-primary to-primary/80">
        <div class="card-body">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 bg-white/20 rounded-xl flex items-center justify-center">
                    <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/>
                    </svg>
                </div>
                <div class="text-white">
                    <p class="text-sm opacity-80">Código del Lote</p>
                    <p class="text-2xl font-bold"><?= htmlspecialchars($lote['codigo']) ?></p>
                </div>
            </div>
        </div>
    </div>

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
                    <!-- Proveedor (solo lectura) -->
                    <div class="form-group">
                        <label class="form-label">Proveedor</label>
                        <?php $prov = $db->fetch("SELECT nombre, codigo FROM proveedores WHERE id = ?", [$lote['proveedor_id']]); ?>
                        <input type="text" class="form-control bg-gray-50" readonly
                               value="<?= htmlspecialchars($prov['codigo']) ?> - <?= htmlspecialchars($prov['nombre']) ?>">
                    </div>

                    <!-- Fecha (solo lectura) -->
                    <div class="form-group">
                        <label class="form-label">Fecha de Entrada</label>
                        <input type="text" class="form-control bg-gray-50" readonly
                               value="<?= Helpers::formatDate($lote['fecha_entrada']) ?>">
                    </div>

                    <!-- Variedad -->
                    <div class="form-group">
                        <label class="form-label required">Variedad</label>
                        <select name="variedad_id" class="form-control form-select" required>
                            <option value="">Seleccione variedad</option>
                            <?php foreach ($variedades as $var): ?>
                                <option value="<?= $var['id'] ?>" <?= $lote['variedad_id'] == $var['id'] ? 'selected' : '' ?>>
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
                                <option value="<?= $ep['id'] ?>" <?= $lote['estado_producto_id'] == $ep['id'] ? 'selected' : '' ?>>
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
                                <option value="<?= $ef['id'] ?>" <?= $lote['estado_fermentacion_id'] == $ef['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ef['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Estado del Proceso -->
                    <div class="form-group">
                        <label class="form-label required">Estado del Proceso</label>
                        <select name="estado_proceso" class="form-control form-select" required>
                            <?php foreach ($estadosProceso as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $lote['estado_proceso'] === $key ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Peso Inicial (solo lectura) -->
                    <div class="form-group">
                        <label class="form-label">Peso Inicial (Kg)</label>
                        <input type="text" class="form-control bg-gray-50" readonly
                               value="<?= Helpers::formatNumber($lote['peso_inicial_kg'], 2) ?>">
                    </div>

                    <!-- Peso Actual -->
                    <div class="form-group">
                        <label class="form-label required">Peso Actual (Kg)</label>
                        <div class="relative">
                            <input type="number" name="peso_actual_kg" class="form-control pr-12" required
                                   id="peso_kg" step="0.01" min="0.01"
                                   value="<?= $lote['peso_actual_kg'] ?>">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-warmgray font-medium">Kg</span>
                        </div>
                    </div>

                    <!-- Peso en Quintales (calculado) -->
                    <div class="form-group">
                        <label class="form-label">Peso Actual (QQ)</label>
                        <div class="relative">
                            <input type="text" class="form-control pr-12 bg-gray-50" readonly
                                   id="peso_qq" value="<?= Helpers::formatNumber($lote['peso_actual_qq'], 2) ?>">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-warmgray font-medium">QQ</span>
                        </div>
                    </div>

                    <!-- Merma -->
                    <div class="form-group">
                        <label class="form-label">Merma (%)</label>
                        <div class="relative">
                            <input type="text" class="form-control pr-10 bg-gray-50" readonly
                                   id="merma" value="0.0">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-warmgray font-medium">%</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4 pt-4 border-t border-gray-100">
                    <!-- Humedad inicial -->
                    <div class="form-group">
                        <label class="form-label">Humedad Inicial (%)</label>
                        <div class="relative">
                            <input type="number" name="humedad_inicial" class="form-control pr-10" 
                                   step="0.1" min="0" max="100"
                                   value="<?= $lote['humedad_inicial'] ?>">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-warmgray font-medium">%</span>
                        </div>
                    </div>

                    <!-- Humedad final -->
                    <div class="form-group">
                        <label class="form-label">Humedad Final (%)</label>
                        <div class="relative">
                            <input type="number" name="humedad_final" class="form-control pr-10" 
                                   step="0.1" min="0" max="100"
                                   value="<?= $lote['humedad_final'] ?>">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-warmgray font-medium">%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Asignación de Equipos -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
                    </svg>
                    Asignación de Equipos
                </h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Cajón de Fermentación -->
                    <div class="form-group">
                        <label class="form-label">Cajón de Fermentación</label>
                        <select name="cajon_fermentacion_id" class="form-control form-select">
                            <option value="">Sin asignar</option>
                            <?php foreach ($cajones as $cajon): ?>
                                <option value="<?= $cajon['id'] ?>" <?= $lote['cajon_fermentacion_id'] == $cajon['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cajon['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Secadora -->
                    <div class="form-group">
                        <label class="form-label">Secadora</label>
                        <select name="secadora_id" class="form-control form-select">
                            <option value="">Sin asignar</option>
                            <?php foreach ($secadoras as $sec): ?>
                                <option value="<?= $sec['id'] ?>" <?= $lote['secadora_id'] == $sec['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sec['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Información Comercial -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Información Comercial
                </h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Precio por Kg -->
                    <div class="form-group">
                        <label class="form-label">Precio por Kg ($)</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-warmgray font-medium">$</span>
                            <input type="number" name="precio_kg" class="form-control pl-8" 
                                   id="precio_kg" step="0.01" min="0"
                                   value="<?= $lote['precio_kg'] ?>">
                        </div>
                    </div>

                    <!-- Total (calculado) -->
                    <div class="form-group">
                        <label class="form-label">Total Actual</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-warmgray font-medium">$</span>
                            <input type="text" class="form-control pl-8 bg-gray-50 font-medium text-primary" readonly
                                   id="total_actual" value="0.00">
                        </div>
                    </div>
                </div>

                <!-- Observaciones -->
                <div class="form-group mt-6">
                    <label class="form-label">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="3" 
                              placeholder="Notas adicionales..."><?= htmlspecialchars($lote['observaciones']) ?></textarea>
                </div>
            </div>
        </div>

        <!-- Botones -->
        <div class="flex items-center justify-between">
            <button type="button" onclick="confirmDelete()" class="btn btn-danger">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
                Eliminar
            </button>
            
            <div class="flex items-center gap-4">
                <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $id ?>" class="btn btn-outline">
                    Cancelar
                </a>
                <button type="submit" class="btn btn-primary">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Guardar Cambios
                </button>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const pesoKgInput = document.getElementById('peso_kg');
    const pesoQQInput = document.getElementById('peso_qq');
    const mermaInput = document.getElementById('merma');
    const precioKgInput = document.getElementById('precio_kg');
    const totalActualInput = document.getElementById('total_actual');
    
    const pesoInicial = <?= $lote['peso_inicial_kg'] ?>;

    function updateCalculations() {
        const pesoActual = parseFloat(pesoKgInput.value) || 0;
        const precioKg = parseFloat(precioKgInput.value) || 0;
        
        // Calcular quintales
        const qq = pesoActual / 45.36;
        pesoQQInput.value = qq.toFixed(2);
        
        // Calcular merma
        if (pesoInicial > 0) {
            const merma = ((pesoInicial - pesoActual) / pesoInicial) * 100;
            mermaInput.value = merma.toFixed(1);
        }
        
        // Calcular total
        const total = pesoActual * precioKg;
        totalActualInput.value = total.toLocaleString('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    pesoKgInput.addEventListener('input', updateCalculations);
    precioKgInput.addEventListener('input', updateCalculations);

    updateCalculations();
});

function confirmDelete() {
    if (confirm('¿Está seguro de eliminar este lote? Esta acción no se puede deshacer.')) {
        window.location.href = '<?= APP_URL ?>/lotes/eliminar.php?id=<?= $id ?>&token=<?= generateCSRFToken() ?>';
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
