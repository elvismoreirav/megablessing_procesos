<?php
/**
 * API: Actualizar Secado
 */

require_once __DIR__ . '/../../bootstrap.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helpers::jsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

$secadoId = $input['secado_id'] ?? null;
$observaciones = trim($input['observaciones'] ?? '');

if (!$secadoId) {
    Helpers::jsonResponse(['success' => false, 'error' => 'ID de secado requerido']);
}

$db = Database::getInstance();

// Verificar que existe
$secado = $db->fetch("SELECT id, fecha_fin FROM registros_secado WHERE id = :id", ['id' => $secadoId]);

if (!$secado) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Secado no encontrado']);
}

if ($secado['fecha_fin']) {
    Helpers::jsonResponse(['success' => false, 'error' => 'El secado ya está finalizado']);
}

try {
    $db->update('registros_secado', [
        'observaciones' => $observaciones
    ], 'id = :id', ['id' => $secadoId]);
    
    Helpers::jsonResponse(['success' => true, 'message' => 'Observaciones actualizadas']);
    
} catch (Exception $e) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Error al actualizar: ' . $e->getMessage()]);
}
