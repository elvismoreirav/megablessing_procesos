<?php
/**
 * Carga masiva de lotes desde archivo XLSX.
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$errors = [];
$result = null;
$fileName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();

    $fileName = trim((string) ($_FILES['archivo_xlsx']['name'] ?? ''));
    $uploadError = (int) ($_FILES['archivo_xlsx']['error'] ?? UPLOAD_ERR_NO_FILE);
    $tmpFile = (string) ($_FILES['archivo_xlsx']['tmp_name'] ?? '');
    $size = (int) ($_FILES['archivo_xlsx']['size'] ?? 0);

    if ($uploadError !== UPLOAD_ERR_OK) {
        $errors[] = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamano maximo permitido.',
            UPLOAD_ERR_PARTIAL => 'El archivo se subio de forma parcial. Intente nuevamente.',
            UPLOAD_ERR_NO_FILE => 'Seleccione un archivo .xlsx para continuar.',
            default => 'No se pudo recibir el archivo cargado.',
        };
    }

    if (empty($errors)) {
        if ($size <= 0) {
            $errors[] = 'El archivo seleccionado esta vacio.';
        } elseif ($size > MAX_FILE_SIZE) {
            $errors[] = 'El archivo supera el limite permitido de ' . number_format(MAX_FILE_SIZE / 1024 / 1024, 0) . ' MB.';
        }
    }

    if (empty($errors)) {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension !== 'xlsx') {
            $errors[] = 'Solo se admiten archivos con extension .xlsx.';
        }
    }

    if (empty($errors)) {
        try {
            $importer = new LoteBulkImporter();
            $result = $importer->importFromXlsx($tmpFile, (int) ($_SESSION['user_id'] ?? 0));
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Carga Masiva de Lotes';
$pageSubtitle = 'Importe multiples lotes desde una plantilla XLSX';

ob_start();
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center gap-2 text-sm text-warmgray">
        <a href="<?= APP_URL ?>/lotes/index.php" class="hover:text-primary">Lotes</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        <span class="text-primary font-medium">Carga masiva</span>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
                <div class="space-y-3">
                    <h2 class="text-xl font-semibold text-gray-900">Subir lotes desde Excel</h2>
                    <p class="text-gray-600 max-w-3xl">
                        Descargue la plantilla oficial, complete la hoja <strong>Lotes</strong> y suba el archivo para crear los registros.
                        El sistema valida catalogos, detecta filas repetidas y muestra el resumen completo de la importacion.
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-gray-600">
                        <div class="bg-amber-50 border border-amber-100 rounded-xl p-4">
                            <p class="font-medium text-amber-800">Campos obligatorios</p>
                            <p class="mt-1"><code>proveedor_codigo</code>, <code>variedad_codigo</code>, <code>estado_producto_codigo</code>, <code>fecha_entrada</code>, <code>peso_inicial_kg</code>.</p>
                        </div>
                        <div class="bg-blue-50 border border-blue-100 rounded-xl p-4">
                            <p class="font-medium text-blue-800">Deteccion de repetidos</p>
                            <p class="mt-1">Se comparan filas iguales dentro del archivo y tambien contra lotes ya existentes.</p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row lg:flex-col gap-3 lg:min-w-[240px]">
                    <a href="<?= APP_URL ?>/lotes/formato-carga-masiva.php" class="btn btn-outline">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v10m0 0l-4-4m4 4l4-4m6 6v2a2 2 0 01-2 2H4a2 2 0 01-2-2v-2"/>
                        </svg>
                        Descargar plantilla
                    </a>
                    <a href="<?= APP_URL ?>/lotes/index.php" class="btn btn-primary">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        Volver a lotes
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="font-medium">No se pudo procesar la carga.</p>
                <ul class="list-disc list-inside mt-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l-4-4m4 4l4-4"/>
                </svg>
                Formulario de carga
            </h3>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" class="space-y-6">
                <?= csrfField() ?>
                <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-4 items-end">
                    <div class="form-group">
                        <label class="form-label required">Archivo XLSX</label>
                        <input
                            type="file"
                            name="archivo_xlsx"
                            class="form-control"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            required
                        >
                        <p class="form-hint">Use la plantilla descargable. Tamano maximo: <?= number_format(MAX_FILE_SIZE / 1024 / 1024, 0) ?> MB.</p>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M8 12l4 4 4-4m-4-8v12"/>
                        </svg>
                        Procesar archivo
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($result !== null): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
            <div class="stat-card">
                <p class="text-white/80 text-sm">Filas procesadas</p>
                <p class="text-3xl font-bold mt-1"><?= number_format($result['totals']['processed']) ?></p>
                <p class="text-white/70 text-sm mt-2"><?= htmlspecialchars($fileName !== '' ? $fileName : 'Archivo cargado') ?></p>
            </div>
            <div class="stat-card accent">
                <p class="text-white/80 text-sm">Creados</p>
                <p class="text-3xl font-bold mt-1"><?= number_format($result['totals']['created']) ?></p>
                <p class="text-white/70 text-sm mt-2">Lotes insertados correctamente</p>
            </div>
            <div class="stat-card gold">
                <p class="text-primary-dark/80 text-sm">Repetidos</p>
                <p class="text-3xl font-bold mt-1"><?= number_format($result['totals']['duplicates']) ?></p>
                <p class="text-primary-dark/70 text-sm mt-2">Filas omitidas por coincidencia</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-red-100 p-4">
                <p class="text-sm text-red-500">Errores</p>
                <p class="text-3xl font-bold text-red-600 mt-1"><?= number_format($result['totals']['errors']) ?></p>
                <p class="text-sm text-red-400 mt-2">Filas con validaciones fallidas</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <p class="text-sm text-gray-500">Filas en blanco</p>
                <p class="text-3xl font-bold text-gray-700 mt-1"><?= number_format($result['totals']['blank_rows']) ?></p>
                <p class="text-sm text-gray-400 mt-2">Se ignoraron automaticamente</p>
            </div>
        </div>

        <?php if ($result['totals']['created'] > 0): ?>
            <div class="alert alert-success">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <p class="font-medium">Carga completada.</p>
                    <p class="mt-1">Se registraron <?= number_format($result['totals']['created']) ?> lotes nuevos. Revise abajo los creados, repetidos y errores detectados.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <p class="font-medium">No se registraron lotes nuevos.</p>
                    <p class="mt-1">Revise los apartados de repetidos y errores para corregir el archivo y volver a intentar.</p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($result['created'])): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <svg class="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Lotes creados
                    </h3>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Fila</th>
                                <th>Código</th>
                                <th>Proveedor</th>
                                <th>Variedad</th>
                                <th>Fecha</th>
                                <th>Peso (Kg)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['created'] as $createdItem): ?>
                                <tr>
                                    <td><?= (int) $createdItem['row_number'] ?></td>
                                    <td class="font-semibold text-primary"><?= htmlspecialchars($createdItem['codigo']) ?></td>
                                    <td><?= htmlspecialchars($createdItem['proveedor']) ?></td>
                                    <td><?= htmlspecialchars($createdItem['variedad']) ?></td>
                                    <td><?= Helpers::formatDate($createdItem['fecha_entrada']) ?></td>
                                    <td><?= number_format((float) $createdItem['peso_inicial_kg'], 2, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($result['duplicates'])): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5h2m-1 0v14m-7-4h14"/>
                        </svg>
                        Filas repetidas
                    </h3>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Fila</th>
                                <th>Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['duplicates'] as $duplicateItem): ?>
                                <tr>
                                    <td><?= (int) $duplicateItem['row_number'] ?></td>
                                    <td><?= htmlspecialchars($duplicateItem['detail']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($result['errors'])): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Errores encontrados
                    </h3>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Fila</th>
                                <th>Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['errors'] as $errorItem): ?>
                                <tr>
                                    <td><?= (int) $errorItem['row_number'] ?></td>
                                    <td><?= htmlspecialchars($errorItem['detail']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
