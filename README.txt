╔══════════════════════════════════════════════════════╗
║   SIG ENERGÉTICO-MINERO · OSINERGMIN PERÚ            ║
║   Sistema de Información Geográfica Energética       ║
╚══════════════════════════════════════════════════════╝

CREDENCIALES DE ACCESO
  Configurar los usuarios en el archivo privado indicado por GEOVISOR_AUTH_DB.
  Las credenciales no se publican en el repositorio.

INSTALACIÓN EN FEROZO
────────────────────────────────────────────────────────
1. Descomprimir ZIP en: public_html/mapa-energetico/
2. Permisos (via cPanel → Administrador de archivos):
     uploads/        → 755
     uploads/excel/  → 755
     cache/          → 755
     data/           → 755 (solo lectura)
3. Python (SSH):
     pip3 install pandas openpyxl xlrd --user
4. Acceder: https://tudominio.com/mapa-energetico/

LOGO CVC ENERGÍA
────────────────────────────────────────────────────────
Colocar el archivo 1.jpg en la RAÍZ del proyecto
(mismo nivel que index.php)

ESTRUCTURA DEL PROYECTO
────────────────────────────────────────────────────────
/
├── index.php          ← Archivo principal (login + mapa)
├── config.php         ← Configuración
├── upload.php         ← Panel de administración
├── logout.php
├── .htaccess
├── 1.jpg              ← LOGO CVC (agregar manualmente)
├── api/
│   ├── auth.php       ← AJAX login
│   ├── get_layer.php  ← Servir GeoJSON
│   └── analyze.php    ← Análisis de carga
├── analysis/
│   ├── load_analyzer.py
│   └── requirements.txt
├── assets/css/style.css
├── assets/js/app.js
├── data/geojson/      ← 9 capas GeoJSON incluidas
└── uploads/excel/     ← Excel de análisis (subir desde admin)

CAPAS GeoJSON INCLUIDAS
────────────────────────────────────────────────────────
SET_Operacion.geojson                 (728 subestaciones)
SET_Proyectadas.geojson               (66 proyectos)
LT_Operacionm.geojson                 (947 líneas)
LT_Proyectadas.geojson                (103 líneas)
SED_Esc_1_30000.geojson               (3000 SED)
SED_Esc_1_9000.geojson                (3000 SED)
Salida_MT_SET.geojson                 (3000 puntos)
Tramo_media_tension.geojson           (1000 tramos)
Concesiones_Distribucion_marzo_2026.geojson (2000 áreas)
