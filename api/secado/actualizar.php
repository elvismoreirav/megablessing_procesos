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

// Compatibilidad de esquema
$colsSecado = array_column($db->fetchAll("SHOW COLUMNS FROM registros_secado"), 'Field');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSecado, true);
$colFechaFin = $hasSecCol('fecha_fin') ? 'fecha_fin' : null;
$colObservaciones = $hasSecCol('observaciones')
    ? 'observaciones'
    : ($hasSecCol('carga_observaciones')
        ? 'carga_observaciones'
        : ($hasSecCol('revision_observaciones')
            ? 'revision_observaciones'
            : ($hasSecCol('descarga_observaciones') ? 'descarga_observaciones' : null)));
$selectFechaFin = $colFechaFin ? $colFechaFin : 'NULL';

// Verificar que existe
$secado = $db->fetch("SELECT id, {$selectFechaFin} as fecha_fin FROM registros_secado WHERE id = :id", ['id' => $secadoId]);

if (!$secado) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Secado no encontrado']);
}

if ($secado['fecha_fin']) {
    Helpers::jsonResponse(['success' => false, 'error' => 'El secado ya está finalizado']);
}

try {
    if (!$colObservaciones) {
        Helpers::jsonResponse(['success' => true, 'message' => 'Sin cambios disponibles en este esquema']);
    }

    $db->update('registros_secado', [
        $colObservaciones => $observaciones !== '' ? $observaciones : null
    ], 'id = :id', ['id' => $secadoId]);
    
    Helpers::jsonResponse(['success' => true, 'message' => 'Observaciones actualizadas']);
    
} catch (Exception $e) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Error al actualizar: ' . $e->getMessage()]);
}
