-- ============================================================================
-- PATCH: Columna peso_final en registros_fermentacion
-- Fecha: 2026-03-23
-- Asegura que el cierre de fermentacion pueda guardar el peso final real
-- para que secado tome ese valor como peso inicial de referencia.
-- ============================================================================

SET @db_name = DATABASE();

-- --------------------------------------------------------------------------
-- 1) Agregar registros_fermentacion.peso_final
-- --------------------------------------------------------------------------
SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = @db_name
              AND table_name = 'registros_fermentacion'
        ) THEN "SELECT 'registros_fermentacion no existe' AS info"
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_fermentacion'
              AND column_name = 'peso_final'
        ) THEN "SELECT 'registros_fermentacion.peso_final ya existe' AS info"
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_fermentacion'
              AND column_name = 'unidad_peso'
        ) THEN "ALTER TABLE registros_fermentacion ADD COLUMN peso_final DECIMAL(10,2) NULL AFTER unidad_peso"
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_fermentacion'
              AND column_name = 'peso_lote_kg'
        ) THEN "ALTER TABLE registros_fermentacion ADD COLUMN peso_final DECIMAL(10,2) NULL AFTER peso_lote_kg"
        ELSE "ALTER TABLE registros_fermentacion ADD COLUMN peso_final DECIMAL(10,2) NULL"
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------------
-- 2) Confirmacion visual
-- --------------------------------------------------------------------------
SHOW COLUMNS FROM registros_fermentacion LIKE 'peso_final';
