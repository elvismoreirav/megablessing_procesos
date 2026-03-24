-- ============================================================================
-- PATCH: Control diario de fermentacion con 2 mediciones por dia
-- Agrega hora/volteo para manana y tarde y actualiza el rango objetivo
-- de temperatura de fermentacion a 35-50 C.
-- ============================================================================

SET @db_name = DATABASE();

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
            'SELECT ''hora_am ya existe'' AS info'
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
            'SELECT ''volteo_am ya existe'' AS info'
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
            'SELECT ''hora_pm ya existe'' AS info'
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
            'SELECT ''volteo_pm ya existe'' AS info'
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
    @table_exists = 0 OR @hora_am_exists = 0 OR @hora_pm_exists = 0 OR @volteo_am_exists = 0 OR @volteo_pm_exists = 0 OR @hora_legacy_exists = 0 OR @volteo_legacy_exists = 0,
    'SELECT ''sin migracion de datos legacy'' AS info',
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

UPDATE parametros_proceso
SET valor = '35'
WHERE categoria = 'FERMENTACION' AND clave = 'temp_min';

UPDATE parametros_proceso
SET valor = '50'
WHERE categoria = 'FERMENTACION' AND clave = 'temp_max';
