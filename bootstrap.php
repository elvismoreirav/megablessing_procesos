<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Bootstrap - Inicialización del sistema
 */

// 1) Cargar configuración ANTES de usar constantes
require_once __DIR__ . '/config/config.php';

// 2) Sesión (no usar SESSION_NAME ?? ... porque rompe en PHP 8 si no existe la constante)
$sessionName = (defined('SESSION_NAME') && is_string(SESSION_NAME) && SESSION_NAME !== '')
    ? SESSION_NAME
    : 'megablessing_session';

session_name($sessionName);

// Cookie params (opcional)
if (defined('SESSION_LIFETIME') && is_int(SESSION_LIFETIME) && SESSION_LIFETIME > 0) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
}

session_start();

// 3) Autoload
spl_autoload_register(function ($class) {
    $paths = [
        BASE_PATH . '/core/',
        BASE_PATH . '/modules/',
    ];

    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) { require_once $file; return; }

        $dirs = glob($path . '*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $file = $dir . '/' . $class . '.php';
            if (file_exists($file)) { require_once $file; return; }
        }
    }
});

// 4) Core
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Helpers.php';

// DB
$db = Database::getInstance();

/**
 * Redirect helper (varios módulos lo usan)
 */
function redirect(string $path, int $status = 302): void {
    $isAbsolute = preg_match('#^https?://#i', $path);
    $url = $isAbsolute ? $path : rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
    header('Location: ' . $url, true, $status);
    exit;
}

/**
 * Auth guards
 */
function requireAuth(): void {
    if (!Auth::check()) {
        redirect('/login.php');
    }
}

function requirePermission(string $permission): void {
    if (!Auth::hasPermission($permission)) {
        redirect('/dashboard.php?error=permission');
    }
}

/**
 * CSRF
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * ✅ ESTA ES LA FUNCIÓN QUE TE FALTA EN /lotes/crear.php
 */
function csrfField(string $name = '_csrf'): string {
    $token = generateCsrfToken();
    return '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') .
           '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * ✅ ESTA TAMBIÉN (se usa en POST)
 */
function verifyCSRF(string $name = '_csrf'): void {
    $token = $_POST[$name] ?? '';
    if (!$token || !verifyCsrfToken($token)) {
        http_response_code(419);
        die('CSRF inválido. Recarga la página e intenta nuevamente.');
    }
}

/**
 * Flash messages
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Escape helper para vistas
 */
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
