<?php
/**
 * MEGABLESSING - Ticket de Compra en Recepción
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

$fichaId = intval($_GET['id'] ?? 0);
if ($fichaId <= 0) {
    setFlash('error', 'Debe seleccionar una ficha válida para imprimir el ticket de compra.');
    redirect('/fichas/index.php?vista=recepcion');
}

$colsProveedores = Helpers::getTableColumns('proveedores');
$hasProveedorCol = static fn(string $name): bool => in_array($name, $colsProveedores, true);
$codigoProveedorExpr = $hasProveedorCol('codigo')
    ? 'p.codigo'
    : 'NULL';

$ficha = $db->fetchOne("
    SELECT f.*,
           l.codigo as lote_codigo,
           l.proveedor_id as lote_proveedor_id,
           {$codigoProveedorExpr} as proveedor_codigo_principal,
           p.nombre as proveedor_nombre_principal,
           v.nombre as variedad_nombre
    FROM fichas_registro f
    LEFT JOIN lotes l ON f.lote_id = l.id
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    WHERE f.id = ?
", [$fichaId]);

if (!$ficha) {
    setFlash('error', 'No se encontró la ficha solicitada.');
    redirect('/fichas/index.php?vista=recepcion');
}

$empresaCols = Helpers::getTableColumns('empresa');
$hasEmpresaCol = static fn(string $name): bool => in_array($name, $empresaCols, true);
$empresa = null;
if (!empty($empresaCols)) {
    $selectCols = ['id'];
    if ($hasEmpresaCol('nombre')) {
        $selectCols[] = 'nombre';
    }
    if ($hasEmpresaCol('logo')) {
        $selectCols[] = 'logo';
    }
    $empresa = $db->fetch("SELECT " . implode(', ', $selectCols) . " FROM empresa ORDER BY id ASC LIMIT 1");
}

$buildPublicUrl = static function (string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) || str_starts_with($path, 'data:image/')) {
        return $path;
    }
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
};

$empresaNombre = trim((string)($empresa['nombre'] ?? 'Megablessing'));
$logoUrl = $buildPublicUrl(trim((string)($empresa['logo'] ?? '')));

$tipoEntregaLabels = [
    'RUTAS' => 'Rutas',
    'COMERCIANTE' => 'Comerciante',
    'ENTREGA_INDIVIDUAL' => 'Entrega Individual',
];
$tipoEntregaTexto = $tipoEntregaLabels[$ficha['tipo_entrega'] ?? ''] ?? 'No definido';
$fechaTicket = trim((string)($ficha['fecha_entrada'] ?? ''));
if ($fechaTicket === '') {
    $fechaTicket = trim((string)($ficha['created_at'] ?? ''));
}
$fechaTicketTexto = $fechaTicket !== '' ? date('d/m/Y', strtotime($fechaTicket)) : '—';

$numeroOrden = str_pad((string)$fichaId, 6, '0', STR_PAD_LEFT);
$unidadPeso = Helpers::normalizePesoUnit($ficha['unidad_peso'] ?? 'KG');
$pesoValor = isset($ficha['peso_final_registro']) && is_numeric($ficha['peso_final_registro'])
    ? (float)$ficha['peso_final_registro']
    : null;
$pesoTexto = $pesoValor !== null && $pesoValor > 0
    ? number_format($pesoValor, 2) . ' ' . $unidadPeso
    : '—';
$calidadAsignada = trim((string)($ficha['calidad_asignada'] ?? ''));
if ($calidadAsignada === '') {
    $calidadAsignada = trim((string)($ficha['clasificacion_compra'] ?? ''));
}
$precioSugerido = isset($ficha['precio_base_dia']) && $ficha['precio_base_dia'] !== null
    ? (float)$ficha['precio_base_dia']
    : null;

$proveedoresCatalogo = [];
if ($hasProveedorCol('codigo')) {
    $proveedoresCatalogo = $db->fetchAll("
        SELECT id, codigo, nombre
        FROM proveedores
        ORDER BY nombre
    ");
}

$proveedoresTicket = [];
$proveedoresCompuestos = Helpers::parseProveedorRutaCompuesta((string)($ficha['proveedor_ruta'] ?? ''));
$proveedoresTexto = $proveedoresCompuestos['proveedores'] ?? [];
$rutaEntregaTexto = trim((string)($proveedoresCompuestos['ruta'] ?? ''));

if (!empty($proveedoresCatalogo)) {
    $catalogoPorNombre = [];
    foreach ($proveedoresCatalogo as $proveedor) {
        $nombreProveedor = trim((string)($proveedor['nombre'] ?? ''));
        if ($nombreProveedor === '') {
            continue;
        }
        $catalogoPorNombre[function_exists('mb_strtolower') ? mb_strtolower($nombreProveedor, 'UTF-8') : strtolower($nombreProveedor)] = [
            'codigo' => trim((string)($proveedor['codigo'] ?? '')),
            'nombre' => $nombreProveedor,
        ];
    }

    foreach ($proveedoresTexto as $proveedorTexto) {
        $clave = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$proveedorTexto), 'UTF-8') : strtolower(trim((string)$proveedorTexto));
        if ($clave === '' || !isset($catalogoPorNombre[$clave])) {
            continue;
        }
        $match = $catalogoPorNombre[$clave];
        $codigo = trim((string)($match['codigo'] ?? ''));
        $nombre = trim((string)($match['nombre'] ?? ''));
        $etiqueta = $codigo !== '' ? $codigo . ' - ' . $nombre : $nombre;
        if ($etiqueta !== '' && !in_array($etiqueta, $proveedoresTicket, true)) {
            $proveedoresTicket[] = $etiqueta;
        }
    }
}

if (empty($proveedoresTicket)) {
    $codigoPrincipal = trim((string)($ficha['proveedor_codigo_principal'] ?? ''));
    $nombrePrincipal = trim((string)($ficha['proveedor_nombre_principal'] ?? ''));
    if ($nombrePrincipal !== '') {
        $proveedoresTicket[] = $codigoPrincipal !== '' ? $codigoPrincipal . ' - ' . $nombrePrincipal : $nombrePrincipal;
    }
}

if (empty($proveedoresTicket) && !empty($proveedoresTexto)) {
    $proveedoresTicket = array_values(array_filter(array_map(
        static fn(string $item): string => trim($item),
        $proveedoresTexto
    )));
}

$codigoProveedorTexto = '—';
if (!empty($proveedoresTicket)) {
    $codigos = [];
    foreach ($proveedoresTicket as $proveedorTicket) {
        $partes = explode(' - ', $proveedorTicket, 2);
        $codigo = trim((string)($partes[0] ?? ''));
        if ($codigo !== '' && $codigo !== $proveedorTicket) {
            $codigos[] = $codigo;
        }
    }
    if (!empty($codigos)) {
        $codigoProveedorTexto = implode(', ', array_values(array_unique($codigos)));
    } else {
        $codigoProveedorTexto = implode(', ', $proveedoresTicket);
    }
}

$proveedorDetalleTexto = !empty($proveedoresTicket)
    ? implode(', ', $proveedoresTicket)
    : trim((string)($ficha['proveedor_ruta'] ?? ($ficha['proveedor_nombre_principal'] ?? '—')));

$pageTitle = 'Ticket de Compra - Ficha #' . $fichaId;
ob_start();
?>

<div class="max-w-3xl mx-auto space-y-6 ticket-compra-page">
    <div class="flex items-center justify-between print:hidden">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Ticket de Compra</h1>
            <p class="text-gray-600">Ficha #<?= (int)$fichaId ?> · Orden <?= htmlspecialchars($numeroOrden) ?></p>
        </div>
        <div class="flex items-center gap-3">
            <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">
                <i class="fas fa-print mr-2"></i>Imprimir
            </button>
            <a href="<?= APP_URL ?>/fichas/ver.php?id=<?= (int)$fichaId ?>" class="text-amber-600 hover:text-amber-700">
                <i class="fas fa-arrow-left mr-2"></i>Volver
            </a>
        </div>
    </div>

    <div class="mx-auto bg-white rounded-2xl shadow-sm border border-gray-200 ticket-wrap">
        <div class="border-b border-dashed border-gray-300 px-6 py-5 text-center">
            <?php if ($logoUrl !== ''): ?>
            <img src="<?= htmlspecialchars($logoUrl) ?>"
                 alt="<?= htmlspecialchars($empresaNombre) ?>"
                 class="mx-auto h-14 object-contain mb-3"
                 loading="eager"
                 decoding="sync">
            <?php endif; ?>
            <p class="text-sm uppercase tracking-[0.35em] text-gray-500">Recepción</p>
            <h2 class="text-2xl font-black text-gray-900 mt-2">TICKET DE COMPRA</h2>
            <p class="text-sm text-gray-600 mt-2"><?= htmlspecialchars($empresaNombre) ?></p>
        </div>

        <div class="px-6 py-5 space-y-5">
            <div class="grid grid-cols-2 gap-4">
                <div class="rounded-xl bg-amber-50 border border-amber-100 px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Nro. de orden</p>
                    <p class="text-lg font-bold text-gray-900"><?= htmlspecialchars($numeroOrden) ?></p>
                </div>
                <div class="rounded-xl bg-amber-50 border border-amber-100 px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Fecha</p>
                    <p class="text-lg font-bold text-gray-900"><?= htmlspecialchars($fechaTicketTexto) ?></p>
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-start justify-between gap-4 border-b border-dashed border-gray-200 pb-3">
                    <span class="text-sm text-gray-500">Cod. proveedor</span>
                    <span class="text-right text-sm font-semibold text-gray-900"><?= htmlspecialchars($codigoProveedorTexto) ?></span>
                </div>
                <div class="flex items-start justify-between gap-4 border-b border-dashed border-gray-200 pb-3">
                    <span class="text-sm text-gray-500">Proveedor(es)</span>
                    <span class="text-right text-sm font-semibold text-gray-900"><?= htmlspecialchars($proveedorDetalleTexto !== '' ? $proveedorDetalleTexto : '—') ?></span>
                </div>
                <div class="flex items-start justify-between gap-4 border-b border-dashed border-gray-200 pb-3">
                    <span class="text-sm text-gray-500">Tipo de entrega</span>
                    <span class="text-right text-sm font-semibold text-gray-900"><?= htmlspecialchars($tipoEntregaTexto) ?></span>
                </div>
                <?php if ($rutaEntregaTexto !== '' && strtoupper($rutaEntregaTexto) !== 'NO APLICA'): ?>
                <div class="flex items-start justify-between gap-4 border-b border-dashed border-gray-200 pb-3">
                    <span class="text-sm text-gray-500">Ruta</span>
                    <span class="text-right text-sm font-semibold text-gray-900"><?= htmlspecialchars($rutaEntregaTexto) ?></span>
                </div>
                <?php endif; ?>
                <div class="flex items-start justify-between gap-4 border-b border-dashed border-gray-200 pb-3">
                    <span class="text-sm text-gray-500">Producto</span>
                    <span class="text-right text-sm font-semibold text-gray-900"><?= htmlspecialchars((string)($ficha['producto'] ?? '—')) ?></span>
                </div>
                <div class="flex items-start justify-between gap-4 border-b border-dashed border-gray-200 pb-3">
                    <span class="text-sm text-gray-500">Variedad</span>
                    <span class="text-right text-sm font-semibold text-gray-900"><?= htmlspecialchars((string)($ficha['variedad_nombre'] ?? '—')) ?></span>
                </div>
                <div class="flex items-start justify-between gap-4 border-b border-dashed border-gray-200 pb-3">
                    <span class="text-sm text-gray-500">Peso</span>
                    <span class="text-right text-sm font-semibold text-gray-900"><?= htmlspecialchars($pesoTexto) ?></span>
                </div>
                <div class="flex items-start justify-between gap-4 border-b border-dashed border-gray-200 pb-3">
                    <span class="text-sm text-gray-500">Calidad asignada</span>
                    <span class="text-right text-sm font-semibold text-gray-900"><?= htmlspecialchars($calidadAsignada !== '' ? $calidadAsignada : '—') ?></span>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <span class="text-sm text-gray-500">Precio sugerido</span>
                    <span class="text-right text-base font-bold text-emerald-700">
                        <?= $precioSugerido !== null ? '$ ' . number_format($precioSugerido, 4) . ' /kg' : '—' ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="border-t border-dashed border-gray-300 px-6 py-4 text-center">
            <p class="text-xs text-gray-500">Documento generado en recepción para entrega en caja.</p>
        </div>
    </div>
</div>

<style>
.ticket-wrap {
    max-width: 92mm;
}

@media print {
    @page {
        size: 80mm auto;
        margin: 4mm;
    }

    html, body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    body * {
        visibility: hidden !important;
    }

    .ticket-compra-page,
    .ticket-compra-page * {
        visibility: visible !important;
    }

    .ticket-compra-page {
        position: fixed !important;
        inset: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        max-width: none !important;
    }

    .print\\:hidden {
        display: none !important;
    }

    .ticket-wrap {
        margin: 0 auto !important;
        box-shadow: none !important;
        border: none !important;
        border-radius: 0 !important;
        max-width: 72mm !important;
        width: 72mm !important;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
