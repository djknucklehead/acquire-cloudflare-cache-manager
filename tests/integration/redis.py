#!/usr/bin/env python3
"""Enable a separately downloaded Redis Object Cache drop-in in disposable sites."""
import pathlib,shutil,sys
base=pathlib.Path(sys.argv[1]).resolve();plugin=pathlib.Path(sys.argv[2]);sock=sys.argv[3]
assert str(base).startswith('/private/tmp/') or str(base).startswith('/tmp/')
for mode in ['single','multi']:
 root=base/mode;shutil.copytree(plugin,root/'wp-content/plugins/redis-cache',dirs_exist_ok=True);shutil.copy(plugin/'includes/object-cache.php',root/'wp-content/object-cache.php')
 p=root/'wp-config.php';s=p.read_text();assert 'WP_REDIS_CLIENT' not in s
 s=s.replace("$table_prefix='wp_';", "$table_prefix='wp_';\n"+f"define('WP_REDIS_CLIENT','predis'); define('WP_REDIS_SCHEME','unix'); define('WP_REDIS_PATH',{sock!r}); define('WP_REDIS_PREFIX',{'acfcm-'+base.name+'-'+mode+':'!r});")
 p.write_text(s)
