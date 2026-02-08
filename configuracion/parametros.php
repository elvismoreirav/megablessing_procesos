<?php
/**
 * Gestión de Parámetros del Proceso
 * Configuración de rangos de temperatura, humedad, y criterios de calidad
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

$categoriaPorGrupo = [
    'fermentacion' => 'FERMENTACION',
    'secado' => 'SECADO',
    'calidad' => 'CALIDAD',
    'general' => 'GENERAL',
];

// Definir grupos de parámetros
$gruposParametros = [
    'fermentacion' => [
        'titulo' => 'Parámetros de Fermentación',
        'icono' => 'fa-fire',
        'color' => 'orange',
        'parametros' => [
            'ferm_temp_min' => ['nombre' => 'Temperatura Mínima', 'unidad' => '°C', 'tipo' => 'number', 'step' => '0.1'],
            'ferm_temp_max' => ['nombre' => 'Temperatura Máxima', 'unidad' => '°C', 'tipo' => 'number', 'step' => '0.1'],
            'ferm_temp_optima' => ['nombre' => 'Temperatura Óptima', 'unidad' => '°C', 'tipo' => 'number', 'step' => '0.1'],
            'ferm_ph_min' => ['nombre' => 'pH Mínimo', 'unidad' => '', 'tipo' => 'number', 'step' => '0.1'],
            'ferm_ph_max' => ['nombre' => 'pH Máximo', 'unidad' => '', 'tipo' => 'number', 'step' => '0.1'],
            'ferm_dias_minimo' => ['nombre' => 'Días Mínimos', 'unidad' => 'días', 'tipo' => 'number', 'step' => '1'],
            'ferm_dias_maximo' => ['nombre' => 'Días Máximos', 'unidad' => 'días', 'tipo' => 'number', 'step' => '1'],
            'ferm_volteos_diarios' => ['nombre' => 'Volteos Diarios Recomendados', 'unidad' => '', 'tipo' => 'number', 'step' => '1'],
        ]
    ],
    'secado' => [
        'titulo' => 'Parámetros de Secado',
        'icono' => 'fa-sun',
        'color' => 'amber',
        'parametros' => [
            'sec_humedad_inicial_max' => ['nombre' => 'Humedad Inicial Máxima', 'unidad' => '%', 'tipo' => 'number', 'step' => '0.1'],
            'sec_humedad_final_optima' => ['nombre' => 'Humedad Final Óptima', 'unidad' => '%', 'tipo' => 'number', 'step' => '0.1'],
            'sec_humedad_final_max' => ['nombre' => 'Humedad Final Máxima', 'unidad' => '%', 'tipo' => 'number', 'step' => '0.1'],
            'sec_temp_max_mecanico' => ['nombre' => 'Temp. Máx. Secado Mecánico', 'unidad' => '°C', 'tipo' => 'number', 'step' => '0.1'],
            'sec_dias_solar_promedio' => ['nombre' => 'Días Promedio Secado Solar', 'unidad' => 'días', 'tipo' => 'number', 'step' => '1'],
            'sec_dias_mecanico_promedio' => ['nombre' => 'Días Promedio Secado Mecánico', 'unidad' => 'días', 'tipo' => 'number', 'step' => '1'],
        ]
    ],
    'calidad' => [
        'titulo' => 'Criterios de Calidad - Prueba de Corte',
        'icono' => 'fa-certificate',
        'color' => 'emerald',
        'parametros' => [
            'cal_premium_ferm_min' => ['nombre' => 'Premium: % Fermentación Mín.', 'unidad' => '%', 'tipo' => 'number', 'step' => '1'],
            'cal_premium_defectos_max' => ['nombre' => 'Premium: % Defectos Máx.', 'unidad' => '%', 'tipo' => 'number', 'step' => '1'],
            'cal_export_ferm_min' => ['nombre' => 'Exportación: % Fermentación Mín.', 'unidad' => '%', 'tipo' => 'number', 'step' => '1'],
            'cal_export_defectos_max' => ['nombre' => 'Exportación: % Defectos Máx.', 'unidad' => '%', 'tipo' => 'number', 'step' => '1'],
            'cal_nacional_ferm_min' => ['nombre' => 'Nacional: % Fermentación Mín.', 'unidad' => '%', 'tipo' => 'number', 'step' => '1'],
            'cal_nacional_defectos_max' => ['nombre' => 'Nacional: % Defectos Máx.', 'unidad' => '%', 'tipo' => 'number', 'step' => '1'],
            'cal_granos_muestra' => ['nombre' => 'Granos por Muestra', 'unidad' => 'granos', 'tipo' => 'number', 'step' => '1'],
        ]
    ],
    'general' => [
        'titulo' => 'Parámetros Generales',
        'icono' => 'fa-cog',
        'color' => 'gray',
        'parametros' => [
            'gen_rendimiento_esperado' => ['nombre' => 'Rendimiento Esperado', 'unidad' => '%', 'tipo' => 'number', 'step' => '1'],
            'gen_perdida_fermentacion' => ['nombre' => 'Pérdida Esperada Fermentación', 'unidad' => '%', 'tipo' => 'number', 'step' => '1'],
            'gen_perdida_secado' => ['nombre' => 'Pérdida Esperada Secado', 'unidad' => '%', 'tipo' => 'number', 'step' => '1'],
            'gen_codigo_lote_prefijo' => ['nombre' => 'Prefijo Código de Lote', 'unidad' => '', 'tipo' => 'text', 'step' => ''],
            'gen_empresa_nombre' => ['nombre' => 'Nombre de la Empresa', 'unidad' => '', 'tipo' => 'text', 'step' => ''],
            'gen_empresa_ruc' => ['nombre' => 'RUC de la Empresa', 'unidad' => '', 'tipo' => 'text', 'step' => ''],
        ]
    ]
];

// Valores por defecto
$valoresDefecto = [
    'ferm_temp_min' => 35,
    'ferm_temp_max' => 50,
    'ferm_temp_optima' => 45,
    'ferm_ph_min' => 4.0,
    'ferm_ph_max' => 6.5,
    'ferm_dias_minimo' => 5,
    'ferm_dias_maximo' => 7,
    'ferm_volteos_diarios' => 2,
    'sec_humedad_inicial_max' => 60,
    'sec_humedad_final_optima' => 7,
    'sec_humedad_final_max' => 8,
    'sec_temp_max_mecanico' => 60,
    'sec_dias_solar_promedio' => 7,
    'sec_dias_mecanico_promedio' => 3,
    'cal_premium_ferm_min' => 80,
    'cal_premium_defectos_max' => 3,
    'cal_export_ferm_min' => 70,
    'cal_export_defectos_max' => 5,
    'cal_nacional_ferm_min' => 60,
    'cal_nacional_defectos_max' => 10,
    'cal_granos_muestra' => 100,
    'gen_rendimiento_esperado' => 35,
    'gen_perdida_fermentacion' => 8,
    'gen_perdida_secado' => 50,
    'gen_codigo_lote_prefijo' => 'MB',
    'gen_empresa_nombre' => 'Megablessing',
    'gen_empresa_ruc' => '',
];

// Procesar guardado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $grupo = $_POST['grupo'] ?? '';
    
    if (isset($gruposParametros[$grupo])) {
        $categoria = $categoriaPorGrupo[$grupo] ?? strtoupper($grupo);
        $errores = [];
        foreach ($gruposParametros[$grupo]['parametros'] as $clave => $config) {
            $valor = trim($_POST[$clave] ?? '');
            
            if ($valor !== '') {
                $tipoParametro = ($config['tipo'] ?? 'text') === 'number' ? 'NUMBER' : 'TEXT';

                // Verificar si existe
                $existing = $db->fetchOne(
                    "SELECT id FROM parametros_proceso WHERE categoria = ? AND clave = ?",
                    [$categoria, $clave]
                );
                
                if ($existing) {
                    $db->query(
                        "UPDATE parametros_proceso SET valor = ?, tipo = ?, descripcion = ?, updated_at = NOW() WHERE id = ?",
                        [$valor, $tipoParametro, $config['nombre'], $existing['id']]
                    );
                } else {
                    $db->query(
                        "INSERT INTO parametros_proceso (categoria, clave, valor, tipo, descripcion, editable) VALUES (?, ?, ?, ?, ?, 1)",
                        [$categoria, $clave, $valor, $tipoParametro, $config['nombre']]
                    );
                }
            }
        }
        $message = 'Parámetros de ' . $gruposParametros[$grupo]['titulo'] . ' guardados correctamente';
    }
}

// Obtener valores actuales
$parametrosDB = $db->fetchAll("SELECT categoria, clave, valor FROM parametros_proceso");
$valoresActuales = [];
foreach ($parametrosDB as $p) {
    $categoria = strtoupper((string)($p['categoria'] ?? ''));
    $grupo = array_search($categoria, $categoriaPorGrupo, true);
    if ($grupo === false) {
        continue;
    }
    $valoresActuales[$grupo . ':' . $p['clave']] = $p['valor'];
}

// Combinar con valores por defecto
foreach ($valoresDefecto as $clave => $valor) {
    foreach (array_keys($gruposParametros) as $grupoKey) {
        $index = $grupoKey . ':' . $clave;
        if (!isset($valoresActuales[$index])) {
            $valoresActuales[$index] = $valor;
        }
    }
}

// Helper para obtener color classes
function getColorClasses($color) {
    $colors = [
        'orange' => ['bg' => 'bg-orange-500', 'light' => 'from-orange-50 to-red-50', 'border' => 'border-orange-200', 'btn' => 'bg-orange-600 hover:bg-orange-700'],
        'amber' => ['bg' => 'bg-amber-500', 'light' => 'from-amber-50 to-yellow-50', 'border' => 'border-amber-200', 'btn' => 'bg-amber-600 hover:bg-amber-700'],
        'emerald' => ['bg' => 'bg-emerald-500', 'light' => 'from-emerald-50 to-teal-50', 'border' => 'border-emerald-200', 'btn' => 'bg-emerald-600 hover:bg-emerald-700'],
        'gray' => ['bg' => 'bg-gray-500', 'light' => 'from-gray-50 to-slate-50', 'border' => 'border-gray-200', 'btn' => 'bg-gray-600 hover:bg-gray-700'],
    ];
    return $colors[$color] ?? $colors['gray'];
}

$pageTitle = 'Parámetros del Proceso';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Parámetros del Proceso</h1>
            <p class="text-gray-600">Configure los rangos y criterios para cada etapa del proceso</p>
        </div>
        <a href="/configuracion/" class="text-amber-600 hover:text-amber-700">
            <i class="fas fa-arrow-left mr-2"></i>Volver a Configuración
        </a>
    </div>

    <?php if ($message): ?>
    <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <?php foreach ($gruposParametros as $grupoKey => $grupo): 
            $colors = getColorClasses($grupo['color']);
        ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b <?= $colors['border'] ?> bg-gradient-to-r <?= $colors['light'] ?>">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 <?= $colors['bg'] ?> rounded-lg flex items-center justify-center">
                        <i class="fas <?= $grupo['icono'] ?> text-white"></i>
                    </div>
                    <h2 class="text-lg font-semibold text-gray-900"><?= $grupo['titulo'] ?></h2>
                </div>
            </div>
            
            <form method="POST" class="p-6">
                <?= csrfField() ?>
                <input type="hidden" name="grupo" value="<?= $grupoKey ?>">
                
                <div class="grid grid-cols-2 gap-4">
                    <?php foreach ($grupo['parametros'] as $clave => $config): ?>
                    <div class="<?= $config['tipo'] === 'text' ? 'col-span-2' : '' ?>">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <?= $config['nombre'] ?>
                            <?php if ($config['unidad']): ?>
                            <span class="text-gray-400 font-normal">(<?= $config['unidad'] ?>)</span>
                            <?php endif; ?>
                        </label>
                        <input type="<?= $config['tipo'] ?>"
                               name="<?= $clave ?>"
                               value="<?= htmlspecialchars($valoresActuales[$grupoKey . ':' . $clave] ?? '') ?>"
                               <?= $config['step'] ? 'step="' . $config['step'] . '"' : '' ?>
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-6 flex gap-2">
                    <button type="submit" class="<?= $colors['btn'] ?> text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-save mr-2"></i>Guardar Cambios
                    </button>
                    <button type="button" onclick="resetDefaults('<?= $grupoKey ?>')" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                        <i class="fas fa-undo mr-2"></i>Restaurar
                    </button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Información -->
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
        <div class="flex items-start gap-3">
            <i class="fas fa-info-circle text-blue-600 mt-0.5"></i>
            <div class="text-sm text-blue-800">
                <p class="font-semibold mb-2">Guía de Parámetros</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="font-medium">Fermentación:</p>
                        <p class="text-xs">Los rangos de temperatura y pH definen las alertas visuales en el control diario. La temperatura óptima es 45°C para desarrollo correcto del sabor.</p>
                    </div>
                    <div>
                        <p class="font-medium">Secado:</p>
                        <p class="text-xs">La humedad final óptima del 7% garantiza estabilidad del producto. El secado mecánico no debe superar los 60°C para preservar aromas.</p>
                    </div>
                    <div>
                        <p class="font-medium">Calidad:</p>
                        <p class="text-xs">Los criterios de clasificación determinan automáticamente la calidad del lote basándose en la prueba de corte de 100 granos.</p>
                    </div>
                    <div>
                        <p class="font-medium">General:</p>
                        <p class="text-xs">El rendimiento esperado y pérdidas sirven para análisis de eficiencia. El prefijo de lote se usa al generar códigos automáticos.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const valoresDefecto = <?= json_encode($valoresDefecto) ?>;

function resetDefaults(grupo) {
    if (!confirm('¿Restaurar valores por defecto de esta sección?')) return;
    
    const form = document.querySelector(`input[name="grupo"][value="${grupo}"]`).closest('form');
    const inputs = form.querySelectorAll('input[name]');
    
    inputs.forEach(input => {
        const name = input.name;
        if (name !== 'grupo' && valoresDefecto[name] !== undefined) {
            input.value = valoresDefecto[name];
        }
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
