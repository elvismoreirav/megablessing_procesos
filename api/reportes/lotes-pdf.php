<?php
/**
 * API: Generar reporte de lotes en formato PDF/HTML para impresión
 * GET /api/reportes/lotes-pdf.php?fecha_desde=YYYY-MM-DD&fecha_hasta=YYYY-MM-DD&...
 */

require_once dirname(dirname(__DIR__)) . '/bootstrap.php';
require_once BASE_PATH . '/core/PdfReport.php';

if (!Auth::check()) {
    http_response_code(401);
    die('No autorizado');
}

$db = Database::getInstance();

// Filtros
$fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$estado = $_GET['estado'] ?? '';
$proveedor = $_GET['proveedor'] ?? '';
$calidad = $_GET['calidad'] ?? '';

// Construir query
$where = ["l.fecha_entrada BETWEEN ? AND ?"];
$params = [$fechaDesde, $fechaHasta];

if ($estado) {
    $where[] = "l.estado_proceso = ?";
    $params[] = $estado;
}

if ($proveedor) {
    $where[] = "l.proveedor_id = ?";
    $params[] = $proveedor;
}

$whereClause = implode(' AND ', $where);

// Obtener lotes
$lotes = $db->fetchAll(
    "SELECT l.*, 
            p.nombre as proveedor_nombre,
            v.nombre as variedad_nombre,
            ep.nombre as estado_nombre
    FROM lotes l
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    LEFT JOIN estados_producto ep ON l.estado_producto_id = ep.id
    WHERE {$whereClause}
    ORDER BY l.fecha_entrada DESC
    LIMIT 100",
    $params
);

// Calcular estadísticas
$totalLotes = count($lotes);
$pesoTotal = array_sum(array_column($lotes, 'peso_inicial_kg'));

// Contar estados
$estadosCount = [];
foreach ($lotes as $l) {
    $est = $l['estado_proceso'] ?? 'DESCONOCIDO';
    $estadosCount[$est] = ($estadosCount[$est] ?? 0) + 1;
}

// Crear reporte
$periodoTexto = "Del " . date('d/m/Y', strtotime($fechaDesde)) . " al " . date('d/m/Y', strtotime($fechaHasta));
$filtrosTexto = [];
if ($estado) $filtrosTexto[] = "Estado: $estado";
if ($proveedor) {
    $provInfo = $db->fetch("SELECT nombre FROM proveedores WHERE id = ?", [$proveedor]);
    if ($provInfo) $filtrosTexto[] = "Proveedor: " . $provInfo['nombre'];
}

$subtitle = $periodoTexto;
if (!empty($filtrosTexto)) {
    $subtitle .= " | " . implode(", ", $filtrosTexto);
}

$pdf = new PdfReport('Reporte de Lotes', $subtitle);

// Obtener nombre de empresa
$empresaData = $db->fetch("SELECT nombre FROM empresa LIMIT 1");
if ($empresaData) {
    $pdf->setEmpresa($empresaData['nombre']);
}

// Estadísticas principales
$finalizados = $estadosCount['FINALIZADO'] ?? 0;
$enProceso = array_sum($estadosCount) - $finalizados - ($estadosCount['RECHAZADO'] ?? 0);
$rechazados = $estadosCount['RECHAZADO'] ?? 0;

$pdf->addStats([
    ['value' => number_format($totalLotes), 'label' => 'Total Lotes', 'color' => ''],
    ['value' => number_format($finalizados), 'label' => 'Finalizados', 'color' => 'emerald'],
    ['value' => number_format($enProceso), 'label' => 'En Proceso', 'color' => 'amber'],
    ['value' => number_format($rechazados), 'label' => 'Rechazados', 'color' => 'orange'],
    ['value' => number_format($pesoTotal, 1) . ' kg', 'label' => 'Peso Total', 'color' => 'blue']
]);

// Distribución por estado
if (!empty($estadosCount)) {
    $estadoData = [];
    foreach ($estadosCount as $est => $count) {
        $estadoData[str_replace('_', ' ', $est)] = $count . ' (' . number_format(($count / $totalLotes) * 100, 1) . '%)';
    }
    $pdf->addKeyValue($estadoData, 'Distribución por Estado');
}

// Tabla de lotes
if (!empty($lotes)) {
    $headers = ['Código', 'Fecha', 'Proveedor', 'Variedad', 'Kg', 'Estado'];
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
        'FINALIZADO' => 'green',
        'RECHAZADO' => 'red'
    ];
    
    foreach ($lotes as $lote) {
        $estadoColor = $estadoColors[$lote['estado_proceso']] ?? 'blue';
        
        $rows[] = [
            '<strong>' . htmlspecialchars($lote['codigo']) . '</strong>',
            date('d/m/Y', strtotime($lote['fecha_entrada'])),
            htmlspecialchars($lote['proveedor_nombre'] ?? '-'),
            htmlspecialchars($lote['variedad_nombre'] ?? '-'),
            number_format($lote['peso_inicial_kg'] ?? 0, 1),
            PdfReport::badge(str_replace('_', ' ', $lote['estado_proceso']), $estadoColor)
        ];
    }
    $pdf->addTable($headers, $rows, 'Listado de Lotes (máx. 100)');
}

// Generar salida
$filename = 'lotes_' . date('Ymd_His') . '.html';
$pdf->output($filename);
