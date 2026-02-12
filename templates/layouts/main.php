<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Template Principal
 * Desarrollado por: Shalom Software
 */

$currentUser = Auth::user();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
$assetVersion = static function (string $path): string {
    $mtime = @filemtime($path);
    return $mtime !== false ? (string) $mtime : date('YmdHis');
};
$cssVersion = $assetVersion(__DIR__ . '/../../assets/css/app.css');
$jsVersion = $assetVersion(__DIR__ . '/../../assets/js/app.js');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-url" content="<?= APP_URL ?>">
    <meta name="csrf-token" content="<?= generateCsrfToken() ?>">
    <title><?= $pageTitle ?? 'Dashboard' ?> - <?= APP_NAME ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            DEFAULT: '#1e4d39',
                            light: '#2a6b4f',
                            dark: '#163828'
                        },
                        ivory: '#f9f8f4',
                        olive: '#A3B7A5',
                        warmgray: '#73796F',
                        gold: '#D6C29A'
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    }
                }
            }
        }
    </script>
    
    <!-- Handsontable -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable/dist/handsontable.full.min.css">
    <script src="https://cdn.jsdelivr.net/npm/handsontable/dist/handsontable.full.min.js"></script>
    
    <!-- SheetJS para export -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    
    <!-- Chart.js para gráficos -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= $cssVersion ?>">
    
    <?php if (isset($extraStyles)): ?>
        <?= $extraStyles ?>
    <?php endif; ?>
</head>
<body class="bg-ivory min-h-screen">
    <!-- Mobile overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 lg:hidden"></div>
    
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-gold rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-primary-dark" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-white font-bold text-lg">Megablessing</h1>
                    <p class="text-white/60 text-xs">Control de Procesos</p>
                </div>
            </div>
        </div>
        
        <nav class="sidebar-nav" aria-label="Menú principal" tabindex="0">
            <!-- Dashboard -->
            <a href="<?= APP_URL ?>/dashboard.php" class="sidebar-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Dashboard
            </a>
            
            <!-- Procesos Centro de Acopio -->
            <div class="sidebar-section-title">Procesos Centro de Acopio</div>

            <a href="<?= APP_URL ?>/fichas/index.php?vista=recepcion" class="sidebar-link <?= $currentDir === 'fichas' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2"/>
                </svg>
                Recepción (Ficha de Recepción)
            </a>

            <a href="<?= APP_URL ?>/fichas/index.php?vista=pagos" class="sidebar-link <?= $currentDir === 'fichas' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1a3 3 0 01-3-3m3 3a3 3 0 003-3"/>
                </svg>
                Registro de Pagos (Ficha de pagos)
            </a>

            <a href="<?= APP_URL ?>/fichas/index.php?vista=codificacion" class="sidebar-link <?= $currentDir === 'fichas' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.53 0 1.04.21 1.41.59l6 6a2 2 0 010 2.82l-4.18 4.18a2 2 0 01-2.82 0l-6-6A2 2 0 016 9V4a1 1 0 011-1z"/>
                </svg>
                Codificación de Lote
            </a>

            <a href="<?= APP_URL ?>/fichas/index.php?vista=etiqueta" class="sidebar-link <?= $currentDir === 'fichas' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2z"/>
                </svg>
                Imprimir Etiqueta (Etiquetado de registro)
            </a>

            <!-- Procesos Planta -->
            <div class="sidebar-section-title">Procesos Planta</div>

            <a href="<?= APP_URL ?>/lotes/index.php" class="sidebar-link <?= $currentDir === 'lotes' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                Verificación de Lote
            </a>

            <a href="<?= APP_URL ?>/fermentacion/index.php" class="sidebar-link <?= $currentDir === 'fermentacion' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
                </svg>
                Fermentación (Ficha de fermentación)
            </a>

            <a href="<?= APP_URL ?>/secado/index.php" class="sidebar-link <?= $currentDir === 'secado' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                Secado (Ficha de secado)
            </a>

            <a href="<?= APP_URL ?>/prueba-corte/index.php" class="sidebar-link <?= in_array($currentDir, ['prueba-corte', 'prueba_corte'], true) ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
                Prueba de Corte (Ficha de prueba de corte)
            </a>

            <a href="<?= APP_URL ?>/calidad-salida/index.php" class="sidebar-link <?= in_array($currentDir, ['calidad-salida', 'calidad_salida'], true) ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m4 2a8 8 0 11-16 0 8 8 0 0116 0z"/>
                </svg>
                Calidad de salida
            </a>
            
            <!-- Reportes -->
            <div class="sidebar-section-title">Reportes</div>
            
            <a href="<?= APP_URL ?>/reportes/index.php" class="sidebar-link <?= $currentDir === 'reportes' && $currentPage === 'index' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                Reportes
            </a>

            <a href="<?= APP_URL ?>/indicadores/index.php" class="sidebar-link <?= $currentDir === 'indicadores' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M7 15v3m4-7v7m4-11v11"/>
                </svg>
                Registro KPIs
            </a>
            
            <a href="<?= APP_URL ?>/reportes/indicadores.php" class="sidebar-link <?= $currentPage === 'indicadores' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                </svg>
                Indicadores
            </a>
            
            <?php if (Auth::isAdmin() || Auth::hasPermission('configuracion')): ?>
            <!-- Configuración -->
            <div class="sidebar-section-title">Configuración</div>
            
            <a href="<?= APP_URL ?>/configuracion/proveedores.php" class="sidebar-link <?= $currentPage === 'proveedores' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                Proveedores
            </a>
            
            <a href="<?= APP_URL ?>/configuracion/parametros.php" class="sidebar-link <?= $currentPage === 'parametros' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                Parámetros
            </a>

            <a href="<?= APP_URL ?>/configuracion/empresa.php" class="sidebar-link <?= $currentPage === 'empresa' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M12 4v12m0 0l-3-3m3 3l3-3"/>
                </svg>
                Empresa y logo
            </a>
            
            <a href="<?= APP_URL ?>/usuarios/index.php" class="sidebar-link <?= $currentDir === 'usuarios' ? 'active' : '' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/>
                </svg>
                Usuarios
            </a>
            <?php endif; ?>
        </nav>
        
        <!-- User info at bottom -->
        <div class="sidebar-user">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-9 h-9 bg-olive rounded-full flex items-center justify-center">
                        <span class="text-primary-dark font-semibold text-sm">
                            <?= strtoupper(substr($currentUser['nombre'], 0, 2)) ?>
                        </span>
                    </div>
                    <div>
                        <p class="text-white text-sm font-medium"><?= htmlspecialchars($currentUser['nombre']) ?></p>
                        <p class="text-white/60 text-xs"><?= htmlspecialchars($currentUser['rol']) ?></p>
                    </div>
                </div>
                <a href="<?= APP_URL ?>/logout.php" class="text-white/60 hover:text-white transition-colors" title="Cerrar sesión">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                </a>
            </div>
        </div>
    </aside>
    
    <!-- Main content -->
    <main class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="flex items-center space-x-4">
                <!-- Mobile menu button -->
                <button id="menuToggle" class="lg:hidden p-2 rounded-lg hover:bg-gray-100">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                
                <div>
                    <h1 class="text-xl font-semibold text-gray-900"><?= $pageTitle ?? 'Dashboard' ?></h1>
                    <?php if (isset($pageSubtitle)): ?>
                        <p class="text-sm text-warmgray"><?= $pageSubtitle ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="flex items-center space-x-3">
                <?php if (isset($headerActions)): ?>
                    <?= $headerActions ?>
                <?php endif; ?>
                
                <span class="text-sm text-warmgray">
                    <?= date('d/m/Y H:i') ?>
                </span>
            </div>
        </header>
        
        <!-- Page content -->
        <div class="p-6">
            <?php 
            $flash = getFlash();
            if ($flash): 
            ?>
                <div class="alert alert-<?= $flash['type'] ?> fade-in" data-auto-dismiss>
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <?php if ($flash['type'] === 'success'): ?>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        <?php elseif ($flash['type'] === 'danger'): ?>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        <?php else: ?>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        <?php endif; ?>
                    </svg>
                    <span><?= htmlspecialchars($flash['message']) ?></span>
                </div>
            <?php endif; ?>
            
            <?= $content ?? '' ?>
        </div>
    </main>
    
    <!-- Custom JS -->
    <script src="<?= APP_URL ?>/assets/js/app.js?v=<?= $jsVersion ?>"></script>
    
    <?php if (isset($extraScripts)): ?>
        <?= $extraScripts ?>
    <?php endif; ?>
</body>
</html>
