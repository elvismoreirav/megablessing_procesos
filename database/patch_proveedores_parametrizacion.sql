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
