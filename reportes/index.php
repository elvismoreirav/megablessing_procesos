<?php
/**
 * Reportes - Panel Principal
 * Centro de reportes y análisis del sistema
 */

require_once __DIR__ . '/../bootstrap.php';

if (!Auth::check()) {
    redirect('/login.php');
}

$pageTitle = 'Centro de Reportes';

// Obtener estadísticas generales para el dashboard
$db = Database::getInstance();
$colsPrueba = array_column($db->fetchAll("SHOW COLUMNS FROM registros_prueba_corte"), 'Field');
$colFechaPrueba = in_array('fecha_prueba', $colsPrueba, true) ? 'fecha_prueba' : (in_array('fecha', $colsPrueba, true) ? 'fecha' : null);
$pruebasMesSql = $colFechaPrueba
    ? "SELECT COUNT(*) as count FROM registros_prueba_corte WHERE MONTH({$colFechaPrueba}) = MONTH(CURRENT_DATE()) AND YEAR({$colFechaPrueba}) = YEAR(CURRENT_DATE())"
    : "SELECT 0 as count";

$colsLotes = array_column($db->fetchAll("SHOW COLUMNS FROM lotes"), 'Field');
$colCalidadLotes = in_array('calidad_final', $colsLotes, true) ? 'calidad_final' : (in_array('calidad', $colsLotes, true) ? 'calidad' : null);
$colCalidadPrueba = in_array('calidad_resultado', $colsPrueba, true) ? 'calidad_resultado' : (in_array('calidad_determinada', $colsPrueba, true) ? 'calidad_determinada' : null);

if ($colCalidadLotes) {
    $sqlPremium = "SELECT COUNT(*) as count FROM lotes WHERE {$colCalidadLotes} = 'PREMIUM'";
    $sqlExportacion = "SELECT COUNT(*) as count FROM lotes WHERE {$colCalidadLotes} = 'EXPORTACION'";
} elseif ($colCalidadPrueba) {
    $sqlPremium = "SELECT COUNT(*) as count FROM registros_prueba_corte WHERE {$colCalidadPrueba} = 'PREMIUM'";
    $sqlExportacion = "SELECT COUNT(*) as count FROM registros_prueba_corte WHERE {$colCalidadPrueba} = 'EXPORTACION'";
} else {
    $sqlPremium = "SELECT 0 as count";
    $sqlExportacion = "SELECT 0 as count";
}

$stats = [
    'total_lotes' => $db->fetchOne("SELECT COUNT(*) as count FROM lotes")['count'],
    'lotes_activos' => $db->fetchOne("SELECT COUNT(*) as count FROM lotes WHERE estado_proceso NOT IN ('FINALIZADO', 'RECHAZADO')")['count'],
    'fermentaciones_mes' => $db->fetchOne("SELECT COUNT(*) as count FROM registros_fermentacion WHERE MONTH(fecha_inicio) = MONTH(CURRENT_DATE())")['count'],
    'pruebas_mes' => $db->fetchOne($pruebasMesSql)['count'],
    'calidad_premium' => $db->fetchOne($sqlPremium)['count'],
    'calidad_exportacion' => $db->fetchOne($sqlExportacion)['count'],
];

ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Encabezado -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-shalom-dark">Centro de Reportes</h1>
        <p class="mt-2 text-gray-600">Genera y descarga reportes detallados de todos los procesos</p>
    </div>

    <!-- Estadísticas Rápidas -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-shalom-primary"><?= number_format($stats['total_lotes']) ?></div>
            <div class="text-xs text-gray-500">Total Lotes</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-blue-600"><?= number_format($stats['lotes_activos']) ?></div>
            <div class="text-xs text-gray-500">Lotes Activos</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-orange-600"><?= number_format($stats['fermentaciones_mes']) ?></div>
            <div class="text-xs text-gray-500">Fermentaciones/Mes</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-purple-600"><?= number_format($stats['pruebas_mes']) ?></div>
            <div class="text-xs text-gray-500">Pruebas/Mes</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-green-600"><?= number_format($stats['calidad_premium']) ?></div>
            <div class="text-xs text-gray-500">Premium</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-shalom-gold"><?= number_format($stats['calidad_exportacion']) ?></div>
            <div class="text-xs text-gray-500">Exportación</div>
        </div>
    </div>

    <!-- Reportes por Categoría -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        
        <!-- Reporte de Lotes -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-shadow">
            <div class="bg-gradient-to-r from-shalom-primary to-shalom-dark p-4">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                    <h3 class="ml-4 text-xl font-bold text-white">Lotes</h3>
                </div>
            </div>
            <div class="p-6">
                <p class="text-gray-600 mb-4">Reporte completo de lotes con estado, proveedor, variedad, pesos y trazabilidad.</p>
                <ul class="text-sm text-gray-500 mb-4 space-y-1">
                    <li>• Filtro por fechas y estado</li>
                    <li>• Resumen de proveedores</li>
                    <li>• Trazabilidad completa</li>
                </ul>
                <a href="lotes.php" class="block w-full text-center bg-shalom-primary text-white py-2 rounded-lg hover:bg-shalom-dark transition-colors">
                    Generar Reporte
                </a>
            </div>
        </div>

        <!-- Reporte de Fermentación -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-shadow">
            <div class="bg-gradient-to-r from-orange-500 to-red-600 p-4">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                        </svg>
                    </div>
                    <h3 class="ml-4 text-xl font-bold text-white">Fermentación</h3>
                </div>
            </div>
            <div class="p-6">
                <p class="text-gray-600 mb-4">Análisis del proceso de fermentación con curvas de temperatura y pH.</p>
                <ul class="text-sm text-gray-500 mb-4 space-y-1">
                    <li>• Control diario de 6 días</li>
                    <li>• Gráficos de temperatura</li>
                    <li>• Registro de volteos</li>
                </ul>
                <a href="fermentacion.php" class="block w-full text-center bg-orange-500 text-white py-2 rounded-lg hover:bg-orange-600 transition-colors">
                    Generar Reporte
                </a>
            </div>
        </div>

        <!-- Reporte de Secado -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-shadow">
            <div class="bg-gradient-to-r from-yellow-500 to-amber-600 p-4">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                    <h3 class="ml-4 text-xl font-bold text-white">Secado</h3>
                </div>
            </div>
            <div class="p-6">
                <p class="text-gray-600 mb-4">Monitoreo del proceso de secado con temperaturas por hora y humedad.</p>
                <ul class="text-sm text-gray-500 mb-4 space-y-1">
                    <li>• 7 lecturas diarias</li>
                    <li>• Control de humedad</li>
                    <li>• Tipos de secado</li>
                </ul>
                <a href="secado.php" class="block w-full text-center bg-yellow-500 text-white py-2 rounded-lg hover:bg-yellow-600 transition-colors">
                    Generar Reporte
                </a>
            </div>
        </div>

        <!-- Reporte de Prueba de Corte -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-shadow">
            <div class="bg-gradient-to-r from-purple-500 to-indigo-600 p-4">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                    </div>
                    <h3 class="ml-4 text-xl font-bold text-white">Prueba de Corte</h3>
                </div>
            </div>
            <div class="p-6">
                <p class="text-gray-600 mb-4">Análisis de calidad con clasificación de 100 granos y defectos.</p>
                <ul class="text-sm text-gray-500 mb-4 space-y-1">
                    <li>• Análisis de 100 granos</li>
                    <li>• Clasificación de calidad</li>
                    <li>• Porcentaje de defectos</li>
                </ul>
                <a href="prueba-corte.php" class="block w-full text-center bg-purple-500 text-white py-2 rounded-lg hover:bg-purple-600 transition-colors">
                    Generar Reporte
                </a>
            </div>
        </div>

        <!-- Reporte de Indicadores -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-shadow">
            <div class="bg-gradient-to-r from-teal-500 to-cyan-600 p-4">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <h3 class="ml-4 text-xl font-bold text-white">Indicadores</h3>
                </div>
            </div>
            <div class="p-6">
                <p class="text-gray-600 mb-4">KPIs y métricas de rendimiento de todos los procesos.</p>
                <ul class="text-sm text-gray-500 mb-4 space-y-1">
                    <li>• Rendimiento por proceso</li>
                    <li>• Pérdidas de peso</li>
                    <li>• Tiempos promedio</li>
                </ul>
                <a href="indicadores.php" class="block w-full text-center bg-teal-500 text-white py-2 rounded-lg hover:bg-teal-600 transition-colors">
                    Ver Indicadores
                </a>
            </div>
        </div>

        <!-- Reporte Consolidado -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-shadow">
            <div class="bg-gradient-to-r from-gray-600 to-gray-800 p-4">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <h3 class="ml-4 text-xl font-bold text-white">Consolidado</h3>
                </div>
            </div>
            <div class="p-6">
                <p class="text-gray-600 mb-4">Reporte completo con todos los procesos de un período.</p>
                <ul class="text-sm text-gray-500 mb-4 space-y-1">
                    <li>• Resumen ejecutivo</li>
                    <li>• Todos los procesos</li>
                    <li>• Exportar a Excel/PDF</li>
                </ul>
                <a href="consolidado.php" class="block w-full text-center bg-gray-600 text-white py-2 rounded-lg hover:bg-gray-700 transition-colors">
                    Generar Consolidado
                </a>
            </div>
        </div>
    </div>

    <!-- Acciones Rápidas -->
    <div class="mt-8 bg-shalom-olive/20 rounded-xl p-6">
        <h3 class="text-lg font-semibold text-shalom-dark mb-4">Exportación Rápida</h3>
        <div class="flex flex-wrap gap-4">
            <a href="?export=lotes&format=excel" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z"/>
                </svg>
                Lotes a Excel
            </a>
            <a href="?export=fermentacion&format=excel" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z"/>
                </svg>
                Fermentación a Excel
            </a>
            <a href="?export=calidad&format=pdf" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z"/>
                </svg>
                Calidad a PDF
            </a>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
