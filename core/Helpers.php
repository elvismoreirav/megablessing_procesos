<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Clase Helpers - Funciones auxiliares
 * Desarrollado por: Shalom Software
 */

class Helpers {

    /**
     * Resolver prefijo de proveedor para código de lote.
     * Prioridad:
     * 1) Código de la categoría semilla (M, CA, ES, FM, VP)
     * 2) Mapeo por categoría textual
     * 3) Mapeo por tipo (MERCADO, CENTRO DE ACOPIO, RUTA, PRODUCTOR)
     */
    public static function resolveProveedorLotePrefix($proveedorRef) {
        $db = Database::getInstance();

        $normalize = static function ($value): string {
            $value = strtoupper(trim((string)$value));
            $value = strtr($value, [
                'Á' => 'A',
                'É' => 'E',
                'Í' => 'I',
                'Ó' => 'O',
                'Ú' => 'U',
            ]);
            return preg_replace('/\s+/', ' ', $value);
        };

        $sanitizeCode = static function ($value): string {
            $code = strtoupper(trim((string)$value));
            return preg_replace('/[^A-Z0-9]/', '', $code);
        };

        $codigosCategoriaValidos = ['M', 'CA', 'ES', 'FM', 'VP'];
        $categoriaMap = [
            'MERCADO' => 'M',
            'BODEGA' => 'CA',
            'CENTRO DE ACOPIO' => 'CA',
            'ESMERALDAS' => 'ES',
            'FLOR DE MANABI' => 'FM',
            'VIA PEDERNALES' => 'VP',
        ];
        $tipoMap = [
            'MERCADO' => 'M',
            'BODEGA' => 'CA',
            'CENTRO DE ACOPIO' => 'CA',
            'RUTA' => 'RT',
            'PRODUCTOR' => 'PR',
        ];

        $row = null;
        if (is_numeric($proveedorRef)) {
            try {
                $row = $db->fetch(
                    "SELECT id, codigo, tipo, categoria, es_categoria
                     FROM proveedores
                     WHERE id = ? LIMIT 1",
                    [(int)$proveedorRef]
                );
            } catch (Throwable $e) {
                $row = $db->fetch(
                    "SELECT id, codigo
                     FROM proveedores
                     WHERE id = ? LIMIT 1",
                    [(int)$proveedorRef]
                );
            }
        } else {
            $raw = trim((string)$proveedorRef);
            $rawCode = $sanitizeCode($raw);
            $rawNorm = $normalize($raw);

            if ($rawCode === 'B') {
                return 'CA';
            }
            if (in_array($rawCode, $codigosCategoriaValidos, true)) {
                return $rawCode;
            }
            if (isset($categoriaMap[$rawNorm])) {
                return $categoriaMap[$rawNorm];
            }
            if (isset($tipoMap[$rawNorm])) {
                return $tipoMap[$rawNorm];
            }
            if ($rawCode !== '') {
                try {
                    $row = $db->fetch(
                        "SELECT id, codigo, tipo, categoria, es_categoria
                         FROM proveedores
                         WHERE UPPER(codigo) = ? LIMIT 1",
                        [$rawCode]
                    );
                } catch (Throwable $e) {
                    $row = $db->fetch(
                        "SELECT id, codigo
                         FROM proveedores
                         WHERE UPPER(codigo) = ? LIMIT 1",
                        [$rawCode]
                    );
                }
            }
        }

        if (is_array($row) && !empty($row)) {
            $categoria = trim((string)($row['categoria'] ?? ''));
            if ($categoria !== '') {
                try {
                    $catRow = $db->fetch(
                        "SELECT codigo
                         FROM proveedores
                         WHERE es_categoria = 1
                           AND categoria = ?
                         LIMIT 1",
                        [$categoria]
                    );
                } catch (Throwable $e) {
                    $catRow = null;
                }
                $catCode = $sanitizeCode((string)($catRow['codigo'] ?? ''));
                if ($catCode === 'B') {
                    return 'CA';
                }
                if (in_array($catCode, $codigosCategoriaValidos, true)) {
                    return $catCode;
                }
            }

            $categoriaNorm = $normalize((string)($row['categoria'] ?? ''));
            if (isset($categoriaMap[$categoriaNorm])) {
                return $categoriaMap[$categoriaNorm];
            }

            $tipoNorm = $normalize((string)($row['tipo'] ?? ''));
            if (isset($tipoMap[$tipoNorm])) {
                return $tipoMap[$tipoNorm];
            }

            $code = $sanitizeCode((string)($row['codigo'] ?? ''));
            if ($code === 'B') {
                return 'CA';
            }
            if (in_array($code, $codigosCategoriaValidos, true)) {
                return $code;
            }
        }

        return 'XX';
    }
    
    /**
     * Generar código de lote
     * Formato: CAT-DD-MM-YY-ESTADO[-LETRA]
     * - ESTADO: ES, SC, SM, BA
     * - Si ya existe un lote con la misma base en el mismo día/categoría, se agrega sufijo alfabético.
     */
    public static function generateLoteCode($proveedorCodigo, $fecha, $estadoProducto, $estadoFermentacion = null) {
        $db = Database::getInstance();

        // Fecha segura
        $ts = strtotime($fecha);
        if (!$ts) $ts = time();

        $dia  = date('d', $ts);
        $mes  = date('m', $ts);
        $anio = date('y', $ts);

        // PROV/RECEPCION (categoría/tipo de proveedor)
        $prov = self::resolveProveedorLotePrefix($proveedorCodigo);

        // ESTADO: si llega ID -> buscar codigo en estados_producto
        $estadoCode = $estadoProducto;
        if (is_numeric($estadoProducto)) {
            $row = $db->fetch("SELECT codigo FROM estados_producto WHERE id = ?", [(int)$estadoProducto]);
            $estadoCode = $row['codigo'] ?? '';
        }
        $estadoCode = strtoupper(trim((string)$estadoCode));
        $estadoCode = preg_replace('/[^A-Z0-9]/', '', $estadoCode);
        if ($estadoCode === 'SS') {
            $estadoCode = 'SM';
        } elseif ($estadoCode === 'SECO') {
            $estadoCode = 'SC';
        } elseif ($estadoCode === 'SEMISECO') {
            $estadoCode = 'SM';
        } elseif ($estadoCode === 'BABA') {
            $estadoCode = 'BA';
        }
        if (!in_array($estadoCode, ['ES', 'SC', 'SM', 'BA'], true)) {
            $estadoCode = 'SC';
        }

        $baseCode = "{$prov}-{$dia}-{$mes}-{$anio}-{$estadoCode}";

        // Resolver sufijo alfabético para evitar colisiones en la misma base.
        $like = $baseCode . '%';
        $rows = $db->fetchAll(
            "SELECT codigo FROM lotes WHERE codigo LIKE ?",
            [$like]
        );

        $used = [];
        $baseUsed = false;
        foreach ($rows as $r) {
            $code = strtoupper(trim((string)($r['codigo'] ?? '')));
            if ($code === $baseCode) {
                $baseUsed = true;
                continue;
            }
            if (preg_match('/^' . preg_quote($baseCode, '/') . '-([A-Z])$/', $code, $m)) {
                $used[$m[1]] = true;
            }
        }

        if (!$baseUsed && empty($used)) {
            return $baseCode;
        }

        foreach (range('A', 'Z') as $letter) {
            if (!isset($used[$letter])) {
                return $baseCode . '-' . $letter;
            }
        }

        // Fallback si se agotaron letras simples.
        return $baseCode . '-Z';
    }

    
    /**
     * Formatear fecha
     */
    public static function formatDate($date, $format = 'd/m/Y') {
        if (empty($date)) return '';
        return date($format, strtotime($date));
    }
    
    /**
     * Formatear fecha y hora
     */
    public static function formatDateTime($datetime, $format = 'd/m/Y H:i') {
        if (empty($datetime)) return '';
        return date($format, strtotime($datetime));
    }
    
    /**
     * Formatear número
     */
    public static function formatNumber($number, $decimals = 2) {
        return number_format($number, $decimals, ',', '.');
    }
    
    /**
     * Formatear peso en quintales
     */
    public static function formatQQ($kg) {
        $qq = $kg / 45.36; // 1 quintal = 45.36 kg
        return self::formatNumber($qq, 2) . ' QQ';
    }
    
    /**
     * Convertir kg a quintales
     */
    public static function kgToQQ($kg) {
        return $kg / 45.36;
    }

    /**
     * Convertir kg a libras
     */
    public static function kgToLb($kg) {
        return $kg / 0.45359237;
    }
    
    /**
     * Convertir quintales a kg
     */
    public static function qqToKg($qq) {
        return $qq * 45.36;
    }

    /**
     * Convertir libras a kg
     */
    public static function lbToKg($lb) {
        return $lb * 0.45359237;
    }

    /**
     * Convertir peso (LB/KG/QQ) a kg
     */
    public static function pesoToKg($peso, $unidad = 'KG') {
        $valor = floatval($peso);
        $unidad = strtoupper(trim((string)$unidad));

        return match ($unidad) {
            'LB' => self::lbToKg($valor),
            'QQ' => self::qqToKg($valor),
            default => $valor,
        };
    }

    /**
     * Convertir kg a la unidad solicitada (LB/KG/QQ).
     */
    public static function kgToPeso($kg, $unidad = 'KG') {
        $valor = floatval($kg);
        $unidad = strtoupper(trim((string)$unidad));

        return match ($unidad) {
            'LB' => self::kgToLb($valor),
            'QQ' => self::kgToQQ($valor),
            default => $valor,
        };
    }

    /**
     * Formatear un peso almacenado en KG usando una o varias unidades visibles.
     */
    public static function formatPesoVisual($kg, array $unidades = ['QQ', 'LB'], int $decimales = 2, string $separador = ' · ') {
        if ($kg === null || $kg === '' || !is_numeric($kg)) {
            return '';
        }

        $valorKg = floatval($kg);
        $partes = [];

        foreach ($unidades as $unidad) {
            $unidadNormalizada = strtoupper(trim((string)$unidad));
            if (!in_array($unidadNormalizada, ['KG', 'LB', 'QQ'], true)) {
                continue;
            }

            $valorConvertido = self::kgToPeso($valorKg, $unidadNormalizada);
            $partes[] = number_format($valorConvertido, $decimales) . ' ' . $unidadNormalizada;
        }

        return implode($separador, $partes);
    }

    /**
     * Formatea el tipo de empaque usando un texto legible para la UI.
     */
    public static function formatTipoEmpaque($tipoEmpaque, $pesoSaco = null): string {
        $tipo = strtoupper(trim((string)$tipoEmpaque));
        $peso = ($pesoSaco !== null && $pesoSaco !== '' && is_numeric($pesoSaco))
            ? (float)$pesoSaco
            : null;

        return match ($tipo) {
            'SACO_ESTANDAR', 'SACO_50', 'SACO_69' => 'Saco estándar' . ($peso !== null ? ' ' . number_format($peso, 0) . ' kg' : ''),
            'SACO_46' => 'Saco 46 kg (Exportación)',
            'SACO_25' => 'Saco 25 kg',
            'BIG_BAG' => 'Big Bag 1000 kg',
            'OTRO' => 'Otro',
            default => trim((string)$tipoEmpaque) !== '' ? trim((string)$tipoEmpaque) : 'N/R',
        };
    }

    /**
     * Descompone el texto "PROVEEDORES: ... | RUTA: ..." en partes utilizables.
     */
    public static function parseProveedorRutaCompuesta($texto): array {
        $texto = trim((string)$texto);
        if ($texto === '') {
            return [
                'proveedores' => [],
                'ruta' => '',
            ];
        }

        $resultado = [
            'proveedores' => [],
            'ruta' => '',
        ];

        if (preg_match('/^PROVEEDORES:\s*(.*?)\s*\|\s*RUTA:\s*(.+)$/iu', $texto, $coincidencias)) {
            $resultado['ruta'] = trim((string)($coincidencias[2] ?? ''));
            $bloqueProveedores = trim((string)($coincidencias[1] ?? ''));
            if ($bloqueProveedores !== '') {
                $resultado['proveedores'] = array_values(array_filter(array_map(
                    static fn(string $item): string => trim($item),
                    preg_split('/\s*,\s*/', $bloqueProveedores) ?: []
                )));
            }
            return $resultado;
        }

        $resultado['proveedores'] = [$texto];
        return $resultado;
    }
    
    /**
     * Sanitizar string
     */
    public static function sanitize($string) {
        return htmlspecialchars(strip_tags(trim($string)), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validar email
     */
    public static function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Generar slug
     */
    public static function slug($string) {
        $string = preg_replace('/[^a-zA-Z0-9\s]/', '', $string);
        $string = strtolower(trim($string));
        $string = preg_replace('/\s+/', '-', $string);
        return $string;
    }
    
    /**
     * Obtener estado de proceso como badge HTML
     */
    public static function getEstadoProcesoBadge($estado) {
        $badges = [
            'RECEPCION' => ['color' => 'bg-blue-100 text-blue-800', 'label' => 'Recepción'],
            'CALIDAD' => ['color' => 'bg-indigo-100 text-indigo-800', 'label' => 'Verificación de Lote'],
            'PRE_SECADO' => ['color' => 'bg-yellow-100 text-yellow-800', 'label' => 'Pre-Secado'],
            'FERMENTACION' => ['color' => 'bg-orange-100 text-orange-800', 'label' => 'Fermentación'],
            'SECADO' => ['color' => 'bg-red-100 text-red-800', 'label' => 'Secado'],
            'CALIDAD_POST' => ['color' => 'bg-green-100 text-green-800', 'label' => 'Prueba de Corte'],
            'CALIDAD_SALIDA' => ['color' => 'bg-emerald-100 text-emerald-800', 'label' => 'Calidad de salida'],
            'EMPAQUETADO' => ['color' => 'bg-pink-100 text-pink-800', 'label' => 'Empaquetado'],
            'ALMACENADO' => ['color' => 'bg-gray-100 text-gray-800', 'label' => 'Almacenado'],
            'DESPACHO' => ['color' => 'bg-teal-100 text-teal-800', 'label' => 'Despacho'],
            'FINALIZADO' => ['color' => 'bg-green-100 text-green-800', 'label' => 'Finalizado'],
            'RECHAZADO' => ['color' => 'bg-red-100 text-red-800', 'label' => 'Rechazado'],
        ];
        
        $badge = $badges[$estado] ?? ['color' => 'bg-gray-100 text-gray-800', 'label' => $estado];
        
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' . $badge['color'] . '">' . $badge['label'] . '</span>';
    }
    
    /**
     * Obtener decisión de lote como badge
     */
    public static function getDecisionBadge($decision) {
        $badges = [
            'APROBADO' => 'bg-green-100 text-green-800',
            'RECHAZADO' => 'bg-red-100 text-red-800',
            'REPROCESO' => 'bg-yellow-100 text-yellow-800',
            'MEZCLA' => 'bg-blue-100 text-blue-800',
        ];
        
        $color = $badges[$decision] ?? 'bg-gray-100 text-gray-800';
        
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' . $color . '">' . $decision . '</span>';
    }
    
    /**
     * Calcular porcentaje de defectos
     */
    public static function calcularDefectosTotales($violeta, $pizarrosos, $mohosos, $insectados, $germinados, $planos) {
        return $violeta + $pizarrosos + $mohosos + $insectados + $germinados + $planos;
    }
    
    /**
     * Evaluar si cumple especificación de calidad
     */
    public static function cumpleEspecificacion($fermentados, $violeta, $mohosos, $peso100) {
        $db = Database::getInstance();
        
        $params = $db->fetchAll("SELECT clave, valor FROM parametros_proceso WHERE categoria = 'CALIDAD'");
        $config = [];
        foreach ($params as $p) {
            $config[$p['clave']] = floatval($p['valor']);
        }
        
        $fermentadosMin = $config['fermentados_minimo'] ?? 75;
        $violetaMax = $config['violeta_maximo'] ?? 15;
        $mohososMax = $config['mohosos_maximo'] ?? 1;
        $peso100Min = $config['peso_100_granos_minimo'] ?? 130;
        
        return $fermentados >= $fermentadosMin 
            && $violeta <= $violetaMax 
            && $mohosos <= $mohososMax 
            && $peso100 >= $peso100Min;
    }
    
    /**
     * Obtener parámetros de proceso por categoría
     */
    public static function getParametros($categoria) {
        $db = Database::getInstance();
        $params = $db->fetchAll(
            "SELECT clave, valor, tipo, descripcion FROM parametros_proceso WHERE categoria = :cat",
            ['cat' => $categoria]
        );
        
        $result = [];
        foreach ($params as $p) {
            $value = $p['valor'];
            if ($p['tipo'] === 'NUMBER') {
                $value = floatval($value);
            } elseif ($p['tipo'] === 'BOOLEAN') {
                $value = $value === '1' || $value === 'true';
            } elseif ($p['tipo'] === 'JSON') {
                $value = json_decode($value, true);
            }
            $result[$p['clave']] = $value;
        }
        
        return $result;
    }

    /**
     * Obtener columnas reales de una tabla (compatibilidad entre esquemas).
     */
    public static function getTableColumns(string $table): array {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return [];
        }

        $db = Database::getInstance();
        try {
            $rows = $db->fetchAll("SHOW COLUMNS FROM {$table}");
        } catch (Throwable $e) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            $name = $row['Field'] ?? $row['field'] ?? null;
            if (is_string($name) && $name !== '') {
                $columns[] = $name;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Asegura capacidad suficiente para proveedor_ruta en fichas_registro.
     */
    public static function ensureFichaProveedorRutaColumn(): bool {
        $db = Database::getInstance();

        try {
            $tablaExiste = (bool)$db->fetch("SHOW TABLES LIKE 'fichas_registro'");
            if (!$tablaExiste) {
                return false;
            }

            $columna = $db->fetch("SHOW COLUMNS FROM fichas_registro LIKE 'proveedor_ruta'");
            if (!$columna) {
                $db->query("ALTER TABLE fichas_registro ADD COLUMN proveedor_ruta TEXT NULL AFTER codificacion");
                return true;
            }

            $tipo = strtolower(trim((string)($columna['Type'] ?? $columna['type'] ?? '')));
            if (str_contains($tipo, 'text')) {
                return true;
            }

            $db->query("ALTER TABLE fichas_registro MODIFY COLUMN proveedor_ruta TEXT NULL");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Verifica si una columna existe en una tabla.
     */
    public static function hasTableColumn(string $table, string $column): bool {
        static $cache = [];
        $cacheKey = strtolower($table);
        if (!array_key_exists($cacheKey, $cache)) {
            $cache[$cacheKey] = self::getTableColumns($table);
        }
        return in_array($column, $cache[$cacheKey], true);
    }

    /**
     * Normaliza una unidad de peso soportada.
     */
    public static function normalizePesoUnit($unidad, string $default = 'KG'): string {
        $unidadNormalizada = strtoupper(trim((string)$unidad));
        if (in_array($unidadNormalizada, ['KG', 'QQ', 'LB'], true)) {
            return $unidadNormalizada;
        }

        $defaultNormalizado = strtoupper(trim($default));
        return in_array($defaultNormalizado, ['KG', 'QQ', 'LB'], true) ? $defaultNormalizado : 'KG';
    }

    /**
     * Asegura la columna unidad_peso en registros_fermentacion para persistir la unidad visible del proceso.
     */
    public static function ensureFermentacionPesoUnitColumn(): bool {
        $db = Database::getInstance();
        $cols = self::getTableColumns('registros_fermentacion');
        if (in_array('unidad_peso', $cols, true)) {
            return true;
        }

        try {
            $db->query("ALTER TABLE registros_fermentacion ADD COLUMN unidad_peso ENUM('LB','KG','QQ') NULL AFTER peso_lote_kg");
        } catch (Throwable $e) {
            // Continuar en modo compatibilidad si el esquema no puede alterarse.
        }

        return in_array('unidad_peso', self::getTableColumns('registros_fermentacion'), true);
    }

    /**
     * Asegura columnas finales de fermentacion necesarias para cierre y traspaso a secado.
     */
    public static function ensureFermentacionFinalColumns(): bool {
        $db = Database::getInstance();
        $cols = self::getTableColumns('registros_fermentacion');
        if (empty($cols)) {
            return false;
        }

        $alterQueries = [];
        if (!in_array('peso_final', $cols, true)) {
            if (in_array('unidad_peso', $cols, true)) {
                $alterQueries[] = "ALTER TABLE registros_fermentacion ADD COLUMN peso_final DECIMAL(10,2) NULL AFTER unidad_peso";
            } elseif (in_array('peso_lote_kg', $cols, true)) {
                $alterQueries[] = "ALTER TABLE registros_fermentacion ADD COLUMN peso_final DECIMAL(10,2) NULL AFTER peso_lote_kg";
            } else {
                $alterQueries[] = "ALTER TABLE registros_fermentacion ADD COLUMN peso_final DECIMAL(10,2) NULL";
            }
        }

        if (!in_array('humedad_final', $cols, true)) {
            if (in_array('porcentaje_mohosos', $cols, true)) {
                $alterQueries[] = "ALTER TABLE registros_fermentacion ADD COLUMN humedad_final DECIMAL(5,2) NULL AFTER porcentaje_mohosos";
            } elseif (in_array('humedad_inicial', $cols, true)) {
                $alterQueries[] = "ALTER TABLE registros_fermentacion ADD COLUMN humedad_final DECIMAL(5,2) NULL AFTER humedad_inicial";
            } else {
                $alterQueries[] = "ALTER TABLE registros_fermentacion ADD COLUMN humedad_final DECIMAL(5,2) NULL";
            }
        }

        foreach ($alterQueries as $sql) {
            try {
                $db->query($sql);
            } catch (Throwable $e) {
                // Continuar con lo disponible si el esquema no puede alterarse.
            }
        }

        $cols = self::getTableColumns('registros_fermentacion');
        return in_array('peso_final', $cols, true) && in_array('humedad_final', $cols, true);
    }

    /**
     * Asegura columnas para registrar dos mediciones diarias en fermentación.
     */
    public static function ensureFermentacionControlMedicionesColumns(): bool {
        $db = Database::getInstance();
        $cols = self::getTableColumns('fermentacion_control_diario');
        if (empty($cols)) {
            return false;
        }

        $alterQueries = [];
        if (!in_array('hora_am', $cols, true)) {
            $alterQueries[] = "ALTER TABLE fermentacion_control_diario ADD COLUMN hora_am TIME NULL AFTER dia";
        }
        if (!in_array('volteo_am', $cols, true)) {
            $alterQueries[] = "ALTER TABLE fermentacion_control_diario ADD COLUMN volteo_am TINYINT(1) NULL DEFAULT NULL AFTER hora_am";
        }
        if (!in_array('hora_pm', $cols, true)) {
            $alterQueries[] = "ALTER TABLE fermentacion_control_diario ADD COLUMN hora_pm TIME NULL AFTER volteo_am";
        }
        if (!in_array('volteo_pm', $cols, true)) {
            $alterQueries[] = "ALTER TABLE fermentacion_control_diario ADD COLUMN volteo_pm TINYINT(1) NULL DEFAULT NULL AFTER hora_pm";
        }

        foreach ($alterQueries as $sql) {
            try {
                $db->query($sql);
            } catch (Throwable $e) {
                // Continuar con lo disponible si el esquema no puede alterarse.
            }
        }

        $cols = self::getTableColumns('fermentacion_control_diario');
        $tieneMinimoNuevo = in_array('hora_am', $cols, true)
            && in_array('hora_pm', $cols, true)
            && in_array('volteo_am', $cols, true)
            && in_array('volteo_pm', $cols, true);

        if (!$tieneMinimoNuevo) {
            return false;
        }

        $horaLegacyCol = in_array('hora_volteo', $cols, true)
            ? 'hora_volteo'
            : (in_array('hora', $cols, true) ? 'hora' : null);
        $volteoLegacyCol = in_array('volteo', $cols, true) ? 'volteo' : null;

        if ($horaLegacyCol !== null || $volteoLegacyCol !== null) {
            $updates = [];

            if ($horaLegacyCol !== null) {
                $updates[] = "hora_am = CASE
                    WHEN hora_am IS NULL
                     AND {$horaLegacyCol} IS NOT NULL
                     AND TIME({$horaLegacyCol}) < '12:00:00'
                    THEN {$horaLegacyCol}
                    ELSE hora_am
                END";
                $updates[] = "hora_pm = CASE
                    WHEN hora_pm IS NULL
                     AND {$horaLegacyCol} IS NOT NULL
                     AND TIME({$horaLegacyCol}) >= '12:00:00'
                    THEN {$horaLegacyCol}
                    ELSE hora_pm
                END";
            }

            if ($volteoLegacyCol !== null && $horaLegacyCol !== null) {
                $updates[] = "volteo_am = CASE
                    WHEN volteo_am IS NULL
                     AND {$volteoLegacyCol} = 1
                     AND {$horaLegacyCol} IS NOT NULL
                     AND TIME({$horaLegacyCol}) < '12:00:00'
                    THEN 1
                    ELSE volteo_am
                END";
                $updates[] = "volteo_pm = CASE
                    WHEN volteo_pm IS NULL
                     AND {$volteoLegacyCol} = 1
                     AND (
                        {$horaLegacyCol} IS NULL
                        OR TIME({$horaLegacyCol}) >= '12:00:00'
                     )
                    THEN 1
                    ELSE volteo_pm
                END";
            } elseif ($volteoLegacyCol !== null) {
                $updates[] = "volteo_pm = CASE
                    WHEN volteo_pm IS NULL AND {$volteoLegacyCol} = 1 THEN 1
                    ELSE volteo_pm
                END";
            }

            if (!empty($updates)) {
                try {
                    $db->query("UPDATE fermentacion_control_diario SET " . implode(', ', $updates));
                } catch (Throwable $e) {
                    // Mantener compatibilidad aunque no se pueda migrar historial anterior.
                }
            }
        }

        return true;
    }

    /**
     * Asegura la columna unidad_peso en registros_secado para persistir la unidad visible del proceso.
     */
    public static function ensureSecadoPesoUnitColumn(): bool {
        $db = Database::getInstance();
        $cols = self::getTableColumns('registros_secado');
        if (in_array('unidad_peso', $cols, true)) {
            return true;
        }

        try {
            $db->query("ALTER TABLE registros_secado ADD COLUMN unidad_peso ENUM('LB','KG','QQ') NULL AFTER etapa_proceso");
        } catch (Throwable $e) {
            // Continuar en modo compatibilidad si el esquema no puede alterarse.
        }

        return in_array('unidad_peso', self::getTableColumns('registros_secado'), true);
    }

    /**
     * Resuelve la unidad de peso heredada para un lote.
     * Prioridad:
     * 1) Último secado con unidad persistida
     * 2) Ficha de registro
     * 3) KG por defecto
     */
    public static function resolveInheritedPesoUnitForLote($loteId, ?int $excludeSecadoId = null): string {
        $loteId = (int)$loteId;
        if ($loteId <= 0) {
            return 'KG';
        }

        $db = Database::getInstance();

        try {
            $colsSecado = self::getTableColumns('registros_secado');
            if (in_array('unidad_peso', $colsSecado, true)) {
                $params = [$loteId];
                $whereExclude = '';
                if ($excludeSecadoId !== null && $excludeSecadoId > 0) {
                    $whereExclude = ' AND id < ?';
                    $params[] = $excludeSecadoId;
                }

                $secado = $db->fetch(
                    "SELECT unidad_peso
                     FROM registros_secado
                     WHERE lote_id = ?{$whereExclude}
                       AND unidad_peso IS NOT NULL
                       AND TRIM(unidad_peso) <> ''
                     ORDER BY id DESC
                     LIMIT 1",
                    $params
                );

                if (!empty($secado['unidad_peso'])) {
                    return self::normalizePesoUnit($secado['unidad_peso']);
                }
            }
        } catch (Throwable $e) {
            // Continuar con la siguiente fuente disponible.
        }

        try {
            $colsFichas = self::getTableColumns('fichas_registro');
            if (in_array('unidad_peso', $colsFichas, true)) {
                $ficha = $db->fetch(
                    "SELECT unidad_peso
                     FROM fichas_registro
                     WHERE lote_id = ?
                     ORDER BY id DESC
                     LIMIT 1",
                    [$loteId]
                );

                if (!empty($ficha['unidad_peso'])) {
                    return self::normalizePesoUnit($ficha['unidad_peso']);
                }
            }
        } catch (Throwable $e) {
            // Si no hay ficha disponible, caer al valor por defecto.
        }

        return 'KG';
    }

    /**
     * Resuelve la unidad visible del proceso de secado según la etapa anterior.
     * PRE_SECADO: ficha de registro -> secado previo -> KG
     * SECADO_FINAL: fermentación -> secado previo -> ficha de registro -> KG
     */
    public static function resolveSecadoPesoUnitForLote($loteId, string $etapaSecado = 'PRE_SECADO', ?int $excludeSecadoId = null): string {
        $loteId = (int)$loteId;
        if ($loteId <= 0) {
            return 'KG';
        }

        $etapa = strtoupper(trim($etapaSecado));
        $db = Database::getInstance();

        if ($etapa === 'SECADO_FINAL') {
            try {
                self::ensureFermentacionPesoUnitColumn();
                $colsFermentacion = self::getTableColumns('registros_fermentacion');
                if (in_array('unidad_peso', $colsFermentacion, true)) {
                    $fermentacion = $db->fetch(
                        "SELECT unidad_peso
                         FROM registros_fermentacion
                         WHERE lote_id = ?
                           AND unidad_peso IS NOT NULL
                           AND TRIM(unidad_peso) <> ''
                         ORDER BY id DESC
                         LIMIT 1",
                        [$loteId]
                    );

                    if (!empty($fermentacion['unidad_peso'])) {
                        return self::normalizePesoUnit($fermentacion['unidad_peso']);
                    }
                }
            } catch (Throwable $e) {
                // Continuar con la siguiente fuente disponible.
            }
        }

        return self::resolveInheritedPesoUnitForLote($loteId, $excludeSecadoId);
    }

    /**
     * Asegura el catálogo base de cajones de fermentación.
     */
    public static function ensureCajonesFermentacionCatalog(int $objetivoBase = 6): bool {
        $db = Database::getInstance();

        try {
            $tablaExiste = (bool)$db->fetch("SHOW TABLES LIKE 'cajones_fermentacion'");
            if (!$tablaExiste) {
                $db->query("
                    CREATE TABLE IF NOT EXISTS cajones_fermentacion (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        numero VARCHAR(20) NOT NULL UNIQUE,
                        capacidad_kg DECIMAL(10,2) NULL,
                        material VARCHAR(50) NULL,
                        ubicacion VARCHAR(100) NULL,
                        activo TINYINT(1) DEFAULT 1,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            }

            $cols = self::getTableColumns('cajones_fermentacion');
            $hasCol = static fn(string $name): bool => in_array($name, $cols, true);

            $alterQueries = [];
            if (!$hasCol('numero')) $alterQueries[] = "ALTER TABLE cajones_fermentacion ADD COLUMN numero VARCHAR(20) NULL";
            if (!$hasCol('capacidad_kg')) $alterQueries[] = "ALTER TABLE cajones_fermentacion ADD COLUMN capacidad_kg DECIMAL(10,2) NULL AFTER numero";
            if (!$hasCol('material')) $alterQueries[] = "ALTER TABLE cajones_fermentacion ADD COLUMN material VARCHAR(50) NULL AFTER capacidad_kg";
            if (!$hasCol('ubicacion')) $alterQueries[] = "ALTER TABLE cajones_fermentacion ADD COLUMN ubicacion VARCHAR(100) NULL AFTER material";
            if (!$hasCol('activo')) $alterQueries[] = "ALTER TABLE cajones_fermentacion ADD COLUMN activo TINYINT(1) DEFAULT 1 AFTER ubicacion";
            if (!$hasCol('created_at')) $alterQueries[] = "ALTER TABLE cajones_fermentacion ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP";

            foreach ($alterQueries as $sql) {
                try {
                    $db->query($sql);
                } catch (Throwable $e) {
                    // Continuar con el esquema disponible.
                }
            }

            $cols = self::getTableColumns('cajones_fermentacion');
            $hasCol = static fn(string $name): bool => in_array($name, $cols, true);
            if (!$hasCol('numero')) {
                return false;
            }

            $hasNombreCol = $hasCol('nombre');
            $objetivoBase = max(0, $objetivoBase);

            for ($i = 1; $i <= $objetivoBase; $i++) {
                $numero = sprintf('CAJ-%02d', $i);
                $registro = $db->fetchOne(
                    "SELECT id" . ($hasNombreCol ? ", nombre" : "") . "
                     FROM cajones_fermentacion
                     WHERE numero = ?
                     LIMIT 1",
                    [$numero]
                );

                if (!$registro) {
                    $dataInsert = [
                        'numero' => $numero,
                        'capacidad_kg' => 500,
                        'material' => 'Madera',
                    ];
                    if ($hasCol('ubicacion')) {
                        $dataInsert['ubicacion'] = null;
                    }
                    if ($hasCol('activo')) {
                        $dataInsert['activo'] = 1;
                    }
                    if ($hasNombreCol) {
                        $dataInsert['nombre'] = 'Cajón ' . $i;
                    }
                    $db->insert('cajones_fermentacion', $dataInsert);
                    continue;
                }

                if ($hasNombreCol) {
                    $nombreActual = trim((string)($registro['nombre'] ?? ''));
                    if ($nombreActual === '') {
                        $db->update(
                            'cajones_fermentacion',
                            ['nombre' => 'Cajón ' . $i],
                            'id = :where_id',
                            ['where_id' => (int)$registro['id']]
                        );
                    }
                }
            }

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Obtiene el catálogo de cajones de fermentación con etiqueta visible segura.
     */
    public static function getCajonesFermentacionCatalog(bool $soloActivos = true): array {
        $db = Database::getInstance();
        self::ensureCajonesFermentacionCatalog();

        $cols = self::getTableColumns('cajones_fermentacion');
        $hasCol = static fn(string $name): bool => in_array($name, $cols, true);
        if (empty($cols) || !$hasCol('id')) {
            return [];
        }

        $exprNumero = $hasCol('numero') ? "NULLIF(TRIM(numero), '')" : 'NULL';
        $exprNombre = $hasCol('nombre') ? "NULLIF(TRIM(nombre), '')" : 'NULL';
        $exprCapacidad = $hasCol('capacidad_kg')
            ? 'capacidad_kg'
            : ($hasCol('capacidad') ? 'capacidad' : 'NULL');
        $where = ($soloActivos && $hasCol('activo')) ? 'WHERE activo = 1' : '';
        $orderBy = $hasCol('numero')
            ? 'ORDER BY numero ASC'
            : ($hasCol('nombre') ? 'ORDER BY nombre ASC' : 'ORDER BY id ASC');

        if ($hasCol('numero') && $hasCol('nombre')) {
            $exprEtiqueta = "CASE
                WHEN {$exprNumero} IS NOT NULL AND {$exprNombre} IS NOT NULL AND UPPER({$exprNumero}) <> UPPER({$exprNombre})
                    THEN CONCAT({$exprNumero}, ' - ', {$exprNombre})
                ELSE COALESCE({$exprNumero}, {$exprNombre}, CONCAT('Cajón #', id))
            END";
        } elseif ($hasCol('numero')) {
            $exprEtiqueta = "COALESCE({$exprNumero}, CONCAT('Cajón #', id))";
        } elseif ($hasCol('nombre')) {
            $exprEtiqueta = "COALESCE({$exprNombre}, CONCAT('Cajón #', id))";
        } else {
            $exprEtiqueta = "CONCAT('Cajón #', id)";
        }

        $exprNumeroLabel = $hasCol('numero')
            ? "COALESCE({$exprNumero}, CONCAT('CAJ-', LPAD(id, 2, '0')))"
            : "CONCAT('CAJ-', LPAD(id, 2, '0'))";

        try {
            return $db->fetchAll("
                SELECT id,
                       {$exprEtiqueta} as nombre,
                       {$exprNumeroLabel} as numero,
                       {$exprCapacidad} as capacidad_kg
                FROM cajones_fermentacion
                {$where}
                {$orderBy}
            ");
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Asegura la tabla de detalle de pagos por proveedor para fichas.
     */
    public static function ensureFichaPagoDetalleTable(): bool {
        $db = Database::getInstance();

        try {
            $tablaExiste = (bool)$db->fetch("SHOW TABLES LIKE 'fichas_pago_detalle'");
            if (!$tablaExiste) {
                $db->query("
                    CREATE TABLE IF NOT EXISTS fichas_pago_detalle (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        ficha_id INT NOT NULL,
                        proveedor_id INT NULL,
                        proveedor_nombre VARCHAR(150) NOT NULL,
                        fecha_pago DATE NOT NULL,
                        tipo_comprobante ENUM('FACTURA','NOTA_COMPRA') NOT NULL,
                        factura_compra VARCHAR(80) NOT NULL,
                        cantidad_comprada_unidad ENUM('LB','KG','QQ') NOT NULL DEFAULT 'KG',
                        cantidad_comprada DECIMAL(10,2) NOT NULL,
                        cantidad_comprada_kg DECIMAL(10,4) NOT NULL,
                        forma_pago ENUM('EFECTIVO','TRANSFERENCIA','CHEQUE','OTROS') NOT NULL,
                        precio_base_dia DECIMAL(10,4) NOT NULL,
                        diferencial_usd DECIMAL(10,4) DEFAULT 0,
                        precio_unitario_final DECIMAL(10,4) NOT NULL,
                        precio_total_pagar DECIMAL(12,2) NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_ficha_pago_detalle_ficha (ficha_id),
                        INDEX idx_ficha_pago_detalle_proveedor (proveedor_id),
                        CONSTRAINT fk_ficha_pago_detalle_ficha FOREIGN KEY (ficha_id) REFERENCES fichas_registro(id) ON DELETE CASCADE,
                        CONSTRAINT fk_ficha_pago_detalle_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            }

            $cols = self::getTableColumns('fichas_pago_detalle');
            $hasCol = static fn(string $name): bool => in_array($name, $cols, true);

            $alterQueries = [];
            if (!$hasCol('proveedor_id')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN proveedor_id INT NULL AFTER ficha_id";
            if (!$hasCol('proveedor_nombre')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN proveedor_nombre VARCHAR(150) NOT NULL AFTER proveedor_id";
            if (!$hasCol('fecha_pago')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN fecha_pago DATE NOT NULL AFTER proveedor_nombre";
            if (!$hasCol('tipo_comprobante')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN tipo_comprobante ENUM('FACTURA','NOTA_COMPRA') NOT NULL AFTER fecha_pago";
            if (!$hasCol('factura_compra')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN factura_compra VARCHAR(80) NOT NULL AFTER tipo_comprobante";
            if (!$hasCol('cantidad_comprada_unidad')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN cantidad_comprada_unidad ENUM('LB','KG','QQ') NOT NULL DEFAULT 'KG' AFTER factura_compra";
            if (!$hasCol('cantidad_comprada')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN cantidad_comprada DECIMAL(10,2) NOT NULL AFTER cantidad_comprada_unidad";
            if (!$hasCol('cantidad_comprada_kg')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN cantidad_comprada_kg DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER cantidad_comprada";
            if (!$hasCol('forma_pago')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN forma_pago ENUM('EFECTIVO','TRANSFERENCIA','CHEQUE','OTROS') NOT NULL AFTER cantidad_comprada_kg";
            if (!$hasCol('precio_base_dia')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN precio_base_dia DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER forma_pago";
            if (!$hasCol('diferencial_usd')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN diferencial_usd DECIMAL(10,4) DEFAULT 0 AFTER precio_base_dia";
            if (!$hasCol('precio_unitario_final')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN precio_unitario_final DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER diferencial_usd";
            if (!$hasCol('precio_total_pagar')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN precio_total_pagar DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER precio_unitario_final";
            if (!$hasCol('created_at')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
            if (!$hasCol('updated_at')) $alterQueries[] = "ALTER TABLE fichas_pago_detalle ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";

            foreach ($alterQueries as $sql) {
                try {
                    $db->query($sql);
                } catch (Throwable $e) {
                    // Continuar con lo disponible para no bloquear la operación.
                }
            }

            return (bool)$db->fetch("SHOW TABLES LIKE 'fichas_pago_detalle'");
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Construye la lista de proveedores participantes de una ficha.
     */
    public static function getFichaPagoParticipantes(array $ficha, array $proveedoresCatalogo = []): array {
        $toLower = static function (string $value): string {
            $value = trim($value);
            if ($value === '') {
                return '';
            }
            return function_exists('mb_strtolower')
                ? mb_strtolower($value, 'UTF-8')
                : strtolower($value);
        };

        $catalogoPorNombre = [];
        $catalogoPorCodigo = [];
        $catalogoPorEtiqueta = [];
        foreach ($proveedoresCatalogo as $proveedor) {
            $idProveedor = (int)($proveedor['id'] ?? 0);
            $nombreProveedor = trim((string)($proveedor['nombre'] ?? ''));
            $codigoProveedor = trim((string)($proveedor['codigo'] ?? ''));
            if ($nombreProveedor !== '') {
                $catalogoPorNombre[$toLower($nombreProveedor)] = [
                    'id' => $idProveedor,
                    'nombre' => $nombreProveedor,
                    'codigo' => $codigoProveedor,
                ];
            }
            if ($codigoProveedor !== '') {
                $catalogoPorCodigo[$toLower($codigoProveedor)] = [
                    'id' => $idProveedor,
                    'nombre' => $nombreProveedor !== '' ? $nombreProveedor : $codigoProveedor,
                    'codigo' => $codigoProveedor,
                ];
            }
            if ($codigoProveedor !== '' && $nombreProveedor !== '') {
                $catalogoPorEtiqueta[$toLower($codigoProveedor . ' - ' . $nombreProveedor)] = [
                    'id' => $idProveedor,
                    'nombre' => $nombreProveedor,
                    'codigo' => $codigoProveedor,
                ];
            }
        }

        $participantesTexto = self::parseProveedorRutaCompuesta((string)($ficha['proveedor_ruta'] ?? ''));
        $nombres = $participantesTexto['proveedores'] ?? [];
        $proveedorNombre = trim((string)($ficha['proveedor_nombre'] ?? ''));
        if ($proveedorNombre !== '' && !in_array($proveedorNombre, $nombres, true)) {
            $nombres[] = $proveedorNombre;
        }
        if (empty($nombres)) {
            $textoSimple = trim((string)($ficha['proveedor_ruta'] ?? ''));
            if ($textoSimple !== '') {
                $nombres[] = $textoSimple;
            }
        }

        $participantes = [];
        $vistos = [];
        foreach ($nombres as $nombreRaw) {
            $nombreRaw = trim((string)$nombreRaw);
            if ($nombreRaw === '') {
                continue;
            }
            $clave = $toLower($nombreRaw);
            $proveedorCatalogo = $catalogoPorNombre[$clave]
                ?? $catalogoPorCodigo[$clave]
                ?? $catalogoPorEtiqueta[$clave]
                ?? null;
            $nombreFinal = trim((string)($proveedorCatalogo['nombre'] ?? $nombreRaw));
            $claveFinal = $toLower($nombreFinal);
            if ($claveFinal === '' || isset($vistos[$claveFinal])) {
                continue;
            }
            $vistos[$claveFinal] = true;
            $participantes[] = [
                'proveedor_id' => (int)($proveedorCatalogo['id'] ?? 0),
                'proveedor_nombre' => $nombreFinal,
            ];
        }

        if (empty($participantes)) {
            $nombreFallback = $proveedorNombre !== '' ? $proveedorNombre : trim((string)($ficha['proveedor_ruta'] ?? ''));
            $participantes[] = [
                'proveedor_id' => (int)($ficha['proveedor_id'] ?? ($ficha['lote_proveedor_id'] ?? 0)),
                'proveedor_nombre' => $nombreFallback !== '' ? $nombreFallback : 'Proveedor',
            ];
        }

        return $participantes;
    }

    /**
     * Obtiene los pagos detallados de una ficha, con compatibilidad hacia registros antiguos.
     */
    public static function getFichaPagoDetalles(int $fichaId, ?array $ficha = null, array $proveedoresCatalogo = []): array {
        $db = Database::getInstance();
        $detalles = [];

        if (self::ensureFichaPagoDetalleTable()) {
            try {
                $detalles = $db->fetchAll(
                    "SELECT *
                     FROM fichas_pago_detalle
                     WHERE ficha_id = ?
                     ORDER BY id ASC",
                    [$fichaId]
                );
            } catch (Throwable $e) {
                $detalles = [];
            }
        }

        if (!empty($detalles)) {
            return array_map(static function (array $detalle) use ($fichaId): array {
                $unidad = strtoupper(trim((string)($detalle['cantidad_comprada_unidad'] ?? 'KG')));
                $cantidad = isset($detalle['cantidad_comprada']) ? (float)$detalle['cantidad_comprada'] : null;
                $cantidadKg = isset($detalle['cantidad_comprada_kg']) ? (float)$detalle['cantidad_comprada_kg'] : null;
                if (($cantidadKg === null || $cantidadKg <= 0) && $cantidad !== null && $cantidad > 0) {
                    $cantidadKg = self::pesoToKg($cantidad, $unidad);
                }

                return [
                    'id' => (int)($detalle['id'] ?? 0),
                    'ficha_id' => (int)($detalle['ficha_id'] ?? $fichaId),
                    'proveedor_id' => (int)($detalle['proveedor_id'] ?? 0),
                    'proveedor_nombre' => trim((string)($detalle['proveedor_nombre'] ?? 'Proveedor')),
                    'fecha_pago' => trim((string)($detalle['fecha_pago'] ?? '')),
                    'tipo_comprobante' => strtoupper(trim((string)($detalle['tipo_comprobante'] ?? ''))),
                    'factura_compra' => trim((string)($detalle['factura_compra'] ?? '')),
                    'cantidad_comprada_unidad' => in_array($unidad, ['LB', 'KG', 'QQ'], true) ? $unidad : 'KG',
                    'cantidad_comprada' => $cantidad,
                    'cantidad_comprada_kg' => $cantidadKg,
                    'forma_pago' => strtoupper(trim((string)($detalle['forma_pago'] ?? ''))),
                    'precio_base_dia' => isset($detalle['precio_base_dia']) ? (float)$detalle['precio_base_dia'] : null,
                    'diferencial_usd' => isset($detalle['diferencial_usd']) ? (float)$detalle['diferencial_usd'] : 0.0,
                    'precio_unitario_final' => isset($detalle['precio_unitario_final']) ? (float)$detalle['precio_unitario_final'] : null,
                    'precio_total_pagar' => isset($detalle['precio_total_pagar']) ? (float)$detalle['precio_total_pagar'] : null,
                ];
            }, $detalles);
        }

        if ($ficha === null) {
            $ficha = $db->fetchOne(
                "SELECT f.*,
                        l.proveedor_id as lote_proveedor_id,
                        p.nombre as proveedor_nombre
                 FROM fichas_registro f
                 LEFT JOIN lotes l ON f.lote_id = l.id
                 LEFT JOIN proveedores p ON l.proveedor_id = p.id
                 WHERE f.id = ?",
                [$fichaId]
            ) ?: [];
        }

        $participantes = self::getFichaPagoParticipantes($ficha, $proveedoresCatalogo);
        $cantidadParticipantes = count($participantes);
        $fechaPagoBase = trim((string)($ficha['fecha_pago'] ?? ''));
        $tipoComprobanteBase = strtoupper(trim((string)($ficha['tipo_comprobante'] ?? '')));
        $facturaBase = trim((string)($ficha['factura_compra'] ?? ''));
        $cantidadUnidadBase = strtoupper(trim((string)($ficha['cantidad_comprada_unidad'] ?? 'KG')));
        $cantidadBase = isset($ficha['cantidad_comprada']) && $ficha['cantidad_comprada'] !== null
            ? (float)$ficha['cantidad_comprada']
            : null;
        $cantidadBaseKg = $cantidadBase !== null && $cantidadBase > 0
            ? self::pesoToKg($cantidadBase, $cantidadUnidadBase)
            : null;
        $formaPagoBase = strtoupper(trim((string)($ficha['forma_pago'] ?? '')));
        $precioBaseDia = isset($ficha['precio_base_dia']) && $ficha['precio_base_dia'] !== null
            ? (float)$ficha['precio_base_dia']
            : null;
        $diferencialBase = isset($ficha['diferencial_usd']) && $ficha['diferencial_usd'] !== null
            ? (float)$ficha['diferencial_usd']
            : 0.0;
        $precioUnitarioBase = isset($ficha['precio_unitario_final']) && $ficha['precio_unitario_final'] !== null
            ? (float)$ficha['precio_unitario_final']
            : null;
        $precioTotalBase = isset($ficha['precio_total_pagar']) && $ficha['precio_total_pagar'] !== null
            ? (float)$ficha['precio_total_pagar']
            : null;

        $resultado = [];
        foreach ($participantes as $indice => $participante) {
            $esUnicoParticipante = $cantidadParticipantes === 1;
            $resultado[] = [
                'id' => 0,
                'ficha_id' => $fichaId,
                'proveedor_id' => (int)($participante['proveedor_id'] ?? 0),
                'proveedor_nombre' => trim((string)($participante['proveedor_nombre'] ?? 'Proveedor')),
                'fecha_pago' => $fechaPagoBase,
                'tipo_comprobante' => $tipoComprobanteBase,
                'factura_compra' => $esUnicoParticipante ? $facturaBase : '',
                'cantidad_comprada_unidad' => in_array($cantidadUnidadBase, ['LB', 'KG', 'QQ'], true) ? $cantidadUnidadBase : 'KG',
                'cantidad_comprada' => $esUnicoParticipante ? $cantidadBase : null,
                'cantidad_comprada_kg' => $esUnicoParticipante ? $cantidadBaseKg : null,
                'forma_pago' => $formaPagoBase,
                'precio_base_dia' => $precioBaseDia,
                'diferencial_usd' => $diferencialBase,
                'precio_unitario_final' => $precioUnitarioBase,
                'precio_total_pagar' => $esUnicoParticipante ? $precioTotalBase : null,
            ];
        }

        return $resultado;
    }

    /**
     * Resume pagos detallados para mantener compatibilidad con la ficha principal.
     */
    public static function getFichaPagoResumen(array $detalles): array {
        $resumen = [
            'detalle_count' => 0,
            'cantidad_total_kg' => 0.0,
            'precio_total_pagar' => 0.0,
            'fecha_pago' => null,
            'tipo_comprobante' => null,
            'factura_compra' => null,
            'cantidad_comprada_unidad' => 'KG',
            'cantidad_comprada' => null,
            'forma_pago' => null,
            'precio_base_dia' => null,
            'diferencial_usd' => null,
            'precio_unitario_final' => null,
            'proveedores' => [],
        ];

        if (empty($detalles)) {
            return $resumen;
        }

        $primerDetalle = null;
        $tipos = [];
        $formas = [];
        $preciosBase = [];
        $diferenciales = [];
        $preciosUnitarios = [];
        $ultimaFecha = null;

        foreach ($detalles as $detalle) {
            $fechaPago = trim((string)($detalle['fecha_pago'] ?? ''));
            $tipoComprobante = strtoupper(trim((string)($detalle['tipo_comprobante'] ?? '')));
            $facturaCompra = trim((string)($detalle['factura_compra'] ?? ''));
            $unidadCantidad = strtoupper(trim((string)($detalle['cantidad_comprada_unidad'] ?? 'KG')));
            $cantidad = isset($detalle['cantidad_comprada']) ? (float)$detalle['cantidad_comprada'] : null;
            $cantidadKg = isset($detalle['cantidad_comprada_kg']) && $detalle['cantidad_comprada_kg'] !== null
                ? (float)$detalle['cantidad_comprada_kg']
                : (($cantidad !== null && $cantidad > 0) ? self::pesoToKg($cantidad, $unidadCantidad) : 0.0);
            $formaPago = strtoupper(trim((string)($detalle['forma_pago'] ?? '')));
            $precioBase = isset($detalle['precio_base_dia']) && $detalle['precio_base_dia'] !== null
                ? (float)$detalle['precio_base_dia']
                : null;
            $diferencial = isset($detalle['diferencial_usd']) && $detalle['diferencial_usd'] !== null
                ? (float)$detalle['diferencial_usd']
                : null;
            $precioUnitario = isset($detalle['precio_unitario_final']) && $detalle['precio_unitario_final'] !== null
                ? (float)$detalle['precio_unitario_final']
                : null;
            $precioTotal = isset($detalle['precio_total_pagar']) && $detalle['precio_total_pagar'] !== null
                ? (float)$detalle['precio_total_pagar']
                : 0.0;
            $proveedorNombre = trim((string)($detalle['proveedor_nombre'] ?? ''));

            if ($primerDetalle === null) {
                $primerDetalle = [
                    'fecha_pago' => $fechaPago,
                    'tipo_comprobante' => $tipoComprobante,
                    'factura_compra' => $facturaCompra,
                    'forma_pago' => $formaPago,
                    'precio_base_dia' => $precioBase,
                    'diferencial_usd' => $diferencial,
                    'precio_unitario_final' => $precioUnitario,
                ];
            }

            $resumen['detalle_count']++;
            $resumen['cantidad_total_kg'] += max(0.0, (float)$cantidadKg);
            $resumen['precio_total_pagar'] += max(0.0, (float)$precioTotal);

            if ($fechaPago !== '') {
                $timestamp = strtotime($fechaPago);
                if ($timestamp !== false && ($ultimaFecha === null || $timestamp > $ultimaFecha)) {
                    $ultimaFecha = $timestamp;
                    $resumen['fecha_pago'] = $fechaPago;
                }
            }
            if ($tipoComprobante !== '') {
                $tipos[$tipoComprobante] = true;
            }
            if ($formaPago !== '') {
                $formas[$formaPago] = true;
            }
            if ($precioBase !== null) {
                $preciosBase[(string)$precioBase] = $precioBase;
            }
            if ($diferencial !== null) {
                $diferenciales[(string)$diferencial] = $diferencial;
            }
            if ($precioUnitario !== null) {
                $preciosUnitarios[(string)$precioUnitario] = $precioUnitario;
            }
            if ($proveedorNombre !== '' && !in_array($proveedorNombre, $resumen['proveedores'], true)) {
                $resumen['proveedores'][] = $proveedorNombre;
            }
        }

        $resumen['cantidad_comprada'] = $resumen['cantidad_total_kg'] > 0
            ? round($resumen['cantidad_total_kg'], 4)
            : null;

        if ($primerDetalle !== null) {
            $resumen['tipo_comprobante'] = count($tipos) === 1 ? $primerDetalle['tipo_comprobante'] : null;
            $resumen['factura_compra'] = $resumen['detalle_count'] === 1 ? $primerDetalle['factura_compra'] : null;
            $resumen['forma_pago'] = count($formas) === 1 ? $primerDetalle['forma_pago'] : null;
            $resumen['precio_base_dia'] = count($preciosBase) === 1 ? $primerDetalle['precio_base_dia'] : null;
            $resumen['diferencial_usd'] = count($diferenciales) === 1 ? $primerDetalle['diferencial_usd'] : null;
            $resumen['precio_unitario_final'] = count($preciosUnitarios) === 1 ? $primerDetalle['precio_unitario_final'] : null;
        }

        return $resumen;
    }

    /**
     * Determina si una ficha ya tiene pago registrado.
     */
    public static function fichaTienePagoRegistrado(array $ficha, ?array $detalles = null): bool {
        $fichaId = (int)($ficha['id'] ?? 0);
        if ($detalles === null && $fichaId > 0) {
            $detalles = self::getFichaPagoDetalles($fichaId, $ficha);
        }

        if (!empty($detalles)) {
            foreach ($detalles as $detalle) {
                $fechaPago = trim((string)($detalle['fecha_pago'] ?? ''));
                $tipoComprobante = strtoupper(trim((string)($detalle['tipo_comprobante'] ?? '')));
                $facturaCompra = trim((string)($detalle['factura_compra'] ?? ''));
                $unidadCantidad = strtoupper(trim((string)($detalle['cantidad_comprada_unidad'] ?? 'KG')));
                $cantidad = isset($detalle['cantidad_comprada']) ? (float)$detalle['cantidad_comprada'] : 0.0;
                $formaPago = strtoupper(trim((string)($detalle['forma_pago'] ?? '')));
                $precioTotal = isset($detalle['precio_total_pagar']) ? (float)$detalle['precio_total_pagar'] : 0.0;

                if ($fechaPago !== ''
                    && in_array($tipoComprobante, ['FACTURA', 'NOTA_COMPRA'], true)
                    && $facturaCompra !== ''
                    && in_array($unidadCantidad, ['LB', 'KG', 'QQ'], true)
                    && $cantidad > 0
                    && in_array($formaPago, ['EFECTIVO', 'TRANSFERENCIA', 'CHEQUE', 'OTROS'], true)
                    && $precioTotal > 0
                ) {
                    return true;
                }
            }
        }

        $fechaPago = trim((string)($ficha['fecha_pago'] ?? ''));
        $tipoComprobante = strtoupper(trim((string)($ficha['tipo_comprobante'] ?? '')));
        $facturaCompra = trim((string)($ficha['factura_compra'] ?? ''));
        $unidadCantidad = strtoupper(trim((string)($ficha['cantidad_comprada_unidad'] ?? 'KG')));
        $cantidad = isset($ficha['cantidad_comprada']) ? (float)$ficha['cantidad_comprada'] : 0.0;
        $formaPago = strtoupper(trim((string)($ficha['forma_pago'] ?? '')));

        if ($fechaPago !== ''
            && in_array($tipoComprobante, ['FACTURA', 'NOTA_COMPRA'], true)
            && $facturaCompra !== ''
            && in_array($unidadCantidad, ['LB', 'KG', 'QQ'], true)
            && $cantidad > 0
            && in_array($formaPago, ['EFECTIVO', 'TRANSFERENCIA', 'CHEQUE', 'OTROS'], true)
        ) {
            return true;
        }

        return isset($ficha['precio_total_pagar']) && $ficha['precio_total_pagar'] !== null;
    }

    /**
     * Asegura la existencia de la tabla de empaquetado y columnas mínimas.
     * Permite operar en instalaciones donde el patch aún no se ejecutó.
     */
    public static function ensureEmpaquetadoTable(): bool {
        $db = Database::getInstance();

        try {
            $tablaExiste = (bool)$db->fetch("SHOW TABLES LIKE 'registros_empaquetado'");
            if (!$tablaExiste) {
                $db->query("
                    CREATE TABLE IF NOT EXISTS registros_empaquetado (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        lote_id INT NOT NULL,
                        tipo_empaque VARCHAR(30) NOT NULL,
                        peso_saco DECIMAL(10,2) NOT NULL,
                        fecha_empaquetado DATE NULL,
                        numero_sacos INT NULL,
                        peso_total DECIMAL(10,2) NULL,
                        lote_empaque VARCHAR(80) NULL,
                        destino VARCHAR(150) NULL,
                        observaciones TEXT NULL,
                        operador_id INT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_empaque_lote (lote_id),
                        INDEX idx_empaque_fecha (fecha_empaquetado),
                        CONSTRAINT fk_empaque_lote FOREIGN KEY (lote_id) REFERENCES lotes(id) ON DELETE CASCADE,
                        CONSTRAINT fk_empaque_operador FOREIGN KEY (operador_id) REFERENCES usuarios(id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            }

            $cols = self::getTableColumns('registros_empaquetado');
            $hasCol = static fn(string $name): bool => in_array($name, $cols, true);

            $alterQueries = [];
            if (!$hasCol('tipo_empaque')) $alterQueries[] = "ALTER TABLE registros_empaquetado ADD COLUMN tipo_empaque VARCHAR(30) NOT NULL AFTER lote_id";
            if (!$hasCol('peso_saco')) $alterQueries[] = "ALTER TABLE registros_empaquetado ADD COLUMN peso_saco DECIMAL(10,2) NOT NULL AFTER tipo_empaque";
            if (!$hasCol('fecha_empaquetado')) $alterQueries[] = "ALTER TABLE registros_empaquetado ADD COLUMN fecha_empaquetado DATE NULL AFTER peso_saco";
            if (!$hasCol('numero_sacos')) $alterQueries[] = "ALTER TABLE registros_empaquetado ADD COLUMN numero_sacos INT NULL AFTER fecha_empaquetado";
            if (!$hasCol('peso_total')) $alterQueries[] = "ALTER TABLE registros_empaquetado ADD COLUMN peso_total DECIMAL(10,2) NULL AFTER numero_sacos";
            if (!$hasCol('lote_empaque')) $alterQueries[] = "ALTER TABLE registros_empaquetado ADD COLUMN lote_empaque VARCHAR(80) NULL AFTER peso_total";
            if (!$hasCol('destino')) $alterQueries[] = "ALTER TABLE registros_empaquetado ADD COLUMN destino VARCHAR(150) NULL AFTER lote_empaque";
            if (!$hasCol('observaciones')) $alterQueries[] = "ALTER TABLE registros_empaquetado ADD COLUMN observaciones TEXT NULL AFTER destino";
            if (!$hasCol('operador_id')) $alterQueries[] = "ALTER TABLE registros_empaquetado ADD COLUMN operador_id INT NULL AFTER observaciones";
            if (!$hasCol('created_at')) $alterQueries[] = "ALTER TABLE registros_empaquetado ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
            if (!$hasCol('updated_at')) $alterQueries[] = "ALTER TABLE registros_empaquetado ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";

            foreach ($alterQueries as $sql) {
                try {
                    $db->query($sql);
                } catch (Throwable $e) {
                    // Si no se puede alterar por permisos o FKs existentes, continuar con lo disponible.
                }
            }

            return (bool)$db->fetch("SHOW TABLES LIKE 'registros_empaquetado'");
        } catch (Throwable $e) {
            return false;
        }
    }
    
    /**
     * Registrar historial de lote
     */
    public static function registrarHistorial($loteId, $accion, $descripcion, $datosAnteriores = null, $datosNuevos = null) {

        $db = Database::getInstance();
        
        $db->insert('lotes_historial', [
            'lote_id' => $loteId,
            'accion' => $accion,
            'descripcion' => $descripcion,
            'datos_anteriores' => $datosAnteriores ? json_encode($datosAnteriores) : null,
            'datos_nuevos' => $datosNuevos ? json_encode($datosNuevos) : null,
            'usuario_id' => Auth::id()
        ]);
    }
    /**
 * Alias de compatibilidad (algunos módulos llaman logHistory)
 */
public static function logHistory($loteId, $accion, $descripcion, $datosAnteriores = null, $datosNuevos = null) {
    return self::registrarHistorial($loteId, $accion, $descripcion, $datosAnteriores, $datosNuevos);
}

    /**
     * Paginación
     */
    public static function paginate($total, $perPage, $currentPage) {
        $totalPages = ceil($total / $perPage);
        $currentPage = max(1, min($currentPage, $totalPages));
        
        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'offset' => ($currentPage - 1) * $perPage,
            'has_prev' => $currentPage > 1,
            'has_next' => $currentPage < $totalPages
        ];
    }
    
    /**
     * Respuesta JSON para API
     */
    public static function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Validar campos requeridos
     */
    public static function validateRequired($data, $fields) {
        $errors = [];
        foreach ($fields as $field => $label) {
            if (empty($data[$field])) {
                $errors[$field] = "El campo {$label} es requerido";
            }
        }
        return $errors;
    }
}
