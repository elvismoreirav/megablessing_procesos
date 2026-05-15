-- ============================================================================
-- PATCH CONSOLIDADO: Cambios BD para produccion
-- Fecha: 2026-05-14
-- Archivo corregido para MariaDB / phpMyAdmin
--
-- Incluye cambios de estructura, datos base y permisos relacionados con:
-- 1) Parametrizacion y normalizacion de proveedores
-- 2) Calidad de salida con enlace de resultados
-- 3) Procesos comerciales: clientes y muestras
-- 4) Roles y accesos para nuevos modulos
--
-- IMPORTANTE:
-- - Este archivo NO ejecuta el reset masivo de lotes por defecto porque es una
--   operacion destructiva y depende de validacion operativa previa.
-- - Si se requiere ese reset, use manualmente el archivo separado:
--   database/patch_reset_lotes_practica_excepto_prov0006_prov0007.sql
-- ============================================================================

SELECT 'INICIO PATCH CONSOLIDADO 2026-05-14 CORREGIDO' AS mensaje;

-- --------------------------------------------------------------------------
-- 1) PROVEEDORES: PARAMETRIZACION BASE
-- --------------------------------------------------------------------------

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

ALTER TABLE proveedores
    MODIFY COLUMN tipo ENUM('MERCADO','BODEGA','COMERCIAL','RUTA','PRODUCTOR') NOT NULL;

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

-- tipos_permitidos
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN tipos_permitidos VARCHAR(120) NULL AFTER categoria',
        'SELECT ''tipos_permitidos ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'tipos_permitidos'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- utm_este_x
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN utm_este_x VARCHAR(50) NULL AFTER direccion',
        'SELECT ''utm_este_x ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'utm_este_x'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- utm_norte_y
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN utm_norte_y VARCHAR(50) NULL AFTER utm_este_x',
        'SELECT ''utm_norte_y ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'utm_norte_y'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- seguridad_deforestacion
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN seguridad_deforestacion TINYINT(1) NULL AFTER utm_norte_y',
        'SELECT ''seguridad_deforestacion ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'seguridad_deforestacion'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- arboles_endemicos
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN arboles_endemicos TINYINT(1) NULL AFTER seguridad_deforestacion',
        'SELECT ''arboles_endemicos ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'arboles_endemicos'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- hectareas_totales
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN hectareas_totales DECIMAL(10,2) NULL AFTER arboles_endemicos',
        'SELECT ''hectareas_totales ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'hectareas_totales'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- hectareas_ccn51
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN hectareas_ccn51 DECIMAL(10,2) NULL AFTER hectareas_totales',
        'SELECT ''hectareas_ccn51 ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'hectareas_ccn51'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- hectareas_fino_aroma
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN hectareas_fino_aroma DECIMAL(10,2) NULL AFTER hectareas_ccn51',
        'SELECT ''hectareas_fino_aroma ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'hectareas_fino_aroma'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- certificaciones
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN certificaciones TEXT NULL AFTER hectareas_fino_aroma',
        'SELECT ''certificaciones ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'certificaciones'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- certificacion_otras
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN certificacion_otras VARCHAR(255) NULL AFTER certificaciones',
        'SELECT ''certificacion_otras ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'certificacion_otras'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- documento_certificaciones
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE proveedores ADD COLUMN documento_certificaciones VARCHAR(255) NULL AFTER certificacion_otras',
        'SELECT ''documento_certificaciones ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'proveedores'
      AND COLUMN_NAME = 'documento_certificaciones'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Sugerencia de categorias por defecto para registros existentes
UPDATE proveedores
SET categoria = 'COMERCIALES'
WHERE UPPER(COALESCE(tipo, '')) = 'MERCADO'
   OR UPPER(COALESCE(categoria, '')) = 'MERCADO';

UPDATE proveedores
SET categoria = 'CENTRO DE ACOPIO'
WHERE UPPER(COALESCE(tipo, '')) IN ('BODEGA', 'CA', 'CENTRO DE ACOPIO', 'CENTRO_ACOPIO')
   OR UPPER(COALESCE(categoria, '')) = 'BODEGA';

UPDATE proveedores
SET tipo = 'COMERCIAL'
WHERE UPPER(COALESCE(tipo, '')) IN ('MERCADO', 'COMERCIAL', 'COMERCIALES', 'BODEGA', 'CA', 'CENTRO DE ACOPIO', 'CENTRO_ACOPIO');

UPDATE proveedores
SET categoria = CASE
    WHEN tipo = 'COMERCIAL' AND UPPER(COALESCE(nombre, '')) IN ('CENTRO DE ACOPIO', 'BODEGA') THEN 'CENTRO DE ACOPIO'
    WHEN tipo = 'COMERCIAL' AND UPPER(COALESCE(categoria, '')) = '' THEN 'COMERCIALES'
    WHEN tipo = 'PRODUCTOR' THEN 'PRODUCTOR'
    WHEN tipo = 'RUTA' AND UPPER(nombre) LIKE '%ESMERALDAS%' THEN 'ESMERALDAS'
    WHEN tipo = 'RUTA' AND (UPPER(nombre) LIKE '%FLOR%' OR UPPER(nombre) LIKE '%MANABI%') THEN 'FLOR DE MANABI'
    WHEN tipo = 'RUTA' AND (UPPER(nombre) LIKE '%PEDERNALES%' OR UPPER(nombre) LIKE '%VIA%') THEN 'VIA PEDERNALES'
    WHEN tipo = 'RUTA' THEN 'ESMERALDAS'
    ELSE categoria
END
WHERE categoria IS NULL OR categoria = '';

-- Renombrar categoria base Bodega a Centro de Acopio
UPDATE proveedores
SET categoria = 'CENTRO DE ACOPIO'
WHERE UPPER(COALESCE(categoria, '')) = 'BODEGA';

UPDATE proveedores
SET categoria = 'COMERCIALES'
WHERE UPPER(COALESCE(categoria, '')) = 'MERCADO';

SET @cat_comerciales_id = (
    SELECT id
    FROM proveedores
    WHERE UPPER(COALESCE(codigo, '')) = 'M'
       OR (
           es_categoria = 1
           AND (
               UPPER(COALESCE(categoria, '')) IN ('MERCADO', 'COMERCIALES')
               OR UPPER(COALESCE(nombre, '')) IN ('MERCADO', 'COMERCIALES', 'COMERCIAL')
           )
       )
    ORDER BY CASE WHEN UPPER(COALESCE(codigo, '')) = 'M' THEN 0 ELSE 1 END, id
    LIMIT 1
);

UPDATE proveedores
SET tipo = 'COMERCIAL',
    categoria = 'COMERCIALES'
WHERE (
        UPPER(COALESCE(codigo, '')) = 'M'
        OR UPPER(COALESCE(categoria, '')) IN ('MERCADO', 'COMERCIALES')
        OR UPPER(COALESCE(nombre, '')) IN ('MERCADO', 'COMERCIALES', 'COMERCIAL')
      )
  AND id <> COALESCE(@cat_comerciales_id, 0);

UPDATE proveedores
SET codigo = 'M',
    nombre = 'Comerciales',
    tipo = 'COMERCIAL',
    categoria = 'COMERCIALES',
    es_categoria = 1
WHERE id = @cat_comerciales_id;

INSERT INTO proveedores (codigo, nombre, tipo, categoria, es_categoria, activo)
SELECT 'M', 'Comerciales', 'COMERCIAL', 'COMERCIALES', 1, 1
FROM DUAL
WHERE @cat_comerciales_id IS NULL;

SET @cat_acopio_id = (
    SELECT id
    FROM proveedores
    WHERE UPPER(COALESCE(codigo, '')) IN ('B', 'CA')
       OR (
           es_categoria = 1
           AND (
               UPPER(COALESCE(categoria, '')) IN ('BODEGA', 'CENTRO DE ACOPIO')
               OR UPPER(COALESCE(nombre, '')) IN ('BODEGA', 'CENTRO DE ACOPIO')
           )
       )
    ORDER BY CASE WHEN UPPER(COALESCE(codigo, '')) = 'CA' THEN 0 WHEN UPPER(COALESCE(codigo, '')) = 'B' THEN 1 ELSE 2 END, id
    LIMIT 1
);

UPDATE proveedores
SET tipo = 'COMERCIAL',
    categoria = 'CENTRO DE ACOPIO'
WHERE (
        UPPER(COALESCE(codigo, '')) IN ('B', 'CA')
        OR UPPER(COALESCE(categoria, '')) IN ('BODEGA', 'CENTRO DE ACOPIO')
        OR UPPER(COALESCE(nombre, '')) IN ('BODEGA', 'CENTRO DE ACOPIO')
      )
  AND id <> COALESCE(@cat_acopio_id, 0);

UPDATE proveedores
SET codigo = 'CA',
    nombre = 'Centro de Acopio',
    tipo = 'COMERCIAL',
    categoria = 'CENTRO DE ACOPIO',
    es_categoria = 1
WHERE id = @cat_acopio_id;

INSERT INTO proveedores (codigo, nombre, tipo, categoria, es_categoria, activo)
SELECT 'CA', 'Centro de Acopio', 'COMERCIAL', 'CENTRO DE ACOPIO', 1, 1
FROM DUAL
WHERE @cat_acopio_id IS NULL;

-- Marcar categorias base ya existentes
UPDATE proveedores
SET es_categoria = 1
WHERE UPPER(codigo) IN ('M','CA','ES','FM','VP');

-- Completar tipos_permitidos para categorias existentes
UPDATE proveedores
SET tipos_permitidos = UPPER(tipo)
WHERE es_categoria = 1
  AND (tipos_permitidos IS NULL OR TRIM(tipos_permitidos) = '');

UPDATE proveedores
SET tipos_permitidos = TRIM(BOTH ',' FROM
    REPLACE(
        REPLACE(
            REPLACE(
                REPLACE(
                    REPLACE(
                        CONCAT(',', REPLACE(REPLACE(UPPER(COALESCE(tipos_permitidos, '')), ';', ','), ' ', ''), ','),
                        ',MERCADO,', ',COMERCIAL,'
                    ),
                    ',COMERCIALES,', ',COMERCIAL,'
                ),
                ',BODEGA,', ',COMERCIAL,'
            ),
            ',COMERCIAL,COMERCIAL,', ',COMERCIAL,'
        ),
        ',,', ','
    )
)
WHERE tipos_permitidos IS NOT NULL
  AND TRIM(tipos_permitidos) <> '';

ALTER TABLE proveedores
    MODIFY COLUMN tipo ENUM('COMERCIAL','RUTA','PRODUCTOR') NOT NULL;


-- --------------------------------------------------------------------------
-- 2) PROVEEDORES: NORMALIZACION DE TIPOS
-- --------------------------------------------------------------------------

-- ============================================================================
-- PATCH: Normalizacion de tipos de proveedores a COMERCIAL / RUTA / PRODUCTOR
-- - Reemplaza MERCADO y BODEGA por COMERCIAL
-- - Renombra la categoria base Mercado a Comerciales
-- - Conserva Centro de Acopio como categoria, pero ya no como tipo independiente
-- ============================================================================

SET @db_name = DATABASE();

ALTER TABLE proveedores
    MODIFY COLUMN tipo ENUM('MERCADO','BODEGA','COMERCIAL','RUTA','PRODUCTOR') NOT NULL;

UPDATE proveedores
SET categoria = 'COMERCIALES'
WHERE UPPER(COALESCE(tipo, '')) = 'MERCADO'
   OR UPPER(COALESCE(categoria, '')) = 'MERCADO';

UPDATE proveedores
SET categoria = 'CENTRO DE ACOPIO'
WHERE UPPER(COALESCE(tipo, '')) IN ('BODEGA', 'CA', 'CENTRO DE ACOPIO', 'CENTRO_ACOPIO', 'CENTRO DE ACOPIO (CA)')
   OR UPPER(COALESCE(categoria, '')) = 'BODEGA';

UPDATE proveedores
SET tipo = 'COMERCIAL'
WHERE UPPER(COALESCE(tipo, '')) IN (
    'MERCADO', 'COMERCIAL', 'COMERCIALES',
    'BODEGA', 'CA', 'CENTRO DE ACOPIO', 'CENTRO_ACOPIO', 'CENTRO DE ACOPIO (CA)'
);

UPDATE proveedores
SET categoria = 'COMERCIALES'
WHERE UPPER(COALESCE(categoria, '')) = 'MERCADO';

SET @cat_comerciales_id = (
    SELECT id
    FROM proveedores
    WHERE UPPER(COALESCE(codigo, '')) = 'M'
       OR (
           COALESCE(es_categoria, 0) = 1
           AND (
               UPPER(COALESCE(categoria, '')) IN ('MERCADO', 'COMERCIALES')
               OR UPPER(COALESCE(nombre, '')) IN ('MERCADO', 'COMERCIALES', 'COMERCIAL')
           )
       )
    ORDER BY CASE WHEN UPPER(COALESCE(codigo, '')) = 'M' THEN 0 ELSE 1 END, id
    LIMIT 1
);

UPDATE proveedores
SET tipo = 'COMERCIAL',
    categoria = 'COMERCIALES'
WHERE (
        UPPER(COALESCE(codigo, '')) = 'M'
        OR UPPER(COALESCE(categoria, '')) IN ('MERCADO', 'COMERCIALES')
        OR UPPER(COALESCE(nombre, '')) IN ('MERCADO', 'COMERCIALES', 'COMERCIAL')
      )
  AND id <> COALESCE(@cat_comerciales_id, 0);

UPDATE proveedores
SET codigo = 'M',
    nombre = 'Comerciales',
    tipo = 'COMERCIAL',
    categoria = 'COMERCIALES',
    es_categoria = 1
WHERE id = @cat_comerciales_id;

INSERT INTO proveedores (codigo, nombre, tipo, categoria, es_categoria, activo)
SELECT 'M', 'Comerciales', 'COMERCIAL', 'COMERCIALES', 1, 1
FROM DUAL
WHERE @cat_comerciales_id IS NULL;

SET @cat_acopio_id = (
    SELECT id
    FROM proveedores
    WHERE UPPER(COALESCE(codigo, '')) IN ('B', 'CA')
       OR (
           COALESCE(es_categoria, 0) = 1
           AND (
               UPPER(COALESCE(categoria, '')) IN ('BODEGA', 'CENTRO DE ACOPIO')
               OR UPPER(COALESCE(nombre, '')) IN ('BODEGA', 'CENTRO DE ACOPIO')
           )
       )
    ORDER BY CASE WHEN UPPER(COALESCE(codigo, '')) = 'CA' THEN 0 WHEN UPPER(COALESCE(codigo, '')) = 'B' THEN 1 ELSE 2 END, id
    LIMIT 1
);

UPDATE proveedores
SET tipo = 'COMERCIAL',
    categoria = 'CENTRO DE ACOPIO'
WHERE (
        UPPER(COALESCE(codigo, '')) IN ('B', 'CA')
        OR UPPER(COALESCE(categoria, '')) IN ('BODEGA', 'CENTRO DE ACOPIO')
        OR UPPER(COALESCE(nombre, '')) IN ('BODEGA', 'CENTRO DE ACOPIO')
      )
  AND id <> COALESCE(@cat_acopio_id, 0);

UPDATE proveedores
SET codigo = 'CA',
    nombre = 'Centro de Acopio',
    tipo = 'COMERCIAL',
    categoria = 'CENTRO DE ACOPIO',
    es_categoria = 1
WHERE id = @cat_acopio_id;

INSERT INTO proveedores (codigo, nombre, tipo, categoria, es_categoria, activo)
SELECT 'CA', 'Centro de Acopio', 'COMERCIAL', 'CENTRO DE ACOPIO', 1, 1
FROM DUAL
WHERE @cat_acopio_id IS NULL;

UPDATE proveedores
SET tipos_permitidos = UPPER(tipo)
WHERE COALESCE(es_categoria, 0) = 1
  AND (tipos_permitidos IS NULL OR TRIM(tipos_permitidos) = '');

UPDATE proveedores
SET tipos_permitidos = TRIM(BOTH ',' FROM
    REPLACE(
        REPLACE(
            REPLACE(
                REPLACE(
                    REPLACE(
                        CONCAT(',', REPLACE(REPLACE(UPPER(COALESCE(tipos_permitidos, '')), ';', ','), ' ', ''), ','),
                        ',MERCADO,', ',COMERCIAL,'
                    ),
                    ',COMERCIALES,', ',COMERCIAL,'
                ),
                ',BODEGA,', ',COMERCIAL,'
            ),
            ',COMERCIAL,COMERCIAL,', ',COMERCIAL,'
        ),
        ',,', ','
    )
)
WHERE tipos_permitidos IS NOT NULL
  AND TRIM(tipos_permitidos) <> '';

ALTER TABLE proveedores
    MODIFY COLUMN tipo ENUM('COMERCIAL','RUTA','PRODUCTOR') NOT NULL;


-- --------------------------------------------------------------------------
-- 3) CALIDAD DE SALIDA
-- --------------------------------------------------------------------------

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
    enlace_resultados_drive VARCHAR(500),
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

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE registros_calidad_salida ADD COLUMN enlace_resultados_drive VARCHAR(500) NULL AFTER observaciones',
        'SELECT ''enlace_resultados_drive ya existe'' AS info'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'registros_calidad_salida'
      AND COLUMN_NAME = 'enlace_resultados_drive'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SHOW COLUMNS FROM registros_calidad_salida LIKE 'enlace_resultados_drive';


-- --------------------------------------------------------------------------
-- 4) CALIDAD DE SALIDA: ENLACE DE RESULTADOS
-- --------------------------------------------------------------------------

-- ============================================================================
-- PATCH: Enlace de analisis y resultados en calidad de salida
-- Fecha: 2026-05-14
--
-- Agrega un campo para almacenar el enlace de Drive u otro repositorio
-- externo donde se guardan los analisis y resultados del lote.
-- ============================================================================

SET @db_name = DATABASE();

SET @has_column = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'registros_calidad_salida'
      AND COLUMN_NAME = 'enlace_resultados_drive'
);

SET @sql = IF(
    @has_column = 0,
    'ALTER TABLE registros_calidad_salida ADD COLUMN enlace_resultados_drive VARCHAR(500) NULL AFTER observaciones',
    'SELECT ''La columna enlace_resultados_drive ya existe en registros_calidad_salida'' AS mensaje'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SHOW COLUMNS FROM registros_calidad_salida LIKE 'enlace_resultados_drive';


-- --------------------------------------------------------------------------
-- 5) PROCESOS COMERCIALES
-- --------------------------------------------------------------------------

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


-- --------------------------------------------------------------------------
-- 6) ROLES Y ACCESOS
-- --------------------------------------------------------------------------

-- ============================================================================
-- PATCH: Roles y accesos (alineado a matriz de usuarios)
-- Fecha: 2026-02-25
-- Idempotente: puede ejecutarse más de una vez.
-- ============================================================================

START TRANSACTION;

-- Administrador
INSERT INTO roles (nombre, descripcion, permisos, activo)
SELECT
    'Administrador',
    'Acceso total al sistema.',
    '{"all": true}',
    1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE LOWER(nombre) = 'administrador'
);

UPDATE roles
SET
    nombre = 'Administrador',
    descripcion = 'Acceso total al sistema.',
    permisos = '{"all": true}',
    activo = 1
WHERE LOWER(nombre) = 'administrador';

-- Recepción
INSERT INTO roles (nombre, descripcion, permisos, activo)
SELECT
    'Recepción',
    'Gestiona ficha de recepción, codificación e impresión de etiqueta.',
    '{"recepcion": true, "codificacion": true, "etiqueta": true}',
    1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE LOWER(nombre) IN ('recepcion', 'recepción')
);

UPDATE roles
SET
    nombre = 'Recepción',
    descripcion = 'Gestiona ficha de recepción, codificación e impresión de etiqueta.',
    permisos = '{"recepcion": true, "codificacion": true, "etiqueta": true}',
    activo = 1
WHERE LOWER(nombre) IN ('recepcion', 'recepción');

-- Operaciones
INSERT INTO roles (nombre, descripcion, permisos, activo)
SELECT
    'Operaciones',
    'Gestiona procesos de centro de acopio y planta.',
    '{"recepcion": true, "codificacion": true, "etiqueta": true, "lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true, "muestras": true}',
    1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE LOWER(nombre) = 'operaciones'
);

UPDATE roles
SET
    nombre = 'Operaciones',
    descripcion = 'Gestiona procesos de centro de acopio y planta.',
    permisos = '{"recepcion": true, "codificacion": true, "etiqueta": true, "lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true, "muestras": true}',
    activo = 1
WHERE LOWER(nombre) = 'operaciones';

-- Pagos
INSERT INTO roles (nombre, descripcion, permisos, activo)
SELECT
    'Pagos',
    'Gestiona registro de pagos y procesos comerciales.',
    '{"pagos": true, "codificacion": true, "etiqueta": true, "proveedores": true, "clientes": true, "muestras": true}',
    1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE LOWER(nombre) = 'pagos'
);

UPDATE roles
SET
    nombre = 'Pagos',
    descripcion = 'Gestiona registro de pagos y procesos comerciales.',
    permisos = '{"pagos": true, "codificacion": true, "etiqueta": true, "proveedores": true, "clientes": true, "muestras": true}',
    activo = 1
WHERE LOWER(nombre) = 'pagos';

-- Supervisor Planta
INSERT INTO roles (nombre, descripcion, permisos, activo)
SELECT
    'Supervisor Planta',
    'Supervisa los procesos operativos de planta.',
    '{"lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true, "muestras": true, "configuracion_panel": true, "configuracion_variedades": true, "configuracion_cajones": true, "configuracion_secadoras": true}',
    1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE LOWER(nombre) = 'supervisor planta'
);

UPDATE roles
SET
    nombre = 'Supervisor Planta',
    descripcion = 'Supervisa los procesos operativos de planta.',
    permisos = '{"lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true, "muestras": true, "configuracion_panel": true, "configuracion_variedades": true, "configuracion_cajones": true, "configuracion_secadoras": true}',
    activo = 1
WHERE LOWER(nombre) = 'supervisor planta';

-- Supervisor Centro de Acopio
INSERT INTO roles (nombre, descripcion, permisos, activo)
SELECT
    'Supervisor Centro de Acopio',
    'Supervisa recepción y abastecimiento del centro de acopio.',
    '{"recepcion": true, "codificacion": true, "etiqueta": true, "proveedores": true, "clientes": true, "muestras": true, "configuracion_panel": true, "configuracion_variedades": true}',
    1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE LOWER(nombre) = 'supervisor centro de acopio'
);

UPDATE roles
SET
    nombre = 'Supervisor Centro de Acopio',
    descripcion = 'Supervisa recepción y abastecimiento del centro de acopio.',
    permisos = '{"recepcion": true, "codificacion": true, "etiqueta": true, "proveedores": true, "clientes": true, "muestras": true, "configuracion_panel": true, "configuracion_variedades": true}',
    activo = 1
WHERE LOWER(nombre) = 'supervisor centro de acopio';

COMMIT;

-- --------------------------------------------------------------------------
-- 7) VALIDACIONES FINALES
-- --------------------------------------------------------------------------
SHOW TABLES LIKE 'clientes';
SHOW TABLES LIKE 'muestras_comerciales';
SHOW COLUMNS FROM registros_calidad_salida LIKE 'enlace_resultados_drive';
SHOW COLUMNS FROM proveedores LIKE 'tipo';

SELECT nombre, permisos
FROM roles
WHERE LOWER(nombre) IN ('pagos', 'operaciones', 'supervisor planta', 'supervisor centro de acopio');

SELECT 'FIN PATCH CONSOLIDADO 2026-05-14 CORREGIDO' AS mensaje;
