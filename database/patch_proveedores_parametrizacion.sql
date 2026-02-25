-- ============================================================================
-- PATCH: Parametrizacion de Proveedores
-- Agrega campos para:
-- - Cedula/RUC
-- - Codigo de identificacion (formato PRO-00001)
-- - Correo electronico
-- - Categoria del proveedor
-- - Bandera de categoria base (es_categoria)
-- ============================================================================

SET @db_name = DATABASE();

-- Ajustar longitud de codigo interno (si usa codigos largos)
ALTER TABLE proveedores MODIFY COLUMN codigo VARCHAR(20) NOT NULL;

-- codigo_identificacion
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN codigo_identificacion VARCHAR(20) NULL AFTER codigo',
        'SELECT ''codigo_identificacion ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'codigo_identificacion'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- es_categoria
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN es_categoria TINYINT(1) NOT NULL DEFAULT 0 AFTER categoria',
        'SELECT ''es_categoria ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'es_categoria'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- cedula_ruc
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN cedula_ruc VARCHAR(20) NULL AFTER nombre',
        'SELECT ''cedula_ruc ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'cedula_ruc'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- categoria
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN categoria VARCHAR(100) NULL AFTER tipo',
        'SELECT ''categoria ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'categoria'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- email
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN email VARCHAR(120) NULL AFTER telefono',
        'SELECT ''email ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'email'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- tipos_permitidos
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN tipos_permitidos VARCHAR(120) NULL AFTER categoria',
        'SELECT ''tipos_permitidos ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'tipos_permitidos'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- utm_este_x
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN utm_este_x VARCHAR(50) NULL AFTER direccion',
        'SELECT ''utm_este_x ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'utm_este_x'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- utm_norte_y
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN utm_norte_y VARCHAR(50) NULL AFTER utm_este_x',
        'SELECT ''utm_norte_y ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'utm_norte_y'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- seguridad_deforestacion
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN seguridad_deforestacion TINYINT(1) NULL AFTER utm_norte_y',
        'SELECT ''seguridad_deforestacion ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'seguridad_deforestacion'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- arboles_endemicos
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN arboles_endemicos TINYINT(1) NULL AFTER seguridad_deforestacion',
        'SELECT ''arboles_endemicos ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'arboles_endemicos'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- hectareas_totales
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN hectareas_totales DECIMAL(10,2) NULL AFTER arboles_endemicos',
        'SELECT ''hectareas_totales ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'hectareas_totales'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- hectareas_ccn51
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN hectareas_ccn51 DECIMAL(10,2) NULL AFTER hectareas_totales',
        'SELECT ''hectareas_ccn51 ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'hectareas_ccn51'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- hectareas_fino_aroma
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN hectareas_fino_aroma DECIMAL(10,2) NULL AFTER hectareas_ccn51',
        'SELECT ''hectareas_fino_aroma ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'hectareas_fino_aroma'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- certificaciones
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN certificaciones TEXT NULL AFTER hectareas_fino_aroma',
        'SELECT ''certificaciones ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'certificaciones'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- certificacion_otras
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN certificacion_otras VARCHAR(255) NULL AFTER certificaciones',
        'SELECT ''certificacion_otras ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'certificacion_otras'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- documento_certificaciones
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN documento_certificaciones VARCHAR(255) NULL AFTER certificacion_otras',
        'SELECT ''documento_certificaciones ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'documento_certificaciones'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Sugerencia de categorias por defecto para registros existentes
UPDATE proveedores
SET categoria = CASE
    WHEN tipo = 'MERCADO' THEN 'MERCADO'
    WHEN tipo = 'BODEGA' THEN 'BODEGA'
    WHEN tipo = 'PRODUCTOR' THEN 'PRODUCTOR'
    WHEN tipo = 'RUTA' AND UPPER(nombre) LIKE '%ESMERALDAS%' THEN 'ESMERALDAS'
    WHEN tipo = 'RUTA' AND (UPPER(nombre) LIKE '%FLOR%' OR UPPER(nombre) LIKE '%MANABI%') THEN 'FLOR DE MANABI'
    WHEN tipo = 'RUTA' AND (UPPER(nombre) LIKE '%PEDERNALES%' OR UPPER(nombre) LIKE '%VIA%') THEN 'VIA PEDERNALES'
    WHEN tipo = 'RUTA' THEN 'ESMERALDAS'
    ELSE categoria
END
WHERE categoria IS NULL OR categoria = '';

-- Marcar categorias base ya existentes
UPDATE proveedores
SET es_categoria = 1
WHERE UPPER(codigo) IN ('M','B','ES','FM','VP');

-- Completar tipos_permitidos para categorias existentes
UPDATE proveedores
SET tipos_permitidos = UPPER(tipo)
WHERE es_categoria = 1
  AND (tipos_permitidos IS NULL OR TRIM(tipos_permitidos) = '');
