<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Fichas de Registro - Editar
 * Desarrollado por: Shalom Software
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();
$error = '';

$id = intval($_GET['id'] ?? 0);
$etapaFormulario = strtolower(trim((string)($_GET['etapa'] ?? 'recepcion')));
if (!in_array($etapaFormulario, ['recepcion', 'completo'], true)) {
    $etapaFormulario = 'recepcion';
}
$esFormularioRecepcion = $etapaFormulario === 'recepcion';

if ($id <= 0) {
    redirect('/fichas/index.php?vista=recepcion');
}

// Obtener ficha actual
$ficha = $db->fetchOne("
    SELECT f.*, l.codigo as lote_codigo
    FROM fichas_registro f
    INNER JOIN lotes l ON f.lote_id = l.id
    WHERE f.id = ?
", [$id]);

if (!$ficha) {
    $_SESSION['error'] = 'Ficha no encontrada';
    redirect('/fichas/index.php?vista=recepcion');
}

// Compatibilidad de esquema para columnas de lotes
$colsLotes = array_column($db->fetchAll("SHOW COLUMNS FROM lotes"), 'Field');
$hasLoteCol = static fn(string $name): bool => in_array($name, $colsLotes, true);
$pesoRecibidoExpr = $hasLoteCol('peso_recibido_kg')
    ? 'l.peso_recibido_kg'
    : ($hasLoteCol('peso_inicial_kg') ? 'l.peso_inicial_kg' : 'NULL');

// Compatibilidad de esquema para columnas de fichas (registro de pago)
$colsFichas = array_column($db->fetchAll("SHOW COLUMNS FROM fichas_registro"), 'Field');
$hasFichaCol = static fn(string $name): bool => in_array($name, $colsFichas, true);
$columnasPagoFicha = ['fecha_pago', 'tipo_comprobante', 'factura_compra', 'cantidad_comprada', 'cantidad_comprada_unidad', 'forma_pago'];
$faltanColumnasPago = array_values(array_filter(
    $columnasPagoFicha,
    static fn(string $col): bool => !$hasFichaCol($col)
));

// Obtener lotes disponibles
$lotes = $db->fetchAll("
    SELECT l.id, l.codigo,
           {$pesoRecibidoExpr} as peso_recibido_kg,
           p.nombre as proveedor_nombre,
           v.nombre as variedad_nombre
    FROM lotes l
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    ORDER BY l.codigo DESC
");

// Obtener proveedores activos para selección
$colsProveedores = array_column($db->fetchAll("SHOW COLUMNS FROM proveedores"), 'Field');
$filtroProveedorReal = in_array('es_categoria', $colsProveedores, true)
    ? ' AND (es_categoria = 0 OR es_categoria IS NULL)'
    : '';
$proveedores = $db->fetchAll("
    SELECT id, codigo, nombre
    FROM proveedores
    WHERE activo = 1{$filtroProveedorReal}
    ORDER BY nombre
");
$whereRutas = in_array('es_categoria', $colsProveedores, true)
    ? "AND (es_categoria = 1 OR UPPER(COALESCE(tipo, '')) = 'RUTA')"
    : "AND UPPER(COALESCE(tipo, '')) = 'RUTA'";
$rutasDisponibles = $db->fetchAll("
    SELECT id, codigo, nombre
    FROM proveedores
    WHERE activo = 1 {$whereRutas}
    ORDER BY nombre
");

$proveedoresPorId = [];
$proveedoresPorNombre = [];
$proveedoresPorCodigo = [];
$proveedoresPorEtiqueta = [];
foreach ($proveedores as $proveedorItem) {
    $idProveedor = (int)($proveedorItem['id'] ?? 0);
    if ($idProveedor <= 0) {
        continue;
    }
    $nombreProveedor = trim((string)($proveedorItem['nombre'] ?? ''));
    $codigoProveedor = trim((string)($proveedorItem['codigo'] ?? ''));
    $proveedorInfo = [
        'id' => $idProveedor,
        'nombre' => $nombreProveedor,
        'codigo' => $codigoProveedor,
    ];
    $proveedoresPorId[$idProveedor] = $proveedorInfo;
    if ($nombreProveedor !== '') {
        $proveedoresPorNombre[strtolower($nombreProveedor)] = $proveedorInfo;
    }
    if ($codigoProveedor !== '') {
        $proveedoresPorCodigo[strtolower($codigoProveedor)] = $proveedorInfo;
    }
    if ($codigoProveedor !== '' && $nombreProveedor !== '') {
        $proveedoresPorEtiqueta[strtolower($codigoProveedor . ' - ' . $nombreProveedor)] = $proveedorInfo;
    }
}

$rutasPorNombre = [];
foreach ($rutasDisponibles as $rutaItem) {
    $nombreRuta = trim((string)($rutaItem['nombre'] ?? ''));
    if ($nombreRuta === '') {
        continue;
    }
    $rutasPorNombre[strtolower($nombreRuta)] = [
        'id' => (int)($rutaItem['id'] ?? 0),
        'codigo' => trim((string)($rutaItem['codigo'] ?? '')),
        'nombre' => $nombreRuta,
    ];
}

$parseProveedorRutaCompuesta = static function (string $valor): array {
    $resultado = [
        'proveedores' => [],
        'ruta' => '',
    ];
    $texto = trim($valor);
    if ($texto === '') {
        return $resultado;
    }

    if (preg_match('/PROVEEDORES:\s*(.*?)\s*\|\s*RUTA:\s*(.*)$/i', $texto, $coincide)) {
        $listaProveedores = trim((string)($coincide[1] ?? ''));
        $ruta = trim((string)($coincide[2] ?? ''));
        if ($listaProveedores !== '') {
            $resultado['proveedores'] = array_values(array_filter(array_map(
                static fn(string $nombre): string => trim($nombre),
                explode(',', $listaProveedores)
            ), static fn(string $nombre): bool => $nombre !== ''));
        }
        $resultado['ruta'] = $ruta;
        return $resultado;
    }

    $resultado['proveedores'] = [$texto];
    return $resultado;
};

// Obtener usuarios para responsable
$usuarios = $db->fetchAll("SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre");

// Obtener estados de fermentación
$estadosFermentacion = $db->fetchAll("SELECT * FROM estados_fermentacion ORDER BY orden");

// Escala de calificación aparente: 0-4 individual, luego rangos hasta >65%.
$opcionesCalificacionHumedad = [
    0 => '0%',
    1 => '1%',
    2 => '2%',
    3 => '3%',
    4 => '4%',
    10 => '5-10%',
    15 => '11-15%',
    20 => '16-20%',
    25 => '21-25%',
    30 => '26-30%',
    35 => '31-35%',
    40 => '36-40%',
    45 => '41-45%',
    50 => '46-50%',
    55 => '51-55%',
    60 => '56-60%',
    65 => '61-65%',
    70 => '> 65%',
];

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($esFormularioRecepcion) {
        $etapaFormulario = 'recepcion';
    } else {
        $etapaFormulario = strtolower(trim((string)($_POST['etapa'] ?? $etapaFormulario)));
        if (!in_array($etapaFormulario, ['recepcion', 'completo'], true)) {
            $etapaFormulario = 'recepcion';
        }
    }
    $esFormularioRecepcion = $etapaFormulario === 'recepcion';

    $lote_id = intval($_POST['lote_id'] ?? 0);
    $producto = trim($_POST['producto'] ?? '');
    $codificacion = $esFormularioRecepcion
        ? trim((string)($ficha['codificacion'] ?? ''))
        : trim($_POST['codificacion'] ?? '');
    $proveedor_id = intval($_POST['proveedor_id'] ?? 0);
    $proveedor_ids = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['proveedor_ids'] ?? [])),
        static fn(int $idProveedor): bool => $idProveedor > 0
    )));
    $ruta_entrega = trim((string)($_POST['ruta_entrega'] ?? ''));
    if ($ruta_entrega === 'NO_APLICA') {
        $ruta_entrega = 'NO APLICA';
    }
    $proveedor_ruta = trim($_POST['proveedor_ruta'] ?? '');
    $tipo_entrega = trim($_POST['tipo_entrega'] ?? '');
    $fecha_entrada = $_POST['fecha_entrada'] ?? null;
    $revision_limpieza = trim($_POST['revision_limpieza'] ?? '');
    $revision_olor_normal = trim($_POST['revision_olor_normal'] ?? '');
    $revision_ausencia_moho = trim($_POST['revision_ausencia_moho'] ?? '');
    $peso_bruto = is_numeric($_POST['peso_bruto'] ?? null) ? (float)$_POST['peso_bruto'] : null;
    $tara_no_aplica = isset($_POST['tara_no_aplica']) && (string)$_POST['tara_no_aplica'] === '1';
    $tara_envase = is_numeric($_POST['tara_envase'] ?? null) ? (float)$_POST['tara_envase'] : null;
    if ($tara_no_aplica) {
        $tara_envase = null;
    }
    $peso_final_registro = is_numeric($_POST['peso_final_registro'] ?? null) ? (float)$_POST['peso_final_registro'] : null;
    $unidad_peso = strtoupper(trim($_POST['unidad_peso'] ?? 'KG'));
    $calificacion_humedad = isset($_POST['calificacion_humedad']) && $_POST['calificacion_humedad'] !== '' ? (int)$_POST['calificacion_humedad'] : null;
    $calidad_registro = trim($_POST['calidad_registro'] ?? '');
    $presencia_defectos = is_numeric($_POST['presencia_defectos'] ?? null) ? (float)$_POST['presencia_defectos'] : null;
    $clasificacion_compra = trim($_POST['clasificacion_compra'] ?? '');
    $precio_base_dia = is_numeric($_POST['precio_base_dia'] ?? null) ? (float)$_POST['precio_base_dia'] : null;
    $calidad_asignada = trim($_POST['calidad_asignada'] ?? '');
    $diferencial_usd = is_numeric($_POST['diferencial_usd'] ?? null) ? (float)$_POST['diferencial_usd'] : null;
    $precio_unitario_final = is_numeric($_POST['precio_unitario_final'] ?? null) ? (float)$_POST['precio_unitario_final'] : null;
    $precio_total_pagar = is_numeric($_POST['precio_total_pagar'] ?? null) ? (float)$_POST['precio_total_pagar'] : null;
    $fecha_pago = $esFormularioRecepcion
        ? trim((string)($ficha['fecha_pago'] ?? ''))
        : trim((string)($_POST['fecha_pago'] ?? ''));
    $tipo_comprobante = $esFormularioRecepcion
        ? strtoupper(trim((string)($ficha['tipo_comprobante'] ?? '')))
        : strtoupper(trim((string)($_POST['tipo_comprobante'] ?? '')));
    $factura_compra = $esFormularioRecepcion
        ? trim((string)($ficha['factura_compra'] ?? ''))
        : trim((string)($_POST['factura_compra'] ?? ''));
    $cantidad_comprada = $esFormularioRecepcion
        ? (is_numeric($ficha['cantidad_comprada'] ?? null) ? (float)$ficha['cantidad_comprada'] : null)
        : (is_numeric($_POST['cantidad_comprada'] ?? null) ? (float)$_POST['cantidad_comprada'] : null);
    $cantidad_comprada_unidad = $esFormularioRecepcion
        ? strtoupper(trim((string)($ficha['cantidad_comprada_unidad'] ?? 'KG')))
        : strtoupper(trim((string)($_POST['cantidad_comprada_unidad'] ?? 'KG')));
    $forma_pago = $esFormularioRecepcion
        ? strtoupper(trim((string)($ficha['forma_pago'] ?? '')))
        : strtoupper(trim((string)($_POST['forma_pago'] ?? '')));
    $fermentacion_estado = $esFormularioRecepcion
        ? trim((string)($ficha['fermentacion_estado'] ?? ''))
        : trim($_POST['fermentacion_estado'] ?? '');
    $secado_inicio = $esFormularioRecepcion
        ? ($ficha['secado_inicio'] ?? null)
        : ($_POST['secado_inicio'] ?? null);
    $secado_fin = $esFormularioRecepcion
        ? ($ficha['secado_fin'] ?? null)
        : ($_POST['secado_fin'] ?? null);
    $temperatura = $esFormularioRecepcion
        ? (float)($ficha['temperatura'] ?? 0)
        : floatval($_POST['temperatura'] ?? 0);
    $tiempo_horas = $esFormularioRecepcion
        ? (float)($ficha['tiempo_horas'] ?? 0)
        : floatval($_POST['tiempo_horas'] ?? 0);
    $responsable_id = intval($_POST['responsable_id'] ?? 0) ?: null;
    $observaciones = trim($_POST['observaciones'] ?? '');

    // Validaciones
    if ($lote_id <= 0) {
        $error = 'Debe seleccionar un lote';
    } else {
        $lote = $db->fetchOne("SELECT id FROM lotes WHERE id = ?", [$lote_id]);
        if (!$lote) {
            $error = 'El lote seleccionado no existe';
        }
    }

    if (!$error && !in_array($tipo_entrega, ['RUTAS', 'COMERCIANTE', 'ENTREGA_INDIVIDUAL'], true)) {
        $error = 'Debe seleccionar el tipo de entrega';
    }

    if (!$error && $esFormularioRecepcion && $ruta_entrega !== '') {
        $claveRuta = strtolower($ruta_entrega);
        if ($claveRuta !== 'no aplica' && !isset($rutasPorNombre[$claveRuta])) {
            $error = 'Debe seleccionar una ruta válida o indicar No aplica';
        }
    }

    if (!$error && !in_array($revision_limpieza, ['CUMPLE', 'NO_CUMPLE'], true)) {
        $error = 'Debe registrar la revisión visual de limpieza';
    }

    if (!$error && !in_array($revision_olor_normal, ['CUMPLE', 'NO_CUMPLE'], true)) {
        $error = 'Debe registrar la revisión visual de olor normal';
    }

    if (!$error && !in_array($revision_ausencia_moho, ['CUMPLE', 'NO_CUMPLE'], true)) {
        $error = 'Debe registrar la revisión visual de ausencia de moho';
    }

    if (!$error && ($peso_bruto === null || $peso_bruto <= 0)) {
        $error = 'Debe registrar un peso bruto válido';
    }

    if (!$error && !$tara_no_aplica && ($tara_envase === null || $tara_envase < 0)) {
        $error = 'Debe registrar una tara de envase válida';
    }

    if (!$error && $peso_final_registro === null && $peso_bruto !== null) {
        if ($tara_envase !== null) {
            $peso_final_registro = $peso_bruto - $tara_envase;
        } else {
            $peso_final_registro = $peso_bruto;
        }
    }

    if (!$error && ($peso_final_registro === null || $peso_final_registro <= 0)) {
        $error = 'Debe registrar un peso final válido';
    }

    if (!$error && !in_array($unidad_peso, ['LB', 'KG', 'QQ'], true)) {
        $error = 'La unidad de peso no es válida';
    }

    if (!$error && ($calificacion_humedad === null || !array_key_exists($calificacion_humedad, $opcionesCalificacionHumedad))) {
        $error = 'La calificación aparente debe ser 0-4 o en rangos de 5% hasta >65%';
    }

    if (!$error && !in_array($calidad_registro, ['SECO', 'SEMISECO', 'ESCURRIDO', 'BABA'], true)) {
        $error = 'Debe seleccionar la calidad del registro';
    }

    if (!$error && ($presencia_defectos === null || $presencia_defectos < 0 || $presencia_defectos > 10)) {
        $error = 'La presencia de defectos debe estar entre 0% y 10%';
    }

    if (!$error && !in_array($clasificacion_compra, ['APTO', 'APTO_DESCUENTO', 'NO_APTO', 'APTO_BONIFICACION'], true)) {
        $error = 'Debe seleccionar la clasificación de compra';
    }

    if (!$error && !$esFormularioRecepcion && ($precio_base_dia === null || $precio_base_dia < 0)) {
        $error = 'Debe registrar un precio base válido';
    }

    if (!$error && !$esFormularioRecepcion && !in_array($calidad_asignada, ['APTO', 'APTO_DESCUENTO', 'NO_APTO'], true)) {
        $error = 'Debe seleccionar la calidad asignada';
    }

    if (!$error && $forma_pago !== '' && !in_array($forma_pago, ['EFECTIVO', 'TRANSFERENCIA', 'CHEQUE', 'OTROS'], true)) {
        $error = 'La forma de pago no es válida';
    }

    if (!$error && $fecha_pago !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_pago)) {
        $error = 'La fecha de pago no es válida';
    }

    if (!$error && $cantidad_comprada !== null && $cantidad_comprada <= 0) {
        $error = 'La cantidad comprada debe ser mayor a 0';
    }

    if (!$error && !in_array($cantidad_comprada_unidad, ['LB', 'KG', 'QQ'], true)) {
        $error = 'La unidad de cantidad comprada no es válida';
    }

    if (!$error && $tipo_comprobante !== '' && !in_array($tipo_comprobante, ['FACTURA', 'NOTA_COMPRA'], true)) {
        $error = 'El tipo de comprobante no es válido';
    }

    if (!$error && $precio_unitario_final === null && $precio_base_dia !== null) {
        $precio_unitario_final = $precio_base_dia + ($diferencial_usd ?? 0.0);
    }

    if (!$error && $precio_unitario_final !== null && $precio_unitario_final < 0) {
        $error = 'El precio unitario final no puede ser negativo';
    }

    if (!$error && $precio_unitario_final !== null && $peso_final_registro !== null) {
        $pesoFinalKg = Helpers::pesoToKg($peso_final_registro, $unidad_peso);
        if ($pesoFinalKg <= 0) {
            $error = 'El peso final convertido a kg debe ser mayor a 0';
        } else {
            $cantidadParaCalculo = ($cantidad_comprada !== null && $cantidad_comprada > 0)
                ? Helpers::pesoToKg($cantidad_comprada, $cantidad_comprada_unidad)
                : $pesoFinalKg;
            $precio_total_pagar = $precio_unitario_final * $cantidadParaCalculo;
        }
    }

    $hayDatosPago = !$esFormularioRecepcion && (
        $fecha_pago !== ''
        || $tipo_comprobante !== ''
        || $factura_compra !== ''
        || $cantidad_comprada !== null
        || $forma_pago !== ''
    );

    if (!$error && $hayDatosPago) {
        if (!empty($faltanColumnasPago)) {
            $error = 'Faltan columnas en fichas_registro para guardar el registro de pago. Ejecute el patch_registro_pagos_fichas.sql';
        } elseif ($fecha_pago === '') {
            $error = 'Debe registrar la fecha de pago';
        } elseif (!in_array($tipo_comprobante, ['FACTURA', 'NOTA_COMPRA'], true)) {
            $error = 'Debe seleccionar el tipo de comprobante';
        } elseif ($factura_compra === '') {
            $error = 'Debe registrar la factura asignada a la compra';
        } elseif ($cantidad_comprada === null || $cantidad_comprada <= 0) {
            $error = 'Debe registrar la cantidad comprada';
        } elseif (!in_array($cantidad_comprada_unidad, ['LB', 'KG', 'QQ'], true)) {
            $error = 'Debe seleccionar una unidad válida para la cantidad comprada';
        } elseif (!in_array($forma_pago, ['EFECTIVO', 'TRANSFERENCIA', 'CHEQUE', 'OTROS'], true)) {
            $error = 'Debe seleccionar la forma de pago';
        }
    }

    if (!$error && $esFormularioRecepcion) {
        $proveedoresSeleccionados = [];
        foreach ($proveedor_ids as $idProveedorSeleccionado) {
            if (isset($proveedoresPorId[$idProveedorSeleccionado])) {
                $proveedoresSeleccionados[] = $proveedoresPorId[$idProveedorSeleccionado];
            }
        }

        if (empty($proveedoresSeleccionados) && $proveedor_id > 0 && isset($proveedoresPorId[$proveedor_id])) {
            $proveedoresSeleccionados[] = $proveedoresPorId[$proveedor_id];
        }

        if (empty($proveedoresSeleccionados) && $proveedor_ruta !== '') {
            $parseadoAnterior = $parseProveedorRutaCompuesta($proveedor_ruta);
            foreach ($parseadoAnterior['proveedores'] as $nombreProveedor) {
                $claveProveedor = strtolower($nombreProveedor);
                if (isset($proveedoresPorNombre[$claveProveedor])) {
                    $proveedoresSeleccionados[] = $proveedoresPorNombre[$claveProveedor];
                }
            }
            if ($ruta_entrega === '' && $parseadoAnterior['ruta'] !== '') {
                $ruta_entrega = $parseadoAnterior['ruta'];
            }
        }

        if (empty($proveedoresSeleccionados)) {
            $error = 'Debe seleccionar al menos un proveedor válido';
        } else {
            $nombresSeleccionados = [];
            foreach ($proveedoresSeleccionados as $provSel) {
                $nombreSel = trim((string)($provSel['nombre'] ?? ''));
                if ($nombreSel !== '' && !in_array($nombreSel, $nombresSeleccionados, true)) {
                    $nombresSeleccionados[] = $nombreSel;
                }
            }
            $textoProveedores = implode(', ', $nombresSeleccionados);
            $rutaTexto = $ruta_entrega !== '' ? $ruta_entrega : 'NO APLICA';
            $proveedor_ruta = 'PROVEEDORES: ' . $textoProveedores . ' | RUTA: ' . $rutaTexto;
            $proveedor_id = (int)($proveedoresSeleccionados[0]['id'] ?? 0);
        }
    }

    // Verificar codificación única (excluyendo la actual)
    if (!$error && $codificacion) {
        $existe = $db->fetchOne("SELECT id FROM fichas_registro WHERE codificacion = ? AND id != ?", [$codificacion, $id]);
        if ($existe) {
            $error = 'Ya existe otra ficha con esta codificación';
        }
    }

    if (!$error) {
        try {
            $dataFicha = [
                'lote_id' => $lote_id,
                'producto' => $producto ?: null,
                'proveedor_ruta' => $proveedor_ruta ?: null,
                'tipo_entrega' => $tipo_entrega,
                'revision_limpieza' => $revision_limpieza,
                'revision_olor_normal' => $revision_olor_normal,
                'revision_ausencia_moho' => $revision_ausencia_moho,
                'peso_bruto' => $peso_bruto,
                'tara_envase' => $tara_envase,
                'peso_final_registro' => $peso_final_registro,
                'unidad_peso' => $unidad_peso,
                'calificacion_humedad' => $calificacion_humedad,
                'calidad_registro' => $calidad_registro,
                'presencia_defectos' => $presencia_defectos,
                'clasificacion_compra' => $clasificacion_compra,
                'precio_base_dia' => $precio_base_dia,
                'calidad_asignada' => $calidad_asignada !== '' ? $calidad_asignada : null,
                'diferencial_usd' => $diferencial_usd,
                'precio_unitario_final' => $precio_unitario_final,
                'precio_total_pagar' => $precio_total_pagar,
                'fecha_entrada' => $fecha_entrada ?: null,
                'responsable_id' => $responsable_id,
                'observaciones' => $observaciones ?: null,
            ];

            if (!$esFormularioRecepcion) {
                $dataFicha['codificacion'] = $codificacion ?: null;
                $dataFicha['fermentacion_estado'] = $fermentacion_estado ?: null;
                $dataFicha['secado_inicio'] = $secado_inicio ?: null;
                $dataFicha['secado_fin'] = $secado_fin ?: null;
                $dataFicha['temperatura'] = $temperatura > 0 ? $temperatura : null;
                $dataFicha['tiempo_horas'] = $tiempo_horas > 0 ? $tiempo_horas : null;
            }

            if (!$esFormularioRecepcion && $hasFichaCol('fecha_pago')) {
                $dataFicha['fecha_pago'] = $fecha_pago !== '' ? $fecha_pago : null;
            }
            if (!$esFormularioRecepcion && $hasFichaCol('tipo_comprobante')) {
                $dataFicha['tipo_comprobante'] = $tipo_comprobante !== '' ? $tipo_comprobante : null;
            }
            if (!$esFormularioRecepcion && $hasFichaCol('factura_compra')) {
                $dataFicha['factura_compra'] = $factura_compra !== '' ? $factura_compra : null;
            }
            if (!$esFormularioRecepcion && $hasFichaCol('cantidad_comprada_unidad')) {
                $dataFicha['cantidad_comprada_unidad'] = in_array($cantidad_comprada_unidad, ['LB', 'KG', 'QQ'], true)
                    ? $cantidad_comprada_unidad
                    : 'KG';
            }
            if (!$esFormularioRecepcion && $hasFichaCol('cantidad_comprada')) {
                $dataFicha['cantidad_comprada'] = $cantidad_comprada;
            }
            if (!$esFormularioRecepcion && $hasFichaCol('forma_pago')) {
                $dataFicha['forma_pago'] = $forma_pago !== '' ? $forma_pago : null;
            }

            $db->update('fichas_registro', $dataFicha, 'id = :id', ['id' => $id]);

            // Registrar en historial
            Helpers::registrarHistorial($lote_id, 'ficha_editada', "Ficha de registro #{$id} actualizada");

            redirect('/fichas/ver.php?id=' . urlencode((string)$id) . '&updated=1');

        } catch (Exception $e) {
            $error = 'Error al actualizar la ficha: ' . $e->getMessage();
        }
    }
}

// Usar datos del POST si hay error, sino usar datos de la DB
$formData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $ficha;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $proveedorRutaFicha = trim((string)($ficha['proveedor_ruta'] ?? ''));
    $parseadoProveedorRuta = $parseProveedorRutaCompuesta($proveedorRutaFicha);
    $idsProveedores = [];
    foreach ($parseadoProveedorRuta['proveedores'] as $nombreProveedor) {
        $claveProveedor = strtolower($nombreProveedor);
        if (isset($proveedoresPorNombre[$claveProveedor])) {
            $idsProveedores[] = (int)$proveedoresPorNombre[$claveProveedor]['id'];
        }
    }
    $formData['proveedor_ids'] = array_values(array_unique(array_filter($idsProveedores, static fn(int $idProv): bool => $idProv > 0)));
    $formData['ruta_entrega'] = $parseadoProveedorRuta['ruta'] !== '' ? $parseadoProveedorRuta['ruta'] : 'NO_APLICA';
    $formData['proveedor_id'] = !empty($formData['proveedor_ids']) ? (int)$formData['proveedor_ids'][0] : 0;
    $formData['tara_no_aplica'] = ($ficha['tara_envase'] === null || $ficha['tara_envase'] === '') ? '1' : '0';
}

if (!isset($formData['cantidad_comprada_unidad']) || trim((string)$formData['cantidad_comprada_unidad']) === '') {
    $formData['cantidad_comprada_unidad'] = (string)($ficha['cantidad_comprada_unidad'] ?? 'KG');
}
if (!isset($formData['tipo_comprobante']) || trim((string)$formData['tipo_comprobante']) === '') {
    $formData['tipo_comprobante'] = (string)($ficha['tipo_comprobante'] ?? '');
}

$pageTitle = $esFormularioRecepcion ? "Editar Ficha de Recepción #{$id}" : "Editar Ficha #{$id}";
ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= $esFormularioRecepcion ? 'Editar Ficha de Recepción' : 'Editar Ficha' ?> #<?= $id ?></h1>
            <p class="text-gray-600">
                <?= $esFormularioRecepcion ? 'Actualice los datos de recepción de esta ficha' : ('Lote: ' . htmlspecialchars($ficha['lote_codigo'])) ?>
            </p>
        </div>
        <a href="<?= APP_URL ?>/fichas/ver.php?id=<?= (int)$id ?>" class="text-amber-600 hover:text-amber-700">
            <i class="fas fa-arrow-left mr-2"></i>Volver al detalle
        </a>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 rounded-xl p-4">
        <div class="flex items-center gap-3">
            <i class="fas fa-exclamation-circle text-red-600"></i>
            <span class="text-red-800"><?= htmlspecialchars($error) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($esFormularioRecepcion): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
        <div class="flex items-center gap-3">
            <i class="fas fa-info-circle text-blue-600"></i>
            <span class="text-blue-800">Esta edición corresponde solo a la ficha de recepción. El pago, la codificación y la etiqueta se gestionan en formularios separados.</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Formulario -->
    <form method="POST" class="space-y-6">
        <input type="hidden" name="etapa" value="<?= htmlspecialchars($etapaFormulario) ?>">
        <!-- Información del Lote -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-amber-50 to-orange-50">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-box text-amber-600 mr-2"></i><?= $esFormularioRecepcion ? 'Datos Generales de Recepción' : 'Información del Lote' ?>
                </h2>
            </div>
            <div class="p-6 space-y-4">
                <?php if (!$esFormularioRecepcion): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Lote <span class="text-red-500">*</span>
                        </label>
                        <select name="lote_id" id="lote_id" required
                                class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                            <option value="">Seleccione un lote</option>
                            <?php foreach ($lotes as $lote): ?>
                            <?php $pesoLote = (float)($lote['peso_recibido_kg'] ?? 0); ?>
                            <option value="<?= $lote['id'] ?>" 
                                    data-proveedor="<?= htmlspecialchars($lote['proveedor_nombre'] ?? '') ?>"
                                    data-variedad="<?= htmlspecialchars($lote['variedad_nombre'] ?? '') ?>"
                                    <?= $formData['lote_id'] == $lote['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lote['codigo']) ?> 
                                <?php if ($lote['proveedor_nombre']): ?>
                                - <?= htmlspecialchars($lote['proveedor_nombre']) ?>
                                <?php endif; ?>
                                (<?= number_format($pesoLote, 2) ?> kg)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Codificación</label>
                        <input type="text" name="codificacion" 
                               value="<?= htmlspecialchars($formData['codificacion'] ?? '') ?>"
                               placeholder="Código único de la ficha"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="lote_id" value="<?= (int)($formData['lote_id'] ?? 0) ?>">
                <?php endif; ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Producto</label>
                        <input type="text" name="producto" 
                               value="<?= htmlspecialchars($formData['producto'] ?? '') ?>"
                               placeholder="Tipo de producto"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    </div>

                    <?php if ($esFormularioRecepcion): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Proveedor(es) <span class="text-red-500">*</span></label>
                        <?php
                        $proveedorIdsSeleccionados = array_values(array_unique(array_filter(
                            array_map('intval', (array)($formData['proveedor_ids'] ?? [])),
                            static fn(int $idProveedor): bool => $idProveedor > 0
                        )));
                        $proveedorPrimario = $proveedorIdsSeleccionados[0] ?? (int)($formData['proveedor_id'] ?? 0);
                        ?>
                        <input type="hidden" name="proveedor_id" id="proveedor_id" value="<?= $proveedorPrimario > 0 ? $proveedorPrimario : '' ?>">
                        <select name="proveedor_ids[]" id="proveedor_ids" multiple size="6"
                                class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                            <?php foreach ($proveedores as $provItem): ?>
                                <?php
                                $idProv = (int)($provItem['id'] ?? 0);
                                $nombreProv = trim((string)($provItem['nombre'] ?? ''));
                                if ($idProv <= 0 || $nombreProv === '') {
                                    continue;
                                }
                                $codigoProv = trim((string)($provItem['codigo'] ?? ''));
                                $labelProv = ($codigoProv !== '' ? $codigoProv . ' - ' : '') . $nombreProv;
                                ?>
                                <option value="<?= $idProv ?>" data-id="<?= $idProv ?>" <?= in_array($idProv, $proveedorIdsSeleccionados, true) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($labelProv) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Puede seleccionar múltiples proveedores (Ctrl/Cmd + clic).</p>
                    </div>
                    <?php else: ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Proveedor</label>
                        <?php
                        $proveedorRutaActual = trim((string)($formData['proveedor_ruta'] ?? ''));
                        $nombresProveedores = [];
                        foreach ($proveedores as $provItem) {
                            $nombreProv = trim((string)($provItem['nombre'] ?? ''));
                            if ($nombreProv !== '') {
                                $nombresProveedores[$nombreProv] = true;
                            }
                        }
                        $proveedorFueraCatalogo = $proveedorRutaActual !== '' && !isset($nombresProveedores[$proveedorRutaActual]);
                        ?>
                        <select name="proveedor_ruta" id="proveedor_ruta"
                                class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                            <option value="">Seleccione un proveedor</option>
                            <?php foreach ($proveedores as $provItem): ?>
                                <?php
                                $nombreProv = trim((string)($provItem['nombre'] ?? ''));
                                if ($nombreProv === '') {
                                    continue;
                                }
                                $codigoProv = trim((string)($provItem['codigo'] ?? ''));
                                $labelProv = ($codigoProv !== '' ? $codigoProv . ' - ' : '') . $nombreProv;
                                ?>
                                <option value="<?= htmlspecialchars($nombreProv) ?>" data-id="<?= (int)$provItem['id'] ?>" <?= $proveedorRutaActual === $nombreProv ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($labelProv) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($proveedorFueraCatalogo): ?>
                                <option value="<?= htmlspecialchars($proveedorRutaActual) ?>" selected>
                                    <?= htmlspecialchars($proveedorRutaActual) ?> (actual)
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($esFormularioRecepcion): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Ruta de entrega</label>
                    <?php
                    $rutaEntregaActual = trim((string)($formData['ruta_entrega'] ?? ''));
                    if ($rutaEntregaActual === 'NO APLICA' || $rutaEntregaActual === '') {
                        $rutaEntregaActual = 'NO_APLICA';
                    }
                    ?>
                    <select name="ruta_entrega" id="ruta_entrega"
                            class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                        <option value="NO_APLICA" <?= $rutaEntregaActual === 'NO_APLICA' ? 'selected' : '' ?>>No aplica</option>
                        <?php foreach ($rutasDisponibles as $rutaItem): ?>
                            <?php
                            $nombreRuta = trim((string)($rutaItem['nombre'] ?? ''));
                            if ($nombreRuta === '') {
                                continue;
                            }
                            $codigoRuta = trim((string)($rutaItem['codigo'] ?? ''));
                            $labelRuta = ($codigoRuta !== '' ? $codigoRuta . ' - ' : '') . $nombreRuta;
                            ?>
                            <option value="<?= htmlspecialchars($nombreRuta) ?>" <?= $rutaEntregaActual === $nombreRuta ? 'selected' : '' ?>>
                                <?= htmlspecialchars($labelRuta) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Fecha de Entrada</label>
                    <input type="date" name="fecha_entrada" 
                           value="<?= htmlspecialchars($formData['fecha_entrada'] ?? '') ?>"
                           class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                </div>
            </div>
        </div>

        <!-- Proceso Planta -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-emerald-50 to-teal-50">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-industry text-emerald-600 mr-2"></i><?= $esFormularioRecepcion ? 'Recepción y Verificación Visual' : 'Proceso Planta' ?>
                </h2>
                <p class="text-sm text-gray-500 mt-1">Registro de revisión visual, pesaje y determinación de precio</p>
            </div>
            <div class="p-6 space-y-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-3">Tipo de entrega <span class="text-red-500">*</span></label>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <?php
                        $tipoEntregaActual = $formData['tipo_entrega'] ?? '';
                        $tiposEntrega = [
                            'RUTAS' => 'Rutas',
                            'COMERCIANTE' => 'Comerciante',
                            'ENTREGA_INDIVIDUAL' => 'Entrega Individual',
                        ];
                        foreach ($tiposEntrega as $valor => $label):
                        ?>
                        <label class="flex items-center gap-2 px-4 py-3 border border-gray-200 rounded-xl hover:border-emerald-400 cursor-pointer">
                            <input type="radio" name="tipo_entrega" value="<?= $valor ?>" class="text-emerald-600 focus:ring-emerald-500"
                                   <?= $tipoEntregaActual === $valor ? 'checked' : '' ?>>
                            <span class="text-sm text-gray-800"><?= $label ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">I. Revisión de calidad visual</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full border border-gray-200 rounded-lg overflow-hidden">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600">Parámetro</th>
                                    <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600">Cumple</th>
                                    <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600">No cumple</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php
                                $revisiones = [
                                    'revision_limpieza' => 'Impurezas',
                                    'revision_olor_normal' => 'Olor normal (sin olores extraños)',
                                    'revision_ausencia_moho' => 'Ausencia de moho visible',
                                ];
                                foreach ($revisiones as $name => $label):
                                $actual = $formData[$name] ?? '';
                                ?>
                                <tr>
                                    <td class="px-3 py-2 text-sm text-gray-800 font-medium"><?= $label ?></td>
                                    <td class="px-3 py-2 text-center">
                                        <input type="radio" name="<?= $name ?>" value="CUMPLE" class="text-emerald-600 focus:ring-emerald-500"
                                               <?= $actual === 'CUMPLE' ? 'checked' : '' ?>>
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <input type="radio" name="<?= $name ?>" value="NO_CUMPLE" class="text-red-600 focus:ring-red-500"
                                               <?= $actual === 'NO_CUMPLE' ? 'checked' : '' ?>>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">II. Registros</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Peso bruto</label>
                            <input type="number" name="peso_bruto" id="peso_bruto" step="0.01" min="0"
                                   value="<?= htmlspecialchars($formData['peso_bruto'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Tara de envase</label>
                            <?php $taraNoAplicaActual = (string)($formData['tara_no_aplica'] ?? '') === '1'; ?>
                            <label class="inline-flex items-center gap-2 text-xs text-gray-600 mb-2">
                                <input type="checkbox" name="tara_no_aplica" id="tara_no_aplica" value="1" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                       <?= $taraNoAplicaActual ? 'checked' : '' ?>>
                                No aplica
                            </label>
                            <input type="number" name="tara_envase" id="tara_envase" step="0.01" min="0"
                                   value="<?= htmlspecialchars($taraNoAplicaActual ? '' : ($formData['tara_envase'] ?? '')) ?>"
                                   <?= $taraNoAplicaActual ? 'disabled' : '' ?>
                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Peso final</label>
                            <input type="number" name="peso_final_registro" id="peso_final_registro" step="0.01" min="0"
                                   value="<?= htmlspecialchars($formData['peso_final_registro'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Unidad</label>
                            <?php $unidadActual = strtoupper($formData['unidad_peso'] ?? 'KG'); ?>
                            <select name="unidad_peso" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="LB" <?= $unidadActual === 'LB' ? 'selected' : '' ?>>LB</option>
                                <option value="KG" <?= $unidadActual === 'KG' ? 'selected' : '' ?>>KG</option>
                                <option value="QQ" <?= $unidadActual === 'QQ' ? 'selected' : '' ?>>QQ</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Calificación aparente (%)</label>
                            <?php $calificacionActual = $formData['calificacion_humedad'] ?? ''; ?>
                            <select name="calificacion_humedad"
                                    class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">Seleccione</option>
                                <?php foreach ($opcionesCalificacionHumedad as $valor => $etiqueta): ?>
                                    <option value="<?= $valor ?>" <?= (string)$calificacionActual === (string)$valor ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($etiqueta) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">0-4 individual; luego rangos de 5% hasta &gt;65%.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Calidad</label>
                            <?php $calidadRegistro = $formData['calidad_registro'] ?? ''; ?>
                            <select name="calidad_registro" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">Seleccione</option>
                                <option value="SECO" <?= $calidadRegistro === 'SECO' ? 'selected' : '' ?>>Seco</option>
                                <option value="SEMISECO" <?= $calidadRegistro === 'SEMISECO' ? 'selected' : '' ?>>Semiseco</option>
                                <option value="ESCURRIDO" <?= $calidadRegistro === 'ESCURRIDO' ? 'selected' : '' ?>>Escurrido</option>
                                <option value="BABA" <?= $calidadRegistro === 'BABA' ? 'selected' : '' ?>>Baba</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Presencia de defectos (%)</label>
                            <input type="number" name="presencia_defectos" step="0.01" min="0" max="10"
                                   value="<?= htmlspecialchars($formData['presencia_defectos'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">III. Determinación de precio</h3>
                    <p class="inline-flex px-3 py-1 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-700 mb-3">
                        Precio oficial de compra: USD/kg
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
                        <?php
                        $clasificacionActual = $formData['clasificacion_compra'] ?? '';
                        $clasificaciones = [
                            'APTO' => 'Apto',
                            'APTO_DESCUENTO' => 'Apto con descuento',
                            'NO_APTO' => 'No apto',
                            'APTO_BONIFICACION' => 'Apto con bonificaciones',
                        ];
                        foreach ($clasificaciones as $valor => $label):
                        ?>
                        <label class="flex items-center gap-2 px-3 py-2 border border-gray-200 rounded-lg hover:border-emerald-400 cursor-pointer">
                            <input type="radio" name="clasificacion_compra" value="<?= $valor ?>" class="text-emerald-600 focus:ring-emerald-500"
                                   <?= $clasificacionActual === $valor ? 'checked' : '' ?>>
                            <span class="text-sm text-gray-800"><?= $label ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Calidad asignada</label>
                            <?php $calidadAsignada = $formData['calidad_asignada'] ?? ''; ?>
                            <select name="calidad_asignada" id="calidad_asignada" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">Seleccione</option>
                                <option value="APTO" <?= $calidadAsignada === 'APTO' ? 'selected' : '' ?>>Apto</option>
                                <option value="APTO_DESCUENTO" <?= $calidadAsignada === 'APTO_DESCUENTO' ? 'selected' : '' ?>>Apto con descuento</option>
                                <option value="NO_APTO" <?= $calidadAsignada === 'NO_APTO' ? 'selected' : '' ?>>No apto</option>
                            </select>
                        </div>
                    </div>

                    <?php if (!$esFormularioRecepcion): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Precio base del día (USD)</label>
                            <input type="number" name="precio_base_dia" id="precio_base_dia" step="0.0001" min="0"
                                   value="<?= htmlspecialchars($formData['precio_base_dia'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Descuento o bonificación (USD)</label>
                            <input type="number" name="diferencial_usd" id="diferencial_usd" step="0.0001"
                                   value="<?= htmlspecialchars($formData['diferencial_usd'] ?? '0') ?>"
                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            <p class="text-xs text-gray-500 mt-1">Usa negativo para descuento y positivo para bonificación.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Precio unitario final (USD/KG)</label>
                            <input type="number" name="precio_unitario_final" id="precio_unitario_final" step="0.0001" min="0"
                                   value="<?= htmlspecialchars($formData['precio_unitario_final'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Precio total a pagar (USD)</label>
                            <input type="number" name="precio_total_pagar" id="precio_total_pagar" step="0.01" min="0"
                                   value="<?= htmlspecialchars($formData['precio_total_pagar'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            <p class="text-xs text-gray-500 mt-1">Cálculo: precio unitario final (USD/KG) x cantidad convertida a KG.</p>
                            <p id="peso_equiv_kg" class="text-xs text-emerald-700 mt-1"></p>
                            <p id="cantidad_calculo" class="text-xs text-emerald-700"></p>
                        </div>
                    </div>
                    <?php else: ?>
                    <p class="text-xs text-gray-500 mt-4">El precio comercial se registra en la fase de pago.</p>
                    <?php endif; ?>
                </div>

                <?php if (!$esFormularioRecepcion): ?>
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">IV. Registro de pago</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Fecha de pago</label>
                            <input type="date" name="fecha_pago"
                                   value="<?= htmlspecialchars($formData['fecha_pago'] ?? '') ?>"
                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Proveedor</label>
                            <input type="text" id="proveedor_pago"
                                   value=""
                                   readonly
                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-700">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Factura asignada a la compra</label>
                            <?php $tipoComprobanteActual = strtoupper((string)($formData['tipo_comprobante'] ?? '')); ?>
                            <select name="tipo_comprobante"
                                    class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 mb-2">
                                <option value="">Tipo de comprobante</option>
                                <option value="FACTURA" <?= $tipoComprobanteActual === 'FACTURA' ? 'selected' : '' ?>>Factura</option>
                                <option value="NOTA_COMPRA" <?= $tipoComprobanteActual === 'NOTA_COMPRA' ? 'selected' : '' ?>>Nota de compra</option>
                            </select>
                            <input type="text" name="factura_compra"
                                   value="<?= htmlspecialchars($formData['factura_compra'] ?? '') ?>"
                                   placeholder="Nro. de factura o comprobante"
                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Cantidad comprada</label>
                            <div class="grid grid-cols-3 gap-2">
                                <input type="number" name="cantidad_comprada" id="cantidad_comprada" step="0.01" min="0"
                                       value="<?= htmlspecialchars($formData['cantidad_comprada'] ?? '') ?>"
                                       placeholder="Ejemplo: 100.00"
                                       class="col-span-2 w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                <?php $cantidadUnidadActual = strtoupper((string)($formData['cantidad_comprada_unidad'] ?? 'KG')); ?>
                                <select name="cantidad_comprada_unidad" id="cantidad_comprada_unidad"
                                        class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                    <option value="LB" <?= $cantidadUnidadActual === 'LB' ? 'selected' : '' ?>>LB</option>
                                    <option value="KG" <?= $cantidadUnidadActual === 'KG' ? 'selected' : '' ?>>KG</option>
                                    <option value="QQ" <?= $cantidadUnidadActual === 'QQ' ? 'selected' : '' ?>>QQ</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Variedad de cacao</label>
                            <input type="text" id="variedad_pago"
                                   value=""
                                   readonly
                                   class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-700">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Forma de pago</label>
                            <?php $formaPagoActual = strtoupper($formData['forma_pago'] ?? ''); ?>
                            <select name="forma_pago"
                                    class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">Seleccione</option>
                                <option value="EFECTIVO" <?= $formaPagoActual === 'EFECTIVO' ? 'selected' : '' ?>>Efectivo</option>
                                <option value="TRANSFERENCIA" <?= $formaPagoActual === 'TRANSFERENCIA' ? 'selected' : '' ?>>Transferencia</option>
                                <option value="CHEQUE" <?= $formaPagoActual === 'CHEQUE' ? 'selected' : '' ?>>Cheque</option>
                                <option value="OTROS" <?= $formaPagoActual === 'OTROS' ? 'selected' : '' ?>>Otros</option>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$esFormularioRecepcion): ?>
        <!-- Estado de Fermentación -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-orange-50 to-amber-50">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-temperature-high text-orange-600 mr-2"></i>Estado de Fermentación
                </h2>
            </div>
            <div class="p-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Estado</label>
                    <select name="fermentacion_estado"
                            class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                        <option value="">Sin especificar</option>
                        <?php foreach ($estadosFermentacion as $estado): ?>
                        <option value="<?= htmlspecialchars($estado['nombre']) ?>"
                                <?= ($formData['fermentacion_estado'] ?? '') === $estado['nombre'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($estado['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                        <option value="Pendiente" <?= ($formData['fermentacion_estado'] ?? '') === 'Pendiente' ? 'selected' : '' ?>>Pendiente</option>
                        <option value="En Proceso" <?= ($formData['fermentacion_estado'] ?? '') === 'En Proceso' ? 'selected' : '' ?>>En Proceso</option>
                        <option value="Finalizada" <?= ($formData['fermentacion_estado'] ?? '') === 'Finalizada' ? 'selected' : '' ?>>Finalizada</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Datos de Secado -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-yellow-50 to-amber-50">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-sun text-yellow-600 mr-2"></i>Datos de Secado
                </h2>
            </div>
            <div class="p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Inicio de Secado</label>
                        <?php
                        $secadoInicio = $formData['secado_inicio'] ?? '';
                        if ($secadoInicio && strpos($secadoInicio, 'T') === false) {
                            $secadoInicio = str_replace(' ', 'T', $secadoInicio);
                        }
                        ?>
                        <input type="datetime-local" name="secado_inicio" 
                               value="<?= htmlspecialchars(substr($secadoInicio, 0, 16)) ?>"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Fin de Secado</label>
                        <?php
                        $secadoFin = $formData['secado_fin'] ?? '';
                        if ($secadoFin && strpos($secadoFin, 'T') === false) {
                            $secadoFin = str_replace(' ', 'T', $secadoFin);
                        }
                        ?>
                        <input type="datetime-local" name="secado_fin" 
                               value="<?= htmlspecialchars(substr($secadoFin, 0, 16)) ?>"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Temperatura (°C)</label>
                        <input type="number" name="temperatura" step="0.01" min="0" max="100"
                               value="<?= htmlspecialchars($formData['temperatura'] ?? '') ?>"
                               placeholder="0.00"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Tiempo (horas)</label>
                        <input type="number" name="tiempo_horas" step="0.01" min="0"
                               value="<?= htmlspecialchars($formData['tiempo_horas'] ?? '') ?>"
                               placeholder="0.00"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Responsable y Observaciones -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-gray-50 to-slate-50">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-user text-gray-600 mr-2"></i>Responsable y Observaciones
                </h2>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Responsable</label>
                    <select name="responsable_id"
                            class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                        <option value="">Seleccione un responsable</option>
                        <?php foreach ($usuarios as $usuario): ?>
                        <option value="<?= $usuario['id'] ?>"
                                <?= ($formData['responsable_id'] ?? '') == $usuario['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($usuario['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Observaciones</label>
                    <textarea name="observaciones" rows="4"
                              placeholder="Notas adicionales sobre esta ficha..."
                              class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-amber-500"><?= htmlspecialchars($formData['observaciones'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Botones -->
        <div class="flex items-center justify-between">
            <div class="text-sm text-gray-500">
                <i class="fas fa-clock mr-1"></i>
                Última actualización: <?= date('d/m/Y H:i', strtotime($ficha['updated_at'])) ?>
            </div>
            <div class="flex items-center gap-4">
                <a href="<?= APP_URL ?>/fichas/ver.php?id=<?= (int)$id ?>" class="px-6 py-2.5 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 transition-colors">
                    Cancelar
                </a>
                <button type="submit" class="px-6 py-2.5 bg-amber-600 text-white rounded-xl hover:bg-amber-700 transition-colors">
                    <i class="fas fa-save mr-2"></i><?= $esFormularioRecepcion ? 'Guardar Recepción' : 'Guardar Cambios' ?>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const loteSelect = document.getElementById('lote_id');
    const proveedorSelect = document.getElementById('proveedor_ruta');
    const proveedoresMultiSelect = document.getElementById('proveedor_ids');

    if (loteSelect?.value) {
        sincronizarDatosLote(loteSelect);
    }

    loteSelect?.addEventListener('change', function() {
        sincronizarDatosLote(this);
    });

    proveedorSelect?.addEventListener('change', function() {
        actualizarProveedorSeleccionado();
    });
    proveedoresMultiSelect?.addEventListener('change', function() {
        actualizarProveedorSeleccionado();
    });

    const pesoBrutoInput = document.getElementById('peso_bruto');
    const taraNoAplicaCheckbox = document.getElementById('tara_no_aplica');
    const taraEnvaseInput = document.getElementById('tara_envase');
    const pesoFinalInput = document.getElementById('peso_final_registro');
    const precioBaseInput = document.getElementById('precio_base_dia');
    const diferencialInput = document.getElementById('diferencial_usd');
    const precioUnitarioInput = document.getElementById('precio_unitario_final');
    const precioTotalInput = document.getElementById('precio_total_pagar');
    const unidadPesoSelect = document.querySelector('select[name="unidad_peso"]');
    const cantidadCompradaInput = document.getElementById('cantidad_comprada');
    const cantidadCompradaUnidad = document.getElementById('cantidad_comprada_unidad');
    const pesoEquivKg = document.getElementById('peso_equiv_kg');
    const cantidadCalculo = document.getElementById('cantidad_calculo');
    const clasificacionRadios = document.querySelectorAll('input[name="clasificacion_compra"]');
    const calidadAsignada = document.getElementById('calidad_asignada');

    function toNumber(input) {
        return parseFloat(input?.value || '0') || 0;
    }

    function pesoToKg(peso, unidad) {
        if (unidad === 'LB') return peso * 0.45359237;
        if (unidad === 'QQ') return peso * 45.36;
        return peso;
    }

    function calcularPesoFinal() {
        if (!pesoBrutoInput || !pesoFinalInput) {
            return;
        }
        const bruto = toNumber(pesoBrutoInput);
        const taraNoAplica = !!taraNoAplicaCheckbox?.checked;
        if (taraEnvaseInput) {
            taraEnvaseInput.disabled = taraNoAplica;
            if (taraNoAplica) {
                taraEnvaseInput.value = '';
            }
        }
        const tara = taraNoAplica ? 0 : toNumber(taraEnvaseInput);
        if (bruto > 0 && tara >= 0) {
            const final = bruto - tara;
            if (final >= 0) {
                pesoFinalInput.value = final.toFixed(2);
            }
        }
        calcularTotalPagar();
    }

    function calcularPrecioUnitarioFinal() {
        if (!precioBaseInput || !diferencialInput || !precioUnitarioInput) {
            return;
        }
        const base = toNumber(precioBaseInput);
        const diferencial = toNumber(diferencialInput);
        const unitario = base + diferencial;
        if (unitario >= 0) {
            precioUnitarioInput.value = unitario.toFixed(4);
        } else {
            precioUnitarioInput.value = '0.0000';
        }
        calcularTotalPagar();
    }

    function calcularTotalPagar() {
        if (!precioUnitarioInput || !precioTotalInput || !pesoFinalInput) {
            return;
        }
        const unitario = toNumber(precioUnitarioInput);
        const peso = toNumber(pesoFinalInput);
        const unidad = unidadPesoSelect?.value || 'KG';
        const pesoKg = pesoToKg(peso, unidad);
        const cantidadIngresada = parseFloat(cantidadCompradaInput?.value || '');
        const unidadCantidad = cantidadCompradaUnidad?.value || 'KG';
        const usarCantidadIngresada = !Number.isNaN(cantidadIngresada) && cantidadIngresada > 0;
        const cantidadKg = usarCantidadIngresada ? pesoToKg(cantidadIngresada, unidadCantidad) : pesoKg;
        const total = unitario * cantidadKg;
        precioTotalInput.value = total.toFixed(2);
        if (pesoEquivKg) {
            pesoEquivKg.textContent = `Peso equivalente: ${pesoKg.toFixed(2)} kg`;
        }
        if (cantidadCalculo) {
            cantidadCalculo.textContent = usarCantidadIngresada
                ? `Cantidad aplicada: ${cantidadIngresada.toFixed(2)} ${unidadCantidad} (${cantidadKg.toFixed(2)} kg)`
                : `Cantidad aplicada: ${cantidadKg.toFixed(2)} kg`;
        }
    }

    function sincronizarCalidad() {
        if (!calidadAsignada) {
            return;
        }
        const seleccionado = Array.from(clasificacionRadios).find(r => r.checked)?.value;
        if (!seleccionado) return;
        if (seleccionado === 'APTO_BONIFICACION') {
            calidadAsignada.value = 'APTO';
            return;
        }
        if (seleccionado === 'APTO' || seleccionado === 'APTO_DESCUENTO' || seleccionado === 'NO_APTO') {
            calidadAsignada.value = seleccionado;
        }
    }

    pesoBrutoInput?.addEventListener('input', calcularPesoFinal);
    taraNoAplicaCheckbox?.addEventListener('change', calcularPesoFinal);
    taraEnvaseInput?.addEventListener('input', calcularPesoFinal);
    pesoFinalInput?.addEventListener('input', calcularTotalPagar);
    precioBaseInput?.addEventListener('input', calcularPrecioUnitarioFinal);
    diferencialInput?.addEventListener('input', calcularPrecioUnitarioFinal);
    precioUnitarioInput?.addEventListener('input', calcularTotalPagar);
    unidadPesoSelect?.addEventListener('change', calcularTotalPagar);
    cantidadCompradaInput?.addEventListener('input', calcularTotalPagar);
    cantidadCompradaUnidad?.addEventListener('change', calcularTotalPagar);
    clasificacionRadios.forEach(r => r.addEventListener('change', sincronizarCalidad));

    calcularPesoFinal();
    calcularPrecioUnitarioFinal();
    calcularTotalPagar();
    sincronizarCalidad();
    actualizarProveedorSeleccionado();
});

function actualizarProveedorSeleccionado() {
    const proveedorIdInput = document.getElementById('proveedor_id');
    if (!proveedorIdInput) {
        return;
    }

    const proveedorMultiple = document.getElementById('proveedor_ids');
    if (proveedorMultiple && proveedorMultiple.tagName === 'SELECT') {
        const seleccion = Array.from(proveedorMultiple.selectedOptions);
        const primerProveedor = seleccion.length > 0 ? seleccion[0] : null;
        proveedorIdInput.value = primerProveedor ? (primerProveedor.dataset?.id || primerProveedor.value || '') : '';
        return;
    }

    const proveedorSimple = document.getElementById('proveedor_ruta');
    if (proveedorSimple && proveedorSimple.tagName === 'SELECT') {
        const option = proveedorSimple.options[proveedorSimple.selectedIndex];
        proveedorIdInput.value = option?.dataset?.id || '';
        return;
    }

    proveedorIdInput.value = '';
}

function sincronizarDatosLote(select) {
    if (!select || select.tagName !== 'SELECT') {
        return;
    }
    const option = select.options[select.selectedIndex];
    const proveedor = option?.dataset?.proveedor || '';
    const variedad = option?.dataset?.variedad || '';
    const proveedorInput = document.getElementById('proveedor_ruta');
    const proveedorPagoInput = document.getElementById('proveedor_pago');
    const variedadPagoInput = document.getElementById('variedad_pago');

    if (proveedor && proveedorInput && proveedorInput.tagName === 'SELECT') {
        const existeProveedor = Array.from(proveedorInput.options).some((opt) => opt.value === proveedor);
        if (!existeProveedor) {
            proveedorInput.add(new Option(`${proveedor} (actual)`, proveedor));
        }
        if (!proveedorInput.value) {
            proveedorInput.value = proveedor;
        }
        actualizarProveedorSeleccionado();
    } else if (proveedor && proveedorInput && proveedorInput.tagName !== 'SELECT' && !proveedorInput.value) {
        proveedorInput.value = proveedor;
    }

    if (proveedorPagoInput) {
        const proveedorTexto = proveedor || (proveedorInput?.value ?? '');
        proveedorPagoInput.value = proveedorTexto;
    }
    if (variedadPagoInput) {
        variedadPagoInput.value = variedad || 'No especificada';
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
