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

UPDATE proveedores
SET codigo = 'M',
    nombre = 'Comerciales',
    tipo = 'COMERCIAL',
    categoria = 'COMERCIALES',
    es_categoria = 1
WHERE UPPER(COALESCE(codigo, '')) = 'M'
   OR (
       COALESCE(es_categoria, 0) = 1
       AND (
           UPPER(COALESCE(categoria, '')) IN ('MERCADO', 'COMERCIALES')
           OR UPPER(COALESCE(nombre, '')) IN ('MERCADO', 'COMERCIALES', 'COMERCIAL')
       )
   );

UPDATE proveedores
SET codigo = 'CA',
    nombre = 'Centro de Acopio',
    tipo = 'COMERCIAL',
    categoria = 'CENTRO DE ACOPIO',
    es_categoria = 1
WHERE UPPER(COALESCE(codigo, '')) IN ('B', 'CA')
   OR (
       COALESCE(es_categoria, 0) = 1
       AND (
           UPPER(COALESCE(categoria, '')) IN ('BODEGA', 'CENTRO DE ACOPIO')
           OR UPPER(COALESCE(nombre, '')) IN ('BODEGA', 'CENTRO DE ACOPIO')
       )
   );

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
