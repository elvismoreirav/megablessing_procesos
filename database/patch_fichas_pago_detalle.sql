-- ============================================================================
-- PATCH: Detalle de Pagos por Proveedor en Fichas
-- Crea la tabla para registrar pagos individuales por proveedor dentro
-- de una misma ficha/lote.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `fichas_pago_detalle` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `ficha_id` INT NOT NULL,
    `proveedor_id` INT NULL,
    `proveedor_nombre` VARCHAR(150) NOT NULL,
    `fecha_pago` DATE NOT NULL,
    `tipo_comprobante` ENUM('FACTURA','NOTA_COMPRA') NOT NULL,
    `factura_compra` VARCHAR(80) NOT NULL,
    `fuente_pago` ENUM('MEGABLESSING','BELLA') NULL,
    `cantidad_comprada_unidad` ENUM('LB','KG','QQ') NOT NULL DEFAULT 'KG',
    `cantidad_comprada` DECIMAL(10,2) NOT NULL,
    `cantidad_comprada_kg` DECIMAL(10,4) NOT NULL,
    `forma_pago` VARCHAR(120) NOT NULL,
    `precio_base_dia` DECIMAL(10,4) NOT NULL,
    `diferencial_usd` DECIMAL(10,4) DEFAULT 0,
    `precio_unitario_final` DECIMAL(10,4) NOT NULL,
    `precio_total_pagar` DECIMAL(12,2) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_fichas_pago_detalle_ficha` (`ficha_id`),
    INDEX `idx_fichas_pago_detalle_proveedor` (`proveedor_id`),
    CONSTRAINT `fk_fichas_pago_detalle_ficha`
        FOREIGN KEY (`ficha_id`) REFERENCES `fichas_registro`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fichas_pago_detalle_proveedor`
        FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @db_name = DATABASE();

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE fichas_pago_detalle ADD COLUMN fuente_pago ENUM(''MEGABLESSING'',''BELLA'') NULL AFTER factura_compra',
        'SELECT ''fuente_pago ya existe'' AS info'
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
