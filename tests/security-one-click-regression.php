<?php
require __DIR__.'/defense-excluded-regression.php';
function full_reset(){
 $zone=excluded_reset();$GLOBALS['defense_state']=state_with(0);
 return $zone;
}
function full_install($zone){return D::install($zone,['complete_security'=>true]);}
$zone=full_reset();$r=ACFCM_Cache_Admin::execute('security');
check($r['status']==='complete'&&$GLOBALS['write_number']===6,'one click installs all five custom rules and one rate rule');
check(count($GLOBALS['defense_state'][D::CUSTOM]['rules'])===5&&count($GLOBALS['defense_state'][D::RATE]['rules'])===1,'full recommended coverage fits Free plan');
check(get_option('acfcm_security_backup')['state'][D::CUSTOM]['rules']===[],'pre-install security backup saved automatically');
$r=full_install($zone);check($r['success']&&$GLOBALS['write_number']===6,'repeat click is verified no-op');
$zone=full_reset();$r=D::review_excluded($zone);$r=D::install($zone,['defense_baseline'=>true],null,$r['id']);$before=$GLOBALS['defense_state'];$GLOBALS['write_number']=0;$r=full_install($zone);
check($r['success']&&$GLOBALS['write_number']===3,'existing three-rule baseline receives three missing legacy protections');
check(array_slice($GLOBALS['defense_state'][D::CUSTOM]['rules'],0,2)===$before[D::CUSTOM]['rules']&&$GLOBALS['defense_state'][D::RATE]===$before[D::RATE],'existing baseline IDs order and limiter retained');
$zone=full_reset();foreach([P::recommended_wordpress_probe_rule(),P::recommended_xmlrpc_block_rule(),P::recommended_legal_query_challenge_rule()] as $i=>$rule){$rule['id']='old-'.$i;$rule['ref']='old-'.$i;$GLOBALS['defense_state'][D::CUSTOM]['rules'][]=$rule;}$before=$GLOBALS['defense_state'][D::CUSTOM]['rules'];$r=full_install($zone);
check($r['success']&&$GLOBALS['write_number']===3&&array_slice($GLOBALS['defense_state'][D::CUSTOM]['rules'],0,3)===$before,'legacy IDs adopted by exact content with no duplicates');
foreach(['pending','moved'] as $status){$zone=full_reset();$GLOBALS['zone_response']['status']=$status;$r=full_install($zone);check(!$r['success']&&!$GLOBALS['write_number'],'one click rejects '.$status.' without approval detour');}
$zone=full_reset();$GLOBALS['zone_callback']=function(){if($GLOBALS['zone_reads']>=2)$GLOBALS['zone_response']['paused']=true;};$r=full_install($zone);check(!$r['success']&&!$GLOBALS['write_number'],'zone status rechecked before first mutation');
$zone=full_reset();$GLOBALS['defense_state']=state_with(1);$before=$GLOBALS['defense_state'];$r=full_install($zone);check(!$r['success']&&!$GLOBALS['write_number']&&$before===$GLOBALS['defense_state'],'capacity conflict preserves unrelated rule');
$zone=full_reset();$GLOBALS['fail_write']=3;$r=full_install($zone);check(!$r['success'],'partial failure reported');unset($GLOBALS['fail_write']);$r=full_install($zone);check($r['success']&&count($GLOBALS['defense_state'][D::CUSTOM]['rules'])===5,'retry completes missing rules without duplication');
$source=file_get_contents(dirname(__DIR__).'/includes/class-acfcm-cache-admin.php');check(strpos($source,'Approve security onboarding')===false&&strpos($source,"'Install cache'")!==false,'network and subsite use direct installation controls');
echo "All one-click security regressions passed.\n";

foreach(['site1.test','sub.site1.test','unrelated.test'] as $host){
 mock_zone(state_with(0));$zone='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';update_option('cloudflare_zone_id',$zone);$GLOBALS['home_urls']=[1=>'https://'.$host];$base=$GLOBALS['defense_mock'];
 $GLOBALS['defense_mock']=function($url,$args)use($base,$zone){if(rtrim($url,'/')==='https://api.cloudflare.com/client/v4/zones/'.$zone)return response(['id'=>$zone,'name'=>'site1.test','status'=>'active','paused'=>false]);return $base($url,$args);};
 $r=full_install($zone);check($r['success']===($host!=='unrelated.test'),'normal zone hostname validation '.$host);
}
