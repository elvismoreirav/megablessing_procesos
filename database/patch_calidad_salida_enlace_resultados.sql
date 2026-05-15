-- ============================================================================
-- PATCH: Enlace de analisis y resultados en calidad de salida
-- Fecha: 2026-05-14
--
-- Agrega un campo para almacenar el enlace de Drive u otro repositorio
-- externo donde se guardan los analisis y resultados del lote.
-- ============================================================================

SET @db_name = DATABASE();

SET @has_column = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'registros_calidad_salida'
      AND COLUMN_NAME = 'enlace_resultados_drive'
);

SET @sql = IF(
    @has_column = 0,
    'ALTER TABLE registros_calidad_salida ADD COLUMN enlace_resultados_drive VARCHAR(500) NULL AFTER observaciones',
    'SELECT ''La columna enlace_resultados_drive ya existe en registros_calidad_salida'' AS mensaje'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SHOW COLUMNS FROM registros_calidad_salida LIKE 'enlace_resultados_drive';
