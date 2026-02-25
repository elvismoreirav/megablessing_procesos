-- ============================================================================
-- PATCH: Registro de Pagos en Fichas
-- Agrega campos para registrar:
-- - fecha de pago
-- - factura asignada
-- - cantidad comprada
-- - forma de pago
-- ============================================================================

SET @db_name = DATABASE();

-- fecha_pago
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE fichas_registro ADD COLUMN fecha_pago DATE NULL AFTER precio_total_pagar',
        'SELECT ''fecha_pago ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fichas_registro'
      AND COLUMN_NAME = 'fecha_pago'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- tipo_comprobante
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE fichas_registro ADD COLUMN tipo_comprobante ENUM(''FACTURA'',''NOTA_COMPRA'') NULL AFTER fecha_pago',
        'SELECT ''tipo_comprobante ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fichas_registro'
      AND COLUMN_NAME = 'tipo_comprobante'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- factura_compra
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE fichas_registro ADD COLUMN factura_compra VARCHAR(80) NULL AFTER fecha_pago',
        'SELECT ''factura_compra ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fichas_registro'
      AND COLUMN_NAME = 'factura_compra'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- cantidad_comprada_unidad
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE fichas_registro ADD COLUMN cantidad_comprada_unidad ENUM(''LB'',''KG'',''QQ'') NOT NULL DEFAULT ''KG'' AFTER factura_compra',
        'SELECT ''cantidad_comprada_unidad ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fichas_registro'
      AND COLUMN_NAME = 'cantidad_comprada_unidad'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- cantidad_comprada
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE fichas_registro ADD COLUMN cantidad_comprada DECIMAL(10,2) NULL AFTER factura_compra',
        'SELECT ''cantidad_comprada ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fichas_registro'
      AND COLUMN_NAME = 'cantidad_comprada'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ampliar enum calidad_registro para incluir ESCURRIDO
ALTER TABLE fichas_registro
MODIFY COLUMN calidad_registro ENUM('SECO','SEMISECO','ESCURRIDO','BABA') NULL;

-- forma_pago
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE fichas_registro ADD COLUMN forma_pago ENUM(''EFECTIVO'',''TRANSFERENCIA'',''CHEQUE'',''OTROS'') NULL AFTER cantidad_comprada',
        'SELECT ''forma_pago ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fichas_registro'
      AND COLUMN_NAME = 'forma_pago'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
