<?php
require __DIR__ . '/purge-regression.php';
if(!defined('WPMU_PLUGIN_DIR'))define('WPMU_PLUGIN_DIR','/tmp/acfcm-policy-unit/mu-plugins');
if(!is_dir(WPMU_PLUGIN_DIR))mkdir(WPMU_PLUGIN_DIR,0777,true);
function wp_unslash($v){return $v;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower($v));}
function apply_filters($hook,$value,...$args){return $value;}
function delete_option($k){unset($GLOBALS['opts'][$GLOBALS['blog']][$k]);return true;}
function get_main_network_id(){return 1;} function get_main_site_id($network=1){return 1;}
function get_current_user_id(){return $GLOBALS['user']??1;}
function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0777,true);}
class_alias('ACFCM_Cache_Policy','C');
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/cache/optimized.json'),true);
function cp_response($s,$code=200){return ['code'=>$code,'body'=>json_encode(['success'=>$code<300,'result'=>$s])];}
function cp_reset($state=null,$host='site1.test',$zone='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'){
 reset_state();$GLOBALS['home_urls']=[1=>'https://'.$host];update_option('cloudflare_zone_id',$zone);$GLOBALS['cp_state']=$state??[C::REQUEST=>null,C::RESPONSE=>null];$GLOBALS['cp_settings']=[P::TIERED_CACHE_SETTING_PATH=>['value'=>'off'],P::SMART_TIERED_CACHE_SETTING_PATH=>['value'=>'off']];$GLOBALS['cp_calls']=[];$GLOBALS['cp_writes']=0;$GLOBALS['cp_reads']=0;
 unset($GLOBALS['cp_fail'],$GLOBALS['cp_before_read'],$GLOBALS['cp_after_write']);
 $GLOBALS['defense_mock']=function($url,$args){
  $path=explode('/zones/',$url)[1];$path=substr($path,strpos($path,'/')+1);$method=$args['method'];$body=json_decode($args['body']??'null',true);$GLOBALS['cp_calls'][]=[$method,$path,$body];
  if(isset($GLOBALS['cp_settings'][$path])){
   if($method==='GET')return cp_response($GLOBALS['cp_settings'][$path]);
   if($method!=='PATCH')throw new RuntimeException('Unexpected setting method');
   $GLOBALS['cp_writes']++;if(($GLOBALS['cp_fail']??0)===$GLOBALS['cp_writes'])return cp_response(null,403);
   $GLOBALS['cp_settings'][$path]['value']=$body['value'];return cp_response($GLOBALS['cp_settings'][$path]);
  }
  if($method==='GET'){$GLOBALS['cp_reads']++;if(isset($GLOBALS['cp_before_read']))($GLOBALS['cp_before_read'])($GLOBALS['cp_reads']);$phase=explode('/',$path)[2];$s=$GLOBALS['cp_state'][$phase]??null;return cp_response($s,$s?200:404);}
  if(!in_array($method,['POST','DELETE'],true)||strpos($path,'purge')!==false)throw new RuntimeException('Forbidden write '.$method.' '.$path);
  $GLOBALS['cp_writes']++;if(($GLOBALS['cp_fail']??0)===$GLOBALS['cp_writes'])return cp_response(null,403);
  if($path==='rulesets'){$phase=$body['phase'];$GLOBALS['cp_state'][$phase]=['id'=>'new-'.$phase,'phase'=>$phase,'kind'=>'zone','version'=>'1','rules'=>[]];$rule=$body['rules'][0];}
  else{$phase=null;foreach($GLOBALS['cp_state'] as $p=>$s)if($s&&strpos($path,'rulesets/'.$s['id'].'/rules')===0)$phase=$p;if(!$phase)throw new RuntimeException('Unexpected URL '.$path);$rule=$body;}
  if($method==='DELETE'){$id=basename($path);$GLOBALS['cp_state'][$phase]['rules']=array_values(array_filter($GLOBALS['cp_state'][$phase]['rules'],fn($r)=>$r['id']!==$id));}
  else{$index=$rule['position']['index']??(count($GLOBALS['cp_state'][$phase]['rules'])+1);unset($rule['position']);$rule['id']='created-'.$GLOBALS['cp_writes'];$rule['version']='1';$rule['last_updated']='now';array_splice($GLOBALS['cp_state'][$phase]['rules'],$index-1,0,[$rule]);}
  $GLOBALS['cp_state'][$phase]['version']=(string)((int)$GLOBALS['cp_state'][$phase]['version']+1);
  if(isset($GLOBALS['cp_after_write']))($GLOBALS['cp_after_write'])($GLOBALS['cp_writes']);return cp_response($GLOBALS['cp_state'][$phase]);
 };
}
function cp_finish($zone){for($i=0;$i<35;$i++){$j=C::step($zone);if(in_array($j['status'],['complete','rolled_back','paused','conflict']))return $j;}throw new RuntimeException('Unbounded migration');}
function cp_begin(){ $p=C::preview(P::get_zone_id());C::begin($p['id']);return $p; }
function cp_fails($fn,$label){try{$fn();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException($label.' did not stop');}
foreach($fixtures as $f){cp_reset($f['after'],$f['host'],$f['zone']);$p=cp_begin();check($p['plan']['kind']==='adopt'&&!$GLOBALS['cp_writes'],'optimized adoption without writes '.$f['host']);$p=cp_begin();check(!$GLOBALS['cp_writes'],'optimized repeat '.$f['host']);}
cp_reset();$p=cp_begin();check(!$GLOBALS['cp_writes']&&file_exists(ACFCM_Runtime_Guard::path()),'backup and persistent guard precede provider writes');$j=cp_finish(P::get_zone_id());check($j['status']==='complete','new zone completes');check(count($GLOBALS['cp_state'][C::REQUEST]['rules'])===3&&count($GLOBALS['cp_state'][C::RESPONSE]['rules'])===3,'new coherent three request plus three response rules');$writes=$GLOBALS['cp_writes'];cp_begin();check($GLOBALS['cp_writes']===$writes,'new zone rerun is write-free');
foreach($fixtures as $f){cp_reset($f['before'],$f['host'],$f['zone']);$p=cp_begin();check($p['plan']['kind']==='migrate','verified historical migration '.$f['host']);$j=cp_finish($f['zone']);check($j['status']==='complete','historical migration completes '.$f['host']);check(count($GLOBALS['cp_state'][C::REQUEST]['rules'])===3,'old rules replaced without accumulation '.$f['host']);$writes=$GLOBALS['cp_writes'];$again=cp_begin();check($again['plan']['kind']==='adopt'&&$GLOBALS['cp_writes']===$writes,'migrated legacy rerun is read-only '.$f['host']);}
$legacy=P::recommended_cache_rules();foreach($legacy as $i=>&$r){$r['id']='legacy-'.$i;$r['ref']='legacy-'.$i;}unset($r);
function cp_legacy($rules){return [C::REQUEST=>['id'=>'legacy-set','phase'=>C::REQUEST,'kind'=>'zone','version'=>'1','rules'=>$rules],C::RESPONSE=>null];}
cp_reset(cp_legacy($legacy));$p=cp_begin();$j=cp_finish(P::get_zone_id());check($j['status']==='complete','exact legacy plugin rules migrate on an unknown zone');$p=C::rollback_preview(P::get_zone_id());C::begin_rollback($p['id']);$j=cp_finish(P::get_zone_id());check($j['status']==='rolled_back','rollback completes under hold');$restored=$GLOBALS['cp_state'][C::REQUEST]['rules'];foreach($restored as &$r){unset($r['id'],$r['version'],$r['last_updated']);}unset($r);$expected=$legacy;foreach($expected as &$r)unset($r['id']);unset($r);check($restored===$expected&&empty($GLOBALS['cp_state'][C::RESPONSE]['rules']),'rollback restores old definitions and removes only new response rules');
$exception=['id'=>'exception','ref'=>'external-exception','action'=>'set_cache_settings','enabled'=>true,'description'=>'Never cache donations','expression'=>'http.request.uri.path eq "/donate/"','action_parameters'=>['cache'=>false]];
cp_reset(cp_legacy(array_merge($legacy,[$exception])));cp_begin();$j=cp_finish(P::get_zone_id());check($j['status']==='complete'&&end($GLOBALS['cp_state'][C::REQUEST]['rules'])===$exception,'intentional bypass exception retains ID expression and precedence');
$modified=$legacy;$modified[0]['expression']='http.host eq "site1.test"';cp_reset(cp_legacy($modified));cp_fails(fn()=>C::preview(P::get_zone_id()),'modified description-matched legacy rule fails closed');
$unsafe=$exception;$unsafe['action_parameters']=['cache'=>true];cp_reset(cp_legacy([$unsafe]));cp_fails(fn()=>C::preview(P::get_zone_id()),'unknown overlapping caching policy stops');
cp_reset(cp_legacy($legacy));$p=C::preview(P::get_zone_id());$GLOBALS['cp_state'][C::REQUEST]['version']='2';cp_fails(fn()=>C::begin($p['id']),'concurrent change since preview stops without writes');
cp_reset(cp_legacy($legacy));cp_begin();$GLOBALS['cp_after_write']=function(){ $GLOBALS['cp_state'][C::REQUEST]['rules'][0]['description']='Concurrent edit';};$j=C::step(P::get_zone_id());check($j['status']==='conflict'&&$GLOBALS['cp_writes']===1,'post-write drift stops and preserves external change');
foreach([1,2,4,7] as $fail){cp_reset(cp_legacy($legacy));cp_begin();$GLOBALS['cp_fail']=$fail;$j=cp_finish(P::get_zone_id());check($j['status']==='paused'&&$GLOBALS['cp_writes']===$fail,'partial API/entitlement failure stops without downgrade '.$fail);unset($GLOBALS['cp_fail']);$j=cp_finish(P::get_zone_id());check($j['status']==='complete','explicit resume safely completes '.$fail);}
$full=[];for($i=0;$i<10;$i++){$r=$exception;$r['id']='ext'.$i;$r['ref']='ext'.$i;$full[]=$r;}cp_reset(cp_legacy($full));cp_fails(fn()=>C::preview(P::get_zone_id()),'quota reserves space for transition hold');
cp_reset();$p=C::preview(P::get_zone_id());$p['created']=time()-1000;update_option('acfcm_cache_preview',$p);cp_fails(fn()=>C::begin($p['id']),'expired preview cannot mutate');
cp_reset();$p=cp_begin();$GLOBALS['opts'][1]['acfcm_cache_lock_'.P::get_zone_id()]=time();cp_fails(fn()=>C::step(P::get_zone_id()),'concurrent local worker blocked');
cp_reset();check(!P::upsert_recommended_cache_rules(P::get_zone_id(),$legacy)['success']&&!$GLOBALS['cp_writes'],'legacy direct installer cannot write');
$src=file_get_contents(dirname(__DIR__).'/includes/class-acfcm-cache-policy.php');check(strpos($src,"self::api( 'PUT'")===false&&strpos($src,'purge_cache')===false,'migration never replaces whole rulesets or purges caches');
cp_reset();$r=P::install_cache_and_default_security_rules(P::get_zone_id());check($r['success']&&!$GLOBALS['cp_writes']&&!empty(get_option('acfcm_cache_preview')['combined']),'combined cache/security setup only prepares an explicit preview');

cp_reset();update_option('acfcm_smart_tiered_cache_enabled','1');$p=cp_begin();check($p['tiered_before'][P::TIERED_CACHE_SETTING_PATH]['value']==='off','tiered settings included in complete preview and backup');$j=cp_finish(P::get_zone_id());check($j['status']==='complete'&&!$j['error']&&$GLOBALS['cp_settings'][P::SMART_TIERED_CACHE_SETTING_PATH]['value']==='on','tiered settings enabled with verified readback');
cp_reset();update_option('acfcm_smart_tiered_cache_enabled','1');cp_begin();$GLOBALS['cp_settings'][P::TIERED_CACHE_SETTING_PATH]['value']='on';$j=cp_finish(P::get_zone_id());check($j['status']==='complete'&&strpos($j['error'],'changed since preview')!==false&&$GLOBALS['cp_settings'][P::SMART_TIERED_CACHE_SETTING_PATH]['value']==='off','tiered drift stops setting writes and remains visible');
cp_reset();update_option('acfcm_smart_tiered_cache_enabled','1');cp_begin();$GLOBALS['cp_fail']=9;$j=cp_finish(P::get_zone_id());check($j['status']==='complete'&&$j['error']&&$GLOBALS['cp_settings'][P::SMART_TIERED_CACHE_SETTING_PATH]['value']==='off','tiered failure is visible with no method or safety fallback');
cp_reset();cp_begin();cp_finish(P::get_zone_id());$original=C::store(P::get_zone_id());cp_begin();check(C::store(P::get_zone_id())['id']===$original['id'],'idempotent revisit retains original rollback point');
cp_reset();$r=P::install_cache_and_default_security_rules(P::get_zone_id());$p=get_option('acfcm_cache_preview');check(isset($p['defense_before'],$p['defense_plan']),'combined preview includes complete security snapshots and additive plan');$_POST=['preview_id'=>$p['id'],'profile_ack'=>1,'also_defense'=>1];ACFCM_Cache_Admin::execute('begin');for($i=0;$i<20;$i++){$r=ACFCM_Cache_Admin::execute('step');if($r['status']!=='running')break;}check($r['status']==='complete'&&!empty($r['defense']['success']),'explicit combined apply completes cache then reviewed security setup');
cp_reset();P::install_cache_and_default_security_rules(P::get_zone_id());$p=get_option('acfcm_cache_preview');$_POST=['preview_id'=>$p['id'],'profile_ack'=>1,'also_defense'=>1];ACFCM_Cache_Admin::execute('begin');$GLOBALS['cp_state'][ACFCM_Defense::CUSTOM]=['id'=>'external','phase'=>ACFCM_Defense::CUSTOM,'kind'=>'zone','version'=>'1','rules'=>[]];for($i=0;$i<20;$i++){$r=ACFCM_Cache_Admin::execute('step');if($r['status']!=='running')break;}check($r['status']==='complete'&&empty($r['defense']['success'])&&strpos($r['defense']['message'],'changed since')!==false,'security drift after combined preview refuses firewall writes');
cp_reset();cp_begin();cp_finish(P::get_zone_id());$original=$GLOBALS['cp_state'];update_option('acfcm_cache_reserve_enabled','1');cp_begin();cp_finish(P::get_zone_id());$p=C::rollback_preview(P::get_zone_id());C::begin_rollback($p['id']);$j=cp_finish(P::get_zone_id());foreach(C::phases() as $phase){$norm=fn($rs)=>array_map(fn($r)=>C::clean($r,false),$rs);check($norm($GLOBALS['cp_state'][$phase]['rules'])===$norm($original[$phase]['rules']),'rollback restores modified definitions sharing managed refs '.$phase);}
cp_reset();cp_begin();$j=C::store(P::get_zone_id());$j['site']=2;C::store(P::get_zone_id(),$j);cp_fails(fn()=>C::preview(P::get_zone_id()),'another site cannot reuse a zone migration journal');
cp_reset();$path=ACFCM_Runtime_Guard::path();$bytes=file_get_contents($path);try{file_put_contents($path,$bytes."\n// unknown edit\n");cp_fails(fn()=>C::preview(P::get_zone_id()),'unknown persistent guard fails closed');}finally{file_put_contents($path,$bytes);}

unset($GLOBALS['defense_mock']);echo "All cache policy regressions passed.\n";

// A reviewed template is trusted only for this exact zone, hostname and full ruleset.
$phil=json_decode(file_get_contents(__DIR__.'/fixtures/cache/philberger-legacy.json'),true);
cp_reset($phil['before'],$phil['host'],$phil['zone']);
$r=P::install_recommended_cache_rules($phil['zone']);
check($r['success']&&!$GLOBALS['cp_writes']&&get_option('acfcm_cache_preview'),'reviewed Phil Berger template produces read-only install preview');
$preview=get_option('acfcm_cache_preview');C::begin($preview['id']);$j=cp_finish($phil['zone']);
check($j['status']==='complete'&&count($GLOBALS['cp_state'][C::REQUEST]['rules'])===3&&count($GLOBALS['cp_state'][C::RESPONSE]['rules'])===3,'reviewed Phil Berger template migrates both cache phases');
$p=C::rollback_preview($phil['zone']);C::begin_rollback($p['id']);cp_finish($phil['zone']);
check(count($GLOBALS['cp_state'][C::REQUEST]['rules'])===2&&$GLOBALS['cp_state'][C::REQUEST]['rules'][0]['expression']==='true'&&$GLOBALS['cp_state'][C::REQUEST]['rules'][1]['expression']===$phil['before'][C::REQUEST]['rules'][1]['expression'],'Phil Berger rollback restores original template and login bypass');
$changed=$phil['before'];$changed[C::REQUEST]['rules'][0]['action_parameters']['edge_ttl']=['mode'=>'override_origin','default'=>60];
cp_reset($changed,$phil['host'],$phil['zone']);update_option('acfcm_cache_preview',['id'=>'stale']);
$r=P::install_recommended_cache_rules($phil['zone']);
check($r['success']&&get_option('acfcm_cache_preview')['plan']['kind']==='replace'&&!$GLOBALS['cp_writes'],'modified template offers explicit replacement preview without writes');
cp_reset($phil['before'],'other.test','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
$r=P::install_recommended_cache_rules(P::get_zone_id());check($r['success']&&get_option('acfcm_cache_preview')['plan']['kind']==='replace'&&!$GLOBALS['cp_writes'],'unfamiliar zone offers explicit blanket replacement without writes');
cp_reset($phil['before'],$phil['host'],$phil['zone']);update_option('acfcm_cache_preview_error',['user'=>1,'message'=>'old failure']);
$r=P::install_recommended_cache_rules($phil['zone']);check($r['success']&&!get_option('acfcm_cache_preview_error'),'successful preview clears previous failure');
echo "All preview preparation regressions passed.\n";

cp_reset();update_option('acfcm_cache_preview',['id'=>'stale']);$r=P::install_recommended_cache_rules('invalid');
check(!$r['success']&&!get_option('acfcm_cache_preview')&&get_option('acfcm_cache_preview_error'),'invalid preview removes stale Apply and stores error');
cp_reset($changed,$phil['host'],$phil['zone']);P::install_recommended_cache_rules($phil['zone']);$p=get_option('acfcm_cache_preview');
$GLOBALS['cp_state'][C::REQUEST]['version']='new-version';
cp_fails(fn()=>C::begin($p['id']),'replacement still rejects provider changes after preview');
cp_reset($changed,$phil['host'],$phil['zone']);P::install_recommended_cache_rules($phil['zone']);$p=get_option('acfcm_cache_preview');
$guard=ACFCM_Runtime_Guard::path();$bytes=file_get_contents($guard);file_put_contents($guard,'modified');
try { C::begin($p['id']); } catch (RuntimeException $e) {}
check(C::store($phil['zone'])['status']==='paused'&&!$GLOBALS['cp_writes'],'guard installation failure pauses journal before provider writes');
file_put_contents($guard,$bytes);$j=cp_finish($phil['zone']);
check($j['status']==='complete'&&count($GLOBALS['cp_state'][C::REQUEST]['rules'])===3,'resume after guard repair completes blanket replacement');
$p=C::rollback_preview($phil['zone']);C::begin_rollback($p['id']);cp_finish($phil['zone']);
check($GLOBALS['cp_state'][C::REQUEST]['rules'][0]['action_parameters']===$changed[C::REQUEST]['rules'][0]['action_parameters'],'blanket replacement rollback restores unfamiliar original settings');
echo "All blanket replacement and guard recovery checks passed.\n";
