<?php
// Run the non-WP-Engine regressions first, then install a transport double.
require __DIR__ . '/purge-regression.php';
if ( ! class_exists( 'WpeCommon' ) ) {
    class WpeCommon {
        public static function http_to_varnish( $method, $host, $headers ) {
            $GLOBALS['origin_calls'][] = [get_current_blog_id(), $method, $host, $headers];
            if (!empty($GLOBALS['origin_callback'])) { $f=$GLOBALS['origin_callback']; unset($GLOBALS['origin_callback']); $f(); }
            if (!empty($GLOBALS['origin_throw'])) throw new RuntimeException('private platform details');
            return $GLOBALS['origin_response'] ?? null; // Installed helper returns void on successful dispatch.
        }
    }
}
function origin_reset(){reset_state();$GLOBALS['origin_calls']=[];unset($GLOBALS['origin_response'],$GLOBALS['origin_callback'],$GLOBALS['origin_throw']);}
origin_reset();$r=P::purge_urls_for_current_site([home_url('/old/'),home_url('/new/')]);
check(count($GLOBALS['origin_calls'])===1&&!$GLOBALS['calls']&&!$r[0]['success']&&$r[0]['queued'],'manual purge dispatches WP Engine before Cloudflare');
$j=array_values(jobs())[0];check($j['due']>=time()+4&&$j['attempt']===0,'origin propagation interval persists without consuming Cloudflare attempt');
P::retry_purge(array_keys(jobs())[0],$j['id']);check(!$GLOBALS['calls'],'Cloudflare waits until the persisted deadline');
$headers=$GLOBALS['origin_calls'][0][3];check(preg_match('~'.$headers['X-Purge-Host'].'~','site1.test')&&!preg_match('~'.$headers['X-Purge-Host'].'~','othersite1.test'),'origin host scope is exact');
check(preg_match('~'.$headers['X-Purge-Path'].'~','/old/?utm_source=x')&&preg_match('~'.$headers['X-Purge-Path'].'~','/new/')&&!preg_match('~'.$headers['X-Purge-Path'].'~','/unrelated/'),'old and new paths include query variants without unrelated paths');
retry_now();check(count($GLOBALS['calls'])===1&&count($GLOBALS['origin_calls'])===1&&!jobs(),'next cron pass completes Cloudflare after origin dispatch');
origin_reset();fire('pre_post_update',7);get_post(7)->post_name='edited';fire('save_post',7,get_post(7),true);P::flush_content_purges();check(!$GLOBALS['origin_calls']&&!$GLOBALS['calls'],'editor save queues both cache layers without HTTP at shutdown');retry_now();check(count($GLOBALS['origin_calls'])===1&&!$GLOBALS['calls'],'saved edit cron dispatches origin first');retry_now();check(count($GLOBALS['calls'])===1,'saved edit second cron pass dispatches Cloudflare');
origin_reset();$GLOBALS['origin_response']=new WP_Error();$r=P::purge_urls_for_current_site([home_url('/a')]);check(!$GLOBALS['calls']&&$r[0]['queued']&&!$r[0]['success'],'origin failure retains retry and blocks Cloudflare');unset($GLOBALS['origin_response']);retry_now();check(!$GLOBALS['calls'],'origin recovery still waits before Cloudflare');retry_now();check(count($GLOBALS['calls'])===1&&!jobs(),'recovered origin proceeds to Cloudflare');
origin_reset();$GLOBALS['origin_throw']=true;$r=P::purge_urls_for_current_site([home_url('/a')]);check(!$GLOBALS['calls']&&strpos(json_encode($r),'private platform')===false,'origin exception is safely reported and blocks Cloudflare');unset($GLOBALS['origin_throw']);retry_now();retry_now();check(count($GLOBALS['calls'])===1,'recursion guard resets after exception');
origin_reset();$GLOBALS['origin_callback']=function(){fire('wpe_cache_flush');fire('wpe_purge_cache');P::purge_urls_for_current_site([home_url('/recursive')]);};P::purge_urls_for_current_site([home_url('/a')]);check(count($GLOBALS['origin_calls'])===1&&count(jobs())===1&&!$GLOBALS['calls'],'origin hooks cannot recursively trigger a network purge');retry_now();
origin_reset();P::purge_urls_for_current_site([home_url('/a')]);$GLOBALS['during_http']=function(){P::purge_urls_for_current_site([home_url('/a')]);};retry_now();retry_now();check(count($GLOBALS['origin_calls'])===2&&count($GLOBALS['calls'])===1,'edit during Cloudflare request gets a fresh origin stage');retry_now();check(count($GLOBALS['calls'])===2&&!jobs(),'trailing generation completes both layers');
origin_reset();$GLOBALS['responses']=[['code'=>503,'body'=>'{}']];P::purge_urls_for_current_site([home_url('/a')]);retry_now();retry_now();check(count($GLOBALS['origin_calls'])===1&&count($GLOBALS['calls'])===2&&!jobs(),'Cloudflare-only failure retries without repeating unchanged origin purge');
origin_reset();$GLOBALS['multi']=true;switch_to_blog(2);update_option('cloudflare_zone_id','zone-2');update_option('cloudflare_api_token','second-secret');restore_current_blog();P::purge_all_enabled_zones();check(array_column($GLOBALS['origin_calls'],0)===[1,2]&&array_column($GLOBALS['origin_calls'],2)===['site1.test','site2.test'],'network manual purge dispatches origin separately for each target site');retry_now();switch_to_blog(2);retry_now();restore_current_blog();check(array_column($GLOBALS['calls'],0)===[1,2]&&get_current_blog_id()===1,'multisite Cloudflare stages retain target site context');
origin_reset();P::purge_urls_for_current_site(['https://cdn.example.test/image.jpg']);check(!$GLOBALS['origin_calls'],'external attachment host never purges unrelated origin');retry_now();check(count($GLOBALS['calls'])===1,'external attachment still reaches Cloudflare');
origin_reset();$GLOBALS['home_path']='/subsite';P::purge_zone_everything('zone-1');$regex=$GLOBALS['origin_calls'][0][3]['X-Purge-Path'];check(preg_match('~'.$regex.'~','/subsite/page/')&&preg_match('~'.$regex.'~','/subsite?utm_source=x')&&!preg_match('~'.$regex.'~','/subsite-other/page/')&&!preg_match('~'.$regex.'~','/sibling/'),'subdirectory full purge retains site boundary');unset($GLOBALS['home_path']);
origin_reset();$GLOBALS['origin_response']=false;P::purge_urls_for_current_site([home_url('/a')]);for($i=0;$i<5;$i++)retry_now();check(count($GLOBALS['origin_calls'])===5&&!$GLOBALS['calls']&&!jobs(),'origin failures exhaust bounded retries without clearing Cloudflare');
origin_reset();define('WPE_DISABLE_CACHE_PURGING',true);$r=P::purge_urls_for_current_site([home_url('/a')]);check(!$GLOBALS['origin_calls']&&!$GLOBALS['calls']&&!empty($r[0]['queued']),'WP Engine purge-disable constant is respected');
echo "All WP Engine ordering regressions passed.\n";
