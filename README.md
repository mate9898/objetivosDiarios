# 📊 Daily Sales Dashboard – Retail Branch Performance Tracker

Dashboard operativo de objetivos y ventas diarias por sucursal, desarrollado en PHP con integración a SQL Server.
Diseñado para retail multi-sucursal con seguimiento en tiempo real por atributo de producto,
permitiendo visualizar el estado de cumplimiento de cada punto de venta de forma clara y accionable.

---

## 📸 ¿Qué muestra?

| Panel | Descripción |
|---|---|
| 🎯 **Objetivo de Hoy** | Metas del día por atributo con estado operativo (En ritmo / En riesgo / Fuera) |
| 📅 **Rendimiento de Ayer** | Cumplimiento del último día con venta vs. semana anterior |
| 📊 **KPIs Mensuales** | Objetivo del mes, acumulado real y % de cumplimiento total |
| 📈 **Gráfico Últimos 7 Días** | Evolución diaria de objetivo vs. venta por atributo |
| 📉 **Gráfico Mensual** | Histórico completo del mes en curso por categoría |
| 🏆 **Ranking de Sucursales** | Posición de cada sucursal según cumplimiento acumulado |

---

## 🚀 Features

- Selector de sucursal con filtrado dinámico en tiempo real

- Desglose por atributo: 👟 Calzado, 👕 Indumentaria, 💼 Accesorio, 🧦 Medias

- Estado operativo del día con semáforo visual (🟢 En ritmo / 🟡 En riesgo / 🔴 Fuera de objetivo)

- Comparativa automática vs. semana anterior por cada atributo

- Indicador de tendencia basado en los últimos 6 días con venta

- Ranking de sucursales calculado dinámicamente por cumplimiento

- Gráficos interactivos con Chart.js (barras + línea de objetivo)

- Clasificación automática de sucursal por tipo (`shopping` vs `calle`) según columna `comercio`

---

## 🛠️ Stack

- **Backend:** PHP 8+ con extensión `sqlsrv`

- **Base de datos:** SQL Server — base `FAM450`

- **Frontend:** HTML5, CSS3, Bootstrap 5, Chart.js 3, JavaScript vanilla

- **Autenticación de sesión:** PHP Sessions

---

## 📁 Estructura del proyecto
```
/
├── index.php         # Dashboard principal
├── config.php        # Conexión a SQL Server
├── estilos.css       # Estilos complementarios
└── imagenes/
    └── favicon.webp
```

---

## ⚙️ Requisitos

- PHP 8.0+

- Extensión `php_sqlsrv` instalada y habilitada

- Tabla `[FAM450].[dbo].[ObjetivosDiario]` con las siguientes columnas:
```
codsuc, Fecha, Dia, comercio
ObjDiarioAt1, ObjDiarioAt2, ObjDiarioAt3, ObjDiarioAt4
VtaDiariaAt1, VtaDiariaAt2, VtaDiariaAt3, VtaDiariaAt4
AcumVtaAt1,   AcumVtaAt2,   AcumVtaAt3,   AcumVtaAt4
ObejtivoAt1,  ObejtivoAt2,  ObejtivoAt3,  ObejtivoAt4
```

---

## 🔄 Flujo de datos
```
index.php
  └── sqlsrv_query()
        └── SELECT * FROM [FAM450].[dbo].[ObjetivosDiario]
              ├── Agrupa por sucursal (codsuc)
              ├── Calcula KPIs, tendencia y ranking en PHP
              └── Serializa a JSON para los gráficos Chart.js en frontend
```

---

## 📌 Notas

> La lógica de tipo de sucursal se infiere automáticamente desde la columna `comercio`.
> Palabras clave `shopping`, `mall` o `centro` clasifican como shopping (abre domingos).
> El resto se clasifica como `calle` y se excluye del promedio dominical.
