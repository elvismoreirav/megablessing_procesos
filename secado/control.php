<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Control de Temperatura de Secado (Handsontable)
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

// Obtener registro de secado
$secado = $db->fetch("
    SELECT rs.*, 
           l.codigo as lote_codigo, l.id as lote_id,
           p.nombre as proveedor, p.codigo as proveedor_codigo,
           v.nombre as variedad,
           s.nombre as secadora, s.tipo as tipo_secadora,
           u.nombre as responsable
    FROM registros_secado rs
    JOIN lotes l ON rs.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    JOIN variedades v ON l.variedad_id = v.id
    LEFT JOIN secadoras s ON rs.secadora_id = s.id
    JOIN usuarios u ON rs.responsable_id = u.id
    WHERE rs.id = :id
", ['id' => $id]);

if (!$secado) {
    setFlash('error', 'Registro de secado no encontrado');
    redirect('/secado/index.php');
}

// Obtener controles de temperatura existentes
$controlesTemp = $db->fetchAll("
    SELECT * FROM secado_control_temperatura 
    WHERE secado_id = :id 
    ORDER BY fecha ASC, hora ASC
", ['id' => $id]);

// Calcular días transcurridos
$fechaInicio = new DateTime($secado['fecha_inicio']);
$hoy = new DateTime();
$diasTranscurridos = $fechaInicio->diff($hoy)->days + 1;

// Horas de control (cada 2 horas de 6:00 a 18:00)
$horasControl = ['06:00', '08:00', '10:00', '12:00', '14:00', '16:00', '18:00'];

// Preparar datos para Handsontable (7 días de control)
$diasControl = [];
for ($i = 0; $i < 7; $i++) {
    $fechaDia = (clone $fechaInicio)->modify('+' . $i . ' days');
    $fechaStr = $fechaDia->format('Y-m-d');
    
    $temperaturas = [];
    $humedad = null;
    
    foreach ($horasControl as $hora) {
        $control = array_filter($controlesTemp, fn($c) => $c['fecha'] == $fechaStr && substr($c['hora'], 0, 5) == $hora);
        $control = !empty($control) ? array_values($control)[0] : null;
        $temperaturas[] = $control['temperatura'] ?? null;
        if ($control && $control['humedad']) {
            $humedad = $control['humedad'];
        }
    }
    
    // Obtener observación del día
    $obsControl = array_filter($controlesTemp, fn($c) => $c['fecha'] == $fechaStr && !empty($c['observaciones']));
    $obsControl = !empty($obsControl) ? array_values($obsControl)[0] : null;
    
    $diasControl[] = [
        'dia' => $i + 1,
        'fecha' => $fechaStr,
        'fecha_display' => $fechaDia->format('d/m'),
        'temperaturas' => $temperaturas,
        'humedad' => $humedad,
        'observaciones' => $obsControl['observaciones'] ?? ''
    ];
}

$pageTitle = 'Control de Secado';
$pageSubtitle = 'Lote: ' . $secado['lote_codigo'];

// Estilos adicionales para Handsontable
$extraHead = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable@12.1.3/dist/handsontable.full.min.css">
<style>
    .handsontable td.htDimmed {
        background-color: #f9f8f4 !important;
    }
    .handsontable th {
        background-color: #1e4d39 !important;
        color: white !important;
        font-weight: 600 !important;
    }
    .handsontable td.temp-alta {
        background-color: #f8d7da !important;
    }
    .handsontable td.temp-normal {
        background-color: #d4edda !important;
    }
    .handsontable td.humedad-ok {
        background-color: #d4edda !important;
        font-weight: bold;
    }
</style>
HTML;

ob_start();
?>

<!-- Info del Lote -->
<div class="card mb-6">
    <div class="card-body">
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Lote</p>
                <p class="font-semibold text-primary"><?= htmlspecialchars($secado['lote_codigo']) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Proveedor</p>
                <p class="font-medium"><?= htmlspecialchars($secado['proveedor']) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Tipo Secado</p>
                <p class="font-medium"><?= htmlspecialchars($secado['tipo_secado']) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Secadora</p>
                <p class="font-medium"><?= $secado['secadora'] ?? 'N/A' ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Fecha Inicio</p>
                <p class="font-medium"><?= Helpers::formatDate($secado['fecha_inicio']) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Días</p>
                <p class="font-semibold text-lg"><?= min($diasTranscurridos, 99) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Estado</p>
                <?php if ($secado['fecha_fin']): ?>
                    <span class="badge badge-success">Finalizado</span>
                <?php else: ?>
                    <span class="badge badge-gold">En proceso</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Datos Iniciales -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">Datos del Proceso</h3>
    </div>
    <div class="card-body">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
            <div class="text-center p-4 bg-olive/10 rounded-lg">
                <p class="text-2xl font-bold text-primary"><?= number_format($secado['peso_inicial'], 2) ?></p>
                <p class="text-xs text-warmgray">Peso Inicial (Kg)</p>
            </div>
            <div class="text-center p-4 bg-olive/10 rounded-lg">
                <p class="text-2xl font-bold text-primary"><?= $secado['humedad_inicial'] ? number_format($secado['humedad_inicial'], 1) . '%' : '-' ?></p>
                <p class="text-xs text-warmgray">Humedad Inicial</p>
            </div>
            <div class="text-center p-4 bg-olive/10 rounded-lg">
                <p class="text-2xl font-bold <?= ($secado['humedad_final'] && $secado['humedad_final'] <= 7) ? 'text-green-600' : 'text-primary' ?>">
                    <?= $secado['humedad_final'] ? number_format($secado['humedad_final'], 1) . '%' : '-' ?>
                </p>
                <p class="text-xs text-warmgray">Humedad Final</p>
            </div>
            <div class="text-center p-4 bg-olive/10 rounded-lg">
                <p class="text-2xl font-bold text-primary"><?= $secado['peso_final'] ? number_format($secado['peso_final'], 2) : '-' ?></p>
                <p class="text-xs text-warmgray">Peso Final (Kg)</p>
            </div>
        </div>
    </div>
</div>

<!-- Control de Temperatura con Handsontable -->
<div class="card mb-6">
    <div class="card-header flex items-center justify-between">
        <h3 class="card-title">Control de Temperatura (cada 2 horas)</h3>
        <div class="flex gap-2">
            <button onclick="guardarControl()" class="btn btn-primary btn-sm" <?= $secado['fecha_fin'] ? 'disabled' : '' ?>>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Guardar Cambios
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div id="controlTable" class="overflow-x-auto"></div>
    </div>
    <div class="card-footer bg-olive/5">
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <div class="flex items-center gap-2">
                <span class="w-4 h-4 rounded" style="background-color: #d4edda"></span>
                <span>Temperatura normal (&lt;60°C)</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="w-4 h-4 rounded" style="background-color: #f8d7da"></span>
                <span>Temperatura alta (&gt;60°C)</span>
            </div>
        </div>
    </div>
</div>

<!-- Acciones de Secado -->
<?php if (!$secado['fecha_fin']): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">Finalizar Secado</h3>
    </div>
    <div class="card-body">
        <form id="finalizarForm" class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div class="form-group">
                <label class="form-label required">Fecha de Fin</label>
                <input type="date" name="fecha_fin" id="fecha_fin" class="form-control" 
                       value="<?= date('Y-m-d') ?>" min="<?= $secado['fecha_inicio'] ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Peso Final (Kg)</label>
                <input type="number" name="peso_final" id="peso_final" class="form-control" 
                       step="0.01" min="0">
            </div>
            <div class="form-group">
                <label class="form-label required">Humedad Final (%)</label>
                <input type="number" name="humedad_final" id="humedad_final" class="form-control" 
                       step="0.1" min="0" max="100" required>
                <p class="text-xs text-warmgray mt-1">Objetivo: 6-7%</p>
            </div>
            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <button type="button" onclick="finalizarSecado()" class="btn btn-gold w-full">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Finalizar
                </button>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="card mb-6 bg-green-50 border-green-200">
    <div class="card-body">
        <div class="flex items-start gap-4">
            <div class="p-3 bg-green-100 rounded-lg">
                <svg class="w-6 h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div>
                <h4 class="font-semibold text-green-800">Secado Finalizado</h4>
                <p class="text-sm text-green-700 mt-1">
                    Fecha: <?= Helpers::formatDate($secado['fecha_fin']) ?> |
                    Peso Final: <?= $secado['peso_final'] ? number_format($secado['peso_final'], 2) . ' Kg' : 'No registrado' ?> |
                    Humedad Final: <?= $secado['humedad_final'] ? number_format($secado['humedad_final'], 1) . '%' : 'No registrada' ?>
                </p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Observaciones Generales -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">Observaciones Generales</h3>
    </div>
    <div class="card-body">
        <textarea id="observaciones_generales" class="form-control" rows="3" 
                  placeholder="Observaciones adicionales del proceso..." <?= $secado['fecha_fin'] ? 'disabled' : '' ?>><?= htmlspecialchars($secado['observaciones'] ?? '') ?></textarea>
        <?php if (!$secado['fecha_fin']): ?>
        <button onclick="guardarObservaciones()" class="btn btn-outline btn-sm mt-3">Guardar Observaciones</button>
        <?php endif; ?>
    </div>
</div>

<!-- Botones de navegación -->
<div class="flex items-center gap-4">
    <a href="<?= APP_URL ?>/secado/index.php" class="btn btn-outline">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Volver al listado
    </a>
    <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $secado['lote_id'] ?>" class="btn btn-outline">Ver Lote</a>
    <?php if ($secado['fecha_fin']): ?>
    <a href="<?= APP_URL ?>/reportes/secado.php?id=<?= $id ?>" class="btn btn-primary">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        Ver Reporte
    </a>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/handsontable@12.1.3/dist/handsontable.full.min.js"></script>
<script>
const secadoId = <?= $id ?>;
const esFinalizado = <?= $secado['fecha_fin'] ? 'true' : 'false' ?>;
const horasControl = <?= json_encode($horasControl) ?>;

// Datos iniciales
const datosControl = <?= json_encode($diasControl) ?>;

// Preparar datos para tabla
const tableData = datosControl.map(d => {
    return [
        d.dia,
        d.fecha_display,
        ...d.temperaturas,
        d.humedad,
        d.observaciones
    ];
});

// Configurar Handsontable
const container = document.getElementById('controlTable');
const hot = new Handsontable(container, {
    data: tableData,
    colHeaders: ['Día', 'Fecha', '06:00', '08:00', '10:00', '12:00', '14:00', '16:00', '18:00', 'Humedad %', 'Observaciones'],
    columns: [
        { data: 0, type: 'numeric', readOnly: true, className: 'htCenter htDimmed', width: 50 },
        { data: 1, type: 'text', readOnly: true, className: 'htCenter htDimmed', width: 70 },
        { data: 2, type: 'numeric', numericFormat: { pattern: '0.0' }, width: 60 },
        { data: 3, type: 'numeric', numericFormat: { pattern: '0.0' }, width: 60 },
        { data: 4, type: 'numeric', numericFormat: { pattern: '0.0' }, width: 60 },
        { data: 5, type: 'numeric', numericFormat: { pattern: '0.0' }, width: 60 },
        { data: 6, type: 'numeric', numericFormat: { pattern: '0.0' }, width: 60 },
        { data: 7, type: 'numeric', numericFormat: { pattern: '0.0' }, width: 60 },
        { data: 8, type: 'numeric', numericFormat: { pattern: '0.0' }, width: 60 },
        { data: 9, type: 'numeric', numericFormat: { pattern: '0.0' }, width: 80 },
        { data: 10, type: 'text', width: 180 }
    ],
    rowHeaders: false,
    height: 'auto',
    licenseKey: 'non-commercial-and-evaluation',
    readOnly: esFinalizado,
    cells: function(row, col) {
        const cellProperties = {};
        const data = this.instance.getData();
        
        // Colorear celdas de temperatura (columnas 2-8)
        if (col >= 2 && col <= 8) {
            const val = data[row][col];
            if (val !== null && val !== '') {
                if (val > 60) {
                    cellProperties.className = 'htCenter temp-alta';
                } else {
                    cellProperties.className = 'htCenter temp-normal';
                }
            }
        }
        
        // Colorear humedad si está en rango objetivo
        if (col === 9) {
            const val = data[row][col];
            if (val !== null && val !== '' && val <= 7) {
                cellProperties.className = 'htCenter humedad-ok';
            }
        }
        
        return cellProperties;
    },
    afterChange: function(changes, source) {
        if (source === 'loadData') return;
        this.render();
    }
});

// Guardar control de temperatura
async function guardarControl() {
    const data = hot.getData();
    const controles = [];
    
    data.forEach((row, idx) => {
        const fecha = datosControl[idx].fecha;
        
        // Crear registro para cada hora con temperatura
        horasControl.forEach((hora, hIdx) => {
            const temp = row[2 + hIdx];
            if (temp !== null && temp !== '') {
                controles.push({
                    fecha: fecha,
                    hora: hora,
                    temperatura: temp,
                    humedad: row[9],
                    observaciones: row[10]
                });
            }
        });
        
        // Si no hay temperaturas pero hay humedad u observaciones
        if (row[9] || row[10]) {
            const hasTemp = horasControl.some((_, hIdx) => row[2 + hIdx] !== null && row[2 + hIdx] !== '');
            if (!hasTemp) {
                controles.push({
                    fecha: fecha,
                    hora: '12:00',
                    temperatura: null,
                    humedad: row[9],
                    observaciones: row[10]
                });
            }
        }
    });
    
    try {
        const response = await App.post('<?= APP_URL ?>/api/secado/guardar-control.php', {
            secado_id: secadoId,
            controles: controles
        });
        
        if (response.success) {
            App.toast('Control guardado correctamente', 'success');
        } else {
            App.toast(response.error || 'Error al guardar', 'error');
        }
    } catch (error) {
        App.toast('Error de conexión', 'error');
    }
}

// Finalizar secado
async function finalizarSecado() {
    const fechaFin = document.getElementById('fecha_fin').value;
    const pesoFinal = document.getElementById('peso_final').value;
    const humedadFinal = document.getElementById('humedad_final').value;
    
    if (!fechaFin) {
        App.toast('Ingrese la fecha de fin', 'warning');
        return;
    }
    
    if (!humedadFinal) {
        App.toast('Ingrese la humedad final', 'warning');
        return;
    }
    
    if (parseFloat(humedadFinal) > 7) {
        if (!confirm('La humedad final es mayor a 7%. ¿Desea continuar?')) {
            return;
        }
    }
    
    if (!confirm('¿Está seguro de finalizar el secado? Esta acción no se puede deshacer.')) {
        return;
    }
    
    // Primero guardar el control actual
    await guardarControl();
    
    try {
        const response = await App.post('<?= APP_URL ?>/api/secado/finalizar.php', {
            secado_id: secadoId,
            fecha_fin: fechaFin,
            peso_final: pesoFinal || null,
            humedad_final: humedadFinal
        });
        
        if (response.success) {
            App.toast('Secado finalizado', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            App.toast(response.error || 'Error al finalizar', 'error');
        }
    } catch (error) {
        App.toast('Error de conexión', 'error');
    }
}

// Guardar observaciones
async function guardarObservaciones() {
    const obs = document.getElementById('observaciones_generales').value;
    
    try {
        const response = await App.post('<?= APP_URL ?>/api/secado/actualizar.php', {
            secado_id: secadoId,
            observaciones: obs
        });
        
        if (response.success) {
            App.toast('Observaciones guardadas', 'success');
        } else {
            App.toast(response.error || 'Error al guardar', 'error');
        }
    } catch (error) {
        App.toast('Error de conexión', 'error');
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
