<?php
/**
 * Roles y Permisos
 * Información sobre los roles del sistema
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

Auth::check();
Auth::requireRole(['admin', 'administrador']);

$db = Database::getInstance();

// Obtener conteo de usuarios por rol
$usuariosPorRol = $db->fetchAll("
    SELECT rol, COUNT(*) as count
    FROM usuarios
    WHERE activo = 1
    GROUP BY rol
");
$countsByRol = [];
foreach ($usuariosPorRol as $row) {
    $countsByRol[$row['rol']] = $row['count'];
}

// Definición de roles y permisos
$rolesDefinition = [
    'admin' => [
        'nombre' => 'Administrador',
        'descripcion' => 'Acceso total al sistema. Puede gestionar todos los módulos, usuarios, configuraciones y realizar respaldos.',
        'color' => 'purple',
        'icono' => 'fa-user-shield',
        'permisos' => [
            'Gestionar lotes (crear, editar, eliminar)',
            'Registrar fermentación y secado',
            'Realizar pruebas de corte',
            'Ver todos los reportes',
            'Exportar datos',
            'Gestionar usuarios',
            'Configurar catálogos',
            'Modificar parámetros del sistema',
            'Crear y restaurar respaldos',
            'Acceso a todas las secciones',
        ]
    ],
    'supervisor' => [
        'nombre' => 'Supervisor',
        'descripcion' => 'Supervisa los procesos y tiene acceso a reportes. No puede modificar configuraciones del sistema.',
        'color' => 'blue',
        'icono' => 'fa-user-tie',
        'permisos' => [
            'Gestionar lotes (crear, editar)',
            'Registrar fermentación y secado',
            'Realizar pruebas de corte',
            'Ver todos los reportes',
            'Exportar datos',
            'Ver catálogos (solo lectura)',
            'No puede gestionar usuarios',
            'No puede modificar configuraciones',
        ]
    ],
    'operador' => [
        'nombre' => 'Operador',
        'descripcion' => 'Registra datos de los procesos diarios. Acceso limitado a reportes.',
        'color' => 'green',
        'icono' => 'fa-user',
        'permisos' => [
            'Crear y editar lotes propios',
            'Registrar datos de fermentación',
            'Registrar datos de secado',
            'Realizar pruebas de corte',
            'Ver reportes básicos',
            'No puede eliminar lotes',
            'No puede acceder a configuración',
        ]
    ],
    'consulta' => [
        'nombre' => 'Consulta',
        'descripcion' => 'Solo puede visualizar información. No puede realizar modificaciones.',
        'color' => 'gray',
        'icono' => 'fa-eye',
        'permisos' => [
            'Ver lotes (solo lectura)',
            'Ver procesos de fermentación',
            'Ver procesos de secado',
            'Ver pruebas de corte',
            'Ver reportes',
            'No puede crear ni editar datos',
            'No puede exportar datos',
        ]
    ],
];

$pageTitle = 'Roles y Permisos';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Roles y Permisos</h1>
            <p class="text-gray-600">Información sobre los niveles de acceso del sistema</p>
        </div>
        <a href="/configuracion/" class="text-amber-600 hover:text-amber-700">
            <i class="fas fa-arrow-left mr-2"></i>Volver a Configuración
        </a>
    </div>

    <!-- Info Box -->
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
        <div class="flex items-start gap-3">
            <i class="fas fa-info-circle text-blue-600 mt-0.5"></i>
            <div class="text-sm text-blue-800">
                <p class="font-semibold mb-1">Sobre los Roles</p>
                <p>Los roles definen qué acciones puede realizar cada usuario en el sistema. Para cambiar el rol de un usuario, vaya a <a href="/usuarios/index.php" class="underline hover:text-blue-900">Gestión de Usuarios</a>.</p>
            </div>
        </div>
    </div>

    <!-- Roles Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <?php foreach ($rolesDefinition as $rolKey => $rol): 
            $colorClasses = [
                'purple' => ['bg' => 'bg-purple-500', 'light' => 'from-purple-50 to-indigo-50', 'border' => 'border-purple-200', 'text' => 'text-purple-700', 'badge' => 'bg-purple-100'],
                'blue' => ['bg' => 'bg-blue-500', 'light' => 'from-blue-50 to-cyan-50', 'border' => 'border-blue-200', 'text' => 'text-blue-700', 'badge' => 'bg-blue-100'],
                'green' => ['bg' => 'bg-green-500', 'light' => 'from-green-50 to-emerald-50', 'border' => 'border-green-200', 'text' => 'text-green-700', 'badge' => 'bg-green-100'],
                'gray' => ['bg' => 'bg-gray-500', 'light' => 'from-gray-50 to-slate-50', 'border' => 'border-gray-200', 'text' => 'text-gray-700', 'badge' => 'bg-gray-100'],
            ];
            $colors = $colorClasses[$rol['color']];
            $userCount = $countsByRol[$rolKey] ?? 0;
        ?>
        <div class="bg-white rounded-xl shadow-sm border <?= $colors['border'] ?> overflow-hidden">
            <div class="px-6 py-4 border-b <?= $colors['border'] ?> bg-gradient-to-r <?= $colors['light'] ?>">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 <?= $colors['bg'] ?> rounded-xl flex items-center justify-center">
                            <i class="fas <?= $rol['icono'] ?> text-white text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900"><?= $rol['nombre'] ?></h2>
                            <p class="text-sm text-gray-500">Código: <?= $rolKey ?></p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="<?= $colors['badge'] ?> <?= $colors['text'] ?> px-3 py-1 rounded-full text-sm font-medium">
                            <?= $userCount ?> usuario<?= $userCount != 1 ? 's' : '' ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="p-6">
                <p class="text-gray-600 mb-4"><?= $rol['descripcion'] ?></p>
                
                <h4 class="font-semibold text-gray-900 mb-3">Permisos:</h4>
                <ul class="space-y-2">
                    <?php foreach ($rol['permisos'] as $permiso): 
                        $isNegative = strpos($permiso, 'No puede') === 0;
                    ?>
                    <li class="flex items-start gap-2 text-sm">
                        <i class="fas <?= $isNegative ? 'fa-times text-red-500' : 'fa-check text-green-500' ?> mt-0.5"></i>
                        <span class="<?= $isNegative ? 'text-gray-500' : 'text-gray-700' ?>"><?= $permiso ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Matriz de Permisos -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-amber-50 to-orange-50">
            <h2 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-table text-amber-600 mr-2"></i>Matriz de Permisos
            </h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Módulo / Acción</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-purple-600 uppercase">Admin</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-blue-600 uppercase">Supervisor</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-green-600 uppercase">Operador</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-600 uppercase">Consulta</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php
                    $permisoMatrix = [
                        'Lotes - Ver' => [true, true, true, true],
                        'Lotes - Crear' => [true, true, true, false],
                        'Lotes - Editar' => [true, true, true, false],
                        'Lotes - Eliminar' => [true, false, false, false],
                        'Fermentación - Registrar' => [true, true, true, false],
                        'Secado - Registrar' => [true, true, true, false],
                        'Prueba de Corte - Realizar' => [true, true, true, false],
                        'Reportes - Ver' => [true, true, true, true],
                        'Reportes - Exportar' => [true, true, false, false],
                        'Catálogos - Ver' => [true, true, true, false],
                        'Catálogos - Editar' => [true, false, false, false],
                        'Usuarios - Gestionar' => [true, false, false, false],
                        'Configuración - Acceder' => [true, false, false, false],
                        'Respaldos - Gestionar' => [true, false, false, false],
                    ];
                    foreach ($permisoMatrix as $permiso => $roles):
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm text-gray-700"><?= $permiso ?></td>
                        <?php foreach ($roles as $tiene): ?>
                        <td class="px-4 py-3 text-center">
                            <?php if ($tiene): ?>
                            <span class="text-green-500"><i class="fas fa-check-circle"></i></span>
                            <?php else: ?>
                            <span class="text-gray-300"><i class="fas fa-minus-circle"></i></span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Nota -->
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
        <div class="flex items-start gap-3">
            <i class="fas fa-lightbulb text-amber-600 mt-0.5"></i>
            <div class="text-sm text-amber-800">
                <p class="font-semibold mb-1">Nota</p>
                <p>Los permisos se aplican automáticamente según el rol asignado a cada usuario. Para personalizar permisos individuales, contacte al administrador del sistema.</p>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
