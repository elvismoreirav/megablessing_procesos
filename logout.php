<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Cierre de Sesión
 */
require_once __DIR__ . '/bootstrap.php';

Auth::logout();
header('Location: login.php');
exit;
