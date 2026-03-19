<?php
/**
 * Lector y generador mínimo de archivos XLSX sin dependencias externas.
 */

class SimpleXlsx
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_PACKAGE_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const NS_DOCUMENT_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public static function readSheetRows(string $filePath, ?string $preferredSheetName = null): array
    {
        $zip = new ZipArchive();
        $openResult = $zip->open($filePath);
        if ($openResult !== true) {
            throw new RuntimeException('No se pudo abrir el archivo XLSX seleccionado.');
        }

        try {
            $sharedStrings = self::readSharedStrings($zip);
            $worksheetPath = self::resolveWorksheetPath($zip, $preferredSheetName);

            return self::readWorksheet($zip, $worksheetPath, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    public static function output(string $downloadName, array $sheets): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tempFile === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal para la descarga.');
        }

        $finalPath = $tempFile . '.xlsx';
        @unlink($finalPath);
        @rename($tempFile, $finalPath);

        try {
            self::save($finalPath, $sheets);

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . self::sanitizeDownloadName($downloadName) . '"');
            header('Content-Length: ' . (string) filesize($finalPath));
            header('Cache-Control: max-age=0');

            readfile($finalPath);
            exit;
        } finally {
            @unlink($finalPath);
        }
    }

    public static function save(string $filePath, array $sheets): void
    {
        if (empty($sheets)) {
            throw new RuntimeException('Debe definir al menos una hoja para generar el archivo XLSX.');
        }

        $preparedSheets = self::prepareSheets($sheets);

        $zip = new ZipArchive();
        $openResult = $zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            throw new RuntimeException('No se pudo generar el archivo XLSX.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', self::buildContentTypesXml(count($preparedSheets)));
            $zip->addFromString('_rels/.rels', self::buildRootRelationshipsXml());
            $zip->addFromString('xl/workbook.xml', self::buildWorkbookXml($preparedSheets));
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::buildWorkbookRelationshipsXml(count($preparedSheets)));

            foreach ($preparedSheets as $index => $sheet) {
                $zip->addFromString(
                    'xl/worksheets/sheet' . ($index + 1) . '.xml',
                    self::buildWorksheetXml($sheet['rows'])
                );
            }
        } finally {
            $zip->close();
        }
    }

    private static function prepareSheets(array $sheets): array
    {
        $prepared = [];
        $usedNames = [];

        foreach (array_values($sheets) as $index => $sheet) {
            $name = is_array($sheet) ? (string) ($sheet['name'] ?? '') : '';
            $rows = is_array($sheet) ? ($sheet['rows'] ?? []) : [];

            if (!is_array($rows)) {
                $rows = [];
            }

            $name = self::uniqueSheetName($name !== '' ? $name : 'Hoja' . ($index + 1), $usedNames);
            $usedNames[$name] = true;

            $prepared[] = [
                'name' => $name,
                'rows' => $rows,
            ];
        }

        return $prepared;
    }

    private static function uniqueSheetName(string $name, array $usedNames): string
    {
        $name = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', trim($name));
        $name = preg_replace('/\s+/', ' ', (string) $name);
        $name = trim($name);
        if ($name === '') {
            $name = 'Hoja';
        }

        $baseName = mb_substr($name, 0, 31);
        $candidate = $baseName;
        $counter = 2;

        while (isset($usedNames[$candidate])) {
            $suffix = ' ' . $counter;
            $candidate = mb_substr($baseName, 0, max(1, 31 - mb_strlen($suffix))) . $suffix;
            $counter++;
        }

        return $candidate;
    }

    private static function buildContentTypesXml(int $sheetCount): string
    {
        $overrides = [
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
        ];

        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides[] = '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . implode('', $overrides)
            . '</Types>';
    }

    private static function buildRootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="' . self::NS_PACKAGE_REL . '">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function buildWorkbookXml(array $preparedSheets): string
    {
        $sheetsXml = [];

        foreach ($preparedSheets as $index => $sheet) {
            $sheetsXml[] = '<sheet name="' . self::xml((string) $sheet['name']) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_DOCUMENT_REL . '">'
            . '<sheets>' . implode('', $sheetsXml) . '</sheets>'
            . '</workbook>';
    }

    private static function buildWorkbookRelationshipsXml(int $sheetCount): string
    {
        $relationships = [];

        for ($i = 1; $i <= $sheetCount; $i++) {
            $relationships[] = '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="' . self::NS_PACKAGE_REL . '">'
            . implode('', $relationships)
            . '</Relationships>';
    }

    private static function buildWorksheetXml(array $rows): string
    {
        $rowsXml = [];

        foreach (array_values($rows) as $rowIndex => $row) {
            if (!is_array($row)) {
                $row = [$row];
            }

            $cellsXml = [];
            foreach (array_values($row) as $columnIndex => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $cellReference = self::columnLetter($columnIndex + 1) . ($rowIndex + 1);

                if (is_int($value) || is_float($value)) {
                    $cellsXml[] = '<c r="' . $cellReference . '"><v>' . self::xml((string) $value) . '</v></c>';
                    continue;
                }

                $stringValue = (string) $value;
                $preserveSpaces = preg_match('/^\s|\s$|\n/', $stringValue) === 1;
                $textAttributes = $preserveSpaces ? ' xml:space="preserve"' : '';

                $cellsXml[] = '<c r="' . $cellReference . '" t="inlineStr"><is><t' . $textAttributes . '>'
                    . self::xml($stringValue)
                    . '</t></is></c>';
            }

            $rowsXml[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cellsXml) . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="' . self::NS_MAIN . '">'
            . '<sheetData>' . implode('', $rowsXml) . '</sheetData>'
            . '</worksheet>';
    }

    private static function columnLetter(int $number): string
    {
        $letter = '';

        while ($number > 0) {
            $number--;
            $letter = chr(65 + ($number % 26)) . $letter;
            $number = intdiv($number, 26);
        }

        return $letter;
    }

    private static function readSharedStrings(ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if ($content === false) {
            return [];
        }

        $xml = self::loadXml($content);
        $xml->registerXPathNamespace('a', self::NS_MAIN);

        $strings = [];
        foreach ($xml->xpath('/a:sst/a:si') ?: [] as $stringNode) {
            $strings[] = self::collectText($stringNode, './/a:t');
        }

        return $strings;
    }

    private static function resolveWorksheetPath(ZipArchive $zip, ?string $preferredSheetName = null): string
    {
        $workbookContent = $zip->getFromName('xl/workbook.xml');
        $relationsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookContent === false || $relationsContent === false) {
            throw new RuntimeException('El archivo XLSX no contiene la estructura esperada.');
        }

        $workbook = self::loadXml($workbookContent);
        $relations = self::loadXml($relationsContent);

        $workbook->registerXPathNamespace('a', self::NS_MAIN);
        $relations->registerXPathNamespace('r', self::NS_PACKAGE_REL);

        $targets = [];
        foreach ($relations->xpath('/r:Relationships/r:Relationship') ?: [] as $relationshipNode) {
            $attributes = $relationshipNode->attributes();
            $id = (string) ($attributes['Id'] ?? '');
            $target = (string) ($attributes['Target'] ?? '');
            if ($id === '' || $target === '') {
                continue;
            }

            if (str_starts_with($target, '/')) {
                $targets[$id] = self::normalizeZipPath(ltrim($target, '/'));
            } elseif (str_starts_with($target, 'xl/')) {
                $targets[$id] = self::normalizeZipPath($target);
            } else {
                $targets[$id] = self::normalizeZipPath('xl/' . $target);
            }
        }

        $preferredKey = self::normalizeSheetLookupName((string) $preferredSheetName);
        $fallback = null;

        foreach ($workbook->xpath('/a:workbook/a:sheets/a:sheet') ?: [] as $sheetNode) {
            $sheetName = (string) ($sheetNode['name'] ?? '');
            $relAttributes = $sheetNode->attributes(self::NS_DOCUMENT_REL);
            $relationshipId = (string) ($relAttributes['id'] ?? '');

            if ($relationshipId === '' || !isset($targets[$relationshipId])) {
                continue;
            }

            $targetPath = $targets[$relationshipId];
            if ($fallback === null) {
                $fallback = $targetPath;
            }

            if ($preferredKey !== '' && self::normalizeSheetLookupName($sheetName) === $preferredKey) {
                return $targetPath;
            }
        }

        if ($fallback === null) {
            throw new RuntimeException('No se encontró ninguna hoja utilizable dentro del archivo XLSX.');
        }

        return $fallback;
    }

    private static function normalizeSheetLookupName(string $value): string
    {
        return mb_strtoupper(trim($value));
    }

    private static function normalizeZipPath(string $path): string
    {
        $segments = explode('/', str_replace('\\', '/', $path));
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }

            $normalized[] = $segment;
        }

        return implode('/', $normalized);
    }

    private static function readWorksheet(ZipArchive $zip, string $worksheetPath, array $sharedStrings): array
    {
        $content = $zip->getFromName($worksheetPath);
        if ($content === false) {
            throw new RuntimeException('No se pudo leer la hoja principal del archivo XLSX.');
        }

        $xml = self::loadXml($content);
        $xml->registerXPathNamespace('a', self::NS_MAIN);

        $rows = [];
        foreach ($xml->xpath('/a:worksheet/a:sheetData/a:row') ?: [] as $rowNode) {
            $rowNode->registerXPathNamespace('a', self::NS_MAIN);
            $rowValuesByIndex = [];
            $nextColumn = 0;

            foreach ($rowNode->xpath('./a:c') ?: [] as $cellNode) {
                $attributes = $cellNode->attributes();
                $reference = trim((string) ($attributes['r'] ?? ''));
                $columnIndex = $reference !== '' ? self::columnIndexFromReference($reference) : $nextColumn;
                if ($columnIndex < 0) {
                    $columnIndex = $nextColumn;
                }

                $rowValuesByIndex[$columnIndex] = self::readCellValue($cellNode, $sharedStrings);
                $nextColumn = $columnIndex + 1;
            }

            if (empty($rowValuesByIndex)) {
                $rows[] = [];
                continue;
            }

            ksort($rowValuesByIndex);
            $lastColumn = max(array_keys($rowValuesByIndex));
            $rowValues = [];
            for ($i = 0; $i <= $lastColumn; $i++) {
                $rowValues[] = (string) ($rowValuesByIndex[$i] ?? '');
            }

            $rows[] = $rowValues;
        }

        return $rows;
    }

    private static function readCellValue(SimpleXMLElement $cellNode, array $sharedStrings): string
    {
        $attributes = $cellNode->attributes();
        $type = (string) ($attributes['t'] ?? '');

        if ($type === 'inlineStr') {
            return self::collectText($cellNode, './/a:t');
        }

        $cellNode->registerXPathNamespace('a', self::NS_MAIN);
        $valueNodes = $cellNode->xpath('./a:v');
        $value = isset($valueNodes[0]) ? (string) $valueNodes[0] : '';

        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }

        if ($type === 'str') {
            return $value;
        }

        if ($type === 'b') {
            return $value === '1' ? '1' : '0';
        }

        return $value;
    }

    private static function collectText(SimpleXMLElement $node, string $xpath): string
    {
        $node->registerXPathNamespace('a', self::NS_MAIN);
        $texts = $node->xpath($xpath) ?: [];
        $result = '';

        foreach ($texts as $textNode) {
            $result .= (string) $textNode;
        }

        return $result;
    }

    private static function columnIndexFromReference(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)\d+$/i', $reference, $matches)) {
            return -1;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;

        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    private static function loadXml(string $content): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_use_internal_errors($previous);

        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException('No se pudo interpretar el contenido del archivo XLSX.');
        }

        return $xml;
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function sanitizeDownloadName(string $downloadName): string
    {
        $downloadName = trim($downloadName);
        if ($downloadName === '') {
            $downloadName = 'archivo.xlsx';
        }

        if (!str_ends_with(strtolower($downloadName), '.xlsx')) {
            $downloadName .= '.xlsx';
        }

        return preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
    }
}
