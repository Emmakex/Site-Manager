<?php
declare(strict_types=1);

/**
 * Private forced-command host worker for Site Manager only.
 * Invoked by the fixed preflight dispatcher; never installed in a web root.
 * The Gateway must authenticate official release provenance before plan.
 */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
ob_start();
function refuse(string $code): never { throw new DomainException($code); }
function exact(array $value,array $keys): bool {
    return count($value)===count($keys) && ! array_diff($keys,array_keys($value));
}
function hexvalue(mixed $v,int $size): bool {
    return is_string($v) && preg_match('/^[a-f0-9]{'.$size.'}$/D',$v)===1;
}
function privateFile(string $path,int $limit): string {
    if (! is_file($path) || is_link($path) || (fileperms($path)&0077)!==0 || filesize($path)>$limit) { refuse('private_state_unavailable'); }
    $raw=file_get_contents($path);
    if (! is_string($raw)) { refuse('private_state_unavailable'); }
    return $raw;
}
function privateJson(string $path,int $limit=8192): array {
    $data=json_decode(privateFile($path,$limit),true,20,JSON_THROW_ON_ERROR);
    if (! is_array($data)) { refuse('private_state_unavailable'); }
    return $data;
}
function writePrivate(string $path,string $data): void {
    if (is_link($path)) { refuse('private_state_unavailable'); }
    $tmp=tempnam(dirname($path),'sm-worker-');
    if (! is_string($tmp) || dirname($tmp)!==dirname($path)) { refuse('private_state_unavailable'); }
    try {
        if (! chmod($tmp,0600) || file_put_contents($tmp,$data)!==strlen($data) || ! rename($tmp,$path)) {
            refuse('private_state_unavailable');
        }
    } finally { if (is_file($tmp)) { @unlink($tmp); } }
}
function writePlan(string $path,array $plan): void {
    writePrivate($path,json_encode($plan,JSON_THROW_ON_ERROR));
}
function receipt(array $request,array $data): void {
    while (ob_get_level()>0) { ob_end_clean(); }
    echo json_encode(['schema'=>'site-manager-host-update-v1','site'=>$request['site'],'nonce'=>$request['nonce']]+$data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
}

try {
    $commands=['site-manager-update-plan-v1'=>'plan','site-manager-update-apply-v1'=>'apply',
        'site-manager-update-verify-v1'=>'verify','site-manager-update-restore-v1'=>'restore',
        'site-manager-update-finalize-v1'=>'finalize','site-manager-update-status-v1'=>'status'];
    $stage=$commands[getenv('SSH_ORIGINAL_COMMAND')] ?? '';
    if ($stage==='') { refuse('worker_command_refused'); }
    $limit=$stage==='plan'?13_400_000:8192;
    $raw=stream_get_contents(STDIN,$limit+1);
    if (! is_string($raw) || strlen($raw)>$limit) { refuse('worker_input_invalid'); }
    $request=json_decode($raw,true,20,JSON_THROW_ON_ERROR); unset($raw);
    $keys=['site','home','connection_id','nonce'];
    $extra=match($stage) {
        'plan'=>['version','package_sha256','source','package_base64'],
        'apply'=>['plan_id','package_sha256','confirmed'],
        'verify'=>['operation_id'],
        'restore','finalize'=>['operation_id','confirmed'],
        'status'=>[],
    };
    if (! is_array($request) || ! exact($request,array_merge($keys,$extra))
        || ! is_string($request['site']) || ! preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/D',$request['site'])
        || ! is_string($request['home']) || ! preg_match('~^https://[a-zA-Z0-9.-]+(?:/[a-zA-Z0-9_-]+)*/$~D',$request['home'])
        || ! is_string($request['connection_id']) || ! preg_match('/^smc_[a-f0-9]{32}$/D',$request['connection_id'])
        || ! hexvalue($request['nonce'],64)) { refuse('worker_input_invalid'); }
    if (in_array($stage,['apply','restore','finalize'],true) && $request['confirmed']!==true) { refuse('confirmation_required'); }
    if (in_array($stage,['verify','restore','finalize'],true) && ! hexvalue($request['operation_id'],32)) { refuse('worker_input_invalid'); }
    $worker=realpath(__DIR__);
    if ($worker===false || (fileperms($worker)&0077)!==0) { refuse('worker_private_directory_required'); }
    $config=privateJson($worker.'/config.json',4096);
    $authority=privateJson($worker.'/update-authority.json',4096);
    if (! exact($config,['wordpress_root','site','home']) || $config['site']!==$request['site'] || $config['home']!==$request['home']
        || ! is_string($config['wordpress_root']) || ! str_starts_with($config['wordpress_root'],'/')
        || ! exact($authority,['enabled','administrator_id']) || $authority['enabled']!==true
        || ! is_int($authority['administrator_id']) || $authority['administrator_id']<1) { refuse('worker_authority_unavailable'); }
    $root=realpath($config['wordpress_root']);
    if ($root===false || ! is_file($root.'/wp-load.php') || $worker===$root || str_starts_with($worker,rtrim($root,'/').'/')) { refuse('worker_private_directory_required'); }
    // Load these exact reviewed classes from private storage, never from the
    // potentially broken plugin. Normal boot uses the installed runtime.
    foreach (['PluginPackageArchiveGuard','PluginPackageRecoveryStore','PluginPackageInstallEngine'] as $class) {
        require $worker.'/'.$class.'.php';
    }
    $skip=in_array($stage,['status','restore'],true);
    if ($skip) {
        $GLOBALS['wp_filter']['pre_option_active_plugins'][10][]=['function'=>static fn()=>[],'accepted_args'=>0];
    }
    require $root.'/wp-load.php';
    if ($skip) { remove_all_filters('pre_option_active_plugins'); }
    if (is_multisite() || ($skip && defined('SITE_MANAGER_VERSION'))
        || trailingslashit((string)get_option('home'))!==$request['home']
        || trailingslashit((string)get_option('siteurl'))!==$request['home']) { refuse('worker_site_identity_mismatch'); }
    $connection=get_option('site_manager_remote_connection',[]);
    if (! is_array($connection) || ($connection['enabled'] ?? false)!==true
        || ! is_string($connection['connection_id'] ?? null) || ! hash_equals($request['connection_id'],$connection['connection_id'])
        || ! in_array('site.read',(array)($connection['scopes'] ?? []),true)
        || (int)($connection['generation'] ?? 0)<1) { refuse('worker_connection_mismatch'); }
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    require_once ABSPATH.'wp-admin/includes/file.php';
    wp_set_current_user($authority['administrator_id']);
    if (! current_user_can('manage_options') || ! current_user_can('install_plugins') || ! current_user_can('update_plugins')
        || get_filesystem_method()!=='direct' || ! wp_is_file_mod_allowed('site_manager_self_update')
        || (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS)) { refuse('worker_permissions_denied'); }
    $store=new \SiteManager\Adapters\WordPress\PluginPackageRecoveryStore($worker);
    if (! $store->storageReady()) { refuse('worker_private_directory_required'); }
    $lockPath=$worker.'/update-worker.lock';
    if (is_link($lockPath)) { refuse('private_state_unavailable'); }
    umask(0077);
    $lock=fopen($lockPath,'c');
    if ($lock===false || ! flock($lock,LOCK_EX|LOCK_NB)) { refuse('worker_busy'); }
    $planPath=$worker.'/approved-plan.json';
    $packagePath=$worker.'/candidate.zip';
    $journal=get_option('site_manager_self_update_journal',[]);
    $generation=(int)$connection['generation'];
    $engine=new \SiteManager\Adapters\WordPress\PluginPackageInstallEngine($worker,true);
    if ($stage==='status') {
        $data=['status'=>'idle','mutation_performed'=>false];
        if (is_array($journal) && $journal!==[]) {
            if (! hexvalue($journal['id'] ?? null,32) || ! in_array($journal['status'] ?? '',['preparing','installing','installed_pending_runtime','restored_pending_runtime'],true)) { refuse('reconciliation_required'); }
            $data=['status'=>$journal['status'],'operation_id'=>$journal['id'],'mutation_performed'=>false];
        }
        receipt($request,$data); exit;
    }
    if ($stage==='plan') {
        if ($journal) { refuse('reconciliation_required'); }
        if (! is_string($request['version']) || ! preg_match('/^[0-9]{1,9}\.[0-9]{1,9}\.[0-9]{1,9}$/D',$request['version'])
            || ! hexvalue($request['package_sha256'],64) || ! is_array($request['source'])
            || ! exact($request['source'],['commit_sha','run_id','artifact_id'])
            || ! hexvalue($request['source']['commit_sha'],40)
            || ! is_int($request['source']['run_id']) || $request['source']['run_id']<1
            || ! is_int($request['source']['artifact_id']) || $request['source']['artifact_id']<1
            || ! is_string($request['package_base64']) || strlen($request['package_base64'])>13_333_336) { refuse('worker_input_invalid'); }
        $bytes=base64_decode($request['package_base64'],true);
        if (! is_string($bytes) || strlen($bytes)>10_000_000 || base64_encode($bytes)!==$request['package_base64']
            || ! hash_equals($request['package_sha256'],hash('sha256',$bytes))) { refuse('package_digest_mismatch'); }
        $plugin=WP_PLUGIN_DIR.'/site-manager';
        $current=get_plugin_data($plugin.'/site-manager.php',false,false);
        if (($current['Name'] ?? '')!=='Site Manager' || ! is_plugin_active('site-manager/site-manager.php')
            || ! defined('SITE_MANAGER_VERSION') || SITE_MANAGER_VERSION!==($current['Version'] ?? '')
            || ! version_compare($request['version'],SITE_MANAGER_VERSION,'>')) { refuse('plugin_version_not_eligible'); }
        $before=$store->fingerprint($plugin);
        // Keep only one staged plan and package, inside the private worker.
        writePrivate($packagePath,$bytes); unset($bytes);
        try {
            $proof=(new \SiteManager\Adapters\WordPress\PluginPackageArchiveGuard())->inspect($packagePath,$request['version'],$request['package_sha256']);
            if (version_compare(PHP_VERSION,$proof['requires_php'],'<') || version_compare((string)get_bloginfo('version'),$proof['requires_wordpress'],'<')) { refuse('plugin_version_not_eligible'); }
            $plan=['plan_id'=>bin2hex(random_bytes(16)),'expires_at'=>time()+1800,'status'=>'planned',
                'site'=>$request['site'],'home'=>$request['home'],'connection_id'=>$request['connection_id'],'generation'=>$generation,
                'current_version'=>SITE_MANAGER_VERSION,'version'=>$request['version'],'package_sha256'=>$request['package_sha256'],
                'source'=>$request['source'],'before_tree'=>$before['tree_sha256'],'after_tree'=>$proof['tree_sha256']];
            writePlan($planPath,$plan);
        } catch (\Throwable $e) { @unlink($packagePath); @unlink($planPath); throw $e; }
        unset($plan['connection_id'],$plan['generation'],$plan['home'],$plan['site']);
        receipt($request,$plan+['verified'=>false,'mutation_performed'=>false,'confirmation_required'=>true]); exit;
    }
    if ($stage==='apply') {
        if ($journal || ! hexvalue($request['plan_id'],32) || ! hexvalue($request['package_sha256'],64)) { refuse('reconciliation_required'); }
        $plan=privateJson($planPath);
        if (($plan['status'] ?? '')!=='planned' || ($plan['expires_at'] ?? 0)<time()
            || ($plan['plan_id'] ?? '')!==$request['plan_id'] || ($plan['package_sha256'] ?? '')!==$request['package_sha256']
            || ($plan['site'] ?? '')!==$request['site'] || ($plan['home'] ?? '')!==$request['home']
            || ($plan['connection_id'] ?? '')!==$request['connection_id'] || ($plan['generation'] ?? 0)!==$generation) { refuse('plan_mismatch_or_expired'); }
        $bytes=privateFile($packagePath,10_000_000);
        if (! hash_equals($plan['package_sha256'],hash('sha256',$bytes))) { refuse('package_digest_mismatch'); }
        unset($bytes);
        // Consume before mutation. Ambiguous failures require independent status.
        $plan['status']='consumed'; writePlan($planPath,$plan);
        $result=$engine->apply($packagePath,$plan['version'],$plan['package_sha256'],$plan['before_tree'],$plan['after_tree'],true);
        @unlink($packagePath);
        receipt($request,$result+['plan_id'=>$plan['plan_id'],'mutation_performed'=>true]); exit;
    }
    if (! is_array($journal) || ($journal['id'] ?? '')!==$request['operation_id']) { refuse('reconciliation_required'); }
    if ($stage==='verify') { receipt($request,$engine->runtimeReceipt($request['operation_id'])); exit; }
    if ($stage==='restore') { receipt($request,$engine->restore($request['operation_id'],true)); exit; }
    if ($stage==='finalize') {
        // Only the protected Gateway may invoke this after separate live MCP proof
        // and operator acceptance. The host cannot independently assert that proof.
        $result=$engine->finalize($request['operation_id'],true);
        @unlink($planPath); @unlink($packagePath);
        receipt($request,$result); exit;
    }
    refuse('worker_command_refused');
} catch (\Throwable $error) {
    while (ob_get_level()>0) { ob_end_clean(); }
    $code=$error->getMessage();
    $allowed=['worker_command_refused','worker_input_invalid','confirmation_required','private_state_unavailable',
        'worker_authority_unavailable','worker_private_directory_required','worker_site_identity_mismatch','worker_connection_mismatch',
        'worker_permissions_denied','worker_busy','reconciliation_required','package_digest_mismatch','plugin_version_not_eligible','plan_mismatch_or_expired'];
    fwrite(STDERR,"HOST_UPDATE_REFUSED:".(in_array($code,$allowed,true)?$code:'worker_internal_failure')."\n"); exit(1);
}
