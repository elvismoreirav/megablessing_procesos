<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Registrar Empaquetado
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

$id = $_GET['id'] ?? null;

if (!$id) {
    setFlash('error', 'ID de empaquetado no especificado');
    redirect('/empaquetado/index.php');
}

// Obtener datos de empaquetado
$empaquetado = $db->fetch("
    SELECT re.*, 
           l.codigo as lote_codigo,
           l.calidad_final,
           p.nombre as proveedor, 
           p.codigo as proveedor_codigo,
           v.nombre as variedad,
           rs.peso_final as peso_disponible,
           rs.humedad_final as humedad
    FROM registros_empaquetado re
    JOIN lotes l ON re.lote_id = l.id
    JOIN proveedores p ON l.proveedor_id = p.id
    JOIN variedades v ON l.variedad_id = v.id
    LEFT JOIN registros_secado rs ON rs.lote_id = l.id
    WHERE re.id = :id
", ['id' => $id]);

if (!$empaquetado) {
    setFlash('error', 'Registro de empaquetado no encontrado');
    redirect('/empaquetado/index.php');
}

// Si ya está completado, redirigir a ver
if ($empaquetado['fecha_empaquetado']) {
    redirect('/empaquetado/ver.php?id=' . $id);
}

$errors = [];

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    
    $fechaEmpaquetado = $_POST['fecha_empaquetado'] ?? '';
    $numeroSacos = intval($_POST['numero_sacos'] ?? 0);
    $pesoTotal = floatval($_POST['peso_total'] ?? 0);
    $loteEmpaque = trim($_POST['lote_empaque'] ?? '');
    $destino = trim($_POST['destino'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    
    // Validaciones
    if (!$fechaEmpaquetado) $errors[] = 'La fecha de empaquetado es requerida';
    if ($numeroSacos <= 0) $errors[] = 'El número de sacos debe ser mayor a 0';
    if ($pesoTotal <= 0) $errors[] = 'El peso total debe ser mayor a 0';
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Actualizar registro de empaquetado
            $db->update('registros_empaquetado', [
                'fecha_empaquetado' => $fechaEmpaquetado,
                'numero_sacos' => $numeroSacos,
                'peso_total' => $pesoTotal,
                'lote_empaque' => $loteEmpaque ?: null,
                'destino' => $destino ?: null,
                'observaciones' => $observaciones
            ], 'id = :id', ['id' => $id]);
            
            // Actualizar estado del lote a FINALIZADO
            $db->update('lotes', [
                'estado_proceso' => 'FINALIZADO',
                'peso_final_kg' => $pesoTotal
            ], 'id = :id', ['id' => $empaquetado['lote_id']]);
            
            // Registrar historial
            Helpers::logHistory(
                $empaquetado['lote_id'], 
                'FINALIZADO', 
                "Empaquetado completado: {$numeroSacos} sacos, {$pesoTotal} kg" . ($destino ? " - Destino: {$destino}" : ''),
                getCurrentUserId()
            );
            
            $db->commit();
            
            setFlash('success', 'Empaquetado registrado correctamente. Proceso finalizado.');
            redirect('/empaquetado/ver.php?id=' . $id);
            
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Registrar Empaquetado';
$pageSubtitle = 'Lote: ' . $empaquetado['lote_codigo'];

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

<!-- Info del Lote -->
<div class="card mb-6 bg-olive/10">
    <div class="card-body">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="text-center">
                <p class="text-xs text-warmgray">Lote</p>
                <p class="font-bold text-primary text-lg"><?= htmlspecialchars($empaquetado['lote_codigo']) ?></p>
            </div>
            <div class="text-center">
                <p class="text-xs text-warmgray">Proveedor</p>
                <p class="font-medium"><?= htmlspecialchars($empaquetado['proveedor']) ?></p>
            </div>
            <div class="text-center">
                <p class="text-xs text-warmgray">Calidad</p>
                <?php
                $badgeClass = match($empaquetado['calidad_final']) {
                    'PREMIUM' => 'badge-success',
                    'EXPORTACION' => 'badge-primary',
                    'NACIONAL' => 'badge-gold',
                    default => 'badge-secondary'
                };
                ?>
                <span class="badge <?= $badgeClass ?>"><?= $empaquetado['calidad_final'] ?></span>
            </div>
            <div class="text-center">
                <p class="text-xs text-warmgray">Peso Disponible</p>
                <p class="font-bold text-lg"><?= $empaquetado['peso_disponible'] ? number_format($empaquetado['peso_disponible'], 2) . ' kg' : 'N/R' ?></p>
            </div>
            <div class="text-center">
                <p class="text-xs text-warmgray">Tipo Empaque</p>
                <p class="font-medium"><?= $empaquetado['tipo_empaque'] ?></p>
                <p class="text-xs text-warmgray"><?= $empaquetado['peso_saco'] ?> kg/saco</p>
            </div>
        </div>
    </div>
</div>

<form method="POST" class="max-w-3xl">
    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
    
    <!-- Datos del Empaquetado -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Registro de Empaquetado</h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="form-group">
                    <label class="form-label required">Fecha de Empaquetado</label>
                    <input type="date" name="fecha_empaquetado" class="form-control" required
                           value="<?= $_POST['fecha_empaquetado'] ?? date('Y-m-d') ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Lote de Empaque</label>
                    <input type="text" name="lote_empaque" class="form-control"
                           placeholder="Ej: EMP-2025-001"
                           value="<?= htmlspecialchars($_POST['lote_empaque'] ?? '') ?>">
                    <p class="text-xs text-warmgray mt-1">Identificador interno del lote empacado</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Número de Sacos</label>
                    <input type="number" name="numero_sacos" id="numero_sacos" class="form-control" required
                           min="1" value="<?= $_POST['numero_sacos'] ?? '' ?>"
                           onchange="calcularPesoTotal()">
                    <?php if ($empaquetado['peso_disponible']): ?>
                        <p class="text-xs text-warmgray mt-1">
                            Estimado: <?= floor($empaquetado['peso_disponible'] / $empaquetado['peso_saco']) ?> sacos de <?= $empaquetado['peso_saco'] ?> kg
                        </p>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Peso Total (kg)</label>
                    <input type="number" name="peso_total" id="peso_total" class="form-control" required
                           step="0.01" min="0.1" value="<?= $_POST['peso_total'] ?? '' ?>">
                    <p class="text-xs text-warmgray mt-1" id="peso_sugerido">
                        <?php if ($empaquetado['peso_disponible']): ?>
                            Peso disponible: <?= number_format($empaquetado['peso_disponible'], 2) ?> kg
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <div class="form-group mt-6">
                <label class="form-label">Destino</label>
                <input type="text" name="destino" class="form-control"
                       placeholder="Ej: Exportación Europa, Mercado Nacional, Cliente X"
                       value="<?= htmlspecialchars($_POST['destino'] ?? '') ?>">
            </div>
            
            <div class="form-group mt-6">
                <label class="form-label">Observaciones</label>
                <textarea name="observaciones" class="form-control" rows="3"
                          placeholder="Observaciones del proceso de empaquetado..."><?= htmlspecialchars($_POST['observaciones'] ?? $empaquetado['observaciones']) ?></textarea>
            </div>
        </div>
    </div>
    
    <!-- Checklist de Empaque -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Verificación de Calidad</h3>
        </div>
        <div class="card-body">
            <div class="space-y-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" class="form-checkbox" required>
                    <span>Humedad verificada (≤7%)</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" class="form-checkbox" required>
                    <span>Sacos en buen estado</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" class="form-checkbox" required>
                    <span>Etiquetas de identificación colocadas</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" class="form-checkbox" required>
                    <span>Peso verificado por saco</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" class="form-checkbox" required>
                    <span>Área de almacenamiento preparada</span>
                </label>
            </div>
        </div>
    </div>
    
    <!-- Resumen -->
    <div class="card mb-6 bg-green-50 border-green-200">
        <div class="card-body">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-green-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <h4 class="font-semibold text-green-900">¡Proceso Final!</h4>
                    <p class="mt-1 text-sm text-green-800">
                        Al completar este registro, el lote pasará a estado <strong>FINALIZADO</strong>. 
                        Esto marca el fin del proceso de producción para este lote de cacao.
                    </p>
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
            Completar Empaquetado
        </button>
        <a href="<?= APP_URL ?>/empaquetado/index.php" class="btn btn-outline">Cancelar</a>
    </div>
</form>

<script>
function calcularPesoTotal() {
    const numeroSacos = parseInt(document.getElementById('numero_sacos').value) || 0;
    const pesoSaco = <?= $empaquetado['peso_saco'] ?>;
    
    if (numeroSacos > 0) {
        const pesoSugerido = numeroSacos * pesoSaco;
        document.getElementById('peso_total').value = pesoSugerido.toFixed(2);
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
