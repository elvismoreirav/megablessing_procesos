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
               rs.humedad_final as humedad_secado
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

// Obtener parámetros de calidad
$parametrosCalidad = $db->fetchAll("
    SELECT nombre, valor_minimo, valor_maximo, descripcion 
    FROM parametros_proceso 
    WHERE nombre LIKE 'calidad_%' OR nombre LIKE 'defecto_%'
");

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
    
    // Validaciones
    if (!$loteId) $errors[] = 'Debe seleccionar un lote';
    if (!$fechaPrueba) $errors[] = 'La fecha de prueba es requerida';
    if ($totalGranos < 100) $errors[] = 'El total de granos debe ser al menos 100';
    if (!$calidad) $errors[] = 'Debe seleccionar una calidad resultado';

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
    
    // Calcular porcentaje de fermentación
    $porcentajeFermentacion = $totalGranos > 0 ? (($granosFermentados + ($granosParciales * 0.5)) / $totalGranos) * 100 : 0;
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Crear registro
            $pruebaId = $db->insert('registros_prueba_corte', [
                'lote_id' => $loteId,
                'fecha_prueba' => $fechaPrueba,
                'total_granos' => $totalGranos,
                'granos_fermentados' => $granosFermentados,
                'granos_parciales' => $granosParciales,
                'granos_mohosos' => $granosMohosos,
                'granos_pizarra' => $granosPizarra,
                'granos_violetas' => $granosVioletas,
                'granos_germinados' => $granosGerminados,
                'granos_dañados' => $granosDañados,
                'porcentaje_fermentacion' => $porcentajeFermentacion,
                'humedad' => $humedad ?: null,
                'calidad_resultado' => $calidad,
                'observaciones' => $observaciones,
                'analista_id' => getCurrentUserId()
            ]);
            
            // Actualizar estado del lote
            $nuevoEstado = $calidad === 'RECHAZADO' ? 'RECHAZADO' : 'EMPAQUETADO';
            $db->update('lotes', [
                'estado_proceso' => $nuevoEstado,
                'calidad_final' => $calidad
            ], 'id = :id', ['id' => $loteId]);
            
            // Registrar historial
            Helpers::logHistory($loteId, $nuevoEstado, 'Prueba de corte: ' . $calidad . ' (' . number_format($porcentajeFermentacion, 1) . '% fermentación)', getCurrentUserId());
            
            $db->commit();
            
            setFlash('success', 'Prueba de corte registrada correctamente');
            redirect('/prueba-corte/ver.php?id=' . $pruebaId);
            
        } catch (Exception $e) {
            $db->rollBack();
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

<form method="POST" class="max-w-5xl" id="pruebaForm">
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
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <div class="text-center p-4 bg-white rounded-lg shadow">
                    <p class="text-3xl font-bold text-primary" id="result_fermentacion">0%</p>
                    <p class="text-xs text-warmgray">% Fermentación</p>
                    <p class="text-xs text-green-600 mt-1">Objetivo: ≥70%</p>
                </div>
                <div class="text-center p-4 bg-white rounded-lg shadow">
                    <p class="text-3xl font-bold text-red-600" id="result_defectos">0%</p>
                    <p class="text-xs text-warmgray">% Defectos</p>
                    <p class="text-xs text-green-600 mt-1">Máximo: ≤5%</p>
                </div>
                <div class="text-center p-4 bg-white rounded-lg shadow">
                    <p class="text-3xl font-bold text-warmgray" id="result_conteo">0/100</p>
                    <p class="text-xs text-warmgray">Granos Contados</p>
                </div>
                <div class="text-center p-4 bg-white rounded-lg shadow">
                    <p class="text-3xl font-bold" id="result_calidad_sugerida">-</p>
                    <p class="text-xs text-warmgray">Calidad Sugerida</p>
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
                        <p class="text-xs text-warmgray">≥80% ferm., ≤3% def.</p>
                    </div>
                </label>
                
                <label class="relative cursor-pointer">
                    <input type="radio" name="calidad_resultado" value="EXPORTACION" class="sr-only peer" required
                           <?= (isset($_POST['calidad_resultado']) && $_POST['calidad_resultado'] === 'EXPORTACION') ? 'checked' : '' ?>>
                    <div class="p-4 border-2 rounded-lg text-center transition-all peer-checked:border-primary peer-checked:bg-olive/20 hover:border-olive">
                        <span class="text-2xl">✈️</span>
                        <p class="font-semibold text-primary">EXPORTACIÓN</p>
                        <p class="text-xs text-warmgray">≥70% ferm., ≤5% def.</p>
                    </div>
                </label>
                
                <label class="relative cursor-pointer">
                    <input type="radio" name="calidad_resultado" value="NACIONAL" class="sr-only peer" required
                           <?= (isset($_POST['calidad_resultado']) && $_POST['calidad_resultado'] === 'NACIONAL') ? 'checked' : '' ?>>
                    <div class="p-4 border-2 rounded-lg text-center transition-all peer-checked:border-gold peer-checked:bg-yellow-50 hover:border-yellow-300">
                        <span class="text-2xl">🏠</span>
                        <p class="font-semibold text-gold">NACIONAL</p>
                        <p class="text-xs text-warmgray">≥60% ferm., ≤10% def.</p>
                    </div>
                </label>
                
                <label class="relative cursor-pointer">
                    <input type="radio" name="calidad_resultado" value="RECHAZADO" class="sr-only peer" required
                           <?= (isset($_POST['calidad_resultado']) && $_POST['calidad_resultado'] === 'RECHAZADO') ? 'checked' : '' ?>>
                    <div class="p-4 border-2 rounded-lg text-center transition-all peer-checked:border-red-500 peer-checked:bg-red-50 hover:border-red-300">
                        <span class="text-2xl">❌</span>
                        <p class="font-semibold text-red-600">RECHAZADO</p>
                        <p class="text-xs text-warmgray">&lt;60% ferm. o &gt;10% def.</p>
                    </div>
                </label>
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
    document.getElementById('result_fermentacion').className = 'text-3xl font-bold ' + 
        (pctFermentacion >= 70 ? 'text-green-600' : (pctFermentacion >= 60 ? 'text-gold' : 'text-red-600'));
    
    document.getElementById('result_defectos').textContent = pctDefectos.toFixed(1) + '%';
    document.getElementById('result_defectos').className = 'text-3xl font-bold ' + 
        (pctDefectos <= 5 ? 'text-green-600' : (pctDefectos <= 10 ? 'text-gold' : 'text-red-600'));
    
    document.getElementById('result_conteo').textContent = conteo + '/' + total;
    document.getElementById('result_conteo').className = 'text-3xl font-bold ' + 
        (conteo === total ? 'text-green-600' : 'text-warmgray');
    
    // Sugerir calidad
    let calidadSugerida = 'RECHAZADO';
    let colorCalidad = 'text-red-600';
    
    if (pctFermentacion >= 80 && pctDefectos <= 3) {
        calidadSugerida = 'PREMIUM';
        colorCalidad = 'text-green-600';
    } else if (pctFermentacion >= 70 && pctDefectos <= 5) {
        calidadSugerida = 'EXPORTACIÓN';
        colorCalidad = 'text-primary';
    } else if (pctFermentacion >= 60 && pctDefectos <= 10) {
        calidadSugerida = 'NACIONAL';
        colorCalidad = 'text-gold';
    }
    
    document.getElementById('result_calidad_sugerida').textContent = calidadSugerida;
    document.getElementById('result_calidad_sugerida').className = 'text-3xl font-bold ' + colorCalidad;
}

// Calcular al cargar
document.addEventListener('DOMContentLoaded', calcularPorcentajes);
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
