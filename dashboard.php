<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Dashboard Principal
 * Desarrollado por: Shalom Software
 */
require_once __DIR__ . '/bootstrap.php';
requireAuth();

$db = Database::getInstance();

function safeFetch($db, $sql, $params = []) {
    try {
        return $db->fetch($sql, $params);
    } catch (Throwable $e) {
        return null;
    }
}

function safeFetchAll($db, $sql, $params = []) {
    try {
        return $db->fetchAll($sql, $params);
    } catch (Throwable $e) {
        return [];
    }
}

function kpiWindowDays(?string $frecuencia): int {
    $frecuencia = strtoupper(trim((string)$frecuencia));
    return match ($frecuencia) {
        'DIARIA' => 1,
        'SEMANAL' => 7,
        'MENSUAL' => 30,
        'TRIMESTRAL' => 90,
        'SEMESTRAL' => 180,
        'POR_LOTE', 'POR_EMBARQUE' => 30,
        default => 30,
    };
}

function kpiWindowLabel(int $days): string {
    if ($days <= 1) {
        return 'Último día';
    }
    return 'Últimos ' . $days . ' días';
}

function formatIndicadorValor(?float $valor, ?string $unidad): string {
    if ($valor === null) {
        return 'N/D';
    }
    $unidad = trim((string)$unidad);
    $decimals = 2;
    if (in_array($unidad, ['%', 'g', 'minutos', 'horas', 'veces'], true)) {
        $decimals = 1;
    }
    if ($unidad === 'ratio') {
        $decimals = 2;
    }
    $formatted = number_format($valor, $decimals, ',', '.');
    if ($unidad === '') {
        return $formatted;
    }
    if ($unidad === '%') {
        return $formatted . '%';
    }
    return $formatted . ' ' . $unidad;
}

// Estadísticas generales (según schema: fecha_entrada, estado_proceso)
$stats = [
    'lotes_hoy' => $db->count('lotes', 'DATE(fecha_entrada) = CURDATE()'),
    'lotes_mes' => $db->count('lotes', 'MONTH(fecha_entrada) = MONTH(CURDATE()) AND YEAR(fecha_entrada) = YEAR(CURDATE())'),
    'en_fermentacion' => $db->count('lotes', "estado_proceso = 'FERMENTACION'"),
    'en_secado' => $db->count('lotes', "estado_proceso = 'SECADO' OR estado_proceso = 'PRE_SECADO'"),
    'total_kg_mes' => ($db->fetch(
        "SELECT COALESCE(SUM(peso_inicial_kg), 0) as total
         FROM lotes
         WHERE MONTH(fecha_entrada) = MONTH(CURDATE()) AND YEAR(fecha_entrada) = YEAR(CURDATE())"
    )['total'] ?? 0),
];

// Últimos lotes
$ultimosLotes = $db->fetchAll("
    SELECT l.*, p.nombre as proveedor, v.nombre as variedad, ep.nombre as estado_producto_nombre
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    JOIN variedades v ON l.variedad_id = v.id
    JOIN estados_producto ep ON l.estado_producto_id = ep.id
    ORDER BY l.created_at DESC
    LIMIT 10
");

// Datos para gráfico de lotes por día (última semana)
$lotesXDia = $db->fetchAll("
    SELECT DATE(fecha_entrada) as fecha, COUNT(*) as cantidad, COALESCE(SUM(peso_inicial_kg),0) as peso
    FROM lotes
    WHERE fecha_entrada >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(fecha_entrada)
    ORDER BY fecha
");

// Lotes por estado (últimos 30 días)
$lotesPorEstado = $db->fetchAll("
    SELECT estado_proceso, COUNT(*) as cantidad
    FROM lotes
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY estado_proceso
");

$clasificacionEstados = safeFetchAll($db, "
    SELECT ep.nombre as estado, COUNT(*) as cantidad
    FROM lotes l
    JOIN estados_producto ep ON l.estado_producto_id = ep.id
    GROUP BY ep.nombre
    ORDER BY cantidad DESC
");

$volteosDiarios = safeFetchAll($db, "
    SELECT DATE(created_at) as fecha, SUM(volteo) as volteos, COUNT(*) as registros
    FROM fermentacion_control_diario
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY DATE(created_at)
    ORDER BY fecha
");

$secadoTemperaturas = safeFetchAll($db, "
    SELECT hora, AVG(temperatura) as temp
    FROM secado_control_temperatura
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND temperatura IS NOT NULL
    GROUP BY hora
    ORDER BY hora
");

$defectosPromedio = safeFetch($db, "
    SELECT AVG(violeta) as violeta,
           AVG(pizarrosos) as pizarrosos,
           AVG(mohosos) as mohosos
    FROM registros_prueba_corte
    WHERE tipo_prueba = 'POST_SECADO'
      AND fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
if (!$defectosPromedio) {
    $defectosPromedio = safeFetch($db, "
        SELECT AVG(granos_violetas) as violeta,
               AVG(granos_pizarra) as pizarrosos,
               AVG(granos_mohosos) as mohosos
        FROM registros_prueba_corte
        WHERE fecha_prueba >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
}

$indicadores = $db->fetchAll("
    SELECT id, etapa_proceso, nombre, meta, formula, frecuencia, justificacion, unidad
    FROM indicadores
    WHERE activo = 1
    ORDER BY id
");

$indicadoresPorNombre = [];
foreach ($indicadores as $indicador) {
    $indicadoresPorNombre[$indicador['nombre']] = $indicador;
}

$kpiValores = [];

$diasHumedad = kpiWindowDays($indicadoresPorNombre['Humedad de Ingreso']['frecuencia'] ?? null);
$humedadIngreso = safeFetch($db, "
    SELECT AVG(humedad_inicial) as valor
    FROM lotes
    WHERE humedad_inicial IS NOT NULL
      AND fecha_entrada >= DATE_SUB(CURDATE(), INTERVAL {$diasHumedad} DAY)
");
if ($humedadIngreso && $humedadIngreso['valor'] !== null) {
    $kpiValores['Humedad de Ingreso'] = [
        'value' => number_format((float)$humedadIngreso['valor'], 1, ',', '.') . '%',
        'sub' => kpiWindowLabel($diasHumedad)
    ];
}

$diasVolteo = kpiWindowDays($indicadoresPorNombre['Volteo Disciplinado']['frecuencia'] ?? null);
$volteoStats = safeFetch($db, "
    SELECT SUM(volteo) as ejecutados, COUNT(*) as programados
    FROM fermentacion_control_diario
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$diasVolteo} DAY)
");
if ($volteoStats && (int)$volteoStats['programados'] > 0) {
    $ratioVolteo = (float)$volteoStats['ejecutados'] / (float)$volteoStats['programados'];
    $kpiValores['Volteo Disciplinado'] = [
        'value' => number_format($ratioVolteo * 100, 0, ',', '.') . '%',
        'sub' => kpiWindowLabel($diasVolteo) . ' • ' . (int)$volteoStats['ejecutados'] . '/' . (int)$volteoStats['programados']
    ];
}

$diasRendimiento = kpiWindowDays($indicadoresPorNombre['Rendimiento del cacao']['frecuencia'] ?? null);
$rendimientoSecado = safeFetch($db, "
    SELECT AVG(CASE WHEN peso_inicial > 0 AND peso_final > 0 THEN peso_final / peso_inicial END) as ratio
    FROM registros_secado
    WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL {$diasRendimiento} DAY)
");
if (!$rendimientoSecado || $rendimientoSecado['ratio'] === null) {
    $rendimientoSecado = safeFetch($db, "
        SELECT AVG(CASE WHEN peso_inicial_kg > 0 AND peso_actual_kg > 0 THEN peso_actual_kg / peso_inicial_kg END) as ratio
        FROM lotes
        WHERE fecha_entrada >= DATE_SUB(CURDATE(), INTERVAL {$diasRendimiento} DAY)
    ");
}
if ($rendimientoSecado && $rendimientoSecado['ratio'] !== null) {
    $kpiValores['Rendimiento del cacao'] = [
        'value' => number_format(((float)$rendimientoSecado['ratio']) * 100, 1, ',', '.') . '%',
        'sub' => kpiWindowLabel($diasRendimiento)
    ];
}

$diasEficiencia = kpiWindowDays($indicadoresPorNombre['Eficiencia Térmica']['frecuencia'] ?? null);
$eficienciaTermica = safeFetch($db, "
    SELECT AVG(TIMESTAMPDIFF(HOUR, fecha_inicio, fecha_fin)) as horas
    FROM registros_secado
    WHERE fecha_inicio IS NOT NULL AND fecha_fin IS NOT NULL
      AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL {$diasEficiencia} DAY)
");
if (!$eficienciaTermica || $eficienciaTermica['horas'] === null) {
    $eficienciaTermica = safeFetch($db, "
        SELECT AVG(TIMESTAMPDIFF(HOUR, fecha_carga, CONCAT(DATE(fecha_carga), ' ', hora_descarga))) as horas
        FROM registros_secado
        WHERE fecha_carga IS NOT NULL AND hora_descarga IS NOT NULL
          AND fecha_carga >= DATE_SUB(NOW(), INTERVAL {$diasEficiencia} DAY)
    ");
}
if ($eficienciaTermica && $eficienciaTermica['horas'] !== null) {
    $kpiValores['Eficiencia Térmica'] = [
        'value' => number_format((float)$eficienciaTermica['horas'], 1, ',', '.') . ' h',
        'sub' => kpiWindowLabel($diasEficiencia)
    ];
}

$diasCalidad = kpiWindowDays($indicadoresPorNombre['Índice de Fermentación']['frecuencia'] ?? null);
$calidadPost = safeFetch($db, "
    SELECT AVG(bien_fermentados) as fermentados,
           AVG(violeta) as violeta,
           AVG(mohosos) as mohosos,
           AVG(peso_100_granos) as peso_100
    FROM registros_prueba_corte
    WHERE tipo_prueba = 'POST_SECADO'
      AND fecha >= DATE_SUB(CURDATE(), INTERVAL {$diasCalidad} DAY)
");
if (!$calidadPost) {
    $calidadPost = safeFetch($db, "
        SELECT AVG(bien_fermentados) as fermentados,
               AVG(violeta) as violeta,
               AVG(mohosos) as mohosos,
               AVG(peso_100_granos) as peso_100
        FROM registros_prueba_corte
        WHERE tipo_prueba = 'POST_SECADO'
          AND created_at >= DATE_SUB(NOW(), INTERVAL {$diasCalidad} DAY)
    ");
}
if ($calidadPost) {
    if ($calidadPost['fermentados'] !== null) {
        $kpiValores['Índice de Fermentación'] = [
            'value' => number_format((float)$calidadPost['fermentados'], 1, ',', '.') . '%',
            'sub' => kpiWindowLabel($diasCalidad)
        ];
    }
    if ($calidadPost['violeta'] !== null || $calidadPost['mohosos'] !== null) {
        $violeta = $calidadPost['violeta'] !== null ? number_format((float)$calidadPost['violeta'], 1, ',', '.') . '%' : 'N/D';
        $mohosos = $calidadPost['mohosos'] !== null ? number_format((float)$calidadPost['mohosos'], 1, ',', '.') . '%' : 'N/D';
        $kpiValores['Pureza Física (Violetas/Mohos)'] = [
            'value' => 'V ' . $violeta . ' / M ' . $mohosos,
            'sub' => kpiWindowLabel($diasCalidad)
        ];
    }
    if ($calidadPost['peso_100'] !== null) {
        $kpiValores['Peso de 100 granos'] = [
            'value' => number_format((float)$calidadPost['peso_100'], 1, ',', '.') . ' g',
            'sub' => kpiWindowLabel($diasCalidad)
        ];
    }
}

foreach ($indicadores as $indicador) {
    if (isset($kpiValores[$indicador['nombre']])) {
        continue;
    }
    $dias = kpiWindowDays($indicador['frecuencia'] ?? null);
    $registro = safeFetch($db, "
        SELECT valor, fecha
        FROM indicadores_registros
        WHERE indicador_id = ?
          AND fecha >= DATE_SUB(CURDATE(), INTERVAL {$dias} DAY)
        ORDER BY fecha DESC, created_at DESC
        LIMIT 1
    ", [$indicador['id']]);

    if ($registro && $registro['valor'] !== null) {
        $valor = formatIndicadorValor((float)$registro['valor'], $indicador['unidad'] ?? '');
        $sub = kpiWindowLabel($dias);
        if (!empty($registro['fecha'])) {
            $sub .= ' • ' . Helpers::formatDate($registro['fecha']);
        }
        $kpiValores[$indicador['nombre']] = [
            'value' => $valor,
            'sub' => $sub
        ];
    }
}

$pageTitle = 'Dashboard';
$pageSubtitle = 'Resumen general del sistema';

ob_start();
?>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="stat-card">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-white/80 text-sm mb-1">Lotes Hoy</p>
                <p class="text-3xl font-bold"><?= (int)$stats['lotes_hoy'] ?></p>
            </div>
            <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
            </div>
        </div>
        <p class="text-white/60 text-sm mt-3">
            <span class="text-gold">+<?= (int)$stats['lotes_mes'] ?></span> este mes
        </p>
    </div>

    <div class="stat-card accent">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-white/80 text-sm mb-1">En Fermentación</p>
                <p class="text-3xl font-bold"><?= (int)$stats['en_fermentacion'] ?></p>
            </div>
            <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
                </svg>
            </div>
        </div>
        <p class="text-white/60 text-sm mt-3">Lotes en proceso</p>
    </div>

    <div class="stat-card gold">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-primary-dark/80 text-sm mb-1">En Secado</p>
                <p class="text-3xl font-bold"><?= (int)$stats['en_secado'] ?></p>
            </div>
            <div class="w-12 h-12 bg-primary/20 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
            </div>
        </div>
        <p class="text-primary-dark/60 text-sm mt-3">Lotes activos</p>
    </div>

    <div class="stat-card gray">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-white/80 text-sm mb-1">Total Kg (Mes)</p>
                <p class="text-3xl font-bold"><?= number_format((float)$stats['total_kg_mes'], 0, ',', '.') ?></p>
            </div>
            <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/>
                </svg>
            </div>
        </div>
        <p class="text-white/60 text-sm mt-3">≈ <?= number_format(Helpers::kgToQQ((float)$stats['total_kg_mes']), 0) ?> quintales</p>
    </div>
</div>

<!-- Flujo Operativo -->
<div class="card mb-8">
    <div class="card-header">
        <h3 class="font-semibold text-gray-900">Flujo Operativo Actualizado</h3>
        <p class="text-sm text-warmgray mt-1">La ficha de registro es el primer paso obligatorio del proceso.</p>
    </div>
    <div class="card-body">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-5">
                <h4 class="font-semibold text-emerald-800 mb-3">Procesos de Recepción</h4>
                <ol class="space-y-2 text-sm text-emerald-900">
                    <li><a href="<?= APP_URL ?>/fichas/index.php?vista=recepcion" class="hover:underline"> Recepción (Ficha de Recepción)</a></li>
                    <li><a href="<?= APP_URL ?>/fichas/index.php?vista=pagos" class="hover:underline">Registro de Pagos</a></li>
                    <li><a href="<?= APP_URL ?>/fichas/index.php?vista=codificacion" class="hover:underline">Codificación de Lote</a></li>
                    <li><a href="<?= APP_URL ?>/fichas/index.php?vista=etiqueta" class="hover:underline">Imprimir Etiqueta</a></li>
                </ol>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-xl p-5">
                <h4 class="font-semibold text-blue-800 mb-3">Procesos Post-cosecha</h4>
                <ol class="space-y-2 text-sm text-blue-900">
                    <li><a href="<?= APP_URL ?>/lotes/index.php" class="hover:underline">Verificación de Lote</a></li>
                    <li><a href="<?= APP_URL ?>/secado/index.php" class="hover:underline">Pre-secado (Ficha de pre-secado)</a></li>
                    <li><a href="<?= APP_URL ?>/fermentacion/index.php" class="hover:underline">Fermentación (Ficha de fermentación)</a></li>
                    <li><a href="<?= APP_URL ?>/secado/index.php" class="hover:underline">Secado final (Ficha de secado)</a></li>
                    <li><a href="<?= APP_URL ?>/prueba-corte/index.php" class="hover:underline">Prueba de Corte (Ficha de prueba de corte)</a></li>
                    <li><a href="<?= APP_URL ?>/calidad-salida/index.php" class="hover:underline">Calidad de salida</a></li>
                </ol>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <a href="<?= APP_URL ?>/fichas/index.php?vista=recepcion" class="btn btn-primary">
                Recepción (Listado)
            </a>
            <a href="<?= APP_URL ?>/fichas/index.php?vista=pagos" class="btn btn-outline">
                Registro de Pagos
            </a>
            <a href="<?= APP_URL ?>/fichas/index.php?vista=codificacion" class="btn btn-outline">
                Codificación de Lote
            </a>
            <a href="<?= APP_URL ?>/fichas/index.php?vista=etiqueta" class="btn btn-outline">
                Imprimir Etiqueta
            </a>
            <a href="<?= APP_URL ?>/lotes/index.php" class="btn btn-outline">
                Verificación de Lote
            </a>
            <a href="<?= APP_URL ?>/calidad-salida/index.php" class="btn btn-outline">
                Calidad de salida
            </a>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="card">
        <div class="card-header">
            <h3 class="font-semibold text-gray-900">Recepción Última Semana</h3>
        </div>
        <div class="card-body">
            <canvas id="chartLotesXDia" height="250"></canvas>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="font-semibold text-gray-900">Lotes por Estado de Proceso</h3>
        </div>
        <div class="card-body">
            <canvas id="chartLotesXEstado" height="250"></canvas>
        </div>
    </div>
</div>

<!-- Proceso y Control -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="card">
        <div class="card-header">
            <h3 class="font-semibold text-gray-900">Clasificación por Humedad (Estado del Producto)</h3>
        </div>
        <div class="card-body">
            <canvas id="chartClasificacion" height="240"></canvas>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="font-semibold text-gray-900">Fermentación: Volteos Diarios</h3>
        </div>
        <div class="card-body">
            <canvas id="chartVolteos" height="240"></canvas>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="card">
        <div class="card-header">
            <h3 class="font-semibold text-gray-900">Secado: Temperatura Promedio por Hora</h3>
        </div>
        <div class="card-body">
            <canvas id="chartSecadoTemp" height="240"></canvas>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="font-semibold text-gray-900">Prueba de Corte: Defectos Promedio</h3>
        </div>
        <div class="card-body">
            <canvas id="chartDefectos" height="240"></canvas>
        </div>
    </div>
</div>

<?php if (!empty($indicadores)): ?>
<div class="card mb-8">
    <div class="card-header flex items-start justify-between gap-4">
        <div>
            <h3 class="font-semibold text-gray-900">Indicadores Clave del Proceso</h3>
            <p class="text-sm text-warmgray">Valores calculados según la frecuencia definida o último registro.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= APP_URL ?>/indicadores/index.php" class="btn btn-sm btn-primary">
                Registrar KPI
            </a>
            <a href="<?= APP_URL ?>/reportes/indicadores.php" class="btn btn-sm btn-outline">
                Ver detalle
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        </div>
    </div>
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Etapa del Proceso</th>
                    <th>Indicador Clave</th>
                    <th>Valor Actual</th>
                    <th>Meta</th>
                    <th>Fórmula / Método</th>
                    <th>Frecuencia</th>
                    <th>Detalle / Justificación</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($indicadores as $indicador): ?>
                    <?php
                    $kpiActual = $kpiValores[$indicador['nombre']] ?? null;
                    $valorActual = $kpiActual['value'] ?? 'N/D';
                    $valorClass = $kpiActual ? 'text-gray-900' : 'text-warmgray';
                    $frecuencia = ucfirst(strtolower(str_replace('_', ' ', $indicador['frecuencia'] ?? '')));
                    ?>
                    <tr class="hover:bg-ivory/50">
                        <td class="font-medium"><?= htmlspecialchars($indicador['etapa_proceso'] ?? '') ?></td>
                        <td><?= htmlspecialchars($indicador['nombre'] ?? '') ?></td>
                        <td class="font-semibold <?= $valorClass ?>">
                            <?= htmlspecialchars($valorActual) ?>
                            <?php if (!empty($kpiActual['sub'])): ?>
                                <div class="text-xs text-warmgray"><?= htmlspecialchars($kpiActual['sub']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($indicador['meta'] ?? '') ?></td>
                        <td class="text-sm text-warmgray"><?= htmlspecialchars($indicador['formula'] ?? '') ?></td>
                        <td><?= htmlspecialchars($frecuencia) ?></td>
                        <td class="text-sm text-warmgray"><?= htmlspecialchars($indicador['justificacion'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Últimos Lotes -->
<div class="card">
    <div class="card-header flex items-center justify-between">
        <h3 class="font-semibold text-gray-900">Últimos Lotes Registrados</h3>
        <a href="<?= APP_URL ?>/lotes/index.php" class="btn btn-sm btn-outline">
            Ver todos
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    </div>

    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Proveedor</th>
                    <th>Variedad</th>
                    <th>Estado</th>
                    <th>Peso (Kg)</th>
                    <th>Proceso</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ultimosLotes)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-8 text-warmgray">No hay lotes registrados aún</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ultimosLotes as $lote): ?>
                        <tr class="hover:bg-ivory/50">
                            <td>
                                <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= (int)$lote['id'] ?>"
                                   class="font-medium text-primary hover:underline">
                                    <?= htmlspecialchars($lote['codigo'] ?? '') ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($lote['proveedor'] ?? '') ?></td>
                            <td><?= htmlspecialchars($lote['variedad'] ?? '') ?></td>
                            <td><?= htmlspecialchars($lote['estado_producto_nombre'] ?? '') ?></td>
                            <td class="font-medium"><?= number_format((float)($lote['peso_inicial_kg'] ?? 0), 2) ?></td>
                            <td><?= Helpers::getEstadoProcesoBadge($lote['estado_proceso'] ?? '') ?></td>
                            <td class="text-warmgray"><?= Helpers::formatDate($lote['fecha_entrada'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$content = ob_get_clean();

// ✅ JSON listo antes del heredoc (evita $json_encode y evita llamar funciones dentro del heredoc)
$lotesXDiaJson = json_encode($lotesXDia ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$lotesPorEstadoJson = json_encode($lotesPorEstado ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$clasificacionJson = json_encode($clasificacionEstados ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$volteosJson = json_encode($volteosDiarios ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$secadoTempJson = json_encode($secadoTemperaturas ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$defectosJson = json_encode($defectosPromedio ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($lotesXDiaJson === false) $lotesXDiaJson = '[]';
if ($lotesPorEstadoJson === false) $lotesPorEstadoJson = '[]';
if ($clasificacionJson === false) $clasificacionJson = '[]';
if ($volteosJson === false) $volteosJson = '[]';
if ($secadoTempJson === false) $secadoTempJson = '[]';
if ($defectosJson === false) $defectosJson = '{}';

$extraScripts = <<<HTML
<script>
const lotesXDiaData = $lotesXDiaJson;
const lotesPorEstadoData = $lotesPorEstadoJson;
const clasificacionData = $clasificacionJson;
const volteosData = $volteosJson;
const secadoTempData = $secadoTempJson;
const defectosData = $defectosJson;

const colors = {
  primary: '#1e4d39',
  gold: '#D6C29A',
  olive: '#A3B7A5',
  warm: '#73796F'
};

// Gráfico lotes por día
const ctxDia = document.getElementById('chartLotesXDia').getContext('2d');
new Chart(ctxDia, {
  type: 'bar',
  data: {
    labels: lotesXDiaData.map(d => {
      const date = new Date(d.fecha + 'T12:00:00');
      return date.toLocaleDateString('es-EC', { weekday: 'short', day: 'numeric' });
    }),
    datasets: [{
      label: 'Lotes',
      data: lotesXDiaData.map(d => d.cantidad),
      backgroundColor: colors.primary,
      borderRadius: 6,
      barPercentage: 0.7
    }, {
      label: 'Peso (Kg/100)',
      data: lotesXDiaData.map(d => (d.peso || 0) / 100),
      backgroundColor: colors.gold,
      borderRadius: 6,
      barPercentage: 0.7
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' } },
    scales: {
      y: { beginAtZero: true, grid: { color: '#f3f4f6' } },
      x: { grid: { display: false } }
    }
  }
});

// Gráfico lotes por estado
const estadoLabels = {
  'RECEPCION': 'Recepción',
  'CALIDAD': 'Verificación de Lote',
  'PRE_SECADO': 'Pre-Secado',
  'FERMENTACION': 'Fermentación',
  'SECADO': 'Secado',
  'CALIDAD_POST': 'Prueba de Corte',
  'CALIDAD_SALIDA': 'Calidad de salida',
  'EMPAQUETADO': 'Empaquetado',
  'ALMACENADO': 'Almacenado',
  'DESPACHO': 'Despacho',
  'FINALIZADO': 'Finalizado',
  'RECHAZADO': 'Rechazado'
};

const estadoColors = [
  '#3b82f6', '#8b5cf6', '#f59e0b', '#f97316',
  '#ef4444', '#6366f1', '#10b981', '#ec4899', '#6b7280', '#14b8a6', '#22c55e'
];

const ctxEstado = document.getElementById('chartLotesXEstado').getContext('2d');
new Chart(ctxEstado, {
  type: 'doughnut',
  data: {
    labels: lotesPorEstadoData.map(d => estadoLabels[d.estado_proceso] || d.estado_proceso),
    datasets: [{
      data: lotesPorEstadoData.map(d => d.cantidad),
      backgroundColor: estadoColors.slice(0, lotesPorEstadoData.length),
      borderWidth: 0
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'right' } },
    cutout: '60%'
  }
});

// Clasificación por estado de producto
const ctxClasificacion = document.getElementById('chartClasificacion').getContext('2d');
new Chart(ctxClasificacion, {
  type: 'doughnut',
  data: {
    labels: clasificacionData.map(d => d.estado),
    datasets: [{
      data: clasificacionData.map(d => d.cantidad),
      backgroundColor: ['#1e4d39', '#D6C29A', '#A3B7A5', '#73796F'],
      borderWidth: 0
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'right' } },
    cutout: '55%'
  }
});

// Fermentación: volteos diarios
const ctxVolteos = document.getElementById('chartVolteos').getContext('2d');
new Chart(ctxVolteos, {
  type: 'bar',
  data: {
    labels: volteosData.map(d => {
      const date = new Date(d.fecha + 'T12:00:00');
      return date.toLocaleDateString('es-EC', { day: 'numeric', month: 'short' });
    }),
    datasets: [{
      label: 'Volteos',
      data: volteosData.map(d => d.volteos || 0),
      backgroundColor: colors.primary,
      borderRadius: 6
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, grid: { color: '#f3f4f6' } },
      x: { grid: { display: false } }
    }
  }
});

// Secado: temperatura promedio por hora
const ctxSecado = document.getElementById('chartSecadoTemp').getContext('2d');
new Chart(ctxSecado, {
  type: 'line',
  data: {
    labels: secadoTempData.map(d => d.hora),
    datasets: [{
      label: '°C',
      data: secadoTempData.map(d => d.temp || 0),
      borderColor: colors.gold,
      backgroundColor: 'rgba(214, 194, 154, 0.2)',
      fill: true,
      tension: 0.35
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' } },
    scales: {
      y: { beginAtZero: true, grid: { color: '#f3f4f6' } },
      x: { grid: { display: false } }
    }
  }
});

// Calidad post-secado: defectos promedio
const ctxDefectos = document.getElementById('chartDefectos').getContext('2d');
const defectosValores = [
  defectosData.violeta || 0,
  defectosData.pizarrosos || 0,
  defectosData.mohosos || 0
];
new Chart(ctxDefectos, {
  type: 'bar',
  data: {
    labels: ['Violetas', 'Pizarrosos', 'Mohosos'],
    datasets: [{
      label: '% promedio',
      data: defectosValores,
      backgroundColor: ['#6366f1', '#f59e0b', '#ef4444'],
      borderRadius: 6
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, grid: { color: '#f3f4f6' } },
      x: { grid: { display: false } }
    }
  }
});
</script>
HTML;

include __DIR__ . '/templates/layouts/main.php';
