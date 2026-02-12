<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Ver Empaquetado
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

if (!Helpers::ensureEmpaquetadoTable()) {
    setFlash('error', 'No se pudo habilitar el módulo de empaquetado en esta base de datos.');
    redirect('/calidad-salida/index.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    setFlash('error', 'ID de empaquetado no especificado');
    redirect('/empaquetado/index.php');
}

// Compatibilidad de esquema
$colsLotes = Helpers::getTableColumns('lotes');
$hasLoteCol = static fn(string $name): bool => in_array($name, $colsLotes, true);
$colsPrueba = Helpers::getTableColumns('registros_prueba_corte');
$hasPrCol = static fn(string $name): bool => in_array($name, $colsPrueba, true);
$colsSecado = Helpers::getTableColumns('registros_secado');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSecado, true);

$prCalidadExpr = 'NULL';
$joinPrueba = '';
if (!empty($colsPrueba)) {
    $prCalidadExpr = $hasPrCol('calidad_resultado')
        ? 'rpc.calidad_resultado'
        : ($hasPrCol('calidad_determinada') ? 'rpc.calidad_determinada' : ($hasPrCol('decision_lote') ? 'rpc.decision_lote' : 'NULL'));

    $joinPrueba = "
        LEFT JOIN registros_prueba_corte rpc ON rpc.id = (
            SELECT rpc2.id
            FROM registros_prueba_corte rpc2
            WHERE rpc2.lote_id = l.id
            ORDER BY rpc2.id DESC
            LIMIT 1
        )
    ";
}

$loteCalidadExpr = "COALESCE({$prCalidadExpr}, 'N/D')";

$pesoSecadoExpr = 'NULL';
$humedadSecadoExpr = 'NULL';
$joinSecado = '';
if (!empty($colsSecado)) {
    $pesoSecadoExpr = $hasSecCol('peso_final')
        ? 'rs.peso_final'
        : ($hasSecCol('qq_cargados')
            ? '(rs.qq_cargados * 45.3592)'
            : ($hasSecCol('cantidad_total_qq') ? '(rs.cantidad_total_qq * 45.3592)' : 'NULL'));
    $humedadSecadoExpr = $hasSecCol('humedad_final') ? 'rs.humedad_final' : 'NULL';
    $joinSecado = "LEFT JOIN registros_secado rs ON rs.lote_id = l.id";
}

$estadoLoteExpr = $hasLoteCol('estado_proceso') ? 'l.estado_proceso' : 'NULL';

$empaquetado = $db->fetch("
    SELECT re.*,
           l.id as lote_id_real,
           l.codigo as lote_codigo,
           {$estadoLoteExpr} as estado_lote,
           {$loteCalidadExpr} as calidad_final,
           p.nombre as proveedor,
           p.codigo as proveedor_codigo,
           v.nombre as variedad,
           {$pesoSecadoExpr} as peso_disponible,
           {$humedadSecadoExpr} as humedad,
           u.nombre as operador
    FROM registros_empaquetado re
    JOIN lotes l ON re.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    JOIN variedades v ON l.variedad_id = v.id
    LEFT JOIN usuarios u ON re.operador_id = u.id
    {$joinPrueba}
    {$joinSecado}
    WHERE re.id = :id
", ['id' => $id]);

if (!$empaquetado) {
    setFlash('error', 'Registro de empaquetado no encontrado');
    redirect('/empaquetado/index.php');
}

$generarCodigoEmpaque = static function (string $loteCodigo, int $registroId): string {
    $base = strtoupper(trim($loteCodigo));
    $base = preg_replace('/[^A-Z0-9-]/', '', $base);
    $base = preg_replace('/-+/', '-', (string)$base);
    $base = trim((string)$base, '-');
    if ($base === '') {
        $base = 'LOTE';
    }

    $sufijo = str_pad((string)$registroId, 4, '0', STR_PAD_LEFT);
    $maxBaseLen = 80 - strlen('EMP--') - strlen($sufijo);
    if (strlen($base) > $maxBaseLen) {
        $base = substr($base, 0, $maxBaseLen);
        $base = rtrim($base, '-');
    }

    return "EMP-{$base}-{$sufijo}";
};

$loteEmpaque = trim((string)($empaquetado['lote_empaque'] ?? ''));
if ($loteEmpaque === '') {
    $loteEmpaque = $generarCodigoEmpaque((string)($empaquetado['lote_codigo'] ?? ''), (int)$id);
}

$completado = !empty($empaquetado['fecha_empaquetado']);

$pageTitle = 'Detalle de Empaquetado';
$pageSubtitle = 'Lote: ' . $empaquetado['lote_codigo'];

ob_start();
?>

<?php if (!$completado): ?>
    <div class="alert alert-warning mb-6">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M12 2l10 18H2L12 2z"/>
        </svg>
        <div>
            <p class="font-medium">Empaquetado pendiente</p>
            <p>Este lote todavía no se ha completado. Continúe con el registro para finalizar el proceso.</p>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-6">
    <div class="card-body">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <p class="text-xs text-warmgray">Lote</p>
                <p class="font-bold text-primary text-lg"><?= htmlspecialchars((string)$empaquetado['lote_codigo']) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray">Lote de Empaque</p>
                <p class="font-semibold"><?= htmlspecialchars($loteEmpaque) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray">Estado</p>
                <?php if ($completado): ?>
                    <span class="badge badge-success">Completado</span>
                <?php else: ?>
                    <span class="badge badge-warning">Pendiente</span>
                <?php endif; ?>
            </div>
            <div>
                <p class="text-xs text-warmgray">Proveedor</p>
                <p class="font-medium"><?= htmlspecialchars((string)$empaquetado['proveedor']) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray">Variedad</p>
                <p class="font-medium"><?= htmlspecialchars((string)$empaquetado['variedad']) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray">Calidad</p>
                <?php
                $badgeClass = match ((string)($empaquetado['calidad_final'] ?? 'N/D')) {
                    'PREMIUM' => 'badge-success',
                    'EXPORTACION' => 'badge-primary',
                    'NACIONAL' => 'badge-gold',
                    default => 'badge-secondary'
                };
                ?>
                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars((string)($empaquetado['calidad_final'] ?? 'N/D')) ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card mb-6">
    <div class="card-header">
        <h3 class="card-title">Datos de Empaquetado</h3>
    </div>
    <div class="card-body">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <p class="text-xs text-warmgray">Tipo de empaque</p>
                <p class="font-medium"><?= htmlspecialchars((string)($empaquetado['tipo_empaque'] ?? 'N/R')) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray">Peso por saco</p>
                <p class="font-medium"><?= isset($empaquetado['peso_saco']) ? number_format((float)$empaquetado['peso_saco'], 2) . ' kg' : 'N/R' ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray">Operador</p>
                <p class="font-medium"><?= htmlspecialchars((string)($empaquetado['operador'] ?? 'N/R')) ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray">Fecha de empaquetado</p>
                <p class="font-medium"><?= $completado ? Helpers::formatDate($empaquetado['fecha_empaquetado']) : 'Pendiente' ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray">Número de sacos</p>
                <p class="font-medium"><?= $empaquetado['numero_sacos'] !== null ? (int)$empaquetado['numero_sacos'] : 'N/R' ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray">Peso total</p>
                <p class="font-medium"><?= $empaquetado['peso_total'] !== null ? number_format((float)$empaquetado['peso_total'], 2) . ' kg' : 'N/R' ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray">Peso disponible (secado)</p>
                <p class="font-medium"><?= $empaquetado['peso_disponible'] !== null ? number_format((float)$empaquetado['peso_disponible'], 2) . ' kg' : 'N/R' ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray">Humedad final</p>
                <p class="font-medium"><?= $empaquetado['humedad'] !== null ? number_format((float)$empaquetado['humedad'], 1) . '%' : 'N/R' ?></p>
            </div>
            <div>
                <p class="text-xs text-warmgray">Destino</p>
                <p class="font-medium"><?= htmlspecialchars((string)($empaquetado['destino'] ?? 'N/R')) ?></p>
            </div>
        </div>

        <div class="mt-6">
            <p class="text-xs text-warmgray">Observaciones</p>
            <p class="font-medium whitespace-pre-line"><?= htmlspecialchars((string)($empaquetado['observaciones'] ?? '')) ?></p>
        </div>
    </div>
</div>

<div class="flex items-center gap-4">
    <?php if (!$completado): ?>
        <a href="<?= APP_URL ?>/empaquetado/registrar.php?id=<?= (int)$empaquetado['id'] ?>" class="btn btn-primary">
            Continuar registro
        </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/empaquetado/index.php" class="btn btn-outline">Volver al listado</a>
    <a href="<?= APP_URL ?>/lotes/ver.php?id=<?= (int)$empaquetado['lote_id_real'] ?>" class="btn btn-outline">Ver lote</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
