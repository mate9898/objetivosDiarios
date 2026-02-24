📊 Daily Sales Dashboard – Retail Branch Performance Tracker
Dashboard operativo de objetivos y ventas diarias por sucursal, desarrollado en PHP con integración a SQL Server. Diseñado para retail multi-sucursal con seguimiento por atributo de producto.

🚀 Features

_Visualización de objetivos vs. ventas por sucursal, filtrable en tiempo real
_Desglose por atributo: Calzado, Indumentaria, Accesorios y Medias
_Vista operativa del día actual con estado (En ritmo / En riesgo / Fuera de objetivo)
_Análisis de rendimiento del día anterior con comparativa vs. semana previa
_Gráficos interactivos de los últimos 7 días y evolución mensual (Chart.js)
_KPIs de cumplimiento mensual acumulado por categoría
_Indicador de tendencia basado en los últimos 6 días con venta
_Ranking de sucursales por cumplimiento


🛠️ Stack

Backend: PHP 8+ con extensión sqlsrv
Base de datos: SQL Server (FAM450)
Frontend: HTML5, CSS3, Bootstrap 5, Chart.js 3
Autenticación de sesión: PHP Sessions

⚙️ Requisitos

PHP 8.0+
Extensión php_sqlsrv instalada y habilitada
Tabla [FAM450].[dbo].[ObjetivosDiario] con columnas: codsuc, Fecha, Dia, ObjDiarioAt1-4, VtaDiariaAt1-4, AcumVtaAt1-4, ObejtivoAt1-4, comercio


📌 Notas

La lógica de tipo de sucursal (shopping vs calle) se infiere automáticamente desde la columna comercio, afectando qué días se consideran hábiles en los promedios.
