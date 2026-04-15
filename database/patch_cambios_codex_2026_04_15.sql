-- ============================================================================
-- PATCH CONSOLIDADO CODEX - Cambios solicitados 2026-04-15
-- Proyecto: Megablessing Procesos
-- Ejecutar sobre la base megablessing_procesos.
-- ============================================================================

SET @db_name = DATABASE();

-- --------------------------------------------------------------------------
-- 1) Roles: gestión de usuarios solo para Administrador.
--    Quita acceso de usuarios/configuración heredado en supervisores.
-- --------------------------------------------------------------------------
UPDATE roles
SET
    descripcion = 'Supervisa los procesos operativos de planta.',
    permisos = '{"lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true, "configuracion_panel": true, "configuracion_variedades": true, "configuracion_cajones": true, "configuracion_secadoras": true}'
WHERE LOWER(nombre) = 'supervisor planta';

UPDATE roles
SET
    descripcion = 'Supervisa recepción y abastecimiento del centro de acopio.',
    permisos = '{"recepcion": true, "codificacion": true, "etiqueta": true, "proveedores": true, "configuracion_panel": true, "configuracion_variedades": true}'
WHERE LOWER(nombre) = 'supervisor centro de acopio';

-- --------------------------------------------------------------------------
-- 2) Registro de pagos: fuente de pago y formas de pago múltiples.
-- --------------------------------------------------------------------------
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE fichas_registro ADD COLUMN fuente_pago ENUM(''MEGABLESSING'',''BELLA'') NULL AFTER factura_compra',
        'SELECT ''fichas_registro.fuente_pago ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fichas_registro'
      AND COLUMN_NAME = 'fuente_pago'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE fichas_registro ADD COLUMN forma_pago VARCHAR(120) NULL AFTER cantidad_comprada',
        'ALTER TABLE fichas_registro MODIFY COLUMN forma_pago VARCHAR(120) NULL'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fichas_registro'
      AND COLUMN_NAME = 'forma_pago'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS fichas_pago_detalle (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ficha_id INT NOT NULL,
    proveedor_id INT NULL,
    proveedor_nombre VARCHAR(150) NOT NULL,
    fecha_pago DATE NOT NULL,
    tipo_comprobante ENUM('FACTURA','NOTA_COMPRA') NOT NULL,
    factura_compra VARCHAR(80) NOT NULL,
    fuente_pago ENUM('MEGABLESSING','BELLA') NULL,
    cantidad_comprada_unidad ENUM('LB','KG','QQ') NOT NULL DEFAULT 'KG',
    cantidad_comprada DECIMAL(10,2) NOT NULL,
    cantidad_comprada_kg DECIMAL(10,4) NOT NULL,
    forma_pago VARCHAR(120) NOT NULL,
    precio_base_dia DECIMAL(10,4) NOT NULL,
    diferencial_usd DECIMAL(10,4) DEFAULT 0,
    precio_unitario_final DECIMAL(10,4) NOT NULL,
    precio_total_pagar DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ficha_pago_detalle_ficha (ficha_id),
    INDEX idx_ficha_pago_detalle_proveedor (proveedor_id),
    CONSTRAINT fk_ficha_pago_detalle_ficha FOREIGN KEY (ficha_id) REFERENCES fichas_registro(id) ON DELETE CASCADE,
    CONSTRAINT fk_ficha_pago_detalle_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE fichas_pago_detalle ADD COLUMN fuente_pago ENUM(''MEGABLESSING'',''BELLA'') NULL AFTER factura_compra',
        'SELECT ''fichas_pago_detalle.fuente_pago ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fichas_pago_detalle'
      AND COLUMN_NAME = 'fuente_pago'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE fichas_pago_detalle
MODIFY COLUMN forma_pago VARCHAR(120) NOT NULL;

-- --------------------------------------------------------------------------
-- 3) Parámetros de proceso: control de fermentación hasta 10 días.
-- --------------------------------------------------------------------------
UPDATE parametros_proceso
SET valor = '10'
WHERE categoria = 'FERMENTACION'
  AND clave = 'dias_maximos';

INSERT INTO parametros_proceso (categoria, clave, valor, tipo, descripcion)
SELECT 'FERMENTACION', 'dias_maximos', '10', 'NUMBER', 'Días máximos de fermentación'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM parametros_proceso
    WHERE categoria = 'FERMENTACION'
      AND clave = 'dias_maximos'
);
