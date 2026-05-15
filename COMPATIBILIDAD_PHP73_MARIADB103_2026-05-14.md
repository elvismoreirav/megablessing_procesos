# Compatibilidad PHP 7.3 / MariaDB 10.3

Fecha: 2026-05-14

## Hallazgo puntual corregido

- Se corrigio [configuracion/index.php](/opt/homebrew/var/www/megablessing_procesos/configuracion/index.php) para no usar `SHOW TABLES LIKE ?` con prepared statements.
- Se agrego `Database::tableExists()` en [core/Database.php](/opt/homebrew/var/www/megablessing_procesos/core/Database.php), usando `information_schema.TABLES`.
- Se agregaron polyfills en [bootstrap.php](/opt/homebrew/var/www/megablessing_procesos/bootstrap.php) para:
  - `str_contains`
  - `str_starts_with`
  - `str_ends_with`

## Estado del dump revisado

El archivo [megablessing.sql](/Users/elviseduardomoreiravillamar/Downloads/megablessing.sql) fue exportado desde:

- MariaDB `10.3.15`
- PHP `7.3.33`

Contiene las tablas relevantes revisadas:

- `cajones_fermentacion`
- `clientes`
- `muestras_comerciales`
- `registros_calidad_salida`
- `proveedores`
- `roles`
- `usuarios`

## Riesgos detectados

El codigo fuente actual ya no es plenamente compatible con PHP 7.3 por sintaxis, no solo por funciones.

Conteo aproximado:

- Archivos con `match (...)`: 29
- Archivos con arrow functions `fn(...) =>`: 52
- Archivos con `str_contains(...)`: 8
- Archivos con `str_starts_with(...)`: 9
- Archivos con `str_ends_with(...)`: 1

## Conclusion tecnica

MariaDB 10.3 no es hoy el principal bloqueo. El principal riesgo operativo es PHP.

Si el servidor de aplicacion realmente corre PHP 7.3:

- las funciones `str_contains`, `str_starts_with`, `str_ends_with` ya quedaron cubiertas;
- pero la sintaxis `match` y `fn` seguira provocando errores fatales de parseo en multiples pantallas.

## Recomendacion

La salida optima es ejecutar la aplicacion con PHP `8.0+`.

Si eso no es posible, hay que abrir una tarea de refactor de compatibilidad para reemplazar:

- `match` por `switch` / `if`
- `fn(...) =>` por closures `function (...) use (...) { ... }`

Ese trabajo afecta decenas de archivos y conviene hacerlo por fases, no de forma aislada.
