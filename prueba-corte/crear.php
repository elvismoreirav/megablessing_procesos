<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Registrar Prueba de Corte
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();
$errors = [];
$tablaCalidadSalidaExiste = (bool)$db->fetch("SHOW TABLES LIKE 'registros_calidad_salida'");

// Compatibilidad de esquema para prueba de corte
$colsPrueba = array_column($db->fetchAll("SHOW COLUMNS FROM registros_prueba_corte"), 'Field');
$hasPrCol = static fn(string $name): bool => in_array($name, $colsPrueba, true);
$uploadDirRelPrueba = 'uploads/prueba-corte';
$uploadDirAbsPrueba = __DIR__ . '/../' . $uploadDirRelPrueba;

$appendFotosToObservaciones = static function (string $texto, array $fotos): string {
    $textoLimpio = trim(preg_replace('/\[FOTOS_PRUEBA_CORTE\].*$/s', '', $texto));
    if (empty($fotos)) {
        return $textoLimpio;
    }

    $bloqueFotos = "[FOTOS_PRUEBA_CORTE]\n" . implode("\n", $fotos);
    if ($textoLimpio === '') {
        return $bloqueFotos;
    }
    return $textoLimpio . "\n\n" . $bloqueFotos;
};

$colFechaPrueba = $hasPrCol('fecha_prueba') ? 'fecha_prueba' : ($hasPrCol('fecha') ? 'fecha' : null);
$colTotalGranos = $hasPrCol('total_granos') ? 'total_granos' : ($hasPrCol('granos_analizados') ? 'granos_analizados' : null);
$colAnalistaId = $hasPrCol('analista_id')
    ? 'analista_id'
    : ($hasPrCol('responsable_analisis_id') ? 'responsable_analisis_id' : ($hasPrCol('usuario_id') ? 'usuario_id' : null));
$colCalidadResultado = $hasPrCol('calidad_resultado')
    ? 'calidad_resultado'
    : ($hasPrCol('calidad_determinada') ? 'calidad_determinada' : ($hasPrCol('decision_lote') ? 'decision_lote' : null));

// Compatibilidad de esquema para lotes
$colsLotes = array_column($db->fetchAll("SHOW COLUMNS FROM lotes"), 'Field');
$hasLoteCol = static fn(string $name): bool => in_array($name, $colsLotes, true);

// Compatibilidad de esquema para secado (lectura de humedad final)
$colsSecado = array_column($db->fetchAll("SHOW COLUMNS FROM registros_secado"), 'Field');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSecado, true);
$exprHumedadSecado = $hasSecCol('humedad_final') ? 'rs.humedad_final' : 'NULL';

// Obtener lote si viene por parámetro
$loteId = $_GET['lote_id'] ?? null;
$loteInfo = null;

if ($loteId) {
    $fichaRegistro = $db->fetch("
        SELECT id FROM fichas_registro WHERE lote_id = :lote_id ORDER BY id DESC LIMIT 1
    ", ['lote_id' => $loteId]);

    if (!$fichaRegistro) {
        setFlash('error', 'Debe completar primero la ficha de registro para este lote.');
        redirect('/fichas/crear.php?etapa=recepcion&lote_id=' . (int)$loteId);
    }

    $loteInfo = $db->fetch("
        SELECT l.*, p.nombre as proveedor, p.codigo as proveedor_codigo, v.nombre as variedad,
               {$exprHumedadSecado} as humedad_secado
        FROM lotes l
        JOIN proveedores p ON l.proveedor_id = p.id
        JOIN variedades v ON l.variedad_id = v.id
        LEFT JOIN registros_secado rs ON rs.lote_id = l.id
        WHERE l.id = :id AND l.estado_proceso = 'CALIDAD_POST'
    ", ['id' => $loteId]);
    
    if (!$loteInfo) {
        setFlash('error', 'Lote no válido para prueba de corte');
        redirect('/prueba-corte/index.php');
    }
    
    // Verificar que no tenga prueba existente
    $pruebaExistente = $db->fetch("
        SELECT id FROM registros_prueba_corte WHERE lote_id = :lote_id
    ", ['lote_id' => $loteId]);
    
    if ($pruebaExistente) {
        setFlash('info', 'Este lote ya tiene una prueba de corte registrada');
        redirect('/prueba-corte/ver.php?id=' . $pruebaExistente['id']);
    }
}

// Mantener variable por compatibilidad de vista (actualmente no se utiliza en esta pantalla)
$parametrosCalidad = [];

// Obtener lotes disponibles
$lotesDisponibles = $db->fetchAll("
    SELECT l.id, l.codigo, p.nombre as proveedor
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    WHERE l.estado_proceso = 'CALIDAD_POST'
    AND EXISTS (SELECT 1 FROM fichas_registro fr WHERE fr.lote_id = l.id)
    AND NOT EXISTS (SELECT 1 FROM registros_prueba_corte rpc WHERE rpc.lote_id = l.id)
    ORDER BY l.fecha_entrada DESC
");

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    
    $loteId = $_POST['lote_id'] ?? '';
    $fechaPrueba = $_POST['fecha_prueba'] ?? '';
    $totalGranos = intval($_POST['total_granos'] ?? 100);
    
    // Granos por tipo
    $granosFermentados = intval($_POST['granos_fermentados'] ?? 0);
    $granosParciales = intval($_POST['granos_parciales'] ?? 0);
    $granosMohosos = intval($_POST['granos_mohosos'] ?? 0);
    $granosPizarra = intval($_POST['granos_pizarra'] ?? 0);
    $granosVioletas = intval($_POST['granos_violetas'] ?? 0);
    $granosGerminados = intval($_POST['granos_germinados'] ?? 0);
    $granosDañados = intval($_POST['granos_dañados'] ?? 0);
    
    $humedad = floatval($_POST['humedad'] ?? 0);
    $observaciones = trim($_POST['observaciones'] ?? '');
    $calidad = $_POST['calidad_resultado'] ?? '';
    $fotosPendientes = [];
    
    // Validaciones
    if (!$loteId) $errors[] = 'Debe seleccionar un lote';
    if (!$fechaPrueba) $errors[] = 'La fecha de prueba es requerida';
    if ($totalGranos < 100) $errors[] = 'El total de granos debe ser al menos 100';
    if (!$calidad) $errors[] = 'Debe seleccionar una calidad resultado';
    if ($calidad !== 'RECHAZADO' && !$tablaCalidadSalidaExiste) {
        $errors[] = 'Falta ejecutar el patch de base de datos para habilitar Calidad de salida.';
    }

    if ($loteId) {
        $fichaRegistro = $db->fetch("
            SELECT id FROM fichas_registro WHERE lote_id = :lote_id ORDER BY id DESC LIMIT 1
        ", ['lote_id' => $loteId]);
        if (!$fichaRegistro) {
            $errors[] = 'Debe completar primero la ficha de registro para este lote.';
        }
    }

    if ($loteId && empty($errors)) {
        $loteValido = $db->fetch("
            SELECT l.id
            FROM lotes l
            WHERE l.id = :id
              AND l.estado_proceso = 'CALIDAD_POST'
              AND EXISTS (SELECT 1 FROM fichas_registro fr WHERE fr.lote_id = l.id)
              AND NOT EXISTS (SELECT 1 FROM registros_prueba_corte rpc WHERE rpc.lote_id = l.id)
        ", ['id' => $loteId]);

        if (!$loteValido) {
            $errors[] = 'Lote no válido para registrar prueba de corte.';
        }
    }

    if (isset($_FILES['fotos_muestra']['name']) && is_array($_FILES['fotos_muestra']['name'])) {
        $names = $_FILES['fotos_muestra']['name'];
        $tmpNames = $_FILES['fotos_muestra']['tmp_name'] ?? [];
        $sizes = $_FILES['fotos_muestra']['size'] ?? [];
        $errorsUpload = $_FILES['fotos_muestra']['error'] ?? [];

        foreach ($names as $index => $nombreOriginal) {
            $err = (int)($errorsUpload[$index] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err !== UPLOAD_ERR_OK) {
                $errors[] = 'No se pudo cargar una de las fotografías de la muestra.';
                continue;
            }

            $tmpName = (string)($tmpNames[$index] ?? '');
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                $errors[] = 'Archivo de foto inválido.';
                continue;
            }

            $size = (int)($sizes[$index] ?? 0);
            if ($size > 8 * 1024 * 1024) {
                $errors[] = 'Cada foto debe ser menor o igual a 8MB.';
                continue;
            }

            $mime = mime_content_type($tmpName) ?: '';
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $errors[] = 'Solo se permiten fotos en formato JPG, PNG o WEBP.';
                continue;
            }

            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => ''
            };
            if ($ext === '') {
                $errors[] = 'Formato de imagen no soportado.';
                continue;
            }

            if (count($fotosPendientes) >= 6) {
                $errors[] = 'Puede cargar máximo 6 fotos por prueba.';
                continue;
            }

            $fotosPendientes[] = [
                'tmp' => $tmpName,
                'ext' => $ext,
                'name' => (string)$nombreOriginal,
            ];
        }
    }
    
    // Calcular porcentaje de fermentación
    $porcentajeFermentacion = $totalGranos > 0 ? (($granosFermentados + ($granosParciales * 0.5)) / $totalGranos) * 100 : 0;
    
    if (empty($errors)) {
        $fotosGuardadasAbs = [];
        try {
            $db->beginTransaction();

            $fotosRelativas = [];
            if (!empty($fotosPendientes)) {
                if (!is_dir($uploadDirAbsPrueba) && !mkdir($uploadDirAbsPrueba, 0775, true) && !is_dir($uploadDirAbsPrueba)) {
                    throw new Exception('No se pudo crear el directorio para fotos de prueba de corte.');
                }

                foreach ($fotosPendientes as $fotoPendiente) {
                    $filename = 'prueba-corte-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $fotoPendiente['ext'];
                    $destAbs = $uploadDirAbsPrueba . '/' . $filename;
                    if (!move_uploaded_file($fotoPendiente['tmp'], $destAbs)) {
                        throw new Exception('No se pudo guardar una fotografía de la muestra.');
                    }
                    $fotosGuardadasAbs[] = $destAbs;
                    $fotosRelativas[] = $uploadDirRelPrueba . '/' . $filename;
                }
            }

            $observacionesPersistir = $appendFotosToObservaciones($observaciones, $fotosRelativas);

            $loteBase = $db->fetch("
                SELECT l.codigo, l.peso_inicial_kg, l.estado_proceso,
                       p.nombre as proveedor, v.nombre as variedad
                FROM lotes l
                JOIN proveedores p ON l.proveedor_id = p.id
                JOIN variedades v ON l.variedad_id = v.id
                WHERE l.id = :id
            ", ['id' => $loteId]);
            
            // Crear registro
            $defectosTotales = $granosMohosos + $granosPizarra + $granosVioletas + $granosGerminados + $granosDañados;
            $defectosPorcentaje = $totalGranos > 0 ? ($defectosTotales / $totalGranos) * 100 : 0;
            $decisionLegacy = $calidad === 'RECHAZADO' ? 'RECHAZADO' : 'APROBADO';

            $dataPrueba = [
                'lote_id' => $loteId
            ];
            if ($hasPrCol('tipo_prueba')) {
                $dataPrueba['tipo_prueba'] = 'POST_SECADO';
            }
            if ($colFechaPrueba) {
                $dataPrueba[$colFechaPrueba] = $fechaPrueba;
            }
            if ($colTotalGranos) {
                $dataPrueba[$colTotalGranos] = $totalGranos;
            }
            if ($hasPrCol('codigo_lote') && !empty($loteBase['codigo'])) {
                $dataPrueba['codigo_lote'] = $loteBase['codigo'];
            }
            if ($hasPrCol('proveedor_origen') && !empty($loteBase['proveedor'])) {
                $dataPrueba['proveedor_origen'] = $loteBase['proveedor'];
            }
            if ($hasPrCol('tipo_cacao') && !empty($loteBase['variedad'])) {
                $dataPrueba['tipo_cacao'] = $loteBase['variedad'];
            }
            if ($hasPrCol('estado')) {
                $dataPrueba['estado'] = 'POST_SECADO';
            }
            if ($hasPrCol('cantidad_qq') && isset($loteBase['peso_inicial_kg'])) {
                $dataPrueba['cantidad_qq'] = Helpers::kgToQQ((float)$loteBase['peso_inicial_kg']);
            }

            if ($hasPrCol('granos_fermentados')) {
                $dataPrueba['granos_fermentados'] = $granosFermentados;
            }
            if ($hasPrCol('bien_fermentados')) {
                $dataPrueba['bien_fermentados'] = $granosFermentados;
            }
            if ($hasPrCol('granos_parciales')) {
                $dataPrueba['granos_parciales'] = $granosParciales;
            }
            if ($hasPrCol('granos_parcialmente_fermentados')) {
                $dataPrueba['granos_parcialmente_fermentados'] = $granosParciales;
            }
            if ($hasPrCol('granos_mohosos')) {
                $dataPrueba['granos_mohosos'] = $granosMohosos;
            }
            if ($hasPrCol('mohosos')) {
                $dataPrueba['mohosos'] = $granosMohosos;
            }
            if ($hasPrCol('granos_pizarra')) {
                $dataPrueba['granos_pizarra'] = $granosPizarra;
            }
            if ($hasPrCol('pizarrosos')) {
                $dataPrueba['pizarrosos'] = $granosPizarra;
            }
            if ($hasPrCol('granos_violetas')) {
                $dataPrueba['granos_violetas'] = $granosVioletas;
            }
            if ($hasPrCol('violeta')) {
                $dataPrueba['violeta'] = $granosVioletas;
            }
            if ($hasPrCol('granos_germinados')) {
                $dataPrueba['granos_germinados'] = $granosGerminados;
            }
            if ($hasPrCol('germinados')) {
                $dataPrueba['germinados'] = $granosGerminados;
            }
            if ($hasPrCol('granos_dañados')) {
                $dataPrueba['granos_dañados'] = $granosDañados;
            }
            if ($hasPrCol('granos_danados')) {
                $dataPrueba['granos_danados'] = $granosDañados;
            }
            if ($hasPrCol('insectados')) {
                $dataPrueba['insectados'] = $granosDañados;
            }

            if ($hasPrCol('porcentaje_fermentacion')) {
                $dataPrueba['porcentaje_fermentacion'] = $porcentajeFermentacion;
            }
            if ($hasPrCol('defectos_totales')) {
                $dataPrueba['defectos_totales'] = $defectosPorcentaje;
            }
            if ($hasPrCol('cumple_especificacion')) {
                $dataPrueba['cumple_especificacion'] = $calidad === 'RECHAZADO' ? 0 : 1;
            }
            if ($hasPrCol('humedad')) {
                $dataPrueba['humedad'] = $humedad ?: null;
            }
            if ($colCalidadResultado) {
                $dataPrueba[$colCalidadResultado] = $colCalidadResultado === 'decision_lote' ? $decisionLegacy : $calidad;
            }
            if ($hasPrCol('decision_lote')) {
                $dataPrueba['decision_lote'] = $decisionLegacy;
            }
            if ($hasPrCol('observaciones')) {
                $dataPrueba['observaciones'] = $observacionesPersistir !== '' ? $observacionesPersistir : null;
            }
            if ($colAnalistaId) {
                $dataPrueba[$colAnalistaId] = getCurrentUserId();
            }

            $pruebaId = $db->insert('registros_prueba_corte', $dataPrueba);
            
            // Actualizar estado del lote
            $nuevoEstado = $calidad === 'RECHAZADO' ? 'RECHAZADO' : 'CALIDAD_SALIDA';
            $dataLote = [
                'estado_proceso' => $nuevoEstado
            ];
            if ($hasLoteCol('calidad_final')) {
                $dataLote['calidad_final'] = $calidad;
            }
            $db->update('lotes', $dataLote, 'id = :id', ['id' => $loteId]);
            
            // Registrar historial
            Helpers::logHistory($loteId, $nuevoEstado, 'Prueba de corte: ' . $calidad . ' (' . number_format($porcentajeFermentacion, 1) . '% fermentación)', getCurrentUserId());
            
            $db->commit();
            
            if ($nuevoEstado === 'CALIDAD_SALIDA') {
                setFlash('success', 'Prueba de corte registrada. Continúe con la ficha de Calidad de salida.');
                redirect('/calidad-salida/crear.php?lote_id=' . (int)$loteId . '&from=prueba-corte');
            }

            setFlash('success', 'Prueba de corte registrada correctamente');
            redirect('/prueba-corte/ver.php?id=' . $pruebaId);
            
        } catch (Exception $e) {
            $db->rollBack();
            foreach ($fotosGuardadasAbs as $fotoAbs) {
                if (is_file($fotoAbs)) {
                    @unlink($fotoAbs);
                }
            }
            $errors[] = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Registrar Prueba de Corte';
$pageSubtitle = 'Análisis de 100 granos';

ob_start();
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error mb-6">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div>
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="max-w-5xl" id="pruebaForm">
    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
    
    <!-- Selección de Lote -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Información del Lote</h3>
        </div>
        <div class="card-body">
            <?php if ($loteInfo): ?>
                <input type="hidden" name="lote_id" value="<?= $loteInfo['id'] ?>">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div>
                        <label class="form-label">Código de Lote</label>
                        <div class="form-control bg-olive/10 font-medium text-primary">
                            <?= htmlspecialchars($loteInfo['codigo']) ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Proveedor</label>
                        <div class="form-control bg-olive/10">
                            <span class="font-bold text-primary"><?= htmlspecialchars($loteInfo['proveedor_codigo']) ?></span>
                            - <?= htmlspecialchars($loteInfo['proveedor']) ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Variedad</label>
                        <div class="form-control bg-olive/10"><?= htmlspecialchars($loteInfo['variedad']) ?></div>
                    </div>
                    <div>
                        <label class="form-label">Humedad Post-Secado</label>
                        <div class="form-control bg-olive/10">
                            <?= $loteInfo['humedad_secado'] ? number_format($loteInfo['humedad_secado'], 1) . '%' : 'N/R' ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label class="form-label required">Seleccionar Lote</label>
                    <select name="lote_id" class="form-control form-select" required>
                        <option value="">-- Seleccione un lote --</option>
                        <?php foreach ($lotesDisponibles as $lote): ?>
                            <option value="<?= $lote['id'] ?>" <?= (isset($_POST['lote_id']) && $_POST['lote_id'] == $lote['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lote['codigo']) ?> - <?= htmlspecialchars($lote['proveedor']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Datos de la Prueba -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Datos de la Prueba</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="form-group">
                    <label class="form-label required">Fecha de Prueba</label>
                    <input type="date" name="fecha_prueba" class="form-control" required
                           value="<?= $_POST['fecha_prueba'] ?? date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label required">Total de Granos Analizados</label>
                    <input type="number" name="total_granos" id="total_granos" class="form-control" required
                           min="100" value="<?= $_POST['total_granos'] ?? 100 ?>" onchange="calcularPorcentajes()">
                </div>
                <div class="form-group">
                    <label class="form-label">Humedad (%)</label>
                    <input type="number" name="humedad" class="form-control"
                           step="0.1" min="0" max="100"
                           value="<?= $_POST['humedad'] ?? ($loteInfo['humedad_secado'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>
    
    <!-- Análisis de Granos -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Análisis de Granos (100 granos cortados)</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <!-- Granos Buenos -->
                <div class="form-group">
                    <label class="form-label">
                        <span class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-green-500"></span>
                            Bien Fermentados
                        </span>
                    </label>
                    <input type="number" name="granos_fermentados" id="granos_fermentados" class="form-control text-center text-lg"
                           min="0" max="100" value="<?= $_POST['granos_fermentados'] ?? 0 ?>" onchange="calcularPorcentajes()">
                    <p class="text-xs text-warmgray mt-1">Color marrón uniforme</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <span class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-yellow-500"></span>
                            Parcialmente Fermentados
                        </span>
                    </label>
                    <input type="number" name="granos_parciales" id="granos_parciales" class="form-control text-center text-lg"
                           min="0" max="100" value="<?= $_POST['granos_parciales'] ?? 0 ?>" onchange="calcularPorcentajes()">
                    <p class="text-xs text-warmgray mt-1">Color irregular</p>
                </div>
                
                <!-- Defectos -->
                <div class="form-group">
                    <label class="form-label">
                        <span class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-gray-400"></span>
                            Pizarra/Sin Fermentar
                        </span>
                    </label>
                    <input type="number" name="granos_pizarra" id="granos_pizarra" class="form-control text-center text-lg"
                           min="0" max="100" value="<?= $_POST['granos_pizarra'] ?? 0 ?>" onchange="calcularPorcentajes()">
                    <p class="text-xs text-warmgray mt-1">Color gris oscuro</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <span class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-purple-500"></span>
                            Violetas
                        </span>
                    </label>
                    <input type="number" name="granos_violetas" id="granos_violetas" class="form-control text-center text-lg"
                           min="0" max="100" value="<?= $_POST['granos_violetas'] ?? 0 ?>" onchange="calcularPorcentajes()">
                    <p class="text-xs text-warmgray mt-1">Fermentación corta</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <span class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-gray-700"></span>
                            Mohosos
                        </span>
                    </label>
                    <input type="number" name="granos_mohosos" id="granos_mohosos" class="form-control text-center text-lg"
                           min="0" max="100" value="<?= $_POST['granos_mohosos'] ?? 0 ?>" onchange="calcularPorcentajes()">
                    <p class="text-xs text-warmgray mt-1">Presencia de moho</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <span class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-green-800"></span>
                            Germinados
                        </span>
                    </label>
                    <input type="number" name="granos_germinados" id="granos_germinados" class="form-control text-center text-lg"
                           min="0" max="100" value="<?= $_POST['granos_germinados'] ?? 0 ?>" onchange="calcularPorcentajes()">
                    <p class="text-xs text-warmgray mt-1">Cotiledón perforado</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <span class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-red-600"></span>
                            Dañados/Insectos
                        </span>
                    </label>
                    <input type="number" name="granos_dañados" id="granos_dañados" class="form-control text-center text-lg"
                           min="0" max="100" value="<?= $_POST['granos_dañados'] ?? 0 ?>" onchange="calcularPorcentajes()">
                    <p class="text-xs text-warmgray mt-1">Daño visible</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Resultados Calculados -->
    <div class="card mb-6 bg-olive/10">
        <div class="card-header">
            <h3 class="card-title">Resultados</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="text-center p-4 bg-white rounded-lg shadow">
                    <p class="text-3xl font-bold text-primary" id="result_fermentacion">0%</p>
                    <p class="text-xs text-warmgray">% Fermentación</p>
                </div>
                <div class="text-center p-4 bg-white rounded-lg shadow">
                    <p class="text-3xl font-bold text-red-600" id="result_defectos">0%</p>
                    <p class="text-xs text-warmgray">% Defectos</p>
                </div>
                <div class="text-center p-4 bg-white rounded-lg shadow">
                    <p class="text-3xl font-bold text-warmgray" id="result_conteo">0/100</p>
                    <p class="text-xs text-warmgray">Granos Contados</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Decisión de Calidad -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Decisión de Calidad</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <label class="relative cursor-pointer">
                    <input type="radio" name="calidad_resultado" value="PREMIUM" class="sr-only peer" required
                           <?= (isset($_POST['calidad_resultado']) && $_POST['calidad_resultado'] === 'PREMIUM') ? 'checked' : '' ?>>
                    <div class="p-4 border-2 rounded-lg text-center transition-all peer-checked:border-green-500 peer-checked:bg-green-50 hover:border-green-300">
                        <span class="text-2xl">🏆</span>
                        <p class="font-semibold text-green-600">PREMIUM</p>
                    </div>
                </label>
                
                <label class="relative cursor-pointer">
                    <input type="radio" name="calidad_resultado" value="EXPORTACION" class="sr-only peer" required
                           <?= (isset($_POST['calidad_resultado']) && $_POST['calidad_resultado'] === 'EXPORTACION') ? 'checked' : '' ?>>
                    <div class="p-4 border-2 rounded-lg text-center transition-all peer-checked:border-primary peer-checked:bg-olive/20 hover:border-olive">
                        <span class="text-2xl">✈️</span>
                        <p class="font-semibold text-primary">EXPORTACIÓN</p>
                    </div>
                </label>
                
                <label class="relative cursor-pointer">
                    <input type="radio" name="calidad_resultado" value="NACIONAL" class="sr-only peer" required
                           <?= (isset($_POST['calidad_resultado']) && $_POST['calidad_resultado'] === 'NACIONAL') ? 'checked' : '' ?>>
                    <div class="p-4 border-2 rounded-lg text-center transition-all peer-checked:border-gold peer-checked:bg-yellow-50 hover:border-yellow-300">
                        <span class="text-2xl">🏠</span>
                        <p class="font-semibold text-gold">NACIONAL</p>
                    </div>
                </label>
                
                <label class="relative cursor-pointer">
                    <input type="radio" name="calidad_resultado" value="RECHAZADO" class="sr-only peer" required
                           <?= (isset($_POST['calidad_resultado']) && $_POST['calidad_resultado'] === 'RECHAZADO') ? 'checked' : '' ?>>
                    <div class="p-4 border-2 rounded-lg text-center transition-all peer-checked:border-red-500 peer-checked:bg-red-50 hover:border-red-300">
                        <span class="text-2xl">❌</span>
                        <p class="font-semibold text-red-600">RECHAZADO</p>
                    </div>
                </label>
            </div>
            <p class="text-xs text-warmgray mt-3">La clasificación final es definida por el analista según la tabla de referencia y criterio técnico.</p>

            <div class="mt-6 p-4 rounded-lg border border-gray-200 bg-gray-50">
                <h4 class="text-sm font-semibold text-gray-800 mb-3">Tabla de referencia CCN51</h4>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead>
                            <tr class="text-left text-gray-600 border-b border-gray-200">
                                <th class="py-2 pr-4">Parámetro</th>
                                <th class="py-2 pr-4">Rango referencial</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            <tr>
                                <td class="py-2 pr-4">Bien fermentados</td>
                                <td class="py-2 pr-4">≥ 65%</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4">Violetas</td>
                                <td class="py-2 pr-4">≤ 15%</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4">Pizarrosos</td>
                                <td class="py-2 pr-4">≤ 3%</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4">Mohosos</td>
                                <td class="py-2 pr-4">≤ 3%</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4">Germinados / Dañados</td>
                                <td class="py-2 pr-4">≤ 3%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-warmgray mt-2">Referencia visual para apoyar la decisión manual del analista.</p>
            </div>

            <div class="form-group mt-6">
                <label class="form-label">Fotos de la muestra (opcional)</label>
                <input type="file" name="fotos_muestra[]" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
                <p class="text-xs text-warmgray mt-1">Puede cargar hasta 6 fotos (JPG/PNG/WEBP, máximo 8MB por archivo).</p>
            </div>
            
            <div class="form-group mt-6">
                <label class="form-label">Observaciones</label>
                <textarea name="observaciones" class="form-control" rows="3"
                          placeholder="Observaciones adicionales sobre la prueba..."><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
    
    <!-- Botones -->
    <div class="flex items-center gap-4">
        <button type="submit" class="btn btn-primary">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            Registrar Prueba de Corte
        </button>
        <a href="<?= APP_URL ?>/prueba-corte/index.php" class="btn btn-outline">Cancelar</a>
    </div>
</form>

<script>
function calcularPorcentajes() {
    const total = parseInt(document.getElementById('total_granos').value) || 100;
    
    const fermentados = parseInt(document.getElementById('granos_fermentados').value) || 0;
    const parciales = parseInt(document.getElementById('granos_parciales').value) || 0;
    const pizarra = parseInt(document.getElementById('granos_pizarra').value) || 0;
    const violetas = parseInt(document.getElementById('granos_violetas').value) || 0;
    const mohosos = parseInt(document.getElementById('granos_mohosos').value) || 0;
    const germinados = parseInt(document.getElementById('granos_germinados').value) || 0;
    const dañados = parseInt(document.getElementById('granos_dañados').value) || 0;
    
    const conteo = fermentados + parciales + pizarra + violetas + mohosos + germinados + dañados;
    const defectos = mohosos + pizarra + violetas + germinados + dañados;
    
    // Porcentaje de fermentación (parciales cuentan como 50%)
    const pctFermentacion = ((fermentados + (parciales * 0.5)) / total) * 100;
    const pctDefectos = (defectos / total) * 100;
    
    // Actualizar displays
    document.getElementById('result_fermentacion').textContent = pctFermentacion.toFixed(1) + '%';
    document.getElementById('result_fermentacion').className = 'text-3xl font-bold text-primary';
    
    document.getElementById('result_defectos').textContent = pctDefectos.toFixed(1) + '%';
    document.getElementById('result_defectos').className = 'text-3xl font-bold text-red-600';
    
    document.getElementById('result_conteo').textContent = conteo + '/' + total;
    document.getElementById('result_conteo').className = 'text-3xl font-bold ' + 
        (conteo === total ? 'text-green-600' : 'text-warmgray');
}

// Calcular al cargar
document.addEventListener('DOMContentLoaded', calcularPorcentajes);
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
