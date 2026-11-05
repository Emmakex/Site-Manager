# SM-4J — verified Site Manager site catalog

## Goal

A Site Manager operator can ask ChatGPT **“Muéstrame las webs que gestiona
Site Manager”** and select an explicitly enrolled logical site without
guessing a GitHub Environment name. This catalog is a safe **directory
of previously verified connections**, not a live connection scanner, secret
manager, permission grant or direct-MCP client.

The trusted directory is `config/site-manager-site-catalog.json`.
Each row contains only:

- `site` — unique logical site ID used by the canonical Gateway;
- `label` — bounded operator-facing name;
- `proof_command_id` — the **existing** successfully verified
  `site.overview` Gateway result for the **same site**.

A registration is accepted only when
`commands/site-manager/results/<proof_command_id>.json` is a matching
`site.overview` / `stage=read` / `status=success` /
`verified=true` result, has the `site.read` scope, a bound Site
Manager connection, discovery fingerprint, HTTPS homepage and
offset-aware verification timestamp.

**No other sites should be inferred** from domain guesses, history,
readme files, issue comments, guessed GitHub Environments or mere
plugin installation. GitHub's connected repository API does not expose
environment listing to this operator workflow. Catalog enrollment is
explicit, reviewable and proof-backed instead.

## Operator use

The normal ChatGPT path is:

1. Read `config/site-manager-site-catalog.json` from the **trusted
   connected Site Manager GitHub repository**. For each item, read its
   exact `proof_command_id` from `commands/site-manager/results/`.
   The CI-validated helper can also render a short snapshot:
   `python3 scripts/site_manager_site_catalog.py list`.
2. Present only the enrolled sites. Describe their state as
   **previously verified at <timestamp>**, never “connected now” on
   the strength of an old result.
3. After the user chooses a logical site, issue a new, strictly
   no-payload `site.overview` command via
   `commands/site-manager/pending/<new-command-id>.json`.
4. The Gateway resolver calls
   `python3 scripts/site_manager_site_catalog.py check --site <site>`
   **before** choosing the protected `site-manager-<site>` Environment.
   The runner also checks enrollment again as defense in depth.
5. The protected Environment's `SITE_MANAGER_SITE_ID`, MCP URL/token
   and the real WordPress connection must agree. The live result, not
   the historical proof, establishes current status.
6. Planning and any later writes continue to require their separate
   scoped WordPress authority and exact-plan user confirmation. The
   catalog never verifies or grants `content.write`,
   `navigation.write` or `seo.write`.

The list operation itself does **not** initiate an MCP call, discover
sites by probing URLs, access sensitive Environment settings, or mutate
WordPress. The canonical ChatGPT → GitHub → Gateway → MCP → Site Manager
→ WordPress path is retained for every *site-specific* read or write.

## Enroll a second client site

Enrollment is a gated two-stage process:

1. Provision the client Site Manager connection and its own protected
   GitHub Environment using the **existing approved onboarding
   process**, with site-specific credentials and scopes. The customer
   WordPress does not need a GitHub repository.
2. Run the separate manual **Site Manager Protected Site Bootstrap**
   workflow under that site's protected Environment. It is the only
   pre-enrollment exception, and **cannot run content/navigation/SEO writes**.
   The Environment must already contain a matching site ID, the expected
   WordPress HTTPS URL and connection ID, plus the existing MCP URL/token.
   The workflow calls only `site_read` and persists a canonical, redacted
   `site.overview` result under `cmd-bootstrap-<run-id>`, which the
   catalog checks against the source, run, WordPress identity and read-only
   capability. See [SITE_ONBOARDING.md](SITE_ONBOARDING.md) for the entire
   isolated operator setup and revocation procedure.
3. Register the logical site ID, label and its **verified** proof
   command ID in the catalog through a normal reviewed GitHub PR.
   Without existing exact-site `site.overview` proof, CI rejects
   the entry. Never add a fabricated proof or copy one from another
   tenant.
4. Exact-head CI validates every enrollment and its evidence. The
   resolver blocks unknown or altered catalog rows. After merge, the
   selected site's ordinary `site.overview` runs under its protected
   Environment.

**Existing enrollment:** the first catalog record is the previously
verified EMMake WordPress acceptance site; this does not certify any
other site. The Research Manager parity site and independent WordPress
portability test in `docs/ROADMAP.md` are still separate acceptance
gates.

## Explicit trust boundaries

The catalog and helper never display or persist:

- protected Environment names, tokens, credential values or MCP URLs;
- arbitrary remote URLs, WordPress REST paths, filesystem/SQL/PHP;
- site content, private menu labels, SEO values or provider storage;
- full connection IDs or credential fingerprints;
- inferred current reachability, implied write grants, or
  `governable=authorized` shortcuts.

A prior read becoming stale does not make a site live. A removed or
incorrect protected Environment must still fail live `site.overview`.
Unknown IDs and cross-site, reused, failed or malformed proofs are
rejected *before* a potentially new GitHub Environment is selected.

## Verification

```bash
python3 scripts/site_manager_site_catalog.py list
python3 scripts/site_manager_site_catalog.py check --site <enrolled-site-id>
python3 tests/site_manager_site_catalog_test.py
python3 tests/site_manager_command_gateway_test.py
python3 scripts/site-manager-command-runner.py --self-test
```

The contract tests cover two synthetic client sites, duplicates,
cross-tenant substitutions, missing/expired-style historical proof,
malformed IDs, unsafe URLs, unverified evidence, scope mismatches
and lack of secret disclosure. Actual historical proof has no
time-to-live claim; any **fresh** operational state requires a new
site-specific read.

No WordPress plugin or MCP deployment is needed for this first
catalog slice. Do not grant new scopes or change client content to test it.

The SM-4K bootstrap is an enrollment-only workflow. It does **not** change
the ordinary Gateway resolver's strict rejection of unregistered site IDs,
and neither workflow can create a new protected Environment with legitimate
secrets merely from an arbitrary site ID. No second client is represented
as live until its own protected bootstrap proof, catalog review and fresh
`site.overview` read have succeeded.
