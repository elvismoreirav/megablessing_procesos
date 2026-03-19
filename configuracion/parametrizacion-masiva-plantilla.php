<?php
/**
 * Descarga de plantillas XLSX para parametrizacion masiva.
 */

require_once __DIR__ . '/../bootstrap.php';

requireAuth();
if (!ConfigBulkImportRegistry::canAccessAnyModule()) {
    setFlash('danger', 'No tiene permisos para acceder a esta seccion.');
    redirect('/dashboard.php');
}

$moduleKey = trim((string) ($_GET['modulo'] ?? ''));
if ($moduleKey === '' || !ConfigBulkImportRegistry::isModuleAllowed($moduleKey)) {
    setFlash('warning', 'El modulo solicitado no esta disponible para su perfil.');
    redirect('/configuracion/parametrizacion-masiva.php');
}

$importer = ConfigBulkImportRegistry::createImporter($moduleKey);

SimpleXlsx::output(
    $importer->getTemplateFileName(),
    $importer->getTemplateSheets()
);
