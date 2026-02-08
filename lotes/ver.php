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
$fichaRegistro = $db->fetch("SELECT * FROM fichas_registro WHERE lote_id = ?", [$id]);
$registroFermentacion = $db->fetch("SELECT * FROM registros_fermentacion WHERE lote_id = ?", [$id]);
$registroSecado = $db->fetch("SELECT * FROM registros_secado WHERE lote_id = ?", [$id]);
$registroPruebaCorte = $db->fetch("SELECT * FROM registros_prueba_corte WHERE lote_id = ?", [$id]);

// Estados del proceso para el timeline
$estadosProceso = [
    'RECEPCION' => ['icon' => 'truck', 'label' => 'Recepción'],
    'CALIDAD' => ['icon' => 'check-circle', 'label' => 'Control Calidad'],
    'PRE_SECADO' => ['icon' => 'sun', 'label' => 'Pre-secado'],
    'FERMENTACION' => ['icon' => 'fire', 'label' => 'Fermentación'],
    'SECADO' => ['icon' => 'sun', 'label' => 'Secado Final'],
    'CALIDAD_POST' => ['icon' => 'clipboard-check', 'label' => 'Calidad Post'],
    'EMPAQUETADO' => ['icon' => 'archive', 'label' => 'Empaquetado'],
    'ALMACENADO' => ['icon' => 'database', 'label' => 'Almacenado'],
    'FINALIZADO' => ['icon' => 'flag', 'label' => 'Finalizado']
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
                        Registros del Proceso
                    </h3>
                </div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Ficha de Registro -->
                        <a href="<?= APP_URL ?>/fichas/<?= $fichaRegistro ? 'ver' : 'crear' ?>.php?lote_id=<?= $lote['id'] ?>" 
                           class="flex items-center gap-4 p-4 rounded-xl border-2 border-dashed <?= $fichaRegistro ? 'border-green-300 bg-green-50' : 'border-gray-200 hover:border-primary hover:bg-olive/5' ?> transition-colors">
                            <div class="w-12 h-12 rounded-xl <?= $fichaRegistro ? 'bg-green-500' : 'bg-gray-200' ?> flex items-center justify-center">
                                <svg class="w-6 h-6 <?= $fichaRegistro ? 'text-white' : 'text-warmgray' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <p class="font-medium">Ficha de Registro</p>
                                <p class="text-sm text-warmgray"><?= $fichaRegistro ? 'Completado' : 'Pendiente' ?></p>
                            </div>
                            <?php if ($fichaRegistro): ?>
                                <svg class="w-6 h-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            <?php else: ?>
                                <svg class="w-6 h-6 text-warmgray" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                            <?php endif; ?>
                        </a>

                        <!-- Registro Fermentación -->
                        <a href="<?= APP_URL ?>/fermentacion/<?= $registroFermentacion ? 'ver' : 'crear' ?>.php?lote_id=<?= $lote['id'] ?>" 
                           class="flex items-center gap-4 p-4 rounded-xl border-2 border-dashed <?= $registroFermentacion ? 'border-green-300 bg-green-50' : 'border-gray-200 hover:border-primary hover:bg-olive/5' ?> transition-colors">
                            <div class="w-12 h-12 rounded-xl <?= $registroFermentacion ? 'bg-green-500' : 'bg-gray-200' ?> flex items-center justify-center">
                                <svg class="w-6 h-6 <?= $registroFermentacion ? 'text-white' : 'text-warmgray' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <p class="font-medium">Fermentación</p>
                                <p class="text-sm text-warmgray"><?= $registroFermentacion ? 'Registrado' : 'Pendiente' ?></p>
                            </div>
                            <?php if ($registroFermentacion): ?>
                                <svg class="w-6 h-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            <?php endif; ?>
                        </a>

                        <!-- Registro Secado -->
                        <a href="<?= APP_URL ?>/secado/<?= $registroSecado ? 'ver' : 'crear' ?>.php?lote_id=<?= $lote['id'] ?>" 
                           class="flex items-center gap-4 p-4 rounded-xl border-2 border-dashed <?= $registroSecado ? 'border-green-300 bg-green-50' : 'border-gray-200 hover:border-primary hover:bg-olive/5' ?> transition-colors">
                            <div class="w-12 h-12 rounded-xl <?= $registroSecado ? 'bg-green-500' : 'bg-gray-200' ?> flex items-center justify-center">
                                <svg class="w-6 h-6 <?= $registroSecado ? 'text-white' : 'text-warmgray' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <p class="font-medium">Secado</p>
                                <p class="text-sm text-warmgray"><?= $registroSecado ? 'Registrado' : 'Pendiente' ?></p>
                            </div>
                            <?php if ($registroSecado): ?>
                                <svg class="w-6 h-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            <?php endif; ?>
                        </a>

                        <!-- Prueba de Corte -->
                        <a href="<?= APP_URL ?>/prueba-corte/<?= $registroPruebaCorte ? 'ver' : 'crear' ?>.php?lote_id=<?= $lote['id'] ?>" 
                           class="flex items-center gap-4 p-4 rounded-xl border-2 border-dashed <?= $registroPruebaCorte ? 'border-green-300 bg-green-50' : 'border-gray-200 hover:border-primary hover:bg-olive/5' ?> transition-colors">
                            <div class="w-12 h-12 rounded-xl <?= $registroPruebaCorte ? 'bg-green-500' : 'bg-gray-200' ?> flex items-center justify-center">
                                <svg class="w-6 h-6 <?= $registroPruebaCorte ? 'text-white' : 'text-warmgray' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <p class="font-medium">Prueba de Corte</p>
                                <p class="text-sm text-warmgray"><?= $registroPruebaCorte ? 'Completado' : 'Pendiente' ?></p>
                            </div>
                            <?php if ($registroPruebaCorte): ?>
                                <svg class="w-6 h-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            <?php endif; ?>
                        </a>
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
                    <h3 class="card-title">Acciones</h3>
                </div>
                <div class="card-body space-y-3">
                    <?php if ($lote['estado_proceso'] === 'RECEPCION'): ?>
                        <a href="<?= APP_URL ?>/lotes/avanzar.php?id=<?= $lote['id'] ?>&estado=CALIDAD" 
                           class="btn btn-primary w-full justify-start">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            Pasar a Control de Calidad
                        </a>
                    <?php elseif ($lote['estado_proceso'] === 'CALIDAD'): ?>
                        <a href="<?= APP_URL ?>/lotes/avanzar.php?id=<?= $lote['id'] ?>&estado=PRE_SECADO" 
                           class="btn btn-primary w-full justify-start">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            Pasar a Pre-secado
                        </a>
                    <?php elseif ($lote['estado_proceso'] === 'PRE_SECADO'): ?>
                        <a href="<?= APP_URL ?>/fermentacion/crear.php?lote_id=<?= $lote['id'] ?>" 
                           class="btn btn-primary w-full justify-start">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                            </svg>
                            Iniciar Fermentación
                        </a>
                    <?php elseif ($lote['estado_proceso'] === 'FERMENTACION'): ?>
                        <a href="<?= APP_URL ?>/secado/crear.php?lote_id=<?= $lote['id'] ?>" 
                           class="btn btn-primary w-full justify-start">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                            Iniciar Secado
                        </a>
                    <?php elseif ($lote['estado_proceso'] === 'SECADO'): ?>
                        <a href="<?= APP_URL ?>/prueba-corte/crear.php?lote_id=<?= $lote['id'] ?>" 
                           class="btn btn-primary w-full justify-start">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                            </svg>
                            Realizar Prueba de Corte
                        </a>
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
                                        <?php if ($h['detalle']): ?>
                                            <p class="text-xs text-warmgray truncate"><?= htmlspecialchars($h['detalle']) ?></p>
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
