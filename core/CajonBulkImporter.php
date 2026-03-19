<?php
/**
 * Importador masivo de cajones de fermentacion.
 */

class CajonBulkImporter
{
    private const SHEET_NAME = 'Cajones';

    private PDO $db;
    private array $columns = [];
    private array $stats = [
        'total' => 0,
        'activos' => 0,
        'capacidad_total' => 0,
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->columns = ConfigBulkImportUtils::getTableColumns($this->db, 'cajones_fermentacion');
        $this->loadStats();
    }

    public function getTemplateFileName(): string
    {
        return 'plantilla_parametrizacion_masiva_cajones.xlsx';
    }

    public function getTemplateSheets(): array
    {
        return [
            [
                'name' => self::SHEET_NAME,
                'rows' => [[
                    'numero',
                    'capacidad_kg',
                    'material',
                    'ubicacion',
                    'activo',
                ]],
            ],
            [
                'name' => 'Instrucciones',
                'rows' => [
                    ['campo', 'obligatorio', 'descripcion', 'ejemplo'],
                    ['numero', 'SI', 'Codigo o numero unico del cajon.', 'CJ-01'],
                    ['capacidad_kg', 'NO', 'Capacidad en kilogramos.', '800'],
                    ['material', 'NO', 'Material del cajon.', 'Madera'],
                    ['ubicacion', 'NO', 'Ubicacion fisica del cajon.', 'Area de fermentacion'],
                    ['activo', 'NO', 'SI o NO. Si se omite se crea en SI.', 'SI'],
                ],
            ],
        ];
    }

    public function getContextStats(): array
    {
        return [
            [
                'label' => 'Cajones registrados',
                'value' => number_format((int) $this->stats['total']),
                'description' => 'Catalogo total de cajones.',
            ],
            [
                'label' => 'Cajones activos',
                'value' => number_format((int) $this->stats['activos']),
                'description' => 'Disponibles para fermentacion.',
            ],
            [
                'label' => 'Capacidad total',
                'value' => number_format((float) $this->stats['capacidad_total'], 0) . ' kg',
                'description' => 'Suma de capacidad registrada.',
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
            ['numero']
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
                $fingerprint = $record['numero'];

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

                $created[] = $this->createCajon($record, $rowEntry['row_number']);
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
            'numero', 'codigo', 'numero_cajon' => 'numero',
            'capacidad_kg', 'capacidad', 'capacidad_kilos' => 'capacidad_kg',
            'material' => 'material',
            'ubicacion', 'ubicacion_cajon' => 'ubicacion',
            'activo', 'estado' => 'activo',
            default => '',
        };
    }

    private function loadStats(): void
    {
        $stats = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(activo = 1), 0) AS activos,
                COALESCE(SUM(capacidad_kg), 0) AS capacidad_total
             FROM cajones_fermentacion"
        )->fetch(PDO::FETCH_ASSOC);

        if ($stats) {
            $this->stats = $stats;
        }
    }

    private function normalizeRow(array $rowValues, array $columnMap): array
    {
        $numero = strtoupper(trim(ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'numero')));
        $capacidad = ConfigBulkImportUtils::parseDecimal(
            ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'capacidad_kg')
        );
        $material = trim(ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'material'));
        $ubicacion = trim(ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'ubicacion'));
        $activo = ConfigBulkImportUtils::parseYesNo(
            ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'activo'),
            true
        );

        if ($numero === '') {
            throw new RuntimeException('El numero del cajon es obligatorio.');
        }

        return [
            'numero' => $numero,
            'nombre' => $this->resolverNombreCajon($numero),
            'capacidad_kg' => $capacidad,
            'material' => $material !== '' ? $material : null,
            'ubicacion' => $ubicacion !== '' ? $ubicacion : null,
            'activo' => $activo ?? 1,
        ];
    }

    private function findExistingDuplicate(array $record): ?string
    {
        $stmt = $this->db->prepare("SELECT id FROM cajones_fermentacion WHERE numero = ? LIMIT 1");
        $stmt->execute([$record['numero']]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return 'Ya existe un cajon con el numero ' . $record['numero'] . '.';
        }

        return null;
    }

    private function createCajon(array $record, int $rowNumber): array
    {
        $insertData = [
            'numero' => $record['numero'],
            'capacidad_kg' => $record['capacidad_kg'],
            'material' => $record['material'],
            'ubicacion' => $record['ubicacion'],
        ];

        if ($this->hasColumn('nombre')) {
            $insertData = [
                'numero' => $record['numero'],
                'nombre' => $record['nombre'],
                'capacidad_kg' => $record['capacidad_kg'],
                'material' => $record['material'],
                'ubicacion' => $record['ubicacion'],
            ];
        }
        if ($this->hasColumn('activo')) {
            $insertData['activo'] = $record['activo'];
        }

        $columns = array_keys($insertData);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $this->db->prepare(
            'INSERT INTO cajones_fermentacion (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
        );

        try {
            $stmt->execute(array_values($insertData));
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo guardar la fila ' . $rowNumber . ': ' . $e->getMessage());
        }

        return [
            'row_number' => $rowNumber,
            'numero' => $record['numero'],
            'nombre' => $record['nombre'],
            'capacidad_kg' => $record['capacidad_kg'] !== null ? number_format((float) $record['capacidad_kg'], 2) : '-',
            'material' => $record['material'] ?? '-',
            'activo' => ConfigBulkImportUtils::yesNoLabel($record['activo']),
        ];
    }

    private function resolverNombreCajon(string $numero): string
    {
        if (preg_match('/(\d+)$/', $numero, $matches)) {
            return 'Cajón ' . (int) $matches[1];
        }

        return $numero;
    }

    private function hasColumn(string $name): bool
    {
        return in_array($name, $this->columns, true);
    }
}
