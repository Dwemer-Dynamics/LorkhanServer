#!/usr/bin/env python3
import atexit, html.parser, http.cookiejar, http.server, io, json, re, sys, threading, urllib.error, urllib.parse, urllib.request, uuid, zipfile

base=sys.argv[1].rstrip('/')
provider_host=sys.argv[2] if len(sys.argv)>2 else '127.0.0.1'
jar=http.cookiejar.CookieJar()
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

class VoiceProvider(http.server.BaseHTTPRequestHandler):
    uploads=[]
    llm_requests=[]
    def do_GET(self):
        if self.path.startswith('/speakers_list'):
            payload=json.dumps({'speakers':['MockProviderVoice']}).encode()
            self.send_response(200); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(payload))); self.end_headers(); self.wfile.write(payload); return
        self.send_error(404)
    def do_POST(self):
        if self.path=='/llm/chat/completions':
            body=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0')))); self.llm_requests.append((dict(self.headers),body))
            content=json.dumps({'utterances':[{'text':'Greetings, traveller.'}],'action':None} if body['model']!='invalid-output' else {'unexpected':'not dialogue'})
            if body.get('stream'):
                payload=('data: '+json.dumps({'choices':[{'delta':{'content':content}}]})+'\n\ndata: [DONE]\n\n').encode(); content_type='text/event-stream'
            else:
                payload=json.dumps({'choices':[{'message':{'content':content}}]}).encode(); content_type='application/json'
            self.send_response(200); self.send_header('Content-Type',content_type); self.send_header('Content-Length',str(len(payload))); self.end_headers(); self.wfile.write(payload); return
        if self.path!='/upload_sample': self.send_error(404); return
        body=self.rfile.read(int(self.headers.get('Content-Length','0'))); self.uploads.append((dict(self.headers),body))
        payload=b'{"status":"ok"}'
        self.send_response(200); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(payload))); self.end_headers(); self.wfile.write(payload)
    def log_message(self,format,*args): pass

voice_provider=http.server.ThreadingHTTPServer(('0.0.0.0',0),VoiceProvider)
threading.Thread(target=voice_provider.serve_forever,daemon=True).start()
atexit.register(voice_provider.server_close)
atexit.register(voice_provider.shutdown)

class Page(html.parser.HTMLParser):
    def __init__(self):
        super().__init__(); self.labels=set(); self.controls=[]; self.nav=[]; self.current=0; self.forms=[]; self.form=None; self.select_name=None; self.label_depth=0
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='label':
            self.label_depth+=1
            if a.get('for'): self.labels.add(a['for'])
        if tag in ('input','textarea','select') and a.get('name') and a.get('type') not in ('hidden','checkbox'): self.controls.append((tag,a.get('id'),a.get('name'),self.label_depth>0 or bool(a.get('aria-label'))))
        if tag=='a' and a.get('href','').startswith('/ALMSIVIserver/ui/'):
            self.nav.append(a['href'])
            if 'dropdown-item' in a.get('class','').split(): self.current+=a.get('aria-current')=='page'
        if tag=='form': self.form={'action':a.get('action',''),'method':a.get('method','get'),'fields':{}}; self.forms.append(self.form)
        if self.form is not None and tag=='input' and a.get('name') and 'disabled' not in a and (a.get('type')!='checkbox' or 'checked' in a): self.form['fields'][a['name']]=a.get('value','')
        if self.form is not None and tag=='select' and a.get('name') and 'disabled' not in a: self.select_name=a['name']
        if self.form is not None and tag=='option' and self.select_name and (self.select_name not in self.form['fields'] or 'selected' in a):
            self.form['fields'][self.select_name]=a.get('value','')
    def handle_endtag(self,tag):
        if tag=='label': self.label_depth=max(0,self.label_depth-1)
        if tag=='select': self.select_name=None
        if tag=='form': self.form=None

def request(path,method='GET',data=None,follow=True):
    body=None if data is None else urllib.parse.urlencode(data,doseq=True).encode()
    req=urllib.request.Request(base+path,data=body,method=method,headers={'Content-Type':'application/x-www-form-urlencoded'} if body else {})
    try: return opener.open(req,timeout=5)
    except urllib.error.HTTPError as e: return e

def multipart_request(path,fields,file_field,filename,content_type,payload):
    boundary='----almsivi-'+uuid.uuid4().hex; body=bytearray()
    for name,value in fields.items():
        body.extend(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+name+'"\r\n\r\n'+str(value)+'\r\n').encode())
    body.extend(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+file_field+'"; filename="'+filename+'"\r\nContent-Type: '+content_type+'\r\n\r\n').encode())
    body.extend(payload); body.extend(('\r\n--'+boundary+'--\r\n').encode())
    req=urllib.request.Request(base+path,data=bytes(body),method='POST',headers={'Content-Type':'multipart/form-data; boundary='+boundary})
    try: return opener.open(req,timeout=5)
    except urllib.error.HTTPError as e: return e

def parse(response):
    text=response.read().decode(); p=Page(); p.feed(text)
    for _,i,n,implicit in p.controls:
        assert n and (implicit or (i and i in p.labels)),('unlabelled control',i,n)
    return p,text

def selected_record_id(text,name):
    match=re.search(re.escape(name)+r'.*?name="(?:configuration_id|profile_id|core_profile_id)" value="([0-9a-f-]{36})"',text,re.S)
    assert match,text
    return match.group(1)

def connector_editor_id(text,name):
    match=re.search(r'<a\b[^>]*href="[^"]*[?&](?:edit|selected)=([0-9a-f-]{36})[^"]*"[^>]*>(?:(?!</a>).)*?<span class="title">'+re.escape(name)+r'</span>',text,re.S)
    assert match,text
    return match.group(1)

r=request('/ALMSIVIserver/manage/quickstart'); assert r.status==200 and r.geturl().endswith('/ui/home.php')
p,text=parse(r); assert len(p.nav)>=4 and p.current==1 and 'Queued Jobs' in text
assert 'class="chim-navbar-wrapper"' in text and '/ALMSIVIserver/ui/lib/ui/bootstrap/bootstrap.min.css' in text
assert '<details' not in text and 'Recent Dialogue' in text and 'Getting Started' not in text
assert re.search(r'<article class="widget">\s*<div class="widget-header"><h3>ALMSIVI Stats</h3>', text)
assert all('/ui/images/'+asset in text for asset in ['youtube.png','discord.png','patreon.png'])
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
events,text=parse(request('/ALMSIVIserver/ui/events-memories.php?tab=eventlog'))
assert events.current==1 and 'id="eventlog-app"' in text and 'data-eventlog-live' in text and 'Delete Latest 5' in text and 'Delete ALL' in text and 'People Present' in text and 'Tamrielic Time' in text and 'data-eventlog-delete-row' not in text and 'Soulgaze' not in text
journal,text=parse(request('/ALMSIVIserver/ui/events-memories.php?tab=journal-tab')); assert journal.current==1 and 'Morrowind Journal' in text and 'id="journal-tab" class="tab-content active"' in text and 'events-memories.php?tab=journal' in text and 'events-memories.php?tab=quests' not in text and 'events-memories.php?tab=relationships' not in text and '>Morrowind</div>' not in text
books,text=parse(request('/ALMSIVIserver/ui/events-memories.php?tab=books-tab')); assert books.current==1 and '>Books</h2>' in text and 'id="books-tab" class="tab-content active"' in text
memories,text=parse(request('/ALMSIVIserver/ui/events-memories.php?tab=memories-tab')); assert memories.current==1 and '>Memories</h2>' in text and 'id="memory-tab" class="tab-content active"' in text and 'Add or rebuild memories' in text
relationships,text=parse(request('/ALMSIVIserver/ui/events-memories.php?tab=relationships-tab')); assert relationships.current==1 and '>Morrowind Journal:</strong>' in text and 'id="journal-tab" class="tab-content active"' in text and 'Add relationship' not in text
narratives_tab,text=parse(request('/ALMSIVIserver/ui/events-memories.php?tab=narratives-tab')); assert narratives_tab.current==1 and '>Adventure Log</h2>' in text and 'id="adventure-tab" class="tab-content active"' in text and 'Manage narratives' in text
narratives_page,text=parse(request('/ALMSIVIserver/ui/narrative_manager.php')); assert narratives_page.current==1 and '<h1>Narratives</h1>' in text and 'Create narrative' in text
cache,text=parse(request('/ALMSIVIserver/ui/cache_browser.php')); assert cache.current==1 and '<h1>Cache Browser</h1>' in text and 'private audio files' in text
queue,text=parse(request('/ALMSIVIserver/ui/response_queue.php')); assert queue.current==1 and '<h1>Response Queue</h1>' in text and 'dialogue delivery states' in text
oghma,text=parse(request('/ALMSIVIserver/ui/oghma_audit.php')); assert oghma.current==1 and '<h1>Oghma Audit</h1>' in text and 'retrieval traces' in text
assert all('value="'+status+'"' in text for status in ['grounded','no_match','fallback_succeeded','fallback_unresolved','fallback_failed','fallback_disabled','fallback_unconfigured','disabled','ineligible','unavailable','not_run','legacy']),text
usage,text=parse(request('/ALMSIVIserver/ui/provider_usage.php')); assert usage.current==1 and '<h1>Provider Usage</h1>' in text and 'does not fabricate currency costs' in text
server_logs,text=parse(request('/ALMSIVIserver/ui/server_logs.php')); assert server_logs.current==1 and '<h1>Server Logs</h1>' in text and 'bounded, redacted output' in text
database,text=parse(request('/ALMSIVIserver/ui/database_manager.php')); assert database.current==1 and '<h1>Database Manager</h1>' in text and 'schema migrations' in text and 'Installation Configuration Backups' in text
studio,text=parse(request('/ALMSIVIserver/ui/core/voice_library.php')); assert studio.current==1 and 'Add WAV voice samples' in text and 'flat ZIP batch' in text and 'Voice Library' in text and 'Configured TTS Connectors' in text and 'Provider Voice Browser' in text and 'never contacts a provider automatically' in text
batch_voice='HTTPBatch'+uuid.uuid4().hex
wav=(b'RIFF'+(36).to_bytes(4,'little')+b'WAVEfmt '+(16).to_bytes(4,'little')+(1).to_bytes(2,'little')+(1).to_bytes(2,'little')
     +(16000).to_bytes(4,'little')+(32000).to_bytes(4,'little')+(2).to_bytes(2,'little')+(16).to_bytes(2,'little')+b'data'+(0).to_bytes(4,'little'))
archive=io.BytesIO()
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as bundle: bundle.writestr(batch_voice+'.wav',wav)
r=multipart_request('/ALMSIVIserver/ui/core/voice_library.php',{'_csrf':csrf,'action':'upload','voice_name':''},'voice_sample','voices.zip','application/zip',archive.getvalue()); body=r.read().decode()
assert r.status==200 and '1 voice samples imported.' in body and batch_voice in body,(r.status,r.geturl(),body)
bad_archive=io.BytesIO()
with zipfile.ZipFile(bad_archive,'w',zipfile.ZIP_DEFLATED) as bundle: bundle.writestr('../Escape.wav',wav)
r=multipart_request('/ALMSIVIserver/ui/core/voice_library.php',{'_csrf':csrf,'action':'upload','voice_name':''},'voice_sample','unsafe.zip','application/zip',bad_archive.getvalue()); body=r.read().decode()
assert r.status==200 and 'invalid_voice_archive' in body and 'Escape' not in body,(r.status,r.geturl(),body)
tts_page,_=parse(request('/ALMSIVIserver/ui/core/tts_connectors.php?create=1')); create_sync_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/tts-providers'))
sync_tts_name='HTTP voice sync '+uuid.uuid4().hex
sync_values=dict(create_sync_tts['fields'],_csrf=csrf,installation_id=create_sync_tts['fields']['installation_id'],name=sync_tts_name,driver='xtts-fastapi',endpoint='http://'+provider_host+':'+str(voice_provider.server_port),model='default',voice='default',language='en',timeout_ms='30000',options_json='{}')
r=request(create_sync_tts['action'],'POST',sync_values); body=r.read().decode(); assert r.status==200 and sync_tts_name in body,(r.status,r.geturl(),body)
sync_tts_id=connector_editor_id(body,sync_tts_name)
r=request('/ALMSIVIserver/ui/core/voice_library.php','POST',{'_csrf':csrf,'action':'sync','voice_name':batch_voice,'configuration_id':sync_tts_id,'language':'en'}); body=r.read().decode()
assert r.status==200 and 'Voice sample synced to '+sync_tts_name+'.' in body,(r.status,r.geturl(),body)
assert len(VoiceProvider.uploads)==1 and b'name="wavFile"' in VoiceProvider.uploads[0][1] and b'name="force"' in VoiceProvider.uploads[0][1] and b'\r\n\r\ntrue\r\n' in VoiceProvider.uploads[0][1],VoiceProvider.uploads
r=request('/ALMSIVIserver/ui/core/voice_library.php','POST',{'_csrf':csrf,'action':'discover','configuration_id':sync_tts_id,'language':'en'}); body=r.read().decode()
assert r.status==200 and '1 provider voices discovered.' in body and 'MockProviderVoice' in body,(r.status,r.geturl(),body)
profiles_with_provider_voice=request('/ALMSIVIserver/ui/core/npc_master.php').read().decode()
assert 'MockProviderVoice' in profiles_with_provider_voice and sync_tts_name in profiles_with_provider_voice,profiles_with_provider_voice
r=request('/ALMSIVIserver/manage/forms/connector-delete','POST',{'_csrf':csrf,'configuration_id':sync_tts_id,'kind':'tts_provider'}); assert r.status==200,(r.status,r.geturl())
assert 'MockProviderVoice' not in request('/ALMSIVIserver/ui/core/npc_master.php').read().decode()
keys,text=parse(request('/ALMSIVIserver/ui/core/api_keys.php')); assert keys.current==1 and 'API Keys</h1>' in text and 'ALMSIVI_LLM_API_KEY' in text and 'type="password"' in text
player,text=parse(request('/ALMSIVIserver/ui/core/player_management.php')); assert player.current==1 and 'Player Management</h1>' in text and 'player profile' in text.lower(),text
narrator,text=parse(request('/ALMSIVIserver/ui/narrator_management.php')); assert narrator.current==1 and 'Narrator Management</h1>' in text and 'narrator routing' in text.lower()
globals_page,text=parse(request('/ALMSIVIserver/ui/core/global_settings.php')); assert globals_page.current==1 and 'Global Settings</h1>' in text and 'name="rechat" value="1" aria-label="Rechat"' in text and 'name="rechat" value="1" disabled' not in text and 'name="boredom" value="1" disabled aria-disabled="true"' in text and 'name="auto_greeting" value="1" disabled aria-disabled="true"' in text and 'feature-state-excluded' in text and 'feature-state-replaced' in text
global_settings_form=next(f for f in globals_page.forms if f['action'].endswith('/forms/global-settings-save'))
assert global_settings_form['fields'].get('oghma_enabled')=='1' and 'oghma_extractor_enabled' not in global_settings_form['fields'] and global_settings_form['fields'].get('oghma_topic_count')=='1' and global_settings_form['fields'].get('oghma_result_limit')=='3' and global_settings_form['fields'].get('oghma_extractor_timeout_ms')=='1500',global_settings_form['fields']
assert '/forms/autonomy' not in text and 'New Schedule' not in text
excluded_autonomy=request('/ALMSIVIserver/manage/forms/autonomy','POST',{'_csrf':csrf}); assert excluded_autonomy.status==404,excluded_autonomy.status
biographies,text=parse(request('/ALMSIVIserver/ui/core/npc_biographies.php'))
assert biographies.current==1 and '<h1>NPC Biography Management</h1>' in text,text
assert any(f['action'].endswith('/forms/biography-template-revise') for f in biographies.forms),'factory biography templates are not editable'
descriptions,text=parse(request('/ALMSIVIserver/ui/description_manager.php')); assert descriptions.current==1 and '<h1>Description Manager</h1>' in text and 'Descriptions Database' in text
assert request('/ALMSIVIserver/ui/server_plugins.php').status==404
assert request('/ALMSIVIserver/manage/server-plugins').status==404
llm,text=parse(request('/ALMSIVIserver/ui/core/llm_connectors.php')); assert llm.current==1 and 'LLM Connectors</h1>' in text and 'Server runtime' in text and all('api_key' not in f['fields'] for f in llm.forms)
llm_runtime,runtime_text=parse(request('/ALMSIVIserver/ui/core/llm_connectors.php?selected=runtime')); assert llm_runtime.current==1 and 'ALMSIVI_LLM_API_KEY' in runtime_text and all('api_key' not in f['fields'] for f in llm_runtime.forms)
for import_path in ['/ALMSIVIserver/ui/core/npc_master.php','/ALMSIVIserver/ui/core/llm_connectors.php?import=1','/ALMSIVIserver/ui/core/tts_connectors.php?import=1','/ALMSIVIserver/ui/prompts_manager.php']:
    _,import_text=parse(request(import_path)); assert 'type="file" accept="application/json,.json" data-json-import-target=' in import_text and 'Choose a JSON file or paste its contents here.' in import_text,import_path
hub_text=request('/ALMSIVIserver/ui/core/config_hub.php').read().decode(); assert all('data-tab="'+tab+'"' in hub_text for tab in ['npc','profiles','player','narrator','npcbio','llm','ttscfg','xtts','sttcfg','keys','globals','oghma','items','actions','prompts']) and 'data-tab="serverplugins"' not in hub_text and 'Server Plugins' not in hub_text and 'data-tab="ittcfg"' not in hub_text and 'ITT</span>' not in hub_text and 'Narration' in hub_text and 'autonomy-page' not in hub_text and '/ui/css/herika-navbar-layout.css' in hub_text
pages_css=request('/ALMSIVIserver/ui/css/almsivi-pages.css').read().decode()
navbar_css=request('/ALMSIVIserver/ui/css/navbar.css').read().decode()
navbar_layout_css=request('/ALMSIVIserver/ui/css/herika-navbar-layout.css').read().decode()
home_css=request('/ALMSIVIserver/ui/css/herika-home.css').read().decode()
resource_css=request('/ALMSIVIserver/ui/css/herika-resource.css').read().decode()
assert '.dashboard-container {' in home_css and 'grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));' in home_css
assert '.llm-layout {' in resource_css and '.page-header {' in resource_css and '.conn-list {' in resource_css
assert '.npc-grid { display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:14px; }' in pages_css
assert '.chim-navbar-wrapper {' in navbar_css and 'max-width: 1200px;' in navbar_css
assert '.navbar-content-wrapper {' in navbar_layout_css and 'justify-content: center;' in navbar_layout_css and 'max-width: 1000px;' in navbar_layout_css
for path in [
    '/ALMSIVIserver/manage/quickstart', '/ALMSIVIserver/manage/roleplay',
    '/ALMSIVIserver/manage/configuration', '/ALMSIVIserver/manage/control-panel',
    '/ALMSIVIserver/manage/characters', '/ALMSIVIserver/manage/profiles',
    '/ALMSIVIserver/manage/player',
    '/ALMSIVIserver/manage/npc-biographies',
    '/ALMSIVIserver/manage/descriptions',
    '/ALMSIVIserver/manage/providers', '/ALMSIVIserver/manage/ai-voice',
    '/ALMSIVIserver/manage/action-editor',
    '/ALMSIVIserver/manage/prompts-actions', '/ALMSIVIserver/manage/world',
    '/ALMSIVIserver/manage/traces', '/ALMSIVIserver/manage/memory',
    '/ALMSIVIserver/manage/relationships', '/ALMSIVIserver/manage/knowledge',
    '/ALMSIVIserver/manage/playthroughs', '/ALMSIVIserver/manage/narrative-autonomy',
    '/ALMSIVIserver/manage/jobs', '/ALMSIVIserver/manage/response-queue', '/ALMSIVIserver/manage/oghma-audit',
    '/ALMSIVIserver/manage/provider-usage', '/ALMSIVIserver/manage/cache', '/ALMSIVIserver/manage/backup-health',
    '/ALMSIVIserver/manage/database-manager', '/ALMSIVIserver/manage/server-logs', '/ALMSIVIserver/manage/diagnostics',
]:
    response=request(path); assert response.status==200 and '/ui/' in response.geturl(),(path,response.geturl())
profile,profile_text=parse(request('/ALMSIVIserver/ui/core/npc_master.php'))
profile_labels=['Voice sample','Standard LLM','Fast LLM','Powerful LLM','Experimental LLM','Fallback LLM','LLM randomizer','Fallback retry','TTS connector','Prompt head (advanced system guidance)','Core identity and boundaries','Gender','Race','Skills and capabilities','Allowed moods and emotes','Lock against automatic AI profile generation','Favorite NPC']
missing_profile_labels=[label for label in profile_labels if label not in profile_text]
assert not missing_profile_labels,missing_profile_labels
characters,_=parse(request('/ALMSIVIserver/ui/core/character_manager.php'))
auto_lock=next(f for f in characters.forms if f['action'].endswith('/forms/profile-auto-lock'))
if auto_lock['fields'].get('enabled')!='1':
    r=request(auto_lock['action'],'POST',dict(auto_lock['fields'],_csrf=csrf,enabled='1')); assert r.status==200
    characters,_=parse(request('/ALMSIVIserver/ui/core/character_manager.php')); auto_lock=next(f for f in characters.forms if f['action'].endswith('/forms/profile-auto-lock'))
assert auto_lock['fields'].get('enabled')=='1','auto-lock preference did not default on'
disabled=dict(auto_lock['fields'],_csrf=csrf); disabled.pop('enabled',None)
r=request(auto_lock['action'],'POST',disabled); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved'),(r.status,r.geturl())
form=next(f for f in profile.forms if f['action'].endswith('/forms/profile-create'))
invalid=dict(form['fields'],_csrf=csrf,installation_id='invalid',name='Test',voice_language='en')
r=request(form['action'],'POST',invalid); _,text=parse(r); assert r.status==422 and 'role="alert"' in text
profile_name='HTTP managed profile '+uuid.uuid4().hex
valid=dict(form['fields'],_csrf=csrf,name=profile_name,voice_id=batch_voice,voice_language='en',gender='Female',race='Dunmer',prompt_head='Stay grounded in TES3 lore.',core='A cautious Balmora guide.',biography='Created through the labelled management form.',personality='Preserved personality field.',skills='Local geography and alchemy.',emote_moods='calm, wary',setting_behavior_rechat='1',setting_behavior_rechat_max_depth='4',setting_behavior_auto_greeting='1',setting_behavior_boredom='1',setting_behavior_combat_barks='1',setting_behavior_rechat_delay_seconds='999',setting_presentation_show_status_hud='0')
valid['installation_id']=auto_lock['fields']['installation_id']
valid['favorite']='1'
r=request(form['action'],'POST',valid); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved'),(r.status,r.geturl(),body); assert 'NPC profile change saved.' in body
profile_match=re.search(re.escape(profile_name)+r'.*?name="profile_id" value="([0-9a-f-]{36})"',body,re.S); assert profile_match,profile_name
profile_id=profile_match.group(1)
profile_export=json.loads(request('/ALMSIVIserver/manage/exports/profiles/'+profile_id+'.json').read().decode()); profile_overrides=profile_export['content']['settings_overrides']
assert profile_overrides['behavior']=={'rechat':True,'rechat_max_depth':4} and 'presentation' not in profile_overrides,profile_overrides
r=request('/ALMSIVIserver/ui/core/voice_library.php','POST',{'_csrf':csrf,'action':'delete','voice_name':batch_voice}); body=r.read().decode()
assert r.status==200 and 'voice_sample_in_use' in body and 'Profile: '+profile_name in body and batch_voice in body,(r.status,r.geturl(),body)
managed_for_clone,_=parse(request('/ALMSIVIserver/ui/core/character_manager.php'))
clone_form=next(f for f in managed_for_clone.forms if f['action'].endswith('/forms/profile-clone') and f['fields'].get('profile_id')==profile_id)
clone_name=profile_name+' clone'
r=request(clone_form['action'],'POST',dict(clone_form['fields'],_csrf=csrf,name=clone_name)); body=r.read().decode()
assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved') and clone_name in body,(r.status,r.geturl(),body)
clone_id=selected_record_id(body,clone_name); clone_export=json.loads(request('/ALMSIVIserver/manage/exports/profiles/'+clone_id+'.json').read().decode())
assert clone_export['name']==clone_name and clone_export['content']['biography']==valid['biography'] and clone_export['content']['core']==valid['core'] and clone_export['content']['skills']==valid['skills'] and clone_export['content']['gender']=='Female' and clone_export['content']['race']=='Dunmer' and 'portrait' not in clone_export['content'],clone_export
r=request('/ALMSIVIserver/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':clone_id}); assert r.status==200
tts_page,_=parse(request('/ALMSIVIserver/ui/core/tts_connectors.php?create=1'))
create_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/tts-providers'))
assert create_tts['fields'].get('option_fields_present')=='1' and 'option__speed' in create_tts['fields'] and 'option__temperature' not in create_tts['fields'],create_tts
tts_name='HTTP TTS '+uuid.uuid4().hex
values=dict(create_tts['fields'],_csrf=csrf,installation_id=valid['installation_id'],name=tts_name,driver='pockettts',endpoint='http://127.0.0.1:8021',model='default',voice='default',language='en',timeout_ms='30000',fallback_male='TestMale',fallback_female='TestFemale',option__speed='1.1',option__temperature='0.6',options_json='{}')
r=request(create_tts['action'],'POST',values); body=r.read().decode(); assert r.status==200 and tts_name in body,(r.status,r.geturl(),body)
tts_id=connector_editor_id(body,tts_name)
tts_export_response=request('/ALMSIVIserver/manage/exports/connectors/'+tts_id+'.json'); tts_export=json.loads(tts_export_response.read().decode())
assert tts_export['content']['options']['speed']==1.1 and tts_export['content']['options']['temperature']==0.6,tts_export
tts_page,_=parse(request('/ALMSIVIserver/ui/core/tts_connectors.php?selected='+tts_id))
revise_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/connector-revise') and f['fields'].get('configuration_id')==tts_id)
assert 'option__speed' in revise_tts['fields'] and 'option__temperature' in revise_tts['fields'] and revise_tts['fields'].get('option_fields_present')=='1',revise_tts
values=dict(revise_tts['fields'],_csrf=csrf,option__speed='1.25',option__temperature='0.7',change_reason='HTTP labelled TTS options')
r=request(revise_tts['action'],'POST',values); body=r.read().decode(); assert r.status==200 and tts_name in body,(r.status,r.geturl(),body)
tts_export_response=request('/ALMSIVIserver/manage/exports/connectors/'+tts_id+'.json'); tts_export=json.loads(tts_export_response.read().decode())
assert tts_export_response.status==200 and tts_export['schema']=='almsivi.connector-export.v1' and tts_export['kind']=='tts_provider' and 'installation_id' not in tts_export and 'api_key' not in json.dumps(tts_export).lower()
assert tts_export['content']['options']['fallback_male']=='TestMale' and tts_export['content']['options']['fallback_female']=='TestFemale',tts_export
assert tts_export['content']['options']['speed']==1.25 and tts_export['content']['options']['temperature']==0.7,tts_export
tts_page,_=parse(request('/ALMSIVIserver/ui/core/tts_connectors.php?selected='+tts_id))
revise_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/connector-revise') and f['fields'].get('configuration_id')==tts_id)
values=dict(revise_tts['fields'],_csrf=csrf,driver='omnivoice',option__speed='1.0',change_reason='HTTP connector driver switch')
r=request(revise_tts['action'],'POST',values); assert r.status==200,(r.status,r.geturl(),r.read().decode())
tts_export=json.loads(request('/ALMSIVIserver/manage/exports/connectors/'+tts_id+'.json').read().decode())
assert tts_export['content']['driver']=='omnivoice' and tts_export['content']['options']['speed']==1.0 and 'temperature' not in tts_export['content']['options'],tts_export
tts_page,_=parse(request('/ALMSIVIserver/ui/core/tts_connectors.php?selected='+tts_id))
clone_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/connector-clone') and f['fields'].get('configuration_id')==tts_id)
clone_name=tts_name+' clone'; r=request(clone_tts['action'],'POST',dict(clone_tts['fields'],_csrf=csrf,name=clone_name)); body=r.read().decode()
assert r.status==200 and clone_name in body,(r.status,r.geturl(),body)
clone_tts_id=connector_editor_id(body,clone_name)
r=request('/ALMSIVIserver/manage/forms/connector-delete','POST',{'_csrf':csrf,'configuration_id':clone_tts_id,'kind':'tts_provider'}); assert r.status==200
tts_export['name']=tts_name+' imported'; tts_page,_=parse(request('/ALMSIVIserver/ui/core/tts_connectors.php?import=1'))
import_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/connector-import'))
r=request(import_tts['action'],'POST',dict(import_tts['fields'],_csrf=csrf,installation_id=valid['installation_id'],kind='tts_provider',connector_json=json.dumps(tts_export))); body=r.read().decode()
assert r.status==200 and tts_export['name'] in body,(r.status,r.geturl(),body)
import_tts_id=connector_editor_id(body,tts_export['name'])
r=request('/ALMSIVIserver/manage/forms/connector-delete','POST',{'_csrf':csrf,'configuration_id':import_tts_id,'kind':'tts_provider'}); assert r.status==200
tts_page,_=parse(request('/ALMSIVIserver/ui/core/tts_connectors.php?selected='+tts_id)); activate_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/connector-selection') and f['fields'].get('configuration_id')==tts_id)
r=request(activate_tts['action'],'POST',dict(activate_tts['fields'],_csrf=csrf)); body=r.read().decode(); assert r.status==200,(r.status,r.geturl(),body)
body=request('/ALMSIVIserver/ui/core/tts_connectors.php?selected='+tts_id).read().decode(); assert 'This active connector cannot be deleted.' in body,body
provider_voice='ProviderVoice'+uuid.uuid4().hex
r=request('/ALMSIVIserver/manage/forms/connector-default-voice','POST',{'_csrf':csrf,'configuration_id':tts_id,'voice_id':provider_voice,'language':'en'}); body=r.read().decode()
saved_voice_url=urllib.parse.urlparse(r.geturl()); saved_voice_query=urllib.parse.parse_qs(saved_voice_url.query)
assert r.status==200 and saved_voice_url.path.endswith('/ui/core/voice_library.php') and saved_voice_query.get('status')==['saved'] and saved_voice_query.get('configuration_id')==[tts_id] and provider_voice in body,(r.status,r.geturl(),body)
updated_tts=json.loads(request('/ALMSIVIserver/manage/exports/connectors/'+tts_id+'.json').read().decode()); assert updated_tts['content']['voice']==provider_voice and updated_tts['content']['language']=='en',updated_tts
r=request('/ALMSIVIserver/manage/forms/connector-delete','POST',{'_csrf':csrf,'configuration_id':tts_id,'kind':'tts_provider'}); body=r.read().decode(); assert r.status==422 and 'connector_in_use' in body,(r.status,r.geturl(),body)
managed_profile,_=parse(request('/ALMSIVIserver/ui/core/character_manager.php'))
generate=next(f for f in managed_profile.forms if f['action'].endswith('/forms/profile-generate') and f['fields'].get('profile_id')==profile_id)
r=request(generate['action'],'POST',dict(generate['fields'],_csrf=csrf)); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved'),(r.status,r.geturl())
managed_profile,body=parse(request('/ALMSIVIserver/ui/core/character_manager.php'))
revise=next(f for f in managed_profile.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==profile_id)
auto_lock=next(f for f in managed_profile.forms if f['action'].endswith('/forms/profile-auto-lock'))
r=request(auto_lock['action'],'POST',dict(auto_lock['fields'],_csrf=csrf,enabled='1')); assert r.status==200
values=dict(revise['fields'],_csrf=csrf,favorite='1',voice_id='',voice_language='en',change_reason='HTTP auto-lock and favorite test'); values.pop('locked',None)
r=request(revise['action'],'POST',values); body=r.read().decode(); locked_export=json.loads(request('/ALMSIVIserver/manage/exports/profiles/'+profile_id+'.json').read().decode())
assert r.status==200 and profile_name in body and 'data-lock-id="'+profile_id+'"' in body and locked_export['content']['management']=={'locked':True,'favorite':True},(r.status,r.geturl(),locked_export)
r=request('/ALMSIVIserver/ui/core/voice_library.php','POST',{'_csrf':csrf,'action':'delete','voice_name':batch_voice}); body=r.read().decode()
assert r.status==200 and 'Local voice sample deleted.' in body and batch_voice not in body,(r.status,r.geturl(),body)
filtered=request('/ALMSIVIserver/ui/core/character_manager.php?state=favorites&q='+urllib.parse.quote(profile_name)); filtered_body=filtered.read().decode()
assert filtered.status==200 and profile_name in filtered_body and 'name="state" value="favorites"' in filtered_body and 'data-favorite-id="'+profile_id+'"' in filtered_body,(filtered.status,filtered.geturl())
r=request('/ALMSIVIserver/manage/forms/profile-generate','POST',{'_csrf':csrf,'profile_id':profile_id}); body=r.read().decode(); assert r.status==422 and 'profile_locked' in body,(r.status,r.geturl(),body)
portrait_png=bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360f8cff00000040101000db24bc40000000049454e44ae426082')
r=multipart_request('/ALMSIVIserver/ui/core/profile_portrait.php',{'_csrf':csrf,'profile_id':profile_id,'action':'upload'},'portrait','portrait.png','image/png',portrait_png)
body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved') and 'Delete portrait' in body,(r.status,r.geturl(),body)
portrait_response=request('/ALMSIVIserver/ui/core/profile_portrait.php?profile_id='+profile_id); portrait_body=portrait_response.read()
assert portrait_response.status==200 and portrait_response.headers.get_content_type()=='image/png' and portrait_body==portrait_png,(portrait_response.status,portrait_response.headers.get_content_type(),portrait_response.headers.get('X-ALMSIVI-Portrait-Status'),len(portrait_body),portrait_body[:24].hex())
export_response=request('/ALMSIVIserver/manage/exports/profiles/'+profile_id+'.json'); exported=json.loads(export_response.read().decode())
assert export_response.status==200 and exported['schema']=='almsivi.profile-export.v1' and exported['name']==profile_name and 'installation_id' not in exported and 'portrait' not in exported['content'] and exported['content']['management']=={'locked':True,'favorite':True}
r=request('/ALMSIVIserver/ui/core/profile_portrait.php','POST',{'_csrf':csrf,'profile_id':profile_id,'action':'delete'}); body=r.read().decode(); deleted_portrait=request('/ALMSIVIserver/ui/core/profile_portrait.php?profile_id='+profile_id)
assert r.status==200 and deleted_portrait.status==404,(r.status,r.geturl(),deleted_portrait.status)
imported_name=profile_name+' imported'; exported['name']=imported_name
profiles,_=parse(request('/ALMSIVIserver/ui/core/npc_master.php'))
import_form=next(f for f in profiles.forms if f['action'].endswith('/forms/profile-import'))
values=dict(import_form['fields'],_csrf=csrf,profile_json=json.dumps(exported))
r=request(import_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved') and imported_name in body,(r.status,r.geturl())
imported_id=selected_record_id(body,imported_name)
r=request('/ALMSIVIserver/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':imported_id}); assert r.status==200
characters,body=parse(request('/ALMSIVIserver/ui/core/character_manager.php'))
unlock=next(f for f in characters.forms if f['action'].endswith('/forms/profile-bulk-unlock'))
r=request(unlock['action'],'POST',dict(unlock['fields'],_csrf=csrf,confirm='wrong')); body=r.read().decode(); assert r.status==422 and 'confirmation_mismatch' in body
r=request(unlock['action'],'POST',dict(unlock['fields'],_csrf=csrf,confirm='Unlock')); body=r.read().decode(); unlocked_export=json.loads(request('/ALMSIVIserver/manage/exports/profiles/'+profile_id+'.json').read().decode())
assert r.status==200 and profile_name in body and unlocked_export['content']['management']['locked'] is False,(r.status,r.geturl(),unlocked_export)
characters,_=parse(request('/ALMSIVIserver/ui/core/character_manager.php'))
revise=next(f for f in characters.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==profile_id)
r=request(revise['action'],'POST',dict(revise['fields'],_csrf=csrf,favorite='1',change_reason='Restore lock after bulk unlock test')); assert r.status==200
characters,_=parse(request('/ALMSIVIserver/ui/core/character_manager.php')); auto_lock=next(f for f in characters.forms if f['action'].endswith('/forms/profile-auto-lock'))
disabled=dict(auto_lock['fields'],_csrf=csrf); disabled.pop('enabled',None); r=request(auto_lock['action'],'POST',disabled); assert r.status==200
extra_name='HTTP bulk delete '+uuid.uuid4().hex
extra=dict(form['fields'],_csrf=csrf,name=extra_name,voice_language='en',biography='Disposable unlocked bulk profile.')
extra['installation_id']=valid['installation_id']
r=request(form['action'],'POST',extra); body=r.read().decode(); assert r.status==200 and extra_name in body,(r.status,r.geturl(),body)
characters,_=parse(request('/ALMSIVIserver/ui/core/character_manager.php'))
bulk_generate=next(f for f in characters.forms if f['action'].endswith('/forms/profile-bulk-generate'))
r=request(bulk_generate['action'],'POST',dict(bulk_generate['fields'],_csrf=csrf,confirm='wrong')); body=r.read().decode(); assert r.status==422 and 'confirmation_mismatch' in body
r=request(bulk_generate['action'],'POST',dict(bulk_generate['fields'],_csrf=csrf,confirm='Generate')); assert r.status==200
characters,_=parse(request('/ALMSIVIserver/ui/core/character_manager.php'))
delete_all=next(f for f in characters.forms if f['action'].endswith('/forms/profile-bulk-delete'))
r=request(delete_all['action'],'POST',dict(delete_all['fields'],_csrf=csrf,confirm='Delete')); body=r.read().decode()
bulk_preserved=json.loads(request('/ALMSIVIserver/manage/exports/profiles/'+profile_id+'.json').read().decode())
assert r.status==200 and extra_name not in body and profile_name in body and bulk_preserved['content']['management']['locked'] is True,(r.status,r.geturl(),bulk_preserved)
playthroughs,_=parse(request('/ALMSIVIserver/ui/playthrough_manager.php'))
create_playthrough=next(f for f in playthroughs.forms if f['action'].endswith('/forms/playthroughs'))
playthrough_name='HTTP playthrough '+uuid.uuid4().hex
values=dict(create_playthrough['fields'],_csrf=csrf,profile_id=profile_id,name=playthrough_name,content_json='{}')
r=request(create_playthrough['action'],'POST',values); body=r.read().decode(); assert r.status==200 and playthrough_name in body and all(label in body for label in ['Sessions','Turns','Responses','Memories','Relationships','Narratives','Knowledge']),(r.status,r.geturl(),body)
match=re.search(r'<h2>'+re.escape(playthrough_name)+r'</h2>.*?/exports/playthroughs/([0-9a-f-]{36})\.json',body,re.S); assert match,body
playthrough_id=match.group(1)
narratives,_=parse(request('/ALMSIVIserver/ui/narrative_manager.php'))
create_narrative=next(f for f in narratives.forms if f['action'].endswith('/forms/narratives'))
narrative_title='HTTP diary '+uuid.uuid4().hex; narrative_text='Arrived in Seyda Neen.'
values=dict(create_narrative['fields'],_csrf=csrf,installation_id=valid['installation_id'],profile_id=profile_id,playthrough_id=playthrough_id,kind='diary',title=narrative_title,content=narrative_text,provenance='management-http')
r=request(create_narrative['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/narrative_manager.php?status=saved') and narrative_title in body,(r.status,r.geturl(),body)
narrative_match=re.search(re.escape(narrative_title)+r'.*?name="narrative_id" value="([0-9a-f-]{36})"',body,re.S); assert narrative_match,body
narrative_id=narrative_match.group(1); narratives,_=parse(request('/ALMSIVIserver/ui/narrative_manager.php'))
revise_narrative=next(f for f in narratives.forms if f['action'].endswith('/forms/narrative-revise') and f['fields'].get('narrative_id')==narrative_id)
revised_title=narrative_title+' revised'; revised_text='Reached Balmora and found Caius.'
r=request(revise_narrative['action'],'POST',dict(revise_narrative['fields'],_csrf=csrf,kind='summary',title=revised_title,content=revised_text,provenance='management-http edit')); body=r.read().decode()
assert r.status==200 and revised_title in body and revised_text in body,(r.status,r.geturl(),body)
r=request('/ALMSIVIserver/manage/forms/narrative-delete','POST',{'_csrf':csrf,'narrative_id':narrative_id}); body=r.read().decode(); assert r.status==200 and revised_title not in body,(r.status,r.geturl())
globals_page,_=parse(request('/ALMSIVIserver/ui/core/global_settings.php'))
settings_form=next(f for f in globals_page.forms if f['action'].endswith('/forms/global-settings-save'))
values=dict(settings_form['fields'],_csrf=csrf,installation_id=valid['installation_id'],recent_turn_limit='20',knowledge_limit='6',narrator_name='The Narrator',narrator_inline_mode='Disabled',auto_lock_profile='1',change_reason='HTTP layered global settings')
r=request(settings_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'tab=globals-page' in r.geturl(),(r.status,r.geturl(),body)
globals_page,body=parse(request('/ALMSIVIserver/ui/core/global_settings.php'))
assert 'name="knowledge_limit" value="6"' in body and 'name="rechat" value="1" aria-label="Rechat"' in body and 'name="rechat" value="1" disabled' not in body and 'name="auto_lock_profile" value="1" checked' in body
assert all('<h2>'+section+'</h2>' in body for section in ['Memory','Misc','Quests','Translation']) and all(name in body for name in ['memory_embedding_enabled','player_worst_memory_game_days','autofill_custom_profiles','chim_ai_quest_progression','translation_provider']) and 'Background Life Trigger Time' not in body
memories,_=parse(request('/ALMSIVIserver/ui/events-memories.php?tab=memories-tab'))
create_memory=next(f for f in memories.forms if f['action'].endswith('/forms/memory'))
memory_text='HTTP managed memory '+uuid.uuid4().hex
values=dict(create_memory['fields'],_csrf=csrf,installation_id=valid['installation_id'],profile_id=profile_id,playthrough_id=playthrough_id,tier='mid',content=memory_text,provenance='management-http')
r=request(create_memory['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'tab=memory' in r.geturl() and memory_text in body,(r.status,r.geturl(),body)
memory_match=re.search(re.escape(memory_text)+r'.*?name="memory_id" value="([0-9a-f-]{36})"',body,re.S); assert memory_match,body
memory_id=memory_match.group(1)
policy_page,_=parse(request('/ALMSIVIserver/ui/events-memories.php?tab=memory'))
summary_form=next(f for f in policy_page.forms if f['action'].endswith('/forms/memory-policy'))
assert 'enabled' not in summary_form['fields']
summary_values=dict(summary_form['fields'],_csrf=csrf,installation_id=valid['installation_id'])
r=request(summary_form['action'],'POST',dict(summary_values,enabled='1',provider_configuration_id='')); assert r.status==422
r=request(summary_form['action'],'POST',dict(summary_values,_csrf='wrong')); assert r.status==200 and r.geturl().endswith('/ui/home.php')
policy_page,_=parse(request('/ALMSIVIserver/ui/events-memories.php?tab=memory'))
assert 'enabled' not in next(f for f in policy_page.forms if f['action'].endswith('/forms/memory-policy'))['fields']
for changes in [{'memory_id':'not-a-uuid'},{'base_revision':'1.5'},{'base_revision':'0'},{}]:
    r=request('/ALMSIVIserver/manage/forms/memory-summarize','POST',dict({'_csrf':csrf,'installation_id':valid['installation_id'],'memory_id':memory_id,'base_revision':'1'},**changes))
    assert r.status==422,(r.status,r.read().decode()) # Manual memories are never model-summary inputs.
memories,_=parse(request('/ALMSIVIserver/ui/events-memories.php?tab=memories-tab'))
revise_memory=next(f for f in memories.forms if f['action'].endswith('/forms/memory-revise') and f['fields'].get('memory_id')==memory_id)
revised_memory=memory_text+' revised'; r=request(revise_memory['action'],'POST',dict(revise_memory['fields'],_csrf=csrf,content=revised_memory)); body=r.read().decode(); assert r.status==200 and revised_memory in body,(r.status,r.geturl(),body)
r=request('/ALMSIVIserver/manage/forms/memory-delete','POST',{'_csrf':csrf,'memory_id':memory_id}); body=r.read().decode(); assert r.status==200 and revised_memory not in body,(r.status,r.geturl())
legacy_relationships,body=parse(request('/ALMSIVIserver/ui/events-memories.php?tab=relationships-tab'))
assert legacy_relationships.current==1 and 'id="journal-tab" class="tab-content active"' in body and '/forms/relationships' not in body,(legacy_relationships.current,body)
relationship_page,body=parse(request('/ALMSIVIserver/ui/relationship_logs.php?embed=1&installation_id='+valid['installation_id']))
relationship_create=next((f for f in relationship_page.forms if f['action'].endswith('/forms/relationships')),None)
assert relationship_create is not None,body
assert re.search(r'id="relationship-custom-info"[^>]*></textarea>',body)
private_relationship_note='{"token":"private reminder","text":"<&> 古"}'
build_query=urllib.parse.urlencode(dict(installation_id=valid['installation_id'],profile_id=profile_id,playthrough_id=playthrough_id,embed='1'))
build_page,build_body=parse(request('/ALMSIVIserver/ui/relationship_logs.php?'+build_query))
build_form=next(f for f in build_page.forms if f['action'].endswith('/forms/relationship-history-build'))
build_values=dict(build_form['fields'],_csrf=csrf,history_limit='25')
assert build_form['fields']['history_limit']=='100' and uuid.UUID(build_values['request_id'])
r=request(build_form['action'],'POST',build_values); build_page,build_body=parse(r)
assert r.status==200 and 'relationship_build_no_connector' in r.geturl() and 'role="alert"' in build_body
build_retry=next(f for f in build_page.forms if f['action'].endswith('/forms/relationship-history-build'))
assert all(build_retry['fields'][key]==build_values[key] for key in ['installation_id','profile_id','playthrough_id','history_limit','embed'])
assert request(build_form['action'],'POST',dict(build_values,history_limit='101')).status==422
assert request(build_form['action'],'POST',dict(build_values,playthrough_id=str(uuid.uuid4()))).status==422
r=request(build_form['action'],'POST',dict(build_values,_csrf='wrong')); assert r.status==200 and r.geturl().endswith('/ui/home.php')
relationship_identity={'kind':'npc','record_id':'http_relationship_actor','display_name':'HTTP relationship actor',
    'content_file':'Morrowind.esm','refnum':{'index':98765,'content_file':0},'cell':{'kind':'interior','name':'HTTP fixture'}}
relationship_values=dict(relationship_create['fields'],_csrf=csrf,installation_id=valid['installation_id'],profile_id=profile_id,
    playthrough_id=playthrough_id,actor_profile_id='',content_json=json.dumps(relationship_identity),disposition='10',affinity='5',reason='HTTP relationship create',custom_info=private_relationship_note)
r=request(relationship_create['action'],'POST',dict(relationship_values,disposition='not-a-number')); assert r.status==422
r=request(relationship_create['action'],'POST',dict(relationship_values,_csrf='wrong')); assert r.status==200 and r.geturl().endswith('/ui/home.php')
r=request(relationship_create['action'],'POST',relationship_values); relationship_page,body=parse(r)
assert r.status==200 and 'embed=1' in r.geturl() and 'HTTP relationship actor' in body,(r.status,r.geturl(),body)
relationship_edit=next(f for f in relationship_page.forms if f['action'].endswith('/forms/relationships') and f['fields'].get('relationship_id'))
relationship_id=relationship_edit['fields']['relationship_id']; assert relationship_edit['fields']['expected_revision']=='1'
custom_info_pattern=r'<textarea id="custom-info-'+relationship_id+r'"[^>]*>\n(.*?)</textarea>'
assert html.unescape(re.search(custom_info_pattern,body,re.S)[1])==private_relationship_note
renamed_identity=dict(relationship_identity,display_name='Renamed HTTP actor')
r=request(relationship_create['action'],'POST',dict(relationship_values,content_json=json.dumps(renamed_identity))); body=r.read().decode()
assert r.status==200 and 'relationship_already_exists' in r.geturl() and 'already has a relationship' in body
r=request(relationship_edit['action'],'POST',dict(relationship_edit['fields'],_csrf=csrf,disposition='20',affinity='6',reason='HTTP relationship edit')); relationship_page,body=parse(r)
latest_edit=next(f for f in relationship_page.forms if f['fields'].get('relationship_id')==relationship_id and f['action'].endswith('/forms/relationships'))
assert latest_edit['fields']['expected_revision']=='2' and latest_edit['fields']['disposition']=='20'
assert html.unescape(re.search(custom_info_pattern,body,re.S)[1])==private_relationship_note # Older score-only forms preserve it.
assert 'private reminder' not in body.split('id="relationship-history"',1)[1] and 'Custom Info updated' in body
assert request(latest_edit['action'],'POST',dict(latest_edit['fields'],_csrf=csrf,custom_info='x'*2001)).status==422
assert request(latest_edit['action'],'POST',dict(latest_edit['fields'],_csrf=csrf,**{'custom_info[]':'invalid'})).status==422
private_relationship_note='\nKeep <&> 古\nTrailing spaces  '
r=request(latest_edit['action'],'POST',dict(latest_edit['fields'],_csrf=csrf,custom_info=private_relationship_note.replace('\n','\r\n'))); relationship_page,body=parse(r)
assert html.unescape(re.search(custom_info_pattern,body,re.S)[1])==private_relationship_note
private_relationship_backup=json.loads(request('/ALMSIVIserver/manage/exports/playthroughs/'+playthrough_id+'.json').read())
exported_relationships=private_relationship_backup['data']['relationships']
assert next(row for row in exported_relationships if row['relationship_id']==relationship_id)['custom_info']==private_relationship_note
latest_edit=next(f for f in relationship_page.forms if f['fields'].get('relationship_id')==relationship_id and f['action'].endswith('/forms/relationships'))
r=request(latest_edit['action'],'POST',dict(latest_edit['fields'],_csrf=csrf,custom_info='')); relationship_page,body=parse(r)
assert re.search(custom_info_pattern,body,re.S)[1]==''
try:
    opener.open(urllib.request.Request(base+'/ALMSIVIserver/manage/api/v1/playthrough-restore',
        data=json.dumps(private_relationship_backup).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':csrf}),timeout=5)
    raise AssertionError('conflicting relationship restore was accepted')
except urllib.error.HTTPError as error:
    assert error.code==409 and json.loads(error.read())['error']=='relationship_restore_conflict'
r=request(relationship_edit['action'],'POST',dict(relationship_edit['fields'],_csrf=csrf,disposition='99',affinity='6',custom_info='Stale overwrite',reason='Stale edit')); body=r.read().decode()
assert r.status==200 and 'relationship_revision_conflict' in r.geturl() and 'Unsaved edits were not kept' in body
assert re.search(custom_info_pattern,body,re.S)[1]==''
api_relationship={key:relationship_values[key] for key in ['installation_id','profile_id','playthrough_id']}
api_relationship.update(relationship_id=relationship_id,expected_revision=1,disposition=99,affinity=0,source_mode='manual')
try:
    opener.open(urllib.request.Request(base+'/ALMSIVIserver/manage/api/v1/relationships',data=json.dumps(api_relationship).encode(),
        headers={'Content-Type':'application/json','X-CSRF-Token':csrf}),timeout=5)
    raise AssertionError('stale management API write accepted')
except urllib.error.HTTPError as error:
    assert error.code==409 and json.loads(error.read())['error']=='relationship_revision_conflict'
relationship_delete=next(f for f in relationship_page.forms if f['fields'].get('relationship_id')==relationship_id and f['action'].endswith('/forms/relationship-delete'))
r=request(relationship_delete['action'],'POST',dict(relationship_delete['fields'],_csrf=csrf,expected_revision='1')); assert r.status==200 and 'relationship_revision_conflict' in r.geturl()
r=request(relationship_delete['action'],'POST',dict(relationship_delete['fields'],_csrf=csrf)); relationship_page,body=parse(r)
assert r.status==200 and not any(f['fields'].get('relationship_id')==relationship_id for f in relationship_page.forms)
assert 'HTTP relationship create' in body and 'HTTP relationship edit' in body and 'management delete' in body and 'Recent changes (5 shown)' in body
backup_response=request('/ALMSIVIserver/manage/exports/playthroughs/'+playthrough_id+'.json'); backup=json.loads(backup_response.read().decode())
assert backup_response.status==200 and backup['schema']=='almsivi.playthrough-export.v1' and backup['scope']=={'installation_id':valid['installation_id'],'profile_id':profile_id,'playthrough_id':playthrough_id},backup['scope']
playthroughs,_=parse(request('/ALMSIVIserver/ui/playthrough_manager.php'))
restore=next(f for f in playthroughs.forms if f['action'].endswith('/forms/playthrough-import'))
values=dict(restore['fields'],_csrf=csrf,profile_id=profile_id,playthrough_id=playthrough_id,playthrough_json=json.dumps(backup))
r=request(restore['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/playthrough_manager.php?status=saved'),(r.status,r.geturl(),values,backup['scope'],body)
characters,body=parse(request('/ALMSIVIserver/ui/core/character_manager.php'))
bio_form=next(f for f in characters.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==profile_id)
current_profile=json.loads(request('/ALMSIVIserver/manage/exports/profiles/'+profile_id+'.json').read().decode())
values=dict(bio_form['fields'],_csrf=csrf,profile_id=profile_id,base_content_json=json.dumps(current_profile['content']),biography='Updated from Character Manager.',change_reason='HTTP biography test')
r=request(bio_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved'),(r.status,r.geturl())
body=request('/ALMSIVIserver/ui/core/character_manager.php').read().decode(); assert 'Updated from Character Manager.' in body and 'Preserved personality field.' in body
summary_connectors,_=parse(request('/ALMSIVIserver/ui/core/llm_connectors.php?create=1'))
summary_connector_form=next(f for f in summary_connectors.forms if f['action'].endswith('/forms/providers'))
summary_connector_name='HTTP memory summary '+uuid.uuid4().hex
r=request(summary_connector_form['action'],'POST',dict(summary_connector_form['fields'],_csrf=csrf,name=summary_connector_name,driver='mock',model='memory-http'))
summary_connector_id=connector_editor_id(r.read().decode(),summary_connector_name)
summary_values.update(provider_configuration_id=summary_connector_id,enabled='1')
r=request(summary_form['action'],'POST',summary_values); policy_page,body=parse(r)
assert r.status==200 and 'policy_installation_id='+valid['installation_id'] in r.geturl()
assert next(f for f in policy_page.forms if f['action'].endswith('/forms/memory-policy'))['fields']['enabled']=='1'
assert any(f['action'].endswith('/forms/memory-rebuild') for f in policy_page.forms)
r=request('/ALMSIVIserver/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':summary_connector_id}); assert r.status==422
database,body=parse(request('/ALMSIVIserver/ui/database_manager.php'))
backup_ids_before=set(re.findall(r'/exports/backups/([0-9a-f-]{36})\.json',body))
create_backup=next(f for f in database.forms if f['action'].endswith('/forms/configuration-backup'))
values=dict(create_backup['fields'],_csrf=csrf,installation_id=valid['installation_id'],confirm='wrong')
r=request(create_backup['action'],'POST',values); body=r.read().decode(); assert r.status==422 and 'confirmation_mismatch' in body,(r.status,r.geturl(),body)
values['confirm']='Backup'; r=request(create_backup['action'],'POST',values); body=r.read().decode()
assert r.status==200 and r.geturl().endswith('/ui/database_manager.php?status=saved') and 'Download backup' in body,(r.status,r.geturl(),body)
backup_ids_after=set(re.findall(r'/exports/backups/([0-9a-f-]{36})\.json',body)); created_backup_ids=backup_ids_after-backup_ids_before; assert len(created_backup_ids)==1,(backup_ids_before,backup_ids_after)
configuration_backup_id=created_backup_ids.pop()
backup_response=request('/ALMSIVIserver/manage/exports/backups/'+configuration_backup_id+'.json'); configuration_backup=json.loads(backup_response.read().decode())
assert backup_response.status==200 and configuration_backup['schema']=='almsivi.configuration-backup.v2' and configuration_backup['format_version']==2 and configuration_backup['installation_id']==valid['installation_id']
core_ids={row['core_profile_id'] for row in configuration_backup['data']['core_profiles']}; assert len(core_ids)>=1 and sum(row['default_npc'] is True for row in configuration_backup['data']['core_profiles'])==1
assert all(row['core_profile_id'] in core_ids for row in configuration_backup['data']['profiles']),configuration_backup['data']['profiles']
assert configuration_backup['backup_id']==configuration_backup_id and 'portrait' not in json.dumps(configuration_backup).lower() and 'api_key' not in json.dumps(configuration_backup).lower()
saved_policy=next(row for row in configuration_backup['data']['configurations'] if row['kind']=='memory_policy')
assert saved_policy['content']['enabled'] is True and saved_policy['content']['provider_configuration_id']==summary_connector_id
summary_values.pop('enabled'); r=request(summary_form['action'],'POST',summary_values); assert r.status==200
characters,_=parse(request('/ALMSIVIserver/ui/core/character_manager.php'))
bio_form=next(f for f in characters.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==profile_id)
current_profile=json.loads(request('/ALMSIVIserver/manage/exports/profiles/'+profile_id+'.json').read().decode())
values=dict(bio_form['fields'],_csrf=csrf,profile_id=profile_id,base_content_json=json.dumps(current_profile['content']),biography='Changed after the configuration backup.',change_reason='HTTP pre-restore mutation')
r=request(bio_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'Changed after the configuration backup.' in body
database,_=parse(request('/ALMSIVIserver/ui/database_manager.php'))
restore_configuration=next(f for f in database.forms if f['action'].endswith('/forms/configuration-restore'))
values=dict(restore_configuration['fields'],_csrf=csrf,installation_id=valid['installation_id'],backup_id=configuration_backup_id,confirm='wrong')
r=request(restore_configuration['action'],'POST',values); body=r.read().decode(); assert r.status==422 and 'confirmation_mismatch' in body
values['confirm']='Restore'; r=request(restore_configuration['action'],'POST',values); body=r.read().decode()
assert r.status==200 and r.geturl().endswith('/ui/database_manager.php?status=saved') and 'restored ·' in body,(r.status,r.geturl(),body)
policy_page,_=parse(request('/ALMSIVIserver/ui/events-memories.php?tab=memory'))
restored_policy=next(f for f in policy_page.forms if f['action'].endswith('/forms/memory-policy'))
assert restored_policy['fields']['enabled']=='1' and restored_policy['fields']['provider_configuration_id']==summary_connector_id
summary_values['provider_configuration_id']=''; r=request(summary_form['action'],'POST',summary_values); assert r.status==200
r=request('/ALMSIVIserver/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':summary_connector_id}); assert r.status==200
body=request('/ALMSIVIserver/ui/core/character_manager.php').read().decode(); assert 'Updated from Character Manager.' in body and 'Changed after the configuration backup.' not in body
r=request('/ALMSIVIserver/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':profile_id}); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved'),(r.status,r.geturl())
descriptions,body=parse(request('/ALMSIVIserver/ui/description_manager.php'))
description_form=next(f for f in descriptions.forms if f['action'].endswith('/forms/description-save'))
installation_id=description_form['fields']['installation_id']
example=request('/ALMSIVIserver/manage/exports/descriptions/example.csv'); example_body=example.read().decode('utf-8-sig')
assert example.status==200 and example_body.startswith('plugin,baseid,name,description')
upload_csv=('plugin,baseid,name,description\nHTTP Test.esp,http_csv_item,HTTP CSV Item,"Imported through the batch description manager."\n').encode()
r=multipart_request('/ALMSIVIserver/manage/forms/description-import',{'_csrf':csrf,'installation_id':installation_id},'csv_file','descriptions.csv','text/csv',upload_csv)
body=r.read().decode(); assert r.status==200 and '1 custom descriptions imported.' in body and 'http_csv_item' in body,(r.status,r.geturl(),body)
exported=request('/ALMSIVIserver/manage/exports/descriptions/custom.csv?installation_id='+installation_id).read().decode('utf-8-sig')
assert 'http_csv_item' in exported and 'HTTP CSV Item' in exported,exported
record_id='http_record_'+uuid.uuid4().hex
values=dict(description_form['fields'],_csrf=csrf,content_file='HTTP Test.esp',record_id=record_id,display_name='HTTP Test Item',description='Created through the descriptions manager.')
r=request(description_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'status=saved' in r.geturl() and record_id in body,(r.status,r.geturl())
match=re.search(re.escape(record_id)+r'.*?name="description_id" value="([0-9a-f-]{36})"',body,re.S); assert match,body
r=request('/ALMSIVIserver/manage/forms/description-delete','POST',{'_csrf':csrf,'installation_id':installation_id,'description_id':match.group(1)}); assert r.status==200 and 'status=saved' in r.geturl()
r=request('/ALMSIVIserver/manage/forms/description-reset','POST',{'_csrf':csrf,'installation_id':installation_id,'confirm':'Reset'}); body=r.read().decode(); assert r.status==200 and 'status=saved' in r.geturl() and 'http_csv_item' not in body
llm_page,body=parse(request('/ALMSIVIserver/ui/core/llm_connectors.php?selected=runtime'))
runtime_test=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-runtime-test'))
r=request(runtime_test['action'],'POST',dict(runtime_test['fields'],_csrf=csrf)); body=r.read().decode(); assert r.status==200 and 'Test completed: 1 valid utterance' in body,(r.status,r.geturl(),body)
llm_create_page,_=parse(request('/ALMSIVIserver/ui/core/llm_connectors.php?create=1'))
llm_form=next(f for f in llm_create_page.forms if f['action'].endswith('/forms/providers'))
slot_name='HTTP model slot '+uuid.uuid4().hex
values=dict(llm_form['fields'],_csrf=csrf,name=slot_name,driver='mock',model='deterministic-mock-v1',mock_prefix='[http] ')
r=request(llm_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/llm_connectors.php?status=saved') and slot_name in body,(r.status,r.geturl())
slot_id=connector_editor_id(body,slot_name)
llm_page,body=parse(request('/ALMSIVIserver/ui/core/llm_connectors.php?selected='+slot_id))
model_test=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-test') and f['fields'].get('configuration_id')==slot_id)
r=request(model_test['action'],'POST',dict(model_test['fields'],_csrf=csrf)); body=r.read().decode(); assert r.status==200 and 'Test completed: 1 valid utterance' in body,(r.status,r.geturl(),body)
revise=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-revise') and f['fields'].get('configuration_id')==slot_id)
values=dict(revise['fields'],_csrf=csrf,driver='mock',model='deterministic-mock-v2',mock_prefix='[revised] ',change_reason='HTTP model-slot test')
r=request(revise['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'deterministic-mock-v2' in body,(r.status,r.geturl())
core_list,core_body=parse(request('/ALMSIVIserver/ui/core/core_profiles.php'))
core_edit=re.search(r'core_profiles\.php\?edit=([0-9a-f-]{36})',core_body); assert core_edit,core_body
# Legacy Core controls have separate known label gaps; validate the new relationship labels specifically.
core_body=request('/ALMSIVIserver/ui/core/core_profiles.php?edit='+core_edit.group(1)).read().decode()
core_page=Page(); core_page.feed(core_body)
assert 'aria-labelledby="relationship_configuration_id-label"' in core_body
assert 'aria-label="Relationship Update Chance"' in core_body and 'for="relationship-lock"' in core_body
core_form=next(f for f in core_page.forms if f['action'].endswith('/forms/core-profile-save'))
core_values=dict(core_form['fields'],_csrf=csrf,relationship_configuration_id=slot_id,
    setting_relationship_update_chance_percent='100',setting_relationship_locked='1')
core_response=request(core_form['action'],'POST',core_values); assert core_response.status==200
core_body=core_response.read().decode(); core_page=Page(); core_page.feed(core_body)
core_saved=next(f for f in core_page.forms if f['action'].endswith('/forms/core-profile-save'))
assert core_saved['fields']['relationship_configuration_id']==slot_id and core_saved['fields']['setting_relationship_update_chance_percent']=='100'
assert core_saved['fields']['setting_relationship_locked']=='1',core_saved
core_reset=dict(core_saved['fields'],_csrf=csrf,relationship_configuration_id='',
    setting_relationship_update_chance_percent='',setting_relationship_locked='inherit')
assert request(core_form['action'],'POST',core_reset).status==200
profiles_page,_=parse(request('/ALMSIVIserver/ui/core/npc_master.php'))
routing_form=next(f for f in profiles_page.forms if f['action'].endswith('/forms/profile-create'))
routing_profile_name='HTTP routed profile '+uuid.uuid4().hex
values=dict(routing_form['fields'],_csrf=csrf,installation_id=valid['installation_id'],name=routing_profile_name,
    biography='Exercises CHIM-style model routing.',voice_language='en',llm_configuration_id=slot_id,
    llm_fast_configuration_id=slot_id,llm_powerful_configuration_id=slot_id,llm_experimental_configuration_id=slot_id,
    llm_randomizer_enabled='1',llm_fallback_configuration_id=slot_id,llm_fallback_enabled='1',profile_generation_configuration_id=slot_id,relationship_configuration_id=slot_id,
    setting_relationship_update_chance_percent='100',setting_relationship_locked='1')
r=request(routing_form['action'],'POST',values); body=r.read().decode()
routing_match=re.search(re.escape(routing_profile_name)+r'.*?name="profile_id" value="([0-9a-f-]{36})"',body,re.S); assert routing_match,body
routing_profile_id=routing_match.group(1)
routing_page=Page(); routing_page.feed(body)
saved_routing=next(f for f in routing_page.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==routing_profile_id)
saved_routing_content=json.loads(saved_routing['fields']['base_content_json']); saved_routing_values=saved_routing_content.get('routing',{})
assert saved_routing_values.get('profile_generation_configuration_id')==slot_id and 'Use server runtime' in body
assert saved_routing_values['relationship_configuration_id']==slot_id
assert saved_routing_content['settings_overrides']['relationship']=={'update_chance_percent':100,'locked':True}
locked_build=dict(build_values,profile_id=routing_profile_id,request_id=str(uuid.uuid4()))
r=request(build_form['action'],'POST',locked_build); assert r.status==200 and 'relationship_build_locked' in r.geturl()
assert r.status==200 and saved_routing_values.get('llm_configuration_id')==slot_id and saved_routing_values.get('llm_fallback_configuration_id')==slot_id and saved_routing_values.get('llm_randomizer_enabled') is True and saved_routing_values.get('llm_fallback_enabled') is True,(r.status,r.geturl(),saved_routing)
llm_page,body=parse(request('/ALMSIVIserver/ui/core/llm_connectors.php?selected='+slot_id))
assert '>1 profiles</span>' in body and 'Connector is in use.' in body,body
r=request('/ALMSIVIserver/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':slot_id}); body=r.read().decode()
assert r.status==422 and 'provider_in_use' in body,(r.status,r.geturl(),body)
profiles_page,_=parse(request('/ALMSIVIserver/ui/core/npc_master.php?selected='+routing_profile_id))
clear_routing=next(f for f in profiles_page.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==routing_profile_id)
assert 'profile_generation_configuration_id' in {control[2] for control in profiles_page.controls},'live NPC editor has no generation route'
runtime_route=dict(clear_routing['fields'],_csrf=csrf,profile_generation_configuration_id='__disabled__',relationship_configuration_id='__disabled__',
    setting_relationship_update_chance_percent='0',setting_relationship_locked='0',change_reason='Use runtime generator')
r=request(clear_routing['action'],'POST',runtime_route); assert r.status==200
runtime_content=json.loads(request('/ALMSIVIserver/manage/exports/profiles/'+routing_profile_id+'.json').read().decode())['content']
assert runtime_content['routing']['profile_generation_configuration_id']=='',runtime_content['routing']
assert runtime_content['routing']['relationship_configuration_id']==''
assert runtime_content['settings_overrides']['relationship']=={'update_chance_percent':0,'locked':False}
values=dict(clear_routing['fields'],_csrf=csrf,llm_configuration_id='',llm_fast_configuration_id='',
    llm_powerful_configuration_id='',llm_experimental_configuration_id='',llm_fallback_configuration_id='',profile_generation_configuration_id='',relationship_configuration_id='',setting_relationship_update_chance_percent='',
    setting_relationship_locked='inherit',change_reason='Clear routing')
values.pop('llm_randomizer_enabled',None); values.pop('llm_fallback_enabled',None)
r=request(clear_routing['action'],'POST',values); assert r.status==200
inherited_content=json.loads(request('/ALMSIVIserver/manage/exports/profiles/'+routing_profile_id+'.json').read().decode())['content']
assert 'profile_generation_configuration_id' not in inherited_content.get('routing',{}),inherited_content.get('routing')
assert 'relationship_configuration_id' not in inherited_content.get('routing',{})
assert 'relationship' not in inherited_content.get('settings_overrides',{})
r=request('/ALMSIVIserver/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':routing_profile_id}); assert r.status==200
provider_export_response=request('/ALMSIVIserver/manage/exports/providers/'+slot_id+'.json'); provider_export=json.loads(provider_export_response.read().decode())
assert provider_export_response.status==200 and provider_export['schema']=='almsivi.provider-export.v1' and 'installation_id' not in provider_export and 'endpoint' not in provider_export and 'api_key' not in json.dumps(provider_export).lower()
llm_page,_=parse(request('/ALMSIVIserver/ui/core/llm_connectors.php?selected='+slot_id))
clone_provider=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-clone') and f['fields'].get('configuration_id')==slot_id)
clone_name=slot_name+' clone'; r=request(clone_provider['action'],'POST',dict(clone_provider['fields'],_csrf=csrf,name=clone_name)); body=r.read().decode()
assert r.status==200 and clone_name in body,(r.status,r.geturl(),body)
clone_provider_id=connector_editor_id(body,clone_name)
r=request('/ALMSIVIserver/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':clone_provider_id}); assert r.status==200
provider_export['name']=slot_name+' imported'; llm_page,_=parse(request('/ALMSIVIserver/ui/core/llm_connectors.php?import=1'))
import_provider=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-import'))
r=request(import_provider['action'],'POST',dict(import_provider['fields'],_csrf=csrf,installation_id=valid['installation_id'],provider_json=json.dumps(provider_export))); body=r.read().decode()
assert r.status==200 and provider_export['name'] in body,(r.status,r.geturl(),body)
import_provider_id=connector_editor_id(body,provider_export['name'])
r=request('/ALMSIVIserver/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':import_provider_id}); assert r.status==200
r=request('/ALMSIVIserver/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':slot_id}); assert r.status==200 and r.geturl().endswith('/ui/core/llm_connectors.php?status=saved')
# Exercise the real adapter with a disposable local HTTP provider, never a paid endpoint.
direct_name='HTTP direct '+uuid.uuid4().hex
direct_values={'_csrf':csrf,'installation_id':valid['installation_id'],'name':direct_name,'driver':'openai-compatible','model':'local-test',
    'endpoint':'http://127.0.0.1:'+str(voice_provider.server_port)+'/llm/chat/completions','credential':'none','timeout_ms':'4000',
    'option_temperature':'0','option_top_p':'0','option_max_completion_tokens':'64','option_stream':'false','option_json_mode':'false'}
r=request('/ALMSIVIserver/manage/forms/providers','POST',direct_values); body=r.read().decode(); assert r.status==200 and direct_name in body,(r.status,body)
direct_id=connector_editor_id(body,direct_name)
_,direct_editor=parse(request('/ALMSIVIserver/ui/core/llm_connectors.php?edit='+direct_id))
assert re.search(r'id="llm_option_max_completion_tokens"[^>]*value="64"',direct_editor),direct_editor
direct_test={'_csrf':csrf,'installation_id':valid['installation_id'],'configuration_id':direct_id}
r=request('/ALMSIVIserver/manage/forms/provider-test','POST',direct_test); body=r.read().decode(); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
headers,sent=VoiceProvider.llm_requests[-1]
assert 'Authorization' not in headers and sent['temperature']==0 and sent['top_p']==0 and sent['max_completion_tokens']==64 and sent['stream'] is False and 'response_format' not in sent,(headers,sent)
r=request('/ALMSIVIserver/ui/core/api_keys.php','POST',{'_csrf':csrf,'action':'set','variable':'ALMSIVI_LLM_CUSTOM_API_KEY','credential':'local-parity-test-key'}); body=r.read().decode(); assert 'Credential saved.' in body,body
direct_values.update(configuration_id=direct_id,credential='custom',option_stream='true',option_json_mode='true',option_disable_reasoning='true',change_reason='Exercise explicit key and streaming')
r=request('/ALMSIVIserver/manage/forms/provider-revise','POST',direct_values); assert r.status==200,(r.status,r.read().decode())
r=request('/ALMSIVIserver/manage/forms/provider-test','POST',direct_test); body=r.read().decode(); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
headers,sent=VoiceProvider.llm_requests[-1]
assert headers.get('Authorization')=='Bearer local-parity-test-key' and sent['stream'] is True and sent['response_format']=={'type':'json_object'} and sent['reasoning']=={'exclude':True,'enabled':False},(headers,sent)
direct_export=json.loads(request('/ALMSIVIserver/manage/exports/providers/'+direct_id+'.json').read().decode())
assert direct_export['content']['credential']=='none' and 'local-parity-test-key' not in json.dumps(direct_export),direct_export
direct_export['name']=direct_name+' portable'; direct_export['content']['credential']='custom'
r=request('/ALMSIVIserver/manage/forms/provider-import','POST',{'_csrf':csrf,'installation_id':valid['installation_id'],'provider_json':json.dumps(direct_export)}); body=r.read().decode(); assert r.status==200,(r.status,body)
portable_id=connector_editor_id(body,direct_export['name'])
r=request('/ALMSIVIserver/manage/forms/provider-test','POST',dict(direct_test,configuration_id=portable_id)); body=r.read().decode(); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
assert 'Authorization' not in VoiceProvider.llm_requests[-1][0],VoiceProvider.llm_requests[-1][0]
r=request('/ALMSIVIserver/manage/forms/provider-rollback','POST',dict(direct_test,revision='1')); assert r.status==200,(r.status,r.read().decode())
r=request('/ALMSIVIserver/manage/forms/provider-test','POST',direct_test); body=r.read().decode(); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
headers,sent=VoiceProvider.llm_requests[-1]; assert 'Authorization' not in headers and sent['stream'] is False and 'response_format' not in sent,(headers,sent)
direct_values.update(model='invalid-output',credential='none',option_stream='false',option_json_mode='false',change_reason='Strict output still required')
r=request('/ALMSIVIserver/manage/forms/provider-revise','POST',direct_values); assert r.status==200,(r.status,r.read().decode())
r=request('/ALMSIVIserver/manage/forms/provider-test','POST',direct_test); body=r.read().decode(); assert 'status=tested' not in r.geturl() and 'provider_invalid_output' in body,(r.status,body)
for connector_id in [direct_id,portable_id]:
    r=request('/ALMSIVIserver/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':connector_id}); assert r.status==200
r=request('/ALMSIVIserver/ui/core/api_keys.php','POST',{'_csrf':csrf,'action':'delete','variable':'ALMSIVI_LLM_CUSTOM_API_KEY'}); assert 'Managed credential removed.' in r.read().decode()
prompts,body=parse(request('/ALMSIVIserver/ui/prompts_manager.php'))
prompt_form=next(f for f in prompts.forms if f['action'].endswith('/forms/prompts'))
prompt_name='HTTP prompt '+uuid.uuid4().hex
values=dict(prompt_form['fields'],_csrf=csrf,name=prompt_name,content_json='{"instruction":"Speak like a Morrowind NPC."}')
r=request(prompt_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and prompt_name in body,(r.status,r.geturl())
match=re.search(re.escape(prompt_name)+r'.*?name="configuration_id" value="([0-9a-f-]{36})"',body,re.S); assert match,body
prompt_id=match.group(1)
profiles_for_prompt,_=parse(request('/ALMSIVIserver/ui/core/npc_master.php'))
prompt_profile_form=next(f for f in profiles_for_prompt.forms if f['action'].endswith('/forms/profile-create'))
prompt_profile_name='HTTP prompt profile '+uuid.uuid4().hex
r=request(prompt_profile_form['action'],'POST',dict(prompt_profile_form['fields'],_csrf=csrf,installation_id=valid['installation_id'],name=prompt_profile_name,biography='Profile used to verify explicit prompt routing.',voice_language='en')); body=r.read().decode()
prompt_profile_match=re.search(re.escape(prompt_profile_name)+r'.*?name="profile_id" value="([0-9a-f-]{36})"',body,re.S); assert prompt_profile_match,body
prompt_profile_id=prompt_profile_match.group(1)
characters,_=parse(request('/ALMSIVIserver/ui/core/character_manager.php'))
profile_prompt=next(f for f in characters.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==prompt_profile_id)
values=dict(profile_prompt['fields'],_csrf=csrf,prompt_configuration_id=prompt_id,change_reason='Assign explicit dialogue prompt')
r=request(profile_prompt['action'],'POST',values); body=r.read().decode(); assert r.status==200 and prompt_name in body,(r.status,r.geturl(),body)
prompts,body=parse(request('/ALMSIVIserver/ui/prompts_manager.php')); assert '1 explicit profile assignments' in body and 'Assigned prompts cannot be deleted.' in body,body
r=request('/ALMSIVIserver/manage/forms/configuration-delete','POST',{'_csrf':csrf,'configuration_id':prompt_id,'kind':'prompt'}); body=r.read().decode(); assert r.status==422 and 'prompt_in_use' in body,(r.status,r.geturl(),body)
page,body=parse(request('/ALMSIVIserver/ui/prompts_manager.php')); revise=next(f for f in page.forms if f['action'].endswith('/forms/configuration-revise') and f['fields'].get('configuration_id')==prompt_id)
values=dict(revise['fields'],_csrf=csrf,kind='prompt',content_json='{"instruction":"Speak briefly in character."}',change_reason='HTTP prompt test')
r=request(revise['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'Speak briefly in character.' in body
prompt_export_response=request('/ALMSIVIserver/manage/exports/prompts/'+prompt_id+'.json'); prompt_export=json.loads(prompt_export_response.read().decode())
assert prompt_export_response.status==200 and prompt_export['schema']=='almsivi.prompt-export.v1' and 'installation_id' not in prompt_export and 'api_key' not in json.dumps(prompt_export).lower()
prompts,_=parse(request('/ALMSIVIserver/ui/prompts_manager.php'))
clone_prompt=next(f for f in prompts.forms if f['action'].endswith('/forms/prompt-clone') and f['fields'].get('configuration_id')==prompt_id)
clone_name=prompt_name+' clone'; r=request(clone_prompt['action'],'POST',dict(clone_prompt['fields'],_csrf=csrf,name=clone_name)); body=r.read().decode()
assert r.status==200 and clone_name in body,(r.status,r.geturl(),body)
clone_match=re.search(re.escape(clone_name)+r'.*?name="configuration_id" value="([0-9a-f-]{36})"',body,re.S); assert clone_match,body
r=request('/ALMSIVIserver/manage/forms/configuration-delete','POST',{'_csrf':csrf,'configuration_id':clone_match.group(1),'kind':'prompt'}); assert r.status==200
prompt_export['name']=prompt_name+' imported'; prompts,_=parse(request('/ALMSIVIserver/ui/prompts_manager.php'))
import_prompt=next(f for f in prompts.forms if f['action'].endswith('/forms/prompt-import'))
r=request(import_prompt['action'],'POST',dict(import_prompt['fields'],_csrf=csrf,installation_id=valid['installation_id'],prompt_json=json.dumps(prompt_export))); body=r.read().decode()
assert r.status==200 and prompt_export['name'] in body,(r.status,r.geturl(),body)
import_match=re.search(re.escape(prompt_export['name'])+r'.*?name="configuration_id" value="([0-9a-f-]{36})"',body,re.S); assert import_match,body
r=request('/ALMSIVIserver/manage/forms/configuration-delete','POST',{'_csrf':csrf,'configuration_id':import_match.group(1),'kind':'prompt'}); assert r.status==200
characters,_=parse(request('/ALMSIVIserver/ui/core/character_manager.php'))
profile_prompt=next(f for f in characters.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==prompt_profile_id)
r=request(profile_prompt['action'],'POST',dict(profile_prompt['fields'],_csrf=csrf,prompt_configuration_id='',change_reason='Remove explicit dialogue prompt')); assert r.status==200
r=request('/ALMSIVIserver/manage/forms/configuration-delete','POST',{'_csrf':csrf,'configuration_id':prompt_id,'kind':'prompt'}); assert r.status==200
r=request('/ALMSIVIserver/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':prompt_profile_id}); assert r.status==200
actions,body=parse(request('/ALMSIVIserver/ui/function_editor.php'))
assert 'negotiated OpenMW action catalogue immutable' in body and 'Action Policies' in body
policy_form=next(f for f in actions.forms if f['action'].endswith('/forms/action-policy-controls-create'))
policy_name='HTTP action policy '+uuid.uuid4().hex
values=dict(policy_form['fields'],_csrf=csrf,name=policy_name,enabled='1',max_tier='1')
values['allowed_actions[]']=['inspect.report','ai.follow']
r=request(policy_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/function_editor.php?status=saved') and policy_name in body,(r.status,r.geturl())
match=re.search(re.escape(policy_name)+r'.*?name="configuration_id" value="([0-9a-f-]{36})"',body,re.S); assert match,body
policy_id=match.group(1); actions,body=parse(request('/ALMSIVIserver/ui/function_editor.php'))
revise_policy=next(f for f in actions.forms if f['action'].endswith('/forms/action-policy-controls-revise') and f['fields'].get('configuration_id')==policy_id)
values=dict(revise_policy['fields'],_csrf=csrf,enabled='1',max_tier='0',change_reason='HTTP labelled action edit'); values['allowed_actions[]']=['inspect.report']
r=request(revise_policy['action'],'POST',values); body=r.read().decode(); revised_actions=Page(); revised_actions.feed(body)
saved_policy=next(f for f in revised_actions.forms if f['action'].endswith('/forms/action-policy-controls-revise') and f['fields'].get('configuration_id')==policy_id)
assert r.status==200 and saved_policy['fields'].get('max_tier')=='0' and saved_policy['fields'].get('allowed_actions[]')=='inspect.report',(r.status,r.geturl(),saved_policy)
r=request('/ALMSIVIserver/manage/forms/configuration-delete','POST',{'_csrf':csrf,'configuration_id':policy_id,'kind':'action_policy'}); assert r.status==200
player,text=parse(request('/ALMSIVIserver/ui/core/player_management.php'))
assert 'profile_generation_configuration_id' in {control[2] for control in player.controls},'live player editor has no generation route'
create_player=next((f for f in player.forms if f['action'].endswith('/forms/player-profile-create')),None)
if create_player is not None:
    player_name='HTTP player '+uuid.uuid4().hex
    values=dict(create_player['fields'],_csrf=csrf,name=player_name,biography='Arrived in Morrowind by prison ship.',personality='Curious',goals='Find Fargoth.')
    r=request(create_player['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/player_management.php?status=saved'),(r.status,r.geturl(),body)
    match=re.search(r'name="profile_id" value="([0-9a-f-]{36})"',body); assert match,body
    assert 'observed player messages' in body
    player_page,_=parse(request('/ALMSIVIserver/ui/core/player_management.php'))
    generate_style=next((f for f in player_page.forms if f['action'].endswith('/forms/player-speech-style-generate')),None)
    if generate_style is not None:
        r=request(generate_style['action'],'POST',dict(generate_style['fields'],_csrf=csrf)); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/player_management.php?status=saved'),(r.status,r.geturl(),body)
    player_id=match.group(1)
    edit_page,body=parse(request('/ALMSIVIserver/ui/core/player_management.php'))
    revise=next(f for f in edit_page.forms if f['action'].endswith('/forms/player-profile-revise'))
    values=dict(revise['fields'],_csrf=csrf,profile_id=player_id,biography='Arrived in Morrowind by prison ship.',personality='Patient',goals='Find Fargoth.',change_reason='HTTP parity test')
    r=request(revise['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'Player profile saved.' in body and 'Patient' in body,(r.status,r.geturl())
    r=request('/ALMSIVIserver/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':player_id}); assert r.status==200
else:
    assert any(f['action'].endswith('/forms/player-profile-revise') for f in player.forms),'existing player profile is not editable'
narrator_page,body=parse(request('/ALMSIVIserver/ui/narrator_management.php'))
assert 'profile_generation_configuration_id' in {control[2] for control in narrator_page.controls},'live narrator editor has no generation route'
create_narrator=next((f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-profile-create')),None)
if create_narrator is not None:
    narrator_name='HTTP narrator '+uuid.uuid4().hex
    values=dict(create_narrator['fields'],_csrf=csrf,name=narrator_name,enabled='1',inline_narration_mode='Narrator',biography='Frames the Nerevarine journey.',personality='Observant',speech_style='Concise sensory prose.',goals='Describe scenes.',notes='HTTP parity test')
    r=request(create_narrator['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/config_hub.php?tab=narration-page&status=saved'),(r.status,r.geturl(),body)
    narrator_page,body=parse(request('/ALMSIVIserver/ui/narrator_management.php'))
    assert narrator_name in body and 'Generate narrator profile with AI' in body and 'preserving narrator enablement and voice routing' in body,body
narrator_page,body=parse(request('/ALMSIVIserver/ui/narrator_management.php'))
generate_narrator=next((f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-profile-generate')),None)
assert generate_narrator is not None and generate_narrator['fields'].get('profile_id'),'narrator profile generation control is missing'
r=request(generate_narrator['action'],'POST',dict(generate_narrator['fields'],_csrf=csrf)); assert r.status==200 and r.geturl().endswith('/ui/core/config_hub.php?tab=narration-page&status=saved'),(r.status,r.geturl())
r=request('/ALMSIVIserver/manage/login'); assert r.status==200 and r.geturl().endswith('/ui/home.php')
print('browser-like management HTTP forms passed')
