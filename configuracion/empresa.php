<?php
/**
 * MEGABLESSING - Configuración de Empresa/Logo
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

if (!Auth::isAdmin() && !Auth::hasRole('Supervisor') && !Auth::hasPermission('configuracion')) {
    setFlash('danger', 'No tiene permisos para acceder a esta sección.');
    redirect('/dashboard.php');
}

$db = Database::getInstance();

$empresaCols = Helpers::getTableColumns('empresa');
$hasEmpresaCol = static fn(string $name): bool => in_array($name, $empresaCols, true);
$hasEmpresaTable = !empty($empresaCols);
$hasEmpresaIdCol = $hasEmpresaCol('id');
$hasEmpresaNombreCol = $hasEmpresaCol('nombre');
$hasEmpresaLogoCol = $hasEmpresaCol('logo');

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

$selectEmpresa = static function () use ($db, $hasEmpresaTable, $hasEmpresaIdCol, $hasEmpresaNombreCol, $hasEmpresaLogoCol): ?array {
    if (!$hasEmpresaTable) {
        return null;
    }

    $selectCols = [];
    if ($hasEmpresaIdCol) {
        $selectCols[] = 'id';
    }
    if ($hasEmpresaNombreCol) {
        $selectCols[] = 'nombre';
    }
    if ($hasEmpresaLogoCol) {
        $selectCols[] = 'logo';
    }

    if (empty($selectCols)) {
        return null;
    }

    $sql = "SELECT " . implode(', ', $selectCols) . " FROM empresa";
    if ($hasEmpresaIdCol) {
        $sql .= " ORDER BY id ASC";
    }
    $sql .= " LIMIT 1";

    return $db->fetch($sql) ?: null;
};

$empresa = $selectEmpresa();

if (!$empresa && $hasEmpresaTable && $hasEmpresaNombreCol) {
    $insertData = ['nombre' => 'MEGABLESSING'];
    if ($hasEmpresaLogoCol) {
        $insertData['logo'] = null;
    }

    try {
        $db->insert('empresa', $insertData);
        $empresa = $selectEmpresa();
    } catch (Throwable $e) {
        // Compatibilidad: mantener la página funcional aunque no se pueda crear el registro base.
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_logo'])) {
    validateCsrf();

    $destPath = '';
    $logoGuardado = false;

    try {
        if (!$hasEmpresaTable) {
            throw new RuntimeException('La tabla empresa no existe en la base de datos.');
        }
        if (!$hasEmpresaLogoCol) {
            throw new RuntimeException('La columna logo no existe en la tabla empresa.');
        }
        if (!isset($_FILES['logo'])) {
            throw new RuntimeException('No se recibió ningún archivo.');
        }

        $uploadError = (int)($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            if ($uploadError === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Seleccione una imagen para continuar.');
            }
            throw new RuntimeException('Error al subir el archivo. Verifique e intente nuevamente.');
        }

        if (!is_uploaded_file($_FILES['logo']['tmp_name'])) {
            throw new RuntimeException('Archivo inválido.');
        }

        $maxBytes = defined('MAX_FILE_SIZE') ? (int)MAX_FILE_SIZE : 5 * 1024 * 1024;
        if ((int)$_FILES['logo']['size'] > $maxBytes) {
            throw new RuntimeException('El logo supera el tamaño máximo permitido.');
        }

        $imageInfo = @getimagesize($_FILES['logo']['tmp_name']);
        $mime = strtolower((string)($imageInfo['mime'] ?? ''));
        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Formato no permitido. Use PNG, JPG o WEBP.');
        }

        $logosDir = rtrim((string)UPLOADS_PATH, '/') . '/logos';
        if (!is_dir($logosDir) && !mkdir($logosDir, 0755, true) && !is_dir($logosDir)) {
            throw new RuntimeException('No se pudo crear la carpeta de logos.');
        }

        $filename = 'logo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        $destPath = $logosDir . '/' . $filename;
        if (!move_uploaded_file($_FILES['logo']['tmp_name'], $destPath)) {
            throw new RuntimeException('No se pudo guardar el archivo de logo.');
        }

        $logoRelPath = 'uploads/logos/' . $filename;
        $oldLogo = trim((string)($empresa['logo'] ?? ''));

        if ($empresa) {
            if ($hasEmpresaIdCol && isset($empresa['id'])) {
                $db->update('empresa', ['logo' => $logoRelPath], 'id = :id', ['id' => (int)$empresa['id']]);
            } else {
                $db->query("UPDATE empresa SET logo = ? LIMIT 1", [$logoRelPath]);
            }
        } else {
            $insertData = ['logo' => $logoRelPath];
            if ($hasEmpresaNombreCol) {
                $insertData['nombre'] = 'MEGABLESSING';
            }
            $db->insert('empresa', $insertData);
        }

        $empresa = $selectEmpresa();
        $logoGuardado = true;

        if ($oldLogo !== '' && $oldLogo !== $logoRelPath && str_starts_with($oldLogo, 'uploads/logos/')) {
            $oldPath = BASE_PATH . '/' . ltrim($oldLogo, '/');
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        setFlash('success', 'Logo actualizado correctamente.');
    } catch (Throwable $e) {
        if (!$logoGuardado && $destPath !== '' && is_file($destPath)) {
            @unlink($destPath);
        }
        setFlash('danger', $e->getMessage());
    }

    redirect('/configuracion/empresa.php');
}

$logoPath = trim((string)($empresa['logo'] ?? ''));
$logoUrl = $buildPublicUrl($logoPath);

$pageTitle = 'Empresa y Logo';
$pageSubtitle = 'Configuración visual para etiquetas y reportes PDF';

ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="card">
        <div class="card-body flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div>
                <h2 class="text-xl font-semibold text-gray-900">Logo institucional</h2>
                <p class="text-warmgray mt-2">
                    Este logo se usa en la impresión de etiquetas y en los reportes PDF del sistema.
                </p>
                <div class="mt-3 text-sm text-warmgray">
                    Ruta actual: <code><?= htmlspecialchars($logoPath !== '' ? $logoPath : 'Sin logo configurado') ?></code>
                </div>
            </div>

            <div class="bg-white border border-gray-200 rounded-lg px-4 py-3 min-w-[210px] min-h-[90px] flex items-center justify-center">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo actual" class="max-h-20 object-contain">
                <?php else: ?>
                    <span class="text-gray-500 text-sm">Sin logo cargado</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$hasEmpresaTable): ?>
        <div class="alert alert-warning">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>No existe la tabla <code>empresa</code>. Ejecute el script de base de datos correspondiente para habilitar esta configuración.</span>
        </div>
    <?php elseif (!$hasEmpresaLogoCol): ?>
        <div class="alert alert-warning">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>La columna <code>empresa.logo</code> no existe. Ejecute el patch de estructura para habilitar la carga de logo.</span>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M12 4v12m0 0l-3-3m3 3l3-3"/>
                    </svg>
                    Actualizar logo
                </h3>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <?= csrfField('csrf_token') ?>
                    <input type="hidden" name="subir_logo" value="1">

                    <div class="form-group">
                        <label class="form-label required">Archivo de imagen</label>
                        <input type="file"
                               name="logo"
                               class="form-control"
                               accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                               required>
                        <p class="text-xs text-gray-500 mt-1">
                            Formatos permitidos: PNG, JPG, WEBP. Tamaño máximo:
                            <?= number_format((defined('MAX_FILE_SIZE') ? (int)MAX_FILE_SIZE : 5 * 1024 * 1024) / 1024 / 1024, 0) ?> MB.
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="submit" class="btn btn-primary">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M12 4v12m0 0l-3-3m3 3l3-3"/>
                            </svg>
                            Guardar logo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
