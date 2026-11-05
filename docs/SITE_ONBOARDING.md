# SM-4K — Alta protegida de una web WordPress nueva

Esta fase resuelve el bloqueo de SM-4J: el Gateway ordinario **no permite
consultar** un `site` que todavía no esté en el catálogo; el catálogo,
a su vez, requiere una prueba `site.overview` real y verificada. El nuevo
bootstrap es un **workflow manual distinto, solo de lectura**, nunca una
excepción dentro del Gateway ordinario.

## Modelo de conexión

El cliente **no necesita GitHub**. Instala el plugin WordPress Site Manager,
autoriza una conexión administrativa con al menos `site.read` y entrega
el acceso a la infraestructura MCP del operador por los canales de
configuración existentes. El operador provisiona por separado un GitHub
Environment protegido por tenant (sin usar repositorio GitHub en la web
del cliente). No se envían tokens ni claves en el chat, comandos pendientes,
archivos JSON o PRs.

La cuenta/operador que lanza el workflow debe ser la cuenta `Emmakex`,
desde la rama `main` y en **primera ejecución**. Históricos re-runs,
forks, ramas distintas y comandos de contenido/nav/SEO no participan.
La protección y revisores del Environment, cuando proceda, siguen
siendo un control humano propio de GitHub.

### Configuración previa obligatoria (fuera del repositorio)

Crear y proteger el GitHub Environment
`site-manager-<logical-site-id>`, usando un identificador de 3–64
caracteres (solo minúsculas, números y guiones). Debe tener:

| Campo | Tipo | Función |
|---|---|---|
| `SITE_MANAGER_SITE_ID` | Variable | Debe coincidir exactamente con el ID pedido |
| `SITE_MANAGER_MCP_BASE_URL` | Variable | HTTPS MCP registrado, sin parámetros ni credenciales |
| `SITE_MANAGER_MCP_TOKEN` | Secreto | Token de esa conexión, **nunca** de otra web |
| `SITE_MANAGER_EXPECTED_SITE_URL` | Variable | URL HTTPS de WordPress que el administrador espera administrar |
| `SITE_MANAGER_EXPECTED_CONNECTION_ID` | Secreto | Identificador `smc_...` confirmado en la conexión autorizada del cliente |

La URL esperada y el ID de conexión se contrastan contra los datos
**devueltos por la lectura remota real**. No basta con acertar el
`SITE_MANAGER_SITE_ID`, y un token de otro tenant no puede confirmar
la URL y el ID esperados. La ausencia de variables o secretos provoca
fallo cerrado, no aprovisionamiento de un Environment vacío.

El WordPress debe tener instalado Site Manager con un MCP de operador
compatible y permitir `site.read`. El flujo no exige conceder
`content.write`, `navigation.write` ni `seo.write`.

## Alta paso a paso

1. Configurar y verificar el WordPress del cliente, MCP y Environment
   protegido. La selección de URL/token/connection ID se hace **antes**
   del bootstrap y fuera de sus parámetros.
2. Lanzar desde GitHub **Site Manager Protected Site Bootstrap**,
   manualmente en `main`, pasando **solo** el ID lógico:
   `gh workflow run "Site Manager Protected Site Bootstrap" --repo Emmakex/Site-Manager --ref main -f site=<logical-site-id>`.
3. El workflow valida actor, ref, primera ejecución, identificador y
   entorno protegido. Llama exclusivamente a `site_read` sin argumentos.
   Proyecta `site.overview`, comprueba proveedor WordPress, versión de
   plugin, `site.read`, identidad de la conexión, URL y fingerprint.
   No hay endpoints personalizables ni herramienta MCP elegida por el
   usuario.
4. Si pasa, genera `cmd-bootstrap-<run-id>.json` como
   **`site.overview`** verificable, con:
   `status=success`, `verified=true`, `stage=read`,
   `required_scope=site.read`, `data.source=live-site-read` y
   metadatos `bootstrap` sobre la identidad protegida y el run.
   Guarda solo el resumen sanitario de lectura, nunca el resultado
   bruto de WordPress, bearer token, contraseña o rutas internas.
   El workflow conserva artefacto y prueba inmutable en
   `commands/site-manager/results/`. Si falla, no inscribe el cliente.
5. El operador revisa el JSON de resultado y propone, en un PR
   independiente, añadir exactamente una fila a
   `config/site-manager-site-catalog.json`:
   `{"site":"<logical-site-id>","label":"<nombre comercial>","proof_command_id":"cmd-bootstrap-<run-id>"}`.
   El catálogo valida identidad, origen protegido, estado, prueba,
   conexión, URL, run y alcance; nunca crea grants.
6. Integrar **solo** con CI exact-head verde, sin drift, merge y checks
   post-merge verdes. El Gateway estándar ya puede seleccionar ese ID
   desde el catálogo.
7. Ejecutar un `site.overview` **nuevo** por el Gateway ordinario.
   Comparar el sitio y la conexión con la prueba inicial. La nueva
   lectura, no el registro histórico, indica si la web sigue
   accesible. Mostrar permisos de escritura como *no verificados*
   hasta cada solicitud real de plan/apply.

## Revocación, rotación y desconexión

- **Revocar acceso:** revocar el token MCP de ese WordPress/tenant y
  desactivar o borrar los secretos del Environment. El catálogo
  puede conservar una entrada histórica, pero una lectura nueva fallará;
  no deberá presentarse como conexión activa.
- **Desregistrar:** retirar la fila del catálogo mediante PR revisado y
  CI; el resolver y el runner rechazarán nuevas operaciones de ese ID,
  independientemente del estado del WordPress.
- **Rotar token:** rotarlo en WordPress/MCP y después actualizar
  únicamente el secreto protegido de GitHub; no modificar históricos
  ni copiar tokens a mensajes.
- **Desinstalar:** revocar primero el token, comprobar que la conexión
  ya no está operativa, y desinstalar/desactivar el plugin en WordPress
  según el procedimiento del cliente. El repositorio no ejecuta una
  desinstalación remota de otro sitio.

## Límites actuales

- Es un **bootstrap de lectura**, no un formulario de autoservicio,
  facturación, contratación, onboarding desatendido ni admin universal.
  La preparación de WordPress/MCP/GitHub Environment sigue siendo
  una tarea de operador y requiere acceso legítimo a cada sistema.
- El workflow no puede crear ni configurar Environments protegidos.
  Un Environment nuevo sin credenciales requeridas no pasa.
- Un resumen anterior demuestra solo que la lectura funcionó en esa
  ejecución. No certifica disponibilidad indefinida ni concesión de
  permisos mutables.
- EMMake sigue siendo la única web inscrita hasta que una segunda
  conexión genuina pase este recorrido. Los tests sintéticos no se
  publican como clientes reales.
- **No se necesita actualizar el plugin WordPress** para la lógica
  de bootstrap; se reutiliza el `site_read` existente.

## Gates técnicos

```bash
python3 tests/site_manager_site_bootstrap_test.py
python3 tests/site_manager_site_catalog_test.py
python3 tests/site_manager_command_gateway_test.py
python3 scripts/site-manager-command-runner.py --self-test
python3 -m py_compile scripts/site_manager_site_bootstrap.py
```

Las pruebas incluyen dos tenants sintéticos, URL y conexión cruzadas,
falta de alcance `site.read`, plugin o fingerprint inválido,
secretos ausentes, reintentos idénticos, error de MCP, evidencia
spoofeada, no publicación de datos sensibles y rechazo de comandos
arbitrarios. El Gateway normal sigue negándose a seleccionar una web
sin registro previo en el catálogo.
