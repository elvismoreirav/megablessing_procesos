-- ============================================================================
-- PATCH: Fermentación nocturna + etapas de secado + secadoras base (13)
-- Fecha: 2026-02-25
-- ============================================================================

START TRANSACTION;

-- Etapa explícita para permitir ficha de pre-secado y ficha de secado final
SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'registros_secado'
        ) THEN 'SELECT 1'
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'registros_secado'
              AND column_name = 'etapa_proceso'
        ) THEN 'SELECT 1'
        ELSE 'ALTER TABLE registros_secado ADD COLUMN etapa_proceso ENUM(''PRE_SECADO'',''SECADO_FINAL'') NULL AFTER estado'
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE registros_secado rs
JOIN (
    SELECT r1.id,
           CASE
               WHEN (
                   SELECT COUNT(*)
                   FROM registros_secado r2
                   WHERE r2.lote_id = r1.lote_id
                     AND r2.id <= r1.id
               ) = 1 THEN 'PRE_SECADO'
               ELSE 'SECADO_FINAL'
           END as etapa_calculada
    FROM registros_secado r1
) x ON x.id = rs.id
SET rs.etapa_proceso = COALESCE(rs.etapa_proceso, x.etapa_calculada);

-- Nuevos registros nocturnos en control diario de fermentación
SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'fermentacion_control_diario'
        ) THEN 'SELECT 1'
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'fermentacion_control_diario'
              AND column_name = 'temp_20h'
        ) THEN 'SELECT 1'
        ELSE 'ALTER TABLE fermentacion_control_diario ADD COLUMN temp_20h DECIMAL(5,2) NULL AFTER temp_ambiente'
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'fermentacion_control_diario'
        ) THEN 'SELECT 1'
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'fermentacion_control_diario'
              AND column_name = 'temp_22h'
        ) THEN 'SELECT 1'
        ELSE 'ALTER TABLE fermentacion_control_diario ADD COLUMN temp_22h DECIMAL(5,2) NULL AFTER temp_20h'
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'fermentacion_control_diario'
        ) THEN 'SELECT 1'
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'fermentacion_control_diario'
              AND column_name = 'temp_24h'
        ) THEN 'SELECT 1'
        ELSE 'ALTER TABLE fermentacion_control_diario ADD COLUMN temp_24h DECIMAL(5,2) NULL AFTER temp_22h'
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'fermentacion_control_diario'
        ) THEN 'SELECT 1'
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'fermentacion_control_diario'
              AND column_name = 'temp_02h'
        ) THEN 'SELECT 1'
        ELSE 'ALTER TABLE fermentacion_control_diario ADD COLUMN temp_02h DECIMAL(5,2) NULL AFTER temp_24h'
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'fermentacion_control_diario'
        ) THEN 'SELECT 1'
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'fermentacion_control_diario'
              AND column_name = 'temp_04h'
        ) THEN 'SELECT 1'
        ELSE 'ALTER TABLE fermentacion_control_diario ADD COLUMN temp_04h DECIMAL(5,2) NULL AFTER temp_02h'
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Parámetros de temperatura solicitados para fermentación
UPDATE parametros_proceso
SET valor = '70'
WHERE categoria = 'FERMENTACION' AND clave = 'temp_min';

UPDATE parametros_proceso
SET valor = '130'
WHERE categoria = 'FERMENTACION' AND clave = 'temp_max';

-- Catálogo base de 13 secadoras
INSERT INTO secadoras (numero, nombre, capacidad_qq, tipo) VALUES
('SEC-05', 'Secadora Industrial 5', 100, 'INDUSTRIAL'),
('SEC-06', 'Secadora Industrial 6', 100, 'INDUSTRIAL'),
('SEC-07', 'Secadora Industrial 7', 100, 'INDUSTRIAL'),
('SEC-08', 'Secadora Industrial 8', 100, 'INDUSTRIAL'),
('SEC-09', 'Secadora Industrial 9', 100, 'INDUSTRIAL'),
('SEC-10', 'Secadora Industrial 10', 100, 'INDUSTRIAL'),
('SEC-11', 'Secadora Industrial 11', 100, 'INDUSTRIAL'),
('SEC-12', 'Secadora Industrial 12', 100, 'INDUSTRIAL'),
('SEC-13', 'Secadora Industrial 13', 100, 'INDUSTRIAL')
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    capacidad_qq = COALESCE(secadoras.capacidad_qq, VALUES(capacidad_qq)),
    tipo = VALUES(tipo);

COMMIT;
