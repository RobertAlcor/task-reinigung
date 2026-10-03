#!/usr/bin/env python3
"""Isolated HTTP checks. Starts PHP only on 127.0.0.1; no live requests."""
from pathlib import Path
import urllib.request, urllib.error, urllib.parse, http.cookiejar, json, os, sys, subprocess, socket, time, tempfile
root = Path(sys.argv[1] if len(sys.argv)>1 else Path(__file__).resolve().parents[1]).resolve()
fixture=Path(__file__).resolve().parent/'support/http_fixture.php'
with socket.socket() as s:
    s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
env=dict(os.environ,TAKT_TEST_ROOT=str(root))
results=[]
jar=http.cookiejar.CookieJar(); client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def req(path,body=None,headers=None,method=None,anonymous=False):
    if isinstance(body,dict): body=json.dumps(body).encode(); headers={'Content-Type':'application/json',**(headers or {})}
    request=urllib.request.Request(f'http://127.0.0.1:{port}'+path,body,headers or {},method=method)
    try: r=(urllib.request.build_opener() if anonymous else client).open(request,timeout=5)
    except urllib.error.HTTPError as e: r=e
    data=r.read(); return r.status,dict(r.headers),json.loads(data) if data else None
def test(name,fn):
    try:
        assert fn(), 'unexpected response'; results.append({'name':name,'ok':True})
    except Exception as e: results.append({'name':name,'ok':False,'message':str(e)})
with tempfile.TemporaryDirectory(prefix='takt-http-') as temp, tempfile.TemporaryFile() as log:
    process=subprocess.Popen(['php','-d','display_errors=0','-d','session.save_path='+temp,'-S',f'127.0.0.1:{port}',str(fixture)],env=env,stdout=log,stderr=log)
    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.2): break
            except OSError: time.sleep(.05)
        else: raise RuntimeError('PHP HTTP fixture did not start')
        test('HTTP: anonymous write denied',lambda:req('/write',{},anonymous=True)[0]==401)
        status,h,session=req('/session'); csrf=session['data']['csrf']
        test('HTTP: missing CSRF denied',lambda:req('/write',{})[0]==419)
        test('HTTP: wrong CSRF denied',lambda:req('/write',{}, {'X-CSRF-Token':'wrong'})[0]==419)
        test('HTTP: valid session+CSRF writes',lambda:req('/write',{}, {'X-CSRF-Token':csrf})[0]==200)
        test('HTTP: valid Bearer writes without cookie CSRF',lambda:req('/write',{}, {'Authorization':'Bearer '+'a'*64},anonymous=True)[0]==200)
        test('HTTP: malformed Bearer cannot use cookie',lambda:req('/write',{}, {'Authorization':'Bearer malformed','X-CSRF-Token':csrf})[0]==401)
        test('HTTP: wrong role forbidden',lambda:req('/write?role=kunde',{}, {'X-CSRF-Token':csrf})[0]==403)
        test('HTTP: authenticated GET requires no CSRF',lambda:req('/read')[0]==200)
        for raw in [b'{',b'[]',b'null',b'"hello"',b'{"x":NaN}']:
            test('HTTP: invalid JSON/root '+raw.decode(),lambda raw=raw:req('/input',raw,{'Content-Type':'application/json'})[0]==400)
        test('HTTP: excessive JSON rejected',lambda:req('/input',b'{"x":"'+b'a'*(1024*1024)+b'"}',{'Content-Type':'application/json'})[0]==413)
        test('HTTP: JSON object accepted',lambda:req('/input',{'x':1})[2]['data']=={'x':1})
        test('HTTP: form-urlencoded preserved',lambda:req('/input',b'value=hello&kind=text',{'Content-Type':'application/x-www-form-urlencoded'})[2]['data']['value']=='hello')
        for val in ['   ','\x00name',True,['name'],'a'*21]:
            test('HTTP: invalid required text '+repr(val),lambda val=val:req('/input',{'kind':'text','value':val})[0]==422)
        test('HTTP: trimmed Unicode text accepted',lambda:req('/input',{'kind':'text','value':'  Grüß Gott  '})[2]['data']['value']=='Grüß Gott')
        for val in ['1e2',True,[],1.2,'999999999999999999999999',101]:
            test('HTTP: invalid integer '+repr(val),lambda val=val:req('/input',{'kind':'int','value':val})[0]==422)
        test('HTTP: canonical integer accepted',lambda:req('/input',{'kind':'int','value':'42'})[2]['data']['value']==42)
        test('HTTP: decimal comma accepted',lambda:req('/input',{'kind':'decimal','value':'3,25'})[2]['data']['value']==3.25)
        test('HTTP: nonfinite decimal rejected',lambda:req('/input',{'kind':'decimal','value':'1e9999'})[0]==422)
        test('HTTP: impossible date rejected',lambda:req('/input',{'kind':'date','value':'2026-02-30'})[0]==422)
        test('HTTP: positive ID accepted',lambda:req('/id/12')[2]['data']['id']==12)
        for val in ['0','-1','999999999999999999999999']:
            test('HTTP: invalid route ID '+val,lambda val=val:req('/id/'+val)[0]==400)
        test('HTTP: JSON response not cached',lambda:'no-store' in req('/read')[1].get('Cache-Control',''))
        test('HTTP: invalid UTF-8 substituted',lambda:'�' in req('/utf8')[2]['data']['text'])
        test('HTTP: non-JSON encodable response gives structured 500',lambda:req('/infinity')[0]==500)
        good=['https://fcm.googleapis.com/fcm/send/example','https://updates.push.services.mozilla.com/wpush/v2/example','https://web.push.apple.com/Qexample','https://wns2-db5p.notify.windows.com/w/?token=example']
        bad=['http://fcm.googleapis.com/x','https://127.0.0.1/x','https://[::1]/x','https://169.254.169.254/latest/','https://fcm.googleapis.com.evil.test/x','https://fcm.googleapis.com@evil.test/x','https://evil@fcm.googleapis.com/x','https://fcm.googleapis.com:8443/x','https://fcm.googleapis.com/x#f','https://evilpush.apple.com/x','https://push.apple.com.evil.test/x','https://fcm.googleapis.com\\@127.0.0.1/x','https://fcm.googleapis.com/\nX:bad']
        for value in good+bad:
            test('Push allowlist: '+value.replace('\n','\\n'),lambda value=value:req('/push?url='+urllib.parse.quote(value,safe=''))[2]['data']['valid']==(value in good))
    finally:
        process.terminate()
        try: process.wait(timeout=5)
        except subprocess.TimeoutExpired: process.kill(); process.wait()
report={'root':str(root),'transport':'real local HTTP; PDO test double','tests':len(results),'passed':sum(r['ok'] for r in results),'failed':sum(not r['ok'] for r in results),'results':results}
print(json.dumps(report,ensure_ascii=False,indent=2)); sys.exit(bool(report['failed']))
