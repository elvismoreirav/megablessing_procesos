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
$temp20Col = $hasCtrlCol('temp_20h') ? 'temp_20h' : null;
$temp22Col = $hasCtrlCol('temp_22h') ? 'temp_22h' : null;
$temp24Col = $hasCtrlCol('temp_24h') ? 'temp_24h' : null;
$temp02Col = $hasCtrlCol('temp_02h') ? 'temp_02h' : null;
$temp04Col = $hasCtrlCol('temp_04h') ? 'temp_04h' : null;
$colsNocturnasDisponibles = $temp20Col && $temp22Col && $temp24Col && $temp02Col && $temp04Col;
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

        $tempAm = isset($control['temp_am']) && $control['temp_am'] !== '' ? floatval($control['temp_am']) : null;
        $tempPm = isset($control['temp_pm']) && $control['temp_pm'] !== '' ? floatval($control['temp_pm']) : null;
        $temp20h = isset($control['temp_20h']) && $control['temp_20h'] !== '' ? floatval($control['temp_20h']) : null;
        $temp22h = isset($control['temp_22h']) && $control['temp_22h'] !== '' ? floatval($control['temp_22h']) : null;
        $temp24h = isset($control['temp_24h']) && $control['temp_24h'] !== '' ? floatval($control['temp_24h']) : null;
        $temp02h = isset($control['temp_02h']) && $control['temp_02h'] !== '' ? floatval($control['temp_02h']) : null;
        $temp04h = isset($control['temp_04h']) && $control['temp_04h'] !== '' ? floatval($control['temp_04h']) : null;

        $temperaturasControl = [
            'AM' => $tempAm,
            'PM' => $tempPm,
            '20h' => $temp20h,
            '22h' => $temp22h,
            '24h' => $temp24h,
            '02h' => $temp02h,
            '04h' => $temp04h,
        ];
        if (!$colsNocturnasDisponibles && ($temp20h !== null || $temp22h !== null || $temp24h !== null || $temp02h !== null || $temp04h !== null)) {
            Helpers::jsonResponse([
                'success' => false,
                'error' => 'Faltan columnas nocturnas en base de datos. Ejecute el patch_fermentacion_secado_etapas.sql para guardar estos campos.'
            ]);
        }
        foreach ($temperaturasControl as $slot => $tempValor) {
            if ($tempValor !== null && ($tempValor < 70 || $tempValor > 130)) {
                Helpers::jsonResponse([
                    'success' => false,
                    'error' => "Temperatura fuera de rango en día {$dia} ({$slot}). Debe estar entre 70°C y 130°C."
                ]);
            }
        }
        
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
            $datos[$tempAmCol] = $tempAm;
        }
        if ($tempPmCol) {
            $datos[$tempPmCol] = $tempPm;
        }
        if ($temp20Col) {
            $datos[$temp20Col] = $temp20h;
        }
        if ($temp22Col) {
            $datos[$temp22Col] = $temp22h;
        }
        if ($temp24Col) {
            $datos[$temp24Col] = $temp24h;
        }
        if ($temp02Col) {
            $datos[$temp02Col] = $temp02h;
        }
        if ($temp04Col) {
            $datos[$temp04Col] = $temp04h;
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
            if (!$tieneData && $tempAmCol && $datos[$tempAmCol] !== null) $tieneData = true;
            if (!$tieneData && $tempPmCol && $datos[$tempPmCol] !== null) $tieneData = true;
            if (!$tieneData && $temp20Col && $datos[$temp20Col] !== null) $tieneData = true;
            if (!$tieneData && $temp22Col && $datos[$temp22Col] !== null) $tieneData = true;
            if (!$tieneData && $temp24Col && $datos[$temp24Col] !== null) $tieneData = true;
            if (!$tieneData && $temp02Col && $datos[$temp02Col] !== null) $tieneData = true;
            if (!$tieneData && $temp04Col && $datos[$temp04Col] !== null) $tieneData = true;
            if (!$tieneData && $phAmCol && $datos[$phAmCol] !== null) $tieneData = true;
            if (!$tieneData && $phPmCol && $datos[$phPmCol] !== null) $tieneData = true;
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
