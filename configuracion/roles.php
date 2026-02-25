<?php
/**
 * Roles y Permisos
 * Información sobre los roles del sistema
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

if (!Auth::isAdmin() && !Auth::hasPermission('configuracion')) {
    setFlash('danger', 'No tiene permisos para acceder a esta sección.');
    redirect('/dashboard.php');
}

$db = Database::getInstance();

// Obtener conteo de usuarios por rol (compatibilidad: esquema legado con `rol` o nuevo con `rol_id`).
$columnasUsuarios = array_column($db->fetchAll("SHOW COLUMNS FROM usuarios"), 'Field');
$usaRolDirecto = in_array('rol', $columnasUsuarios, true);

if ($usaRolDirecto) {
    $usuariosPorRol = $db->fetchAll("
        SELECT rol, COUNT(*) as count
        FROM usuarios
        WHERE activo = 1
        GROUP BY rol
    ");
} else {
    $usuariosPorRol = $db->fetchAll("
        SELECT LOWER(COALESCE(r.nombre, 'consulta')) as rol, COUNT(*) as count
        FROM usuarios u
        LEFT JOIN roles r ON u.rol_id = r.id
        WHERE u.activo = 1
        GROUP BY rol
    ");
}

$normalizeRoleKey = static function (string $value): string {
    $normalize = function_exists('mb_strtolower')
        ? mb_strtolower(trim($value), 'UTF-8')
        : strtolower(trim($value));
    $normalize = strtr($normalize, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
    ]);
    $normalize = preg_replace('/\s+/', '_', $normalize);
    return preg_replace('/[^a-z0-9_]/', '', (string)$normalize);
};

$countsByRol = [];
foreach ($usuariosPorRol as $row) {
    $rolKey = $normalizeRoleKey((string)$row['rol']);
    if ($rolKey === '') {
        continue;
    }
    $countsByRol[$rolKey] = (int)$row['count'];
}

// Definición de roles y permisos (alineada a correcciones del aplicativo).
$rolesDefinition = [
    'administrador' => [
        'nombre' => 'Administrador',
        'descripcion' => 'Acceso total al sistema.',
        'color' => 'purple',
        'icono' => 'fa-user-shield',
        'permisos' => [
            'Acceso completo a todos los módulos',
            'Gestión de usuarios y configuración',
            'Visualización y edición total',
        ]
    ],
    'recepcion' => [
        'nombre' => 'Recepción',
        'descripcion' => 'Gestiona ficha de recepción, codificación e impresión de etiqueta.',
        'color' => 'emerald',
        'icono' => 'fa-truck-loading',
        'permisos' => [
            'Recepción',
            'Codificación de lote',
            'Imprimir etiqueta',
        ]
    ],
    'operaciones' => [
        'nombre' => 'Operaciones',
        'descripcion' => 'Acceso a módulos de centro de acopio y procesos de planta.',
        'color' => 'amber',
        'icono' => 'fa-industry',
        'permisos' => [
            'Recepción',
            'Codificación de lote',
            'Imprimir etiqueta',
            'Verificación de lote',
            'Fermentación',
            'Secado',
            'Prueba de corte',
            'Calidad de salida',
        ]
    ],
    'pagos' => [
        'nombre' => 'Pagos',
        'descripcion' => 'Acceso a pagos, codificación, etiqueta y proveedores.',
        'color' => 'teal',
        'icono' => 'fa-money-bill-wave',
        'permisos' => [
            'Registro de pagos',
            'Codificación de lote',
            'Imprimir etiqueta',
            'Configuración: Proveedores',
        ]
    ],
    'supervisor_planta' => [
        'nombre' => 'Supervisor Planta',
        'descripcion' => 'Acceso a todos los módulos, excepto registro de pagos.',
        'color' => 'blue',
        'icono' => 'fa-user-tie',
        'permisos' => [
            'Todos los módulos',
            'No puede acceder a Registro de pagos',
        ]
    ],
    'supervisor_centro_de_acopio' => [
        'nombre' => 'Supervisor Centro de Acopio',
        'descripcion' => 'Acceso a todos los módulos, excepto registro de pagos.',
        'color' => 'indigo',
        'icono' => 'fa-warehouse',
        'permisos' => [
            'Todos los módulos',
            'No puede acceder a Registro de pagos',
        ]
    ],
];

$pageTitle = 'Roles y Permisos';
ob_start();
?>

<div class="max-w-7xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-primary">Roles y Permisos</h1>
            <p class="text-warmgray">Información sobre los niveles de acceso del sistema</p>
        </div>
        <a href="/configuracion/"
           class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
            <i class="fas fa-arrow-left"></i>
            Volver a Configuración
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
                'emerald' => ['bg' => 'bg-emerald-500', 'light' => 'from-emerald-50 to-teal-50', 'border' => 'border-emerald-200', 'text' => 'text-emerald-700', 'badge' => 'bg-emerald-100'],
                'amber' => ['bg' => 'bg-amber-500', 'light' => 'from-amber-50 to-orange-50', 'border' => 'border-amber-200', 'text' => 'text-amber-700', 'badge' => 'bg-amber-100'],
                'teal' => ['bg' => 'bg-teal-500', 'light' => 'from-teal-50 to-cyan-50', 'border' => 'border-teal-200', 'text' => 'text-teal-700', 'badge' => 'bg-teal-100'],
                'indigo' => ['bg' => 'bg-indigo-500', 'light' => 'from-indigo-50 to-violet-50', 'border' => 'border-indigo-200', 'text' => 'text-indigo-700', 'badge' => 'bg-indigo-100'],
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
                        <th class="px-4 py-3 text-center text-xs font-medium text-purple-600 uppercase">Administrador</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-emerald-600 uppercase">Recepción</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-amber-600 uppercase">Operaciones</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-teal-600 uppercase">Pagos</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-blue-600 uppercase">Sup. Planta</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-indigo-600 uppercase">Sup. C. Acopio</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php
                    $permisoMatrix = [
                        'Ficha de recepción' => [true, true, true, false, true, true],
                        'Imprimir etiqueta' => [true, true, true, true, true, true],
                        'Codificación de lote' => [true, true, true, true, true, true],
                        'Registro de pagos' => [true, false, false, true, false, false],
                        'Configuración - Proveedores' => [true, false, false, true, true, true],
                        'Verificación de lote' => [true, false, true, false, true, true],
                        'Fermentación y secado' => [true, false, true, false, true, true],
                        'Prueba de corte / Calidad salida' => [true, false, true, false, true, true],
                        'Reportes e indicadores' => [true, false, false, false, true, true],
                        'Configuración operativa' => [true, false, false, false, true, true],
                        'Usuarios y roles' => [true, false, false, false, true, true],
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
include __DIR__ . '/../templates/layouts/main.php';
?>
