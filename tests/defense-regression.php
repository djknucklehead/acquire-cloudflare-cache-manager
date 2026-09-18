<?php
require __DIR__ . '/purge-regression.php';
function delete_option($k){unset($GLOBALS['opts'][$GLOBALS['blog']][$k]);return true;}
function get_main_network_id(){return 1;}
function get_main_site_id($network=1){return 1;}
class_alias('ACFCM_Defense','D');
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/defense/rollout.json'),true);
$baseline=json_decode(file_get_contents(__DIR__.'/fixtures/defense/baseline.json'),true);
function response($s,$code=200){return ['code'=>$code,'body'=>json_encode(['success'=>$code<300,'result'=>$s])];}
function mock_zone($states){
 reset_state();$GLOBALS['defense_state']=$states;$GLOBALS['defense_requests']=[];$GLOBALS['write_number']=0;$GLOBALS['read_number']=0;unset($GLOBALS['mutate_read'],$GLOBALS['mutate_write'],$GLOBALS['fail_write']);
 $GLOBALS['defense_mock']=function($url,$args){
  $method=$args['method'];$path=explode('/zones/', $url)[1];$path=substr($path,strpos($path,'/')+1);$payload=json_decode($args['body']??'null',true);
  $GLOBALS['defense_requests'][]=[$method,$path,$payload];
  if($method==='GET'){
   $GLOBALS['read_number']++;
   if(isset($GLOBALS['mutate_read']))($GLOBALS['mutate_read'])($GLOBALS['read_number']);
   $phase=explode('/',$path)[2];$s=$GLOBALS['defense_state'][$phase]??null;return response($s,$s?200:404);
  }
  if($method!=='POST')throw new RuntimeException('Destructive method '.$method);
  $GLOBALS['write_number']++;
  if(($GLOBALS['fail_write']??0)===$GLOBALS['write_number'])return response(null,503);
  if($path==='rulesets'){$phase=$payload['phase'];$GLOBALS['defense_state'][$phase]=['id'=>'new-'.$phase,'kind'=>'zone','phase'=>$phase,'version'=>'1','rules'=>[]];$rule=$payload['rules'][0];}
  else{$phase=null;foreach($GLOBALS['defense_state'] as $p=>$s)if($s&&$path==='rulesets/'.$s['id'].'/rules')$phase=$p;if(!$phase)throw new RuntimeException('Invalid rule URL');$rule=$payload;}
  $rule['id']='new-rule-'.$GLOBALS['write_number'];$rule['version']='1';$rule['last_updated']='now';$GLOBALS['defense_state'][$phase]['rules'][]=$rule;$GLOBALS['defense_state'][$phase]['version']=(string)((int)$GLOBALS['defense_state'][$phase]['version']+1);
  if(isset($GLOBALS['mutate_write']))($GLOBALS['mutate_write'])($GLOBALS['write_number']);
  return response($GLOBALS['defense_state'][$phase]);
 };
}
function apply_baseline($zone='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'){return P::install_recommended_hardening_rules($zone,['defense_baseline'=>true]);}
function state_with($n){$rules=[];for($i=0;$i<$n;$i++)$rules[]=['id'=>'external-'.$i,'ref'=>'external-'.$i,'enabled'=>true,'action'=>'block','description'=>'External policy '.$i,'expression'=>'ip.src eq 192.0.2.'.($i+1)];return [D::CUSTOM=>['id'=>'custom','version'=>'1','kind'=>'zone','phase'=>D::CUSTOM,'rules'=>$rules],D::RATE=>null];}
foreach($fixtures as $f){
 mock_zone($f['after']);$before=$GLOBALS['defense_state'];$r=apply_baseline($f['zone']);$again=apply_baseline($f['zone']);
 check($r['success']&&$again['success']&&$GLOBALS['write_number']===0&&$before===$GLOBALS['defense_state'],'rollout repeat read-only: '.$f['name']);
 // Old checkboxes and the old combined install's default selection cannot recreate split blocks.
 $r=P::install_recommended_hardening_rules($f['zone'],['wordpress_probes'=>true,'xmlrpc'=>true,'legal_query_challenge'=>true,'legal_query_rate_limit'=>true]);
 check($r['success']&&$GLOBALS['write_number']===0,'legacy selections retain adopted policy: '.$f['name']);
 $GLOBALS['defense_state'][D::CUSTOM]['rules'][0]['expression'].=' or true';
 $r=apply_baseline($f['zone']);check(!$r['success']&&$GLOBALS['write_number']===0,'rollout drift blocks writes: '.$f['name']);
}
foreach([0,3,4,5] as $n){
 mock_zone(state_with($n));$prior=$GLOBALS['defense_state'][D::CUSTOM]['rules'];$r=apply_baseline();
 check($r['success']===($n<=3),'quota preflight '.$n.' existing rules');
 check($GLOBALS['write_number']===($n<=3?3:0),'quota writes '.$n);
 check(array_slice($GLOBALS['defense_state'][D::CUSTOM]['rules'],0,$n)===$prior,'external IDs expressions and order retained '.$n);
 if($n<=3){$r=apply_baseline();check($r['success']&&$GLOBALS['write_number']===3,'new baseline repeat is no-op '.$n);}
}
mock_zone([D::CUSTOM=>null,D::RATE=>null]);$r=apply_baseline();check($r['success']&&$GLOBALS['write_number']===3,'absent phases created with baseline');
$requests=$GLOBALS['defense_requests'];$writes=array_values(array_filter($requests,fn($r)=>$r[0]==='POST'));check($writes[1][2]['ref']===$baseline['guard']['ref']&&$writes[2][2]['rules'][0]['ref']===$baseline['rate']['ref'],'guard installed before rate policy');
foreach([false,true] as $enabled){$s=state_with(0);$s[D::RATE]=['id'=>'rate','version'=>'1','kind'=>'zone','phase'=>D::RATE,'rules'=>[['id'=>'foreign','ref'=>'foreign','action'=>'block','enabled'=>$enabled,'expression'=>'true','ratelimit'=>['period'=>10,'requests_per_period'=>200]]]];mock_zone($s);$r=apply_baseline();check(!$r['success']&&!$GLOBALS['write_number'],'external '.($enabled?'active':'disabled').' limiter retained');}
$s=state_with(0);$s[D::CUSTOM]['rules'][]=['ref'=>'external-skip','action'=>'skip','expression'=>'true','enabled'=>true,'action_parameters'=>['phases'=>['http_request_firewall_managed']]];mock_zone($s);$r=apply_baseline();check(!$r['success']&&!$GLOBALS['write_number'],'external skip requires review');
foreach([1,2,3] as $fail){mock_zone(state_with(0));$GLOBALS['fail_write']=$fail;$r=apply_baseline();check(!$r['success']&&$GLOBALS['write_number']===$fail&&strpos($r['message'],'Partial or uncertain')!==false,'partial failure stops without rollback '.$fail);unset($GLOBALS['fail_write']);$r=apply_baseline();check($r['success'],'explicit retry reconciles partial installation '.$fail);}
mock_zone(state_with(0));$GLOBALS['mutate_read']=function($n){if($n===3)$GLOBALS['defense_state'][D::CUSTOM]['version']='2';};$r=apply_baseline();check(!$r['success']&&!$GLOBALS['write_number'],'concurrent drift before write stops');
mock_zone(state_with(0));$GLOBALS['mutate_write']=function($n){$GLOBALS['defense_state'][D::CUSTOM]['rules'][0]['expression']='true';};$r=apply_baseline();check(!$r['success']&&$GLOBALS['write_number']===1&&$GLOBALS['defense_state'][D::CUSTOM]['rules'][0]['expression']==='true','concurrent post-write change preserved and further writes stopped');
mock_zone(state_with(0));$GLOBALS['mutate_write']=function($n){$GLOBALS['defense_state'][D::RATE]=['id'=>'external-rate','version'=>'2','kind'=>'zone','phase'=>D::RATE,'rules'=>[]];};$r=apply_baseline();check(!$r['success']&&$GLOBALS['write_number']===1,'cross-phase concurrent change stops');
mock_zone(state_with(0));add_option('acfcm_defense_lock_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',time());$r=apply_baseline();check(!$r['success']&&!$GLOBALS['defense_requests'],'duplicate installer blocked before HTTP');delete_option('acfcm_defense_lock_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
mock_zone(state_with(0));$GLOBALS['multi']=true;$GLOBALS['blog']=2;update_option('cloudflare_api_token','second-secret');$r=apply_baseline();check($r['success']&&get_current_blog_id()===2&&empty($GLOBALS['opts'][1]['acfcm_defense_lock_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']),'multisite main lock restores caller and token context');
mock_zone(state_with(0));$GLOBALS['defense_mock']=fn()=>response(null,403);$r=apply_baseline();check(!$r['success']&&strpos($r['message'],'permissions')!==false,'API permission failure is actionable');
mock_zone(state_with(0));$GLOBALS['defense_mock']=fn()=>response(['rules'=>[]]);$r=apply_baseline();check(!$r['success'],'malformed successful API response rejected');
$legacy=P::recommended_wordpress_probe_rule();$live=$legacy;$live['id']='legacy';$live['ref']='legacy';check(P::merge_hardening_rules([$live],[$legacy])===[$live],'exact legacy adoption retains ID/ref');
$live['expression']='('.$legacy['expression'].') or (ip.src eq 192.0.2.1)';try{P::merge_hardening_rules([$live],[$legacy]);throw new Exception('Unsafe merge accepted');}catch(RuntimeException $e){check(true,'description-matched augmented rule cannot be replaced');}
$source=file_get_contents(dirname(__DIR__).'/includes/class-acfcm-defense.php');check(strpos($source,"self::request( 'PUT'")===false&&strpos($source,"self::request( 'PATCH'")===false&&strpos($source,"self::request( 'DELETE'")===false,'defense API never overwrites existing policies');
// Network entry point must also be repeatable and retain independently deployed zones.
mock_zone($fixtures[0]['after']);$GLOBALS['multi']=true;
$byzone=[];foreach(array_slice($fixtures,0,2) as $i=>$f){$byzone[$f['zone']]=$f['after'];$GLOBALS['opts'][$i+1]['cloudflare_zone_id']=$f['zone'];}
$baseMock=$GLOBALS['defense_mock'];$GLOBALS['defense_mock']=function($url,$args)use($baseMock,$byzone){preg_match('#/zones/([^/]+)/#',$url,$m);$GLOBALS['defense_state']=$byzone[$m[1]];return $baseMock($url,$args);};
$r=P::install_recommended_hardening_rules_for_enabled_zones(['defense_baseline'=>true]);$again=P::install_recommended_hardening_rules_for_enabled_zones(['defense_baseline'=>true]);
check($r['success']&&$again['success']&&!$GLOBALS['write_number'],'network repeated verification retains deployed rules');
mock_zone(state_with(0));apply_baseline();$GLOBALS['defense_state'][D::CUSTOM]['rules'][1]['action_parameters']['phases'][]='http_request_firewall_managed';$GLOBALS['write_number']=0;$r=apply_baseline();
check(!$r['success']&&!$GLOBALS['write_number'],'broader WAF skip is rejected as drift');
mock_zone(state_with(0));apply_baseline();$GLOBALS['defense_state'][D::RATE]['rules'][0]['enabled']=false;$GLOBALS['write_number']=0;$r=apply_baseline();
check(!$r['success']&&!$GLOBALS['write_number'],'disabled baseline is not silently reenabled');
foreach((require dirname(__DIR__).'/includes/defense-adoptions.php')['_excluded'] as $zone=>$status){mock_zone(state_with(0));$r=apply_baseline($zone);check(!$r['success']&&!$GLOBALS['write_number'],'excluded rollout zone requires review '.$status);}
unset($GLOBALS['defense_mock']);
echo "All defense regression tests passed.\n";
