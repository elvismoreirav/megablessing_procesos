<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Iniciar Fermentación
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();
$errors = [];
$success = false;

// Obtener lote si viene por parámetro
$loteId = $_GET['lote_id'] ?? null;
$loteInfo = null;

if ($loteId) {
    $loteInfo = $db->fetch("
        SELECT l.*, p.nombre as proveedor, p.codigo as proveedor_codigo, v.nombre as variedad
        FROM lotes l
        JOIN proveedores p ON l.proveedor_id = p.id
        JOIN variedades v ON l.variedad_id = v.id
        WHERE l.id = :id AND l.estado_proceso = 'FERMENTACION'
    ", ['id' => $loteId]);
    
    if (!$loteInfo) {
        setFlash('error', 'Lote no válido para fermentación');
        redirect('/fermentacion/index.php');
    }
    
    // Verificar que no tenga fermentación activa
    $fermentacionExistente = $db->fetch("
        SELECT id FROM registros_fermentacion WHERE lote_id = :lote_id
    ", ['lote_id' => $loteId]);
    
    if ($fermentacionExistente) {
        setFlash('error', 'Este lote ya tiene un registro de fermentación');
        redirect('/fermentacion/control.php?id=' . $fermentacionExistente['id']);
    }
}

// Obtener cajones disponibles
$cajones = $db->fetchAll("SELECT id, nombre, capacidad_kg FROM cajones_fermentacion WHERE activo = 1 ORDER BY nombre");

// Obtener lotes disponibles para fermentación
$lotesDisponibles = $db->fetchAll("
    SELECT l.id, l.codigo, l.peso_inicial_kg, p.nombre as proveedor
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    WHERE l.estado_proceso = 'FERMENTACION'
    AND NOT EXISTS (SELECT 1 FROM registros_fermentacion rf WHERE rf.lote_id = l.id)
    ORDER BY l.fecha_entrada DESC
");

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    
    $loteId = $_POST['lote_id'] ?? '';
    $cajonId = $_POST['cajon_id'] ?? null;
    $fechaInicio = $_POST['fecha_inicio'] ?? '';
    $pesoInicial = floatval($_POST['peso_inicial'] ?? 0);
    $humedadInicial = floatval($_POST['humedad_inicial'] ?? 0);
    $temperaturaInicial = floatval($_POST['temperatura_inicial'] ?? 0);
    $phInicial = floatval($_POST['ph_inicial'] ?? 0);
    $observaciones = trim($_POST['observaciones'] ?? '');
    
    // Validaciones
    if (!$loteId) $errors[] = 'Debe seleccionar un lote';
    if (!$fechaInicio) $errors[] = 'La fecha de inicio es requerida';
    if ($pesoInicial <= 0) $errors[] = 'El peso inicial debe ser mayor a 0';
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Crear registro de fermentación
            $fermentacionId = $db->insert('registros_fermentacion', [
                'lote_id' => $loteId,
                'cajon_id' => $cajonId ?: null,
                'fecha_inicio' => $fechaInicio,
                'peso_inicial' => $pesoInicial,
                'humedad_inicial' => $humedadInicial ?: null,
                'temperatura_inicial' => $temperaturaInicial ?: null,
                'ph_inicial' => $phInicial ?: null,
                'observaciones' => $observaciones,
                'responsable_id' => getCurrentUserId()
            ]);
            
            // Registrar historial
            Helpers::logHistory($loteId, 'FERMENTACION', 'Inicio de fermentación', getCurrentUserId());
            
            $db->commit();
            
            setFlash('success', 'Fermentación iniciada correctamente');
            redirect('/fermentacion/control.php?id=' . $fermentacionId);
            
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Iniciar Fermentación';
$pageSubtitle = 'Registrar nuevo proceso de fermentación';

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

<form method="POST" class="max-w-4xl">
    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
    
    <!-- Selección de Lote -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Información del Lote</h3>
        </div>
        <div class="card-body">
            <?php if ($loteInfo): ?>
                <input type="hidden" name="lote_id" value="<?= $loteInfo['id'] ?>">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
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
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label class="form-label required">Seleccionar Lote</label>
                    <select name="lote_id" class="form-control form-select" required>
                        <option value="">-- Seleccione un lote --</option>
                        <?php foreach ($lotesDisponibles as $lote): ?>
                            <option value="<?= $lote['id'] ?>" <?= (isset($_POST['lote_id']) && $_POST['lote_id'] == $lote['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lote['codigo']) ?> - <?= htmlspecialchars($lote['proveedor']) ?> 
                                (<?= number_format($lote['peso_inicial_kg'], 2) ?> Kg)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($lotesDisponibles)): ?>
                        <p class="text-sm text-warmgray mt-2">No hay lotes disponibles para fermentación</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Datos de Fermentación -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Datos de Fermentación</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="form-group">
                    <label class="form-label required">Fecha de Inicio</label>
                    <input type="date" name="fecha_inicio" class="form-control" required
                           value="<?= $_POST['fecha_inicio'] ?? date('Y-m-d') ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Cajón de Fermentación</label>
                    <select name="cajon_id" class="form-control form-select">
                        <option value="">-- Sin asignar --</option>
                        <?php foreach ($cajones as $cajon): ?>
                            <option value="<?= $cajon['id'] ?>" <?= (isset($_POST['cajon_id']) && $_POST['cajon_id'] == $cajon['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cajon['nombre']) ?> 
                                (<?= number_format($cajon['capacidad_kg'], 0) ?> Kg)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Peso Inicial (Kg)</label>
                    <input type="number" name="peso_inicial" class="form-control" required
                           step="0.01" min="0"
                           value="<?= $_POST['peso_inicial'] ?? ($loteInfo['peso_inicial_kg'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Humedad Inicial (%)</label>
                    <input type="number" name="humedad_inicial" class="form-control"
                           step="0.1" min="0" max="100"
                           value="<?= $_POST['humedad_inicial'] ?? ($loteInfo['humedad_inicial'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Temperatura Inicial (°C)</label>
                    <input type="number" name="temperatura_inicial" class="form-control"
                           step="0.1" min="0" max="60"
                           value="<?= $_POST['temperatura_inicial'] ?? '' ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">pH Inicial</label>
                    <input type="number" name="ph_inicial" class="form-control"
                           step="0.01" min="0" max="14"
                           value="<?= $_POST['ph_inicial'] ?? '' ?>">
                </div>
            </div>
            
            <div class="form-group mt-6">
                <label class="form-label">Observaciones</label>
                <textarea name="observaciones" class="form-control" rows="3"
                          placeholder="Observaciones adicionales..."><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
    
    <!-- Información del Proceso -->
    <div class="card mb-6 bg-olive/10">
        <div class="card-body">
            <div class="flex items-start gap-4">
                <div class="p-3 bg-primary/10 rounded-lg">
                    <svg class="w-6 h-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <h4 class="font-semibold text-primary mb-2">Proceso de Fermentación</h4>
                    <ul class="text-sm text-warmgray space-y-1">
                        <li>• La fermentación típica dura entre 5-7 días</li>
                        <li>• Se recomienda realizar volteos cada 24-48 horas</li>
                        <li>• La temperatura óptima está entre 45-50°C</li>
                        <li>• El pH debe mantenerse entre 4.5-5.5</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Botones -->
    <div class="flex items-center gap-4">
        <button type="submit" class="btn btn-primary">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            Iniciar Fermentación
        </button>
        <a href="<?= APP_URL ?>/fermentacion/index.php" class="btn btn-outline">Cancelar</a>
    </div>
</form>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
