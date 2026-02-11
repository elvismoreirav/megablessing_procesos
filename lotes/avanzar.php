<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Avanzar Estado del Lote
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Lote no especificado');
    redirect('/lotes/index.php');
}

// Obtener lote con información relacionada
$lote = $db->fetch("
    SELECT l.*, 
           p.nombre as proveedor_nombre, p.codigo as proveedor_codigo,
           v.nombre as variedad_nombre,
           ep.nombre as estado_producto_nombre, ep.codigo as estado_producto_codigo,
           ef.nombre as estado_fermentacion_nombre,
           u.nombre as usuario_nombre
    FROM lotes l
    JOIN proveedores p ON l.proveedor_id = p.id
    JOIN variedades v ON l.variedad_id = v.id
    JOIN estados_producto ep ON l.estado_producto_id = ep.id
    LEFT JOIN estados_fermentacion ef ON l.estado_fermentacion_id = ef.id
    JOIN usuarios u ON l.usuario_id = u.id
    WHERE l.id = ?
", [$id]);

if (!$lote) {
    setFlash('error', 'Lote no encontrado');
    redirect('/lotes/index.php');
}

// Definir flujo de estados
$flujoEstados = [
    'RECEPCION' => [
        'label' => 'Recepción',
        'icon' => 'truck',
        'color' => 'blue',
        'siguiente' => 'CALIDAD',
        'accion' => 'Enviar a Verificación de Lote'
    ],
    'CALIDAD' => [
        'label' => 'Verificación de Lote',
        'icon' => 'clipboard-check',
        'color' => 'indigo',
        'siguiente' => 'FERMENTACION',
        'accion' => 'Iniciar Fermentación',
        'crear_registro' => 'fermentacion'
    ],
    'PRE_SECADO' => [
        'label' => 'Pre-secado (Legado)',
        'icon' => 'sun',
        'color' => 'yellow',
        'siguiente' => 'FERMENTACION',
        'accion' => 'Iniciar Fermentación (Legado)',
        'crear_registro' => 'fermentacion'
    ],
    'FERMENTACION' => [
        'label' => 'Fermentación',
        'icon' => 'fire',
        'color' => 'orange',
        'siguiente' => 'SECADO',
        'accion' => 'Iniciar Secado',
        'crear_registro' => 'secado',
        'requiere_finalizar' => 'fermentacion'
    ],
    'SECADO' => [
        'label' => 'Secado',
        'icon' => 'sun',
        'color' => 'yellow',
        'siguiente' => 'CALIDAD_POST',
        'accion' => 'Enviar a Prueba de Corte',
        'crear_registro' => 'prueba_corte',
        'requiere_finalizar' => 'secado'
    ],
    'CALIDAD_POST' => [
        'label' => 'Prueba de Corte',
        'icon' => 'check-double',
        'color' => 'green',
        'siguiente' => 'EMPAQUETADO',
        'accion' => 'Enviar a Empaquetado',
        'requiere_finalizar' => 'prueba_corte'
    ],
    'EMPAQUETADO' => [
        'label' => 'Empaquetado',
        'icon' => 'box',
        'color' => 'purple',
        'siguiente' => 'ALMACENADO',
        'accion' => 'Enviar a Almacén'
    ],
    'ALMACENADO' => [
        'label' => 'Almacenado',
        'icon' => 'warehouse',
        'color' => 'gray',
        'siguiente' => 'DESPACHO',
        'accion' => 'Preparar Despacho'
    ],
    'DESPACHO' => [
        'label' => 'Despacho',
        'icon' => 'shipping-fast',
        'color' => 'teal',
        'siguiente' => 'FINALIZADO',
        'accion' => 'Finalizar Lote'
    ],
    'FINALIZADO' => [
        'label' => 'Finalizado',
        'icon' => 'flag-checkered',
        'color' => 'green',
        'siguiente' => null,
        'accion' => null
    ],
    'RECHAZADO' => [
        'label' => 'Rechazado',
        'icon' => 'times-circle',
        'color' => 'red',
        'siguiente' => null,
        'accion' => null
    ]
];

$estadoActual = $lote['estado_proceso'];
$infoEstadoActual = $flujoEstados[$estadoActual] ?? null;
if (!$infoEstadoActual) {
    setFlash('error', 'El lote tiene un estado de proceso no soportado: ' . $estadoActual);
    redirect('/lotes/ver.php?id=' . $id);
}

// Verificar si hay registros existentes
$fichaRegistro = $db->fetch("SELECT id FROM fichas_registro WHERE lote_id = ? ORDER BY id DESC LIMIT 1", [$id]);
$registroFermentacion = $db->fetch("SELECT * FROM registros_fermentacion WHERE lote_id = ? ORDER BY id DESC LIMIT 1", [$id]);
$registroSecado = $db->fetch("SELECT * FROM registros_secado WHERE lote_id = ? ORDER BY id DESC LIMIT 1", [$id]);
$registroPruebaCorte = $db->fetch("SELECT * FROM registros_prueba_corte WHERE lote_id = ? AND tipo_prueba = 'POST_SECADO' ORDER BY id DESC LIMIT 1", [$id]);
$registroFermentacionId = (int)($registroFermentacion['id'] ?? 0);
$registroSecadoId = (int)($registroSecado['id'] ?? 0);

// Verificar si el proceso actual está finalizado (para mostrar advertencia)
$procesoActualFinalizado = true;
$mensajeBloqueo = '';

if (!$fichaRegistro && !in_array($estadoActual, ['FINALIZADO', 'RECHAZADO'], true)) {
    $procesoActualFinalizado = false;
    $mensajeBloqueo = 'Debe crear primero la ficha de registro antes de avanzar el lote.';
}

if ($estadoActual === 'FERMENTACION' && $registroFermentacion) {
    if (empty($registroFermentacion['aprobado_secado'])) {
        $procesoActualFinalizado = false;
        $mensajeBloqueo = 'Debe finalizar y aprobar el proceso de fermentación antes de avanzar.';
    }
} elseif ($estadoActual === 'FERMENTACION') {
    $procesoActualFinalizado = false;
    $mensajeBloqueo = 'Debe registrar primero la fermentación antes de avanzar.';
}

if ($estadoActual === 'PRE_SECADO' && !$registroSecado) {
    $procesoActualFinalizado = false;
    $mensajeBloqueo = 'Debe registrar el pre-secado antes de iniciar fermentación.';
}

if ($estadoActual === 'SECADO' && $registroSecado) {
    if (empty($registroSecado['humedad_final']) || $registroSecado['humedad_final'] > 8) {
        $procesoActualFinalizado = false;
        $mensajeBloqueo = 'Debe completar el secado (humedad final ≤ 8%) antes de avanzar.';
    }
} elseif ($estadoActual === 'SECADO') {
    $procesoActualFinalizado = false;
    $mensajeBloqueo = 'Debe registrar primero el secado antes de avanzar.';
}

if ($estadoActual === 'CALIDAD_POST' && $registroPruebaCorte) {
    if (empty($registroPruebaCorte['decision_lote'])) {
        $procesoActualFinalizado = false;
        $mensajeBloqueo = 'Debe completar la prueba de corte y registrar la decisión antes de avanzar.';
    }
} elseif ($estadoActual === 'CALIDAD_POST') {
    $procesoActualFinalizado = false;
    $mensajeBloqueo = 'Debe completar la prueba de corte antes de avanzar.';
}

// Procesar avance
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avanzar'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } elseif (!$procesoActualFinalizado) {
        $error = $mensajeBloqueo;
    } elseif (in_array($estadoActual, ['FINALIZADO', 'RECHAZADO'], true)) {
        $error = 'El lote ya está cerrado y no puede avanzar';
    } else {
        $siguienteEstado = $infoEstadoActual['siguiente'];
        $observaciones = trim($_POST['observaciones'] ?? '');
        
        try {
            $db->getConnection()->beginTransaction();
            
            // Crear registro según el siguiente estado
            if (isset($infoEstadoActual['crear_registro'])) {
                switch ($infoEstadoActual['crear_registro']) {
                    case 'fermentacion':
                        // Verificar si ya existe
                        if (!$registroFermentacion) {
                            $registroFermentacionId = (int)$db->insert('registros_fermentacion', [
                                'lote_id' => $id,
                                'fecha_inicio' => date('Y-m-d'),
                                'peso_lote_kg' => $lote['peso_inicial_kg'],
                                'humedad_inicial' => $lote['humedad_inicial'],
                                'responsable_id' => Auth::user()['id']
                            ]);
                        } else {
                            $registroFermentacionId = (int)$registroFermentacion['id'];
                        }
                        break;
                        
                    case 'secado':
                        // Verificar si ya existe
                        if (!$registroSecado) {
                            $registroSecadoId = (int)$db->insert('registros_secado', [
                                'lote_id' => $id,
                                'fecha' => date('Y-m-d'),
                                'responsable_id' => Auth::user()['id'],
                                'variedad' => $lote['variedad_nombre'],
                                'humedad_inicial' => $registroFermentacion['humedad_final'] ?? $lote['humedad_inicial']
                            ]);
                        } else {
                            $registroSecadoId = (int)$registroSecado['id'];
                        }
                        break;
                        
                    case 'prueba_corte':
                        // Verificar si ya existe prueba POST_SECADO
                        if (!$registroPruebaCorte) {
                            $db->insert('registros_prueba_corte', [
                                'lote_id' => $id,
                                'tipo_prueba' => 'POST_SECADO',
                                'fecha' => date('Y-m-d'),
                                'codigo_lote' => $lote['codigo'],
                                'proveedor_origen' => $lote['proveedor_nombre'],
                                'tipo_cacao' => $lote['variedad_nombre'],
                                'responsable_analisis_id' => Auth::user()['id']
                            ]);
                        }
                        break;
                }
            }
            
            // Actualizar estado del lote
            $db->update('lotes', 
                ['estado_proceso' => $siguienteEstado],
                'id = :id',
                ['id' => $id]
            );
            
            // Registrar en historial
            $db->insert('lotes_historial', [
                'lote_id' => $id,
                'accion' => 'CAMBIO_ESTADO',
                'descripcion' => "Estado cambiado de {$estadoActual} a {$siguienteEstado}" . ($observaciones ? ". Obs: {$observaciones}" : ''),
                'datos_anteriores' => json_encode(['estado_proceso' => $estadoActual]),
                'datos_nuevos' => json_encode(['estado_proceso' => $siguienteEstado]),
                'usuario_id' => Auth::user()['id']
            ]);
            
            $db->getConnection()->commit();
            
            setFlash('success', 'Lote avanzado correctamente a: ' . $flujoEstados[$siguienteEstado]['label']);
            
            // Redirigir según el nuevo estado
            switch ($siguienteEstado) {
                case 'PRE_SECADO':
                    redirect('/secado/crear.php?lote_id=' . $id);
                    break;
                case 'FERMENTACION':
                    if ($registroFermentacionId > 0) {
                        redirect('/fermentacion/control.php?id=' . $registroFermentacionId);
                    }
                    redirect('/fermentacion/crear.php?lote_id=' . $id);
                    break;
                case 'SECADO':
                    if ($registroSecadoId > 0) {
                        redirect('/secado/control.php?id=' . $registroSecadoId);
                    }
                    redirect('/secado/crear.php?lote_id=' . $id);
                    break;
                case 'CALIDAD_POST':
                    redirect('/prueba-corte/crear.php?lote_id=' . $id . '&tipo=POST_SECADO');
                    break;
                default:
                    redirect('/lotes/ver.php?id=' . $id);
            }
            
        } catch (Exception $e) {
            $db->getConnection()->rollBack();
            $error = 'Error al avanzar el lote: ' . $e->getMessage();
        }
    }
}

// Procesar retroceso (solo admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retroceder']) && Auth::hasRole('Administrador')) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } else {
        $estadoAnterior = $_POST['estado_anterior'] ?? '';
        $observaciones = trim($_POST['observaciones_retroceso'] ?? '');
        
        if ($estadoAnterior && isset($flujoEstados[$estadoAnterior])) {
            try {
                $db->getConnection()->beginTransaction();
                
                $db->update('lotes', 
                    ['estado_proceso' => $estadoAnterior],
                    'id = :id',
                    ['id' => $id]
                );
                
                $db->insert('lotes_historial', [
                    'lote_id' => $id,
                    'accion' => 'RETROCESO_ESTADO',
                    'descripcion' => "Estado retrocedido de {$estadoActual} a {$estadoAnterior}" . ($observaciones ? ". Motivo: {$observaciones}" : ''),
                    'datos_anteriores' => json_encode(['estado_proceso' => $estadoActual]),
                    'datos_nuevos' => json_encode(['estado_proceso' => $estadoAnterior]),
                    'usuario_id' => Auth::user()['id']
                ]);
                
                $db->getConnection()->commit();
                
                setFlash('success', 'Lote retrocedido a: ' . $flujoEstados[$estadoAnterior]['label']);
                redirect('/lotes/avanzar.php?id=' . $id);
                
            } catch (Exception $e) {
                $db->getConnection()->rollBack();
                $error = 'Error al retroceder el lote: ' . $e->getMessage();
            }
        }
    }
}

// Obtener estados anteriores para retroceso
$estadosAnteriores = [];
$encontrado = false;
foreach (array_reverse(array_keys($flujoEstados)) as $estado) {
    if ($estado === $estadoActual) {
        $encontrado = true;
        continue;
    }
    if ($encontrado && !in_array($estado, ['FINALIZADO', 'RECHAZADO'], true)) {
        $estadosAnteriores[$estado] = $flujoEstados[$estado]['label'];
    }
}
$estadosAnteriores = array_reverse($estadosAnteriores, true);

$pageTitle = 'Avanzar Lote ' . $lote['codigo'];
ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Avanzar Estado del Lote</h1>
            <p class="text-gray-600">Gestión del flujo de proceso</p>
        </div>
        <a href="/lotes/ver.php?id=<?= $id ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            <i class="fas fa-arrow-left mr-2"></i>Volver al Lote
        </a>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
            <span class="text-red-700"><?= htmlspecialchars($error) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Información del Lote -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 bg-gradient-to-r from-green-600 to-green-700">
            <div class="flex items-center justify-between">
                <div class="text-white">
                    <h2 class="text-xl font-bold"><?= htmlspecialchars($lote['codigo']) ?></h2>
                    <p class="text-green-100 text-sm"><?= htmlspecialchars($lote['proveedor_nombre']) ?> · <?= htmlspecialchars($lote['variedad_nombre']) ?></p>
                </div>
                <div class="text-right text-white">
                    <p class="text-2xl font-bold"><?= number_format($lote['peso_inicial_kg'], 1) ?> kg</p>
                    <p class="text-green-100 text-sm">Peso inicial</p>
                </div>
            </div>
        </div>
        
        <div class="p-6">
            <!-- Timeline de Estados -->
            <div class="mb-8">
                <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Flujo del Proceso</h3>
                <div class="flex items-center justify-between overflow-x-auto pb-4">
                    <?php 
                    $estadoIndex = 0;
                    $actualIndex = array_search($estadoActual, array_keys($flujoEstados));
                    foreach ($flujoEstados as $estado => $info): 
                        $esActual = $estado === $estadoActual;
                        $esCompletado = $estadoIndex < $actualIndex;
                        $esPendiente = $estadoIndex > $actualIndex;
                    ?>
                    <div class="flex flex-col items-center min-w-[80px] <?= $esPendiente ? 'opacity-40' : '' ?>">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center mb-2 <?php
                            if ($esActual) echo 'bg-' . $info['color'] . '-500 text-white ring-4 ring-' . $info['color'] . '-200';
                            elseif ($esCompletado) echo 'bg-green-500 text-white';
                            else echo 'bg-gray-200 text-gray-500';
                        ?>">
                            <?php if ($esCompletado && !$esActual): ?>
                                <i class="fas fa-check"></i>
                            <?php else: ?>
                                <i class="fas fa-<?= $info['icon'] ?>"></i>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs text-center <?= $esActual ? 'font-bold text-' . $info['color'] . '-600' : 'text-gray-600' ?>">
                            <?= $info['label'] ?>
                        </span>
                    </div>
                    <?php if ($estado !== 'FINALIZADO'): ?>
                    <div class="flex-1 h-1 mx-1 <?= $esCompletado ? 'bg-green-500' : 'bg-gray-200' ?> min-w-[20px]"></div>
                    <?php endif; ?>
                    <?php $estadoIndex++; endforeach; ?>
                </div>
            </div>

            <!-- Estado Actual -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Estado Actual Info -->
                <div class="bg-<?= $infoEstadoActual['color'] ?>-50 rounded-xl p-6 border-2 border-<?= $infoEstadoActual['color'] ?>-200">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-14 h-14 bg-<?= $infoEstadoActual['color'] ?>-500 rounded-xl flex items-center justify-center">
                            <i class="fas fa-<?= $infoEstadoActual['icon'] ?> text-2xl text-white"></i>
                        </div>
                        <div>
                            <p class="text-sm text-<?= $infoEstadoActual['color'] ?>-600 font-medium">Estado Actual</p>
                            <h3 class="text-xl font-bold text-gray-900"><?= $infoEstadoActual['label'] ?></h3>
                        </div>
                    </div>
                    
                    <?php if (!$procesoActualFinalizado): ?>
                    <div class="bg-yellow-100 border border-yellow-300 rounded-lg p-3 mt-4">
                        <div class="flex items-start gap-2">
                            <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i>
                            <div class="text-sm text-yellow-800">
                                <p><?= htmlspecialchars($mensajeBloqueo) ?></p>
                                <?php if (!$fichaRegistro): ?>
                                <a href="/fichas/crear.php?etapa=recepcion&lote_id=<?= $id ?>" class="inline-flex items-center mt-2 text-yellow-900 font-semibold hover:underline">
                                    <i class="fas fa-file-alt mr-1"></i>Crear ficha de registro
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Links a módulos actuales -->
                    <?php if ($estadoActual === 'FERMENTACION' && $registroFermentacion): ?>
                    <a href="/fermentacion/control.php?id=<?= (int)$registroFermentacion['id'] ?>" class="mt-4 inline-flex items-center text-orange-600 hover:text-orange-700">
                        <i class="fas fa-external-link-alt mr-2"></i>Ir a Control de Fermentación
                    </a>
                    <?php elseif ($estadoActual === 'PRE_SECADO'): ?>
                    <a href="/secado/crear.php?lote_id=<?= $id ?>" class="mt-4 inline-flex items-center text-amber-600 hover:text-amber-700">
                        <i class="fas fa-external-link-alt mr-2"></i>Registrar Pre-secado
                    </a>
                    <?php elseif ($estadoActual === 'SECADO' && $registroSecado): ?>
                    <a href="/secado/control.php?id=<?= (int)$registroSecado['id'] ?>" class="mt-4 inline-flex items-center text-yellow-600 hover:text-yellow-700">
                        <i class="fas fa-external-link-alt mr-2"></i>Ir a Control de Secado
                    </a>
                    <?php elseif ($estadoActual === 'CALIDAD_POST'): ?>
                    <a href="/prueba-corte/crear.php?lote_id=<?= $id ?>&tipo=POST_SECADO" class="mt-4 inline-flex items-center text-green-600 hover:text-green-700">
                        <i class="fas fa-external-link-alt mr-2"></i>Ir a Prueba de Corte
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Siguiente Estado -->
                <?php if ($infoEstadoActual['siguiente']): ?>
                <?php $infoSiguiente = $flujoEstados[$infoEstadoActual['siguiente']]; ?>
                <div class="bg-gray-50 rounded-xl p-6 border-2 border-dashed border-gray-300">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-14 h-14 bg-gray-300 rounded-xl flex items-center justify-center">
                            <i class="fas fa-<?= $infoSiguiente['icon'] ?> text-2xl text-gray-600"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 font-medium">Siguiente Estado</p>
                            <h3 class="text-xl font-bold text-gray-700"><?= $infoSiguiente['label'] ?></h3>
                        </div>
                    </div>
                    
                    <div class="flex items-center text-gray-500 text-sm">
                        <i class="fas fa-arrow-right mr-2"></i>
                        <span><?= $infoEstadoActual['accion'] ?></span>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-green-50 rounded-xl p-6 border-2 border-green-200 flex items-center justify-center">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-green-500 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-check text-3xl text-white"></i>
                        </div>
                        <h3 class="text-xl font-bold text-green-700">Proceso Completado</h3>
                        <p class="text-green-600 text-sm mt-1">El lote ha finalizado su ciclo</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Acciones -->
    <?php if ($infoEstadoActual['siguiente']): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-forward text-green-600 mr-2"></i>Avanzar al Siguiente Estado
            </h3>
        </div>
        <div class="p-6">
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Observaciones (opcional)</label>
                    <textarea name="observaciones" rows="2" 
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                              placeholder="Agregar notas sobre el avance..."></textarea>
                </div>
                
                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <div class="text-sm text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        Esta acción quedará registrada en el historial del lote
                    </div>
                    <button type="submit" name="avanzar" value="1"
                            class="inline-flex items-center px-6 py-3 bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700 focus:ring-4 focus:ring-green-200 transition <?= !$procesoActualFinalizado ? 'opacity-50 cursor-not-allowed' : '' ?>"
                            <?= !$procesoActualFinalizado ? 'disabled' : '' ?>>
                        <i class="fas fa-arrow-right mr-2"></i>
                        <?= htmlspecialchars($infoEstadoActual['accion']) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Retroceder (Solo Admin) -->
    <?php if (Auth::hasRole('Administrador') && !empty($estadosAnteriores)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-red-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-red-100 bg-red-50">
            <h3 class="text-lg font-semibold text-red-700">
                <i class="fas fa-undo mr-2"></i>Retroceder Estado (Solo Administrador)
            </h3>
        </div>
        <div class="p-6">
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                <div class="flex items-start gap-3">
                    <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i>
                    <div class="text-sm text-yellow-800">
                        <strong>Advertencia:</strong> Retroceder un lote puede afectar los registros asociados. 
                        Use esta función solo cuando sea estrictamente necesario.
                    </div>
                </div>
            </div>
            
            <form method="POST" class="space-y-4" onsubmit="return confirm('¿Está seguro de retroceder el estado del lote? Esta acción quedará registrada.')">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Retroceder a</label>
                        <select name="estado_anterior" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500">
                            <option value="">Seleccionar estado...</option>
                            <?php foreach ($estadosAnteriores as $estado => $label): ?>
                            <option value="<?= $estado ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Motivo del retroceso *</label>
                        <input type="text" name="observaciones_retroceso" required
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500"
                               placeholder="Explique el motivo...">
                    </div>
                </div>
                
                <div class="flex justify-end pt-4 border-t border-gray-100">
                    <button type="submit" name="retroceder" value="1"
                            class="inline-flex items-center px-4 py-2 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700">
                        <i class="fas fa-undo mr-2"></i>Retroceder Estado
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Historial Reciente -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-history text-gray-500 mr-2"></i>Historial de Cambios
            </h3>
        </div>
        <div class="p-6">
            <?php 
            $historial = $db->fetchAll("
                SELECT h.*, u.nombre as usuario
                FROM lotes_historial h
                LEFT JOIN usuarios u ON h.usuario_id = u.id
                WHERE h.lote_id = ?
                ORDER BY h.created_at DESC
                LIMIT 10
            ", [$id]);
            ?>
            <?php if (empty($historial)): ?>
            <p class="text-gray-500 text-center py-4">No hay registros en el historial</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($historial as $h): ?>
                <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                    <div class="w-8 h-8 bg-<?= $h['accion'] === 'CAMBIO_ESTADO' ? 'green' : ($h['accion'] === 'RETROCESO_ESTADO' ? 'red' : 'blue') ?>-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-<?= $h['accion'] === 'CAMBIO_ESTADO' ? 'arrow-right' : ($h['accion'] === 'RETROCESO_ESTADO' ? 'undo' : 'edit') ?> text-<?= $h['accion'] === 'CAMBIO_ESTADO' ? 'green' : ($h['accion'] === 'RETROCESO_ESTADO' ? 'red' : 'blue') ?>-600 text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-900"><?= htmlspecialchars($h['descripcion']) ?></p>
                        <p class="text-xs text-gray-500 mt-1">
                            <?= htmlspecialchars($h['usuario'] ?? 'Sistema') ?> · 
                            <?= date('d/m/Y H:i', strtotime($h['created_at'])) ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
