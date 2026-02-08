<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Fichas de Registro - Eliminar
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /fichas/');
    exit;
}

$db = Database::getInstance();

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['error'] = 'ID de ficha inválido';
    header('Location: /fichas/');
    exit;
}

// Verificar que la ficha existe
$ficha = $db->fetchOne("
    SELECT f.*, l.codigo as lote_codigo
    FROM fichas_registro f
    INNER JOIN lotes l ON f.lote_id = l.id
    WHERE f.id = ?
", [$id]);

if (!$ficha) {
    $_SESSION['error'] = 'Ficha no encontrada';
    header('Location: /fichas/');
    exit;
}

try {
    // Eliminar la ficha
    $db->query("DELETE FROM fichas_registro WHERE id = ?", [$id]);

    // Registrar en historial
    Helpers::registrarHistorial($ficha['lote_id'], 'ficha_eliminada', "Ficha de registro #{$id} eliminada");

    $_SESSION['success'] = "Ficha #{$id} del lote {$ficha['lote_codigo']} eliminada correctamente";

} catch (Exception $e) {
    $_SESSION['error'] = 'Error al eliminar la ficha: ' . $e->getMessage();
}

header('Location: /fichas/');
exit;
