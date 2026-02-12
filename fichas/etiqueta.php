<?php
/**
 * MEGABLESSING - Etiqueta de Lote (QR)
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

$fichaId = intval($_GET['id'] ?? 0);
$loteId = intval($_GET['lote_id'] ?? 0);

$ficha = null;
if ($fichaId > 0) {
    $ficha = $db->fetchOne("
        SELECT f.id, f.lote_id, f.codificacion, f.created_at,
               l.codigo as lote_codigo
        FROM fichas_registro f
        LEFT JOIN lotes l ON f.lote_id = l.id
        WHERE f.id = ?
    ", [$fichaId]);
} elseif ($loteId > 0) {
    $ficha = $db->fetchOne("
        SELECT f.id, f.lote_id, f.codificacion, f.created_at,
               l.codigo as lote_codigo
        FROM fichas_registro f
        LEFT JOIN lotes l ON f.lote_id = l.id
        WHERE f.lote_id = ?
        ORDER BY f.id DESC
        LIMIT 1
    ", [$loteId]);
}

if (!$ficha) {
    setFlash('error', 'No existe una ficha de recepcion para generar la etiqueta');
    if ($loteId > 0) {
        redirect('/fichas/crear.php?etapa=recepcion&lote_id=' . $loteId);
    }
    redirect('/fichas/index.php?vista=recepcion');
}

$codigoEtiqueta = trim((string)($ficha['codificacion'] ?? ''));
if ($codigoEtiqueta === '') {
    $codigoEtiqueta = trim((string)($ficha['lote_codigo'] ?? ''));
}
if ($codigoEtiqueta === '') {
    $codigoEtiqueta = 'SIN-CODIGO';
}

$urlConsulta = APP_URL . '/fichas/consulta.php?ficha_id=' . (int)$ficha['id'];
$qrImage = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=8&data=' . urlencode($urlConsulta);

$pageTitle = 'Etiqueta de Registro - ' . $codigoEtiqueta;
ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6 etiqueta-page">
    <div class="flex items-center justify-between print:hidden">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Imprimir Etiqueta (Etiquetado de registro)</h1>
            <p class="text-gray-600">Ficha #<?= (int)$ficha['id'] ?> · Lote <?= htmlspecialchars((string)($ficha['lote_codigo'] ?: 'Sin lote asignado')) ?></p>
        </div>
        <div class="flex items-center gap-3">
            <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">
                <i class="fas fa-print mr-2"></i>Imprimir
            </button>
            <a href="<?= APP_URL ?>/fichas/ver.php?id=<?= (int)$ficha['id'] ?>" class="text-amber-600 hover:text-amber-700">
                <i class="fas fa-arrow-left mr-2"></i>Volver
            </a>
        </div>
    </div>

    <div class="mx-auto bg-white rounded-xl shadow-sm border border-gray-200 p-6 etiqueta-wrap">
        <div class="text-center text-2xl font-bold text-gray-900 mb-5">ETIQUETA PARA IMPRIMIR:</div>

        <div class="border border-black">
            <div class="border-b border-black px-6 py-5 text-center">
                <div class="text-2xl font-bold tracking-wide">CODIGO DE LOTE: <?= htmlspecialchars($codigoEtiqueta) ?></div>
            </div>
            <div class="border-b border-black px-6 py-6 flex items-center justify-center">
                    <img src="<?= htmlspecialchars($qrImage) ?>"
                         alt="QR lote <?= htmlspecialchars($codigoEtiqueta) ?>"
                         class="w-56 h-56 object-contain"
                         loading="eager"
                         decoding="sync">
            </div>
            <div class="px-6 py-5 text-center">
                <div class="inline-flex items-center gap-2">
                    <span class="text-4xl text-red-600 leading-none">*</span>
                    <span class="text-5xl font-semibold text-gray-800 tracking-tight">megaBlessing</span>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-amber-400/90 rounded-xl p-4 text-gray-900 font-semibold leading-tight print:hidden">
        AL ESCANEAR EL QR, SE MOSTRARA LA INFORMACION DE LA FICHA DE LOTE, PERO SIN LA INFORMACION DE PRECIO COMERCIAL.
    </div>
</div>

<style>
.etiqueta-wrap {
    max-width: 820px;
}
@media print {
    @page {
        size: A4 landscape;
        margin: 8mm;
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

    .etiqueta-page,
    .etiqueta-page * {
        visibility: visible !important;
    }

    .etiqueta-page {
        position: fixed !important;
        inset: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        max-width: none !important;
    }

    .print\\:hidden { display: none !important; }

    .etiqueta-wrap {
        margin: 0 auto !important;
        box-shadow: none !important;
        border: none !important;
        padding: 0 !important;
        max-width: 100% !important;
        page-break-inside: avoid;
        break-inside: avoid;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
