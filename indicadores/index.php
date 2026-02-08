<?php
/**
 * MEGABLESSING - Registro de Indicadores
 * Captura manual de KPIs
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

function formatIndicadorRegistroValor(?float $valor, ?string $unidad): string {
    if ($valor === null) {
        return 'N/D';
    }
    $unidad = trim((string)$unidad);
    $decimals = 2;
    if (in_array($unidad, ['%', 'g', 'minutos', 'horas', 'veces'], true)) {
        $decimals = 1;
    }
    if ($unidad === 'ratio') {
        $decimals = 2;
    }
    $formatted = number_format($valor, $decimals, ',', '.');
    if ($unidad === '') {
        return $formatted;
    }
    if ($unidad === '%') {
        return $formatted . '%';
    }
    return $formatted . ' ' . $unidad;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();

    $indicadorId = (int)($_POST['indicador_id'] ?? 0);
    $fecha = trim($_POST['fecha'] ?? '');
    $valor = trim($_POST['valor'] ?? '');
    $referencia = trim($_POST['referencia'] ?? '');
    $detalle = trim($_POST['detalle'] ?? '');

    if ($indicadorId <= 0) {
        $errors[] = 'Selecciona un indicador.';
    }
    if ($fecha === '') {
        $errors[] = 'La fecha es requerida.';
    }
    if ($valor === '' || !is_numeric(str_replace(',', '.', $valor))) {
        $errors[] = 'El valor debe ser numérico.';
    }

    if (empty($errors)) {
        $db->insert('indicadores_registros', [
            'indicador_id' => $indicadorId,
            'fecha' => $fecha,
            'valor' => (float)str_replace(',', '.', $valor),
            'referencia' => $referencia !== '' ? $referencia : null,
            'detalle' => $detalle !== '' ? $detalle : null,
            'usuario_id' => Auth::id()
        ]);

        setFlash('success', 'Registro de KPI guardado correctamente.');
        redirect('/indicadores/index.php');
    }
}

$indicadores = $db->fetchAll("
    SELECT id, etapa_proceso, nombre, unidad, frecuencia
    FROM indicadores
    WHERE activo = 1
    ORDER BY etapa_proceso, nombre
");

$registros = [];
try {
    $registros = $db->fetchAll("
        SELECT ir.*, i.nombre as indicador_nombre, i.etapa_proceso, i.unidad, u.nombre as usuario
        FROM indicadores_registros ir
        JOIN indicadores i ON ir.indicador_id = i.id
        LEFT JOIN usuarios u ON ir.usuario_id = u.id
        ORDER BY ir.fecha DESC, ir.created_at DESC
        LIMIT 20
    ");
} catch (Throwable $e) {
    $registros = [];
}

$pageTitle = 'Registro de Indicadores';
$pageSubtitle = 'Captura manual de KPIs del proceso';

ob_start();
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="card">
            <div class="card-header">
                <h3 class="font-semibold text-gray-900">Nuevo registro</h3>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger mb-4">
                        <ul class="list-disc list-inside text-sm text-red-700">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <?= csrfField() ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Indicador</label>
                        <select name="indicador_id" class="form-control form-select" required>
                            <option value="">Seleccionar indicador...</option>
                            <?php foreach ($indicadores as $indicador): ?>
                                <option value="<?= $indicador['id'] ?>" <?= (int)($_POST['indicador_id'] ?? 0) === (int)$indicador['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($indicador['etapa_proceso']) ?> · <?= htmlspecialchars($indicador['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Fecha</label>
                        <input type="date" name="fecha" class="form-control" value="<?= htmlspecialchars($_POST['fecha'] ?? date('Y-m-d')) ?>" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Valor</label>
                        <input type="text" name="valor" class="form-control" value="<?= htmlspecialchars($_POST['valor'] ?? '') ?>" placeholder="Ej: 70, 6.8, 0.95" required>
                        <p class="text-xs text-warmgray mt-1">Usa punto o coma para decimales.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Referencia (opcional)</label>
                        <input type="text" name="referencia" class="form-control" value="<?= htmlspecialchars($_POST['referencia'] ?? '') ?>" placeholder="Lote, embarque, muestra...">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Detalle (opcional)</label>
                        <textarea name="detalle" class="form-control" rows="3" placeholder="Observaciones o justificación"><?= htmlspecialchars($_POST['detalle'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-full">
                        Guardar KPI
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="card">
            <div class="card-header flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-gray-900">Últimos registros</h3>
                    <p class="text-sm text-warmgray">Se muestran los 20 más recientes.</p>
                </div>
                <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-sm btn-outline">Volver al dashboard</a>
            </div>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Etapa</th>
                            <th>Indicador</th>
                            <th>Valor</th>
                            <th>Referencia</th>
                            <th>Registrado por</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($registros)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-8 text-warmgray">No hay registros aún.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($registros as $registro): ?>
                                <tr class="hover:bg-ivory/50">
                                    <td><?= Helpers::formatDate($registro['fecha']) ?></td>
                                    <td class="font-medium"><?= htmlspecialchars($registro['etapa_proceso']) ?></td>
                                    <td><?= htmlspecialchars($registro['indicador_nombre']) ?></td>
                                    <td class="font-semibold">
                                        <?= htmlspecialchars(formatIndicadorRegistroValor((float)$registro['valor'], $registro['unidad'] ?? '')) ?>
                                    </td>
                                    <td><?= htmlspecialchars($registro['referencia'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($registro['usuario'] ?? 'Sistema') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
