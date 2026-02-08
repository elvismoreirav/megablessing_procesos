<?php
/**
 * API: Guardar Control de Temperatura de Secado
 */

require_once __DIR__ . '/../../bootstrap.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helpers::jsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

$secadoId = $input['secado_id'] ?? null;
$controles = $input['controles'] ?? [];

if (!$secadoId) {
    Helpers::jsonResponse(['success' => false, 'error' => 'ID de secado requerido']);
}

$db = Database::getInstance();

// Verificar que el secado existe y no está finalizado
$secado = $db->fetch("
    SELECT id, lote_id, fecha_fin FROM registros_secado WHERE id = :id
", ['id' => $secadoId]);

if (!$secado) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Secado no encontrado']);
}

if ($secado['fecha_fin']) {
    Helpers::jsonResponse(['success' => false, 'error' => 'El secado ya está finalizado']);
}

try {
    $db->beginTransaction();
    
    // Eliminar controles anteriores para este secado
    $db->delete('secado_control_temperatura', 'secado_id = :id', ['id' => $secadoId]);
    
    // Insertar nuevos controles
    foreach ($controles as $control) {
        $datos = [
            'secado_id' => $secadoId,
            'fecha' => $control['fecha'],
            'hora' => $control['hora'],
            'temperatura' => isset($control['temperatura']) && $control['temperatura'] !== '' ? floatval($control['temperatura']) : null,
            'humedad' => isset($control['humedad']) && $control['humedad'] !== '' ? floatval($control['humedad']) : null,
            'observaciones' => trim($control['observaciones'] ?? '')
        ];
        
        // Solo insertar si tiene al menos un dato
        if ($datos['temperatura'] !== null || $datos['humedad'] !== null || $datos['observaciones']) {
            $db->insert('secado_control_temperatura', $datos);
        }
    }
    
    $db->commit();
    
    Helpers::jsonResponse(['success' => true, 'message' => 'Control guardado correctamente']);
    
} catch (Exception $e) {
    $db->rollBack();
    Helpers::jsonResponse(['success' => false, 'error' => 'Error al guardar: ' . $e->getMessage()]);
}
