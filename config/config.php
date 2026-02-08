<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Archivo de Configuración
 * Desarrollado por: Shalom Software
 */

// Zona horaria
date_default_timezone_set('America/Guayaquil');

// Configuración de la base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'megablessing_procesos');
define('DB_USER', 'root');
define('DB_PASS', '12345678');
define('DB_CHARSET', 'utf8mb4');

// Configuración de la aplicación
define('APP_NAME', 'Megablessing - Control de Procesos');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/megablessing_procesos');
define('APP_DEBUG', true);

// Rutas del sistema
define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOADS_PATH', BASE_PATH . '/uploads');

// Colores Shalom (para uso en PHP si es necesario)
define('COLOR_PRIMARY', '#1e4d39');
define('COLOR_BACKGROUND', '#f9f8f4');
define('COLOR_ACCENT', '#A3B7A5');
define('COLOR_SECONDARY_TEXT', '#73796F');
define('COLOR_HIGHLIGHT', '#D6C29A');

// Configuración de sesión
define('SESSION_NAME', 'megablessing_session');
define('SESSION_LIFETIME', 3600 * 8); // 8 horas

// Configuración de paginación
define('ITEMS_PER_PAGE', 25);

// Configuración de archivos
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf', 'xlsx', 'xls']);

// Mostrar errores en desarrollo
if (APP_DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}
