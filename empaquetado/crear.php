<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Iniciar Empaquetado
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
    $loteInfo = $db->fetch("
        SELECT l.*, p.nombre as proveedor, p.codigo as proveedor_codigo, v.nombre as variedad,
               rpc.porcentaje_fermentacion, rpc.calidad_resultado,
               rs.peso_final as peso_secado, rs.humedad_final as humedad_secado
        FROM lotes l
        JOIN proveedores p ON l.proveedor_id = p.id
        JOIN variedades v ON l.variedad_id = v.id
        LEFT JOIN registros_prueba_corte rpc ON rpc.lote_id = l.id
        LEFT JOIN registros_secado rs ON rs.lote_id = l.id
        WHERE l.id = :id AND l.estado_proceso = 'EMPAQUETADO'
    ", ['id' => $loteId]);
    
    if (!$loteInfo) {
        setFlash('error', 'Lote no válido para empaquetado');
        redirect('/empaquetado/index.php');
    }
    
    // Verificar que no tenga empaquetado existente
    $empaquetadoExistente = $db->fetch("
        SELECT id FROM registros_empaquetado WHERE lote_id = :lote_id
    ", ['lote_id' => $loteId]);
    
    if ($empaquetadoExistente) {
        setFlash('info', 'Este lote ya tiene un registro de empaquetado');
        redirect('/empaquetado/ver.php?id=' . $empaquetadoExistente['id']);
    }
}

// Obtener lotes disponibles
$lotesDisponibles = $db->fetchAll("
    SELECT l.id, l.codigo, p.nombre as proveedor, l.calidad_final
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    WHERE l.estado_proceso = 'EMPAQUETADO'
    AND NOT EXISTS (SELECT 1 FROM registros_empaquetado re WHERE re.lote_id = l.id)
    ORDER BY l.fecha_entrada DESC
");

// Obtener tipos de empaque
$tiposEmpaque = [
    ['codigo' => 'SACO_50', 'nombre' => 'Saco 50 kg', 'peso' => 50],
    ['codigo' => 'SACO_46', 'nombre' => 'Saco 46 kg (Exportación)', 'peso' => 46],
    ['codigo' => 'SACO_25', 'nombre' => 'Saco 25 kg', 'peso' => 25],
    ['codigo' => 'BIG_BAG', 'nombre' => 'Big Bag 1000 kg', 'peso' => 1000],
    ['codigo' => 'OTRO', 'nombre' => 'Otro', 'peso' => 0]
];

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    
    $loteId = $_POST['lote_id'] ?? '';
    $tipoEmpaque = $_POST['tipo_empaque'] ?? '';
    $pesoSaco = floatval($_POST['peso_saco'] ?? 0);
    $observaciones = trim($_POST['observaciones'] ?? '');
    
    // Validaciones
    if (!$loteId) $errors[] = 'Debe seleccionar un lote';
    if (!$tipoEmpaque) $errors[] = 'Debe seleccionar un tipo de empaque';
    if ($pesoSaco <= 0) $errors[] = 'El peso por saco debe ser mayor a 0';
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Crear registro de empaquetado
            $empaquetadoId = $db->insert('registros_empaquetado', [
                'lote_id' => $loteId,
                'tipo_empaque' => $tipoEmpaque,
                'peso_saco' => $pesoSaco,
                'observaciones' => $observaciones,
                'operador_id' => getCurrentUserId()
            ]);
            
            // Registrar historial
            Helpers::logHistory($loteId, 'EMPAQUETADO', 'Iniciado proceso de empaquetado - Tipo: ' . $tipoEmpaque, getCurrentUserId());
            
            $db->commit();
            
            setFlash('success', 'Proceso de empaquetado iniciado correctamente');
            redirect('/empaquetado/registrar.php?id=' . $empaquetadoId);
            
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Iniciar Empaquetado';
$pageSubtitle = 'Configurar proceso de empaque';

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

<form method="POST" class="max-w-3xl">
    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
    
    <!-- Selección de Lote -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Información del Lote</h3>
        </div>
        <div class="card-body">
            <?php if ($loteInfo): ?>
                <input type="hidden" name="lote_id" value="<?= $loteInfo['id'] ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
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
                        <label class="form-label">Calidad Final</label>
                        <div class="form-control bg-olive/10">
                            <?php
                            $badgeClass = match($loteInfo['calidad_final']) {
                                'PREMIUM' => 'badge-success',
                                'EXPORTACION' => 'badge-primary',
                                'NACIONAL' => 'badge-gold',
                                default => 'badge-secondary'
                            };
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= $loteInfo['calidad_final'] ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Info adicional -->
                <div class="mt-6 p-4 bg-olive/10 rounded-lg">
                    <h4 class="font-semibold mb-3">Datos del Proceso</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div>
                            <p class="text-warmgray">Peso Disponible</p>
                            <p class="font-bold text-lg"><?= $loteInfo['peso_secado'] ? number_format($loteInfo['peso_secado'], 2) . ' kg' : 'N/R' ?></p>
                        </div>
                        <div>
                            <p class="text-warmgray">Humedad Final</p>
                            <p class="font-bold text-lg"><?= $loteInfo['humedad_secado'] ? number_format($loteInfo['humedad_secado'], 1) . '%' : 'N/R' ?></p>
                        </div>
                        <div>
                            <p class="text-warmgray">% Fermentación</p>
                            <p class="font-bold text-lg"><?= $loteInfo['porcentaje_fermentacion'] ? number_format($loteInfo['porcentaje_fermentacion'], 1) . '%' : 'N/R' ?></p>
                        </div>
                        <div>
                            <p class="text-warmgray">Calidad</p>
                            <p class="font-bold text-lg"><?= $loteInfo['calidad_resultado'] ?? 'N/R' ?></p>
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
                                (<?= $lote['calidad_final'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Configuración de Empaque -->
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title">Configuración de Empaque</h3>
        </div>
        <div class="card-body">
            <div class="form-group mb-6">
                <label class="form-label required">Tipo de Empaque</label>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <?php foreach ($tiposEmpaque as $tipo): ?>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="tipo_empaque" value="<?= $tipo['codigo'] ?>" 
                                   class="sr-only peer" required
                                   data-peso="<?= $tipo['peso'] ?>"
                                   <?= (isset($_POST['tipo_empaque']) && $_POST['tipo_empaque'] === $tipo['codigo']) ? 'checked' : '' ?>
                                   onchange="actualizarPeso(this)">
                            <div class="p-4 border-2 rounded-lg text-center transition-all peer-checked:border-primary peer-checked:bg-olive/20 hover:border-olive">
                                <svg class="w-8 h-8 mx-auto mb-2 text-warmgray" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                                <p class="font-semibold"><?= $tipo['nombre'] ?></p>
                                <?php if ($tipo['peso'] > 0): ?>
                                    <p class="text-xs text-warmgray"><?= $tipo['peso'] ?> kg</p>
                                <?php endif; ?>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="form-group">
                    <label class="form-label required">Peso por Saco (kg)</label>
                    <input type="number" name="peso_saco" id="peso_saco" class="form-control" required
                           step="0.01" min="0.1" max="1500"
                           value="<?= $_POST['peso_saco'] ?? '50' ?>">
                    <p class="text-xs text-warmgray mt-1">Peso estándar por unidad de empaque</p>
                </div>
                
                <?php if ($loteInfo && $loteInfo['peso_secado']): ?>
                <div class="form-group">
                    <label class="form-label">Sacos Estimados</label>
                    <div class="form-control bg-olive/10 font-bold text-primary" id="sacos_estimados">
                        <?= floor($loteInfo['peso_secado'] / 50) ?> sacos
                    </div>
                    <p class="text-xs text-warmgray mt-1">Basado en peso disponible</p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="form-group mt-6">
                <label class="form-label">Observaciones</label>
                <textarea name="observaciones" class="form-control" rows="3"
                          placeholder="Instrucciones especiales de empaque, destino, etc..."><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
    
    <!-- Información de Empaque -->
    <div class="card mb-6 bg-blue-50 border-blue-200">
        <div class="card-body">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <h4 class="font-semibold text-blue-900">Información de Empaque</h4>
                    <ul class="mt-2 text-sm text-blue-800 space-y-1">
                        <li>• <strong>Exportación:</strong> Sacos de yute de 46 kg con etiqueta de trazabilidad</li>
                        <li>• <strong>Nacional:</strong> Sacos de polipropileno de 50 kg</li>
                        <li>• <strong>Premium:</strong> Empaque especial según especificaciones del cliente</li>
                        <li>• Registrar número de lote en cada saco</li>
                        <li>• Verificar que la humedad sea ≤7% antes de empacar</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Botones -->
    <div class="flex items-center gap-4">
        <button type="submit" class="btn btn-primary">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            Iniciar Empaquetado
        </button>
        <a href="<?= APP_URL ?>/empaquetado/index.php" class="btn btn-outline">Cancelar</a>
    </div>
</form>

<script>
function actualizarPeso(radio) {
    const peso = radio.dataset.peso;
    if (peso && peso > 0) {
        document.getElementById('peso_saco').value = peso;
        calcularSacos();
    }
}

function calcularSacos() {
    const pesoDisponible = <?= $loteInfo['peso_secado'] ?? 0 ?>;
    const pesoSaco = parseFloat(document.getElementById('peso_saco').value) || 50;
    
    if (pesoDisponible > 0 && pesoSaco > 0) {
        const sacos = Math.floor(pesoDisponible / pesoSaco);
        const sacosEl = document.getElementById('sacos_estimados');
        if (sacosEl) {
            sacosEl.textContent = sacos + ' sacos';
        }
    }
}

document.getElementById('peso_saco').addEventListener('input', calcularSacos);
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
