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
    '{"recepcion": true, "codificacion": true, "etiqueta": true, "lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true}',
    1
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE LOWER(nombre) = 'operaciones'
);

UPDATE roles
SET
    nombre = 'Operaciones',
    descripcion = 'Gestiona procesos de centro de acopio y planta.',
    permisos = '{"recepcion": true, "codificacion": true, "etiqueta": true, "lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true}',
    activo = 1
WHERE LOWER(nombre) = 'operaciones';

-- Pagos
INSERT INTO roles (nombre, descripcion, permisos, activo)
SELECT
    'Pagos',
    'Gestiona registro de pagos y acceso a proveedores.',
    '{"pagos": true, "codificacion": true, "etiqueta": true, "proveedores": true}',
    1
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE LOWER(nombre) = 'pagos'
);

UPDATE roles
SET
    nombre = 'Pagos',
    descripcion = 'Gestiona registro de pagos y acceso a proveedores.',
    permisos = '{"pagos": true, "codificacion": true, "etiqueta": true, "proveedores": true}',
    activo = 1
WHERE LOWER(nombre) = 'pagos';

-- Supervisor Planta
INSERT INTO roles (nombre, descripcion, permisos, activo)
SELECT
    'Supervisor Planta',
    'Acceso a todos los módulos, excepto registro de pagos.',
    '{"recepcion": true, "codificacion": true, "etiqueta": true, "proveedores": true, "lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true, "reportes": true, "indicadores": true, "configuracion": true, "usuarios": true}',
    1
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE LOWER(nombre) = 'supervisor planta'
);

UPDATE roles
SET
    nombre = 'Supervisor Planta',
    descripcion = 'Acceso a todos los módulos, excepto registro de pagos.',
    permisos = '{"recepcion": true, "codificacion": true, "etiqueta": true, "proveedores": true, "lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true, "reportes": true, "indicadores": true, "configuracion": true, "usuarios": true}',
    activo = 1
WHERE LOWER(nombre) = 'supervisor planta';

-- Supervisor Centro de Acopio
INSERT INTO roles (nombre, descripcion, permisos, activo)
SELECT
    'Supervisor Centro de Acopio',
    'Acceso a todos los módulos, excepto registro de pagos.',
    '{"recepcion": true, "codificacion": true, "etiqueta": true, "proveedores": true, "lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true, "reportes": true, "indicadores": true, "configuracion": true, "usuarios": true}',
    1
WHERE NOT EXISTS (
    SELECT 1 FROM roles WHERE LOWER(nombre) = 'supervisor centro de acopio'
);

UPDATE roles
SET
    nombre = 'Supervisor Centro de Acopio',
    descripcion = 'Acceso a todos los módulos, excepto registro de pagos.',
    permisos = '{"recepcion": true, "codificacion": true, "etiqueta": true, "proveedores": true, "lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true, "reportes": true, "indicadores": true, "configuracion": true, "usuarios": true}',
    activo = 1
WHERE LOWER(nombre) = 'supervisor centro de acopio';

COMMIT;
