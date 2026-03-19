<?php
/**
 * Compatibilidad para descarga legacy de plantilla de proveedores.
 */

require_once __DIR__ . '/../bootstrap.php';

requireAuth();
redirect('/configuracion/parametrizacion-masiva-plantilla.php?modulo=proveedores');
