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
        if tag=='a' and a.get('href','').startswith('/ALMSIVIserver/ui/'): self.nav.append(a['href']); self.current+=a.get('aria-current')=='page'
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

r=request('/ALMSIVIserver/manage/quickstart'); assert r.status==200 and r.geturl().endswith('/ui/home.php')
p,text=parse(r); assert len(p.nav)>=4 and p.current==1 and 'Queued Jobs' in text
assert 'class="almsivi-navbar-wrapper"' in text and '/ALMSIVIserver/ui/lib/ui/bootstrap/bootstrap.min.css' in text
assert '<details' not in text and 'Recent Dialogue' in text and 'Getting Started' not in text
assert 'Management secret' not in text and '/logout' not in text
csrf=next(c.value for c in jar if c.name=='almsivi_csrf')
for path,marker in [
    ('/ALMSIVIserver/ui/home.php','dashboard-container'),
    ('/ALMSIVIserver/ui/events-memories.php','events-memories-navigation'),
    ('/ALMSIVIserver/ui/core/config_hub.php','config-navigation'),
    ('/ALMSIVIserver/ui/control_panel.php','config-navigation'),
]:
    page,text=parse(request(path)); assert page.current==1,path; assert marker in text,path
    if path != '/ALMSIVIserver/ui/home.php': assert '<body class="hub-page">' in text,path
pages_css=request('/ALMSIVIserver/ui/css/almsivi-pages.css').read().decode()
navbar_css=request('/ALMSIVIserver/ui/css/navbar.css').read().decode()
assert '.dashboard-shell {\n    padding: 18px 10px 52px;' in pages_css
assert 'body.hub-page > main' in pages_css and 'max-width: 1600px' not in pages_css
assert 'min-height: calc(100vh - 205px)' not in pages_css and 'min-height: calc(100vh - 225px)' not in pages_css
assert '.navbar-content-wrapper {' in navbar_css and 'max-width: 1000px;' in navbar_css
for path in [
    '/ALMSIVIserver/manage/quickstart', '/ALMSIVIserver/manage/roleplay',
    '/ALMSIVIserver/manage/configuration', '/ALMSIVIserver/manage/control-panel',
    '/ALMSIVIserver/manage/characters', '/ALMSIVIserver/manage/profiles',
    '/ALMSIVIserver/manage/providers', '/ALMSIVIserver/manage/ai-voice',
    '/ALMSIVIserver/manage/prompts-actions', '/ALMSIVIserver/manage/world',
    '/ALMSIVIserver/manage/traces', '/ALMSIVIserver/manage/memory',
    '/ALMSIVIserver/manage/relationships', '/ALMSIVIserver/manage/knowledge',
    '/ALMSIVIserver/manage/playthroughs', '/ALMSIVIserver/manage/narrative-autonomy',
    '/ALMSIVIserver/manage/jobs', '/ALMSIVIserver/manage/backup-health',
    '/ALMSIVIserver/manage/diagnostics',
]:
    response=request(path); assert response.status==200 and '/ui/' in response.geturl(),(path,response.geturl())
profile=parse(request('/ALMSIVIserver/ui/core/core_profiles.php'))[0]
form=next(f for f in profile.forms if f['action'].endswith('/forms/profiles'))
invalid=dict(form['fields'],_csrf=csrf,installation_id='invalid',name='Test',content_json='{}')
r=request(form['action'],'POST',invalid); _,text=parse(r); assert r.status==422 and 'role="alert"' in text
valid=dict(form['fields'],_csrf=csrf,installation_id='00000000-0000-4000-8000-000000000001',name='HTTP managed profile',content_json='{"source":"browser"}')
r=request(form['action'],'POST',valid); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/core_profiles.php?status=saved'),(r.status,r.geturl(),body); assert 'Changes saved.' in body
r=request('/ALMSIVIserver/manage/login'); assert r.status==200 and r.geturl().endswith('/ui/home.php')
print('browser-like management HTTP forms passed')
