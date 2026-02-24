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
    <link href="/imagenes/favicon.webp" rel="icon" type="image/webp" sizes="16x16">
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
        .daily-card-header.accesorio {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .daily-card-header.medias {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
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
        /* HERO SECTION - VISTA OPERATIVA HOY */
        .hero-hoy {
            background: linear-gradient(135deg, #0f1419 0%, #1a2332 50%, #2d3a4e 100%);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            border: 2px solid #667eea;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }
        .hero-titulo {
            text-align: center;
            color: #fff;
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .vista-toggle {
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .vista-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.16);
            color: rgba(255, 255, 255, 0.9);
            padding: 8px 12px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            cursor: pointer;
            user-select: none;
        }
        .vista-btn:hover {
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }
        .vista-btn.active {
            background: rgba(102, 126, 234, 0.22);
            border-color: rgba(102, 126, 234, 0.6);
            color: #fff;
        }
        .vista-bloque {
            display: none;
        }
        .vista-bloque.activa {
            display: block;
        }
        .hero-metricas-principales {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .hero-metrica {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s;
        }
        .hero-metrica:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
        }
        .hero-metrica-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        .hero-metrica-valor {
            color: #fff;
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
        }
        .hero-metrica-valor.grande {
            font-size: 2.5rem;
        }
        .hero-metrica-valor.success {
            color: #38ef7d;
        }
        .hero-metrica-valor.warning {
            color: #ffc107;
        }
        .hero-metrica-valor.danger {
            color: #f5576c;
        }
        .hero-metrica-subtext {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.7rem;
            margin-top: 5px;
        }
        .hero-estado {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 20px;
            font-weight: 700;
            font-size: 1.1rem;
            border: 2px solid;
        }
        .hero-estado.en-ritmo {
            border-color: #38ef7d;
            color: #38ef7d;
            background: rgba(56, 239, 125, 0.1);
        }
        .hero-estado.en-riesgo {
            border-color: #ffc107;
            color: #ffc107;
            background: rgba(255, 193, 7, 0.1);
        }
        .hero-estado.fuera {
            border-color: #f5576c;
            color: #f5576c;
            background: rgba(245, 87, 108, 0.1);
        }
        .hero-cards-atributos {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .hero-card-atributo {
            background: #1a2332;
            border-radius: 10px;
            padding: 15px;
            border: 2px solid;
        }
        .hero-card-atributo.calzado {
            border-color: #667eea;
        }
        .hero-card-atributo.indumentaria {
            border-color: #764ba2;
        }
        .hero-card-atributo.accesorio {
            border-color: #4facfe;
        }
        .hero-card-atributo.medias {
            border-color: #f093fb;
        }
        .hero-card-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            font-size: 0.9rem;
            font-weight: 700;
            color: #fff;
        }
        .hero-card-metricas {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .hero-card-col {
            flex: 1;
            text-align: center;
        }
        .hero-card-col-label {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.65rem;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .hero-card-col-valor {
            color: #fff;
            font-size: 1.2rem;
            font-weight: 700;
        }
        .hero-gap {
            display: flex;
            align-items: center;
            gap: 5px;
            justify-content: center;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
            margin-top: 8px;
        }
        .hero-gap.positivo {
            background: rgba(56, 239, 125, 0.15);
            color: #38ef7d;
        }
        .hero-gap.negativo {
            background: rgba(245, 87, 108, 0.15);
            color: #f5576c;
        }
        .hero-gap.exacto {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        /* BADGES COMPACTOS PARA ANÁLISIS */
        .analisis-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            justify-content: center;
        }
        .badge-analisis {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 8px 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
        }
        .badge-analisis-icono {
            font-size: 1.1rem;
        }
        .badge-analisis-texto {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .badge-analisis-label {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.65rem;
            text-transform: uppercase;
        }
        .badge-analisis-valor {
            color: #fff;
            font-weight: 700;
        }
        .seccion-titulo {
            color: #fff;
            font-size: 1.3rem;
            font-weight: 700;
            margin: 30px 0 15px 0;
            padding-left: 15px;
            border-left: 4px solid #667eea;
        }
        /* GRÁFICO COMPACTO 7 DÍAS */
        .grafico-7dias {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        .grafico-7dias-titulo {
            color: #fff;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 10px;
            text-align: center;
        }
        .grafico-7dias-container {
            height: 150px;
            position: relative;
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
        /* Header sticky atributos */
        .atributo-header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: linear-gradient(135deg, #1a2332 0%, #2d3a4e 100%);
            padding: 12px 20px;
            margin: 0 -20px 20px -20px;
            border-bottom: 2px solid #667eea;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .atributo-header-label {
            color: rgba(255,255,255,0.9);
            font-weight: 700;
            font-size: 0.9rem;
            margin-right: 8px;
        }
        .atributo-btn {
            background: rgba(255,255,255,0.08);
            border: 2px solid rgba(255,255,255,0.2);
            color: #e0e0e0;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .atributo-btn:hover {
            background: rgba(255,255,255,0.12);
            border-color: #667eea;
            color: #fff;
        }
        .atributo-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: #764ba2;
            color: #fff;
        }
        .contenido-atributo {
            display: none;
        }
        .contenido-atributo.visible {
            display: block;
        }
    </style>
</head>
<body>
    <div class="main-container">

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
                
                // Ranking de esta sucursal
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
                
                // At3 - Accesorio
                $compVsAyerAt3 = null;
                $compVsSemanaAt3 = null;
                
                // At4 - Medias
                $compVsAyerAt4 = null;
                $compVsSemanaAt4 = null;
                
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
                
                // At4 - Medias
                if ($totalDiasData >= 2) {
                    $diaActualIdx = $totalDiasData - 1;
                    $diaAnteriorIdx = $totalDiasData - 2;
                    
                    $objHoy = floatval($diasConVenta[$diaActualIdx]['ObjDiarioAt4'] ?? 0);
                    $vtaHoy = floatval($diasConVenta[$diaActualIdx]['VtaDiariaAt4'] ?? 0);
                    $cumplHoy = $objHoy > 0 ? ($vtaHoy / $objHoy * 100) : 0;
                    
                    $objAyer = floatval($diasConVenta[$diaAnteriorIdx]['ObjDiarioAt4'] ?? 0);
                    $vtaAyer = floatval($diasConVenta[$diaAnteriorIdx]['VtaDiariaAt4'] ?? 0);
                    $cumplAyer = $objAyer > 0 ? ($vtaAyer / $objAyer * 100) : 0;
                    
                    $compVsAyerAt4 = $cumplHoy - $cumplAyer;
                }
                
                if ($totalDiasData >= 8) {
                    $diaActualIdx = $totalDiasData - 1;
                    $diaSemanaIdx = $totalDiasData - 8;
                    
                    $objHoy = floatval($diasConVenta[$diaActualIdx]['ObjDiarioAt4'] ?? 0);
                    $vtaHoy = floatval($diasConVenta[$diaActualIdx]['VtaDiariaAt4'] ?? 0);
                    $cumplHoy = $objHoy > 0 ? ($vtaHoy / $objHoy * 100) : 0;
                    
                    $objSemana = floatval($diasConVenta[$diaSemanaIdx]['ObjDiarioAt4'] ?? 0);
                    $vtaSemana = floatval($diasConVenta[$diaSemanaIdx]['VtaDiariaAt4'] ?? 0);
                    $cumplSemana = $objSemana > 0 ? ($vtaSemana / $objSemana * 100) : 0;
                    
                    $compVsSemanaAt4 = $cumplHoy - $cumplSemana;
                }
                ?>
                
                <div class="sucursal-section" data-sucursal="<?php echo htmlspecialchars($codsuc); ?>">
                    <!-- Header sticky: elegir atributo -->
                    <div class="atributo-header">
                        <span class="atributo-header-label">Atributo:</span>
                        <button type="button" class="atributo-btn active" data-atributo="at1"><span>👟</span> Calzado</button>
                        <button type="button" class="atributo-btn" data-atributo="at2"><span>👕</span> Indumentaria</button>
                        <button type="button" class="atributo-btn" data-atributo="at3"><span>💼</span> Accesorio</button>
                        <button type="button" class="atributo-btn" data-atributo="at4"><span>🧦</span> Medias</button>
                    </div>
                    
                    <!-- Badges de Análisis Rápido -->
                    <div class="analisis-badges">
                        <div class="badge-analisis">
                            <span class="badge-analisis-icono">🏆</span>
                            <div class="badge-analisis-texto">
                                <span class="badge-analisis-label">Posición</span>
                                <span class="badge-analisis-valor"><?php echo $miPosicion; ?>° / <?php echo $totalSucursales; ?></span>
                            </div>
                        </div>
                        
                        <div class="badge-analisis">
                            <span class="badge-analisis-icono"><?php echo $tendencia; ?></span>
                            <div class="badge-analisis-texto">
                                <span class="badge-analisis-label">Tendencia</span>
                                <span class="badge-analisis-valor">
                                    <?php 
                                    if ($tendenciaClass === 'up') echo 'Mejorando';
                                    elseif ($tendenciaClass === 'down') echo 'Bajando';
                                    else echo 'Estable';
                                    ?> (<?php echo $tendenciaValor > 0 ? '+' : ''; ?><?php echo number_format($tendenciaValor, 1); ?>%)
                                </span>
                            </div>
                        </div>
                        
                        <!-- OCULTO: Comparativa con promedio de red
                        <div class="badge-analisis">
                            <span class="badge-analisis-icono">🌐</span>
                            <div class="badge-analisis-texto">
                                <span class="badge-analisis-label">vs Promedio Red</span>
                                <span class="badge-analisis-valor">
                                    <?php 
                                    // Calcular promedio de la red
                                    $promedioRed = array_sum($rankingSucursales) / count($rankingSucursales);
                                    $difVsRed = $miCumplimiento - $promedioRed;
                                    echo $difVsRed >= 0 ? '+' : '';
                                    echo number_format($difVsRed, 1);
                                    ?>% (<?php echo $difVsRed > 0 ? '↗ Arriba' : '↘ Abajo'; ?>)
                                </span>
                            </div>
                        </div>
                        -->
                    </div>
                    
                    <!-- HERO SECTION - VISTA OPERATIVA HOY -->
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
                    
                    // Si no hay datos de hoy, buscar el día más reciente en el futuro
                    if ($datosHoy === null) {
                        foreach ($datosSucursal as $fila) {
                            $fechaFila = formatearFecha($fila['Fecha']);
                            if (strtotime($fechaFila) >= strtotime($fechaHoy)) {
                                $datosHoy = $fila;
                                break;
                            }
                        }
                    }
                    
                    // Último día con venta (para "Rendimiento de ayer")
                    $diaActual = null;
                    foreach (array_reverse($datosSucursal) as $fila) {
                        if ($fila['VtaDiariaAt1'] !== null && floatval($fila['VtaDiariaAt1']) > 0) {
                            $diaActual = $fila;
                            break;
                        }
                    }
                    
                    if ($datosHoy !== null):
                        // Objetivos del día
                        $objHoyAt1 = floatval($datosHoy['ObjDiarioAt1'] ?? 0);
                        $objHoyAt2 = floatval($datosHoy['ObjDiarioAt2'] ?? 0);
                        $objHoyAt3 = floatval($datosHoy['ObjDiarioAt3'] ?? 0);
                        $objHoyAt4 = floatval($datosHoy['ObjDiarioAt4'] ?? 0);
                        $objHoyTotal = $objHoyAt1 + $objHoyAt2 + $objHoyAt3 + $objHoyAt4;
                        
                        // Ventas del día (pueden ser null si aún no hay datos)
                        $vtaHoyAt1 = floatval($datosHoy['VtaDiariaAt1'] ?? 0);
                        $vtaHoyAt2 = floatval($datosHoy['VtaDiariaAt2'] ?? 0);
                        $vtaHoyAt3 = floatval($datosHoy['VtaDiariaAt3'] ?? 0);
                        $vtaHoyAt4 = floatval($datosHoy['VtaDiariaAt4'] ?? 0);
                        $vtaHoyTotal = $vtaHoyAt1 + $vtaHoyAt2 + $vtaHoyAt3 + $vtaHoyAt4;
                        
                        // Cumplimiento
                        $cumplHoy = $objHoyTotal > 0 ? ($vtaHoyTotal / $objHoyTotal * 100) : 0;
                        $gapTotal = $vtaHoyTotal - $objHoyTotal;
                        
                        // Estado del día
                        $estadoClase = 'fuera';
                        $estadoTexto = '🔴 FUERA DE OBJETIVO';
                        if ($cumplHoy >= 90) {
                            $estadoClase = 'en-ritmo';
                            $estadoTexto = '🟢 EN RITMO';
                        } elseif ($cumplHoy >= 70) {
                            $estadoClase = 'en-riesgo';
                            $estadoTexto = '🟡 EN RIESGO';
                        }
                        
                        // Proyección simple (si hay venta, proyectar al 100%)
                        $proyeccionCierre = $vtaHoyTotal > 0 ? $vtaHoyTotal : 0;
                        
                        // Gaps por atributo
                        $gapAt1 = $vtaHoyAt1 - $objHoyAt1;
                        $gapAt2 = $vtaHoyAt2 - $objHoyAt2;
                        $gapAt3 = $vtaHoyAt3 - $objHoyAt3;
                        $gapAt4 = $vtaHoyAt4 - $objHoyAt4;
                        
                        $fechaMostrar = formatearFecha($datosHoy['Fecha']);
                    ?>
                    <div class="hero-hoy">
                        <div class="hero-titulo">📊 ESTADO OPERATIVO</div>

                        <div class="vista-toggle">
                            <button type="button" class="vista-btn active" data-vista="hoy">🎯 Objetivo de hoy</button>
                            <button type="button" class="vista-btn" data-vista="ayer">📅 Rendimiento de ayer</button>
                        </div>

                        <div class="vista-bloque activa" data-vista="hoy">
                        
                        <!-- Estado del Día -->
                        <div class="hero-estado <?php echo $estadoClase; ?>">
                            <?php echo $estadoTexto; ?>
                        </div>
                        
                        <!-- Cards por Atributo -->
                        <div class="hero-cards-atributos">
                            <!-- Calzado -->
                            <div class="contenido-atributo visible" data-atributo="at1">
                            <div class="hero-card-atributo calzado">
                                <div class="hero-card-header">
                                    <span>👟</span> CALZADO
                                </div>
                                <div class="hero-card-metricas">
                                    <div class="hero-card-col">
                                        <div class="hero-card-col-label">Objetivo</div>
                                        <div class="hero-card-col-valor"><?php echo number_format($objHoyAt1, 0); ?></div>
                                    </div>
                                    <div class="hero-card-col">
                                        <div class="hero-card-col-label">Venta</div>
                                        <div class="hero-card-col-valor"><?php echo number_format($vtaHoyAt1, 0); ?></div>
                                    </div>
                                </div>
                                <div class="hero-gap <?php echo $gapAt1 > 0 ? 'positivo' : ($gapAt1 < 0 ? 'negativo' : 'exacto'); ?>">
                                    <span><?php echo $gapAt1 > 0 ? '✓' : ($gapAt1 < 0 ? '⚠' : '='); ?></span>
                                    <span><?php echo $gapAt1 >= 0 ? '+' : ''; ?><?php echo number_format($gapAt1, 0); ?></span>
                                </div>
                            </div>
                            </div>
                            
                            <!-- Indumentaria -->
                            <div class="contenido-atributo" data-atributo="at2">
                            <div class="hero-card-atributo indumentaria">
                                <div class="hero-card-header">
                                    <span>👕</span> INDUMENTARIA
                                </div>
                                <div class="hero-card-metricas">
                                    <div class="hero-card-col">
                                        <div class="hero-card-col-label">Objetivo</div>
                                        <div class="hero-card-col-valor"><?php echo number_format($objHoyAt2, 0); ?></div>
                                    </div>
                                    <div class="hero-card-col">
                                        <div class="hero-card-col-label">Venta</div>
                                        <div class="hero-card-col-valor"><?php echo number_format($vtaHoyAt2, 0); ?></div>
                                    </div>
                                </div>
                                <div class="hero-gap <?php echo $gapAt2 > 0 ? 'positivo' : ($gapAt2 < 0 ? 'negativo' : 'exacto'); ?>">
                                    <span><?php echo $gapAt2 > 0 ? '✓' : ($gapAt2 < 0 ? '⚠' : '='); ?></span>
                                    <span><?php echo $gapAt2 >= 0 ? '+' : ''; ?><?php echo number_format($gapAt2, 0); ?></span>
                                </div>
                            </div>
                            </div>
                            
                            <!-- Accesorio -->
                            <div class="contenido-atributo" data-atributo="at3">
                            <div class="hero-card-atributo accesorio">
                                <div class="hero-card-header">
                                    <span>💼</span> ACCESORIO
                                </div>
                                <div class="hero-card-metricas">
                                    <div class="hero-card-col">
                                        <div class="hero-card-col-label">Objetivo</div>
                                        <div class="hero-card-col-valor"><?php echo number_format($objHoyAt3, 0); ?></div>
                                    </div>
                                    <div class="hero-card-col">
                                        <div class="hero-card-col-label">Venta</div>
                                        <div class="hero-card-col-valor"><?php echo number_format($vtaHoyAt3, 0); ?></div>
                                    </div>
                                </div>
                                <div class="hero-gap <?php echo $gapAt3 > 0 ? 'positivo' : ($gapAt3 < 0 ? 'negativo' : 'exacto'); ?>">
                                    <span><?php echo $gapAt3 > 0 ? '✓' : ($gapAt3 < 0 ? '⚠' : '='); ?></span>
                                    <span><?php echo $gapAt3 >= 0 ? '+' : ''; ?><?php echo number_format($gapAt3, 0); ?></span>
                                </div>
                            </div>
                            </div>
                            
                            <!-- Medias -->
                            <div class="contenido-atributo" data-atributo="at4">
                            <div class="hero-card-atributo medias">
                                <div class="hero-card-header">
                                    <span>🧦</span> MEDIAS
                                </div>
                                <div class="hero-card-metricas">
                                    <div class="hero-card-col">
                                        <div class="hero-card-col-label">Objetivo</div>
                                        <div class="hero-card-col-valor"><?php echo number_format($objHoyAt4, 0); ?></div>
                                    </div>
                                    <div class="hero-card-col">
                                        <div class="hero-card-col-label">Venta</div>
                                        <div class="hero-card-col-valor"><?php echo number_format($vtaHoyAt4, 0); ?></div>
                                    </div>
                                </div>
                                <div class="hero-gap <?php echo $gapAt4 > 0 ? 'positivo' : ($gapAt4 < 0 ? 'negativo' : 'exacto'); ?>">
                                    <span><?php echo $gapAt4 > 0 ? '✓' : ($gapAt4 < 0 ? '⚠' : '='); ?></span>
                                    <span><?php echo $gapAt4 >= 0 ? '+' : ''; ?><?php echo number_format($gapAt4, 0); ?></span>
                                </div>
                            </div>
                            </div>
                        </div>
                        </div>

                        <div class="vista-bloque" data-vista="ayer">
                        <?php if ($diaActual !== null): ?>
                        <div class="daily-indicators-section">
                            <div class="daily-indicators">
                                <!-- Calzado -->
                                <div class="contenido-atributo visible" data-atributo="at1">
                                <?php
                                $objDiaAt1 = floatval($diaActual['ObjDiarioAt1'] ?? 0);
                                $vtaDiaAt1 = floatval($diaActual['VtaDiariaAt1'] ?? 0);
                                $difAt1 = $vtaDiaAt1 - $objDiaAt1;
                                $porcCumplimientoAt1 = $objDiaAt1 > 0 ? (($vtaDiaAt1 / $objDiaAt1) * 100) : 0;
                                $variacionAt1 = $porcCumplimientoAt1 - 100;
                                $statusAt1 = $variacionAt1 > 0 ? 'above' : ($variacionAt1 < 0 ? 'below' : 'exact');
                                ?>
                                <div class="daily-card">
                                    <div class="daily-card-header calzado"><span>👟</span> CALZADO</div>
                                    <div class="daily-card-body">
                                        <div class="daily-metric"><span class="daily-metric-label">Objetivo del Día</span><span class="daily-metric-value"><?php echo number_format($objDiaAt1, 0); ?></span></div>
                                        <div class="daily-metric"><span class="daily-metric-label">Venta del Día</span><span class="daily-metric-value"><?php echo number_format($vtaDiaAt1, 0); ?></span></div>
                                        <div class="daily-metric"><div class="daily-performance <?php echo $statusAt1; ?>"><span class="daily-performance-icon"><?php echo $statusAt1 === 'above' ? '📈' : ($statusAt1 === 'below' ? '📉' : '➡️'); ?></span><span class="daily-performance-value <?php echo $statusAt1; ?>"><?php echo $variacionAt1 >= 0 ? '+' : ''; ?><?php echo number_format($variacionAt1, 1); ?>%</span><span class="daily-metric-label">(<?php echo $difAt1 >= 0 ? '+' : ''; ?><?php echo number_format($difAt1, 0); ?>)</span></div></div>
                                    </div>
                                    <div class="comparison-badges"><?php if ($compVsSemanaAt1 !== null): ?><div class="comp-badge <?php echo $compVsSemanaAt1 > 0 ? 'up' : ($compVsSemanaAt1 < 0 ? 'down' : 'neutral'); ?>"><span class="trend-arrow"><?php echo $compVsSemanaAt1 > 0 ? '↗' : ($compVsSemanaAt1 < 0 ? '↘' : '→'); ?></span><span>Vs. Semana Anterior: <?php echo $compVsSemanaAt1 >= 0 ? '+' : ''; ?><?php echo number_format($compVsSemanaAt1, 1); ?>%</span></div><?php endif; ?></div>
                                </div>
                                </div>
                                <!-- Indumentaria -->
                                <div class="contenido-atributo" data-atributo="at2">
                                <?php
                                $objDiaAt2 = floatval($diaActual['ObjDiarioAt2'] ?? 0);
                                $vtaDiaAt2 = floatval($diaActual['VtaDiariaAt2'] ?? 0);
                                $difAt2 = $vtaDiaAt2 - $objDiaAt2;
                                $porcCumplimientoAt2 = $objDiaAt2 > 0 ? (($vtaDiaAt2 / $objDiaAt2) * 100) : 0;
                                $variacionAt2 = $porcCumplimientoAt2 - 100;
                                $statusAt2 = $variacionAt2 > 0 ? 'above' : ($variacionAt2 < 0 ? 'below' : 'exact');
                                ?>
                                <div class="daily-card">
                                    <div class="daily-card-header indumentaria"><span>👕</span> INDUMENTARIA</div>
                                    <div class="daily-card-body">
                                        <div class="daily-metric"><span class="daily-metric-label">Objetivo del Día</span><span class="daily-metric-value"><?php echo number_format($objDiaAt2, 0); ?></span></div>
                                        <div class="daily-metric"><span class="daily-metric-label">Venta del Día</span><span class="daily-metric-value"><?php echo number_format($vtaDiaAt2, 0); ?></span></div>
                                        <div class="daily-metric"><div class="daily-performance <?php echo $statusAt2; ?>"><span class="daily-performance-icon"><?php echo $statusAt2 === 'above' ? '📈' : ($statusAt2 === 'below' ? '📉' : '➡️'); ?></span><span class="daily-performance-value <?php echo $statusAt2; ?>"><?php echo $variacionAt2 >= 0 ? '+' : ''; ?><?php echo number_format($variacionAt2, 1); ?>%</span><span class="daily-metric-label">(<?php echo $difAt2 >= 0 ? '+' : ''; ?><?php echo number_format($difAt2, 0); ?>)</span></div></div>
                                    </div>
                                    <div class="comparison-badges"><?php if ($compVsSemanaAt2 !== null): ?><div class="comp-badge <?php echo $compVsSemanaAt2 > 0 ? 'up' : ($compVsSemanaAt2 < 0 ? 'down' : 'neutral'); ?>"><span class="trend-arrow"><?php echo $compVsSemanaAt2 > 0 ? '↗' : ($compVsSemanaAt2 < 0 ? '↘' : '→'); ?></span><span>Vs. Semana Anterior: <?php echo $compVsSemanaAt2 >= 0 ? '+' : ''; ?><?php echo number_format($compVsSemanaAt2, 1); ?>%</span></div><?php endif; ?></div>
                                </div>
                                </div>
                                <!-- Accesorio -->
                                <div class="contenido-atributo" data-atributo="at3">
                                <?php
                                $objDiaAt3 = floatval($diaActual['ObjDiarioAt3'] ?? 0);
                                $vtaDiaAt3 = floatval($diaActual['VtaDiariaAt3'] ?? 0);
                                $difAt3 = $vtaDiaAt3 - $objDiaAt3;
                                $porcCumplimientoAt3 = $objDiaAt3 > 0 ? (($vtaDiaAt3 / $objDiaAt3) * 100) : 0;
                                $variacionAt3 = $porcCumplimientoAt3 - 100;
                                $statusAt3 = $variacionAt3 > 0 ? 'above' : ($variacionAt3 < 0 ? 'below' : 'exact');
                                ?>
                                <div class="daily-card">
                                    <div class="daily-card-header accesorio"><span>💼</span> ACCESORIO</div>
                                    <div class="daily-card-body">
                                        <div class="daily-metric"><span class="daily-metric-label">Objetivo del Día</span><span class="daily-metric-value"><?php echo number_format($objDiaAt3, 0); ?></span></div>
                                        <div class="daily-metric"><span class="daily-metric-label">Venta del Día</span><span class="daily-metric-value"><?php echo number_format($vtaDiaAt3, 0); ?></span></div>
                                        <div class="daily-metric"><div class="daily-performance <?php echo $statusAt3; ?>"><span class="daily-performance-icon"><?php echo $statusAt3 === 'above' ? '📈' : ($statusAt3 === 'below' ? '📉' : '➡️'); ?></span><span class="daily-performance-value <?php echo $statusAt3; ?>"><?php echo $variacionAt3 >= 0 ? '+' : ''; ?><?php echo number_format($variacionAt3, 1); ?>%</span><span class="daily-metric-label">(<?php echo $difAt3 >= 0 ? '+' : ''; ?><?php echo number_format($difAt3, 0); ?>)</span></div></div>
                                    </div>
                                    <div class="comparison-badges"><?php if ($compVsSemanaAt3 !== null): ?><div class="comp-badge <?php echo $compVsSemanaAt3 > 0 ? 'up' : ($compVsSemanaAt3 < 0 ? 'down' : 'neutral'); ?>"><span class="trend-arrow"><?php echo $compVsSemanaAt3 > 0 ? '↗' : ($compVsSemanaAt3 < 0 ? '↘' : '→'); ?></span><span>Vs. Semana Anterior: <?php echo $compVsSemanaAt3 >= 0 ? '+' : ''; ?><?php echo number_format($compVsSemanaAt3, 1); ?>%</span></div><?php endif; ?></div>
                                </div>
                                </div>
                                <!-- Medias -->
                                <div class="contenido-atributo" data-atributo="at4">
                                <?php
                                $objDiaAt4 = floatval($diaActual['ObjDiarioAt4'] ?? 0);
                                $vtaDiaAt4 = floatval($diaActual['VtaDiariaAt4'] ?? 0);
                                $difAt4 = $vtaDiaAt4 - $objDiaAt4;
                                $porcCumplimientoAt4 = $objDiaAt4 > 0 ? (($vtaDiaAt4 / $objDiaAt4) * 100) : 0;
                                $variacionAt4 = $porcCumplimientoAt4 - 100;
                                $statusAt4 = $variacionAt4 > 0 ? 'above' : ($variacionAt4 < 0 ? 'below' : 'exact');
                                ?>
                                <div class="daily-card">
                                    <div class="daily-card-header medias"><span>🧦</span> MEDIAS</div>
                                    <div class="daily-card-body">
                                        <div class="daily-metric"><span class="daily-metric-label">Objetivo del Día</span><span class="daily-metric-value"><?php echo number_format($objDiaAt4, 0); ?></span></div>
                                        <div class="daily-metric"><span class="daily-metric-label">Venta del Día</span><span class="daily-metric-value"><?php echo number_format($vtaDiaAt4, 0); ?></span></div>
                                        <div class="daily-metric"><div class="daily-performance <?php echo $statusAt4; ?>"><span class="daily-performance-icon"><?php echo $statusAt4 === 'above' ? '📈' : ($statusAt4 === 'below' ? '📉' : '➡️'); ?></span><span class="daily-performance-value <?php echo $statusAt4; ?>"><?php echo $variacionAt4 >= 0 ? '+' : ''; ?><?php echo number_format($variacionAt4, 1); ?>%</span><span class="daily-metric-label">(<?php echo $difAt4 >= 0 ? '+' : ''; ?><?php echo number_format($difAt4, 0); ?>)</span></div></div>
                                    </div>
                                    <div class="comparison-badges"><?php if ($compVsSemanaAt4 !== null): ?><div class="comp-badge <?php echo $compVsSemanaAt4 > 0 ? 'up' : ($compVsSemanaAt4 < 0 ? 'down' : 'neutral'); ?>"><span class="trend-arrow"><?php echo $compVsSemanaAt4 > 0 ? '↗' : ($compVsSemanaAt4 < 0 ? '↘' : '→'); ?></span><span>Vs. Semana Anterior: <?php echo $compVsSemanaAt4 >= 0 ? '+' : ''; ?><?php echo number_format($compVsSemanaAt4, 1); ?>%</span></div><?php endif; ?></div>
                                </div>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="daily-indicators-section">
                            <div style="color: rgba(255, 255, 255, 0.75); font-size: 0.9rem;">Sin datos para mostrar.</div>
                        </div>
                        <?php endif; ?>
                        </div>
                        
                        <!-- GRÁFICO ÚLTIMOS 7 DÍAS -->
                        <?php
                        // Obtener los 7 días anteriores al día actual por calendario
                        $fechaHoyObj = new DateTime($fechaHoy);
                        $ultimos7DiasCalendario = [];
                        
                        for ($i = 7; $i >= 1; $i--) {
                            $fechaTemp = clone $fechaHoyObj;
                            $fechaTemp->modify("-$i days");
                            $fechaBuscar = $fechaTemp->format('Y-m-d');
                            
                            // Buscar datos de esa fecha
                            $datoFecha = null;
                            foreach ($datosSucursal as $fila) {
                                if (formatearFecha($fila['Fecha']) === $fechaBuscar) {
                                    $datoFecha = $fila;
                                    break;
                                }
                            }
                            
                            $ultimos7DiasCalendario[] = [
                                'fecha' => $fechaBuscar,
                                'datos' => $datoFecha
                            ];
                        }
                        
                        if (count($ultimos7DiasCalendario) > 0):
                        ?>
                        <div class="contenido-atributo visible" data-atributo="at1">
                        <div class="grafico-7dias">
                            <div class="grafico-7dias-titulo">📊 Últimos 7 Días – Calzado</div>
                            <div class="grafico-7dias-container"><canvas id="chart7Dias_At1_<?php echo $codsuc; ?>"></canvas></div>
                        </div>
                        </div>
                        <div class="contenido-atributo" data-atributo="at2">
                        <div class="grafico-7dias">
                            <div class="grafico-7dias-titulo">📊 Últimos 7 Días – Indumentaria</div>
                            <div class="grafico-7dias-container"><canvas id="chart7Dias_At2_<?php echo $codsuc; ?>"></canvas></div>
                        </div>
                        </div>
                        <div class="contenido-atributo" data-atributo="at3">
                        <div class="grafico-7dias">
                            <div class="grafico-7dias-titulo">📊 Últimos 7 Días – Accesorio</div>
                            <div class="grafico-7dias-container"><canvas id="chart7Dias_At3_<?php echo $codsuc; ?>"></canvas></div>
                        </div>
                        </div>
                        <div class="contenido-atributo" data-atributo="at4">
                        <div class="grafico-7dias">
                            <div class="grafico-7dias-titulo">📊 Últimos 7 Días – Medias</div>
                            <div class="grafico-7dias-container"><canvas id="chart7Dias_At4_<?php echo $codsuc; ?>"></canvas></div>
                        </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php
                    // Fila del primer día del mes para leer ObejtivoAt1/2/3/4 (objetivo total del mes)
                    $fechaHoyCalculo = isset($fechaHoy) ? $fechaHoy : date('Y-m-d');
                    $primerDiaMes = substr($fechaHoyCalculo, 0, 7) . '-01'; // YYYY-MM-01
                    $filaObjetivoMes = null;
                    foreach ($datosSucursal as $fila) {
                        $fechaFila = formatearFecha($fila['Fecha']);
                        if ($fechaFila !== 'N/A' && $fechaFila === $primerDiaMes) {
                            $filaObjetivoMes = $fila;
                            break;
                        }
                    }
                    if ($filaObjetivoMes === null && !empty($datosSucursal)) {
                        $anioMesHoy = substr($fechaHoyCalculo, 0, 7);
                        foreach ($datosSucursal as $fila) {
                            $fechaFila = formatearFecha($fila['Fecha']);
                            if ($fechaFila !== 'N/A' && substr($fechaFila, 0, 7) === $anioMesHoy) {
                                $filaObjetivoMes = $fila;
                                break;
                            }
                        }
                    }
                    $objetivoTotalDelMesAt1 = $filaObjetivoMes ? floatval($filaObjetivoMes['ObejtivoAt1'] ?? 0) : 0;
                    $objetivoTotalDelMesAt2 = $filaObjetivoMes ? floatval($filaObjetivoMes['ObejtivoAt2'] ?? 0) : 0;
                    $objetivoTotalDelMesAt3 = $filaObjetivoMes ? floatval($filaObjetivoMes['ObejtivoAt3'] ?? 0) : 0;
                    $objetivoTotalDelMesAt4 = $filaObjetivoMes ? floatval($filaObjetivoMes['ObejtivoAt4'] ?? 0) : 0;
                    ?>

                    <!-- Tarjetas KPI At1 (Calzado) -->
                    <div class="contenido-atributo visible" data-atributo="at1">
                    <h3 class="kpi-section-title calzado"><span>👟</span> CALZADO</h3>
                    <?php
                    $objetivoTotalHastaDia = 0;
                    $objetivoTotalAcumulado = 0;
                    $ultimoIndiceConVenta = -1;
                    $fechaHoyCalculo = isset($fechaHoy) ? $fechaHoy : date('Y-m-d');
                    $anioMesHoy = substr($fechaHoyCalculo, 0, 7);
                    $diasHastaHoyAt1 = [];
                    foreach ($datosSucursal as $fila) {
                        $fechaFila = formatearFecha($fila['Fecha']);
                        if ($fechaFila === 'N/A') continue;
                        if (substr($fechaFila, 0, 7) !== $anioMesHoy) continue;
                        if (strtotime($fechaFila) > strtotime($fechaHoyCalculo)) continue;
                        if (isset($diasHastaHoyAt1[$fechaFila])) continue;
                        $diasHastaHoyAt1[$fechaFila] = true;
                        if (isset($fila['ObjDiarioAt1']) && $fila['ObjDiarioAt1'] !== null) {
                            $objetivoTotalHastaDia += floatval($fila['ObjDiarioAt1']);
                        }
                    }
                    
                    // Objetivo total acumulado: Acum. Vta At1 del último día con venta diaria (se mantiene igual)
                    foreach ($datosSucursal as $indice => $fila) {
                        if ($fila['VtaDiariaAt1'] !== null && floatval($fila['VtaDiariaAt1']) > 0) {
                            $ultimoIndiceConVenta = $indice;
                        }
                    }
                    if ($ultimoIndiceConVenta >= 0) {
                        $ultimoDiaConVenta = $datosSucursal[$ultimoIndiceConVenta];
                        if ($ultimoDiaConVenta['AcumVtaAt1'] !== null) {
                            $objetivoTotalAcumulado = floatval($ultimoDiaConVenta['AcumVtaAt1']);
                        }
                    }
                    
                    // Porcentaje de cumplimiento: (Objetivo total acumulado / Objetivo total del mes) * 100
                    $cumplimientoPorcentaje = 0;
                    if ($objetivoTotalDelMesAt1 > 0 && $objetivoTotalAcumulado > 0) {
                        $cumplimientoPorcentaje = ($objetivoTotalAcumulado / $objetivoTotalDelMesAt1) * 100;
                    }
                    ?>
                    <div class="kpi-cards">
                        <div class="kpi-card">
                            <div class="kpi-icon revenue">
                                <span>📊</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">Objetivo Total del Mes</div>
                                <div class="kpi-value" id="kpiObjetivoHasta_<?php echo $codsuc; ?>"><?php echo number_format($objetivoTotalDelMesAt1, 0); ?></div>
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
                    </div>

                    <!-- Tarjetas KPI At2 (Indumentaria) -->
                    <div class="contenido-atributo" data-atributo="at2">
                    <h3 class="kpi-section-title indumentaria"><span>👕</span> INDUMENTARIA</h3>
                    <?php
                    $objetivoTotalHastaDiaAt2 = 0;
                    $objetivoTotalAcumuladoAt2 = 0;
                    $ultimoIndiceConVentaAt2 = -1;
                    $fechaHoyCalculo = isset($fechaHoy) ? $fechaHoy : date('Y-m-d');
                    $anioMesHoy = substr($fechaHoyCalculo, 0, 7);
                    $diasHastaHoyAt2 = [];
                    foreach ($datosSucursal as $fila) {
                        $fechaFila = formatearFecha($fila['Fecha']);
                        if ($fechaFila === 'N/A') continue;
                        if (substr($fechaFila, 0, 7) !== $anioMesHoy) continue;
                        if (strtotime($fechaFila) > strtotime($fechaHoyCalculo)) continue;
                        if (isset($diasHastaHoyAt2[$fechaFila])) continue;
                        $diasHastaHoyAt2[$fechaFila] = true;
                        if (isset($fila['ObjDiarioAt2']) && $fila['ObjDiarioAt2'] !== null) {
                            $objetivoTotalHastaDiaAt2 += floatval($fila['ObjDiarioAt2']);
                        }
                    }
                    
                    // Objetivo total acumulado: Acum. Vta At2 del último día con venta diaria (se mantiene igual)
                    foreach ($datosSucursal as $indice => $fila) {
                        if ($fila['VtaDiariaAt2'] !== null && floatval($fila['VtaDiariaAt2']) > 0) {
                            $ultimoIndiceConVentaAt2 = $indice;
                        }
                    }
                    if ($ultimoIndiceConVentaAt2 >= 0) {
                        $ultimoDiaConVentaAt2 = $datosSucursal[$ultimoIndiceConVentaAt2];
                        if ($ultimoDiaConVentaAt2['AcumVtaAt2'] !== null) {
                            $objetivoTotalAcumuladoAt2 = floatval($ultimoDiaConVentaAt2['AcumVtaAt2']);
                        }
                    }
                    
                    // Porcentaje de cumplimiento: (Objetivo total acumulado / Objetivo total del mes) * 100
                    $cumplimientoPorcentajeAt2 = 0;
                    if ($objetivoTotalDelMesAt2 > 0 && $objetivoTotalAcumuladoAt2 > 0) {
                        $cumplimientoPorcentajeAt2 = ($objetivoTotalAcumuladoAt2 / $objetivoTotalDelMesAt2) * 100;
                    }
                    ?>
                    <div class="kpi-cards">
                        <div class="kpi-card">
                            <div class="kpi-icon revenue">
                                <span>📊</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">Objetivo Total del Mes</div>
                                <div class="kpi-value" id="kpiObjetivoHastaAt2_<?php echo $codsuc; ?>"><?php echo number_format($objetivoTotalDelMesAt2, 0); ?></div>
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
                    </div>

                    <!-- Tarjetas KPI At3 (Accesorio) -->
                    <div class="contenido-atributo" data-atributo="at3">
                    <h3 class="kpi-section-title accesorio"><span>💼</span> ACCESORIO</h3>
                    <?php
                    $objetivoTotalHastaDiaAt3 = 0;
                    $objetivoTotalAcumuladoAt3 = 0;
                    $ultimoIndiceConVentaAt3 = -1;
                    $fechaHoyCalculo = isset($fechaHoy) ? $fechaHoy : date('Y-m-d');
                    $anioMesHoy = substr($fechaHoyCalculo, 0, 7);
                    $diasHastaHoyAt3 = [];
                    foreach ($datosSucursal as $fila) {
                        $fechaFila = formatearFecha($fila['Fecha']);
                        if ($fechaFila === 'N/A') continue;
                        if (substr($fechaFila, 0, 7) !== $anioMesHoy) continue;
                        if (strtotime($fechaFila) > strtotime($fechaHoyCalculo)) continue;
                        if (isset($diasHastaHoyAt3[$fechaFila])) continue;
                        $diasHastaHoyAt3[$fechaFila] = true;
                        if (isset($fila['ObjDiarioAt3']) && $fila['ObjDiarioAt3'] !== null) {
                            $objetivoTotalHastaDiaAt3 += floatval($fila['ObjDiarioAt3']);
                        }
                    }
                    
                    // Objetivo total acumulado: Acum. Vta At3 del último día con venta diaria (se mantiene igual)
                    foreach ($datosSucursal as $indice => $fila) {
                        if ($fila['VtaDiariaAt3'] !== null && floatval($fila['VtaDiariaAt3']) > 0) {
                            $ultimoIndiceConVentaAt3 = $indice;
                        }
                    }
                    if ($ultimoIndiceConVentaAt3 >= 0) {
                        $ultimoDiaConVentaAt3 = $datosSucursal[$ultimoIndiceConVentaAt3];
                        if ($ultimoDiaConVentaAt3['AcumVtaAt3'] !== null) {
                            $objetivoTotalAcumuladoAt3 = floatval($ultimoDiaConVentaAt3['AcumVtaAt3']);
                        }
                    }
                    
                    // Porcentaje de cumplimiento: (Objetivo total acumulado / Objetivo total del mes) * 100
                    $cumplimientoPorcentajeAt3 = 0;
                    if ($objetivoTotalDelMesAt3 > 0 && $objetivoTotalAcumuladoAt3 > 0) {
                        $cumplimientoPorcentajeAt3 = ($objetivoTotalAcumuladoAt3 / $objetivoTotalDelMesAt3) * 100;
                    }
                    ?>
                    <div class="kpi-cards">
                        <div class="kpi-card">
                            <div class="kpi-icon revenue">
                                <span>📊</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">Objetivo Total del Mes</div>
                                <div class="kpi-value" id="kpiObjetivoHastaAt3_<?php echo $codsuc; ?>"><?php echo number_format($objetivoTotalDelMesAt3, 0); ?></div>
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
                    </div>

                    <!-- Tarjetas KPI At4 (Medias) -->
                    <div class="contenido-atributo" data-atributo="at4">
                    <h3 class="kpi-section-title medias"><span>🧦</span> MEDIAS</h3>
                    <?php
                    $objetivoTotalHastaDiaAt4 = 0;
                    $objetivoTotalAcumuladoAt4 = 0;
                    $ultimoIndiceConVentaAt4 = -1;
                    $fechaHoyCalculo = isset($fechaHoy) ? $fechaHoy : date('Y-m-d');
                    $anioMesHoy = substr($fechaHoyCalculo, 0, 7);
                    $diasHastaHoyAt4 = [];
                    foreach ($datosSucursal as $fila) {
                        $fechaFila = formatearFecha($fila['Fecha']);
                        if ($fechaFila === 'N/A') continue;
                        if (substr($fechaFila, 0, 7) !== $anioMesHoy) continue;
                        if (strtotime($fechaFila) > strtotime($fechaHoyCalculo)) continue;
                        if (isset($diasHastaHoyAt4[$fechaFila])) continue;
                        $diasHastaHoyAt4[$fechaFila] = true;
                        if (isset($fila['ObjDiarioAt4']) && $fila['ObjDiarioAt4'] !== null) {
                            $objetivoTotalHastaDiaAt4 += floatval($fila['ObjDiarioAt4']);
                        }
                    }
                    
                    // Objetivo total acumulado: Acum. Vta At4 del último día con venta diaria (se mantiene igual)
                    foreach ($datosSucursal as $indice => $fila) {
                        if ($fila['VtaDiariaAt4'] !== null && floatval($fila['VtaDiariaAt4']) > 0) {
                            $ultimoIndiceConVentaAt4 = $indice;
                        }
                    }
                    if ($ultimoIndiceConVentaAt4 >= 0) {
                        $ultimoDiaConVentaAt4 = $datosSucursal[$ultimoIndiceConVentaAt4];
                        if ($ultimoDiaConVentaAt4['AcumVtaAt4'] !== null) {
                            $objetivoTotalAcumuladoAt4 = floatval($ultimoDiaConVentaAt4['AcumVtaAt4']);
                        }
                    }
                    
                    // Porcentaje de cumplimiento: (Objetivo total acumulado / Objetivo total del mes) * 100
                    $cumplimientoPorcentajeAt4 = 0;
                    if ($objetivoTotalDelMesAt4 > 0 && $objetivoTotalAcumuladoAt4 > 0) {
                        $cumplimientoPorcentajeAt4 = ($objetivoTotalAcumuladoAt4 / $objetivoTotalDelMesAt4) * 100;
                    }
                    ?>
                    <div class="kpi-cards">
                        <div class="kpi-card">
                            <div class="kpi-icon revenue">
                                <span>📊</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">Objetivo Total del Mes</div>
                                <div class="kpi-value" id="kpiObjetivoHastaAt4_<?php echo $codsuc; ?>"><?php echo number_format($objetivoTotalDelMesAt4, 0); ?></div>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-icon orders">
                                <span>🎯</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">Objetivo Total Acumulado</div>
                                <div class="kpi-value" id="kpiObjetivoAcumAt4_<?php echo $codsuc; ?>"><?php echo number_format($objetivoTotalAcumuladoAt4, 0); ?></div>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-icon atp">
                                <span>✓</span>
                            </div>
                            <div class="kpi-content">
                                <div class="kpi-label">% Cumplimiento Total</div>
                                <div class="kpi-value" id="kpiCumplimientoAt4_<?php echo $codsuc; ?>"><?php echo number_format($cumplimientoPorcentajeAt4, 2); ?>%</div>
                            </div>
                        </div>
                    </div>
                    </div>

                    <!-- Gráficos por Categoría -->
                    <div class="contenido-atributo visible" data-atributo="at1">
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="chart-section">
                                <h3 style="display: flex; align-items: center; gap: 8px;"><span>👟</span> Calzado</h3>
                                <div class="chart-container" style="height: 350px;">
                                    <canvas id="chartCalzado_<?php echo $codsuc; ?>"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                    <div class="contenido-atributo" data-atributo="at2">
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="chart-section">
                                <h3 style="display: flex; align-items: center; gap: 8px;"><span>👕</span> Indumentaria</h3>
                                <div class="chart-container" style="height: 350px;">
                                    <canvas id="chartIndumentaria_<?php echo $codsuc; ?>"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                    <div class="contenido-atributo" data-atributo="at3">
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="chart-section">
                                <h3 style="display: flex; align-items: center; gap: 8px;"><span>💼</span> Accesorio</h3>
                                <div class="chart-container" style="height: 350px;">
                                    <canvas id="chartAccesorio_<?php echo $codsuc; ?>"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                    <div class="contenido-atributo" data-atributo="at4">
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="chart-section">
                                <h3 style="display: flex; align-items: center; gap: 8px;"><span>🧦</span> Medias</h3>
                                <div class="chart-container" style="height: 350px;">
                                    <canvas id="chartMedias_<?php echo $codsuc; ?>"></canvas>
                                </div>
                            </div>
                        </div>
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

        // Función helper para crear gráficos (solo hasta el día actual)
        function crearGrafico(canvasId, datos, objField, vtaField, colorBarra, colorLinea) {
            const ctx = document.getElementById(canvasId);
            if (!ctx) return;

            const hoyStr = new Date().toISOString().substring(0, 10);
            datos = datos.filter(f => {
                const fecha = f.Fecha;
                if (fecha == null) return false;
                const fechaStr = typeof fecha === 'string' ? fecha.substring(0, 10) : (fecha.date ? fecha.date.substring(0, 10) : '');
                return fechaStr && fechaStr <= hoyStr;
            });

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

            // Gráfico Accesorio (At3)
            crearGrafico(
                `chartAccesorio_${codsuc}`,
                datos,
                'ObjDiarioAt3',
                'VtaDiariaAt3',
                'rgba(79, 172, 254, 0.8)',
                '#f5576c'
            );

            // Gráfico Medias (At4)
            crearGrafico(
                `chartMedias_${codsuc}`,
                datos,
                'ObjDiarioAt4',
                'VtaDiariaAt4',
                'rgba(240, 147, 251, 0.8)',
                '#f5576c'
            );
            
            // Gráficos compactos de últimos 7 días (uno por atributo)
            const mapAt = [
                { id: 'At1', obj: 'ObjDiarioAt1', vta: 'VtaDiariaAt1' },
                { id: 'At2', obj: 'ObjDiarioAt2', vta: 'VtaDiariaAt2' },
                { id: 'At3', obj: 'ObjDiarioAt3', vta: 'VtaDiariaAt3' },
                { id: 'At4', obj: 'ObjDiarioAt4', vta: 'VtaDiariaAt4' }
            ];
            const hoy = new Date();
            const ultimos7Calendario = [];
            for (let i = 7; i >= 1; i--) {
                const fecha = new Date(hoy);
                fecha.setDate(fecha.getDate() - i);
                const fechaStr = fecha.toISOString().substring(0, 10);
                const datoFecha = datos.find(d => {
                    const fd = d.Fecha;
                    if (typeof fd === 'string') return fd.substring(0, 10) === fechaStr;
                    if (typeof fd === 'object' && fd.date) return fd.date.substring(0, 10) === fechaStr;
                    return false;
                });
                ultimos7Calendario.push({ fecha: fechaStr, datos: datoFecha || null });
            }
            const labels7 = ultimos7Calendario.map(item => item.fecha.substring(5, 10));
            mapAt.forEach(({ id, obj, vta }) => {
                const ctx7 = document.getElementById(`chart7Dias_${id}_${codsuc}`);
                if (!ctx7) return;
                const objetivos7 = ultimos7Calendario.map(item => item.datos ? parseFloat(item.datos[obj]) || 0 : 0);
                const ventas7 = ultimos7Calendario.map(item => item.datos ? parseFloat(item.datos[vta]) || 0 : 0);
                new Chart(ctx7, {
                    type: 'line',
                    data: {
                        labels: labels7,
                        datasets: [
                            {
                                label: 'Objetivo',
                                data: objetivos7,
                                borderColor: '#f5576c',
                                backgroundColor: 'rgba(245, 87, 108, 0.1)',
                                borderWidth: 2,
                                borderDash: [5, 5],
                                tension: 0.3,
                                pointRadius: 4,
                                pointBackgroundColor: '#f5576c'
                            },
                            {
                                label: 'Venta',
                                data: ventas7,
                                borderColor: '#38ef7d',
                                backgroundColor: 'rgba(56, 239, 125, 0.1)',
                                borderWidth: 3,
                                tension: 0.3,
                                pointRadius: 5,
                                pointBackgroundColor: '#38ef7d',
                                fill: true
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: { color: '#e0e0e0', font: { size: 10 }, boxWidth: 10, padding: 5 }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: '#fff',
                                bodyColor: '#e0e0e0'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { color: '#e0e0e0', font: { size: 9 } },
                                grid: { color: 'rgba(255, 255, 255, 0.05)' }
                            },
                            x: {
                                ticks: { color: '#e0e0e0', font: { size: 8 } },
                                grid: { color: 'rgba(255, 255, 255, 0.05)' }
                            }
                        }
                    }
                });
            });
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

            // Selector de atributo: mostrar solo contenido del atributo elegido en la sucursal activa
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.atributo-btn');
                if (!btn) return;
                const section = btn.closest('.sucursal-section');
                if (!section) return;
                const atributo = btn.getAttribute('data-atributo');
                if (!atributo) return;
                section.querySelectorAll('.atributo-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                section.querySelectorAll('.contenido-atributo').forEach(bloque => {
                    bloque.classList.toggle('visible', bloque.getAttribute('data-atributo') === atributo);
                });
            });

            // Toggle de vista: objetivo de hoy vs rendimiento de ayer
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.vista-btn');
                if (!btn) return;
                const section = btn.closest('.sucursal-section');
                if (!section) return;
                const vista = btn.getAttribute('data-vista');
                if (!vista) return;
                section.querySelectorAll('.vista-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                section.querySelectorAll('.vista-bloque').forEach(bloque => {
                    bloque.classList.toggle('activa', bloque.getAttribute('data-vista') === vista);
                });
                if (vista === 'ayer') {
                    const target = section.querySelector('.vista-bloque[data-vista="ayer"]');
                    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    </script>
</body>
</html>
