<?php
/**
 * MEGABLESSING - Sistema de Control de Procesos de Cacao
 * Clase Helpers - Funciones auxiliares
 * Desarrollado por: Shalom Software
 */

class Helpers {
    
    /**
     * Generar código de lote
     * Formato: XX-DD-MM-AA-EE-F
     */
    /**
 * Generar código de lote
 * Formato: PROV-DD-MM-YY-ESTADO-FER
 * - ESTADO sale de estados_producto.codigo (si llega ID lo resuelve)
 * - FER: NF si no hay fermentación previa, o código de estados_fermentacion.codigo (si llega ID lo resuelve)
 */
public static function generateLoteCode($proveedorCodigo, $fecha, $estadoProducto, $estadoFermentacion = null) {
    $db = Database::getInstance();

    // Fecha segura
    $ts = strtotime($fecha);
    if (!$ts) $ts = time();

    $dia  = date('d', $ts);
    $mes  = date('m', $ts);
    $anio = date('y', $ts);

    // PROV
    $prov = strtoupper(trim((string)$proveedorCodigo));
    $prov = preg_replace('/[^A-Z0-9]/', '', $prov);
    if ($prov === '') $prov = 'XX';

    // ESTADO: si llega ID -> buscar codigo en estados_producto
    $estadoCode = $estadoProducto;
    if (is_numeric($estadoProducto)) {
        $row = $db->fetch("SELECT codigo FROM estados_producto WHERE id = ?", [(int)$estadoProducto]);
        $estadoCode = $row['codigo'] ?? '';
    }
    $estadoCode = strtoupper(trim((string)$estadoCode));
    $estadoCode = preg_replace('/[^A-Z0-9]/', '', $estadoCode);
    if ($estadoCode === '') $estadoCode = 'EC'; // fallback

    // FER: si no hay fermentación previa -> NF
    if (empty($estadoFermentacion)) {
        $ferCode = 'NF';
    } else {
        // si llega ID -> buscar codigo en estados_fermentacion
        $ferCode = $estadoFermentacion;
        if (is_numeric($estadoFermentacion)) {
            $row = $db->fetch("SELECT codigo FROM estados_fermentacion WHERE id = ?", [(int)$estadoFermentacion]);
            $ferCode = $row['codigo'] ?? 'F';
        }
        $ferCode = strtoupper(trim((string)$ferCode));
        $ferCode = preg_replace('/[^A-Z0-9]/', '', $ferCode);
        if ($ferCode === '') $ferCode = 'F';
    }

    return "{$prov}-{$dia}-{$mes}-{$anio}-{$estadoCode}-{$ferCode}";
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
            'PRE_SECADO' => ['color' => 'bg-yellow-100 text-yellow-800', 'label' => 'Pre-Secado (Legado)'],
            'FERMENTACION' => ['color' => 'bg-orange-100 text-orange-800', 'label' => 'Fermentación'],
            'SECADO' => ['color' => 'bg-red-100 text-red-800', 'label' => 'Secado'],
            'CALIDAD_POST' => ['color' => 'bg-green-100 text-green-800', 'label' => 'Prueba de Corte'],
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
