<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Eliminar Lote
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    setFlash('danger', 'Método no permitido para eliminar lotes.');
    redirect('/lotes/index.php');
}

$id = (int)($_REQUEST['id'] ?? 0);
$token = trim((string)($_REQUEST['token'] ?? ($_REQUEST['_csrf'] ?? '')));

if ($id <= 0) {
    setFlash('danger', 'Lote no especificado.');
    redirect('/lotes/index.php');
}

if ($token === '' || !verifyCsrfToken($token)) {
    setFlash('danger', 'Token de seguridad inválido. Recargue la página e intente nuevamente.');
    redirect('/lotes/editar.php?id=' . $id);
}

$lote = $db->fetch(
    "SELECT l.id, l.codigo, p.nombre AS proveedor
     FROM lotes l
     JOIN proveedores p ON p.id = l.proveedor_id
     WHERE l.id = ?",
    [$id]
);

if (!$lote) {
    setFlash('danger', 'El lote no existe o ya fue eliminado.');
    redirect('/lotes/index.php');
}

try {
    $db->beginTransaction();
    $deleted = $db->delete('lotes', 'id = :id', ['id' => $id]);

    if ($deleted < 1) {
        throw new RuntimeException('No se encontró un registro para eliminar.');
    }

    $db->commit();

    setFlash('success', 'Lote ' . $lote['codigo'] . ' eliminado correctamente.');
} catch (Throwable $e) {
    if ($db->getConnection()->inTransaction()) {
        $db->rollback();
    }

    $mensaje = 'No se pudo eliminar el lote.';
    if (stripos($e->getMessage(), 'foreign key constraint fails') !== false) {
        $mensaje = 'No se pudo eliminar el lote porque tiene registros relacionados.';
    }
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $mensaje .= ' (' . $e->getMessage() . ')';
    }

    setFlash('danger', $mensaje);
}

redirect('/lotes/index.php');
