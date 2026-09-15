#!/usr/bin/env python3
"""Build a deterministic allowlisted WordPress package. Never includes test harnesses."""
import argparse,hashlib,pathlib,re,zipfile
p=argparse.ArgumentParser();p.add_argument('--source',default=str(pathlib.Path(__file__).resolve().parents[1]));p.add_argument('--output',required=True);p.add_argument('--tag',required=True);a=p.parse_args()
root=pathlib.Path(a.source);main=root/'acquire-cloudflare-cache-manager.php';data=main.read_text()
header=re.search(r'Version:\s+(\S+)',data)[1];constant=re.search(r"const VERSION\s*=\s*'([^']+)'",data)[1]
assert re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+',header), 'Expected X.Y.Z version'
assert header==constant and a.tag=='v'+header, 'Version/tag mismatch'
files=[main,root/'README.md',root/'CHANGELOG.md']+sorted((root/'assets').rglob('*'))
files=[f for f in files if f.is_file()]
out=pathlib.Path(a.output);out.parent.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
 for f in files:
  info=zipfile.ZipInfo('acquire-cloudflare-cache-manager/'+f.relative_to(root).as_posix(),(2026,9,15,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,f.read_bytes())
with zipfile.ZipFile(out) as z:
 assert z.testzip() is None
 assert z.read('acquire-cloudflare-cache-manager/acquire-cloudflare-cache-manager.php')==main.read_bytes()
 assert len(z.namelist())==len(files)
print(hashlib.sha256(out.read_bytes()).hexdigest(),out)
