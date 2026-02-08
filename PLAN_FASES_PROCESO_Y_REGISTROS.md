# Megablessing: Plan por Fases (Hoja "PROCESO Y REGISTROS")

## 1) Brecha detectada contra el flujo actual

Tomando como fuente la hoja **PROCESO Y REGISTROS** del Excel, estas son las diferencias funcionales principales frente a lo implementado hoy:

1. Recepción ampliada en acopio:
- Verificación visual (limpieza, olor, moho visible, estado grano).
- Pesaje detallado (peso bruto, tara, peso neto final).
- Clasificación comercial (Apto, Apto con descuento, No apto).
- Determinación de precio (precio base, diferenciales, precio final).
- Registro y pago (tipo de entrega, comprobante).

2. Regla operativa de clasificación:
- Grano **escurrido** va a **pre-secado**.
- Grano **semiseco** va a **fermentación**.

3. Prueba de corte en dos momentos:
- En **calidad de ingreso**.
- En **calidad post-secado**.

4. Secado con dos bloques horarios:
- Diurno (08h00 a 18h00).
- Nocturno (20h00 a 06h00).

5. Requisito de trazabilidad:
- El código de recepción debe mantenerse en todo el proceso.
- Al salir de secado, se incorpora referencia de secadora.

6. Etapas logísticas explicitadas:
- Empaquetado -> Almacenado -> Transporte/Despacho.

## 2) Riesgo técnico previo (importante)

Antes de implementar nuevos cambios funcionales, hay que estabilizar inconsistencias actuales entre modelo de datos y código:

1. El código usa columnas que no aparecen en `/opt/homebrew/var/www/megablessing_procesos/database/schema.sql`.
2. Varios módulos usan nombres de columnas distintos para la misma entidad (fermentación, secado, prueba de corte).
3. No se pudo validar estructura real de BD desde entorno sandbox (`Operation not permitted` al conectar).

Sin resolver esto, cualquier fase funcional puede romper en producción según la BD activa.

## 3) Ejecución por fases

## Fase 0 (base técnica) - Requerida antes de cambios de negocio

Objetivo: alinear contrato de datos real.

Entregables:
1. Diccionario real de tablas/columnas en ambiente objetivo (lotes, fermentación, secado, prueba_corte, empaquetado).
2. Normalización de nombres de columnas en código o capa de compatibilidad.
3. Script de sincronización de esquema para instalaciones nuevas (`database/schema.sql`) y guía de actualización para instalaciones existentes.

## Fase 1 - Recepción, verificación visual y codificación

Objetivo: cubrir la parte inicial de la hoja (acopio + recepción planta).

Cambios:
1. Extender registro de lote con:
- Tipo de entrega.
- Checklist visual cumple/no cumple.
- Pesaje bruto/tara/neto.
- Calidad de compra (apto/descuento/no apto).
- Precio base, diferencial y precio final.
- Método/pago y referencia.
2. Mostrar estos datos en:
- `/opt/homebrew/var/www/megablessing_procesos/lotes/crear.php`
- `/opt/homebrew/var/www/megablessing_procesos/lotes/ver.php`
- `/opt/homebrew/var/www/megablessing_procesos/lotes/index.php` (filtros mínimos de calidad/tipo entrega).
3. Ajustar codificación para mantener código de recepción como identificador raíz.

## Fase 2 - Flujo de estados con Pre-secado

Objetivo: llevar la regla operativa a estados del lote.

Cambios:
1. Actualizar transición en `/opt/homebrew/var/www/megablessing_procesos/lotes/avanzar.php`:
- CALIDAD -> PRE_SECADO (si escurrido).
- CALIDAD -> FERMENTACION (si semiseco).
2. Validaciones de paso por etapa (bloqueos claros antes de avanzar).
3. Historial de cambios con motivo operativo.

## Fase 3 - Fermentación y secado (estructura de registro completa)

Objetivo: registrar datos según formularios R. FERMENTACIÓN y R. SECADO.

Cambios:
1. Fermentación:
- Datos iniciales + control diario + evaluación final.
2. Secado:
- Revisión inicial.
- Carga.
- Temperaturas diurnas y nocturnas.
- Humedad 12h y final.
- Descarga/enfriado.
3. Ajuste de APIs:
- `/opt/homebrew/var/www/megablessing_procesos/api/fermentacion/guardar-control.php`
- `/opt/homebrew/var/www/megablessing_procesos/api/secado/guardar-control.php`

## Fase 4 - Prueba de corte en ingreso y post-secado

Objetivo: habilitar doble punto de control de calidad.

Cambios:
1. Soportar `tipo_prueba` en UI y lógica.
2. Permitir prueba en estado de ingreso y en post-secado.
3. Reglas de decisión del lote (aprobado/rechazado/reproceso/mezcla) por momento del proceso.

## Fase 5 - Empaquetado, almacenado y transporte

Objetivo: cerrar trazabilidad operacional completa.

Cambios:
1. Datos de empaque (sacos/pallets/lote de empaque).
2. Condiciones de almacenado.
3. Validaciones de despacho/transporte.

## Fase 6 - Reportes e indicadores alineados al flujo socializado

Objetivo: que reporte e indicadores reflejen la nueva operación.

Cambios:
1. Reportes por etapa con campos nuevos.
2. Indicadores de la hoja "INDICADORES" conectados a datos reales.
3. Exportables PDF/CSV con trazabilidad completa.

## 4) Orden recomendado de ejecución inmediata

1. Fase 0
2. Fase 1
3. Fase 2
4. Fase 3
5. Fase 4
6. Fase 5
7. Fase 6

---

Este plan toma como fuente la hoja **PROCESO Y REGISTROS** y está preparado para ejecutar cambios incrementales sin perder continuidad operativa.
