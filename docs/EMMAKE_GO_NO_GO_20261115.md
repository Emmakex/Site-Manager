# Emmake — Go / No-Go de migración (15-11-2026)

**Destino previsto:** `https://emmake.com/nuevaweb/` → `https://emmake.com/`.
**Decisión:** **NO-GO por ahora.** Este informe no ejecuta ni autoriza el cambio de dominio.

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
| Ruta de blog | Comparación de URL pública | Una URL histórica `/blog/<slug>/` funciona (200); bajo `/nuevaweb/blog/<slug>/` se observa 301 hacia una URL interna del staging con el mismo slug |
| Web antigua | Descubrimiento | Homepage y entrada muestreada indexables, con canonical, Open Graph y JSON-LD |
| Portada nueva | Auditoría SEO | Longitud de `<title>`: 209 caracteres. Revisar título antes del lanzamiento |

## Bloqueos de lanzamiento

1. **Página antigua sin equivalencia:** la calculadora de donativos devuelve 200 en la instalación antigua y 404 en la nueva. Conservarla o aprobar su retirada con una respuesta HTTP deliberada y política de redirección si corresponde; nunca redirigir indiscriminadamente al inicio.
2. **Paridad de permalinks:** validar el inventario completo de 1.726 posts, no solamente el recuento. Preservar preferentemente las rutas históricas `/blog/<slug>/`, o generar un mapa probado de 301 uno a uno. Comprobar referencias a adjuntos, categorías y páginas.
3. **Backup y rollback:** exigir copia íntegra y verificada del WordPress actual (base de datos, uploads, configuración, tema, plugins y reglas de servidor) y una recuperación ensayada antes de sustituir el dominio principal.
4. **SEO indexable post-cambio:** activar visibilidad pública solo al terminar la migración, eliminar `noindex` de la nueva web, y comprobar canonical único con URL definitiva, Open Graph, datos estructurados coherentes, `robots.txt`, `sitemap_index.xml` o sitemap nativo, y `llms.txt` en el dominio final.
5. **Portada y calidad:** ajustar el `<title>` excesivo sin cambiar el H1 visible; asegurar CI de rendimiento aprobado y comprobar carga real en producción.
6. **Contacto y seguimiento:** probar envío controlado del formulario, enlaces y CTA, política de privacidad, HTTPS, y las comprobaciones de navegación/visual inmediatamente después del cambio.

## Procedimiento recomendado

- **Preflight:** resolver los seis bloqueos, inventariar 1.726 enlaces, fotografiar la configuración actual, fijar ventana de cambio y copia restaurable.
- **Cutover:** solo con autorización explícita y plan reversible; aplicar cambio de URL base, migración de datos y rutas, limpiar cachés y regenerar reglas de enlaces permanentes/sitemap.
- **Verify (sin asumir éxito):** visitar URLs antiguas y nuevas, exigir 200/301 deliberados y ningún 404 inesperado; repetir SEO/GEO y pruebas de escritorio/móvil; comprobar que el dominio final es indexable y `/nuevaweb` deja de competir en buscadores.
- **Rollback:** restaurar datos y rutas de la web previa si falla cualquiera de las condiciones críticas. Conservar evidencias y backups hasta cerrar aceptación.

**Nota:** que el tema tenga correctamente implementadas funciones SEO/GEO no acredita por sí solo sus señales de producción mientras el staging esté configurado como `noindex`. Tampoco acredita la equivalencia de URLs históricas el hecho de que dos instalaciones tengan el mismo número de entradas.
