#!/usr/bin/env python3
"""Provision only into a fresh disposable directory against a supplied private DB socket."""
import os,pathlib,re,shutil,subprocess,sys
base=pathlib.Path(sys.argv[1]).resolve(); socket=sys.argv[2]; source=pathlib.Path(sys.argv[3]).resolve()
suffix=sys.argv[4] if len(sys.argv)>4 else ''
assert re.fullmatch(r'[a-z0-9_]*',suffix)
assert socket.startswith('/tmp/') or socket.startswith('/private/tmp/')
repo=pathlib.Path(__file__).resolve().parents[2]
assert str(base).startswith('/private/tmp/') or str(base).startswith('/tmp/')
for mode,port in [('single',18891),('multi',18892)]:
 root=base/mode
 if root.exists(): raise SystemExit('Refusing to overwrite '+str(root))
 dbname='acfcm_'+mode+suffix
 subprocess.run(['php','-r',"$db=new mysqli('localhost','root','',null,0,$argv[1]); $db->query('CREATE DATABASE `'.$argv[2].'`');",socket,dbname],check=True)
 shutil.copytree(source,root)
 state=base/(mode+'-state');state.mkdir()
 (state/'http.json').write_text('{"calls":[],"responses":[]}')
 mu=root/'wp-content/mu-plugins';mu.mkdir(exist_ok=True);shutil.copy(repo/'tests/integration/mock.php',mu/'mock.php')
 plugin=root/'wp-content/plugins/acquire-cloudflare-cache-manager';plugin.mkdir();(plugin/'acquire-cloudflare-cache-manager.php').symlink_to(repo/'acquire-cloudflare-cache-manager.php')
 shutil.copy(repo/'tests/integration/endpoint.php',root/'harness.php')
 config="<?php\n"
 for k,v in {'DB_NAME':dbname,'DB_USER':'root','DB_PASSWORD':'','DB_HOST':'localhost:'+socket,'DB_CHARSET':'utf8mb4','DB_COLLATE':'','WP_HOME':f'http://127.0.0.1:{port}','WP_SITEURL':f'http://127.0.0.1:{port}','ACFCM_TEST_STATE':str(state),'WP_ENVIRONMENT_TYPE':'local'}.items():config+=f"define('{k}', {v!r});\n"
 config+="define('DISABLE_WP_CRON', true); define('WP_HTTP_BLOCK_EXTERNAL', true); define('AUTOMATIC_UPDATER_DISABLED', true); define('WP_DEBUG', true); define('WP_DEBUG_DISPLAY', false); define('WP_DEBUG_LOG', true);\n$table_prefix='wp_';\n"
 config+="if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/'); require ABSPATH.'wp-settings.php';\n"
 (root/'wp-config.php').write_text(config)
 subprocess.run(['php',str(repo/'tests/integration/install.php'),str(root),mode],check=True)
 if mode=='multi':
  config=config.replace("$table_prefix='wp_';", "$table_prefix='wp_';\ndefine('MULTISITE', true); define('SUBDOMAIN_INSTALL', false); define('DOMAIN_CURRENT_SITE', '127.0.0.1:18892'); define('PATH_CURRENT_SITE', '/'); define('SITE_ID_CURRENT_SITE', 1); define('BLOG_ID_CURRENT_SITE', 1);")
  config='\n'.join(line for line in config.splitlines() if not line.startswith("define('WP_HOME'") and not line.startswith("define('WP_SITEURL'"))
  (root/'wp-config.php').write_text(config)
 print(root,flush=True)
