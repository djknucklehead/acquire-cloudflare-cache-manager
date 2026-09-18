<?php
// Run with php -d disable_functions=link tests/runtime-guard-install.php.
define('ABSPATH', __DIR__);
define('WPMU_PLUGIN_DIR', sys_get_temp_dir().'/acfcm-guard-install-'.getmypid());
function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0777,true);}
function get_option($k,$default=[]){return $GLOBALS['options'][$k]??$default;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;}
require dirname(__DIR__).'/includes/class-acfcm-runtime-guard.php';
try {
    ACFCM_Runtime_Guard::install('example.test','test-zone');
    if(hash_file('sha256',ACFCM_Runtime_Guard::path())!==hash_file('sha256',dirname(__DIR__).'/includes/public-cache-guard.php'))throw new RuntimeException('Guard bytes differ');
    echo "PASS guard installs without hard-link support\n";
    ACFCM_Runtime_Guard::install('example.test','test-zone');
    echo "PASS repeated install accepts verified existing guard\n";
    file_put_contents(ACFCM_Runtime_Guard::path(),'unknown guard');
    try {ACFCM_Runtime_Guard::install('example.test','test-zone');throw new LogicException('Overwrote unknown guard');}
    catch(RuntimeException $e){echo "PASS unknown existing guard is preserved\n";}
    if(file_get_contents(ACFCM_Runtime_Guard::path())!=='unknown guard')throw new RuntimeException('Unknown file changed');
    if(glob(WPMU_PLUGIN_DIR.'/.acfcm-*')!==[WPMU_PLUGIN_DIR.'/.acfcm-guard-install.lock'])throw new RuntimeException('Temporary file left behind');
    echo "PASS temporary files cleaned up\n";
} finally {
    foreach(glob(WPMU_PLUGIN_DIR.'/*')?:[] as $f)unlink($f);
    foreach(glob(WPMU_PLUGIN_DIR.'/.acfcm-*')?:[] as $f)unlink($f);
    if(is_dir(WPMU_PLUGIN_DIR))rmdir(WPMU_PLUGIN_DIR);
}
