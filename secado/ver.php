<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Detalle de Secado
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

$id = $_GET['id'] ?? null;

if (!$id) {
    setFlash('error', 'ID de secado no especificado');
    redirect('/secado/index.php');
}

// Obtener datos de secado
$secado = $db->fetch("
    SELECT rs.*, 
           l.codigo as lote_codigo,
           p.nombre as proveedor, 
           p.codigo as proveedor_codigo,
           v.nombre as variedad,
           u.nombre as operador,
           s.codigo as secadora_codigo,
           s.tipo as secadora_tipo,
           s.capacidad_kg as secadora_capacidad
    FROM registros_secado rs
    JOIN lotes l ON rs.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    JOIN variedades v ON l.variedad_id = v.id
    JOIN usuarios u ON rs.operador_id = u.id
    LEFT JOIN secadoras s ON rs.secadora_id = s.id
    WHERE rs.id = :id
", ['id' => $id]);

if (!$secado) {
    setFlash('error', 'Registro de secado no encontrado');
    redirect('/secado/index.php');
}

// Obtener control de temperatura
$controlTemp = $db->fetchAll("
    SELECT * FROM secado_control_temperatura 
    WHERE secado_id = :id 
    ORDER BY fecha ASC, hora ASC
", ['id' => $id]);

// Agrupar por día para la tabla
$controlPorDia = [];
foreach ($controlTemp as $reg) {
    $fecha = $reg['fecha'];
    if (!isset($controlPorDia[$fecha])) {
        $controlPorDia[$fecha] = [
            'fecha' => $fecha,
            'temperaturas' => [],
            'humedad' => null,
            'observaciones' => ''
        ];
    }
    $controlPorDia[$fecha]['temperaturas'][$reg['hora']] = $reg['temperatura'];
    if ($reg['humedad']) {
        $controlPorDia[$fecha]['humedad'] = $reg['humedad'];
    }
    if ($reg['observaciones']) {
        $controlPorDia[$fecha]['observaciones'] = $reg['observaciones'];
    }
}

// Calcular estadísticas
$stats = [
    'dias_registrados' => count($controlPorDia),
    'temp_promedio' => 0,
    'temp_max' => 0,
    'humedad_min' => null
];

if (!empty($controlTemp)) {
    $temps = array_filter(array_column($controlTemp, 'temperatura'));
    $humedades = array_filter(array_column($controlTemp, 'humedad'));
    
    if (!empty($temps)) {
        $stats['temp_promedio'] = array_sum($temps) / count($temps);
        $stats['temp_max'] = max($temps);
    }
    if (!empty($humedades)) {
        $stats['humedad_min'] = min($humedades);
    }
}

// Estado
$finalizado = !empty($secado['fecha_fin']);

// Horas de control
$horasControl = ['06:00', '08:00', '10:00', '12:00', '14:00', '16:00', '18:00'];

$pageTitle = 'Secado: ' . $secado['lote_codigo'];
$pageSubtitle = 'Detalle del proceso de secado';

ob_start();
?>

<!-- Header con estado -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div class="flex items-center gap-4">
        <a href="<?= APP_URL ?>/secado/index.php" class="p-2 text-warmgray hover:text-primary hover:bg-olive/20 rounded-lg transition-colors">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
        </a>
        <div>
            <h2 class="text-2xl font-bold text-primary"><?= htmlspecialchars($secado['lote_codigo']) ?></h2>
            <p class="text-warmgray"><?= htmlspecialchars($secado['proveedor']) ?></p>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <?php if ($finalizado): ?>
            <span class="badge badge-success">Finalizado</span>
        <?php else: ?>
            <span class="badge badge-warning">En Proceso</span>
            <a href="<?= APP_URL ?>/secado/control.php?id=<?= $id ?>" class="btn btn-primary">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
                Continuar Control
            </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/reportes/secado.php?id=<?= $id ?>" class="btn btn-outline">
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
        <p class="text-3xl font-bold text-warmgray"><?= number_format($stats['temp_promedio'], 1) ?>°C</p>
        <p class="text-xs text-warmgray">Temp. Promedio</p>
    </div>
    <div class="card p-4 text-center">
        <p class="text-3xl font-bold <?= $stats['temp_max'] > 60 ? 'text-red-600' : 'text-green-600' ?>"><?= number_format($stats['temp_max'], 1) ?>°C</p>
        <p class="text-xs text-warmgray">Temp. Máxima</p>
    </div>
    <div class="card p-4 text-center">
        <p class="text-3xl font-bold <?= ($stats['humedad_min'] !== null && $stats['humedad_min'] <= 7) ? 'text-green-600' : 'text-warmgray' ?>">
            <?= $stats['humedad_min'] !== null ? number_format($stats['humedad_min'], 1) . '%' : 'N/R' ?>
        </p>
        <p class="text-xs text-warmgray">Humedad Mínima</p>
    </div>
    <div class="card p-4 text-center">
        <span class="inline-flex items-center gap-1 text-sm">
            <?php
            $tipoClass = match($secado['tipo_secado']) {
                'SOLAR' => 'text-yellow-600',
                'MECANICO' => 'text-blue-600',
                'MIXTO' => 'text-purple-600',
                default => 'text-warmgray'
            };
            ?>
            <span class="<?= $tipoClass ?> text-2xl font-bold"><?= $secado['tipo_secado'] ?></span>
        </span>
        <p class="text-xs text-warmgray">Tipo de Secado</p>
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
                    <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $secado['lote_id'] ?>" class="font-medium text-primary hover:underline">
                        <?= htmlspecialchars($secado['lote_codigo']) ?>
                    </a>
                </div>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Proveedor</span>
                    <span class="font-medium">
                        <span class="text-primary font-bold"><?= htmlspecialchars($secado['proveedor_codigo']) ?></span>
                        - <?= htmlspecialchars($secado['proveedor']) ?>
                    </span>
                </div>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Variedad</span>
                    <span class="font-medium"><?= htmlspecialchars($secado['variedad']) ?></span>
                </div>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Tipo de Secado</span>
                    <span class="badge <?= match($secado['tipo_secado']) {
                        'SOLAR' => 'badge-gold',
                        'MECANICO' => 'badge-primary',
                        'MIXTO' => 'badge-secondary',
                        default => 'badge-secondary'
                    } ?>"><?= $secado['tipo_secado'] ?></span>
                </div>
                <?php if ($secado['secadora_codigo']): ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Secadora</span>
                    <span class="font-medium"><?= htmlspecialchars($secado['secadora_codigo']) ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Operador</span>
                    <span class="font-medium"><?= htmlspecialchars($secado['operador']) ?></span>
                </div>
                <div class="flex justify-between items-center py-2">
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
                    <span class="font-medium"><?= Helpers::formatDate($secado['fecha_inicio']) ?></span>
                </div>
                <?php if ($finalizado): ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Fecha Fin</span>
                    <span class="font-medium"><?= Helpers::formatDate($secado['fecha_fin']) ?></span>
                </div>
                <?php
                    $inicio = new DateTime($secado['fecha_inicio']);
                    $fin = new DateTime($secado['fecha_fin']);
                    $duracion = $inicio->diff($fin)->days;
                ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Duración</span>
                    <span class="font-medium"><?= $duracion ?> días</span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Peso Inicial</span>
                    <span class="font-medium"><?= $secado['peso_inicial'] ? number_format($secado['peso_inicial'], 2) . ' kg' : 'N/R' ?></span>
                </div>
                <?php if ($finalizado && $secado['peso_final']): ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Peso Final</span>
                    <span class="font-medium"><?= number_format($secado['peso_final'], 2) ?> kg</span>
                </div>
                <?php 
                    $perdida = (($secado['peso_inicial'] - $secado['peso_final']) / $secado['peso_inicial']) * 100;
                ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Pérdida de Peso</span>
                    <span class="font-medium text-red-600"><?= number_format($perdida, 1) ?>%</span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Humedad Inicial</span>
                    <span class="font-medium"><?= $secado['humedad_inicial'] ? number_format($secado['humedad_inicial'], 1) . '%' : 'N/R' ?></span>
                </div>
                <?php if ($finalizado && $secado['humedad_final']): ?>
                <div class="flex justify-between items-center py-2">
                    <span class="text-warmgray">Humedad Final</span>
                    <span class="font-medium <?= $secado['humedad_final'] <= 7 ? 'text-green-600' : 'text-gold' ?>">
                        <?= number_format($secado['humedad_final'], 1) ?>%
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Observaciones -->
        <?php if ($secado['observaciones']): ?>
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Observaciones</h3>
            </div>
            <div class="card-body">
                <p class="text-warmgray whitespace-pre-wrap"><?= htmlspecialchars($secado['observaciones']) ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Control de Temperatura -->
    <div class="lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Control de Temperatura por Día</h3>
            </div>
            <div class="table-container">
                <table class="table text-sm">
                    <thead>
                        <tr>
                            <th>Día</th>
                            <th>Fecha</th>
                            <?php foreach ($horasControl as $hora): ?>
                                <th class="text-center"><?= $hora ?></th>
                            <?php endforeach; ?>
                            <th class="text-center">Humedad</th>
                            <th>Obs.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($controlPorDia)): ?>
                            <tr>
                                <td colspan="<?= 3 + count($horasControl) ?>" class="text-center py-8 text-warmgray">
                                    No hay registros de control de temperatura
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $diaNum = 1; ?>
                            <?php foreach ($controlPorDia as $fecha => $dia): ?>
                            <tr>
                                <td class="text-center font-medium"><?= $diaNum++ ?></td>
                                <td class="whitespace-nowrap"><?= Helpers::formatDate($fecha) ?></td>
                                <?php foreach ($horasControl as $hora): ?>
                                    <td class="text-center">
                                        <?php 
                                        $horaKey = substr($hora, 0, 5) . ':00';
                                        $temp = $dia['temperaturas'][$horaKey] ?? null;
                                        ?>
                                        <?php if ($temp): ?>
                                            <span class="px-2 py-1 rounded text-xs <?= $temp > 60 ? 'bg-red-100 text-red-600' : 'bg-green-100 text-green-600' ?>">
                                                <?= number_format($temp, 1) ?>°
                                            </span>
                                        <?php else: ?>
                                            <span class="text-gray-300">-</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-center">
                                    <?php if ($dia['humedad']): ?>
                                        <span class="font-medium <?= $dia['humedad'] <= 7 ? 'text-green-600' : '' ?>">
                                            <?= number_format($dia['humedad'], 1) ?>%
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-300">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-xs text-warmgray max-w-[100px] truncate" title="<?= htmlspecialchars($dia['observaciones']) ?>">
                                    <?= htmlspecialchars($dia['observaciones']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Gráfico de Temperatura -->
        <?php if (!empty($controlTemp)): ?>
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Evolución de Temperatura</h3>
            </div>
            <div class="card-body">
                <canvas id="tempChart" height="200"></canvas>
            </div>
        </div>
        
        <!-- Gráfico de Humedad -->
        <?php 
        $humedadData = array_filter($controlTemp, fn($r) => $r['humedad'] !== null);
        if (!empty($humedadData)): 
        ?>
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Evolución de Humedad</h3>
            </div>
            <div class="card-body">
                <canvas id="humedadChart" height="150"></canvas>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($controlTemp)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Gráfico de temperatura
    const tempCtx = document.getElementById('tempChart').getContext('2d');
    
    const tempData = <?= json_encode($controlTemp) ?>;
    const labels = tempData.map(d => d.fecha.substr(5) + ' ' + d.hora.substr(0, 5));
    const temps = tempData.map(d => d.temperatura);
    
    new Chart(tempCtx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Temperatura (°C)',
                data: temps,
                borderColor: '#1e4d39',
                backgroundColor: 'rgba(30, 77, 57, 0.1)',
                tension: 0.3,
                fill: true
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    min: 20,
                    max: 70,
                    title: { display: true, text: 'Temperatura (°C)' }
                },
                x: {
                    ticks: { maxRotation: 45, minRotation: 45 }
                }
            }
        }
    });
    
    // Gráfico de humedad si existe
    const humedadCanvas = document.getElementById('humedadChart');
    if (humedadCanvas) {
        const humedadCtx = humedadCanvas.getContext('2d');
        const humedadData = tempData.filter(d => d.humedad !== null);
        
        new Chart(humedadCtx, {
            type: 'line',
            data: {
                labels: humedadData.map(d => d.fecha.substr(5)),
                datasets: [{
                    label: 'Humedad (%)',
                    data: humedadData.map(d => d.humedad),
                    borderColor: '#D6C29A',
                    backgroundColor: 'rgba(214, 194, 154, 0.2)',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    annotation: {
                        annotations: {
                            line1: {
                                type: 'line',
                                yMin: 7,
                                yMax: 7,
                                borderColor: 'rgb(34, 197, 94)',
                                borderWidth: 2,
                                borderDash: [5, 5]
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 50,
                        title: { display: true, text: 'Humedad (%)' }
                    }
                }
            }
        });
    }
});
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
