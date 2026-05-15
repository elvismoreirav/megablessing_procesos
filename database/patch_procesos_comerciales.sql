-- ============================================================================
-- PATCH: Procesos comerciales
-- Fecha: 2026-05-14
--
-- Crea las tablas:
--   - clientes
--   - muestras_comerciales
--
-- Nota:
-- Los permisos de roles quedan alineados por la aplicacion al actualizar
-- core/Auth.php y pueden reforzarse ejecutando patch_roles_accesos.sql.
-- ============================================================================

SET @db_name = DATABASE();

CREATE TABLE IF NOT EXISTS `clientes` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `codigo` VARCHAR(20) NOT NULL UNIQUE,
    `nombre` VARCHAR(150) NOT NULL,
    `ruc_taxes` VARCHAR(30) NULL,
    `representante_nombre` VARCHAR(120) NULL,
    `telefono` VARCHAR(50) NULL,
    `email` VARCHAR(120) NULL,
    `pagina_web` VARCHAR(255) NULL,
    `pais` VARCHAR(100) NULL,
    `direccion` TEXT NULL,
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `usuario_id` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_clientes_activo` (`activo`),
    INDEX `idx_clientes_pais` (`pais`),
    CONSTRAINT `fk_clientes_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `muestras_comerciales` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `tipo_muestra` ENUM('RECIBIDA','ENVIADA') NOT NULL,
    `proveedor_id` INT NULL,
    `proveedor_nombre` VARCHAR(150) NULL,
    `cliente_id` INT NULL,
    `cliente_nombre` VARCHAR(150) NULL,
    `producto` ENUM('CACAO_GRANO','MANTECA_CACAO','POLVO_CACAO','NIBS_CACAO') NOT NULL,
    `volumen` DECIMAL(10,2) NOT NULL,
    `unidad_volumen` ENUM('KG','QQ') NOT NULL DEFAULT 'KG',
    `variedad` ENUM('FINO_AROMA','CCN51_GRADO_1','CCN51_GRADO_2','CCN51_GRADO_3') NOT NULL,
    `origen` VARCHAR(150) NOT NULL,
    `destino` VARCHAR(150) NULL,
    `fecha_recepcion` DATE NULL,
    `fecha_envio` DATE NULL,
    `fecha_arribo` DATE NULL,
    `enlace_resultados_laboratorio` VARCHAR(500) NULL,
    `observaciones` TEXT NULL,
    `usuario_id` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_muestras_tipo` (`tipo_muestra`),
    INDEX `idx_muestras_fecha_recepcion` (`fecha_recepcion`),
    INDEX `idx_muestras_fecha_envio` (`fecha_envio`),
    INDEX `idx_muestras_proveedor` (`proveedor_id`),
    INDEX `idx_muestras_cliente` (`cliente_id`),
    CONSTRAINT `fk_muestras_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_muestras_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_muestras_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SHOW TABLES LIKE 'clientes';
SHOW TABLES LIKE 'muestras_comerciales';
SHOW COLUMNS FROM clientes;
SHOW COLUMNS FROM muestras_comerciales;
