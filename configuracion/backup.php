<?php
/**
 * Gestión de Respaldos
 * Backup y restauración de la base de datos
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

if (!Auth::isAdmin() && !Auth::hasPermission('configuracion')) {
    setFlash('danger', 'No tiene permisos para acceder a esta sección.');
    redirect('/dashboard.php');
}

$db = Database::getInstance();
$message = '';
$error = '';

// Directorio de backups
$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_backup') {
        $descripcion = trim($_POST['descripcion'] ?? 'Backup manual');
        
        try {
            // Obtener configuración de BD
            $dbHost = $_ENV['DB_HOST'] ?? 'localhost';
            $dbName = $_ENV['DB_NAME'] ?? 'megablessing_procesos';
            $dbUser = $_ENV['DB_USER'] ?? 'root';
            $dbPass = $_ENV['DB_PASS'] ?? '';
            
            $timestamp = date('Y-m-d_H-i-s');
            $filename = "backup_{$timestamp}.sql";
            $filepath = "{$backupDir}/{$filename}";
            
            // Crear backup usando mysqldump
            $command = sprintf(
                'mysqldump -h %s -u %s %s %s > %s 2>&1',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                $dbPass ? '-p' . escapeshellarg($dbPass) : '',
                escapeshellarg($dbName),
                escapeshellarg($filepath)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($filepath) && filesize($filepath) > 0) {
                // Registrar backup en BD
                $db->query(
                    "INSERT INTO backups (filename, descripcion, size_bytes, usuario_id) VALUES (?, ?, ?, ?)",
                    [$filename, $descripcion, filesize($filepath), Auth::id()]
                );
                $message = 'Backup creado exitosamente: ' . $filename;
            } else {
                // Método alternativo: export via PHP
                $tables = $db->fetchAll("SHOW TABLES");
                $sqlContent = "-- Megablessing Backup\n";
                $sqlContent .= "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
                $sqlContent .= "-- Descripción: {$descripcion}\n\n";
                $sqlContent .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
                
                foreach ($tables as $table) {
                    $tableName = array_values($table)[0];
                    
                    // Estructura
                    $createTable = $db->fetchOne("SHOW CREATE TABLE `{$tableName}`");
                    $sqlContent .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
                    $sqlContent .= $createTable['Create Table'] . ";\n\n";
                    
                    // Datos
                    $rows = $db->fetchAll("SELECT * FROM `{$tableName}`");
                    if (!empty($rows)) {
                        foreach ($rows as $row) {
                            $values = array_map(function($v) use ($db) {
                                if ($v === null) return 'NULL';
                                return "'" . addslashes($v) . "'";
                            }, array_values($row));
                            $sqlContent .= "INSERT INTO `{$tableName}` VALUES (" . implode(', ', $values) . ");\n";
                        }
                        $sqlContent .= "\n";
                    }
                }
                
                $sqlContent .= "SET FOREIGN_KEY_CHECKS=1;\n";
                
                if (file_put_contents($filepath, $sqlContent)) {
                    $db->query(
                        "INSERT INTO backups (filename, descripcion, size_bytes, usuario_id) VALUES (?, ?, ?, ?)",
                        [$filename, $descripcion, filesize($filepath), Auth::id()]
                    );
                    $message = 'Backup creado exitosamente: ' . $filename;
                } else {
                    $error = 'Error al crear el archivo de backup';
                }
            }
        } catch (Exception $e) {
            $error = 'Error al crear backup: ' . $e->getMessage();
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $backup = $db->fetchOne("SELECT * FROM backups WHERE id = ?", [$id]);
        
        if ($backup) {
            $filepath = "{$backupDir}/{$backup['filename']}";
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            $db->query("DELETE FROM backups WHERE id = ?", [$id]);
            $message = 'Backup eliminado';
        }
    } elseif ($action === 'download') {
        $id = (int)($_POST['id'] ?? 0);
        $backup = $db->fetchOne("SELECT * FROM backups WHERE id = ?", [$id]);
        
        if ($backup) {
            $filepath = "{$backupDir}/{$backup['filename']}";
            if (file_exists($filepath)) {
                header('Content-Type: application/sql');
                header('Content-Disposition: attachment; filename="' . $backup['filename'] . '"');
                header('Content-Length: ' . filesize($filepath));
                readfile($filepath);
                exit;
            }
        }
        $error = 'Archivo de backup no encontrado';
    }
}

// Verificar tabla backups existe
try {
    $db->query("SELECT 1 FROM backups LIMIT 1");
} catch (Exception $e) {
    // Crear tabla si no existe
    $db->query("
        CREATE TABLE IF NOT EXISTS backups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            descripcion TEXT,
            size_bytes BIGINT DEFAULT 0,
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        )
    ");
}

// Obtener backups
$backups = $db->fetchAll("
    SELECT b.*, u.nombre as usuario_nombre
    FROM backups b
    LEFT JOIN usuarios u ON b.usuario_id = u.id
    ORDER BY b.created_at DESC
");

// Verificar archivos existen
foreach ($backups as &$backup) {
    $filepath = "{$backupDir}/{$backup['filename']}";
    $backup['file_exists'] = file_exists($filepath);
    if ($backup['file_exists']) {
        $backup['size_bytes'] = filesize($filepath);
    }
}

// Estadísticas
$totalBackups = count($backups);
$totalSize = array_sum(array_column($backups, 'size_bytes'));
$lastBackup = !empty($backups) ? $backups[0]['created_at'] : null;

// Helper para formatear bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

$pageTitle = 'Gestión de Respaldos';
ob_start();
?>

<div class="max-w-7xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-primary">Gestión de Respaldos</h1>
            <p class="text-warmgray">Backup y restauración de la base de datos</p>
        </div>
        <a href="/configuracion/"
           class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
            <i class="fas fa-arrow-left"></i>
            Volver a Configuración
        </a>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success" data-auto-dismiss>
        <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger" data-auto-dismiss>
        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-database text-blue-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900"><?= $totalBackups ?></p>
                    <p class="text-sm text-gray-500">Backups guardados</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-amber-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-hdd text-amber-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900"><?= formatBytes($totalSize) ?></p>
                    <p class="text-sm text-gray-500">Espacio utilizado</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-clock text-green-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-lg font-bold text-gray-900">
                        <?= $lastBackup ? date('d/m/Y H:i', strtotime($lastBackup)) : 'Nunca' ?>
                    </p>
                    <p class="text-sm text-gray-500">Último backup</p>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Crear Backup -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-plus text-white"></i>
                    </div>
                    <h2 class="text-lg font-semibold text-gray-900">Crear Nuevo Backup</h2>
                </div>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create_backup">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                        <input type="text" name="descripcion"
                               placeholder="Ej: Backup antes de actualización"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <button type="submit" class="w-full bg-blue-600 text-white px-4 py-3 rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center gap-2">
                        <i class="fas fa-download"></i>
                        Crear Backup Ahora
                    </button>
                </form>
                
                <div class="mt-4 p-3 bg-blue-50 rounded-lg">
                    <p class="text-xs text-blue-700">
                        <i class="fas fa-info-circle mr-1"></i>
                        El backup incluye todas las tablas, datos de lotes, procesos, usuarios y configuraciones.
                    </p>
                </div>
            </div>

            <!-- Restaurar -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-4">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-gradient-to-br from-amber-500 to-orange-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-upload text-white"></i>
                    </div>
                    <h2 class="text-lg font-semibold text-gray-900">Restaurar Backup</h2>
                </div>
                
                <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg">
                    <p class="text-sm text-amber-800 mb-2">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <strong>Precaución:</strong> Restaurar un backup reemplazará todos los datos actuales.
                    </p>
                    <p class="text-xs text-amber-700">
                        Para restaurar, descargue el archivo .sql y ejecútelo manualmente en la base de datos usando phpMyAdmin o línea de comandos.
                    </p>
                </div>
            </div>

            <!-- Programación automática (info) -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-4">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-pink-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-calendar-alt text-white"></i>
                    </div>
                    <h2 class="text-lg font-semibold text-gray-900">Backups Automáticos</h2>
                </div>
                
                <p class="text-sm text-gray-600 mb-3">
                    Para programar backups automáticos, configure un cron job en el servidor:
                </p>
                
                <div class="bg-gray-900 text-green-400 p-3 rounded-lg text-xs font-mono overflow-x-auto">
                    0 2 * * * php /path/to/backup-cron.php
                </div>
                
                <p class="text-xs text-gray-500 mt-2">
                    Este ejemplo ejecuta un backup diario a las 2:00 AM.
                </p>
            </div>
        </div>

        <!-- Lista de Backups -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-blue-50 to-indigo-50">
                    <h2 class="text-lg font-semibold text-gray-900">
                        Historial de Backups
                    </h2>
                </div>
                
                <div class="divide-y divide-gray-100">
                    <?php foreach ($backups as $backup): ?>
                    <div class="px-6 py-4 hover:bg-gray-50 flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 <?= $backup['file_exists'] ? 'bg-green-100' : 'bg-red-100' ?> rounded-lg flex items-center justify-center">
                                <i class="fas <?= $backup['file_exists'] ? 'fa-file-archive text-green-600' : 'fa-file-excel text-red-600' ?>"></i>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900"><?= htmlspecialchars($backup['filename']) ?></div>
                                <div class="text-sm text-gray-500">
                                    <?= htmlspecialchars($backup['descripcion'] ?: 'Sin descripción') ?>
                                </div>
                                <div class="text-xs text-gray-400 mt-1">
                                    <i class="fas fa-calendar mr-1"></i><?= date('d/m/Y H:i', strtotime($backup['created_at'])) ?>
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-user mr-1"></i><?= htmlspecialchars($backup['usuario_nombre'] ?? 'Sistema') ?>
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-hdd mr-1"></i><?= formatBytes($backup['size_bytes']) ?>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if ($backup['file_exists']): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="download">
                                <input type="hidden" name="id" value="<?= $backup['id'] ?>">
                                <button type="submit" class="px-3 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition-colors text-sm">
                                    <i class="fas fa-download mr-1"></i>Descargar
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="px-3 py-2 bg-red-100 text-red-600 rounded-lg text-sm">
                                <i class="fas fa-exclamation-circle mr-1"></i>Archivo no encontrado
                            </span>
                            <?php endif; ?>
                            
                            <form method="POST" class="inline" onsubmit="return (window.inlineConfirm ? inlineConfirm(event, '¿Eliminar este backup?', 'Eliminar respaldo') : confirm('¿Eliminar este backup?'))">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $backup['id'] ?>">
                                <button type="submit" class="px-3 py-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($backups)): ?>
                    <div class="px-6 py-12 text-center">
                        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-database text-gray-400 text-2xl"></i>
                        </div>
                        <p class="text-gray-500 mb-2">No hay backups guardados</p>
                        <p class="text-sm text-gray-400">Cree su primer backup usando el formulario de la izquierda</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
