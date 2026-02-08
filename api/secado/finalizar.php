<?php
/**
 * API: Finalizar Secado
 */

require_once __DIR__ . '/../../bootstrap.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helpers::jsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

$secadoId = $input['secado_id'] ?? null;
$fechaFin = $input['fecha_fin'] ?? null;
$pesoFinal = isset($input['peso_final']) && $input['peso_final'] !== '' ? floatval($input['peso_final']) : null;
$humedadFinal = isset($input['humedad_final']) && $input['humedad_final'] !== '' ? floatval($input['humedad_final']) : null;

if (!$secadoId || !$fechaFin) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Datos incompletos']);
}

$db = Database::getInstance();

// Verificar que el secado existe
$secado = $db->fetch("
    SELECT rs.*, l.id as lote_id 
    FROM registros_secado rs
    JOIN lotes l ON rs.lote_id = l.id
    WHERE rs.id = :id
", ['id' => $secadoId]);

if (!$secado) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Secado no encontrado']);
}

if ($secado['fecha_fin']) {
    Helpers::jsonResponse(['success' => false, 'error' => 'El secado ya está finalizado']);
}

// Validar fecha
if ($fechaFin < $secado['fecha_inicio']) {
    Helpers::jsonResponse(['success' => false, 'error' => 'La fecha de fin no puede ser anterior a la fecha de inicio']);
}

try {
    $db->beginTransaction();
    
    // Actualizar registro de secado
    $db->update('registros_secado', [
        'fecha_fin' => $fechaFin,
        'peso_final' => $pesoFinal,
        'humedad_final' => $humedadFinal
    ], 'id = :id', ['id' => $secadoId]);
    
    // Actualizar estado del lote a CALIDAD_POST (prueba de corte)
    $db->update('lotes', [
        'estado_proceso' => 'CALIDAD_POST',
        'humedad_final' => $humedadFinal,
        'peso_final_kg' => $pesoFinal
    ], 'id = :id', ['id' => $secado['lote_id']]);
    
    // Registrar historial
    $msg = 'Secado finalizado (Humedad: ' . ($humedadFinal ? number_format($humedadFinal, 1) . '%' : 'N/R') . ')';
    Helpers::logHistory($secado['lote_id'], 'CALIDAD_POST', $msg, getCurrentUserId());
    
    $db->commit();
    
    Helpers::jsonResponse([
        'success' => true, 
        'message' => 'Secado finalizado correctamente',
        'redirect' => APP_URL . '/prueba-corte/index.php'
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    Helpers::jsonResponse(['success' => false, 'error' => 'Error al finalizar: ' . $e->getMessage()]);
}
