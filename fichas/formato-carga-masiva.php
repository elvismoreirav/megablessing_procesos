<?php
/**
 * Descarga de plantilla para carga masiva de recepcion.
 */

require_once __DIR__ . '/../bootstrap.php';
requireAuth();

$importer = new RecepcionBulkImporter();

SimpleXlsx::output(
    $importer->getTemplateFileName(),
    $importer->getTemplateSheets()
);
