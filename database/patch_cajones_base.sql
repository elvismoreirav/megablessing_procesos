-- ============================================================================
-- PATCH: Catálogo base de cajones de fermentación
-- Crea/asegura 6 cajones base y el parámetro configurable de objetivo.
-- ============================================================================

SET @db_name = DATABASE();

CREATE TABLE IF NOT EXISTS `cajones_fermentacion` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `numero` VARCHAR(20) NOT NULL UNIQUE,
    `capacidad_kg` DECIMAL(10,2),
    `material` VARCHAR(50),
    `ubicacion` VARCHAR(100),
    `activo` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `cajones_fermentacion` (`numero`, `capacidad_kg`, `material`, `activo`) VALUES
('CAJ-01', 500, 'Madera', 1),
('CAJ-02', 500, 'Madera', 1),
('CAJ-03', 500, 'Madera', 1),
('CAJ-04', 500, 'Madera', 1),
('CAJ-05', 500, 'Madera', 1),
('CAJ-06', 500, 'Madera', 1)
ON DUPLICATE KEY UPDATE
    capacidad_kg = COALESCE(cajones_fermentacion.capacidad_kg, VALUES(capacidad_kg)),
    material = COALESCE(cajones_fermentacion.material, VALUES(material)),
    activo = COALESCE(cajones_fermentacion.activo, VALUES(activo));

SET @parametros_table_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'parametros_proceso'
);

SET @sql = IF(
    @parametros_table_exists = 0,
    "SELECT 'parametros_proceso no existe' AS info",
    (
        SELECT IF(
            COUNT(*) = 0,
            'INSERT INTO parametros_proceso (categoria, clave, valor, tipo, descripcion, editable) VALUES (''GENERAL'', ''cajones_fermentacion_objetivo'', ''6'', ''NUMBER'', ''Cantidad objetivo de cajones de fermentación activos'', 1)',
            'UPDATE parametros_proceso SET valor = COALESCE(NULLIF(valor, ''''), ''6'') WHERE categoria = ''GENERAL'' AND clave = ''cajones_fermentacion_objetivo'''
        )
        FROM parametros_proceso
        WHERE categoria = 'GENERAL'
          AND clave = 'cajones_fermentacion_objetivo'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_nombre_col = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'cajones_fermentacion'
      AND COLUMN_NAME = 'nombre'
);

SET @sql = IF(
    @has_nombre_col > 0,
    "UPDATE cajones_fermentacion
     SET nombre = CASE numero
         WHEN 'CAJ-01' THEN 'Cajón 1'
         WHEN 'CAJ-02' THEN 'Cajón 2'
         WHEN 'CAJ-03' THEN 'Cajón 3'
         WHEN 'CAJ-04' THEN 'Cajón 4'
         WHEN 'CAJ-05' THEN 'Cajón 5'
         WHEN 'CAJ-06' THEN 'Cajón 6'
         ELSE nombre
     END
     WHERE (nombre IS NULL OR TRIM(nombre) = '')
       AND numero IN ('CAJ-01','CAJ-02','CAJ-03','CAJ-04','CAJ-05','CAJ-06')",
    "SELECT 'nombre no existe en cajones_fermentacion' AS info"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
