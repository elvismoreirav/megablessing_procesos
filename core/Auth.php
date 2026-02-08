<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Clase Auth - Autenticación y autorización
 * Desarrollado por: Shalom Software
 */

class Auth {
    
    public static function attempt($email, $password) {
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
            $_SESSION['user_permisos'] = json_decode($user['permisos'], true);
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
        $db = Database::getInstance();
        
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        
        return $db->insert('usuarios', $data);
    }
}
