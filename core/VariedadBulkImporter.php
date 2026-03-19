<?php
/**
 * Importador masivo de variedades.
 */

class VariedadBulkImporter
{
    private const SHEET_NAME = 'Variedades';

    private PDO $db;
    private array $columns = [];
    private array $stats = [
        'total' => 0,
        'activas' => 0,
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->columns = ConfigBulkImportUtils::getTableColumns($this->db, 'variedades');
        $this->loadStats();
    }

    public function getTemplateFileName(): string
    {
        return 'plantilla_parametrizacion_masiva_variedades.xlsx';
    }

    public function getTemplateSheets(): array
    {
        return [
            [
                'name' => self::SHEET_NAME,
                'rows' => [[
                    'codigo',
                    'nombre',
                    'descripcion',
                    'activo',
                ]],
            ],
            [
                'name' => 'Instrucciones',
                'rows' => [
                    ['campo', 'obligatorio', 'descripcion', 'ejemplo'],
                    ['codigo', 'SI', 'Codigo unico de la variedad.', 'CCN51'],
                    ['nombre', 'SI', 'Nombre visible de la variedad.', 'CCN-51'],
                    ['descripcion', 'NO', 'Detalle adicional de la variedad.', 'Variedad de alto rendimiento'],
                    ['activo', 'NO', 'SI o NO. Si se omite se crea en SI.', 'SI'],
                    ['nota', 'NO', 'La hoja importada es solo "' . self::SHEET_NAME . '".', ''],
                ],
            ],
        ];
    }

    public function getContextStats(): array
    {
        return [
            [
                'label' => 'Variedades registradas',
                'value' => number_format((int) $this->stats['total']),
                'description' => 'Catalogo total disponible.',
            ],
            [
                'label' => 'Variedades activas',
                'value' => number_format((int) $this->stats['activas']),
                'description' => 'Disponibles para lotes nuevos.',
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
            ['codigo', 'nombre']
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
                $fingerprint = $record['codigo'] . '|' . ConfigBulkImportUtils::normalizeKey($record['nombre']);

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

                $created[] = $this->createVariedad($record, $rowEntry['row_number']);
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
            'codigo', 'codigo_variedad' => 'codigo',
            'nombre', 'variedad', 'nombre_variedad' => 'nombre',
            'descripcion', 'detalle', 'observacion' => 'descripcion',
            'activo', 'estado' => 'activo',
            default => '',
        };
    }

    private function loadStats(): void
    {
        $stats = $this->db->query(
            "SELECT COUNT(*) AS total, COALESCE(SUM(activo = 1), 0) AS activas FROM variedades"
        )->fetch(PDO::FETCH_ASSOC);

        if ($stats) {
            $this->stats = $stats;
        }
    }

    private function normalizeRow(array $rowValues, array $columnMap): array
    {
        $codigo = strtoupper(trim(ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'codigo')));
        $nombre = trim(ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'nombre'));
        $descripcion = trim(ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'descripcion'));
        $activo = ConfigBulkImportUtils::parseYesNo(
            ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'activo'),
            true
        );

        if ($codigo === '' || $nombre === '') {
            throw new RuntimeException('El codigo y el nombre son obligatorios.');
        }

        return [
            'codigo' => $codigo,
            'nombre' => $nombre,
            'descripcion' => $descripcion !== '' ? $descripcion : null,
            'activo' => $activo ?? 1,
        ];
    }

    private function findExistingDuplicate(array $record): ?string
    {
        $stmt = $this->db->prepare("SELECT nombre FROM variedades WHERE codigo = ? LIMIT 1");
        $stmt->execute([$record['codigo']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            return 'Ya existe una variedad con el codigo ' . $record['codigo'] . ': ' . trim((string) ($existing['nombre'] ?? '')) . '.';
        }

        return null;
    }

    private function createVariedad(array $record, int $rowNumber): array
    {
        $insertData = [
            'codigo' => $record['codigo'],
            'nombre' => $record['nombre'],
            'descripcion' => $record['descripcion'],
        ];

        if ($this->hasColumn('activo')) {
            $insertData['activo'] = $record['activo'];
        }

        $columns = array_keys($insertData);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $this->db->prepare(
            'INSERT INTO variedades (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
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
            'descripcion' => $record['descripcion'] ?? '-',
            'activo' => ConfigBulkImportUtils::yesNoLabel($record['activo']),
        ];
    }

    private function hasColumn(string $name): bool
    {
        return in_array($name, $this->columns, true);
    }
}
