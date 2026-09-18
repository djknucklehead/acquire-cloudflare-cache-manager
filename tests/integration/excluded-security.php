<?php
$root=realpath($argv[1]??'');
if(!$root||!preg_match('#^/(private/)?tmp/acfcm-#',$root))exit('Disposable fixture required.');
require $root.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/template.php';
function sx($ok,$m){if(!$ok)throw new RuntimeException($m);echo "PASS $m\n";}
$zone='899904e844a1246a10e039c97925c370';$site=is_multisite()?2:1;
wp_set_current_user(1);
if(is_multisite())switch_to_blog($site);
$keys=['cloudflare_zone_id','cloudflare_api_token','acfcm_security_review','acfcm_security_result','acfcm_security_backup'];
$old=[];foreach($keys as $k)$old[$k]=get_option($k,null);
update_option('cloudflare_zone_id',$zone);update_option('cloudflare_api_token','fake-test-token');
delete_option('acfcm_security_review');
$cache_before=ACFCM_Cache_Policy::store($zone);$scope_before=get_option('acfcm_public_cache_scope',[]);
add_filter('home_url',function($url){return 'https://75pac.com/';},999);
$states=[];$writes=0;$paths=[];
add_filter('pre_http_request',function($pre,$args,$url)use($zone,&$states,&$writes,&$paths){
 $path=substr($url,strlen('https://api.cloudflare.com/client/v4/zones/'.$zone));$paths[]=$path;$code=200;
 if($args['method']==='GET'&&($path===''||$path==='/'))$result=['id'=>$zone,'name'=>'75pac.com','account'=>['id'=>'63ef3a537b1cffce309b6a7645491560'],'status'=>'active','paused'=>false];
 elseif($args['method']==='GET'){$phase=explode('/',$path)[3];$result=$states[$phase]??null;$code=$result?200:404;}
 elseif($args['method']==='POST'){
  $writes++;$body=json_decode($args['body'],true);
  if($path==='/rulesets'){$phase=$body['phase'];$states[$phase]=['id'=>'set-'.$phase,'kind'=>'zone','phase'=>$phase,'version'=>'1','rules'=>[]];$rule=$body['rules'][0];}
  else{foreach($states as $p=>$s)if($path==='/rulesets/'.$s['id'].'/rules')$phase=$p;$rule=$body;}
  $rule['id']='rule-'.$writes;$states[$phase]['rules'][]=$rule;$states[$phase]['version']=(string)(1+$writes);$result=$states[$phase];
 }else throw new RuntimeException('Unexpected provider mutation');
 return ['response'=>['code'=>$code],'body'=>wp_json_encode(['success'=>$code<300,'result'=>$result]),'headers'=>[]];
},PHP_INT_MAX,3);
try{
 $nonce=wp_create_nonce('acfcm_network_zone_'.$site.'_'.$zone);
 $request=['blog_id'=>$site,'zone'=>$zone,'_wpnonce'=>$nonce,'policy_action'=>'security'];
 if(is_multisite())restore_current_blog();
 $call=function($action,$id='')use(&$request){$request['policy_action']=$action;$request['review_id']=$id;return is_multisite()?ACFCM_Cache_Admin::network_execute($request):ACFCM_Cache_Admin::execute($action,$id);};
 $start=get_current_blog_id();$r=$call('security');
 sx($r['status']==='complete'&&$writes===6,'one click installs all recommended security rules');
 sx(get_current_blog_id()===$start,'installation restores network context');
 if(is_multisite()){
  ob_start();ACFCM_Cache_Admin::network_site_buttons(['blog_id'=>$site,'zone_id'=>$zone]);$html=ob_get_clean();
  sx(strpos($html,'Approve security onboarding')===false&&strpos($html,'Install cache')!==false&&strpos($html,'Install security rules')!==false,'network row has direct install buttons');
  ob_start();ACFCM_Cache_Admin::network_zones([['blog_id'=>$site,'zone_id'=>$zone]]);$forms=ob_get_clean();
  sx(strpos($forms,'security_approve')===false,'network forms have no approval step');
 }
 $r=$call('security');sx($r['status']==='complete'&&$writes===6,'repeat one-click install does not duplicate rules');
 if(is_multisite())switch_to_blog($site);
 sx(!empty(get_option('acfcm_security_backup')),'automatic security backup exists');
 sx(ACFCM_Cache_Policy::store($zone)===$cache_before&&get_option('acfcm_public_cache_scope',[])===$scope_before,'cache journal and runtime scope unchanged');
 ob_start();ACFCM_Cache_Admin::policy();$html=ob_get_clean();
 sx(strpos($html,'Approve security onboarding')===false&&strpos($html,'Security: All recommended security rules')!==false,'completion replaces approval with separate security outcome');
 sx(!array_filter($paths,fn($p)=>strpos($p,'purge')!==false||strpos($p,'cache_settings')!==false),'no cache API or purge requests');
}finally{
 if(get_current_blog_id()!==$site&&is_multisite())switch_to_blog($site);
 foreach($old as $k=>$v){if($v===null)delete_option($k);else update_option($k,$v,false);}
 if(is_multisite())restore_current_blog();
}
echo "Formerly excluded security UI integration passed.\n";
