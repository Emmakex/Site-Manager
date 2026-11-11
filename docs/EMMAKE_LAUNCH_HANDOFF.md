# EMMAKE — entrega de la web nueva y migración de /nuevaweb a emmake.com

**Estado de trabajo, 11/11/2026. NO ES UN ACTA DE ENTREGA.**

La migración inicial del contenido del antiguo WordPress a
`https://emmake.com/nuevaweb/` **ya se completó** en fases anteriores.
No volver a ejecutar Migration Bridge ni reemplazar de nuevo ese contenido.

El pendiente es el **cambio final de la instalación nueva al dominio
principal** `https://emmake.com/`, conservando su historia de URLs,
contenidos, medios, datos de formularios, SEO y retorno operativo.

## Lo que ya conocemos con evidencia

- Sitio de pruebas: `emmake-nuevaweb`, plugin Site Manager **0.11.7**.
  MCP OVH con lectura pública verificada y Gateway funcional.
- [Inventario WordPress verificado](../commands/site-manager/results/cmd-emmake-published-targets-20261111-01.json):
  12 páginas publicadas (sin truncar), 40 entradas de blog **truncadas**,
  portada WordPress **ID 7**.
- Páginas prioritarias: Inicio 7, Servicios 54267, Casos de éxito 54268,
  Contacto 50085, Sobre Nosotros 50097, Nuestras Marcas 50089,
  Blog 50093. Conservar también Política de privacidad y las páginas
  adicionales antes de decidir redirecciones o bajas.
- La web antigua sigue siendo la que el público encuentra en
  `https://emmake.com/` y su blog tiene URLs antiguas bajo `/blog/`.
  **No asumir que la nueva web puede sustituirlas sin redirecciones**.
- **Bloqueador #75:** el inventario devuelve permalinks de publicaciones
  con marcadores de WordPress sin resolver (`%category%/%postname%`
  u otra representación con hash). Estos enlaces no pueden entrar en un
  sitemap, canonical, redirect map ni botón de navegación hasta
  certificar su resolución real.
- En pruebas, WordPress informó `search_visibility=discourage`;
  conservarlo durante staging. Cambiar esa opción es una operación
  separada en la ventana de publicación.

## Fase A: terminar la web antes del cambio público

1. **Inspección/editorial.** Leer y revisar portada y las 12 páginas
   publicadas. Confirmar los mensajes, CTA, pruebas sociales reales,
   jerarquía H1/H2, enlaces, imágenes/ALT, bloques y coherencia de marca.
   Los textos y las citas de clientes deben ser auténticos.
2. **UX responsive.** Revisar página a página desktop, móvil y tablet:
   navegación, contraste, foco de teclado, formularios, footer, menú,
   CLS, imágenes optimizadas, enlaces internos y estados vacíos.
   Mantener evidencia de capturas y pruebas por URL.
3. **Blog.** Corregir la causa exacta de los permalinks, no solo la
   representación del informe; comprobar la URL de una entrada
   publicada con HTTP público, canonical y sitemap. Inventariar **todas**
   las entradas, no solo la muestra de 40.
4. **Gobernanza.** Completar vista previa privada de cambios de páginas,
   permiso/confirmación explícita, plan/apply/verify/rollback y crear/
   publicar nuevos artículos sin exponer cuerpos de texto en GitHub.
5. **SEO/GEO.** Title y description originales/candidatos, un solo
   canonical por URL, robots, datos estructurados representativos,
   favicon/OG, sitemap, redirects, hreflang si aplica, 404 y enlaces
   internos; evitar noindex accidental tras el lanzamiento.
6. **Contacto y legal.** Confirmar entregas reales de formulario,
   consentimiento/privacidad, accesibilidad, protección antispam y
   enlaces legales. Una página que solo muestra el formulario no es
   evidencia de que los mensajes lleguen.

Estas fases necesitan pruebas reales, no solo CI de código.

## Fase B: backup y mapping de contenido/SEO

**No ejecutar el corte sin evidencia verificable de cada punto:**

- Backup completo de **archivos y base de datos** del WordPress
  actualmente público en `emmake.com`, con ubicación de recuperación,
  fecha y comprobación de integridad.
- Backup completo e independiente de archivos y base de datos del
  WordPress nuevo en `/nuevaweb`.
- Prueba de restauración practicada en un destino aislado.
- Censo completo de URLs públicas antiguas (páginas, entradas,
  categorías, imágenes relevantes, formularios, feeds, sitemap)
  y nuevas. Mantener rutas iguales cuando sea posible.
- Mapa de migración **vieja URL → nueva URL** (301 únicamente si el
  recurso ha cambiado de ruta; no 301 masivos a portada). Incluir
  casos 404/410 intencionados y evitar cadenas/loops.
- Plan de congelación editorial: no permitir nuevas publicaciones ni
  envíos críticos durante el intercambio de archivos/BD.
- Plan de rollback y persona autorizada con acceso al hosting, backups,
  BD, configuración del dominio, caché/WAF y DNS si hace falta.

La auditoría de datos y el manifiesto inicial están en
`scripts/emmake_release_audit.py` y
`ops/emmake/release-gates.json`.
Todos los gates están inicialmente **pending**. Solo cambiar a
`verified` con una referencia a evidencia real; el estado
`verified` no se infiere de un test o de un checkbox.

## Fase C: cutover técnico al dominio principal (requiere Hostinger)

**Operación de infraestructura, NO una acción de contenido WordPress del
Gateway.** El operador del hosting deberá disponer de acceso
autenticado y autorizado. Site Manager no implementa un proxy de
archivos, BD o PHP arbitrario.

Procedimiento técnico controlado:

1. Guardar backups independientes y verificar la restauración.
   Anotar estado anterior de document root, rutas y configuración
   WordPress, además del punto de retorno.
2. Congelar cambios editoriales, formularios y cachés relevantes.
3. Sustituir la web antigua por la nueva con el método compatible
   con la instalación de Hostinger: **no** copiar archivos encima
   del WordPress anterior ni mezclar tablas de bases de datos.
   Definir previamente si se mueve la instalación o se cambia el
   document root/servidor. Si son WordPress separados, el swap debe
   conservar una copia íntegra de ambos.
4. Corregir de forma compatible con datos **serializados**
   `home`, `siteurl`, enlaces internos, medios, referencias a
   `/nuevaweb` y reglas de rewrite. Una búsqueda/reemplazo SQL
   ciega puede corromper opciones serializadas. Usar las herramientas
   de migración compatibles de WordPress con `dry-run`/backup y
   alcance delimitado, no una mutación sin control.
5. Actualizar el routing, estructura de enlaces permanentes y el
   árbol de media. Aplicar 301 exclusivamente del mapa verificado.
   Regenerar sitemap y cachés, purgar CDN/WAF si procede.
6. Revisar plugins/theme y sus URLs base. Revalidar la conexión de
   Site Manager: el contexto `site-manager-emmake-nuevaweb` está
   vinculado a la URL/instalación antigua y **no puede asumirse
   operativo tras el traslado**. Reapuntar y volver a certificarlo
   con el mismo contrato de identidad, sin copiar secretos a Git.
7. Si la redirección, validación o los formularios fallan, **ejecutar
   el rollback** de document root/BD y restaurar caché/URLs
   anteriores; comprobar home/contacto/blog antiguos.

No alterar robots/indexabilidad durante el swap hasta que las
comprobaciones post-cutover pasen. No enviar un sitemap nuevo
mientras haya canonical/URL erróneos.

## Fase D: aceptación post-cutover

Repetir el inventario y pruebas HTTPS bajo `https://emmake.com/`:

- portada, todas las páginas principales, mínimo varias entradas de
  blog, imágenes, formularios, breadcrumbs, 404 y redirecciones;
- canonical, robots, sitemap, structured data, OG, enlaces internos,
  ausencia de `/nuevaweb` en elementos públicos;
- objetivos de rendimiento, accesibilidad y UX responsive;
- consentimiento/contacto real y envío/recepción verificable;
- conexión Site Manager en la **nueva identidad**, capacidad de
  lectura y primera operación editorial gobernada y reversible;
- levantar `discourage` solo tras el QA y con la estrategia de
  lanzamiento acordada; revisar y enviar sitemap a Search Console;
- acta de entrega con resultados, copias de respaldo, rollback y
  pendientes conocidos si los hubiera.

**Criterio de 100 %:** todos los gates con evidencia, web pública nueva
operativa, URLs y SEO preservados, 3xx/4xx intencionados, formularios
y QA reales aprobados, y Site Manager gobernando el dominio final.
No confundir despliegue de MCP, versión WordPress o éxito de Actions
con entrega real.

## Preflight read-only desde GitHub

El workflow `EMMAKE Read-only Launch Preflight` recoge únicamente:

1. Informe basado en los resultados **ya sanitizados** del Gateway,
   con bloqueos explícitos.
2. Metadatos públicos HTTP de URLs fijas de la web antigua y nueva.
   No sigue redirecciones ni almacena cuerpo HTML, tokens o datos
   enviados por formularios.

El workflow no puede mover el WordPress ni autorizar el cutover.
Su resultado sirve para planificar el siguiente arreglo sin romper
la web pública.
