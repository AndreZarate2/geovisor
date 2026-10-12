# Acceso al GeoVisor mediante Vercel

Estado: adaptador preparado; pendiente de configurar un servidor de origen activo,
confirmar un plan apto para el uso previsto y verificar el despliegue. Esta carpeta no migra el backend a
Vercel ni garantiza funcionamiento con la PC apagada.

## Funcionamiento

Vercel recibe las visitas y reenvia las solicitudes al servidor HTTPS definido en
`GEOVISOR_ORIGIN`. Ese servidor ejecuta el proyecto PHP/Python completo y conserva
usuarios, archivos cargados, analisis e informes en disco. La sesion y la
autorizacion siguen siendo responsabilidad del GeoVisor.

Puede utilizarse el equipo local a traves de un tunel HTTPS ya configurado. En
ese caso la PC, el servidor y el tunel deben permanecer activos. Un enlace
temporal puede cambiar y requerira actualizar la variable y volver a desplegar.
Tambien puede apuntarse a un servidor PHP/Python con disco persistente existente.

## Configuracion del proyecto de Vercel

1. Importar `AndreZarate2/geovisor`.
2. Establecer **Root Directory** en `deploy/vercel` y **Framework Preset** en `Other`.
3. Dejar desactivada la descarga Git LFS para este despliegue. Esta carpeta solo
   necesita el adaptador; los 8,6 GiB del proyecto se atienden desde el origen.
4. Definir `GEOVISOR_ORIGIN` con la URL HTTPS real del servidor, sin ruta final.
   No usar una URL de ejemplo ni una direccion localhost. No poner claves en esta variable.
5. Utilizar el comando de compilacion `node build.mjs` y la configuracion
   `vercel.json` incluida en esta carpeta. No seleccionar la raiz PHP del repositorio.
   No sobrescribir Output Directory: el script genera `.vercel/output` conforme
   a Build Output API. Las pruebas locales se ejecutan con `node --test build.test.mjs`.
6. Desplegar y comprobar el mapa, inicio de sesion, carga de un KMZ, lectura de
   Excel y generacion/descarga de un informe. Confirmar en el servidor que las
   modificaciones del administrador se guardan alli.

El build falla expresamente si no se configura el origen. No se publica una
pagina vacia ni se sirven los archivos PHP como texto. Las respuestas de la
aplicacion no se almacenan en la cache compartida del proxy. Se bloquean las
rutas internas que el proyecto protege mediante `.htaccess`; el servidor de
origen tambien debe aplicar esas protecciones.

## Limites y costos

No se contrata ningun servicio ni se activa un plan con estos archivos.
Vercel Hobby esta limitado a uso personal no comercial. Para uso empresarial,
comprobar la elegibilidad del plan existente antes de desplegar; no se ha
contratado Pro ni se ha activado una prueba de pago.

Las solicitudes reenviadas por Vercel tienen un limite de 120 segundos. Analisis
que superen ese tiempo necesitan responder mediante el flujo de trabajos y
consulta de estado del backend; deben probarse los reportes largos y los
archivos grandes en el entorno final. No se promete disponibilidad permanente
mediante un tunel temporal.

GitHub LFS conserva los documentos grandes en el repositorio, pero tiene cuotas
propias de almacenamiento y transferencia. Esas cuotas son independientes de
Vercel y de la API de OpenAI.

Referencias: [rewrites externos](https://vercel.com/docs/routing/rewrites),
[Build Output API](https://vercel.com/docs/build-output-api/configuration),
[limites](https://vercel.com/docs/limits),
[plan Hobby](https://vercel.com/docs/plans/hobby).
