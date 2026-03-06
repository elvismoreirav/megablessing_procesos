# Consolidado de cambios y scripts

Fecha: 2026-03-05

Este archivo centraliza los cambios funcionales implementados y los scripts SQL necesarios o recomendados para dejar la base alineada con esos cambios.

## 1. Cambios funcionales implementados

### 1.1 Proveedores, rutas y selectores

- Se agrego un buscador reutilizable para `select` de proveedores, evitando que la seleccion sea engorrosa cuando crece el catalogo.
- El campo `ruta_entrega` ahora muestra solo proveedores de tipo `RUTA`.
- Se renombro la categoria visible `Bodega (B)` a `Centro de Acopio (CA)`.
- Se habilito la eliminacion de rutas/categorias y de proveedores desde la pantalla de configuracion.
- Se corrigio el error JavaScript que impedia abrir la edicion de categorias/rutas.

Archivos de codigo:

- `assets/js/app.js`
- `assets/css/app.css`
- `lotes/crear.php`
- `lotes/index.php`
- `fichas/crear.php`
- `fichas/editar.php`
- `reportes/lotes.php`
- `reportes/fermentacion.php`
- `configuracion/proveedores.php`
- `core/Helpers.php`

### 1.2 Fichas, lotes, codificacion y pagos

- En lotes tipo ruta con varios proveedores, el pago ahora se registra de forma individual por proveedor, pero dentro de la misma ficha/lote.
- Se agrego soporte para detalle de pagos por proveedor con conversion entre `KG`, `LB` y `QQ`.
- En la codificacion del lote tipo ruta ahora se muestran la ruta y los proveedores en la parte superior.
- Se cambio el texto de referencia para que no parezca un lote duplicado, sino un codigo base de referencia.

Archivos de codigo:

- `fichas/pago.php`
- `fichas/ver.php`
- `fichas/index.php`
- `lotes/ver.php`
- `fichas/codificacion.php`
- `fichas/crear.php`
- `fichas/editar.php`
- `core/Helpers.php`

### 1.3 Cajones de fermentacion

- Se parametrizo el catalogo de cajones para permitir crecimiento futuro.
- Se dejaron 6 cajones base activos como configuracion objetivo.
- Se corrigio el warning de `htmlspecialchars(null)` cuando algun cajon o secadora no traia nombre.
- En los selectores de `Cajon de Fermentacion` el valor vacio ahora aparece como `No aplica`.

Archivos de codigo:

- `core/Helpers.php`
- `lotes/editar.php`
- `fermentacion/crear.php`
- `fermentacion/control.php`
- `configuracion/cajones.php`
- `configuracion/index.php`

### 1.4 Fermentacion

- En el control diario se aclararon las franjas diurnas visibles, dejando rotulos `08h-12h` y `12h-18h`.
- El `Hora Volteo` ahora incluye horas diurnas y nocturnas.
- Los visualizadores de peso se cambiaron para mostrar `QQ` y `LB` como referencia principal.
- Al finalizar fermentacion el usuario puede ingresar el peso final en `QQ`, `LB` o `KG`, y el sistema convierte a kg internamente.

Archivos de codigo:

- `fermentacion/control.php`
- `fermentacion/ver.php`
- `api/fermentacion/finalizar.php`
- `core/Helpers.php`

### 1.5 Secado

- La ficha de secado ahora muestra tambien el bloque nocturno.
- Se hizo compatible la vista `secado/ver.php` con diferentes estructuras de base de datos, evitando depender de columnas fijas como `s.codigo`.
- Se corrigio el warning de `DateTime(null)` cuando no existe `fecha_fin`.

Archivos de codigo:

- `secado/control.php`
- `secado/ver.php`

### 1.6 Empaquetado

- El saco estandar se cambio a `69 kg`.
- La pantalla de empaquetado toma ese valor desde `GENERAL.peso_saco_kg`.
- La presentacion de tipos de empaque se normalizo para mostrar nombres legibles.

Archivos de codigo:

- `empaquetado/crear.php`
- `empaquetado/registrar.php`
- `empaquetado/ver.php`
- `core/Helpers.php`

## 2. Scripts SQL requeridos o generados

### 2.1 Script de proveedores y rutas

Archivo:

- `database/patch_proveedores_parametrizacion.sql`

Uso:

- Parametrizacion inicial de categorias y rutas.
- Normalizacion de rutas base.
- Renombrado de categoria base `Bodega` a `Centro de Acopio`.
- Actualizacion de catalogo relacionado con proveedores.

### 2.2 Script de detalle de pagos por proveedor

Archivo:

- `database/patch_fichas_pago_detalle.sql`

Uso:

- Crea la tabla `fichas_pago_detalle`.
- Permite almacenar pagos individuales por proveedor dentro de una misma ficha/lote.
- Agrega indices y relaciones para consulta por ficha y proveedor.

### 2.3 Script de cajones base

Archivo:

- `database/patch_cajones_base.sql`

Uso:

- Asegura el catalogo minimo de 6 cajones de fermentacion.
- Inserta o actualiza el parametro `GENERAL.cajones_fermentacion_objetivo = 6`.
- Deja la configuracion lista para ampliar el numero de cajones despues.

### 2.4 Esquema base actualizado

Archivo:

- `database/schema.sql`

Cambios reflejados en el esquema:

- Tabla `fichas_pago_detalle`.
- Parametro `GENERAL.cajones_fermentacion_objetivo = 6`.
- Parametro `GENERAL.peso_saco_kg = 69`.
- Datos iniciales de proveedores base y rutas.
- Etiquetas y catalogos alineados con `Centro de Acopio`.

## 3. Script complementario para instalaciones existentes

Si la base ya estaba creada y no se va a reinstalar desde `database/schema.sql`, este ajuste adicional deja el saco estandar en `69 kg`:

```sql
INSERT INTO parametros_proceso (
    categoria,
    clave,
    valor,
    tipo,
    descripcion,
    editable
) VALUES (
    'GENERAL',
    'peso_saco_kg',
    '69',
    'NUMBER',
    'Peso estandar por saco (kg)',
    1
)
ON DUPLICATE KEY UPDATE
    valor = VALUES(valor),
    tipo = VALUES(tipo),
    descripcion = VALUES(descripcion),
    editable = VALUES(editable);
```

## 4. Orden sugerido de aplicacion

1. Ejecutar `database/patch_proveedores_parametrizacion.sql`.
2. Ejecutar `database/patch_fichas_pago_detalle.sql`.
3. Ejecutar `database/patch_cajones_base.sql`.
4. Ejecutar el bloque complementario de `peso_saco_kg = 69` solo si la base existente no tiene ese parametro.
5. Desplegar el codigo actualizado.

## 5. Cambios que fueron solo de codigo

Los siguientes ajustes no requieren script SQL adicional:

- Buscador en `select` de proveedores.
- Filtro de `ruta_entrega` para mostrar solo tipo `RUTA`.
- Ajustes visuales en codificacion de lote tipo ruta.
- Correccion del JavaScript de edicion de categorias/rutas.
- Etiquetas `No aplica` en cajon de fermentacion.
- Ajustes de horarios diurnos y nocturnos en fermentacion y secado.
- Compatibilidad y correcciones de `secado/ver.php`.
- Visualizacion de pesos en `QQ` y `LB`.
