# Emmake — Go / No-Go de migración (15-11-2026)

**Destino previsto:** `https://emmake.com/nuevaweb/` → `https://emmake.com/`.
**Decisión actualizada (16-11-2026): GO para preparar el corte de dominio.** El respaldo está certificado por el propietario; el traslado aún no se ha ejecutado.

**Actualización 16-11-2026 (incluye confirmación de backups diarios por el propietario):** [Runbook de corte Hostinger con backup y rollback](./EMMAKE_CUTOVER_RUNBOOK_20261116.md); [preflight SSH solo lectura](../scripts/emmake-hostinger-preflight.sh) (CI aprobado). El plugin Migration Bridge no traslada por sí mismo el WordPress entre directorios. La raíz actual sigue sin cambios. La copia diaria ha sido certificada por el propietario. El paso operativo pendiente es ejecutar el preflight y el traslado controlado en Hostinger, con verificación posterior de indexación, formularios y rutas.

## Evidencia revisada

| Área | Evidencia | Resultado |
| --- | --- | --- |
| Sitio WP | `cmd-emmake-launch-diagnostics-20261115-01` | HTTPS, permalinks y REST correctos; el staging conserva `search_visible=false` |
| Menú interior móvil | [QA 43699621781](https://github.com/Emmakex/Site-Manager/actions/runs/43699621781) | Nueve páginas, 390/430 px, 27/27 pruebas superadas |
| Imágenes interiores | `audits/emmake/inner-media/results/visual-inner-emmake-20261114-01.json` | 9 imágenes WebP, 18/18 verificaciones |
| Metadatos SEO del staging | [QA 43700632788](https://github.com/Emmakex/Site-Manager/actions/runs/43700632788) | 10/11 URL 200; metadescripción y 1 H1 en las diez; la calculadora devuelve 404 |
| Política del staging | Misma auditoría + `site.diagnostics` | `noindex` intencionado. Canonical, Open Graph y JSON-LD suprimidos en estas páginas no indexables |
| GEO | [Descubrimiento 43700972091](https://github.com/Emmakex/Site-Manager/actions/runs/43700972091) | `/nuevaweb/llms.txt` sirve contenido; robots y sitemaps del staging devuelven 404 mientras la indexación está desactivada |
| Contenido publicado | Mismo descubrimiento | 1.726 entradas y 10 páginas publicadas en cada instalación |
| Identidad de páginas | [Comparación de slugs 43701239241](https://github.com/Emmakex/Site-Manager/actions/runs/43701239241) | Antigua: `calculadora-de-donativos`; nueva: `servicios`; 9 slugs compartidos |
| Rutas de artículos | [Paridad tras el despliegue 43702916067](https://github.com/Emmakex/Site-Manager/actions/runs/43702916067) + [HTTP 43704144126](https://github.com/Emmakex/Site-Manager/actions/runs/43704144126) | **Corregido en staging**: 1.725 slugs idénticos con 0 diferencias de ruta; 1 normalizado recibe 301. El histórico real son 1.702 enlaces `/blog/` (dos slugs percent-encoded) y 24 excepciones bajo categorías, no 24 URLs de raíz. HTTP 31/31 |
| Web antigua | Descubrimiento | Homepage y entrada muestreada indexables, con canonical, Open Graph y JSON-LD |
| Portada nueva | [QA posterior al despliegue 43712323106](https://github.com/Emmakex/Site-Manager/actions/runs/43712323106) + `cmd-emmake-home-seo-title-apply-20261115-01` | **Corregido**: título HTML de 209 a 65 caracteres; `EMMAKE | Estrategia digital, tecnología e inteligencia artificial`; H1 único, 10 páginas activas en staging, `noindex` preservado |
| Rendimiento del tema | [Lighthouse CI 43701537269](https://github.com/Emmakex/template-wordpress-seo-geo/actions/runs/43701537269) | Fixture corporativa 100/100, LCP ~753 ms, CLS 0, CSS 9.855 bytes con límite explícito 10 KiB; no son Core Web Vitals de usuarios reales |
| CSS móvil optimizado | `cmd-emmake-precutover-css-opt-apply-20261115-01` y [QA móvil reejecutada](https://github.com/Emmakex/Site-Manager/actions/runs/43697116481) | Site Manager verificó el árbol instalado; menú de home aprobado a 390 y 430 px, contraste 11,37:1 y cierre correcto |

## Bloqueos de lanzamiento

1. **Calculadora de donativos — exclusión aprobada por el propietario:** no se migrará; la ausencia de esta página en la nueva web es deliberada. No inventar redirecciones ni bloquear el proyecto por esta página.
2. **URLs de artículos — cerrado en staging:** el tema conserva `/blog/%postname%/` y las 24 rutas históricas por categorías. Los 1.725 slugs coincidentes tienen paridad exacta; el único slug normalizado recibe 301 hacia su enlace limpio. La auditoría HTTP ha pasado 31/31. Durante el cambio al dominio principal, verificar que continúe el mismo comportamiento sin `/nuevaweb` y revisar aparte adjuntos, taxonomías y paginación.
3. **Backup y rollback — CERTIFICADO POR EL PROPIETARIO (16-11-2026):** Hostinger realiza copias de seguridad diarias y el propietario ha confirmado su certificación para esta migración. Se da por cerrado este punto: no pedir nuevas pruebas ni construir otro sistema. El plan de rollback sigue usando esos respaldos si el cambio de raíz falla.
4. **SEO indexable post-cambio:** activar visibilidad pública solo al terminar la migración, eliminar `noindex` de la nueva web, y comprobar canonical único con URL definitiva, Open Graph, datos estructurados coherentes, `robots.txt`, `sitemap_index.xml` o sitemap nativo, y `llms.txt` en el dominio final.
5. **Portada y calidad — título cerrado:** `<title>` de portada corregido a 65 caracteres sin tocar H1 ni contenido; CI de rendimiento aprobada. Comprobar Core Web Vitals reales tras la migración.
6. **Contacto y seguimiento:** probar envío controlado del formulario, enlaces y CTA, política de privacidad, HTTPS, y las comprobaciones de navegación/visual inmediatamente después del cambio.

## Procedimiento recomendado

- **Preflight:** resolver los pendientes aún abiertos, conservar la evidencia de los 1.726 enlaces, fotografiar la configuración actual, fijar ventana de cambio y copia restaurable.
- **Cutover:** solo con autorización explícita y plan reversible; aplicar cambio de URL base, migración de datos y rutas, limpiar cachés y regenerar reglas de enlaces permanentes/sitemap.
- **Verify (sin asumir éxito):** visitar URLs antiguas y nuevas, exigir 200/301 deliberados y ningún 404 inesperado; repetir SEO/GEO y pruebas de escritorio/móvil; comprobar que el dominio final es indexable y `/nuevaweb` deja de competir en buscadores.
- **Rollback:** restaurar datos y rutas de la web previa si falla cualquiera de las condiciones críticas. Conservar evidencias y backups hasta cerrar aceptación.

**Nota:** que el tema tenga correctamente implementadas funciones SEO/GEO no acredita por sí solo sus señales de producción mientras el staging esté configurado como `noindex`. Tampoco acredita la equivalencia de URLs históricas el hecho de que dos instalaciones tengan el mismo número de entradas.
