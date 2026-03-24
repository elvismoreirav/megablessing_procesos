<?php
/**
 * API: Finalizar Fermentación
 */

require_once __DIR__ . '/../../bootstrap.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helpers::jsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

$fermentacionId = $input['fermentacion_id'] ?? null;
$fechaFin = $input['fecha_fin'] ?? null;
$pesoFinalUnidad = Helpers::normalizePesoUnit($input['peso_final_unidad'] ?? 'KG');
$pesoFinalIngresado = $input['peso_final'] ?? null;
$pesoFinal = null;
if ($pesoFinalIngresado !== null && $pesoFinalIngresado !== '') {
    $pesoFinal = Helpers::pesoToKg($pesoFinalIngresado, $pesoFinalUnidad);
}
$humedadFinal = isset($input['humedad_final']) ? floatval($input['humedad_final']) : null;

if (!$fermentacionId) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Datos incompletos']);
}

$db = Database::getInstance();

// Compatibilidad de esquema
Helpers::ensureFermentacionFinalColumns();
Helpers::ensureFermentacionPesoUnitColumn();
$colsFermentacion = array_column($db->fetchAll("SHOW COLUMNS FROM registros_fermentacion"), 'Field');
$hasFerCol = static fn(string $name): bool => in_array($name, $colsFermentacion, true);
$colFechaCierre = $hasFerCol('fecha_fin') ? 'fecha_fin' : ($hasFerCol('fecha_salida') ? 'fecha_salida' : null);
$selectFechaCierre = $colFechaCierre ? "rf.{$colFechaCierre}" : "NULL";

// Verificar que la fermentación existe
$fermentacion = $db->fetch("
    SELECT rf.*, l.id as lote_id,
           {$selectFechaCierre} as fecha_cierre
    FROM registros_fermentacion rf
    JOIN lotes l ON rf.lote_id = l.id
    WHERE rf.id = :id
", ['id' => $fermentacionId]);

if (!$fermentacion) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Fermentación no encontrada']);
}

$fermentacionYaFinalizada = !empty($fermentacion['fecha_cierre']);
$pesoFinalActual = isset($fermentacion['peso_final']) && $fermentacion['peso_final'] !== null
    ? (float)$fermentacion['peso_final']
    : null;

if (!$fermentacionYaFinalizada && !$fechaFin) {
    Helpers::jsonResponse(['success' => false, 'error' => 'La fecha de fin es requerida']);
}

if ($fermentacionYaFinalizada) {
    $fechaFin = $fermentacion['fecha_cierre'];
}

// Validar fecha
if ($fechaFin < $fermentacion['fecha_inicio']) {
    Helpers::jsonResponse(['success' => false, 'error' => 'La fecha de fin no puede ser anterior a la fecha de inicio']);
}

if ($pesoFinal !== null && $pesoFinal <= 0) {
    Helpers::jsonResponse(['success' => false, 'error' => 'El peso final debe ser mayor a 0']);
}

if ($pesoFinalActual === null && $pesoFinal === null) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Debe registrar el peso final de fermentación']);
}

try {
    $db->beginTransaction();
    
    // Actualizar registro de fermentación
    $datosActualizacion = [];
    if ($colFechaCierre && !$fermentacionYaFinalizada) {
        $datosActualizacion[$colFechaCierre] = $fechaFin;
    }
    if ($hasFerCol('peso_final') && $pesoFinal !== null) {
        $datosActualizacion['peso_final'] = $pesoFinal;
    }
    if ($hasFerCol('unidad_peso') && $pesoFinal !== null) {
        $datosActualizacion['unidad_peso'] = $pesoFinalUnidad;
    }
    if ($hasFerCol('humedad_final') && $humedadFinal !== null) {
        $datosActualizacion['humedad_final'] = $humedadFinal;
    }
    if ($hasFerCol('aprobado_secado')) {
        $datosActualizacion['aprobado_secado'] = 1;
    }
    if (!empty($datosActualizacion)) {
        $db->update('registros_fermentacion', $datosActualizacion, 'id = :id', ['id' => $fermentacionId]);
    }
    
    if (!$fermentacionYaFinalizada) {
        // Actualizar estado del lote a SECADO
        $db->update('lotes', [
            'estado_proceso' => 'SECADO',
            'estado_fermentacion_id' => 2 // Fermentado
        ], 'id = :id', ['id' => $fermentacion['lote_id']]);

        // Registrar historial
        Helpers::logHistory($fermentacion['lote_id'], 'SECADO', 'Fermentación finalizada, pasa a secado');
    }
    
    $db->commit();

    $fichaRegistro = $db->fetch(
        "SELECT id FROM fichas_registro WHERE lote_id = ? ORDER BY id DESC LIMIT 1",
        [$fermentacion['lote_id']]
    );
    $requiereRecepcion = !$fichaRegistro;

    $secadoExistente = $db->fetch(
        "SELECT id FROM registros_secado WHERE lote_id = ? ORDER BY id DESC LIMIT 1",
        [$fermentacion['lote_id']]
    );
    $redirectUrl = $secadoExistente
        ? (APP_URL . '/secado/control.php?id=' . (int)$secadoExistente['id'])
        : (APP_URL . '/secado/crear.php?lote_id=' . (int)$fermentacion['lote_id']);
    $message = $fermentacionYaFinalizada
        ? 'Datos finales de fermentación actualizados. Redirigiendo a secado.'
        : ($requiereRecepcion
        ? 'Fermentación finalizada. Para continuar con secado, complete primero la ficha de recepción.'
        : 'Fermentación finalizada correctamente. Redirigiendo a secado.');
    
    Helpers::jsonResponse([
        'success' => true, 
        'message' => $message,
        'requires_recepcion' => $requiereRecepcion,
        'redirect' => $redirectUrl
    ]);
    
} catch (Throwable $e) {
    if ($db->getConnection()->inTransaction()) {
        $db->rollBack();
    }
    Helpers::jsonResponse(['success' => false, 'error' => 'Error al finalizar: ' . $e->getMessage()]);
}
