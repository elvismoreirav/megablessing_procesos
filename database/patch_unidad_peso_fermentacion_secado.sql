-- ============================================================================
-- PATCH: Unidad de peso heredada en fermentacion y secado
-- Fecha: 2026-03-17
-- Agrega las columnas unidad_peso y realiza una carga inicial para registros
-- existentes sin depender del ajuste automatico por codigo.
-- ============================================================================

SET @db_name = DATABASE();

-- --------------------------------------------------------------------------
-- 1) Agregar registros_fermentacion.unidad_peso
-- --------------------------------------------------------------------------
SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = @db_name
              AND table_name = 'registros_fermentacion'
        ) THEN "SELECT 'registros_fermentacion no existe' AS info"
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_fermentacion'
              AND column_name = 'unidad_peso'
        ) THEN "SELECT 'registros_fermentacion.unidad_peso ya existe' AS info"
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_fermentacion'
              AND column_name = 'peso_lote_kg'
        ) THEN "ALTER TABLE registros_fermentacion ADD COLUMN unidad_peso ENUM('LB','KG','QQ') NULL AFTER peso_lote_kg"
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_fermentacion'
              AND column_name = 'peso_inicial'
        ) THEN "ALTER TABLE registros_fermentacion ADD COLUMN unidad_peso ENUM('LB','KG','QQ') NULL AFTER peso_inicial"
        ELSE "ALTER TABLE registros_fermentacion ADD COLUMN unidad_peso ENUM('LB','KG','QQ') NULL"
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------------
-- 2) Agregar registros_secado.unidad_peso
-- --------------------------------------------------------------------------
SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = @db_name
              AND table_name = 'registros_secado'
        ) THEN "SELECT 'registros_secado no existe' AS info"
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_secado'
              AND column_name = 'unidad_peso'
        ) THEN "SELECT 'registros_secado.unidad_peso ya existe' AS info"
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_secado'
              AND column_name = 'etapa_proceso'
        ) THEN "ALTER TABLE registros_secado ADD COLUMN unidad_peso ENUM('LB','KG','QQ') NULL AFTER etapa_proceso"
        WHEN EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_secado'
              AND column_name = 'estado'
        ) THEN "ALTER TABLE registros_secado ADD COLUMN unidad_peso ENUM('LB','KG','QQ') NULL AFTER estado"
        ELSE "ALTER TABLE registros_secado ADD COLUMN unidad_peso ENUM('LB','KG','QQ') NULL"
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------------
-- 3) Carga inicial de unidad en fermentacion desde ficha de registro
-- --------------------------------------------------------------------------
SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = @db_name
              AND table_name = 'registros_fermentacion'
        ) THEN "SELECT 'Backfill fermentacion omitido: registros_fermentacion no existe' AS info"
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = @db_name
              AND table_name = 'fichas_registro'
        ) THEN "SELECT 'Backfill fermentacion omitido: fichas_registro no existe' AS info"
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_fermentacion'
              AND column_name = 'unidad_peso'
        ) THEN "SELECT 'Backfill fermentacion omitido: unidad_peso no existe en registros_fermentacion' AS info"
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'fichas_registro'
              AND column_name = 'unidad_peso'
        ) THEN "SELECT 'Backfill fermentacion omitido: unidad_peso no existe en fichas_registro' AS info"
        ELSE "
            UPDATE registros_fermentacion rf
            JOIN (
                SELECT fr1.lote_id, UPPER(TRIM(fr1.unidad_peso)) AS unidad_peso
                FROM fichas_registro fr1
                JOIN (
                    SELECT lote_id, MAX(id) AS max_id
                    FROM fichas_registro
                    GROUP BY lote_id
                ) frx ON frx.max_id = fr1.id
                WHERE fr1.unidad_peso IS NOT NULL
                  AND TRIM(fr1.unidad_peso) <> ''
            ) fuente ON fuente.lote_id = rf.lote_id
            SET rf.unidad_peso = fuente.unidad_peso
            WHERE (rf.unidad_peso IS NULL OR TRIM(rf.unidad_peso) = '')
              AND fuente.unidad_peso IN ('LB','KG','QQ')
        "
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------------
-- 4) Carga inicial de secado final desde la fermentacion
-- --------------------------------------------------------------------------
SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = @db_name
              AND table_name = 'registros_secado'
        ) THEN "SELECT 'Backfill secado/fermentacion omitido: registros_secado no existe' AS info"
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = @db_name
              AND table_name = 'registros_fermentacion'
        ) THEN "SELECT 'Backfill secado/fermentacion omitido: registros_fermentacion no existe' AS info"
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_secado'
              AND column_name = 'unidad_peso'
        ) THEN "SELECT 'Backfill secado/fermentacion omitido: unidad_peso no existe en registros_secado' AS info"
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_fermentacion'
              AND column_name = 'unidad_peso'
        ) THEN "SELECT 'Backfill secado/fermentacion omitido: unidad_peso no existe en registros_fermentacion' AS info"
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_secado'
              AND column_name = 'etapa_proceso'
        ) THEN "SELECT 'Backfill secado/fermentacion omitido: etapa_proceso no existe en registros_secado' AS info"
        ELSE "
            UPDATE registros_secado rs
            JOIN (
                SELECT rf1.lote_id, UPPER(TRIM(rf1.unidad_peso)) AS unidad_peso
                FROM registros_fermentacion rf1
                JOIN (
                    SELECT lote_id, MAX(id) AS max_id
                    FROM registros_fermentacion
                    GROUP BY lote_id
                ) rfx ON rfx.max_id = rf1.id
                WHERE rf1.unidad_peso IS NOT NULL
                  AND TRIM(rf1.unidad_peso) <> ''
            ) fuente ON fuente.lote_id = rs.lote_id
            SET rs.unidad_peso = fuente.unidad_peso
            WHERE (rs.unidad_peso IS NULL OR TRIM(rs.unidad_peso) = '')
              AND UPPER(COALESCE(rs.etapa_proceso, '')) = 'SECADO_FINAL'
              AND fuente.unidad_peso IN ('LB','KG','QQ')
        "
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------------
-- 5) Carga inicial de secado desde un secado previo del mismo lote
-- --------------------------------------------------------------------------
SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = @db_name
              AND table_name = 'registros_secado'
        ) THEN "SELECT 'Backfill secado previo omitido: registros_secado no existe' AS info"
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_secado'
              AND column_name = 'unidad_peso'
        ) THEN "SELECT 'Backfill secado previo omitido: unidad_peso no existe en registros_secado' AS info"
        ELSE "
            UPDATE registros_secado rs
            JOIN (
                SELECT actual.id, MAX(previo.id) AS previo_id
                FROM registros_secado actual
                JOIN registros_secado previo
                  ON previo.lote_id = actual.lote_id
                 AND previo.id < actual.id
                 AND previo.unidad_peso IS NOT NULL
                 AND TRIM(previo.unidad_peso) <> ''
                GROUP BY actual.id
            ) relacion ON relacion.id = rs.id
            JOIN registros_secado prev ON prev.id = relacion.previo_id
            SET rs.unidad_peso = prev.unidad_peso
            WHERE (rs.unidad_peso IS NULL OR TRIM(rs.unidad_peso) = '')
              AND prev.unidad_peso IN ('LB','KG','QQ')
        "
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------------
-- 6) Carga inicial restante de secado desde ficha de registro
-- --------------------------------------------------------------------------
SET @sql = (
    SELECT CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = @db_name
              AND table_name = 'registros_secado'
        ) THEN "SELECT 'Backfill secado/ficha omitido: registros_secado no existe' AS info"
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = @db_name
              AND table_name = 'fichas_registro'
        ) THEN "SELECT 'Backfill secado/ficha omitido: fichas_registro no existe' AS info"
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'registros_secado'
              AND column_name = 'unidad_peso'
        ) THEN "SELECT 'Backfill secado/ficha omitido: unidad_peso no existe en registros_secado' AS info"
        WHEN NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = @db_name
              AND table_name = 'fichas_registro'
              AND column_name = 'unidad_peso'
        ) THEN "SELECT 'Backfill secado/ficha omitido: unidad_peso no existe en fichas_registro' AS info"
        ELSE "
            UPDATE registros_secado rs
            JOIN (
                SELECT fr1.lote_id, UPPER(TRIM(fr1.unidad_peso)) AS unidad_peso
                FROM fichas_registro fr1
                JOIN (
                    SELECT lote_id, MAX(id) AS max_id
                    FROM fichas_registro
                    GROUP BY lote_id
                ) frx ON frx.max_id = fr1.id
                WHERE fr1.unidad_peso IS NOT NULL
                  AND TRIM(fr1.unidad_peso) <> ''
            ) fuente ON fuente.lote_id = rs.lote_id
            SET rs.unidad_peso = fuente.unidad_peso
            WHERE (rs.unidad_peso IS NULL OR TRIM(rs.unidad_peso) = '')
              AND fuente.unidad_peso IN ('LB','KG','QQ')
        "
    END
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Patch unidad_peso_fermentacion_secado aplicado' AS resultado;
