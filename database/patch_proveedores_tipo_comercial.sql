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
