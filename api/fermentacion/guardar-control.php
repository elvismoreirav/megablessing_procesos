<?php
/**
 * API: Guardar Control Diario de Fermentación
 */

require_once __DIR__ . '/../../bootstrap.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helpers::jsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

$fermentacionId = $input['fermentacion_id'] ?? null;
$controles = $input['controles'] ?? [];

if (!$fermentacionId || empty($controles)) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Datos incompletos']);
}

$db = Database::getInstance();

// Compatibilidad de esquema
$colsFermentacion = array_column($db->fetchAll("SHOW COLUMNS FROM registros_fermentacion"), 'Field');
$hasFerCol = static fn(string $name): bool => in_array($name, $colsFermentacion, true);
$fechaCierreExpr = $hasFerCol('fecha_fin')
    ? 'fecha_fin'
    : ($hasFerCol('fecha_salida') ? 'fecha_salida' : 'NULL');

$colsControl = array_column($db->fetchAll("SHOW COLUMNS FROM fermentacion_control_diario"), 'Field');
$hasCtrlCol = static fn(string $name): bool => in_array($name, $colsControl, true);
$fkControlCol = $hasCtrlCol('fermentacion_id') ? 'fermentacion_id' : 'registro_fermentacion_id';

$tempAmCol = $hasCtrlCol('temperatura_am') ? 'temperatura_am' : ($hasCtrlCol('temp_masa') ? 'temp_masa' : null);
$tempPmCol = $hasCtrlCol('temperatura_pm') ? 'temperatura_pm' : ($hasCtrlCol('temp_ambiente') ? 'temp_ambiente' : null);
$phAmCol = $hasCtrlCol('ph_am') ? 'ph_am' : ($hasCtrlCol('ph_pulpa') ? 'ph_pulpa' : null);
$phPmCol = $hasCtrlCol('ph_pm') ? 'ph_pm' : ($hasCtrlCol('ph_cotiledon') ? 'ph_cotiledon' : null);
$horaCol = $hasCtrlCol('hora_volteo') ? 'hora_volteo' : ($hasCtrlCol('hora') ? 'hora' : null);
$obsCol = $hasCtrlCol('observaciones') ? 'observaciones' : null;
$volteoCol = $hasCtrlCol('volteo') ? 'volteo' : null;

// Verificar que la fermentación existe y no está finalizada
$fermentacion = $db->fetch("
    SELECT id, lote_id, {$fechaCierreExpr} as fecha_cierre FROM registros_fermentacion WHERE id = :id
", ['id' => $fermentacionId]);

if (!$fermentacion) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Fermentación no encontrada']);
}

if (!empty($fermentacion['fecha_cierre'])) {
    Helpers::jsonResponse(['success' => false, 'error' => 'La fermentación ya está finalizada']);
}

try {
    $db->beginTransaction();
    
    $totalVolteos = 0;
    
    foreach ($controles as $control) {
        $dia = intval($control['dia']);
        $fecha = $control['fecha'];
        
        // Verificar si ya existe
        $existente = $db->fetch("
            SELECT id FROM fermentacion_control_diario 
            WHERE {$fkControlCol} = :fid AND dia = :dia
        ", ['fid' => $fermentacionId, 'dia' => $dia]);
        
        $datos = [
            $fkControlCol => $fermentacionId,
            'dia' => $dia,
        ];

        if ($volteoCol) {
            $datos[$volteoCol] = intval($control['volteo']);
        }
        if ($obsCol) {
            $datos[$obsCol] = trim($control['observaciones'] ?? '');
        }

        if ($hasCtrlCol('fecha')) {
            $datos['fecha'] = $fecha;
        }
        if ($tempAmCol) {
            $datos[$tempAmCol] = !empty($control['temp_am']) ? floatval($control['temp_am']) : null;
        }
        if ($tempPmCol) {
            $datos[$tempPmCol] = !empty($control['temp_pm']) ? floatval($control['temp_pm']) : null;
        }
        if ($phAmCol) {
            $datos[$phAmCol] = !empty($control['ph_am']) ? floatval($control['ph_am']) : null;
        }
        if ($phPmCol) {
            $datos[$phPmCol] = !empty($control['ph_pm']) ? floatval($control['ph_pm']) : null;
        }
        if ($horaCol) {
            $datos[$horaCol] = !empty($control['hora_volteo']) ? $control['hora_volteo'] : null;
        }
        
        if ($existente) {
            // Actualizar
            unset($datos[$fkControlCol], $datos['dia']);
            $db->update('fermentacion_control_diario', $datos, 'id = :id', ['id' => $existente['id']]);
        } else {
            // Insertar solo si hay datos
            $tieneData = ($volteoCol && !empty($datos[$volteoCol])) || ($obsCol && !empty($datos[$obsCol]));
            if (!$tieneData && $tempAmCol && !empty($datos[$tempAmCol])) $tieneData = true;
            if (!$tieneData && $tempPmCol && !empty($datos[$tempPmCol])) $tieneData = true;
            if (!$tieneData && $phAmCol && !empty($datos[$phAmCol])) $tieneData = true;
            if (!$tieneData && $phPmCol && !empty($datos[$phPmCol])) $tieneData = true;
            if (!$tieneData && $horaCol && !empty($datos[$horaCol])) $tieneData = true;
            
            if ($tieneData) {
                $db->insert('fermentacion_control_diario', $datos);
            }
        }
        
        if ($control['volteo']) {
            $totalVolteos++;
        }
    }
    
    // Actualizar total de volteos
    if ($hasFerCol('total_volteos')) {
        $db->update('registros_fermentacion', 
            ['total_volteos' => $totalVolteos], 
            'id = :id', 
            ['id' => $fermentacionId]
        );
    }
    
    $db->commit();
    
    Helpers::jsonResponse(['success' => true, 'message' => 'Control guardado correctamente']);
    
} catch (Exception $e) {
    $db->rollBack();
    Helpers::jsonResponse(['success' => false, 'error' => 'Error al guardar: ' . $e->getMessage()]);
}
