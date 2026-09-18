<?php
require __DIR__.'/defense-regression.php';
function get_current_user_id(){return $GLOBALS['review_user']??1;}
function sanitize_key($v){return $v;}
function wp_unslash($v){return $v;}
function excluded_reset($zone='899904e844a1246a10e039c97925c370'){
 mock_zone(state_with(1));$GLOBALS['review_user']=1;
 $identity=D::excluded_identity($zone);$GLOBALS['home_urls']=[1=>'https://'.$identity['name']];
 update_option('cloudflare_zone_id',$zone);
 $GLOBALS['zone_response']=['id'=>$zone,'name'=>$identity['name'],'account'=>['id'=>$identity['account']],'status'=>'active','paused'=>false];
 $GLOBALS['zone_reads']=0;unset($GLOBALS['zone_callback'],$GLOBALS['zone_fail']);
 $base=$GLOBALS['defense_mock'];
 $GLOBALS['defense_mock']=function($url,$args)use($base,$zone){
  if(rtrim($url,'/')==='https://api.cloudflare.com/client/v4/zones/'.$zone){
   $GLOBALS['zone_reads']++;if(isset($GLOBALS['zone_callback']))($GLOBALS['zone_callback'])();
   return response($GLOBALS['zone_response'],empty($GLOBALS['zone_fail'])?200:403);
  }
  return $base($url,$args);
 };
 return $zone;
}
function rejects_review($fn,$label){try{$fn();}catch(RuntimeException $e){check(!$GLOBALS['write_number'],$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
function approve($zone,$r){return D::install($zone,['defense_baseline'=>true],null,$r['id']);}
foreach(array_keys((require dirname(__DIR__).'/includes/defense-adoptions.php')['_excluded']) as $zone){
 excluded_reset($zone);$r=D::review_excluded($zone);check(!$GLOBALS['write_number'],'historical exclusion can be reviewed when active: '.$zone);
 $result=approve($zone,$r);check($result['success']&&$GLOBALS['write_number']===3,'explicit approval onboards formerly excluded zone');
 $count=$GLOBALS['write_number'];$result=approve($zone,$r);check($result['success']&&$count===$GLOBALS['write_number'],'repeat approval is idempotent');
}
foreach(['pending','moved','initializing'] as $status){$zone=excluded_reset();$GLOBALS['zone_response']['status']=$status;rejects_review(fn()=>D::review_excluded($zone),'refuses current '.$status);}
$zone=excluded_reset();$GLOBALS['zone_response']['paused']=true;rejects_review(fn()=>D::review_excluded($zone),'refuses paused active zone');
foreach(['id','name','account'] as $field){$zone=excluded_reset();$GLOBALS['zone_response'][$field]=$field==='account'?['id'=>'wrong']:'wrong';rejects_review(fn()=>D::review_excluded($zone),'refuses wrong '.$field);}
$zone=excluded_reset();$GLOBALS['home_urls'][1]='https://other.test';rejects_review(fn()=>D::review_excluded($zone),'refuses wrong WordPress hostname');
$zone=excluded_reset();$GLOBALS['zone_fail']=true;rejects_review(fn()=>D::review_excluded($zone),'read failure sends no writes');
$zone=excluded_reset();$r=D::review_excluded($zone);$GLOBALS['zone_response']['status']='pending';check(!approve($zone,$r)['success']&&!$GLOBALS['write_number'],'approval rechecks current status');
$zone=excluded_reset();$r=D::review_excluded($zone);$GLOBALS['zone_callback']=function(){if($GLOBALS['zone_reads']>=3)$GLOBALS['zone_response']['paused']=true;};check(!approve($zone,$r)['success']&&!$GLOBALS['write_number'],'status rechecked before mutation');
$zone=excluded_reset();$r=D::review_excluded($zone);$GLOBALS['defense_state'][D::CUSTOM]['version']='2';check(!approve($zone,$r)['success']&&!$GLOBALS['write_number'],'rules drift after review blocks approval');
$zone=excluded_reset();$r=D::review_excluded($zone);$GLOBALS['review_user']=2;check(!approve($zone,$r)['success']&&!$GLOBALS['write_number'],'approval bound to reviewing user');
$zone=excluded_reset();$r=D::review_excluded($zone);$r['created']=time()-901;update_option('acfcm_security_review',$r);check(!approve($zone,$r)['success']&&!$GLOBALS['write_number'],'expired approval refused');
$zone=excluded_reset();$r=D::review_excluded($zone);$GLOBALS['fail_write']=2;check(!approve($zone,$r)['success'],'partial provider failure reported');unset($GLOBALS['fail_write']);$result=approve($zone,$r);check($result['success']&&count($GLOBALS['defense_state'][D::CUSTOM]['rules'])===3,'retry preserves unrelated rule and avoids duplicate successful additions');
$zone=excluded_reset();$r=D::review_excluded($zone);$GLOBALS['mutate_write']=function(){$GLOBALS['defense_state'][D::CUSTOM]['rules'][0]['expression']='false';};check(!approve($zone,$r)['success'],'concurrent mutation detected in readback');unset($GLOBALS['mutate_write']);check(!approve($zone,$r)['success']&&$GLOBALS['write_number']===1,'retry after drift requires fresh review');
$zone=excluded_reset();$GLOBALS['defense_state']=state_with(4);rejects_review(fn()=>D::review_excluded($zone),'quota checks retained for excluded zones');
$zone=excluded_reset();$cache=['status'=>'complete','cursor'=>8,'before'=>['saved'=>'backup']];update_option('acfcm_cache_migration_'.$zone,$cache);$scope=['host'=>'75pac.com','zone'=>$zone];update_option('acfcm_public_cache_scope',$scope);
$r=D::review_excluded($zone);approve($zone,$r);
check(get_option('acfcm_cache_migration_'.$zone)===$cache&&get_option('acfcm_public_cache_scope')===$scope&&!jobs(),'security review and approval leave completed cache journal and scope unchanged');
check(!array_filter($GLOBALS['defense_requests'],fn($r)=>strpos($r[1],'purge')!==false||strpos($r[1],'cache_settings')!==false),'security fix never requests cache phases or purge endpoints');
echo "All formerly excluded security regressions passed.\n";
