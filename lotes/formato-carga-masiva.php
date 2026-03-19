<?php
/**
 * Descarga de plantilla para carga masiva de lotes.
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$importer = new LoteBulkImporter();

SimpleXlsx::output(
    $importer->getTemplateFileName(),
    $importer->getTemplateSheets()
);
