-- ============================================================================
-- PATCH: Empaquetado
-- Crea/actualiza la tabla registros_empaquetado requerida por el módulo.
-- ============================================================================

CREATE TABLE IF NOT EXISTS registros_empaquetado (
    id INT PRIMARY KEY AUTO_INCREMENT,
    lote_id INT NOT NULL,
    tipo_empaque VARCHAR(30) NOT NULL,
    peso_saco DECIMAL(10,2) NOT NULL,
    fecha_empaquetado DATE NULL,
    numero_sacos INT NULL,
    peso_total DECIMAL(10,2) NULL,
    lote_empaque VARCHAR(80) NULL,
    destino VARCHAR(150) NULL,
    observaciones TEXT,
    operador_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_empaque_lote (lote_id),
    INDEX idx_empaque_fecha (fecha_empaquetado),
    CONSTRAINT fk_empaque_lote FOREIGN KEY (lote_id) REFERENCES lotes(id) ON DELETE CASCADE,
    CONSTRAINT fk_empaque_operador FOREIGN KEY (operador_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Columnas de compatibilidad para tablas creadas parcialmente
SET @db_name = DATABASE();

SET @alter_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_empaquetado'
              AND column_name = 'tipo_empaque'
        ),
        'SELECT ''tipo_empaque ya existe'' AS info',
        'ALTER TABLE registros_empaquetado ADD COLUMN tipo_empaque VARCHAR(30) NOT NULL AFTER lote_id'
    )
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @alter_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_empaquetado'
              AND column_name = 'peso_saco'
        ),
        'SELECT ''peso_saco ya existe'' AS info',
        'ALTER TABLE registros_empaquetado ADD COLUMN peso_saco DECIMAL(10,2) NOT NULL AFTER tipo_empaque'
    )
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @alter_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_empaquetado'
              AND column_name = 'fecha_empaquetado'
        ),
        'SELECT ''fecha_empaquetado ya existe'' AS info',
        'ALTER TABLE registros_empaquetado ADD COLUMN fecha_empaquetado DATE NULL AFTER peso_saco'
    )
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @alter_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_empaquetado'
              AND column_name = 'numero_sacos'
        ),
        'SELECT ''numero_sacos ya existe'' AS info',
        'ALTER TABLE registros_empaquetado ADD COLUMN numero_sacos INT NULL AFTER fecha_empaquetado'
    )
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @alter_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_empaquetado'
              AND column_name = 'peso_total'
        ),
        'SELECT ''peso_total ya existe'' AS info',
        'ALTER TABLE registros_empaquetado ADD COLUMN peso_total DECIMAL(10,2) NULL AFTER numero_sacos'
    )
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @alter_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_empaquetado'
              AND column_name = 'lote_empaque'
        ),
        'SELECT ''lote_empaque ya existe'' AS info',
        'ALTER TABLE registros_empaquetado ADD COLUMN lote_empaque VARCHAR(80) NULL AFTER peso_total'
    )
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @alter_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_empaquetado'
              AND column_name = 'destino'
        ),
        'SELECT ''destino ya existe'' AS info',
        'ALTER TABLE registros_empaquetado ADD COLUMN destino VARCHAR(150) NULL AFTER lote_empaque'
    )
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @alter_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_empaquetado'
              AND column_name = 'observaciones'
        ),
        'SELECT ''observaciones ya existe'' AS info',
        'ALTER TABLE registros_empaquetado ADD COLUMN observaciones TEXT NULL AFTER destino'
    )
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @alter_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_empaquetado'
              AND column_name = 'operador_id'
        ),
        'SELECT ''operador_id ya existe'' AS info',
        'ALTER TABLE registros_empaquetado ADD COLUMN operador_id INT NULL AFTER observaciones'
    )
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
