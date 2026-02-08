<?php
/**
 * API: Actualizar Fermentación
 */

require_once __DIR__ . '/../../bootstrap.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helpers::jsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

$fermentacionId = $input['fermentacion_id'] ?? null;
$observaciones = trim($input['observaciones'] ?? '');

if (!$fermentacionId) {
    Helpers::jsonResponse(['success' => false, 'error' => 'ID de fermentación requerido']);
}

$db = Database::getInstance();

// Compatibilidad de esquema
$colsFermentacion = array_column($db->fetchAll("SHOW COLUMNS FROM registros_fermentacion"), 'Field');
$hasFerCol = static fn(string $name): bool => in_array($name, $colsFermentacion, true);
$colFechaCierre = $hasFerCol('fecha_fin') ? 'fecha_fin' : ($hasFerCol('fecha_salida') ? 'fecha_salida' : null);
$colObservaciones = $hasFerCol('observaciones') ? 'observaciones' : ($hasFerCol('observaciones_generales') ? 'observaciones_generales' : null);
$selectFechaCierre = $colFechaCierre ?: 'NULL';

// Verificar que existe
$fermentacion = $db->fetch("SELECT id, {$selectFechaCierre} as fecha_cierre FROM registros_fermentacion WHERE id = :id", ['id' => $fermentacionId]);

if (!$fermentacion) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Fermentación no encontrada']);
}

if (!empty($fermentacion['fecha_cierre'])) {
    Helpers::jsonResponse(['success' => false, 'error' => 'La fermentación ya está finalizada']);
}

try {
    if (!$colObservaciones) {
        Helpers::jsonResponse(['success' => false, 'error' => 'No existe columna de observaciones en registros_fermentacion']);
    }

    $db->update('registros_fermentacion', [
        $colObservaciones => $observaciones
    ], 'id = :id', ['id' => $fermentacionId]);
    
    Helpers::jsonResponse(['success' => true, 'message' => 'Observaciones actualizadas']);
    
} catch (Exception $e) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Error al actualizar: ' . $e->getMessage()]);
}
