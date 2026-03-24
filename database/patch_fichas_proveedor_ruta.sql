-- ============================================================================
-- PATCH: Ampliar proveedor_ruta en fichas_registro
-- Evita errores "Data too long" cuando la ficha guarda múltiples proveedores
-- y la ruta de entrega en un mismo campo compuesto.
-- ============================================================================

SET @db_name = DATABASE();

SET @sql = (
    SELECT CASE
        WHEN COUNT(*) = 0 THEN
            'ALTER TABLE fichas_registro ADD COLUMN proveedor_ruta TEXT NULL AFTER codificacion'
        WHEN MAX(DATA_TYPE) <> 'text' THEN
            'ALTER TABLE fichas_registro MODIFY COLUMN proveedor_ruta TEXT NULL'
        ELSE
            'SELECT ''proveedor_ruta ya tiene capacidad suficiente'' AS info'
    END
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fichas_registro'
      AND COLUMN_NAME = 'proveedor_ruta'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
