<?php
declare(strict_types=1);

/**
 * Independent hosting preflight, CLI only. Install in a private host directory
 * with the reviewed PluginPackageRecoveryStore.php and local config.json.
 * No update, restore, shell execution, URL fetch or database mutation.
 */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
ob_start();
try {
    if (getenv('SSH_ORIGINAL_COMMAND')!=='site-manager-preflight-v1') {
        throw new RuntimeException('worker_command_refused');
    }
    $raw=stream_get_contents(STDIN,4097);
    if (! is_string($raw) || strlen($raw)>4096) { throw new RuntimeException('worker_input_invalid'); }
    $request=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    $keys=['site','home','connection_id','nonce'];
    if (! is_array($request) || count($request)!==count($keys) || array_diff($keys,array_keys($request))
        || ! is_string($request['site']) || ! preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/D',$request['site'])
        || ! is_string($request['home']) || ! preg_match('~^https://[a-zA-Z0-9.-]+(?:/[a-zA-Z0-9_-]+)*/$~D',$request['home'])
        || ! is_string($request['connection_id']) || ! preg_match('/^smc_[a-f0-9]{32}$/D',$request['connection_id'])
        || ! is_string($request['nonce']) || ! preg_match('/^[a-f0-9]{64}$/D',$request['nonce'])) {
        throw new RuntimeException('worker_input_invalid');
    }
    $configPath=__DIR__.'/config.json';
    if (! is_file($configPath) || is_link($configPath) || (fileperms($configPath)&0077)!==0
        || filesize($configPath)>4096) { throw new RuntimeException('worker_config_invalid'); }
    $config=json_decode((string)file_get_contents($configPath),true,8,JSON_THROW_ON_ERROR);
    if (! is_array($config) || count($config)!==3 || ($config['site'] ?? null)!==$request['site'] || ($config['home'] ?? null)!==$request['home']
        || ! is_string($config['wordpress_root'] ?? null)
        || ! str_starts_with($config['wordpress_root'],'/')) {
        throw new RuntimeException('worker_config_invalid');
    }
    $root=realpath($config['wordpress_root']);
    $worker=realpath(__DIR__);
    if ($root===false || $worker===false || ! is_file($root.'/wp-load.php')
        || $worker===$root || str_starts_with($worker,rtrim($root,'/').'/')
        || (fileperms($worker)&0077)!==0) {
        throw new RuntimeException('worker_private_directory_required');
    }
    // Normal WordPress bootstrap, without standard active plugins for this
    // process only. Does not deactivate them in the database. MU plugins and
    // other host bootstrap code may still run; never claim full isolation.
    $GLOBALS['wp_filter']['pre_option_active_plugins'][10][]=[
        'function'=>static fn()=>[], 'accepted_args'=>0,
    ];
    require $root.'/wp-load.php';
    remove_all_filters('pre_option_active_plugins');
    if (defined('SITE_MANAGER_VERSION') || is_multisite()
        || trailingslashit((string)get_option('home'))!==$request['home']
        || trailingslashit((string)get_option('siteurl'))!==$request['home']) {
        throw new RuntimeException('worker_site_identity_mismatch');
    }
    $connection=get_option('site_manager_remote_connection',[]);
    if (! is_array($connection) || ($connection['enabled'] ?? false)!==true
        || ! is_string($connection['connection_id'] ?? null)
        || ! hash_equals($request['connection_id'],$connection['connection_id'])
        || ! in_array('site.read',(array)($connection['scopes'] ?? []),true)) {
        throw new RuntimeException('worker_connection_mismatch');
    }
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    $plugin=WP_PLUGIN_DIR.'/site-manager';
    require __DIR__.'/PluginPackageRecoveryStore.php';
    $store=new \SiteManager\Adapters\WordPress\PluginPackageRecoveryStore($worker);
    $tree=$store->fingerprint($plugin);
    $data=get_plugin_data($plugin.'/site-manager.php',false,false);
    $version=$data['Version'] ?? '';
    if (($data['Name'] ?? '')!=='Site Manager' || ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$version)
        || ! is_plugin_active('site-manager/site-manager.php')) {
        throw new RuntimeException('worker_plugin_identity_mismatch');
    }
    // Same private root that the independent update worker will use.
    $content=realpath(WP_CONTENT_DIR);
    $privateTemp=$store->storageReady();
    if ($content===false || $worker===$content || str_starts_with($worker,rtrim($content,'/').'/')) {
        throw new RuntimeException('worker_private_directory_required');
    }
    $receipt=['schema'=>'site-manager-host-preflight-v1','site'=>$request['site'],'nonce'=>$request['nonce'],
        'home'=>$request['home'],'connection_sha256'=>hash('sha256',$connection['connection_id']),
        'plugin_version'=>$version,'tree_sha256'=>$tree['tree_sha256'],'php_version'=>PHP_VERSION,
        'wordpress_version'=>(string)get_bloginfo('version'),'plugin_bypassed'=>true,
        'active'=>true,'zip_available'=>class_exists(ZipArchive::class),
        'private_temp_available'=>$privateTemp,
        'plugin_dir_writable'=>is_writable(WP_PLUGIN_DIR) && is_writable($plugin),
        'file_mod_allowed'=>wp_is_file_mod_allowed('site_manager_self_update') && !(defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS),
        'environment'=>wp_get_environment_type(),'mutation_performed'=>false];
    ob_end_clean();
    echo json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
 } catch (\Throwable $error) {
    if (ob_get_level()>0) { ob_end_clean(); }
    $code=$error->getMessage();
    $allowed=['worker_command_refused','worker_input_invalid','worker_config_invalid',
        'worker_private_directory_required','worker_site_identity_mismatch','worker_connection_mismatch',
        'worker_plugin_identity_mismatch','plugin_recovery_invalid_root','plugin_recovery_unsafe_entry',
        'plugin_recovery_unexpected_entry','plugin_recovery_unsafe_file','plugin_recovery_incomplete_tree'];
    fwrite(STDERR,"HOST_PREFLIGHT_REFUSED:".(in_array($code,$allowed,true)?$code:'worker_internal_failure')."\n");
    exit(1);
}
