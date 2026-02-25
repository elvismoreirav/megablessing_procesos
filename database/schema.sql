-- ========================================================================
-- MEGABLESSING - Sistema de Control de Procesos de Cacao
-- Base de Datos: MySQL / MariaDB
-- Desarrollado por: Shalom Software
-- ========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ========================================================================
-- TABLAS DE CONFIGURACIÓN Y USUARIOS
-- ========================================================================

-- Tabla de Empresa/Configuración General
CREATE TABLE IF NOT EXISTS `empresa` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nombre` VARCHAR(200) NOT NULL,
    `ruc` VARCHAR(20),
    `direccion` TEXT,
    `telefono` VARCHAR(50),
    `email` VARCHAR(100),
    `logo` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de Roles de Usuario
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nombre` VARCHAR(50) NOT NULL,
    `descripcion` TEXT,
    `permisos` JSON,
    `activo` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de Usuarios
CREATE TABLE IF NOT EXISTS `usuarios` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nombre` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) UNIQUE NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `rol_id` INT NOT NULL,
    `avatar` VARCHAR(255),
    `activo` TINYINT(1) DEFAULT 1,
    `ultimo_acceso` DATETIME,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`rol_id`) REFERENCES `roles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================================
-- TABLAS DE CONFIGURACIÓN DE PROCESOS
-- ========================================================================

-- Proveedores/Rutas
CREATE TABLE IF NOT EXISTS `proveedores` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `codigo` VARCHAR(20) NOT NULL UNIQUE,
    `codigo_identificacion` VARCHAR(20),
    `nombre` VARCHAR(100) NOT NULL,
    `cedula_ruc` VARCHAR(20),
    `tipo` ENUM('MERCADO','BODEGA','RUTA','PRODUCTOR') NOT NULL,
    `categoria` VARCHAR(100),
    `tipos_permitidos` VARCHAR(120),
    `es_categoria` TINYINT(1) NOT NULL DEFAULT 0,
    `direccion` TEXT,
    `utm_este_x` VARCHAR(50),
    `utm_norte_y` VARCHAR(50),
    `seguridad_deforestacion` TINYINT(1),
    `arboles_endemicos` TINYINT(1),
    `hectareas_totales` DECIMAL(10,2),
    `hectareas_ccn51` DECIMAL(10,2),
    `hectareas_fino_aroma` DECIMAL(10,2),
    `certificaciones` TEXT,
    `certificacion_otras` VARCHAR(255),
    `documento_certificaciones` VARCHAR(255),
    `telefono` VARCHAR(50),
    `email` VARCHAR(120),
    `contacto` VARCHAR(100),
    `activo` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Variedades de Cacao
CREATE TABLE IF NOT EXISTS `variedades` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `codigo` VARCHAR(10) NOT NULL UNIQUE,
    `nombre` VARCHAR(100) NOT NULL,
    `descripcion` TEXT,
    `activo` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Estados del Producto
CREATE TABLE IF NOT EXISTS `estados_producto` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `codigo` VARCHAR(10) NOT NULL UNIQUE,
    `nombre` VARCHAR(50) NOT NULL,
    `descripcion` TEXT,
    `activo` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Estados de Fermentación
CREATE TABLE IF NOT EXISTS `estados_fermentacion` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `codigo` VARCHAR(10) NOT NULL UNIQUE,
    `nombre` VARCHAR(50) NOT NULL,
    `descripcion` TEXT,
    `activo` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Secadoras
CREATE TABLE IF NOT EXISTS `secadoras` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `numero` VARCHAR(20) NOT NULL UNIQUE,
    `nombre` VARCHAR(100),
    `capacidad_qq` DECIMAL(10,2),
    `tipo` ENUM('INDUSTRIAL','ARTESANAL','SOLAR') DEFAULT 'INDUSTRIAL',
    `ubicacion` VARCHAR(100),
    `activo` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cajones de Fermentación
CREATE TABLE IF NOT EXISTS `cajones_fermentacion` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `numero` VARCHAR(20) NOT NULL UNIQUE,
    `capacidad_kg` DECIMAL(10,2),
    `material` VARCHAR(50),
    `ubicacion` VARCHAR(100),
    `activo` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================================
-- TABLAS DE INDICADORES
-- ========================================================================

CREATE TABLE IF NOT EXISTS `indicadores` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `etapa_proceso` VARCHAR(100) NOT NULL,
    `nombre` VARCHAR(100) NOT NULL,
    `meta` VARCHAR(50),
    `formula` TEXT,
    `frecuencia` ENUM('DIARIA','SEMANAL','MENSUAL','TRIMESTRAL','SEMESTRAL','POR_LOTE','POR_EMBARQUE') NOT NULL,
    `justificacion` TEXT,
    `valor_minimo` DECIMAL(10,4),
    `valor_maximo` DECIMAL(10,4),
    `unidad` VARCHAR(20),
    `activo` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registros manuales de indicadores
CREATE TABLE IF NOT EXISTS `indicadores_registros` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `indicador_id` INT NOT NULL,
    `fecha` DATE NOT NULL,
    `valor` DECIMAL(12,4),
    `referencia` VARCHAR(100),
    `detalle` TEXT,
    `usuario_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`indicador_id`) REFERENCES `indicadores`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================================
-- TABLA PRINCIPAL DE LOTES
-- ========================================================================

CREATE TABLE IF NOT EXISTS `lotes` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `codigo` VARCHAR(50) NOT NULL UNIQUE,
    `proveedor_id` INT NOT NULL,
    `variedad_id` INT NOT NULL,
    `estado_producto_id` INT NOT NULL,
    `estado_fermentacion_id` INT,
    `fecha_entrada` DATE NOT NULL,
    `peso_inicial_kg` DECIMAL(10,2) NOT NULL,
    `peso_qq` DECIMAL(10,2),
    `humedad_inicial` DECIMAL(5,2),
    `observaciones` TEXT,
    `estado_proceso` ENUM('RECEPCION','CALIDAD','PRE_SECADO','FERMENTACION','SECADO','CALIDAD_POST','CALIDAD_SALIDA','EMPAQUETADO','ALMACENADO','DESPACHO','FINALIZADO','RECHAZADO') DEFAULT 'RECEPCION',
    `usuario_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`),
    FOREIGN KEY (`variedad_id`) REFERENCES `variedades`(`id`),
    FOREIGN KEY (`estado_producto_id`) REFERENCES `estados_producto`(`id`),
    FOREIGN KEY (`estado_fermentacion_id`) REFERENCES `estados_fermentacion`(`id`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================================
-- FICHA DE REGISTRO GENERAL
-- ========================================================================

CREATE TABLE IF NOT EXISTS `fichas_registro` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `lote_id` INT NOT NULL,
    `producto` VARCHAR(100),
    `codificacion` VARCHAR(50),
    `proveedor_ruta` VARCHAR(100),
    `tipo_entrega` ENUM('RUTAS','COMERCIANTE','ENTREGA_INDIVIDUAL'),
    `fecha_entrada` DATE,
    `revision_limpieza` ENUM('CUMPLE','NO_CUMPLE'),
    `revision_olor_normal` ENUM('CUMPLE','NO_CUMPLE'),
    `revision_ausencia_moho` ENUM('CUMPLE','NO_CUMPLE'),
    `peso_bruto` DECIMAL(10,2),
    `tara_envase` DECIMAL(10,2),
    `peso_final_registro` DECIMAL(10,2),
    `unidad_peso` ENUM('LB','KG','QQ') DEFAULT 'KG',
    `calificacion_humedad` TINYINT,
    `calidad_registro` ENUM('SECO','SEMISECO','ESCURRIDO','BABA'),
    `presencia_defectos` DECIMAL(5,2),
    `clasificacion_compra` ENUM('APTO','APTO_DESCUENTO','NO_APTO','APTO_BONIFICACION'),
    `precio_base_dia` DECIMAL(10,4),
    `calidad_asignada` ENUM('APTO','APTO_DESCUENTO','NO_APTO'),
    `diferencial_usd` DECIMAL(10,4),
    `precio_unitario_final` DECIMAL(10,4),
    `precio_total_pagar` DECIMAL(12,2),
    `fecha_pago` DATE,
    `tipo_comprobante` ENUM('FACTURA','NOTA_COMPRA'),
    `factura_compra` VARCHAR(80),
    `cantidad_comprada_unidad` ENUM('LB','KG','QQ') DEFAULT 'KG',
    `cantidad_comprada` DECIMAL(10,2),
    `forma_pago` ENUM('EFECTIVO','TRANSFERENCIA','CHEQUE','OTROS'),
    `fermentacion_estado` VARCHAR(50),
    `secado_inicio` DATETIME,
    `secado_fin` DATETIME,
    `temperatura` DECIMAL(5,2),
    `tiempo_horas` DECIMAL(6,2),
    `responsable_id` INT,
    `observaciones` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`lote_id`) REFERENCES `lotes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`responsable_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================================
-- REGISTRO DE FERMENTACIÓN
-- ========================================================================

CREATE TABLE IF NOT EXISTS `registros_fermentacion` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `lote_id` INT NOT NULL,
    `cajon_id` INT,
    `fecha_inicio` DATE NOT NULL,
    `peso_lote_kg` DECIMAL(10,2),
    -- Datos iniciales del grano
    `humedad_inicial` DECIMAL(5,2),
    `temperatura_inicial` DECIMAL(5,2),
    `ph_pulpa_inicial` DECIMAL(4,2),
    `grado_madurez` VARCHAR(50),
    -- Evaluación Final
    `aroma_final` VARCHAR(100),
    `color_cotiledon` VARCHAR(100),
    `porcentaje_violeta` DECIMAL(5,2),
    `porcentaje_pizarrosos` DECIMAL(5,2),
    `porcentaje_fermentados` DECIMAL(5,2),
    `porcentaje_mohosos` DECIMAL(5,2),
    `humedad_final` DECIMAL(5,2),
    `aprobado_secado` TINYINT(1) DEFAULT 0,
    `observaciones_generales` TEXT,
    `responsable_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`lote_id`) REFERENCES `lotes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`cajon_id`) REFERENCES `cajones_fermentacion`(`id`),
    FOREIGN KEY (`responsable_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Control diario de fermentación
CREATE TABLE IF NOT EXISTS `fermentacion_control_diario` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `registro_fermentacion_id` INT NOT NULL,
    `dia` INT NOT NULL,
    `hora` TIME,
    `volteo` TINYINT(1) DEFAULT 0,
    `temp_masa` DECIMAL(5,2),
    `temp_ambiente` DECIMAL(5,2),
    `temp_20h` DECIMAL(5,2),
    `temp_22h` DECIMAL(5,2),
    `temp_24h` DECIMAL(5,2),
    `temp_02h` DECIMAL(5,2),
    `temp_04h` DECIMAL(5,2),
    `ph_pulpa` DECIMAL(4,2),
    `ph_cotiledon` DECIMAL(4,2),
    `olor` VARCHAR(100),
    `color` VARCHAR(100),
    `observaciones` TEXT,
    `responsable_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`registro_fermentacion_id`) REFERENCES `registros_fermentacion`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`responsable_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================================
-- REGISTRO DE SECADO
-- ========================================================================

CREATE TABLE IF NOT EXISTS `registros_secado` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `lote_id` INT NOT NULL,
    `secadora_id` INT,
    -- Datos Generales
    `fecha` DATE NOT NULL,
    `responsable_id` INT,
    `variedad` VARCHAR(100),
    `estado` VARCHAR(50),
    `etapa_proceso` ENUM('PRE_SECADO','SECADO_FINAL') NULL,
    `cantidad_total_qq` DECIMAL(10,2),
    -- Revisión inicial
    `limpieza_area` TINYINT(1) DEFAULT 0,
    `secadora_limpia` TINYINT(1) DEFAULT 0,
    `verificacion_energia` TINYINT(1) DEFAULT 0,
    `bandejas_limpias` TINYINT(1) DEFAULT 0,
    `termometros_funcionando` TINYINT(1) DEFAULT 0,
    `registro_clima` TINYINT(1) DEFAULT 0,
    `revision_observaciones` TEXT,
    -- Carga de cacao
    `fecha_carga` DATETIME,
    `hora_carga` TIME,
    `qq_cargados` DECIMAL(10,2),
    `humedad_inicial` DECIMAL(5,2),
    `carga_observaciones` TEXT,
    -- Control de humedad
    `humedad_12h` DECIMAL(5,2),
    `humedad_final` DECIMAL(5,2),
    `humedad_observaciones` TEXT,
    -- Descarga y enfriado
    `hora_descarga` TIME,
    `temperatura_grano` DECIMAL(5,2),
    `color_olor_adecuado` TINYINT(1) DEFAULT 0,
    `descarga_observaciones` TEXT,
    `firma_responsable` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`lote_id`) REFERENCES `lotes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`secadora_id`) REFERENCES `secadoras`(`id`),
    FOREIGN KEY (`responsable_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Control de temperatura del secado (cada 2 horas)
CREATE TABLE IF NOT EXISTS `secado_control_temperatura` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `registro_secado_id` INT NOT NULL,
    `hora` VARCHAR(10) NOT NULL,
    `temperatura` DECIMAL(5,2),
    `turno` ENUM('DIURNO','NOCTURNO') DEFAULT 'DIURNO',
    `observaciones` TEXT,
    `responsable_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`registro_secado_id`) REFERENCES `registros_secado`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`responsable_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================================
-- REGISTRO DE PRUEBA DE CORTE
-- ========================================================================

CREATE TABLE IF NOT EXISTS `registros_prueba_corte` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `lote_id` INT NOT NULL,
    `tipo_prueba` ENUM('RECEPCION','POST_SECADO') NOT NULL,
    -- Datos del lote
    `fecha` DATE NOT NULL,
    `codigo_lote` VARCHAR(50),
    `proveedor_origen` VARCHAR(100),
    `tipo_cacao` VARCHAR(100),
    `estado` VARCHAR(50),
    `cantidad_qq` DECIMAL(10,2),
    -- Datos de la muestra
    `granos_analizados` INT,
    `peso_100_granos` DECIMAL(6,2),
    `granos_en_100g` INT,
    `responsable_analisis_id` INT,
    -- Resultados prueba de corte (base 100 granos)
    `bien_fermentados` DECIMAL(5,2),
    `violeta` DECIMAL(5,2),
    `pizarrosos` DECIMAL(5,2),
    `mohosos` DECIMAL(5,2),
    `insectados` DECIMAL(5,2),
    `germinados` DECIMAL(5,2),
    `planos_vanos` DECIMAL(5,2),
    -- Evaluación y Decisión
    `defectos_totales` DECIMAL(5,2),
    `cumple_especificacion` TINYINT(1) DEFAULT 0,
    `decision_lote` ENUM('APROBADO','RECHAZADO','REPROCESO','MEZCLA') DEFAULT 'APROBADO',
    -- Acción correctiva
    `no_conformidad` TEXT,
    `accion_tomada` TEXT,
    `responsable_accion_id` INT,
    `fecha_accion` DATE,
    `observaciones` TEXT,
    `firma_responsable` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`lote_id`) REFERENCES `lotes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`responsable_analisis_id`) REFERENCES `usuarios`(`id`),
    FOREIGN KEY (`responsable_accion_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================================
-- REGISTRO DE EMPAQUETADO
-- ========================================================================

CREATE TABLE IF NOT EXISTS `registros_empaquetado` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `lote_id` INT NOT NULL,
    `tipo_empaque` VARCHAR(30) NOT NULL,
    `peso_saco` DECIMAL(10,2) NOT NULL,
    `fecha_empaquetado` DATE NULL,
    `numero_sacos` INT NULL,
    `peso_total` DECIMAL(10,2) NULL,
    `lote_empaque` VARCHAR(80) NULL,
    `destino` VARCHAR(150) NULL,
    `observaciones` TEXT,
    `operador_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`lote_id`) REFERENCES `lotes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`operador_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================================
-- REGISTRO DE CALIDAD DE SALIDA
-- ========================================================================

CREATE TABLE IF NOT EXISTS `registros_calidad_salida` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `lote_id` INT NOT NULL,
    `fecha_registro` DATE NOT NULL,
    `fichas_conforman_lote` VARCHAR(255) NOT NULL,
    `categoria_proveedor` VARCHAR(120) NOT NULL,
    `fecha_entrada` DATE NOT NULL,
    `variedad` VARCHAR(100) NOT NULL,
    `grado_calidad` ENUM('GRADO_1','GRADO_2','GRADO_3','NO_APLICA') DEFAULT 'NO_APLICA',
    `estado_producto` VARCHAR(50) NOT NULL,
    `estado_fermentacion` VARCHAR(50) NOT NULL,
    `certificaciones` JSON,
    `certificaciones_texto` VARCHAR(255),
    `otra_certificacion` VARCHAR(120),
    `observaciones` TEXT,
    `usuario_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_calidad_salida_lote` (`lote_id`),
    FOREIGN KEY (`lote_id`) REFERENCES `lotes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================================
-- PARÁMETROS CONFIGURABLES
-- ========================================================================

CREATE TABLE IF NOT EXISTS `parametros_proceso` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `categoria` VARCHAR(50) NOT NULL,
    `clave` VARCHAR(100) NOT NULL,
    `valor` TEXT,
    `tipo` ENUM('TEXT','NUMBER','BOOLEAN','JSON','DATE') DEFAULT 'TEXT',
    `descripcion` TEXT,
    `editable` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `categoria_clave` (`categoria`, `clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historial de cambios de lotes
CREATE TABLE IF NOT EXISTS `lotes_historial` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `lote_id` INT NOT NULL,
    `accion` VARCHAR(100) NOT NULL,
    `descripcion` TEXT,
    `datos_anteriores` JSON,
    `datos_nuevos` JSON,
    `usuario_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`lote_id`) REFERENCES `lotes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================================
-- DATOS INICIALES
-- ========================================================================

-- Roles por defecto
INSERT INTO `roles` (`nombre`, `descripcion`, `permisos`) VALUES
('Administrador', 'Acceso total al sistema.', '{"all": true}'),
('Recepción', 'Gestiona ficha de recepción, codificación e impresión de etiqueta.', '{"recepcion": true, "codificacion": true, "etiqueta": true}'),
('Operaciones', 'Gestiona procesos de centro de acopio y planta.', '{"recepcion": true, "codificacion": true, "etiqueta": true, "lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true}'),
('Pagos', 'Gestiona registro de pagos y acceso a proveedores.', '{"pagos": true, "codificacion": true, "etiqueta": true, "proveedores": true}'),
('Supervisor Planta', 'Acceso a todos los módulos, excepto registro de pagos.', '{"recepcion": true, "codificacion": true, "etiqueta": true, "proveedores": true, "lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true, "reportes": true, "indicadores": true, "configuracion": true, "usuarios": true}'),
('Supervisor Centro de Acopio', 'Acceso a todos los módulos, excepto registro de pagos.', '{"recepcion": true, "codificacion": true, "etiqueta": true, "proveedores": true, "lotes": true, "fermentacion": true, "secado": true, "prueba_corte": true, "calidad_salida": true, "reportes": true, "indicadores": true, "configuracion": true, "usuarios": true}');

-- Usuario administrador por defecto (password: admin123)
INSERT INTO `usuarios` (`nombre`, `email`, `password`, `rol_id`) VALUES
('Administrador', 'admin@megablessing.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);

-- Categorías base de proveedores
INSERT INTO `proveedores` (`codigo`, `codigo_identificacion`, `nombre`, `tipo`, `categoria`, `es_categoria`) VALUES
('M', NULL, 'Mercado', 'MERCADO', 'MERCADO', 1),
('B', NULL, 'Bodega', 'BODEGA', 'BODEGA', 1),
('ES', NULL, 'Esmeraldas', 'RUTA', 'ESMERALDAS', 1),
('FM', NULL, 'Flor de Manabí', 'RUTA', 'FLOR DE MANABI', 1),
('VP', NULL, 'Vía Pedernales', 'RUTA', 'VIA PEDERNALES', 1);

-- Variedades de cacao
INSERT INTO `variedades` (`codigo`, `nombre`, `descripcion`) VALUES
('CCN51', 'CCN-51', 'Clon híbrido de alta productividad'),
('NAC', 'Nacional', 'Cacao fino de aroma ecuatoriano'),
('ORG', 'Orgánico', 'Cacao con certificación orgánica'),
('CONV', 'Convencional', 'Cacao convencional');

-- Estados del producto
INSERT INTO `estados_producto` (`codigo`, `nombre`, `descripcion`) VALUES
('SC', 'Seco', 'Grano con humedad menor al 8%'),
('SS', 'Semi Seco', 'Grano con humedad entre 15-25%'),
('ES', 'Escurrido', 'Grano recién despulpado con alto contenido de humedad');

-- Estados de fermentación
INSERT INTO `estados_fermentacion` (`codigo`, `nombre`, `descripcion`) VALUES
('F', 'Fermentado', 'Grano que ha pasado por proceso completo de fermentación'),
('SF', 'Sin Fermentar', 'Grano que no ha sido fermentado');

-- Secadoras
INSERT INTO `secadoras` (`numero`, `nombre`, `capacidad_qq`, `tipo`) VALUES
('SEC-01', 'Secadora Industrial 1', 100, 'INDUSTRIAL'),
('SEC-02', 'Secadora Industrial 2', 100, 'INDUSTRIAL'),
('SEC-03', 'Secadora Industrial 3', 100, 'INDUSTRIAL'),
('SEC-04', 'Secadora Industrial 4', 100, 'INDUSTRIAL'),
('SEC-05', 'Secadora Industrial 5', 100, 'INDUSTRIAL'),
('SEC-06', 'Secadora Industrial 6', 100, 'INDUSTRIAL'),
('SEC-07', 'Secadora Industrial 7', 100, 'INDUSTRIAL'),
('SEC-08', 'Secadora Industrial 8', 100, 'INDUSTRIAL'),
('SEC-09', 'Secadora Industrial 9', 100, 'INDUSTRIAL'),
('SEC-10', 'Secadora Industrial 10', 100, 'INDUSTRIAL'),
('SEC-11', 'Secadora Industrial 11', 100, 'INDUSTRIAL'),
('SEC-12', 'Secadora Industrial 12', 100, 'INDUSTRIAL'),
('SEC-13', 'Secadora Industrial 13', 100, 'INDUSTRIAL');

-- Cajones de fermentación
INSERT INTO `cajones_fermentacion` (`numero`, `capacidad_kg`, `material`) VALUES
('CAJ-01', 500, 'Madera'),
('CAJ-02', 500, 'Madera'),
('CAJ-03', 500, 'Madera'),
('CAJ-04', 500, 'Madera'),
('CAJ-05', 500, 'Madera'),
('CAJ-06', 500, 'Madera');

-- Indicadores principales
INSERT INTO `indicadores` (`etapa_proceso`, `nombre`, `meta`, `formula`, `frecuencia`, `justificacion`, `unidad`) VALUES
('Recepción', 'Tiempo promedio de descarga', '70 minutos', 'Promedio diario/70 min', 'DIARIA', 'Optimiza el flujo para absorber un +177% de volumen', 'minutos'),
('Recepción', 'Humedad de Ingreso', 'Variable', 'Humedad Medida/Humedad Permitida', 'POR_LOTE', 'Clasifica el grano (Escurrido/Semiseco) para el flujo correcto', '%'),
('Calidad', 'Índice de Pureza Genética', '≥ 90%', '(Granos Nacional/100)×100', 'POR_LOTE', 'Mantiene la uniformidad de los lotes', '%'),
('Calidad', 'Tasa de Inocuidad (Cadmio)', 'Según destino', 'Nivel de cadmio/Nivel Permitido', 'TRIMESTRAL', 'Obligatorio para acceso a mercados europeos y asiáticos', 'ppm'),
('Fermentación', 'Volteo Disciplinado', '1', 'Ejecutados/Programados', 'DIARIA', 'Garantiza precursores de sabor para el segmento Premium', 'ratio'),
('Secado Final', 'Rendimiento del cacao', '0.5', 'Peso cacao escurrido ingresado / Peso cacao seco obtenido', 'DIARIA', 'Reduce merma del 57% al 50%; ahorro de $3,091/máquina', 'ratio'),
('Secado Final', 'Eficiencia Térmica', '30 horas', 'Promedio de Horas totales de secado', 'SEMANAL', 'Optimización vs 36h actuales mediante monitoreo nocturno', 'horas'),
('Calidad Post-Secado', 'Índice de Fermentación', '≥ 90%', '(Fermentados/100)×100', 'SEMANAL', 'Requisito de nicho "Bean-to-Bar" (G1 pide ≥75%)', '%'),
('Calidad Post-Secado', 'Pureza Física (Violetas/Mohos)', 'Límites G1', 'Conteo por muestra', 'SEMANAL', 'Violetas ≤15%, Mohos ≤1% (Norma INEN 176)', '%'),
('Calidad Post-Secado', 'Peso de 100 granos', '> 130 g', 'Peso real/130 g', 'SEMANAL', 'Calidad Premium exigida por clientes asiáticos', 'g'),
('Almacenamiento', 'Rotación de Inventario', '25-30 veces', 'Ventas/Inventario', 'MENSUAL', 'Crea buffer de 12-15 días para asegurar suministro B2B', 'veces'),
('Almacenamiento', 'Inocuidad POES', '≥ 95%', 'Registros Limpios/Total de registros', 'SEMANAL', 'Evita contaminación cruzada con maracuyá o químicos', '%'),
('Comercial', 'Tasa de Aceptación Muestras', '≥ 90%', 'No. Feedback positivo/No. de envío de muestra', 'SEMESTRAL', 'Mide eficacia de la Biblioteca de Muestras Pre-Validadas', '%'),
('Logística', 'Cumplimiento', '1', 'TM Enviadas/TM Contratadas', 'TRIMESTRAL', 'Base para construir confianza', 'ratio'),
('Logística', 'Humedad de Tránsito', '6.5% - 7.5%', 'Promedio de Humedad al despacho', 'POR_EMBARQUE', 'Previene moho en tránsito marítimo prolongado a Asia', '%');

-- Parámetros de proceso configurables
INSERT INTO `parametros_proceso` (`categoria`, `clave`, `valor`, `tipo`, `descripcion`) VALUES
('FERMENTACION', 'dias_minimos', '5', 'NUMBER', 'Días mínimos de fermentación'),
('FERMENTACION', 'dias_maximos', '7', 'NUMBER', 'Días máximos de fermentación'),
('FERMENTACION', 'temp_min', '70', 'NUMBER', 'Temperatura mínima esperada (°C)'),
('FERMENTACION', 'temp_max', '130', 'NUMBER', 'Temperatura máxima esperada (°C)'),
('FERMENTACION', 'ph_min', '3.5', 'NUMBER', 'pH mínimo esperado'),
('FERMENTACION', 'ph_max', '5.5', 'NUMBER', 'pH máximo esperado'),
('SECADO', 'humedad_objetivo', '7', 'NUMBER', 'Humedad objetivo final (%)'),
('SECADO', 'humedad_tolerancia', '1', 'NUMBER', 'Tolerancia de humedad (±%)'),
('SECADO', 'temp_max_grano', '60', 'NUMBER', 'Temperatura máxima del grano (°C)'),
('SECADO', 'horas_estimadas', '30', 'NUMBER', 'Horas estimadas de secado'),
('CALIDAD', 'fermentados_minimo', '75', 'NUMBER', 'Porcentaje mínimo de granos bien fermentados'),
('CALIDAD', 'violeta_maximo', '15', 'NUMBER', 'Porcentaje máximo de granos violeta'),
('CALIDAD', 'mohosos_maximo', '1', 'NUMBER', 'Porcentaje máximo de granos mohosos'),
('CALIDAD', 'peso_100_granos_minimo', '130', 'NUMBER', 'Peso mínimo de 100 granos (g)'),
('GENERAL', 'peso_saco_kg', '69', 'NUMBER', 'Peso estándar por saco (kg)'),
('GENERAL', 'sacos_por_pallet', '30', 'NUMBER', 'Cantidad de sacos por pallet');

SET FOREIGN_KEY_CHECKS = 1;
