#!/usr/bin/env python3
import atexit, csv, html.parser, http.cookiejar, http.server, io, json, pathlib, re, subprocess, sys, threading, urllib.error, urllib.parse, urllib.request, uuid, zipfile

base=sys.argv[1].rstrip('/')
provider_host=sys.argv[2] if len(sys.argv)>2 else '127.0.0.1'
jar=http.cookiejar.CookieJar()
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

class VoiceProvider(http.server.BaseHTTPRequestHandler):
    uploads=[]
    llm_requests=[]
    embedding_requests=[]
    speech_requests=[]
    transcription_requests=[]
    transcription_auth=[]
    transcription_text='Ash drifts across the quiet road. A traveler stops at the inn, warms by the fire, and asks the keeper for a room until morning.'
    samples=b'\x00'*160
    silence=(b'RIFF'+(36+len(samples)).to_bytes(4,'little')+b'WAVEfmt '+(16).to_bytes(4,'little')+(1).to_bytes(2,'little')+(1).to_bytes(2,'little')
             +(16000).to_bytes(4,'little')+(32000).to_bytes(4,'little')+(2).to_bytes(2,'little')+(16).to_bytes(2,'little')+b'data'+len(samples).to_bytes(4,'little')+samples)
    def do_GET(self):
        if self.path.startswith('/speakers_list'):
            payload=json.dumps({'speakers':['MockProviderVoice']}).encode()
            self.send_response(200); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(payload))); self.end_headers(); self.wfile.write(payload); return
        self.send_error(404)
    def do_POST(self):
        if self.path=='/stt-test':
            self.transcription_requests.append(self.rfile.read(int(self.headers.get('Content-Length','0'))))
            self.transcription_auth.append(self.headers.get('Authorization',''))
            payload=json.dumps({'text':self.transcription_text}).encode()
            self.send_response(200); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(payload))); self.end_headers(); self.wfile.write(payload); return
        if self.path=='/embed':
            body=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0')))); self.embedding_requests.append(body)
            payload=json.dumps({'embedding':[1.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0]}).encode()
            self.send_response(200); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(payload))); self.end_headers(); self.wfile.write(payload); return
        if self.path=='/llm/chat/completions':
            body=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0')))); self.llm_requests.append((dict(self.headers),body))
            content=json.dumps({'utterances':[{'text':'Greetings, traveller.'}],'action':None} if body['model']!='invalid-output' else {'unexpected':'not dialogue'})
            if body.get('stream'):
                payload=('data: '+json.dumps({'choices':[{'delta':{'content':content}}]})+'\n\ndata: [DONE]\n\n').encode(); content_type='text/event-stream'
            else:
                payload=json.dumps({'choices':[{'message':{'content':content}}]}).encode(); content_type='application/json'
            self.send_response(200); self.send_header('Content-Type',content_type); self.send_header('Content-Length',str(len(payload))); self.end_headers(); self.wfile.write(payload); return
        if self.path=='/tts_to_audio':
            body=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0')))); self.speech_requests.append(body)
            self.send_response(200); self.send_header('Content-Type','audio/wav'); self.send_header('Content-Length',str(len(self.silence))); self.end_headers(); self.wfile.write(self.silence); return
        if self.path!='/upload_sample': self.send_error(404); return
        body=self.rfile.read(int(self.headers.get('Content-Length','0'))); self.uploads.append((dict(self.headers),body))
        payload=b'{"status":"ok"}'
        self.send_response(200); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(payload))); self.end_headers(); self.wfile.write(payload)
    def log_message(self,format,*args): pass

voice_provider=http.server.ThreadingHTTPServer(('0.0.0.0',0),VoiceProvider)
threading.Thread(target=voice_provider.serve_forever,daemon=True).start()
atexit.register(voice_provider.server_close)
atexit.register(voice_provider.shutdown)
repository_root=pathlib.Path(__file__).resolve().parents[2]
embedding_probe=subprocess.run(['php','-r',
    "require $argv[1].'/lib/Autoload.php'; $provider=new LorkhanServer\\Application\\MiniMeEmbeddingProvider($argv[2],1250); echo json_encode($provider->embed('Vivec remembers Red Mountain.',new LorkhanServer\\Application\\NeverCancelledToken()));",
    str(repository_root),'http://127.0.0.1:'+str(voice_provider.server_port)],capture_output=True,text=True,timeout=5)
assert embedding_probe.returncode==0 and json.loads(embedding_probe.stdout)==[1,0,0,0,0,0,0,0] and VoiceProvider.embedding_requests==[{'text':'Vivec remembers Red Mountain.'}],(embedding_probe.returncode,embedding_probe.stdout,embedding_probe.stderr,VoiceProvider.embedding_requests)
VoiceProvider.embedding_requests.clear()

class Page(html.parser.HTMLParser):
    def __init__(self):
        super().__init__(); self.labels=set(); self.controls=[]; self.nav=[]; self.current=0; self.forms=[]; self.form=None; self.select_name=None; self.label_depth=0
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='label':
            self.label_depth+=1
            if a.get('for'): self.labels.add(a['for'])
        if tag in ('input','textarea','select') and a.get('name') and a.get('type') not in ('hidden','checkbox'): self.controls.append((tag,a.get('id'),a.get('name'),self.label_depth>0 or bool(a.get('aria-label'))))
        if tag=='a' and a.get('href','').startswith('/LorkhanServer/ui/'):
            self.nav.append(a['href'])
            if 'dropdown-item' in a.get('class','').split(): self.current+=a.get('aria-current')=='page'
        if tag=='form': self.form={'action':a.get('action',''),'method':a.get('method','get'),'fields':{}}; self.forms.append(self.form)
        if self.form is not None and tag=='input' and a.get('name') and 'disabled' not in a and (a.get('type')!='checkbox' or 'checked' in a): self.form['fields'][a['name']]=a.get('value','')
        if self.form is not None and tag=='input' and a.get('type')=='checkbox' and a.get('name') and 'checked' in a:
            self.form.setdefault('checked',{}).setdefault(a['name'],[]).append(a.get('value',''))
        if self.form is not None and tag=='select' and a.get('name') and 'disabled' not in a: self.select_name=a['name']
        if self.form is not None and tag=='option' and self.select_name and (self.select_name not in self.form['fields'] or 'selected' in a):
            self.form['fields'][self.select_name]=a.get('value','')
    def handle_endtag(self,tag):
        if tag=='label': self.label_depth=max(0,self.label_depth-1)
        if tag=='select': self.select_name=None
        if tag=='form': self.form=None

def request(path,method='GET',data=None,follow=True,accept=None):
    body=None if data is None else urllib.parse.urlencode(data,doseq=True).encode()
    headers={'Content-Type':'application/x-www-form-urlencoded'} if body else {}
    if accept: headers['Accept']=accept
    req=urllib.request.Request(base+path,data=body,method=method,headers=headers)
    try: return opener.open(req,timeout=5)
    except urllib.error.HTTPError as e: return e

def json_request(path,method='GET',data=None,csrf_token=None):
    body=None if data is None else json.dumps(data).encode()
    headers={'Accept':'application/json'}
    if body is not None: headers['Content-Type']='application/json'
    if csrf_token is not None: headers['X-CSRF-Token']=csrf_token
    req=urllib.request.Request(base+path,data=body,method=method,headers=headers)
    try: return opener.open(req,timeout=5)
    except urllib.error.HTTPError as e: return e

def multipart_request(path,fields,file_field,filename,content_type,payload):
    boundary='----lorkhan-'+uuid.uuid4().hex; body=bytearray()
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

# Moving the front controller to the server root must not expose internal files.
for path in ['/LorkhanServer/conf/server.example.php', '/LorkhanServer/lib/Autoload.php',
             '/LorkhanServer/data/migrations/001_initial.up.sql', '/LorkhanServer/deploy/runtime-files.txt',
             '/LorkhanServer/composer.json', '/LorkhanServer/tests/run.php']:
    r=request(path)
    assert r.status in (403,404), (path,r.status)

r=request('/LorkhanServer/manage/quickstart'); assert r.status==200 and r.geturl().endswith('/ui/home.php')
p,text=parse(r); assert len(p.nav)>=4 and p.current==1 and 'Total Events' in text and 'Queued Jobs' not in text
assert 'class="chim-navbar-wrapper"' in text and '/LorkhanServer/ui/lib/ui/bootstrap/bootstrap.min.css' in text
assert '<details' not in text and 'Recent Dialogue' in text and 'Getting Started' not in text
assert re.search(r'<article class="widget">\s*<div class="widget-header"><h3>LORKHAN Stats</h3>', text)
assert all('/ui/images/'+asset in text for asset in ['youtube.png','discord.png','patreon.png'])
assert 'Management secret' not in text and '/logout' not in text
csrf=next(c.value for c in jar if c.name=='lorkhan_csrf')
for path,marker,title in [
    ('/LorkhanServer/ui/home.php','dashboard-container','Home'),
    ('/LorkhanServer/ui/events-memories.php','events-memories-navigation','Roleplay'),
    ('/LorkhanServer/ui/core/config_hub.php','config-navigation','Configuration'),
    ('/LorkhanServer/ui/control_panel.php','config-navigation','Control Panel'),
]:
    page,text=parse(request(path)); assert page.current==1,path; assert marker in text,path; assert '<title>'+title+'</title>' in text,path
    if path != '/LorkhanServer/ui/home.php': assert '<body class="hub-page">' in text,path
events,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=eventlog'))
assert events.current==1 and 'id="eventlog-app"' in text and 'data-eventlog-live' in text and 'Delete Latest 5' in text and 'Delete ALL' in text and 'People Present' in text and 'Tamrielic Time' in text and 'data-eventlog-delete-row' not in text and all(removed not in text for removed in ['Soulgaze','AI Quest Manager','Active Quests','Background Life','data-tab="questgen"','data-tab="backgroundlife"'])
for removed_tab in ['backgroundlife','questgen','quests','soulgaze']:
    removed_page,removed_text=parse(request('/LorkhanServer/ui/events-memories.php?tab='+removed_tab))
    assert removed_page.current==1 and 'id="eventlog-app"' in removed_text and 'id="journal-tab" class="tab-content active"' not in removed_text,removed_tab
journal,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=journal-tab')); assert journal.current==1 and 'Morrowind Journal' in text and 'id="journal-tab" class="tab-content active"' in text and 'events-memories.php?tab=journal' in text and 'events-memories.php?tab=quests' not in text and 'events-memories.php?tab=relationships' not in text and '>Morrowind</div>' not in text
books,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=books-tab')); assert books.current==1 and 'class="books-table"' in text and 'id="books-tab" class="tab-content active"' in text
responses,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=responses-tab')); assert responses.current==1 and 'class="ai-response-table"' in text and 'Oghma Topic' in text and 'HTTP Request' in text and 'data-reader-play' not in text
memories,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=memories-tab')); assert memories.current==1 and '>Memories</h2>' in text and 'id="memory-tab" class="tab-content active"' in text and 'Add or rebuild memories' in text
embedding_policy=next(f for f in memories.forms if f['action'].endswith('/forms/memory-embedding-policy'))
assert embedding_policy['fields'].get('timeout_ms')=='1500' and embedding_policy['fields'].get('endpoint')=='' and 'enabled' not in embedding_policy['fields'],embedding_policy
embedding_values=dict(embedding_policy['fields'],_csrf=csrf,enabled='1',endpoint='http://'+provider_host+':'+str(voice_provider.server_port),timeout_ms='1250')
r=request(embedding_policy['action'],'POST',embedding_values); body=r.read().decode()
assert r.status==200 and 'status=embedding-saved' in r.geturl() and 'Use MiniMe semantic retrieval' in body and 'value="1250"' in body and ' checked' in body,(r.status,r.geturl(),body)
memories,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=memory')); embedding_backfill=next(f for f in memories.forms if f['action'].endswith('/forms/memory-embedding-backfill'))
r=request(embedding_backfill['action'],'POST',dict(embedding_backfill['fields'],_csrf=csrf,limit='100')); body=r.read().decode()
assert r.status==200 and 'status=embedding-backfill-empty' in r.geturl() and 'No memories needed embedding' in body and VoiceProvider.embedding_requests==[],(r.status,r.geturl(),body,VoiceProvider.embedding_requests)
relationships,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=relationships-tab')); assert relationships.current==1 and '<strong>Morrowind Journal:</strong>' in text and '<th scope="col">Journal ID</th>' in text and 'id="journal-tab" class="tab-content active"' in text and 'Add relationship' not in text
narratives_tab,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=narratives-tab')); assert narratives_tab.current==1 and '>Adventure Log</h1>' in text and 'id="adventure-tab" class="tab-content active"' in text and 'Regular Calendar' in text and 'calendar-event-table' in text and 'Create / Generate Entry' not in text
diaries,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=diaries')); assert 'Diary Log</h1>' in text and 'Filter by Person' in text and 'calendar-event-table' in text
narratives_page,text=parse(request('/LorkhanServer/ui/narrative_manager.php')); assert narratives_page.current==1 and '<h1 class="lorkhan-page-head-title">Narratives</h1>' in text and 'Create narrative' in text
cache,text=parse(request('/LorkhanServer/ui/cache_browser.php'))
assert cache.current==1 and '<h1 class="cache-title">Audio Cache</h1>' in text and 'class="cache-panel"' in text and 'No cached audio files found.' in text
assert cache.forms[0]['fields']['state']=='available' and cache.forms[0]['fields']['period']=='all' and 'Expired and deleted entries' in text
assert '<table' not in text and '/soundcache/' not in text and 'Soulgaze' not in text and 'data-audio-cache' in text
cache_all,text=parse(request('/LorkhanServer/ui/cache_browser.php?state=&period=all')); assert cache_all.forms[0]['fields']['state']==''
cache_audio='/LorkhanServer/ui/cache_audio.php?media_id=00000000-0000-4000-8000-000000000099&installation_id='+cache.forms[0]['fields']['installation_id']
assert request(cache_audio).status==404 and request(cache_audio,'HEAD').status==404 and request(cache_audio,'POST').status==405
try:
    urllib.request.urlopen(base+cache_audio,timeout=5)
    raise AssertionError('Audio was accessible without browser authentication')
except urllib.error.HTTPError as error:
    assert error.code==401,error.code
queue,text=parse(request('/LorkhanServer/ui/response_queue.php')); assert queue.current==1 and '>Response Queue</h1>' in text and 'actual playback state' in text and 'data-response-queue' in text
queue_remove='/LorkhanServer/manage/api/v1/response-queue/remove'
queue_values={'installation_id':queue.forms[0]['fields']['installation_id'],'rowid':1,'confirm':'Remove'}
r=json_request(queue_remove,'GET',None,csrf); assert r.status in (404,405)
r=json_request(queue_remove,'POST',queue_values); assert r.status==401
r=json_request(queue_remove,'POST',dict(queue_values,confirm=''),csrf); assert r.status==422
r=json_request(queue_remove,'POST',dict(queue_values,rowid='1'),csrf); assert r.status==422
r=json_request(queue_remove,'POST',dict(queue_values,installation_id=str(uuid.uuid4())),csrf); assert r.status==404
oghma,text=parse(request('/LorkhanServer/ui/oghma_audit.php')); assert oghma.current==1 and '<h1>Oghma Audit</h1>' in text and 'retrieval traces' in text
assert all(marker in text for marker in ['Only Matched','Filter current page','Showing 0-0 of 0 rows','More filters','data-oghma-audit']) and 'runtime-metrics' not in text
oghma_invalid,text=parse(request('/LorkhanServer/ui/oghma_audit.php?installation_id=not-a-uuid&page_size=999'))
assert oghma_invalid.current==1 and oghma_invalid.forms[0]['fields']['installation_id']=='' and oghma_invalid.forms[0]['fields']['page_size']=='50'
oghma_matched,text=parse(request('/LorkhanServer/ui/oghma_audit.php?matched=1&page_size=25'))
assert '(matched only)' in text and oghma_matched.forms[0]['fields']['matched']=='matched' and oghma_matched.forms[0]['fields']['page_size']=='25'
assert all('value="'+status+'"' in text for status in ['grounded','no_match','fallback_succeeded','fallback_unresolved','fallback_failed','fallback_disabled','fallback_unconfigured','disabled','ineligible','unavailable','not_run','legacy']),text
usage,text=parse(request('/LorkhanServer/ui/provider_usage.php')); assert usage.current==1 and '<h1>Cost Breakdown</h1>' in text and 'Missing pricing is shown as unknown' in text
server_logs,text=parse(request('/LorkhanServer/ui/server_logs.php'))
assert server_logs.current==1 and '<h1>Server Logs</h1>' in text and 'bounded to 256 KiB and redacted' in text
assert text.count('class="log-section"')==3 and all(label in text for label in ['Download Logs','Timezone: UTC','Filter by Level:','Search expanded log','data-expand-log'])
assert '/var/log/' not in text and 'chim.log' not in text
database,text=parse(request('/LorkhanServer/ui/database_manager.php')); assert database.current==1 and '<h1 class="lorkhan-page-head-title">Database Manager</h1>' in text and 'schema migrations' in text and 'Installation Configuration Backups' in text
studio,text=parse(request('/LorkhanServer/ui/core/voice_library.php')); assert studio.current==1 and 'Add WAV voice samples' in text and 'flat ZIP batch' in text and 'Voice Library' in text and 'Configured TTS Connectors' in text and 'Provider Voice Browser' in text and 'never contacts a provider automatically' in text
fallback_page,fallback_html=parse(request('/LorkhanServer/ui/core/voice_library.php?tab=fallbacks'))
fallback_form=next(f for f in fallback_page.forms if f['fields'].get('action')=='fallback_save')
fallback_fields=fallback_form['fields']
assert 'fallback-connector' not in fallback_html and 'configuration_id' not in fallback_fields
assert sum(k.startswith('fallbacks[') for k in fallback_fields)==20 and fallback_fields['fallbacks[dark_elf][male]']=='mw_dark_elf_male'
saved_fallback_fields=dict(fallback_fields)
changed_fallback_fields=dict(fallback_fields,_csrf=csrf)
changed_fallback_fields['fallbacks[dark_elf][male]']='global_dunmer_test'
changed_fallback_fields['fallbacks[argonian][female]']=''
r=request('/LorkhanServer/ui/core/voice_library.php','POST',changed_fallback_fields); body=r.read().decode()
assert r.status==200 and 'Global fallback voices saved for every TTS connector.' in body,(r.status,body)
_,reloaded_fallbacks=parse(request('/LorkhanServer/ui/core/voice_library.php?tab=fallbacks&configuration_id='+str(uuid.uuid4())))
assert 'value="global_dunmer_test"' in reloaded_fallbacks and 'fallback-connector' not in reloaded_fallbacks
r=request('/LorkhanServer/ui/core/voice_library.php','POST',dict(changed_fallback_fields,_csrf='invalid')); body=r.read().decode()
assert 'unauthorized' in body,body
r=request('/LorkhanServer/ui/core/voice_library.php','POST',dict(saved_fallback_fields,_csrf=csrf)); body=r.read().decode()
assert 'Global fallback voices saved for every TTS connector.' in body and 'value="mw_dark_elf_male"' in body,body
batch_voice='HTTPBatch'+uuid.uuid4().hex
wav=VoiceProvider.silence
archive=io.BytesIO()
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as bundle: bundle.writestr(batch_voice+'.wav',wav)
r=multipart_request('/LorkhanServer/ui/core/voice_library.php',{'_csrf':csrf,'action':'upload','voice_name':''},'voice_sample','voices.zip','application/zip',archive.getvalue()); body=r.read().decode()
assert r.status==200 and '1 voice samples imported.' in body and batch_voice in body,(r.status,r.geturl(),body)
bad_archive=io.BytesIO()
with zipfile.ZipFile(bad_archive,'w',zipfile.ZIP_DEFLATED) as bundle: bundle.writestr('../Escape.wav',wav)
r=multipart_request('/LorkhanServer/ui/core/voice_library.php',{'_csrf':csrf,'action':'upload','voice_name':''},'voice_sample','unsafe.zip','application/zip',bad_archive.getvalue()); body=r.read().decode()
assert r.status==200 and 'invalid_voice_archive' in body and 'Escape' not in body,(r.status,r.geturl(),body)
tts_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?create=1')); create_sync_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/tts-providers'))
sync_tts_name='HTTP voice sync '+uuid.uuid4().hex
sync_values=dict(create_sync_tts['fields'],_csrf=csrf,installation_id=create_sync_tts['fields']['installation_id'],name=sync_tts_name,driver='xtts-fastapi',endpoint='http://'+provider_host+':'+str(voice_provider.server_port),model='default',voice='default',language='en',timeout_ms='30000',options_json='{}')
r=request(create_sync_tts['action'],'POST',sync_values); body=r.read().decode(); assert r.status==200 and sync_tts_name in body,(r.status,r.geturl(),body)
sync_tts_id=connector_editor_id(body,sync_tts_name)
r=request('/LorkhanServer/ui/core/voice_library.php','POST',{'_csrf':csrf,'action':'sync','voice_name':batch_voice,'configuration_id':sync_tts_id,'language':'en'}); body=r.read().decode()
assert r.status==200 and 'Voice sample synced to '+sync_tts_name+'.' in body,(r.status,r.geturl(),body)
assert len(VoiceProvider.uploads)==1 and b'name="wavFile"' in VoiceProvider.uploads[0][1] and b'name="force"' in VoiceProvider.uploads[0][1] and b'\r\n\r\ntrue\r\n' in VoiceProvider.uploads[0][1],VoiceProvider.uploads
r=request('/LorkhanServer/ui/core/voice_library.php','POST',{'_csrf':csrf,'action':'discover','configuration_id':sync_tts_id,'language':'en'}); body=r.read().decode()
assert r.status==200 and '1 provider voices discovered.' in body and 'MockProviderVoice' in body,(r.status,r.geturl(),body)
profiles_with_provider_voice=request('/LorkhanServer/ui/core/npc_master.php').read().decode()
assert 'MockProviderVoice' in profiles_with_provider_voice and sync_tts_name in profiles_with_provider_voice,profiles_with_provider_voice
pron,text=parse(request('/LorkhanServer/ui/core/voice_library.php?tab=pronunciations'))
assert pron.current==1 and 'id="pron-preview"' in text and 'data-pron-endpoint="/LorkhanServer/manage/api/v1/tts-previews"' in text
assert 'Written vs Spoken Preview' not in text and 'Preview is unavailable' not in text and 'id="pron-preview-audio"' in text
assert text.count('data-pron-play="1"')>=4 and 'data-pron-input="pron-add-source"' in text and 'data-pron-input="pron-add-spoken"' in text
assert '>Play Original</span>' in text and '>Play Spoken version</span>' in text and '<option value="'+batch_voice+'"' in text
# Opening the connector test only renders the shared, scoped voice catalog; it never synthesizes.
connector_test_page=request('/LorkhanServer/ui/core/tts_connectors.php?edit='+sync_tts_id).read().decode()
assert 'id="tts-test-open"' in connector_test_page and 'id="tts-test-dialog"' in connector_test_page and 'id="tts-test-audio"' in connector_test_page,connector_test_page
assert 'data-endpoint="/LorkhanServer/manage/api/v1/tts-previews"' in connector_test_page and 'value="'+batch_voice+'"' in connector_test_page,connector_test_page
assert 'action="/LorkhanServer/manage/forms/connector-test"' not in connector_test_page and not VoiceProvider.speech_requests,VoiceProvider.speech_requests
option_ids=re.findall(r'id="(tts-option-[^"]+)"',connector_test_page)
assert len(option_ids)==len(set(option_ids)),option_ids
tts_installation=create_sync_tts['fields']['installation_id']
def preview(payload,token=csrf): return json_request('/LorkhanServer/manage/api/v1/tts-previews','POST',payload,token)
r=preview({'installation_id':tts_installation,'configuration_id':sync_tts_id,'voice':batch_voice,'text':'Vvardenfell'}); clip=r.read()
assert r.status==200 and r.headers.get('Content-Type')=='audio/wav' and clip.startswith(b'RIFF'),(r.status,r.headers.get('Content-Type'),clip[:160])
assert VoiceProvider.speech_requests==[{'text':'Vvardenfell','speaker_wav':batch_voice,'language':'en'}],VoiceProvider.speech_requests
assert len(VoiceProvider.uploads)==2 and b'name="wavFile"' in VoiceProvider.uploads[1][1],VoiceProvider.uploads
r=preview({'installation_id':tts_installation,'configuration_id':sync_tts_id,'voice':'NotInstalled','text':'Vvardenfell'}); body=r.read().decode()
assert r.status==422 and json.loads(body)=={'error':'invalid_tts_preview_voice'},(r.status,body)
r=preview({'installation_id':tts_installation,'configuration_id':sync_tts_id,'voice':batch_voice,'text':'V'*241}); body=r.read().decode()
assert r.status==422 and json.loads(body)=={'error':'invalid_tts_preview_text'},(r.status,body)
r=preview({'installation_id':tts_installation,'configuration_id':sync_tts_id,'voice':batch_voice,'text':'Vvardenfell'},None)
assert r.status==401 and len(VoiceProvider.speech_requests)==1,(r.status,VoiceProvider.speech_requests)
for _ in range(27):
    r=preview({'installation_id':tts_installation,'configuration_id':sync_tts_id,'voice':batch_voice,'text':'Vvardenfell'}); clip=r.read()
    assert r.status==200 and clip.startswith(b'RIFF'),(r.status,clip[:160])
r=preview({'installation_id':tts_installation,'configuration_id':sync_tts_id,'voice':batch_voice,'text':'Vvardenfell'}); body=r.read().decode()
assert r.status==429 and json.loads(body)=={'error':'tts_preview_rate_limited'} and len(VoiceProvider.speech_requests)==28,(r.status,body,len(VoiceProvider.speech_requests))
r=request('/LorkhanServer/manage/forms/connector-delete','POST',{'_csrf':csrf,'configuration_id':sync_tts_id,'kind':'tts_provider'}); assert r.status==200,(r.status,r.geturl())
assert 'MockProviderVoice' not in request('/LorkhanServer/ui/core/npc_master.php').read().decode()
keys,text=parse(request('/LorkhanServer/ui/core/api_keys.php')); assert keys.current==1 and 'API Keys</h1>' in text and 'LORKHAN_LLM_API_KEY' in text and 'type="password"' in text
deepl_key_input=re.search(r'<input id="credential-deepl"[^>]*>',text); assert deepl_key_input,text
deepl_key_input=deepl_key_input.group(0); assert 'name="credentials[LORKHAN_DEEPL_API_KEY]"' in deepl_key_input and 'disabled' not in deepl_key_input and 'value=' not in deepl_key_input,deepl_key_input
player,text=parse(request('/LorkhanServer/ui/core/player_management.php')); assert player.current==1 and 'Player Management</h1>' in text and any(f['action'].endswith(('/forms/player-profile-create','/forms/player-profile-revise')) for f in player.forms) and 'Profile generation uses the connector selected in' in text,text
narrator,text=parse(request('/LorkhanServer/ui/narrator_management.php')); assert narrator.current==1 and 'Narrator Management</h1>' in text and 'Configure narrator behavior and settings' in text and 'Profile generation uses the connector selected in' in text
globals_page,text=parse(request('/LorkhanServer/ui/core/global_settings.php')); assert globals_page.current==1 and 'Global Settings</h1>' in text and 'name="rechat_mode"' in text and 'name="rechat_allow_actions" value="1"' in text and 'name="relationship_enabled" value="1"' in text and 'name="context_section_conversation_history" value="1"' in text and 'name="context_location_blacklist"' in text and 'name="profile_generation_configuration_id"' in text and 'name="autofill_custom_profiles" value="1" checked' in text and 'name="autofill_custom_profiles_trigger" value="40"' in text and 'name="boredom"' in text and 'name="auto_greeting"' in text and 'name="combat_barks"' in text and 'feature-state-excluded' not in text and 'feature-state-replaced' not in text
assert 'class="page-header-actions"' in text and '&#128229; Import Settings' in text and 'class="gs-portability"' not in text and 'aria-controls="settings-panel-prompt-rechat"' in text and 'id="settings-panel-prompt-rechat"' in text
global_settings_form=next(f for f in globals_page.forms if f['action'].endswith('/forms/global-settings-save'))
global_settings_import=next(f for f in globals_page.forms if f['action'].endswith('/forms/global-settings-import'))
assert 'data-json-import-target="gs-preset-json"' in text and 'replaces every value in the typed document' in text and global_settings_import['fields'].get('installation_id')==global_settings_form['fields'].get('installation_id')
assert global_settings_form['fields'].get('oghma_enabled')=='1' and 'oghma_extractor_enabled' not in global_settings_form['fields'] and global_settings_form['fields'].get('oghma_topic_count')=='1' and global_settings_form['fields'].get('oghma_result_limit')=='3' and global_settings_form['fields'].get('oghma_extractor_timeout_ms')=='1500',global_settings_form['fields']
assert '/forms/autonomy' not in text and 'New Schedule' not in text
excluded_autonomy=request('/LorkhanServer/manage/forms/autonomy','POST',{'_csrf':csrf}); assert excluded_autonomy.status==404,excluded_autonomy.status
biographies,text=parse(request('/LorkhanServer/ui/core/npc_biographies.php'))
assert biographies.current==1 and '<h1>NPC Biography Management</h1>' in text,text
assert any(f['action'].endswith('/forms/biography-template-revise') for f in biographies.forms),'factory biography templates are not editable'
biography_import=next(f for f in biographies.forms if f['action'].endswith('/forms/biography-import'))
biography_installation=biography_import['fields']['installation_id']
biography_header=['content_file','record_id','name','core','biography','appearance','personality','relationships','occupation','skills','speech_style','goals','oghma_tags','voice_id','gender','race']
def biography_csv(rows):
    stream=io.StringIO(newline=''); writer=csv.writer(stream,lineterminator='\n'); writer.writerow(biography_header); writer.writerows(rows)
    return stream.getvalue().encode()
example=request('/LorkhanServer/manage/exports/biographies/example.csv'); example_body=example.read().decode('utf-8-sig')
assert example.status==200 and next(csv.reader(io.StringIO(example_body)))==biography_header,example_body
biography_suffix=uuid.uuid4().hex; biography_record='http_biography_'+biography_suffix; biography_name='HTTP Biography '+biography_suffix
atomic_record='http_atomic_'+biography_suffix; invalid_record='http_invalid_'+biography_suffix
atomic_rows=[
    ['HTTP Test.esp',atomic_record,'HTTP Atomic '+biography_suffix,'Atomic core','Must not persist','','','{}','','','','','','','Female','Dark Elf'],
    ['HTTP Test.esp',invalid_record,'HTTP Invalid '+biography_suffix,'Invalid core','Rejected','','','[]','','','','','','','Male','Wood Elf'],
]
r=multipart_request(biography_import['action'],{'_csrf':csrf,'installation_id':biography_installation,'embed':'0'},'csv_file','biographies.csv','text/csv',biography_csv(atomic_rows)); body=r.read().decode()
assert r.status==422 and 'invalid_biography_relationships' in body,(r.status,r.geturl(),body)
exported=request('/LorkhanServer/manage/exports/biographies/custom.csv?installation_id='+biography_installation).read().decode('utf-8-sig')
assert atomic_record not in exported and invalid_record not in exported,exported
biography_row=['HTTP Test.esp',biography_record,biography_name,'A careful OpenMW guide.','Imported biography v1.','Travel-worn clothes.','Patient and observant.','{"Player":{"aff":25}}','Guide','Local geography.','Direct and calm.','Help travellers.','Balmora, common','', 'Female','Dark Elf']
r=multipart_request(biography_import['action'],{'_csrf':csrf,'installation_id':biography_installation,'embed':'0'},'csv_file','biographies.csv','text/csv',biography_csv([biography_row])); body=r.read().decode()
assert r.status==200 and 'status=imported' in r.geturl() and '1 biography template imported.' in body,(r.status,r.geturl(),body)
biography_row[4]='Imported biography v2.'
r=multipart_request(biography_import['action'],{'_csrf':csrf,'installation_id':biography_installation,'embed':'0'},'csv_file','biographies.csv','text/csv',biography_csv([biography_row])); body=r.read().decode()
assert r.status==200 and '1 biography template imported.' in body,(r.status,r.geturl(),body)
exported=request('/LorkhanServer/manage/exports/biographies/custom.csv?installation_id='+biography_installation).read().decode('utf-8-sig')
export_rows=[row for row in csv.DictReader(io.StringIO(exported)) if row['record_id']==biography_record]
assert len(export_rows)==1 and export_rows[0]['content_file']=='HTTP Test.esp' and export_rows[0]['biography']=='Imported biography v2.' and export_rows[0]['oghma_tags']=='Balmora',export_rows
descriptions,text=parse(request('/LorkhanServer/ui/description_manager.php')); assert descriptions.current==1 and '<h1>Description Manager</h1>' in text and 'Descriptions Database' in text
oghma_response=request('/LorkhanServer/ui/worldknowledge_upload.php'); text=oghma_response.read().decode(); assert oghma_response.status==200 and 'Oghma Infinium' in text and 'Dynamic Oghma' not in text
assert request('/LorkhanServer/ui/server_plugins.php').status==404
assert request('/LorkhanServer/manage/server-plugins').status==404
assert request('/LorkhanServer/ui/itt_connectors.php').status==404
assert request('/LorkhanServer/ui/soulgaze_gallery.php').status==404
llm,text=parse(request('/LorkhanServer/ui/core/llm_connectors.php')); assert llm.current==1 and 'LLM Connectors</h1>' in text and 'Server runtime' in text and all('api_key' not in f['fields'] for f in llm.forms)
llm_runtime,runtime_text=parse(request('/LorkhanServer/ui/core/llm_connectors.php?selected=runtime')); assert llm_runtime.current==1 and 'LORKHAN_LLM_API_KEY' in runtime_text and all('api_key' not in f['fields'] for f in llm_runtime.forms)
for import_path in ['/LorkhanServer/ui/core/npc_master.php','/LorkhanServer/ui/core/llm_connectors.php?import=1','/LorkhanServer/ui/core/tts_connectors.php?import=1','/LorkhanServer/ui/prompts_manager.php']:
    _,import_text=parse(request(import_path)); assert 'type="file" accept="application/json,.json" data-json-import-target=' in import_text and 'Choose a JSON file or paste its contents here.' in import_text,import_path
hub_text=request('/LorkhanServer/ui/core/config_hub.php').read().decode(); assert all('data-tab="'+tab+'"' in hub_text for tab in ['npc','profiles','player','narrator','npcbio','llm','ttscfg','xtts','sttcfg','keys','globals','oghma','items','actions','prompts']) and 'data-tab="serverplugins"' not in hub_text and 'Server Plugins' not in hub_text and 'data-tab="ittcfg"' not in hub_text and 'ITT</span>' not in hub_text and 'Narration' in hub_text and 'autonomy-page' not in hub_text and '/ui/css/herika-navbar-layout.css' in hub_text
pages_css=request('/LorkhanServer/ui/css/lorkhan-pages.css').read().decode()
navbar_css=request('/LorkhanServer/ui/css/navbar.css').read().decode()
navbar_layout_css=request('/LorkhanServer/ui/css/herika-navbar-layout.css').read().decode()
home_css=request('/LorkhanServer/ui/css/herika-home.css').read().decode()
resource_css=request('/LorkhanServer/ui/css/herika-resource.css').read().decode()
assert '.dashboard-container {' in home_css and 'grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));' in home_css
assert '.llm-layout {' in resource_css and '.page-header {' in resource_css and '.conn-list {' in resource_css
assert '.npc-grid { display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:14px; }' in pages_css
assert '.chim-navbar-wrapper {' in navbar_css and 'max-width: 1200px;' in navbar_css
assert '.navbar-content-wrapper {' in navbar_layout_css and 'justify-content: center;' in navbar_layout_css and 'max-width: 1000px;' in navbar_layout_css
for path in [
    '/LorkhanServer/manage/quickstart', '/LorkhanServer/manage/roleplay',
    '/LorkhanServer/manage/configuration', '/LorkhanServer/manage/control-panel',
    '/LorkhanServer/manage/characters', '/LorkhanServer/manage/profiles',
    '/LorkhanServer/manage/player',
    '/LorkhanServer/manage/npc-biographies',
    '/LorkhanServer/manage/descriptions',
    '/LorkhanServer/manage/providers', '/LorkhanServer/manage/ai-voice',
    '/LorkhanServer/manage/action-editor',
    '/LorkhanServer/manage/prompts-actions', '/LorkhanServer/manage/world',
    '/LorkhanServer/manage/traces', '/LorkhanServer/manage/memory',
    '/LorkhanServer/manage/relationships', '/LorkhanServer/manage/knowledge',
    '/LorkhanServer/manage/playthroughs', '/LorkhanServer/manage/narrative-autonomy',
    '/LorkhanServer/manage/jobs', '/LorkhanServer/manage/response-queue', '/LorkhanServer/manage/oghma-audit',
    '/LorkhanServer/manage/provider-usage', '/LorkhanServer/manage/cache', '/LorkhanServer/manage/backup-health',
    '/LorkhanServer/manage/database-manager', '/LorkhanServer/manage/server-logs', '/LorkhanServer/manage/diagnostics',
]:
    response=request(path); assert response.status==200 and '/ui/' in response.geturl(),(path,response.geturl())
profile,profile_text=parse(request('/LorkhanServer/ui/core/npc_master.php'))
assert 'data-npc-editor-tab="background-life"' not in profile_text and 'data-npc-editor-panel="background-life"' not in profile_text
profile_labels=['Voice sample','Core Profile','Profile LLMs','Prompt head (advanced system guidance)','Backstory','Gender','Race','Skills','Emote Moods Override','Lock against automatic AI profile generation','Favorite NPC','Auto Diary','Auto Diary Wait','Visit','Teleport']
missing_profile_labels=[label for label in profile_labels if label not in profile_text]
assert not missing_profile_labels,missing_profile_labels
assert not any('name="'+field+'"' in profile_text for field in ['llm_configuration_id','llm_fast_configuration_id','llm_powerful_configuration_id','llm_experimental_configuration_id','llm_fallback_configuration_id','llm_randomizer_enabled','llm_fallback_enabled','tts_configuration_id'])
characters,_=parse(request('/LorkhanServer/ui/core/character_manager.php'))
auto_lock=next(f for f in characters.forms if f['action'].endswith('/forms/profile-auto-lock'))
if auto_lock['fields'].get('enabled')!='1':
    r=request(auto_lock['action'],'POST',dict(auto_lock['fields'],_csrf=csrf,enabled='1')); assert r.status==200
    characters,_=parse(request('/LorkhanServer/ui/core/character_manager.php')); auto_lock=next(f for f in characters.forms if f['action'].endswith('/forms/profile-auto-lock'))
assert auto_lock['fields'].get('enabled')=='1','auto-lock preference did not default on'
disabled=dict(auto_lock['fields'],_csrf=csrf); disabled.pop('enabled',None)
r=request(auto_lock['action'],'POST',disabled); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved'),(r.status,r.geturl())
form=next(f for f in profile.forms if f['action'].endswith('/forms/profile-create'))
invalid=dict(form['fields'],_csrf=csrf,installation_id='invalid',name='Test',voice_language='en')
r=request(form['action'],'POST',invalid); _,text=parse(r); assert r.status==422 and 'role="alert"' in text
profile_name='HTTP managed profile '+uuid.uuid4().hex
valid=dict(form['fields'],_csrf=csrf,name=profile_name,record_id='http_managed_'+uuid.uuid4().hex,content_file='Morrowind.esm',refnum_index='62010',refnum_content_file='0',voice_id=batch_voice,voice_language='en',gender='Female',race='Dunmer',prompt_head='Stay grounded in TES3 lore.',core='A cautious Balmora guide.',biography='Created through the labelled management form.',personality='Preserved personality field.',skills='Local geography and alchemy.',emote_moods='calm, wary',setting_behavior_rechat='1',setting_behavior_rechat_max_depth='4',setting_behavior_auto_greeting='1',setting_behavior_boredom='1',setting_behavior_combat_barks='1',setting_behavior_rechat_delay_seconds='999',setting_presentation_show_status_hud='0')
valid['installation_id']=auto_lock['fields']['installation_id']
valid['favorite']='1'
r=request(form['action'],'POST',valid); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved'),(r.status,r.geturl(),body); assert 'NPC profile change saved.' in body
profile_match=re.search(re.escape(profile_name)+r'.*?name="profile_id" value="([0-9a-f-]{36})"',body,re.S); assert profile_match,profile_name
profile_id=profile_match.group(1)
profile_export=json.loads(request('/LorkhanServer/manage/exports/profiles/'+profile_id+'.json').read().decode())
assert 'settings_overrides' not in profile_export['content'] and 'routing' not in profile_export['content'],profile_export['content']
r=request('/LorkhanServer/ui/core/voice_library.php','POST',{'_csrf':csrf,'action':'delete','voice_name':batch_voice}); body=r.read().decode()
assert r.status==200 and 'voice_sample_in_use' in body and 'Profile: '+profile_name in body and batch_voice in body,(r.status,r.geturl(),body)
managed_for_clone,_=parse(request('/LorkhanServer/ui/core/character_manager.php'))
clone_form=next(f for f in managed_for_clone.forms if f['action'].endswith('/forms/profile-clone') and f['fields'].get('profile_id')==profile_id)
clone_name=profile_name+' clone'
r=request(clone_form['action'],'POST',dict(clone_form['fields'],_csrf=csrf,name=clone_name)); body=r.read().decode()
assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved') and clone_name in body,(r.status,r.geturl(),body)
clone_id=selected_record_id(body,clone_name); clone_export=json.loads(request('/LorkhanServer/manage/exports/profiles/'+clone_id+'.json').read().decode())
assert clone_export['name']==clone_name and clone_export['content']['biography']==valid['biography'] and clone_export['content']['core']==valid['core'] and clone_export['content']['skills']==valid['skills'] and clone_export['content']['gender']=='Female' and clone_export['content']['race']=='Dunmer' and 'portrait' not in clone_export['content'],clone_export
r=request('/LorkhanServer/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':clone_id}); assert r.status==200
tts_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?create=1'))
create_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/tts-providers'))
assert create_tts['fields'].get('option_fields_present')=='1' and 'option__speed' in create_tts['fields'] and 'option__temperature' not in create_tts['fields'],create_tts
tts_name='HTTP TTS '+uuid.uuid4().hex
values=dict(create_tts['fields'],_csrf=csrf,installation_id=valid['installation_id'],name=tts_name,driver='pockettts',endpoint='http://127.0.0.1:8021',model='default',voice='default',language='en',timeout_ms='30000',fallback_male='TestMale',fallback_female='TestFemale',option__speed='1.1',option__temperature='0.6',options_json='{}')
r=request(create_tts['action'],'POST',values); body=r.read().decode(); assert r.status==200 and tts_name in body,(r.status,r.geturl(),body)
tts_id=connector_editor_id(body,tts_name)
tts_export_response=request('/LorkhanServer/manage/exports/connectors/'+tts_id+'.json'); tts_export=json.loads(tts_export_response.read().decode())
assert tts_export['content']['options']['speed']==1.1 and tts_export['content']['options']['temperature']==0.6,tts_export
tts_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?selected='+tts_id))
revise_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/connector-revise') and f['fields'].get('configuration_id')==tts_id)
assert 'option__speed' in revise_tts['fields'] and 'option__temperature' in revise_tts['fields'] and revise_tts['fields'].get('option_fields_present')=='1',revise_tts
values=dict(revise_tts['fields'],_csrf=csrf,option__speed='1.25',option__temperature='0.7',change_reason='HTTP labelled TTS options')
r=request(revise_tts['action'],'POST',values); body=r.read().decode(); assert r.status==200 and tts_name in body,(r.status,r.geturl(),body)
tts_export_response=request('/LorkhanServer/manage/exports/connectors/'+tts_id+'.json'); tts_export=json.loads(tts_export_response.read().decode())
assert tts_export_response.status==200 and tts_export['schema']=='lorkhan.connector-export.v1' and tts_export['kind']=='tts_provider' and 'installation_id' not in tts_export and 'api_key' not in json.dumps(tts_export).lower()
assert tts_export['content']['options']['fallback_male']=='TestMale' and tts_export['content']['options']['fallback_female']=='TestFemale',tts_export
assert tts_export['content']['options']['speed']==1.25 and tts_export['content']['options']['temperature']==0.7,tts_export
tts_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?selected='+tts_id))
revise_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/connector-revise') and f['fields'].get('configuration_id')==tts_id)
values=dict(revise_tts['fields'],_csrf=csrf,driver='omnivoice',option__speed='1.0',change_reason='HTTP connector driver switch')
r=request(revise_tts['action'],'POST',values); assert r.status==200,(r.status,r.geturl(),r.read().decode())
tts_export=json.loads(request('/LorkhanServer/manage/exports/connectors/'+tts_id+'.json').read().decode())
assert tts_export['content']['driver']=='omnivoice' and tts_export['content']['options']['speed']==1.0 and 'temperature' not in tts_export['content']['options'],tts_export
tts_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?selected='+tts_id))
clone_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/connector-clone') and f['fields'].get('configuration_id')==tts_id)
clone_name=tts_name+' clone'; r=request(clone_tts['action'],'POST',dict(clone_tts['fields'],_csrf=csrf,name=clone_name)); body=r.read().decode()
assert r.status==200 and clone_name in body,(r.status,r.geturl(),body)
clone_tts_id=connector_editor_id(body,clone_name)
r=request('/LorkhanServer/manage/forms/connector-delete','POST',{'_csrf':csrf,'configuration_id':clone_tts_id,'kind':'tts_provider'}); assert r.status==200
tts_export['name']=tts_name+' imported'; tts_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?import=1'))
import_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/connector-import'))
r=request(import_tts['action'],'POST',dict(import_tts['fields'],_csrf=csrf,installation_id=valid['installation_id'],kind='tts_provider',connector_json=json.dumps(tts_export))); body=r.read().decode()
assert r.status==200 and tts_export['name'] in body,(r.status,r.geturl(),body)
import_tts_id=connector_editor_id(body,tts_export['name'])
r=request('/LorkhanServer/manage/forms/connector-delete','POST',{'_csrf':csrf,'configuration_id':import_tts_id,'kind':'tts_provider'}); assert r.status==200
tts_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?selected='+tts_id)); activate_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/connector-selection') and f['fields'].get('configuration_id')==tts_id)
r=request(activate_tts['action'],'POST',dict(activate_tts['fields'],_csrf=csrf)); body=r.read().decode(); assert r.status==200,(r.status,r.geturl(),body)
body=request('/LorkhanServer/ui/core/tts_connectors.php?selected='+tts_id).read().decode(); assert 'This active connector cannot be deleted.' in body,body
provider_voice='ProviderVoice'+uuid.uuid4().hex
r=request('/LorkhanServer/manage/forms/connector-default-voice','POST',{'_csrf':csrf,'configuration_id':tts_id,'voice_id':provider_voice,'language':'en'}); body=r.read().decode()
saved_voice_url=urllib.parse.urlparse(r.geturl()); saved_voice_query=urllib.parse.parse_qs(saved_voice_url.query)
assert r.status==200 and saved_voice_url.path.endswith('/ui/core/voice_library.php') and saved_voice_query.get('status')==['saved'] and saved_voice_query.get('configuration_id')==[tts_id] and provider_voice in body,(r.status,r.geturl(),body)
updated_tts=json.loads(request('/LorkhanServer/manage/exports/connectors/'+tts_id+'.json').read().decode()); assert updated_tts['content']['voice']==provider_voice and updated_tts['content']['language']=='en',updated_tts
r=request('/LorkhanServer/manage/forms/connector-delete','POST',{'_csrf':csrf,'configuration_id':tts_id,'kind':'tts_provider'}); body=r.read().decode(); assert r.status==422 and 'connector_in_use' in body,(r.status,r.geturl(),body)
managed_profile,_=parse(request('/LorkhanServer/ui/core/character_manager.php'))
generate=next(f for f in managed_profile.forms if f['action'].endswith('/forms/profile-generate') and f['fields'].get('profile_id')==profile_id)
r=request(generate['action'],'POST',dict(generate['fields'],_csrf=csrf)); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved'),(r.status,r.geturl())
managed_profile,body=parse(request('/LorkhanServer/ui/core/character_manager.php'))
revise=next(f for f in managed_profile.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==profile_id)
auto_lock=next(f for f in managed_profile.forms if f['action'].endswith('/forms/profile-auto-lock'))
r=request(auto_lock['action'],'POST',dict(auto_lock['fields'],_csrf=csrf,enabled='1')); assert r.status==200
values=dict(revise['fields'],_csrf=csrf,favorite='1',voice_id='',voice_language='en',change_reason='HTTP auto-lock and favorite test'); values.pop('locked',None)
r=request(revise['action'],'POST',values); body=r.read().decode(); locked_export=json.loads(request('/LorkhanServer/manage/exports/profiles/'+profile_id+'.json').read().decode())
assert r.status==200 and profile_name in body and 'data-lock-id="'+profile_id+'"' in body and locked_export['content']['management']=={'locked':True,'favorite':True},(r.status,r.geturl(),locked_export)
r=request('/LorkhanServer/ui/core/voice_library.php','POST',{'_csrf':csrf,'action':'delete','voice_name':batch_voice}); body=r.read().decode()
assert r.status==200 and 'Local voice sample deleted.' in body and batch_voice not in body,(r.status,r.geturl(),body)
filtered=request('/LorkhanServer/ui/core/character_manager.php?state=favorites&q='+urllib.parse.quote(profile_name)); filtered_body=filtered.read().decode()
assert filtered.status==200 and profile_name in filtered_body and 'name="state" value="favorites"' in filtered_body and 'data-favorite-id="'+profile_id+'"' in filtered_body,(filtered.status,filtered.geturl())
r=request('/LorkhanServer/manage/forms/profile-generate','POST',{'_csrf':csrf,'profile_id':profile_id}); body=r.read().decode(); assert r.status==422 and 'profile_locked' in body,(r.status,r.geturl(),body)
portrait_png=bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360f8cff00000040101000db24bc40000000049454e44ae426082')
r=multipart_request('/LorkhanServer/ui/core/profile_portrait.php',{'_csrf':csrf,'profile_id':profile_id,'action':'upload'},'portrait','portrait.png','image/png',portrait_png)
body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved') and 'Delete portrait' in body,(r.status,r.geturl(),body)
portrait_response=request('/LorkhanServer/ui/core/profile_portrait.php?profile_id='+profile_id); portrait_body=portrait_response.read()
assert portrait_response.status==200 and portrait_response.headers.get_content_type()=='image/png' and portrait_body==portrait_png,(portrait_response.status,portrait_response.headers.get_content_type(),portrait_response.headers.get('X-LORKHAN-Portrait-Status'),len(portrait_body),portrait_body[:24].hex())
export_response=request('/LorkhanServer/manage/exports/profiles/'+profile_id+'.json'); exported=json.loads(export_response.read().decode())
assert export_response.status==200 and exported['schema']=='lorkhan.profile-export.v1' and exported['name']==profile_name and 'installation_id' not in exported and 'portrait' not in exported['content'] and exported['content']['management']=={'locked':True,'favorite':True}
r=request('/LorkhanServer/ui/core/profile_portrait.php','POST',{'_csrf':csrf,'profile_id':profile_id,'action':'delete'}); body=r.read().decode(); deleted_portrait=request('/LorkhanServer/ui/core/profile_portrait.php?profile_id='+profile_id)
assert r.status==200 and deleted_portrait.status==404,(r.status,r.geturl(),deleted_portrait.status)
imported_name=profile_name+' imported'; exported['name']=imported_name
profiles,_=parse(request('/LorkhanServer/ui/core/npc_master.php'))
import_form=next(f for f in profiles.forms if f['action'].endswith('/forms/profile-import'))
values=dict(import_form['fields'],_csrf=csrf,profile_json=json.dumps(exported))
r=request(import_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved') and imported_name in body,(r.status,r.geturl())
imported_id=selected_record_id(body,imported_name)
r=request('/LorkhanServer/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':imported_id}); assert r.status==200
characters,body=parse(request('/LorkhanServer/ui/core/character_manager.php'))
unlock=next(f for f in characters.forms if f['action'].endswith('/forms/profile-bulk-unlock'))
r=request(unlock['action'],'POST',dict(unlock['fields'],_csrf=csrf,confirm='wrong')); body=r.read().decode(); assert r.status==422 and 'confirmation_mismatch' in body
r=request(unlock['action'],'POST',dict(unlock['fields'],_csrf=csrf,confirm='Unlock')); body=r.read().decode(); unlocked_export=json.loads(request('/LorkhanServer/manage/exports/profiles/'+profile_id+'.json').read().decode())
assert r.status==200 and profile_name in body and unlocked_export['content']['management']['locked'] is False,(r.status,r.geturl(),unlocked_export)
characters,_=parse(request('/LorkhanServer/ui/core/character_manager.php'))
revise=next(f for f in characters.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==profile_id)
r=request(revise['action'],'POST',dict(revise['fields'],_csrf=csrf,favorite='1',change_reason='Restore lock after bulk unlock test')); assert r.status==200
characters,_=parse(request('/LorkhanServer/ui/core/character_manager.php')); auto_lock=next(f for f in characters.forms if f['action'].endswith('/forms/profile-auto-lock'))
disabled=dict(auto_lock['fields'],_csrf=csrf); disabled.pop('enabled',None); r=request(auto_lock['action'],'POST',disabled); assert r.status==200
extra_name='HTTP bulk delete '+uuid.uuid4().hex
extra=dict(form['fields'],_csrf=csrf,name=extra_name,voice_language='en',biography='Disposable unlocked bulk profile.')
extra['installation_id']=valid['installation_id']
r=request(form['action'],'POST',extra); body=r.read().decode(); assert r.status==200 and extra_name in body,(r.status,r.geturl(),body)
characters,_=parse(request('/LorkhanServer/ui/core/character_manager.php'))
bulk_generate=next(f for f in characters.forms if f['action'].endswith('/forms/profile-bulk-generate'))
r=request(bulk_generate['action'],'POST',dict(bulk_generate['fields'],_csrf=csrf,confirm='wrong')); body=r.read().decode(); assert r.status==422 and 'confirmation_mismatch' in body
r=request(bulk_generate['action'],'POST',dict(bulk_generate['fields'],_csrf=csrf,confirm='Generate')); assert r.status==200
characters,_=parse(request('/LorkhanServer/ui/core/character_manager.php'))
delete_all=next(f for f in characters.forms if f['action'].endswith('/forms/profile-bulk-delete'))
r=request(delete_all['action'],'POST',dict(delete_all['fields'],_csrf=csrf,confirm='Delete')); body=r.read().decode()
bulk_preserved=json.loads(request('/LorkhanServer/manage/exports/profiles/'+profile_id+'.json').read().decode())
assert r.status==200 and extra_name not in body and profile_name in body and bulk_preserved['content']['management']['locked'] is True,(r.status,r.geturl(),bulk_preserved)
playthroughs,_=parse(request('/LorkhanServer/ui/playthrough_manager.php'))
create_playthrough=next(f for f in playthroughs.forms if f['action'].endswith('/forms/playthroughs'))
playthrough_name='HTTP playthrough '+uuid.uuid4().hex
values=dict(create_playthrough['fields'],_csrf=csrf,profile_id=profile_id,name=playthrough_name,content_json='{}')
r=request(create_playthrough['action'],'POST',values); body=r.read().decode(); assert r.status==200 and playthrough_name in body and all(label in body for label in ['Sessions','Turns','Responses','Memories','Relationships','Narratives','Knowledge']),(r.status,r.geturl(),body)
match=re.search(r'<h2>'+re.escape(playthrough_name)+r'</h2>.*?/exports/playthroughs/([0-9a-f-]{36})\.json',body,re.S); assert match,body
playthrough_id=match.group(1)
state_query=urllib.parse.urlencode(dict(embed='1',q=profile_name,profile='',state='favorites',initial='H',fav='1',lock='1',installation_id=valid['installation_id']))
characters,state_body=parse(request('/LorkhanServer/ui/core/npc_master.php?'+state_query))
core_match=re.search(r'name="core_profile_id" form="management-form-profile-'+re.escape(profile_id)+r'"[^>]*>.*?<option value="([0-9a-f-]{36})" selected',state_body,re.S)
assert core_match,state_body
state_query=urllib.parse.urlencode(dict(embed='1',q=profile_name,profile=core_match.group(1),state='favorites',initial='H',fav='1',lock='1',installation_id=valid['installation_id']))
characters,state_body=parse(request('/LorkhanServer/ui/core/npc_master.php?'+state_query))
assert '&#128220; History' in state_body and 'data-npc-history-view' in state_body and 'data-npc-history-recipients' in state_body
assert all(('name="ui_'+field+'"' in state_body) for field in ['embed','q','profile','state','initial','fav','lock','installation_id']),state_body
revise=next(f for f in characters.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==profile_id)
state_values=dict(revise['fields'],_csrf=csrf,favorite='1',change_reason='HTTP list-state continuity',ui_page='2')
r=request(revise['action'],'POST',state_values); state_url=urllib.parse.urlparse(r.geturl()); state_params=urllib.parse.parse_qs(state_url.query)
assert r.status==200 and state_url.path.endswith('/ui/core/npc_master.php') and state_params.get('status')==['saved']
assert state_params.get('embed')==['1'] and state_params.get('q')==[profile_name] and state_params.get('profile')==[core_match.group(1)]
assert state_params.get('state')==['favorites'] and state_params.get('initial')==['H'] and state_params.get('fav')==['1'] and state_params.get('lock')==['1']
assert state_params.get('installation_id')==[valid['installation_id']] and state_params.get('page')==['2'],state_params

recipient_name='HTTP history recipient '+uuid.uuid4().hex
recipient_values=dict(form['fields'],_csrf=csrf,installation_id=valid['installation_id'],name=recipient_name,
    record_id='http_history_recipient_'+uuid.uuid4().hex,content_file='Morrowind.esm',refnum_index='62011',refnum_content_file='0',
    voice_language='en',biography='Known recipient for the NPC history test.')
r=request(form['action'],'POST',recipient_values); recipient_body=r.read().decode()
assert r.status==200 and recipient_name in recipient_body,(r.status,r.geturl(),recipient_body)
recipient_profile_id=selected_record_id(recipient_body,recipient_name)
provider_calls_before_history=len(VoiceProvider.llm_requests)
history_path='/LorkhanServer/manage/api/v1/profiles/'+profile_id+'/eventlog'
history_query='?'+urllib.parse.urlencode(dict(playthrough_id=playthrough_id,limit='100'))
r=json_request(history_path+history_query); history=json.loads(r.read().decode())['data']
assert r.status==200 and history['events']==[] and history['recipient_profiles'],history
recipient_id=recipient_profile_id
assert recipient_id in [recipient['profile_id'] for recipient in history['recipient_profiles']],history
history_event='Met a known companion near Balmora. <&> 古'
payload={'playthrough_id':playthrough_id,'event':history_event,'recipient_profile_ids':[recipient_id]}
r=json_request(history_path,'POST',payload); assert r.status==401,(r.status,r.read().decode())
r=json_request(history_path,'POST',payload,csrf); injected=json.loads(r.read().decode())['data']; history_row_id=injected['rowid']
assert r.status==201 and history_row_id>0 and recipient_id in [recipient['profile_id'] for recipient in injected['recipients']],injected
r=json_request(history_path+history_query); history=json.loads(r.read().decode())['data']
assert r.status==200 and [event['rowid'] for event in history['events']]==[history_row_id]
assert history['events'][0]['data']=='('+history_event+')' and history['events'][0]['deletable'] is True and 'inputtext' in history['event_types'],history
delete_path=history_path+'/'+str(history_row_id)
r=json_request(delete_path,'DELETE',{'playthrough_id':playthrough_id}); assert r.status==401,(r.status,r.read().decode())
r=json_request(delete_path,'DELETE',{'playthrough_id':playthrough_id},csrf); assert r.status==200,(r.status,r.read().decode())
r=json_request(history_path+history_query); history=json.loads(r.read().decode())['data']
assert r.status==200 and history['events']==[] and len(VoiceProvider.llm_requests)==provider_calls_before_history,history
r=request('/LorkhanServer/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':recipient_profile_id}); assert r.status==200

provider_calls_before_conversion=len(VoiceProvider.llm_requests)
conversion_query=urllib.parse.urlencode(dict(embed='1',q=profile_name,initial='H',fav='1',lock='1',installation_id=valid['installation_id']))
characters,conversion_body=parse(request('/LorkhanServer/ui/core/npc_master.php?'+conversion_query))
conversion_form=next(f for f in characters.forms if f['action'].endswith('/forms/relationship-text-convert'))
assert 'data-npc-modal-target="npc-relationships-modal"' in conversion_body and 'id="npc-generate-modal"' in conversion_body
assert 'Bulk relationship text conversion is planned' not in conversion_body and 'never reads Custom Info' in conversion_body
assert conversion_form['fields']['playthrough_id']==playthrough_id and conversion_form['fields']['mode']=='missing' and uuid.UUID(conversion_form['fields']['request_id'])
assert len(VoiceProvider.llm_requests)==provider_calls_before_conversion # Rendering never calls the Relationship LLM.
conversion_values=dict(conversion_form['fields'],_csrf=csrf,confirm='Build',ui_page='2')
r=request(conversion_form['action'],'POST',dict(conversion_values,confirm='wrong')); assert r.status==422 and 'confirmation_mismatch' in r.read().decode()
r=request(conversion_form['action'],'POST',dict(conversion_values,_csrf='wrong')); assert r.status==200 and r.geturl().endswith('/ui/home.php')
r=request(conversion_form['action'],'POST',dict(conversion_values,request_id=str(uuid.uuid4()),playthrough_id=str(uuid.uuid4()))); assert r.status==422
r=request(conversion_form['action'],'POST',dict(conversion_values,request_id=str(uuid.uuid4()),mode='transitive')); assert r.status==422
r=request(conversion_form['action'],'POST',conversion_values); conversion_result,conversion_body=parse(r)
conversion_url=urllib.parse.urlparse(r.geturl()); conversion_params=urllib.parse.parse_qs(conversion_url.query)
assert r.status==200 and conversion_url.path.endswith('/ui/core/npc_master.php') and conversion_params.get('embed')==['1']
assert conversion_params.get('q')==[profile_name] and conversion_params.get('initial')==['H'] and conversion_params.get('fav')==['1'] and conversion_params.get('lock')==['1'] and conversion_params.get('page')==['2']
assert conversion_params.get('installation_id')==[valid['installation_id']] and conversion_params.get('status',[None])[0] in ('relationship_conversion_requested','relationship_conversion_no_eligible')
assert ('role="status"' in conversion_body or 'role="alert"' in conversion_body) and len(VoiceProvider.llm_requests)==provider_calls_before_conversion
narratives,narrative_body=parse(request('/LorkhanServer/ui/narrative_manager.php'))
generate_diary=next(f for f in narratives.forms if f['action'].endswith('/forms/narrative-generate'))
assert all(field in generate_diary['fields'] for field in ['installation_id','profile_id','playthrough_id'])
assert 'Request a diary' in narrative_body and 'Automatic timer, sleep, and optional wait diaries' in narrative_body and 'Diary generation queued.' not in narrative_body
provider_calls_before_diary=len(VoiceProvider.llm_requests)
r=request(generate_diary['action'],'POST',dict(generate_diary['fields'],_csrf=csrf,installation_id=valid['installation_id'],profile_id=profile_id,playthrough_id=playthrough_id)); diary_error=r.read().decode()
assert r.status==422 and 'diary_generation_disabled' in diary_error and len(VoiceProvider.llm_requests)==provider_calls_before_diary,(r.status,diary_error)
create_narrative=next(f for f in narratives.forms if f['action'].endswith('/forms/narratives'))
narrative_title='HTTP diary '+uuid.uuid4().hex; narrative_text='Arrived in Seyda Neen.'
values=dict(create_narrative['fields'],_csrf=csrf,installation_id=valid['installation_id'],profile_id=profile_id,playthrough_id=playthrough_id,kind='diary',title=narrative_title,content=narrative_text,provenance='management-http')
r=request(create_narrative['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/narrative_manager.php?status=saved') and narrative_title in body,(r.status,r.geturl(),body)
narrative_match=re.search(re.escape(narrative_title)+r'.*?name="narrative_id" value="([0-9a-f-]{36})"',body,re.S); assert narrative_match,body
narrative_id=narrative_match.group(1); narratives,_=parse(request('/LorkhanServer/ui/narrative_manager.php'))
# A real diary must appear in the calendar and escaped modal, and stay playthrough-scoped.
diary_url='/LorkhanServer/ui/events-memories.php?'+urllib.parse.urlencode({'tab':'diaries','installation_id':valid['installation_id'],'playthrough_id':playthrough_id})
diary_page,diary_html=parse(request(diary_url))
assert narrative_text in diary_html and 'id="entry-'+narrative_id+'"' in diary_html and 'has-event' in diary_html and 'data-reader-form' in diary_html
_,game_diary_html=parse(request(diary_url+'&calendar=tamrielic&game_year=427&game_month=8'))
assert 'Last Seed, 3E 427' in game_diary_html and 'Fredas' in game_diary_html and 'Not recorded' in game_diary_html and 'game_month=9' in game_diary_html
_,empty_diary_html=parse(request(diary_url+'&date=1900-01-01'))
assert narrative_text not in empty_diary_html and 'No entries match this date' in empty_diary_html
_,person_diary_html=parse(request(diary_url+'&view=people&person='+profile_id))
assert narrative_text in person_diary_html and 'calendar-people' in person_diary_html
diary_export=request(diary_url+'&export=1')
assert diary_export.headers.get('Content-Type','').startswith('text/csv') and narrative_text in diary_export.read().decode()
empty_export=request(diary_url+'&export=1&date=1900-01-01')
assert narrative_text not in empty_export.read().decode()
revise_narrative=next(f for f in narratives.forms if f['action'].endswith('/forms/narrative-revise') and f['fields'].get('narrative_id')==narrative_id)
revised_title=narrative_title+' revised'; revised_text='Reached Balmora and found Caius.'
r=request(revise_narrative['action'],'POST',dict(revise_narrative['fields'],_csrf=csrf,kind='summary',title=revised_title,content=revised_text,provenance='management-http edit')); body=r.read().decode()
assert r.status==200 and revised_title in body and revised_text in body,(r.status,r.geturl(),body)
request_log_path='/LorkhanServer/ui/request_logs.php?installation_id='+valid['installation_id']
_,request_log_html=parse(request(request_log_path+'&limit=200&embed=1'))
assert 'Request to LLM Services Log' in request_log_html and 'Limit 200' in request_log_html and 'data-confirm-clear' in request_log_html
assert '_provider_configuration' not in request_log_html
request_clear_path='/LorkhanServer/manage/api/v1/request-logs/clear'
request_clear_values={'installation_id':valid['installation_id'],'confirm':'Clear'}
r=json_request(request_clear_path,'GET',None,csrf); assert r.status in (404,405)
r=json_request(request_clear_path,'POST',request_clear_values); assert r.status==401
r=json_request(request_clear_path,'POST',dict(request_clear_values,confirm=''),csrf); assert r.status==422
r=json_request(request_clear_path,'POST',dict(request_clear_values,installation_id=str(uuid.uuid4())),csrf); assert r.status==422
r=json_request(request_clear_path,'POST',request_clear_values,csrf); assert r.status==200 and isinstance(json.loads(r.read())['cleared'],int)
r=json_request(request_clear_path,'POST',request_clear_values,csrf); assert r.status==200 and json.loads(r.read())['cleared']==0
clear_path='/LorkhanServer/manage/api/v1/roleplay/clear'
clear_values={'installation_id':valid['installation_id'],'playthrough_id':playthrough_id,'kind':'diaries','confirm':'Clear'}
r=json_request(clear_path,'GET',None,csrf); assert r.status in (404,405)
r=json_request(clear_path,'POST',clear_values); assert r.status==401
r=json_request(clear_path,'POST',dict(clear_values,confirm=''),csrf); assert r.status==422
r=json_request(clear_path,'POST',dict(clear_values,playthrough_id=str(uuid.uuid4())),csrf); assert r.status==422
r=json_request(clear_path,'POST',dict(clear_values,kind='source_events'),csrf); assert r.status==422
bulk_diary_title=narrative_title+' bulk test'
r=request(create_narrative['action'],'POST',dict(values,title=bulk_diary_title)); assert r.status==200
r=json_request(clear_path,'POST',clear_values,csrf); assert r.status==200 and json.loads(r.read())['cleared']==1
_,cleared_narratives=parse(request('/LorkhanServer/ui/narrative_manager.php'))
assert bulk_diary_title not in cleared_narratives and revised_title in cleared_narratives
r=json_request(clear_path,'POST',clear_values,csrf); assert r.status==200 and json.loads(r.read())['cleared']==0
r=request('/LorkhanServer/manage/forms/narrative-delete','POST',{'_csrf':csrf,'narrative_id':narrative_id}); body=r.read().decode(); assert r.status==200 and revised_title not in body,(r.status,r.geturl())
globals_page,_=parse(request('/LorkhanServer/ui/core/global_settings.php'))
settings_form=next(f for f in globals_page.forms if f['action'].endswith('/forms/global-settings-save'))
values=dict(settings_form['fields'],_csrf=csrf,installation_id=valid['installation_id'],rechat_mode='group',
    rechat_allow_actions='1',relationship_enabled='1',relationship_update_chance_percent='75',context_location_blacklist='Balmora',
    auto_lock_profile='1',autofill_custom_profiles='1',autofill_custom_profiles_trigger='25',
    prompt_head='Global roleplay <&> sentinel.',emote_moods='curious, guarded',change_reason='HTTP layered global settings')
r=request(settings_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'tab=globals-page' in r.geturl(),(r.status,r.geturl(),body)
globals_page,body=parse(request('/LorkhanServer/ui/core/global_settings.php'))
assert '<option value="group" selected>Group</option>' in body and 'name="rechat_allow_actions" value="1" checked' in body and 'name="recent_turn_limit"' not in body and 'name="knowledge_limit"' not in body and 'name="relationship_enabled" value="1" checked' in body and 'name="relationship_update_chance_percent" value="75"' in body and 'name="auto_lock_profile" value="1" checked' in body and 'name="autofill_custom_profiles" value="1" checked' in body and 'name="autofill_custom_profiles_trigger" value="25"' in body
assert all('<h2>'+section in body for section in ['Prompt &amp; Rechat','Memory','Misc','Translation','Oghma','Context Selections','Global Connectors']) and all(name in body for name in ['auto_lock_profile','autofill_custom_profiles','autofill_custom_profiles_trigger','oghma_enabled','translation_provider','context_location_blacklist','profile_generation_configuration_id','memory_embedding_enabled','memory_summary_enabled','memory_summary_interval']) and not any(name in body for name in ['player_worst_memory_game_days','chim_ai_quest_progression','Background Life Trigger Time'])
assert all(re.search(r'<(?:input|select)[^>]*name="'+re.escape(name)+r'"[^>]*data-translation-control=',body) for name in ['translation_provider','translation_text','translation_audio','translation_save_text','translation_source_language','translation_target_language','translation_endpoint_url'])
assert '<option value="none" selected>None</option>' in body and '<option value="deepl">DeepL</option>' in body and 'name="translation_provider" data-translation-control="provider" aria-label="Provider"' in body
assert '<option value="https://api-free.deepl.com/v2/translate" selected>Free account (api-free.deepl.com)</option>' in body and '<option value="https://api.deepl.com/v2/translate">Pro account (api.deepl.com)</option>' in body
assert 'translates NPC subtitles and speech audio' in body and not any(name in body for name in ['translation_player_audio','translation_save_player_text','translation_player_source_language','translation_player_target_language'])
translation_values=dict(values,translation_provider='deepl',translation_text='1')
context_names=re.findall(r'name="(context_(?:section|detail)_[a-z_]+)"',body)
assert len(context_names)==len(set(context_names))==33
assert all(title in body for title in ['Top-Level Sections','Character Subsections','Appearance / State Subsections','Nearby Actor Details','Nearby Item Details'])
assert all(len(re.findall(r'name="'+field+r'"',body))==1 for field in ['memory_summary_enabled','memory_summary_connector','oghma_configuration_id','oghma_extractor_enabled','relationship_enabled','relationship_configuration_id'])
context_values=dict(values); context_values.pop('context_section_world',None); context_values.pop('context_detail_npc_summary',None)
r=request(settings_form['action'],'POST',context_values); assert r.status==200,r.status
_,context_body=parse(request('/LorkhanServer/ui/core/global_settings.php'))
assert 'name="context_section_world" value="1" checked' not in context_body and 'name="context_detail_npc_summary" value="1" checked' not in context_body
r=request(settings_form['action'],'POST',translation_values); invalid_body=r.read().decode()
assert r.status==422 and 'invalid_translation_activation' in invalid_body,(r.status,invalid_body)
r=request(settings_form['action'],'POST',dict(translation_values,translation_target_language='de',translation_endpoint_url='https://api.deepl.com/v2/translate')); body=r.read().decode(); assert r.status==200,(r.status,body)
_,body=parse(request('/LorkhanServer/ui/core/global_settings.php'))
assert '<option value="deepl" selected>DeepL</option>' in body and 'name="translation_target_language" data-translation-control="target"' in body and 'value="DE"' in body and '<option value="https://api.deepl.com/v2/translate" selected>Pro account (api.deepl.com)</option>' in body
r=request(settings_form['action'],'POST',dict(values,translation_provider='none')); assert r.status==200,r.status
globals_page,body=parse(request('/LorkhanServer/ui/core/global_settings.php'))
assert '<option value="none" selected>None</option>' in body and '<option value="https://api-free.deepl.com/v2/translate" selected>Free account (api-free.deepl.com)</option>' in body
global_import=next(f for f in globals_page.forms if f['action'].endswith('/forms/global-settings-import'))
global_export_match=re.search(r'/manage/exports/global-settings/([0-9a-f-]{36})\.json',body); assert global_export_match,body
global_configuration_id=global_export_match.group(1)
global_preset_response=request('/LorkhanServer/manage/exports/global-settings/'+global_configuration_id+'.json')
global_preset=json.loads(global_preset_response.read().decode())
assert global_preset_response.status==200 and sorted(global_preset)==['exported_at','memory_policies','name','schema','settings']
assert global_preset['schema']=='lorkhan.global-settings-preset.v3' and global_preset['settings']['schema']=='lorkhan.global-settings.v2'
assert global_preset['settings']['prompt']=={'prompt_head':'Global roleplay <&> sentinel.','emote_moods':'curious, guarded'}
assert global_preset['settings']['client']['behavior']['rechat_mode']=='group' and global_preset['settings']['client']['behavior']['rechat_allow_actions'] is True and global_preset['settings']['context']['location_blacklist']==['Balmora'] and global_preset['settings']['profile_management']=={'auto_lock_profile':True,'autofill_custom_profiles':True,'autofill_custom_profiles_trigger':25} and global_preset['settings']['relationship']=={'enabled':True,'update_chance_percent':75}
assert not any(key in global_preset for key in ['installation_id','configuration_id','revision','revisions','routing','api_keys','npc_assignments'])
invalid_global_preset=dict(global_preset,unexpected='rejected')
r=request(global_import['action'],'POST',dict(global_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(invalid_global_preset))); invalid_body=r.read().decode()
assert r.status==422 and 'invalid_global_settings_preset' in invalid_body,(r.status,invalid_body)
secret_global_preset=dict(global_preset,settings=dict(global_preset['settings'],api_key='never'))
r=request(global_import['action'],'POST',dict(global_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(secret_global_preset))); invalid_body=r.read().decode()
assert r.status==422 and 'invalid_global_settings_preset' in invalid_body,(r.status,invalid_body)
global_preset['settings']['context']['location_blacklist']=['Balmora','Seyda Neen']
global_preset['memory_policies']['summary'].update(summary_interval=3,minimum_events=6)
global_preset['memory_policies']['embedding']['timeout_ms']=1700
r=request(global_import['action'],'POST',dict(global_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(global_preset))); imported_page,imported_body=parse(r)
imported_export=json.loads(request('/LorkhanServer/manage/exports/global-settings/'+global_configuration_id+'.json').read().decode())
assert r.status==200 and 'status=imported' in r.geturl() and 'name="knowledge_limit"' not in imported_body and imported_export['settings']['context']['location_blacklist']==['Balmora','Seyda Neen'],(r.status,r.geturl(),imported_export)
assert 'name="auto_lock_profile" value="1" checked' in imported_body and 'name="oghma_result_limit" value="3"' in imported_body
assert imported_export['memory_policies']==global_preset['memory_policies']
assert imported_export['settings']['prompt']==global_preset['settings']['prompt'] and 'Global roleplay &lt;&amp;&gt; sentinel.' in imported_body
global_rollback=next(f for f in imported_page.forms if f['action'].endswith('/forms/global-settings-rollback'))
assert global_rollback['fields']['configuration_id']==global_configuration_id and int(global_rollback['fields']['revision'])>=1
r=request(global_rollback['action'],'POST',dict(global_rollback['fields'],_csrf=csrf)); rolled_page,rolled_body=parse(r)
rolled_export=json.loads(request('/LorkhanServer/manage/exports/global-settings/'+global_configuration_id+'.json').read().decode())
assert r.status==200 and 'status=rolled-back' in r.geturl() and 'name="knowledge_limit"' not in rolled_body and rolled_export['settings']['context']['location_blacklist']!=['Balmora','Seyda Neen'],(r.status,r.geturl(),rolled_export)
assert 'Earlier revision restored as a new Global Settings revision.' in rolled_body
r=request(global_rollback['action'],'POST',dict(global_rollback['fields'],_csrf=csrf,configuration_id=str(uuid.uuid4()))); invalid_body=r.read().decode()
assert r.status==422 and 'invalid_global_settings_revision' in invalid_body,(r.status,invalid_body)
memories,_=parse(request('/LorkhanServer/ui/events-memories.php?tab=memories-tab'))
# Named presets save unsaved controls without changing the active revision or connector assignments.
preset_path='/LorkhanServer/manage/forms/global-settings-preset'
preset_request=lambda data: request(preset_path,'POST',data,accept='application/json')
preset_form=next(f for f in rolled_page.forms if f['action'].endswith('/forms/global-settings-save'))
preset_values=dict(preset_form['fields'],_csrf=csrf,installation_id=valid['installation_id'],operation='save_new',
    preset_name='HTTP <named> preset',prompt_head='Unsaved preset prompt',context_location_blacklist='')
r=preset_request(dict(preset_values,_csrf='wrong')); assert r.status==401,r.status
r=preset_request(preset_values); result=json.loads(r.read()); assert r.status==200,(r.status,result)
named_id=result['preset_id']; assert any(p['preset_id']==named_id and p['revision']==1 for p in result['presets'])
unchanged=json.loads(request('/LorkhanServer/manage/exports/global-settings/'+global_configuration_id+'.json').read())
assert unchanged['settings']==rolled_export['settings'] and unchanged['memory_policies']==rolled_export['memory_policies']
_,preset_html=parse(request('/LorkhanServer/ui/core/global_settings.php'))
assert 'HTTP &lt;named&gt; preset' in preset_html and 'data-preset-operation="overwrite"' in preset_html
r=preset_request(preset_values); assert r.status==422,r.status
overwrite=dict(preset_values,operation='overwrite',preset_id=named_id,preset_revision='1',confirm='Overwrite',emote_moods='alert')
r=preset_request(overwrite); result=json.loads(r.read()); assert r.status==200,(r.status,result)
r=preset_request(overwrite); assert r.status==409,r.status
r=preset_request(dict(overwrite,preset_id='default')); assert r.status==422,r.status
apply_values={'_csrf':csrf,'installation_id':valid['installation_id'],'operation':'apply','preset_id':named_id}
r=preset_request(apply_values); assert r.status==422,r.status
r=preset_request(dict(apply_values,confirm='Apply')); result=json.loads(r.read()); assert r.status==200 and result['applied'],(r.status,result)
applied=json.loads(request('/LorkhanServer/manage/exports/global-settings/'+global_configuration_id+'.json').read())
assert applied['settings']['prompt']=={'prompt_head':'Unsaved preset prompt','emote_moods':'alert'}
assert applied['settings']['context']['location_blacklist']==[]
assert applied['settings']['system_routing']==unchanged['settings']['system_routing']
assert applied['settings']['translation']['endpoint']==unchanged['settings']['translation']['endpoint']
assert applied['memory_policies']['summary']['provider_configuration_id']==unchanged['memory_policies']['summary']['provider_configuration_id']
r=preset_request(dict(apply_values,confirm='Apply',preset_id='default')); assert r.status==200,(r.status,r.read())
# Restore the surrounding test's active settings; no real provider is invoked by saving or applying presets.
r=request(global_import['action'],'POST',dict(global_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(rolled_export))); assert r.status==200,(r.status,r.read())
create_memory=next(f for f in memories.forms if f['action'].endswith('/forms/memory'))
memory_text='HTTP managed memory '+uuid.uuid4().hex
memory_url='/LorkhanServer/ui/events-memories.php?'+urllib.parse.urlencode({'tab':'memory','installation_id':valid['installation_id'],'playthrough_id':playthrough_id})
values=dict(create_memory['fields'],_csrf=csrf,installation_id=valid['installation_id'],profile_id=profile_id,playthrough_id=playthrough_id,tier='mid',content=memory_text,provenance='management-http')
r=request(create_memory['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'tab=memory' in r.geturl() and memory_text in body,(r.status,r.geturl(),body)
memory_match=re.search(re.escape(memory_text)+r'.*?name="memory_id" value="([0-9a-f-]{36})"',body,re.S); assert memory_match,body
memory_id=memory_match.group(1)
policy_page,_=parse(request(memory_url))
summary_form=next(f for f in policy_page.forms if f['action'].endswith('/forms/memory-policy'))
assert 'enabled' not in summary_form['fields']
summary_values=dict(summary_form['fields'],_csrf=csrf,installation_id=valid['installation_id'])
r=request(summary_form['action'],'POST',dict(summary_values,enabled='1',provider_configuration_id='')); assert r.status==422
r=request(summary_form['action'],'POST',dict(summary_values,_csrf='wrong')); assert r.status==200 and r.geturl().endswith('/ui/home.php')
policy_page,_=parse(request(memory_url))
assert 'enabled' not in next(f for f in policy_page.forms if f['action'].endswith('/forms/memory-policy'))['fields']
for changes in [{'memory_id':'not-a-uuid'},{'base_revision':'1.5'},{'base_revision':'0'},{}]:
    r=request('/LorkhanServer/manage/forms/memory-summarize','POST',dict({'_csrf':csrf,'installation_id':valid['installation_id'],'memory_id':memory_id,'base_revision':'1'},**changes))
    assert r.status==422,(r.status,r.read().decode()) # Manual memories are never model-summary inputs.
memories,_=parse(request(memory_url))
revise_memory=next(f for f in memories.forms if f['action'].endswith('/forms/memory-revise') and f['fields'].get('memory_id')==memory_id)
revised_memory=memory_text+' revised'; r=request(revise_memory['action'],'POST',dict(revise_memory['fields'],_csrf=csrf,content=revised_memory)); body=r.read().decode(); assert r.status==200 and revised_memory in body,(r.status,r.geturl(),body)
r=request('/LorkhanServer/manage/forms/memory-delete','POST',{'_csrf':csrf,'memory_id':memory_id}); body=r.read().decode(); assert r.status==200 and revised_memory not in body,(r.status,r.geturl())
assert 'Sync Memory Summaries Now' in body and 'Delete All Memory Summaries' in body and 'memory-config-link' in body
sync_path='/LorkhanServer/manage/api/v1/roleplay/sync-memories'
sync_values={'installation_id':valid['installation_id'],'playthrough_id':playthrough_id,'confirm':'Sync'}
assert json_request(sync_path,'POST',sync_values).status==401
assert json_request(sync_path,'GET',None,csrf).status in (404,405)
assert json_request(sync_path,'POST',dict(sync_values,confirm=''),csrf).status==422
assert json_request(sync_path,'POST',dict(sync_values,playthrough_id=str(uuid.uuid4())),csrf).status==422
assert json_request(sync_path,'POST',sync_values,csrf).status==422 # Disabled summary policy does not enqueue paid work.
r=request(create_memory['action'],'POST',values); assert r.status==200
r=json_request(clear_path,'POST',dict(clear_values,kind='memories'),csrf)
assert r.status==200 and json.loads(r.read())['cleared']>=1
_,memory_after_clear=parse(request(memory_url))
assert memory_text not in memory_after_clear
legacy_relationships,body=parse(request('/LorkhanServer/ui/events-memories.php?tab=relationships-tab'))
assert legacy_relationships.current==1 and 'id="journal-tab" class="tab-content active"' in body and '/forms/relationships' not in body,(legacy_relationships.current,body)
relationship_page,body=parse(request('/LorkhanServer/ui/relationship_logs.php?embed=1&installation_id='+valid['installation_id']))
relationship_create=next((f for f in relationship_page.forms if f['action'].endswith('/forms/relationships')),None)
assert relationship_create is not None,body
assert re.search(r'id="relationship-custom-info"[^>]*></textarea>',body)
assert body.count('id="relationship-type-options"')==1 and relationship_create['fields']['relationship_type']=='neutral'
assert 'sent to AI and shown in prompts, unlike Custom Info' in body
private_relationship_note='{"token":"private reminder","text":"<&> 古"}'
build_query=urllib.parse.urlencode(dict(installation_id=valid['installation_id'],profile_id=profile_id,playthrough_id=playthrough_id,embed='1'))
build_page,build_body=parse(request('/LorkhanServer/ui/relationship_logs.php?'+build_query))
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
relationship_values['relationship_type']='professional'
r=request(relationship_create['action'],'POST',dict(relationship_values,disposition='not-a-number')); assert r.status==422
r=request(relationship_create['action'],'POST',dict(relationship_values,relationship_type='two words')); assert r.status==422
r=request(relationship_create['action'],'POST',dict(relationship_values,_csrf='wrong')); assert r.status==200 and r.geturl().endswith('/ui/home.php')
r=request(relationship_create['action'],'POST',relationship_values); relationship_page,body=parse(r)
assert r.status==200 and 'embed=1' in r.geturl() and 'HTTP relationship actor' in body,(r.status,r.geturl(),body)
relationship_edit=next(f for f in relationship_page.forms if f['action'].endswith('/forms/relationships') and f['fields'].get('relationship_id'))
relationship_id=relationship_edit['fields']['relationship_id']; assert relationship_edit['fields']['expected_revision']=='1' and relationship_edit['fields']['relationship_type']=='professional'
custom_info_pattern=r'<textarea id="custom-info-'+relationship_id+r'"[^>]*>\n(.*?)</textarea>'
assert html.unescape(re.search(custom_info_pattern,body,re.S)[1])==private_relationship_note
renamed_identity=dict(relationship_identity,display_name='Renamed HTTP actor')
r=request(relationship_create['action'],'POST',dict(relationship_values,content_json=json.dumps(renamed_identity))); body=r.read().decode()
assert r.status==200 and 'relationship_already_exists' in r.geturl() and 'already has a relationship' in body
score_only=dict(relationship_edit['fields'],_csrf=csrf,disposition='20',affinity='6',reason='HTTP relationship edit')
score_only.pop('relationship_type')
r=request(relationship_edit['action'],'POST',score_only); relationship_page,body=parse(r)
latest_edit=next(f for f in relationship_page.forms if f['fields'].get('relationship_id')==relationship_id and f['action'].endswith('/forms/relationships'))
assert latest_edit['fields']['expected_revision']=='2' and latest_edit['fields']['disposition']=='20' and latest_edit['fields']['relationship_type']=='professional'
assert html.unescape(re.search(custom_info_pattern,body,re.S)[1])==private_relationship_note # Older score-only forms preserve it.
assert 'private reminder' not in body.split('id="relationship-history"',1)[1] and 'Custom Info updated' in body
r=request(latest_edit['action'],'POST',dict(latest_edit['fields'],_csrf=csrf,relationship_type='trusted_companion',reason='Choose custom type')); relationship_page,body=parse(r)
latest_edit=next(f for f in relationship_page.forms if f['fields'].get('relationship_id')==relationship_id and f['action'].endswith('/forms/relationships'))
assert latest_edit['fields']['relationship_type']=='trusted_companion' and 'type trusted_companion' in body
assert request(latest_edit['action'],'POST',dict(latest_edit['fields'],_csrf=csrf,custom_info='x'*2001)).status==422
assert request(latest_edit['action'],'POST',dict(latest_edit['fields'],_csrf=csrf,**{'custom_info[]':'invalid'})).status==422
private_relationship_note='\nKeep <&> 古\nTrailing spaces  '
r=request(latest_edit['action'],'POST',dict(latest_edit['fields'],_csrf=csrf,custom_info=private_relationship_note.replace('\n','\r\n'))); relationship_page,body=parse(r)
assert html.unescape(re.search(custom_info_pattern,body,re.S)[1])==private_relationship_note
private_relationship_backup=json.loads(request('/LorkhanServer/manage/exports/playthroughs/'+playthrough_id+'.json').read())
exported_relationships=private_relationship_backup['data']['relationships']
exported_relationship=next(row for row in exported_relationships if row['relationship_id']==relationship_id)
assert exported_relationship['custom_info']==private_relationship_note and exported_relationship['relationship_type']=='trusted_companion'
latest_edit=next(f for f in relationship_page.forms if f['fields'].get('relationship_id')==relationship_id and f['action'].endswith('/forms/relationships'))
r=request(latest_edit['action'],'POST',dict(latest_edit['fields'],_csrf=csrf,custom_info='')); relationship_page,body=parse(r)
assert re.search(custom_info_pattern,body,re.S)[1]==''
try:
    opener.open(urllib.request.Request(base+'/LorkhanServer/manage/api/v1/playthrough-restore',
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
    opener.open(urllib.request.Request(base+'/LorkhanServer/manage/api/v1/relationships',data=json.dumps(api_relationship).encode(),
        headers={'Content-Type':'application/json','X-CSRF-Token':csrf}),timeout=5)
    raise AssertionError('stale management API write accepted')
except urllib.error.HTTPError as error:
    assert error.code==409 and json.loads(error.read())['error']=='relationship_revision_conflict'
relationship_delete=next(f for f in relationship_page.forms if f['fields'].get('relationship_id')==relationship_id and f['action'].endswith('/forms/relationship-delete'))
r=request(relationship_delete['action'],'POST',dict(relationship_delete['fields'],_csrf=csrf,expected_revision='1')); assert r.status==200 and 'relationship_revision_conflict' in r.geturl()
r=request(relationship_delete['action'],'POST',dict(relationship_delete['fields'],_csrf=csrf)); relationship_page,body=parse(r)
assert r.status==200 and not any(f['fields'].get('relationship_id')==relationship_id for f in relationship_page.forms)
assert 'HTTP relationship create' in body and 'HTTP relationship edit' in body and 'management delete' in body
assert 'Recent changes' in body and '>6 shown<' in body
backup_response=request('/LorkhanServer/manage/exports/playthroughs/'+playthrough_id+'.json'); backup=json.loads(backup_response.read().decode())
assert backup_response.status==200 and backup['schema']=='lorkhan.playthrough-export.v1' and backup['scope']=={'installation_id':valid['installation_id'],'profile_id':profile_id,'playthrough_id':playthrough_id},backup['scope']
playthroughs,_=parse(request('/LorkhanServer/ui/playthrough_manager.php'))
restore=next(f for f in playthroughs.forms if f['action'].endswith('/forms/playthrough-import'))
values=dict(restore['fields'],_csrf=csrf,profile_id=profile_id,playthrough_id=playthrough_id,playthrough_json=json.dumps(backup))
r=request(restore['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/playthrough_manager.php?status=saved'),(r.status,r.geturl(),values,backup['scope'],body)
characters,body=parse(request('/LorkhanServer/ui/core/character_manager.php'))
bio_form=next(f for f in characters.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==profile_id)
current_profile=json.loads(request('/LorkhanServer/manage/exports/profiles/'+profile_id+'.json').read().decode())
values=dict(bio_form['fields'],_csrf=csrf,profile_id=profile_id,base_content_json=json.dumps(current_profile['content']),biography='Updated from Character Manager.',change_reason='HTTP biography test')
r=request(bio_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved'),(r.status,r.geturl())
body=request('/LorkhanServer/ui/core/character_manager.php').read().decode(); assert 'Updated from Character Manager.' in body and 'Preserved personality field.' in body
summary_connectors,_=parse(request('/LorkhanServer/ui/core/llm_connectors.php?create=1'))
summary_connector_form=next(f for f in summary_connectors.forms if f['action'].endswith('/forms/providers'))
summary_connector_name='HTTP memory summary '+uuid.uuid4().hex
r=request(summary_connector_form['action'],'POST',dict(summary_connector_form['fields'],_csrf=csrf,name=summary_connector_name,driver='mock',model='memory-http'))
summary_connector_id=connector_editor_id(r.read().decode(),summary_connector_name)
summary_values.update(provider_configuration_id=summary_connector_id,enabled='1')
r=request(summary_form['action'],'POST',summary_values); policy_page,body=parse(r)
assert r.status==200 and 'policy_installation_id='+valid['installation_id'] in r.geturl()
assert next(f for f in policy_page.forms if f['action'].endswith('/forms/memory-policy'))['fields']['enabled']=='1'
assert any(f['action'].endswith('/forms/memory-rebuild') for f in policy_page.forms)
r=request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':summary_connector_id}); assert r.status==422
database,body=parse(request('/LorkhanServer/ui/database_manager.php'))
backup_ids_before=set(re.findall(r'/exports/backups/([0-9a-f-]{36})\.json',body))
create_backup=next(f for f in database.forms if f['action'].endswith('/forms/configuration-backup'))
values=dict(create_backup['fields'],_csrf=csrf,installation_id=valid['installation_id'],confirm='wrong')
r=request(create_backup['action'],'POST',values); body=r.read().decode(); assert r.status==422 and 'confirmation_mismatch' in body,(r.status,r.geturl(),body)
values['confirm']='Backup'; r=request(create_backup['action'],'POST',values); body=r.read().decode()
assert r.status==200 and r.geturl().endswith('/ui/database_manager.php?status=saved') and 'Download backup' in body,(r.status,r.geturl(),body)
backup_ids_after=set(re.findall(r'/exports/backups/([0-9a-f-]{36})\.json',body)); created_backup_ids=backup_ids_after-backup_ids_before; assert len(created_backup_ids)==1,(backup_ids_before,backup_ids_after)
configuration_backup_id=created_backup_ids.pop()
backup_response=request('/LorkhanServer/manage/exports/backups/'+configuration_backup_id+'.json'); configuration_backup=json.loads(backup_response.read().decode())
assert backup_response.status==200 and configuration_backup['schema']=='lorkhan.configuration-backup.v2' and configuration_backup['format_version']==2 and configuration_backup['installation_id']==valid['installation_id']
core_ids={row['core_profile_id'] for row in configuration_backup['data']['core_profiles']}; assert len(core_ids)>=1 and sum(row['default_npc'] is True for row in configuration_backup['data']['core_profiles'])==1
assert all(row['core_profile_id'] in core_ids for row in configuration_backup['data']['profiles']),configuration_backup['data']['profiles']
assert configuration_backup['backup_id']==configuration_backup_id and 'portrait' not in json.dumps(configuration_backup).lower() and 'api_key' not in json.dumps(configuration_backup).lower()
saved_policy=next(row for row in configuration_backup['data']['configurations'] if row['kind']=='memory_policy')
assert saved_policy['content']['enabled'] is True and saved_policy['content']['provider_configuration_id']==summary_connector_id
summary_values.pop('enabled'); r=request(summary_form['action'],'POST',summary_values); assert r.status==200
characters,_=parse(request('/LorkhanServer/ui/core/character_manager.php'))
bio_form=next(f for f in characters.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==profile_id)
current_profile=json.loads(request('/LorkhanServer/manage/exports/profiles/'+profile_id+'.json').read().decode())
values=dict(bio_form['fields'],_csrf=csrf,profile_id=profile_id,base_content_json=json.dumps(current_profile['content']),biography='Changed after the configuration backup.',change_reason='HTTP pre-restore mutation')
r=request(bio_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'Changed after the configuration backup.' in body
database,_=parse(request('/LorkhanServer/ui/database_manager.php'))
restore_configuration=next(f for f in database.forms if f['action'].endswith('/forms/configuration-restore'))
values=dict(restore_configuration['fields'],_csrf=csrf,installation_id=valid['installation_id'],backup_id=configuration_backup_id,confirm='wrong')
r=request(restore_configuration['action'],'POST',values); body=r.read().decode(); assert r.status==422 and 'confirmation_mismatch' in body
values['confirm']='Restore'; r=request(restore_configuration['action'],'POST',values); body=r.read().decode()
assert r.status==200 and r.geturl().endswith('/ui/database_manager.php?status=saved') and 'restored ·' in body,(r.status,r.geturl(),body)
policy_page,_=parse(request('/LorkhanServer/ui/events-memories.php?tab=memory'))
restored_policy=next(f for f in policy_page.forms if f['action'].endswith('/forms/memory-policy'))
assert restored_policy['fields']['enabled']=='1' and restored_policy['fields']['provider_configuration_id']==summary_connector_id
summary_values['provider_configuration_id']=''; r=request(summary_form['action'],'POST',summary_values); assert r.status==200
r=request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':summary_connector_id}); assert r.status==200
body=request('/LorkhanServer/ui/core/character_manager.php').read().decode(); assert 'Updated from Character Manager.' in body and 'Changed after the configuration backup.' not in body
r=request('/LorkhanServer/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':profile_id}); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved'),(r.status,r.geturl())
descriptions,body=parse(request('/LorkhanServer/ui/description_manager.php'))
description_form=next(f for f in descriptions.forms if f['action'].endswith('/forms/description-save'))
installation_id=description_form['fields']['installation_id']
example=request('/LorkhanServer/manage/exports/descriptions/example.csv'); example_body=example.read().decode('utf-8-sig')
assert example.status==200 and example_body.startswith('plugin,baseid,name,description')
upload_csv=('plugin,baseid,name,description\nHTTP Test.esp,http_csv_item,HTTP CSV Item,"Imported through the batch description manager."\n').encode()
r=multipart_request('/LorkhanServer/manage/forms/description-import',{'_csrf':csrf,'installation_id':installation_id},'csv_file','descriptions.csv','text/csv',upload_csv)
body=r.read().decode(); assert r.status==200 and '1 custom descriptions imported.' in body and 'http_csv_item' in body,(r.status,r.geturl(),body)
exported=request('/LorkhanServer/manage/exports/descriptions/custom.csv?installation_id='+installation_id).read().decode('utf-8-sig')
assert 'http_csv_item' in exported and 'HTTP CSV Item' in exported,exported
record_id='http_record_'+uuid.uuid4().hex
values=dict(description_form['fields'],_csrf=csrf,content_file='HTTP Test.esp',record_id=record_id,display_name='HTTP Test Item',description='Created through the descriptions manager.')
r=request(description_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'status=saved' in r.geturl() and record_id in body,(r.status,r.geturl())
match=re.search(re.escape(record_id)+r'.*?name="description_id" value="([0-9a-f-]{36})"',body,re.S); assert match,body
r=request('/LorkhanServer/manage/forms/description-delete','POST',{'_csrf':csrf,'installation_id':installation_id,'description_id':match.group(1)}); assert r.status==200 and 'status=saved' in r.geturl()
r=request('/LorkhanServer/manage/forms/description-reset','POST',{'_csrf':csrf,'installation_id':installation_id,'confirm':'Reset'}); body=r.read().decode(); assert r.status==200 and 'status=saved' in r.geturl() and 'http_csv_item' not in body
llm_page,body=parse(request('/LorkhanServer/ui/core/llm_connectors.php?selected=runtime'))
runtime_test=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-runtime-test'))
r=request(runtime_test['action'],'POST',dict(runtime_test['fields'],_csrf=csrf)); body=r.read().decode(); assert r.status==200 and 'Test completed: 1 valid utterance' in body,(r.status,r.geturl(),body)
llm_create_page,_=parse(request('/LorkhanServer/ui/core/llm_connectors.php?create=1'))
llm_form=next(f for f in llm_create_page.forms if f['action'].endswith('/forms/providers'))
slot_name='HTTP model slot '+uuid.uuid4().hex
values=dict(llm_form['fields'],_csrf=csrf,name=slot_name,driver='mock',model='deterministic-mock-v1',mock_prefix='[http] ')
r=request(llm_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/llm_connectors.php?status=saved') and slot_name in body,(r.status,r.geturl())
slot_id=connector_editor_id(body,slot_name)
llm_page,body=parse(request('/LorkhanServer/ui/core/llm_connectors.php?selected='+slot_id))
model_test=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-test') and f['fields'].get('configuration_id')==slot_id)
r=request(model_test['action'],'POST',dict(model_test['fields'],_csrf=csrf)); body=r.read().decode(); assert r.status==200 and 'Test completed: 1 valid utterance' in body,(r.status,r.geturl(),body)
revise=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-revise') and f['fields'].get('configuration_id')==slot_id)
values=dict(revise['fields'],_csrf=csrf,driver='mock',model='deterministic-mock-v2',mock_prefix='[revised] ',change_reason='HTTP model-slot test')
r=request(revise['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'deterministic-mock-v2' in body,(r.status,r.geturl())
core_list,core_body=parse(request('/LorkhanServer/ui/core/core_profiles.php'))
core_edit=re.search(r'core_profiles\.php\?edit=([0-9a-f-]{36})',core_body); assert core_edit,core_body
assert '/exports/core-profile-settings/'+core_edit.group(1)+'.json' in core_body and '>Import</a>' in core_body
assert 'settings overrides only' in core_body
assert 'id="profile-rules-open"' in core_body and 'id="profile-connector-test-open"' in core_body
core_import_page,core_import_body=parse(request('/LorkhanServer/ui/core/core_profiles.php?import=1'))
core_import_form=next(f for f in core_import_page.forms if f['action'].endswith('/forms/core-profile-settings-import'))
assert 'name="preset_json"' in core_import_body and 'data-json-import-target="core-profile-preset-json"' in core_import_body
# Validate the compact Core Profile response, Rechat, context, and automatic diary controls.
core_body=request('/LorkhanServer/ui/core/core_profiles.php?edit='+core_edit.group(1)).read().decode()
core_page=Page(); core_page.feed(core_body)
assert 'aria-labelledby="diary_generation_configuration_id-label"' in core_body and 'Automatic Diary' in core_body
assert 'aria-labelledby="relationship_configuration_id-label"' not in core_body and 'Relationship Update Chance' not in core_body
assert 'name="setting_behavior_rechat"' in core_body and 'name="setting_memory_recent_turn_limit"' in core_body
assert 'name="setting_diary_automatic_enabled"' in core_body and 'name="setting_diary_automatic_wait_enabled"' in core_body
assert 'Create a physical in-game diary that can be read.' not in core_body
core_form=next(f for f in core_page.forms if f['action'].endswith('/forms/core-profile-save'))
core_values=dict(core_form['fields'],_csrf=csrf,tts_configuration_id=tts_id,llm_configuration_id=slot_id,llm_fast_configuration_id=slot_id,
    setting_behavior_rechat='1',setting_behavior_rechat_max_depth='5',setting_behavior_rechat_probability_percent='65',setting_behavior_rechat_allow_actions='1',
    setting_memory_recent_turn_limit='24',setting_response_max_words='60',diary_generation_configuration_id=slot_id,
    setting_diary_enabled='1',setting_diary_automatic_enabled='1',setting_diary_automatic_wait_enabled='1',
    setting_diary_automatic_interval_seconds='90',setting_diary_context_turn_limit='12',
    setting_diary_prompt='Record only witnessed events.')
core_values.pop('setting_diary_include_in_context',None)
core_response=request(core_form['action'],'POST',core_values); assert core_response.status==200
core_body=core_response.read().decode(); core_page=Page(); core_page.feed(core_body)
core_saved=next(f for f in core_page.forms if f['action'].endswith('/forms/core-profile-save'))
assert core_saved['fields']['diary_generation_configuration_id']==slot_id and core_saved['fields']['setting_diary_enabled']=='1'
assert core_saved['fields']['setting_diary_automatic_enabled']=='1' and core_saved['fields']['setting_diary_automatic_wait_enabled']=='1'
assert core_saved['fields']['setting_diary_automatic_interval_seconds']=='90'
assert core_saved['fields']['setting_behavior_rechat']=='1' and core_saved['fields']['setting_behavior_rechat_max_depth']=='5' and core_saved['fields']['setting_behavior_rechat_probability_percent']=='65'
assert core_saved['fields']['setting_memory_recent_turn_limit']=='24',core_saved
assert core_saved['fields']['setting_response_max_words']=='60',core_saved
invalid_word_values=dict(core_values,setting_response_max_words='10001')
assert request(core_form['action'],'POST',invalid_word_values).status==422
assert 'setting_diary_include_in_context' not in core_saved['fields'] and core_saved['fields']['setting_diary_context_turn_limit']=='12'
assert '<textarea id="profile-diary-prompt" name="setting_diary_prompt" rows="3" maxlength="8192">Record only witnessed events.</textarea>' in core_body
assert len(VoiceProvider.llm_requests)==provider_calls_before_diary,core_saved
connector_plan_calls=len(VoiceProvider.llm_requests)
connector_plan_response=json_request('/LorkhanServer/manage/api/v1/profile-connector-tests?installation_id='+valid['installation_id'])
connector_plan=json.loads(connector_plan_response.read().decode())
assert connector_plan_response.status==200 and len(VoiceProvider.llm_requests)==connector_plan_calls,connector_plan
matching_jobs=[job for job in connector_plan['jobs'] if job['configuration_id']==slot_id]
assert len(matching_jobs)==1 and matching_jobs[0]['kind']=='provider' and matching_jobs[0]['label']==slot_name,connector_plan
matching_profile=next(profile for profile in connector_plan['profiles'] if profile['id']==core_edit.group(1))
matching_slots=[slot for slot in matching_profile['slots'] if slot['configuration_id']==slot_id]
assert {slot['field'] for slot in matching_slots}=={'llm_configuration_id','llm_fast_configuration_id','diary_generation_configuration_id'} and len({slot['job_key'] for slot in matching_slots})==1,matching_slots
assert any(job['configuration_id']==tts_id and job['kind']=='tts_provider' for job in connector_plan['jobs']),connector_plan
assert not any(key in json.dumps(connector_plan).lower() for key in ['api_key','credential','endpoint','content']),connector_plan
bulk_values={'installation_id':valid['installation_id'],'kind':'provider','configuration_id':slot_id}
r=json_request('/LorkhanServer/manage/api/v1/profile-connector-tests','POST',bulk_values); bulk_error=json.loads(r.read().decode())
assert r.status==401 and bulk_error['error']=='unauthorized',bulk_error
r=json_request('/LorkhanServer/manage/api/v1/profile-connector-tests','POST',dict(bulk_values,kind='stt_provider'),csrf); bulk_error=json.loads(r.read().decode())
assert r.status==422 and bulk_error['error']=='invalid_connector_kind',bulk_error
r=json_request('/LorkhanServer/manage/api/v1/profile-connector-tests','POST',bulk_values,csrf); bulk_result=json.loads(r.read().decode())['result']
assert r.status==200 and bulk_result['job_key']=='provider:'+slot_id and bulk_result['status']=='pass' and bulk_result['message'].startswith('1 valid utterance'),bulk_result
assert len(VoiceProvider.llm_requests)==connector_plan_calls,bulk_result
rules_path='/LorkhanServer/manage/api/v1/profile-assignment-rules'
rules_plan=json.loads(json_request(rules_path+'?installation_id='+valid['installation_id']).read().decode())
assert rules_plan['rules']==[] and any(profile['core_profile_id']==core_edit.group(1) for profile in rules_plan['core_profiles']),rules_plan
rule_match={'names':['HTTP Rule NPC','http rule npc'],'races':['Dark Elf'],'classes':['Commoner'],'genders':['Female'],
    'factions':['fighters guild'],'content_files':['Morrowind.esm']}
rule_values={'operation':'save','installation_id':valid['installation_id'],'rule_id':None,'description':'HTTP assignment rule',
    'core_profile_id':core_edit.group(1),'priority':25,'enabled':True,'match':rule_match}
r=json_request(rules_path,'POST',rule_values); rule_error=json.loads(r.read().decode())
assert r.status==401 and rule_error['error']=='unauthorized',rule_error
invalid_rule=dict(rule_values,match={key:[] for key in rule_match})
r=json_request(rules_path,'POST',invalid_rule,csrf); rule_error=json.loads(r.read().decode())
assert r.status==422 and rule_error['error']=='profile_assignment_rule_match_required',rule_error
r=json_request(rules_path,'POST',rule_values,csrf); saved_rule=json.loads(r.read().decode())
assert r.status==200 and saved_rule['saved'] is True and re.fullmatch(r'[0-9a-f-]{36}',saved_rule['rule_id']),saved_rule
rules_plan=json.loads(json_request(rules_path+'?installation_id='+valid['installation_id']).read().decode())
assert len(rules_plan['rules'])==1 and rules_plan['rules'][0]['rule_id']==saved_rule['rule_id'] and rules_plan['rules'][0]['priority']==25,rules_plan
assert rules_plan['rules'][0]['match']['names']==['HTTP Rule NPC'] and 'fighters guild' in rules_plan['options']['factions'],rules_plan
revised_rule=dict(rule_values,rule_id=saved_rule['rule_id'],description='HTTP assignment rule revised',priority=30,enabled=False)
r=json_request(rules_path,'POST',revised_rule,csrf); assert r.status==200,(r.status,r.read().decode())
rules_plan=json.loads(json_request(rules_path+'?installation_id='+valid['installation_id']).read().decode())
assert rules_plan['rules'][0]['description']=='HTTP assignment rule revised' and rules_plan['rules'][0]['enabled'] is False,rules_plan
r=json_request(rules_path,'POST',{'operation':'delete','installation_id':valid['installation_id'],'rule_id':saved_rule['rule_id']},csrf)
assert r.status==200 and json.loads(r.read().decode())=={'deleted':True}
assert json.loads(json_request(rules_path+'?installation_id='+valid['installation_id']).read().decode())['rules']==[]
core_preset_response=request('/LorkhanServer/manage/exports/core-profile-settings/'+core_edit.group(1)+'.json')
core_preset=json.loads(core_preset_response.read().decode())
assert core_preset_response.status==200 and sorted(core_preset)==['exported_at','name','schema','settings_overrides']
assert core_preset['schema']=='lorkhan.core-profile-settings.v2' and core_preset['settings_overrides']['behavior']=={'rechat':True,'rechat_max_depth':5,'rechat_probability_percent':65,'rechat_allow_actions':True}
assert core_preset['settings_overrides']['memory']=={'recent_turn_limit':24,'short_term_enabled':True,'mid_term_enabled':True,'long_term_enabled':True}
assert core_preset['settings_overrides']['response']=={'max_words':60}
assert core_preset['settings_overrides']['diary']=={'enabled':True,'automatic_enabled':True,'automatic_wait_enabled':True,'automatic_interval_seconds':90,'include_in_context':False,'context_turn_limit':12,'prompt':'Record only witnessed events.'}
assert not any(key in core_preset for key in ['core_profile_id','installation_id','prompt','routing','slot','default_npc','revision','npc_assignments'])
core_preset['name']='HTTP imported Core settings '+uuid.uuid4().hex
r=request(core_import_form['action'],'POST',dict(core_import_form['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(core_preset)))
body=r.read().decode(); assert r.status==200 and 'status=imported' in r.geturl() and core_preset['name'] in body,(r.status,r.geturl(),body)
imported_id_match=re.search(r'core_profiles\.php\?[^"\']*edit=([0-9a-f-]{36})[^"\']*status=imported',r.geturl())
if imported_id_match is None: imported_id_match=re.search(r'name="core_profile_id" value="([0-9a-f-]{36})"',body)
assert imported_id_match,body
imported_core_id=imported_id_match.group(1); imported_page=Page(); imported_page.feed(body)
# The NPC mass switch selects Core Profiles and keeps the existing authenticated form boundary.
switch_page,switch_html=parse(request('/LorkhanServer/ui/core/npc_master.php'))
switch_form=next(f for f in switch_page.forms if f['action'].endswith('/forms/profile-bulk-switch'))
switch_values=dict(switch_form['fields'],installation_id=valid['installation_id'],source_profile_id=imported_core_id,
    target_profile_id=core_edit.group(1),include_locked='0',confirm='Switch')
r=request(switch_form['action'],'POST',dict(switch_values,_csrf='invalid'),accept='application/json'); assert r.status==401
r=request(switch_form['action'],'POST',dict(switch_values,_csrf=csrf,confirm='wrong'),accept='application/json'); assert r.status==422 and json.loads(r.read())['error']=='confirmation_mismatch'
r=request(switch_form['action'],'POST',dict(switch_values,_csrf=csrf,target_profile_id=profile_id),accept='application/json'); assert r.status==422 and json.loads(r.read())['error']=='core_profile_scope_mismatch'
r=request(switch_form['action'],'POST',dict(switch_values,_csrf=csrf),accept='application/json')
assert r.status==200 and json.loads(r.read())=={'ok':True,'updated':0,'total_matched':0,'skipped_locked':0}
imported_form=next(f for f in imported_page.forms if f['action'].endswith('/forms/core-profile-save') and f['fields'].get('core_profile_id')==imported_core_id)
assert imported_form['fields']['label']==core_preset['name'] and '<textarea id="profile-prompt" name="prompt" maxlength="65536"></textarea>' in body
assert imported_form['fields']['setting_behavior_rechat']=='1' and imported_form['fields']['setting_behavior_rechat_max_depth']=='5'
assert imported_form['fields']['setting_behavior_rechat_probability_percent']=='65' and imported_form['fields']['setting_memory_recent_turn_limit']=='24'
assert imported_form['fields']['setting_diary_enabled']=='1' and 'setting_diary_include_in_context' not in imported_form['fields']
assert imported_form['fields']['setting_diary_automatic_enabled']=='1' and imported_form['fields']['setting_diary_automatic_wait_enabled']=='1'
assert imported_form['fields']['setting_diary_automatic_interval_seconds']=='90'
assert imported_form['fields']['setting_response_max_words']=='60'
assert imported_form['fields']['setting_behavior_rechat_allow_actions']=='1'
assert imported_form['fields']['setting_diary_context_turn_limit']=='12' and '>Record only witnessed events.</textarea>' in body
assert all(imported_form['fields'].get(field,'')=='' for field in ['prompt_configuration_id','llm_configuration_id','llm_fast_configuration_id','llm_powerful_configuration_id','llm_experimental_configuration_id','llm_fallback_configuration_id','diary_generation_configuration_id','tts_configuration_id'])
assert imported_form['fields'].get('slot','')=='' and 'default_npc' not in imported_form['fields']
invalid_preset=dict(core_preset,unexpected='rejected')
r=request(core_import_form['action'],'POST',dict(core_import_form['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(invalid_preset))); body=r.read().decode()
assert r.status==422 and 'invalid_core_profile_settings_preset' in body,(r.status,body)
secret_preset=dict(core_preset,settings_overrides={'memory':{'api_key':'never'}})
r=request(core_import_form['action'],'POST',dict(core_import_form['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(secret_preset))); body=r.read().decode()
assert r.status==422 and 'invalid_core_profile_settings_preset' in body,(r.status,body)
r=request('/LorkhanServer/manage/forms/core-profile-delete','POST',{'_csrf':csrf,'core_profile_id':imported_core_id}); assert r.status==200
core_reset=dict(core_saved['fields'],_csrf=csrf,tts_configuration_id='',llm_configuration_id='',llm_fast_configuration_id='',diary_generation_configuration_id='',
    setting_behavior_rechat_max_depth='2',setting_behavior_rechat_probability_percent='50',setting_memory_recent_turn_limit='20',
    setting_diary_automatic_interval_seconds='120',setting_diary_context_turn_limit='20')
for field in ['setting_behavior_rechat','setting_diary_enabled','setting_diary_automatic_enabled',
              'setting_diary_automatic_wait_enabled','setting_diary_include_in_context']:
    core_reset.pop(field,None)
core_reset_response=request(core_form['action'],'POST',core_reset); core_reset_body=core_reset_response.read().decode()
assert core_reset_response.status==200,(core_reset_response.status,core_reset_response.geturl(),core_reset_body)
profiles_page,_=parse(request('/LorkhanServer/ui/core/npc_master.php'))
routing_form=next(f for f in profiles_page.forms if f['action'].endswith('/forms/profile-create'))
routing_profile_name='HTTP ownership profile '+uuid.uuid4().hex
values=dict(routing_form['fields'],_csrf=csrf,installation_id=valid['installation_id'],name=routing_profile_name,
    biography='Exercises strict character-only profile ownership.',voice_language='en',
    profile_generation_configuration_id=slot_id,relationship_configuration_id=slot_id,diary_generation_configuration_id=slot_id,
    setting_relationship_update_chance_percent='100',setting_relationship_locked='1')
provider_calls_before_routing_save=len(VoiceProvider.llm_requests)
r=request(routing_form['action'],'POST',values); body=r.read().decode()
routing_match=re.search(re.escape(routing_profile_name)+r'.*?name="profile_id" value="([0-9a-f-]{36})"',body,re.S); assert routing_match,body
routing_profile_id=routing_match.group(1)
routing_page=Page(); routing_page.feed(body)
saved_routing=next(f for f in routing_page.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==routing_profile_id)
saved_routing_content=json.loads(saved_routing['fields']['base_content_json'])
assert 'routing' not in saved_routing_content and 'settings_overrides' not in saved_routing_content,saved_routing_content
assert len(VoiceProvider.llm_requests)==provider_calls_before_routing_save # Saving character details never calls a provider.
assert not any(field in saved_routing['fields'] for field in ['profile_generation_configuration_id','relationship_configuration_id','diary_generation_configuration_id','setting_relationship_locked'])

# NPC diary switches preserve inheritance and other diary policy leaves.
diary_base=dict(saved_routing_content,diary={'automatic_interval_seconds':240,'include_in_context':False})
diary_values=dict(saved_routing['fields'],_csrf=csrf,change_reason='HTTP NPC diary override',base_content_json=json.dumps(diary_base),
    npc_diary_automatic_enabled='1',npc_diary_automatic_wait_enabled='0')
for enabled,waiting in [('1','0'),('0','1'),('inherit','inherit')]:
    diary_values.update(npc_diary_automatic_enabled=enabled,npc_diary_automatic_wait_enabled=waiting)
    r=request(saved_routing['action'],'POST',diary_values); diary_body=r.read().decode()
    assert r.status==200,(r.status,diary_body)
    diary_page=Page(); diary_page.feed(diary_body)
    diary_saved=next(f for f in diary_page.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==routing_profile_id)
    diary_content=json.loads(diary_saved['fields']['base_content_json'])['diary']
    assert diary_content['automatic_interval_seconds']==240 and diary_content['include_in_context'] is False,diary_content
    if enabled=='inherit': assert 'automatic_enabled' not in diary_content and 'automatic_wait_enabled' not in diary_content,diary_content
    else: assert diary_content['automatic_enabled']==(enabled=='1') and diary_content['automatic_wait_enabled']==(waiting=='1'),diary_content
    diary_values=dict(diary_saved['fields'],_csrf=csrf,change_reason='HTTP NPC diary override')
r=request(saved_routing['action'],'POST',dict(diary_values,npc_diary_automatic_enabled='invalid'))
assert r.status==422 and 'invalid_npc_diary_override' in r.read().decode()
assert len(VoiceProvider.llm_requests)==provider_calls_before_routing_save

# System connectors are installation-owned Global Settings, never NPC profile fields.
global_route_page,_=parse(request('/LorkhanServer/ui/core/global_settings.php'))
global_route_form=next(f for f in global_route_page.forms if f['action'].endswith('/forms/global-settings-save'))
global_route_values=dict(global_route_form['fields'],_csrf=csrf,profile_generation_configuration_id=slot_id,
    relationship_configuration_id=slot_id,change_reason='HTTP global connector ownership')
r=request(global_route_form['action'],'POST',global_route_values); assert r.status==200,(r.status,r.read().decode())
# Global tests read saved, enabled routes; shared connectors are tested only once.
filter_path='/LorkhanServer/manage/api/v1/context-filter-candidates?installation_id='+valid['installation_id']
for filter_kind in ['locations','items','magic','event_types']:
    r=json_request(filter_path+'&kind='+filter_kind); candidates=json.loads(r.read())
    assert r.status==200 and candidates['scan_limit']==5000 and isinstance(candidates['items'],list),(r.status,candidates)
assert json_request(filter_path+'&kind=unknown').status==422
assert json_request('/LorkhanServer/manage/api/v1/context-filter-candidates?installation_id='+str(uuid.uuid4())+'&kind=items').status==404
global_test_path='/LorkhanServer/manage/api/v1/global-connector-tests'
global_test_values=dict(global_route_values,relationship_enabled='1',relationship_update_chance_percent='50',
    oghma_enabled='1',oghma_extractor_enabled='1',oghma_configuration_id=slot_id)
global_test_values.pop('memory_summary_enabled',None)
assert request(global_route_form['action'],'POST',global_test_values).status==200
before_global_test_calls=len(VoiceProvider.llm_requests)
r=json_request(global_test_path+'?installation_id='+valid['installation_id']); global_test_plan=json.loads(r.read())
assert r.status==200 and len(global_test_plan['jobs'])==1 and global_test_plan['jobs'][0]['configuration_id']==slot_id,global_test_plan
global_slots=global_test_plan['groups'][0]['slots']
assert len(global_slots)==4 and sum(slot['status']=='pending' for slot in global_slots)==3 and global_slots[0]['status']=='skipped',global_slots
assert len(VoiceProvider.llm_requests)==before_global_test_calls and not any(key in json.dumps(global_test_plan).lower() for key in ['api_key','credential','endpoint','content'])
global_test_request={'installation_id':valid['installation_id'],'configuration_id':slot_id,'kind':'provider','confirm':'Run tests'}
r=json_request(global_test_path,'POST',global_test_request); assert r.status==401,r.status
r=json_request(global_test_path,'POST',dict(global_test_request,confirm=''),csrf); assert r.status==422,r.status
r=json_request(global_test_path,'POST',dict(global_test_request,configuration_id=str(uuid.uuid4())),csrf); assert r.status==422,r.status
r=json_request(global_test_path+'?installation_id='+str(uuid.uuid4())); assert r.status==404,r.status
r=json_request(global_test_path,'POST',global_test_request,csrf); result=json.loads(r.read()); assert r.status==200 and result['result']['status']=='pass',(r.status,result)
assert len(VoiceProvider.llm_requests)==before_global_test_calls
disabled_test_values=dict(global_test_values,profile_generation_configuration_id='')
disabled_test_values.pop('relationship_enabled',None); disabled_test_values.pop('oghma_extractor_enabled',None)
assert request(global_route_form['action'],'POST',disabled_test_values).status==200
r=json_request(global_test_path+'?installation_id='+valid['installation_id']); disabled_test_plan=json.loads(r.read())
assert disabled_test_plan['jobs']==[] and all(slot['status']=='skipped' for slot in disabled_test_plan['groups'][0]['slots'])
r=json_request(global_test_path,'POST',global_test_request,csrf); result=json.loads(r.read()); assert r.status==422 and result['error']=='connector_test_plan_changed',result
assert request(global_route_form['action'],'POST',global_route_values).status==200
llm_page,body=parse(request('/LorkhanServer/ui/core/llm_connectors.php?selected='+slot_id))
assert 'Connector is in use.' in body,body
r=request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':slot_id}); body=r.read().decode()
assert r.status==422 and 'provider_in_use' in body,(r.status,r.geturl(),body)
profiles_page,_=parse(request('/LorkhanServer/ui/core/npc_master.php?selected='+routing_profile_id))
clear_routing=next(f for f in profiles_page.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==routing_profile_id)
assert not {'profile_generation_configuration_id','relationship_configuration_id','diary_generation_configuration_id'} & {control[2] for control in profiles_page.controls}
global_route_values['profile_generation_configuration_id']=''; global_route_values['relationship_configuration_id']=''
assert request(global_route_form['action'],'POST',global_route_values).status==200
r=request('/LorkhanServer/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':routing_profile_id}); assert r.status==200
provider_export_response=request('/LorkhanServer/manage/exports/providers/'+slot_id+'.json'); provider_export=json.loads(provider_export_response.read().decode())
assert provider_export_response.status==200 and provider_export['schema']=='lorkhan.provider-export.v1' and 'installation_id' not in provider_export and 'endpoint' not in provider_export and 'api_key' not in json.dumps(provider_export).lower()
llm_page,_=parse(request('/LorkhanServer/ui/core/llm_connectors.php?selected='+slot_id))
clone_provider=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-clone') and f['fields'].get('configuration_id')==slot_id)
clone_name=slot_name+' clone'; r=request(clone_provider['action'],'POST',dict(clone_provider['fields'],_csrf=csrf,name=clone_name)); body=r.read().decode()
assert r.status==200 and clone_name in body,(r.status,r.geturl(),body)
clone_provider_id=connector_editor_id(body,clone_name)
r=request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':clone_provider_id}); assert r.status==200
provider_export['name']=slot_name+' imported'; llm_page,_=parse(request('/LorkhanServer/ui/core/llm_connectors.php?import=1'))
import_provider=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-import'))
r=request(import_provider['action'],'POST',dict(import_provider['fields'],_csrf=csrf,installation_id=valid['installation_id'],provider_json=json.dumps(provider_export))); body=r.read().decode()
assert r.status==200 and provider_export['name'] in body,(r.status,r.geturl(),body)
import_provider_id=connector_editor_id(body,provider_export['name'])
r=request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':import_provider_id}); assert r.status==200
# Keep the shared model available for the later player and narrator generation checks.
# Exercise the real adapter with a disposable local HTTP provider, never a paid endpoint.
direct_name='HTTP direct '+uuid.uuid4().hex
direct_values={'_csrf':csrf,'installation_id':valid['installation_id'],'name':direct_name,'driver':'openai-compatible','model':'local-test',
    'endpoint':'http://127.0.0.1:'+str(voice_provider.server_port)+'/llm/chat/completions','credential':'none','timeout_ms':'4000',
    'option_temperature':'0','option_top_p':'0','option_max_completion_tokens':'64','option_stream':'false','option_json_mode':'false'}
r=request('/LorkhanServer/manage/forms/providers','POST',direct_values); body=r.read().decode(); assert r.status==200 and direct_name in body,(r.status,body)
direct_id=connector_editor_id(body,direct_name)
_,direct_editor=parse(request('/LorkhanServer/ui/core/llm_connectors.php?edit='+direct_id))
assert re.search(r'id="llm_option_max_completion_tokens"[^>]*value="64"',direct_editor),direct_editor
# Range companions must never duplicate the submitted override or turn blank defaults into zero.
llm_ranges=re.findall(r'<input type="range"[^>]+>',direct_editor)
assert len(llm_ranges)==8 and all(' name=' not in tag for tag in llm_ranges),llm_ranges
assert re.search(r'id="llm_option_presence_penalty"[^>]*value=""',direct_editor),direct_editor
assert re.search(r'id="llm_option_temperature"[^>]*value="0"',direct_editor),direct_editor
direct_test={'_csrf':csrf,'installation_id':valid['installation_id'],'configuration_id':direct_id}
r=request('/LorkhanServer/manage/forms/provider-test','POST',direct_test); body=r.read().decode(); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
headers,sent=VoiceProvider.llm_requests[-1]
assert 'Authorization' not in headers and sent['temperature']==0 and sent['top_p']==0 and sent['max_completion_tokens']==64 and sent['stream'] is False and 'response_format' not in sent,(headers,sent)
r=request('/LorkhanServer/ui/core/api_keys.php','POST',{'_csrf':csrf,'action':'set','variable':'LORKHAN_LLM_CUSTOM_API_KEY','credential':'local-parity-test-key'}); body=r.read().decode(); assert 'Credential saved.' in body,body
direct_values.update(configuration_id=direct_id,credential='custom',option_stream='true',option_json_mode='true',option_disable_reasoning='true',option_reasoning_model='true',change_reason='Exercise explicit key, streaming, and reasoning cleanup')
r=request('/LorkhanServer/manage/forms/provider-revise','POST',direct_values); assert r.status==200,(r.status,r.read().decode())
r=request('/LorkhanServer/manage/forms/provider-test','POST',direct_test); body=r.read().decode(); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
headers,sent=VoiceProvider.llm_requests[-1]
assert headers.get('Authorization')=='Bearer local-parity-test-key' and sent['stream'] is True and sent['response_format']=={'type':'json_object'} and sent['reasoning']=={'exclude':True,'enabled':False},(headers,sent)
direct_export=json.loads(request('/LorkhanServer/manage/exports/providers/'+direct_id+'.json').read().decode())
assert direct_export['content']['credential']=='none' and direct_export['content']['options']['reasoning_model'] is True and 'local-parity-test-key' not in json.dumps(direct_export),direct_export
direct_export['name']=direct_name+' portable'; direct_export['content']['credential']='custom'
r=request('/LorkhanServer/manage/forms/provider-import','POST',{'_csrf':csrf,'installation_id':valid['installation_id'],'provider_json':json.dumps(direct_export)}); body=r.read().decode(); assert r.status==200,(r.status,body)
portable_id=connector_editor_id(body,direct_export['name'])
r=request('/LorkhanServer/manage/forms/provider-test','POST',dict(direct_test,configuration_id=portable_id)); body=r.read().decode(); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
assert 'Authorization' not in VoiceProvider.llm_requests[-1][0],VoiceProvider.llm_requests[-1][0]
r=request('/LorkhanServer/manage/forms/provider-rollback','POST',dict(direct_test,revision='1')); assert r.status==200,(r.status,r.read().decode())
r=request('/LorkhanServer/manage/forms/provider-test','POST',direct_test); body=r.read().decode(); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
headers,sent=VoiceProvider.llm_requests[-1]; assert 'Authorization' not in headers and sent['stream'] is False and 'response_format' not in sent,(headers,sent)
direct_values.update(model='invalid-output',credential='none',option_stream='false',option_json_mode='false',change_reason='Strict output still required')
r=request('/LorkhanServer/manage/forms/provider-revise','POST',direct_values); assert r.status==200,(r.status,r.read().decode())
r=request('/LorkhanServer/manage/forms/provider-test','POST',direct_test); body=r.read().decode(); assert 'status=tested' not in r.geturl() and 'provider_invalid_output' in body,(r.status,body)
for connector_id in [direct_id,portable_id]:
    r=request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':connector_id}); assert r.status==200
r=request('/LorkhanServer/ui/core/api_keys.php','POST',{'_csrf':csrf,'action':'delete','variable':'LORKHAN_LLM_CUSTOM_API_KEY'}); assert 'Managed credential removed.' in r.read().decode()
prompts,body=parse(request('/LorkhanServer/ui/prompts_manager.php'))
prompt_form=next(f for f in prompts.forms if f['action'].endswith('/forms/prompts'))
mood_keys=['happy','sad','angry','annoyed','scared','surprised','confused','suspicious','playful','flirty','custom']
assert all(('player_mood_prompt_'+key) in prompt_form['fields'] for key in mood_keys)
assert 'name="prompt_format"' not in body and '{CUSTOM_MOOD}' in body
prompt_name='HTTP prompt '+uuid.uuid4().hex
invalid_mood_name=prompt_name+' invalid mood'
invalid_mood_values=dict(prompt_form['fields'],_csrf=csrf,name=invalid_mood_name,content_json='{"instruction":"Rejected mood."}')
invalid_mood_values['player_mood_prompt_happy']='two\nlines'
r=request(prompt_form['action'],'POST',invalid_mood_values); invalid_mood_body=r.read().decode()
assert r.status==422 and 'invalid_player_mood_prompt_happy' in invalid_mood_body and invalid_mood_name not in invalid_mood_body,(r.status,invalid_mood_body)
values=dict(prompt_form['fields'],_csrf=csrf,name=prompt_name,prompt_instruction='Speak like a Morrowind NPC.',content_json='{}')
values['player_mood_prompt_playful']='({PLAYER_NAME} sounds {MOOD}.)'; values['player_mood_prompt_custom']='({PLAYER_NAME} speaks {CUSTOM_MOOD}.)'
r=request(prompt_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and prompt_name in body,(r.status,r.geturl())
created_prompts=Page(); created_prompts.feed(body)
prompt_id=next(f['fields']['configuration_id'] for f in created_prompts.forms if f['action'].endswith('/forms/prompt-clone') and f['fields'].get('name')==prompt_name+' copy')
core_for_prompt=Page(); core_for_prompt.feed(request('/LorkhanServer/ui/core/core_profiles.php?edit='+core_edit.group(1)).read().decode())
profile_prompt=next(f for f in core_for_prompt.forms if f['action'].endswith('/forms/core-profile-save'))
values=dict(profile_prompt['fields'],_csrf=csrf,prompt_configuration_id=prompt_id,change_reason='Assign explicit dialogue prompt')
r=request(profile_prompt['action'],'POST',values); body=r.read().decode(); assert r.status==200 and prompt_name in body,(r.status,r.geturl(),body)
prompts,body=parse(request('/LorkhanServer/ui/prompts_manager.php')); assert '1 explicit profile assignments' in body and 'Assigned prompts cannot be deleted.' in body,body
r=request('/LorkhanServer/manage/forms/configuration-delete','POST',{'_csrf':csrf,'configuration_id':prompt_id,'kind':'prompt'}); body=r.read().decode(); assert r.status==422 and 'prompt_in_use' in body,(r.status,r.geturl(),body)
page,body=parse(request('/LorkhanServer/ui/prompts_manager.php')); revise=next(f for f in page.forms if f['action'].endswith('/forms/configuration-revise') and f['fields'].get('configuration_id')==prompt_id)
assert 'prompt_format' not in revise['fields'] and revise['fields'].get('player_mood_prompt_playful')=='({PLAYER_NAME} sounds {MOOD}.)'
assert '&quot;format&quot;' not in body and '&quot;player_mood_prompts&quot;' not in body,revise['fields']
values=dict(revise['fields'],_csrf=csrf,kind='prompt',custom_prompt='Speak briefly in character.',change_reason='HTTP prompt test')
values['player_mood_prompt_playful']='({PLAYER_NAME} answers in a {MOOD} way.)'
r=request(revise['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'Speak briefly in character.' in body
prompt_export_response=request('/LorkhanServer/manage/exports/prompts/'+prompt_id+'.json'); prompt_export=json.loads(prompt_export_response.read().decode())
assert prompt_export_response.status==200 and prompt_export['schema']=='lorkhan.prompt-export.v1' and 'format' not in prompt_export['content']
assert prompt_export['content']['player_mood_prompts']['playful']=='({PLAYER_NAME} answers in a {MOOD} way.)' and len(prompt_export['content']['player_mood_prompts'])==11
assert 'installation_id' not in prompt_export and 'api_key' not in json.dumps(prompt_export).lower()
r=request(revise['action'],'POST',values); assert r.status==409 and 'revision_conflict' in r.read().decode()
clear_page,_=parse(request('/LorkhanServer/ui/prompts_manager.php'))
clear_form=next(f for f in clear_page.forms if f['action'].endswith('/forms/configuration-revise') and f['fields'].get('configuration_id')==prompt_id)
clear_values=dict(clear_form['fields'],_csrf=csrf,custom_prompt='',default_prompt='Cannot replace the server baseline')
csv_url='/LorkhanServer/ui/prompts_manager.php?export=csv&installation_id='+valid['installation_id']
exported_prompt_rows=list(csv.reader(io.StringIO(request(csv_url).read().decode('utf-8-sig'))))
csv_prompt_key=next(row[0] for row in exported_prompt_rows[1:] if row[1]=='Speak briefly in character.')
r=request(clear_form['action'],'POST',clear_values,accept='application/json'); clear_result=json.loads(r.read().decode())
assert r.status==200 and clear_result['ok'] is True and clear_result['revision']>int(clear_form['fields']['expected_revision']),clear_result
clear_body=request('/LorkhanServer/ui/prompts_manager.php').read().decode()
assert '1 explicit profile assignments' in clear_body and 'Cannot replace the server baseline' not in clear_body
clear_export=json.loads(request('/LorkhanServer/manage/exports/prompts/'+prompt_id+'.json').read().decode())
assert clear_export['content']['custom_prompt'] is None and clear_export['content']['instruction']=='Speak like a Morrowind NPC.'
assert clear_export['content']['player_mood_prompts']==prompt_export['content']['player_mood_prompts']
assert 'prompt_text_editor' in clear_form['fields'] and 'content_json' not in clear_form['fields']
for csv_override in ['CSV replacement after an editor save.','']:
    csv_buffer=io.StringIO(); csv_writer=csv.writer(csv_buffer); csv_writer.writerow(['prompt_key','custom_prompt']); csv_writer.writerow([csv_prompt_key,csv_override])
    r=multipart_request('/LorkhanServer/ui/prompts_manager.php',{'_csrf':csrf,'action':'import_csv','installation_id':valid['installation_id']},'csv_file','custom_prompts.csv','text/csv',csv_buffer.getvalue().encode())
    assert r.status==200 and '1 prompts imported.' in r.read().decode()
    csv_saved=json.loads(request('/LorkhanServer/manage/exports/prompts/'+prompt_id+'.json').read().decode())['content']
    assert csv_saved['instruction']==(csv_override or 'Speak like a Morrowind NPC.') and csv_saved['custom_prompt']==(csv_override or None),csv_saved
prompts,_=parse(request('/LorkhanServer/ui/prompts_manager.php'))
clone_prompt=next(f for f in prompts.forms if f['action'].endswith('/forms/prompt-clone') and f['fields'].get('configuration_id')==prompt_id)
clone_name=prompt_name+' clone'; r=request(clone_prompt['action'],'POST',dict(clone_prompt['fields'],_csrf=csrf,name=clone_name)); body=r.read().decode()
assert r.status==200 and clone_name in body,(r.status,r.geturl(),body)
cloned_prompts=Page(); cloned_prompts.feed(body)
cloned_prompt_id=next(f['fields']['configuration_id'] for f in cloned_prompts.forms if f['action'].endswith('/forms/prompt-clone') and f['fields'].get('name')==clone_name+' copy')
r=request('/LorkhanServer/manage/forms/configuration-delete','POST',{'_csrf':csrf,'configuration_id':cloned_prompt_id,'kind':'prompt'}); assert r.status==200
prompt_export['name']=prompt_name+' imported'; prompt_export['content']['format']='xml'; prompts,_=parse(request('/LorkhanServer/ui/prompts_manager.php'))
import_prompt=next(f for f in prompts.forms if f['action'].endswith('/forms/prompt-import'))
r=request(import_prompt['action'],'POST',dict(import_prompt['fields'],_csrf=csrf,installation_id=valid['installation_id'],prompt_json=json.dumps(prompt_export))); body=r.read().decode()
assert r.status==200 and prompt_export['name'] in body,(r.status,r.geturl(),body)
imported_prompts=Page(); imported_prompts.feed(body)
imported_prompt_id=next(f['fields']['configuration_id'] for f in imported_prompts.forms if f['action'].endswith('/forms/prompt-clone') and f['fields'].get('name')==prompt_export['name']+' copy')
imported_page,imported_body=parse(request('/LorkhanServer/ui/prompts_manager.php'))
imported_prompt_form=next(f for f in imported_page.forms if f['action'].endswith('/forms/configuration-revise') and f['fields'].get('configuration_id')==imported_prompt_id)
assert 'prompt_format' not in imported_prompt_form['fields'] and imported_prompt_form['fields'].get('player_mood_prompt_playful')=='({PLAYER_NAME} answers in a {MOOD} way.)'
assert '&quot;format&quot;' not in imported_body and '&quot;player_mood_prompts&quot;' not in imported_body
r=request('/LorkhanServer/manage/forms/configuration-delete','POST',{'_csrf':csrf,'configuration_id':imported_prompt_id,'kind':'prompt'}); assert r.status==200
core_for_prompt=Page(); core_for_prompt.feed(request('/LorkhanServer/ui/core/core_profiles.php?edit='+core_edit.group(1)).read().decode())
profile_prompt=next(f for f in core_for_prompt.forms if f['action'].endswith('/forms/core-profile-save'))
r=request(profile_prompt['action'],'POST',dict(profile_prompt['fields'],_csrf=csrf,prompt_configuration_id='',change_reason='Remove explicit dialogue prompt')); assert r.status==200
r=request('/LorkhanServer/manage/forms/configuration-delete','POST',{'_csrf':csrf,'configuration_id':prompt_id,'kind':'prompt'}); assert r.status==200
actions,body=parse(request('/LorkhanServer/ui/function_editor.php'))
assert 'data-action-editor' in body and 'Configure available actions exposed to AI prompting and execution' in body and 'Save all changes' in body
editor_path='/LorkhanServer/manage/api/v1/action-policies/editor?'+urllib.parse.urlencode({'installation_id':valid['installation_id']})
r=json_request(editor_path); editor=json.loads(r.read().decode())
assert r.status==200 and len(editor['catalog'])==16 and editor['policies']=={
    'installation_policy':None,'profile_policy':None,'effective_policy':None},editor
assert all(set(action)>=set(['name','tier','description','parameter_schema','result_schema','client_capability',
    'server_owned','terminal_result_required','continuation_capable','display_name','category','sort_order',
    'confirmation_mode','followup_default','followup_actions_supported','cooldown_seconds','available_to_npc',
    'available_to_followers','available_to_narrator','game_function','source','code_name','action_name',
    'return_message','is_activated','parameters_json','metadata','import_version','script_proxy_program']) for action in editor['catalog'])
overrides={}
for action in editor['catalog']:
    metadata=json.loads(json.dumps(action['metadata']))
    metadata['custom_config']={
        'confirmation_required':action['confirmation_mode']=='required',
        'followup_enabled':action['name']=='ai.follow',
        'followup_prompt':'React to the completed OpenMW result.' if action['name']=='ai.follow' else action['metadata']['followup']['prompt'],
        'followup_use_functions_again':False,
    }
    metadata['cooldown_seconds']=0
    overrides[action['name']]={
        'code_name':action['code_name'],
        'action_name':'Follow Player' if action['name']=='ai.follow' else action['action_name'],
        'description':action['description'],
        'return_message':'',
        'available_to_npc':action['available_to_npc'],
        'available_to_followers':action['available_to_followers'],
        'available_to_narrator':action['available_to_narrator'],
        'is_activated':action['name'] in ('inspect.report','ai.follow'),
        'parameters_json':action['parameters_json'],
        'metadata':metadata,
        'game_function':action['game_function'],
        'import_version':action['import_version'],
        'script_proxy_program':action['script_proxy_program'],
    }
payload={'configuration_id':None,'installation_id':valid['installation_id'],'profile_id':None,
    'expected_revision':None,'enabled':True,'max_tier':1,'actions':overrides,'change_reason':'HTTP compact editor create'}
r=json_request('/LorkhanServer/manage/api/v1/action-policies/revisions','POST',payload); assert r.status==401,(r.status,r.read().decode())
r=json_request('/LorkhanServer/manage/api/v1/action-policies/revisions','POST',payload,csrf); saved=json.loads(r.read().decode())
assert r.status==200 and saved['name']=='Action configuration' and saved['current_revision']==1,saved
policy_id=saved['configuration_id']; payload.update(configuration_id=policy_id,expected_revision=2,max_tier=0,
    change_reason='HTTP stale action edit')
r=json_request('/LorkhanServer/manage/api/v1/action-policies/revisions','POST',payload,csrf)
assert r.status==409 and json.loads(r.read().decode())['error']=='revision_conflict'
payload.update(expected_revision=1,change_reason='HTTP labelled action edit')
payload['actions']['inspect.report']['action_name']='Inspect Current Target'
r=json_request('/LorkhanServer/manage/api/v1/action-policies/revisions','POST',payload,csrf); revised=json.loads(r.read().decode())
assert r.status==200 and revised['current_revision']==2 and revised['content']['max_tier']==0
r=json_request(editor_path); editor=json.loads(r.read().decode()); stored=editor['policies']['installation_policy']
assert stored['configuration_id']==policy_id and stored['revision']==2
assert stored['content']['actions']['ai.follow']['metadata']['custom_config']['followup_enabled'] is True
# Resetting the final custom action is an empty override map, not an invalid policy.
reset_actions=dict(payload,expected_revision=2,actions={},change_reason='HTTP reset final action override')
r=json_request('/LorkhanServer/manage/api/v1/action-policies/revisions','POST',reset_actions,csrf)
reset_result=json.loads(r.read()); assert r.status==200 and reset_result['current_revision']==3,reset_result
assert reset_result['content']=={'enabled':True,'max_tier':0},reset_result
r=json_request('/LorkhanServer/manage/api/v1/action-policies/revisions','POST',reset_actions,csrf)
assert r.status==409 and json.loads(r.read())['error']=='revision_conflict'
r=json_request(editor_path); reset_editor=json.loads(r.read())
assert reset_editor['policies']['installation_policy']['revision']==3 and 'actions' not in reset_editor['policies']['effective_policy']['content']
assert stored['content']['actions']['ai.follow']['metadata']['custom_config']['followup_prompt']=='React to the completed OpenMW result.'
assert stored['content']['actions']['inspect.report']['action_name']=='Inspect Current Target'
assert set(stored['content']['actions']['inspect.report'])==set([
    'code_name','action_name','description','return_message','available_to_npc','available_to_followers',
    'available_to_narrator','is_activated','parameters_json','metadata','game_function','import_version',
    'script_proxy_program'])
r=request('/LorkhanServer/manage/forms/configuration-delete','POST',{'_csrf':csrf,'configuration_id':policy_id,'kind':'action_policy'}); assert r.status==200
global_generation_page,_=parse(request('/LorkhanServer/ui/core/global_settings.php'))
global_generation_form=next(f for f in global_generation_page.forms if f['action'].endswith('/forms/global-settings-save'))
r=request(global_generation_form['action'],'POST',dict(global_generation_form['fields'],_csrf=csrf,profile_generation_configuration_id=slot_id,change_reason='HTTP special profile generation route')); assert r.status==200,(r.status,r.read().decode())
player,text=parse(request('/LorkhanServer/ui/core/player_management.php'))
assert 'profile_generation_configuration_id' not in {control[2] for control in player.controls} and 'Global Settings' in text
create_player=next((f for f in player.forms if f['action'].endswith('/forms/player-profile-create')),None)
if create_player is not None:
    player_name='HTTP player '+uuid.uuid4().hex
    values=dict(create_player['fields'],_csrf=csrf,name=player_name,biography='Arrived in Morrowind by prison ship.',personality='Curious',goals='Find Fargoth.')
    r=request(create_player['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/player_management.php?status=saved'),(r.status,r.geturl(),body)
    match=re.search(r'name="profile_id" value="([0-9a-f-]{36})"',body); assert match,body
    assert 'observed player messages' in body
    player_page,_=parse(request('/LorkhanServer/ui/core/player_management.php'))
    generate_style=next((f for f in player_page.forms if f['action'].endswith('/forms/player-speech-style-generate')),None)
    if generate_style is not None:
        r=request(generate_style['action'],'POST',dict(generate_style['fields'],_csrf=csrf)); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/player_management.php?status=saved'),(r.status,r.geturl(),body)
    player_id=match.group(1)
    edit_page,body=parse(request('/LorkhanServer/ui/core/player_management.php'))
    revise=next(f for f in edit_page.forms if f['action'].endswith('/forms/player-profile-revise'))
    values=dict(revise['fields'],_csrf=csrf,profile_id=player_id,biography='Arrived in Morrowind by prison ship.',biography_known_by_all='0',personality='Patient',goals='Find Fargoth.',
        diary_enabled='1',auto_diary_enabled='1',auto_diary_wait_enabled='1',diary_interval_seconds='90',change_reason='HTTP parity test')
    r=request(revise['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'Player profile saved.' in body and 'Patient' in body,(r.status,r.geturl())
    edit_page,body=parse(request('/LorkhanServer/ui/core/player_management.php'))
    revise=next(f for f in edit_page.forms if f['action'].endswith('/forms/player-profile-revise'))
    assert revise['fields'].get('biography_known_by_all')=='0' and 'id="player-biography-known-by-all"' in body
    assert revise['fields'].get('diary_enabled')=='1' and revise['fields'].get('auto_diary_enabled')=='1'
    assert revise['fields'].get('auto_diary_wait_enabled')=='1' and revise['fields'].get('diary_interval_seconds')=='90'
    player_import=next(f for f in edit_page.forms if f['action'].endswith('/forms/player-profile-settings-import'))
    player_preset_response=request('/LorkhanServer/manage/exports/player-profile-settings/'+player_id+'.json')
    player_preset=json.loads(player_preset_response.read().decode())
    assert player_preset_response.status==200 and sorted(player_preset)==['exported_at','schema','settings']
    assert player_preset['schema']=='lorkhan.player-profile-settings.v2' and player_preset['settings']['personality']=='Patient'
    assert sorted(player_preset['settings'])==['appearance','biography','biography_known_by_all','goals','notes','personality','speech_style']
    assert player_preset['settings']['biography_known_by_all'] is False
    assert not any(key in player_preset for key in ['name','actor_identity','installation_id','profile_id','revision','routing','latest_context'])
    invalid_player_preset=dict(player_preset,unexpected='rejected')
    r=request(player_import['action'],'POST',dict(player_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(invalid_player_preset))); invalid_body=r.read().decode()
    assert r.status==422 and 'invalid_player_profile_settings_preset' in invalid_body,(r.status,invalid_body)
    secret_player_preset=dict(player_preset,settings=dict(player_preset['settings'],api_key='never'))
    r=request(player_import['action'],'POST',dict(player_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(secret_player_preset))); invalid_body=r.read().decode()
    assert r.status==422 and 'invalid_player_profile_settings_preset' in invalid_body,(r.status,invalid_body)
    invalid_visibility_preset=dict(player_preset,settings=dict(player_preset['settings'],biography_known_by_all='false'))
    r=request(player_import['action'],'POST',dict(player_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(invalid_visibility_preset))); invalid_body=r.read().decode()
    assert r.status==422 and 'invalid_player_profile_settings_preset' in invalid_body,(r.status,invalid_body)
    legacy_player_preset=dict(player_preset,schema='lorkhan.player-profile-settings.v1',settings=dict(player_preset['settings']))
    legacy_player_preset['settings'].pop('biography_known_by_all'); legacy_player_preset['settings']['personality']='Legacy portable player'
    r=request(player_import['action'],'POST',dict(player_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(legacy_player_preset))); legacy_body=r.read().decode()
    assert r.status==200 and 'status=imported' in r.geturl() and 'Legacy portable player' in legacy_body,(r.status,r.geturl(),legacy_body)
    legacy_imported=json.loads(request('/LorkhanServer/manage/exports/player-profile-settings/'+player_id+'.json').read().decode())
    assert legacy_imported['schema']=='lorkhan.player-profile-settings.v2' and legacy_imported['settings']['biography_known_by_all'] is False
    player_preset['settings']['personality']='Portable and patient'
    player_preset['settings']['goals']=''
    player_preset['settings']['biography_known_by_all']=True
    r=request(player_import['action'],'POST',dict(player_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(player_preset))); imported_body=r.read().decode()
    assert r.status==200 and 'status=imported' in r.geturl() and 'Portable player settings imported as a new player profile revision.' in imported_body and 'Portable and patient' in imported_body,(r.status,r.geturl(),imported_body)
    imported_player=json.loads(request('/LorkhanServer/manage/exports/player-profile-settings/'+player_id+'.json').read().decode())
    assert imported_player['settings']['goals']=='' and imported_player['settings']['personality']=='Portable and patient' and imported_player['settings']['biography_known_by_all'] is True
    imported_player_page,_=parse(request('/LorkhanServer/ui/core/player_management.php'))
    imported_player_form=next(f for f in imported_player_page.forms if f['action'].endswith('/forms/player-profile-revise'))
    assert 'profile_generation_configuration_id' not in imported_player_form['fields']
    r=request('/LorkhanServer/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':player_id}); assert r.status==200
else:
    assert any(f['action'].endswith('/forms/player-profile-revise') for f in player.forms),'existing player profile is not editable'
narrator_page,body=parse(request('/LorkhanServer/ui/narrator_management.php'))
assert 'profile_generation_configuration_id' not in {control[2] for control in narrator_page.controls} and 'Global Settings' in body
# Inline narrator prompts and Prompts Manager share one revisioned document, even before a narrator exists.
event_form=next(f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-prompt-save') and f['fields'].get('prompt_key')=='narrator_welcome_prompt')
event_values=dict(event_form['fields'],custom_prompt='Welcome {PLAYER_NAME}. HTTP shared narrator prompt.')
r=request(event_form['action'],'POST',dict(event_values,_csrf='invalid'),accept='application/json'); assert r.status==401,r.status
r=request(event_form['action'],'POST',dict(event_values,_csrf=csrf)); event_saved=json.loads(r.read().decode()); assert r.status==200 and event_saved['ok'],event_saved
r=request(event_form['action'],'POST',dict(event_values,_csrf=csrf)); assert r.status==409
prompt_page,prompt_body=parse(request('/LorkhanServer/ui/prompts_manager.php?installation_id='+valid['installation_id']))
shared_form=next(f for f in prompt_page.forms if f['action'].endswith('/forms/narrator-prompt-save') and f['fields'].get('prompt_key')=='narrator_welcome_prompt')
assert event_values['custom_prompt'] in prompt_body and int(shared_form['fields']['expected_revision'])==event_saved['revision']
r=request(shared_form['action'],'POST',dict(shared_form['fields'],_csrf=csrf,custom_prompt='')); assert r.status==200
reset_page,reset_body=parse(request('/LorkhanServer/ui/narrator_management.php'))
reset_form=next(f for f in reset_page.forms if f['action'].endswith('/forms/narrator-prompt-save') and f['fields'].get('prompt_key')=='narrator_welcome_prompt')
assert event_values['custom_prompt'] not in reset_body and int(reset_form['fields']['expected_revision'])==event_saved['revision']+1
r=request(reset_form['action'],'POST',dict(reset_form['fields'],_csrf=csrf,custom_prompt='',prompt_key='not_a_narrator_prompt')); assert r.status==422,r.status
event_csv=b'prompt_key,custom_prompt\nnarrator_welcome_prompt,CSV narrator welcome.\n'
r=multipart_request('/LorkhanServer/ui/prompts_manager.php',{'_csrf':csrf,'action':'import_csv','installation_id':valid['installation_id']},'csv_file','custom_prompts.csv','text/csv',event_csv)
assert r.status==200 and '1 prompts imported.' in r.read().decode()
assert 'CSV narrator welcome.' in request('/LorkhanServer/ui/narrator_management.php').read().decode()
assert 'narrator_welcome_prompt' not in request('/LorkhanServer/ui/core/core_profiles.php').read().decode()
create_narrator=next((f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-profile-create')),None)
if create_narrator is not None:
    narrator_name='HTTP narrator '+uuid.uuid4().hex
    values=dict(create_narrator['fields'],_csrf=csrf,name=narrator_name,enabled='1',inline_narration_mode='Narrator',biography='Frames the Nerevarine journey.',personality='Observant',speech_style='Concise sensory prose.',goals='Describe scenes.',notes='HTTP parity test')
    r=request(create_narrator['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/config_hub.php?tab=narration-page&status=saved'),(r.status,r.geturl(),body)
    narrator_page,body=parse(request('/LorkhanServer/ui/narrator_management.php'))
    assert narrator_name in body and 'Generate narrator profile with AI' in body and 'preserving narrator enablement and voice routing' in body,body
narrator_page,body=parse(request('/LorkhanServer/ui/narrator_management.php'))
generate_narrator=next((f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-profile-generate')),None)
assert generate_narrator is not None and generate_narrator['fields'].get('profile_id'),'narrator profile generation control is missing'
r=request(generate_narrator['action'],'POST',dict(generate_narrator['fields'],_csrf=csrf)); assert r.status==200 and r.geturl().endswith('/ui/core/config_hub.php?tab=narration-page&status=saved'),(r.status,r.geturl())
narrator_revise=next(f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-profile-revise'))
narrator_route_values=dict(narrator_revise['fields'],_csrf=csrf,inline_narration_mode='Narrator',diary_enabled='1',
    auto_diary_enabled='1',auto_diary_wait_enabled='1',diary_interval_seconds='90',change_reason='HTTP narrator portability route')
r=request(narrator_revise['action'],'POST',narrator_route_values); narrator_route_body=r.read().decode(); assert r.status==200,(r.status,r.geturl(),narrator_route_body)
narrator_page,body=parse(request('/LorkhanServer/ui/narrator_management.php'))
saved_narrator_form=next(f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-profile-revise'))
assert saved_narrator_form['fields'].get('diary_enabled')=='1' and saved_narrator_form['fields'].get('auto_diary_enabled')=='1'
assert saved_narrator_form['fields'].get('auto_diary_wait_enabled')=='1' and saved_narrator_form['fields'].get('diary_interval_seconds')=='90'
generate_narrator=next(f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-profile-generate'))
narrator_import=next(f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-profile-settings-import'))
narrator_id=generate_narrator['fields']['profile_id']
narrator_preset_response=request('/LorkhanServer/manage/exports/narrator-profile-settings/'+narrator_id+'.json')
narrator_preset=json.loads(narrator_preset_response.read().decode())
assert narrator_preset_response.status==200 and sorted(narrator_preset)==['exported_at','schema','settings']
assert narrator_preset['schema']=='lorkhan.narrator-profile-settings.v2' and sorted(narrator_preset['settings'])==['biography','book_events','bored_chance_percent','bored_events','context_visibility','core','enabled','goals','inline_narration_mode','narration_filters','notes','personality','prompt_head','quest_chance_percent','quest_cooldown_minutes','quest_events','random_chance_percent','random_cooldown_rounds','random_events','speech_style','voice','welcome_cooldown_minutes','welcome_events']
assert not any(key in narrator_preset for key in ['name','actor_identity','installation_id','profile_id','revision','routing'])
invalid_narrator_preset=dict(narrator_preset,unexpected='rejected')
r=request(narrator_import['action'],'POST',dict(narrator_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(invalid_narrator_preset))); invalid_body=r.read().decode()
assert r.status==422 and 'invalid_narrator_profile_settings_preset' in invalid_body,(r.status,invalid_body)
narrator_preset['settings']['personality']='Portable narrator persona'
narrator_preset['settings']['inline_narration_mode']='Text Only'
r=request(narrator_import['action'],'POST',dict(narrator_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(narrator_preset))); imported_body=r.read().decode()
assert r.status==200 and 'status=imported' in r.geturl(),(r.status,r.geturl(),imported_body)
imported_narrator_page,imported_narrator_body=parse(request('/LorkhanServer/ui/narrator_management.php?installation_id='+valid['installation_id']+'&status=imported'))
assert 'Portable narrator settings imported as a new narrator profile revision.' in imported_narrator_body and 'Portable narrator persona' in imported_narrator_body,imported_narrator_body
imported_narrator_form=next(f for f in imported_narrator_page.forms if f['action'].endswith('/forms/narrator-profile-revise'))
assert '<option selected>Text Only</option>' in imported_narrator_body and 'profile_generation_configuration_id' not in imported_narrator_form['fields'],imported_narrator_form['fields']
global_generation_page,_=parse(request('/LorkhanServer/ui/core/global_settings.php'))
global_generation_form=next(f for f in global_generation_page.forms if f['action'].endswith('/forms/global-settings-save'))
r=request(global_generation_form['action'],'POST',dict(global_generation_form['fields'],_csrf=csrf,profile_generation_configuration_id='',change_reason='HTTP special profile generation cleanup')); assert r.status==200,(r.status,r.read().decode())
r=request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':slot_id}); body=r.read().decode()
assert r.status==422 and 'provider_in_use' in body,(r.status,r.geturl(),body)
r=request('/LorkhanServer/manage/login'); assert r.status==200 and r.geturl().endswith('/ui/home.php')
# Quickstart uses saved connectors, preserves profiles, and rejects stale submissions atomically.
quickstart,quickstart_body=parse(request('/LorkhanServer/ui/quickstart.php?installation_id='+valid['installation_id']))
if 'name="player_revision"' not in quickstart_body:
    assert 'No player profile is configured.' in quickstart_body
    player_setup,_=parse(request('/LorkhanServer/ui/core/player_management.php?installation_id='+valid['installation_id']))
    create=next(f for f in player_setup.forms if f['action'].endswith('/forms/player-profile-create'))
    r=request(create['action'],'POST',dict(create['fields'],_csrf=csrf,name='Quickstart Original Player',biography='Preserve this biography.'))
    r.read(); assert r.status==200
    quickstart,quickstart_body=parse(request('/LorkhanServer/ui/quickstart.php?installation_id='+valid['installation_id']))
quickstart_form=next(f for f in quickstart.forms if f['action'].endswith('/forms/quickstart-save'))
quickstart_values=dict(quickstart_form['fields'],_csrf=csrf)
assert quickstart_values.get('core_profile_id'),quickstart_values
assert all(marker in quickstart_body for marker in ['Quickstart Menu','>Player</h2>','>TTS Service</h2>','>STT Service</h2>','LLM Connectors Note','Save and Continue'])
assert quickstart_values.get('player_revision'), 'Quickstart must use the existing player revision'
quickstart_values['player_name']='Quickstart Test Player'
model_choices=re.search(r'<select name="llm_configuration_id"[^>]*>(.*?)</select>',quickstart_body,re.S)
model_choice=re.search(r'<option value="([0-9a-f-]{36})"',model_choices.group(1)); assert model_choice,quickstart_body
for field in ['llm_configuration_id','llm_fast_configuration_id','llm_powerful_configuration_id','llm_experimental_configuration_id']:
    quickstart_values[field]=model_choice.group(1)
r=request(quickstart_form['action'],'POST',quickstart_values); saved_body=r.read().decode()
assert r.status==200 and 'Quickstart settings saved.' in saved_body,(r.status,saved_body)
r=request(quickstart_form['action'],'POST',quickstart_values); stale_body=r.read().decode()
assert r.status in (409,422) and 'revision' in stale_body,(r.status,stale_body)
# A stale player edit rolls back the Core Profile revision and connector selections as well.
fresh,body=parse(request('/LorkhanServer/ui/quickstart.php?installation_id='+valid['installation_id']))
fresh_form=next(f for f in fresh.forms if f['action'].endswith('/forms/quickstart-save'))
fresh_values=dict(fresh_form['fields'],_csrf=csrf)
assert fresh_values['player_name']=='Quickstart Test Player'
bad=dict(fresh_values,player_name='Must Not Be Saved',player_revision='1')
r=request(fresh_form['action'],'POST',bad); body=r.read().decode()
assert r.status in (409,422) and 'revision' in body,(r.status,body)
after,body=parse(request('/LorkhanServer/ui/quickstart.php?installation_id='+valid['installation_id']))
after_values=next(f['fields'] for f in after.forms if f['action'].endswith('/forms/quickstart-save'))
assert after_values['player_name']==fresh_values['player_name'] and after_values['base_revision']==fresh_values['base_revision']
r=request(fresh_form['action'],'POST',dict(fresh_values,player_name='')); body=r.read().decode()
assert r.status==422,(r.status,body)
# Copy-to-all is a confirmed, CSRF-protected exact-field write; stale sources cannot overwrite newer work.
copy_body=request('/LorkhanServer/ui/core/core_profiles.php?edit='+core_edit.group(1)).read().decode()
copy_revision=int(re.search(r'data-profile-copy-revision="(\d+)"',copy_body).group(1))
assert len(re.findall(r'data-profile-copy-setting="',copy_body))==8
copy_path='/LorkhanServer/manage/api/v1/core-profile-copy-setting'
copy_values={'core_profile_id':core_edit.group(1),'revision':copy_revision,'setting':'response.max_words','value':37,'confirm':'Copy to all'}
assert json_request(copy_path,'POST',copy_values).status==401
assert json_request(copy_path,'POST',dict(copy_values,confirm=''),csrf).status==422
assert json_request(copy_path,'POST',dict(copy_values,setting='routing.llm_configuration_id'),csrf).status==422
assert json_request(copy_path,'POST',dict(copy_values,value='37'),csrf).status==422
assert json_request(copy_path,'POST',dict(copy_values,core_profile_id=str(uuid.uuid4())),csrf).status==404
r=json_request(copy_path,'POST',copy_values,csrf); copied=json.loads(r.read())
assert r.status==200 and copied['profiles_updated']>=1 and copied['profiles_total']>=copied['profiles_updated'],(r.status,copied)
assert json_request(copy_path,'POST',copy_values,csrf).status==409
copy_values['revision']=copied['revision']
r=json_request(copy_path,'POST',copy_values,csrf); repeated=json.loads(r.read())
assert r.status==200 and repeated['profiles_updated']==0 and repeated['revision']==copied['revision'],repeated
# STT tests use the owned fixed sample, not a TTS call, and expose only bounded results.
# Earlier TTS checks deliberately exhaust their browser's shared speech-test budget.
jar.clear()
request('/LorkhanServer/ui/home.php').read()
csrf=next(c.value for c in jar if c.name=='lorkhan_csrf')
r=json_request('/LorkhanServer/manage/api/v1/stt-providers','POST',{'installation_id':valid['installation_id'],'name':'HTTP STT test','content':{'driver':'localwhisper','endpoint':'http://'+provider_host+':'+str(voice_provider.server_port)+'/stt-test','model':'whisper-1','language':'en','timeout_ms':30000,'options':{}}},csrf)
created_stt=json.loads(r.read()); assert r.status==201,(r.status,created_stt)
stt_page,stt_body=parse(request('/LorkhanServer/ui/core/stt_connectors.php?installation_id='+valid['installation_id']+'&driver=localwhisper'))
stt_form=next(f for f in stt_page.forms if f['action'].endswith('/forms/connector-revise'))
stt_values=dict(stt_form['fields'],_csrf=csrf,endpoint='http://'+provider_host+':'+str(voice_provider.server_port)+'/stt-test',options_json='{}')
r=request(stt_form['action'],'POST',stt_values); r.read(); assert r.status==200,r.status
stt_path='/LorkhanServer/manage/api/v1/stt-connector-tests'
stt_request={'installation_id':valid['installation_id'],'configuration_id':stt_values['configuration_id']}
assert json_request(stt_path,'POST',stt_request).status==401
assert json_request(stt_path,'POST',dict(stt_request,text='arbitrary audio'),csrf).status==422
assert json_request(stt_path,'POST',dict(stt_request,installation_id=str(uuid.uuid4())),csrf).status==422
assert json_request(stt_path,'POST',dict(stt_request,configuration_id=tts_id),csrf).status==422
assert json_request(stt_path,'POST',dict(stt_request,configuration_id=str(uuid.uuid4())),csrf).status==404
speech_count=len(VoiceProvider.speech_requests)
r=json_request(stt_path,'POST',stt_request,csrf); stt_result=json.loads(r.read())
assert r.status==200 and stt_result['transcript']==VoiceProvider.transcription_text and stt_result['similarity_percent']==100 and stt_result['driver']=='localwhisper' and stt_result['elapsed_ms']>=0,(r.status,stt_result)
assert len(VoiceProvider.transcription_requests)==1 and (repository_root/'ui/tests/assets/stt-test.wav').read_bytes() in VoiceProvider.transcription_requests[0]
assert len(VoiceProvider.speech_requests)==speech_count
VoiceProvider.transcription_text=''
r=json_request(stt_path,'POST',stt_request,csrf); assert r.status==502 and json.loads(r.read())=={'error':'stt_test_failed'}
# Only active driver fields submit, explicit False stays false, and badges resolve server-side.
for driver in ['none','localwhisper','parakeet','whisper','azure','deepgram','gemini','inworld']:
    page,body=parse(request('/LorkhanServer/ui/core/stt_connectors.php?installation_id='+valid['installation_id']+'&driver='+driver))
    fields=next(f['fields'] for f in page.forms if f['action'].endswith('/forms/connector-revise'))
    assert fields['driver']==driver and ('option__translate' in fields)==(driver=='whisper') and ('option__file_field' in fields)==(driver=='localwhisper'),(driver,list(fields))
    ids=re.findall(r'\bid="([^"]+)"',body); assert len(ids)==len(set(ids)),driver
for option,expected in [('0',False),('1',True)]:
    r=request(stt_form['action'],'POST',dict(stt_values,driver='whisper',option__translate=option,credential='none')); r.read(); assert r.status==200,r.status
    exported=json.loads(request('/LorkhanServer/manage/exports/connectors/'+stt_values['configuration_id']+'.json').read())
    assert exported['content']['options']['translate'] is expected
r=request(stt_form['action'],'POST',dict(stt_values,credential='NOT_A_CREDENTIAL')); r.read(); assert r.status==422,r.status
r=request('/LorkhanServer/ui/core/api_keys.php','POST',{'_csrf':csrf,'add_custom':'1','custom_name':'STT_HTTP','custom_credential':'fixture-stt-key'}); body=r.read().decode(); assert r.status==200 and 'fixture-stt-key' not in body
VoiceProvider.transcription_text='A short mock transcript.'
for badge,auth in [('LORKHAN_CUSTOM_STT_HTTP_API_KEY','Bearer fixture-stt-key'),('none','')]:
    r=request(stt_form['action'],'POST',dict(stt_values,credential=badge)); r.read(); assert r.status==200,r.status
    r=json_request(stt_path,'POST',stt_request,csrf); result=json.loads(r.read()); assert r.status==200,(r.status,result)
    assert VoiceProvider.transcription_auth[-1]==auth
    exported=json.loads(request('/LorkhanServer/manage/exports/connectors/'+stt_values['configuration_id']+'.json').read())
    assert exported['content']['credential']=='none' and 'fixture-stt-key' not in json.dumps(exported)
exported['name']='HTTP imported STT badge'; exported['content']['credential']='LORKHAN_CUSTOM_STT_HTTP_API_KEY'
r=request('/LorkhanServer/manage/forms/connector-import','POST',{'_csrf':csrf,'installation_id':valid['installation_id'],'kind':'stt_provider','connector_json':json.dumps(exported)}); r.read(); assert r.status==200,r.status
stt_records=json.loads(json_request('/LorkhanServer/manage/api/v1/stt-providers?installation_id='+valid['installation_id']).read())['items']
imported_stt=next(row for row in stt_records if row['name']=='HTTP imported STT badge')['content']
assert (json.loads(imported_stt) if isinstance(imported_stt,str) else imported_stt)['credential']=='none'
r=request(stt_form['action'],'POST',dict(stt_values,credential='none',options_json=json.dumps({'credential':'fixture-stt-key'}))); r.read(); assert r.status==422,r.status
# Names are ordinary editable labels; a rename and settings revision succeed or fail together.
for kind,connector_id,editor,export_path,action in [
    ('provider',slot_id,'llm_connectors.php','providers','provider-revise'),
    ('tts_provider',tts_id,'tts_connectors.php','connectors','connector-revise'),
    ('stt_provider',stt_values['configuration_id'],'stt_connectors.php','connectors','connector-revise'),
]:
    if kind=='stt_provider':
        r=json_request('/LorkhanServer/manage/api/v1/connector-selections','POST',{'installation_id':valid['installation_id'],'kind':kind,'configuration_id':connector_id},csrf); r.read(); assert r.status==200,r.status
        active_stt,_=parse(request('/LorkhanServer/ui/core/stt_connectors.php?installation_id='+valid['installation_id']))
        connector_id=next(f['fields']['configuration_id'] for f in active_stt.forms if f['action'].endswith('/forms/connector-revise'))
    before=json.loads(request('/LorkhanServer/manage/exports/'+export_path+'/'+connector_id+'.json').read())
    renamed='Renamed '+kind+' '+uuid.uuid4().hex
    content=before['content']; rename_values=dict(content,_csrf=csrf,name=renamed,kind=kind,configuration_id=connector_id,change_reason='HTTP connector rename')
    rename_values['options_json']=json.dumps(rename_values.pop('options',{}))
    rename_path='/LorkhanServer/manage/forms/'+action
    denied=dict(rename_values);denied.pop('_csrf')
    denied_response=request(rename_path,'POST',denied); denied_response.read()
    assert denied_response.geturl().endswith('/ui/home.php')
    assert json.loads(request('/LorkhanServer/manage/exports/'+export_path+'/'+connector_id+'.json').read())['name']==before['name']
    r=request(rename_path,'POST',rename_values); body=r.read().decode(); assert r.status==200,(kind,r.status)
    after=json.loads(request('/LorkhanServer/manage/exports/'+export_path+'/'+connector_id+'.json').read())
    assert after['name']==renamed and after['content']==before['content'],(kind,after)
    editor_body=request('/LorkhanServer/ui/core/'+editor+'?selected='+connector_id+'&installation_id='+valid['installation_id']).read().decode()
    assert renamed in editor_body and re.search(r'<input[^>]*name="name"[^>]*value="'+re.escape(renamed)+'"',editor_body),kind
    for invalid_name in ['', 'x'*129]:
        r=request(rename_path,'POST',dict(rename_values,name=invalid_name)); r.read(); assert r.status==422,(kind,r.status)
    if kind=='stt_provider':
        collision_name=stt_values['name'] if connector_id!=stt_values['configuration_id'] else 'HTTP imported STT badge'
        r=request(rename_path,'POST',dict(rename_values,name=collision_name,model='must-not-save')); error=r.read().decode()
        assert r.status==422 and 'connector_name_in_use' in error,(r.status,error[:100])
    unchanged=json.loads(request('/LorkhanServer/manage/exports/'+export_path+'/'+connector_id+'.json').read())
    assert unchanged['name']==after['name'] and unchanged['content']==after['content'],kind
r=request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':slot_id}); assert r.status==422 and 'provider_in_use' in r.read().decode()
# Quickstart keys use fixed server-owned names, CSRF, bounded inputs and status-only responses.
key_path='/LorkhanServer/manage/api/v1/quickstart-key'
dummy_key='quickstart-isolated-test-'+uuid.uuid4().hex
assert json_request(key_path,'POST',{'provider':'openrouter','credential':dummy_key}).status==401
for payload in [
    {'provider':'unknown','credential':dummy_key},
    {'provider':'openrouter','credential':''},
    {'provider':'openrouter','credential':'x'*8193},
    {'provider':'openrouter','credential':'bad\nheader'},
    {'provider':'openrouter','credential':[]},
    {'provider':'openrouter','credential':dummy_key,'variable':'LORKHAN_CUSTOM_INJECTED_API_KEY'},
]:
    r=json_request(key_path,'POST',payload,csrf); error=r.read().decode()
    assert r.status==422 and dummy_key not in error,(r.status,error)
for provider in ['openrouter','deepgram']:
    r=json_request(key_path,'POST',{'provider':provider,'credential':dummy_key+'-'+provider},csrf)
    assert r.status==200 and json.loads(r.read())=={'saved':True}
key_page,key_body=parse(request('/LorkhanServer/ui/quickstart.php?installation_id='+valid['installation_id']))
assert dummy_key not in key_body and 'data-key-endpoint="'+key_path+'"' in key_body
for provider in ['openrouter','deepgram']:
    input_tag=re.search(r'<input[^>]*id="qs-'+provider+r'-key"[^>]*>',key_body).group(0)
    assert 'Configured - leave blank to keep' in input_tag and 'value=' not in input_tag and ' name=' not in input_tag,input_tag
    assert 'data-quick-key="'+provider+'"' in key_body
assert json_request(key_path).status==404
# API Keys card mutations return metadata only; malformed tests never contact a provider.
badge_path='/LorkhanServer/ui/core/api_keys.php'
ajax='application/json'
r=request(badge_path,'POST',{'action':'set','variable':'LORKHAN_LLM_API_KEY','credential':dummy_key},accept=ajax)
assert r.status==401 and dummy_key not in r.read().decode()
for values in [
    {'action':'set','variable':'UNKNOWN','credential':dummy_key},
    {'action':'set','variable':'LORKHAN_LLM_API_KEY','credential[]':dummy_key},
    {'test_key':'unknown'},
    {'test_key':'LORKHAN_LLM_API_KEY','credentials[LORKHAN_LLM_API_KEY]':'invalid\nheader'},
]:
    r=request(badge_path,'POST',dict(values,_csrf=csrf),accept=ajax); result=r.read().decode()
    assert r.status==422 and json.loads(result)['ok'] is False and dummy_key not in result,(r.status,result)
custom_name='CARD_'+uuid.uuid4().hex[:12].upper(); custom_variable='LORKHAN_CUSTOM_'+custom_name+'_API_KEY'
custom_values={'_csrf':csrf,'add_custom':'1','custom_name':custom_name,'custom_credential':dummy_key}
r=request(badge_path,'POST',custom_values,accept=ajax); result=r.read().decode()
assert r.status==200 and json.loads(result)['variable']==custom_variable and dummy_key not in result
r=request(badge_path,'POST',custom_values,accept=ajax); result=r.read().decode()
assert r.status==422 and 'already exists' in json.loads(result)['message'] and dummy_key not in result
r=request(badge_path,'POST',{'_csrf':csrf,'action':'set','variable':custom_variable,'credential':dummy_key+'-replacement'},accept=ajax)
assert r.status==200 and json.loads(r.read())['ok'] is True
card_page,card_body=parse(request(badge_path))
assert 'custom-card has-key' in card_body and 'data-variable="'+custom_variable+'"' in card_body
assert 'Preset Keys (Saves Automatically)' in card_body and 'id="apikey-test-dialog"' in card_body and dummy_key not in card_body
r=request(badge_path,'POST',{'_csrf':csrf,'delete_custom':custom_variable},accept=ajax)
assert r.status==200 and json.loads(r.read())['ok'] is True
_,card_body=parse(request(badge_path)); assert custom_variable not in card_body
print('browser-like management HTTP forms passed')
