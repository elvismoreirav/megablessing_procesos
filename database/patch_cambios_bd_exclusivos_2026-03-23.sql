-- ============================================================================
-- PATCH CONSOLIDADO: Cambios exclusivos de base de datos
-- Fecha: 2026-03-23
--
-- Incluye unicamente cambios estructurales y de datos aplicables en BD:
-- 1) fichas_registro.proveedor_ruta -> TEXT
-- 2) fermentacion_control_diario -> hora_am, volteo_am, hora_pm, volteo_pm
-- 3) parametros_proceso FERMENTACION temp_min/temp_max -> 35/50
-- 4) registros_fermentacion.peso_final -> DECIMAL(10,2)
-- ============================================================================

SET @db_name = DATABASE();

-- --------------------------------------------------------------------------
-- 1) fichas_registro.proveedor_ruta
-- --------------------------------------------------------------------------
SET @sql = (
    SELECT CASE
        WHEN COUNT(*) = 0 THEN
            'ALTER TABLE fichas_registro ADD COLUMN proveedor_ruta TEXT NULL AFTER codificacion'
        WHEN MAX(DATA_TYPE) <> 'text' THEN
            'ALTER TABLE fichas_registro MODIFY COLUMN proveedor_ruta TEXT NULL'
        ELSE
            'SELECT ''fichas_registro.proveedor_ruta ya esta en TEXT'' AS info'
    END
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fichas_registro'
      AND COLUMN_NAME = 'proveedor_ruta'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------------
-- 2) fermentacion_control_diario: 2 mediciones por dia
-- --------------------------------------------------------------------------
SET @table_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fermentacion_control_diario'
);

SET @sql = IF(
    @table_exists = 0,
    'SELECT ''fermentacion_control_diario no existe'' AS info',
    (
        SELECT IF(
            COUNT(*) = 0,
            'ALTER TABLE fermentacion_control_diario ADD COLUMN hora_am TIME NULL AFTER dia',
            'SELECT ''fermentacion_control_diario.hora_am ya existe'' AS info'
        )
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'fermentacion_control_diario'
          AND COLUMN_NAME = 'hora_am'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @table_exists = 0,
    'SELECT ''fermentacion_control_diario no existe'' AS info',
    (
        SELECT IF(
            COUNT(*) = 0,
            'ALTER TABLE fermentacion_control_diario ADD COLUMN volteo_am TINYINT(1) NULL DEFAULT NULL AFTER hora_am',
            'SELECT ''fermentacion_control_diario.volteo_am ya existe'' AS info'
        )
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'fermentacion_control_diario'
          AND COLUMN_NAME = 'volteo_am'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @table_exists = 0,
    'SELECT ''fermentacion_control_diario no existe'' AS info',
    (
        SELECT IF(
            COUNT(*) = 0,
            'ALTER TABLE fermentacion_control_diario ADD COLUMN hora_pm TIME NULL AFTER volteo_am',
            'SELECT ''fermentacion_control_diario.hora_pm ya existe'' AS info'
        )
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'fermentacion_control_diario'
          AND COLUMN_NAME = 'hora_pm'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @table_exists = 0,
    'SELECT ''fermentacion_control_diario no existe'' AS info',
    (
        SELECT IF(
            COUNT(*) = 0,
            'ALTER TABLE fermentacion_control_diario ADD COLUMN volteo_pm TINYINT(1) NULL DEFAULT NULL AFTER hora_pm',
            'SELECT ''fermentacion_control_diario.volteo_pm ya existe'' AS info'
        )
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'fermentacion_control_diario'
          AND COLUMN_NAME = 'volteo_pm'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hora_am_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fermentacion_control_diario'
      AND COLUMN_NAME = 'hora_am'
);
SET @hora_pm_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fermentacion_control_diario'
      AND COLUMN_NAME = 'hora_pm'
);
SET @volteo_am_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fermentacion_control_diario'
      AND COLUMN_NAME = 'volteo_am'
);
SET @volteo_pm_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fermentacion_control_diario'
      AND COLUMN_NAME = 'volteo_pm'
);
SET @hora_legacy_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fermentacion_control_diario'
      AND COLUMN_NAME = 'hora'
);
SET @volteo_legacy_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fermentacion_control_diario'
      AND COLUMN_NAME = 'volteo'
);

SET @sql = IF(
    @table_exists = 0
    OR @hora_am_exists = 0
    OR @hora_pm_exists = 0
    OR @volteo_am_exists = 0
    OR @volteo_pm_exists = 0
    OR @hora_legacy_exists = 0
    OR @volteo_legacy_exists = 0,
    'SELECT ''sin migracion de datos legacy para fermentacion_control_diario'' AS info',
    "UPDATE fermentacion_control_diario
     SET
         hora_am = CASE
             WHEN hora_am IS NULL AND hora IS NOT NULL AND TIME(hora) < '12:00:00' THEN hora
             ELSE hora_am
         END,
         hora_pm = CASE
             WHEN hora_pm IS NULL AND hora IS NOT NULL AND TIME(hora) >= '12:00:00' THEN hora
             ELSE hora_pm
         END,
         volteo_am = CASE
             WHEN volteo_am IS NULL AND volteo = 1 AND hora IS NOT NULL AND TIME(hora) < '12:00:00' THEN 1
             ELSE volteo_am
         END,
         volteo_pm = CASE
             WHEN volteo_pm IS NULL AND volteo = 1 AND (hora IS NULL OR TIME(hora) >= '12:00:00') THEN 1
             ELSE volteo_pm
         END"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------------
-- 3) parametros_proceso FERMENTACION temp_min/temp_max
-- --------------------------------------------------------------------------
INSERT INTO parametros_proceso (categoria, clave, valor, tipo, descripcion)
VALUES ('FERMENTACION', 'temp_min', '35', 'NUMBER', 'Temperatura minima esperada (C)')
ON DUPLICATE KEY UPDATE
    valor = VALUES(valor),
    tipo = VALUES(tipo),
    descripcion = VALUES(descripcion);

INSERT INTO parametros_proceso (categoria, clave, valor, tipo, descripcion)
VALUES ('FERMENTACION', 'temp_max', '50', 'NUMBER', 'Temperatura maxima esperada (C)')
ON DUPLICATE KEY UPDATE
    valor = VALUES(valor),
    tipo = VALUES(tipo),
    descripcion = VALUES(descripcion);

-- --------------------------------------------------------------------------
-- 4) registros_fermentacion.peso_final
-- --------------------------------------------------------------------------
SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'registros_fermentacion'
        ) THEN "SELECT 'registros_fermentacion no existe' AS info"
        WHEN EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'registros_fermentacion'
              AND COLUMN_NAME = 'peso_final'
        ) THEN "SELECT 'registros_fermentacion.peso_final ya existe' AS info"
        WHEN EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'registros_fermentacion'
              AND COLUMN_NAME = 'unidad_peso'
        ) THEN "ALTER TABLE registros_fermentacion ADD COLUMN peso_final DECIMAL(10,2) NULL AFTER unidad_peso"
        WHEN EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'registros_fermentacion'
              AND COLUMN_NAME = 'peso_lote_kg'
        ) THEN "ALTER TABLE registros_fermentacion ADD COLUMN peso_final DECIMAL(10,2) NULL AFTER peso_lote_kg"
        ELSE "ALTER TABLE registros_fermentacion ADD COLUMN peso_final DECIMAL(10,2) NULL"
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------------
-- Verificacion rapida
-- --------------------------------------------------------------------------
SHOW COLUMNS FROM fichas_registro LIKE 'proveedor_ruta';
SHOW COLUMNS FROM fermentacion_control_diario LIKE 'hora_am';
SHOW COLUMNS FROM fermentacion_control_diario LIKE 'volteo_am';
SHOW COLUMNS FROM fermentacion_control_diario LIKE 'hora_pm';
SHOW COLUMNS FROM fermentacion_control_diario LIKE 'volteo_pm';
SELECT categoria, clave, valor
FROM parametros_proceso
WHERE categoria = 'FERMENTACION'
  AND clave IN ('temp_min', 'temp_max')
ORDER BY clave;
SHOW COLUMNS FROM registros_fermentacion LIKE 'peso_final';
