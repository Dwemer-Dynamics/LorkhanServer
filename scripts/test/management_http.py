#!/usr/bin/env python3
import html.parser, http.cookiejar, sys, urllib.error, urllib.parse, urllib.request

base=sys.argv[1].rstrip('/')
jar=http.cookiejar.CookieJar()
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

class Page(html.parser.HTMLParser):
    def __init__(self):
        super().__init__(); self.labels=set(); self.controls=[]; self.nav=[]; self.current=0; self.forms=[]; self.form=None
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='label' and a.get('for'): self.labels.add(a['for'])
        if tag in ('input','textarea','select') and a.get('type') not in ('hidden','checkbox'): self.controls.append((tag,a.get('id'),a.get('name')))
        if tag=='a' and a.get('href','').startswith('/ALMSIVIserver/manage/'): self.nav.append(a['href']); self.current+=a.get('aria-current')=='page'
        if tag=='form': self.form={'action':a.get('action',''),'method':a.get('method','get'),'fields':{}}; self.forms.append(self.form)
        if self.form is not None and tag=='input' and a.get('name'): self.form['fields'][a['name']]=a.get('value','')
    def handle_endtag(self,tag):
        if tag=='form': self.form=None

def request(path,method='GET',data=None,follow=True):
    body=None if data is None else urllib.parse.urlencode(data).encode()
    req=urllib.request.Request(base+path,data=body,method=method,headers={'Content-Type':'application/x-www-form-urlencoded'} if body else {})
    try: return opener.open(req,timeout=5)
    except urllib.error.HTTPError as e: return e

def parse(response):
    text=response.read().decode(); p=Page(); p.feed(text)
    for _,i,n in p.controls:
        assert i and n and i in p.labels,('unlabelled control',i,n)
    return p,text

r=request('/ALMSIVIserver/manage/quickstart'); assert r.status==200 and r.geturl().endswith('/login')
r=request('/ALMSIVIserver/manage/login','POST',{'setup_secret':'wrong'}); p,text=parse(r); assert r.status==401 and 'role="alert"' in text
r=request('/ALMSIVIserver/manage/login','POST',{'setup_secret':'manage-secret'}); assert r.status==200 and r.geturl().endswith('/quickstart')
p,text=parse(r); assert len(p.nav)>=11 and p.current==1 and 'Queued jobs' in text
csrf=next(c.value for c in jar if c.name=='almsivi_csrf')
for path in sorted(set(p.nav)):
    page=parse(request(path))[0]; assert page.current==1,path
profile=parse(request('/ALMSIVIserver/manage/profiles'))[0]
form=next(f for f in profile.forms if f['action'].endswith('/forms/profiles'))
invalid=dict(form['fields'],_csrf=csrf,installation_id='invalid',name='Test',content_json='{}')
r=request(form['action'],'POST',invalid); _,text=parse(r); assert r.status==422 and 'role="alert"' in text
valid=dict(form['fields'],_csrf=csrf,installation_id='00000000-0000-4000-8000-000000000001',name='HTTP managed profile',content_json='{"source":"browser"}')
r=request(form['action'],'POST',valid); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/profiles?status=saved'),(r.status,r.geturl(),body); assert 'Changes saved.' in body
r=request('/ALMSIVIserver/manage/logout','POST',{'_csrf':csrf}); assert r.status==200 and r.geturl().endswith('/login')
r=request('/ALMSIVIserver/manage/quickstart'); assert r.geturl().endswith('/login')
print('browser-like management HTTP forms passed')
