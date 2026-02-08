<?php
/**
 * API: Generar reporte consolidado en formato PDF/HTML para impresión
 * GET /api/reportes/consolidado-pdf.php?fecha_inicio=YYYY-MM-DD&fecha_fin=YYYY-MM-DD
 */

require_once dirname(dirname(__DIR__)) . '/bootstrap.php';
require_once BASE_PATH . '/core/PdfReport.php';

if (!Auth::check()) {
    http_response_code(401);
    die('No autorizado');
}

$db = Database::getInstance();

// Obtener filtros
$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fechaFin = $_GET['fecha_fin'] ?? date('Y-m-d');

// Título del reporte
$periodoTexto = "Del " . date('d/m/Y', strtotime($fechaInicio)) . " al " . date('d/m/Y', strtotime($fechaFin));

$pdf = new PdfReport(
    'Reporte Consolidado de Producción',
    $periodoTexto
);

// Obtener nombre de empresa
$empresaData = $db->fetch("SELECT nombre FROM empresa LIMIT 1");
if ($empresaData) {
    $pdf->setEmpresa($empresaData['nombre']);
}

// =============================================================================
// ESTADÍSTICAS DE LOTES
// =============================================================================

$statsLotes = $db->fetch("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN estado_proceso = 'FINALIZADO' THEN 1 END) as finalizados,
        COUNT(CASE WHEN estado_proceso NOT IN ('FINALIZADO', 'RECHAZADO') THEN 1 END) as en_proceso,
        COUNT(CASE WHEN estado_proceso = 'RECHAZADO' THEN 1 END) as rechazados,
        COALESCE(SUM(peso_inicial_kg), 0) as kg_recibidos
    FROM lotes 
    WHERE fecha_entrada BETWEEN ? AND ?
", [$fechaInicio, $fechaFin]);

// Stats de fermentación
$statsFerm = $db->fetch("
    SELECT 
        COUNT(DISTINCT rf.id) as total,
        AVG(TIMESTAMPDIFF(DAY, rf.fecha_inicio, NOW())) as dias_promedio,
        AVG(rf.temperatura_inicial) as temp_promedio,
        AVG(rf.porcentaje_fermentados) as ferm_promedio
    FROM registros_fermentacion rf
    JOIN lotes l ON rf.lote_id = l.id
    WHERE l.fecha_entrada BETWEEN ? AND ?
", [$fechaInicio, $fechaFin]);

// Stats de secado
$statsSecado = $db->fetch("
    SELECT 
        COUNT(DISTINCT rs.id) as total,
        AVG(rs.humedad_inicial) as humedad_inicial_prom,
        AVG(rs.humedad_final) as humedad_final_prom,
        COUNT(CASE WHEN rs.humedad_final <= 7 THEN 1 END) as optimos
    FROM registros_secado rs
    JOIN lotes l ON rs.lote_id = l.id
    WHERE l.fecha_entrada BETWEEN ? AND ?
", [$fechaInicio, $fechaFin]);

// Stats de calidad (prueba de corte)
$statsCalidad = $db->fetch("
    SELECT 
        COUNT(*) as total,
        AVG(bien_fermentados) as ferm_promedio,
        AVG(defectos_totales) as defectos_promedio,
        COUNT(CASE WHEN decision_lote = 'APROBADO' AND bien_fermentados >= 75 THEN 1 END) as premium,
        COUNT(CASE WHEN decision_lote = 'APROBADO' AND bien_fermentados >= 60 AND bien_fermentados < 75 THEN 1 END) as exportacion,
        COUNT(CASE WHEN decision_lote = 'APROBADO' AND bien_fermentados < 60 THEN 1 END) as nacional,
        COUNT(CASE WHEN decision_lote = 'RECHAZADO' THEN 1 END) as rechazado
    FROM registros_prueba_corte pc
    JOIN lotes l ON pc.lote_id = l.id
    WHERE l.fecha_entrada BETWEEN ? AND ?
", [$fechaInicio, $fechaFin]);

// Agregar estadísticas principales
$pdf->addStats([
    ['value' => number_format($statsLotes['total'] ?? 0), 'label' => 'Total Lotes', 'color' => ''],
    ['value' => number_format($statsLotes['finalizados'] ?? 0), 'label' => 'Finalizados', 'color' => 'emerald'],
    ['value' => number_format($statsLotes['en_proceso'] ?? 0), 'label' => 'En Proceso', 'color' => 'amber'],
    ['value' => number_format($statsLotes['rechazados'] ?? 0), 'label' => 'Rechazados', 'color' => 'orange'],
    ['value' => number_format($statsLotes['kg_recibidos'] ?? 0, 1) . ' kg', 'label' => 'Kg Recibidos', 'color' => 'blue'],
    ['value' => number_format($statsFerm['total'] ?? 0), 'label' => 'Procesos Ferm.', 'color' => 'purple'],
    ['value' => number_format($statsSecado['total'] ?? 0), 'label' => 'Procesos Secado', 'color' => 'amber'],
    ['value' => number_format($statsCalidad['total'] ?? 0), 'label' => 'Pruebas Corte', 'color' => 'emerald']
]);

// =============================================================================
// RESUMEN DE PROCESOS
// =============================================================================

$pdf->addKeyValue([
    'Procesos Fermentación' => number_format($statsFerm['total'] ?? 0),
    'Temp. Inicial Promedio' => number_format($statsFerm['temp_promedio'] ?? 0, 1) . '°C',
    '% Fermentación Promedio' => number_format($statsFerm['ferm_promedio'] ?? 0, 1) . '%',
    'Procesos Secado' => number_format($statsSecado['total'] ?? 0),
    'Humedad Inicial Prom.' => number_format($statsSecado['humedad_inicial_prom'] ?? 0, 1) . '%',
    'Humedad Final Prom.' => number_format($statsSecado['humedad_final_prom'] ?? 0, 1) . '%',
    'Lotes Óptimos (≤7%)' => number_format($statsSecado['optimos'] ?? 0),
    'Pruebas de Corte' => number_format($statsCalidad['total'] ?? 0)
], 'Resumen de Procesos');

// =============================================================================
// TOP PROVEEDORES
// =============================================================================

$topProveedores = $db->fetchAll("
    SELECT 
        p.nombre,
        COUNT(l.id) as total_lotes,
        SUM(l.peso_inicial_kg) as kg_total
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    WHERE l.fecha_entrada BETWEEN ? AND ?
    GROUP BY p.id, p.nombre
    ORDER BY kg_total DESC
    LIMIT 5
", [$fechaInicio, $fechaFin]);

if (!empty($topProveedores)) {
    $headers = ['Proveedor', 'Lotes', 'Kg Recibidos'];
    $rows = [];
    foreach ($topProveedores as $prov) {
        $rows[] = [
            htmlspecialchars($prov['nombre']),
            number_format($prov['total_lotes']),
            number_format($prov['kg_total'] ?? 0, 1)
        ];
    }
    $pdf->addTable($headers, $rows, 'Top 5 Proveedores');
}

// =============================================================================
// DISTRIBUCIÓN POR VARIEDAD
// =============================================================================

$porVariedad = $db->fetchAll("
    SELECT 
        v.nombre,
        COUNT(l.id) as total_lotes,
        SUM(l.peso_inicial_kg) as kg_total
    FROM lotes l
    JOIN variedades v ON l.variedad_id = v.id
    WHERE l.fecha_entrada BETWEEN ? AND ?
    GROUP BY v.id, v.nombre
    ORDER BY kg_total DESC
", [$fechaInicio, $fechaFin]);

if (!empty($porVariedad)) {
    $headers = ['Variedad', 'Lotes', 'Kg Total', '% del Total'];
    $rows = [];
    $totalKg = array_sum(array_column($porVariedad, 'kg_total'));
    foreach ($porVariedad as $var) {
        $porcentaje = $totalKg > 0 ? ($var['kg_total'] / $totalKg) * 100 : 0;
        $rows[] = [
            htmlspecialchars($var['nombre']),
            number_format($var['total_lotes']),
            number_format($var['kg_total'] ?? 0, 1),
            number_format($porcentaje, 1) . '%'
        ];
    }
    $pdf->addTable($headers, $rows, 'Distribución por Variedad');
}

// =============================================================================
// DISTRIBUCIÓN DE CALIDAD
// =============================================================================

$totalCalidad = ($statsCalidad['premium'] ?? 0) + ($statsCalidad['exportacion'] ?? 0) + 
                ($statsCalidad['nacional'] ?? 0) + ($statsCalidad['rechazado'] ?? 0);

if ($totalCalidad > 0) {
    $pdf->addKeyValue([
        'Premium (≥75% ferm.)' => number_format($statsCalidad['premium'] ?? 0) . ' (' . number_format((($statsCalidad['premium'] ?? 0) / $totalCalidad) * 100, 1) . '%)',
        'Exportación (60-75%)' => number_format($statsCalidad['exportacion'] ?? 0) . ' (' . number_format((($statsCalidad['exportacion'] ?? 0) / $totalCalidad) * 100, 1) . '%)',
        'Nacional (<60%)' => number_format($statsCalidad['nacional'] ?? 0) . ' (' . number_format((($statsCalidad['nacional'] ?? 0) / $totalCalidad) * 100, 1) . '%)',
        'Rechazado' => number_format($statsCalidad['rechazado'] ?? 0) . ' (' . number_format((($statsCalidad['rechazado'] ?? 0) / $totalCalidad) * 100, 1) . '%)',
        '% Bien Fermentados Prom.' => number_format($statsCalidad['ferm_promedio'] ?? 0, 1) . '%',
        '% Defectos Prom.' => number_format($statsCalidad['defectos_promedio'] ?? 0, 1) . '%'
    ], 'Clasificación de Calidad');
}

// =============================================================================
// ÚLTIMOS LOTES
// =============================================================================

$ultimosLotes = $db->fetchAll("
    SELECT 
        l.codigo,
        DATE_FORMAT(l.fecha_entrada, '%d/%m/%Y') as fecha,
        p.nombre as proveedor,
        v.nombre as variedad,
        l.peso_inicial_kg,
        l.estado_proceso,
        pc.decision_lote as calidad
    FROM lotes l
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    LEFT JOIN registros_prueba_corte pc ON l.id = pc.lote_id AND pc.tipo_prueba = 'POST_SECADO'
    WHERE l.fecha_entrada BETWEEN ? AND ?
    ORDER BY l.fecha_entrada DESC, l.id DESC
    LIMIT 10
", [$fechaInicio, $fechaFin]);

if (!empty($ultimosLotes)) {
    $headers = ['Código', 'Fecha', 'Proveedor', 'Variedad', 'Kg', 'Estado', 'Calidad'];
    $rows = [];
    
    $estadoColors = [
        'RECEPCION' => 'blue',
        'CALIDAD' => 'blue',
        'PRE_SECADO' => 'yellow',
        'FERMENTACION' => 'yellow',
        'SECADO' => 'yellow',
        'CALIDAD_POST' => 'yellow',
        'EMPAQUETADO' => 'yellow',
        'ALMACENADO' => 'green',
        'DESPACHO' => 'green',
        'FINALIZADO' => 'green'
    ];
    
    $calidadColors = [
        'APROBADO' => 'green',
        'RECHAZADO' => 'red',
        'REPROCESO' => 'yellow',
        'MEZCLA' => 'blue'
    ];
    
    foreach ($ultimosLotes as $lote) {
        $estadoColor = $estadoColors[$lote['estado_proceso']] ?? 'blue';
        $calidadColor = $calidadColors[$lote['calidad']] ?? 'blue';
        
        $rows[] = [
            '<strong>' . htmlspecialchars($lote['codigo']) . '</strong>',
            $lote['fecha'],
            htmlspecialchars($lote['proveedor'] ?? '-'),
            htmlspecialchars($lote['variedad'] ?? '-'),
            number_format($lote['peso_inicial_kg'] ?? 0, 1),
            PdfReport::badge(str_replace('_', ' ', $lote['estado_proceso']), $estadoColor),
            $lote['calidad'] ? PdfReport::badge($lote['calidad'], $calidadColor) : '-'
        ];
    }
    $pdf->addTable($headers, $rows, 'Últimos 10 Lotes del Período');
}

// =============================================================================
// GENERAR SALIDA
// =============================================================================

$filename = 'consolidado_' . date('Ymd_His') . '.html';
$pdf->output($filename);
