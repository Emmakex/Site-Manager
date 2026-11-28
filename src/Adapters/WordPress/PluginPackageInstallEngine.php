<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use DomainException;

/**
 * Staging-only native installer and recovery primitive. No REST registration.
 * Recovery must run from trusted code outside the plugin being overwritten.
 * Local package and expected digests must originate in the reviewed release
 * pipeline, never from a caller-selected URL or filesystem path.
 */
final class PluginPackageInstallEngine
{
    private const PLUGIN='site-manager/site-manager.php';
    private const JOURNAL='site_manager_self_update_journal';

    public function apply(string $package,string $version,string $sha,string $beforeTree,string $afterTree,bool $confirmed): array
    {
        if (! $confirmed) { throw new DomainException('plugin_update_confirmation_required'); }
        $root=$this->eligible();
        $proof=(new PluginPackageArchiveGuard())->inspect($package,$version,$sha);
        $store=new PluginPackageRecoveryStore();
        $before=$store->fingerprint($root);
        $current=$this->installedVersion($root);
        if (! hash_equals($beforeTree,$before['tree_sha256']) || ! hash_equals($afterTree,$proof['tree_sha256'])
            || ! version_compare($version,$current,'>') || version_compare(PHP_VERSION,$proof['requires_php'],'<')
            || version_compare((string) get_bloginfo('version'),$proof['requires_wordpress'],'<')) {
            throw new DomainException('plugin_update_stale_or_incompatible');
        }
        // Persist the exclusive claim before touching code. Never release an
        // ambiguous claim automatically or blindly retry a failed replacement.
        $id=bin2hex(random_bytes(16));
        $journal=['id'=>$id,'status'=>'preparing','before_tree'=>$beforeTree,'after_tree'=>$afterTree,'version'=>$version];
        if (! add_option(self::JOURNAL,$journal,'',false)) { throw new DomainException('plugin_update_reconciliation_required'); }
        $archiveLock=null;
        try {
            $backup=$store->capture($root,$current,$beforeTree);
            $archiveLock=$this->lockArchive($backup['path']);
            $journal['backup']=$backup;
            $journal['status']='installing';
            $this->persist($journal);
            if ($store->fingerprint($root)['tree_sha256']!==$beforeTree) {
                throw new DomainException('plugin_update_stale_source');
            }
            // Native WordPress filesystem/ZIP installation. Never execute
            // incoming PHP within this process or consider a header sufficient.
            if (! $this->install($package)) {
                return ['status'=>'recovery_required','verified'=>false,'operation_id'=>$id];
            }
            $after=$store->fingerprint($root);
            if ($after['tree_sha256']!==$afterTree || $this->installedVersion($root)!==$version || ! is_plugin_active(self::PLUGIN)) {
                return ['status'=>'recovery_required','verified'=>false,'operation_id'=>$id];
            }
            $journal['status']='installed_pending_runtime';
            $this->persist($journal);
            return ['status'=>'installed_pending_runtime','verified'=>false,'tree_verified'=>true,'operation_id'=>$id,'version'=>$version];
        } catch (\Throwable) {
            // The retained journal is authoritative; do not erase it on error.
            return ['status'=>'recovery_required','verified'=>false,'operation_id'=>$id];
        } finally {
            if (is_resource($archiveLock)) { fclose($archiveLock); }
        }
    }

    /** Explicit recovery of the one retained operation, from independent trusted code. */
    public function restore(string $operationId,bool $confirmed): array
    {
        if (! $confirmed || ! preg_match('/^[a-f0-9]{32}$/D',$operationId)) {
            throw new DomainException('plugin_update_confirmation_required');
        }
        $root=$this->eligible(true);
        $journal=get_option(self::JOURNAL,[]);
        if (! is_array($journal) || ($journal['id'] ?? null)!==$operationId
            || ! in_array($journal['status'] ?? '',['installing','installed_pending_runtime'],true)
            || ! is_array($journal['backup'] ?? null)) {
            throw new DomainException('plugin_update_recovery_unavailable');
        }
        $backup=$journal['backup'];
        $store=new PluginPackageRecoveryStore();
        $proof=$store->verify($backup);
        if ($proof['tree_sha256']!==$journal['before_tree']) { throw new DomainException('plugin_update_backup_mismatch'); }
        // A successful installation must still match the approved tree before
        // rollback; a failed/partial install requires explicit reconciliation.
        if ($journal['status']==='installed_pending_runtime'
            && $store->fingerprint($root)['tree_sha256']!==$journal['after_tree']) {
            throw new DomainException('plugin_update_rollback_drift');
        }
        $archiveLock=$this->lockArchive($backup['path']);
        try {
            if (get_option(self::JOURNAL)!==$journal) { throw new DomainException('plugin_update_reconciliation_required'); }
            $store->verify($backup);
            if (! $this->install($backup['path']) || $store->fingerprint($root)['tree_sha256']!==$backup['tree_sha256']
                || $this->installedVersion($root)!==$backup['version'] || ! is_plugin_active(self::PLUGIN)) {
                return ['status'=>'manual_recovery_required','verified'=>false];
            }
            $journal['status']='restored_pending_runtime';
            $this->persist($journal);
            return ['status'=>'restored_pending_runtime','verified'=>false,'tree_verified'=>true,'version'=>$backup['version']];
        } catch (\Throwable) {
            return ['status'=>'manual_recovery_required','verified'=>false];
        } finally {
            // Retain journal and archive for subsequent fresh runtime readback.
            fclose($archiveLock);
        }
    }

    private function eligible(bool $recovery=false): string
    {
        if (! function_exists('wp_get_environment_type') || ! in_array(wp_get_environment_type(),['local','development','staging'],true)
            || is_multisite() || (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS)
            || ! wp_is_file_mod_allowed('site_manager_self_update') || ! current_user_can('manage_options')
            || ! current_user_can('install_plugins') || ! current_user_can('update_plugins')) {
            throw new DomainException('plugin_update_not_eligible');
        }
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        if (get_filesystem_method()!=='direct' || ! is_plugin_active(self::PLUGIN)) {
            throw new DomainException('plugin_update_direct_active_required');
        }
        $root=WP_PLUGIN_DIR.'/site-manager';
        if (is_link($root) || (! $recovery && ! is_dir($root))) {
            throw new DomainException('plugin_update_invalid_root');
        }
        return $root;
    }

    private function installedVersion(string $root): string
    {
        $data=get_plugin_data($root.'/site-manager.php',false,false);
        $version=$data['Version'] ?? '';
        if (($data['Name'] ?? '')!=='Site Manager' || ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$version)) {
            throw new DomainException('plugin_update_installed_identity_mismatch');
        }
        return $version;
    }

    private function persist(array $journal): void
    {
        update_option(self::JOURNAL,$journal,false);
        if (get_option(self::JOURNAL)!==$journal) {
            throw new DomainException('plugin_update_journal_unavailable');
        }
    }

    /** Kernel lock on the private recovery archive; released if PHP terminates. */
    private function lockArchive(string $path)
    {
        $handle=fopen($path,'rb');
        if ($handle===false || ! flock($handle,LOCK_EX|LOCK_NB)) {
            if (is_resource($handle)) { fclose($handle); }
            throw new DomainException('plugin_update_reconciliation_required');
        }
        return $handle;
    }

    private function install(string $trustedLocalZip): bool
    {
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader-skins.php';
        $upgrader=new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $ok=$upgrader->install($trustedLocalZip,['overwrite_package'=>true]);
        clearstatcache();
        wp_clean_plugins_cache(true);
        return $ok===true;
    }
}
