# 🍫 MEGABLESSING - Sistema de Control de Procesos de Cacao

> **Sistema integral para el control y trazabilidad del procesamiento de cacao**  
> Desarrollado por: **Shalom Software**

---

## 📋 Tabla de Contenidos

1. [Descripción General](#descripción-general)
2. [Flujo del Proceso](#flujo-del-proceso)
3. [Módulos del Sistema](#módulos-del-sistema)
4. [Requisitos Técnicos](#requisitos-técnicos)
5. [Instalación](#instalación)
6. [Estructura del Proyecto](#estructura-del-proyecto)
7. [Base de Datos](#base-de-datos)
8. [Roles y Permisos](#roles-y-permisos)
9. [Credenciales por Defecto](#credenciales-por-defecto)

---

## 📖 Descripción General

**MEGABLESSING** es un sistema web completo diseñado para empresas procesadoras de cacao que necesitan:

- **Trazabilidad completa**: Seguimiento del cacao desde la recepción hasta el empaquetado
- **Control de calidad**: Pruebas de corte con clasificación automática
- **Gestión de procesos**: Fermentación y secado con registro de parámetros
- **Reportes ejecutivos**: Indicadores de producción y calidad
- **Cumplimiento normativo**: Basado en normas INEN y estándares de exportación

### Características Principales

✅ Control de lotes con códigos únicos  
✅ Registro diario de fermentación (6 días, control de volteos)  
✅ Control de temperatura de secado (7 slots cada 2 horas)  
✅ Prueba de corte con 100 granos (clasificación automática)  
✅ Dashboard con indicadores en tiempo real  
✅ Reportes exportables a CSV y PDF  
✅ Sistema de roles y permisos  
✅ Configuración de parámetros de proceso  

---

## 🔄 Flujo del Proceso

El sistema sigue el flujo natural del procesamiento de cacao:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         FLUJO DE PROCESAMIENTO DE CACAO                      │
└─────────────────────────────────────────────────────────────────────────────┘

    ┌──────────────┐
    │  RECEPCIÓN   │  ← Ingreso del cacao (proveedor, variedad, peso, humedad)
    │    (Lotes)   │
    └──────┬───────┘
           │
           ▼
    ┌──────────────┐
    │   CALIDAD    │  ← Prueba de corte inicial (opcional)
    │   INICIAL    │    Verificación de pureza genética
    └──────┬───────┘
           │
           ▼
    ┌──────────────┐
    │ FERMENTACIÓN │  ← 5-7 días en cajones de madera
    │              │    Control diario: temperatura, pH, volteos, olor, color
    │  📊 Control  │    Objetivo: desarrollar precursores de sabor
    │    Diario    │
    └──────┬───────┘
           │
           ▼
    ┌──────────────┐
    │    SECADO    │  ← Secadoras industriales (30 horas aprox)
    │              │    Control cada 2 horas: temperatura del grano
    │  🌡️ Control  │    Objetivo: reducir humedad al 6.5-7.5%
    │  Temperatura │
    └──────┬───────┘
           │
           ▼
    ┌──────────────┐
    │   CALIDAD    │  ← Prueba de corte final (100 granos)
    │ POST-SECADO  │    Clasificación: Premium, Exportación, Nacional, Rechazado
    │              │    Evaluación: fermentación, violetas, mohosos, defectos
    └──────┬───────┘
           │
           ▼
    ┌──────────────┐
    │ EMPAQUETADO  │  ← Ensacado (sacos de 69 kg estándar)
    │              │    Registro de lotes empaquetados
    └──────┬───────┘
           │
           ▼
    ┌──────────────┐
    │  ALMACENADO  │  ← Control de inventario
    │   DESPACHO   │    Trazabilidad para exportación
    │  FINALIZADO  │
    └──────────────┘
```

### Estados del Proceso

| Estado | Descripción |
|--------|-------------|
| `RECEPCION` | Lote recién ingresado al sistema |
| `CALIDAD` | En evaluación de calidad inicial |
| `PRE_SECADO` | Preparación para fermentación |
| `FERMENTACION` | En proceso de fermentación activa |
| `SECADO` | En proceso de secado |
| `CALIDAD_POST` | Evaluación de calidad post-secado |
| `EMPAQUETADO` | En proceso de empaquetado |
| `ALMACENADO` | En almacén, listo para despacho |
| `DESPACHO` | En proceso de envío |
| `FINALIZADO` | Proceso completado |

---

## 📦 Módulos del Sistema

### 1. 📥 Lotes (`/lotes/`)

Gestión central de lotes de cacao:

- **Crear**: Registro de nuevo lote con código único automático
- **Listar**: Vista de todos los lotes con filtros por estado, proveedor, fecha
- **Ver**: Detalle completo del lote con trazabilidad de todos los procesos
- **Editar**: Modificación de datos del lote

**Datos registrados:**
- Código único del lote
- Proveedor/Ruta de origen
- Variedad de cacao (CCN-51, Nacional, Orgánico, etc.)
- Estado del producto (Seco, Semi Seco, Escurrido)
- Peso inicial en kg
- Humedad inicial
- Observaciones

---

### 2. 🔥 Fermentación (`/fermentacion/`)

Control del proceso de fermentación (5-7 días):

- **Crear**: Iniciar proceso de fermentación para un lote
- **Control**: Registro diario con Handsontable (tabla interactiva)
- **Ver**: Visualización completa del proceso

**Control Diario (por cada día):**
| Campo | Descripción |
|-------|-------------|
| Volteo | ✓/✗ Si se realizó volteo |
| Temp. Masa | Temperatura de la masa de cacao (°C) |
| Temp. Ambiente | Temperatura del ambiente (°C) |
| pH Pulpa | Nivel de pH de la pulpa |
| pH Cotiledón | Nivel de pH del cotiledón |
| Olor | Descripción del olor (vinagre, chocolate, etc.) |
| Color | Color observado |
| Observaciones | Notas adicionales |

**Evaluación Final:**
- Porcentaje de granos violeta
- Porcentaje de granos pizarrosos
- Porcentaje de granos fermentados
- Porcentaje de granos mohosos
- Aroma final
- Aprobación para secado

---

### 3. ☀️ Secado (`/secado/`)

Control del proceso de secado industrial:

- **Crear**: Iniciar proceso de secado para un lote
- **Control**: Registro de temperatura cada 2 horas (7 slots)
- **Ver**: Visualización con gráfico de temperatura

**Revisión Inicial (Checklist):**
- [ ] Limpieza del área
- [ ] Secadora limpia
- [ ] Verificación de energía
- [ ] Bandejas limpias
- [ ] Termómetros funcionando
- [ ] Registro de clima

**Control de Temperatura:**
| Hora | Temperatura | Turno |
|------|-------------|-------|
| 06:00 | ___ °C | Diurno |
| 08:00 | ___ °C | Diurno |
| 10:00 | ___ °C | Diurno |
| 12:00 | ___ °C | Diurno |
| 14:00 | ___ °C | Diurno |
| 16:00 | ___ °C | Diurno |
| 18:00 | ___ °C | Nocturno |

**Datos de Humedad:**
- Humedad inicial
- Humedad a las 12 horas
- Humedad final (objetivo: 6.5% - 7.5%)

---

### 4. ✂️ Prueba de Corte (`/prueba-corte/`)

Evaluación de calidad mediante corte de granos:

- **Crear**: Nueva prueba de corte (Recepción o Post-Secado)
- **Ver**: Resultados detallados con clasificación

**Análisis de 100 Granos:**
| Categoría | Descripción | Límite |
|-----------|-------------|--------|
| Bien Fermentados | Granos con fermentación completa | ≥75% Premium |
| Violeta | Granos con fermentación incompleta | ≤15% |
| Pizarrosos | Granos sin fermentar | Mínimo |
| Mohosos | Granos con hongos | ≤1% |
| Insectados | Granos con daño de insectos | Mínimo |
| Germinados | Granos que germinaron | Mínimo |
| Planos/Vanos | Granos vacíos o deformes | Mínimo |

**Clasificación Automática:**
| Clasificación | Criterio |
|---------------|----------|
| 🏆 **PREMIUM** | ≥75% fermentados, ≤5% defectos |
| 📦 **EXPORTACIÓN** | 60-74% fermentados, ≤10% defectos |
| 🏠 **NACIONAL** | 50-59% fermentados |
| ❌ **RECHAZADO** | <50% fermentados o >15% defectos |

---

### 5. 📊 Reportes (`/reportes/`)

Generación de informes y análisis:

| Reporte | Descripción |
|---------|-------------|
| **Consolidado** | Resumen ejecutivo de toda la producción |
| **Lotes** | Listado detallado de lotes con trazabilidad |
| **Fermentación** | Análisis del proceso de fermentación |
| **Secado** | Análisis del proceso de secado |
| **Prueba de Corte** | Resultados de calidad |
| **Indicadores** | KPIs y métricas del proceso |
| **Registro KPIs** | Captura manual de indicadores cuando no hay datos automáticos |

**Formatos de Exportación:**
- 📊 CSV (Excel compatible)
- 📄 PDF (versión imprimible)

---

### 6. ⚙️ Configuración (`/configuracion/`)

Administración del sistema:

| Módulo | Función |
|--------|---------|
| **Proveedores** | Gestión de proveedores/rutas de cacao |
| **Variedades** | Tipos de cacao (CCN-51, Nacional, etc.) |
| **Cajones** | Cajones de fermentación |
| **Secadoras** | Equipos de secado |
| **Estados** | Estados de fermentación y calidad |
| **Parámetros** | Configuración de límites y valores |
| **Usuarios** | Gestión de usuarios y accesos |
| **Roles** | Definición de permisos |
| **Backup** | Respaldo de base de datos |

---

### 7. 📝 Fichas de Registro (`/fichas/`)

Formularios de registro general para documentación física:

- Fichas de entrada de producto
- Documentación de trazabilidad
- Registros para auditoría

---

## 💻 Requisitos Técnicos

### Servidor
- **PHP** 8.0 o superior
- **MySQL** 5.7+ / MariaDB 10.3+
- **Apache** con mod_rewrite habilitado

### Extensiones PHP Requeridas
```
- pdo_mysql
- mbstring
- json
- session
```

### Navegador
- Chrome 80+
- Firefox 75+
- Safari 13+
- Edge 80+

---

## 🚀 Instalación

### 1. Clonar/Descomprimir el proyecto
```bash
# Descomprimir en la carpeta del servidor web
unzip megablessing_procesos.zip -d /var/www/html/
```

### 2. Configurar la base de datos

Editar `/config/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'megablessing_procesos');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña');
```

### 3. Crear la base de datos
```bash
mysql -u root -p
```
```sql
CREATE DATABASE megablessing_procesos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE megablessing_procesos;
SOURCE /ruta/al/proyecto/database/schema.sql;
```

> Si ya existe la base, aplica la tabla nueva para KPIs manuales:
```sql
CREATE TABLE IF NOT EXISTS indicadores_registros (
  id INT PRIMARY KEY AUTO_INCREMENT,
  indicador_id INT NOT NULL,
  fecha DATE NOT NULL,
  valor DECIMAL(12,4),
  referencia VARCHAR(100),
  detalle TEXT,
  usuario_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (indicador_id) REFERENCES indicadores(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4. Configurar permisos
```bash
chmod -R 755 /var/www/html/megablessing_procesos
chmod -R 777 /var/www/html/megablessing_procesos/storage
```

### 5. Configurar URL base

Editar `/config/config.php`:
```php
define('APP_URL', 'http://tu-dominio.com/megablessing_procesos');
```

### 6. Acceder al sistema
```
http://tu-dominio.com/megablessing_procesos/login.php
```

---

## 📁 Estructura del Proyecto

```
megablessing_procesos/
│
├── api/                    # Endpoints API (AJAX)
│   ├── fermentacion/       # API de fermentación
│   ├── secado/             # API de secado
│   ├── prueba-corte/       # API de prueba de corte
│   ├── lotes/              # API de lotes
│   └── reportes/           # API de reportes PDF
│
├── assets/                 # Recursos estáticos
│   ├── css/               # Estilos CSS
│   └── js/                # JavaScript
│
├── config/                 # Configuración
│   └── config.php         # Variables de configuración
│
├── configuracion/          # Módulo de configuración
│   ├── proveedores.php
│   ├── variedades.php
│   ├── cajones.php
│   ├── secadoras.php
│   ├── estados.php
│   ├── parametros.php
│   ├── usuarios.php
│   ├── roles.php
│   └── backup.php
│
├── core/                   # Clases del núcleo
│   ├── Auth.php           # Autenticación
│   ├── Database.php       # Conexión BD
│   ├── Helpers.php        # Funciones auxiliares
│   └── PdfReport.php      # Generador de PDF
│
├── database/               # Scripts de base de datos
│   └── schema.sql         # Esquema completo
│
├── empaquetado/            # Módulo de empaquetado
├── fermentacion/           # Módulo de fermentación
├── fichas/                 # Módulo de fichas
├── indicadores/            # Captura manual de KPIs
├── lotes/                  # Módulo de lotes
├── prueba-corte/           # Módulo de prueba de corte
├── reportes/               # Módulo de reportes
├── secado/                 # Módulo de secado
│
├── templates/              # Plantillas
│   └── layouts/
│       └── main.php       # Layout principal
│
├── bootstrap.php           # Inicialización del sistema
├── dashboard.php           # Panel principal
├── login.php               # Inicio de sesión
├── logout.php              # Cierre de sesión
└── index.php               # Redirección inicial
```

---

## 🗄️ Base de Datos

### Diagrama de Entidades Principales

```
┌─────────────┐     ┌─────────────────────┐     ┌──────────────┐
│ proveedores │────<│       lotes         │>────│  variedades  │
└─────────────┘     └─────────┬───────────┘     └──────────────┘
                              │
          ┌───────────────────┼───────────────────┐
          │                   │                   │
          ▼                   ▼                   ▼
┌─────────────────┐  ┌─────────────────┐  ┌──────────────────┐
│   registros_    │  │   registros_    │  │    registros_    │
│  fermentacion   │  │     secado      │  │   prueba_corte   │
└────────┬────────┘  └────────┬────────┘  └──────────────────┘
         │                    │
         ▼                    ▼
┌─────────────────┐  ┌─────────────────┐
│  fermentacion_  │  │ secado_control_ │
│ control_diario  │  │   temperatura   │
└─────────────────┘  └─────────────────┘
```

### Tablas Principales

| Tabla | Descripción |
|-------|-------------|
| `lotes` | Registro central de lotes |
| `registros_fermentacion` | Procesos de fermentación |
| `fermentacion_control_diario` | Control diario de fermentación |
| `registros_secado` | Procesos de secado |
| `secado_control_temperatura` | Control de temperatura |
| `registros_prueba_corte` | Pruebas de calidad |
| `usuarios` | Usuarios del sistema |
| `roles` | Roles y permisos |
| `proveedores` | Proveedores de cacao |
| `variedades` | Variedades de cacao |
| `indicadores` | KPIs configurados |
| `indicadores_registros` | Registros manuales de KPIs |
| `parametros_proceso` | Parámetros configurables |

---

## 👥 Roles y Permisos

| Rol | Descripción | Permisos |
|-----|-------------|----------|
| **Administrador** | Acceso total | Todo el sistema |
| **Supervisor** | Supervisión de procesos | Lotes, Fermentación, Secado, Prueba Corte, Reportes |
| **Operador** | Registro de datos | Ver/Crear/Editar en procesos |
| **Calidad** | Control de calidad | Prueba de Corte, Ver Lotes y Reportes |
| **Consulta** | Solo visualización | Solo lectura en todo el sistema |

---

## 🔐 Credenciales por Defecto

| Campo | Valor |
|-------|-------|
| **Email** | `admin@megablessing.com` |
| **Contraseña** | `admin123` |

> ⚠️ **IMPORTANTE**: Cambiar la contraseña del administrador después de la instalación.

---

## 🎨 Tecnologías Utilizadas

- **Backend**: PHP 8.0+ (sin framework, MVC simple)
- **Base de Datos**: MySQL/MariaDB
- **Frontend**: HTML5, CSS3, JavaScript
- **CSS Framework**: TailwindCSS
- **Tablas Interactivas**: Handsontable
- **Íconos**: Font Awesome
- **Gráficos**: Chart.js (opcional)

---

## 📞 Soporte

**Desarrollado por Shalom Software**

Para soporte técnico o consultas sobre el sistema, contactar al desarrollador.

---

## 📄 Licencia

Este software es propietario y su uso está restringido al cliente autorizado.

---

*Última actualización: Enero 2026*
