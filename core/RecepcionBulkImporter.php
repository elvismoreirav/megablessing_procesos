<?php
/**
 * Servicio para plantilla e importación masiva de fichas de recepción.
 */

class RecepcionBulkImporter
{
    private const SHEET_NAME = 'Recepcion';

    private Database $db;
    private array $loteColumns = [];
    private array $fichaColumns = [];
    private array $proveedoresByCode = [];
    private array $proveedoresByName = [];
    private array $proveedoresByLabel = [];
    private array $variedadesByCode = [];
    private array $variedadesByName = [];
    private array $estadosProductoByCode = [];
    private array $estadosProductoByName = [];
    private array $templateHeaders = [
        'lote_codigo',
        'proveedor_codigo',
        'variedad_codigo',
        'tipo_entrega',
        'ruta_entrega',
        'fecha_entrada',
        'revision_limpieza',
        'revision_olor_normal',
        'revision_ausencia_moho',
        'peso_bruto',
        'tara_envase',
        'peso_final',
        'unidad_peso',
        'calificacion_humedad',
        'calidad_registro',
        'presencia_defectos',
        'clasificacion_compra',
        'precio_sugerido',
        'observaciones',
    ];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->loteColumns = Helpers::getTableColumns('lotes');
        $this->fichaColumns = Helpers::getTableColumns('fichas_registro');
        Helpers::ensureFichaProveedorRutaColumn();
        Helpers::ensureFichaProveedorPesosTable();
        $this->loadCatalogs();
    }

    public function getTemplateFileName(): string
    {
        return 'plantilla_carga_masiva_recepcion.xlsx';
    }

    public function getTemplateSheets(): array
    {
        $proveedoresRows = [['codigo', 'nombre']];
        foreach ($this->catalogRows($this->proveedoresByCode) as $item) {
            $proveedoresRows[] = [$item['codigo'], $item['nombre']];
        }

        $variedadesRows = [['codigo', 'nombre']];
        foreach ($this->catalogRows($this->variedadesByCode) as $item) {
            $variedadesRows[] = [$item['codigo'], $item['nombre']];
        }

        return [
            [
                'name' => self::SHEET_NAME,
                'rows' => [$this->templateHeaders],
            ],
            [
                'name' => 'Instrucciones',
                'rows' => [
                    ['campo', 'obligatorio', 'descripcion', 'ejemplo'],
                    ['lote_codigo', 'NO', 'Codigo de lote existente. Si queda vacio, se crea un lote automaticamente.', 'M-15-04-26-SC'],
                    ['proveedor_codigo', 'SI', 'Codigo, nombre o etiqueta del proveedor activo.', 'PR001'],
                    ['variedad_codigo', 'SI', 'Codigo o nombre de variedad activa para crear o asociar el lote.', 'CCN51'],
                    ['tipo_entrega', 'SI', 'RUTAS, COMERCIANTE o ENTREGA_INDIVIDUAL.', 'ENTREGA_INDIVIDUAL'],
                    ['ruta_entrega', 'NO', 'Ruta textual. Use NO APLICA cuando no corresponda.', 'NO APLICA'],
                    ['fecha_entrada', 'SI', 'Fecha en formato YYYY-MM-DD.', '2026-04-15'],
                    ['revision_limpieza', 'SI', 'CUMPLE o NO_CUMPLE.', 'CUMPLE'],
                    ['revision_olor_normal', 'SI', 'CUMPLE o NO_CUMPLE.', 'CUMPLE'],
                    ['revision_ausencia_moho', 'SI', 'CUMPLE o NO_CUMPLE.', 'CUMPLE'],
                    ['peso_bruto', 'SI', 'Peso bruto en la unidad indicada.', '150.00'],
                    ['tara_envase', 'NO', 'Tara del envase. Vacio equivale a 0.', '10.00'],
                    ['peso_final', 'NO', 'Si queda vacio, se calcula peso_bruto - tara_envase.', '140.00'],
                    ['unidad_peso', 'SI', 'LB, KG o QQ.', 'KG'],
                    ['calificacion_humedad', 'SI', '0-4 o rangos 5,10,...70.', '10'],
                    ['calidad_registro', 'SI', 'SECO, SEMISECO, ESCURRIDO o BABA.', 'SECO'],
                    ['presencia_defectos', 'SI', 'Porcentaje entre 0 y 10.', '1.50'],
                    ['clasificacion_compra', 'SI', 'APTO, APTO_DESCUENTO, NO_APTO o APTO_BONIFICACION.', 'APTO'],
                    ['precio_sugerido', 'NO', 'Precio sugerido/base por kg.', '150.00'],
                    ['observaciones', 'NO', 'Texto libre.', 'Carga masiva de recepcion'],
                ],
            ],
            [
                'name' => 'Catalogo_Proveedores',
                'rows' => $proveedoresRows,
            ],
            [
                'name' => 'Catalogo_Variedades',
                'rows' => $variedadesRows,
            ],
        ];
    }

    public function importFromXlsx(string $filePath, ?int $userId = null): array
    {
        $sheetRows = SimpleXlsx::readSheetRows($filePath, self::SHEET_NAME);
        $preparedRows = $this->prepareRows($sheetRows);

        $created = [];
        $duplicates = [];
        $errors = [];
        $seenFingerprints = [];
        $blankRows = 0;
        $processedRows = 0;

        foreach ($preparedRows['rows'] as $rowEntry) {
            $rowValues = $rowEntry['values'];
            if ($this->isEmptyRow($rowValues)) {
                $blankRows++;
                continue;
            }

            $processedRows++;
            try {
                $record = $this->normalizeRow($rowValues, $preparedRows['column_map']);
                $fingerprint = $this->buildFingerprint($record);

                if (isset($seenFingerprints[$fingerprint])) {
                    $duplicates[] = [
                        'row_number' => $rowEntry['row_number'],
                        'detail' => 'Fila repetida dentro del archivo. Coincide con la fila ' . $seenFingerprints[$fingerprint] . '.',
                    ];
                    continue;
                }
                $seenFingerprints[$fingerprint] = $rowEntry['row_number'];

                $existing = $this->findExistingDuplicate($record);
                if ($existing !== null) {
                    $duplicates[] = [
                        'row_number' => $rowEntry['row_number'],
                        'detail' => 'Ya existe una ficha de recepcion equivalente: #' . $existing['id'] . '.',
                    ];
                    continue;
                }

                $created[] = $this->createRecepcion($record, $rowEntry['row_number'], $userId);
            } catch (Throwable $e) {
                $errors[] = [
                    'row_number' => $rowEntry['row_number'],
                    'detail' => $e->getMessage(),
                ];
            }
        }

        return [
            'sheet_name' => self::SHEET_NAME,
            'totals' => [
                'processed' => $processedRows,
                'created' => count($created),
                'duplicates' => count($duplicates),
                'errors' => count($errors),
                'blank_rows' => $blankRows,
            ],
            'created' => $created,
            'duplicates' => $duplicates,
            'errors' => $errors,
        ];
    }

    private function loadCatalogs(): void
    {
        $providerColumns = Helpers::getTableColumns('proveedores');
        $providerFilter = in_array('es_categoria', $providerColumns, true)
            ? ' AND (es_categoria = 0 OR es_categoria IS NULL)'
            : '';
        $proveedores = $this->db->fetchAll("
            SELECT id, codigo, nombre
            FROM proveedores
            WHERE activo = 1{$providerFilter}
            ORDER BY nombre
        ");
        foreach ($proveedores as $proveedor) {
            $item = [
                'id' => (int)($proveedor['id'] ?? 0),
                'codigo' => trim((string)($proveedor['codigo'] ?? '')),
                'nombre' => trim((string)($proveedor['nombre'] ?? '')),
            ];
            if ($item['id'] <= 0) {
                continue;
            }
            if ($item['codigo'] !== '') {
                $this->proveedoresByCode[$this->normalizeKey($item['codigo'])] = $item;
            }
            if ($item['nombre'] !== '') {
                $this->proveedoresByName[$this->normalizeKey($item['nombre'])] = $item;
            }
            if ($item['codigo'] !== '' && $item['nombre'] !== '') {
                $this->proveedoresByLabel[$this->normalizeKey($item['codigo'] . ' - ' . $item['nombre'])] = $item;
            }
        }

        foreach ($this->db->fetchAll("SELECT id, codigo, nombre FROM variedades WHERE activo = 1 ORDER BY nombre") as $variedad) {
            $item = [
                'id' => (int)($variedad['id'] ?? 0),
                'codigo' => trim((string)($variedad['codigo'] ?? '')),
                'nombre' => trim((string)($variedad['nombre'] ?? '')),
            ];
            if ($item['id'] <= 0) {
                continue;
            }
            if ($item['codigo'] !== '') {
                $this->variedadesByCode[$this->normalizeKey($item['codigo'])] = $item;
            }
            if ($item['nombre'] !== '') {
                $this->variedadesByName[$this->normalizeKey($item['nombre'])] = $item;
            }
        }

        foreach ($this->db->fetchAll("SELECT id, codigo, nombre FROM estados_producto WHERE activo = 1 ORDER BY id") as $estado) {
            $item = [
                'id' => (int)($estado['id'] ?? 0),
                'codigo' => trim((string)($estado['codigo'] ?? '')),
                'nombre' => trim((string)($estado['nombre'] ?? '')),
            ];
            if ($item['id'] <= 0) {
                continue;
            }
            if ($item['codigo'] !== '') {
                $this->estadosProductoByCode[$this->normalizeKey($item['codigo'])] = $item;
            }
            if ($item['nombre'] !== '') {
                $this->estadosProductoByName[$this->normalizeKey($item['nombre'])] = $item;
            }
        }
    }

    private function prepareRows(array $sheetRows): array
    {
        $headerRowIndex = null;
        $headerValues = [];
        foreach ($sheetRows as $index => $rowValues) {
            if (!$this->isEmptyRow($rowValues)) {
                $headerRowIndex = $index;
                $headerValues = $rowValues;
                break;
            }
        }
        if ($headerRowIndex === null) {
            throw new RuntimeException('La hoja "' . self::SHEET_NAME . '" no contiene encabezados.');
        }

        $columnMap = [];
        foreach ($headerValues as $index => $headerValue) {
            $canonical = $this->canonicalHeader($headerValue);
            if ($canonical !== '' && !isset($columnMap[$canonical])) {
                $columnMap[$canonical] = $index;
            }
        }

        $required = [
            'proveedor',
            'variedad',
            'tipo_entrega',
            'fecha_entrada',
            'revision_limpieza',
            'revision_olor_normal',
            'revision_ausencia_moho',
            'peso_bruto',
            'unidad_peso',
            'calificacion_humedad',
            'calidad_registro',
            'presencia_defectos',
            'clasificacion_compra',
        ];
        $missingHeaders = array_values(array_filter($required, static fn(string $header): bool => !isset($columnMap[$header])));
        if (!empty($missingHeaders)) {
            throw new RuntimeException('Faltan columnas requeridas en la hoja "' . self::SHEET_NAME . '": ' . implode(', ', $missingHeaders) . '.');
        }

        $dataRows = [];
        for ($i = $headerRowIndex + 1, $total = count($sheetRows); $i < $total; $i++) {
            $dataRows[] = [
                'row_number' => $i + 1,
                'values' => $sheetRows[$i],
            ];
        }
        if (empty($dataRows)) {
            throw new RuntimeException('La hoja "' . self::SHEET_NAME . '" no contiene filas para importar.');
        }

        return [
            'column_map' => $columnMap,
            'rows' => $dataRows,
        ];
    }

    private function canonicalHeader($headerValue): string
    {
        $normalized = strtolower($this->normalizeKey((string)$headerValue));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        $normalized = trim((string)$normalized, '_');

        return match ($normalized) {
            'lote', 'lote_codigo', 'codigo_lote' => 'lote_codigo',
            'proveedor', 'proveedor_codigo', 'codigo_proveedor', 'proveedor_nombre' => 'proveedor',
            'variedad', 'variedad_codigo', 'codigo_variedad', 'variedad_nombre' => 'variedad',
            'tipo_entrega', 'entrega' => 'tipo_entrega',
            'ruta_entrega', 'ruta' => 'ruta_entrega',
            'fecha_entrada', 'fecha', 'fecha_recepcion' => 'fecha_entrada',
            'revision_limpieza', 'limpieza' => 'revision_limpieza',
            'revision_olor_normal', 'olor_normal' => 'revision_olor_normal',
            'revision_ausencia_moho', 'ausencia_moho', 'moho' => 'revision_ausencia_moho',
            'peso_bruto', 'bruto' => 'peso_bruto',
            'tara_envase', 'tara' => 'tara_envase',
            'peso_final', 'peso_final_registro', 'peso_neto' => 'peso_final',
            'unidad_peso', 'unidad' => 'unidad_peso',
            'calificacion_humedad', 'humedad_aparente', 'humedad' => 'calificacion_humedad',
            'calidad_registro', 'calidad' => 'calidad_registro',
            'presencia_defectos', 'defectos' => 'presencia_defectos',
            'clasificacion_compra', 'clasificacion' => 'clasificacion_compra',
            'precio_sugerido', 'precio_base_dia', 'precio' => 'precio_sugerido',
            'observaciones', 'observacion', 'nota', 'notas' => 'observaciones',
            default => '',
        };
    }

    private function normalizeRow(array $rowValues, array $columnMap): array
    {
        $proveedor = $this->resolveCatalogValue($this->getCellValue($rowValues, $columnMap, 'proveedor'), $this->proveedoresByCode, $this->proveedoresByName, $this->proveedoresByLabel, 'proveedor');
        $variedad = $this->resolveCatalogValue($this->getCellValue($rowValues, $columnMap, 'variedad'), $this->variedadesByCode, $this->variedadesByName, [], 'variedad');
        $fechaEntrada = $this->parseDateValue($this->getCellValue($rowValues, $columnMap, 'fecha_entrada'));
        if ($fechaEntrada === null) {
            throw new RuntimeException('La fecha de entrada debe estar en formato valido.');
        }

        $tipoEntrega = strtoupper(str_replace(' ', '_', $this->normalizeKey($this->getCellValue($rowValues, $columnMap, 'tipo_entrega'))));
        if ($tipoEntrega === 'INDIVIDUAL') {
            $tipoEntrega = 'ENTREGA_INDIVIDUAL';
        }
        if (!in_array($tipoEntrega, ['RUTAS', 'COMERCIANTE', 'ENTREGA_INDIVIDUAL'], true)) {
            throw new RuntimeException('El tipo de entrega debe ser RUTAS, COMERCIANTE o ENTREGA_INDIVIDUAL.');
        }

        $revisionLimpieza = $this->normalizeCumple($this->getCellValue($rowValues, $columnMap, 'revision_limpieza'), 'revision_limpieza');
        $revisionOlor = $this->normalizeCumple($this->getCellValue($rowValues, $columnMap, 'revision_olor_normal'), 'revision_olor_normal');
        $revisionMoho = $this->normalizeCumple($this->getCellValue($rowValues, $columnMap, 'revision_ausencia_moho'), 'revision_ausencia_moho');

        $pesoBruto = $this->parseDecimalValue($this->getCellValue($rowValues, $columnMap, 'peso_bruto'));
        if ($pesoBruto === null || $pesoBruto <= 0) {
            throw new RuntimeException('El peso bruto debe ser mayor a 0.');
        }
        $taraEnvase = $this->parseDecimalValue($this->getCellValue($rowValues, $columnMap, 'tara_envase')) ?? 0.0;
        if ($taraEnvase < 0) {
            throw new RuntimeException('La tara de envase no puede ser negativa.');
        }
        $pesoFinal = $this->parseDecimalValue($this->getCellValue($rowValues, $columnMap, 'peso_final'));
        if ($pesoFinal === null) {
            $pesoFinal = $pesoBruto - $taraEnvase;
        }
        if ($pesoFinal <= 0) {
            throw new RuntimeException('El peso final debe ser mayor a 0.');
        }

        $unidadPeso = strtoupper($this->normalizeKey($this->getCellValue($rowValues, $columnMap, 'unidad_peso')));
        if (!in_array($unidadPeso, ['LB', 'KG', 'QQ'], true)) {
            throw new RuntimeException('La unidad de peso debe ser LB, KG o QQ.');
        }

        $calificacionHumedad = $this->parseIntegerValue($this->getCellValue($rowValues, $columnMap, 'calificacion_humedad'));
        $humedadPermitida = array_merge(range(0, 4), range(5, 70, 5));
        if ($calificacionHumedad === null || !in_array($calificacionHumedad, $humedadPermitida, true)) {
            throw new RuntimeException('La calificacion de humedad debe ser 0-4 o rangos de 5 hasta 70.');
        }

        $calidadRegistro = strtoupper($this->normalizeKey($this->getCellValue($rowValues, $columnMap, 'calidad_registro')));
        if (!in_array($calidadRegistro, ['SECO', 'SEMISECO', 'ESCURRIDO', 'BABA'], true)) {
            throw new RuntimeException('La calidad debe ser SECO, SEMISECO, ESCURRIDO o BABA.');
        }

        $presenciaDefectos = $this->parseDecimalValue($this->getCellValue($rowValues, $columnMap, 'presencia_defectos'));
        if ($presenciaDefectos === null || $presenciaDefectos < 0 || $presenciaDefectos > 10) {
            throw new RuntimeException('La presencia de defectos debe estar entre 0 y 10.');
        }

        $clasificacion = strtoupper($this->normalizeKey($this->getCellValue($rowValues, $columnMap, 'clasificacion_compra')));
        if (!in_array($clasificacion, ['APTO', 'APTO_DESCUENTO', 'NO_APTO', 'APTO_BONIFICACION'], true)) {
            throw new RuntimeException('La clasificacion debe ser APTO, APTO_DESCUENTO, NO_APTO o APTO_BONIFICACION.');
        }

        $precioSugerido = $this->parseDecimalValue($this->getCellValue($rowValues, $columnMap, 'precio_sugerido'));
        if ($precioSugerido !== null && $precioSugerido < 0) {
            throw new RuntimeException('El precio sugerido no puede ser negativo.');
        }

        return [
            'lote_codigo' => strtoupper(trim($this->getCellValue($rowValues, $columnMap, 'lote_codigo'))),
            'proveedor_id' => $proveedor['id'],
            'proveedor_codigo' => $proveedor['codigo'],
            'proveedor_nombre' => $proveedor['nombre'],
            'variedad_id' => $variedad['id'],
            'variedad_nombre' => $variedad['nombre'],
            'tipo_entrega' => $tipoEntrega,
            'ruta_entrega' => $this->getCellValue($rowValues, $columnMap, 'ruta_entrega') ?: 'NO APLICA',
            'fecha_entrada' => $fechaEntrada,
            'revision_limpieza' => $revisionLimpieza,
            'revision_olor_normal' => $revisionOlor,
            'revision_ausencia_moho' => $revisionMoho,
            'peso_bruto' => round($pesoBruto, 2),
            'tara_envase' => round($taraEnvase, 2),
            'peso_final_registro' => round($pesoFinal, 2),
            'unidad_peso' => $unidadPeso,
            'peso_final_kg' => round(Helpers::pesoToKg($pesoFinal, $unidadPeso), 4),
            'calificacion_humedad' => $calificacionHumedad,
            'calidad_registro' => $calidadRegistro,
            'presencia_defectos' => round($presenciaDefectos, 2),
            'clasificacion_compra' => $clasificacion,
            'precio_base_dia' => $precioSugerido,
            'precio_unitario_final' => $precioSugerido,
            'precio_total_pagar' => $precioSugerido !== null ? round($precioSugerido * Helpers::pesoToKg($pesoFinal, $unidadPeso), 2) : null,
            'observaciones' => preg_replace('/\s+/', ' ', trim($this->getCellValue($rowValues, $columnMap, 'observaciones'))),
        ];
    }

    private function createRecepcion(array $record, int $rowNumber, ?int $userId): array
    {
        $resolvedUserId = $userId ?? (int)($_SESSION['user_id'] ?? 0);
        if ($resolvedUserId <= 0) {
            throw new RuntimeException('No se pudo determinar el usuario responsable.');
        }

        $this->db->beginTransaction();
        try {
            $lote = $this->resolveOrCreateLote($record, $resolvedUserId);
            $proveedorRuta = 'PROVEEDORES: ' . $record['proveedor_nombre'] . ' | RUTA: ' . ($record['ruta_entrega'] !== '' ? $record['ruta_entrega'] : 'NO APLICA');
            $fichaData = [
                'lote_id' => $lote['id'],
                'producto' => null,
                'codificacion' => null,
                'proveedor_ruta' => $proveedorRuta,
                'tipo_entrega' => $record['tipo_entrega'],
                'fecha_entrada' => $record['fecha_entrada'],
                'revision_limpieza' => $record['revision_limpieza'],
                'revision_olor_normal' => $record['revision_olor_normal'],
                'revision_ausencia_moho' => $record['revision_ausencia_moho'],
                'peso_bruto' => $record['peso_bruto'],
                'tara_envase' => $record['tara_envase'],
                'peso_final_registro' => $record['peso_final_registro'],
                'unidad_peso' => $record['unidad_peso'],
                'calificacion_humedad' => $record['calificacion_humedad'],
                'calidad_registro' => $record['calidad_registro'],
                'presencia_defectos' => $record['presencia_defectos'],
                'clasificacion_compra' => $record['clasificacion_compra'],
                'precio_base_dia' => $record['precio_base_dia'],
                'calidad_asignada' => null,
                'diferencial_usd' => null,
                'precio_unitario_final' => $record['precio_unitario_final'],
                'precio_total_pagar' => $record['precio_total_pagar'],
                'fermentacion_estado' => null,
                'secado_inicio' => null,
                'secado_fin' => null,
                'temperatura' => null,
                'tiempo_horas' => null,
                'responsable_id' => $resolvedUserId,
                'observaciones' => $record['observaciones'] !== '' ? $record['observaciones'] : null,
            ];
            $fichaData = array_intersect_key($fichaData, array_flip($this->fichaColumns));
            $fichaId = (int)$this->db->insert('fichas_registro', $fichaData);
            Helpers::syncFichaProveedorPesos($fichaId, [[
                'proveedor_id' => $record['proveedor_id'],
                'proveedor_nombre' => $record['proveedor_nombre'],
                'peso' => $record['peso_final_registro'],
                'unidad_peso' => $record['unidad_peso'],
                'peso_kg' => $record['peso_final_kg'],
            ]]);
            Helpers::registrarHistorial($lote['id'], 'ficha_creada', "Ficha de recepcion #{$fichaId} creada por carga masiva");
            $this->db->commit();

            return [
                'row_number' => $rowNumber,
                'ficha_id' => $fichaId,
                'lote_codigo' => $lote['codigo'],
                'proveedor' => trim($record['proveedor_codigo'] . ' - ' . $record['proveedor_nombre']),
                'fecha_entrada' => $record['fecha_entrada'],
                'peso_final_registro' => $record['peso_final_registro'],
                'unidad_peso' => $record['unidad_peso'],
            ];
        } catch (Throwable $e) {
            if ($this->db->getConnection()->inTransaction()) {
                $this->db->rollback();
            }
            throw new RuntimeException('No se pudo guardar la fila ' . $rowNumber . ': ' . $e->getMessage());
        }
    }

    private function resolveOrCreateLote(array $record, int $userId): array
    {
        $codigoSolicitado = trim((string)$record['lote_codigo']);
        if ($codigoSolicitado !== '') {
            $lote = $this->db->fetch("SELECT id, codigo FROM lotes WHERE codigo = ? LIMIT 1", [$codigoSolicitado]);
            if ($lote) {
                return ['id' => (int)$lote['id'], 'codigo' => (string)$lote['codigo']];
            }
        }

        $estadoProducto = $this->resolveEstadoProductoForCalidad($record['calidad_registro']);
        $codigoBase = $codigoSolicitado !== ''
            ? $codigoSolicitado
            : Helpers::generateLoteCode($record['proveedor_id'], $record['fecha_entrada'], $estadoProducto['id'], null);
        $codigo = $codigoBase;
        $secuencia = 2;
        while ($this->db->fetch("SELECT id FROM lotes WHERE codigo = ? LIMIT 1", [$codigo])) {
            $codigo = $codigoBase . '-' . $secuencia;
            $secuencia++;
        }

        $pesoKg = $record['peso_final_kg'];
        $insertData = [
            'codigo' => $codigo,
            'proveedor_id' => $record['proveedor_id'],
            'variedad_id' => $record['variedad_id'],
            'estado_producto_id' => $estadoProducto['id'],
            'fecha_entrada' => $record['fecha_entrada'],
            'peso_inicial_kg' => $pesoKg,
            'peso_qq' => Helpers::kgToQQ($pesoKg),
            'humedad_inicial' => $record['calificacion_humedad'],
            'observaciones' => $record['observaciones'] !== '' ? $record['observaciones'] : 'Lote generado por carga masiva de recepcion.',
            'estado_proceso' => 'RECEPCION',
            'usuario_id' => $userId,
        ];
        if ($this->hasLoteColumn('estado_fermentacion_id')) {
            $insertData['estado_fermentacion_id'] = null;
        }
        if ($this->hasLoteColumn('peso_inicial_qq')) {
            $insertData['peso_inicial_qq'] = Helpers::kgToQQ($pesoKg);
        }
        if ($this->hasLoteColumn('peso_actual_kg')) {
            $insertData['peso_actual_kg'] = $pesoKg;
        }
        if ($this->hasLoteColumn('peso_actual_qq')) {
            $insertData['peso_actual_qq'] = Helpers::kgToQQ($pesoKg);
        }
        if ($this->hasLoteColumn('peso_recibido_kg')) {
            $insertData['peso_recibido_kg'] = $pesoKg;
        }
        if ($this->hasLoteColumn('precio_kg')) {
            $insertData['precio_kg'] = $record['precio_base_dia'];
        }
        if ($this->hasLoteColumn('fecha_recepcion')) {
            $insertData['fecha_recepcion'] = $record['fecha_entrada'];
        }
        $insertData = array_intersect_key($insertData, array_flip($this->loteColumns));

        $loteId = (int)$this->db->insert('lotes', $insertData);
        Helpers::registrarHistorial($loteId, 'RECEPCION', 'Lote creado por carga masiva de recepcion');
        return ['id' => $loteId, 'codigo' => $codigo];
    }

    private function resolveEstadoProductoForCalidad(string $calidad): array
    {
        $codigos = match ($calidad) {
            'ESCURRIDO' => ['ES'],
            'SEMISECO' => ['SM', 'SS'],
            'BABA' => ['BA'],
            default => ['SC'],
        };
        foreach ($codigos as $codigo) {
            $key = $this->normalizeKey($codigo);
            if (isset($this->estadosProductoByCode[$key])) {
                return $this->estadosProductoByCode[$key];
            }
        }
        $first = reset($this->estadosProductoByCode);
        if (is_array($first)) {
            return $first;
        }
        throw new RuntimeException('No existen estados de producto activos para crear el lote.');
    }

    private function findExistingDuplicate(array $record): ?array
    {
        $lote = null;
        if ($record['lote_codigo'] !== '') {
            $lote = $this->db->fetch("SELECT id FROM lotes WHERE codigo = ? LIMIT 1", [$record['lote_codigo']]);
        }
        $sql = "
            SELECT f.id
            FROM fichas_registro f
            WHERE f.fecha_entrada = ?
              AND f.tipo_entrega = ?
              AND ABS(f.peso_bruto - ?) < 0.0001
              AND ABS(f.peso_final_registro - ?) < 0.0001
              AND f.unidad_peso = ?
              AND TRIM(COALESCE(f.proveedor_ruta, '')) LIKE ?
        ";
        $params = [
            $record['fecha_entrada'],
            $record['tipo_entrega'],
            $record['peso_bruto'],
            $record['peso_final_registro'],
            $record['unidad_peso'],
            '%' . $record['proveedor_nombre'] . '%',
        ];
        if ($lote) {
            $sql .= ' AND f.lote_id = ?';
            $params[] = (int)$lote['id'];
        }
        $sql .= ' LIMIT 1';

        $existing = $this->db->fetch($sql, $params);
        return is_array($existing) && !empty($existing) ? $existing : null;
    }

    private function buildFingerprint(array $record): string
    {
        return implode('|', [
            $record['lote_codigo'],
            (string)$record['proveedor_id'],
            (string)$record['variedad_id'],
            $record['tipo_entrega'],
            $record['fecha_entrada'],
            number_format((float)$record['peso_bruto'], 2, '.', ''),
            number_format((float)$record['tara_envase'], 2, '.', ''),
            number_format((float)$record['peso_final_registro'], 2, '.', ''),
            $record['unidad_peso'],
            (string)$record['calificacion_humedad'],
            $record['calidad_registro'],
            (string)$record['clasificacion_compra'],
            $this->normalizeKey($record['observaciones']),
        ]);
    }

    private function resolveCatalogValue(string $reference, array $catalogByCode, array $catalogByName, array $catalogByLabel, string $label): array
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new RuntimeException('El campo "' . $label . '" es obligatorio.');
        }
        $key = $this->normalizeKey($reference);
        if (isset($catalogByCode[$key])) {
            return $catalogByCode[$key];
        }
        if (isset($catalogByName[$key])) {
            return $catalogByName[$key];
        }
        if (isset($catalogByLabel[$key])) {
            return $catalogByLabel[$key];
        }
        throw new RuntimeException('No se encontro el ' . $label . ': ' . $reference . '.');
    }

    private function getCellValue(array $rowValues, array $columnMap, string $field): string
    {
        $index = $columnMap[$field] ?? null;
        return $index === null ? '' : trim((string)($rowValues[$index] ?? ''));
    }

    private function normalizeCumple(string $value, string $field): string
    {
        $normalized = $this->normalizeKey($value);
        if (in_array($normalized, ['CUMPLE', 'SI', 'S'], true)) {
            return 'CUMPLE';
        }
        if (in_array($normalized, ['NO_CUMPLE', 'NO CUMPLE', 'NO', 'N'], true)) {
            return 'NO_CUMPLE';
        }
        throw new RuntimeException('El campo ' . $field . ' debe ser CUMPLE o NO_CUMPLE.');
    }

    private function catalogRows(array $catalog): array
    {
        $rows = array_values($catalog);
        usort($rows, static fn(array $left, array $right): int => strnatcasecmp(($left['codigo'] ?? '') . '|' . ($left['nombre'] ?? ''), ($right['codigo'] ?? '') . '|' . ($right['nombre'] ?? '')));
        return $rows;
    }

    private function hasLoteColumn(string $column): bool
    {
        return in_array($column, $this->loteColumns, true);
    }

    private function parseDateValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $timestamp = (int)round(((float)$value - 25569) * 86400);
            if ($timestamp > 0) {
                return gmdate('Y-m-d', $timestamp);
            }
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y', 'm/d/Y'] as $format) {
            $date = DateTime::createFromFormat('!' . $format, $value);
            if ($date instanceof DateTime && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private function parseDecimalValue(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = preg_replace('/[^0-9,.\-]/', '', $value);
        if ($value === '' || $value === '-' || $value === '.' || $value === ',') {
            return null;
        }
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(',', '.', str_replace('.', '', $value))
                : str_replace(',', '', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }
        return is_numeric($value) ? (float)$value : null;
    }

    private function parseIntegerValue(string $value): ?int
    {
        $decimal = $this->parseDecimalValue($value);
        return $decimal === null ? null : (int)round($decimal);
    }

    private function normalizeKey(string $value): string
    {
        $value = trim($value);
        $value = strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ü' => 'U', 'ñ' => 'N',
        ]);
        $value = preg_replace('/\s+/', ' ', $value);
        return strtoupper(trim((string)$value));
    }

    private function isEmptyRow(array $rowValues): bool
    {
        foreach ($rowValues as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
    }
}
