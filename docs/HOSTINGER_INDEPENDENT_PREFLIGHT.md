# Independent hosting preflight (Emmake)

This one-time setup proves independent access to the actual WordPress hosting
account, not to the OVH MCP container. It is read-only and does not authorize
a code update. No extra WordPress plugin or public recovery endpoint.

## On the hosting account (operator, one time)

1. In Hostinger hPanel, open the SSH access settings for emmake.com. Obtain the
   actual SSH hostname, port, account username, and the absolute WordPress root
   (NOT the /nuevaweb staging root). Use the host's supported SSH/CLI access.
   Do not reuse the OVH, Kairoseth or another customer's account or credentials.
2. With the existing authorized administrative SSH session, identify the
   absolute PHP CLI executable (command -v php, php -v). The worker needs
   PHP >=8.1 and the WordPress account's existing filesystem/DB access.
3. Create a private directory outside ALL public site roots, e.g.
   $HOME/.site-manager-host-worker/emmake-produccion, mode 0700. From the
   exact reviewed merged main commit, copy scripts/site-manager-host-worker-preflight.php
   as preflight.php and src/Adapters/WordPress/PluginPackageRecoveryStore.php
   as PluginPackageRecoveryStore.php into this directory. Keep both files
   private and owned by the hosting account. No code download at request time.
4. Create config.json there (0600) with the actual absolute root and fixed
   identity:

       {"wordpress_root":"/actual/hosting/path/public_html","site":"emmake-produccion","home":"https://emmake.com/"}

5. Generate a NEW dedicated SSH keypair on the operator computer (never in
   chat). Authorize only its public key on the hosting account with a forced
   command. Use the actual absolute PHP executable and private worker path;
   do not copy the example paths literally:

       restrict,command="/absolute/php /absolute/private/worker/preflight.php" ssh-ed25519 AAAA... site-manager-emmake-host-preflight

   Do not authorize the same key on another line without restrictions.
   The worker refuses every SSH_ORIGINAL_COMMAND except
   site-manager-preflight-v1. No shell, forwarding, PTY or SFTP for this key.
   If shared hosting cannot enforce forced commands, stop and use an
   independently authorized restricted host channel; do not silently grant
   general shell access.

## Protected GitHub Environment

Use site-manager-emmake-produccion (already enrolled), not a repository-wide
secret or the staging Environment. Add:

| Name | Type | Value |
|---|---|---|
| HOSTINGER_SM_HOST | Secret | Actual Hostinger SSH hostname/IP |
| HOSTINGER_SM_USER | Secret | Actual hosting account username |
| HOSTINGER_SM_PORT | Variable | Actual SSH port |
| HOSTINGER_SM_SSH_KEY | Secret | New restricted private key |
| HOSTINGER_SM_SSH_KNOWN_HOSTS | Secret | Independently verified Hostinger SSH host key, including port notation if nonstandard |

Reuse existing SITE_MANAGER_EXPECTED_SITE_URL and
SITE_MANAGER_EXPECTED_CONNECTION_ID (must identify the root production site).
Verify the server fingerprint independently before saving known_hosts.
Never paste keys, bearer tokens or passwords into a chat, repo or workflow log.

Run **Site Manager Hostinger Independent Preflight** once from main, with the
existing protected environment. Only Emmakex may execute, on first attempt.
The workflow sends one nonce and expected identity over the fixed SSH command,
uses strict host checking and rejects unexpected/cross-site receipts. It
retains a sanitized artifact for seven days, no connection IDs, keys,
filesystem paths, raw WP output or DB credentials.

## Interpreting the result

independent_access_verified proves that the worker could boot the correct
WordPress without loading the normal active plugins, check the exact installed
Site Manager code tree and compare the registered connection ID. It does NOT
prove successful installation, restore, update permission, or isolation from
MU plugins/theme/host bootstrap code. Readiness flags report ZIP support,
the pinned private worker directory for durable code recovery (not shared WordPress temp), apparent filesystem write permissions and file
modification policy; these are checks, not a destructive write probe.
production_update_authorized is always false.

A future reviewed update worker may add fixed, separate plan/apply/restore
operations, but this preflight key cannot invoke them. Do not remove the
staging-only engine guard or attempt a production upgrade until host-side
recovery and authenticated live MCP checks are proven and specifically
authorized. No changes to site content, active plugins or database options are
requested by the preflight.
