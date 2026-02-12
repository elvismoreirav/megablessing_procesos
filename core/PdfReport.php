<?php
/**
 * PdfReport - Generador de reportes PDF para Megablessing
 * Utiliza formato de texto simple para compatibilidad
 */

class PdfReport {
    private $title;
    private $subtitle;
    private $content = [];
    private $tableData = [];
    private $stats = [];
    private $empresa;
    private $logoUrl;
    private $fechaGeneracion;
    
    // Configuración de página
    private $pageWidth = 210; // A4 mm
    private $pageHeight = 297;
    private $marginLeft = 15;
    private $marginRight = 15;
    private $marginTop = 20;
    private $marginBottom = 20;
    
    // Colores Shalom
    private $colorPrimary = '#16a34a';
    private $colorSecondary = '#15803d';
    private $colorAccent = '#22c55e';
    
    public function __construct($title, $subtitle = '') {
        $this->title = $title;
        $this->subtitle = $subtitle;
        $this->fechaGeneracion = date('d/m/Y H:i:s');
        
        // Obtener nombre de empresa de parámetros si está disponible
        $this->empresa = 'MEGABLESSING';
        $this->logoUrl = '';
    }
    
    public function setEmpresa($empresa) {
        $this->empresa = $empresa;
        return $this;
    }

    public function setLogoUrl($logoUrl) {
        $this->logoUrl = trim((string)$logoUrl);
        return $this;
    }
    
    public function addSection($title, $content) {
        $this->content[] = [
            'type' => 'section',
            'title' => $title,
            'content' => $content
        ];
        return $this;
    }
    
    public function addStats($stats) {
        $this->stats = array_merge($this->stats, $stats);
        return $this;
    }
    
    public function addTable($headers, $rows, $title = '') {
        $this->tableData[] = [
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows
        ];
        return $this;
    }
    
    public function addKeyValue($data, $title = '') {
        $this->content[] = [
            'type' => 'keyvalue',
            'title' => $title,
            'data' => $data
        ];
        return $this;
    }
    
    /**
     * Genera HTML para vista previa o conversión
     */
    public function generateHtml() {
        $html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($this->title) . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #1f2937;
            background: white;
            padding: 20mm 15mm;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid ' . $this->colorPrimary . ';
        }
        .logo {
            font-size: 24px;
            font-weight: 700;
            color: ' . $this->colorPrimary . ';
            letter-spacing: 2px;
        }
        .logo-image-wrap {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 4px;
        }
        .logo-image {
            max-height: 64px;
            max-width: 280px;
            width: auto;
            object-fit: contain;
        }
        .logo span {
            color: ' . $this->colorSecondary . ';
        }
        .report-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
            margin-top: 10px;
        }
        .report-subtitle {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }
        .meta-info {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #6b7280;
            margin-top: 10px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin: 20px 0;
        }
        .stat-card {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            padding: 12px;
            border-radius: 8px;
            border-left: 4px solid ' . $this->colorPrimary . ';
        }
        .stat-card.orange {
            background: linear-gradient(135deg, #fff7ed 0%, #fed7aa 100%);
            border-left-color: #f97316;
        }
        .stat-card.amber {
            background: linear-gradient(135deg, #fffbeb 0%, #fde68a 100%);
            border-left-color: #f59e0b;
        }
        .stat-card.emerald {
            background: linear-gradient(135deg, #ecfdf5 0%, #a7f3d0 100%);
            border-left-color: #10b981;
        }
        .stat-card.blue {
            background: linear-gradient(135deg, #eff6ff 0%, #bfdbfe 100%);
            border-left-color: #3b82f6;
        }
        .stat-card.purple {
            background: linear-gradient(135deg, #faf5ff 0%, #e9d5ff 100%);
            border-left-color: #a855f7;
        }
        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
        }
        .stat-label {
            font-size: 10px;
            color: #6b7280;
            margin-top: 4px;
        }
        .section {
            margin: 20px 0;
            page-break-inside: avoid;
        }
        .section-title {
            font-size: 14px;
            font-weight: 600;
            color: ' . $this->colorPrimary . ';
            padding-bottom: 8px;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 10px;
        }
        th {
            background: ' . $this->colorPrimary . ';
            color: white;
            padding: 8px 6px;
            text-align: left;
            font-weight: 600;
        }
        td {
            padding: 6px;
            border-bottom: 1px solid #e5e7eb;
        }
        tr:nth-child(even) {
            background: #f9fafb;
        }
        tr:hover {
            background: #f0fdf4;
        }
        .kv-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
        .kv-item {
            display: flex;
            justify-content: space-between;
            padding: 6px 10px;
            background: #f9fafb;
            border-radius: 4px;
        }
        .kv-label {
            color: #6b7280;
        }
        .kv-value {
            font-weight: 600;
            color: #111827;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 500;
        }
        .badge-green {
            background: #dcfce7;
            color: #166534;
        }
        .badge-yellow {
            background: #fef9c3;
            color: #854d0e;
        }
        .badge-red {
            background: #fee2e2;
            color: #991b1b;
        }
        .badge-blue {
            background: #dbeafe;
            color: #1e40af;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 9px;
            color: #9ca3af;
        }
        @media print {
            body {
                padding: 10mm;
            }
            .section {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        ' . ($this->logoUrl !== ''
            ? '<div class="logo-image-wrap"><img src="' . htmlspecialchars($this->logoUrl) . '" alt="Logo" class="logo-image"></div>'
            : '<div class="logo">MEGA<span>BLESSING</span></div>') . '
        <div class="report-title">' . htmlspecialchars($this->title) . '</div>';
        
        if ($this->subtitle) {
            $html .= '<div class="report-subtitle">' . htmlspecialchars($this->subtitle) . '</div>';
        }
        
        $html .= '
        <div class="meta-info">
            <span>Generado: ' . $this->fechaGeneracion . '</span>
            <span>' . htmlspecialchars($this->empresa) . '</span>
        </div>
    </div>';
        
        // Stats section
        if (!empty($this->stats)) {
            $html .= '<div class="stats-grid">';
            foreach ($this->stats as $stat) {
                $colorClass = $stat['color'] ?? '';
                $html .= '
                <div class="stat-card ' . $colorClass . '">
                    <div class="stat-value">' . htmlspecialchars($stat['value']) . '</div>
                    <div class="stat-label">' . htmlspecialchars($stat['label']) . '</div>
                </div>';
            }
            $html .= '</div>';
        }
        
        // Content sections
        foreach ($this->content as $item) {
            if ($item['type'] === 'section') {
                $html .= '
                <div class="section">
                    <div class="section-title">' . htmlspecialchars($item['title']) . '</div>
                    <div>' . $item['content'] . '</div>
                </div>';
            } elseif ($item['type'] === 'keyvalue') {
                $html .= '
                <div class="section">';
                if ($item['title']) {
                    $html .= '<div class="section-title">' . htmlspecialchars($item['title']) . '</div>';
                }
                $html .= '<div class="kv-grid">';
                foreach ($item['data'] as $key => $value) {
                    $html .= '
                    <div class="kv-item">
                        <span class="kv-label">' . htmlspecialchars($key) . '</span>
                        <span class="kv-value">' . htmlspecialchars($value) . '</span>
                    </div>';
                }
                $html .= '</div></div>';
            }
        }
        
        // Tables
        foreach ($this->tableData as $table) {
            $html .= '<div class="section">';
            if ($table['title']) {
                $html .= '<div class="section-title">' . htmlspecialchars($table['title']) . '</div>';
            }
            $html .= '<table><thead><tr>';
            foreach ($table['headers'] as $header) {
                $html .= '<th>' . htmlspecialchars($header) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($table['rows'] as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>' . $cell . '</td>'; // Allow HTML in cells for badges
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }
        
        $html .= '
    <div class="footer">
        <p>Sistema de Control de Procesos - MEGABLESSING</p>
        <p>Documento generado automáticamente · ' . $this->fechaGeneracion . '</p>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Envía el HTML como respuesta para impresión/PDF
     */
    public function output($filename = 'reporte.html') {
        $html = $this->generateHtml();
        
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        
        echo $html;
        exit;
    }
    
    /**
     * Descarga como archivo HTML
     */
    public function download($filename = 'reporte.html') {
        $html = $this->generateHtml();
        
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($html));
        
        echo $html;
        exit;
    }
    
    /**
     * Guarda el HTML en un archivo
     */
    public function save($filepath) {
        $html = $this->generateHtml();
        return file_put_contents($filepath, $html);
    }
    
    /**
     * Helper para crear badge HTML
     */
    public static function badge($text, $type = 'green') {
        return '<span class="badge badge-' . $type . '">' . htmlspecialchars($text) . '</span>';
    }
    
    /**
     * Helper para formatear número
     */
    public static function number($value, $decimals = 2) {
        return number_format($value, $decimals, ',', '.');
    }
    
    /**
     * Helper para formatear porcentaje
     */
    public static function percent($value, $decimals = 1) {
        return number_format($value, $decimals, ',', '.') . '%';
    }
}
