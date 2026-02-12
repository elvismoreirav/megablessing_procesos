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
$pesoFinal = isset($input['peso_final']) ? floatval($input['peso_final']) : null;
$humedadFinal = isset($input['humedad_final']) ? floatval($input['humedad_final']) : null;

if (!$fermentacionId || !$fechaFin) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Datos incompletos']);
}

$db = Database::getInstance();

// Compatibilidad de esquema
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

if (!empty($fermentacion['fecha_cierre'])) {
    Helpers::jsonResponse(['success' => false, 'error' => 'La fermentación ya está finalizada']);
}

// Validar fecha
if ($fechaFin < $fermentacion['fecha_inicio']) {
    Helpers::jsonResponse(['success' => false, 'error' => 'La fecha de fin no puede ser anterior a la fecha de inicio']);
}

try {
    $db->beginTransaction();
    
    // Actualizar registro de fermentación
    $datosActualizacion = [];
    if ($colFechaCierre) {
        $datosActualizacion[$colFechaCierre] = $fechaFin;
    }
    if ($hasFerCol('peso_final')) {
        $datosActualizacion['peso_final'] = $pesoFinal;
    }
    if ($hasFerCol('humedad_final')) {
        $datosActualizacion['humedad_final'] = $humedadFinal;
    }
    if ($hasFerCol('aprobado_secado')) {
        $datosActualizacion['aprobado_secado'] = 1;
    }
    if (!empty($datosActualizacion)) {
        $db->update('registros_fermentacion', $datosActualizacion, 'id = :id', ['id' => $fermentacionId]);
    }
    
    // Actualizar estado del lote a SECADO
    $db->update('lotes', [
        'estado_proceso' => 'SECADO',
        'estado_fermentacion_id' => 2 // Fermentado
    ], 'id = :id', ['id' => $fermentacion['lote_id']]);
    
    // Registrar historial
    Helpers::logHistory($fermentacion['lote_id'], 'SECADO', 'Fermentación finalizada, pasa a secado');
    
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
    $message = $requiereRecepcion
        ? 'Fermentación finalizada. Para continuar con secado, complete primero la ficha de recepción.'
        : 'Fermentación finalizada correctamente. Redirigiendo a secado.';
    
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
