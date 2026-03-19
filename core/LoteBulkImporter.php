<?php
/**
 * Servicio para plantilla e importación masiva de lotes desde XLSX.
 */

class LoteBulkImporter
{
    private const SHEET_NAME = 'Lotes';

    private Database $db;
    private array $loteColumns = [];
    private array $proveedoresByCode = [];
    private array $proveedoresByName = [];
    private array $proveedoresByLabel = [];
    private array $variedadesByCode = [];
    private array $variedadesByName = [];
    private array $estadosProductoByCode = [];
    private array $estadosProductoByName = [];
    private array $estadosFermentacionByCode = [];
    private array $estadosFermentacionByName = [];
    private array $templateHeaders = [
        'proveedor_codigo',
        'variedad_codigo',
        'estado_producto_codigo',
        'estado_fermentacion_codigo',
        'fecha_entrada',
        'peso_inicial_kg',
        'humedad_inicial',
        'precio_kg',
        'observaciones',
    ];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->loteColumns = array_column($this->db->fetchAll("SHOW COLUMNS FROM lotes"), 'Field');

        $this->loadCatalogs();
    }

    public function getTemplateFileName(): string
    {
        return 'plantilla_carga_masiva_lotes.xlsx';
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

        $estadosProductoRows = [['codigo', 'nombre']];
        foreach ($this->catalogRows($this->estadosProductoByCode) as $item) {
            $estadosProductoRows[] = [$item['codigo'], $item['nombre']];
        }

        $estadosFermentacionRows = [['codigo', 'nombre']];
        $estadosFermentacionRows[] = ['', 'Sin fermentacion previa'];
        foreach ($this->catalogRows($this->estadosFermentacionByCode) as $item) {
            $estadosFermentacionRows[] = [$item['codigo'], $item['nombre']];
        }

        return [
            [
                'name' => self::SHEET_NAME,
                'rows' => [
                    $this->templateHeaders,
                ],
            ],
            [
                'name' => 'Instrucciones',
                'rows' => [
                    ['campo', 'obligatorio', 'descripcion', 'ejemplo'],
                    ['proveedor_codigo', 'SI', 'Codigo exacto del proveedor activo.', 'PR001'],
                    ['variedad_codigo', 'SI', 'Codigo exacto de la variedad activa.', 'CCN51'],
                    ['estado_producto_codigo', 'SI', 'Codigo del estado del producto.', 'SC'],
                    ['estado_fermentacion_codigo', 'NO', 'Codigo del estado de fermentacion. Puede quedar vacio.', 'F1'],
                    ['fecha_entrada', 'SI', 'Fecha en formato YYYY-MM-DD.', '2026-03-18'],
                    ['peso_inicial_kg', 'SI', 'Peso inicial en kilogramos. Acepta coma o punto decimal.', '1450.50'],
                    ['humedad_inicial', 'NO', 'Porcentaje entre 0 y 100.', '7.50'],
                    ['precio_kg', 'NO', 'Precio por kilogramo.', '3.20'],
                    ['observaciones', 'NO', 'Texto libre.', 'Recepcion de ruta norte'],
                    ['nota', 'NO', 'La hoja "' . self::SHEET_NAME . '" es la unica que se importa.', ''],
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
            [
                'name' => 'Catalogo_Estados_Producto',
                'rows' => $estadosProductoRows,
            ],
            [
                'name' => 'Catalogo_Estados_Fermentacion',
                'rows' => $estadosFermentacionRows,
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
                        'detail' => 'Ya existe un lote con los mismos datos en el sistema: ' . $existing['codigo'] . '.',
                    ];
                    continue;
                }

                $created[] = $this->createLote($record, $rowEntry['row_number'], $userId);
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
        $providerColumns = array_column($this->db->fetchAll("SHOW COLUMNS FROM proveedores"), 'Field');
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
                'id' => (int) ($proveedor['id'] ?? 0),
                'codigo' => trim((string) ($proveedor['codigo'] ?? '')),
                'nombre' => trim((string) ($proveedor['nombre'] ?? '')),
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
                $label = $item['codigo'] . ' - ' . $item['nombre'];
                $this->proveedoresByLabel[$this->normalizeKey($label)] = $item;
            }
        }

        $variedades = $this->db->fetchAll("
            SELECT id, codigo, nombre
            FROM variedades
            WHERE activo = 1
            ORDER BY nombre
        ");

        foreach ($variedades as $variedad) {
            $item = [
                'id' => (int) ($variedad['id'] ?? 0),
                'codigo' => trim((string) ($variedad['codigo'] ?? '')),
                'nombre' => trim((string) ($variedad['nombre'] ?? '')),
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

        $estadosProducto = $this->db->fetchAll("
            SELECT id, codigo, nombre
            FROM estados_producto
            WHERE activo = 1
            ORDER BY id
        ");

        foreach ($estadosProducto as $estado) {
            $item = [
                'id' => (int) ($estado['id'] ?? 0),
                'codigo' => trim((string) ($estado['codigo'] ?? '')),
                'nombre' => trim((string) ($estado['nombre'] ?? '')),
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

        $estadosFermentacion = $this->db->fetchAll("
            SELECT id, codigo, nombre
            FROM estados_fermentacion
            WHERE activo = 1
            ORDER BY id
        ");

        foreach ($estadosFermentacion as $estado) {
            $item = [
                'id' => (int) ($estado['id'] ?? 0),
                'codigo' => trim((string) ($estado['codigo'] ?? '')),
                'nombre' => trim((string) ($estado['nombre'] ?? '')),
            ];
            if ($item['id'] <= 0) {
                continue;
            }

            if ($item['codigo'] !== '') {
                $this->estadosFermentacionByCode[$this->normalizeKey($item['codigo'])] = $item;
            }
            if ($item['nombre'] !== '') {
                $this->estadosFermentacionByName[$this->normalizeKey($item['nombre'])] = $item;
            }
        }
    }

    private function catalogRows(array $catalog): array
    {
        $rows = array_values($catalog);
        usort($rows, static function (array $left, array $right): int {
            return strnatcasecmp(
                trim((string) ($left['codigo'] ?? '')) . '|' . trim((string) ($left['nombre'] ?? '')),
                trim((string) ($right['codigo'] ?? '')) . '|' . trim((string) ($right['nombre'] ?? ''))
            );
        });

        return $rows;
    }

    private function prepareRows(array $sheetRows): array
    {
        $headerRowIndex = null;
        $headerValues = [];

        foreach ($sheetRows as $index => $rowValues) {
            if ($this->isEmptyRow($rowValues)) {
                continue;
            }

            $headerRowIndex = $index;
            $headerValues = $rowValues;
            break;
        }

        if ($headerRowIndex === null) {
            throw new RuntimeException('La hoja "' . self::SHEET_NAME . '" no contiene encabezados.');
        }

        $columnMap = [];
        foreach ($headerValues as $index => $headerValue) {
            $canonical = $this->canonicalHeader($headerValue);
            if ($canonical === '') {
                continue;
            }

            if (!isset($columnMap[$canonical])) {
                $columnMap[$canonical] = $index;
            }
        }

        $missingHeaders = array_values(array_filter(
            ['proveedor', 'variedad', 'estado_producto', 'fecha_entrada', 'peso_inicial_kg'],
            static fn(string $header): bool => !isset($columnMap[$header])
        ));

        if (!empty($missingHeaders)) {
            throw new RuntimeException(
                'Faltan columnas requeridas en la hoja "' . self::SHEET_NAME . '": ' . implode(', ', $missingHeaders) . '.'
            );
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
            'header_row_number' => $headerRowIndex + 1,
            'column_map' => $columnMap,
            'rows' => $dataRows,
        ];
    }

    private function canonicalHeader($headerValue): string
    {
        $normalized = strtolower($this->normalizeKey((string) $headerValue));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        $normalized = trim((string) $normalized, '_');

        return match ($normalized) {
            'proveedor', 'proveedor_codigo', 'codigo_proveedor', 'proveedor_ref', 'proveedor_referencia', 'proveedor_nombre' => 'proveedor',
            'variedad', 'variedad_codigo', 'codigo_variedad', 'variedad_nombre' => 'variedad',
            'estado_producto', 'estado_producto_codigo', 'codigo_estado_producto' => 'estado_producto',
            'estado_fermentacion', 'estado_fermentacion_codigo', 'codigo_estado_fermentacion' => 'estado_fermentacion',
            'fecha_entrada', 'fecha' => 'fecha_entrada',
            'peso_inicial_kg', 'peso_kg', 'peso', 'peso_inicial' => 'peso_inicial_kg',
            'humedad_inicial', 'humedad' => 'humedad_inicial',
            'precio_kg', 'precio' => 'precio_kg',
            'observaciones', 'observacion', 'nota', 'notas' => 'observaciones',
            default => '',
        };
    }

    private function normalizeRow(array $rowValues, array $columnMap): array
    {
        $proveedorRef = $this->getCellValue($rowValues, $columnMap, 'proveedor');
        $variedadRef = $this->getCellValue($rowValues, $columnMap, 'variedad');
        $estadoProductoRef = $this->getCellValue($rowValues, $columnMap, 'estado_producto');
        $estadoFermentacionRef = $this->getCellValue($rowValues, $columnMap, 'estado_fermentacion');
        $fechaEntradaRaw = $this->getCellValue($rowValues, $columnMap, 'fecha_entrada');
        $pesoInicialRaw = $this->getCellValue($rowValues, $columnMap, 'peso_inicial_kg');
        $humedadRaw = $this->getCellValue($rowValues, $columnMap, 'humedad_inicial');
        $precioRaw = $this->getCellValue($rowValues, $columnMap, 'precio_kg');
        $observaciones = trim($this->getCellValue($rowValues, $columnMap, 'observaciones'));
        $observaciones = preg_replace('/\s+/', ' ', $observaciones);

        $proveedor = $this->resolveCatalogValue($proveedorRef, $this->proveedoresByCode, $this->proveedoresByName, $this->proveedoresByLabel, 'proveedor');
        $variedad = $this->resolveCatalogValue($variedadRef, $this->variedadesByCode, $this->variedadesByName, [], 'variedad');
        $estadoProducto = $this->resolveCatalogValue($estadoProductoRef, $this->estadosProductoByCode, $this->estadosProductoByName, [], 'estado del producto');
        $estadoFermentacion = null;
        if ($estadoFermentacionRef !== '') {
            $estadoFermentacion = $this->resolveCatalogValue(
                $estadoFermentacionRef,
                $this->estadosFermentacionByCode,
                $this->estadosFermentacionByName,
                [],
                'estado de fermentacion'
            );
        }

        $fechaEntrada = $this->parseDateValue($fechaEntradaRaw);
        if ($fechaEntrada === null) {
            throw new RuntimeException('La fecha de entrada debe estar en formato valido (preferible YYYY-MM-DD).');
        }

        $pesoInicialKg = $this->parseDecimalValue($pesoInicialRaw);
        if ($pesoInicialKg === null || $pesoInicialKg <= 0) {
            throw new RuntimeException('El peso inicial debe ser un numero mayor a 0.');
        }
        $pesoInicialKg = round($pesoInicialKg, 2);

        $humedadInicial = null;
        if ($humedadRaw !== '') {
            $humedadInicial = $this->parseDecimalValue($humedadRaw);
            if ($humedadInicial === null || $humedadInicial < 0 || $humedadInicial > 100) {
                throw new RuntimeException('La humedad inicial debe estar entre 0 y 100.');
            }
            $humedadInicial = round($humedadInicial, 2);
        }

        $precioKg = null;
        if ($precioRaw !== '') {
            $precioKg = $this->parseDecimalValue($precioRaw);
            if ($precioKg === null || $precioKg < 0) {
                throw new RuntimeException('El precio por kg debe ser un numero valido mayor o igual a 0.');
            }
            $precioKg = round($precioKg, 2);
        }

        return [
            'proveedor_id' => $proveedor['id'],
            'proveedor_codigo' => $proveedor['codigo'],
            'proveedor_nombre' => $proveedor['nombre'],
            'variedad_id' => $variedad['id'],
            'variedad_codigo' => $variedad['codigo'],
            'variedad_nombre' => $variedad['nombre'],
            'estado_producto_id' => $estadoProducto['id'],
            'estado_producto_codigo' => $estadoProducto['codigo'],
            'estado_producto_nombre' => $estadoProducto['nombre'],
            'estado_fermentacion_id' => $estadoFermentacion['id'] ?? null,
            'estado_fermentacion_codigo' => $estadoFermentacion['codigo'] ?? '',
            'estado_fermentacion_nombre' => $estadoFermentacion['nombre'] ?? '',
            'fecha_entrada' => $fechaEntrada,
            'peso_inicial_kg' => $pesoInicialKg,
            'peso_inicial_qq' => round(Helpers::kgToQQ($pesoInicialKg), 2),
            'humedad_inicial' => $humedadInicial,
            'precio_kg' => $precioKg,
            'observaciones' => $observaciones,
        ];
    }

    private function resolveCatalogValue(
        string $reference,
        array $catalogByCode,
        array $catalogByName,
        array $catalogByLabel,
        string $label
    ): array {
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
        if ($index === null) {
            return '';
        }

        return trim((string) ($rowValues[$index] ?? ''));
    }

    private function buildFingerprint(array $record): string
    {
        return implode('|', [
            (string) $record['proveedor_id'],
            (string) $record['variedad_id'],
            (string) $record['estado_producto_id'],
            (string) ($record['estado_fermentacion_id'] ?? 'NULL'),
            (string) $record['fecha_entrada'],
            number_format((float) $record['peso_inicial_kg'], 2, '.', ''),
            $record['humedad_inicial'] === null ? 'NULL' : number_format((float) $record['humedad_inicial'], 2, '.', ''),
            $record['precio_kg'] === null ? 'NULL' : number_format((float) $record['precio_kg'], 2, '.', ''),
            $this->normalizeKey($record['observaciones']),
        ]);
    }

    private function findExistingDuplicate(array $record): ?array
    {
        $sql = "
            SELECT id, codigo
            FROM lotes
            WHERE proveedor_id = ?
              AND variedad_id = ?
              AND estado_producto_id = ?
        ";
        $params = [
            $record['proveedor_id'],
            $record['variedad_id'],
            $record['estado_producto_id'],
        ];

        if ($this->hasLoteColumn('estado_fermentacion_id')) {
            if ($record['estado_fermentacion_id'] === null) {
                $sql .= " AND estado_fermentacion_id IS NULL";
            } else {
                $sql .= " AND estado_fermentacion_id = ?";
                $params[] = $record['estado_fermentacion_id'];
            }
        }

        if ($this->hasLoteColumn('fecha_entrada')) {
            $sql .= " AND fecha_entrada = ?";
            $params[] = $record['fecha_entrada'];
        }

        if ($this->hasLoteColumn('peso_inicial_kg')) {
            $sql .= " AND ABS(peso_inicial_kg - ?) < 0.0001";
            $params[] = $record['peso_inicial_kg'];
        }

        if ($this->hasLoteColumn('humedad_inicial')) {
            if ($record['humedad_inicial'] === null) {
                $sql .= " AND humedad_inicial IS NULL";
            } else {
                $sql .= " AND ABS(humedad_inicial - ?) < 0.0001";
                $params[] = $record['humedad_inicial'];
            }
        }

        if ($this->hasLoteColumn('precio_kg')) {
            if ($record['precio_kg'] === null) {
                $sql .= " AND precio_kg IS NULL";
            } else {
                $sql .= " AND ABS(precio_kg - ?) < 0.0001";
                $params[] = $record['precio_kg'];
            }
        }

        if ($this->hasLoteColumn('observaciones')) {
            $sql .= " AND TRIM(COALESCE(observaciones, '')) = ?";
            $params[] = trim((string) $record['observaciones']);
        }

        $sql .= " LIMIT 1";

        $existing = $this->db->fetch($sql, $params);

        return is_array($existing) && !empty($existing) ? $existing : null;
    }

    private function createLote(array $record, int $rowNumber, ?int $userId): array
    {
        $codigo = Helpers::generateLoteCode(
            $record['proveedor_id'],
            $record['fecha_entrada'],
            $record['estado_producto_id'],
            $record['estado_fermentacion_id']
        );

        $insertData = [
            'codigo' => $codigo,
            'proveedor_id' => $record['proveedor_id'],
            'variedad_id' => $record['variedad_id'],
            'estado_producto_id' => $record['estado_producto_id'],
            'fecha_entrada' => $record['fecha_entrada'],
        ];

        if ($this->hasLoteColumn('estado_fermentacion_id')) {
            $insertData['estado_fermentacion_id'] = $record['estado_fermentacion_id'];
        }

        if ($this->hasLoteColumn('peso_inicial_kg')) {
            $insertData['peso_inicial_kg'] = $record['peso_inicial_kg'];
        }
        if ($this->hasLoteColumn('peso_inicial_qq')) {
            $insertData['peso_inicial_qq'] = $record['peso_inicial_qq'];
        }
        if ($this->hasLoteColumn('peso_qq')) {
            $insertData['peso_qq'] = $record['peso_inicial_qq'];
        }
        if ($this->hasLoteColumn('peso_actual_kg')) {
            $insertData['peso_actual_kg'] = $record['peso_inicial_kg'];
        }
        if ($this->hasLoteColumn('peso_actual_qq')) {
            $insertData['peso_actual_qq'] = $record['peso_inicial_qq'];
        }
        if ($this->hasLoteColumn('humedad_inicial')) {
            $insertData['humedad_inicial'] = $record['humedad_inicial'];
        }
        if ($this->hasLoteColumn('precio_kg')) {
            $insertData['precio_kg'] = $record['precio_kg'];
        }
        if ($this->hasLoteColumn('observaciones')) {
            $insertData['observaciones'] = $record['observaciones'];
        }
        if ($this->hasLoteColumn('estado_proceso')) {
            $insertData['estado_proceso'] = 'RECEPCION';
        }
        if ($this->hasLoteColumn('usuario_id')) {
            $resolvedUserId = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
            if ($resolvedUserId <= 0) {
                throw new RuntimeException('No se pudo determinar el usuario responsable para la carga masiva.');
            }
            $insertData['usuario_id'] = $resolvedUserId;
        }

        try {
            $loteId = (int) $this->db->insert('lotes', $insertData);
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo guardar la fila ' . $rowNumber . ': ' . $e->getMessage());
        }

        Helpers::registrarHistorial(
            $loteId,
            'RECEPCION',
            'Lote creado por carga masiva con codigo: ' . $codigo,
            null,
            [
                'codigo' => $codigo,
                'proveedor_id' => $record['proveedor_id'],
                'variedad_id' => $record['variedad_id'],
                'estado_producto_id' => $record['estado_producto_id'],
                'estado_fermentacion_id' => $record['estado_fermentacion_id'],
                'fecha_entrada' => $record['fecha_entrada'],
                'peso_inicial_kg' => $record['peso_inicial_kg'],
                'humedad_inicial' => $record['humedad_inicial'],
                'precio_kg' => $record['precio_kg'],
                'observaciones' => $record['observaciones'],
                'origen' => 'CARGA_MASIVA_XLSX',
            ]
        );

        return [
            'row_number' => $rowNumber,
            'codigo' => $codigo,
            'proveedor' => trim($record['proveedor_codigo'] . ' - ' . $record['proveedor_nombre']),
            'variedad' => $record['variedad_nombre'],
            'fecha_entrada' => $record['fecha_entrada'],
            'peso_inicial_kg' => $record['peso_inicial_kg'],
        ];
    }

    private function parseDateValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial > 0) {
                $timestamp = (int) round(($serial - 25569) * 86400);
                if ($timestamp > 0) {
                    return gmdate('Y-m-d', $timestamp);
                }
            }
        }

        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y', 'm/d/Y'];
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat('!' . $format, $value);
            if ($date instanceof DateTime && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
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

        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma && $hasDot) {
            $lastComma = strrpos($value, ',');
            $lastDot = strrpos($value, '.');
            if ($lastComma !== false && $lastDot !== false && $lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($hasComma) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeKey(string $value): string
    {
        $value = trim($value);
        $value = strtr($value, [
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
            'Ü' => 'U',
            'Ñ' => 'N',
            'á' => 'A',
            'é' => 'E',
            'í' => 'I',
            'ó' => 'O',
            'ú' => 'U',
            'ü' => 'U',
            'ñ' => 'N',
        ]);
        $value = preg_replace('/\s+/', ' ', $value);

        return strtoupper(trim((string) $value));
    }

    private function isEmptyRow(array $rowValues): bool
    {
        foreach ($rowValues as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function hasLoteColumn(string $column): bool
    {
        return in_array($column, $this->loteColumns, true);
    }
}
