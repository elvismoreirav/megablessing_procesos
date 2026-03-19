<?php
/**
 * Importador masivo de secadoras.
 */

class SecadoraBulkImporter
{
    private const SHEET_NAME = 'Secadoras';

    private PDO $db;
    private array $columns = [];
    private array $stats = [
        'total' => 0,
        'activos' => 0,
        'industrial' => 0,
        'artesanal' => 0,
        'solar' => 0,
    ];
    private array $allowedTypes = ['INDUSTRIAL', 'ARTESANAL', 'SOLAR'];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->columns = ConfigBulkImportUtils::getTableColumns($this->db, 'secadoras');
        $this->loadStats();
    }

    public function getTemplateFileName(): string
    {
        return 'plantilla_parametrizacion_masiva_secadoras.xlsx';
    }

    public function getTemplateSheets(): array
    {
        $typeRows = [['codigo', 'descripcion']];
        foreach ($this->allowedTypes as $type) {
            $typeRows[] = [$type, $type];
        }

        return [
            [
                'name' => self::SHEET_NAME,
                'rows' => [[
                    'numero',
                    'nombre',
                    'tipo',
                    'capacidad_qq',
                    'ubicacion',
                    'activo',
                ]],
            ],
            [
                'name' => 'Instrucciones',
                'rows' => [
                    ['campo', 'obligatorio', 'descripcion', 'ejemplo'],
                    ['numero', 'SI', 'Codigo o numero unico de la secadora.', 'SEC-01'],
                    ['nombre', 'NO', 'Nombre visible. Si se omite queda vacio.', 'Secadora principal'],
                    ['tipo', 'NO', 'Valores permitidos: INDUSTRIAL, ARTESANAL o SOLAR. Si se omite usa INDUSTRIAL.', 'INDUSTRIAL'],
                    ['capacidad_qq', 'NO', 'Capacidad en quintales.', '120'],
                    ['ubicacion', 'NO', 'Ubicacion o referencia fisica.', 'Patio norte'],
                    ['activo', 'NO', 'SI o NO. Si se omite se crea en SI.', 'SI'],
                ],
            ],
            [
                'name' => 'Tipos',
                'rows' => $typeRows,
            ],
        ];
    }

    public function getContextStats(): array
    {
        return [
            [
                'label' => 'Secadoras registradas',
                'value' => number_format((int) $this->stats['total']),
                'description' => 'Catalogo completo de secadoras.',
            ],
            [
                'label' => 'Secadoras activas',
                'value' => number_format((int) $this->stats['activos']),
                'description' => 'Disponibles para procesos nuevos.',
            ],
            [
                'label' => 'Tipos activos',
                'value' => number_format(
                    ((int) $this->stats['industrial'] > 0 ? 1 : 0) +
                    ((int) $this->stats['artesanal'] > 0 ? 1 : 0) +
                    ((int) $this->stats['solar'] > 0 ? 1 : 0)
                ),
                'description' => 'Industrial, artesanal y solar.',
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

                $created[] = $this->createSecadora($record, $rowEntry['row_number']);
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
            'numero', 'codigo', 'numero_secadora' => 'numero',
            'nombre', 'nombre_secadora' => 'nombre',
            'tipo', 'tipo_secadora' => 'tipo',
            'capacidad_qq', 'capacidad', 'capacidad_quintales' => 'capacidad_qq',
            'ubicacion', 'ubicacion_secadora' => 'ubicacion',
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
                COALESCE(SUM(tipo = 'INDUSTRIAL'), 0) AS industrial,
                COALESCE(SUM(tipo = 'ARTESANAL'), 0) AS artesanal,
                COALESCE(SUM(tipo = 'SOLAR'), 0) AS solar
             FROM secadoras"
        )->fetch(PDO::FETCH_ASSOC);

        if ($stats) {
            $this->stats = $stats;
        }
    }

    private function normalizeRow(array $rowValues, array $columnMap): array
    {
        $numero = strtoupper(trim(ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'numero')));
        $nombre = trim(ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'nombre'));
        $tipoRaw = ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'tipo');
        $tipo = $this->normalizeType($tipoRaw !== '' ? $tipoRaw : 'INDUSTRIAL');
        $capacidad = ConfigBulkImportUtils::parseDecimal(
            ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'capacidad_qq')
        );
        $ubicacion = trim(ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'ubicacion'));
        $activo = ConfigBulkImportUtils::parseYesNo(
            ConfigBulkImportUtils::getCellValue($rowValues, $columnMap, 'activo'),
            true
        );

        if ($numero === '') {
            throw new RuntimeException('El numero de la secadora es obligatorio.');
        }
        if (!in_array($tipo, $this->allowedTypes, true)) {
            throw new RuntimeException('El tipo de secadora no es valido.');
        }

        return [
            'numero' => $numero,
            'nombre' => $nombre !== '' ? $nombre : null,
            'tipo' => $tipo,
            'capacidad_qq' => $capacidad,
            'ubicacion' => $ubicacion !== '' ? $ubicacion : null,
            'activo' => $activo ?? 1,
        ];
    }

    private function normalizeType(string $value): string
    {
        return ConfigBulkImportUtils::normalizeKey($value);
    }

    private function findExistingDuplicate(array $record): ?string
    {
        $stmt = $this->db->prepare("SELECT nombre FROM secadoras WHERE numero = ? LIMIT 1");
        $stmt->execute([$record['numero']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            return 'Ya existe una secadora con el numero ' . $record['numero'] . '.';
        }

        return null;
    }

    private function createSecadora(array $record, int $rowNumber): array
    {
        $insertData = [
            'numero' => $record['numero'],
            'nombre' => $record['nombre'],
            'tipo' => $record['tipo'],
            'capacidad_qq' => $record['capacidad_qq'],
            'ubicacion' => $record['ubicacion'],
        ];

        if ($this->hasColumn('activo')) {
            $insertData['activo'] = $record['activo'];
        }

        $columns = array_keys($insertData);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $this->db->prepare(
            'INSERT INTO secadoras (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
        );

        try {
            $stmt->execute(array_values($insertData));
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo guardar la fila ' . $rowNumber . ': ' . $e->getMessage());
        }

        return [
            'row_number' => $rowNumber,
            'numero' => $record['numero'],
            'nombre' => $record['nombre'] ?? '-',
            'tipo' => $record['tipo'],
            'capacidad_qq' => $record['capacidad_qq'] !== null ? number_format((float) $record['capacidad_qq'], 2) : '-',
            'activo' => ConfigBulkImportUtils::yesNoLabel($record['activo']),
        ];
    }

    private function hasColumn(string $name): bool
    {
        return in_array($name, $this->columns, true);
    }
}
