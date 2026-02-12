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

// Compatibilidad de esquema
$colsSecado = array_column($db->fetchAll("SHOW COLUMNS FROM registros_secado"), 'Field');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSecado, true);
$colFechaFinSecado = $hasSecCol('fecha_fin') ? 'fecha_fin' : null;
$selectFechaFinSecado = $colFechaFinSecado ? $colFechaFinSecado : 'NULL';

$colsControl = array_column($db->fetchAll("SHOW COLUMNS FROM secado_control_temperatura"), 'Field');
$hasCtrlCol = static fn(string $name): bool => in_array($name, $colsControl, true);
$fkControlCol = $hasCtrlCol('secado_id') ? 'secado_id' : ($hasCtrlCol('registro_secado_id') ? 'registro_secado_id' : null);
$colFechaControl = $hasCtrlCol('fecha') ? 'fecha' : null;
$colHoraControl = $hasCtrlCol('hora') ? 'hora' : null;
$colTempControl = $hasCtrlCol('temperatura') ? 'temperatura' : null;
$colHumedadControl = $hasCtrlCol('humedad') ? 'humedad' : null;
$colObsControl = $hasCtrlCol('observaciones') ? 'observaciones' : null;
$colTurnoControl = $hasCtrlCol('turno') ? 'turno' : null;
$colRespControl = $hasCtrlCol('responsable_id') ? 'responsable_id' : null;

if (!$fkControlCol) {
    Helpers::jsonResponse(['success' => false, 'error' => 'El esquema de control de secado no es compatible']);
}

// Verificar que el secado existe y no está finalizado
$secado = $db->fetch("
    SELECT id, lote_id, {$selectFechaFinSecado} as fecha_fin FROM registros_secado WHERE id = :id
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
    $db->delete('secado_control_temperatura', "{$fkControlCol} = :id", ['id' => $secadoId]);
    
    // Insertar nuevos controles
    foreach ($controles as $control) {
        $temperatura = isset($control['temperatura']) && $control['temperatura'] !== '' ? floatval($control['temperatura']) : null;
        $humedad = isset($control['humedad']) && $control['humedad'] !== '' ? floatval($control['humedad']) : null;
        $observaciones = trim($control['observaciones'] ?? '');
        $horaControl = trim((string)($control['hora'] ?? ''));
        $turnoControl = 'DIURNO';
        if ($horaControl !== '') {
            $horaEntera = (int)substr($horaControl, 0, 2);
            if ($horaEntera < 6 || $horaEntera > 18) {
                $turnoControl = 'NOCTURNO';
            }
        }
        
        // Solo insertar si tiene al menos un dato
        if ($temperatura !== null || $humedad !== null || $observaciones !== '') {
            $datos = [
                $fkControlCol => $secadoId
            ];
            if ($colFechaControl) {
                $datos[$colFechaControl] = $control['fecha'] ?? date('Y-m-d');
            }
            if ($colHoraControl) {
                $datos[$colHoraControl] = $horaControl !== '' ? $horaControl : null;
            }
            if ($colTempControl) {
                $datos[$colTempControl] = $temperatura;
            }
            if ($colHumedadControl) {
                $datos[$colHumedadControl] = $humedad;
            }
            if ($colObsControl) {
                $datos[$colObsControl] = $observaciones !== '' ? $observaciones : null;
            }
            if ($colTurnoControl) {
                $datos[$colTurnoControl] = $turnoControl;
            }
            if ($colRespControl) {
                $datos[$colRespControl] = getCurrentUserId();
            }

            $db->insert('secado_control_temperatura', $datos);
        }
    }
    
    $db->commit();
    
    Helpers::jsonResponse(['success' => true, 'message' => 'Control guardado correctamente']);
    
} catch (Exception $e) {
    $db->rollBack();
    Helpers::jsonResponse(['success' => false, 'error' => 'Error al guardar: ' . $e->getMessage()]);
}
