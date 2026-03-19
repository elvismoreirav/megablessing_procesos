<?php
/**
 * Utilidades compartidas para importadores masivos de configuracion.
 */

class ConfigBulkImportUtils
{
    public static function getTableColumns(PDO $db, string $table): array
    {
        return array_column(
            $db->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );
    }

    public static function prepareRows(
        array $sheetRows,
        string $sheetName,
        callable $canonicalHeaderResolver,
        array $requiredHeaders
    ): array {
        $headerRowIndex = null;
        $headerValues = [];

        foreach ($sheetRows as $index => $rowValues) {
            if (self::isEmptyRow($rowValues)) {
                continue;
            }

            $headerRowIndex = $index;
            $headerValues = $rowValues;
            break;
        }

        if ($headerRowIndex === null) {
            throw new RuntimeException('La hoja "' . $sheetName . '" no contiene encabezados.');
        }

        $columnMap = [];
        foreach ($headerValues as $index => $headerValue) {
            $canonical = (string) $canonicalHeaderResolver($headerValue);
            if ($canonical === '') {
                continue;
            }

            if (!isset($columnMap[$canonical])) {
                $columnMap[$canonical] = $index;
            }
        }

        $missingHeaders = array_values(array_filter(
            $requiredHeaders,
            static fn(string $header): bool => !isset($columnMap[$header])
        ));

        if (!empty($missingHeaders)) {
            throw new RuntimeException(
                'Faltan columnas requeridas en la hoja "' . $sheetName . '": ' . implode(', ', $missingHeaders) . '.'
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
            throw new RuntimeException('La hoja "' . $sheetName . '" no contiene filas para importar.');
        }

        return [
            'header_row_number' => $headerRowIndex + 1,
            'column_map' => $columnMap,
            'rows' => $rows,
        ];
    }

    public static function headerToken($headerValue): string
    {
        $normalized = strtolower(self::normalizeKey((string) $headerValue));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);

        return trim((string) $normalized, '_');
    }

    public static function getCellValue(array $rowValues, array $columnMap, string $field): string
    {
        $index = $columnMap[$field] ?? null;
        if ($index === null) {
            return '';
        }

        return trim((string) ($rowValues[$index] ?? ''));
    }

    public static function isEmptyRow(array $rowValues): bool
    {
        foreach ($rowValues as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    public static function parseDecimal(string $value): ?float
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

    public static function parseYesNo(string $value, bool $defaultYes = false): ?int
    {
        $value = self::normalizeKey($value);
        if ($value === '') {
            return $defaultYes ? 1 : null;
        }

        return match ($value) {
            'SI', 'S', 'YES', 'Y', '1', 'TRUE' => 1,
            'NO', 'N', '0', 'FALSE' => 0,
            default => throw new RuntimeException('Solo se admiten valores SI o NO en los campos booleanos.'),
        };
    }

    public static function normalizeKey(string $value): string
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

    public static function yesNoLabel($value): string
    {
        return (int) $value === 1 ? 'SI' : 'NO';
    }
}
