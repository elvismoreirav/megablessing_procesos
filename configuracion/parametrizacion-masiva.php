<?php
/**
 * Concentrador de parametrizacion masiva.
 */

require_once __DIR__ . '/../bootstrap.php';

requireAuth();
if (!ConfigBulkImportRegistry::canAccessAnyModule()) {
    setFlash('danger', 'No tiene permisos para acceder a esta seccion.');
    redirect('/dashboard.php');
}

$availableModules = ConfigBulkImportRegistry::accessible();
$defaultModuleKey = ConfigBulkImportRegistry::firstAccessibleKey();
$requestedModuleKey = trim((string) ($_GET['modulo'] ?? ''));

if ($requestedModuleKey !== '' && !isset($availableModules[$requestedModuleKey])) {
    setFlash('warning', 'El modulo solicitado no esta disponible para su perfil.');
    redirect('/configuracion/parametrizacion-masiva.php');
}

$activeModuleKey = $requestedModuleKey !== '' ? $requestedModuleKey : (string) $defaultModuleKey;
$activeModule = $availableModules[$activeModuleKey];
$errors = [];
$result = null;
$fileName = '';

try {
    $importer = ConfigBulkImportRegistry::createImporter($activeModuleKey);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
    $importer = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $importer !== null) {
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
            $result = $importer->importFromXlsx($tmpFile);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Parametrizacion Masiva';
$pageSubtitle = 'Carga masiva agrupada de catalogos desde plantillas XLSX';
$contextStats = ($importer !== null && method_exists($importer, 'getContextStats'))
    ? $importer->getContextStats()
    : [];
$downloadTemplateUrl = APP_URL . '/configuracion/parametrizacion-masiva-plantilla.php?modulo=' . urlencode($activeModuleKey);
$modulePageUrl = APP_URL . '/configuracion/parametrizacion-masiva.php?modulo=';

ob_start();
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center gap-2 text-sm text-warmgray">
        <a href="<?= APP_URL ?>/configuracion/index.php" class="hover:text-primary">Configuracion</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        <span class="text-primary font-medium">Parametrizacion masiva</span>
    </div>

    <div class="card">
        <div class="card-body space-y-5">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">
                <div class="space-y-3">
                    <h2 class="text-xl font-semibold text-gray-900">Parametrizacion masiva agrupada</h2>
                    <p class="text-gray-600 max-w-3xl">
                        Desde aqui puede cargar en bloque las parametrizaciones maestras del sistema usando plantillas Excel.
                        Cada modulo valida duplicados, muestra registros creados, filas repetidas, errores y filas en blanco ignoradas.
                    </p>
                </div>
                <a href="<?= APP_URL ?>/configuracion/index.php" class="btn btn-outline">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Volver a configuracion
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
                <?php foreach ($availableModules as $module): ?>
                    <?php $isSelected = $module['key'] === $activeModuleKey; ?>
                    <a
                        href="<?= htmlspecialchars($modulePageUrl . urlencode($module['key'])) ?>"
                        class="rounded-2xl border p-4 transition-all <?= $isSelected ? 'border-primary bg-primary/5 shadow-sm' : 'border-gray-200 bg-white hover:border-primary/40 hover:shadow-sm' ?>"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold <?= $isSelected ? 'text-primary' : 'text-gray-900' ?>">
                                    <?= htmlspecialchars($module['label']) ?>
                                </p>
                                <p class="mt-2 text-sm text-gray-600">
                                    <?= htmlspecialchars($module['short_description']) ?>
                                </p>
                            </div>
                            <?php if ($isSelected): ?>
                                <span class="inline-flex px-2 py-1 rounded-full bg-primary text-white text-xs font-medium">Activo</span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
                <div class="space-y-3">
                    <h2 class="text-xl font-semibold text-gray-900"><?= htmlspecialchars($activeModule['title']) ?></h2>
                    <p class="text-gray-600 max-w-3xl"><?= htmlspecialchars($activeModule['description']) ?></p>
                    <?php if (!empty($contextStats)): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                            <?php foreach ($contextStats as $stat): ?>
                                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                                    <p class="text-sm font-medium text-slate-600"><?= htmlspecialchars((string) ($stat['label'] ?? 'Dato')) ?></p>
                                    <p class="text-2xl font-bold text-slate-900 mt-1"><?= htmlspecialchars((string) ($stat['value'] ?? '-')) ?></p>
                                    <p class="text-sm text-slate-500 mt-2"><?= htmlspecialchars((string) ($stat['description'] ?? '')) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="flex flex-col sm:flex-row lg:flex-col gap-3 lg:min-w-[260px]">
                    <a href="<?= htmlspecialchars($downloadTemplateUrl) ?>" class="btn btn-outline">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v10m0 0l-4-4m4 4l4-4m6 6v2a2 2 0 01-2 2H4a2 2 0 01-2-2v-2"/>
                        </svg>
                        Descargar plantilla
                    </a>
                    <a href="<?= htmlspecialchars($activeModule['target_path']) ?>" class="btn btn-primary">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        <?= htmlspecialchars($activeModule['target_label']) ?>
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
                <p class="font-medium">No se pudo procesar la parametrizacion masiva.</p>
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
            <form method="POST" enctype="multipart/form-data" action="<?= htmlspecialchars($modulePageUrl . urlencode($activeModuleKey)) ?>" class="space-y-6">
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
                        <p class="form-hint">
                            Use la plantilla de <?= htmlspecialchars(strtolower($activeModule['label'])) ?>. Tamano maximo:
                            <?= number_format(MAX_FILE_SIZE / 1024 / 1024, 0) ?> MB.
                        </p>
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
                <p class="text-white/70 text-sm mt-2">Registros creados correctamente</p>
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
                    <p class="font-medium">Parametrizacion completada.</p>
                    <p class="mt-1">
                        Se registraron <?= number_format($result['totals']['created']) ?> nuevo(s) <?= htmlspecialchars($activeModule['entity_plural']) ?>.
                        Revise abajo creados, repetidos y errores.
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <p class="font-medium">No se registraron datos nuevos.</p>
                    <p class="mt-1">Revise los repetidos y errores antes de volver a cargar el archivo.</p>
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
                        <?= htmlspecialchars($activeModule['created_title']) ?>
                    </h3>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <?php foreach ($activeModule['created_columns'] as $column): ?>
                                    <th><?= htmlspecialchars($column['label']) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['created'] as $createdItem): ?>
                                <tr>
                                    <?php foreach ($activeModule['created_columns'] as $column): ?>
                                        <?php
                                        $value = $createdItem[$column['key']] ?? '-';
                                        if ($column['key'] === 'row_number') {
                                            $value = (int) $value;
                                        }
                                        ?>
                                        <td><?= htmlspecialchars((string) $value) ?></td>
                                    <?php endforeach; ?>
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
