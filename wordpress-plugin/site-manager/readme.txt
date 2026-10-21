=== Site Manager ===
Contributors: emmake
Tags: site management, discovery, diagnostics, governance
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.11.3
License: Proprietary

Site Manager discovers the real structure of a WordPress installation and reports what is detected, understood, governable, authorized or blocked.

== Description ==

Site Manager is a platform-neutral control plane. WordPress is its first adapter, not the product boundary.

On activation Site Manager performs a bounded local discovery of the installation, including runtime, routing, active theme, public content types, taxonomies, navigation providers, active plugins and known providers. Detection never grants write access.

The WordPress admin surface follows the configured WordPress/user locale. English is the source language and Spanish (`es_ES`) is bundled.

Version 0.11.3 refreshes Site Manager > Tests once after an MCP acceptance run transitions from an active state (`queued`, `running` or `cleaning`) to `completed` or `failed`. This keeps server-rendered failure evidence and Recent runs synchronized with the persisted terminal state without creating reload loops when the page is opened on an already-terminal run.

Version 0.11.2 stabilizes the temporary classic-menu acceptance fixture by assigning an explicit initial menu position. WordPress therefore no longer normalizes a zero-position fixture during the governed update/rollback cycle, while exact rollback verification and stale protection remain unchanged.

Version 0.11.1 adds bounded MCP acceptance failure evidence directly to Site Manager > Tests. When an MCP acceptance run fails, the dashboard can inspect only the matching acceptance fixture plan records for the active connection/generation and show plan, verification and rollback status/fingerprints without exposing bearer material. This is a diagnostic release for the remaining live classic-menu rollback mismatch discovered by the first 0.11.0 MCP acceptance run.

Version 0.11.0 completes the connection-first Acceptance Center. Site Manager now distinguishes not connected, connection prepared and MCP connected. When MCP is connected, the dashboard queues one remote acceptance job and the MCP bridge executes exactly one bounded phase at a time through the existing scoped Site Manager routes. Content, navigation and SEO therefore use one end-to-end MCP acceptance path instead of duplicating the same lifecycle locally. Idle MCP job polling is deliberately low frequency, active phases retain a two-second minimum pause, concurrency remains one, temporary fixtures are isolated and cleanup always runs. This release also makes navigation stale/rollback fingerprints depend only on Site Manager-owned navigation state so WordPress request metadata timestamp drift cannot cause a false rollback failure.

Version 0.10.1 separates connection credentials from a real active MCP connection. Authenticated Site Manager bearer traffic is observed without persisting bearer material; `site-manager-mcp/<version>` clients are identified by bounded client metadata and recent presence. The Connection and Tests surfaces can therefore distinguish not connected, connection prepared and MCP connected.

Version 0.10.0 adds a product-facing Acceptance Center under `Site Manager > Tests`. The default low-resource profile executes one bounded phase per request, enforces concurrency 1 with a server-side lock, waits between phases, persists progress for resume, creates isolated temporary draft/menu fixtures, exercises the existing plan/apply/verify/rollback governance paths for content, navigation and SEO, then cleans the fixtures and stores bounded history/evidence. The Acceptance Center never runs multiple write tests in parallel and does not select provider storage directly.

Version 0.9.0 completes the first scoped remote-governance set with provider-aware SEO writes. `seo.write` remains explicitly opt-in. Remote SEO operations use fixed plan/apply/rollback routes and reuse Site Manager's existing provider registry, so WordPress resolves the active SEO authority and provider adapter before any plan can be created. Unsupported fields or unresolved/conflicting SEO ownership fail closed.

Version 0.8.0 added native WordPress navigation writes behind `navigation.write`. Version 0.7.0 introduced the first governed remote write surface for native posts/pages using `content.write`. Version 0.6.0 introduced the neutral remote-connection contract, one revocable scoped connection, high-entropy bearer token issuance, digest-only storage, stable `smc_` connection id, generation counter, explicit scopes, rotation, revocation and last-successful-use tracking.

Remote discovery and capabilities require `site.read`; diagnostics requires `site.diagnostics`. Write scopes are never inferred from detection. They may be selected only when the corresponding capability is currently governable and are consumed only by their matching fixed remote/MCP tools.

The provider registry currently includes:

* SEO/GEO Theme: semantic native sources; title -> `post_title`, description -> `post_excerpt`; other SEO outputs remain derived.
* Yoast SEO: verified `WPSEO_Meta` API; title, description, canonical and indexability (`default|index|noindex`).
* Rank Math: verified post-meta contract; title, description and canonical. Robots/indexability remains read-only until its array/default contract is separately accepted.
* All in One SEO (AIOSEO): the plugin's own `aioseo_meta_data` REST field through local WordPress `rest_do_request()`; title, description and canonical. No direct custom-table writes are performed.

All writable provider adapters retain Site Manager's guarded lifecycle: plan-only preview, explicit confirmation, stale-target protection, bounded apply, read-back verification and guarded rollback. Unsupported provider surfaces remain blocked instead of being guessed.

Native WordPress content, navigation and provider-aware SEO governance use the same safety model.

== Installation ==

1. Upload the official `site-manager-0.11.3.zip` from Plugins > Add New > Upload Plugin.
2. Activate Site Manager.
3. Open the top-level Site Manager menu in WordPress admin.
4. Review Site Manager > Dashboard and Site Manager > Diagnostics.
5. Open Site Manager > Connection to see whether credentials are merely prepared or an MCP client is actually connected.
6. Open Site Manager > Tests. When MCP is connected, start one MCP acceptance sequence from the WordPress dashboard. When MCP is not connected, the local low-resource self-test remains available.
7. The MCP acceptance path requires `site.read`, `content.write`, `navigation.write` and `seo.write`, because those are the exact capabilities being tested. Site Manager does not auto-grant missing write scopes.
8. When remote access is required, generate a scoped token and copy it immediately into the external client's secret store; Site Manager never stores the plaintext token.

== Changelog ==

= 0.11.3 =
* Refreshes Site Manager > Tests once when an MCP acceptance run transitions from active to `completed` or `failed`.
* Keeps server-rendered failure evidence and Recent runs synchronized with the persisted terminal run.
* Does not reload when the initial page state is already terminal, preventing refresh loops.
* Leaves MCP polling cadence, scopes, concurrency and execution behavior unchanged.

= 0.11.2 =
* Gives the temporary classic-menu acceptance fixture an explicit initial menu position.
* Prevents WordPress from normalizing a zero-position fixture during apply/rollback housekeeping.
* Keeps exact rollback verification and stale-plan/stale-rollback protection unchanged.

= 0.11.1 =
* Adds a bounded MCP acceptance evidence panel to Site Manager > Tests after failed remote acceptance runs.
* Reads only the acceptance fixture plan ids already stored in the failed run and only for the current connection id/generation.
* Shows safe plan/verification/rollback statuses and fingerprints without exposing bearer tokens or plaintext connection credentials.
* Keeps the existing fail-closed MCP sequence and cleanup behavior unchanged while diagnosing the remaining live classic-menu rollback mismatch.

= 0.11.0 =
* Completes the connection-first Acceptance Center with a single MCP-driven acceptance job path.
* Queues MCP acceptance from WordPress without asking WordPress to store or recover the plaintext bearer token.
* Adds bounded authenticated remote acceptance job, fixture, report and cleanup routes dedicated to Site Manager-owned test fixtures.
* Keeps active execution sequential: one bounded phase at a time, concurrency 1 and at least two seconds between active phases.
* Uses low-frequency polling while idle so persistent MCP presence does not overload small WordPress hosts.
* Reuses the existing scoped content/navigation/SEO plan, confirmed apply, verification and rollback routes; no generic write proxy is added.
* Requires the exact scopes under test (`site.read`, `content.write`, `navigation.write`, `seo.write`) before a remote acceptance run can start.
* Stores local and MCP acceptance outcomes in the same bounded history surface.
* Changes navigation stale/rollback fingerprints to Site Manager-owned navigation fields only, preventing false stale rollback failures caused solely by WordPress `post_modified_gmt` drift while preserving stale protection for governed field edits.

= 0.10.1 =
* Distinguishes generated/enabled connection credentials from an actually active MCP client.
* Tracks only bounded authenticated remote-client metadata and recent presence; bearer tokens and bearer digests are not copied into client-presence state.
* Identifies `site-manager-mcp/<version>` traffic and invalidates prior MCP presence after connection generation changes.
* Adds explicit MCP connected/not connected status to Site Manager > Connection.
* Makes Site Manager > Tests connection-first and disables the duplicate local sequence while MCP is connected.
* Prepares the Acceptance Center for a single MCP-driven acceptance job path rather than running both local and remote tests.

= 0.10.0 =
* Adds `Site Manager > Tests` as a WordPress-native Acceptance Center.
* Uses a low-resource sequential runner: exactly one bounded phase per request, concurrency limit 1 and a pause between phases.
* Persists active-run state so a page reload can resume without repeating completed phases.
* Creates only Site Manager-owned temporary fixtures: one draft page and one unassigned classic menu/item.
* Exercises the existing WordPressAdapter governance path for content, navigation and provider-aware SEO: plan, confirmed apply, verification and confirmed rollback.
* Resolves SEO through the existing provider registry instead of selecting or writing provider storage in the dashboard.
* On failure, stops further write tests and transitions directly to cleanup.
* Removes temporary fixtures during cleanup and retains only bounded acceptance evidence/history.
* Keeps the MCP/remote connection contract unchanged.

= 0.9.0 =
* Adds fixed remote `seo.write` plan/apply/rollback routes.
* Routes remote SEO plans through the existing provider registry instead of selecting or writing provider storage in the bridge.
* Supports the stable semantic request fields `title`, `description`, `canonical` and `indexability`; the active provider remains responsible for accepting only fields it actually governs.
* Preserves provider ownership/conflict checks, explicit confirmation, connection-generation binding, stale-site and stale-target protection, verification and guarded rollback.
* Returns a provider-aware plan preview showing requested semantic fields, current owned storage snapshot, planned storage and derived outputs.
* Completes the first content/navigation/SEO scoped write surface without adding a generic REST or write-anything proxy.

= 0.8.0 =
* Adds fixed remote `navigation.write` plan/apply/rollback routes for discovered native WordPress navigation providers.
* Supports classic-menu item updates and block-navigation targets through the existing provider-aware navigation governance service.
* Requires explicit `navigation.write` scope and the administrator principal bound to the connection.
* Preserves explicit confirmation, connection-generation binding, site and target stale-plan protection, verification and guarded rollback.
* Adds plan capability/operation matching so a plan cannot be applied or rolled back through a different scoped write route.
* Keeps SEO remote writes disabled until their separate implementation and acceptance slice.

= 0.7.0 =
* Adds the first governed remote write surface for native WordPress posts/pages behind `content.write`.
* Adds fixed content plan/apply/rollback routes with short-lived plans and retained rollback evidence.
* Binds write authorization to the administrator principal who grants write scopes.
* Adds connection-generation binding, stale-site and stale-target protection, explicit confirmation, verification and rollback.
* Adds the authenticated `/connection` introspection resource used by the MCP bridge to discover granted scopes.

= 0.6.0 =
* Adds `Site Manager > Connection` as the neutral remote-access control surface.
* Adds stable `smc_` connection ids, token generation/rotation, revocation, generation and last-use tracking.
* Stores only SHA-256 bearer-token digests; plaintext tokens are returned once and never persisted.
* Adds scope policy derived from the current governable capability graph.
* Keeps write scopes opt-in and unavailable when their matching capability is not governable.
* Adds scoped bearer access to existing read REST resources while preserving local WordPress administrator access.
* Requires `site.read` for discovery/capabilities and `site.diagnostics` for diagnostics.
* Makes dashboard authorization counts reflect the scopes of the active connection.
* Adds regression contracts for token secrecy, scope enforcement, rotation, revocation and authorization summary.

= 0.5.2 =
* Normalizes `CapabilityGraph::jsonSerialize()` so persisted capabilities are plain arrays rather than nested `Capability` objects.
* Aligns WordPress option persistence, dashboard reads, REST output and future MCP consumers on the same stable structure.
* Replaces the 0.5.1 synthetic dashboard regression fixture with a test built from real `Capability` and `CapabilityGraph` objects.
* Forces discovery refresh on upgrade through the normal version guard so legacy object-backed state is rewritten automatically.

= 0.5.1 =
* Fixes dashboard capability lookup when capabilities are persisted using the `CapabilityGraph` envelope.
* Keeps compatibility with direct capability-list input.
* Makes the SEO ownership row, provider adapter, write mode and governable inputs reflect the same `seo.write` capability used by the capability counters.

= 0.5.0 =
* Adds the generic `SeoProviderAdapter` contract and provider registry.
* Routes SEO planning and execution through the resolved provider adapter rather than theme-specific code.
* Adds guarded Yoast SEO support for title, description, canonical and indexability using Yoast's own `WPSEO_Meta` API.
* Adds guarded Rank Math support for title, description and canonical using verified provider post-meta keys.
* Adds guarded AIOSEO support for title, description and canonical using the plugin's own `aioseo_meta_data` REST contract through local WordPress REST dispatch.
* Preserves provider conflicts as write-blocking states.
* Keeps unknown providers and unverified provider surfaces read/diagnostic only.
* Exposes the resolved provider adapter, write mode and governable SEO inputs in the dashboard/capability metadata.

= 0.4.0 =
* Adds the first verified theme-native semantic SEO writer for `seo-geo-theme`.
* Promotes `seo.write` to governable only when source evidence proves the native-derived contract.
* Maps SEO title to native `post_title` and SEO description to native `post_excerpt` instead of creating parallel metadata.
* Keeps canonical, robots, Open Graph, schema, hreflang and sitemap as derived outputs unless a provider contract proves direct storage.
* Adds plan, explicit confirmation, stale-target protection, verification and rollback for semantic SEO updates.

= 0.3.3 =
* Adds bounded read-only tracing of data sources referenced by SEO-focused theme code.
* Detects native WordPress content, permalink, taxonomy and site-identity API usage.

= 0.3.2 =
* Adds bounded read-only static inspection of the active/parent theme PHP source.
* Adds Site Manager > Diagnostics for detailed provider-contract evidence.

= 0.3.1 =
* Adds bounded local SEO ownership discovery.
* Detects known SEO plugin ownership candidates and reports conflicts.

= 0.3.0 =
* Adds discovery and guarded governance for native WordPress classic menus and block navigation.

= 0.2.0 =
* Adds safe native WordPress content governance for posts and pages.

= 0.1.2 =
* Adds English/Spanish localization for the WordPress dashboard.

= 0.1.1 =
* Fixes dashboard rendering and moves Site Manager into its own top-level WordPress admin menu.

= 0.1.0 =
* First installable WordPress acceptance build.
