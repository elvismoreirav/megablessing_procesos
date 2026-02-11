<?php
/**
 * Consulta QR de ficha/lote (sin informacion comercial)
 */

require_once __DIR__ . '/../bootstrap.php';

$db = Database::getInstance();

$fichaId = intval($_GET['ficha_id'] ?? 0);
$loteId = intval($_GET['lote_id'] ?? 0);

$registro = null;
if ($fichaId > 0) {
    $registro = $db->fetchOne("
        SELECT f.id as ficha_id,
               f.lote_id,
               f.codificacion,
               f.producto,
               f.tipo_entrega,
               f.revision_limpieza,
               f.revision_olor_normal,
               f.revision_ausencia_moho,
               f.peso_bruto,
               f.tara_envase,
               f.peso_final_registro,
               f.unidad_peso,
               f.calificacion_humedad,
               f.calidad_registro,
               f.presencia_defectos,
               f.clasificacion_compra,
               f.fermentacion_estado,
               f.secado_inicio,
               f.secado_fin,
               f.observaciones,
               f.created_at as ficha_creada,
               l.codigo as lote_codigo,
               l.fecha_entrada as lote_fecha_entrada,
               l.estado_proceso as lote_estado,
               p.nombre as proveedor_nombre,
               p.tipo as proveedor_tipo,
               v.nombre as variedad_nombre
        FROM fichas_registro f
        INNER JOIN lotes l ON f.lote_id = l.id
        LEFT JOIN proveedores p ON l.proveedor_id = p.id
        LEFT JOIN variedades v ON l.variedad_id = v.id
        WHERE f.id = ?
    ", [$fichaId]);
} elseif ($loteId > 0) {
    $registro = $db->fetchOne("
        SELECT f.id as ficha_id,
               f.lote_id,
               f.codificacion,
               f.producto,
               f.tipo_entrega,
               f.revision_limpieza,
               f.revision_olor_normal,
               f.revision_ausencia_moho,
               f.peso_bruto,
               f.tara_envase,
               f.peso_final_registro,
               f.unidad_peso,
               f.calificacion_humedad,
               f.calidad_registro,
               f.presencia_defectos,
               f.clasificacion_compra,
               f.fermentacion_estado,
               f.secado_inicio,
               f.secado_fin,
               f.observaciones,
               f.created_at as ficha_creada,
               l.codigo as lote_codigo,
               l.fecha_entrada as lote_fecha_entrada,
               l.estado_proceso as lote_estado,
               p.nombre as proveedor_nombre,
               p.tipo as proveedor_tipo,
               v.nombre as variedad_nombre
        FROM fichas_registro f
        INNER JOIN lotes l ON f.lote_id = l.id
        LEFT JOIN proveedores p ON l.proveedor_id = p.id
        LEFT JOIN variedades v ON l.variedad_id = v.id
        WHERE f.lote_id = ?
        ORDER BY f.id DESC
        LIMIT 1
    ", [$loteId]);
}

$calificacionMap = [
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
];

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Consulta de Lote</title>
    <style>
        body {
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            margin: 0;
            background: #f5f7f9;
            color: #1f2937;
        }
        .wrap {
            max-width: 900px;
            margin: 24px auto;
            padding: 0 16px;
        }
        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        .title {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
        }
        .subtitle {
            margin-top: 6px;
            color: #4b5563;
            font-size: 14px;
        }
        .badge {
            display: inline-block;
            margin-top: 10px;
            background: #eef6ff;
            color: #1d4ed8;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
        }
        .warning {
            margin-top: 12px;
            background: #fff8db;
            border: 1px solid #f2d67c;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 600;
        }
        .grid {
            margin-top: 14px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .item {
            border: 1px solid #edf0f4;
            border-radius: 8px;
            padding: 10px;
            background: #fafbfc;
        }
        .item .label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        .item .value {
            font-size: 14px;
            font-weight: 600;
            color: #111827;
        }
        @media (max-width: 720px) {
            .grid { grid-template-columns: 1fr; }
            .title { font-size: 24px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <?php if (!$registro): ?>
            <h1 class="title">Consulta de Lote</h1>
            <p class="subtitle">No se encontro informacion para el codigo QR escaneado.</p>
        <?php else: ?>
            <?php
            $codigoVisual = trim((string)($registro['codificacion'] ?? ''));
            if ($codigoVisual === '') {
                $codigoVisual = (string)$registro['lote_codigo'];
            }
            $calificacionRaw = isset($registro['calificacion_humedad']) ? (int)$registro['calificacion_humedad'] : null;
            $calificacionTxt = $calificacionRaw === null ? '—' : ($calificacionMap[$calificacionRaw] ?? ($calificacionRaw . '%'));
            ?>
            <h1 class="title">Ficha de Lote: <?= htmlspecialchars($codigoVisual) ?></h1>
            <p class="subtitle">Lote base: <?= htmlspecialchars((string)$registro['lote_codigo']) ?> · Ficha #<?= (int)$registro['ficha_id'] ?></p>
            <span class="badge">Consulta de trazabilidad (sin datos comerciales)</span>
            <div class="warning">Informacion comercial restringida: este visor no muestra precios ni valores de compra.</div>

            <div class="grid">
                <div class="item"><div class="label">Proveedor</div><div class="value"><?= htmlspecialchars((string)($registro['proveedor_nombre'] ?? '—')) ?></div></div>
                <div class="item"><div class="label">Tipo de proveedor</div><div class="value"><?= htmlspecialchars((string)($registro['proveedor_tipo'] ?? '—')) ?></div></div>
                <div class="item"><div class="label">Variedad</div><div class="value"><?= htmlspecialchars((string)($registro['variedad_nombre'] ?? '—')) ?></div></div>
                <div class="item"><div class="label">Producto</div><div class="value"><?= htmlspecialchars((string)($registro['producto'] ?? '—')) ?></div></div>
                <div class="item"><div class="label">Fecha de entrada</div><div class="value"><?= !empty($registro['lote_fecha_entrada']) ? date('d/m/Y', strtotime($registro['lote_fecha_entrada'])) : '—' ?></div></div>
                <div class="item"><div class="label">Estado del lote</div><div class="value"><?= htmlspecialchars((string)($registro['lote_estado'] ?? '—')) ?></div></div>
                <div class="item"><div class="label">Tipo de entrega</div><div class="value"><?= htmlspecialchars((string)($registro['tipo_entrega'] ?? '—')) ?></div></div>
                <div class="item"><div class="label">Calificacion aparente</div><div class="value"><?= htmlspecialchars((string)$calificacionTxt) ?></div></div>
                <div class="item"><div class="label">Calidad del registro</div><div class="value"><?= htmlspecialchars((string)($registro['calidad_registro'] ?? '—')) ?></div></div>
                <div class="item"><div class="label">Clasificacion de compra</div><div class="value"><?= htmlspecialchars((string)($registro['clasificacion_compra'] ?? '—')) ?></div></div>
                <div class="item"><div class="label">Peso bruto</div><div class="value"><?= isset($registro['peso_bruto']) ? number_format((float)$registro['peso_bruto'], 2) . ' ' . htmlspecialchars((string)($registro['unidad_peso'] ?? 'KG')) : '—' ?></div></div>
                <div class="item"><div class="label">Tara de envase</div><div class="value"><?= isset($registro['tara_envase']) ? number_format((float)$registro['tara_envase'], 2) . ' ' . htmlspecialchars((string)($registro['unidad_peso'] ?? 'KG')) : '—' ?></div></div>
                <div class="item"><div class="label">Peso final</div><div class="value"><?= isset($registro['peso_final_registro']) ? number_format((float)$registro['peso_final_registro'], 2) . ' ' . htmlspecialchars((string)($registro['unidad_peso'] ?? 'KG')) : '—' ?></div></div>
                <div class="item"><div class="label">Defectos visibles</div><div class="value"><?= isset($registro['presencia_defectos']) ? number_format((float)$registro['presencia_defectos'], 2) . '%' : '—' ?></div></div>
                <div class="item"><div class="label">Revision de limpieza</div><div class="value"><?= htmlspecialchars((string)($registro['revision_limpieza'] ?? '—')) ?></div></div>
                <div class="item"><div class="label">Revision de olor normal</div><div class="value"><?= htmlspecialchars((string)($registro['revision_olor_normal'] ?? '—')) ?></div></div>
                <div class="item"><div class="label">Revision de ausencia de moho</div><div class="value"><?= htmlspecialchars((string)($registro['revision_ausencia_moho'] ?? '—')) ?></div></div>
                <div class="item"><div class="label">Estado de fermentacion</div><div class="value"><?= htmlspecialchars((string)($registro['fermentacion_estado'] ?? '—')) ?></div></div>
                <div class="item"><div class="label">Secado inicio</div><div class="value"><?= !empty($registro['secado_inicio']) ? date('d/m/Y H:i', strtotime($registro['secado_inicio'])) : '—' ?></div></div>
                <div class="item"><div class="label">Secado fin</div><div class="value"><?= !empty($registro['secado_fin']) ? date('d/m/Y H:i', strtotime($registro['secado_fin'])) : '—' ?></div></div>
            </div>

            <?php if (!empty($registro['observaciones'])): ?>
                <div class="warning" style="margin-top:16px;">
                    Observaciones: <?= nl2br(htmlspecialchars((string)$registro['observaciones'])) ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
