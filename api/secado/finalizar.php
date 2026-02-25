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

// Compatibilidad de esquema
$colsSecado = array_column($db->fetchAll("SHOW COLUMNS FROM registros_secado"), 'Field');
$hasSecCol = static fn(string $name): bool => in_array($name, $colsSecado, true);
$fechaInicioExpr = $hasSecCol('fecha_inicio') ? 'rs.fecha_inicio' : ($hasSecCol('fecha') ? 'rs.fecha' : 'NULL');
$fechaFinExpr = $hasSecCol('fecha_fin') ? 'rs.fecha_fin' : 'NULL';
$colFechaFin = $hasSecCol('fecha_fin') ? 'fecha_fin' : null;
$colPesoFinal = $hasSecCol('peso_final') ? 'peso_final' : null;
$colHumedadFinal = $hasSecCol('humedad_final') ? 'humedad_final' : null;
$etapaProcesoExpr = $hasSecCol('etapa_proceso') ? 'rs.etapa_proceso' : 'NULL';

// Verificar que el secado existe
$secado = $db->fetch("
    SELECT rs.*, l.id as lote_id,
           l.estado_proceso as lote_estado,
           {$fechaInicioExpr} as fecha_inicio_base,
           {$fechaFinExpr} as fecha_fin_base,
           {$etapaProcesoExpr} as etapa_proceso,
           (SELECT COUNT(*) FROM registros_secado rs2 WHERE rs2.lote_id = rs.lote_id AND rs2.id <= rs.id) as orden_etapa
    FROM registros_secado rs
    JOIN lotes l ON rs.lote_id = l.id
    WHERE rs.id = :id
", ['id' => $secadoId]);

if (!$secado) {
    Helpers::jsonResponse(['success' => false, 'error' => 'Secado no encontrado']);
}

$secadoFinalizado = !empty($secado['fecha_fin_base'])
    || (!$colFechaFin && $colHumedadFinal && isset($secado[$colHumedadFinal]) && $secado[$colHumedadFinal] !== null && $secado[$colHumedadFinal] !== '');

if ($secadoFinalizado) {
    Helpers::jsonResponse(['success' => false, 'error' => 'El secado ya está finalizado']);
}

$etapaSecado = strtoupper(trim((string)($secado['etapa_proceso'] ?? '')));
if (!in_array($etapaSecado, ['PRE_SECADO', 'SECADO_FINAL'], true)) {
    $estadoLote = strtoupper(trim((string)($secado['lote_estado'] ?? '')));
    $etapaSecado = (
        ((int)($secado['orden_etapa'] ?? 1) > 1)
        || in_array($estadoLote, ['SECADO', 'CALIDAD_POST', 'CALIDAD_SALIDA', 'EMPAQUETADO', 'ALMACENADO', 'DESPACHO', 'FINALIZADO'], true)
    ) ? 'SECADO_FINAL' : 'PRE_SECADO';
}

// Validar fecha
if (!empty($secado['fecha_inicio_base']) && $fechaFin < $secado['fecha_inicio_base']) {
    Helpers::jsonResponse(['success' => false, 'error' => 'La fecha de fin no puede ser anterior a la fecha de inicio']);
}

try {
    $db->beginTransaction();
    
    // Actualizar registro de secado
    $dataSecado = [];
    if ($colFechaFin) {
        $dataSecado[$colFechaFin] = $fechaFin;
    }
    if ($colPesoFinal) {
        $dataSecado[$colPesoFinal] = $pesoFinal;
    }
    if ($colHumedadFinal) {
        $dataSecado[$colHumedadFinal] = $humedadFinal;
    }
    if (!empty($dataSecado)) {
        $db->update('registros_secado', $dataSecado, 'id = :id', ['id' => $secadoId]);
    }

    if ($etapaSecado === 'PRE_SECADO') {
        $db->update('lotes', [
            'estado_proceso' => 'FERMENTACION'
        ], 'id = :id', ['id' => $secado['lote_id']]);

        $msg = 'Pre-secado finalizado (Humedad: ' . ($humedadFinal ? number_format($humedadFinal, 1) . '%' : 'N/R') . ')';
        Helpers::logHistory($secado['lote_id'], 'FERMENTACION', $msg);

        $registroFermentacion = $db->fetch(
            "SELECT id FROM registros_fermentacion WHERE lote_id = ? ORDER BY id DESC LIMIT 1",
            [$secado['lote_id']]
        );
        $redirectUrl = $registroFermentacion
            ? (APP_URL . '/fermentacion/control.php?id=' . (int)$registroFermentacion['id'])
            : (APP_URL . '/fermentacion/crear.php?lote_id=' . (int)$secado['lote_id']);
        $mensaje = 'Pre-secado finalizado correctamente. Continúe con fermentación.';
    } else {
        $db->update('lotes', [
            'estado_proceso' => 'CALIDAD_POST',
            'humedad_final' => $humedadFinal,
            'peso_final_kg' => $pesoFinal
        ], 'id = :id', ['id' => $secado['lote_id']]);

        $msg = 'Secado final finalizado (Humedad: ' . ($humedadFinal ? number_format($humedadFinal, 1) . '%' : 'N/R') . ')';
        Helpers::logHistory($secado['lote_id'], 'CALIDAD_POST', $msg);

        $pruebaExistente = $db->fetch(
            "SELECT id FROM registros_prueba_corte WHERE lote_id = ? ORDER BY id DESC LIMIT 1",
            [$secado['lote_id']]
        );
        $redirectUrl = $pruebaExistente
            ? (APP_URL . '/prueba-corte/ver.php?id=' . (int)$pruebaExistente['id'])
            : (APP_URL . '/prueba-corte/crear.php?lote_id=' . (int)$secado['lote_id']);
        $mensaje = 'Secado final finalizado correctamente.';
    }

    $db->commit();
    
    Helpers::jsonResponse([
        'success' => true, 
        'message' => $mensaje,
        'redirect' => $redirectUrl
    ]);
    
} catch (Throwable $e) {
    if ($db->getConnection()->inTransaction()) {
        $db->rollBack();
    }
    Helpers::jsonResponse(['success' => false, 'error' => 'Error al finalizar: ' . $e->getMessage()]);
}
