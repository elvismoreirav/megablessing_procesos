-- ============================================================================
-- PATCH: Calidad de salida
-- 1) Inserta el estado CALIDAD_SALIDA en lotes.estado_proceso
-- 2) Crea tabla registros_calidad_salida
-- ============================================================================

SET @db_name = DATABASE();

-- Actualizar enum de estado_proceso (incluye RECHAZADO por consistencia de flujo)
ALTER TABLE lotes
MODIFY COLUMN estado_proceso ENUM(
    'RECEPCION',
    'CALIDAD',
    'PRE_SECADO',
    'FERMENTACION',
    'SECADO',
    'CALIDAD_POST',
    'CALIDAD_SALIDA',
    'EMPAQUETADO',
    'ALMACENADO',
    'DESPACHO',
    'FINALIZADO',
    'RECHAZADO'
) DEFAULT 'RECEPCION';

-- Crear tabla de calidad de salida
CREATE TABLE IF NOT EXISTS registros_calidad_salida (
    id INT PRIMARY KEY AUTO_INCREMENT,
    lote_id INT NOT NULL,
    fecha_registro DATE NOT NULL,
    fichas_conforman_lote VARCHAR(255) NOT NULL,
    categoria_proveedor VARCHAR(120) NOT NULL,
    fecha_entrada DATE NOT NULL,
    variedad VARCHAR(100) NOT NULL,
    grado_calidad ENUM('GRADO_1','GRADO_2','GRADO_3','NO_APLICA') DEFAULT 'NO_APLICA',
    estado_producto VARCHAR(50) NOT NULL,
    estado_fermentacion VARCHAR(50) NOT NULL,
    certificaciones JSON,
    certificaciones_texto VARCHAR(255),
    otra_certificacion VARCHAR(120),
    observaciones TEXT,
    usuario_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_calidad_salida_lote (lote_id),
    CONSTRAINT fk_calidad_salida_lote FOREIGN KEY (lote_id) REFERENCES lotes(id) ON DELETE CASCADE,
    CONSTRAINT fk_calidad_salida_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Asegurar índice único en instalaciones donde la tabla ya existía
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE registros_calidad_salida ADD UNIQUE KEY uq_calidad_salida_lote (lote_id)',
        'SELECT ''uq_calidad_salida_lote ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'registros_calidad_salida'
      AND INDEX_NAME = 'uq_calidad_salida_lote'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
