-- ============================================================================
-- PATCH: Reset de lotes de practica conservando proveedores reales
-- Fecha: 2026-05-14
--
-- Conserva unicamente los lotes asociados a los proveedores:
--   - PROV0006
--   - PROV0007
--
-- Elimina todos los demas lotes de la tabla `lotes`. La eliminacion se propaga
-- por ON DELETE CASCADE a fichas, fermentacion, secado, prueba de corte,
-- empaquetado, calidad de salida e historial asociados.
-- ============================================================================

SET @db_name = DATABASE();

DROP TEMPORARY TABLE IF EXISTS tmp_proveedores_protegidos;
CREATE TEMPORARY TABLE tmp_proveedores_protegidos (
    codigo VARCHAR(20) NOT NULL PRIMARY KEY
);

INSERT INTO tmp_proveedores_protegidos (codigo)
VALUES ('PROV0006'), ('PROV0007');

SET @missing_protected = (
    SELECT COUNT(*)
    FROM tmp_proveedores_protegidos tpp
    LEFT JOIN proveedores p
        ON p.codigo = tpp.codigo
    WHERE p.id IS NULL
);

SELECT tpp.codigo AS proveedor_protegido_faltante
FROM tmp_proveedores_protegidos tpp
LEFT JOIN proveedores p
    ON p.codigo = tpp.codigo
WHERE p.id IS NULL;

SELECT CASE
    WHEN @missing_protected = 0 THEN 'Validacion de proveedores protegidos OK'
    ELSE 'ABORTADO: faltan proveedores protegidos requeridos (PROV0006 y/o PROV0007). No se eliminara ningun lote.'
END AS mensaje;

DROP TEMPORARY TABLE IF EXISTS tmp_lotes_protegidos;
CREATE TEMPORARY TABLE tmp_lotes_protegidos AS
SELECT
    l.id,
    l.codigo,
    p.codigo AS proveedor_codigo
FROM lotes l
INNER JOIN proveedores p
    ON p.id = l.proveedor_id
INNER JOIN tmp_proveedores_protegidos tpp
    ON tpp.codigo = p.codigo;

SELECT
    proveedor_codigo,
    COUNT(*) AS lotes_conservados
FROM tmp_lotes_protegidos
GROUP BY proveedor_codigo
ORDER BY proveedor_codigo;

SELECT COUNT(*) INTO @lotes_a_eliminar
FROM lotes l
LEFT JOIN tmp_lotes_protegidos tlp
    ON tlp.id = l.id
WHERE tlp.id IS NULL;

SELECT @lotes_a_eliminar AS lotes_a_eliminar;

START TRANSACTION;

DELETE l
FROM lotes l
LEFT JOIN tmp_lotes_protegidos tlp
    ON tlp.id = l.id
WHERE tlp.id IS NULL
  AND @missing_protected = 0;

SELECT ROW_COUNT() AS lotes_eliminados;

COMMIT;

SELECT
    COUNT(*) AS lotes_restantes,
    SUM(CASE WHEN p.codigo = 'PROV0006' THEN 1 ELSE 0 END) AS lotes_prov0006,
    SUM(CASE WHEN p.codigo = 'PROV0007' THEN 1 ELSE 0 END) AS lotes_prov0007
FROM lotes l
INNER JOIN proveedores p
    ON p.id = l.proveedor_id;
