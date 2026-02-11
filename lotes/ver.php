<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Ver Detalle del Lote
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

// Obtener lote con información relacionada
$lote = $db->fetch("
    SELECT l.*, 
           p.nombre as proveedor, p.codigo as proveedor_codigo,
           v.nombre as variedad,
           ep.nombre as estado_producto,
           ef.nombre as estado_fermentacion,
           u.nombre as usuario,
           cf.nombre as cajon_fermentacion,
           s.nombre as secadora
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    JOIN variedades v ON l.variedad_id = v.id
    JOIN estados_producto ep ON l.estado_producto_id = ep.id
    LEFT JOIN estados_fermentacion ef ON l.estado_fermentacion_id = ef.id
    JOIN usuarios u ON l.usuario_id = u.id
    LEFT JOIN cajones_fermentacion cf ON l.cajon_fermentacion_id = cf.id
    LEFT JOIN secadoras s ON l.secadora_id = s.id
    WHERE l.id = ?
", [$id]);

if (!$lote) {
    setFlash('error', 'Lote no encontrado');
    redirect('/lotes/index.php');
}

// Obtener historial
$historial = $db->fetchAll("
    SELECT h.*, u.nombre as usuario
    FROM lotes_historial h
    LEFT JOIN usuarios u ON h.usuario_id = u.id
    WHERE h.lote_id = ?
    ORDER BY h.created_at DESC
", [$id]);

// Obtener registros relacionados
$fichaRegistro = $db->fetch("SELECT * FROM fichas_registro WHERE lote_id = ? ORDER BY id DESC LIMIT 1", [$id]);
$registroFermentacion = $db->fetch("SELECT * FROM registros_fermentacion WHERE lote_id = ? ORDER BY id DESC LIMIT 1", [$id]);
$registroSecado = $db->fetch("SELECT * FROM registros_secado WHERE lote_id = ? ORDER BY id DESC LIMIT 1", [$id]);
$registroPruebaCorte = $db->fetch("SELECT * FROM registros_prueba_corte WHERE lote_id = ? ORDER BY id DESC LIMIT 1", [$id]);

$colsFichas = array_column($db->fetchAll("SHOW COLUMNS FROM fichas_registro"), 'Field');
$hasFichaCol = static fn(string $name): bool => in_array($name, $colsFichas, true);
$camposPagoCompletos = $hasFichaCol('fecha_pago')
    && $hasFichaCol('factura_compra')
    && $hasFichaCol('cantidad_comprada')
    && $hasFichaCol('forma_pago');

$tieneFichaRegistro = (bool)$fichaRegistro;
$tieneRegistroPago = false;
if ($tieneFichaRegistro) {
    if ($camposPagoCompletos) {
        $tieneRegistroPago = !empty($fichaRegistro['fecha_pago'])
            && trim((string)($fichaRegistro['factura_compra'] ?? '')) !== ''
            && isset($fichaRegistro['cantidad_comprada']) && (float)$fichaRegistro['cantidad_comprada'] > 0
            && trim((string)($fichaRegistro['forma_pago'] ?? '')) !== '';
    } else {
        $tieneRegistroPago = isset($fichaRegistro['precio_total_pagar']) && $fichaRegistro['precio_total_pagar'] !== null;
    }
}
$tieneCodificacion = $tieneFichaRegistro && trim((string)($fichaRegistro['codificacion'] ?? '')) !== '';
$rutaFicha = APP_URL . '/fichas/' . ($tieneFichaRegistro ? 'ver.php?id=' . (int)$fichaRegistro['id'] : 'crear.php?etapa=recepcion&lote_id=' . $id);
$rutaPago = $tieneFichaRegistro ? (APP_URL . '/fichas/pago.php?id=' . (int)$fichaRegistro['id']) : '#';
$rutaCodificacion = $tieneFichaRegistro ? (APP_URL . '/fichas/codificacion.php?id=' . (int)$fichaRegistro['id']) : '#';
$rutaEtiqueta = $tieneFichaRegistro ? (APP_URL . '/fichas/etiqueta.php?id=' . (int)$fichaRegistro['id']) : '#';
$rutaFermentacion = APP_URL . '/fermentacion/' . ($registroFermentacion ? 'ver.php?id=' . (int)$registroFermentacion['id'] : 'crear.php?lote_id=' . $id);
$rutaSecado = APP_URL . '/secado/' . ($registroSecado ? 'ver.php?id=' . (int)$registroSecado['id'] : 'crear.php?lote_id=' . $id);
$rutaPruebaCorte = APP_URL . '/prueba-corte/' . ($registroPruebaCorte ? 'ver.php?id=' . (int)$registroPruebaCorte['id'] : 'crear.php?lote_id=' . $id);

// Estados del proceso para el timeline
$estadosProceso = [
    'RECEPCION' => ['icon' => 'truck', 'label' => 'Recepción'],
    'CALIDAD' => ['icon' => 'check-circle', 'label' => 'Verificación de Lote'],
    'PRE_SECADO' => ['icon' => 'sun', 'label' => 'Pre-secado (Legado)'],
    'FERMENTACION' => ['icon' => 'fire', 'label' => 'Fermentación'],
    'SECADO' => ['icon' => 'sun', 'label' => 'Secado'],
    'CALIDAD_POST' => ['icon' => 'clipboard-check', 'label' => 'Prueba de Corte'],
    'EMPAQUETADO' => ['icon' => 'archive', 'label' => 'Empaquetado'],
    'ALMACENADO' => ['icon' => 'database', 'label' => 'Almacenado'],
    'DESPACHO' => ['icon' => 'truck', 'label' => 'Despacho'],
    'FINALIZADO' => ['icon' => 'flag', 'label' => 'Finalizado'],
    'RECHAZADO' => ['icon' => 'x-circle', 'label' => 'Rechazado']
];

$estadoActualIndex = array_search($lote['estado_proceso'], array_keys($estadosProceso));

$pageTitle = 'Lote ' . $lote['codigo'];
$pageSubtitle = 'Detalle del lote';

ob_start();
?>

<div class="max-w-6xl mx-auto">
    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-sm text-warmgray mb-6">
        <a href="<?= APP_URL ?>/lotes/index.php" class="hover:text-primary">Lotes</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        <span class="text-primary font-medium"><?= htmlspecialchars($lote['codigo']) ?></span>
    </div>

    <!-- Header con código -->
    <div class="card mb-6 bg-gradient-to-r from-primary to-primary/80">
        <div class="card-body">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                    <div class="text-white">
                        <h1 class="text-3xl font-bold"><?= htmlspecialchars($lote['codigo']) ?></h1>
                        <p class="opacity-80"><?= htmlspecialchars($lote['proveedor']) ?> • <?= htmlspecialchars($lote['variedad']) ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <a href="<?= APP_URL ?>/lotes/editar.php?id=<?= $lote['id'] ?>" class="btn bg-white/20 text-white hover:bg-white/30 border-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        Editar
                    </a>
                    <?= Helpers::getEstadoProcesoBadge($lote['estado_proceso']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Timeline del Proceso -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">
                <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                </svg>
                Progreso del Proceso
            </h3>
        </div>
        <div class="card-body overflow-x-auto">
            <div class="process-timeline">
                <?php $index = 0; foreach ($estadosProceso as $estado => $info): ?>
                    <?php
                    $isActive = $estado === $lote['estado_proceso'];
                    $isCompleted = $index < $estadoActualIndex;
                    $class = $isActive ? 'active' : ($isCompleted ? 'completed' : '');
                    ?>
                    <div class="timeline-step <?= $class ?>">
                        <div class="timeline-icon">
                            <?php if ($isCompleted): ?>
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                            <?php else: ?>
                                <span class="text-xs font-bold"><?= $index + 1 ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="timeline-label"><?= $info['label'] ?></span>
                    </div>
                <?php $index++; endforeach; ?>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Columna principal -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Información del Lote -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Información General</h3>
                </div>
                <div class="card-body">
                    <dl class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-sm text-warmgray">Fecha de Entrada</dt>
                            <dd class="font-medium"><?= Helpers::formatDate($lote['fecha_entrada']) ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm text-warmgray">Proveedor</dt>
                            <dd class="font-medium">
                                <span class="inline-flex items-center gap-2">
                                    <span class="w-7 h-7 bg-olive/30 rounded text-xs font-bold flex items-center justify-center text-primary">
                                        <?= htmlspecialchars($lote['proveedor_codigo']) ?>
                                    </span>
                                    <?= htmlspecialchars($lote['proveedor']) ?>
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-warmgray">Variedad</dt>
                            <dd class="font-medium"><?= htmlspecialchars($lote['variedad']) ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm text-warmgray">Estado del Producto</dt>
                            <dd><span class="badge badge-primary"><?= htmlspecialchars($lote['estado_producto']) ?></span></dd>
                        </div>
                        <?php if ($lote['estado_fermentacion']): ?>
                        <div>
                            <dt class="text-sm text-warmgray">Estado Fermentación</dt>
                            <dd><span class="badge badge-gold"><?= htmlspecialchars($lote['estado_fermentacion']) ?></span></dd>
                        </div>
                        <?php endif; ?>
                        <div>
                            <dt class="text-sm text-warmgray">Registrado por</dt>
                            <dd class="font-medium"><?= htmlspecialchars($lote['usuario']) ?></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Pesos -->
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
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="text-center p-4 bg-olive/10 rounded-xl">
                            <p class="text-2xl font-bold text-primary"><?= Helpers::formatNumber($lote['peso_inicial_kg'], 2) ?></p>
                            <p class="text-sm text-warmgray">Peso Inicial (Kg)</p>
                        </div>
                        <div class="text-center p-4 bg-olive/10 rounded-xl">
                            <p class="text-2xl font-bold text-primary"><?= Helpers::formatNumber($lote['peso_inicial_qq'], 2) ?></p>
                            <p class="text-sm text-warmgray">Peso Inicial (QQ)</p>
                        </div>
                        <div class="text-center p-4 bg-gold/20 rounded-xl">
                            <p class="text-2xl font-bold text-gold"><?= Helpers::formatNumber($lote['peso_actual_kg'], 2) ?></p>
                            <p class="text-sm text-warmgray">Peso Actual (Kg)</p>
                        </div>
                        <div class="text-center p-4 bg-gold/20 rounded-xl">
                            <p class="text-2xl font-bold text-gold"><?= Helpers::formatNumber($lote['peso_actual_qq'], 2) ?></p>
                            <p class="text-sm text-warmgray">Peso Actual (QQ)</p>
                        </div>
                    </div>
                    
                    <?php if ($lote['peso_inicial_kg'] > $lote['peso_actual_kg']): ?>
                        <?php $merma = (($lote['peso_inicial_kg'] - $lote['peso_actual_kg']) / $lote['peso_inicial_kg']) * 100; ?>
                        <div class="mt-4 p-4 bg-warmgray/10 rounded-xl">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-warmgray">Merma total:</span>
                                <span class="font-bold text-warmgray"><?= Helpers::formatNumber($merma, 1) ?>%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                                <div class="bg-warmgray h-2 rounded-full" style="width: <?= min($merma, 100) ?>%"></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($lote['humedad_inicial'] || $lote['humedad_final']): ?>
                        <div class="grid grid-cols-2 gap-4 mt-4 pt-4 border-t border-gray-100">
                            <?php if ($lote['humedad_inicial']): ?>
                            <div>
                                <p class="text-sm text-warmgray">Humedad Inicial</p>
                                <p class="text-xl font-bold"><?= Helpers::formatNumber($lote['humedad_inicial'], 1) ?>%</p>
                            </div>
                            <?php endif; ?>
                            <?php if ($lote['humedad_final']): ?>
                            <div>
                                <p class="text-sm text-warmgray">Humedad Final</p>
                                <p class="text-xl font-bold"><?= Helpers::formatNumber($lote['humedad_final'], 1) ?>%</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Registros del Proceso -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Flujo de Proceso
                    </h3>
                </div>
                <div class="card-body space-y-6">
                    <div>
                        <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-600 mb-3">1. Procesos Centro de Acopio</h4>
                        <div class="space-y-3">
                            <a href="<?= $rutaFicha ?>" class="flex items-center justify-between gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50">
                                <span class="font-medium text-gray-800">a. Recepción (Ficha de Recepción)</span>
                                <span class="text-xs px-2 py-1 rounded-full <?= $tieneFichaRegistro ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                    <?= $tieneFichaRegistro ? 'Completado' : 'Pendiente' ?>
                                </span>
                            </a>
                            <a href="<?= $rutaPago ?>"
                               class="flex items-center justify-between gap-3 p-3 rounded-lg border border-gray-200 <?= $tieneFichaRegistro ? 'hover:bg-gray-50' : 'opacity-60 cursor-not-allowed pointer-events-none' ?>">
                                <span class="font-medium text-gray-800">b. Registro de Pagos (Ficha de pagos)</span>
                                <span class="text-xs px-2 py-1 rounded-full <?= $tieneRegistroPago ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                    <?= $tieneRegistroPago ? 'Registrado' : 'Pendiente' ?>
                                </span>
                            </a>
                            <a href="<?= $rutaCodificacion ?>"
                               class="flex items-center justify-between gap-3 p-3 rounded-lg border border-gray-200 <?= $tieneFichaRegistro ? 'hover:bg-gray-50' : 'opacity-60 cursor-not-allowed pointer-events-none' ?>">
                                <span class="font-medium text-gray-800">c. Codificación de Lote</span>
                                <span class="text-xs px-2 py-1 rounded-full <?= $tieneCodificacion ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                    <?= $tieneCodificacion ? 'Codificado' : 'Pendiente' ?>
                                </span>
                            </a>
                            <a href="<?= $rutaEtiqueta ?>"
                               class="flex items-center justify-between gap-3 p-3 rounded-lg border border-gray-200 <?= $tieneFichaRegistro ? 'hover:bg-gray-50' : 'opacity-60 cursor-not-allowed pointer-events-none' ?>">
                                <span class="font-medium text-gray-800">i. Imprimir Etiqueta (Etiquetado de registro)</span>
                                <span class="text-xs px-2 py-1 rounded-full <?= $tieneFichaRegistro ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700' ?>">
                                    <?= $tieneFichaRegistro ? 'Disponible' : 'Pendiente' ?>
                                </span>
                            </a>
                        </div>
                        <?php if (!$tieneFichaRegistro): ?>
                        <p class="text-xs text-amber-700 mt-3">Primero debe completar la ficha de recepción para habilitar pago, codificación y etiqueta.</p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-600 mb-3">2. Procesos Planta</h4>
                        <div class="space-y-3">
                            <a href="<?= APP_URL ?>/lotes/editar.php?id=<?= $lote['id'] ?>" class="flex items-center justify-between gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50">
                                <span class="font-medium text-gray-800">a. Verificación de Lote</span>
                                <span class="text-xs px-2 py-1 rounded-full bg-blue-100 text-blue-700">Formulario</span>
                            </a>
                            <a href="<?= $tieneFichaRegistro ? $rutaFermentacion : '#' ?>"
                               class="flex items-center justify-between gap-3 p-3 rounded-lg border border-gray-200 <?= $tieneFichaRegistro ? 'hover:bg-gray-50' : 'opacity-60 cursor-not-allowed' ?>">
                                <span class="font-medium text-gray-800">b. Fermentación (Ficha de fermentación)</span>
                                <span class="text-xs px-2 py-1 rounded-full <?= $registroFermentacion ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                    <?= $registroFermentacion ? 'Registrado' : 'Pendiente' ?>
                                </span>
                            </a>
                            <a href="<?= $tieneFichaRegistro ? $rutaSecado : '#' ?>"
                               class="flex items-center justify-between gap-3 p-3 rounded-lg border border-gray-200 <?= $tieneFichaRegistro ? 'hover:bg-gray-50' : 'opacity-60 cursor-not-allowed' ?>">
                                <span class="font-medium text-gray-800">c. Secado (Ficha de secado)</span>
                                <span class="text-xs px-2 py-1 rounded-full <?= $registroSecado ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                    <?= $registroSecado ? 'Registrado' : 'Pendiente' ?>
                                </span>
                            </a>
                            <a href="<?= $tieneFichaRegistro ? $rutaPruebaCorte : '#' ?>"
                               class="flex items-center justify-between gap-3 p-3 rounded-lg border border-gray-200 <?= $tieneFichaRegistro ? 'hover:bg-gray-50' : 'opacity-60 cursor-not-allowed' ?>">
                                <span class="font-medium text-gray-800">d. Prueba de Corte (Ficha de Prueba de Corte)</span>
                                <span class="text-xs px-2 py-1 rounded-full <?= $registroPruebaCorte ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                    <?= $registroPruebaCorte ? 'Completado' : 'Pendiente' ?>
                                </span>
                            </a>
                        </div>
                        <?php if (!$tieneFichaRegistro): ?>
                        <p class="text-xs text-amber-700 mt-3">Primero debe completar la ficha de registro para habilitar procesos de planta.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Observaciones -->
            <?php if ($lote['observaciones']): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Observaciones</h3>
                </div>
                <div class="card-body">
                    <p class="text-warmgray whitespace-pre-wrap"><?= htmlspecialchars($lote['observaciones']) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Columna lateral -->
        <div class="space-y-6">
            <!-- Información comercial -->
            <?php if ($lote['precio_kg']): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Información Comercial
                    </h3>
                </div>
                <div class="card-body space-y-4">
                    <div>
                        <p class="text-sm text-warmgray">Precio por Kg</p>
                        <p class="text-2xl font-bold text-primary">$<?= Helpers::formatNumber($lote['precio_kg'], 2) ?></p>
                    </div>
                    <div class="pt-4 border-t border-gray-100">
                        <p class="text-sm text-warmgray">Total Estimado</p>
                        <p class="text-3xl font-bold text-gold">$<?= Helpers::formatNumber($lote['peso_inicial_kg'] * $lote['precio_kg'], 2) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Acciones rápidas -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Menú del Proceso Detallado</h3>
                </div>
                <div class="card-body space-y-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Centro de Acopio</p>
                        <div class="space-y-2">
                            <a href="<?= $rutaFicha ?>" class="btn btn-outline w-full justify-start">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2"/>
                                </svg>
                                1.a Recepción (Ficha de Recepción)
                            </a>
                            <a href="<?= $rutaPago ?>" class="btn w-full justify-start <?= $tieneFichaRegistro ? 'btn-outline' : 'btn-outline opacity-50 cursor-not-allowed pointer-events-none' ?>">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2"/>
                                </svg>
                                1.b Registro de Pagos (Ficha de pagos)
                            </a>
                            <a href="<?= $rutaCodificacion ?>" class="btn w-full justify-start <?= $tieneFichaRegistro ? 'btn-outline' : 'btn-outline opacity-50 cursor-not-allowed pointer-events-none' ?>">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.53 0 1.04.21 1.41.59l6 6a2 2 0 010 2.82l-4.18 4.18a2 2 0 01-2.82 0l-6-6A2 2 0 016 9V4a1 1 0 011-1z"/>
                                </svg>
                                1.c Codificación de Lote
                            </a>
                            <a href="<?= $rutaEtiqueta ?>" class="btn w-full justify-start <?= $tieneFichaRegistro ? 'btn-outline' : 'btn-outline opacity-50 cursor-not-allowed pointer-events-none' ?>">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2"/>
                                </svg>
                                1.i Imprimir Etiqueta (Etiquetado de registro)
                            </a>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Procesos Planta</p>
                        <div class="space-y-2">
                            <a href="<?= APP_URL ?>/lotes/editar.php?id=<?= $lote['id'] ?>" class="btn btn-outline w-full justify-start">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11"/>
                                </svg>
                                2.a Verificación de Lote
                            </a>
                            <a href="<?= $tieneFichaRegistro ? $rutaFermentacion : '#' ?>"
                               class="btn w-full justify-start <?= $tieneFichaRegistro ? 'btn-primary' : 'btn-outline opacity-50 cursor-not-allowed pointer-events-none' ?>">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10"/>
                                </svg>
                                2.b Fermentación (Ficha de fermentación)
                            </a>
                            <a href="<?= $tieneFichaRegistro ? $rutaSecado : '#' ?>"
                               class="btn w-full justify-start <?= $tieneFichaRegistro ? 'btn-primary' : 'btn-outline opacity-50 cursor-not-allowed pointer-events-none' ?>">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3"/>
                                </svg>
                                2.c Secado (Ficha de secado)
                            </a>
                            <a href="<?= $tieneFichaRegistro ? $rutaPruebaCorte : '#' ?>"
                               class="btn w-full justify-start <?= $tieneFichaRegistro ? 'btn-primary' : 'btn-outline opacity-50 cursor-not-allowed pointer-events-none' ?>">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10"/>
                                </svg>
                                2.d Prueba de Corte (Ficha de prueba de corte)
                            </a>
                        </div>
                    </div>

                    <?php if (!$tieneFichaRegistro): ?>
                    <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-amber-700 text-xs">
                        Debe completar primero la ficha de registro para habilitar los procesos de planta.
                    </div>
                    <?php endif; ?>

                    <a href="<?= APP_URL ?>/reportes/lote.php?id=<?= $lote['id'] ?>" class="btn btn-outline w-full justify-start">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Ver Reporte
                    </a>
                    
                    <a href="<?= APP_URL ?>/lotes/index.php" class="btn btn-outline w-full justify-start">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"/>
                        </svg>
                        Volver al Listado
                    </a>
                </div>
            </div>

            <!-- Historial -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Historial
                    </h3>
                </div>
                <div class="card-body">
                    <?php if (empty($historial)): ?>
                        <p class="text-warmgray text-center py-4">Sin registros de historial</p>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach (array_slice($historial, 0, 10) as $h): ?>
                                <div class="flex gap-3">
                                    <div class="w-2 h-2 mt-2 bg-primary rounded-full flex-shrink-0"></div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium"><?= htmlspecialchars($h['accion']) ?></p>
                                        <?php $detalleHistorial = trim((string)($h['descripcion'] ?? $h['detalle'] ?? '')); ?>
                                        <?php if ($detalleHistorial !== ''): ?>
                                            <p class="text-xs text-warmgray truncate"><?= htmlspecialchars($detalleHistorial) ?></p>
                                        <?php endif; ?>
                                        <p class="text-xs text-warmgray mt-1">
                                            <?= date('d/m/Y H:i', strtotime($h['created_at'])) ?>
                                            <?php if ($h['usuario']): ?> • <?= htmlspecialchars($h['usuario']) ?><?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
