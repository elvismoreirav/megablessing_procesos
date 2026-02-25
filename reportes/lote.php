<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Reporte Individual de Lote - Trazabilidad Completa
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Lote no especificado');
    redirect('/reportes/lotes.php');
}

// Obtener lote con información relacionada
$lote = $db->fetch("
    SELECT l.*, 
           p.nombre as proveedor_nombre, p.codigo as proveedor_codigo, p.telefono as proveedor_telefono,
           p.direccion as proveedor_direccion, p.tipo as proveedor_tipo,
           v.nombre as variedad_nombre, v.codigo as variedad_codigo, v.descripcion as variedad_descripcion,
           ep.nombre as estado_producto_nombre, ep.codigo as estado_producto_codigo,
           ef.nombre as estado_fermentacion_nombre,
           u.nombre as usuario_nombre, u.email as usuario_email,
           cf.nombre as cajon_nombre, cf.capacidad_kg as cajon_capacidad,
           s.nombre as secadora_nombre, s.capacidad_qq as secadora_capacidad
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
    redirect('/reportes/lotes.php');
}

// Obtener empresa
$empresa = $db->fetch("SELECT * FROM empresa WHERE id = 1");

// Compatibilidad de esquema para reportes
$colsFermentacion = Helpers::getTableColumns('registros_fermentacion');
$hasFerCol = static fn(string $name): bool => in_array($name, $colsFermentacion, true);
$colsFerCtrl = Helpers::getTableColumns('fermentacion_control_diario');
$hasFerCtrlCol = static fn(string $name): bool => in_array($name, $colsFerCtrl, true);
$colsSecado = Helpers::getTableColumns('registros_secado');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSecado, true);
$colsSecCtrl = Helpers::getTableColumns('secado_control_temperatura');
$hasSecCtrlCol = static fn(string $name): bool => in_array($name, $colsSecCtrl, true);
$colsPrueba = Helpers::getTableColumns('registros_prueba_corte');
$hasPrCol = static fn(string $name): bool => in_array($name, $colsPrueba, true);

// Obtener registros de fermentación
$ferRespJoinCol = $hasFerCol('responsable_id')
    ? 'rf.responsable_id'
    : ($hasFerCol('operador_id') ? 'rf.operador_id' : 'NULL');
$ferFechaFinExpr = $hasFerCol('fecha_fin')
    ? 'rf.fecha_fin'
    : ($hasFerCol('fecha_salida') ? 'rf.fecha_salida' : 'NULL');
$ferAprobadoExpr = $hasFerCol('aprobado_secado') ? 'rf.aprobado_secado' : '0';
$ferObsFinalExpr = $hasFerCol('observaciones_finales')
    ? 'rf.observaciones_finales'
    : ($hasFerCol('observaciones')
        ? 'rf.observaciones'
        : ($hasFerCol('observaciones_generales') ? 'rf.observaciones_generales' : 'NULL'));

$registroFermentacion = $db->fetch("
    SELECT rf.*,
           {$ferFechaFinExpr} as fecha_fin_rep,
           {$ferAprobadoExpr} as aprobado_secado_rep,
           {$ferObsFinalExpr} as observaciones_finales_rep,
           u.nombre as responsable_nombre
    FROM registros_fermentacion rf
    LEFT JOIN usuarios u ON {$ferRespJoinCol} = u.id
    WHERE rf.lote_id = ?
    ORDER BY rf.id DESC
    LIMIT 1
", [$id]);

// Obtener control diario de fermentación
$controlFermentacion = [];
if ($registroFermentacion && !empty($colsFerCtrl)) {
    $fkFerCtrlCol = $hasFerCtrlCol('fermentacion_id')
        ? 'fermentacion_id'
        : ($hasFerCtrlCol('registro_fermentacion_id') ? 'registro_fermentacion_id' : null);

    if ($fkFerCtrlCol) {
        $ferDiaCtrlExpr = $hasFerCtrlCol('dia') ? 'fcd.dia' : 'NULL';
        $ferFechaCtrlExpr = $hasFerCtrlCol('fecha') ? 'fcd.fecha' : 'NULL';
        $ferVolteoCtrlExpr = $hasFerCtrlCol('volteo') ? 'fcd.volteo' : '0';
        $ferTempMasaCtrlExpr = $hasFerCtrlCol('temperatura_masa')
            ? 'fcd.temperatura_masa'
            : ($hasFerCtrlCol('temperatura_am')
                ? 'fcd.temperatura_am'
                : ($hasFerCtrlCol('temp_masa')
                    ? 'fcd.temp_masa'
                    : ($hasFerCtrlCol('temp_am') ? 'fcd.temp_am' : 'NULL')));
        $ferTempAmbCtrlExpr = $hasFerCtrlCol('temperatura_ambiente')
            ? 'fcd.temperatura_ambiente'
            : ($hasFerCtrlCol('temperatura_pm')
                ? 'fcd.temperatura_pm'
                : ($hasFerCtrlCol('temp_ambiente')
                    ? 'fcd.temp_ambiente'
                    : ($hasFerCtrlCol('temp_pm') ? 'fcd.temp_pm' : 'NULL')));
        $ferPhPulpaCtrlExpr = $hasFerCtrlCol('ph_pulpa') ? 'fcd.ph_pulpa' : ($hasFerCtrlCol('ph_am') ? 'fcd.ph_am' : 'NULL');
        $ferPhCotCtrlExpr = $hasFerCtrlCol('ph_cotiledon') ? 'fcd.ph_cotiledon' : ($hasFerCtrlCol('ph_pm') ? 'fcd.ph_pm' : 'NULL');
        $ferOlorCtrlExpr = $hasFerCtrlCol('olor') ? 'fcd.olor' : 'NULL';
        $ferColorCtrlExpr = $hasFerCtrlCol('color') ? 'fcd.color' : 'NULL';
        $ferOrderCtrlExpr = $hasFerCtrlCol('dia')
            ? 'fcd.dia ASC'
            : ($hasFerCtrlCol('fecha') ? 'fcd.fecha ASC' : 'fcd.id ASC');

        $controlFermentacion = $db->fetchAll("
            SELECT {$ferDiaCtrlExpr} as dia,
                   {$ferFechaCtrlExpr} as fecha_control,
                   {$ferVolteoCtrlExpr} as volteo,
                   {$ferTempMasaCtrlExpr} as temperatura_masa,
                   {$ferTempAmbCtrlExpr} as temperatura_ambiente,
                   {$ferPhPulpaCtrlExpr} as ph_pulpa,
                   {$ferPhCotCtrlExpr} as ph_cotiledon,
                   {$ferOlorCtrlExpr} as olor,
                   {$ferColorCtrlExpr} as color
            FROM fermentacion_control_diario fcd
            WHERE fcd.{$fkFerCtrlCol} = ?
            ORDER BY {$ferOrderCtrlExpr}
        ", [$registroFermentacion['id']]);
    }
}

// Obtener registro de secado
$secRespJoinCol = $hasSecCol('responsable_id')
    ? 'rs.responsable_id'
    : ($hasSecCol('operador_id') ? 'rs.operador_id' : 'NULL');
$secFechaExpr = $hasSecCol('fecha')
    ? 'rs.fecha'
    : ($hasSecCol('fecha_inicio')
        ? 'rs.fecha_inicio'
        : ($hasSecCol('created_at') ? 'DATE(rs.created_at)' : 'NULL'));
$secHumedadIniExpr = $hasSecCol('humedad_inicial') ? 'rs.humedad_inicial' : 'NULL';
$secHumedadFinExpr = $hasSecCol('humedad_final') ? 'rs.humedad_final' : 'NULL';

$registroSecado = $db->fetch("
    SELECT rs.*,
           {$secFechaExpr} as fecha_rep,
           {$secHumedadIniExpr} as humedad_inicial_rep,
           {$secHumedadFinExpr} as humedad_final_rep,
           u.nombre as responsable_nombre
    FROM registros_secado rs
    LEFT JOIN usuarios u ON {$secRespJoinCol} = u.id
    WHERE rs.lote_id = ?
    ORDER BY rs.id DESC
    LIMIT 1
", [$id]);

// Obtener control de temperatura de secado
$controlSecado = [];
if ($registroSecado && !empty($colsSecCtrl)) {
    $fkSecCtrlCol = $hasSecCtrlCol('secado_id')
        ? 'secado_id'
        : ($hasSecCtrlCol('registro_secado_id') ? 'registro_secado_id' : null);

    if ($fkSecCtrlCol) {
        $secCtrlFechaExpr = $hasSecCtrlCol('fecha') ? 'sct.fecha' : 'NULL';
        $secCtrlTempExpr = $hasSecCtrlCol('temperatura') ? 'sct.temperatura' : 'NULL';
        if ($hasSecCtrlCol('slot')) {
            $secCtrlSlotExpr = 'sct.slot';
            $secCtrlOrderExpr = ($hasSecCtrlCol('fecha') ? 'sct.fecha ASC, ' : '') . 'sct.slot ASC';
        } elseif ($hasSecCtrlCol('hora')) {
            $secCtrlSlotExpr = "CASE
                WHEN TIME(sct.hora) <= '06:30:00' THEN 1
                WHEN TIME(sct.hora) <= '08:30:00' THEN 2
                WHEN TIME(sct.hora) <= '10:30:00' THEN 3
                WHEN TIME(sct.hora) <= '12:30:00' THEN 4
                WHEN TIME(sct.hora) <= '14:30:00' THEN 5
                WHEN TIME(sct.hora) <= '16:30:00' THEN 6
                ELSE 7
            END";
            $secCtrlOrderExpr = ($hasSecCtrlCol('fecha') ? 'sct.fecha ASC, ' : '') . 'sct.hora ASC';
        } elseif ($hasSecCtrlCol('turno')) {
            $secCtrlSlotExpr = "CASE UPPER(CAST(sct.turno AS CHAR))
                WHEN '1' THEN 1
                WHEN '2' THEN 2
                WHEN '3' THEN 3
                WHEN '4' THEN 4
                WHEN '5' THEN 5
                WHEN '6' THEN 6
                WHEN '7' THEN 7
                WHEN 'AM' THEN 1
                WHEN 'PM' THEN 5
                WHEN 'MANANA' THEN 1
                WHEN 'MAÑANA' THEN 1
                ELSE 7
            END";
            $secCtrlOrderExpr = ($hasSecCtrlCol('fecha') ? 'sct.fecha ASC, ' : '') . 'sct.turno ASC';
        } else {
            $secCtrlSlotExpr = '1';
            $secCtrlOrderExpr = $hasSecCtrlCol('fecha') ? 'sct.fecha ASC' : 'sct.id ASC';
        }

        $controlSecado = $db->fetchAll("
            SELECT {$secCtrlFechaExpr} as fecha,
                   {$secCtrlSlotExpr} as slot,
                   {$secCtrlTempExpr} as temperatura
            FROM secado_control_temperatura sct
            WHERE sct.{$fkSecCtrlCol} = ?
            ORDER BY {$secCtrlOrderExpr}
        ", [$registroSecado['id']]);
    }
}

// Obtener pruebas de corte
$prRespJoinCol = $hasPrCol('responsable_analisis_id')
    ? 'pc.responsable_analisis_id'
    : ($hasPrCol('responsable_id')
        ? 'pc.responsable_id'
        : ($hasPrCol('usuario_id') ? 'pc.usuario_id' : 'NULL'));
$prFechaExpr = $hasPrCol('fecha')
    ? 'pc.fecha'
    : ($hasPrCol('fecha_prueba')
        ? 'pc.fecha_prueba'
        : ($hasPrCol('created_at') ? 'DATE(pc.created_at)' : 'NULL'));
$prTipoExpr = $hasPrCol('tipo_prueba') ? 'pc.tipo_prueba' : "'POST_SECADO'";
$prClasifExpr = $hasPrCol('clasificacion_calidad')
    ? 'pc.clasificacion_calidad'
    : ($hasPrCol('calidad_determinada') ? 'pc.calidad_determinada' : 'NULL');
$prDecisionExpr = $hasPrCol('decision_lote') ? 'pc.decision_lote' : 'NULL';
$prObsExpr = $hasPrCol('observaciones') ? 'pc.observaciones' : 'NULL';
$prOrderExpr = $hasPrCol('fecha')
    ? 'pc.fecha ASC'
    : ($hasPrCol('fecha_prueba') ? 'pc.fecha_prueba ASC' : 'pc.id ASC');

$pruebasCorte = $db->fetchAll("
    SELECT pc.*,
           {$prFechaExpr} as fecha_rep,
           {$prTipoExpr} as tipo_prueba_rep,
           {$prClasifExpr} as clasificacion_calidad_rep,
           {$prDecisionExpr} as decision_lote_rep,
           {$prObsExpr} as observaciones_rep,
           u.nombre as responsable_nombre
    FROM registros_prueba_corte pc
    LEFT JOIN usuarios u ON {$prRespJoinCol} = u.id
    WHERE pc.lote_id = ?
    ORDER BY {$prOrderExpr}
", [$id]);

// Obtener historial
$historial = $db->fetchAll("
    SELECT h.*, u.nombre as usuario_nombre
    FROM lotes_historial h
    LEFT JOIN usuarios u ON h.usuario_id = u.id
    WHERE h.lote_id = ?
    ORDER BY h.created_at ASC
", [$id]);

// Calcular días en proceso
$fechaInicio = new DateTime($lote['fecha_entrada']);
$fechaFin = $lote['estado_proceso'] === 'FINALIZADO' ? new DateTime($lote['updated_at']) : new DateTime();
$diasProceso = $fechaInicio->diff($fechaFin)->days;

// Estados del proceso para el timeline
$estadosProceso = [
    'RECEPCION' => ['icon' => '📥', 'label' => 'Recepción', 'color' => 'blue'],
    'CALIDAD' => ['icon' => '🔍', 'label' => 'Verificación de Lote', 'color' => 'indigo'],
    'PRE_SECADO' => ['icon' => '🌤️', 'label' => 'Pre-secado', 'color' => 'yellow'],
    'FERMENTACION' => ['icon' => '🔥', 'label' => 'Fermentación', 'color' => 'orange'],
    'SECADO' => ['icon' => '☀️', 'label' => 'Secado', 'color' => 'yellow'],
    'CALIDAD_POST' => ['icon' => '✂️', 'label' => 'Prueba Corte', 'color' => 'green'],
    'CALIDAD_SALIDA' => ['icon' => '✅', 'label' => 'Calidad de salida', 'color' => 'emerald'],
    'EMPAQUETADO' => ['icon' => '📦', 'label' => 'Empaquetado', 'color' => 'purple'],
    'ALMACENADO' => ['icon' => '🏭', 'label' => 'Almacenado', 'color' => 'gray'],
    'DESPACHO' => ['icon' => '🚚', 'label' => 'Despacho', 'color' => 'teal'],
    'FINALIZADO' => ['icon' => '✅', 'label' => 'Finalizado', 'color' => 'green']
];

$estadoActualIndex = array_search($lote['estado_proceso'], array_keys($estadosProceso));

$pageTitle = 'Reporte Lote ' . $lote['codigo'];
ob_start();
?>

<div class="max-w-5xl mx-auto space-y-6 print:space-y-4">
    <!-- Header con acciones -->
    <div class="flex items-center justify-between print:hidden">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Reporte de Trazabilidad</h1>
            <p class="text-gray-600">Lote <?= htmlspecialchars($lote['codigo']) ?></p>
        </div>
        <div class="flex items-center gap-3">
            <a href="<?= APP_URL ?>/api/reportes/lote-pdf.php?id=<?= $id ?>" target="_blank"
               class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Imprimir PDF
            </a>
            <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Imprimir
            </button>
            <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $id ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Volver
            </a>
        </div>
    </div>

    <!-- Encabezado del Reporte (visible en impresión) -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden print:shadow-none print:border-2 print:border-green-600">
        <div class="bg-gradient-to-r from-green-600 to-green-700 px-6 py-4 print:bg-green-600">
            <div class="flex items-center justify-between">
                <div class="text-white">
                    <h1 class="text-2xl font-bold"><?= htmlspecialchars($empresa['nombre'] ?? 'MEGABLESSING') ?></h1>
                    <p class="text-green-100 text-sm">Sistema de Control de Procesos de Cacao</p>
                </div>
                <div class="text-right text-white">
                    <p class="text-sm text-green-100">Reporte generado el</p>
                    <p class="font-semibold"><?= date('d/m/Y H:i') ?></p>
                </div>
            </div>
        </div>
        
        <div class="p-6">
            <div class="text-center mb-6">
                <h2 class="text-xl font-bold text-gray-900">REPORTE DE TRAZABILIDAD</h2>
                <p class="text-3xl font-bold text-green-600 mt-2"><?= htmlspecialchars($lote['codigo']) ?></p>
            </div>
            
            <!-- Estado actual -->
            <div class="flex justify-center mb-6">
                <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold
                    <?php
                    $estado = $lote['estado_proceso'];
                    if (in_array($estado, ['FINALIZADO', 'ALMACENADO'])) echo 'bg-green-100 text-green-800';
                    elseif (in_array($estado, ['FERMENTACION', 'SECADO'])) echo 'bg-yellow-100 text-yellow-800';
                    elseif ($estado === 'RECHAZADO') echo 'bg-red-100 text-red-800';
                    else echo 'bg-blue-100 text-blue-800';
                    ?>">
                    <?= $estadosProceso[$estado]['icon'] ?? '📋' ?>
                    <span class="ml-2"><?= $estadosProceso[$estado]['label'] ?? $estado ?></span>
                </span>
            </div>

            <!-- Timeline visual simplificado -->
            <div class="flex items-center justify-center gap-1 mb-6 overflow-x-auto pb-2 print:gap-0">
                <?php 
                $idx = 0;
                foreach ($estadosProceso as $est => $info): 
                    $completado = $idx < $estadoActualIndex;
                    $actual = $idx === $estadoActualIndex;
                ?>
                <div class="flex items-center">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs
                        <?= $completado ? 'bg-green-500 text-white' : ($actual ? 'bg-green-600 text-white ring-2 ring-green-300' : 'bg-gray-200 text-gray-500') ?>">
                        <?= $completado ? '✓' : ($idx + 1) ?>
                    </div>
                    <?php if ($est !== 'FINALIZADO'): ?>
                    <div class="w-4 h-1 <?= $completado ? 'bg-green-500' : 'bg-gray-200' ?> print:w-2"></div>
                    <?php endif; ?>
                </div>
                <?php $idx++; endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Información General del Lote -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden print:shadow-none print:border">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">📋 Información General</h3>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 print:grid-cols-4">
                <div class="bg-blue-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-blue-600 font-medium">Fecha Ingreso</p>
                    <p class="text-lg font-bold text-gray-900"><?= date('d/m/Y', strtotime($lote['fecha_entrada'])) ?></p>
                </div>
                <div class="bg-green-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-green-600 font-medium">Peso Inicial</p>
                    <p class="text-lg font-bold text-gray-900"><?= number_format($lote['peso_inicial_kg'], 1) ?> kg</p>
                </div>
                <div class="bg-amber-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-amber-600 font-medium">Humedad Inicial</p>
                    <p class="text-lg font-bold text-gray-900"><?= $lote['humedad_inicial'] ? number_format($lote['humedad_inicial'], 1) . '%' : 'N/R' ?></p>
                </div>
                <div class="bg-purple-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-purple-600 font-medium">Días en Proceso</p>
                    <p class="text-lg font-bold text-gray-900"><?= $diasProceso ?> días</p>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                <div>
                    <h4 class="text-sm font-semibold text-gray-500 uppercase mb-3">Datos del Lote</h4>
                    <table class="w-full text-sm">
                        <tr class="border-b border-gray-100">
                            <td class="py-2 text-gray-600">Código:</td>
                            <td class="py-2 font-semibold text-right"><?= htmlspecialchars($lote['codigo']) ?></td>
                        </tr>
                        <tr class="border-b border-gray-100">
                            <td class="py-2 text-gray-600">Variedad:</td>
                            <td class="py-2 font-semibold text-right"><?= htmlspecialchars($lote['variedad_nombre']) ?></td>
                        </tr>
                        <tr class="border-b border-gray-100">
                            <td class="py-2 text-gray-600">Estado Producto:</td>
                            <td class="py-2 font-semibold text-right"><?= htmlspecialchars($lote['estado_producto_nombre']) ?></td>
                        </tr>
                        <tr class="border-b border-gray-100">
                            <td class="py-2 text-gray-600">Estado Fermentación:</td>
                            <td class="py-2 font-semibold text-right"><?= htmlspecialchars($lote['estado_fermentacion_nombre'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td class="py-2 text-gray-600">Registrado por:</td>
                            <td class="py-2 font-semibold text-right"><?= htmlspecialchars($lote['usuario_nombre']) ?></td>
                        </tr>
                    </table>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-gray-500 uppercase mb-3">Proveedor / Origen</h4>
                    <table class="w-full text-sm">
                        <tr class="border-b border-gray-100">
                            <td class="py-2 text-gray-600">Nombre:</td>
                            <td class="py-2 font-semibold text-right"><?= htmlspecialchars($lote['proveedor_nombre']) ?></td>
                        </tr>
                        <tr class="border-b border-gray-100">
                            <td class="py-2 text-gray-600">Código:</td>
                            <td class="py-2 font-semibold text-right"><?= htmlspecialchars($lote['proveedor_codigo']) ?></td>
                        </tr>
                        <tr class="border-b border-gray-100">
                            <td class="py-2 text-gray-600">Tipo:</td>
                            <td class="py-2 font-semibold text-right"><?= htmlspecialchars($lote['proveedor_tipo']) ?></td>
                        </tr>
                        <tr class="border-b border-gray-100">
                            <td class="py-2 text-gray-600">Teléfono:</td>
                            <td class="py-2 font-semibold text-right"><?= htmlspecialchars($lote['proveedor_telefono'] ?? 'N/R') ?></td>
                        </tr>
                        <tr>
                            <td class="py-2 text-gray-600">Dirección:</td>
                            <td class="py-2 font-semibold text-right"><?= htmlspecialchars($lote['proveedor_direccion'] ?? 'N/R') ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <?php if ($lote['observaciones']): ?>
            <div class="mt-4 p-4 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-600"><strong>Observaciones:</strong> <?= htmlspecialchars($lote['observaciones']) ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Proceso de Fermentación -->
    <?php if ($registroFermentacion): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden print:shadow-none print:border print:break-inside-avoid">
        <div class="px-6 py-4 border-b border-gray-100 bg-orange-50">
            <h3 class="text-lg font-semibold text-orange-800">🔥 Proceso de Fermentación</h3>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="text-center">
                    <p class="text-sm text-gray-600">Fecha Inicio</p>
                    <p class="font-bold"><?= date('d/m/Y', strtotime($registroFermentacion['fecha_inicio'])) ?></p>
                </div>
                <div class="text-center">
                    <p class="text-sm text-gray-600">Fecha Fin</p>
                    <?php $fechaFinFer = $registroFermentacion['fecha_fin_rep'] ?? ($registroFermentacion['fecha_fin'] ?? null); ?>
                    <p class="font-bold"><?= $fechaFinFer ? date('d/m/Y', strtotime((string)$fechaFinFer)) : 'En proceso' ?></p>
                </div>
                <div class="text-center">
                    <p class="text-sm text-gray-600">Cajón</p>
                    <p class="font-bold"><?= htmlspecialchars($lote['cajon_nombre'] ?? 'N/A') ?></p>
                </div>
                <div class="text-center">
                    <p class="text-sm text-gray-600">Responsable</p>
                    <p class="font-bold"><?= htmlspecialchars($registroFermentacion['responsable_nombre'] ?? 'N/A') ?></p>
                </div>
            </div>
            
            <?php if (!empty($controlFermentacion)): ?>
            <h4 class="text-sm font-semibold text-gray-500 uppercase mb-3">Control Diario</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm border border-gray-200">
                    <thead class="bg-orange-50">
                        <tr>
                            <th class="px-3 py-2 text-left border-b">Día</th>
                            <th class="px-3 py-2 text-center border-b">Volteo</th>
                            <th class="px-3 py-2 text-center border-b">T° Masa</th>
                            <th class="px-3 py-2 text-center border-b">T° Amb.</th>
                            <th class="px-3 py-2 text-center border-b">pH Pulpa</th>
                            <th class="px-3 py-2 text-center border-b">pH Cotil.</th>
                            <th class="px-3 py-2 text-left border-b">Olor</th>
                            <th class="px-3 py-2 text-left border-b">Color</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($controlFermentacion as $control): ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <?php
                            $diaControl = $control['dia'] ?? null;
                            $fechaControl = $control['fecha_control'] ?? null;
                            $etiquetaDia = ($diaControl !== null && $diaControl !== '')
                                ? ('Día ' . $diaControl)
                                : ($fechaControl ? date('d/m', strtotime((string)$fechaControl)) : '-');
                            ?>
                            <td class="px-3 py-2 font-medium"><?= htmlspecialchars((string)$etiquetaDia) ?></td>
                            <td class="px-3 py-2 text-center"><?= !empty($control['volteo']) ? '✅' : '❌' ?></td>
                            <td class="px-3 py-2 text-center"><?= isset($control['temperatura_masa']) && $control['temperatura_masa'] !== null && $control['temperatura_masa'] !== '' ? $control['temperatura_masa'] . '°C' : '-' ?></td>
                            <td class="px-3 py-2 text-center"><?= isset($control['temperatura_ambiente']) && $control['temperatura_ambiente'] !== null && $control['temperatura_ambiente'] !== '' ? $control['temperatura_ambiente'] . '°C' : '-' ?></td>
                            <td class="px-3 py-2 text-center"><?= $control['ph_pulpa'] ?? '-' ?></td>
                            <td class="px-3 py-2 text-center"><?= $control['ph_cotiledon'] ?? '-' ?></td>
                            <td class="px-3 py-2"><?= htmlspecialchars($control['olor'] ?? '-') ?></td>
                            <td class="px-3 py-2"><?= htmlspecialchars($control['color'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($registroFermentacion['aprobado_secado_rep'])): ?>
            <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                <p class="text-green-800 font-medium">✅ Aprobado para Secado</p>
                <?php if (!empty($registroFermentacion['observaciones_finales_rep'])): ?>
                <p class="text-sm text-green-700 mt-1"><?= htmlspecialchars((string)$registroFermentacion['observaciones_finales_rep']) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Proceso de Secado -->
    <?php if ($registroSecado): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden print:shadow-none print:border print:break-inside-avoid">
        <div class="px-6 py-4 border-b border-gray-100 bg-yellow-50">
            <h3 class="text-lg font-semibold text-yellow-800">☀️ Proceso de Secado</h3>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                <div class="text-center">
                    <p class="text-sm text-gray-600">Fecha</p>
                    <?php $fechaSecado = $registroSecado['fecha_rep'] ?? ($registroSecado['fecha'] ?? null); ?>
                    <p class="font-bold"><?= $fechaSecado ? date('d/m/Y', strtotime((string)$fechaSecado)) : 'N/R' ?></p>
                </div>
                <div class="text-center">
                    <p class="text-sm text-gray-600">Secadora</p>
                    <p class="font-bold"><?= htmlspecialchars($lote['secadora_nombre'] ?? 'N/A') ?></p>
                </div>
                <div class="text-center">
                    <p class="text-sm text-gray-600">Humedad Inicial</p>
                    <?php $humedadInicialSecado = $registroSecado['humedad_inicial_rep'] ?? ($registroSecado['humedad_inicial'] ?? null); ?>
                    <p class="font-bold"><?= $humedadInicialSecado !== null && $humedadInicialSecado !== '' ? $humedadInicialSecado . '%' : 'N/R' ?></p>
                </div>
                <div class="text-center">
                    <p class="text-sm text-gray-600">Humedad Final</p>
                    <?php $humedadFinalSecado = $registroSecado['humedad_final_rep'] ?? ($registroSecado['humedad_final'] ?? null); ?>
                    <p class="font-bold <?= ($humedadFinalSecado !== null && $humedadFinalSecado !== '' && (float)$humedadFinalSecado <= 8) ? 'text-green-600' : 'text-amber-600' ?>">
                        <?= $humedadFinalSecado !== null && $humedadFinalSecado !== '' ? $humedadFinalSecado . '%' : 'N/R' ?>
                    </p>
                </div>
                <div class="text-center">
                    <p class="text-sm text-gray-600">Responsable</p>
                    <p class="font-bold"><?= htmlspecialchars($registroSecado['responsable_nombre'] ?? 'N/A') ?></p>
                </div>
            </div>
            
            <?php if (!empty($controlSecado)): ?>
            <h4 class="text-sm font-semibold text-gray-500 uppercase mb-3">Control de Temperatura</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm border border-gray-200">
                    <thead class="bg-yellow-50">
                        <tr>
                            <th class="px-3 py-2 text-left border-b">Fecha</th>
                            <th class="px-3 py-2 text-center border-b">06:00</th>
                            <th class="px-3 py-2 text-center border-b">08:00</th>
                            <th class="px-3 py-2 text-center border-b">10:00</th>
                            <th class="px-3 py-2 text-center border-b">12:00</th>
                            <th class="px-3 py-2 text-center border-b">14:00</th>
                            <th class="px-3 py-2 text-center border-b">16:00</th>
                            <th class="px-3 py-2 text-center border-b">18:00</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $fechasSecado = [];
                        foreach ($controlSecado as $ctrl) {
                            $fechaCtrl = $ctrl['fecha'] ?? null;
                            $slotCtrl = isset($ctrl['slot']) ? (int)$ctrl['slot'] : 1;
                            if ($slotCtrl < 1 || $slotCtrl > 7) {
                                $slotCtrl = 7;
                            }
                            $fechaKey = $fechaCtrl ?: 'SIN_FECHA';
                            $fechasSecado[$fechaKey][$slotCtrl] = $ctrl['temperatura'] ?? null;
                        }
                        foreach ($fechasSecado as $fecha => $slots): 
                        ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="px-3 py-2 font-medium"><?= $fecha === 'SIN_FECHA' ? '-' : date('d/m', strtotime((string)$fecha)) ?></td>
                            <?php for ($i = 1; $i <= 7; $i++): ?>
                            <td class="px-3 py-2 text-center">
                                <?php if (isset($slots[$i]) && $slots[$i] !== null && $slots[$i] !== ''): ?>
                                <span class="<?= $slots[$i] > 60 ? 'text-red-600 font-bold' : '' ?>"><?= $slots[$i] ?>°C</span>
                                <?php else: ?>
                                <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endfor; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pruebas de Corte -->
    <?php if (!empty($pruebasCorte)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden print:shadow-none print:border print:break-inside-avoid">
        <div class="px-6 py-4 border-b border-gray-100 bg-green-50">
            <h3 class="text-lg font-semibold text-green-800">✂️ Pruebas de Corte</h3>
        </div>
        <div class="p-6">
            <?php foreach ($pruebasCorte as $idx => $prueba): ?>
            <?php
            $tipoPrueba = (string)($prueba['tipo_prueba_rep'] ?? ($prueba['tipo_prueba'] ?? 'POST_SECADO'));
            $fechaPrueba = $prueba['fecha_rep'] ?? ($prueba['fecha'] ?? null);
            $clasificacionPrueba = $prueba['clasificacion_calidad_rep'] ?? ($prueba['clasificacion_calidad'] ?? null);
            $decisionPrueba = $prueba['decision_lote_rep'] ?? ($prueba['decision_lote'] ?? null);
            $obsPrueba = $prueba['observaciones_rep'] ?? ($prueba['observaciones'] ?? null);
            ?>
            <div class="<?= $idx > 0 ? 'mt-6 pt-6 border-t border-gray-200' : '' ?>">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium
                            <?= $tipoPrueba === 'RECEPCION' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800' ?>">
                            <?= $tipoPrueba === 'RECEPCION' ? '📥 Recepción' : '✅ Post-Secado' ?>
                        </span>
                        <span class="ml-2 text-sm text-gray-600"><?= $fechaPrueba ? date('d/m/Y', strtotime((string)$fechaPrueba)) : 'N/R' ?></span>
                    </div>
                    <?php if (!empty($clasificacionPrueba)): ?>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold
                        <?php
                        $clasif = strtoupper((string)$clasificacionPrueba);
                        if ($clasif === 'PREMIUM') echo 'bg-green-100 text-green-800';
                        elseif ($clasif === 'EXPORTACION') echo 'bg-blue-100 text-blue-800';
                        elseif ($clasif === 'NACIONAL') echo 'bg-yellow-100 text-yellow-800';
                        else echo 'bg-red-100 text-red-800';
                        ?>">
                        <?= htmlspecialchars((string)$clasificacionPrueba) ?>
                    </span>
                    <?php endif; ?>
                </div>
                
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-green-50 rounded-lg p-3 text-center">
                        <p class="text-xs text-green-600">Bien Fermentados</p>
                        <p class="text-xl font-bold text-green-700"><?= $prueba['granos_bien_fermentados'] ?? 0 ?>%</p>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-3 text-center">
                        <p class="text-xs text-purple-600">Violetas</p>
                        <p class="text-xl font-bold text-purple-700"><?= $prueba['granos_violeta'] ?? 0 ?>%</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-3 text-center">
                        <p class="text-xs text-gray-600">Pizarrosos</p>
                        <p class="text-xl font-bold text-gray-700"><?= $prueba['granos_pizarrosos'] ?? 0 ?>%</p>
                    </div>
                    <div class="bg-red-50 rounded-lg p-3 text-center">
                        <p class="text-xs text-red-600">Mohosos</p>
                        <p class="text-xl font-bold text-red-700"><?= $prueba['granos_mohosos'] ?? 0 ?>%</p>
                    </div>
                </div>
                
                <div class="grid grid-cols-3 md:grid-cols-6 gap-3 mt-3">
                    <div class="text-center p-2 bg-gray-50 rounded">
                        <p class="text-xs text-gray-500">Insectados</p>
                        <p class="font-semibold"><?= $prueba['granos_insectados'] ?? 0 ?>%</p>
                    </div>
                    <div class="text-center p-2 bg-gray-50 rounded">
                        <p class="text-xs text-gray-500">Germinados</p>
                        <p class="font-semibold"><?= $prueba['granos_germinados'] ?? 0 ?>%</p>
                    </div>
                    <div class="text-center p-2 bg-gray-50 rounded">
                        <p class="text-xs text-gray-500">Planos/Vanos</p>
                        <p class="font-semibold"><?= $prueba['granos_planos_vanos'] ?? 0 ?>%</p>
                    </div>
                    <div class="text-center p-2 bg-gray-50 rounded">
                        <p class="text-xs text-gray-500">Partidos</p>
                        <p class="font-semibold"><?= $prueba['granos_partidos'] ?? 0 ?>%</p>
                    </div>
                    <div class="text-center p-2 bg-gray-50 rounded">
                        <p class="text-xs text-gray-500">Múltiples</p>
                        <p class="font-semibold"><?= $prueba['granos_multiples'] ?? 0 ?>%</p>
                    </div>
                    <div class="text-center p-2 bg-amber-50 rounded">
                        <p class="text-xs text-amber-600">Peso 100g</p>
                        <p class="font-semibold"><?= $prueba['peso_100_granos'] ? number_format($prueba['peso_100_granos'], 1) . 'g' : '-' ?></p>
                    </div>
                </div>
                
                <?php if (!empty($decisionPrueba)): ?>
                <div class="mt-3 p-3 rounded-lg <?= $decisionPrueba === 'APROBADO' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200' ?>">
                    <p class="font-medium <?= $decisionPrueba === 'APROBADO' ? 'text-green-800' : 'text-red-800' ?>">
                        <?= $decisionPrueba === 'APROBADO' ? '✅ Lote Aprobado' : '❌ Lote Rechazado' ?>
                    </p>
                    <?php if (!empty($obsPrueba)): ?>
                    <p class="text-sm mt-1 <?= $decisionPrueba === 'APROBADO' ? 'text-green-700' : 'text-red-700' ?>">
                        <?= htmlspecialchars((string)$obsPrueba) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Historial de Cambios -->
    <?php if (!empty($historial)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden print:shadow-none print:border">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900">📜 Historial de Trazabilidad</h3>
        </div>
        <div class="p-6">
            <div class="relative">
                <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-gray-200"></div>
                <div class="space-y-4">
                    <?php foreach ($historial as $h): ?>
                    <div class="relative pl-10">
                        <div class="absolute left-2 w-4 h-4 rounded-full bg-green-500 border-2 border-white"></div>
                        <div class="bg-gray-50 rounded-lg p-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($h['accion']) ?></span>
                                <span class="text-xs text-gray-500"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></span>
                            </div>
                            <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($h['descripcion']) ?></p>
                            <p class="text-xs text-gray-400 mt-1">Por: <?= htmlspecialchars($h['usuario_nombre'] ?? 'Sistema') ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pie del Reporte -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 print:shadow-none print:border">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="text-center">
                <div class="border-t-2 border-gray-300 pt-2 mt-8">
                    <p class="text-sm text-gray-600">Elaborado por</p>
                </div>
            </div>
            <div class="text-center">
                <div class="border-t-2 border-gray-300 pt-2 mt-8">
                    <p class="text-sm text-gray-600">Revisado por</p>
                </div>
            </div>
            <div class="text-center">
                <div class="border-t-2 border-gray-300 pt-2 mt-8">
                    <p class="text-sm text-gray-600">Aprobado por</p>
                </div>
            </div>
        </div>
        
        <div class="mt-6 pt-4 border-t border-gray-200 text-center text-sm text-gray-500">
            <p>Reporte generado por <strong class="text-green-600">MEGABLESSING</strong> - Sistema de Control de Procesos</p>
            <p class="text-xs mt-1">Desarrollado por Shalom Software · <?= date('Y') ?></p>
        </div>
    </div>
</div>

<style>
@media print {
    body { 
        font-size: 11px; 
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .print\\:hidden { display: none !important; }
    .print\\:shadow-none { box-shadow: none !important; }
    .print\\:border { border: 1px solid #e5e7eb !important; }
    .print\\:border-2 { border-width: 2px !important; }
    .print\\:break-inside-avoid { break-inside: avoid; }
    .print\\:space-y-4 > * + * { margin-top: 1rem; }
    .print\\:grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .print\\:gap-0 { gap: 0; }
    .print\\:w-2 { width: 0.5rem; }
    .print\\:bg-green-600 { background-color: #16a34a !important; }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
