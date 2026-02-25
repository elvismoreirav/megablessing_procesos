<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Fichas de Registro - Ver Detalle
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

$id = intval($_GET['id'] ?? 0);
$created = isset($_GET['created']);
$updated = isset($_GET['updated']);

if ($id <= 0) {
    redirect('/fichas/index.php?vista=recepcion');
}

// Compatibilidad de esquema para columnas de lotes
$colsLotes = array_column($db->fetchAll("SHOW COLUMNS FROM lotes"), 'Field');
$hasLoteCol = static fn(string $name): bool => in_array($name, $colsLotes, true);

$pesoRecibidoExpr = $hasLoteCol('peso_recibido_kg')
    ? 'l.peso_recibido_kg'
    : ($hasLoteCol('peso_inicial_kg') ? 'l.peso_inicial_kg' : 'NULL');
$fechaRecepcionExpr = $hasLoteCol('fecha_recepcion')
    ? 'l.fecha_recepcion'
    : ($hasLoteCol('fecha_entrada') ? 'l.fecha_entrada' : 'NULL');
$loteEstadoExpr = $hasLoteCol('estado')
    ? 'l.estado'
    : ($hasLoteCol('estado_proceso') ? 'l.estado_proceso' : 'NULL');

// Compatibilidad de esquema para columnas de proveedores
$colsProveedores = array_column($db->fetchAll("SHOW COLUMNS FROM proveedores"), 'Field');
$hasProvCol = static fn(string $name): bool => in_array($name, $colsProveedores, true);
$proveedorRucExpr = $hasProvCol('cedula_ruc')
    ? 'p.cedula_ruc'
    : ($hasProvCol('ruc') ? 'p.ruc' : ($hasProvCol('identificacion') ? 'p.identificacion' : 'NULL'));

// Compatibilidad de esquema para columnas de fichas (registro de pago)
$colsFichas = array_column($db->fetchAll("SHOW COLUMNS FROM fichas_registro"), 'Field');
$hasFichaCol = static fn(string $name): bool => in_array($name, $colsFichas, true);

// Obtener ficha con información relacionada
$ficha = $db->fetchOne("
    SELECT f.*,
           l.codigo as lote_codigo,
           {$pesoRecibidoExpr} as peso_recibido_kg,
           {$fechaRecepcionExpr} as fecha_recepcion,
           {$loteEstadoExpr} as lote_estado,
           p.nombre as proveedor_nombre,
           {$proveedorRucExpr} as proveedor_ruc,
           v.nombre as variedad_nombre,
           u.nombre as responsable_nombre
    FROM fichas_registro f
    LEFT JOIN lotes l ON f.lote_id = l.id
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    LEFT JOIN usuarios u ON f.responsable_id = u.id
    WHERE f.id = ?
", [$id]);

if (!$ficha) {
    $_SESSION['error'] = 'Ficha no encontrada';
    redirect('/fichas/index.php?vista=recepcion');
}

$tieneLoteAsociado = isset($ficha['lote_id']) && (int)$ficha['lote_id'] > 0;
$loteCodigoTexto = trim((string)($ficha['lote_codigo'] ?? ''));
if ($loteCodigoTexto === '') {
    $loteCodigoTexto = 'Sin lote asignado';
}

$etiquetasCalificacionHumedad = [
    0 => '0%',
    1 => '1%',
    2 => '2%',
    3 => '3%',
    4 => '4%',
    10 => '5-10%',
    15 => '11-15%',
    20 => '16-20%',
    25 => '21-25%',
    30 => '26-30%',
    35 => '31-35%',
    40 => '36-40%',
    45 => '41-45%',
    50 => '46-50%',
    55 => '51-55%',
    60 => '56-60%',
    65 => '61-65%',
    70 => '> 65%',
];
$valorCalificacion = isset($ficha['calificacion_humedad']) ? (int)$ficha['calificacion_humedad'] : null;
$calificacionHumedadTexto = $valorCalificacion === null
    ? '—'
    : ($etiquetasCalificacionHumedad[$valorCalificacion] ?? ($valorCalificacion . '%'));

// Obtener información adicional del lote
$fermentacion = null;
if ($tieneLoteAsociado) {
    $fermentacion = $db->fetchOne("
        SELECT rf.*, c.nombre as cajon_nombre
        FROM registros_fermentacion rf
        LEFT JOIN cajones_fermentacion c ON rf.cajon_id = c.id
        WHERE rf.lote_id = ?
        ORDER BY rf.fecha_inicio DESC
        LIMIT 1
    ", [$ficha['lote_id']]);
}

// Compatibilidad de esquema para registros de secado
$colsSecado = array_column($db->fetchAll("SHOW COLUMNS FROM registros_secado"), 'Field');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSecado, true);
$ordenSecadoExpr = $hasSecCol('fecha_inicio')
    ? 'rs.fecha_inicio'
    : ($hasSecCol('fecha') ? 'rs.fecha' : ($hasSecCol('created_at') ? 'rs.created_at' : 'rs.id'));

$secado = null;
if ($tieneLoteAsociado) {
    $secado = $db->fetchOne("
        SELECT rs.*, s.nombre as secadora_nombre
        FROM registros_secado rs
        LEFT JOIN secadoras s ON rs.secadora_id = s.id
        WHERE rs.lote_id = ?
        ORDER BY {$ordenSecadoExpr} DESC
        LIMIT 1
    ", [$ficha['lote_id']]);
}

// Compatibilidad de esquema para prueba de corte
$tablaPruebaCorte = $db->fetch("SHOW TABLES LIKE 'pruebas_corte'")
    ? 'pruebas_corte'
    : ($db->fetch("SHOW TABLES LIKE 'registros_prueba_corte'") ? 'registros_prueba_corte' : null);

$pruebaCorte = null;
if ($tablaPruebaCorte && $tieneLoteAsociado) {
    $colsPrueba = array_column($db->fetchAll("SHOW COLUMNS FROM {$tablaPruebaCorte}"), 'Field');
    $hasPrCol = static fn(string $name): bool => in_array($name, $colsPrueba, true);

    $ordenPruebaExpr = $hasPrCol('fecha_prueba')
        ? 'pc.fecha_prueba'
        : ($hasPrCol('fecha') ? 'pc.fecha' : ($hasPrCol('created_at') ? 'pc.created_at' : 'pc.id'));
    $pctFerExpr = $hasPrCol('porcentaje_fermentacion')
        ? 'pc.porcentaje_fermentacion'
        : ($hasPrCol('bien_fermentados') ? 'pc.bien_fermentados' : 'NULL');
    $calificacionExpr = $hasPrCol('calificacion')
        ? 'pc.calificacion'
        : ($hasPrCol('decision_lote') ? 'pc.decision_lote' : 'NULL');

    $pruebaCorte = $db->fetchOne("
        SELECT pc.*,
               {$pctFerExpr} as porcentaje_fermentacion,
               {$calificacionExpr} as calificacion
        FROM {$tablaPruebaCorte} pc
        WHERE pc.lote_id = ?
        ORDER BY {$ordenPruebaExpr} DESC
        LIMIT 1
    ", [$ficha['lote_id']]);
}

$tablaCalidadSalida = $db->fetch("SHOW TABLES LIKE 'registros_calidad_salida'");
$calidadSalida = null;
if ($tablaCalidadSalida && $tieneLoteAsociado) {
    $calidadSalida = $db->fetchOne("
        SELECT *
        FROM registros_calidad_salida
        WHERE lote_id = ?
        ORDER BY fecha_registro DESC, id DESC
        LIMIT 1
    ", [$ficha['lote_id']]);
}

$fechaPago = $hasFichaCol('fecha_pago') ? ($ficha['fecha_pago'] ?? null) : null;
$tipoComprobante = $hasFichaCol('tipo_comprobante') ? trim((string)($ficha['tipo_comprobante'] ?? '')) : '';
$facturaCompra = $hasFichaCol('factura_compra') ? trim((string)($ficha['factura_compra'] ?? '')) : '';
$cantidadCompradaUnidad = $hasFichaCol('cantidad_comprada_unidad') ? trim((string)($ficha['cantidad_comprada_unidad'] ?? 'KG')) : 'KG';
$cantidadComprada = $hasFichaCol('cantidad_comprada') ? ($ficha['cantidad_comprada'] ?? null) : null;
$formaPago = $hasFichaCol('forma_pago') ? trim((string)($ficha['forma_pago'] ?? '')) : '';

$tienePago = false;
if ($hasFichaCol('fecha_pago') && $hasFichaCol('tipo_comprobante') && $hasFichaCol('factura_compra') && $hasFichaCol('cantidad_comprada_unidad') && $hasFichaCol('cantidad_comprada') && $hasFichaCol('forma_pago')) {
    $tienePago = !empty($fechaPago)
        && $tipoComprobante !== ''
        && $facturaCompra !== ''
        && $cantidadCompradaUnidad !== ''
        && $cantidadComprada !== null
        && (float)$cantidadComprada > 0
        && $formaPago !== '';
} else {
    $tienePago = isset($ficha['precio_total_pagar']) && $ficha['precio_total_pagar'] !== null;
}
$tieneCodificacion = trim((string)($ficha['codificacion'] ?? '')) !== '';
$rutaPago = APP_URL . '/fichas/pago.php?id=' . (int)$id;
$rutaCodificacion = APP_URL . '/fichas/codificacion.php?id=' . (int)$id;
$rutaEtiqueta = APP_URL . '/fichas/etiqueta.php?id=' . (int)$id;

$pageTitle = "Ficha #{$id} - {$loteCodigoTexto}";
ob_start();
?>

<div class="max-w-5xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-gray-900">Ficha de Recepción #<?= $id ?></h1>
                <?php if ($ficha['codificacion']): ?>
                <span class="px-3 py-1 bg-amber-100 text-amber-700 rounded-full text-sm font-mono">
                    <?= htmlspecialchars($ficha['codificacion']) ?>
                </span>
                <?php endif; ?>
            </div>
            <p class="text-gray-600">Ficha de recepción y verificación visual</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="<?= APP_URL ?>/fichas/editar.php?id=<?= (int)$id ?>&etapa=recepcion" 
               class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-xl hover:bg-amber-700 transition-colors">
                <i class="fas fa-edit mr-2"></i>Editar Recepción
            </a>
            <a href="<?= APP_URL ?>/fichas/index.php?vista=recepcion" class="text-amber-600 hover:text-amber-700">
                <i class="fas fa-arrow-left mr-2"></i>Volver
            </a>
        </div>
    </div>

    <?php if ($created): ?>
    <div class="bg-green-50 border border-green-200 rounded-xl p-4">
        <div class="flex items-center gap-3">
            <i class="fas fa-check-circle text-green-600"></i>
            <span class="text-green-800">Ficha creada exitosamente</span>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($updated): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
        <div class="flex items-center gap-3">
            <i class="fas fa-check-circle text-blue-600"></i>
            <span class="text-blue-800">Ficha actualizada exitosamente</span>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Columna principal -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Información del Lote -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-amber-50 to-orange-50">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-box text-amber-600 mr-2"></i>Información del Lote
                    </h2>
                </div>
                <div class="p-6">
                    <dl class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-sm text-gray-500">Código de Lote</dt>
                            <dd class="font-semibold text-gray-900">
                                <?php if ($tieneLoteAsociado): ?>
                                <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= (int)$ficha['lote_id'] ?>" class="text-amber-600 hover:text-amber-700">
                                    <?= htmlspecialchars($loteCodigoTexto) ?>
                                </a>
                                <?php else: ?>
                                <span class="text-gray-500">Sin lote asignado</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Producto</dt>
                            <dd class="font-medium text-gray-900"><?= htmlspecialchars($ficha['producto'] ?: '—') ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Proveedor/Ruta</dt>
                            <dd class="font-medium text-gray-900">
                                <?= htmlspecialchars($ficha['proveedor_ruta'] ?: $ficha['proveedor_nombre'] ?: '—') ?>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Fecha de Entrada</dt>
                            <dd class="font-medium text-gray-900">
                                <?php if ($ficha['fecha_entrada']): ?>
                                <?= date('d/m/Y', strtotime($ficha['fecha_entrada'])) ?>
                                <?php else: ?>
                                —
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Variedad</dt>
                            <dd class="font-medium text-gray-900"><?= htmlspecialchars($ficha['variedad_nombre'] ?: '—') ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Peso Recibido</dt>
                            <dd class="font-medium text-gray-900">
                                <?php if (isset($ficha['peso_recibido_kg']) && is_numeric($ficha['peso_recibido_kg'])): ?>
                                    <?= number_format((float)$ficha['peso_recibido_kg'], 2) ?> kg
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Proceso Planta -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-emerald-50 to-teal-50">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-industry text-emerald-600 mr-2"></i>Proceso Planta
                    </h2>
                </div>
                <div class="p-6 space-y-6">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Tipo de entrega</h3>
                        <?php
                        $tiposEntregaLabels = [
                            'RUTAS' => 'Rutas',
                            'COMERCIANTE' => 'Comerciante',
                            'ENTREGA_INDIVIDUAL' => 'Entrega Individual',
                        ];
                        $tipoEntregaLabel = $tiposEntregaLabels[$ficha['tipo_entrega'] ?? ''] ?? '—';
                        ?>
                        <span class="inline-flex px-3 py-1 rounded-full text-sm bg-emerald-100 text-emerald-700">
                            <?= htmlspecialchars($tipoEntregaLabel) ?>
                        </span>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Revisión de calidad visual</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <?php
                            $revisionItems = [
                                'Impurezas' => $ficha['revision_limpieza'] ?? null,
                                'Olor normal (sin olores extraños)' => $ficha['revision_olor_normal'] ?? null,
                                'Ausencia de moho visible' => $ficha['revision_ausencia_moho'] ?? null,
                            ];
                            foreach ($revisionItems as $label => $valor):
                                $ok = $valor === 'CUMPLE';
                                $badgeClass = $ok ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
                            ?>
                            <div class="p-3 rounded-lg border border-gray-100">
                                <p class="text-sm text-gray-600"><?= $label ?></p>
                                <?php if ($valor): ?>
                                <span class="inline-flex mt-2 px-2 py-1 text-xs rounded-full <?= $badgeClass ?>">
                                    <?= $ok ? 'Cumple' : 'No cumple' ?>
                                </span>
                                <?php else: ?>
                                <span class="inline-flex mt-2 px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-600">No registrado</span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Registros y precio</h3>
                        <p class="inline-flex px-3 py-1 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-700 mb-3">
                            Precio oficial de compra: USD/kg
                        </p>
                        <dl class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <dt class="text-sm text-gray-500">Peso bruto</dt>
                                <dd class="font-medium text-gray-900">
                                    <?= isset($ficha['peso_bruto']) ? number_format((float)$ficha['peso_bruto'], 2) : '—' ?>
                                    <?= htmlspecialchars($ficha['unidad_peso'] ?? '') ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Tara envase</dt>
                                <dd class="font-medium text-gray-900">
                                    <?= isset($ficha['tara_envase']) ? number_format((float)$ficha['tara_envase'], 2) : '—' ?>
                                    <?= htmlspecialchars($ficha['unidad_peso'] ?? '') ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Peso final</dt>
                                <dd class="font-medium text-gray-900">
                                    <?= isset($ficha['peso_final_registro']) ? number_format((float)$ficha['peso_final_registro'], 2) : '—' ?>
                                    <?= htmlspecialchars($ficha['unidad_peso'] ?? '') ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Calificación humedad</dt>
                                <dd class="font-medium text-gray-900"><?= htmlspecialchars($calificacionHumedadTexto) ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Calidad</dt>
                                <dd class="font-medium text-gray-900"><?= htmlspecialchars($ficha['calidad_registro'] ?? '—') ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Defectos</dt>
                                <dd class="font-medium text-gray-900">
                                    <?= isset($ficha['presencia_defectos']) ? number_format((float)$ficha['presencia_defectos'], 2) . '%' : '—' ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Clasificación compra</dt>
                                <dd class="font-medium text-gray-900"><?= htmlspecialchars($ficha['clasificacion_compra'] ?? '—') ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Precio base día</dt>
                                <dd class="font-medium text-gray-900">
                                    <?= isset($ficha['precio_base_dia']) ? '$ ' . number_format((float)$ficha['precio_base_dia'], 4) : '—' ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Diferencial</dt>
                                <dd class="font-medium text-gray-900">
                                    <?= isset($ficha['diferencial_usd']) ? '$ ' . number_format((float)$ficha['diferencial_usd'], 4) : '—' ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Precio unitario final</dt>
                                <dd class="font-semibold text-emerald-700">
                                    <?= isset($ficha['precio_unitario_final']) ? '$ ' . number_format((float)$ficha['precio_unitario_final'], 4) . ' /kg' : '—' ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Precio total a pagar</dt>
                                <dd class="font-bold text-emerald-700">
                                    <?= isset($ficha['precio_total_pagar']) ? '$ ' . number_format((float)$ficha['precio_total_pagar'], 2) : '—' ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Cantidad comprada</dt>
                                <dd class="font-medium text-gray-900">
                                    <?= ($cantidadComprada !== null && is_numeric($cantidadComprada)) ? number_format((float)$cantidadComprada, 2) . ' ' . htmlspecialchars($cantidadCompradaUnidad !== '' ? $cantidadCompradaUnidad : 'KG') : '—' ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Tipo de comprobante</dt>
                                <dd class="font-medium text-gray-900">
                                    <?= $tipoComprobante !== '' ? htmlspecialchars(str_replace('_', ' ', $tipoComprobante)) : '—' ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Fecha de pago</dt>
                                <dd class="font-medium text-gray-900">
                                    <?= !empty($fechaPago) ? date('d/m/Y', strtotime((string)$fechaPago)) : '—' ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Factura asignada</dt>
                                <dd class="font-medium text-gray-900">
                                    <?= $facturaCompra !== '' ? htmlspecialchars($facturaCompra) : '—' ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">Forma de pago</dt>
                                <dd class="font-medium text-gray-900">
                                    <?= $formaPago !== '' ? htmlspecialchars(ucfirst(strtolower($formaPago))) : '—' ?>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <!-- Estado de Fermentación -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-orange-50 to-red-50">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-temperature-high text-orange-600 mr-2"></i>Estado de Fermentación
                    </h2>
                </div>
                <div class="p-6">
                    <div class="flex items-center gap-4 mb-4">
                        <span class="text-gray-500">Estado:</span>
                        <?php if ($ficha['fermentacion_estado']): ?>
                        <?php
                        $estadoColor = match(strtolower($ficha['fermentacion_estado'])) {
                            'completa', 'terminada', 'finalizada' => 'bg-green-100 text-green-700 border-green-200',
                            'en proceso', 'activa' => 'bg-amber-100 text-amber-700 border-amber-200',
                            'pendiente' => 'bg-gray-100 text-gray-700 border-gray-200',
                            default => 'bg-blue-100 text-blue-700 border-blue-200'
                        };
                        ?>
                        <span class="inline-flex px-4 py-2 text-sm font-medium rounded-xl border <?= $estadoColor ?>">
                            <?= htmlspecialchars($ficha['fermentacion_estado']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-gray-400">No especificado</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($fermentacion): ?>
                    <div class="mt-4 p-4 bg-orange-50 rounded-xl">
                        <h4 class="text-sm font-semibold text-orange-800 mb-2">Registro de Fermentación Activo</h4>
                        <dl class="grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-gray-500">Cajón:</dt>
                                <dd class="text-gray-900"><?= htmlspecialchars($fermentacion['cajon_nombre'] ?? 'No asignado') ?></dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Fecha Inicio:</dt>
                                <dd class="text-gray-900"><?= date('d/m/Y', strtotime($fermentacion['fecha_inicio'])) ?></dd>
                            </div>
                            <?php if ($fermentacion['porcentaje_fermentados']): ?>
                            <div>
                                <dt class="text-gray-500">% Fermentados:</dt>
                                <dd class="text-gray-900"><?= number_format($fermentacion['porcentaje_fermentados'], 1) ?>%</dd>
                            </div>
                            <?php endif; ?>
                        </dl>
                        <a href="<?= APP_URL ?>/fermentacion/ver.php?id=<?= (int)$fermentacion['id'] ?>" class="text-orange-600 hover:text-orange-700 text-sm mt-2 inline-block">
                            Ver detalle de fermentación →
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Datos de Secado -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-yellow-50 to-amber-50">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-sun text-yellow-600 mr-2"></i>Datos de Secado
                    </h2>
                </div>
                <div class="p-6">
                    <dl class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-sm text-gray-500">Inicio de Secado</dt>
                            <dd class="font-medium text-gray-900">
                                <?php if ($ficha['secado_inicio']): ?>
                                <?= date('d/m/Y H:i', strtotime($ficha['secado_inicio'])) ?>
                                <?php else: ?>
                                <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Fin de Secado</dt>
                            <dd class="font-medium text-gray-900">
                                <?php if ($ficha['secado_fin']): ?>
                                <?= date('d/m/Y H:i', strtotime($ficha['secado_fin'])) ?>
                                <?php else: ?>
                                <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Temperatura</dt>
                            <dd class="font-medium text-gray-900">
                                <?php if ($ficha['temperatura']): ?>
                                <?= number_format($ficha['temperatura'], 2) ?> °C
                                <?php else: ?>
                                <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Tiempo Total</dt>
                            <dd class="font-medium text-gray-900">
                                <?php if ($ficha['tiempo_horas']): ?>
                                <?= number_format($ficha['tiempo_horas'], 2) ?> horas
                                <?php else: ?>
                                <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                    </dl>

                    <?php if ($secado): ?>
                    <div class="mt-4 p-4 bg-yellow-50 rounded-xl">
                        <h4 class="text-sm font-semibold text-yellow-800 mb-2">Registro de Secado Activo</h4>
                        <dl class="grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-gray-500">Tipo:</dt>
                                <dd class="text-gray-900"><?= ucfirst($secado['tipo_secado'] ?? 'No especificado') ?></dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Humedad Final:</dt>
                                <dd class="text-gray-900"><?= $secado['humedad_final'] ? number_format($secado['humedad_final'], 1) . '%' : '—' ?></dd>
                            </div>
                        </dl>
                        <a href="<?= APP_URL ?>/secado/ver.php?id=<?= (int)$secado['id'] ?>" class="text-yellow-600 hover:text-yellow-700 text-sm mt-2 inline-block">
                            Ver detalle de secado →
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Observaciones -->
            <?php if ($ficha['observaciones']): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-sticky-note text-gray-600 mr-2"></i>Observaciones
                    </h2>
                </div>
                <div class="p-6">
                    <p class="text-gray-700 whitespace-pre-wrap"><?= htmlspecialchars($ficha['observaciones']) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Columna lateral -->
        <div class="space-y-6">
            <!-- Información de la Ficha -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-gray-50 to-slate-50">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-info-circle text-gray-600 mr-2"></i>Información
                    </h2>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <dt class="text-sm text-gray-500">ID de Ficha</dt>
                        <dd class="font-mono text-lg font-semibold text-gray-900">#<?= $id ?></dd>
                    </div>
                    <?php if ($ficha['codificacion']): ?>
                    <div>
                        <dt class="text-sm text-gray-500">Codificación</dt>
                        <dd class="font-mono font-medium text-gray-900"><?= htmlspecialchars($ficha['codificacion']) ?></dd>
                    </div>
                    <?php endif; ?>
                    <div>
                        <dt class="text-sm text-gray-500">Responsable</dt>
                        <dd class="font-medium text-gray-900"><?= htmlspecialchars($ficha['responsable_nombre'] ?? 'No asignado') ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Creado</dt>
                        <dd class="text-sm text-gray-700"><?= date('d/m/Y H:i', strtotime($ficha['created_at'])) ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Actualizado</dt>
                        <dd class="text-sm text-gray-700"><?= date('d/m/Y H:i', strtotime($ficha['updated_at'])) ?></dd>
                    </div>
                </div>
            </div>

            <!-- Estado del Lote -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-blue-50 to-indigo-50">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-chart-line text-blue-600 mr-2"></i>Estado del Lote
                    </h2>
                </div>
                <div class="p-6">
                    <?php
                    $estadoLoteRaw = (string)($ficha['lote_estado'] ?? '');
                    $estadoLoteNorm = strtoupper($estadoLoteRaw);
                    $loteEstadoColor = match($estadoLoteNorm) {
                        'RECEPCION', 'RECIBIDO' => 'bg-blue-100 text-blue-700',
                        'FERMENTACION' => 'bg-orange-100 text-orange-700',
                        'PRE_SECADO', 'SECADO' => 'bg-yellow-100 text-yellow-700',
                        'CALIDAD', 'CALIDAD_POST', 'PRUEBA_CORTE', 'CALIDAD_SALIDA' => 'bg-emerald-100 text-emerald-700',
                        'EMPAQUETADO', 'ALMACENADO' => 'bg-purple-100 text-purple-700',
                        'FINALIZADO' => 'bg-green-100 text-green-700',
                        'RECHAZADO' => 'bg-red-100 text-red-700',
                        default => 'bg-gray-100 text-gray-700'
                    };
                    $estadoLoteMap = [
                        'RECEPCION' => 'Recepción',
                        'RECIBIDO' => 'Recepción',
                        'CALIDAD' => 'Verificación de Lote',
                        'PRE_SECADO' => 'Pre-secado',
                        'FERMENTACION' => 'Fermentación',
                        'SECADO' => 'Secado',
                        'CALIDAD_POST' => 'Prueba de Corte',
                        'PRUEBA_CORTE' => 'Prueba de Corte',
                        'CALIDAD_SALIDA' => 'Calidad de salida',
                        'EMPAQUETADO' => 'Empaquetado',
                        'ALMACENADO' => 'Almacenado',
                        'DESPACHO' => 'Despacho',
                        'FINALIZADO' => 'Finalizado',
                        'RECHAZADO' => 'Rechazado',
                    ];
                    $estadoLoteTexto = $estadoLoteRaw !== ''
                        ? ($estadoLoteMap[$estadoLoteNorm] ?? ucwords(strtolower(str_replace('_', ' ', $estadoLoteRaw))))
                        : 'Sin estado';
                    ?>
                    <div class="text-center">
                        <span class="inline-flex px-4 py-2 text-sm font-medium rounded-xl <?= $loteEstadoColor ?>">
                            <?= htmlspecialchars($estadoLoteTexto) ?>
                        </span>
                    </div>

                    <?php if ($pruebaCorte): ?>
                    <div class="mt-4 p-3 bg-emerald-50 rounded-lg">
                        <div class="text-sm">
                            <p class="font-medium text-emerald-800">Prueba de Corte</p>
                            <p class="text-emerald-600">
                                <?= number_format($pruebaCorte['porcentaje_fermentacion'] ?? 0, 1) ?>% fermentación
                            </p>
                            <?php if ($pruebaCorte['calificacion']): ?>
                            <p class="text-emerald-700 font-medium mt-1">
                                Calificación: <?= htmlspecialchars($pruebaCorte['calificacion']) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Acciones Rápidas -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-semibold text-gray-900 mb-4">Acciones Rápidas</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">1. Procesos de Recepción</p>
                        <div class="space-y-2">
                            <a href="<?= APP_URL ?>/fichas/ver.php?id=<?= (int)$id ?>" class="flex items-center justify-between gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors">
                                <span class="text-gray-700">a. Recepción (Ficha de Recepción)</span>
                                <span class="text-xs px-2 py-1 rounded-full bg-emerald-100 text-emerald-700">Completado</span>
                            </a>
                            <a href="<?= $rutaPago ?>" class="flex items-center justify-between gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors">
                                <span class="text-gray-700">b. Registro de Pagos</span>
                                <span class="text-xs px-2 py-1 rounded-full <?= $tienePago ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                    <?= $tienePago ? 'Registrado' : 'Pendiente' ?>
                                </span>
                            </a>
                            <a href="<?= $rutaCodificacion ?>" class="flex items-center justify-between gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors">
                                <span class="text-gray-700">c. Codificación de Lote</span>
                                <span class="text-xs px-2 py-1 rounded-full <?= $tieneCodificacion ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                    <?= $tieneCodificacion ? 'Codificado' : 'Pendiente' ?>
                                </span>
                            </a>
                            <a href="<?= $rutaEtiqueta ?>" class="flex items-center justify-between gap-3 p-3 rounded-lg hover:bg-gray-50 transition-colors">
                                <span class="text-gray-700">i. Imprimir Etiqueta</span>
                                <span class="text-xs px-2 py-1 rounded-full bg-blue-100 text-blue-700">Disponible</span>
                            </a>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">2. Procesos Post-cosecha</p>
                        <div class="space-y-2">
                            <a href="<?= $tieneLoteAsociado ? APP_URL . '/lotes/editar.php?id=' . (int)$ficha['lote_id'] : '#' ?>" class="flex items-center gap-3 p-3 rounded-lg transition-colors <?= $tieneLoteAsociado ? 'hover:bg-blue-50' : 'opacity-60 cursor-not-allowed pointer-events-none' ?>">
                                <i class="fas fa-clipboard-check text-blue-600 w-5"></i>
                                <span class="text-gray-700">a. Verificación de Lote</span>
                            </a>
                            <?php if (!$fermentacion): ?>
                            <a href="<?= $tieneLoteAsociado ? APP_URL . '/fermentacion/crear.php?lote_id=' . (int)$ficha['lote_id'] : '#' ?>" class="flex items-center gap-3 p-3 rounded-lg transition-colors <?= $tieneLoteAsociado ? 'hover:bg-orange-50' : 'opacity-60 cursor-not-allowed pointer-events-none' ?>">
                                <i class="fas fa-temperature-high text-orange-600 w-5"></i>
                                <span class="text-gray-700">b. Fermentación (Ficha de fermentación)</span>
                            </a>
                            <?php endif; ?>
                            <?php if (!$secado): ?>
                            <a href="<?= $tieneLoteAsociado ? APP_URL . '/secado/crear.php?lote_id=' . (int)$ficha['lote_id'] : '#' ?>" class="flex items-center gap-3 p-3 rounded-lg transition-colors <?= $tieneLoteAsociado ? 'hover:bg-yellow-50' : 'opacity-60 cursor-not-allowed pointer-events-none' ?>">
                                <i class="fas fa-sun text-yellow-600 w-5"></i>
                                <span class="text-gray-700">c. Secado (Ficha de secado)</span>
                            </a>
                            <?php endif; ?>
                            <?php if (!$pruebaCorte): ?>
                            <a href="<?= $tieneLoteAsociado ? APP_URL . '/prueba-corte/crear.php?lote_id=' . (int)$ficha['lote_id'] : '#' ?>" class="flex items-center gap-3 p-3 rounded-lg transition-colors <?= $tieneLoteAsociado ? 'hover:bg-emerald-50' : 'opacity-60 cursor-not-allowed pointer-events-none' ?>">
                                <i class="fas fa-cut text-emerald-600 w-5"></i>
                                <span class="text-gray-700">d. Prueba de Corte (Ficha de prueba de corte)</span>
                            </a>
                            <?php endif; ?>
                            <?php if ($tablaCalidadSalida && !$calidadSalida): ?>
                            <a href="<?= $tieneLoteAsociado ? APP_URL . '/calidad-salida/crear.php?lote_id=' . (int)$ficha['lote_id'] : '#' ?>" class="flex items-center gap-3 p-3 rounded-lg transition-colors <?= $tieneLoteAsociado ? 'hover:bg-emerald-50' : 'opacity-60 cursor-not-allowed pointer-events-none' ?>">
                                <i class="fas fa-check-circle text-emerald-600 w-5"></i>
                                <span class="text-gray-700">e. Calidad de salida</span>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php if (!$tieneLoteAsociado): ?>
                        <p class="text-xs text-amber-700 mt-2">Debe completar la codificación/asignación de lote para habilitar los procesos de planta.</p>
                        <?php endif; ?>
                    </div>

                    <a href="<?= $tieneLoteAsociado ? APP_URL . '/lotes/ver.php?id=' . (int)$ficha['lote_id'] : '#' ?>" class="flex items-center gap-3 p-3 rounded-lg transition-colors <?= $tieneLoteAsociado ? 'hover:bg-gray-50' : 'opacity-60 cursor-not-allowed pointer-events-none' ?>">
                        <i class="fas fa-box text-amber-600 w-5"></i>
                        <span class="text-gray-700">Ver Lote Completo</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
