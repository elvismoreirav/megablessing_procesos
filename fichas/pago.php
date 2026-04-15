<?php
/**
 * MEGABLESSING - Registro de Pago por Ficha
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$db = Database::getInstance();
$error = '';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('/fichas/index.php?vista=pagos');
}

Helpers::ensureFichaRegistroPagoColumns();
$colsFichas = array_column($db->fetchAll("SHOW COLUMNS FROM fichas_registro"), 'Field');
$hasFichaCol = static fn(string $name): bool => in_array($name, $colsFichas, true);
$columnasPago = ['fecha_pago', 'tipo_comprobante', 'factura_compra', 'fuente_pago', 'cantidad_comprada_unidad', 'cantidad_comprada', 'forma_pago'];
$faltantesPago = array_values(array_filter($columnasPago, static fn(string $col): bool => !$hasFichaCol($col)));
$columnasPrecio = ['precio_base_dia', 'diferencial_usd', 'precio_unitario_final', 'precio_total_pagar'];
$faltantesPrecio = array_values(array_filter($columnasPrecio, static fn(string $col): bool => !$hasFichaCol($col)));

$ficha = $db->fetchOne("
    SELECT f.*,
           l.codigo as lote_codigo,
           l.fecha_entrada as lote_fecha_entrada,
           l.proveedor_id as lote_proveedor_id,
           COALESCE(NULLIF(TRIM(p.nombre), ''), NULLIF(TRIM(f.proveedor_ruta), '')) as proveedor_nombre,
           v.nombre as variedad_nombre
    FROM fichas_registro f
    LEFT JOIN lotes l ON f.lote_id = l.id
    LEFT JOIN proveedores p ON l.proveedor_id = p.id
    LEFT JOIN variedades v ON l.variedad_id = v.id
    WHERE f.id = ?
", [$id]);

if (!$ficha) {
    setFlash('error', 'Ficha no encontrada');
    redirect('/fichas/index.php?vista=pagos');
}

$colsProveedores = array_column($db->fetchAll("SHOW COLUMNS FROM proveedores"), 'Field');
$filtroProveedorReal = in_array('es_categoria', $colsProveedores, true)
    ? ' AND (es_categoria = 0 OR es_categoria IS NULL)'
    : '';
$proveedoresCatalogo = $db->fetchAll("
    SELECT id, codigo, nombre
    FROM proveedores
    WHERE activo = 1{$filtroProveedorReal}
    ORDER BY nombre
");
$proveedoresPorId = [];
foreach ($proveedoresCatalogo as $proveedor) {
    $proveedoresPorId[(int)($proveedor['id'] ?? 0)] = $proveedor;
}

$tablaPagoDetalleLista = Helpers::ensureFichaPagoDetalleTable();
$detallesBase = Helpers::getFichaPagoDetalles($id, $ficha, $proveedoresCatalogo);
$participantesPago = Helpers::getFichaPagoParticipantes($ficha, $proveedoresCatalogo);

$normalizarFilaPago = static function (array $row, array $proveedoresPorId) use ($id): array {
    $proveedorId = (int)($row['proveedor_id'] ?? 0);
    $proveedorNombre = trim((string)($row['proveedor_nombre'] ?? ''));
    if ($proveedorNombre === '' && $proveedorId > 0 && isset($proveedoresPorId[$proveedorId])) {
        $proveedorNombre = trim((string)($proveedoresPorId[$proveedorId]['nombre'] ?? ''));
    }

    $unidad = strtoupper(trim((string)($row['cantidad_comprada_unidad'] ?? 'KG')));
    if (!in_array($unidad, ['LB', 'KG', 'QQ'], true)) {
        $unidad = 'KG';
    }

    $cantidad = is_numeric($row['cantidad_comprada'] ?? null) ? (float)$row['cantidad_comprada'] : null;
    $cantidadKg = is_numeric($row['cantidad_comprada_kg'] ?? null) ? (float)$row['cantidad_comprada_kg'] : null;
    if (($cantidadKg === null || $cantidadKg <= 0) && $cantidad !== null && $cantidad > 0) {
        $cantidadKg = Helpers::pesoToKg($cantidad, $unidad);
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'ficha_id' => (int)($row['ficha_id'] ?? $id),
        'proveedor_id' => $proveedorId,
        'proveedor_nombre' => $proveedorNombre !== '' ? $proveedorNombre : 'Proveedor',
        'fecha_pago' => trim((string)($row['fecha_pago'] ?? '')),
        'tipo_comprobante' => strtoupper(trim((string)($row['tipo_comprobante'] ?? ''))),
        'factura_compra' => trim((string)($row['factura_compra'] ?? '')),
        'fuente_pago' => strtoupper(trim((string)($row['fuente_pago'] ?? ''))),
        'cantidad_comprada_unidad' => $unidad,
        'cantidad_comprada' => $cantidad,
        'cantidad_comprada_kg' => $cantidadKg,
        'forma_pago' => Helpers::normalizePagoFormaValue($row['forma_pago'] ?? ''),
        'precio_base_dia' => is_numeric($row['precio_base_dia'] ?? null) ? (float)$row['precio_base_dia'] : null,
        'diferencial_usd' => is_numeric($row['diferencial_usd'] ?? null) ? (float)$row['diferencial_usd'] : 0.0,
        'precio_unitario_final' => is_numeric($row['precio_unitario_final'] ?? null) ? (float)$row['precio_unitario_final'] : null,
        'precio_total_pagar' => is_numeric($row['precio_total_pagar'] ?? null) ? (float)$row['precio_total_pagar'] : null,
    ];
};

$formDetalles = $detallesBase;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!empty($faltantesPrecio)) {
        $error = 'Faltan columnas de precio en fichas_registro. Ejecute database/patch_fase_planta_fichas.sql';
    } elseif (!empty($faltantesPago)) {
        $error = 'Faltan columnas de pago en fichas_registro. Ejecute database/patch_registro_pagos_fichas.sql';
    } elseif (!$tablaPagoDetalleLista) {
        $error = 'No se pudo preparar el detalle de pagos por proveedor. Revise la base de datos o ejecute el patch correspondiente.';
    }

    $rawDetalles = [];
    if (!$error) {
        $rawDetalles = array_values(array_filter(
            (array)($_POST['pago_detalle'] ?? []),
            static fn($row): bool => is_array($row)
        ));
        if (empty($rawDetalles)) {
            $error = 'Debe registrar al menos un detalle de pago';
        }
    }

    if (!$error) {
        $detallesGuardar = [];
        foreach ($rawDetalles as $row) {
            $detalle = $normalizarFilaPago($row, $proveedoresPorId);
            $nombreProveedor = $detalle['proveedor_nombre'];

            if ($detalle['fecha_pago'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $detalle['fecha_pago'])) {
                $error = "Debe ingresar una fecha de pago válida para {$nombreProveedor}";
                break;
            }
            if (!in_array($detalle['tipo_comprobante'], ['FACTURA', 'NOTA_COMPRA'], true)) {
                $error = "Debe seleccionar un tipo de comprobante válido para {$nombreProveedor}";
                break;
            }
            if ($detalle['factura_compra'] === '') {
                $error = "Debe ingresar la factura o comprobante para {$nombreProveedor}";
                break;
            }
            if (!in_array($detalle['fuente_pago'], ['MEGABLESSING', 'BELLA'], true)) {
                $error = "Debe seleccionar una fuente de pago válida para {$nombreProveedor}";
                break;
            }
            if ($detalle['cantidad_comprada'] === null || $detalle['cantidad_comprada'] <= 0) {
                $error = "Debe ingresar una cantidad comprada mayor a 0 para {$nombreProveedor}";
                break;
            }
            if (!in_array($detalle['cantidad_comprada_unidad'], ['LB', 'KG', 'QQ'], true)) {
                $error = "Debe seleccionar una unidad válida para {$nombreProveedor}";
                break;
            }
            if (empty(Helpers::normalizePagoFormas($detalle['forma_pago']))) {
                $error = "Debe seleccionar al menos una forma de pago válida para {$nombreProveedor}";
                break;
            }
            if ($detalle['precio_base_dia'] === null || $detalle['precio_base_dia'] < 0) {
                $error = "Debe ingresar un precio base del día válido para {$nombreProveedor}";
                break;
            }

            if ($detalle['precio_unitario_final'] === null) {
                $detalle['precio_unitario_final'] = $detalle['precio_base_dia'] + $detalle['diferencial_usd'];
            }
            if ($detalle['precio_unitario_final'] < 0) {
                $error = "El precio unitario final no puede ser negativo para {$nombreProveedor}";
                break;
            }

            $detalle['cantidad_comprada_kg'] = Helpers::pesoToKg($detalle['cantidad_comprada'], $detalle['cantidad_comprada_unidad']);
            if ($detalle['cantidad_comprada_kg'] <= 0) {
                $error = "La cantidad convertida a kg debe ser mayor a 0 para {$nombreProveedor}";
                break;
            }

            $detalle['precio_total_pagar'] = $detalle['precio_unitario_final'] * $detalle['cantidad_comprada_kg'];
            $detallesGuardar[] = $detalle;
        }

        if (!$error) {
            $resumenPago = Helpers::getFichaPagoResumen($detallesGuardar);
            $formDetalles = $detallesGuardar;

            try {
                $db->beginTransaction();
                $db->delete('fichas_pago_detalle', 'ficha_id = ?', [$id]);

                foreach ($detallesGuardar as $detalle) {
                    $db->insert('fichas_pago_detalle', [
                        'ficha_id' => $id,
                        'proveedor_id' => $detalle['proveedor_id'] > 0 ? $detalle['proveedor_id'] : null,
                        'proveedor_nombre' => $detalle['proveedor_nombre'],
                        'fecha_pago' => $detalle['fecha_pago'],
                        'tipo_comprobante' => $detalle['tipo_comprobante'],
                        'factura_compra' => $detalle['factura_compra'],
                        'fuente_pago' => $detalle['fuente_pago'],
                        'cantidad_comprada_unidad' => $detalle['cantidad_comprada_unidad'],
                        'cantidad_comprada' => $detalle['cantidad_comprada'],
                        'cantidad_comprada_kg' => $detalle['cantidad_comprada_kg'],
                        'forma_pago' => $detalle['forma_pago'],
                        'precio_base_dia' => $detalle['precio_base_dia'],
                        'diferencial_usd' => $detalle['diferencial_usd'],
                        'precio_unitario_final' => $detalle['precio_unitario_final'],
                        'precio_total_pagar' => $detalle['precio_total_pagar'],
                    ]);
                }

                $dataPago = [];
                if ($hasFichaCol('precio_base_dia')) {
                    $dataPago['precio_base_dia'] = $resumenPago['precio_base_dia'];
                }
                if ($hasFichaCol('diferencial_usd')) {
                    $dataPago['diferencial_usd'] = $resumenPago['diferencial_usd'];
                }
                if ($hasFichaCol('precio_unitario_final')) {
                    $dataPago['precio_unitario_final'] = $resumenPago['precio_unitario_final'];
                }
                if ($hasFichaCol('precio_total_pagar')) {
                    $dataPago['precio_total_pagar'] = $resumenPago['precio_total_pagar'] > 0
                        ? $resumenPago['precio_total_pagar']
                        : null;
                }
                if ($hasFichaCol('fecha_pago')) {
                    $dataPago['fecha_pago'] = $resumenPago['fecha_pago'];
                }
                if ($hasFichaCol('tipo_comprobante')) {
                    $dataPago['tipo_comprobante'] = $resumenPago['tipo_comprobante'];
                }
                if ($hasFichaCol('factura_compra')) {
                    $dataPago['factura_compra'] = $resumenPago['factura_compra'];
                }
                if ($hasFichaCol('fuente_pago')) {
                    $dataPago['fuente_pago'] = $resumenPago['fuente_pago'];
                }
                if ($hasFichaCol('cantidad_comprada_unidad')) {
                    $dataPago['cantidad_comprada_unidad'] = 'KG';
                }
                if ($hasFichaCol('cantidad_comprada')) {
                    $dataPago['cantidad_comprada'] = $resumenPago['cantidad_comprada'];
                }
                if ($hasFichaCol('forma_pago')) {
                    $dataPago['forma_pago'] = $resumenPago['forma_pago'];
                }

                $db->update('fichas_registro', $dataPago, 'id = :id', ['id' => $id]);
                if (!empty($ficha['lote_id']) && (int)$ficha['lote_id'] > 0) {
                    Helpers::registrarHistorial(
                        $ficha['lote_id'],
                        'ficha_pago_registrado',
                        "Registro de pago completado en ficha #{$id}"
                    );
                }
                $db->commit();

                setFlash('success', 'Registro de pago guardado correctamente para la ficha #' . $id);
                redirect('/fichas/index.php?vista=pagos');
            } catch (Throwable $e) {
                if ($db->getConnection()->inTransaction()) {
                    $db->rollback();
                }
                $error = 'Error al guardar el registro de pago: ' . $e->getMessage();
            }
        } else {
            $formDetalles = array_map(
                static fn(array $row): array => $normalizarFilaPago($row, $proveedoresPorId),
                $rawDetalles
            );
        }
    } else {
        $formDetalles = !empty($_POST['pago_detalle'])
            ? array_map(
                static fn(array $row): array => $normalizarFilaPago($row, $proveedoresPorId),
                array_values(array_filter((array)$_POST['pago_detalle'], static fn($row): bool => is_array($row)))
            )
            : $detallesBase;
    }
}

$pesoFinalOriginal = (float)($ficha['peso_final_registro'] ?? 0);
$unidadPesoFicha = strtoupper(trim((string)($ficha['unidad_peso'] ?? 'KG')));
$pesoFinalKg = Helpers::pesoToKg($pesoFinalOriginal, $unidadPesoFicha);
$pesoFinalLb = Helpers::kgToLb($pesoFinalKg);
$pesoFinalQq = Helpers::kgToQQ($pesoFinalKg);
$resumenFormulario = Helpers::getFichaPagoResumen($formDetalles);
$cantidadResumenKg = (float)($resumenFormulario['cantidad_total_kg'] ?? 0);
$cantidadResumenQq = Helpers::kgToQQ($cantidadResumenKg);
$cantidadResumenLb = Helpers::kgToLb($cantidadResumenKg);
$nombresParticipantes = array_values(array_filter(array_map(
    static fn(array $item): string => trim((string)($item['proveedor_nombre'] ?? '')),
    $participantesPago
)));
$textoParticipantes = !empty($nombresParticipantes)
    ? implode(', ', $nombresParticipantes)
    : trim((string)($ficha['proveedor_nombre'] ?? '—'));
$esPagoIndividualPorProveedor = count($formDetalles) > 1;

$pageTitle = "Registro de Pago - Ficha #{$id}";
ob_start();
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Registro de Pagos</h1>
            <p class="text-gray-600">Ficha #<?= (int)$id ?> · Lote <?= htmlspecialchars((string)($ficha['lote_codigo'] ?: 'Sin lote asignado')) ?></p>
        </div>
        <a href="<?= APP_URL ?>/fichas/index.php?vista=pagos" class="text-amber-600 hover:text-amber-700">
            <i class="fas fa-arrow-left mr-2"></i>Volver al listado de pagos
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

    <?php if ($esPagoIndividualPorProveedor): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
        <div class="flex items-center gap-3">
            <i class="fas fa-info-circle text-blue-600"></i>
            <span class="text-blue-800">Esta ficha corresponde a una ruta con varios proveedores. El pago se registra individualmente por proveedor dentro del mismo lote.</span>
        </div>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500"><?= $esPagoIndividualPorProveedor ? 'Proveedor(es)' : 'Proveedor' ?></p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars($textoParticipantes !== '' ? $textoParticipantes : '—') ?></p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Variedad de cacao</p>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars((string)($ficha['variedad_nombre'] ?? '—')) ?></p>
            </div>
            <div class="p-3 rounded-lg bg-gray-50 border border-gray-100">
                <p class="text-xs text-gray-500">Peso final equivalente</p>
                <p class="font-semibold text-gray-900">
                    <?= number_format($pesoFinalOriginal, 2) ?> <?= htmlspecialchars($unidadPesoFicha) ?>
                </p>
                <p class="text-xs text-gray-500 mt-1">
                    <?= number_format($pesoFinalKg, 2) ?> KG · <?= number_format($pesoFinalLb, 2) ?> LB · <?= number_format($pesoFinalQq, 2) ?> QQ
                </p>
            </div>
        </div>
    </div>

    <form method="POST" class="space-y-6">
        <?php foreach ($formDetalles as $indice => $detalle): ?>
        <?php
        $proveedorNombreDetalle = trim((string)($detalle['proveedor_nombre'] ?? 'Proveedor'));
        $tipoComprobanteActual = strtoupper((string)($detalle['tipo_comprobante'] ?? ''));
        $fuentePagoActual = strtoupper((string)($detalle['fuente_pago'] ?? ''));
        $formasPagoActuales = Helpers::normalizePagoFormas($detalle['forma_pago'] ?? '');
        $cantidadUnidadActual = strtoupper((string)($detalle['cantidad_comprada_unidad'] ?? 'KG'));
        ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 js-pago-detalle">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">
                        <?= $esPagoIndividualPorProveedor ? 'Pago por proveedor' : 'Detalle del pago' ?>
                    </h2>
                    <p class="text-sm text-gray-600"><?= htmlspecialchars($proveedorNombreDetalle) ?></p>
                </div>
                <?php if ($esPagoIndividualPorProveedor): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full bg-amber-100 text-amber-700 text-xs font-semibold">
                    Proveedor <?= $indice + 1 ?> de <?= count($formDetalles) ?>
                </span>
                <?php endif; ?>
            </div>

            <input type="hidden" name="pago_detalle[<?= $indice ?>][id]" value="<?= (int)($detalle['id'] ?? 0) ?>">
            <input type="hidden" name="pago_detalle[<?= $indice ?>][ficha_id]" value="<?= (int)$id ?>">
            <input type="hidden" name="pago_detalle[<?= $indice ?>][proveedor_id]" value="<?= (int)($detalle['proveedor_id'] ?? 0) ?>">
            <input type="hidden" name="pago_detalle[<?= $indice ?>][proveedor_nombre]" value="<?= htmlspecialchars($proveedorNombreDetalle) ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Fecha de pago <span class="text-red-500">*</span></label>
                    <input type="date"
                           name="pago_detalle[<?= $indice ?>][fecha_pago]"
                           value="<?= htmlspecialchars((string)($detalle['fecha_pago'] ?? '')) ?>"
                           required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 js-fecha">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de comprobante <span class="text-red-500">*</span></label>
                    <select name="pago_detalle[<?= $indice ?>][tipo_comprobante]"
                            required
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 js-tipo">
                        <option value="">Seleccione</option>
                        <option value="FACTURA" <?= $tipoComprobanteActual === 'FACTURA' ? 'selected' : '' ?>>Factura</option>
                        <option value="NOTA_COMPRA" <?= $tipoComprobanteActual === 'NOTA_COMPRA' ? 'selected' : '' ?>>Nota de compra</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Factura/Comprobante <span class="text-red-500">*</span></label>
                    <input type="text"
                           name="pago_detalle[<?= $indice ?>][factura_compra]"
                           value="<?= htmlspecialchars((string)($detalle['factura_compra'] ?? '')) ?>"
                           placeholder="Nro. de factura o comprobante"
                           required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 js-factura">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Fuente de pago <span class="text-red-500">*</span></label>
                    <select name="pago_detalle[<?= $indice ?>][fuente_pago]"
                            required
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 js-fuente">
                        <option value="">Seleccione</option>
                        <option value="MEGABLESSING" <?= $fuentePagoActual === 'MEGABLESSING' ? 'selected' : '' ?>>Megablessing</option>
                        <option value="BELLA" <?= $fuentePagoActual === 'BELLA' ? 'selected' : '' ?>>Bella</option>
                    </select>
                </div>
                <div class="xl:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Forma de pago <span class="text-red-500">*</span></label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
                        <?php foreach ([
                            'EFECTIVO' => 'Efectivo',
                            'TRANSFERENCIA' => 'Transferencia',
                            'CHEQUE' => 'Cheque',
                            'OTROS' => 'Otros',
                            'REMANENTE' => 'Remanente',
                        ] as $formaValue => $formaLabel): ?>
                        <label class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <input type="checkbox"
                                   name="pago_detalle[<?= $indice ?>][forma_pago][]"
                                   value="<?= htmlspecialchars($formaValue) ?>"
                                   <?= in_array($formaValue, $formasPagoActuales, true) ? 'checked' : '' ?>
                                   class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 js-forma">
                            <span><?= htmlspecialchars($formaLabel) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Cantidad comprada <span class="text-red-500">*</span></label>
                    <div class="grid grid-cols-3 gap-2">
                        <input type="number"
                               name="pago_detalle[<?= $indice ?>][cantidad_comprada]"
                               value="<?= htmlspecialchars((string)($detalle['cantidad_comprada'] ?? '')) ?>"
                               step="0.01"
                               min="0.01"
                               required
                               class="col-span-2 w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 js-cantidad">
                        <select name="pago_detalle[<?= $indice ?>][cantidad_comprada_unidad]"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 js-unidad">
                            <option value="LB" <?= $cantidadUnidadActual === 'LB' ? 'selected' : '' ?>>LB</option>
                            <option value="KG" <?= $cantidadUnidadActual === 'KG' ? 'selected' : '' ?>>KG</option>
                            <option value="QQ" <?= $cantidadUnidadActual === 'QQ' ? 'selected' : '' ?>>QQ</option>
                        </select>
                    </div>
                    <input type="hidden" name="pago_detalle[<?= $indice ?>][cantidad_comprada_kg]" value="<?= htmlspecialchars((string)($detalle['cantidad_comprada_kg'] ?? '')) ?>" class="js-cantidad-kg">
                    <p class="text-xs text-emerald-700 mt-1 js-cantidad-equivalente"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Precio base del día (USD)</label>
                    <input type="number"
                           name="pago_detalle[<?= $indice ?>][precio_base_dia]"
                           value="<?= htmlspecialchars((string)($detalle['precio_base_dia'] ?? '')) ?>"
                           step="0.0001"
                           min="0"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 js-base">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Descuento o bonificación (USD)</label>
                    <input type="number"
                           name="pago_detalle[<?= $indice ?>][diferencial_usd]"
                           value="<?= htmlspecialchars((string)($detalle['diferencial_usd'] ?? '0')) ?>"
                           step="0.0001"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 js-diferencial">
                    <p class="text-xs text-gray-500 mt-1">Usa negativo para descuento y positivo para bonificación.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Precio unitario final (USD)</label>
                    <input type="number"
                           name="pago_detalle[<?= $indice ?>][precio_unitario_final]"
                           value="<?= htmlspecialchars((string)($detalle['precio_unitario_final'] ?? '')) ?>"
                           step="0.0001"
                           min="0"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 js-unitario">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Precio a pagar (USD)</label>
                    <input type="number"
                           name="pago_detalle[<?= $indice ?>][precio_total_pagar]"
                           value="<?= htmlspecialchars((string)($detalle['precio_total_pagar'] ?? '')) ?>"
                           step="0.01"
                           min="0"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 js-total">
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Resumen general del pago</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="p-4 rounded-lg bg-gray-50 border border-gray-100">
                    <p class="text-xs text-gray-500">Proveedores en el pago</p>
                    <p class="text-2xl font-bold text-gray-900" id="resumen_detalles"><?= number_format((float)($resumenFormulario['detalle_count'] ?? 0), 0) ?></p>
                </div>
                <div class="p-4 rounded-lg bg-gray-50 border border-gray-100">
                    <p class="text-xs text-gray-500">Cantidad total equivalente</p>
                    <p class="text-2xl font-bold text-gray-900"><span id="resumen_cantidad_kg"><?= number_format($cantidadResumenKg, 2) ?></span> kg</p>
                    <p class="text-sm text-gray-500 mt-1">
                        <span id="resumen_cantidad_qq"><?= number_format($cantidadResumenQq, 2) ?></span> QQ ·
                        <span id="resumen_cantidad_lb"><?= number_format($cantidadResumenLb, 2) ?></span> LB
                    </p>
                </div>
                <div class="p-4 rounded-lg bg-gray-50 border border-gray-100">
                    <p class="text-xs text-gray-500">Total a pagar</p>
                    <p class="text-2xl font-bold text-emerald-700" id="resumen_total_pago">$ <?= number_format((float)($resumenFormulario['precio_total_pagar'] ?? 0), 2) ?></p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="<?= APP_URL ?>/fichas/index.php?vista=pagos" class="px-6 py-2.5 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 transition-colors">
                Cancelar
            </a>
            <button type="submit" class="px-6 py-2.5 bg-amber-600 text-white rounded-xl hover:bg-amber-700 transition-colors">
                <i class="fas fa-save mr-2"></i>Guardar Registro de Pago
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const detalleCards = Array.from(document.querySelectorAll('.js-pago-detalle'));
    const resumenDetalles = document.getElementById('resumen_detalles');
    const resumenCantidadKg = document.getElementById('resumen_cantidad_kg');
    const resumenCantidadQq = document.getElementById('resumen_cantidad_qq');
    const resumenCantidadLb = document.getElementById('resumen_cantidad_lb');
    const resumenTotalPago = document.getElementById('resumen_total_pago');

    function toNumber(input) {
        return parseFloat(input?.value || '0') || 0;
    }

    function pesoToKg(peso, unidad) {
        if (unidad === 'LB') return peso * 0.45359237;
        if (unidad === 'QQ') return peso * 45.36;
        return peso;
    }

    function kgToLb(kg) {
        return kg / 0.45359237;
    }

    function kgToQq(kg) {
        return kg / 45.36;
    }

    function formatNumber(value, decimals) {
        return Number.isFinite(value) ? value.toFixed(decimals) : (0).toFixed(decimals);
    }

    function calcularDetalle(card) {
        const cantidadInput = card.querySelector('.js-cantidad');
        const unidadInput = card.querySelector('.js-unidad');
        const cantidadKgInput = card.querySelector('.js-cantidad-kg');
        const baseInput = card.querySelector('.js-base');
        const diferencialInput = card.querySelector('.js-diferencial');
        const unitarioInput = card.querySelector('.js-unitario');
        const totalInput = card.querySelector('.js-total');
        const equivalenteText = card.querySelector('.js-cantidad-equivalente');

        const cantidad = toNumber(cantidadInput);
        const unidad = unidadInput?.value || 'KG';
        const cantidadKg = pesoToKg(cantidad, unidad);
        const cantidadLb = kgToLb(cantidadKg);
        const cantidadQq = kgToQq(cantidadKg);

        const base = toNumber(baseInput);
        const diferencial = toNumber(diferencialInput);
        const unitario = Math.max(0, base + diferencial);
        const total = unitario * cantidadKg;

        if (unitarioInput) {
            unitarioInput.value = formatNumber(unitario, 4);
        }
        if (totalInput) {
            totalInput.value = formatNumber(total, 2);
        }
        if (cantidadKgInput) {
            cantidadKgInput.value = formatNumber(cantidadKg, 4);
        }
        if (equivalenteText) {
            equivalenteText.textContent = cantidad > 0
                ? `Equivalente: ${formatNumber(cantidadKg, 2)} KG · ${formatNumber(cantidadLb, 2)} LB · ${formatNumber(cantidadQq, 2)} QQ`
                : 'Ingrese la cantidad para calcular su equivalente.';
        }

        return {
            cantidadKg,
            total,
        };
    }

    function recalcularResumen() {
        let totalKg = 0;
        let totalPago = 0;

        detalleCards.forEach(function(card) {
            const calculo = calcularDetalle(card);
            totalKg += calculo.cantidadKg;
            totalPago += calculo.total;
        });

        if (resumenDetalles) {
            resumenDetalles.textContent = String(detalleCards.length);
        }
        if (resumenCantidadKg) {
            resumenCantidadKg.textContent = formatNumber(totalKg, 2);
        }
        if (resumenCantidadQq) {
            resumenCantidadQq.textContent = formatNumber(kgToQq(totalKg), 2);
        }
        if (resumenCantidadLb) {
            resumenCantidadLb.textContent = formatNumber(kgToLb(totalKg), 2);
        }
        if (resumenTotalPago) {
            resumenTotalPago.textContent = `$ ${formatNumber(totalPago, 2)}`;
        }
    }

    detalleCards.forEach(function(card) {
        card.querySelectorAll('input, select').forEach(function(input) {
            input.addEventListener('input', recalcularResumen);
            input.addEventListener('change', recalcularResumen);
        });
    });

    recalcularResumen();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
