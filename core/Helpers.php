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
     * 1) Código de la categoría semilla (M, B, ES, FM, VP)
     * 2) Mapeo por categoría textual
     * 3) Mapeo por tipo (MERCADO, BODEGA, RUTA, PRODUCTOR)
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

        $codigosCategoriaValidos = ['M', 'B', 'ES', 'FM', 'VP'];
        $categoriaMap = [
            'MERCADO' => 'M',
            'BODEGA' => 'B',
            'ESMERALDAS' => 'ES',
            'FLOR DE MANABI' => 'FM',
            'VIA PEDERNALES' => 'VP',
        ];
        $tipoMap = [
            'MERCADO' => 'M',
            'BODEGA' => 'B',
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
