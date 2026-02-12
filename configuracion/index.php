<?php
/**
 * Configuración - Panel Principal
 * Administración del sistema
 */

require_once __DIR__ . '/../bootstrap.php';

requireAuth();

// Verificar permisos de administrador/configuración.
if (!Auth::isAdmin() && !Auth::hasPermission('configuracion')) {
    setFlash('danger', 'No tiene permisos para acceder a esta sección.');
    redirect('/dashboard.php');
}

$pageTitle = 'Configuración del Sistema';
$db = Database::getInstance();
$tableExists = static function (string $table) use ($db): bool {
    return (bool)$db->fetchOne("SHOW TABLES LIKE ?", [$table]);
};
$tablaCajones = $tableExists('cajones_fermentacion') ? 'cajones_fermentacion' : 'cajones';

// Obtener conteos para mostrar en las tarjetas
$counts = [
    'proveedores' => $db->fetchOne("SELECT COUNT(*) as c FROM proveedores WHERE activo = 1")['c'],
    'variedades' => $db->fetchOne("SELECT COUNT(*) as c FROM variedades WHERE activo = 1")['c'],
    'usuarios' => $db->fetchOne("SELECT COUNT(*) as c FROM usuarios WHERE activo = 1")['c'],
    'cajones' => $db->fetchOne("SELECT COUNT(*) as c FROM {$tablaCajones} WHERE activo = 1")['c'],
    'secadoras' => $db->fetchOne("SELECT COUNT(*) as c FROM secadoras WHERE activo = 1")['c'],
];

ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Encabezado -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-primary-dark">Configuración del Sistema</h1>
        <p class="mt-2 text-gray-600">Administre los parámetros, catálogos y usuarios del sistema</p>
    </div>

    <!-- Catálogos -->
    <div class="mb-8">
        <h2 class="text-lg font-semibold text-primary-dark mb-4 flex items-center">
            <span class="w-8 h-8 bg-primary rounded-lg flex items-center justify-center mr-3">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
            </span>
            Catálogos
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            
            <!-- Proveedores -->
            <a href="proveedores.php" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 hover:shadow-md transition-shadow group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center group-hover:bg-blue-200 transition-colors">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>
                    <span class="text-2xl font-bold text-blue-600"><?= $counts['proveedores'] ?></span>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">Proveedores</h3>
                <p class="text-sm text-gray-500 mt-1">Gestione productores y proveedores de cacao</p>
            </a>

            <!-- Variedades -->
            <a href="variedades.php" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 hover:shadow-md transition-shadow group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center group-hover:bg-green-200 transition-colors">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                        </svg>
                    </div>
                    <span class="text-2xl font-bold text-green-600"><?= $counts['variedades'] ?></span>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">Variedades</h3>
                <p class="text-sm text-gray-500 mt-1">Tipos y variedades de cacao</p>
            </a>

            <!-- Cajones de Fermentación -->
            <a href="cajones.php" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 hover:shadow-md transition-shadow group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center group-hover:bg-orange-200 transition-colors">
                        <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                    <span class="text-2xl font-bold text-orange-600"><?= $counts['cajones'] ?></span>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">Cajones</h3>
                <p class="text-sm text-gray-500 mt-1">Cajones de fermentación disponibles</p>
            </a>

            <!-- Secadoras -->
            <a href="secadoras.php" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 hover:shadow-md transition-shadow group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center group-hover:bg-yellow-200 transition-colors">
                        <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                    <span class="text-2xl font-bold text-yellow-600"><?= $counts['secadoras'] ?></span>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">Secadoras</h3>
                <p class="text-sm text-gray-500 mt-1">Equipos y áreas de secado</p>
            </a>

            <!-- Estados -->
            <a href="estados.php" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 hover:shadow-md transition-shadow group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center group-hover:bg-purple-200 transition-colors">
                        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                    </div>
                    <span class="text-2xl font-bold text-purple-600">—</span>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">Estados</h3>
                <p class="text-sm text-gray-500 mt-1">Estados de fermentación y calidad</p>
            </a>

            <!-- Parámetros -->
            <a href="parametros.php" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 hover:shadow-md transition-shadow group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center group-hover:bg-teal-200 transition-colors">
                        <svg class="w-6 h-6 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                        </svg>
                    </div>
                    <span class="text-2xl font-bold text-teal-600">—</span>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">Parámetros</h3>
                <p class="text-sm text-gray-500 mt-1">Parámetros de proceso configurables</p>
            </a>
        </div>
    </div>

    <!-- Administración -->
    <div class="mb-8">
        <h2 class="text-lg font-semibold text-primary-dark mb-4 flex items-center">
            <span class="w-8 h-8 bg-red-500 rounded-lg flex items-center justify-center mr-3">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </span>
            Administración
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            
            <!-- Usuarios -->
            <a href="/usuarios/index.php" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 hover:shadow-md transition-shadow group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center group-hover:bg-indigo-200 transition-colors">
                        <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                    <span class="text-2xl font-bold text-indigo-600"><?= $counts['usuarios'] ?></span>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">Usuarios</h3>
                <p class="text-sm text-gray-500 mt-1">Gestión de usuarios del sistema</p>
            </a>

            <!-- Roles -->
            <a href="roles.php" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 hover:shadow-md transition-shadow group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-pink-100 rounded-lg flex items-center justify-center group-hover:bg-pink-200 transition-colors">
                        <svg class="w-6 h-6 text-pink-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                    <span class="text-2xl font-bold text-pink-600">—</span>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">Roles y Permisos</h3>
                <p class="text-sm text-gray-500 mt-1">Configuración de roles y permisos</p>
            </a>

            <!-- Backup -->
            <a href="backup.php" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 hover:shadow-md transition-shadow group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center group-hover:bg-gray-200 transition-colors">
                        <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
                        </svg>
                    </div>
                    <span class="text-2xl font-bold text-gray-600">—</span>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">Respaldos</h3>
                <p class="text-sm text-gray-500 mt-1">Respaldo y restauración de datos</p>
            </a>
        </div>
    </div>

    <!-- Acciones Rápidas -->
    <div class="bg-olive/20 rounded-xl p-6">
        <h3 class="text-lg font-semibold text-primary-dark mb-4">Información del Sistema</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="text-gray-500">Versión:</span>
                <span class="font-medium ml-2">1.0.0</span>
            </div>
            <div>
                <span class="text-gray-500">Base de datos:</span>
                <span class="font-medium ml-2">MySQL 8.0</span>
            </div>
            <div>
                <span class="text-gray-500">PHP:</span>
                <span class="font-medium ml-2"><?= phpversion() ?></span>
            </div>
            <div>
                <span class="text-gray-500">Última actualización:</span>
                <span class="font-medium ml-2"><?= date('d/m/Y') ?></span>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
