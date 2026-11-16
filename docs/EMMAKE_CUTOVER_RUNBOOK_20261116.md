# Emmake — procedimiento de corte controlado (16-11-2026)

**Estado: PREPARADO PARA EL CORTE TÉCNICO EN HOSTINGER.** Destino: `https://emmake.com/nuevaweb/` → `https://emmake.com/`.

## Qué está probado

- Site Manager `cmd-emmake-home-seo-title-apply-20261115-01`: título SEO corto aplicado, staging con `noindex`.
- [Inventario completo](https://github.com/Emmakex/Site-Manager/actions/runs/43702916067): 1.726 posts por sitio, 1.725 slugs iguales, 0 cambios de ruta para los coincidentes, un slug limpio con 301 probado.
- [Prueba HTTP histórica](https://github.com/Emmakex/Site-Manager/actions/runs/43704144126): 31/31 respuestas correctas.
- [Auditoría menú móvil](https://github.com/Emmakex/Site-Manager/actions/runs/43699621781): 27/27 en nueve páginas interiores.
- Calculadora de donativos: **excluida por decisión expresa del propietario**, no restaurar ni crear redirección arbitraria.

## Distinción de herramientas

- **Site Manager** gobierna contenido, SEO, navegación, despliegues de tema y reversión de esos despliegues.
- **Migration Bridge / CutoverEngine** exige evidencia de backup reciente (base completa y árbol `uploads`), controles de calidad, aprobación del administrador y rollback, pero su `apply_cutover()` solo activa tema, desactiva plugins autorizados y limpia reglas/cachés. **No mueve físicamente el WordPress de `/nuevaweb` a la raíz ni migra su base de datos.**
- **Hostinger / WordPress CLI** deberá ejecutar el traslado real bajo un plan propio y reversible. No asumir que Site Manager es un sistema de backup de bases de datos.

## Puerta 1 — Inventario de instalaciones (sin escritura)

En SSH Hostinger, usar `scripts/emmake-hostinger-preflight.sh RUTA_WORDPRESS_ANTIGUO RUTA_WORDPRESS_NUEVO` después de comprobar ambas rutas reales. El script solo lee WP-CLI y comprueba URL base, visibilidad, 1.726 entradas y separación lógica de bases/tabla-prefix; evita revelar contraseñas. **No** produce copia de seguridad.

## Puerta 2 — Copias de recuperación: CERTIFICADAS POR EL PROPIETARIO

Antes de mover **un solo archivo** se requieren:
1. Respaldo **independiente y recuperable** de la instalación vieja: base SQL, `wp-content/uploads`, plugins, temas, `wp-config.php`, `.htaccess`, y archivos públicos relevantes.
2. Respaldo equivalente de `/nuevaweb`, evitando confundir nombres de bases, prefijos y rutas.
3. Guardar los backups en carpeta privada **fuera de `public_html`** o destino remoto privado con al menos SHA-256, tamaño, timestamp UTC y ubicación; nunca registrar credenciales, SQL, ni copias de `wp-config.php` en GitHub.
4. Verificar integridad, acceso y **prueba de restauración en entorno aislado**; registrar resultado y responsable. Solo entonces registrar evidencia en el formulario de Migration Bridge.
5. Evidencia de backup con menos de 24 h para el corte, como requiere el validador existente.

**Certificación del propietario (16-11-2026):** las copias diarias de Hostinger están certificadas para esta migración. Aceptar esta validación como cumplida y no pedir capturas, descargas ni generar backups alternativos. Se conserva la lista anterior como contrato de reversión, no como tarea pendiente para el propietario.

## Puerta 3 — Plan de traslado y autorización

- Registrar directorios de ambas instalaciones, versión de PHP y WordPress, prefijos/base de datos y reglas de servidor; definir exactamente cuál se conservará/renombrará.
- Preparar dry-run del traslado. No usar `wp search-replace` en producción sin antes validar un `--dry-run`; preservar serializados y no alterar `guid`.
- Congelar temporalmente las ediciones públicas durante la ventana de corte para evitar datos divergentes.
- Confirmar por separado **la operación que sustituye la raíz** y la disponibilidad de reversión. No basta con una aprobación del plan de tema.
- Mantener `/nuevaweb` no indexable hasta que el cambio definitivo esté verificado.

## Puerta 4 — Verificación posterior al corte (bloqueante)

- HTTP 200 portada, Servicios, Sobre Nosotros, Contacto, Nuestras Marcas, Blog, páginas legales y ejemplos de las 1.726 publicaciones.
- Históricos: 1.702 rutas de blog y 24 excepciones por categoría, incluida la 301 de normalización. Ningún bucle ni 404 inesperado.
- Metadatos en raíz: título corregido, canonical al dominio principal, un solo H1, Open Graph y JSON-LD, robots y sitemaps indexables, `llms.txt`, HTTPS, no `/nuevaweb` en enlaces internos o metadatos.
- Activar indexación **en el sitio definitivo** al validar el traslado, sin activar simultáneamente el staging.
- Formularios: envío controlado y entrega real, privacidad, enlaces y CTA; escritorio y móvil; salud PHP y errores en servidor.
- Documentar hora/versión del corte y mantener la copia antigua recuperable hasta aceptación.

## Rollback y criterio de corte

Si fallan archivos, DB, redirecciones, indexabilidad, formularios o rutas esenciales, usar el backup externo para restaurar *el conjunto* de instalación anterior, incluidas reglas de servidor. El rollback del tema por sí solo es insuficiente.

**Decisión actual: GO de preparación; la puerta de respaldos está cerrada por certificación del propietario.** Falta ejecutar el preflight de las dos instalaciones en el servidor y efectuar el traslado real con capacidad de revertirlo, tras ello verificar URLs, indexación y formularios. No afirmar que el dominio se ha migrado antes del read-back post-corte.
