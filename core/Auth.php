<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Clase Auth - Autenticación y autorización
 * Desarrollado por: Shalom Software
 */

class Auth {
    private static $rolesCatalogSynced = false;

    private static function normalizeRoleKey(string $roleName): string {
        $key = function_exists('mb_strtolower')
            ? mb_strtolower(trim($roleName), 'UTF-8')
            : strtolower(trim($roleName));
        $key = strtr($key, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
        ]);
        $key = preg_replace('/\s+/', ' ', $key);
        return $key ?? '';
    }

    private static function rolesCatalogDefinition(): array {
        return [
            [
                'nombre' => 'Administrador',
                'descripcion' => 'Acceso total al sistema.',
                'permisos' => [
                    'all' => true,
                ],
            ],
            [
                'nombre' => 'Recepción',
                'descripcion' => 'Gestiona ficha de recepción, codificación e impresión de etiqueta.',
                'permisos' => [
                    'recepcion' => true,
                    'codificacion' => true,
                    'etiqueta' => true,
                ],
            ],
            [
                'nombre' => 'Operaciones',
                'descripcion' => 'Gestiona verificación de lote y procesos de post-cosecha.',
                'permisos' => [
                    'recepcion' => true,
                    'codificacion' => true,
                    'etiqueta' => true,
                    'lotes' => true,
                    'fermentacion' => true,
                    'secado' => true,
                    'prueba_corte' => true,
                    'calidad_salida' => true,
                ],
            ],
            [
                'nombre' => 'Pagos',
                'descripcion' => 'Gestiona registro y consulta de pagos.',
                'permisos' => [
                    'pagos' => true,
                    'codificacion' => true,
                    'etiqueta' => true,
                    'proveedores' => true,
                ],
            ],
            [
                'nombre' => 'Supervisor Planta',
                'descripcion' => 'Acceso a todos los módulos, excepto registro de pagos.',
                'permisos' => [
                    'recepcion' => true,
                    'codificacion' => true,
                    'etiqueta' => true,
                    'proveedores' => true,
                    'lotes' => true,
                    'fermentacion' => true,
                    'secado' => true,
                    'prueba_corte' => true,
                    'calidad_salida' => true,
                    'reportes' => true,
                    'indicadores' => true,
                    'configuracion' => true,
                    'usuarios' => true,
                ],
            ],
            [
                'nombre' => 'Supervisor Centro de Acopio',
                'descripcion' => 'Acceso a todos los módulos, excepto registro de pagos.',
                'permisos' => [
                    'recepcion' => true,
                    'codificacion' => true,
                    'etiqueta' => true,
                    'proveedores' => true,
                    'lotes' => true,
                    'fermentacion' => true,
                    'secado' => true,
                    'prueba_corte' => true,
                    'calidad_salida' => true,
                    'reportes' => true,
                    'indicadores' => true,
                    'configuracion' => true,
                    'usuarios' => true,
                ],
            ],
        ];
    }

    private static function managedModules(): array {
        return [
            'dashboard',
            'recepcion',
            'codificacion',
            'etiqueta',
            'pagos',
            'proveedores',
            'lotes',
            'fermentacion',
            'secado',
            'prueba_corte',
            'calidad_salida',
            'reportes',
            'indicadores',
            'configuracion',
            'usuarios',
        ];
    }

    public static function roleKey(): string {
        return self::normalizeRoleKey((string)($_SESSION['user_rol'] ?? ''));
    }

    public static function modulesForRole(?string $roleName = null): array {
        $roleKey = $roleName !== null
            ? self::normalizeRoleKey($roleName)
            : self::roleKey();

        if ($roleKey === '') {
            return [];
        }

        if ($roleKey === 'administrador') {
            return self::managedModules();
        }

        if ($roleKey === 'recepcion') {
            return ['dashboard', 'recepcion', 'codificacion', 'etiqueta'];
        }

        if ($roleKey === 'operaciones') {
            return [
                'dashboard',
                'recepcion',
                'codificacion',
                'etiqueta',
                'lotes',
                'fermentacion',
                'secado',
                'prueba_corte',
                'calidad_salida',
            ];
        }

        if ($roleKey === 'pagos') {
            return ['dashboard', 'pagos', 'codificacion', 'etiqueta', 'proveedores'];
        }

        if ($roleKey === 'supervisor planta' || $roleKey === 'supervisor centro de acopio') {
            return array_values(array_diff(self::managedModules(), ['pagos']));
        }

        return [];
    }

    public static function hasModuleAccess(string $module): bool {
        $module = strtolower(trim($module));
        if ($module === '') {
            return false;
        }

        if (!self::check()) {
            return false;
        }

        if ($module === 'dashboard') {
            return true;
        }

        $roleModules = self::modulesForRole();
        if (!empty($roleModules)) {
            return in_array($module, $roleModules, true);
        }

        if (self::isAdmin()) {
            return true;
        }

        $permisos = $_SESSION['user_permisos'] ?? [];
        if (isset($permisos[$module])) {
            return $permisos[$module] === true || is_array($permisos[$module]);
        }
        return false;
    }

    public static function hasAnyModuleAccess(array $modules): bool {
        foreach ($modules as $module) {
            if (self::hasModuleAccess((string)$module)) {
                return true;
            }
        }
        return false;
    }

    public static function modulesForRoute(?string $scriptPath = null, ?array $query = null): array {
        $script = str_replace('\\', '/', (string)($scriptPath ?? ($_SERVER['SCRIPT_NAME'] ?? '')));
        $script = '/' . ltrim($script, '/');
        $query = $query ?? $_GET;

        $matches = static function (string $needle) use ($script): bool {
            return $needle !== '' && str_contains($script, $needle);
        };

        if ($matches('/dashboard.php')) {
            return ['dashboard'];
        }

        if ($matches('/api/fermentacion/')) {
            return ['fermentacion'];
        }

        if ($matches('/api/secado/')) {
            return ['secado'];
        }

        if ($matches('/api/prueba-corte/')) {
            return ['prueba_corte'];
        }

        if ($matches('/api/reportes/')) {
            return ['reportes'];
        }

        if ($matches('/fichas/index.php')) {
            $vista = strtolower(trim((string)($query['vista'] ?? 'recepcion')));
            return match ($vista) {
                'pagos' => ['pagos'],
                'codificacion' => ['codificacion'],
                'etiqueta' => ['etiqueta'],
                default => ['recepcion'],
            };
        }

        if ($matches('/fichas/pago.php')) {
            return ['pagos'];
        }

        if ($matches('/fichas/codificacion.php')) {
            return ['codificacion'];
        }

        if ($matches('/fichas/etiqueta.php')) {
            return ['etiqueta'];
        }

        if ($matches('/fichas/crear.php') || $matches('/fichas/editar.php') || $matches('/fichas/eliminar.php')) {
            return ['recepcion'];
        }

        if ($matches('/fichas/ver.php') || $matches('/fichas/consulta.php')) {
            return ['recepcion', 'pagos', 'codificacion', 'etiqueta'];
        }

        if ($matches('/lotes/')) {
            return ['lotes'];
        }

        if ($matches('/fermentacion/')) {
            return ['fermentacion'];
        }

        if ($matches('/secado/')) {
            return ['secado'];
        }

        if ($matches('/prueba-corte/') || $matches('/prueba_corte/')) {
            return ['prueba_corte'];
        }

        if ($matches('/calidad-salida/') || $matches('/calidad_salida/')) {
            return ['calidad_salida'];
        }

        if ($matches('/reportes/indicadores.php') || $matches('/indicadores/')) {
            return ['indicadores'];
        }

        if ($matches('/reportes/')) {
            return ['reportes'];
        }

        if ($matches('/configuracion/proveedores.php')) {
            return ['proveedores'];
        }

        if ($matches('/usuarios/') || $matches('/configuracion/usuarios.php') || $matches('/configuracion/roles.php')) {
            return ['usuarios'];
        }

        if ($matches('/configuracion/')) {
            return ['configuracion'];
        }

        return [];
    }

    public static function canAccessRoute(?string $scriptPath = null, ?array $query = null): bool {
        $modules = self::modulesForRoute($scriptPath, $query);
        if (empty($modules)) {
            return true;
        }
        return self::hasAnyModuleAccess($modules);
    }

    public static function getRolesCatalogDefinition(): array {
        return self::rolesCatalogDefinition();
    }

    public static function ensureRolesCatalog(): void {
        if (self::$rolesCatalogSynced) {
            return;
        }
        self::$rolesCatalogSynced = true;

        try {
            $db = Database::getInstance();
            $rolesTable = $db->fetch("SHOW TABLES LIKE 'roles'");
            if (!$rolesTable) {
                return;
            }

            $roleColumns = array_column($db->fetchAll("SHOW COLUMNS FROM roles"), 'Field');
            if (empty($roleColumns)) {
                return;
            }

            $hasRoleColumn = static fn(string $name): bool => in_array($name, $roleColumns, true);
            if (!$hasRoleColumn('nombre')) {
                return;
            }

            $existingRoles = $db->fetchAll("SELECT id, nombre FROM roles");
            $rolesByKey = [];
            foreach ($existingRoles as $roleRow) {
                $key = self::normalizeRoleKey((string)($roleRow['nombre'] ?? ''));
                if ($key !== '') {
                    $rolesByKey[$key] = $roleRow;
                }
            }

            foreach (self::rolesCatalogDefinition() as $roleDef) {
                $nombre = (string)($roleDef['nombre'] ?? '');
                if ($nombre === '') {
                    continue;
                }

                $payload = ['nombre' => $nombre];
                if ($hasRoleColumn('descripcion')) {
                    $payload['descripcion'] = (string)($roleDef['descripcion'] ?? '');
                }
                if ($hasRoleColumn('permisos')) {
                    $payload['permisos'] = json_encode(
                        $roleDef['permisos'] ?? [],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                }
                if ($hasRoleColumn('activo')) {
                    $payload['activo'] = 1;
                }

                $roleKey = self::normalizeRoleKey($nombre);
                if (isset($rolesByKey[$roleKey])) {
                    $db->update(
                        'roles',
                        $payload,
                        'id = :id',
                        ['id' => (int)$rolesByKey[$roleKey]['id']]
                    );
                } else {
                    $newId = $db->insert('roles', $payload);
                    $rolesByKey[$roleKey] = ['id' => (int)$newId, 'nombre' => $nombre];
                }
            }
        } catch (Throwable $e) {
            // En caso de esquemas parciales o errores de permisos, no bloquear el flujo.
        }
    }
    
    public static function attempt($email, $password) {
        self::ensureRolesCatalog();
        $db = Database::getInstance();
        
        $user = $db->fetch(
            "SELECT u.*, r.nombre as rol_nombre, r.permisos 
             FROM usuarios u 
             JOIN roles r ON u.rol_id = r.id 
             WHERE u.email = :email AND u.activo = 1",
            ['email' => $email]
        );
        
        if ($user && password_verify($password, $user['password'])) {
            // Actualizar último acceso
            $db->update('usuarios', 
                ['ultimo_acceso' => date('Y-m-d H:i:s')], 
                'id = :id', 
                ['id' => $user['id']]
            );
            
            // Guardar en sesión
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['nombre'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_rol'] = $user['rol_nombre'];
            $_SESSION['user_rol_id'] = $user['rol_id'];
            $_SESSION['user_permisos'] = json_decode((string)($user['permisos'] ?? '{}'), true) ?: [];
            $_SESSION['logged_in'] = true;
            
            return true;
        }
        
        return false;
    }
    
    public static function check() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    public static function user() {
        if (!self::check()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'nombre' => $_SESSION['user_name'],
            'email' => $_SESSION['user_email'],
            'rol' => $_SESSION['user_rol'],
            'rol_id' => $_SESSION['user_rol_id'],
            'permisos' => $_SESSION['user_permisos']
        ];
    }
    
    public static function id() {
        return $_SESSION['user_id'] ?? null;
    }
    
    public static function isAdmin() {
        $permisos = $_SESSION['user_permisos'] ?? [];
        return isset($permisos['all']) && $permisos['all'] === true;
    }
    
    public static function hasPermission($permission) {
        if (self::isAdmin()) {
            return true;
        }

        $permission = trim((string)$permission);
        if ($permission === '') {
            return false;
        }

        $managedModules = self::managedModules();
        if (in_array($permission, $managedModules, true)) {
            return self::hasModuleAccess($permission);
        }
        
        $permisos = $_SESSION['user_permisos'] ?? [];
        
        // Verificar permiso exacto
        if (isset($permisos[$permission])) {
            return $permisos[$permission] === true || is_array($permisos[$permission]);
        }
        
        // Verificar permiso con acción específica (ej: "lotes.create")
        $parts = explode('.', $permission);
        if (count($parts) === 2) {
            $module = $parts[0];
            $action = $parts[1];

            if (in_array($module, $managedModules, true)) {
                return self::hasModuleAccess($module);
            }
            
            if (isset($permisos[$module])) {
                if ($permisos[$module] === true) {
                    return true;
                }
                if (is_array($permisos[$module]) && in_array($action, $permisos[$module])) {
                    return true;
                }
            }
        }
        
        // Permiso de solo lectura
        if (isset($permisos['view_only']) && $permisos['view_only'] === true) {
            return strpos($permission, 'view') !== false;
        }
        
        return false;
    }
    
    public static function hasRole($roleName) {
        return isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === $roleName;
    }
    
    public static function logout() {
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    public static function updatePassword($userId, $newPassword) {
        $db = Database::getInstance();
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        return $db->update(
            'usuarios',
            ['password' => $hashedPassword],
            'id = :id',
            ['id' => $userId]
        );
    }
    
    public static function createUser($data) {
        self::ensureRolesCatalog();
        $db = Database::getInstance();
        
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        
        return $db->insert('usuarios', $data);
    }
}
