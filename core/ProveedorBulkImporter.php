<?php
/**
 * Servicio para parametrizacion masiva de proveedores desde XLSX.
 */

class ProveedorBulkImporter
{
    private const SHEET_NAME = 'Proveedores';

    private PDO $db;
    private array $providerColumns = [];
    private array $categoryRows = [];
    private array $categoriesByCode = [];
    private array $categoriesByName = [];
    private array $categoriesByInternal = [];
    private array $allowedTypes = ['MERCADO', 'BODEGA', 'RUTA', 'PRODUCTOR'];
    private array $certificationCodes = [
        'ORGANICA' => 'Organica',
        'FAIR_TRADE' => 'Fair Trade',
        'UEDR' => 'UEDR',
        'RAINFOREST_ALLIANCE' => 'Rainforest Alliance',
        'OTRAS' => 'Otras',
        'NO_APLICA' => 'No aplica',
    ];
    private int $nextProviderCodeNumber = 1;
    private int $nextIdentificationNumber = 1;
    private array $templateHeaders = [
        'nombre',
        'tipo',
        'categoria_codigo',
        'cedula_ruc',
        'email',
        'telefono',
        'contacto',
        'direccion',
        'utm_este_x',
        'utm_norte_y',
        'seguridad_deforestacion',
        'arboles_endemicos',
        'hectareas_totales',
        'hectareas_ccn51',
        'hectareas_fino_aroma',
        'certificaciones',
        'certificacion_otras',
        'activo',
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->providerColumns = $this->getTableColumns('proveedores');

        $this->ensureSchema();
        $this->providerColumns = $this->getTableColumns('proveedores');

        $this->loadCategories();
        $this->loadSequences();
    }

    public function getTemplateFileName(): string
    {
        return 'plantilla_parametrizacion_masiva_proveedores.xlsx';
    }

    public function getCategoryCount(): int
    {
        return count($this->categoryRows);
    }

    public function getContextStats(): array
    {
        return [
            [
                'label' => 'Categorias disponibles',
                'value' => number_format($this->getCategoryCount()),
                'description' => 'Catalogo base usado para validar la plantilla.',
            ],
            [
                'label' => 'Codigos automaticos',
                'value' => 'PROV / PRO',
                'description' => 'Los codigos internos e identificacion se generan en la importacion.',
            ],
        ];
    }

    public function getTemplateSheets(): array
    {
        $categoryRows = [['codigo', 'nombre', 'categoria_interna', 'tipos_permitidos', 'activo']];
        foreach ($this->categoryRows as $category) {
            $categoryRows[] = [
                $category['codigo'],
                $category['nombre'],
                $category['categoria'],
                implode(',', $category['tipos_permitidos']),
                $category['activo'] ? 'SI' : 'NO',
            ];
        }

        $typeRows = [['codigo', 'descripcion']];
        foreach ($this->allowedTypes as $type) {
            $typeRows[] = [$type, $this->typeLabel($type)];
        }

        $certificationRows = [['codigo', 'descripcion']];
        foreach ($this->certificationCodes as $code => $label) {
            $certificationRows[] = [$code, $label];
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
                    ['nombre', 'SI', 'Nombre o razon social del proveedor.', 'Finca Los Robles'],
                    ['tipo', 'SI', 'Use MERCADO, BODEGA, RUTA o PRODUCTOR.', 'PRODUCTOR'],
                    ['categoria_codigo', 'SI', 'Codigo de la categoria existente en el catalogo.', 'CA'],
                    ['cedula_ruc', 'NO', 'Cedula o RUC de 10 o 13 digitos. Si existe se usa para detectar repetidos.', '0999999999001'],
                    ['email', 'NO', 'Correo del proveedor.', 'proveedor@correo.com'],
                    ['telefono', 'NO', 'Telefono o celular.', '0999123456'],
                    ['contacto', 'NO', 'Persona de contacto.', 'Maria Perez'],
                    ['direccion', 'NO', 'Direccion completa.', 'Via principal km 5'],
                    ['utm_este_x', 'NO', 'Coordenada UTM Este.', '654321'],
                    ['utm_norte_y', 'NO', 'Coordenada UTM Norte.', '9876543'],
                    ['seguridad_deforestacion', 'NO', 'Valores permitidos: SI o NO.', 'SI'],
                    ['arboles_endemicos', 'NO', 'Valores permitidos: SI o NO.', 'NO'],
                    ['hectareas_totales', 'NO', 'Numero decimal.', '12.50'],
                    ['hectareas_ccn51', 'NO', 'Numero decimal.', '7.25'],
                    ['hectareas_fino_aroma', 'NO', 'Numero decimal.', '5.25'],
                    ['certificaciones', 'NO', 'Lista separada por coma o punto y coma usando codigos del catalogo.', 'ORGANICA;UEDR'],
                    ['certificacion_otras', 'CONDICIONAL', 'Obligatorio cuando certificaciones incluye OTRAS.', 'Certificacion local'],
                    ['activo', 'NO', 'SI o NO. Si se omite se crea en SI.', 'SI'],
                    ['nota', 'NO', 'Los campos codigo y codigo_identificacion se generan automaticamente. La hoja importada es solo "Proveedores".', ''],
                    ['nota_documentos', 'NO', 'El documento PDF de certificaciones no se carga por XLSX; solo se registran datos textuales.', ''],
                ],
            ],
            [
                'name' => 'Categorias',
                'rows' => $categoryRows,
            ],
            [
                'name' => 'Tipos',
                'rows' => $typeRows,
            ],
            [
                'name' => 'Certificaciones',
                'rows' => $certificationRows,
            ],
        ];
    }

    public function importFromXlsx(string $filePath): array
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
                        'detail' => $existing['detail'],
                    ];
                    continue;
                }

                $created[] = $this->createProvider($record, $rowEntry['row_number']);
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

    private function ensureSchema(): void
    {
        $hasColumn = fn(string $name): bool => in_array($name, $this->providerColumns, true);
        $statements = [];

        if (!$hasColumn('cedula_ruc')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN cedula_ruc VARCHAR(20) NULL AFTER nombre";
        }
        if (!$hasColumn('codigo_identificacion')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN codigo_identificacion VARCHAR(20) NULL AFTER codigo";
        }
        if (!$hasColumn('categoria')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN categoria VARCHAR(100) NULL AFTER tipo";
        }
        if (!$hasColumn('es_categoria')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN es_categoria TINYINT(1) NOT NULL DEFAULT 0 AFTER categoria";
        }
        if (!$hasColumn('email')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN email VARCHAR(120) NULL AFTER telefono";
        }
        if (!$hasColumn('tipos_permitidos')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN tipos_permitidos VARCHAR(120) NULL AFTER categoria";
        }
        if (!$hasColumn('utm_este_x')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN utm_este_x VARCHAR(50) NULL AFTER direccion";
        }
        if (!$hasColumn('utm_norte_y')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN utm_norte_y VARCHAR(50) NULL AFTER utm_este_x";
        }
        if (!$hasColumn('seguridad_deforestacion')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN seguridad_deforestacion TINYINT(1) NULL AFTER utm_norte_y";
        }
        if (!$hasColumn('arboles_endemicos')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN arboles_endemicos TINYINT(1) NULL AFTER seguridad_deforestacion";
        }
        if (!$hasColumn('hectareas_totales')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN hectareas_totales DECIMAL(10,2) NULL AFTER arboles_endemicos";
        }
        if (!$hasColumn('hectareas_ccn51')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN hectareas_ccn51 DECIMAL(10,2) NULL AFTER hectareas_totales";
        }
        if (!$hasColumn('hectareas_fino_aroma')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN hectareas_fino_aroma DECIMAL(10,2) NULL AFTER hectareas_ccn51";
        }
        if (!$hasColumn('certificaciones')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN certificaciones TEXT NULL AFTER hectareas_fino_aroma";
        }
        if (!$hasColumn('certificacion_otras')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN certificacion_otras VARCHAR(255) NULL AFTER certificaciones";
        }
        if (!$hasColumn('documento_certificaciones')) {
            $statements[] = "ALTER TABLE proveedores ADD COLUMN documento_certificaciones VARCHAR(255) NULL AFTER certificacion_otras";
        }

        foreach ($statements as $statement) {
            try {
                $this->db->exec($statement);
            } catch (Throwable $e) {
                // Continuar en modo compatibilidad.
            }
        }
    }

    private function loadCategories(): void
    {
        $seedRows = [
            ['codigo' => 'M', 'nombre' => 'Mercado', 'tipo' => 'MERCADO', 'categoria' => 'MERCADO', 'tipos_permitidos' => 'MERCADO', 'activo' => 1],
            ['codigo' => 'CA', 'nombre' => 'Centro de Acopio', 'tipo' => 'BODEGA', 'categoria' => 'CENTRO DE ACOPIO', 'tipos_permitidos' => 'BODEGA', 'activo' => 1],
            ['codigo' => 'ES', 'nombre' => 'Esmeraldas', 'tipo' => 'RUTA', 'categoria' => 'ESMERALDAS', 'tipos_permitidos' => 'RUTA', 'activo' => 1],
            ['codigo' => 'FM', 'nombre' => 'Flor de Manabi', 'tipo' => 'RUTA', 'categoria' => 'FLOR DE MANABI', 'tipos_permitidos' => 'RUTA', 'activo' => 1],
            ['codigo' => 'VP', 'nombre' => 'Via Pedernales', 'tipo' => 'RUTA', 'categoria' => 'VIA PEDERNALES', 'tipos_permitidos' => 'RUTA', 'activo' => 1],
        ];

        $rows = [];
        if ($this->hasColumn('categoria') && $this->hasColumn('es_categoria')) {
            try {
                $rows = $this->db->query(
                    "SELECT id, codigo, nombre, tipo, categoria, activo, " .
                    ($this->hasColumn('tipos_permitidos') ? "tipos_permitidos" : "NULL AS tipos_permitidos") .
                    " FROM proveedores
                      WHERE es_categoria = 1
                      ORDER BY activo DESC, tipo ASC, nombre ASC"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $rows = [];
            }
        }

        if (empty($rows)) {
            $rows = $seedRows;
        }

        $this->categoryRows = [];
        foreach ($rows as $row) {
            $categoryInternal = $this->normalizeCategory((string) ($row['categoria'] ?? $row['nombre'] ?? ''));
            if ($categoryInternal === '') {
                continue;
            }

            $typesAllowed = $this->parseAllowedTypes((string) ($row['tipos_permitidos'] ?? $row['tipo'] ?? ''));
            if (empty($typesAllowed)) {
                $normalizedType = $this->normalizeType((string) ($row['tipo'] ?? 'RUTA'));
                $typesAllowed = in_array($normalizedType, $this->allowedTypes, true) ? [$normalizedType] : ['RUTA'];
            }

            $category = [
                'id' => (int) ($row['id'] ?? 0),
                'codigo' => strtoupper(trim((string) ($row['codigo'] ?? ''))),
                'nombre' => trim((string) ($row['nombre'] ?? '')),
                'categoria' => $categoryInternal,
                'activo' => (int) ($row['activo'] ?? 1) === 1,
                'tipos_permitidos' => $typesAllowed,
            ];

            $this->categoryRows[] = $category;
            if ($category['codigo'] !== '') {
                $this->categoriesByCode[$this->normalizeKey($category['codigo'])] = $category;
            }
            if ($category['nombre'] !== '') {
                $this->categoriesByName[$this->normalizeKey($category['nombre'])] = $category;
            }
            $this->categoriesByInternal[$this->normalizeKey($category['categoria'])] = $category;
        }
    }

    private function loadSequences(): void
    {
        try {
            $rows = $this->db->query("SELECT codigo FROM proveedores WHERE codigo LIKE 'PROV%'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $value = strtoupper(trim((string) ($row['codigo'] ?? '')));
                if (preg_match('/^PROV(\d{4,})$/', $value, $matches)) {
                    $this->nextProviderCodeNumber = max($this->nextProviderCodeNumber, (int) $matches[1] + 1);
                }
            }
        } catch (Throwable $e) {
            $this->nextProviderCodeNumber = 1;
        }

        if ($this->hasColumn('codigo_identificacion')) {
            try {
                $rows = $this->db->query("SELECT codigo_identificacion FROM proveedores WHERE codigo_identificacion LIKE 'PRO-%'")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $value = strtoupper(trim((string) ($row['codigo_identificacion'] ?? '')));
                    if (preg_match('/^PRO-(\d{5})$/', $value, $matches)) {
                        $this->nextIdentificationNumber = max($this->nextIdentificationNumber, (int) $matches[1] + 1);
                    }
                }
            } catch (Throwable $e) {
                $this->nextIdentificationNumber = 1;
            }
        }
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
            ['nombre', 'tipo', 'categoria'],
            static fn(string $header): bool => !isset($columnMap[$header])
        ));

        if (!empty($missingHeaders)) {
            throw new RuntimeException(
                'Faltan columnas requeridas en la hoja "' . self::SHEET_NAME . '": ' . implode(', ', $missingHeaders) . '.'
            );
        }

        $rows = [];
        for ($i = $headerRowIndex + 1, $total = count($sheetRows); $i < $total; $i++) {
            $rows[] = [
                'row_number' => $i + 1,
                'values' => $sheetRows[$i],
            ];
        }

        if (empty($rows)) {
            throw new RuntimeException('La hoja "' . self::SHEET_NAME . '" no contiene filas para importar.');
        }

        return [
            'column_map' => $columnMap,
            'rows' => $rows,
        ];
    }

    private function canonicalHeader($headerValue): string
    {
        $normalized = strtolower($this->normalizeKey((string) $headerValue));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        $normalized = trim((string) $normalized, '_');

        return match ($normalized) {
            'nombre', 'nombre_empresa', 'razon_social' => 'nombre',
            'tipo', 'tipo_proveedor' => 'tipo',
            'categoria', 'categoria_codigo', 'codigo_categoria', 'categoria_nombre' => 'categoria',
            'cedula_ruc', 'ruc', 'cedula' => 'cedula_ruc',
            'email', 'correo', 'correo_electronico' => 'email',
            'telefono', 'celular' => 'telefono',
            'contacto', 'persona_contacto' => 'contacto',
            'direccion', 'direccion_completa' => 'direccion',
            'utm_este_x', 'utm_este', 'coordenada_este', 'utm_x' => 'utm_este_x',
            'utm_norte_y', 'utm_norte', 'coordenada_norte', 'utm_y' => 'utm_norte_y',
            'seguridad_deforestacion', 'deforestacion' => 'seguridad_deforestacion',
            'arboles_endemicos', 'endemicos' => 'arboles_endemicos',
            'hectareas_totales', 'ha_totales' => 'hectareas_totales',
            'hectareas_ccn51', 'ha_ccn51' => 'hectareas_ccn51',
            'hectareas_fino_aroma', 'ha_fino_aroma', 'ha_nacional' => 'hectareas_fino_aroma',
            'certificaciones', 'certificacion' => 'certificaciones',
            'certificacion_otras', 'otras_certificaciones' => 'certificacion_otras',
            'activo', 'estado' => 'activo',
            default => '',
        };
    }

    private function normalizeRow(array $rowValues, array $columnMap): array
    {
        $name = trim($this->getCellValue($rowValues, $columnMap, 'nombre'));
        $type = $this->normalizeType($this->getCellValue($rowValues, $columnMap, 'tipo'));
        $categoryRef = $this->getCellValue($rowValues, $columnMap, 'categoria');
        $cedulaRuc = preg_replace('/\s+/', '', trim($this->getCellValue($rowValues, $columnMap, 'cedula_ruc')));
        $email = trim($this->getCellValue($rowValues, $columnMap, 'email'));
        $phone = trim($this->getCellValue($rowValues, $columnMap, 'telefono'));
        $contact = trim($this->getCellValue($rowValues, $columnMap, 'contacto'));
        $address = trim($this->getCellValue($rowValues, $columnMap, 'direccion'));
        $utmEsteX = trim($this->getCellValue($rowValues, $columnMap, 'utm_este_x'));
        $utmNorteY = trim($this->getCellValue($rowValues, $columnMap, 'utm_norte_y'));
        $deforestation = $this->parseYesNo($this->getCellValue($rowValues, $columnMap, 'seguridad_deforestacion'));
        $endemicTrees = $this->parseYesNo($this->getCellValue($rowValues, $columnMap, 'arboles_endemicos'));
        $hectareasTotales = $this->parseDecimal($this->getCellValue($rowValues, $columnMap, 'hectareas_totales'));
        $hectareasCcn51 = $this->parseDecimal($this->getCellValue($rowValues, $columnMap, 'hectareas_ccn51'));
        $hectareasFinoAroma = $this->parseDecimal($this->getCellValue($rowValues, $columnMap, 'hectareas_fino_aroma'));
        $certifications = $this->parseCertifications($this->getCellValue($rowValues, $columnMap, 'certificaciones'));
        $otherCertification = trim($this->getCellValue($rowValues, $columnMap, 'certificacion_otras'));
        $active = $this->parseYesNo($this->getCellValue($rowValues, $columnMap, 'activo'), true);

        if ($name === '') {
            throw new RuntimeException('El nombre es obligatorio.');
        }

        if (!in_array($type, $this->allowedTypes, true)) {
            throw new RuntimeException('El tipo de proveedor no es valido.');
        }

        $category = $this->resolveCategory($categoryRef);
        if (!$category['activo']) {
            throw new RuntimeException('La categoria seleccionada esta inactiva.');
        }
        if (!in_array($type, $category['tipos_permitidos'], true)) {
            throw new RuntimeException(
                'El tipo ' . $type . ' no esta permitido para la categoria ' . ($category['codigo'] !== '' ? $category['codigo'] : $category['nombre']) . '.'
            );
        }

        if ($cedulaRuc !== '' && !preg_match('/^\d{10}(\d{3})?$/', $cedulaRuc)) {
            throw new RuntimeException('La cedula/RUC debe tener 10 o 13 digitos numericos.');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo electronico no es valido.');
        }

        if (in_array('OTRAS', $certifications, true) && $otherCertification === '') {
            throw new RuntimeException('Debe detallar la certificacion cuando use OTRAS.');
        }

        if (in_array('NO_APLICA', $certifications, true)) {
            $certifications = ['NO_APLICA'];
            $otherCertification = '';
        }

        return [
            'nombre' => $name,
            'nombre_key' => $this->normalizeKey($name),
            'tipo' => $type,
            'categoria' => $category['categoria'],
            'categoria_codigo' => $category['codigo'],
            'categoria_nombre' => $category['nombre'],
            'cedula_ruc' => $cedulaRuc !== '' ? $cedulaRuc : null,
            'email' => $email !== '' ? $email : null,
            'telefono' => $phone !== '' ? $phone : null,
            'contacto' => $contact !== '' ? $contact : null,
            'direccion' => $address !== '' ? $address : null,
            'utm_este_x' => $utmEsteX !== '' ? $utmEsteX : null,
            'utm_norte_y' => $utmNorteY !== '' ? $utmNorteY : null,
            'seguridad_deforestacion' => $deforestation,
            'arboles_endemicos' => $endemicTrees,
            'hectareas_totales' => $hectareasTotales,
            'hectareas_ccn51' => $hectareasCcn51,
            'hectareas_fino_aroma' => $hectareasFinoAroma,
            'certificaciones' => $certifications,
            'certificacion_otras' => $otherCertification !== '' ? $otherCertification : null,
            'activo' => $active,
        ];
    }

    private function resolveCategory(string $reference): array
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new RuntimeException('La categoria es obligatoria.');
        }

        $key = $this->normalizeKey($reference);
        if (isset($this->categoriesByCode[$key])) {
            return $this->categoriesByCode[$key];
        }
        if (isset($this->categoriesByName[$key])) {
            return $this->categoriesByName[$key];
        }
        if (isset($this->categoriesByInternal[$key])) {
            return $this->categoriesByInternal[$key];
        }

        throw new RuntimeException('No se encontro la categoria indicada: ' . $reference . '.');
    }

    private function buildFingerprint(array $record): string
    {
        return sha1(json_encode([
            $record['nombre_key'],
            $record['tipo'],
            $record['categoria'],
            $record['cedula_ruc'],
            $record['email'],
            $record['telefono'],
            $record['contacto'],
            $record['direccion'],
            $record['utm_este_x'],
            $record['utm_norte_y'],
            $record['seguridad_deforestacion'],
            $record['arboles_endemicos'],
            $record['hectareas_totales'],
            $record['hectareas_ccn51'],
            $record['hectareas_fino_aroma'],
            implode(',', $record['certificaciones']),
            $record['certificacion_otras'],
            $record['activo'],
        ], JSON_UNESCAPED_UNICODE));
    }

    private function findExistingDuplicate(array $record): ?array
    {
        if ($this->hasColumn('cedula_ruc') && $record['cedula_ruc'] !== null) {
            $stmt = $this->db->prepare("
                SELECT codigo, nombre
                FROM proveedores
                WHERE cedula_ruc = ?
                LIMIT 1
            ");
            $stmt->execute([$record['cedula_ruc']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return [
                    'detail' => 'Ya existe un proveedor con la misma cedula/RUC: ' . trim((string) ($existing['codigo'] ?? '')) . ' - ' . trim((string) ($existing['nombre'] ?? '')) . '.',
                ];
            }
        }

        $params = [$record['nombre_key'], $record['tipo']];
        $sql = "
            SELECT codigo, nombre
            FROM proveedores
            WHERE UPPER(TRIM(COALESCE(nombre, ''))) = ?
              AND tipo = ?
        ";

        if ($this->hasColumn('categoria')) {
            $sql .= " AND UPPER(TRIM(COALESCE(categoria, ''))) = ?";
            $params[] = $record['categoria'];
        }
        if ($this->hasColumn('es_categoria')) {
            $sql .= " AND (es_categoria = 0 OR es_categoria IS NULL)";
        }
        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return [
                'detail' => 'Ya existe un proveedor con el mismo nombre, tipo y categoria: ' . trim((string) ($existing['codigo'] ?? '')) . ' - ' . trim((string) ($existing['nombre'] ?? '')) . '.',
            ];
        }

        return null;
    }

    private function createProvider(array $record, int $rowNumber): array
    {
        $providerCode = $this->generateNextProviderCode();
        $identificationCode = $this->hasColumn('codigo_identificacion')
            ? $this->generateNextIdentificationCode()
            : null;

        $insertData = [
            'codigo' => $providerCode,
            'nombre' => $record['nombre'],
            'tipo' => $record['tipo'],
            'activo' => $record['activo'] ? 1 : 0,
        ];

        if ($this->hasColumn('direccion')) {
            $insertData['direccion'] = $record['direccion'];
        }
        if ($this->hasColumn('telefono')) {
            $insertData['telefono'] = $record['telefono'];
        }
        if ($this->hasColumn('es_categoria')) {
            $insertData['es_categoria'] = 0;
        }
        if ($this->hasColumn('contacto')) {
            $insertData['contacto'] = $record['contacto'];
        }
        if ($this->hasColumn('cedula_ruc')) {
            $insertData['cedula_ruc'] = $record['cedula_ruc'];
        }
        if ($this->hasColumn('codigo_identificacion')) {
            $insertData['codigo_identificacion'] = $identificationCode;
        }
        if ($this->hasColumn('categoria')) {
            $insertData['categoria'] = $record['categoria'];
        }
        if ($this->hasColumn('email')) {
            $insertData['email'] = $record['email'];
        }
        if ($this->hasColumn('tipos_permitidos')) {
            $insertData['tipos_permitidos'] = null;
        }
        if ($this->hasColumn('utm_este_x')) {
            $insertData['utm_este_x'] = $record['utm_este_x'];
        }
        if ($this->hasColumn('utm_norte_y')) {
            $insertData['utm_norte_y'] = $record['utm_norte_y'];
        }
        if ($this->hasColumn('seguridad_deforestacion')) {
            $insertData['seguridad_deforestacion'] = $record['seguridad_deforestacion'];
        }
        if ($this->hasColumn('arboles_endemicos')) {
            $insertData['arboles_endemicos'] = $record['arboles_endemicos'];
        }
        if ($this->hasColumn('hectareas_totales')) {
            $insertData['hectareas_totales'] = $record['hectareas_totales'];
        }
        if ($this->hasColumn('hectareas_ccn51')) {
            $insertData['hectareas_ccn51'] = $record['hectareas_ccn51'];
        }
        if ($this->hasColumn('hectareas_fino_aroma')) {
            $insertData['hectareas_fino_aroma'] = $record['hectareas_fino_aroma'];
        }
        if ($this->hasColumn('certificaciones')) {
            $insertData['certificaciones'] = !empty($record['certificaciones'])
                ? json_encode($record['certificaciones'], JSON_UNESCAPED_UNICODE)
                : null;
        }
        if ($this->hasColumn('certificacion_otras')) {
            $insertData['certificacion_otras'] = $record['certificacion_otras'];
        }
        if ($this->hasColumn('documento_certificaciones')) {
            $insertData['documento_certificaciones'] = null;
        }

        $columns = array_keys($insertData);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $this->db->prepare(
            'INSERT INTO proveedores (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
        );

        try {
            $stmt->execute(array_values($insertData));
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo guardar la fila ' . $rowNumber . ': ' . $e->getMessage());
        }

        return [
            'row_number' => $rowNumber,
            'codigo' => $providerCode,
            'codigo_identificacion' => $identificationCode,
            'nombre' => $record['nombre'],
            'tipo' => $record['tipo'],
            'categoria' => $record['categoria_nombre'] !== '' ? $record['categoria_nombre'] : $record['categoria'],
            'activo' => $record['activo'] ? 'SI' : 'NO',
        ];
    }

    private function generateNextProviderCode(): string
    {
        while (true) {
            $code = 'PROV' . str_pad((string) $this->nextProviderCodeNumber, 4, '0', STR_PAD_LEFT);
            $this->nextProviderCodeNumber++;

            $stmt = $this->db->prepare('SELECT id FROM proveedores WHERE codigo = ? LIMIT 1');
            $stmt->execute([$code]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                return $code;
            }
        }
    }

    private function generateNextIdentificationCode(): string
    {
        while (true) {
            $code = 'PRO-' . str_pad((string) $this->nextIdentificationNumber, 5, '0', STR_PAD_LEFT);
            $this->nextIdentificationNumber++;

            $stmt = $this->db->prepare('SELECT id FROM proveedores WHERE codigo_identificacion = ? LIMIT 1');
            $stmt->execute([$code]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                return $code;
            }
        }
    }

    private function parseAllowedTypes(string $value): array
    {
        $items = preg_split('/[\s,;|]+/', trim($value)) ?: [];
        $types = [];

        foreach ($items as $item) {
            $type = $this->normalizeType($item);
            if ($type === '' || !in_array($type, $this->allowedTypes, true) || in_array($type, $types, true)) {
                continue;
            }
            $types[] = $type;
        }

        return $types;
    }

    private function parseCertifications(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $items = preg_split('/[\s,;|]+/', $value) ?: [];
        $result = [];

        foreach ($items as $item) {
            $normalized = $this->normalizeKey($item);
            if ($normalized === '') {
                continue;
            }

            $code = null;
            if (isset($this->certificationCodes[$normalized])) {
                $code = $normalized;
            } else {
                foreach ($this->certificationCodes as $candidateCode => $label) {
                    if ($this->normalizeKey($label) === $normalized) {
                        $code = $candidateCode;
                        break;
                    }
                }
            }

            if ($code === null || in_array($code, $result, true)) {
                continue;
            }

            if ($code === 'NO_APLICA') {
                return ['NO_APLICA'];
            }

            $result[] = $code;
        }

        return $result;
    }

    private function parseDecimal(string $value): ?float
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

        if (!is_numeric($value)) {
            throw new RuntimeException('Se encontro un valor decimal no valido: ' . $value . '.');
        }

        return round((float) $value, 2);
    }

    private function parseYesNo(string $value, bool $defaultYes = false): ?int
    {
        $value = $this->normalizeKey($value);
        if ($value === '') {
            return $defaultYes ? 1 : null;
        }

        return match ($value) {
            'SI', 'S', 'YES', 'Y', '1', 'TRUE' => 1,
            'NO', 'N', '0', 'FALSE' => 0,
            default => throw new RuntimeException('Solo se admiten valores SI o NO en los campos booleanos.'),
        };
    }

    private function normalizeType(string $value): string
    {
        $value = $this->normalizeKey($value);

        return match ($value) {
            'CA', 'CENTRO_DE_ACOPIO', 'CENTRO ACOPIO', 'CENTRO DE ACOPIO (CA)' => 'BODEGA',
            default => $value,
        };
    }

    private function normalizeCategory(string $value): string
    {
        return $this->normalizeKey($value);
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

    private function getCellValue(array $rowValues, array $columnMap, string $field): string
    {
        $index = $columnMap[$field] ?? null;
        if ($index === null) {
            return '';
        }

        return trim((string) ($rowValues[$index] ?? ''));
    }

    private function getTableColumns(string $table): array
    {
        return array_column(
            $this->db->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
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

    private function hasColumn(string $name): bool
    {
        return in_array($name, $this->providerColumns, true);
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'MERCADO' => 'Mercado',
            'BODEGA' => 'Centro de Acopio',
            'RUTA' => 'Ruta',
            'PRODUCTOR' => 'Productor',
            default => $type,
        };
    }
}
