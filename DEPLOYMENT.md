# Despliegue de GeoVisor CVC

## Requisitos

- PHP 8.2 o superior.
- Extensiones PHP: `json`, `mbstring`, `fileinfo`, `zip`, `curl`, `openssl`, `session`, `zlib`, `dom`, `libxml` e `iconv`.
- Python 3.11 o superior si el servidor ejecutara reportes/analisis desde backend.
- Permisos de escritura para `cache/`, `reports/` y `uploads/`.
- `shell_exec` habilitado para ejecutar los analizadores y generadores Python.
- Conservar `certs/cacert.pem`; las APIs HTTPS lo usan para validar certificados TLS.

## Web root

El document root del hosting debe apuntar a esta carpeta `mapa/`.

No publicar como archivos descargables las carpetas internas:

- `.vscode/`
- `analysis/`
- `cache/`
- `data/`
- `includes/`
- `scripts/`
- `uploads/`

El archivo `.htaccess` ya bloquea el acceso directo a esas rutas cuando Apache permite overrides.

## Configuracion privada

En produccion, configurar rutas privadas fuera del document root cuando sea posible:

- `GEOVISOR_AUTH_DB`: archivo JSON de usuarios.
- `GEOVISOR_OPENAI_KEY_FILE`: archivo privado con la API key.
- `GEOVISOR_AI_MEMORY_FILE`: archivo privado de memoria local de IA.
- `PYTHON_BIN`: ruta al Python del servidor, si no se detecta automaticamente.
- `DOC_PYTHON_BIN`: ruta al Python usado para reportes Word/PDF, si aplica.

## Desarrollo local

Desde VS Code usar la configuracion de debug:

- `Abrir mapa en Chrome`
- `Abrir mapa en Edge`

Ambas usan la tarea `PHP: servidor local mapa`, que ejecuta directamente:

```powershell
php -d upload_max_filesize=250M -d post_max_size=260M -d memory_limit=512M -d max_execution_time=300 -d max_input_time=300 -d display_errors=0 -d html_errors=0 -d log_errors=1 -S 127.0.0.1:8000 -t .
```

No se requiere archivo `.bat` ni script auxiliar. Solo es necesario que PHP este instalado y disponible como comando `php` en el PATH del sistema. Para subir GeoJSON grandes, el servidor local debe iniciarse con esos limites; si el servidor ya estaba abierto, cerrar el debug y volver a iniciarlo desde VS Code.

La carga temporal admite KML/KMZ de hasta 40 MB, incluidos archivos con `LineString`, `Polygon`, `Point`, `MultiGeometry` y rutas GPS `gx:Track`. En hosting, permitir al menos 180 segundos de ejecucion para KMZ grandes y 300 segundos para analisis/reportes.

## Actualizar capa de lineas de transmision

1. Reemplazar los archivos `Linea_transmision.*` en `data/shapes/Transmision/`.
2. Ejecutar:

```powershell
python scripts/convert_transmission_shp.py
```

3. Verificar que `data/geojson/LT_Operacionm.geojson` contenga los codigos esperados.
