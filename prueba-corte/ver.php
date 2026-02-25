<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Detalle de Prueba de Corte
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();
$colsPrueba = array_column($db->fetchAll("SHOW COLUMNS FROM registros_prueba_corte"), 'Field');
$hasPrCol = static fn(string $name): bool => in_array($name, $colsPrueba, true);

$id = $_GET['id'] ?? null;

if (!$id) {
    setFlash('error', 'ID de prueba no especificado');
    redirect('/prueba-corte/index.php');
}

$colAnalistaId = $hasPrCol('analista_id')
    ? 'analista_id'
    : ($hasPrCol('responsable_analisis_id') ? 'responsable_analisis_id' : ($hasPrCol('usuario_id') ? 'usuario_id' : null));
$joinAnalista = $colAnalistaId
    ? "LEFT JOIN usuarios u ON rpc.{$colAnalistaId} = u.id"
    : "LEFT JOIN usuarios u ON 1 = 0";

$fechaExpr = $hasPrCol('fecha_prueba') ? 'rpc.fecha_prueba' : ($hasPrCol('fecha') ? 'rpc.fecha' : 'NULL');
$calidadExpr = $hasPrCol('calidad_resultado')
    ? 'rpc.calidad_resultado'
    : ($hasPrCol('calidad_determinada') ? 'rpc.calidad_determinada' : ($hasPrCol('decision_lote') ? 'rpc.decision_lote' : 'NULL'));
$totalExpr = $hasPrCol('total_granos') ? 'rpc.total_granos' : ($hasPrCol('granos_analizados') ? 'rpc.granos_analizados' : '0');
$humedadExpr = $hasPrCol('humedad') ? 'rpc.humedad' : 'NULL';

$fermentadosExpr = $hasPrCol('granos_fermentados') ? 'rpc.granos_fermentados' : ($hasPrCol('bien_fermentados') ? 'rpc.bien_fermentados' : '0');
$parcialesExpr = $hasPrCol('granos_parciales')
    ? 'rpc.granos_parciales'
    : ($hasPrCol('granos_parcialmente_fermentados') ? 'rpc.granos_parcialmente_fermentados' : '0');
$pizarraExpr = $hasPrCol('granos_pizarra') ? 'rpc.granos_pizarra' : ($hasPrCol('pizarrosos') ? 'rpc.pizarrosos' : '0');
$violetasExpr = $hasPrCol('granos_violetas') ? 'rpc.granos_violetas' : ($hasPrCol('violeta') ? 'rpc.violeta' : '0');
$mohososExpr = $hasPrCol('granos_mohosos') ? 'rpc.granos_mohosos' : ($hasPrCol('mohosos') ? 'rpc.mohosos' : '0');
$germinadosExpr = $hasPrCol('granos_germinados') ? 'rpc.granos_germinados' : ($hasPrCol('germinados') ? 'rpc.germinados' : '0');
$danadosExpr = $hasPrCol('granos_danados')
    ? 'rpc.granos_danados'
    : ($hasPrCol('granos_dañados') ? 'rpc.granos_dañados' : ($hasPrCol('insectados') ? 'rpc.insectados' : '0'));

$pctFermentacionExpr = $hasPrCol('porcentaje_fermentacion')
    ? 'rpc.porcentaje_fermentacion'
    : "CASE WHEN {$totalExpr} > 0 THEN (({$fermentadosExpr} + ({$parcialesExpr} * 0.5)) / {$totalExpr}) * 100 ELSE 0 END";

// Obtener datos de prueba de corte
$prueba = $db->fetch("
    SELECT rpc.*,
           l.codigo as lote_codigo,
           l.peso_inicial_kg,
           p.nombre as proveedor,
           p.codigo as proveedor_codigo,
           v.nombre as variedad,
           u.nombre as analista,
           {$fechaExpr} as fecha_prueba,
           {$calidadExpr} as calidad_resultado,
           {$totalExpr} as total_granos,
           {$humedadExpr} as humedad,
           {$fermentadosExpr} as granos_fermentados,
           {$parcialesExpr} as granos_parciales,
           {$pizarraExpr} as granos_pizarra,
           {$violetasExpr} as granos_violetas,
           {$mohososExpr} as granos_mohosos,
           {$germinadosExpr} as granos_germinados,
           {$danadosExpr} as granos_danados,
           {$pctFermentacionExpr} as porcentaje_fermentacion
    FROM registros_prueba_corte rpc
    JOIN lotes l ON rpc.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    JOIN variedades v ON l.variedad_id = v.id
    {$joinAnalista}
    WHERE rpc.id = :id
", ['id' => $id]);

if (!$prueba) {
    setFlash('error', 'Prueba de corte no encontrada');
    redirect('/prueba-corte/index.php');
}

$tablaCalidadSalidaExiste = (bool)$db->fetch("SHOW TABLES LIKE 'registros_calidad_salida'");
$registroCalidadSalida = null;
if ($tablaCalidadSalidaExiste) {
    $registroCalidadSalida = $db->fetch(
        "SELECT id FROM registros_calidad_salida WHERE lote_id = :lote_id ORDER BY id DESC LIMIT 1",
        ['lote_id' => $prueba['lote_id']]
    );
}

$accionSiguiente = null;
if (strtoupper((string)$prueba['calidad_resultado']) === 'RECHAZADO') {
    $accionSiguiente = [
        'titulo' => 'Lote rechazado',
        'descripcion' => 'Este lote fue rechazado en prueba de corte y no avanza a calidad de salida.',
        'url' => APP_URL . '/lotes/ver.php?id=' . (int)$prueba['lote_id'],
        'label' => 'Ver lote',
        'disabled' => false,
    ];
} elseif (!$tablaCalidadSalidaExiste) {
    $accionSiguiente = [
        'titulo' => 'Patch pendiente',
        'descripcion' => 'Falta ejecutar el patch de base de datos para habilitar Calidad de salida.',
        'url' => '#',
        'label' => 'Patch requerido',
        'disabled' => true,
    ];
} elseif ($registroCalidadSalida) {
    $accionSiguiente = [
        'titulo' => 'Calidad de salida ya registrada',
        'descripcion' => 'Este lote ya tiene registro de calidad de salida.',
        'url' => APP_URL . '/calidad-salida/ver.php?id=' . (int)$registroCalidadSalida['id'],
        'label' => 'Ver calidad de salida',
        'disabled' => false,
    ];
} else {
    $accionSiguiente = [
        'titulo' => 'Siguiente paso: Calidad de salida',
        'descripcion' => 'Complete la ficha de calidad de salida antes del empaquetado.',
        'url' => APP_URL . '/calidad-salida/crear.php?lote_id=' . (int)$prueba['lote_id'] . '&from=prueba-corte',
        'label' => 'Ir a calidad de salida',
        'disabled' => false,
    ];
}

// Calcular porcentajes
$total = max(1, (int)($prueba['total_granos'] ?? 0));
$granosDanados = (int)($prueba['granos_danados'] ?? ($prueba['granos_dañados'] ?? 0));
$granosBuenos = (int)($prueba['granos_fermentados'] ?? 0) + (int)($prueba['granos_parciales'] ?? 0);
$defectos = (int)($prueba['granos_mohosos'] ?? 0) + (int)($prueba['granos_pizarra'] ?? 0) + (int)($prueba['granos_violetas'] ?? 0)
    + (int)($prueba['granos_germinados'] ?? 0) + $granosDanados;

$pctFermentados = ((int)($prueba['granos_fermentados'] ?? 0) / $total) * 100;
$pctParciales = ((int)($prueba['granos_parciales'] ?? 0) / $total) * 100;
$pctMohosos = ((int)($prueba['granos_mohosos'] ?? 0) / $total) * 100;
$pctPizarra = ((int)($prueba['granos_pizarra'] ?? 0) / $total) * 100;
$pctVioletas = ((int)($prueba['granos_violetas'] ?? 0) / $total) * 100;
$pctGerminados = ((int)($prueba['granos_germinados'] ?? 0) / $total) * 100;
$pctDañados = ($granosDanados / $total) * 100;
$pctDefectos = ($defectos / $total) * 100;

// Datos para el gráfico de dona
$granosData = [
    ['label' => 'Bien Fermentados', 'value' => $prueba['granos_fermentados'], 'color' => '#22c55e'],
    ['label' => 'Parciales', 'value' => $prueba['granos_parciales'], 'color' => '#eab308'],
    ['label' => 'Pizarra', 'value' => $prueba['granos_pizarra'], 'color' => '#9ca3af'],
    ['label' => 'Violetas', 'value' => $prueba['granos_violetas'], 'color' => '#a855f7'],
    ['label' => 'Mohosos', 'value' => $prueba['granos_mohosos'], 'color' => '#374151'],
    ['label' => 'Germinados', 'value' => $prueba['granos_germinados'], 'color' => '#166534'],
    ['label' => 'Dañados', 'value' => $granosDanados, 'color' => '#dc2626']
];

$observacionesRaw = (string)($prueba['observaciones'] ?? '');
$observacionesTexto = trim(preg_replace('/\[FOTOS_PRUEBA_CORTE\].*$/s', '', $observacionesRaw));
$fotosPruebaUrls = [];
if (preg_match('/\[FOTOS_PRUEBA_CORTE\]\s*(.*)$/s', $observacionesRaw, $matchFotos)) {
    $lineas = preg_split('/\R+/', trim((string)($matchFotos[1] ?? ''))) ?: [];
    foreach ($lineas as $linea) {
        $ruta = trim($linea);
        if ($ruta === '' || !str_starts_with($ruta, 'uploads/prueba-corte/')) {
            continue;
        }
        $fotosPruebaUrls[] = rtrim(APP_URL, '/') . '/' . ltrim($ruta, '/');
    }
}

$pageTitle = 'Prueba de Corte: ' . $prueba['lote_codigo'];
$pageSubtitle = 'Análisis de calidad post-secado';

ob_start();
?>

<!-- Header con estado -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div class="flex items-center gap-4">
        <a href="<?= APP_URL ?>/prueba-corte/index.php" class="p-2 text-warmgray hover:text-primary hover:bg-olive/20 rounded-lg transition-colors">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
        </a>
        <div>
            <h2 class="text-2xl font-bold text-primary"><?= htmlspecialchars($prueba['lote_codigo']) ?></h2>
            <p class="text-warmgray"><?= htmlspecialchars($prueba['proveedor']) ?></p>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <?php
        $badgeClass = match($prueba['calidad_resultado']) {
            'PREMIUM' => 'badge-success',
            'EXPORTACION' => 'badge-primary',
            'NACIONAL' => 'badge-gold',
            'RECHAZADO' => 'badge-error',
            default => 'badge-secondary'
        };
        ?>
        <span class="badge <?= $badgeClass ?> text-lg px-4 py-2"><?= $prueba['calidad_resultado'] ?></span>
        <a href="<?= APP_URL ?>/reportes/prueba-corte.php?id=<?= $id ?>" class="btn btn-outline">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Reporte PDF
        </a>
    </div>
</div>

<?php if ($accionSiguiente): ?>
<div class="card mb-6 border border-emerald-200 bg-emerald-50/60">
    <div class="card-body">
        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Flujo guiado</p>
        <h3 class="text-lg font-semibold text-emerald-900 mt-1"><?= htmlspecialchars($accionSiguiente['titulo']) ?></h3>
        <p class="text-sm text-emerald-800 mt-1"><?= htmlspecialchars($accionSiguiente['descripcion']) ?></p>
        <div class="mt-4">
            <a href="<?= htmlspecialchars($accionSiguiente['url']) ?>"
               class="btn <?= $accionSiguiente['disabled'] ? 'btn-outline opacity-50 cursor-not-allowed pointer-events-none' : 'btn-primary' ?>">
                <?= htmlspecialchars($accionSiguiente['label']) ?>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Resultados Principales -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="card p-4 text-center">
        <p class="text-3xl font-bold text-primary">
            <?= number_format($prueba['porcentaje_fermentacion'], 1) ?>%
        </p>
        <p class="text-xs text-warmgray">Fermentación</p>
    </div>
    <div class="card p-4 text-center">
        <p class="text-3xl font-bold text-red-600">
            <?= number_format($pctDefectos, 1) ?>%
        </p>
        <p class="text-xs text-warmgray">Defectos</p>
    </div>
    <div class="card p-4 text-center">
        <p class="text-3xl font-bold text-primary"><?= $prueba['total_granos'] ?></p>
        <p class="text-xs text-warmgray">Granos Analizados</p>
    </div>
    <div class="card p-4 text-center">
        <p class="text-3xl font-bold text-warmgray">
            <?= $prueba['humedad'] ? number_format($prueba['humedad'], 1) . '%' : 'N/R' ?>
        </p>
        <p class="text-xs text-warmgray">Humedad</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Información -->
    <div class="lg:col-span-1">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Información de la Prueba</h3>
            </div>
            <div class="card-body space-y-4">
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Lote</span>
                    <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= $prueba['lote_id'] ?>" class="font-medium text-primary hover:underline">
                        <?= htmlspecialchars($prueba['lote_codigo']) ?>
                    </a>
                </div>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Proveedor</span>
                    <span class="font-medium">
                        <span class="text-primary font-bold"><?= htmlspecialchars($prueba['proveedor_codigo']) ?></span>
                        - <?= htmlspecialchars($prueba['proveedor']) ?>
                    </span>
                </div>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Variedad</span>
                    <span class="font-medium"><?= htmlspecialchars($prueba['variedad']) ?></span>
                </div>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Fecha de Prueba</span>
                    <span class="font-medium"><?= Helpers::formatDate($prueba['fecha_prueba']) ?></span>
                </div>
                <div class="flex justify-between items-center py-2 border-b border-gray-100">
                    <span class="text-warmgray">Analista</span>
                    <span class="font-medium"><?= htmlspecialchars($prueba['analista']) ?></span>
                </div>
                <div class="flex justify-between items-center py-2">
                    <span class="text-warmgray">Calidad Final</span>
                    <span class="badge <?= $badgeClass ?>"><?= $prueba['calidad_resultado'] ?></span>
                </div>
            </div>
        </div>
        
        <!-- Referencia CCN51 -->
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Tabla de referencia CCN51</h3>
            </div>
            <div class="card-body">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead>
                            <tr class="text-left text-gray-600 border-b border-gray-200">
                                <th class="py-2 pr-4">Parámetro</th>
                                <th class="py-2 pr-4">Rango referencial</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            <tr>
                                <td class="py-2 pr-4">Bien fermentados</td>
                                <td class="py-2 pr-4">≥ 65%</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4">Violetas</td>
                                <td class="py-2 pr-4">≤ 15%</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4">Pizarrosos</td>
                                <td class="py-2 pr-4">≤ 3%</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4">Mohosos</td>
                                <td class="py-2 pr-4">≤ 3%</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4">Germinados / Dañados</td>
                                <td class="py-2 pr-4">≤ 3%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Observaciones -->
        <?php if ($observacionesTexto !== ''): ?>
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Observaciones</h3>
            </div>
            <div class="card-body">
                <p class="text-warmgray whitespace-pre-wrap"><?= htmlspecialchars($observacionesTexto) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($fotosPruebaUrls)): ?>
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Fotos de la Muestra</h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($fotosPruebaUrls as $fotoUrl): ?>
                        <a href="<?= htmlspecialchars($fotoUrl) ?>" target="_blank" rel="noopener" class="block border border-gray-200 rounded-lg overflow-hidden hover:border-primary transition-colors">
                            <img src="<?= htmlspecialchars($fotoUrl) ?>" alt="Foto de muestra" class="w-full h-48 object-cover">
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Detalle de Granos -->
    <div class="lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Detalle del Análisis de Granos</h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Granos Buenos -->
                    <div>
                        <h4 class="font-semibold text-green-600 mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Granos Buenos
                        </h4>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <span class="w-3 h-3 rounded-full bg-green-500"></span>
                                    <span>Bien Fermentados</span>
                                </div>
                                <div class="text-right">
                                    <span class="font-bold text-lg"><?= $prueba['granos_fermentados'] ?></span>
                                    <span class="text-warmgray text-sm ml-1">(<?= number_format($pctFermentados, 1) ?>%)</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-yellow-50 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <span class="w-3 h-3 rounded-full bg-yellow-500"></span>
                                    <span>Parcialmente Fermentados</span>
                                </div>
                                <div class="text-right">
                                    <span class="font-bold text-lg"><?= $prueba['granos_parciales'] ?></span>
                                    <span class="text-warmgray text-sm ml-1">(<?= number_format($pctParciales, 1) ?>%)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Defectos -->
                    <div>
                        <h4 class="font-semibold text-red-600 mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            Defectos
                        </h4>
                        <div class="space-y-2">
                            <div class="flex items-center justify-between p-2 bg-gray-50 rounded">
                                <div class="flex items-center gap-2">
                                    <span class="w-3 h-3 rounded-full bg-gray-400"></span>
                                    <span class="text-sm">Pizarra/Sin Fermentar</span>
                                </div>
                                <div class="text-right">
                                    <span class="font-bold"><?= $prueba['granos_pizarra'] ?></span>
                                    <span class="text-warmgray text-xs ml-1">(<?= number_format($pctPizarra, 1) ?>%)</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between p-2 bg-purple-50 rounded">
                                <div class="flex items-center gap-2">
                                    <span class="w-3 h-3 rounded-full bg-purple-500"></span>
                                    <span class="text-sm">Violetas</span>
                                </div>
                                <div class="text-right">
                                    <span class="font-bold"><?= $prueba['granos_violetas'] ?></span>
                                    <span class="text-warmgray text-xs ml-1">(<?= number_format($pctVioletas, 1) ?>%)</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between p-2 bg-gray-100 rounded">
                                <div class="flex items-center gap-2">
                                    <span class="w-3 h-3 rounded-full bg-gray-700"></span>
                                    <span class="text-sm">Mohosos</span>
                                </div>
                                <div class="text-right">
                                    <span class="font-bold"><?= $prueba['granos_mohosos'] ?></span>
                                    <span class="text-warmgray text-xs ml-1">(<?= number_format($pctMohosos, 1) ?>%)</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between p-2 bg-green-50 rounded">
                                <div class="flex items-center gap-2">
                                    <span class="w-3 h-3 rounded-full bg-green-800"></span>
                                    <span class="text-sm">Germinados</span>
                                </div>
                                <div class="text-right">
                                    <span class="font-bold"><?= $prueba['granos_germinados'] ?></span>
                                    <span class="text-warmgray text-xs ml-1">(<?= number_format($pctGerminados, 1) ?>%)</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between p-2 bg-red-50 rounded">
                                <div class="flex items-center gap-2">
                                    <span class="w-3 h-3 rounded-full bg-red-600"></span>
                                    <span class="text-sm">Dañados/Insectos</span>
                                </div>
                                <div class="text-right">
                                    <span class="font-bold"><?= $granosDanados ?></span>
                                    <span class="text-warmgray text-xs ml-1">(<?= number_format($pctDañados, 1) ?>%)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Gráfico de Distribución -->
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Distribución de Granos</h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <canvas id="granosChart" height="250"></canvas>
                    </div>
                    <div class="flex flex-col justify-center">
                        <div class="space-y-3">
                            <?php foreach ($granosData as $item): ?>
                                <?php if ($item['value'] > 0): ?>
                                <div class="flex items-center gap-3">
                                    <span class="w-4 h-4 rounded" style="background-color: <?= $item['color'] ?>"></span>
                                    <span class="flex-1"><?= $item['label'] ?></span>
                                    <span class="font-bold"><?= $item['value'] ?></span>
                                    <span class="text-warmgray text-sm">(<?= number_format(($item['value'] / $total) * 100, 1) ?>%)</span>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mt-6 pt-6 border-t border-gray-200">
                            <div class="flex justify-between items-center mb-2">
                                <span class="font-semibold">Total Granos Buenos</span>
                                <span class="text-green-600 font-bold"><?= $granosBuenos ?> (<?= number_format(($granosBuenos / $total) * 100, 1) ?>%)</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="font-semibold">Total Defectos</span>
                                <span class="text-red-600 font-bold"><?= $defectos ?> (<?= number_format($pctDefectos, 1) ?>%)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Barra de Progreso Visual -->
        <div class="card mt-6">
            <div class="card-header">
                <h3 class="card-title">Composición Visual</h3>
            </div>
            <div class="card-body">
                <div class="flex h-8 rounded-lg overflow-hidden">
                    <?php foreach ($granosData as $item): ?>
                        <?php if ($item['value'] > 0): ?>
                            <div style="width: <?= ($item['value'] / $total) * 100 ?>%; background-color: <?= $item['color'] ?>" 
                                 class="flex items-center justify-center text-white text-xs font-medium"
                                 title="<?= $item['label'] ?>: <?= $item['value'] ?> (<?= number_format(($item['value'] / $total) * 100, 1) ?>%)">
                                <?php if (($item['value'] / $total) * 100 > 8): ?>
                                    <?= number_format(($item['value'] / $total) * 100, 0) ?>%
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="flex flex-wrap gap-4 mt-4 justify-center">
                    <?php foreach ($granosData as $item): ?>
                        <?php if ($item['value'] > 0): ?>
                        <div class="flex items-center gap-1 text-xs">
                            <span class="w-3 h-3 rounded" style="background-color: <?= $item['color'] ?>"></span>
                            <?= $item['label'] ?>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('granosChart').getContext('2d');
    
    const data = <?= json_encode($granosData) ?>;
    const filteredData = data.filter(d => d.value > 0);
    
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: filteredData.map(d => d.label),
            datasets: [{
                data: filteredData.map(d => d.value),
                backgroundColor: filteredData.map(d => d.color),
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.raw;
                            const total = <?= $total ?>;
                            const pct = ((value / total) * 100).toFixed(1);
                            return context.label + ': ' + value + ' (' + pct + '%)';
                        }
                    }
                }
            },
            cutout: '60%'
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
