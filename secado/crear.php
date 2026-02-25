<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Iniciar Secado
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();
$errors = [];

// Compatibilidad de esquema para datos de fermentación
$colsFermentacion = array_column($db->fetchAll("SHOW COLUMNS FROM registros_fermentacion"), 'Field');
$hasFerCol = static fn(string $name): bool => in_array($name, $colsFermentacion, true);
$exprPesoFermentacion = $hasFerCol('peso_final')
    ? 'rf.peso_final'
    : ($hasFerCol('peso_lote_kg') ? 'rf.peso_lote_kg' : 'NULL');
$exprHumedadFermentacion = $hasFerCol('humedad_final')
    ? 'rf.humedad_final'
    : ($hasFerCol('humedad_inicial') ? 'rf.humedad_inicial' : 'NULL');

// Compatibilidad de esquema para secadoras
$colsSecadoras = array_column($db->fetchAll("SHOW COLUMNS FROM secadoras"), 'Field');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSecadoras, true);
$exprSecadoraNombre = $hasSecCol('nombre')
    ? "NULLIF(TRIM(nombre), '')"
    : ($hasSecCol('numero') ? "NULLIF(TRIM(numero), '')" : "NULL");
if ($hasSecCol('capacidad_kg')) {
    $exprSecadoraCapacidadKg = 'capacidad_kg';
} elseif ($hasSecCol('capacidad_qq')) {
    // 1 QQ = 45.3592 kg
    $exprSecadoraCapacidadKg = '(capacidad_qq * 45.3592)';
} else {
    $exprSecadoraCapacidadKg = 'NULL';
}
$whereSecadoraActiva = $hasSecCol('activo') ? 'activo = 1' : '1 = 1';

// Compatibilidad de esquema para registros de secado
$colsRegistroSecado = array_column($db->fetchAll("SHOW COLUMNS FROM registros_secado"), 'Field');
$hasRegSecCol = static fn(string $name): bool => in_array($name, $colsRegistroSecado, true);
$colFechaInicioSecado = $hasRegSecCol('fecha_inicio')
    ? 'fecha_inicio'
    : ($hasRegSecCol('fecha') ? 'fecha' : null);
$colTipoSecadoSecado = $hasRegSecCol('tipo_secado')
    ? 'tipo_secado'
    : ($hasRegSecCol('estado') ? 'estado' : null);
$colPesoInicialSecado = $hasRegSecCol('peso_inicial')
    ? 'peso_inicial'
    : ($hasRegSecCol('qq_cargados')
        ? 'qq_cargados'
        : ($hasRegSecCol('cantidad_total_qq') ? 'cantidad_total_qq' : null));
$colObservacionesSecado = $hasRegSecCol('observaciones')
    ? 'observaciones'
    : ($hasRegSecCol('carga_observaciones')
        ? 'carga_observaciones'
        : ($hasRegSecCol('revision_observaciones') ? 'revision_observaciones' : null));
$exprCierreSecado = $hasRegSecCol('fecha_fin')
    ? 'fecha_fin'
    : ($hasRegSecCol('humedad_final') ? 'humedad_final' : 'NULL');

// Obtener lote si viene por parámetro
$loteId = $_GET['lote_id'] ?? null;
$loteInfo = null;
$loteSinFichaRecepcion = false;
$registroFermentacion = null;
$etapaSecado = null;
$etapaSecadoLabel = static fn(string $etapa): string => $etapa === 'PRE_SECADO' ? 'Pre-secado' : 'Secado final';

if ($loteId) {
    $fichaRegistro = $db->fetch("
        SELECT id FROM fichas_registro WHERE lote_id = :lote_id ORDER BY id DESC LIMIT 1
    ", ['lote_id' => $loteId]);
    $loteSinFichaRecepcion = !$fichaRegistro;

    $loteInfo = $db->fetch("
        SELECT l.*, p.nombre as proveedor, p.codigo as proveedor_codigo, v.nombre as variedad,
               {$exprPesoFermentacion} as peso_fermentacion,
               {$exprHumedadFermentacion} as humedad_fermentacion
        FROM lotes l
        JOIN proveedores p ON l.proveedor_id = p.id
        JOIN variedades v ON l.variedad_id = v.id
        LEFT JOIN registros_fermentacion rf ON rf.lote_id = l.id
        WHERE l.id = :id AND l.estado_proceso IN ('PRE_SECADO', 'SECADO')
    ", ['id' => $loteId]);
    
    if (!$loteInfo) {
        setFlash('error', 'Lote no válido para secado');
        redirect('/secado/index.php');
    }
    $etapaSecado = ($loteInfo['estado_proceso'] ?? '') === 'PRE_SECADO' ? 'PRE_SECADO' : 'SECADO_FINAL';

    if ($loteSinFichaRecepcion) {
        $registroFermentacion = $db->fetch("
            SELECT id FROM registros_fermentacion WHERE lote_id = :lote_id ORDER BY id DESC LIMIT 1
        ", ['lote_id' => $loteId]);
    }
    
    // Verificar duplicidad por etapa (permite una ficha de pre-secado y una de secado final)
    $registrosSecadoExistentes = $db->fetchAll("
        SELECT id, {$exprCierreSecado} as cierre
        FROM registros_secado
        WHERE lote_id = :lote_id
        ORDER BY id ASC
    ", ['lote_id' => $loteId]);
    $totalRegistrosSecado = count($registrosSecadoExistentes);

    if ($etapaSecado === 'PRE_SECADO' && $totalRegistrosSecado >= 1) {
        setFlash('error', 'Este lote ya tiene una ficha de pre-secado registrada.');
        redirect('/secado/control.php?id=' . (int)$registrosSecadoExistentes[0]['id']);
    }
    if ($etapaSecado === 'SECADO_FINAL' && $totalRegistrosSecado >= 2) {
        $ultimoRegistro = end($registrosSecadoExistentes);
        setFlash('error', 'Este lote ya tiene una ficha de secado final registrada.');
        redirect('/secado/control.php?id=' . (int)$ultimoRegistro['id']);
    }
    if ($etapaSecado === 'SECADO_FINAL' && $totalRegistrosSecado === 1 && empty($registrosSecadoExistentes[0]['cierre'])) {
        setFlash('error', 'Ya existe una ficha de secado en proceso para este lote.');
        redirect('/secado/control.php?id=' . (int)$registrosSecadoExistentes[0]['id']);
    }
}

// Obtener secadoras disponibles
$secadoras = $db->fetchAll("
    SELECT id,
           COALESCE({$exprSecadoraNombre}, CONCAT('Secadora #', id)) as nombre,
           " . ($hasSecCol('tipo') ? 'tipo' : 'NULL') . " as tipo,
           {$exprSecadoraCapacidadKg} as capacidad_kg
    FROM secadoras
    WHERE {$whereSecadoraActiva}
    ORDER BY nombre
");
$totalSecadorasActivas = count($secadoras);

// Obtener lotes disponibles para secado
$lotesDisponibles = $db->fetchAll("
    SELECT l.id, l.codigo, l.peso_inicial_kg, p.nombre as proveedor, l.estado_proceso
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    WHERE l.estado_proceso IN ('PRE_SECADO', 'SECADO')
    AND EXISTS (SELECT 1 FROM fichas_registro fr WHERE fr.lote_id = l.id)
    AND (
        (
            l.estado_proceso = 'PRE_SECADO'
            AND (SELECT COUNT(*) FROM registros_secado rs WHERE rs.lote_id = l.id) = 0
        )
        OR
        (
            l.estado_proceso = 'SECADO'
            AND (SELECT COUNT(*) FROM registros_secado rs WHERE rs.lote_id = l.id) < 2
        )
    )
    ORDER BY l.fecha_entrada DESC
");

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    
    $loteId = $_POST['lote_id'] ?? '';
    $secadoraId = $_POST['secadora_id'] ?? null;
    $tipoSecado = $_POST['tipo_secado'] ?? 'SOLAR';
    $etapaSecadoPost = strtoupper(trim((string)($_POST['etapa_secado'] ?? '')));
    $fechaInicio = $_POST['fecha_inicio'] ?? '';
    $pesoInicial = floatval($_POST['peso_inicial'] ?? 0);
    $humedadInicial = floatval($_POST['humedad_inicial'] ?? 0);
    $observaciones = trim($_POST['observaciones'] ?? '');
    
    // Validaciones
    if (!$loteId) $errors[] = 'Debe seleccionar un lote';
    if (!$fechaInicio) $errors[] = 'La fecha de inicio es requerida';
    if ($pesoInicial <= 0) $errors[] = 'El peso inicial debe ser mayor a 0';

    $loteEstadoProceso = null;
    if ($loteId) {
        $loteEstado = $db->fetch("
            SELECT estado_proceso
            FROM lotes
            WHERE id = :id
        ", ['id' => $loteId]);
        $loteEstadoProceso = (string)($loteEstado['estado_proceso'] ?? '');

        if (!in_array($loteEstadoProceso, ['PRE_SECADO', 'SECADO'], true)) {
            $errors[] = 'Lote no válido para iniciar secado.';
        } else {
            $etapaSecado = $loteEstadoProceso === 'PRE_SECADO' ? 'PRE_SECADO' : 'SECADO_FINAL';
            if ($etapaSecadoPost !== '' && $etapaSecadoPost !== $etapaSecado) {
                $errors[] = 'La etapa de secado no coincide con el estado actual del lote.';
            }
        }

        $fichaRegistro = $db->fetch("
            SELECT id FROM fichas_registro WHERE lote_id = :lote_id ORDER BY id DESC LIMIT 1
        ", ['lote_id' => $loteId]);
        if (!$fichaRegistro) {
            $errors[] = 'Debe completar primero la ficha de registro para este lote.';
        }
    }

    if ($loteId && empty($errors)) {
        $registrosSecadoEstado = $db->fetchAll("
            SELECT id, {$exprCierreSecado} as cierre
            FROM registros_secado
            WHERE lote_id = :lote_id
            ORDER BY id ASC
        ", ['lote_id' => $loteId]);
        $registrosSecadoCount = count($registrosSecadoEstado);

        if ($etapaSecado === 'PRE_SECADO' && $registrosSecadoCount >= 1) {
            $errors[] = 'Ya existe una ficha de pre-secado para este lote.';
        }
        if ($etapaSecado === 'SECADO_FINAL' && $registrosSecadoCount >= 2) {
            $errors[] = 'Ya existe una ficha de secado final para este lote.';
        }
        if ($etapaSecado === 'SECADO_FINAL' && $registrosSecadoCount === 1 && empty($registrosSecadoEstado[0]['cierre'])) {
            $errors[] = 'Ya existe una ficha de secado en proceso para este lote.';
        }
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Crear registro de secado
            $dataSecado = [
                'lote_id' => $loteId
            ];
            if ($hasRegSecCol('secadora_id')) {
                $dataSecado['secadora_id'] = $secadoraId ?: null;
            }
            if ($hasRegSecCol('etapa_proceso')) {
                $dataSecado['etapa_proceso'] = ($etapaSecado === 'PRE_SECADO') ? 'PRE_SECADO' : 'SECADO_FINAL';
            }
            if ($colTipoSecadoSecado) {
                $dataSecado[$colTipoSecadoSecado] = $tipoSecado;
            }
            if ($colFechaInicioSecado) {
                $dataSecado[$colFechaInicioSecado] = $fechaInicio;
            }
            if ($hasRegSecCol('fecha') && $colFechaInicioSecado !== 'fecha') {
                $dataSecado['fecha'] = $fechaInicio;
            }
            if ($colPesoInicialSecado === 'peso_inicial') {
                $dataSecado['peso_inicial'] = $pesoInicial;
            } elseif ($colPesoInicialSecado === 'qq_cargados') {
                $dataSecado['qq_cargados'] = Helpers::kgToQQ($pesoInicial);
            } elseif ($colPesoInicialSecado === 'cantidad_total_qq') {
                $dataSecado['cantidad_total_qq'] = Helpers::kgToQQ($pesoInicial);
            }
            if ($hasRegSecCol('humedad_inicial')) {
                $dataSecado['humedad_inicial'] = $humedadInicial ?: null;
            }
            if ($colObservacionesSecado) {
                $dataSecado[$colObservacionesSecado] = $observaciones !== '' ? $observaciones : null;
            }
            if ($hasRegSecCol('variedad') && is_array($loteInfo) && !empty($loteInfo['variedad'])) {
                $dataSecado['variedad'] = $loteInfo['variedad'];
            }
            if ($hasRegSecCol('responsable_id')) {
                $dataSecado['responsable_id'] = getCurrentUserId();
            }

            $secadoId = $db->insert('registros_secado', $dataSecado);
            
            // Registrar historial
            $etapaHistorial = ($etapaSecado === 'PRE_SECADO') ? 'Pre-secado' : 'Secado final';
            Helpers::logHistory($loteId, 'SECADO', 'Inicio de ' . $etapaHistorial . ' (' . $tipoSecado . ')', getCurrentUserId());
            
            $db->commit();
            
            setFlash('success', (($etapaSecado === 'PRE_SECADO') ? 'Pre-secado' : 'Secado final') . ' iniciado correctamente');
            redirect('/secado/control.php?id=' . $secadoId);
            
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

$etapaSecadoVista = $etapaSecado;
if ($etapaSecadoVista === null) {
    $etapaSecadoVista = (strtoupper(trim((string)($_POST['etapa_secado'] ?? ''))) === 'PRE_SECADO')
        ? 'PRE_SECADO'
        : 'SECADO_FINAL';
}
$tituloEtapaSecado = $etapaSecadoLabel($etapaSecadoVista);
$pageTitle = 'Iniciar ' . $tituloEtapaSecado;
$pageSubtitle = 'Registrar nuevo proceso de ' . strtolower($tituloEtapaSecado);

ob_start();
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error mb-6">
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

<?php if ($loteSinFichaRecepcion && $loteInfo): ?>
    <div class="max-w-4xl space-y-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Información del Lote</h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="form-label">Código de Lote</label>
                        <div class="form-control bg-olive/10 font-medium text-primary">
                            <?= htmlspecialchars($loteInfo['codigo']) ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Proveedor</label>
                        <div class="form-control bg-olive/10">
                            <span class="font-bold text-primary"><?= htmlspecialchars($loteInfo['proveedor_codigo']) ?></span>
                            - <?= htmlspecialchars($loteInfo['proveedor']) ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Variedad</label>
                        <div class="form-control bg-olive/10"><?= htmlspecialchars($loteInfo['variedad']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border border-amber-200 bg-amber-50/70">
            <div class="card-body">
                <div class="flex items-start gap-4">
                    <div class="p-3 bg-amber-100 rounded-lg">
                        <svg class="w-6 h-6 text-amber-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-lg font-semibold text-amber-900">Requisito pendiente: ficha de recepción</h3>
                        <p class="text-sm text-amber-800 mt-2">
                            Para iniciar secado, este lote debe tener una ficha de recepción asociada.
                            Complete esa ficha y luego continúe con el secado.
                        </p>
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <a href="<?= APP_URL ?>/fichas/crear.php?etapa=recepcion&lote_id=<?= (int)$loteInfo['id'] ?>&next=secado"
                               class="btn btn-primary">
                                Completar ficha de recepción
                            </a>
                            <?php if ($registroFermentacion): ?>
                                <a href="<?= APP_URL ?>/fermentacion/control.php?id=<?= (int)$registroFermentacion['id'] ?>"
                                   class="btn btn-outline">
                                    Volver a fermentación
                                </a>
                            <?php endif; ?>
                            <a href="<?= APP_URL ?>/secado/index.php" class="btn btn-outline">Volver al listado</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
<form method="POST" class="max-w-4xl">
    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
    <?php
        $etapaFormulario = $etapaSecado ?? $etapaSecadoVista;
    ?>
    <input type="hidden" name="etapa_secado" id="etapa_secado" value="<?= htmlspecialchars($etapaFormulario) ?>">

    <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Etapa de la ficha</p>
        <p id="etapa_secado_label" class="text-lg font-semibold text-blue-900">
            <?= htmlspecialchars($etapaSecadoLabel($etapaFormulario)) ?>
        </p>
    </div>
    <?php if ($totalSecadorasActivas < 13): ?>
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm text-amber-900">
                Actualmente hay <strong><?= (int)$totalSecadorasActivas ?></strong> secadora(s) activa(s).
                Recomendación del proceso: configurar un total de <strong>13 secadoras</strong>.
            </p>
        </div>
    <?php endif; ?>
    
    <!-- Selección de Lote -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Información del Lote</h3>
        </div>
        <div class="card-body">
            <?php if ($loteInfo): ?>
                <input type="hidden" name="lote_id" value="<?= $loteInfo['id'] ?>">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="form-label">Código de Lote</label>
                        <div class="form-control bg-olive/10 font-medium text-primary">
                            <?= htmlspecialchars($loteInfo['codigo']) ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Proveedor</label>
                        <div class="form-control bg-olive/10">
                            <span class="font-bold text-primary"><?= htmlspecialchars($loteInfo['proveedor_codigo']) ?></span>
                            - <?= htmlspecialchars($loteInfo['proveedor']) ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Variedad</label>
                        <div class="form-control bg-olive/10"><?= htmlspecialchars($loteInfo['variedad']) ?></div>
                    </div>
                </div>
                <?php if ($loteInfo['peso_fermentacion'] || $loteInfo['humedad_fermentacion']): ?>
                    <div class="mt-4 p-4 bg-green-50 rounded-lg">
                        <p class="text-sm font-medium text-green-800">Datos de Fermentación:</p>
                        <p class="text-sm text-green-700">
                            Peso: <?= $loteInfo['peso_fermentacion'] ? number_format($loteInfo['peso_fermentacion'], 2) . ' Kg' : 'No registrado' ?> |
                            Humedad: <?= $loteInfo['humedad_fermentacion'] ? number_format($loteInfo['humedad_fermentacion'], 1) . '%' : 'No registrada' ?>
                        </p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="form-group">
                    <label class="form-label required">Seleccionar Lote</label>
                    <select name="lote_id" class="form-control form-select" required>
                        <option value="">-- Seleccione un lote --</option>
                        <?php foreach ($lotesDisponibles as $lote): ?>
                            <?php $etapaOpcion = ($lote['estado_proceso'] ?? '') === 'PRE_SECADO' ? 'PRE_SECADO' : 'SECADO_FINAL'; ?>
                            <option value="<?= $lote['id'] ?>" data-etapa="<?= htmlspecialchars($etapaOpcion) ?>" <?= (isset($_POST['lote_id']) && $_POST['lote_id'] == $lote['id']) ? 'selected' : '' ?>>
                                <?= $etapaOpcion === 'PRE_SECADO' ? '[Pre-secado] ' : '[Secado final] ' ?>
                                <?= htmlspecialchars($lote['codigo']) ?> - <?= htmlspecialchars($lote['proveedor']) ?> 
                                (<?= number_format($lote['peso_inicial_kg'], 2) ?> Kg)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($lotesDisponibles)): ?>
                        <p class="text-sm text-warmgray mt-2">No hay lotes disponibles para secado</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Datos de Secado -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Datos de <?= htmlspecialchars($etapaSecadoLabel($etapaFormulario)) ?></h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="form-group">
                    <label class="form-label required">Fecha de Inicio</label>
                    <input type="date" name="fecha_inicio" class="form-control" required
                           value="<?= $_POST['fecha_inicio'] ?? date('Y-m-d') ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Tipo de Secado</label>
                    <select name="tipo_secado" class="form-control form-select" required>
                        <option value="SOLAR" <?= ($_POST['tipo_secado'] ?? '') === 'SOLAR' ? 'selected' : '' ?>>Solar (Tendal)</option>
                        <option value="MECANICO" <?= ($_POST['tipo_secado'] ?? '') === 'MECANICO' ? 'selected' : '' ?>>Mecánico (Secadora)</option>
                        <option value="MIXTO" <?= ($_POST['tipo_secado'] ?? '') === 'MIXTO' ? 'selected' : '' ?>>Mixto</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Secadora</label>
                    <select name="secadora_id" class="form-control form-select">
                        <option value="">-- Sin asignar --</option>
                        <?php foreach ($secadoras as $sec): ?>
                            <?php
                                $tipoSecadora = trim((string)($sec['tipo'] ?? ''));
                                $capacidadTexto = is_numeric($sec['capacidad_kg'] ?? null)
                                    ? number_format((float)$sec['capacidad_kg'], 0) . ' Kg'
                                    : 'Capacidad N/D';
                            ?>
                            <option value="<?= $sec['id'] ?>" <?= (isset($_POST['secadora_id']) && $_POST['secadora_id'] == $sec['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sec['nombre']) ?> 
                                (<?= $tipoSecadora !== '' ? htmlspecialchars($tipoSecadora) : 'Tipo N/D' ?> - <?= htmlspecialchars($capacidadTexto) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Peso Inicial (Kg)</label>
                    <input type="number" name="peso_inicial" class="form-control" required
                           step="0.01" min="0"
                           value="<?= $_POST['peso_inicial'] ?? ($loteInfo['peso_fermentacion'] ?? $loteInfo['peso_inicial_kg'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Humedad Inicial (%)</label>
                    <input type="number" name="humedad_inicial" class="form-control"
                           step="0.1" min="0" max="100"
                           value="<?= $_POST['humedad_inicial'] ?? ($loteInfo['humedad_fermentacion'] ?? '') ?>">
                    <p class="text-xs text-warmgray mt-1">Típicamente entre 40-50% después de fermentación</p>
                </div>
            </div>
            
            <div class="form-group mt-6">
                <label class="form-label">Observaciones</label>
                <textarea name="observaciones" class="form-control" rows="3"
                          placeholder="Observaciones adicionales..."><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
    
    <!-- Información del Proceso -->
    <div class="card mb-6 bg-olive/10">
        <div class="card-body">
            <div class="flex items-start gap-4">
                <div class="p-3 bg-primary/10 rounded-lg">
                    <svg class="w-6 h-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </div>
                <div>
                    <h4 class="font-semibold text-primary mb-2">Proceso de <?= htmlspecialchars($etapaSecadoLabel($etapaFormulario)) ?></h4>
                    <ul class="text-sm text-warmgray space-y-1">
                        <li>• El secado típico dura entre 5-8 días (solar) o 1-2 días (mecánico)</li>
                        <li>• La humedad objetivo final es 6-7%</li>
                        <li>• Temperatura máxima recomendada: 60°C</li>
                        <li>• Se deben registrar temperaturas cada 2 horas</li>
                        <li>• Configuración recomendada del sistema: 13 secadoras activas</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Botones -->
    <div class="flex items-center gap-4">
        <button type="submit" class="btn btn-primary">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            Iniciar <?= htmlspecialchars($etapaSecadoLabel($etapaFormulario)) ?>
        </button>
        <a href="<?= APP_URL ?>/secado/index.php" class="btn btn-outline">Cancelar</a>
    </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const loteSelect = document.querySelector('select[name=\"lote_id\"]');
    const etapaInput = document.getElementById('etapa_secado');
    const etapaLabel = document.getElementById('etapa_secado_label');
    if (!loteSelect || !etapaInput || !etapaLabel) {
        return;
    }

    const syncEtapa = () => {
        const option = loteSelect.options[loteSelect.selectedIndex];
        const etapa = option?.dataset?.etapa === 'PRE_SECADO' ? 'PRE_SECADO' : 'SECADO_FINAL';
        etapaInput.value = etapa;
        etapaLabel.textContent = etapa === 'PRE_SECADO' ? 'Pre-secado' : 'Secado final';
    };

    loteSelect.addEventListener('change', syncEtapa);
    syncEtapa();
});
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
