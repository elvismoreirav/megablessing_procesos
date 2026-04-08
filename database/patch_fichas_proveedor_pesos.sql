-- ============================================================================
-- PATCH: Peso individual por proveedor en recepción
-- Permite guardar el peso registrado por proveedor cuando una misma entrega
-- incluye varios participantes y luego reutilizarlo en el módulo de pagos.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `fichas_proveedor_pesos` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `ficha_id` INT NOT NULL,
    `proveedor_id` INT NULL,
    `proveedor_nombre` VARCHAR(150) NOT NULL,
    `peso` DECIMAL(10,2) NOT NULL,
    `unidad_peso` ENUM('LB','KG','QQ') NOT NULL DEFAULT 'KG',
    `peso_kg` DECIMAL(10,4) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_fichas_proveedor_pesos_ficha` (`ficha_id`),
    INDEX `idx_fichas_proveedor_pesos_proveedor` (`proveedor_id`),
    CONSTRAINT `fk_fichas_proveedor_pesos_ficha`
        FOREIGN KEY (`ficha_id`) REFERENCES `fichas_registro`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fichas_proveedor_pesos_proveedor`
        FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
