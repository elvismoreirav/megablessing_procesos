<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Detalle de Fermentación
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

$id = $_GET['id'] ?? null;

if (!$id) {
    setFlash('error', 'ID de fermentación no especificado');
    redirect('/fermentacion/index.php');
}

// Compatibilidad de esquema (columnas/tablas varían según instalación)
$colsFermentacion = array_column($db->fetchAll("SHOW COLUMNS FROM registros_fermentacion"), 'Field');
$hasFerCol = static fn(string $name): bool => in_array($name, $colsFermentacion, true);

$fechaFinExpr = $hasFerCol('fecha_fin')
    ? 'rf.fecha_fin'
    : ($hasFerCol('fecha_salida') ? 'rf.fecha_salida' : 'NULL');
$pesoInicialExpr = $hasFerCol('peso_inicial')
    ? 'rf.peso_inicial'
    : ($hasFerCol('peso_lote_kg') ? 'rf.peso_lote_kg' : 'NULL');
$pesoFinalExpr = $hasFerCol('peso_final') ? 'rf.peso_final' : 'NULL';
$phInicialExpr = $hasFerCol('ph_inicial')
    ? 'rf.ph_inicial'
    : ($hasFerCol('ph_pulpa_inicial') ? 'rf.ph_pulpa_inicial' : 'NULL');
$observacionesExpr = $hasFerCol('observaciones')
    ? 'rf.observaciones'
    : ($hasFerCol('observaciones_generales') ? 'rf.observaciones_generales' : 'NULL');
$usuarioFerCol = $hasFerCol('operador_id')
    ? 'operador_id'
    : ($hasFerCol('responsable_id') ? 'responsable_id' : null);
$operadorExpr = $usuarioFerCol ? 'u.nombre' : 'NULL';
$joinUsuario = $usuarioFerCol ? "LEFT JOIN usuarios u ON rf.{$usuarioFerCol} = u.id" : '';

$tablaCajones = $db->fetch("SHOW TABLES LIKE 'cajones_fermentacion'")
    ? 'cajones_fermentacion'
    : ($db->fetch("SHOW TABLES LIKE 'cajones'") ? 'cajones' : null);
$joinCajon = '';
$cajonCodigoExpr = 'NULL';
$cajonCapacidadExpr = 'NULL';
if ($tablaCajones) {
    $colsCajones = array_column($db->fetchAll("SHOW COLUMNS FROM {$tablaCajones}"), 'Field');
    $hasCajCol = static fn(string $name): bool => in_array($name, $colsCajones, true);

    if ($hasCajCol('codigo')) {
        $cajonCodigoExpr = 'c.codigo';
    } elseif ($hasCajCol('nombre')) {
        $cajonCodigoExpr = 'c.nombre';
    } elseif ($hasCajCol('numero')) {
        $cajonCodigoExpr = 'c.numero';
    }

    if ($hasCajCol('capacidad_kg')) {
        $cajonCapacidadExpr = 'c.capacidad_kg';
    }

    $joinCajon = "LEFT JOIN {$tablaCajones} c ON rf.cajon_id = c.id";
}

// Obtener datos de fermentación
$fermentacion = $db->fetch("
    SELECT rf.*,
           {$fechaFinExpr} as fecha_fin,
           {$pesoInicialExpr} as peso_inicial,
           {$pesoFinalExpr} as peso_final,
           {$phInicialExpr} as ph_inicial,
           {$observacionesExpr} as observaciones,
           {$operadorExpr} as operador,
           {$cajonCodigoExpr} as cajon_codigo,
           {$cajonCapacidadExpr} as cajon_capacidad,
           l.codigo as lote_codigo,
           p.nombre as proveedor,
           p.codigo as proveedor_codigo,
           v.nombre as variedad,
           ef.nombre as estado_fermentacion
    FROM registros_fermentacion rf
    JOIN lotes l ON rf.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    JOIN variedades v ON l.variedad_id = v.id
    {$joinUsuario}
    {$joinCajon}
    LEFT JOIN estados_fermentacion ef ON l.estado_fermentacion_id = ef.id
    WHERE rf.id = :id
", ['id' => $id]);

if (!$fermentacion) {
    setFlash('error', 'Registro de fermentación no encontrado');
    redirect('/fermentacion/index.php');
}

// Obtener control diario (compatibilidad de columnas)
$colsControl = array_column($db->fetchAll("SHOW COLUMNS FROM fermentacion_control_diario"), 'Field');
$hasCtrlCol = static fn(string $name): bool => in_array($name, $colsControl, true);

$fkControlCol = $hasCtrlCol('fermentacion_id') ? 'fermentacion_id' : 'registro_fermentacion_id';
$fechaCtrlExpr = $hasCtrlCol('fecha') ? 'fecha' : 'NULL';
$tempAmExpr = $hasCtrlCol('temperatura_am')
    ? 'temperatura_am'
    : ($hasCtrlCol('temp_am') ? 'temp_am' : ($hasCtrlCol('temp_masa') ? 'temp_masa' : 'NULL'));
$tempPmExpr = $hasCtrlCol('temperatura_pm')
    ? 'temperatura_pm'
    : ($hasCtrlCol('temp_pm') ? 'temp_pm' : ($hasCtrlCol('temp_ambiente') ? 'temp_ambiente' : 'NULL'));
$phAmExpr = $hasCtrlCol('ph_am') ? 'ph_am' : ($hasCtrlCol('ph_pulpa') ? 'ph_pulpa' : 'NULL');
$phPmExpr = $hasCtrlCol('ph_pm') ? 'ph_pm' : ($hasCtrlCol('ph_cotiledon') ? 'ph_cotiledon' : 'NULL');
$horaCtrlExpr = $hasCtrlCol('hora_volteo') ? 'hora_volteo' : ($hasCtrlCol('hora') ? 'hora' : 'NULL');
$obsCtrlExpr = $hasCtrlCol('observaciones') ? 'observaciones' : 'NULL';

$controlDiario = $db->fetchAll("
    SELECT dia,
           {$fechaCtrlExpr} as fecha,
           {$tempAmExpr} as temp_am,
           {$tempPmExpr} as temp_pm,
           {$phAmExpr} as ph_am,
           {$phPmExpr} as ph_pm,
           volteo,
           {$horaCtrlExpr} as hora_volteo,
           {$obsCtrlExpr} as observaciones
    FROM fermentacion_control_diario
    WHERE {$fkControlCol} = :id
    ORDER BY dia ASC
", ['id' => $id]);

// Calcular estadísticas
$stats = [
    'dias_registrados' => count($controlDiario),
    'volteos_realizados' => $fermentacion['total_volteos'] ?? 0,
    'temp_promedio' => 0,
    'temp_max' => 0,
    'ph_promedio' => 0
];

if (!empty($controlDiario)) {
    $temps = [];
    $phs = [];
    foreach ($controlDiario as $dia) {
        if ($dia['temp_am']) $temps[] = $dia['temp_am'];
        if ($dia['temp_pm']) $temps[] = $dia['temp_pm'];
        if ($dia['ph_am']) $phs[] = $dia['ph_am'];
        if ($dia['ph_pm']) $phs[] = $dia['ph_pm'];
    }
    if (!empty($temps)) {
        $stats['temp_promedio'] = array_sum($temps) / count($temps);
        $stats['temp_max'] = max($temps);
    }
    if (!empty($phs)) {
        $stats['ph_promedio'] = array_sum($phs) / count($phs);
    }
}

// Estado
$finalizado = !empty($fermentacion['fecha_fin']);

$pageTitle = 'Fermentación: ' . $fermentacion['lote_codigo'];
$pageSubtitle = 'Detalle del proceso de fermentación';

ob_start();
?>

<!-- Header con estado -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div class="flex items-center gap-4">
        <a href="<?= APP_URL ?>/fermentacion/index.php" class="p-2 text-warmgray hover:text-primary hover:bg-olive/20 rounded-lg transition-colors">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
        </a>
        <div>
            <h2 class="text-2xl font-bold text-primary"><?= htmlspecialchars($fermentacion['lote_codigo']) ?></h2>
            <p class="text-warmgray"><?= htmlspecialchars($fermentacion['proveedor']) ?></p>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <?php if ($finalizado): ?>
            <span class="badge badge-success">Finalizado</span>
        <?php else: ?>
            <span class="badge badge-warning">En Proceso</span>
            <a href="<?= APP_URL ?>/fermentacion/control.php?id=<?= $id ?>" class="btn btn-primary">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
                Continuar Control
            </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/reportes/fermentacion.php?id=<?= $id ?>" class="btn btn-outline">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Reporte PDF
        </a>
    </div>
</div>

<!-- Estadísticas -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <div class="card p-4 text-center">
        <p class="text-3xl font-bold text-primary"><?= $stats['dias_registrados'] ?></p>
        <p class="text-xs text-warmgray">Días Registrados</p>
    </div>
    <div class="card p-4 text-center">
        <p class="text-3xl font-bold text-green-600"><?= $stats['volteos_realizados'] ?></p>
        <p class="text-xs text-warmgray">Volteos Realizados</p>
    </div>
    <div class="card p-4 text-center">
        <p class="text-3xl font-bold <?= $stats['temp_promedio'] >= 45 ? 'text-green-600' : 'text-gold' ?>"><?= number_format($stats['temp_promedio'], 1) ?>°C</p>
        <p class="text-xs text-warmgray">Temp. Promedio</p>
    </div>
    <div class="card p-4 text-center">
        <p class="text-3xl font-bold <?= $stats['temp_max'] > 50 ? 'text-red-600' : 'text-green-600' ?>"><?= number_format($stats['temp_max'], 1) ?>°C</p>
        <p class="text-xs text-warmgray">Temp. Máxima</p>
    </div>
    <div class="card p-4 text-center">
        <p class="text-3xl font-bold text-warmgray"><?= number_format($stats['ph_promedio'], 1) ?></p>
        <p class="text-xs text-warmgray">pH Promedio</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Información del Proceso -->
    <div class="lg:col-span-1">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Información del Proceso</h3>
            </div>
            <div class="card-body space-y-4">
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Lote</span>
                    <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $fermentacion['lote_id'] ?>" class="font-medium text-primary hover:underline">
                        <?= htmlspecialchars($fermentacion['lote_codigo']) ?>
                    </a>
                </div>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Proveedor</span>
                    <span class="font-medium">
                        <span class="text-primary font-bold"><?= htmlspecialchars($fermentacion['proveedor_codigo']) ?></span>
                        - <?= htmlspecialchars($fermentacion['proveedor']) ?>
                    </span>
                </div>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Variedad</span>
                    <span class="font-medium"><?= htmlspecialchars($fermentacion['variedad']) ?></span>
                </div>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Cajón</span>
                    <span class="font-medium"><?= $fermentacion['cajon_codigo'] ? htmlspecialchars($fermentacion['cajon_codigo']) : 'No asignado' ?></span>
                </div>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Operador</span>
                    <span class="font-medium"><?= htmlspecialchars($fermentacion['operador']) ?></span>
                </div>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Estado</span>
                    <span class="badge <?= $finalizado ? 'badge-success' : 'badge-warning' ?>">
                        <?= $finalizado ? 'Finalizado' : 'En Proceso' ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Fechas y Pesos -->
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Fechas y Mediciones</h3>
            </div>
            <div class="card-body space-y-4">
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Fecha Inicio</span>
                    <span class="font-medium"><?= Helpers::formatDate($fermentacion['fecha_inicio']) ?></span>
                </div>
                <?php if ($finalizado): ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Fecha Fin</span>
                    <span class="font-medium"><?= Helpers::formatDate($fermentacion['fecha_fin']) ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Peso Inicial</span>
                    <span class="font-medium"><?= $fermentacion['peso_inicial'] ? number_format($fermentacion['peso_inicial'], 2) . ' kg' : 'N/R' ?></span>
                </div>
                <?php if ($finalizado && $fermentacion['peso_final']): ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Peso Final</span>
                    <span class="font-medium"><?= number_format($fermentacion['peso_final'], 2) ?> kg</span>
                </div>
                <?php 
                    $perdida = (($fermentacion['peso_inicial'] - $fermentacion['peso_final']) / $fermentacion['peso_inicial']) * 100;
                ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Pérdida de Peso</span>
                    <span class="font-medium text-red-600"><?= number_format($perdida, 1) ?>%</span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Humedad Inicial</span>
                    <span class="font-medium"><?= $fermentacion['humedad_inicial'] ? number_format($fermentacion['humedad_inicial'], 1) . '%' : 'N/R' ?></span>
                </div>
                <?php if ($finalizado && $fermentacion['humedad_final']): ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Humedad Final</span>
                    <span class="font-medium"><?= number_format($fermentacion['humedad_final'], 1) ?>%</span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Temp. Inicial</span>
                    <span class="font-medium"><?= $fermentacion['temperatura_inicial'] ? number_format($fermentacion['temperatura_inicial'], 1) . '°C' : 'N/R' ?></span>
                </div>
                <div class="flex justify-between items-center py-2">
                    <span class="text-warmgray">pH Inicial</span>
                    <span class="font-medium"><?= $fermentacion['ph_inicial'] ? number_format($fermentacion['ph_inicial'], 1) : 'N/R' ?></span>
                </div>
            </div>
        </div>
        
        <!-- Observaciones -->
        <?php if ($fermentacion['observaciones']): ?>
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Observaciones</h3>
            </div>
            <div class="card-body">
                <p class="text-warmgray whitespace-pre-wrap"><?= htmlspecialchars($fermentacion['observaciones']) ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Control Diario -->
    <div class="lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Control Diario de Fermentación</h3>
            </div>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="text-center">Día</th>
                            <th class="text-center">Fecha</th>
                            <th class="text-center">Temp AM</th>
                            <th class="text-center">Temp PM</th>
                            <th class="text-center">pH AM</th>
                            <th class="text-center">pH PM</th>
                            <th class="text-center">Volteo</th>
                            <th class="text-center">Hora</th>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($controlDiario)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-8 text-warmgray">
                                    No hay registros de control diario
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($controlDiario as $dia): ?>
                            <tr>
                                <td class="text-center font-medium"><?= $dia['dia'] ?></td>
                                <td class="text-center"><?= $dia['fecha'] ? Helpers::formatDate($dia['fecha']) : '-' ?></td>
                                <td class="text-center">
                                    <?php if ($dia['temp_am']): ?>
                                        <span class="px-2 py-1 rounded <?= $dia['temp_am'] > 50 ? 'bg-red-100 text-red-600' : ($dia['temp_am'] >= 45 ? 'bg-green-100 text-green-600' : 'bg-gray-100') ?>">
                                            <?= number_format($dia['temp_am'], 1) ?>°C
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($dia['temp_pm']): ?>
                                        <span class="px-2 py-1 rounded <?= $dia['temp_pm'] > 50 ? 'bg-red-100 text-red-600' : ($dia['temp_pm'] >= 45 ? 'bg-green-100 text-green-600' : 'bg-gray-100') ?>">
                                            <?= number_format($dia['temp_pm'], 1) ?>°C
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= $dia['ph_am'] ? number_format($dia['ph_am'], 1) : '-' ?></td>
                                <td class="text-center"><?= $dia['ph_pm'] ? number_format($dia['ph_pm'], 1) : '-' ?></td>
                                <td class="text-center">
                                    <?php if ($dia['volteo']): ?>
                                        <span class="badge badge-success">Sí</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= $dia['hora_volteo'] ? substr($dia['hora_volteo'], 0, 5) : '-' ?></td>
                                <td class="text-sm text-warmgray max-w-xs truncate"><?= htmlspecialchars($dia['observaciones'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Gráfico de Temperatura -->
        <?php if (!empty($controlDiario)): ?>
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Evolución de Temperatura</h3>
            </div>
            <div class="card-body">
                <canvas id="tempChart" height="200"></canvas>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($controlDiario)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('tempChart').getContext('2d');
    
    const data = <?= json_encode($controlDiario) ?>;
    const labels = data.map(d => 'Día ' + d.dia);
    const tempAM = data.map(d => d.temp_am);
    const tempPM = data.map(d => d.temp_pm);
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Temperatura AM',
                    data: tempAM,
                    borderColor: '#1e4d39',
                    backgroundColor: 'rgba(30, 77, 57, 0.1)',
                    tension: 0.3,
                    fill: true
                },
                {
                    label: 'Temperatura PM',
                    data: tempPM,
                    borderColor: '#D6C29A',
                    backgroundColor: 'rgba(214, 194, 154, 0.1)',
                    tension: 0.3,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top'
                },
                annotation: {
                    annotations: {
                        line1: {
                            type: 'line',
                            yMin: 50,
                            yMax: 50,
                            borderColor: 'rgb(239, 68, 68)',
                            borderWidth: 2,
                            borderDash: [5, 5],
                            label: {
                                content: 'Máx. 50°C',
                                enabled: true
                            }
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    min: 30,
                    max: 60,
                    title: {
                        display: true,
                        text: 'Temperatura (°C)'
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
