<?php
session_start();
require_once 'config.php';

// Consulta SQL para obtener los datos de objetivos diarios desde FAM450
$query = "SELECT * FROM [FAM450].[dbo].[ObjetivosDiario] ORDER BY codsuc, Fecha, Dia";

$result = sqlsrv_query($conn, $query);
$datos = [];
$sucursales = [];

if ($result !== false) {
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $codsuc = $row['codsuc'];
        if ($codsuc === null) {
            $codsuc = 'N/A';
        }
        if (!isset($sucursales[$codsuc])) {
            $sucursales[$codsuc] = [];
        }
        $sucursales[$codsuc][] = $row;
        $datos[] = $row;
    }
    sqlsrv_free_stmt($result);
} else {
    $errorSQL = sqlsrv_errors();
    if ($errorSQL) {
        error_log("Error en consulta SQL: " . print_r($errorSQL, true));
    }
    $datos = [];
}

// Función para formatear fecha (solo fecha, sin hora)
function formatearFecha($fecha) {
    if ($fecha === null) {
        return 'N/A';
    }
    if ($fecha instanceof DateTime) {
        return $fecha->format('Y-m-d');
    }
    if (is_string($fecha)) {
        $fechaSinHora = explode(' ', $fecha);
        return $fechaSinHora[0];
    }
    if (is_object($fecha)) {
        if (method_exists($fecha, 'format')) {
            return $fecha->format('Y-m-d');
        }
        if (isset($fecha->date)) {
            $fechaSinHora = explode(' ', $fecha->date);
            return $fechaSinHora[0];
        }
    }
    return 'N/A';
}

// Función para identificar tipo de sucursal desde la columna "comercio"
function getTipoSucursal($comercio) {
    /* 
     * REGLA DE NEGOCIO:
     * - Sucursales tipo "calle" o "local": NO abren domingos (se excluyen del promedio)
     * - Sucursales tipo "shopping": SÍ abren domingos (se incluyen en el promedio)
     * 
     * Esta función lee directamente de la columna "comercio" de la tabla
     */
    
    if ($comercio === null || $comercio === '') {
        return 'calle'; // Por defecto
    }
    
    $comercioLower = strtolower(trim(strval($comercio)));
    
    // Si contiene "shopping", "mall" o "centro", es shopping
    if (strpos($comercioLower, 'shopping') !== false || 
        strpos($comercioLower, 'mall') !== false || 
        strpos($comercioLower, 'centro') !== false) {
        return 'shopping';
    }
    
    // Si contiene "calle" o "local", es calle
    if (strpos($comercioLower, 'calle') !== false || 
        strpos($comercioLower, 'local') !== false) {
        return 'calle';
    }
    
    // Por defecto, asumir que es calle
    return 'calle';
}

// Función para obtener color de cumplimiento
function getCumplimientoColor($cumplimiento) {
    if ($cumplimiento === null) return '';
    $val = floatval($cumplimiento);
    if ($val < 70) return 'cumplimiento-bajo';
    if ($val < 100) return 'cumplimiento-medio';
    return 'cumplimiento-alto';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Objetivos Diarios - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <link rel="stylesheet" href="estilos.css" />
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            background: #0f1419;
            color: #e0e0e0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }
        .main-container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
        }
        .header-section {
            background: linear-gradient(135deg, #1a2332 0%, #2d3a4e 100%);
            padding: 25px 30px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            border-bottom: 3px solid #667eea;
        }
        .header-section h1 {
            color: #fff;
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
            letter-spacing: 0.5px;
        }
        .sucursal-selector {
            background: #1a2332;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #2d3a4e;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .sucursal-selector label {
            color: #fff;
            font-weight: 600;
            font-size: 0.9rem;
            margin: 0;
        }
        .sucursal-selector select {
            background: #0f1419;
            color: #fff;
            border: 2px solid #667eea;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.95rem;
            min-width: 180px;
            max-width: 220px;
        }
        .sucursal-selector select:focus {
            outline: none;
            border-color: #764ba2;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }
        .sucursal-section {
            display: none;
        }
        .sucursal-section.active {
            display: block;
        }
        .daily-indicators-section {
            background: linear-gradient(135deg, #1a2332 0%, #2d3a4e 100%);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            border: 1px solid #667eea;
        }
        .daily-indicators-title {
            color: #fff;
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 15px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .daily-indicators {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .daily-card {
            background: #1a2332;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            flex: 1;
            min-width: 280px;
            max-width: 350px;
            transition: transform 0.2s, box-shadow 0.2s;
            border: 2px solid transparent;
        }
        .daily-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.4);
        }
        .daily-card-header {
            padding: 12px 15px;
            color: #fff;
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .daily-card-header.calzado {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .daily-card-header.indumentaria {
            background: linear-gradient(135deg, #764ba2 0%, #f093fb 100%);
        }
        .daily-card-header.medias {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .daily-card-body {
            padding: 15px;
            background: #0f1419;
        }
        .daily-metric {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .daily-metric:last-child {
            border-bottom: none;
            padding-top: 12px;
        }
        .daily-metric-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.8rem;
            font-weight: 600;
        }
        .daily-metric-value {
            color: #fff;
            font-size: 1.1rem;
            font-weight: 700;
        }
        .daily-performance {
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: center;
            padding: 10px;
            border-radius: 6px;
            margin-top: 5px;
        }
        .daily-performance.above {
            background: rgba(56, 239, 125, 0.15);
            border: 1px solid rgba(56, 239, 125, 0.3);
        }
        .daily-performance.below {
            background: rgba(245, 87, 108, 0.15);
            border: 1px solid rgba(245, 87, 108, 0.3);
        }
        .daily-performance.exact {
            background: rgba(255, 193, 7, 0.15);
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        .daily-performance-value {
            font-size: 1.3rem;
            font-weight: 800;
        }
        .daily-performance-value.above {
            color: #38ef7d;
        }
        .daily-performance-value.below {
            color: #f5576c;
        }
        .daily-performance-value.exact {
            color: #ffc107;
        }
        .daily-performance-icon {
            font-size: 1.2rem;
        }
        .quick-analysis {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .quick-card {
            background: linear-gradient(135deg, #1a2332 0%, #2d3a4e 100%);
            border-radius: 8px;
            padding: 12px 18px;
            flex: 1;
            min-width: 140px;
            max-width: 200px;
            border: 1px solid rgba(102, 126, 234, 0.3);
            text-align: center;
            transition: all 0.2s;
        }
        .quick-card:hover {
            transform: translateY(-2px);
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
        }
        .quick-card-icon {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }
        .quick-card-label {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .quick-card-value {
            color: #fff;
            font-size: 1.1rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .quick-card-value.highlight {
            color: #38ef7d;
        }
        .quick-card-value.warning {
            color: #ffc107;
        }
        .quick-card-value.danger {
            color: #f5576c;
        }
        .quick-card-subtext {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.65rem;
            margin-top: 3px;
        }
        .comparison-badges {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .comp-badge {
            background: rgba(255, 255, 255, 0.05);
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .comp-badge.up {
            color: #38ef7d;
            border-color: rgba(56, 239, 125, 0.3);
            background: rgba(56, 239, 125, 0.1);
        }
        .comp-badge.down {
            color: #f5576c;
            border-color: rgba(245, 87, 108, 0.3);
            background: rgba(245, 87, 108, 0.1);
        }
        .comp-badge.neutral {
            color: #ffc107;
            border-color: rgba(255, 193, 7, 0.3);
            background: rgba(255, 193, 7, 0.1);
        }
        .trend-arrow {
            font-size: 0.8rem;
        }
        .objetivos-hoy-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 2px solid #764ba2;
            box-shadow: 0 4px 16px rgba(102, 126, 234, 0.3);
        }
        .objetivos-hoy-title {
            color: #fff;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
            text-align: center;
        }
        .objetivos-hoy-cards {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .objetivo-hoy-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 8px;
            padding: 12px 20px;
            flex: 1;
            min-width: 160px;
            max-width: 220px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            text-align: center;
            transition: all 0.2s;
        }
        .objetivo-hoy-card:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        .objetivo-hoy-icon {
            font-size: 1.3rem;
            margin-bottom: 5px;
        }
        .objetivo-hoy-label {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .objetivo-hoy-value {
            color: #fff;
            font-size: 1.4rem;
            font-weight: 800;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .kpi-section-title {
            color: #fff;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            margin-top: 20px;
            padding-left: 5px;
            border-left: 4px solid #667eea;
            padding-left: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .kpi-section-title.calzado {
            border-left-color: #667eea;
        }
        .kpi-section-title.indumentaria {
            border-left-color: #764ba2;
        }
        .kpi-section-title.medias {
            border-left-color: #4facfe;
        }
        .kpi-section-title span {
            font-size: 1.3rem;
        }
        .kpi-cards {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .kpi-card {
            flex: 1;
            min-width: 200px;
            background: #1a2332;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            border: 1px solid #2d3a4e;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.4);
        }
        .kpi-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }
        .kpi-icon.revenue {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .kpi-icon.orders {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .kpi-icon.atp {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .kpi-content {
            flex: 1;
        }
        .kpi-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .kpi-value {
            color: #fff;
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.2;
        }
        .chart-section {
            background: #1a2332;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .chart-section h3 {
            color: #fff;
            font-size: 1.2rem;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .chart-container {
            position: relative;
            height: 400px;
            background: #0f1419;
            border-radius: 6px;
            padding: 15px;
        }
        .table-wrapper {
            background: #1a2332;
            border-radius: 8px;
            padding: 0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .table-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px 25px;
            border-bottom: 2px solid #5568d3;
        }
        .table-header h2 {
            color: #fff;
            font-size: 1.4rem;
            font-weight: 600;
            margin: 0;
        }
        .table-container {
            overflow-x: auto;
            overflow-y: visible;
            max-height: calc(100vh - 300px);
        }
        .table {
            width: 100%;
            margin: 0;
            background: #1a2332;
            color: #fff;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background: linear-gradient(135deg, #2d3a4e 0%, #1a2332 100%);
        }
        .table thead th {
            color: #fff;
            font-weight: 600;
            padding: 15px 12px;
            text-align: left;
            border-bottom: 2px solid #667eea;
            white-space: nowrap;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table thead th.col-group {
            background: rgba(102, 126, 234, 0.2);
            text-align: center;
            font-size: 0.9rem;
            font-weight: 700;
        }
        .table tbody tr {
            border-bottom: 1px solid #2d3a4e;
            transition: background 0.2s ease;
        }
        .table tbody tr:nth-child(even) {
            background: rgba(45, 58, 78, 0.3);
        }
        .table tbody tr:hover {
            background: rgba(102, 126, 234, 0.15);
        }
        .table tbody td {
            padding: 14px 12px;
            color: #fff;
            border-right: 1px solid rgba(45, 58, 78, 0.5);
            vertical-align: middle;
        }
        .table tbody td:last-child {
            border-right: none;
        }
        .col-group-at1 {
            background: rgba(102, 126, 234, 0.1) !important;
            border-left: 3px solid #667eea;
            border-right: 3px solid #667eea;
        }
        .col-group-at2 {
            background: rgba(118, 75, 162, 0.1) !important;
            border-left: 3px solid #764ba2;
            border-right: 3px solid #764ba2;
        }
        .col-group-at3 {
            background: rgba(79, 172, 254, 0.1) !important;
            border-left: 3px solid #4facfe;
            border-right: 3px solid #4facfe;
        }
        .vta-diaria-cell {
            min-width: 150px;
        }
        .vta-diaria-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .vta-diaria-value {
            min-width: 60px;
            text-align: right;
            font-weight: 600;
        }
        .vta-diaria-bar-container {
            flex: 1;
            height: 24px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            min-width: 80px;
        }
        .vta-diaria-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #38ef7d 0%, #11998e 100%);
            border-radius: 12px;
            transition: width 0.4s ease;
            box-shadow: 0 2px 4px rgba(56, 239, 125, 0.3);
        }
        .cumplimiento-cell {
            font-weight: 600;
            text-align: center;
            padding: 10px;
            border-radius: 4px;
        }
        .cumplimiento-bajo {
            background: rgba(245, 87, 108, 0.2);
            color: #f5576c;
        }
        .cumplimiento-medio {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        .cumplimiento-alto {
            background: rgba(56, 239, 125, 0.2);
            color: #38ef7d;
        }
        .btn-back {
            background: #667eea;
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }
        .btn-back:hover {
            background: #5568d3;
            color: white;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        @media (max-width: 768px) {
            .table-container {
                overflow-x: scroll;
            }
            .table {
                min-width: 1200px;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <a href="login.php" class="btn-back">← Volver al Login</a>
        
        <!-- Selector de Sucursal -->
        <div class="sucursal-selector">
            <label for="selectSucursal">Seleccionar Sucursal:</label>
            <select id="selectSucursal" class="form-select">
                <?php 
                $firstSucursal = true;
                foreach ($sucursales as $codsuc => $datosSucursal): 
                ?>
                    <option value="<?php echo htmlspecialchars($codsuc); ?>" <?php echo $firstSucursal ? 'selected' : ''; ?>>
                        Sucursal <?php echo htmlspecialchars($codsuc); ?>
                    </option>
                <?php 
                $firstSucursal = false;
                endforeach; 
                ?>
            </select>
        </div>

        <!-- Secciones por Sucursal -->
        <?php if (empty($sucursales)): ?>
            <div class="alert alert-warning" style="background: #1a2332; border: 1px solid #667eea; color: #e0e0e0; padding: 20px; border-radius: 8px;">
                <h4>No hay datos disponibles</h4>
                <p>Por favor, verifica que la tabla ObjetivosDiario exista en la base de datos FAM450 y que contenga datos.</p>
                <?php if ($result === false): ?>
                    <p><strong>Error SQL:</strong> <?php echo print_r(sqlsrv_errors(), true); ?></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php 
            // ===== CÁLCULOS GLOBALES PARA RANKING =====
            $rankingSucursales = [];
            foreach ($sucursales as $codSuc => $datosSuc) {
                $totalObjetivo = 0;
                $totalVenta = 0;
                foreach ($datosSuc as $fila) {
                    if ($fila['VtaDiariaAt1'] !== null && floatval($fila['VtaDiariaAt1']) > 0) {
                        $totalObjetivo += floatval($fila['ObjDiarioAt1'] ?? 0);
                        $totalVenta += floatval($fila['VtaDiariaAt1'] ?? 0);
                    }
                }
                $cumplimiento = $totalObjetivo > 0 ? (($totalVenta / $totalObjetivo) * 100) : 0;
                $rankingSucursales[$codSuc] = $cumplimiento;
            }
            arsort($rankingSucursales);
            $posicionRanking = array_keys($rankingSucursales);
            ?>
            
            <?php foreach ($sucursales as $codsuc => $datosSucursal): ?>
                <?php
                // ===== CÁLCULOS ESPECÍFICOS DE ESTA SUCURSAL =====
                
                // 1. Análisis por día de la semana
                // Obtener el tipo de comercio de la primera fila (es el mismo para toda la sucursal)
                $comercio = isset($datosSucursal[0]['comercio']) ? $datosSucursal[0]['comercio'] : '';
                $tipoSucursal = getTipoSucursal($comercio);
                
                $ventasPorDia = [];
                $objetivosPorDia = [];
                $fechasPorDia = []; // Para guardar las fechas de cada día
                
                foreach ($datosSucursal as $fila) {
                    $dia = $fila['DiaDeSemana'] ?? 'N/A';
                    
                    // Excluir domingos si es sucursal tipo "calle"
                    if ($tipoSucursal === 'calle' && strtolower($dia) === 'domingo') {
                        continue;
                    }
                    
                    if ($fila['VtaDiariaAt1'] !== null && floatval($fila['VtaDiariaAt1']) > 0) {
                        if (!isset($ventasPorDia[$dia])) {
                            $ventasPorDia[$dia] = [];
                            $objetivosPorDia[$dia] = [];
                            $fechasPorDia[$dia] = [];
                        }
                        $ventasPorDia[$dia][] = floatval($fila['VtaDiariaAt1'] ?? 0);
                        $objetivosPorDia[$dia][] = floatval($fila['ObjDiarioAt1'] ?? 0);
                        $fechasPorDia[$dia][] = $fila['Fecha'];
                    }
                }
                
                $promediosPorDia = [];
                foreach ($ventasPorDia as $dia => $ventas) {
                    $promVenta = array_sum($ventas) / count($ventas);
                    $promObj = array_sum($objetivosPorDia[$dia]) / count($objetivosPorDia[$dia]);
                    $cumplimiento = $promObj > 0 ? (($promVenta / $promObj) * 100) : 0;
                    $promediosPorDia[$dia] = $cumplimiento;
                }
                
                arsort($promediosPorDia);
                $mejorDia = !empty($promediosPorDia) ? array_key_first($promediosPorDia) : 'N/A';
                $peorDia = !empty($promediosPorDia) ? array_key_last($promediosPorDia) : 'N/A';
                
                // Obtener la última fecha de cada día para mostrar
                $fechaMejorDia = isset($fechasPorDia[$mejorDia]) && !empty($fechasPorDia[$mejorDia]) 
                    ? end($fechasPorDia[$mejorDia]) : null;
                $fechaPeorDia = isset($fechasPorDia[$peorDia]) && !empty($fechasPorDia[$peorDia]) 
                    ? end($fechasPorDia[$peorDia]) : null;
                
                // 2. Ranking de esta sucursal
                $miPosicion = array_search($codsuc, $posicionRanking) + 1;
                $totalSucursales = count($rankingSucursales);
                $miCumplimiento = $rankingSucursales[$codsuc];
                
                // 3. Tendencia (últimos 3 días con venta vs 3 días anteriores)
                $diasConVenta = array_filter($datosSucursal, function($f) {
                    return $f['VtaDiariaAt1'] !== null && floatval($f['VtaDiariaAt1']) > 0;
                });
                $diasConVenta = array_values($diasConVenta);
                $totalDias = count($diasConVenta);
                
                $tendencia = '→';
                $tendenciaValor = 0;
                $tendenciaClass = 'neutral';
                if ($totalDias >= 6) {
                    $ultimos3Obj = 0; $ultimos3Vta = 0;
                    $anteriores3Obj = 0; $anteriores3Vta = 0;
                    
                    for ($i = $totalDias - 3; $i < $totalDias; $i++) {
                        $ultimos3Obj += floatval($diasConVenta[$i]['ObjDiarioAt1'] ?? 0);
                        $ultimos3Vta += floatval($diasConVenta[$i]['VtaDiariaAt1'] ?? 0);
                    }
                    for ($i = $totalDias - 6; $i < $totalDias - 3; $i++) {
                        $anteriores3Obj += floatval($diasConVenta[$i]['ObjDiarioAt1'] ?? 0);
                        $anteriores3Vta += floatval($diasConVenta[$i]['VtaDiariaAt1'] ?? 0);
                    }
                    
                    $cumplUltimos = $ultimos3Obj > 0 ? ($ultimos3Vta / $ultimos3Obj * 100) : 0;
                    $cumplAnteriores = $anteriores3Obj > 0 ? ($anteriores3Vta / $anteriores3Obj * 100) : 0;
                    $tendenciaValor = $cumplUltimos - $cumplAnteriores;
                    
                    if ($tendenciaValor > 2) {
                        $tendencia = '📈';
                        $tendenciaClass = 'up';
                    } elseif ($tendenciaValor < -2) {
                        $tendencia = '📉';
                        $tendenciaClass = 'down';
                    }
                }
                
                // 4. Comparativas temporales (vs día anterior y vs semana anterior)
                // Calcular para cada atributo por separado
                $totalDiasData = count($diasConVenta);
                
                // At1 - Calzado
                $compVsAyerAt1 = null;
                $compVsSemanaAt1 = null;
                
                if ($totalDiasData >= 2) {
                    $diaActualIdx = $totalDiasData - 1;
                    $diaAnteriorIdx = $totalDiasData - 2;
                    
                    $objHoy = floatval($diasConVenta[$diaActualIdx]['ObjDiarioAt1'] ?? 0);
                    $vtaHoy = floatval($diasConVenta[$diaActualIdx]['VtaDiariaAt1'] ?? 0);
                    $cumplHoy = $objHoy > 0 ? ($vtaHoy / $objHoy * 100) : 0;
                    
                    $objAyer = floatval($diasConVenta[$diaAnteriorIdx]['ObjDiarioAt1'] ?? 0);
                    $vtaAyer = floatval($diasConVenta[$diaAnteriorIdx]['VtaDiariaAt1'] ?? 0);
                    $cumplAyer = $objAyer > 0 ? ($vtaAyer / $objAyer * 100) : 0;
                    
                    $compVsAyerAt1 = $cumplHoy - $cumplAyer;
                }
                
                if ($totalDiasData >= 8) {
                    $diaActualIdx = $totalDiasData - 1;
                    $diaSemanaIdx = $totalDiasData - 8;
                    
                    $objHoy = floatval($diasConVenta[$diaActualIdx]['ObjDiarioAt1'] ?? 0);
                    $vtaHoy = floatval($diasConVenta[$diaActualIdx]['VtaDiariaAt1'] ?? 0);
                    $cumplHoy = $objHoy > 0 ? ($vtaHoy / $objHoy * 100) : 0;
                    
                    $objSemana = floatval($diasConVenta[$diaSemanaIdx]['ObjDiarioAt1'] ?? 0);
                    $vtaSemana = floatval($diasConVenta[$diaSemanaIdx]['VtaDiariaAt1'] ?? 0);
                    $cumplSemana = $objSemana > 0 ? ($vtaSemana / $objSemana * 100) : 0;
                    
                    $compVsSemanaAt1 = $cumplHoy - $cumplSemana;
                }
                
                // At2 - Indumentaria
                $compVsAyerAt2 = null;
                $compVsSemanaAt2 = null;
                
                if ($totalDiasData >= 2) {
                    $diaActualIdx = $totalDiasData - 1;
                    $diaAnteriorIdx = $totalDiasData - 2;
                    
                    $objHoy = floatval($diasConVenta[$diaActualIdx]['ObjDiarioAt2'] ?? 0);
                    $vtaHoy = floatval($diasConVenta[$diaActualIdx]['VtaDiariaAt2'] ?? 0);
                    $cumplHoy = $objHoy > 0 ? ($vtaHoy / $objHoy * 100) : 0;
                    
                    $objAyer = floatval($diasConVenta[$diaAnteriorIdx]['ObjDiarioAt2'] ?? 0);
                    $vtaAyer = floatval($diasConVenta[$diaAnteriorIdx]['VtaDiariaAt2'] ?? 0);
                    $cumplAyer = $objAyer > 0 ? ($vtaAyer / $objAyer * 100) : 0;
                    
                    $compVsAyerAt2 = $cumplHoy - $cumplAyer;
                }
                
                if ($totalDiasData >= 8) {
                    $diaActualIdx = $totalDiasData - 1;
                    $diaSemanaIdx = $totalDiasData - 8;
                    
                    $objHoy = floatval($diasConVenta[$diaActualIdx]['ObjDiarioAt2'] ?? 0);
                    $vtaHoy = floatval($diasConVenta[$diaActualIdx]['VtaDiariaAt2'] ?? 0);
                    $cumplHoy = $objHoy > 0 ? ($vtaHoy / $objHoy * 100) : 0;
                    
                    $objSemana = floatval($diasConVenta[$diaSemanaIdx]['ObjDiarioAt2'] ?? 0);
                    $vtaSemana = floatval($diasConVenta[$diaSemanaIdx]['VtaDiariaAt2'] ?? 0);
                    $cumplSemana = $objSemana > 0 ? ($vtaSemana / $objSemana * 100) : 0;
                    
                    $compVsSemanaAt2 = $cumplHoy - $cumplSemana;
                }
                
                // At3 - Medias
                $compVsAyerAt3 = null;
                $compVsSemanaAt3 = null;
                
                if ($totalDiasData >= 2) {
                    $diaActualIdx = $totalDiasData - 1;
                    $diaAnteriorIdx = $totalDiasData - 2;
                    
                    $objHoy = floatval($diasConVenta[$diaActualIdx]['ObjDiarioAt3'] ?? 0);
                    $vtaHoy = floatval($diasConVenta[$diaActualIdx]['VtaDiariaAt3'] ?? 0);
                    $cumplHoy = $objHoy > 0 ? ($vtaHoy / $objHoy * 100) : 0;
                    
                    $objAyer = floatval($diasConVenta[$diaAnteriorIdx]['ObjDiarioAt3'] ?? 0);
                    $vtaAyer = floatval($diasConVenta[$diaAnteriorIdx]['VtaDiariaAt3'] ?? 0);
                    $cumplAyer = $objAyer > 0 ? ($vtaAyer / $objAyer * 100) : 0;
                    
                    $compVsAyerAt3 = $cumplHoy - $cumplAyer;
                }
                
                if ($totalDiasData >= 8) {
                    $diaActualIdx = $totalDiasData - 1;
                    $diaSemanaIdx = $totalDiasData - 8;
                    
                    $objHoy = floatval($diasConVenta[$diaActualIdx]['ObjDiarioAt3'] ?? 0);
                    $vtaHoy = floatval($diasConVenta[$diaActualIdx]['VtaDiariaAt3'] ?? 0);
                    $cumplHoy = $objHoy > 0 ? ($vtaHoy / $objHoy * 100) : 0;
                    
                    $objSemana = floatval($diasConVenta[$diaSemanaIdx]['ObjDiarioAt3'] ?? 0);
                    $vtaSemana = floatval($diasConVenta[$diaSemanaIdx]['VtaDiariaAt3'] ?? 0);
                    $cumplSemana = $objSemana > 0 ? ($vtaSemana / $objSemana * 100) : 0;
                    
                    $compVsSemanaAt3 = $cumplHoy - $cumplSemana;
                }
                ?>
                
                <div class="sucursal-section" data-sucursal="<?php echo htmlspecialchars($codsuc); ?>">
                    <!-- Header -->
                    <div class="header-section">
                        <h1>Detalle por Día – Sucursal <?php echo htmlspecialchars($codsuc); ?></h1>
                    </div>
                    
                    <!-- Objetivos de Hoy -->
                    <?php
                    // Buscar los datos del día actual (fecha de hoy)
                    $fechaHoy = date('Y-m-d');
                    $datosHoy = null;
                    
                    foreach ($datosSucursal as $fila) {
                        $fechaFila = formatearFecha($fila['Fecha']);
                        if ($fechaFila === $fechaHoy) {
                            $datosHoy = $fila;
                            break;
                        }
                    }
                    
                    // Si no hay datos de hoy, buscar el día más reciente en el futuro (próximo día con objetivo)
                    if ($datosHoy === null) {
                        foreach ($datosSucursal as $fila) {
                            $fechaFila = formatearFecha($fila['Fecha']);
                            if (strtotime($fechaFila) >= strtotime($fechaHoy)) {
                                $datosHoy = $fila;
                                break;
                            }
                        }
                    }
                    
                    if ($datosHoy !== null):
                        $objHoyAt1 = floatval($datosHoy['ObjDiarioAt1'] ?? 0);
                        $objHoyAt2 = floatval($datosHoy['ObjDiarioAt2'] ?? 0);
                        $objHoyAt3 = floatval($datosHoy['ObjDiarioAt3'] ?? 0);
                        $fechaMostrar = formatearFecha($datosHoy['Fecha']);
                    ?>
                    <div class="objetivos-hoy-section">
                        <div class="objetivos-hoy-title">🎯 OBJETIVOS DE HOY - <?php echo $fechaMostrar; ?></div>
                        <div class="objetivos-hoy-cards">
                            <div class="objetivo-hoy-card">
                                <div class="objetivo-hoy-icon">👟</div>
                                <div class="objetivo-hoy-label">Calzado</div>
                                <div class="objetivo-hoy-value"><?php echo number_format($objHoyAt1, 0); ?></div>
                            </div>
                            <div class="objetivo-hoy-card">
                                <div class="objetivo-hoy-icon">👕</div>
                                <div class="objetivo-hoy-label">Indumentaria</div>
                                <div class="objetivo-hoy-value"><?php echo number_format($objHoyAt2, 0); ?></div>
                            </div>
                            <div class="objetivo-hoy-card">
                                <div class="objetivo-hoy-icon">🧦</div>
                                <div class="objetivo-hoy-label">Medias</div>
                                <div class="objetivo-hoy-value"><?php echo number_format($objHoyAt3, 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Análisis Rápido -->
                    <div class="quick-analysis">
                        <div class="quick-card">
                            <div class="quick-card-icon">🏆</div>
                            <div class="quick-card-label">Tu Posición</div>
                            <div class="quick-card-value <?php echo $miPosicion <= 3 ? 'highlight' : ($miPosicion > $totalSucursales / 2 ? 'danger' : 'warning'); ?>">
                                <?php echo $miPosicion; ?>° de <?php echo $totalSucursales; ?>
                            </div>
                            <div class="quick-card-subtext"><?php echo number_format($miCumplimiento, 1); ?>% cumplimiento</div>
                        </div>
                        
                        <div class="quick-card">
                            <div class="quick-card-icon">⭐</div>
                            <div class="quick-card-label">Mejor Día</div>
                            <div class="quick-card-value highlight">
                                <?php echo $fechaMejorDia ? formatearFecha($fechaMejorDia) : 'N/A'; ?>
                            </div>
                            <div class="quick-card-subtext">
                                <?php echo isset($promediosPorDia[$mejorDia]) ? number_format($promediosPorDia[$mejorDia], 0) . '% prom' : 'N/A'; ?>
                            </div>
                        </div>
                        
                        <div class="quick-card">
                            <div class="quick-card-icon">⚠️</div>
                            <div class="quick-card-label">Peor Día</div>
                            <div class="quick-card-value danger">
                                <?php echo $fechaPeorDia ? formatearFecha($fechaPeorDia) : 'N/A'; ?>
                            </div>
                            <div class="quick-card-subtext">
                                <?php echo isset($promediosPorDia[$peorDia]) ? number_format($promediosPorDia[$peorDia], 0) . '% prom' : 'N/A'; ?>
                            </div>
                        </div>
                        
                        <div class="quick-card">
                            <div class="quick-card-icon"><?php echo $tendencia; ?></div>
                            <div class="quick-card-label">Tendencia</div>
                            <div class="quick-card-value <?php echo $tendenciaClass === 'up' ? 'highlight' : ($tendenciaClass === 'down' ? 'danger' : 'warning'); ?>">
                                <?php 
                                if ($tendenciaClass === 'up') echo 'Mejorando';
                                elseif ($tendenciaClass === 'down') echo 'Bajando';
                                else echo 'Estable';
                                ?>
                            </div>
                            <div class="quick-card-subtext">
                                <?php echo $tendenciaValor > 0 ? '+' : ''; ?><?php echo number_format($tendenciaValor, 1); ?>% vs ant.
                            </div>
                        </div>
                    </div>

                    <!-- Indicadores del Día Actual -->
                    <?php
                    // Obtener datos del día actual (último día con venta registrada)
                    $diaActual = null;
                    foreach (array_reverse($datosSucursal) as $fila) {
                        if ($fila['VtaDiariaAt1'] !== null && floatval($fila['VtaDiariaAt1']) > 0) {
                            $diaActual = $fila;
                            break;
                        }
                    }
                    
                    if ($diaActual !== null):
                    ?>
                    <div class="daily-indicators-section">
                        <h2 class="daily-indicators-title">📅 Rendimiento del Día: <?php echo formatearFecha($diaActual['Fecha']); ?></h2>
                        <div class="daily-indicators">
                            <!-- Calzado -->
                            <?php
                            $objDiaAt1 = floatval($diaActual['ObjDiarioAt1'] ?? 0);
                            $vtaDiaAt1 = floatval($diaActual['VtaDiariaAt1'] ?? 0);
                            $difAt1 = $vtaDiaAt1 - $objDiaAt1;
                            $porcCumplimientoAt1 = $objDiaAt1 > 0 ? (($vtaDiaAt1 / $objDiaAt1) * 100) : 0;
                            $variacionAt1 = $porcCumplimientoAt1 - 100; // Diferencia respecto al 100%
                            $statusAt1 = $variacionAt1 > 0 ? 'above' : ($variacionAt1 < 0 ? 'below' : 'exact');
                            ?>
                            <div class="daily-card">
                                <div class="daily-card-header calzado">
                                    <span>👟</span> CALZADO
                                </div>
                                <div class="daily-card-body">
                                    <div class="daily-metric">
                                        <span class="daily-metric-label">Objetivo del Día</span>
                                        <span class="daily-metric-value"><?php echo number_format($objDiaAt1, 0); ?></span>
                                    </div>
                                    <div class="daily-metric">
                                        <span class="daily-metric-label">Venta del Día</span>
                                        <span class="daily-metric-value"><?php echo number_format($vtaDiaAt1, 0); ?></span>
                                    </div>
                                    <div class="daily-metric">
                                        <div class="daily-performance <?php echo $statusAt1; ?>">
                                            <span class="daily-performance-icon"><?php echo $statusAt1 === 'above' ? '📈' : ($statusAt1 === 'below' ? '📉' : '➡️'); ?></span>
                                            <span class="daily-performance-value <?php echo $statusAt1; ?>"><?php echo $variacionAt1 >= 0 ? '+' : ''; ?><?php echo number_format($variacionAt1, 1); ?>%</span>
                                            <span class="daily-metric-label">(<?php echo $difAt1 >= 0 ? '+' : ''; ?><?php echo number_format($difAt1, 0); ?>)</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="comparison-badges">
                                    <?php if ($compVsAyerAt1 !== null): ?>
                                        <div class="comp-badge <?php echo $compVsAyerAt1 > 0 ? 'up' : ($compVsAyerAt1 < 0 ? 'down' : 'neutral'); ?>">
                                            <span class="trend-arrow"><?php echo $compVsAyerAt1 > 0 ? '↗' : ($compVsAyerAt1 < 0 ? '↘' : '→'); ?></span>
                                            <span>vs Ayer: <?php echo $compVsAyerAt1 >= 0 ? '+' : ''; ?><?php echo number_format($compVsAyerAt1, 1); ?>%</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($compVsSemanaAt1 !== null): ?>
                                        <div class="comp-badge <?php echo $compVsSemanaAt1 > 0 ? 'up' : ($compVsSemanaAt1 < 0 ? 'down' : 'neutral'); ?>">
                                            <span class="trend-arrow"><?php echo $compVsSemanaAt1 > 0 ? '↗' : ($compVsSemanaAt1 < 0 ? '↘' : '→'); ?></span>
                                            <span>vs Sem.Ant.: <?php echo $compVsSemanaAt1 >= 0 ? '+' : ''; ?><?php echo number_format($compVsSemanaAt1, 1); ?>%</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Indumentaria -->
                            <?php
                            $objDiaAt2 = floatval($diaActual['ObjDiarioAt2'] ?? 0);
                            $vtaDiaAt2 = floatval($diaActual['VtaDiariaAt2'] ?? 0);
                            $difAt2 = $vtaDiaAt2 - $objDiaAt2;
                            $porcCumplimientoAt2 = $objDiaAt2 > 0 ? (($vtaDiaAt2 / $objDiaAt2) * 100) : 0;
                            $variacionAt2 = $porcCumplimientoAt2 - 100; // Diferencia respecto al 100%
                            $statusAt2 = $variacionAt2 > 0 ? 'above' : ($variacionAt2 < 0 ? 'below' : 'exact');
                            ?>
                            <div class="daily-card">
                                <div class="daily-card-header indumentaria">
                                    <span>👕</span> INDUMENTARIA
                                </div>
                                <div class="daily-card-body">
                                    <div class="daily-metric">
                                        <span class="daily-metric-label">Objetivo del Día</span>
                                        <span class="daily-metric-value"><?php echo number_format($objDiaAt2, 0); ?></span>
                                    </div>
                                    <div class="daily-metric">
                                        <span class="daily-metric-label">Venta del Día</span>
                                        <span class="daily-metric-value"><?php echo number_format($vtaDiaAt2, 0); ?></span>
                                    </div>
                                    <div class="daily-metric">
                                        <div class="daily-performance <?php echo $statusAt2; ?>">
                                            <span class="daily-performance-icon"><?php echo $statusAt2 === 'above' ? '📈' : ($statusAt2 === 'below' ? '📉' : '➡️'); ?></span>
                                            <span class="daily-performance-value <?php echo $statusAt2; ?>"><?php echo $variacionAt2 >= 0 ? '+' : ''; ?><?php echo number_format($variacionAt2, 1); ?>%</span>
                                            <span class="daily-metric-label">(<?php echo $difAt2 >= 0 ? '+' : ''; ?><?php echo number_format($difAt2, 0); ?>)</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="comparison-badges">
                                    <?php if ($compVsAyerAt2 !== null): ?>
                                        <div class="comp-badge <?php echo $compVsAyerAt2 > 0 ? 'up' : ($compVsAyerAt2 < 0 ? 'down' : 'neutral'); ?>">
                                            <span class="trend-arrow"><?php echo $compVsAyerAt2 > 0 ? '↗' : ($compVsAyerAt2 < 0 ? '↘' : '→'); ?></span>
                                            <span>vs Ayer: <?php echo $compVsAyerAt2 >= 0 ? '+' : ''; ?><?php echo number_format($compVsAyerAt2, 1); ?>%</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($compVsSemanaAt2 !== null): ?>
                                        <div class="comp-badge <?php echo $compVsSemanaAt2 > 0 ? 'up' : ($compVsSemanaAt2 < 0 ? 'down' : 'neutral'); ?>">
                                            <span class="trend-arrow"><?php echo $compVsSemanaAt2 > 0 ? '↗' : ($compVsSemanaAt2 < 0 ? '↘' : '→'); ?></span>
                                            <span>vs Sem.Ant.: <?php echo $compVsSemanaAt2 >= 0 ? '+' : ''; ?><?php echo number_format($compVsSemanaAt2, 1); ?>%</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Medias -->
                            <?php
                            $objDiaAt3 = floatval($diaActual['ObjDiarioAt3'] ?? 0);
                            $vtaDiaAt3 = floatval($diaActual['VtaDiariaAt3'] ?? 0);
                            $difAt3 = $vtaDiaAt3 - $objDiaAt3;
                            $porcCumplimientoAt3 = $objDiaAt3 > 0 ? (($vtaDiaAt3 / $objDiaAt3) * 100) : 0;
                            $variacionAt3 = $porcCumplimientoAt3 - 100; // Diferencia respecto al 100%
                            $statusAt3 = $variacionAt3 > 0 ? 'above' : ($variacionAt3 < 0 ? 'below' : 'exact');
                            ?>
                            <div class="daily-card">
                                <div class="daily-card-header medias">
                                    <span>🧦</span> MEDIAS
                                </div>
                                <div class="daily-card-body">
                                    <div class="daily-metric">
                                        <span class="daily-metric-label">Objetivo del Día</span>
                                        <span class="daily-metric-value"><?php echo number_format($objDiaAt3, 0); ?></span>
                                    </div>
                                    <div class="daily-metric">
                                        <span class="daily-metric-label">Venta del Día</span>
                                        <span class="daily-metric-value"><?php echo number_format($vtaDiaAt3, 0); ?></span>
                                    </div>
                                    <div class="daily-metric">
                                        <div class="daily-performance <?php echo $statusAt3; ?>">
                                            <span class="daily-performance-icon"><?php echo $statusAt3 === 'above' ? '📈' : ($statusAt3 === 'below' ? '📉' : '➡️'); ?></span>
                                            <span class="daily-performance-value <?php echo $statusAt3; ?>"><?php echo $variacionAt3 >= 0 ? '+' : ''; ?><?php echo number_format($variacionAt3, 1); ?>%</span>
                                            <span class="daily-metric-label">(<?php echo $difAt3 >= 0 ? '+' : ''; ?><?php echo number_format($difAt3, 0); ?>)</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="comparison-badges">
                                    <?php if ($compVsAyerAt3 !== null): ?>
                                        <div class="comp-badge <?php echo $compVsAyerAt3 > 0 ? 'up' : ($compVsAyerAt3 < 0 ? 'down' : 'neutral'); ?>">
                                            <span class="trend-arrow"><?php echo $compVsAyerAt3 > 0 ? '↗' : ($compVsAyerAt3 < 0 ? '↘' : '→'); ?></span>
                                            <span>vs Ayer: <?php echo $compVsAyerAt3 >= 0 ? '+' : ''; ?><?php echo number_format($compVsAyerAt3, 1); ?>%</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($compVsSemanaAt3 !== null): ?>
                                        <div class="comp-badge <?php echo $compVsSemanaAt3 > 0 ? 'up' : ($compVsSemanaAt3 < 0 ? 'down' : 'neutral'); ?>">
                                            <span class="trend-arrow"><?php echo $compVsSemanaAt3 > 0 ? '↗' : ($compVsSemanaAt3 < 0 ? '↘' : '→'); ?></span>
                                            <span>vs Sem.Ant.: <?php echo $compVsSemanaAt3 >= 0 ? '+' : ''; ?><?php echo number_format($compVsSemanaAt3, 1); ?>%</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Tarjetas KPI At1 (Calzado) -->
                    <h3 class="kpi-section-title calzado"><span>👟</span> CALZADO</h3>
                    <?php
                    // Calcular métricas de At1 basadas en los valores de la tabla
                    $objetivoTotalHastaDia = 0;
                    $objetivoTotalAcumulado = 0;
                    $ultimoIndiceConVenta = -1;
                    
                    // Buscar el índice del último día con Vta. Diaria At1 no null
                    foreach ($datosSucursal as $indice => $fila) {
                        if ($fila['VtaDiariaAt1'] !== null && floatval($fila['VtaDiariaAt1']) > 0) {
                            $ultimoIndiceConVenta = $indice;
                        }
                    }
                    
                    // Si hay al menos un día con venta
                    if ($ultimoIndiceConVenta >= 0) {
                        // Calcular objetivo total hasta el día: suma de ObjDiarioAt1 hasta el último día con venta (incluyéndolo)
                        for ($i = 0; $i <= $ultimoIndiceConVenta; $i++) {
                            if (isset($datosSucursal[$i]) && $datosSucursal[$i]['ObjDiarioAt1'] !== null) {
                                $objetivoTotalHastaDia += floatval($datosSucursal[$i]['ObjDiarioAt1']);
                            }
                        }
                        
                        // Objetivo total acumulado: Acum. Vta At1 del último día con venta diaria
                        $ultimoDiaConVenta = $datosSucursal[$ultimoIndiceConVenta];
                        if ($ultimoDiaConVenta['AcumVtaAt1'] !== null) {
                            $objetivoTotalAcumulado = floatval($ultimoDiaConVenta['AcumVtaAt1']);
                        }
                    }
                    
                    // Porcentaje de cumplimiento: (Acum. Vta / Objetivo Total hasta el día) * 100
                    $cumplimientoPorcentaje = 0;
                    if ($objetivoTotalHastaDia > 0 && $objetivoTotalAcumulado > 0) {
                        $cumplimientoPorcentaje = ($objetivoTotalAcumulado / $objetivoTotalHastaDia) * 100;
                    }
                    ?>
                    <div class="kpi-cards">
                        <div class="kpi-card">
                            <div class="kpi-icon revenue">
                                <span>📊</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">Objetivo Total hasta el Día</div>
                                <div class="kpi-value" id="kpiObjetivoHasta_<?php echo $codsuc; ?>"><?php echo number_format($objetivoTotalHastaDia, 0); ?></div>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-icon orders">
                                <span>🎯</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">Objetivo Total Acumulado</div>
                                <div class="kpi-value" id="kpiObjetivoAcum_<?php echo $codsuc; ?>"><?php echo number_format($objetivoTotalAcumulado, 0); ?></div>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-icon atp">
                                <span>✓</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">% Cumplimiento Total</div>
                                <div class="kpi-value" id="kpiCumplimiento_<?php echo $codsuc; ?>"><?php echo number_format($cumplimientoPorcentaje, 2); ?>%</div>
                            </div>
                        </div>
                    </div>

                    <!-- Tarjetas KPI At2 (Indumentaria) -->
                    <h3 class="kpi-section-title indumentaria"><span>👕</span> INDUMENTARIA</h3>
                    <?php
                    // Calcular métricas de At2 basadas en los valores de la tabla
                    $objetivoTotalHastaDiaAt2 = 0;
                    $objetivoTotalAcumuladoAt2 = 0;
                    $ultimoIndiceConVentaAt2 = -1;
                    
                    // Buscar el índice del último día con Vta. Diaria At2 no null
                    foreach ($datosSucursal as $indice => $fila) {
                        if ($fila['VtaDiariaAt2'] !== null && floatval($fila['VtaDiariaAt2']) > 0) {
                            $ultimoIndiceConVentaAt2 = $indice;
                        }
                    }
                    
                    // Si hay al menos un día con venta
                    if ($ultimoIndiceConVentaAt2 >= 0) {
                        // Calcular objetivo total hasta el día: suma de ObjDiarioAt2 hasta el último día con venta (incluyéndolo)
                        for ($i = 0; $i <= $ultimoIndiceConVentaAt2; $i++) {
                            if (isset($datosSucursal[$i]) && $datosSucursal[$i]['ObjDiarioAt2'] !== null) {
                                $objetivoTotalHastaDiaAt2 += floatval($datosSucursal[$i]['ObjDiarioAt2']);
                            }
                        }
                        
                        // Objetivo total acumulado: Acum. Vta At2 del último día con venta diaria
                        $ultimoDiaConVentaAt2 = $datosSucursal[$ultimoIndiceConVentaAt2];
                        if ($ultimoDiaConVentaAt2['AcumVtaAt2'] !== null) {
                            $objetivoTotalAcumuladoAt2 = floatval($ultimoDiaConVentaAt2['AcumVtaAt2']);
                        }
                    }
                    
                    // Porcentaje de cumplimiento: (Acum. Vta / Objetivo Total hasta el día) * 100
                    $cumplimientoPorcentajeAt2 = 0;
                    if ($objetivoTotalHastaDiaAt2 > 0 && $objetivoTotalAcumuladoAt2 > 0) {
                        $cumplimientoPorcentajeAt2 = ($objetivoTotalAcumuladoAt2 / $objetivoTotalHastaDiaAt2) * 100;
                    }
                    ?>
                    <div class="kpi-cards">
                        <div class="kpi-card">
                            <div class="kpi-icon revenue">
                                <span>📊</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">Objetivo Total hasta el Día</div>
                                <div class="kpi-value" id="kpiObjetivoHastaAt2_<?php echo $codsuc; ?>"><?php echo number_format($objetivoTotalHastaDiaAt2, 0); ?></div>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-icon orders">
                                <span>🎯</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">Objetivo Total Acumulado</div>
                                <div class="kpi-value" id="kpiObjetivoAcumAt2_<?php echo $codsuc; ?>"><?php echo number_format($objetivoTotalAcumuladoAt2, 0); ?></div>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-icon atp">
                                <span>✓</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">% Cumplimiento Total</div>
                                <div class="kpi-value" id="kpiCumplimientoAt2_<?php echo $codsuc; ?>"><?php echo number_format($cumplimientoPorcentajeAt2, 2); ?>%</div>
                            </div>
                        </div>
                    </div>

                    <!-- Tarjetas KPI At3 (Medias) -->
                    <h3 class="kpi-section-title medias"><span>🧦</span> MEDIAS</h3>
                    <?php
                    // Calcular métricas de At3 basadas en los valores de la tabla
                    $objetivoTotalHastaDiaAt3 = 0;
                    $objetivoTotalAcumuladoAt3 = 0;
                    $ultimoIndiceConVentaAt3 = -1;
                    
                    // Buscar el índice del último día con Vta. Diaria At3 no null
                    foreach ($datosSucursal as $indice => $fila) {
                        if ($fila['VtaDiariaAt3'] !== null && floatval($fila['VtaDiariaAt3']) > 0) {
                            $ultimoIndiceConVentaAt3 = $indice;
                        }
                    }
                    
                    // Si hay al menos un día con venta
                    if ($ultimoIndiceConVentaAt3 >= 0) {
                        // Calcular objetivo total hasta el día: suma de ObjDiarioAt3 hasta el último día con venta (incluyéndolo)
                        for ($i = 0; $i <= $ultimoIndiceConVentaAt3; $i++) {
                            if (isset($datosSucursal[$i]) && $datosSucursal[$i]['ObjDiarioAt3'] !== null) {
                                $objetivoTotalHastaDiaAt3 += floatval($datosSucursal[$i]['ObjDiarioAt3']);
                            }
                        }
                        
                        // Objetivo total acumulado: Acum. Vta At3 del último día con venta diaria
                        $ultimoDiaConVentaAt3 = $datosSucursal[$ultimoIndiceConVentaAt3];
                        if ($ultimoDiaConVentaAt3['AcumVtaAt3'] !== null) {
                            $objetivoTotalAcumuladoAt3 = floatval($ultimoDiaConVentaAt3['AcumVtaAt3']);
                        }
                    }
                    
                    // Porcentaje de cumplimiento: (Acum. Vta / Objetivo Total hasta el día) * 100
                    $cumplimientoPorcentajeAt3 = 0;
                    if ($objetivoTotalHastaDiaAt3 > 0 && $objetivoTotalAcumuladoAt3 > 0) {
                        $cumplimientoPorcentajeAt3 = ($objetivoTotalAcumuladoAt3 / $objetivoTotalHastaDiaAt3) * 100;
                    }
                    ?>
                    <div class="kpi-cards">
                        <div class="kpi-card">
                            <div class="kpi-icon revenue">
                                <span>📊</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">Objetivo Total hasta el Día</div>
                                <div class="kpi-value" id="kpiObjetivoHastaAt3_<?php echo $codsuc; ?>"><?php echo number_format($objetivoTotalHastaDiaAt3, 0); ?></div>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-icon orders">
                                <span>🎯</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">Objetivo Total Acumulado</div>
                                <div class="kpi-value" id="kpiObjetivoAcumAt3_<?php echo $codsuc; ?>"><?php echo number_format($objetivoTotalAcumuladoAt3, 0); ?></div>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-icon atp">
                                <span>✓</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">% Cumplimiento Total</div>
                                <div class="kpi-value" id="kpiCumplimientoAt3_<?php echo $codsuc; ?>"><?php echo number_format($cumplimientoPorcentajeAt3, 2); ?>%</div>
                            </div>
                        </div>
                    </div>

                    <!-- Gráficos por Categoría -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="chart-section">
                                <h3 style="display: flex; align-items: center; gap: 8px;"><span>👟</span> Calzado</h3>
                                <div class="chart-container" style="height: 350px;">
                                    <canvas id="chartCalzado_<?php echo $codsuc; ?>"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="chart-section">
                                <h3 style="display: flex; align-items: center; gap: 8px;"><span>👕</span> Indumentaria</h3>
                                <div class="chart-container" style="height: 350px;">
                                    <canvas id="chartIndumentaria_<?php echo $codsuc; ?>"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-6 offset-md-3">
                            <div class="chart-section">
                                <h3 style="display: flex; align-items: center; gap: 8px;"><span>🧦</span> Medias</h3>
                                <div class="chart-container" style="height: 350px;">
                                    <canvas id="chartMedias_<?php echo $codsuc; ?>"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tabla Principal -->
                    <div class="table-wrapper">
                        <div class="table-header">
                            <h2>Detalle por Día - Sucursal <?php echo htmlspecialchars($codsuc); ?></h2>
                        </div>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th rowspan="2">Fecha</th>
                                        <th rowspan="2">Día Semana</th>
                                        <th colspan="4" class="col-group col-group-at1">Calzado</th>
                                        <th colspan="4" class="col-group col-group-at2">Indumentaria</th>
                                        <th colspan="4" class="col-group col-group-at3">Medias</th>
                                    </tr>
                                    <tr>
                                        <th class="col-group-at1">Obj. Diario</th>
                                        <th class="col-group-at1">Vta. Diaria</th>
                                        <th class="col-group-at1">Acum. Vta</th>
                                        <th class="col-group-at1">Cumpl.</th>
                                        <th class="col-group-at2">Obj. Diario</th>
                                        <th class="col-group-at2">Vta. Diaria</th>
                                        <th class="col-group-at2">Acum. Vta</th>
                                        <th class="col-group-at2">Cumpl.</th>
                                        <th class="col-group-at3">Obj. Diario</th>
                                        <th class="col-group-at3">Vta. Diaria</th>
                                        <th class="col-group-at3">Acum. Vta</th>
                                        <th class="col-group-at3">Cumpl.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($datosSucursal as $fila): 
                                        $objDiarioAt1 = $fila['ObjDiarioAt1'] !== null ? floatval($fila['ObjDiarioAt1']) : 0;
                                        $vtaDiariaAt1 = $fila['VtaDiariaAt1'] !== null ? floatval($fila['VtaDiariaAt1']) : 0;
                                        $porcentajeBarAt1 = $objDiarioAt1 > 0 ? min(100, ($vtaDiariaAt1 / $objDiarioAt1) * 100) : 0;
                                        
                                        $objDiarioAt2 = $fila['ObjDiarioAt2'] !== null ? floatval($fila['ObjDiarioAt2']) : 0;
                                        $vtaDiariaAt2 = $fila['VtaDiariaAt2'] !== null ? floatval($fila['VtaDiariaAt2']) : 0;
                                        $porcentajeBarAt2 = $objDiarioAt2 > 0 ? min(100, ($vtaDiariaAt2 / $objDiarioAt2) * 100) : 0;
                                        
                                        $objDiarioAt3 = $fila['ObjDiarioAt3'] !== null ? floatval($fila['ObjDiarioAt3']) : 0;
                                        $vtaDiariaAt3 = $fila['VtaDiariaAt3'] !== null ? floatval($fila['VtaDiariaAt3']) : 0;
                                        $porcentajeBarAt3 = $objDiarioAt3 > 0 ? min(100, ($vtaDiariaAt3 / $objDiarioAt3) * 100) : 0;
                                    ?>
                                        <tr>
                                            <td><?php echo $fila['Fecha'] ? formatearFecha($fila['Fecha']) : 'N/A'; ?></td>
                                            <td class="text-center"><?php echo htmlspecialchars($fila['DiaDeSemana'] ?? 'N/A'); ?></td>
                                            
                                            <!-- At1 -->
                                            <td class="col-group-at1 text-right"><?php echo $fila['ObjDiarioAt1'] !== null ? number_format($fila['ObjDiarioAt1'], 0) : 'N/A'; ?></td>
                                            <td class="col-group-at1 vta-diaria-cell">
                                                <?php if ($fila['VtaDiariaAt1'] !== null): ?>
                                                    <div class="vta-diaria-wrapper">
                                                        <span class="vta-diaria-value"><?php echo number_format($fila['VtaDiariaAt1'], 0); ?></span>
                                                        <div class="vta-diaria-bar-container">
                                                            <div class="vta-diaria-bar-fill" style="width: <?php echo $porcentajeBarAt1; ?>%"></div>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-group-at1 text-right"><?php echo $fila['AcumVtaAt1'] !== null ? number_format($fila['AcumVtaAt1'], 0) : 'N/A'; ?></td>
                                            <td class="col-group-at1 cumplimiento-cell <?php echo getCumplimientoColor($fila['CumplimientoAt1']); ?>">
                                                <?php echo $fila['CumplimientoAt1'] !== null ? number_format($fila['CumplimientoAt1'], 2) . '%' : 'N/A'; ?>
                                            </td>
                                            
                                            <!-- At2 -->
                                            <td class="col-group-at2 text-right"><?php echo $fila['ObjDiarioAt2'] !== null ? number_format($fila['ObjDiarioAt2'], 0) : 'N/A'; ?></td>
                                            <td class="col-group-at2 vta-diaria-cell">
                                                <?php if ($fila['VtaDiariaAt2'] !== null): ?>
                                                    <div class="vta-diaria-wrapper">
                                                        <span class="vta-diaria-value"><?php echo number_format($fila['VtaDiariaAt2'], 0); ?></span>
                                                        <div class="vta-diaria-bar-container">
                                                            <div class="vta-diaria-bar-fill" style="width: <?php echo $porcentajeBarAt2; ?>%"></div>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-group-at2 text-right"><?php echo $fila['AcumVtaAt2'] !== null ? number_format($fila['AcumVtaAt2'], 0) : 'N/A'; ?></td>
                                            <td class="col-group-at2 cumplimiento-cell <?php echo getCumplimientoColor($fila['CumplimientoAt2']); ?>">
                                                <?php echo $fila['CumplimientoAt2'] !== null ? number_format($fila['CumplimientoAt2'], 2) . '%' : 'N/A'; ?>
                                            </td>
                                            
                                            <!-- At3 -->
                                            <td class="col-group-at3 text-right"><?php echo $fila['ObjDiarioAt3'] !== null ? number_format($fila['ObjDiarioAt3'], 0) : 'N/A'; ?></td>
                                            <td class="col-group-at3 vta-diaria-cell">
                                                <?php if ($fila['VtaDiariaAt3'] !== null): ?>
                                                    <div class="vta-diaria-wrapper">
                                                        <span class="vta-diaria-value"><?php echo number_format($fila['VtaDiariaAt3'], 0); ?></span>
                                                        <div class="vta-diaria-bar-container">
                                                            <div class="vta-diaria-bar-fill" style="width: <?php echo $porcentajeBarAt3; ?>%"></div>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-group-at3 text-right"><?php echo $fila['AcumVtaAt3'] !== null ? number_format($fila['AcumVtaAt3'], 0) : 'N/A'; ?></td>
                                            <td class="col-group-at3 cumplimiento-cell <?php echo getCumplimientoColor($fila['CumplimientoAt3']); ?>">
                                                <?php echo $fila['CumplimientoAt3'] !== null ? number_format($fila['CumplimientoAt3'], 2) . '%' : 'N/A'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
    <script>
        // Datos de las sucursales desde PHP
        const sucursalesData = <?php echo json_encode($sucursales, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // Función para formatear fecha en JavaScript
        function formatearFechaJS(fecha) {
            if (fecha === null || fecha === undefined) return '';
            if (typeof fecha === 'object' && fecha.date) {
                return fecha.date.substring(0, 10);
            }
            if (typeof fecha === 'string') {
                return fecha.substring(0, 10);
            }
            try {
                const dateObj = new Date(fecha);
                return dateObj.toISOString().substring(0, 10);
            } catch(e) {
                return '';
            }
        }

        // Función helper para crear gráficos
        function crearGrafico(canvasId, datos, objField, vtaField, colorBarra, colorLinea) {
            const ctx = document.getElementById(canvasId);
            if (!ctx) return;

                const labels = datos.map(f => {
                    const fecha = f.Fecha;
                if (fecha === null || fecha === undefined) return '';
                    if (typeof fecha === 'object') {
                    if (fecha.date) return fecha.date.substring(0, 10);
                        try {
                            const dateObj = new Date(fecha);
                            return dateObj.toLocaleDateString('es-AR', {day: '2-digit', month: '2-digit'});
                    } catch(e) { return ''; }
                        }
                if (typeof fecha === 'string') return fecha.substring(0, 10);
                    return '';
                });
                
            const objetivos = datos.map(f => parseFloat(f[objField]) || 0);
            const ventas = datos.map(f => parseFloat(f[vtaField]) || 0);

            new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                            label: 'Objetivo',
                                data: objetivos,
                                type: 'line',
                            borderColor: colorLinea,
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                                borderDash: [5, 5],
                                fill: false,
                            tension: 0.3,
                            pointRadius: 3,
                            pointHoverRadius: 5
                        },
                        {
                            label: 'Venta Real',
                                data: ventas,
                            backgroundColor: colorBarra,
                            borderColor: colorBarra,
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    color: '#e0e0e0',
                                font: { size: 10 },
                                boxWidth: 12,
                                padding: 8
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: '#fff',
                                bodyColor: '#e0e0e0',
                            borderColor: colorBarra,
                                borderWidth: 1
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                color: '#e0e0e0',
                                font: { size: 9 }
                                },
                                grid: {
                                color: 'rgba(255, 255, 255, 0.05)'
                                }
                            },
                            x: {
                                ticks: {
                                    color: '#e0e0e0',
                                    maxRotation: 45,
                                minRotation: 45,
                                font: { size: 8 }
                                },
                                grid: {
                                color: 'rgba(255, 255, 255, 0.05)'
                                }
                            }
                        }
                    }
                });
            }

        // Crear gráficos para cada sucursal
        Object.keys(sucursalesData).forEach(codsuc => {
            const datos = sucursalesData[codsuc];
            
            // Gráfico Calzado (At1)
            crearGrafico(
                `chartCalzado_${codsuc}`,
                datos,
                'ObjDiarioAt1',
                'VtaDiariaAt1',
                'rgba(102, 126, 234, 0.8)',
                '#f5576c'
            );

            // Gráfico Indumentaria (At2)
            crearGrafico(
                `chartIndumentaria_${codsuc}`,
                datos,
                'ObjDiarioAt2',
                'VtaDiariaAt2',
                'rgba(118, 75, 162, 0.8)',
                '#f5576c'
            );

            // Gráfico Medias (At3)
            crearGrafico(
                `chartMedias_${codsuc}`,
                datos,
                'ObjDiarioAt3',
                'VtaDiariaAt3',
                'rgba(79, 172, 254, 0.8)',
                '#f5576c'
            );
        });

        // Función para filtrar sucursales
        document.addEventListener('DOMContentLoaded', function() {
            const selectSucursal = document.getElementById('selectSucursal');
            if (selectSucursal) {
                function filtrarSucursales() {
                    const sucursalSeleccionada = selectSucursal.value;
                    const todasLasSecciones = document.querySelectorAll('.sucursal-section');
                    
                    todasLasSecciones.forEach(seccion => {
                        const codsuc = seccion.getAttribute('data-sucursal');
                        if (codsuc === sucursalSeleccionada) {
                            seccion.classList.add('active');
                        } else {
                            seccion.classList.remove('active');
                        }
                    });
                }
                
                selectSucursal.addEventListener('change', filtrarSucursales);
                // Ejecutar filtrado inicial para mostrar la primera sucursal
                filtrarSucursales();
            }
        });
    </script>
</body>
</html>
