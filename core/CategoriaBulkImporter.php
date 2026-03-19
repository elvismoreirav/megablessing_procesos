<?php
/**
 * Importador masivo de categorias de proveedores.
 */

class CategoriaBulkImporter
{
    private const SHEET_NAME = 'Categorias';

    private PDO $db;
    private array $providerColumns = [];
    private array $allowedTypes = ['MERCADO', 'BODEGA', 'RUTA', 'PRODUCTOR'];
    private array $currentCategories = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();

        // Reutiliza la preparacion de esquema de proveedores.
        new ProveedorBulkImporter($this->db);

        $this->providerColumns = ConfigBulkImportUtils::getTableColumns($this->db, 'proveedores');
        $this->loadCurrentCategories();
    }

    public function getTemplateFileName(): string
    {
        return 'plantilla_parametrizacion_masiva_categorias.xlsx';
    }

    public function getTemplateSheets(): array
    {
        $currentRows = [['codigo', 'nombre', 'tipos_permitidos', 'activo']];
        foreach ($this->currentCategories as $category) {
            $currentRows[] = [
                $category['codigo'],
                $category['nombre'],
                implode(',', $category['tipos_permitidos']),
                $category['activo'] ? 'SI' : 'NO',
            ];
        }

        $typeRows = [['codigo', 'descripcion']];
        foreach ($this->allowedTypes as $type) {
            $typeRows[] = [$type, $type];
        }

        return [
            [
                'name' => self::SHEET_NAME,
                'rows' => [[
                    'codigo',
                    'nombre',
                    'tipos_permitidos',
                    'activo',
                ]],
            ],
            [
                'name' => 'Instrucciones',
                'rows' => [
                    ['campo', 'obligatorio', 'descripcion', 'ejemplo'],
                    ['codigo', 'SI', 'Codigo unico de la categoria o ruta.', 'CA'],
                    ['nombre', 'SI', 'Nombre visible de la categoria.', 'Centro de Acopio'],
                    ['tipos_permitidos', 'SI', 'Lista separada por coma o punto y coma con valores MERCADO, BODEGA, RUTA o PRODUCTOR.', 'BODEGA;RUTA'],
                    ['activo', 'NO', 'SI o NO. Si se omite se crea en SI.', 'SI'],
                    ['nota', 'NO', 'La hoja importada es solo "' . self::SHEET_NAME . '".', ''],
                ],
            ],
            [
                'name' => 'Tipos',
                'rows' => $typeRows,
            ],
            [
                'name' => 'Categorias_Actuales',
                'rows' => $currentRows,
            ],
        ];
    }

    public function getContextStats(): array
    {
        $active = 0;
        foreach ($this->currentCategories as $category) {
            if ($category['activo']) {
                $active++;
            }
        }

        return [
            [
                'label' => 'Categorias registradas',
                'value' => number_format(count($this->currentCategories)),
                'description' => 'Catalogo actual de categorias y rutas.',
            ],
            [
                'label' => 'Categorias activas',
                'value' => number_format($active),
                'description' => 'Se usan para asignar proveedores.',
            ],
        ];
    }

    public function importFromXlsx(string $filePath): array
    {
        $sheetRows = SimpleXlsx::readSheetRows($filePath, self::SHEET_NAME);
        $preparedRows = ConfigBulkImportUtils::prepareRows(
            $sheetRows,
            self::SHEET_NAME,
            [$this, 'canonicalHeader'],
            ['codigo', 'nombre', 'tipos_permitidos']
        );

        $created = [];
        $duplicates = [];
        $errors = [];
        $seenFingerprints = [];
        $blankRows = 0;
        $processedRows = 0;

        foreach ($preparedRows['rows'] as $rowEntry) {
            $rowValues = $rowEntry['values'];
            if (ConfigBulkImportUtils::isEmptyRow($rowValues)) {
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
                        'detail' => $existing,
                    ];
                    continue;
                }

                $created[] = $this->createCategory($record, $rowEntry['row_number']);
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

    public function canonicalHeader($headerValue): string
    {
        return match (ConfigBulkImportUtils::headerToken($headerValue)) {
            'codigo', 'codigo_categoria' => 'codigo',
            'nombre', 'categoria', 'categoria_nombre' => 'nombre',
            'tipos_permitidos', 'tipos', 'tipo', 'tipo_permitido' => 'tipos_permitidos',
            'activo', 'estado' => 'activo',
            default => '',
        };
    }

    private function loadCurrentCategories(): void
    {
        $this->currentCategories = [];
        if (!$this->hasColumn('categoria')) {
            return;
        }

        $rows = [];
        try {
            $rows = $this->db->query(
                "SELECT codigo, nombre, tipo, categoria, activo, " .
                ($this->hasColumn('tipos_permitidos') ? 'tipos_permitidos' : "NULL AS tipos_permitidos") .
                " FROM proveedores
                  WHERE " . ($this->hasColumn('es_categoria') ? 'es_categoria = 1' : '1 = 0') . "
                  ORDER BY activo DESC, nombre ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $rows = [];
        }

        foreach ($rows as $row) {
            $types = $this->parseAllowedTypes((string) ($row['tipos_permitidos'] ?? $row['tipo'] ?? ''));
            if (empty($types)) {
                $type = $this->normalizeType((string) ($row['tipo'] ?? ''));
                if ($type !== '') {
                    $types = [$type];
                }
            }

            $this->currentCategories[] = [
                'codigo' => strtoupper(trim((string) ($row['codigo'] ?? ''))),
                'nombre' => trim((string) ($row['nombre'] ?? '')),
                'categoria' => ConfigBulkImportUtils::normalizeKey((string) ($row['categoria'] ?? '')),
                'tipos_permitidos' => $types,
                'activo' => (int) ($row['activo'] ?? 1) === 1,
            ];
        }
    }

    private function normalizeRow(array $rowValues, array $columnMap): array
    {
        $codigo = strtoupper(trim(ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'codigo')));
        $nombre = trim(ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'nombre'));
        $tiposPermitidos = $this->parseAllowedTypes(
            ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'tipos_permitidos')
        );
        $activo = ConfigBulkImportUtils::parseYesNo(
            ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'activo'),
            true
        );

        if ($codigo === '' || $nombre === '') {
            throw new RuntimeException('El codigo y el nombre son obligatorios.');
        }
        if (empty($tiposPermitidos)) {
            throw new RuntimeException('Debe indicar al menos un tipo permitido valido.');
        }
        if (!$this->hasColumn('categoria')) {
            throw new RuntimeException('La columna de categoria no esta disponible en proveedores.');
        }

        return [
            'codigo' => $codigo,
            'nombre' => $nombre,
            'categoria' => ConfigBulkImportUtils::normalizeKey($nombre),
            'tipos_permitidos' => $tiposPermitidos,
            'tipo' => $tiposPermitidos[0],
            'activo' => $activo ?? 1,
        ];
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

    private function normalizeType(string $value): string
    {
        $value = ConfigBulkImportUtils::normalizeKey($value);

        return match ($value) {
            'CA', 'CENTRO_DE_ACOPIO', 'CENTRO ACOPIO', 'CENTRO DE ACOPIO', 'CENTRO DE ACOPIO (CA)' => 'BODEGA',
            default => $value,
        };
    }

    private function buildFingerprint(array $record): string
    {
        return sha1(json_encode([
            $record['codigo'],
            $record['categoria'],
            implode(',', $record['tipos_permitidos']),
            $record['activo'],
        ]));
    }

    private function findExistingDuplicate(array $record): ?string
    {
        $stmt = $this->db->prepare("SELECT id FROM proveedores WHERE codigo = ? LIMIT 1");
        $stmt->execute([$record['codigo']]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return 'Ya existe un registro con el codigo ' . $record['codigo'] . '.';
        }

        if ($this->hasColumn('es_categoria')) {
            $stmt = $this->db->prepare("SELECT nombre FROM proveedores WHERE es_categoria = 1 AND categoria = ? LIMIT 1");
            $stmt->execute([$record['categoria']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return 'Ya existe una categoria con el mismo nombre interno: ' . trim((string) ($existing['nombre'] ?? '')) . '.';
            }
        }

        return null;
    }

    private function createCategory(array $record, int $rowNumber): array
    {
        $insertData = [
            'codigo' => $record['codigo'],
            'nombre' => $record['nombre'],
            'tipo' => $record['tipo'],
            'categoria' => $record['categoria'],
            'activo' => $record['activo'],
        ];

        if ($this->hasColumn('tipos_permitidos')) {
            $insertData['tipos_permitidos'] = implode(',', $record['tipos_permitidos']);
        }
        if ($this->hasColumn('es_categoria')) {
            $insertData['es_categoria'] = 1;
        }
        if ($this->hasColumn('codigo_identificacion')) {
            $insertData['codigo_identificacion'] = null;
        }
        if ($this->hasColumn('cedula_ruc')) {
            $insertData['cedula_ruc'] = null;
        }
        if ($this->hasColumn('email')) {
            $insertData['email'] = null;
        }
        if ($this->hasColumn('contacto')) {
            $insertData['contacto'] = null;
        }
        if ($this->hasColumn('direccion')) {
            $insertData['direccion'] = null;
        }
        if ($this->hasColumn('telefono')) {
            $insertData['telefono'] = null;
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
            'codigo' => $record['codigo'],
            'nombre' => $record['nombre'],
            'tipos_permitidos' => implode(', ', $record['tipos_permitidos']),
            'activo' => ConfigBulkImportUtils::yesNoLabel($record['activo']),
        ];
    }

    private function hasColumn(string $name): bool
    {
        return in_array($name, $this->providerColumns, true);
    }
}
