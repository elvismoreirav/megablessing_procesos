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
$tempMinFermentacion = 35.0;
$tempMaxFermentacion = 50.0;
Helpers::ensureFermentacionControlMedicionesColumns();

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
$horaAmCol = $hasCtrlCol('hora_am') ? 'hora_am' : null;
$horaPmCol = $hasCtrlCol('hora_pm') ? 'hora_pm' : null;
$volteoAmCol = $hasCtrlCol('volteo_am') ? 'volteo_am' : null;
$volteoPmCol = $hasCtrlCol('volteo_pm') ? 'volteo_pm' : null;
$temp20Col = $hasCtrlCol('temp_20h') ? 'temp_20h' : null;
$temp22Col = $hasCtrlCol('temp_22h') ? 'temp_22h' : null;
$temp24Col = $hasCtrlCol('temp_24h') ? 'temp_24h' : null;
$temp02Col = $hasCtrlCol('temp_02h') ? 'temp_02h' : null;
$temp04Col = $hasCtrlCol('temp_04h') ? 'temp_04h' : null;
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
    $normalizarHora = static function ($valor): ?string {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $valor)) {
            return $valor . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $valor)) {
            return $valor;
        }

        return null;
    };
    
    foreach ($controles as $control) {
        $dia = intval($control['dia']);
        $fecha = $control['fecha'];
        $horaAm = $normalizarHora($control['hora_am'] ?? '');
        $horaPm = $normalizarHora($control['hora_pm'] ?? '');
        $tempAm = isset($control['temp_am']) && $control['temp_am'] !== '' ? floatval($control['temp_am']) : null;
        $tempPm = isset($control['temp_pm']) && $control['temp_pm'] !== '' ? floatval($control['temp_pm']) : null;
        $volteoAm = array_key_exists('volteo_am', $control) && $control['volteo_am'] !== null && $control['volteo_am'] !== ''
            ? intval($control['volteo_am'])
            : null;
        $volteoPm = array_key_exists('volteo_pm', $control) && $control['volteo_pm'] !== null && $control['volteo_pm'] !== ''
            ? intval($control['volteo_pm'])
            : null;

        if (($control['hora_am'] ?? '') !== '' && $horaAm === null) {
            Helpers::jsonResponse([
                'success' => false,
                'error' => "Hora inválida en la medición de mañana del día {$dia}. Use formato HH:MM."
            ]);
        }
        if (($control['hora_pm'] ?? '') !== '' && $horaPm === null) {
            Helpers::jsonResponse([
                'success' => false,
                'error' => "Hora inválida en la medición de tarde del día {$dia}. Use formato HH:MM."
            ]);
        }

        $mediciones = [
            'mañana' => ['hora' => $horaAm, 'temp' => $tempAm, 'volteo' => $volteoAm],
            'tarde' => ['hora' => $horaPm, 'temp' => $tempPm, 'volteo' => $volteoPm],
        ];

        foreach ($mediciones as $etiqueta => $medicion) {
            $tieneDatos = $medicion['hora'] !== null || $medicion['temp'] !== null || $medicion['volteo'] !== null;
            if (!$tieneDatos) {
                continue;
            }

            if ($medicion['hora'] === null) {
                Helpers::jsonResponse([
                    'success' => false,
                    'error' => "Debe registrar la hora de la medición de {$etiqueta} en el día {$dia}."
                ]);
            }
            if ($medicion['temp'] === null) {
                Helpers::jsonResponse([
                    'success' => false,
                    'error' => "Debe registrar la temperatura de la medición de {$etiqueta} en el día {$dia}."
                ]);
            }
            if ($medicion['volteo'] === null || !in_array($medicion['volteo'], [0, 1], true)) {
                Helpers::jsonResponse([
                    'success' => false,
                    'error' => "Debe indicar si hubo volteo en la medición de {$etiqueta} del día {$dia}."
                ]);
            }
            if ($medicion['temp'] < $tempMinFermentacion || $medicion['temp'] > $tempMaxFermentacion) {
                Helpers::jsonResponse([
                    'success' => false,
                    'error' => "Temperatura fuera de rango en la medición de {$etiqueta} del día {$dia}. Debe estar entre {$tempMinFermentacion}°C y {$tempMaxFermentacion}°C."
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

        if ($obsCol) {
            $datos[$obsCol] = trim($control['observaciones'] ?? '');
        }

        if ($hasCtrlCol('fecha')) {
            $datos['fecha'] = $fecha;
        }
        if ($horaAmCol) {
            $datos[$horaAmCol] = $horaAm;
        }
        if ($volteoAmCol) {
            $datos[$volteoAmCol] = $volteoAm;
        }
        if ($tempAmCol) {
            $datos[$tempAmCol] = $tempAm;
        }
        if ($horaPmCol) {
            $datos[$horaPmCol] = $horaPm;
        }
        if ($volteoPmCol) {
            $datos[$volteoPmCol] = $volteoPm;
        }
        if ($tempPmCol) {
            $datos[$tempPmCol] = $tempPm;
        }
        if ($temp20Col) {
            $datos[$temp20Col] = null;
        }
        if ($temp22Col) {
            $datos[$temp22Col] = null;
        }
        if ($temp24Col) {
            $datos[$temp24Col] = null;
        }
        if ($temp02Col) {
            $datos[$temp02Col] = null;
        }
        if ($temp04Col) {
            $datos[$temp04Col] = null;
        }
        if ($phAmCol) {
            $datos[$phAmCol] = null;
        }
        if ($phPmCol) {
            $datos[$phPmCol] = null;
        }
        if ($volteoCol) {
            $datos[$volteoCol] = ($volteoAm === 1 || $volteoPm === 1) ? 1 : 0;
        }
        if ($horaCol) {
            if ($volteoPm === 1 && $horaPm !== null) {
                $datos[$horaCol] = $horaPm;
            } elseif ($volteoAm === 1 && $horaAm !== null) {
                $datos[$horaCol] = $horaAm;
            } elseif ($horaPm !== null) {
                $datos[$horaCol] = $horaPm;
            } else {
                $datos[$horaCol] = $horaAm;
            }
        }
        
        if ($existente) {
            // Actualizar
            unset($datos[$fkControlCol], $datos['dia']);
            $db->update('fermentacion_control_diario', $datos, 'id = :id', ['id' => $existente['id']]);
        } else {
            // Insertar solo si hay datos
            $tieneData = ($obsCol && !empty($datos[$obsCol]));
            if (!$tieneData && $horaAmCol && !empty($datos[$horaAmCol])) $tieneData = true;
            if (!$tieneData && $horaPmCol && !empty($datos[$horaPmCol])) $tieneData = true;
            if (!$tieneData && $volteoAmCol && $datos[$volteoAmCol] !== null) $tieneData = true;
            if (!$tieneData && $volteoPmCol && $datos[$volteoPmCol] !== null) $tieneData = true;
            if (!$tieneData && $tempAmCol && $datos[$tempAmCol] !== null) $tieneData = true;
            if (!$tieneData && $tempPmCol && $datos[$tempPmCol] !== null) $tieneData = true;
            
            if ($tieneData) {
                $db->insert('fermentacion_control_diario', $datos);
            }
        }
        
        if ($volteoAm === 1) {
            $totalVolteos++;
        }
        if ($volteoPm === 1) {
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
