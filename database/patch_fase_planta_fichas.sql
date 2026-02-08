-- Patch Fase Planta - Ficha de Registro
-- Ejecutar una sola vez en la base de datos existente.

ALTER TABLE fichas_registro
    ADD COLUMN tipo_entrega ENUM('RUTAS','COMERCIANTE','ENTREGA_INDIVIDUAL') NULL AFTER proveedor_ruta,
    ADD COLUMN revision_limpieza ENUM('CUMPLE','NO_CUMPLE') NULL AFTER fecha_entrada,
    ADD COLUMN revision_olor_normal ENUM('CUMPLE','NO_CUMPLE') NULL AFTER revision_limpieza,
    ADD COLUMN revision_ausencia_moho ENUM('CUMPLE','NO_CUMPLE') NULL AFTER revision_olor_normal,
    ADD COLUMN peso_bruto DECIMAL(10,2) NULL AFTER revision_ausencia_moho,
    ADD COLUMN tara_envase DECIMAL(10,2) NULL AFTER peso_bruto,
    ADD COLUMN peso_final_registro DECIMAL(10,2) NULL AFTER tara_envase,
    ADD COLUMN unidad_peso ENUM('LB','KG','QQ') DEFAULT 'KG' AFTER peso_final_registro,
    ADD COLUMN calificacion_humedad TINYINT NULL AFTER unidad_peso,
    ADD COLUMN calidad_registro ENUM('SECO','SEMISECO','BABA') NULL AFTER calificacion_humedad,
    ADD COLUMN presencia_defectos DECIMAL(5,2) NULL AFTER calidad_registro,
    ADD COLUMN clasificacion_compra ENUM('APTO','APTO_DESCUENTO','NO_APTO','APTO_BONIFICACION') NULL AFTER presencia_defectos,
    ADD COLUMN precio_base_dia DECIMAL(10,4) NULL AFTER clasificacion_compra,
    ADD COLUMN calidad_asignada ENUM('APTO','APTO_DESCUENTO','NO_APTO') NULL AFTER precio_base_dia,
    ADD COLUMN diferencial_usd DECIMAL(10,4) NULL AFTER calidad_asignada,
    ADD COLUMN precio_unitario_final DECIMAL(10,4) NULL AFTER diferencial_usd,
    ADD COLUMN precio_total_pagar DECIMAL(12,2) NULL AFTER precio_unitario_final;
