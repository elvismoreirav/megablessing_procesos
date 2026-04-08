<?php
/**
 * Alias legacy del modulo de usuarios.
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

if (!Auth::canManageUsers()) {
    setFlash('danger', 'No tiene permisos para acceder a esta sección.');
    redirect('/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setFlash('warning', 'La gestión de usuarios se atiende desde el módulo principal.');
    redirect('/usuarios/index.php');
}

$query = $_GET ? ('?' . http_build_query($_GET)) : '';
redirect('/usuarios/index.php' . $query);
