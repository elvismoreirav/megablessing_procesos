<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Control Diario de Fermentación (Handsontable)
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

// Compatibilidad de esquema (instalaciones con columnas distintas)
Helpers::ensureFermentacionFinalColumns();
$colsFermentacion = array_column($db->fetchAll("SHOW COLUMNS FROM registros_fermentacion"), 'Field');
$hasFerCol = static fn(string $name): bool => in_array($name, $colsFermentacion, true);
Helpers::ensureFermentacionPesoUnitColumn();
Helpers::ensureFermentacionControlMedicionesColumns();
$colsFermentacion = array_column($db->fetchAll("SHOW COLUMNS FROM registros_fermentacion"), 'Field');
$hasFerCol = static fn(string $name): bool => in_array($name, $colsFermentacion, true);

$fechaFinExpr = $hasFerCol('fecha_fin')
    ? 'rf.fecha_fin'
    : ($hasFerCol('fecha_salida') ? 'rf.fecha_salida' : 'NULL');
$pesoInicialExpr = $hasFerCol('peso_inicial')
    ? 'rf.peso_inicial'
    : ($hasFerCol('peso_lote_kg') ? 'rf.peso_lote_kg' : 'NULL');
$unidadPesoExpr = $hasFerCol('unidad_peso') ? 'rf.unidad_peso' : 'NULL';
$phInicialExpr = $hasFerCol('ph_inicial')
    ? 'rf.ph_inicial'
    : ($hasFerCol('ph_pulpa_inicial') ? 'rf.ph_pulpa_inicial' : 'NULL');
$observacionesExpr = $hasFerCol('observaciones')
    ? 'rf.observaciones'
    : ($hasFerCol('observaciones_generales') ? 'rf.observaciones_generales' : 'NULL');
$totalVolteosExpr = $hasFerCol('total_volteos') ? 'rf.total_volteos' : 'NULL';

$colsControl = array_column($db->fetchAll("SHOW COLUMNS FROM fermentacion_control_diario"), 'Field');
$hasCtrlCol = static fn(string $name): bool => in_array($name, $colsControl, true);
$fkControlCol = $hasCtrlCol('fermentacion_id') ? 'fermentacion_id' : 'registro_fermentacion_id';
$fechaCtrlExpr = $hasCtrlCol('fecha') ? 'fecha' : 'NULL';
$tempAmExpr = $hasCtrlCol('temperatura_am') ? 'temperatura_am' : ($hasCtrlCol('temp_masa') ? 'temp_masa' : 'NULL');
$tempPmExpr = $hasCtrlCol('temperatura_pm') ? 'temperatura_pm' : ($hasCtrlCol('temp_ambiente') ? 'temp_ambiente' : 'NULL');
$horaAmExpr = $hasCtrlCol('hora_am') ? 'hora_am' : 'NULL';
$horaPmExpr = $hasCtrlCol('hora_pm') ? 'hora_pm' : 'NULL';
$volteoAmExpr = $hasCtrlCol('volteo_am') ? 'volteo_am' : 'NULL';
$volteoPmExpr = $hasCtrlCol('volteo_pm') ? 'volteo_pm' : 'NULL';
$obsCtrlExpr = $hasCtrlCol('observaciones') ? 'observaciones' : 'NULL';

// Obtener registro de fermentación
$fermentacion = $db->fetch("
    SELECT rf.*, 
           {$fechaFinExpr} as fecha_fin,
           {$pesoInicialExpr} as peso_inicial,
           {$unidadPesoExpr} as unidad_peso,
           {$phInicialExpr} as ph_inicial,
           {$observacionesExpr} as observaciones,
           {$totalVolteosExpr} as total_volteos,
           l.codigo as lote_codigo, l.id as lote_id,
           p.nombre as proveedor, p.codigo as proveedor_codigo,
           v.nombre as variedad,
           cf.nombre as cajon,
           u.nombre as responsable
    FROM registros_fermentacion rf
    JOIN lotes l ON rf.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    JOIN variedades v ON l.variedad_id = v.id
    LEFT JOIN cajones_fermentacion cf ON rf.cajon_id = cf.id
    JOIN usuarios u ON rf.responsable_id = u.id
    WHERE rf.id = :id
", ['id' => $id]);

if (!$fermentacion) {
    setFlash('error', 'Registro de fermentación no encontrado');
    redirect('/fermentacion/index.php');
}

$pesoInicialKg = isset($fermentacion['peso_inicial']) && $fermentacion['peso_inicial'] !== null
    ? (float)$fermentacion['peso_inicial']
    : null;
$pesoFinalKg = isset($fermentacion['peso_final']) && $fermentacion['peso_final'] !== null
    ? (float)$fermentacion['peso_final']
    : null;
$unidadPesoProcesoFermentacion = !empty($fermentacion['unidad_peso'])
    ? Helpers::normalizePesoUnit($fermentacion['unidad_peso'])
    : Helpers::resolveInheritedPesoUnitForLote((int)$fermentacion['lote_id']);

$registroSecado = $db->fetch(
    "SELECT id FROM registros_secado WHERE lote_id = ? ORDER BY id DESC LIMIT 1",
    [$fermentacion['lote_id']]
);
$fermentacionFinalizada = !empty($fermentacion['fecha_fin']);
$requiereCompletarDatosFinales = $fermentacionFinalizada
    && ($pesoFinalKg === null || $fermentacion['humedad_final'] === null);
$rutaSiguienteSecado = $registroSecado
    ? (APP_URL . '/secado/control.php?id=' . (int)$registroSecado['id'])
    : (APP_URL . '/secado/crear.php?lote_id=' . (int)$fermentacion['lote_id']);
$labelSiguienteSecado = $registroSecado ? 'Ver estado de secado' : 'Iniciar Secado';
$descripcionSiguienteSecado = $fermentacionFinalizada
    ? 'La fermentación está finalizada. Continúe con el proceso de secado.'
    : 'Al finalizar la fermentación se habilitará automáticamente el formulario de secado.';

// Obtener controles diarios existentes
$controlesDiarios = $db->fetchAll("
    SELECT dia,
           {$fechaCtrlExpr} as fecha,
           {$tempAmExpr} as temperatura_am,
           {$tempPmExpr} as temperatura_pm,
           {$horaAmExpr} as hora_am,
           {$volteoAmExpr} as volteo_am,
           {$horaPmExpr} as hora_pm,
           {$volteoPmExpr} as volteo_pm,
           {$obsCtrlExpr} as observaciones
    FROM fermentacion_control_diario
    WHERE {$fkControlCol} = :id
    ORDER BY dia ASC
", ['id' => $id]);

// Calcular días transcurridos
$fechaInicio = new DateTime($fermentacion['fecha_inicio']);
$hoy = new DateTime();
$diasTranscurridos = $fechaInicio->diff($hoy)->days + 1;

// Preparar datos para Handsontable (6 días de control)
$diasControl = [];
for ($i = 1; $i <= 6; $i++) {
    $fechaDia = (clone $fechaInicio)->modify('+' . ($i - 1) . ' days');
    $controlExistente = array_filter($controlesDiarios, fn($c) => $c['dia'] == $i);
    $control = !empty($controlExistente) ? array_values($controlExistente)[0] : null;
    
    $diasControl[] = [
        'dia' => $i,
        'fecha' => $fechaDia->format('Y-m-d'),
        'fecha_display' => $fechaDia->format('d/m'),
        'hora_am' => $control['hora_am'] ?? null,
        'volteo_am' => isset($control['volteo_am']) && $control['volteo_am'] !== null ? (int)$control['volteo_am'] : null,
        'temp_am' => $control['temperatura_am'] ?? null,
        'hora_pm' => $control['hora_pm'] ?? null,
        'volteo_pm' => isset($control['volteo_pm']) && $control['volteo_pm'] !== null ? (int)$control['volteo_pm'] : null,
        'temp_pm' => $control['temperatura_pm'] ?? null,
        'observaciones' => $control['observaciones'] ?? ''
    ];
}

$pageTitle = 'Control de Fermentación';
$pageSubtitle = 'Lote: ' . $fermentacion['lote_codigo'];
$medicionMananaLabel = 'Mañana';
$medicionTardeLabel = 'Tarde';
$tempMinFermentacion = 35;
$tempMaxFermentacion = 50;
$horasMedicionMananaDisponibles = ['06:00', '07:00', '08:00', '09:00', '10:00', '11:00', '12:00'];
$horasMedicionTardeDisponibles = ['13:00', '14:00', '15:00', '16:00', '17:00', '18:00'];

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
    .handsontable td.volteo-si {
        background-color: #d4edda !important;
        font-weight: bold;
    }
    .handsontable td.volteo-no {
        background-color: #fff3cd !important;
    }
    .handsontable td.temp-alta {
        background-color: #f8d7da !important;
    }
    .handsontable td.temp-optima {
        background-color: #d4edda !important;
    }
    .handsontable td.temp-baja {
        background-color: #fff3cd !important;
    }
    .control-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
    }
</style>
HTML;

ob_start();
?>

<!-- Info del Lote -->
<div class="card mb-6">
    <div class="card-body">
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Lote</p>
                <p class="font-semibold text-primary"><?= htmlspecialchars($fermentacion['lote_codigo']) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Proveedor</p>
                <p class="font-medium"><?= htmlspecialchars($fermentacion['proveedor']) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Cajón</p>
                <p class="font-medium"><?= $fermentacion['cajon'] ?? 'No aplica' ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Fecha Inicio</p>
                <p class="font-medium"><?= Helpers::formatDate($fermentacion['fecha_inicio']) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Días</p>
                <p class="font-semibold text-lg <?= $diasTranscurridos >= 6 ? 'text-green-600' : 'text-gold' ?>">
                    <?= min($diasTranscurridos, 99) ?>
                </p>
            </div>
            <div>
                <p class="text-xs text-warmgray uppercase tracking-wide">Estado</p>
                <?php if ($fermentacion['fecha_fin']): ?>
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
        <h3 class="card-title">Datos Iniciales</h3>
    </div>
    <div class="card-body">
        <div class="control-summary">
            <div class="text-center p-4 bg-olive/10 rounded-lg">
                <p class="text-2xl font-bold text-primary"><?= $pesoInicialKg !== null ? Helpers::formatPesoVisual($pesoInicialKg, ['QQ']) : '-' ?></p>
                <p class="text-xs text-warmgray">Peso Inicial</p>
                <p class="text-xs text-warmgray mt-1"><?= $pesoInicialKg !== null ? Helpers::formatPesoVisual($pesoInicialKg, ['LB']) : '' ?></p>
            </div>
            <div class="text-center p-4 bg-olive/10 rounded-lg">
                <p class="text-2xl font-bold text-primary"><?= $fermentacion['humedad_inicial'] ? number_format($fermentacion['humedad_inicial'], 1) . '%' : '-' ?></p>
                <p class="text-xs text-warmgray">Humedad Inicial</p>
            </div>
            <div class="text-center p-4 bg-olive/10 rounded-lg">
                <p class="text-2xl font-bold text-primary"><?= $fermentacion['temperatura_inicial'] ? number_format($fermentacion['temperatura_inicial'], 1) . '°C' : '-' ?></p>
                <p class="text-xs text-warmgray">Temp. Inicial</p>
            </div>
            <div class="text-center p-4 bg-olive/10 rounded-lg">
                <p class="text-2xl font-bold text-primary"><?= $fermentacion['ph_inicial'] ? number_format($fermentacion['ph_inicial'], 2) : '-' ?></p>
                <p class="text-xs text-warmgray">pH Inicial</p>
            </div>
            <div class="text-center p-4 bg-olive/10 rounded-lg">
                <p class="text-2xl font-bold text-primary"><?= $fermentacion['total_volteos'] ?? 0 ?></p>
                <p class="text-xs text-warmgray">Total Volteos</p>
            </div>
        </div>
    </div>
</div>

<!-- Control Diario con Handsontable -->
<div class="card mb-6">
    <div class="card-header flex items-center justify-between">
        <h3 class="card-title">Control Diario de Fermentación</h3>
        <div class="flex gap-2">
            <button onclick="guardarControl()" class="btn btn-primary btn-sm" <?= $fermentacion['fecha_fin'] ? 'disabled' : '' ?>>
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
                <span>Temperatura en rango (35-50°C)</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="w-4 h-4 rounded" style="background-color: #fff3cd"></span>
                <span>Temperatura baja (&lt;35°C)</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="w-4 h-4 rounded" style="background-color: #f8d7da"></span>
                <span>Temperatura alta (&gt;50°C)</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="w-4 h-4 rounded" style="background-color: #d4edda"></span>
                <span>Volteo realizado</span>
            </div>
            <div class="w-full text-warmgray">
                Registre 2 mediciones por día: mañana y tarde. En cada medición capture hora, volteo y temperatura.
            </div>
        </div>
    </div>
</div>

<!-- Acciones de Fermentación -->
<?php if (!$fermentacion['fecha_fin'] || $requiereCompletarDatosFinales): ?>
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title"><?= $fermentacionFinalizada ? 'Completar Datos Finales' : 'Finalizar Fermentación' ?></h3>
    </div>
    <div class="card-body">
        <?php if ($fermentacionFinalizada): ?>
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            Esta fermentación ya fue finalizada, pero aún faltan datos finales.
            Registre el peso final para que secado tome la referencia correcta.
        </div>
        <?php endif; ?>
        <form id="finalizarForm" class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="form-group">
                <label class="form-label required">Fecha de Fin</label>
                <input type="date" name="fecha_fin" id="fecha_fin" class="form-control" 
                       value="<?= htmlspecialchars($fermentacion['fecha_fin'] ?: date('Y-m-d')) ?>"
                       min="<?= $fermentacion['fecha_inicio'] ?>"
                       <?= $fermentacionFinalizada ? 'readonly' : '' ?>>
            </div>
            <div class="form-group">
                <label class="form-label required">Peso Final</label>
                <div class="flex gap-3">
                    <input type="number" name="peso_final" id="peso_final" class="form-control flex-1"
                           step="0.01" min="0" placeholder="0.00"
                           value="<?= $pesoFinalKg !== null ? htmlspecialchars(number_format(Helpers::kgToPeso($pesoFinalKg, $unidadPesoProcesoFermentacion), 2, '.', '')) : '' ?>">
                    <select id="peso_final_unidad" class="form-control w-28">
                        <option value="QQ" <?= $unidadPesoProcesoFermentacion === 'QQ' ? 'selected' : '' ?>>QQ</option>
                        <option value="LB" <?= $unidadPesoProcesoFermentacion === 'LB' ? 'selected' : '' ?>>LB</option>
                        <option value="KG" <?= $unidadPesoProcesoFermentacion === 'KG' ? 'selected' : '' ?>>KG</option>
                    </select>
                </div>
                <p id="peso_final_equivalencias" class="form-hint mt-2">
                    <?= $pesoInicialKg !== null ? 'Referencia actual: ' . htmlspecialchars(Helpers::formatPesoVisual($pesoInicialKg, ['KG', 'QQ', 'LB'])) : 'Ingrese el peso final en LB, QQ o KG.' ?>
                </p>
            </div>
            <div class="form-group">
                <label class="form-label">Humedad Final (%)</label>
                <input type="number" name="humedad_final" id="humedad_final" class="form-control" 
                       step="0.1" min="0" max="100"
                       value="<?= $fermentacion['humedad_final'] !== null ? htmlspecialchars(number_format((float)$fermentacion['humedad_final'], 1, '.', '')) : '' ?>">
            </div>
        </form>
        <div class="mt-4">
            <button onclick="finalizarFermentacion()" class="btn btn-gold">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <?= $fermentacionFinalizada ? 'Guardar Datos Finales' : 'Finalizar Fermentación' ?>
            </button>
        </div>
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
                <h4 class="font-semibold text-green-800">Fermentación Finalizada</h4>
                <p class="text-sm text-green-700 mt-1">
                    Fecha: <?= Helpers::formatDate($fermentacion['fecha_fin']) ?> |
                    Peso Final: <?= $pesoFinalKg !== null ? Helpers::formatPesoVisual($pesoFinalKg, ['QQ', 'LB']) : 'No registrado' ?> |
                    Humedad Final: <?= $fermentacion['humedad_final'] ? number_format($fermentacion['humedad_final'], 1) . '%' : 'No registrada' ?>
                </p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Siguiente paso -->
<div class="card mb-6 border border-emerald-200 bg-emerald-50/60">
    <div class="card-body">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Siguiente paso</p>
                <h4 class="text-lg font-semibold text-emerald-900">Proceso de Secado</h4>
                <p class="text-sm text-emerald-800 mt-1"><?= htmlspecialchars($descripcionSiguienteSecado) ?></p>
            </div>
            <a href="<?= $fermentacionFinalizada ? $rutaSiguienteSecado : '#' ?>"
               class="btn <?= $fermentacionFinalizada ? 'btn-primary' : 'btn-outline opacity-50 cursor-not-allowed pointer-events-none' ?>">
                <?= htmlspecialchars($labelSiguienteSecado) ?>
            </a>
        </div>
    </div>
</div>

<!-- Observaciones Generales -->
<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">Observaciones Generales</h3>
    </div>
    <div class="card-body">
        <textarea id="observaciones_generales" class="form-control" rows="3" 
                  placeholder="Observaciones adicionales del proceso..." <?= $fermentacion['fecha_fin'] ? 'disabled' : '' ?>><?= htmlspecialchars($fermentacion['observaciones'] ?? '') ?></textarea>
        <?php if (!$fermentacion['fecha_fin']): ?>
        <button onclick="guardarObservaciones()" class="btn btn-outline btn-sm mt-3">Guardar Observaciones</button>
        <?php endif; ?>
    </div>
</div>

<!-- Botones de navegación -->
<div class="flex items-center gap-4">
    <a href="<?= APP_URL ?>/fermentacion/index.php" class="btn btn-outline">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Volver al listado
    </a>
    <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $fermentacion['lote_id'] ?>" class="btn btn-outline">
        Ver Lote
    </a>
    <?php if ($fermentacion['fecha_fin']): ?>
    <a href="<?= APP_URL ?>/reportes/fermentacion.php?id=<?= $id ?>" class="btn btn-primary">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        Ver Reporte
    </a>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/handsontable@12.1.3/dist/handsontable.full.min.js"></script>
<script>
const fermentacionId = <?= $id ?>;
const esFinalizado = <?= $fermentacion['fecha_fin'] ? 'true' : 'false' ?>;
const requiereCompletarDatosFinales = <?= $requiereCompletarDatosFinales ? 'true' : 'false' ?>;
const medicionMananaLabel = <?= json_encode($medicionMananaLabel) ?>;
const medicionTardeLabel = <?= json_encode($medicionTardeLabel) ?>;
const horasMedicionMananaDisponibles = <?= json_encode($horasMedicionMananaDisponibles) ?>;
const horasMedicionTardeDisponibles = <?= json_encode($horasMedicionTardeDisponibles) ?>;
const pesoInicialKgReferencia = <?= json_encode($pesoInicialKg) ?>;
const tempMinFermentacion = <?= json_encode($tempMinFermentacion) ?>;
const tempMaxFermentacion = <?= json_encode($tempMaxFermentacion) ?>;

// Datos iniciales
const datosControl = <?= json_encode($diasControl) ?>;
const pesoFinalInput = document.getElementById('peso_final');
const pesoFinalUnidadSelect = document.getElementById('peso_final_unidad');
const pesoFinalEquivalencias = document.getElementById('peso_final_equivalencias');

function pesoToKg(peso, unidad) {
    if (unidad === 'LB') return peso * 0.45359237;
    if (unidad === 'QQ') return peso * 45.36;
    return peso;
}

function kgToLb(kg) {
    return kg / 0.45359237;
}

function kgToQq(kg) {
    return kg / 45.36;
}

function formatPeso(valor) {
    return Number(valor).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function actualizarPesoFinalEquivalencias() {
    if (!pesoFinalEquivalencias) {
        return;
    }

    const valor = Number.parseFloat(pesoFinalInput?.value ?? '');
    if (!Number.isFinite(valor) || valor <= 0) {
        pesoFinalEquivalencias.textContent = pesoInicialKgReferencia && pesoInicialKgReferencia > 0
            ? `Referencia actual: ${formatPeso(pesoInicialKgReferencia)} KG | ${formatPeso(kgToQq(pesoInicialKgReferencia))} QQ | ${formatPeso(kgToLb(pesoInicialKgReferencia))} LB`
            : 'Ingrese el peso final en LB, QQ o KG.';
        return;
    }

    const unidad = pesoFinalUnidadSelect?.value || 'QQ';
    const pesoKg = pesoToKg(valor, unidad);
    pesoFinalEquivalencias.textContent = `Equivalente: ${formatPeso(kgToQq(pesoKg))} QQ | ${formatPeso(kgToLb(pesoKg))} LB | ${formatPeso(pesoKg)} KG`;
}

if (pesoFinalInput && pesoFinalUnidadSelect) {
    pesoFinalInput.addEventListener('input', actualizarPesoFinalEquivalencias);
    pesoFinalUnidadSelect.addEventListener('change', actualizarPesoFinalEquivalencias);
    actualizarPesoFinalEquivalencias();
}

// Configurar Handsontable
const container = document.getElementById('controlTable');
const hot = new Handsontable(container, {
    data: datosControl.map(d => [
        d.dia,
        d.fecha_display,
        d.hora_am,
        d.volteo_am === 1 ? 'Sí' : (d.volteo_am === 0 ? 'No' : ''),
        d.temp_am,
        d.hora_pm,
        d.volteo_pm === 1 ? 'Sí' : (d.volteo_pm === 0 ? 'No' : ''),
        d.temp_pm,
        d.observaciones
    ]),
    colHeaders: [
        'Día',
        'Fecha',
        `Hora ${medicionMananaLabel}`,
        `Volteo ${medicionMananaLabel}`,
        `Temp. ${medicionMananaLabel} (°C)`,
        `Hora ${medicionTardeLabel}`,
        `Volteo ${medicionTardeLabel}`,
        `Temp. ${medicionTardeLabel} (°C)`,
        'Observaciones'
    ],
    columns: [
        { data: 0, type: 'numeric', readOnly: true, className: 'htCenter htDimmed' },
        { data: 1, type: 'text', readOnly: true, className: 'htCenter htDimmed' },
        { data: 2, type: 'dropdown', source: ['', ...horasMedicionMananaDisponibles], strict: false, allowInvalid: false, className: 'htCenter' },
        { data: 3, type: 'dropdown', source: ['', 'Sí', 'No'], className: 'htCenter' },
        { data: 4, type: 'numeric', numericFormat: { pattern: '0.0' } },
        { data: 5, type: 'dropdown', source: ['', ...horasMedicionTardeDisponibles], strict: false, allowInvalid: false, className: 'htCenter' },
        { data: 6, type: 'dropdown', source: ['', 'Sí', 'No'], className: 'htCenter' },
        { data: 7, type: 'numeric', numericFormat: { pattern: '0.0' } },
        { data: 8, type: 'text', width: 220 }
    ],
    colWidths: [50, 80, 100, 100, 120, 100, 100, 120, 220],
    rowHeaders: false,
    height: 'auto',
    licenseKey: 'non-commercial-and-evaluation',
    readOnly: esFinalizado,
    cells: function(row, col) {
        const cellProperties = {};
        const data = this.instance.getData();
        
        // Colorear celdas de temperatura
        if (col === 4 || col === 7) {
            const val = data[row][col];
            if (val !== null && val !== '') {
                if (val > tempMaxFermentacion) {
                    cellProperties.className = 'htCenter temp-alta';
                } else if (val < tempMinFermentacion) {
                    cellProperties.className = 'htCenter temp-baja';
                } else {
                    cellProperties.className = 'htCenter temp-optima';
                }
            }
        }
        
        // Colorear columnas de volteo
        if (col === 3 || col === 6) {
            const val = data[row][col];
            if (val === 'Sí') {
                cellProperties.className = 'htCenter volteo-si';
            } else if (val === 'No') {
                cellProperties.className = 'htCenter volteo-no';
            }
        }
        
        return cellProperties;
    },
    afterChange: function(changes, source) {
        if (source === 'loadData') return;
        // Re-render para actualizar colores
        this.render();
    }
});

// Guardar control diario
async function guardarControl() {
    const data = hot.getData();
    const controles = data.map((row, idx) => ({
        dia: row[0],
        fecha: datosControl[idx].fecha,
        hora_am: row[2],
        volteo_am: row[3] === 'Sí' ? 1 : (row[3] === 'No' ? 0 : null),
        temp_am: row[4],
        hora_pm: row[5],
        volteo_pm: row[6] === 'Sí' ? 1 : (row[6] === 'No' ? 0 : null),
        temp_pm: row[7],
        observaciones: row[8]
    }));
    
    try {
        const response = await App.post('/api/fermentacion/guardar-control.php', {
            fermentacion_id: fermentacionId,
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

// Finalizar fermentación
async function finalizarFermentacion() {
    const fechaFin = document.getElementById('fecha_fin').value;
    const pesoFinal = document.getElementById('peso_final').value;
    const pesoFinalUnidad = document.getElementById('peso_final_unidad')?.value || 'QQ';
    const humedadFinal = document.getElementById('humedad_final').value;
    
    if (!fechaFin) {
        App.toast('Ingrese la fecha de fin', 'warning');
        return;
    }

    if (!pesoFinal) {
        App.toast('Ingrese el peso final de fermentación', 'warning');
        return;
    }
    
    const confirmed = await App.confirm(
        esFinalizado
            ? 'Se actualizarán los datos finales faltantes para que secado use el peso correcto.'
            : '¿Está seguro de finalizar la fermentación? Esta acción no se puede deshacer.',
        esFinalizado ? 'Guardar datos finales' : 'Finalizar fermentación'
    );
    if (!confirmed) {
        return;
    }
    
    // Primero guardar el control actual
    if (!esFinalizado) {
        await guardarControl();
    }
    
    try {
        const response = await App.post('/api/fermentacion/finalizar.php', {
            fermentacion_id: fermentacionId,
            fecha_fin: fechaFin,
            peso_final: pesoFinal || null,
            peso_final_unidad: pesoFinal ? pesoFinalUnidad : null,
            humedad_final: humedadFinal || null
        });
        
        if (response.success) {
            const redirectUrl = response.redirect || '<?= APP_URL ?>/secado/crear.php?lote_id=<?= (int)$fermentacion['lote_id'] ?>';
            const toastType = response.requires_recepcion ? 'warning' : 'success';
            const toastMessage = response.message || 'Fermentación finalizada. Redirigiendo a secado...';
            App.toast(toastMessage, toastType);
            setTimeout(() => window.location.href = redirectUrl, 900);
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
        const response = await App.post('/api/fermentacion/actualizar.php', {
            fermentacion_id: fermentacionId,
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
