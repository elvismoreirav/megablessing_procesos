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

$fichaRegistro = $db->fetchOne(
    "SELECT id FROM fichas_registro WHERE lote_id = ? ORDER BY id DESC LIMIT 1",
    [$id]
);
$registroFermentacion = $db->fetchOne(
    "SELECT id FROM registros_fermentacion WHERE lote_id = ? ORDER BY id DESC LIMIT 1",
    [$id]
);
$loteEnFermentacion = strtoupper((string)($lote['estado_proceso'] ?? '')) === 'FERMENTACION';
$loteListoParaFormularioFermentacion = $loteEnFermentacion && (bool)$fichaRegistro;
$rutaFermentacion = $registroFermentacion
    ? (APP_URL . '/fermentacion/control.php?id=' . (int)$registroFermentacion['id'])
    : (APP_URL . '/fermentacion/crear.php?lote_id=' . $id . '&from=verificacion');
$labelAccionFermentacion = $registroFermentacion ? 'Ver estado de fermentación' : 'Ir al formulario de fermentación';
$bloquearAccionFermentacion = !$registroFermentacion && !$loteListoParaFormularioFermentacion;
$mensajeGuiaFermentacion = '';
if (!$registroFermentacion) {
    if (!$fichaRegistro) {
        $mensajeGuiaFermentacion = 'Primero debe completar la ficha de recepción para habilitar fermentación.';
    } elseif (!$loteEnFermentacion) {
        $mensajeGuiaFermentacion = 'Cambie el estado del proceso a Fermentación y guarde para continuar.';
    } else {
        $mensajeGuiaFermentacion = 'El lote está listo para iniciar el formulario de fermentación.';
    }
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
Helpers::ensureCajonesFermentacionCatalog();
$cajones = Helpers::getCajonesFermentacionCatalog(true);
$colsSecadoras = array_column($db->fetchAll("SHOW COLUMNS FROM secadoras"), 'Field');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSecadoras, true);
$exprNumeroSecadora = $hasSecCol('numero') ? "NULLIF(TRIM(numero), '')" : 'NULL';
$exprNombreSecadora = $hasSecCol('nombre') ? "NULLIF(TRIM(nombre), '')" : 'NULL';
$exprSecadoraActivo = $hasSecCol('activo') ? 'activo = 1' : '1 = 1';
$exprEtiquetaSecadora = ($hasSecCol('numero') && $hasSecCol('nombre'))
    ? "CASE
        WHEN {$exprNumeroSecadora} IS NOT NULL AND {$exprNombreSecadora} IS NOT NULL AND UPPER({$exprNumeroSecadora}) <> UPPER({$exprNombreSecadora})
            THEN CONCAT({$exprNumeroSecadora}, ' - ', {$exprNombreSecadora})
        ELSE COALESCE({$exprNumeroSecadora}, {$exprNombreSecadora}, CONCAT('Secadora #', id))
      END"
    : ($hasSecCol('numero')
        ? "COALESCE({$exprNumeroSecadora}, CONCAT('Secadora #', id))"
        : ($hasSecCol('nombre')
            ? "COALESCE({$exprNombreSecadora}, CONCAT('Secadora #', id))"
            : "CONCAT('Secadora #', id)"));
$secadoras = $db->fetchAll("
    SELECT id, {$exprEtiquetaSecadora} as nombre
    FROM secadoras
    WHERE {$exprSecadoraActivo}
    ORDER BY " . ($hasSecCol('numero') ? 'numero' : ($hasSecCol('nombre') ? 'nombre' : 'id'))
);

// Estados del proceso
$estadosProceso = [
    'RECEPCION' => 'Recepción',
    'CALIDAD' => 'Verificación de Lote',
    'PRE_SECADO' => 'Pre-secado',
    'FERMENTACION' => 'Fermentación',
    'SECADO' => 'Secado',
    'CALIDAD_POST' => 'Prueba de Corte',
    'CALIDAD_SALIDA' => 'Calidad de salida',
    'EMPAQUETADO' => 'Empaquetado',
    'ALMACENADO' => 'Almacenado',
    'DESPACHO' => 'Despacho',
    'FINALIZADO' => 'Finalizado',
    'RECHAZADO' => 'Rechazado'
];

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $postAction = trim((string)($_POST['post_action'] ?? 'save'));
    
    $errors = [];
    
    // Validaciones
    $variedadId = intval($_POST['variedad_id'] ?? 0);
    $estadoProductoId = intval($_POST['estado_producto_id'] ?? 0);
    $estadoFermentacionId = intval($_POST['estado_fermentacion_id'] ?? 0) ?: null;
    $pesoActualKg = floatval(str_replace(',', '.', $_POST['peso_actual_kg'] ?? 0));
    $humedadInicial = !empty($_POST['humedad_inicial']) ? floatval(str_replace(',', '.', $_POST['humedad_inicial'])) : null;
    $humedadFinal = !empty($_POST['humedad_final']) ? floatval(str_replace(',', '.', $_POST['humedad_final'])) : null;
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
                'estado_proceso' => $estadoProceso,
                'cajon_fermentacion_id' => $cajonFermentacionId,
                'secadora_id' => $secadoraId,
                'observaciones' => $observaciones
            ], 'id = :where_id', ['where_id' => $id]);
            
            // Registrar en historial
            if (!empty($cambios)) {
                Helpers::logHistory($id, 'MODIFICACION', implode('; ', $cambios), $_SESSION['user_id']);
            }
            
            $fichaRegistroActual = $db->fetchOne(
                "SELECT id FROM fichas_registro WHERE lote_id = ? ORDER BY id DESC LIMIT 1",
                [$id]
            );
            $fermentacionExistente = $db->fetchOne(
                "SELECT id FROM registros_fermentacion WHERE lote_id = ? ORDER BY id DESC LIMIT 1",
                [$id]
            );
            $estadoActualizadoEnFermentacion = strtoupper((string)$estadoProceso) === 'FERMENTACION';

            if ($postAction === 'goto_fermentacion') {
                if ($fermentacionExistente) {
                    setFlash('success', 'Lote actualizado correctamente. Mostrando estado actual de fermentación.');
                    redirect('/fermentacion/control.php?id=' . (int)$fermentacionExistente['id']);
                }

                if (!$fichaRegistroActual) {
                    setFlash('warning', 'Antes de iniciar fermentación debe completar la ficha de recepción.');
                    redirect('/fichas/crear.php?etapa=recepcion&lote_id=' . $id);
                }

                if (!$estadoActualizadoEnFermentacion) {
                    setFlash('warning', 'Para iniciar fermentación, cambie el estado del proceso a Fermentación y guarde.');
                    redirect('/lotes/editar.php?id=' . $id);
                }

                setFlash('success', 'Lote actualizado correctamente. Continúe con la ficha de fermentación.');
                redirect('/fermentacion/crear.php?lote_id=' . $id . '&from=verificacion');
            }

            if ($estadoActualizadoEnFermentacion && $fichaRegistroActual && !$fermentacionExistente) {
                setFlash('success', 'Lote actualizado correctamente. Continúe con la ficha de fermentación.');
                redirect('/fermentacion/crear.php?lote_id=' . $id . '&from=verificacion');
            }

            setFlash('success', 'Lote actualizado correctamente.');
            redirect('/lotes/ver.php?id=' . $id);
            
        } catch (Exception $e) {
            $errors[] = 'Error al actualizar el lote: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Editar Lote';
$pageSubtitle = $lote['codigo'];
$pesoInicialKgVisual = (float)($lote['peso_inicial_kg'] ?? 0);
$pesoInicialQqVisual = Helpers::kgToQQ($pesoInicialKgVisual);
$pesoInicialLbVisual = Helpers::kgToLb($pesoInicialKgVisual);
$pesoActualKgVisual = (float)($lote['peso_actual_kg'] ?? 0);
$pesoActualQqVisual = Helpers::kgToQQ($pesoActualKgVisual);
$pesoActualLbVisual = Helpers::kgToLb($pesoActualKgVisual);

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
                        <p class="mt-2 text-xs text-warmgray">
                            Equivalente: <?= Helpers::formatNumber($pesoInicialKgVisual, 2) ?> KG |
                            <?= Helpers::formatNumber($pesoInicialQqVisual, 2) ?> QQ |
                            <?= Helpers::formatNumber($pesoInicialLbVisual, 2) ?> LB
                        </p>
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
                        <p id="peso_actual_equivalencias" class="mt-2 text-xs text-warmgray">
                            Equivalente: <?= Helpers::formatNumber($pesoActualKgVisual, 2) ?> KG |
                            <?= Helpers::formatNumber($pesoActualQqVisual, 2) ?> QQ |
                            <?= Helpers::formatNumber($pesoActualLbVisual, 2) ?> LB
                        </p>
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
                            <option value="">No aplica</option>
                            <?php foreach ($cajones as $cajon): ?>
                                <option value="<?= $cajon['id'] ?>" <?= $lote['cajon_fermentacion_id'] == $cajon['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)($cajon['nombre'] ?? 'Cajón #' . (int)($cajon['id'] ?? 0))) ?>
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
                                    <?= htmlspecialchars((string)($sec['nombre'] ?? 'Secadora #' . (int)($sec['id'] ?? 0))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Observaciones -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h8m-8 4h6M5 4h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z"/>
                    </svg>
                    Observaciones
                </h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <textarea name="observaciones" class="form-control" rows="3"
                              placeholder="Notas adicionales..."><?= htmlspecialchars($lote['observaciones']) ?></textarea>
                </div>
            </div>
        </div>

        <!-- Guía al siguiente formulario -->
        <div class="card border border-emerald-200 bg-emerald-50/60">
            <div class="card-body">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Siguiente paso</p>
                        <h3 class="text-lg font-semibold text-emerald-900">Proceso de Fermentación</h3>
                        <?php if ($mensajeGuiaFermentacion !== ''): ?>
                            <p class="text-sm text-emerald-800 mt-1"><?= htmlspecialchars($mensajeGuiaFermentacion) ?></p>
                        <?php elseif ($registroFermentacion): ?>
                            <p class="text-sm text-emerald-800 mt-1">Este lote ya tiene fermentación registrada. Puede abrir el control para revisar su estado.</p>
                        <?php endif; ?>
                    </div>
                    <a href="<?= $bloquearAccionFermentacion ? '#' : $rutaFermentacion ?>"
                       class="btn <?= $bloquearAccionFermentacion ? 'btn-outline opacity-50 cursor-not-allowed pointer-events-none' : 'btn-primary' ?>">
                        <?= htmlspecialchars($labelAccionFermentacion) ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Botones -->
        <div class="flex items-center justify-between">
            <button type="button" onclick="confirmDeleteLote()" class="btn btn-danger">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
                Eliminar
            </button>
            
            <div class="flex items-center gap-4">
                <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $id ?>" class="btn btn-outline">
                    Cancelar
                </a>
                <button type="submit" name="post_action" value="goto_fermentacion" class="btn btn-primary">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                    </svg>
                    Guardar e ir a Fermentación
                </button>
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
    const pesoActualEquivalencias = document.getElementById('peso_actual_equivalencias');
    
    const pesoInicial = <?= $lote['peso_inicial_kg'] ?>;

    function kgToQq(kg) {
        return kg / 45.36;
    }

    function kgToLb(kg) {
        return kg / 0.45359237;
    }

    function formatPeso(value) {
        return new Intl.NumberFormat('es-EC', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(value);
    }

    function updateCalculations() {
        const pesoActual = parseFloat(pesoKgInput.value) || 0;
        
        // Calcular quintales
        const qq = kgToQq(pesoActual);
        pesoQQInput.value = qq.toFixed(2);

        if (pesoActualEquivalencias) {
            pesoActualEquivalencias.textContent = `Equivalente: ${formatPeso(pesoActual)} KG | ${formatPeso(qq)} QQ | ${formatPeso(kgToLb(pesoActual))} LB`;
        }
        
        // Calcular merma
        if (pesoInicial > 0) {
            const merma = ((pesoInicial - pesoActual) / pesoInicial) * 100;
            mermaInput.value = merma.toFixed(1);
        }
    }

    pesoKgInput.addEventListener('input', updateCalculations);

    updateCalculations();
});

async function confirmDeleteLote() {
    const confirmed = await App.confirm(
        '¿Está seguro de eliminar este lote? Esta acción no se puede deshacer.',
        'Eliminar lote'
    );
    if (!confirmed) {
        return;
    }

    window.location.href = '<?= APP_URL ?>/lotes/eliminar.php?id=<?= $id ?>&token=<?= generateCSRFToken() ?>';
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
