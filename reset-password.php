<?php
/**
 * Script temporal para resetear contraseña
 * ELIMINAR DESPUÉS DE USAR
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Database.php';

// Nueva contraseña
$nueva_password = 'admin123';
$hash = password_hash($nueva_password, PASSWORD_DEFAULT);

echo "Hash generado para 'admin123':\n";
echo $hash . "\n\n";

// Conectar a la base de datos
$db = Database::getInstance();

// Obtener todos los usuarios
$usuarios = $db->fetchAll("SELECT id, nombre, email, activo FROM usuarios");

echo "Usuarios en el sistema:\n";
echo str_repeat("-", 80) . "\n";
printf("%-5s %-30s %-35s %-10s\n", "ID", "Nombre", "Email", "Activo");
echo str_repeat("-", 80) . "\n";

foreach ($usuarios as $user) {
    printf("%-5s %-30s %-35s %-10s\n", 
        $user['id'], 
        $user['nombre'], 
        $user['email'],
        $user['activo'] ? 'Sí' : 'No'
    );
}

echo "\n" . str_repeat("-", 80) . "\n";
echo "Para resetear la contraseña, ejecuta este script con el ID del usuario:\n";
echo "php reset_password.php <ID_USUARIO>\n\n";

// Si se proporciona un ID de usuario como argumento
if (isset($argv[1])) {
    $userId = (int)$argv[1];
    
    $result = $db->update(
        'usuarios',
        ['password' => $hash],
        'id = :id',
        ['id' => $userId]
    );
    
    if ($result) {
        echo "\n✓ Contraseña actualizada exitosamente para el usuario ID: $userId\n";
        echo "  Nueva contraseña: admin123\n";
        
        // Mostrar datos del usuario actualizado
        $user = $db->fetch("SELECT nombre, email FROM usuarios WHERE id = :id", ['id' => $userId]);
        if ($user) {
            echo "  Usuario: {$user['nombre']}\n";
            echo "  Email: {$user['email']}\n";
        }
    } else {
        echo "\n✗ Error al actualizar la contraseña\n";
    }
}

echo "\n";
