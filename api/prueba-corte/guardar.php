<?php
/**
 * API: Guardar Prueba de Corte
 * Guarda los resultados del análisis de 100 granos
 */

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Validar campos requeridos
$requiredFields = ['lote_id', 'fecha_prueba', 'total_granos', 'granos_fermentados', 
                   'granos_parcialmente_fermentados', 'granos_pizarra', 'granos_violetas',
                   'granos_mohosos', 'granos_germinados', 'granos_danados', 
                   'porcentaje_fermentacion', 'porcentaje_defectos', 'calidad_determinada'];

foreach ($requiredFields as $field) {
    if (!isset($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Campo requerido: {$field}"]);
        exit;
    }
}

try {
    $db = Database::getInstance();
    $db->beginTransaction();
    
    $loteId = (int)$input['lote_id'];
    
    // Verificar que el lote existe y está en estado correcto
    $lote = $db->fetchOne(
        "SELECT id, codigo, estado_proceso FROM lotes WHERE id = ?",
        [$loteId]
    );
    
    if (!$lote) {
        throw new Exception('Lote no encontrado');
    }
    
    if ($lote['estado_proceso'] !== 'CALIDAD_POST') {
        throw new Exception('El lote no está en estado de prueba de calidad');
    }
    
    // Verificar que no exista ya una prueba de corte para este lote
    $existingTest = $db->fetchOne(
        "SELECT id FROM registros_prueba_corte WHERE lote_id = ?",
        [$loteId]
    );
    
    if ($existingTest) {
        throw new Exception('Ya existe una prueba de corte para este lote');
    }
    
    // Calcular estado de calidad ID basado en la calidad determinada
    $calidadMap = [
        'PREMIUM' => 1,
        'EXPORTACION' => 2,
        'NACIONAL' => 3,
        'RECHAZADO' => 4
    ];
    
    $estadoCalidadId = $calidadMap[$input['calidad_determinada']] ?? 3;
    
    // Insertar registro de prueba de corte
    $db->execute(
        "INSERT INTO registros_prueba_corte (
            lote_id, fecha_prueba, total_granos, humedad,
            granos_fermentados, granos_parcialmente_fermentados,
            granos_pizarra, granos_violetas, granos_mohosos,
            granos_germinados, granos_danados,
            porcentaje_fermentacion, porcentaje_defectos,
            calidad_determinada, observaciones, usuario_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $loteId,
            $input['fecha_prueba'],
            (int)$input['total_granos'],
            (float)($input['humedad'] ?? 0),
            (int)$input['granos_fermentados'],
            (int)$input['granos_parcialmente_fermentados'],
            (int)$input['granos_pizarra'],
            (int)$input['granos_violetas'],
            (int)$input['granos_mohosos'],
            (int)$input['granos_germinados'],
            (int)$input['granos_danados'],
            (float)$input['porcentaje_fermentacion'],
            (float)$input['porcentaje_defectos'],
            $input['calidad_determinada'],
            $input['observaciones'] ?? null,
            Auth::user()['id']
        ]
    );
    
    $pruebaId = $db->lastInsertId();
    
    // Determinar nuevo estado del proceso según calidad
    $nuevoEstado = $input['calidad_determinada'] === 'RECHAZADO' ? 'RECHAZADO' : 'CALIDAD_SALIDA';
    
    // Actualizar lote con calidad final y nuevo estado
    $db->execute(
        "UPDATE lotes SET 
            estado_proceso = ?,
            calidad_final = ?,
            estado_calidad_id = ?,
            updated_at = NOW()
        WHERE id = ?",
        [$nuevoEstado, $input['calidad_determinada'], $estadoCalidadId, $loteId]
    );
    
    // Registrar en historial
    $descripcion = "Prueba de corte completada. Calidad: {$input['calidad_determinada']}. " .
                   "Fermentación: {$input['porcentaje_fermentacion']}%, Defectos: {$input['porcentaje_defectos']}%";
    
    $db->execute(
        "INSERT INTO lotes_historial (lote_id, accion, descripcion, usuario_id, created_at)
        VALUES (?, 'PRUEBA_CORTE', ?, ?, NOW())",
        [$loteId, $descripcion, Auth::user()['id']]
    );
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Prueba de corte guardada exitosamente',
        'data' => [
            'prueba_id' => $pruebaId,
            'lote_id' => $loteId,
            'calidad' => $input['calidad_determinada'],
            'nuevo_estado' => $nuevoEstado
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
