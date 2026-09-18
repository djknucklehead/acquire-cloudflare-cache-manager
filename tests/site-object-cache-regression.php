<?php
require __DIR__ . '/purge-regression.php';
function get_taxonomies(){return ['category'];}
function wp_cache_get($key,$group='',$force=false,&$found=null){$found=array_key_exists($key,$GLOBALS['objects'][get_current_blog_id()][$group]??[]);return $GLOBALS['objects'][get_current_blog_id()][$group][$key]??false;}
function wp_cache_set($key,$value,$group=''){$GLOBALS['objects'][get_current_blog_id()][$group][$key]=$value;return true;}
function wp_cache_flush(){throw new RuntimeException('Network-wide flush forbidden');}
function wp_cache_flush_group($group){throw new RuntimeException('Group flush forbidden');}
class ObjectDB extends DB {
 public $posts='wp_1_posts', $terms='wp_1_terms', $comments='wp_1_comments', $last_error='';
 function get_results($query){
  if(!is_array($query))return parent::get_results($query);
  [$sql,$args]=$query;
  if(strpos($sql,'cursor_id')===false)return parent::get_results($query);
  if(!empty($GLOBALS['db_failure'])){$this->last_error='private DB details';return null;}
  check(strpos($sql,'`'.$this->options.'`')!==false||strpos($sql,'`wp_'.get_current_blog_id().'_')!==false,'SQL enumeration uses current-site tables');
  $isOptions=strpos($sql,'option_name')!==false;
  $result=[];
  for($id=$args[0]+1;$id<=($isOptions?501:2)&&count($result)<500;$id++)$result[]=(object)['cursor_id'=>$id,'cache_key'=>$isOptions?'option'.$id:$id];
  return $result;
 }
}
if (!class_exists('WpeCommon')) { class WpeCommon {static function http_to_varnish(...$args){$GLOBALS['object_order'][]='wpe';return true;}} }
function setup_objects($multi=true){
 reset_state();$GLOBALS['multi']=$multi;$GLOBALS['wpdb']=new ObjectDB;
 $GLOBALS['wp_object_cache']=(object)['global_groups'=>['users','site-options'],'customer'=>'wpe','blog_prefix'=>'1::wpe','generation'=>[]];
 $GLOBALS['objects']=[];$GLOBALS['object_order']=[];unset($GLOBALS['db_failure'],$GLOBALS['delete_failure']);
 foreach([1,2] as $blog){
  $GLOBALS['objects'][$blog]=['options'=>['alloptions'=>['wpseo_titles'=>'old'],'notoptions'=>['missing'=>true],'wpseo_titles'=>'old','option501'=>'old'],'posts'=>[1=>'old',2=>'old','last_changed'=>'before'],'post_meta'=>[1=>'old'],'category_relationships'=>[1=>'old'],'terms'=>[1=>'old','last_changed'=>'before'],'term_meta'=>[1=>'old'],'comments'=>[1=>'old'],'comment_meta'=>[1=>'old'],'comment'=>['last_changed'=>'before'],'opaque-plugin'=>['sentinel'=>'keep'],'users'=>[1=>'keep'],'site-options'=>['network'=>'keep']];
 }
 $GLOBALS['object_delete']=function($key,$group=''){
  $GLOBALS['object_order'][]='object';
  if(!empty($GLOBALS['delete_failure']))return false;
  unset($GLOBALS['objects'][get_current_blog_id()][$group][$key]);return true;
 };
}
setup_objects();$other=$GLOBALS['objects'][2];
$GLOBALS['during_http']=function(){check(in_array('wpe',$GLOBALS['object_order'])&&!isset($GLOBALS['objects'][1]['options']['alloptions'])&&!isset($GLOBALS['objects'][1]['posts'][1]),'object keys cleared before WP Engine and Cloudflare');};
$r=P::purge_current_site_everything();
check($r['success'],'combined site purge completes');
check($GLOBALS['objects'][2]===$other,'every other-blog sentinel survives');
foreach(['options','post_meta','term_meta','comments','comment_meta','category_relationships'] as $g)check(!$GLOBALS['objects'][1][$g],'current-site '.$g.' entries invalidated');
foreach(['posts','terms','comment'] as $g)check($GLOBALS['objects'][1][$g]['last_changed']!=='before','site query generation refreshed for '.$g);
check($GLOBALS['objects'][1]['users'][1]==='keep'&&$GLOBALS['objects'][1]['site-options']['network']==='keep'&&$GLOBALS['objects'][1]['opaque-plugin']['sentinel']==='keep','global and opaque third-party keys untouched');
check(count($GLOBALS['calls'])===1&&strpos($GLOBALS['calls'][0][1],'/zones/zone-1/')!==false,'only configured Cloudflare zone purged');
setup_objects();$original=$GLOBALS['objects'][1];switch_to_blog(2);$GLOBALS['wp_object_cache']->blog_prefix='2::wpe';$GLOBALS['wpdb']->options='wp_2_options';$GLOBALS['wpdb']->posts='wp_2_posts';$GLOBALS['wpdb']->terms='wp_2_terms';$GLOBALS['wpdb']->comments='wp_2_comments';update_option('cloudflare_zone_id','zone-2');update_option('cloudflare_api_token','second-secret');$r=P::purge_current_site_everything();restore_current_blog();check($r['success']&&$GLOBALS['objects'][1]===$original&&!isset($GLOBALS['objects'][2]['posts'][1])&&get_current_blog_id()===1,'switched network-row scope invalidates selected blog only');
setup_objects(false);check(P::purge_current_site_everything()['success'],'standalone site supported');
setup_objects();$GLOBALS['db_failure']=true;$r=P::purge_current_site_everything();check(!$r['success']&&!$GLOBALS['calls']&&!in_array('wpe',$GLOBALS['object_order']),'database read failure blocks page/CDN dispatch');
setup_objects();$GLOBALS['delete_failure']=true;check(!P::purge_current_site_everything()['success']&&!$GLOBALS['calls'],'retained object key blocks false success');
setup_objects();$GLOBALS['wp_object_cache']->global_groups[]='options';$before=$GLOBALS['objects'];check(!P::purge_current_site_everything()['success']&&$GLOBALS['objects']===$before,'unexpected global options group refuses all invalidation');
setup_objects();$GLOBALS['wp_object_cache']->blog_prefix='2::wpe';$before=$GLOBALS['objects'];check(!P::purge_current_site_everything()['success']&&$GLOBALS['objects']===$before,'wrong WPE blog prefix refuses all invalidation');
setup_objects();$before=$GLOBALS['objects'];P::purge_zone_everything('zone-1',true);check($GLOBALS['objects'][1]['posts']===$before[1]['posts']&&$GLOBALS['objects'][2]===$before[2],'existing maintenance/zone purge does not broaden to object cache');
$source=file_get_contents(dirname(__DIR__).'/acquire-cloudflare-cache-manager.php');
check(substr_count($source,'$result  = self::purge_current_site_everything();')===1&&substr_count($source,'$result = self::purge_current_site_everything();')===1,'toolbar/subsite and network single-site buttons use scoped entrypoint');
echo "All site object-cache regressions passed.\n";
