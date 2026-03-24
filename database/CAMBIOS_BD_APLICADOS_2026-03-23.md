# Cambios BD aplicados localmente

Base de datos: `megablessing_procesos`
Fecha de corte: `2026-03-23`

Este archivo resume los cambios que si se aplicaron en la base local durante las correcciones recientes.
Los patches SQL relacionados quedan referenciados para poder reusar o auditar cada ajuste.

## 1) fichas_registro.proveedor_ruta

- Cambio aplicado: columna `proveedor_ruta` ampliada a `TEXT NULL`.
- Motivo: corregir el error `SQLSTATE[22001] / Data too long`.
- Patch relacionado: `database/patch_fichas_proveedor_ruta.sql`
- Esquema actualizado tambien en: `database/schema.sql`

## 2) fermentacion_control_diario

- Cambios aplicados:
  - columna `hora_am`
  - columna `volteo_am`
  - columna `hora_pm`
  - columna `volteo_pm`
- Motivo: soportar 2 mediciones por dia (manana y tarde) en lugar del esquema anterior.
- Patch relacionado: `database/patch_fermentacion_control_mediciones.sql`
- Esquema actualizado tambien en: `database/schema.sql`

## 3) parametros_proceso

- Cambios aplicados en categoria `FERMENTACION`:
  - `temp_min = 35`
  - `temp_max = 50`
- Motivo: ajustar el rango valido del control de fermentacion.
- Patch relacionado: `database/patch_fermentacion_control_mediciones.sql`

## 4) registros_fermentacion.peso_final

- Cambio aplicado: columna `peso_final DECIMAL(10,2) NULL`.
- Motivo: permitir guardar el peso final real de fermentacion para que secado tome ese valor como referencia.
- Patch relacionado: `database/patch_fermentacion_peso_final.sql`
- Esquema actualizado tambien en: `database/schema.sql`

## 5) Cambios sin ALTER TABLE

Estos ajustes se hicieron en codigo, pero no requirieron cambio estructural de base:

- `Precio unitario final` mostrado sin `/KG` en fichas.
- Resumenes y equivalencias de peso en `KG / QQ / LB`.
- Empaquetado con captura visible de `Peso Total` en `QQ`, manteniendo almacenamiento interno en `kg`.

## 6) Nota operativa

El cambio de `registros_fermentacion.peso_final` no backfillea datos historicos automaticamente.
Si una fermentacion fue cerrada antes de agregar esa columna y quedo con `peso_final = NULL`, ese dato debe completarse desde la pantalla de control de fermentacion.
