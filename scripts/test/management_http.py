#!/usr/bin/env python3
import atexit, csv, html.parser, http.cookiejar, http.server, io, json, pathlib, re, subprocess, sys, threading, urllib.error, urllib.parse, urllib.request, uuid, zipfile

base=sys.argv[1].rstrip('/')
provider_host=sys.argv[2] if len(sys.argv)>2 else '127.0.0.1'
jar=http.cookiejar.CookieJar()
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

class VoiceProvider(http.server.BaseHTTPRequestHandler):
    uploads=[]
    upload_status=200
    llm_requests=[]
    embedding_requests=[]
    speech_requests=[]
    openai_speech_requests=[]
    transcription_requests=[]
    transcription_auth=[]
    transcription_text='Ash drifts across the quiet road. A traveler stops at the inn, warms by the fire, and asks the keeper for a room until morning.'
    samples=b'\x00'*160
    silence=(b'RIFF'+(36+len(samples)).to_bytes(4,'little')+b'WAVEfmt '+(16).to_bytes(4,'little')+(1).to_bytes(2,'little')+(1).to_bytes(2,'little')
             +(16000).to_bytes(4,'little')+(32000).to_bytes(4,'little')+(2).to_bytes(2,'little')+(16).to_bytes(2,'little')+b'data'+len(samples).to_bytes(4,'little')+samples)
    omni_speakers=None
    deletes=[]
    delete_status=204
    minime_status=200
    def do_GET(self):
        if self.path=='/':
            self.send_response(self.minime_status); self.send_header('Content-Length','0'); self.end_headers(); return
        if self.path=='/voice_libraries':
            payload=json.dumps([{'id':'en','name':'English'},{'id':'fr','name':'French'}]).encode()
            self.send_response(200); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(payload))); self.end_headers(); self.wfile.write(payload); return
        if self.path.startswith('/speakers_list'):
            speakers=self.omni_speakers if self.path.startswith('/speakers_list_extended') and self.omni_speakers is not None else ['MockProviderVoice']
            payload=json.dumps({'speakers':speakers}).encode()
            self.send_response(200); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(payload))); self.end_headers(); self.wfile.write(payload); return
        self.send_error(404)
    def do_DELETE(self):
        type(self).deletes.append(self.path)
        if self.delete_status==204:
            name=urllib.parse.unquote(urllib.parse.urlparse(self.path).path.rsplit('/',1)[-1])
            type(self).omni_speakers=[row for row in self.omni_speakers if row.get('name')!=name]
        self.send_response(self.delete_status); self.end_headers()
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
            if body['model']=='prefill-continuation':
                assert body['messages'][-1]=={'role':'assistant','content':'{"utterances":'},body
                content=content[len('{"utterances":'):]
            if body['model']=='structured-fixture':
                fixture=json.loads(body['messages'][1]['content'])
                content=json.dumps(fixture['fixture_response'],separators=(',',':'))
                prefix=body['messages'][-1]['content']
                assert body['messages'][-1]['role']=='assistant' and content.startswith(prefix),body
                content=content[len(prefix):]
            if body.get('stream'):
                payload=('data: '+json.dumps({'choices':[{'delta':{'content':content}}]})+'\n\ndata: [DONE]\n\n').encode(); content_type='text/event-stream'
            else:
                payload=json.dumps({'choices':[{'message':{'content':content}}]}).encode(); content_type='application/json'
            self.send_response(200); self.send_header('Content-Type',content_type); self.send_header('Content-Length',str(len(payload))); self.end_headers(); self.wfile.write(payload); return
        if self.path=='/v1/audio/speech':
            body=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0')))); self.openai_speech_requests.append(body)
            self.send_response(200); self.send_header('Content-Type','audio/wav'); self.send_header('Content-Length',str(len(self.silence))); self.end_headers(); self.wfile.write(self.silence); return
        if self.path=='/tts_to_audio':
            body=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0')))); self.speech_requests.append(body)
            self.send_response(200); self.send_header('Content-Type','audio/wav'); self.send_header('Content-Length',str(len(self.silence))); self.end_headers(); self.wfile.write(self.silence); return
        if self.path!='/upload_sample': self.send_error(404); return
        body=self.rfile.read(int(self.headers.get('Content-Length','0'))); self.uploads.append((dict(self.headers),body))
        payload=b'{"status":"ok"}'
        self.send_response(self.upload_status); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(payload))); self.end_headers(); self.wfile.write(payload)
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
for speech_driver,speech_model,speech_options in [('openai','gpt-4o-mini-tts',{'instructions':'Speak softly.\nPause between sentences.'}),('openai','tts-1',{'instructions':'Not supported by this model.'}),('kokoro','kokoro',{'speed':1.2})]:
    speech_content={'driver':speech_driver,'model':speech_model,'voice':'alloy','endpoint':'http://127.0.0.1:'+str(voice_provider.server_port)+'/v1/audio/speech','credential':'none','options':speech_options}
    speech_probe=subprocess.run(['php','-r',"require $argv[1].'/lib/Autoload.php'; $p=LorkhanServer\\Application\\ProviderFactory::speechForPreset([],['content'=>json_decode($argv[2],true)]); echo $p->synthesize('Hello.',new LorkhanServer\\Application\\NeverCancelledToken())['duration_ms'];",str(repository_root),json.dumps(speech_content)],capture_output=True,text=True,timeout=5)
    assert speech_probe.returncode==0 and int(speech_probe.stdout)>0,(speech_probe.returncode,speech_probe.stderr)
assert VoiceProvider.openai_speech_requests[0]['instructions']=='Speak softly.\nPause between sentences.'
assert 'instructions' not in VoiceProvider.openai_speech_requests[1] and VoiceProvider.openai_speech_requests[2]['speed']==1.2
assert all(p['response_format']=='wav' for p in VoiceProvider.openai_speech_requests)
for tag_driver in ['chatterbox','xtts-fastapi']:
    tag_probe=subprocess.run(['php','-r',"require $argv[1].'/lib/Autoload.php'; $p=new LorkhanServer\\Application\\XttsCompatibleSpeechProvider($argv[2],$argv[3],'fixture','en',['paralinguistic_tags_enabled'=>true,'paralinguistic_tags_list'=>'[sigh]']); echo $p->synthesize('[SIGH] Hello [unknown].',new LorkhanServer\\Application\\NeverCancelledToken())['duration_ms'];",str(repository_root),'http://127.0.0.1:'+str(voice_provider.server_port)+'/tts_to_audio',tag_driver],capture_output=True,text=True,timeout=5)
    assert tag_probe.returncode==0 and int(tag_probe.stdout)>0,(tag_probe.returncode,tag_probe.stderr)
assert [p['text'] for p in VoiceProvider.speech_requests]==['[SIGH] Hello .','[SIGH] Hello .']
VoiceProvider.speech_requests.clear()

class Page(html.parser.HTMLParser):
    def __init__(self,external_form=None):
        super().__init__(); self.labels=set(); self.controls=[]; self.nav=[]; self.current=0; self.forms=[]; self.form=None; self.select_name=None; self.label_depth=0
        self.external_form=external_form; self.external_fields={}; self.external_select=None; self.external_textarea=None
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if self.external_form and a.get('form')==self.external_form and a.get('name') and 'disabled' not in a:
            if tag=='input' and (a.get('type') not in ('checkbox','radio') or 'checked' in a): self.external_fields[a['name']]=a.get('value','')
            if tag=='select': self.external_select=a['name']
            if tag=='textarea': self.external_textarea=a['name']; self.external_fields[a['name']]=''
        if tag=='option' and self.external_select and (self.external_select not in self.external_fields or 'selected' in a):
            self.external_fields[self.external_select]=a.get('value','')
        if tag=='label':
            self.label_depth+=1
            if a.get('for'): self.labels.add(a['for'])
        if tag in ('input','textarea','select') and a.get('name') and a.get('type') not in ('hidden','checkbox'): self.controls.append((tag,a.get('id'),a.get('name'),self.label_depth>0 or bool(a.get('aria-label'))))
        if tag=='a' and a.get('href','').startswith('/LorkhanServer/ui/'):
            self.nav.append(a['href'])
            if 'dropdown-item' in a.get('class','').split(): self.current+=a.get('aria-current')=='page'
        if tag=='form': self.form={'id':a.get('id',''),'action':a.get('action',''),'method':a.get('method','get'),'fields':{}}; self.forms.append(self.form)
        if self.form is not None and tag=='input' and a.get('name') and 'disabled' not in a and (a.get('type') not in ('checkbox','radio') or 'checked' in a): self.form['fields'][a['name']]=a.get('value','')
        if self.form is not None and tag=='input' and a.get('type')=='checkbox' and a.get('name') and 'checked' in a:
            self.form.setdefault('checked',{}).setdefault(a['name'],[]).append(a.get('value',''))
        if self.form is not None and tag=='select' and a.get('name') and 'disabled' not in a: self.select_name=a['name']
        if self.form is not None and tag=='option' and self.select_name and (self.select_name not in self.form['fields'] or 'selected' in a):
            self.form['fields'][self.select_name]=a.get('value','')
    def handle_endtag(self,tag):
        if tag=='select': self.external_select=None
        if tag=='textarea' and self.external_textarea:
            self.external_fields[self.external_textarea]=self.external_fields[self.external_textarea].removeprefix('\n'); self.external_textarea=None
        if tag=='label': self.label_depth=max(0,self.label_depth-1)
        if tag=='select': self.select_name=None
        if tag=='form': self.form=None
    def handle_data(self,data):
        if self.external_textarea: self.external_fields[self.external_textarea]+=data

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

def multipart_request(path,fields,file_field=None,filename='',content_type='',payload=b'',extra_files=()):
    boundary='----lorkhan-'+uuid.uuid4().hex; body=bytearray()
    for name,value in fields.items():
        body.extend(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+name+'"\r\n\r\n'+str(value)+'\r\n').encode())
    for field,name,mime,content in ([(file_field,filename,content_type,payload)] if file_field is not None else [])+list(extra_files):
        body.extend(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+field+'"; filename="'+name+'"\r\nContent-Type: '+mime+'\r\n\r\n').encode())
        body.extend(content); body.extend(b'\r\n')
    body.extend(('--'+boundary+'--\r\n').encode())
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
for catalogue in ['llm-models','llm-providers']:
    try:
        urllib.request.urlopen(base+'/LorkhanServer/manage/api/v1/'+catalogue)
        raise AssertionError('Catalogue requires a management session')
    except urllib.error.HTTPError as error:
        assert error.code==401
    assert request('/LorkhanServer/manage/api/v1/'+catalogue+'?url=https%3A%2F%2Fexample.invalid').status==422
groq_catalogue='/LorkhanServer/manage/api/v1/llm-groq-models'
groq_empty={'driver':'openai-compatible','credential':'none'}
try:
    urllib.request.urlopen(urllib.request.Request(base+groq_catalogue,data=json.dumps(groq_empty).encode(),headers={'Content-Type':'application/json'}))
    raise AssertionError('Groq catalogue requires a management session')
except urllib.error.HTTPError as error: assert error.code==401
assert json_request(groq_catalogue,'POST',groq_empty).status==401
r=json_request(groq_catalogue,'POST',groq_empty,csrf); assert r.status==422 and json.load(r)['error']=='groq_api_key_required'
for invalid_groq in [
    dict(groq_empty,credential='DATABASE_PASSWORD'),dict(groq_empty,driver='mock'),
    dict(groq_empty,url='https://example.invalid'),{'driver':'configured','credential':'groq'},
    {'driver':'configured','credential':''},
]:
    assert json_request(groq_catalogue,'POST',invalid_groq,csrf).status==422
assert json_request(groq_catalogue+'?url=https://example.invalid','POST',groq_empty,csrf).status==422
for path,marker,title in [
    ('/LorkhanServer/ui/home.php','dashboard-container','Home'),
    ('/LorkhanServer/ui/events-memories.php','events-memories-navigation','Roleplay'),
    ('/LorkhanServer/ui/core/config_hub.php','config-navigation','Configuration'),
    ('/LorkhanServer/ui/control_panel.php','config-navigation','Control Panel'),
]:
    page,text=parse(request(path)); assert page.current==1,path; assert marker in text,path; assert '<title>'+title+'</title>' in text,path
    if path != '/LorkhanServer/ui/home.php': assert '<body class="hub-page">' in text,path
events,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=eventlog'))
assert events.current==1 and 'id="eventlog-app"' in text and 'data-eventlog-live' in text and 'Delete Latest 5' in text and 'Delete ALL' in text and '<table class="eventlog-table' not in text and 'data-eventlog-delete-row' not in text and all(removed not in text for removed in ['Soulgaze','AI Quest Manager','Active Quests','Background Life','data-tab="questgen"','data-tab="backgroundlife"'])
assert text.count('data-eventlog-pagination')==1 and 'roleplay-list-footer' not in text
for removed_tab in ['backgroundlife','questgen','quests','soulgaze']:
    removed_page,removed_text=parse(request('/LorkhanServer/ui/events-memories.php?tab='+removed_tab))
    assert removed_page.current==1 and 'id="eventlog-app"' in removed_text and 'id="journal-tab" class="tab-content active"' not in removed_text,removed_tab
journal,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=journal-tab')); assert journal.current==1 and 'Morrowind Journal' in text and 'id="journal-tab" class="tab-content active"' in text and 'events-memories.php?tab=journal' in text and 'events-memories.php?tab=quests' not in text and 'events-memories.php?tab=relationships' not in text and '>Morrowind</div>' not in text
books,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=books-tab')); assert books.current==1 and 'No books found.' in text and 'id="books-tab" class="tab-content active"' in text
responses,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=responses-tab')); assert responses.current==1 and 'class="ai-response-table"' in text and 'Oghma Topic' in text and 'HTTP Request' in text and 'data-reader-play' not in text
memories,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=memories-tab')); assert memories.current==1 and '>Memories</h2>' in text and 'id="memory-tab" class="tab-content active"' in text and 'Add or rebuild memories' in text
embedding_policy=next(f for f in memories.forms if f['action'].endswith('/forms/memory-embedding-policy'))
assert embedding_policy['fields'].get('timeout_ms')=='1500' and embedding_policy['fields'].get('endpoint')=='' and 'enabled' not in embedding_policy['fields'],embedding_policy
embedding_values=dict(embedding_policy['fields'],_csrf=csrf,enabled='1',endpoint='http://'+provider_host+':'+str(voice_provider.server_port),timeout_ms='1250')
r=request(embedding_policy['action'],'POST',embedding_values); body=r.read().decode()
assert r.status==200 and 'status=embedding-saved' in r.geturl() and 'Use MiniMe semantic retrieval' in body and 'value="1250"' in body and ' checked' in body,(r.status,r.geturl(),body)
# Quickstart checks only the saved MiniMe endpoint, never an arbitrary submitted URL or an embedding.
original_opener,original_jar=opener,jar
jar=http.cookiejar.CookieJar()
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
request('/LorkhanServer/ui/home.php').read()
minime_csrf=next(c.value for c in jar if c.name=='lorkhan_csrf')
minime_path='/LorkhanServer/manage/api/v1/quickstart-minime'
minime_body={'installation_id':embedding_values['installation_id']}
assert json_request(minime_path,'POST',minime_body).status==401
assert json_request(minime_path,'POST',dict(minime_body,url='http://127.0.0.1:1'),minime_csrf).status>=400
probe=json_request(minime_path,'POST',minime_body,minime_csrf); result=json.load(probe)
assert probe.status==200 and result['ok'] and result['http_code']==200 and VoiceProvider.embedding_requests==[],result
VoiceProvider.minime_status=503
probe=json_request(minime_path,'POST',minime_body,minime_csrf); result=json.load(probe)
assert probe.status==200 and not result['ok'] and result['http_code']==503 and VoiceProvider.embedding_requests==[],result
VoiceProvider.minime_status=200
opener,jar=original_opener,original_jar
memories,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=memory')); embedding_backfill=next(f for f in memories.forms if f['action'].endswith('/forms/memory-embedding-backfill'))
r=request(embedding_backfill['action'],'POST',dict(embedding_backfill['fields'],_csrf=csrf,limit='100')); body=r.read().decode()
assert r.status==200 and 'status=embedding-backfill-empty' in r.geturl() and 'No memories needed embedding' in body and VoiceProvider.embedding_requests==[],(r.status,r.geturl(),body,VoiceProvider.embedding_requests)
relationships,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=relationships-tab')); assert relationships.current==1 and '<strong>Morrowind Journal:</strong>' in text and '<th scope="col">Journal ID</th>' in text and 'id="journal-tab" class="tab-content active"' in text and 'Add relationship' not in text
narratives_tab,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=narratives-tab')); assert narratives_tab.current==1 and '>Adventure Log</h1>' in text and 'id="adventure-tab" class="tab-content active"' in text and 'Regular Calendar' in text and 'calendar-event-table' in text and 'Create / Generate Entry' not in text
diaries,text=parse(request('/LorkhanServer/ui/events-memories.php?tab=diaries')); assert 'Diary Log</h1>' in text and 'Filter by Person' in text and 'calendar-event-table' in text
narratives_page,text=parse(request('/LorkhanServer/ui/narrative_manager.php')); assert narratives_page.current==1 and '<h1>📝 Narratives</h1>' in text and 'Create narrative' in text
assert 'data-open-narrative="narrative-create"' in text and 'No narratives match these filters.' in text
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
usage,text=parse(request('/LorkhanServer/ui/provider_usage.php'))
assert usage.current==1 and '<h1>💰 Cost Distribution by Request Type</h1>' in text and 'Total Cost: $0.00' in text
assert all(marker in text for marker in ['Apply Date','Apply Week','Cost values by request type','More filters and export','No provider attempts match this date range']) and 'runtime-metrics' not in text
usage_date,text=parse(request('/LorkhanServer/ui/provider_usage.php?filter=date&date=2020-12-31&installation_id='))
assert 'Date: 2020-12-31' in text and usage_date.forms[0]['fields']['installation_id']=='' and 'Scope: All Installations' in text
cost_chart=json.loads(re.search(r'id="cost-chart-data">(.*?)</script>',text,re.S).group(1))
assert cost_chart=={'labels':['dialogue'],'values':[103]} and 'Total Cost: $103.00' in text
assert '102 of 105 attempts include cost' in text and '<td>unknown</td><td>Unknown</td>' in text
cost_export=request('/LorkhanServer/ui/provider_usage.php?filter=date&date=2020-12-31&installation_id=&export=1')
assert len(list(csv.DictReader(io.StringIO(cost_export.read().decode('utf-8-sig')))))==100
_,cost_scoped=parse(request('/LorkhanServer/ui/provider_usage.php?filter=date&date=2020-12-31'))
assert 'Total Cost: $0.00' in cost_scoped and 'id="cost-chart-data"' not in cost_scoped
usage_week,text=parse(request('/LorkhanServer/ui/provider_usage.php?filter=week&week=2020-W53'))
assert 'Week: 2020-W53' in text
_,cost_week=parse(request('/LorkhanServer/ui/provider_usage.php?filter=week&week=2020-W53&installation_id='))
assert 'Total Cost: $112.00' in cost_week
assert json.loads(re.search(r'id="cost-chart-data">(.*?)</script>',cost_week,re.S).group(1))=={'labels':['dialogue','diary'],'values':[103,9]}
for invalid_date in ['2020-02-30','0000-01-01','2020-01-01%00']:
    invalid,text=parse(request('/LorkhanServer/ui/provider_usage.php?filter=date&date='+invalid_date))
    assert 'Date: '+invalid_date not in text and invalid.current==1
invalid,text=parse(request('/LorkhanServer/ui/provider_usage.php?filter=week&week=2021-W53'))
assert 'Week: 2021-W53' not in text
usage_period,text=parse(request('/LorkhanServer/ui/provider_usage.php?period=all'))
assert '<h3>All Time</h3>' in text
# Operational readers page the complete safe metadata set, including unassigned historical attempts.
_,attempts=parse(request('/LorkhanServer/ui/provider_attempts.php?embed=1'))
assert 'Showing 50 of 110 records. Page 1 / 3.' in attempts and 'operational-log-page' in attempts
_,attempts_last=parse(request('/LorkhanServer/ui/provider_attempts.php?page=3&embed=1'))
assert 'Showing 10 of 110 records. Page 3 / 3.' in attempts_last and 'embed=1' in attempts_last
attempt_csv=request('/LorkhanServer/ui/provider_attempts.php?page=3&export=csv')
attempt_rows=list(csv.DictReader(io.StringIO(attempt_csv.read().decode('utf-8-sig'))))
assert len(attempt_rows)==10 and set(attempt_rows[0])=={'ID','Time (UTC)','Service','Connector','Model','Operation','Status','Duration (ms)','Error'}
for filters in ['q=diary','q=%25','state=failed','installation_id=00000000-0000-4000-8000-000000000001','period=24h']:
    _,filtered=parse(request('/LorkhanServer/ui/provider_attempts.php?'+filters))
    assert ('Showing 3 of 3 records.' in filtered) if filters=='q=diary' else ('No provider attempts match these filters.' in filtered)
_,jobs_empty=parse(request('/LorkhanServer/ui/jobs.php?q=unmatched-operational-fixture'))
assert 'No durable jobs match these filters.' in jobs_empty and 'operational-log-page' in jobs_empty
_,health_reader=parse(request('/LorkhanServer/ui/diagnostics.php?q=health-reader-fixture'))
assert 'Server-wide snapshot' in health_reader and 'Showing 1 of 1 records.' in health_reader
assert 'safe_scope' in health_reader and '00000000-0000-4000-8000-000000000099' in health_reader
assert 'hidden-scope-fixture' not in health_reader and 'hidden-audit-detail-fixture' not in health_reader
health_export=request('/LorkhanServer/ui/diagnostics.php?q=health-reader-fixture&export=csv')
health_rows=list(csv.DictReader(io.StringIO(health_export.read().decode('utf-8-sig'))))
assert len(health_rows)==1 and health_rows[0]['Time (UTC)']=='31-12-2020 12:00:00'
assert 'hidden-' not in str(health_rows) and set(health_rows[0])=={'Audit ID','Time (UTC)','Category','Action','Scope'}
_,health_recent=parse(request('/LorkhanServer/ui/diagnostics.php?q=health-reader-fixture&period=24h'))
assert 'No operational audit records match these filters.' in health_recent
backup_health,backup_health_html=parse(request('/LorkhanServer/ui/backup_health.php'))
assert 'No backups match these filters.' in backup_health_html and 'Operational retention' in backup_health_html
assert 'Backups, NPC memories, narrative entries, voice files and game saves are retained.' in backup_health_html
retention_form=next(f for f in backup_health.forms if f['action'].endswith('/forms/retention'))
assert retention_form['fields']['days']=='30' and 'data-retention-confirm' in backup_health_html and 'id="operational-retention-confirm"' in backup_health_html
_,game_debug_html=parse(request('/LorkhanServer/ui/game_debug.php?embed=1'))
assert 'request-log-page game-debug-page' in game_debug_html and 'Created (UTC)' in game_debug_html
assert 'data-debug-table hidden' in game_debug_html and 'data-debug-empty' in game_debug_html
assert game_debug_html.count('data-debug-command=')==19
assert 'aria-label="God Mode on"' in game_debug_html and 'Refresh state queues a read-only game snapshot' in game_debug_html
server_logs,text=parse(request('/LorkhanServer/ui/server_logs.php'))
assert server_logs.current==1 and '<h1>Server Logs</h1>' in text and 'bounded to 256 KiB and redacted' in text
assert text.count('class="log-section"')==3 and all(label in text for label in ['Download Logs','Timezone: UTC','Filter by Level:','Search expanded log','data-expand-log'])
assert '/var/log/' not in text and 'chim.log' not in text
database,text=parse(request('/LorkhanServer/ui/database_manager.php')); assert database.current==1 and '<h1>Database Manager</h1>' in text and 'schema migrations' in text and 'Installation Configuration Backups' in text
assert 'server-file-list' not in text and 'No configuration backups are available.' in text
assert 'Database Versioning Manager' in text and 'not a full database backup' in text
studio,text=parse(request('/LorkhanServer/ui/core/voice_library.php')); assert studio.current==1 and 'Add WAV voice samples' in text and 'flat ZIP batch' in text and 'Voice Library' in text and 'Configured TTS Connectors' in text and 'Provider Voice Browser' in text and 'never contacts a provider automatically' in text
for provider_tab,provider_label in [('xtts','XTTS'),('chatterbox','Chatterbox'),('pockettts','PocketTTS'),('omnivoice','OmniVoice'),('cartesia','Cartesia'),('inworld','Inworld')]:
    cache_html=request('/LorkhanServer/ui/core/voice_library.php?tab='+provider_tab).read().decode()
    assert cache_html.count('<h1>'+provider_label+' Voice '+('Library' if provider_tab=='omnivoice' else 'Cache')+'</h1>')==1 and ' Local Voice Library</h1>' not in cache_html
    assert 'Provider Voice Browser &amp; Connector Settings' in cache_html and 'Missing Voices</h1>' in cache_html
fallback_page,fallback_html=parse(request('/LorkhanServer/ui/core/voice_library.php?tab=fallbacks'))
fallback_form=next(f for f in fallback_page.forms if f['fields'].get('action')=='fallback_save')
fallback_fields=fallback_form['fields']
assert 'fallback-connector' not in fallback_html and 'configuration_id' not in fallback_fields
assert sum(k.startswith('fallbacks[') for k in fallback_fields)==20 and fallback_fields['fallbacks[dark_elf][male]']=='mw_dark_elf_male'
assert fallback_html.count('list="tts-fallback-voiceids"')==20 and '<datalist id="tts-fallback-voiceids">' in fallback_html and '<strong>Resolution order:</strong>' in fallback_html
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
# A WAV's filename is the default voice name; custom naming remains optional.
r=multipart_request('/LorkhanServer/ui/core/voice_library.php',{'_csrf':csrf,'action':'upload','voice_name':''},'voice_sample',batch_voice+'.wav','audio/wav',wav); body=r.read().decode()
assert r.status==200 and 'Voice sample saved.' in body and batch_voice in body
r=request('/LorkhanServer/ui/core/voice_library.php','POST',{'_csrf':csrf,'action':'delete','voice_name':batch_voice}); assert 'Local voice sample deleted.' in r.read().decode()
# Multi-file selections are atomic, including mixed WAV/ZIP, invalid files and truncated multipart counts.
multi_a='HTTPMultiA'+uuid.uuid4().hex; multi_b='HTTPMultiB'+uuid.uuid4().hex
upload_fields={'_csrf':csrf,'action':'upload','voice_name':'','upload_count':'2'}
for extra,expected_error in [(('bad.wav','audio/wav',b'not a wav'),'invalid_voice_sample'),((multi_a+'.wav','audio/wav',wav),'voice_sample_exists')]:
    r=multipart_request('/LorkhanServer/ui/core/voice_library.php',upload_fields,'voice_sample[]',multi_a+'.wav','audio/wav',wav,[('voice_sample[]',*extra)])
    text=r.read().decode(); assert expected_error in text and 'data-copy-voice="'+multi_a+'"' not in text
r=multipart_request('/LorkhanServer/ui/core/voice_library.php',upload_fields,'voice_sample[]',multi_a+'.wav','audio/wav',wav)
assert 'invalid_voice_upload_selection' in r.read().decode()
multi_archive=io.BytesIO()
with zipfile.ZipFile(multi_archive,'w',zipfile.ZIP_DEFLATED) as bundle: bundle.writestr(multi_b+'.wav',wav)
r=multipart_request('/LorkhanServer/ui/core/voice_library.php',upload_fields,'voice_sample[]',multi_a+'.wav','audio/wav',wav,[('voice_sample[]','multi.zip','application/zip',multi_archive.getvalue())])
text=r.read().decode(); assert '2 voice samples imported.' in text and multi_a in text and multi_b in text
multi_c='HTTPMultiC'+uuid.uuid4().hex
r=multipart_request('/LorkhanServer/ui/core/voice_library.php',upload_fields,'voice_sample[]',multi_c+'.wav','audio/wav',wav,[('voice_sample[]',multi_a+'.wav','audio/wav',wav)])
text=r.read().decode(); assert 'voice_sample_exists' in text and 'data-copy-voice="'+multi_c+'"' not in text and 'data-copy-voice="'+multi_a+'"' in text
for name in [multi_a,multi_b]:
    r=request('/LorkhanServer/ui/core/voice_library.php','POST',{'_csrf':csrf,'action':'delete','voice_name':name}); assert 'Local voice sample deleted.' in r.read().decode()
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
# Planning does not upload; named steps do not depend on a provider refreshing its speaker list immediately.
batch_fields={'_csrf':csrf,'action':'batch_sync','_batch_ajax':'1','_batch_phase':'plan','consent':'1','configuration_id':sync_tts_id,'language':'en'}
before_uploads=len(VoiceProvider.uploads)
r=request('/LorkhanServer/ui/core/voice_library.php','POST',dict(batch_fields,consent='0')); assert r.status==422
r=request('/LorkhanServer/ui/core/voice_library.php','POST',dict(batch_fields,_csrf='invalid')); assert r.status==401
r=request('/LorkhanServer/ui/core/voice_library.php','POST',batch_fields); planned=json.load(r)
assert batch_voice in planned['voices'] and len(VoiceProvider.uploads)==before_uploads
r=request('/LorkhanServer/ui/core/voice_library.php','POST',dict(batch_fields,_batch_phase='voice',voice_name=batch_voice)); result=json.load(r)
assert result['voice']==batch_voice and result['uploaded']==1 and result['failed']==0 and result['skipped']==0 and len(VoiceProvider.uploads)==before_uploads+1
r=request('/LorkhanServer/ui/core/voice_library.php','POST',dict(batch_fields,_batch_phase='voice',voice_name='MockProviderVoice')); result=json.load(r)
assert result['skipped']==1 and len(VoiceProvider.uploads)==before_uploads+1
r=request('/LorkhanServer/ui/core/voice_library.php','POST',dict(batch_fields,_batch_phase='voice',voice_name='Missing'+uuid.uuid4().hex)); result=json.load(r)
assert result['failed']==1 and result['uploaded']==0 and len(VoiceProvider.uploads)==before_uploads+1
r=request('/LorkhanServer/ui/core/voice_library.php','POST',dict(batch_fields,_batch_phase='voice',voice_name='../Escape')); assert r.status==422
VoiceProvider.upload_status=429
r=request('/LorkhanServer/ui/core/voice_library.php','POST',dict(batch_fields,_batch_phase='voice',voice_name=batch_voice)); result=json.load(r)
assert result['failed']==1 and result['rate_limited'] is True
VoiceProvider.upload_status=200
profiles_with_provider_voice=request('/LorkhanServer/ui/core/npc_master.php').read().decode()
assert 'MockProviderVoice' in profiles_with_provider_voice and sync_tts_name in profiles_with_provider_voice,profiles_with_provider_voice
pron,text=parse(request('/LorkhanServer/ui/core/voice_library.php?tab=pronunciations'))
assert pron.current==1 and 'id="pron-preview"' in text and 'data-pron-endpoint="/LorkhanServer/manage/api/v1/tts-previews"' in text
builtin_form=next(f for f in pron.forms if f['fields'].get('action')=='pronunciation_toggle')
builtin_fields=builtin_form['fields']; builtin_id=builtin_fields['id']; original_spoken=builtin_fields['spoken_text']
assert 'data-pron-edit' in text and 'data-pron-editor hidden' in text and 'can be disabled or deleted' in text
builtin_save=dict(builtin_fields,_csrf=csrf,action='pronunciation_builtin_save',spoken_text='EditedBuiltinHTTP',enabled='1',source_text='DoNotChangeTheOriginal',npc_names='DoNotChangeScope')
r=request(builtin_form['action'],'POST',builtin_save); edited,text=parse(r)
assert 'Built-in pronunciation saved.' in text and 'DoNotChangeTheOriginal' not in text and 'DoNotChangeScope' not in text
assert next(f for f in edited.forms if f['fields'].get('id')==builtin_id and f['fields'].get('action')=='pronunciation_toggle')['fields']['spoken_text']=='EditedBuiltinHTTP'
r=request(builtin_form['action'],'POST',dict(builtin_save,spoken_text='')); assert 'Enter a valid original term and spoken version.' in r.read().decode()
r=request(builtin_form['action'],'POST',dict(builtin_save,_csrf='invalid')); assert 'Your management session expired.' in r.read().decode()
r=request(builtin_form['action'],'POST',dict(builtin_save,spoken_text=original_spoken)); assert 'Built-in pronunciation saved.' in r.read().decode()
r=request(builtin_form['action'],'POST',dict(builtin_save,action='pronunciation_save')); assert 'cannot be changed with this action' in r.read().decode()
custom_term='HTTPPron'+uuid.uuid4().hex
r=request(builtin_form['action'],'POST',{'_csrf':csrf,'action':'pronunciation_save','source_text':custom_term,'spoken_text':'CustomSpoken','npc_names':'Jiub','races':'Dark Elf','oghma_tags':'http-tag','enabled':'1'}); custom_page,custom_text=parse(r)
assert 'Custom pronunciation added.' in custom_text
custom_form=next(f for f in custom_page.forms if f['fields'].get('source_text')==custom_term)
filtered=request('/LorkhanServer/ui/core/voice_library.php?tab=pronunciations&oghma_tag=http-tag').read().decode()
assert '1 custom entry tagged &quot;http-tag&quot;' in filtered and custom_term in filtered and 'Clear filter' in filtered
r=request(custom_form['action'],'POST',dict(custom_form['fields'],_csrf=csrf,spoken_text='UpdatedSpoken')); assert 'Custom pronunciation saved.' in r.read().decode()
for entry_id in [custom_form['fields']['id'],builtin_id]:
    r=request(builtin_form['action'],'POST',{'_csrf':csrf,'action':'pronunciation_delete','id':entry_id}); deleted,deleted_text=parse(r)
    assert 'Pronunciation deleted.' in deleted_text and not any(f['fields'].get('id')==entry_id for f in deleted.forms)
# Subsequent preview assertions use the page from before these isolated dictionary mutations.
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
before_preview_uploads=len(VoiceProvider.uploads)
r=preview({'installation_id':tts_installation,'configuration_id':sync_tts_id,'voice':batch_voice,'text':'Vvardenfell'}); clip=r.read()
assert r.status==200 and r.headers.get('Content-Type')=='audio/wav' and clip.startswith(b'RIFF'),(r.status,r.headers.get('Content-Type'),clip[:160])
assert VoiceProvider.speech_requests==[{'text':'Vvardenfell','speaker_wav':batch_voice,'language':'en'}],VoiceProvider.speech_requests
assert len(VoiceProvider.uploads)==before_preview_uploads+1 and b'name="wavFile"' in VoiceProvider.uploads[-1][1],VoiceProvider.uploads
r=preview({'installation_id':tts_installation,'configuration_id':sync_tts_id,'voice':'NotInstalled','text':'Vvardenfell'}); body=r.read().decode()
assert r.status==422 and json.loads(body)=={'error':'invalid_tts_preview_voice'},(r.status,body)
r=preview({'installation_id':tts_installation,'configuration_id':sync_tts_id,'voice':batch_voice,'text':'V'*241}); body=r.read().decode()
assert r.status==422 and json.loads(body)=={'error':'invalid_tts_preview_text'},(r.status,body)
r=preview({'installation_id':tts_installation,'configuration_id':sync_tts_id,'voice':batch_voice,'text':'Vvardenfell'},None)
assert r.status==401 and len(VoiceProvider.speech_requests)==1,(r.status,VoiceProvider.speech_requests)
omni_name='HTTP OmniVoice language '+uuid.uuid4().hex
omni_values=dict(sync_values,name=omni_name,driver='omnivoice')
r=request(create_sync_tts['action'],'POST',omni_values); omni_body=r.read().decode(); assert r.status==200
omni_id=connector_editor_id(omni_body,omni_name)
omni_url='/LorkhanServer/ui/core/voice_library.php?tab=omnivoice&embed=1&configuration_id='+omni_id+'&language=fr'
VoiceProvider.omni_speakers=[{'name':'RemoteReady','status':'ready','can_delete':True},{'name':'RemoteNeedsText','status':'needs_reference_text'},
    {'name':'ReadyByFlag','status':'transcribing','runtime_ready':True},{'name':batch_voice,'status':'needs_reference_text','runtime_ready':False}]
omni_page,omni_html=parse(request(omni_url))
assert 'OmniVoice Language Library' in omni_html and 'French (fr)' in omni_html and re.search(r'<option value="fr" selected',omni_html)
assert all(f['fields'].get('language')=='fr' for f in omni_page.forms if f['fields'].get('action') in ['sync','batch_sync','discover'])
def omni_card(name,body): return next(row for row in re.findall(r'<article class="voice-status-item[^"]*">.*?</article>',body,re.S) if 'data-copy-voice="'+name+'"' in row)
assert 'server</span>' in omni_card('RemoteReady',omni_html) and 'title="Test voice"' in omni_card('RemoteReady',omni_html)
assert 'needs text</span>' in omni_card('RemoteNeedsText',omni_html) and 'title="Test voice"' not in omni_card('RemoteNeedsText',omni_html)
assert 'title="Test voice"' in omni_card('ReadyByFlag',omni_html)
assert 'local</span>' in omni_card(batch_voice,omni_html) and 'title="Test voice"' not in omni_card(batch_voice,omni_html)
omni_upload=next(f for f in omni_page.forms if f['fields'].get('action')=='upload')
assert omni_upload['fields']['language']=='fr' and omni_upload['fields']['configuration_id']==omni_id
assert 'Import Voice Sample' in omni_html and 'Custom voice name (optional)' not in omni_html
import_voice='omni_import_'+uuid.uuid4().hex
r=multipart_request(omni_upload['action'],omni_upload['fields'],'voice_sample[]',import_voice+'.wav','audio/wav',wav)
assert '1 voice sample(s) imported into OmniVoice.' in r.read().decode()
assert b'\r\n\r\nfr\r\n' in VoiceProvider.uploads[-1][1]
VoiceProvider.upload_status=500
retry_voice='omni_retry_'+uuid.uuid4().hex
r=multipart_request(omni_upload['action'],omni_upload['fields'],'voice_sample[]',retry_voice+'.wav','audio/wav',wav); retry_html=r.read().decode()
assert 'Local WAVs were kept; retry with Sync' in retry_html and 'data-copy-voice="'+retry_voice+'"' in retry_html
VoiceProvider.upload_status=200
delete_form=next(f for f in omni_page.forms if f['fields'].get('action')=='delete_provider')
assert delete_form['fields']['voice_id']=='RemoteReady' and delete_form['fields']['language']=='fr'
assert 'Remove custom voice' not in omni_card('ReadyByFlag',omni_html)
VoiceProvider.delete_status=500
r=request(delete_form['action'],'POST',delete_form['fields']); assert 'voice_delete_failed' in r.read().decode()
VoiceProvider.delete_status=204
r=request(delete_form['action'],'POST',delete_form['fields']); assert 'Provider voice removed. The local WAV was kept' in r.read().decode()
assert VoiceProvider.deletes[-1]=='/voices/RemoteReady?language=fr'
count_deletes=len(VoiceProvider.deletes)
r=request(delete_form['action'],'POST',delete_form['fields']); assert 'voice_not_managed' in r.read().decode() and len(VoiceProvider.deletes)==count_deletes
omni_batch=dict(batch_fields,configuration_id=omni_id,language='fr')
r=request('/LorkhanServer/ui/core/voice_library.php','POST',omni_batch); assert batch_voice in json.load(r)['voices']
r=request('/LorkhanServer/ui/core/voice_library.php','POST',dict(omni_batch,_batch_phase='voice',voice_name=batch_voice)); result=json.load(r)
assert result['uploaded']==1 and result['skipped']==0 and b'\r\n\r\nfr\r\n' in VoiceProvider.uploads[-1][1],result
VoiceProvider.omni_speakers[-1]['status']='ready'
omni_html=request(omni_url).read().decode(); assert 'title="Test voice"' in omni_card(batch_voice,omni_html)

r=preview({'installation_id':tts_installation,'configuration_id':omni_id,'voice':batch_voice,'language':'fr','text':'Bonjour'}); clip=r.read()
assert r.status==200 and clip.startswith(b'RIFF') and VoiceProvider.speech_requests[-1]['language']=='fr',(r.status,clip[:160],VoiceProvider.speech_requests[-1])
r=preview({'installation_id':tts_installation,'configuration_id':omni_id,'voice':batch_voice,'language':'../bad','text':'Bonjour'}); assert r.status==422
r=request('/LorkhanServer/manage/forms/connector-delete','POST',{'_csrf':csrf,'configuration_id':omni_id,'kind':'tts_provider'}); assert r.status==200
VoiceProvider.omni_speakers=None
for _ in range(25):
    r=preview({'installation_id':tts_installation,'configuration_id':sync_tts_id,'voice':batch_voice,'text':'Vvardenfell'}); clip=r.read()
    assert r.status==200 and clip.startswith(b'RIFF'),(r.status,clip[:160])
r=preview({'installation_id':tts_installation,'configuration_id':sync_tts_id,'voice':batch_voice,'text':'Vvardenfell'}); body=r.read().decode()
assert r.status==429 and json.loads(body)=={'error':'tts_preview_rate_limited'} and len(VoiceProvider.speech_requests)==27,(r.status,body,len(VoiceProvider.speech_requests))
r=request('/LorkhanServer/manage/forms/connector-delete','POST',{'_csrf':csrf,'configuration_id':sync_tts_id,'kind':'tts_provider'}); assert r.status==200,(r.status,r.geturl())
assert 'MockProviderVoice' not in request('/LorkhanServer/ui/core/npc_master.php').read().decode()
keys,text=parse(request('/LorkhanServer/ui/core/api_keys.php')); assert keys.current==0 and 'API Keys</h1>' in text and 'LORKHAN_LLM_API_KEY' in text and 'type="password"' in text
assert '<nav class="navbar' not in text, 'API Keys child page must not add a second navigation shell'
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
biography_prefix='/LorkhanServer/ui/core/npc_biographies.php?search=ZZZ+Pagination+Fixture'
first_catalog=request(biography_prefix).read().decode()
assert first_catalog.count('<tr data-biography-row ')==50 and '5,005 templates' in first_catalog and 'Page 1 of 101' in first_catalog
last_catalog=request(biography_prefix+'&page=999999').read().decode()
assert last_catalog.count('<tr data-biography-row ')==5 and 'ZZZ Pagination Fixture 05005</td>' in last_catalog and 'Page 101 of 101' in last_catalog
assert 'ZZZ Pagination Fixture 05005</td>' in request('/LorkhanServer/ui/core/npc_biographies.php?search=05005&letter=Z').read().decode()
assert 'No NPCs found.' in request('/LorkhanServer/ui/core/npc_biographies.php?search=05005&letter=A').read().decode()
literal_catalog=request('/LorkhanServer/ui/core/npc_biographies.php?search=%25_').read().decode()
assert literal_catalog.count('<tr data-biography-row ')==1 and 'ZZZ Literal %_ Name</td>' in literal_catalog
assert any(f['action'].endswith('/forms/biography-template-revise') for f in biographies.forms),'factory biography templates are not editable'
biography_import=next(f for f in biographies.forms if f['action'].endswith('/forms/biography-import'))
biography_installation=biography_import['fields']['installation_id']
biography_header=['content_file','record_id','name','core','biography','appearance','personality','relationships','occupation','skills','speech_style','goals','oghma_tags','voice_id','gender','race']
biography_create=next(f for f in biographies.forms if f['action'].endswith('/forms/biography-template-create'))
new_biography_dialog=text.split('id="biography-create-modal"',1)[1].split('</form>',1)[0]
assert set(biography_header).issubset(re.findall(r'<(?:input|textarea)\b[^>]*\bname="([^"]+)"',new_biography_dialog))
assert all(marker in text for marker in ['Extended Profile</h3>','Voice &amp; Meta</h3>','id="biography-display-name"','data-biography-create-close']) and 'id="create-biography"' not in text
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
entry_values=dict(zip(biography_header,['HTTP Test.esp','new_entry_'+biography_suffix,'New Entry '+biography_suffix,'Core summary for table.','Different detailed history.','Golden mask','Curious','{}','Scholar','Alchemy','Formal','Find lost books','Balmora','fixture_voice','Male','Argonian']))
r=request(biography_create['action'],'POST',dict(biography_create['fields'],**entry_values,_csrf=csrf)); entry_body=r.read().decode()
assert r.status==200 and '1 biography template imported.' in entry_body and re.search(r'<td>'+re.escape(entry_values['name'])+r'</td>\s*<td>Core summary for table\.</td>',entry_body),(r.status,entry_body)
entry_export=request('/LorkhanServer/manage/exports/biographies/custom.csv?installation_id='+biography_installation).read().decode('utf-8-sig')
created_entry=next(row for row in csv.DictReader(io.StringIO(entry_export)) if row['record_id']==entry_values['record_id'])
assert all(created_entry[key]==value for key,value in entry_values.items()),created_entry
entry_profile=re.search(r'data-template-name="'+re.escape(entry_values['name'])+r'" data-template-profile="([^"]+)"',entry_body).group(1)
entry_url='/LorkhanServer/ui/core/npc_biographies.php?'+urllib.parse.urlencode({'template':entry_values['name'],'profile_id':entry_profile,'installation_id':biography_installation})
entry_template=json.loads(request(entry_url).read())
assert entry_template['core']==entry_values['core'] and entry_template['npc_static_bio']==entry_values['biography'] and entry_template['speechstyle']=='Formal' and entry_template['voiceid']=='fixture_voice'
entry_edit=dict(entry_template,_csrf=csrf,expected_revision=str(entry_template['current_revision']),core='Updated installation summary.')
revised=request('/LorkhanServer/manage/forms/biography-template-revise','POST',entry_edit)
assert revised.status==200,revised.status
saved_entry=json.loads(request(entry_url).read())
assert saved_entry['core']=='Updated installation summary.' and saved_entry['current_revision']==entry_template['current_revision']+1,saved_entry
entry_export=request('/LorkhanServer/manage/exports/biographies/custom.csv?installation_id='+biography_installation).read().decode('utf-8-sig')
assert next(row for row in csv.DictReader(io.StringIO(entry_export)) if row['record_id']==entry_values['record_id'])['core']=='Updated installation summary.'
assert request('/LorkhanServer/manage/forms/biography-template-revise','POST',entry_edit).status==409
assert request('/LorkhanServer/ui/core/npc_biographies.php?template='+urllib.parse.quote(entry_values['name'])).status==404
assert request(entry_url.replace(biography_installation,str(uuid.uuid4()))).status==404
tampered_entry=dict(entry_edit,expected_revision=str(saved_entry['current_revision']),refid='some_other_record')
assert request('/LorkhanServer/manage/forms/biography-template-revise','POST',tampered_entry).status!=200
assert json.loads(request(entry_url).read())['current_revision']==saved_entry['current_revision']
biography_knowledge_url=entry_url.replace('template=','oghma=')
biography_knowledge_raw=request(biography_knowledge_url).read().decode()
biography_knowledge=json.loads(biography_knowledge_raw)
assert biography_knowledge['total']==54 and biography_knowledge['counts']=={'advanced':1,'basic':53,'denied':1},biography_knowledge
assert len(biography_knowledge['items'])==50 and set(biography_knowledge['categories'])=={'Lore','Public'}
assert 'AdvancedOnlyHiddenText' not in biography_knowledge_raw and 'DeniedAdvancedText' not in biography_knowledge_raw
basic_preview=json.loads(request(biography_knowledge_url+'&search=BiographyNeedle&page=2').read())
assert basic_preview['total']==53 and len(basic_preview['items'])==3 and all(item['level']=='Basic' for item in basic_preview['items'])
assert json.loads(request(biography_knowledge_url+'&search=AdvancedOnlyHiddenText').read())['total']==0
public_preview=json.loads(request(biography_knowledge_url+'&category=Public').read())
assert public_preview['total']==1 and public_preview['items'][0]['description']=='Visible & readable',public_preview
assert request(biography_knowledge_url.replace(biography_installation,str(uuid.uuid4()))).status==404
assert 'data-biography-oghma' in entry_body and 'id="biography-oghma-modal"' in entry_body
npc_knowledge_url='/LorkhanServer/ui/oghma_knowledge.php?'+urllib.parse.urlencode({'installation_id':biography_installation,'profile_id':entry_profile})
npc_knowledge_page=request(npc_knowledge_url+'&search=BiographyNeedle&page=2').read().decode()
assert 'Topic</th><th>Knowledge Level</th><th>Description</th>' in npc_knowledge_page and '53 articles' in npc_knowledge_page and 'Page 2 of 2' in npc_knowledge_page
assert npc_knowledge_page.count('class="knowledge-description"')==3 and 'AdvancedOnlyHiddenText' not in npc_knowledge_page and 'DeniedAdvancedText' not in npc_knowledge_page
assert 'No knowledge articles found matching the current filters.' in request(npc_knowledge_url+'&search=AdvancedOnlyHiddenText').read().decode()
assert request(npc_knowledge_url.replace(biography_installation,str(uuid.uuid4()))).status==404
descriptions,text=parse(request('/LorkhanServer/ui/description_manager.php')); assert descriptions.current==1 and 'id="title-text">Description Manager</span>' in text and 'Descriptions Database' in text
description_form=next(form for form in descriptions.forms if form['action'].endswith('/forms/description-save'))
description_record='ui_description_'+uuid.uuid4().hex
description_text=('A finely engraved blade with a maker’s mark.\n'*8).strip()
description_values=dict(description_form['fields'],_csrf=csrf,content_file='HTTP Test.esp',record_id=description_record,display_name='UI Description Fixture',description=description_text)
description_saved=request(description_form['action'],'POST',description_values)
assert description_saved.status==200
description_page=request('/LorkhanServer/ui/description_manager.php?search='+description_record).read().decode()
description_rows=[json.loads(html.unescape(value)) for value in re.findall(r'data-description-entry="([^"]+)"',description_page)]
description_entry=next(row for row in description_rows if row['record_id']==description_record)
assert description_entry['description']==description_text and '<th>Source</th>' not in description_page and 'id="description-editor"' in description_page
description_export_url='/LorkhanServer/manage/exports/descriptions/custom.csv?installation_id='+description_values['installation_id']
exported_descriptions=list(csv.DictReader(io.StringIO(request(description_export_url).read().decode('utf-8-sig'))))
assert next(row for row in exported_descriptions if row['baseid']==description_record)['description']==description_text
description_values.update(display_name='Edited UI Description Fixture',description='Updated full description.')
assert request(description_form['action'],'POST',description_values).status==200
exported_descriptions=list(csv.DictReader(io.StringIO(request(description_export_url).read().decode('utf-8-sig'))))
assert next(row for row in exported_descriptions if row['baseid']==description_record)['description']=='Updated full description.'
# Optional editor fields must remain blank through save, readback and CSV re-import.
description_values.update(display_name='',description='')
assert request(description_form['action'],'POST',description_values).status==200
blank_descriptions=list(csv.DictReader(io.StringIO(request(description_export_url).read().decode('utf-8-sig'))))
blank_description=next(row for row in blank_descriptions if row['baseid']==description_record)
assert blank_description['name']=='' and blank_description['description']==''
blank_csv=io.StringIO(); blank_writer=csv.DictWriter(blank_csv,fieldnames=['plugin','baseid','name','description']); blank_writer.writeheader(); blank_writer.writerow(blank_description)
assert multipart_request('/LorkhanServer/manage/forms/description-import',{'_csrf':csrf,'installation_id':description_values['installation_id']},'csv_file','blank-description.csv','text/csv',blank_csv.getvalue().encode()).status==200
assert request(description_form['action'],'POST',dict(description_values,record_id='')).status!=200
assert request('/LorkhanServer/manage/forms/description-delete','POST',{'_csrf':csrf,'installation_id':description_values['installation_id'],'description_id':description_entry['description_id']}).status==200
assert 'No descriptions found.' in request('/LorkhanServer/ui/description_manager.php?search='+description_record).read().decode()
oghma_response=request('/LorkhanServer/ui/worldknowledge_upload.php'); text=oghma_response.read().decode(); assert oghma_response.status==200 and 'Oghma Infinium' in text and 'data-oghma-tab="dynamic"' in text
oghma_page,_=parse(request('/LorkhanServer/ui/worldknowledge_upload.php'))
oghma_form=next(form for form in oghma_page.forms if form['action'].endswith('/forms/knowledge'))
oghma_topic='partialcatalog'+uuid.uuid4().hex
oghma_values=dict(oghma_form['fields'],_csrf=csrf,topic=oghma_topic,title='',aliases='The HalfRememberedName',content='DescriptionOnlyNeedle',knowledge_class='scholar',topic_desc_basic='',knowledge_class_basic='common',tags='TagsOnlyNeedle',category='')
assert request(oghma_form['action'],'POST',oghma_values).status==200
oghma_url='/LorkhanServer/ui/worldknowledge_upload.php?'+urllib.parse.urlencode({'installation_id':oghma_values['installation_id']})
partial_page=request(oghma_url+'&search=PARTIALCAT').read().decode()
oghma_entries=[json.loads(html.unescape(value)) for value in re.findall(r"data-oghma-edit='([^']+)'",partial_page)]
optional_article=next(row for row in oghma_entries if row['topic']==oghma_topic)
assert optional_article['topic_desc_basic']=='' and optional_article['category']==''
assert oghma_topic+'</td>' in request(oghma_url+'&search=remembered').read().decode()
assert 'No entries found.' in request(oghma_url+'&search=DescriptionOnlyNeedle').read().decode()
assert 'No entries found.' in request(oghma_url+'&search=TagsOnlyNeedle').read().decode()
assert 'No entries found.' in request(oghma_url+'&search=PARTIALCAT&category=Unrelated').read().decode()
assert 'No entries found.' in request(oghma_url.replace(oghma_values['installation_id'],str(uuid.uuid4()))+'&search=PARTIALCAT').read().decode()
assert request('/LorkhanServer/manage/forms/knowledge-revise','POST',dict(oghma_values,document_id=optional_article['document_id'],content='Edited advanced content.')).status==200
oghma_csv=io.StringIO(); oghma_writer=csv.writer(oghma_csv); oghma_writer.writerow(['topic','topic_desc','knowledge_class','topic_desc_basic','knowledge_class_basic','tags','category','aliases']); oghma_writer.writerow([oghma_topic,'CSV advanced content.','scholar','','common','','','The HalfRememberedName'])
assert multipart_request('/LorkhanServer/manage/forms/knowledge-import',{'_csrf':csrf,'installation_id':oghma_values['installation_id']},'csv_file','optional-oghma.csv','text/csv',oghma_csv.getvalue().encode()).status==200
reloaded_article=next(json.loads(html.unescape(value)) for value in re.findall(r"data-oghma-edit='([^']+)'",request(oghma_url+'&search=PARTIALCAT').read().decode()) if json.loads(html.unescape(value))['topic']==oghma_topic)
assert reloaded_article['topic_desc_basic']=='' and reloaded_article['category']=='' and reloaded_article['content']=='CSV advanced content.'
basic_knowledge_raw=request(biography_knowledge_url+'&search='+oghma_topic).read().decode()
assert 'CSV advanced content.' not in basic_knowledge_raw
basic_knowledge=json.loads(basic_knowledge_raw)
assert basic_knowledge['total']==0 and basic_knowledge['counts']['denied']==biography_knowledge['counts']['denied']+1,basic_knowledge
assert request(oghma_form['action'],'POST',dict(oghma_values,content='')).status!=200
dynamic_headers=['id_quest','stage','topic','topic_desc','knowledge_class','topic_desc_basic','knowledge_class_basic','tags','category']
dynamic_csv=io.StringIO(); dynamic_writer=csv.writer(dynamic_csv); dynamic_writer.writerow(dynamic_headers); dynamic_writer.writerow(['ui_parity_quest','10','ui_parity_topic','Updated after the quest.','','clearall','','','ui_parity'])
dynamic_fields={'_csrf':csrf,'installation_id':oghma_values['installation_id'],'embed':'1'}
dynamic_upload=multipart_request('/LorkhanServer/manage/forms/oghma-dynamic-import',dynamic_fields,'csv_file','dynamic.csv','text/csv',dynamic_csv.getvalue().encode())
assert dynamic_upload.status==200 and 'tab=dynamic' in dynamic_upload.url
dynamic_page=request(oghma_url+'&tab=dynamic&dynamic_cat=ui_parity').read().decode()
dynamic_rows=[json.loads(html.unescape(value)) for value in re.findall(r"data-dynamic-edit='([^']+)'",dynamic_page)]
assert len(dynamic_rows)==1 and dynamic_rows[0]['topic_desc_basic']=='clearall' and dynamic_rows[0]['knowledge_class']==''
dynamic_values=dict(dynamic_fields,**dynamic_rows[0]); dynamic_values['topic_desc']='Edited through the dynamic form.'
assert request('/LorkhanServer/manage/forms/oghma-dynamic-save','POST',dynamic_values).status==200
assert request('/LorkhanServer/manage/forms/oghma-dynamic-save','POST',dynamic_values).status==422
dynamic_latest=json.loads(html.unescape(re.search(r"data-dynamic-edit='([^']+)'",request(oghma_url+'&tab=dynamic&dynamic_cat=ui_parity').read().decode()).group(1)))
assert request('/LorkhanServer/manage/forms/oghma-dynamic-delete','POST',dict(dynamic_fields,mode='single',confirm='Delete',id=dynamic_latest['id'],revision=dynamic_latest['revision'])).status==200
assert 'No dynamic entries found.' in request(oghma_url+'&tab=dynamic&dynamic_cat=ui_parity').read().decode()
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
profile_labels=['Voice sample','Core Profile','Profile LLMs','Prompt head (advanced system guidance)','Backstory','Gender','Race','Skills','Emote Moods Override','Lock This NPC','Oghma Tags','Favorite NPC','Auto Diary','Auto Diary Wait','Visit','Teleport']
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
r=request('/LorkhanServer/ui/core/api_keys.php','POST',{'_csrf':csrf,'add_custom':'1','custom_name':'TTS_HTTP','custom_credential':'fixture-tts-badge-key'})
assert r.status==200 and 'fixture-tts-badge-key' not in r.read().decode()
assert create_tts['fields'].get('option_fields_present')=='1' and 'option__speed' in create_tts['fields'] and 'option__temperature' not in create_tts['fields'],create_tts
tts_name='HTTP TTS '+uuid.uuid4().hex
values=dict(create_tts['fields'],_csrf=csrf,installation_id=valid['installation_id'],name=tts_name,driver='pockettts',endpoint='http://127.0.0.1:8021',model='default',voice='default',language='en',timeout_ms='30000',fallback_male='TestMale',fallback_female='TestFemale',option__speed='1.1',option__temperature='0.6',options_json='{}')
values['credential']='LORKHAN_CUSTOM_TTS_HTTP_API_KEY'
r=request(create_tts['action'],'POST',values); body=r.read().decode(); assert r.status==200 and tts_name in body,(r.status,r.geturl(),body)
tts_id=connector_editor_id(body,tts_name)
tts_export_response=request('/LorkhanServer/manage/exports/connectors/'+tts_id+'.json'); tts_export=json.loads(tts_export_response.read().decode())
assert tts_export['content']['options']['speed']==1.1 and tts_export['content']['options']['temperature']==0.6,tts_export
tts_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?selected='+tts_id))
revise_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/connector-revise') and f['fields'].get('configuration_id')==tts_id)
assert revise_tts['fields']['credential']=='LORKHAN_CUSTOM_TTS_HTTP_API_KEY'
assert 'option__speed' in revise_tts['fields'] and 'option__temperature' in revise_tts['fields'] and revise_tts['fields'].get('option_fields_present')=='1',revise_tts
values=dict(revise_tts['fields'],_csrf=csrf,option__speed='1.25',option__temperature='0.7',change_reason='HTTP labelled TTS options')
r=request(revise_tts['action'],'POST',values); body=r.read().decode(); assert r.status==200 and tts_name in body,(r.status,r.geturl(),body)
tts_export_response=request('/LorkhanServer/manage/exports/connectors/'+tts_id+'.json'); tts_export=json.loads(tts_export_response.read().decode())
assert tts_export_response.status==200 and tts_export['schema']=='lorkhan.connector-export.v1' and tts_export['kind']=='tts_provider' and 'installation_id' not in tts_export and 'api_key' not in json.dumps(tts_export).lower()
assert tts_export['content']['options']['fallback_male']=='TestMale' and tts_export['content']['options']['fallback_female']=='TestFemale',tts_export
assert tts_export['content']['options']['speed']==1.25 and tts_export['content']['options']['temperature']==0.7,tts_export
assert tts_export['content']['credential']=='none' and 'fixture-tts-badge-key' not in json.dumps(tts_export)
# Textareas and provider switches round-trip without leaking fields into a different driver.
instructions='Read the text naturally.\n'+('Keep a steady pace. '*35)
openai_values=dict(values,driver='openai',model='gpt-4o-mini-tts',option__instructions=instructions)
r=request('/LorkhanServer/manage/forms/connector-revise','POST',openai_values); assert r.status==200
openai_page,openai_html=parse(request('/LorkhanServer/ui/core/tts_connectors.php?selected='+tts_id))
openai_form=next(f for f in openai_page.forms if f['action'].endswith('/forms/connector-revise'))
openai_fields=Page(external_form=openai_form['id']); openai_fields.feed(openai_html); openai_form['fields'].update(openai_fields.external_fields)
assert openai_form['fields']['option__instructions']==instructions.strip()
r=request('/LorkhanServer/manage/forms/connector-revise','POST',dict(openai_form['fields'],_csrf=csrf,option__instructions='x'*4097)); assert r.status==422
eleven_values=dict(values,driver='11labs',model='eleven_v3',option__optimize_streaming_latency='2',option__speed='0.9',option__apply_text_normalization='off',option__apply_language_text_normalization='true',option__v3_audio_tags='[whispers]\n[curious]')
r=request('/LorkhanServer/manage/forms/connector-revise','POST',eleven_values); assert r.status==200
eleven_export=json.loads(request('/LorkhanServer/manage/exports/connectors/'+tts_id+'.json').read().decode())['content']['options']
assert eleven_export['v3_audio_tags']=='[whispers]\n[curious]' and eleven_export['apply_language_text_normalization'] is True and eleven_export['optimize_streaming_latency']==2 and eleven_export['speed']==0.9
assert 'instructions' not in eleven_export
# Provider controls round-trip the typed connector, including custom model and language values.
inworld_values=dict(values,driver='inworld',model='inworld-custom-snapshot',language='en-GB',option__workspace='workspaces/fixture',option__temperature='0.8',option__speed='1.1')
r=request('/LorkhanServer/manage/forms/connector-revise','POST',inworld_values); assert r.status==200
inworld_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?selected='+tts_id))
inworld_form=next(f for f in inworld_page.forms if f['action'].endswith('/forms/connector-revise'))
assert inworld_form['fields']['model']=='inworld-custom-snapshot' and inworld_form['fields']['language']=='en-GB'
assert inworld_form['fields']['option__workspace']=='fixture' and inworld_form['fields']['option__temperature']=='0.8'
r=request('/LorkhanServer/manage/forms/connector-revise','POST',dict(inworld_form['fields'],_csrf=csrf,option__workspace='../wrong')); assert r.status==422
bad_tts_values=dict(values,credential='DATABASE_PASSWORD')
r=request('/LorkhanServer/manage/forms/connector-revise','POST',bad_tts_values); assert r.status==422

azure_values=dict(inworld_form['fields'],_csrf=csrf,driver='azure',option__region='eastus',
    option__fixedMood='angry',option__volume='20',option__rate='1.25',option__countour='(11%, +15%)')
r=request('/LorkhanServer/manage/forms/connector-revise','POST',azure_values); assert r.status==200
azure_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?selected='+tts_id))
azure_form=next(f for f in azure_page.forms if f['action'].endswith('/forms/connector-revise'))
assert azure_form['fields']['endpoint']=='https://eastus.tts.speech.microsoft.com'
assert azure_form['fields']['option__fixedMood']=='angry' and azure_form['fields']['option__countour']=='(11%, +15%)'
assert azure_form['fields']['option__volume']=='20' and azure_form['fields']['option__rate']=='1.25'
r=request('/LorkhanServer/manage/forms/connector-revise','POST',dict(azure_form['fields'],_csrf=csrf,option__region='evil.example/')); assert r.status==422
tts_badge_html=request('/LorkhanServer/ui/core/tts_connectors.php?selected='+tts_id).read().decode()
assert 'fixture-tts-badge-key' not in tts_badge_html and 'id="tts_credential"' in tts_badge_html
assert tts_badge_html.index('🟢 Custom Tts Http') < tts_badge_html.index('— Missing Key —')
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
clone_tts_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?edit='+clone_tts_id))
assert next(f for f in clone_tts_page.forms if f['action'].endswith('/forms/connector-revise'))['fields']['credential']=='LORKHAN_CUSTOM_TTS_HTTP_API_KEY'
r=request('/LorkhanServer/manage/forms/connector-delete','POST',{'_csrf':csrf,'configuration_id':clone_tts_id,'kind':'tts_provider'}); assert r.status==200
tts_export['name']=tts_name+' imported'; tts_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?import=1&embed=1'))
import_tts=next(f for f in tts_page.forms if f['action'].endswith('/forms/connector-import'))
tts_export['content']['credential']='LORKHAN_CUSTOM_TTS_HTTP_API_KEY'
r=request(import_tts['action'],'POST',dict(import_tts['fields'],_csrf=csrf,installation_id=valid['installation_id'],kind='tts_provider',connector_json=json.dumps(tts_export))); body=r.read().decode()
assert r.status==200 and tts_export['name'] in body,(r.status,r.geturl(),body)
assert import_tts['fields'].get('embed')=='1' and 'embed=1' in r.geturl() and 'edit=' in r.geturl() and r.geturl().count('?')==1,r.geturl()
import_tts_id=connector_editor_id(body,tts_export['name'])
imported_tts_page,_=parse(request('/LorkhanServer/ui/core/tts_connectors.php?edit='+import_tts_id))
assert next(f for f in imported_tts_page.forms if f['action'].endswith('/forms/connector-revise'))['fields']['credential']=='none'
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
match=re.search(r'/exports/playthroughs/([0-9a-f-]{36})\.json',body); assert match,body
playthrough_id=match.group(1)
selected_playthrough,selected_body=parse(request('/LorkhanServer/ui/playthrough_manager.php?playthrough_id='+playthrough_id+'&installation_id='+valid['installation_id']+'&embed=1'))
assert 'Selected Playthrough</h2>' in selected_body and 'Export Profile Snapshot</h2>' in selected_body and 'class="backup-item selected"' in selected_body
assert all(marker in selected_body for marker in ['Conversations, source events, knowledge, configuration, credentials and audio are not included','not stored database backups','Snapshot JSON','Create playthrough'])
selected_import=next(f for f in selected_playthrough.forms if f['action'].endswith('/forms/playthrough-import'))
assert selected_import['fields']['installation_id']==valid['installation_id'] and selected_import['fields']['profile_id']==profile_id and selected_import['fields']['playthrough_id']==playthrough_id
invalid_playthrough,invalid_body=parse(request('/LorkhanServer/ui/playthrough_manager.php?playthrough_id='+str(uuid.uuid4())+'&installation_id=invalid'))
assert 'Selected Playthrough</h2>' in invalid_body and invalid_playthrough.forms[0]['fields']['installation_id']==valid['installation_id']
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
assert 'id="narrative-edit-'+narrative_id+'"' in body and 'class="event-table"' in body
_,narrative_filtered=parse(request('/LorkhanServer/ui/narrative_manager.php?state=summary'))
assert narrative_title not in narrative_filtered
_,narrative_filtered=parse(request('/LorkhanServer/ui/narrative_manager.php?q='+urllib.parse.quote(narrative_title)))
assert narrative_title in narrative_filtered and '1 entries · Page 1 of 1' in narrative_filtered
# A real diary must appear in the calendar and escaped modal, and stay playthrough-scoped.
diary_url='/LorkhanServer/ui/events-memories.php?'+urllib.parse.urlencode({'tab':'diaries','installation_id':valid['installation_id'],'playthrough_id':playthrough_id})
_,diary_unselected=parse(request(diary_url))
assert 'Select a date to view diary entries.' in diary_unselected and 'has-event' in diary_unselected
diary_url+='&q='+urllib.parse.quote(narrative_title)
diary_page,diary_html=parse(request(diary_url))
assert narrative_text in diary_html and 'id="entry-'+narrative_id+'"' in diary_html and 'has-event' in diary_html and 'data-reader-form' in diary_html
assert 'Read / Edit' not in diary_html and 'id="edit-'+narrative_id+'"' in diary_html and 'id="delete-'+narrative_id+'"' in diary_html
assert 'Save Changes' in diary_html and 'Edit the content of the diary entry below.' in diary_html
# Use the existing mock XTTS connector with this saved diary's author; restore all fixture settings afterward.
diary_psql=['psql','-h','127.0.0.1','-p',sys.argv[3] if len(sys.argv)>3 else '55463','-d','lorkhan_management_http','-v','ON_ERROR_STOP=1','-At']
def diary_sql(sql): return subprocess.run(diary_psql,input=sql,text=True,capture_output=True,check=True).stdout.strip()
diary_profile=json.loads(diary_sql(f"SELECT row_to_json(x) FROM (SELECT p.core_profile_id,p.current_revision,r.content FROM lorkhan_internal.profiles p JOIN lorkhan_internal.profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id='{profile_id}') x;"))
diary_core=diary_profile['core_profile_id'] or diary_sql(f"SELECT core_profile_id FROM lorkhan_internal.core_profiles WHERE installation_id='{valid['installation_id']}' AND default_npc=true AND deleted_at IS NULL LIMIT 1;")
diary_core_row=json.loads(diary_sql(f"SELECT row_to_json(x) FROM (SELECT c.current_revision,r.content FROM lorkhan_internal.core_profiles c JOIN lorkhan_internal.core_profile_revisions r ON r.core_profile_id=c.core_profile_id AND r.revision=c.current_revision WHERE c.core_profile_id='{diary_core}') x;"))
def diary_document(table,key,id,revision,content):
    encoded=json.dumps(content).replace("'","''")
    diary_sql(f"UPDATE lorkhan_internal.{table} SET content='{encoded}'::jsonb WHERE {key}='{id}' AND revision={revision};")
diary_tts_id=str(uuid.uuid4())
diary_sql(f"INSERT INTO lorkhan_internal.configuration_sets(configuration_id,installation_id,kind,name,created_at) VALUES('{diary_tts_id}','{valid['installation_id']}','tts_provider','Diary mock TTS',clock_timestamp()); INSERT INTO lorkhan_internal.configuration_revisions(configuration_id,revision,content,change_reason,created_at) SELECT '{diary_tts_id}',1,r.content,'diary fixture',clock_timestamp() FROM lorkhan_internal.configuration_revisions r JOIN lorkhan_internal.configuration_sets c ON c.configuration_id=r.configuration_id AND c.current_revision=r.revision WHERE c.configuration_id='{sync_tts_id}';")
try:
    author_content=dict(diary_profile['content'],voice={'id':batch_voice})
    core_content=dict(diary_core_row['content']); core_content['routing']=dict(core_content.get('routing',{}),tts_configuration_id=diary_tts_id)
    diary_document('profile_revisions','profile_id',profile_id,diary_profile['current_revision'],author_content)
    diary_document('core_profile_revisions','core_profile_id',diary_core,diary_core_row['current_revision'],core_content)
    diary_payload={'installation_id':valid['installation_id'],'narrative_id':narrative_id}
    # Earlier tests intentionally exhaust this disposable browser's preview window.
    diary_sql("UPDATE lorkhan_internal.browser_sessions SET tts_preview_count=0,tts_preview_window_started_at=NULL;")
    before_diary_audio=len(VoiceProvider.speech_requests)
    response=json_request('/LorkhanServer/manage/api/v1/diary-audio','POST',diary_payload,csrf); diary_clip=response.read()
    assert response.status==200 and diary_clip.startswith(b'RIFF') and response.headers.get('X-Diary-Audio-Cache')=='miss',(response.status,diary_clip[:200])
    response=json_request('/LorkhanServer/manage/api/v1/diary-audio','POST',diary_payload,csrf)
    assert response.status==200 and response.read()==diary_clip and response.headers.get('X-Diary-Audio-Cache')=='hit'
    assert len(VoiceProvider.speech_requests)==before_diary_audio+1 and VoiceProvider.speech_requests[-1]['speaker_wav']==batch_voice
    wrong=json_request('/LorkhanServer/manage/api/v1/diary-audio','POST',dict(diary_payload,installation_id=str(uuid.uuid4())),csrf)
    assert wrong.status==404 and len(VoiceProvider.speech_requests)==before_diary_audio+1
    invalid=json_request('/LorkhanServer/manage/api/v1/diary-audio','POST',diary_payload,'invalid-csrf')
    assert not invalid.headers.get('Content-Type','').startswith('audio/') and len(VoiceProvider.speech_requests)==before_diary_audio+1
    assert 'data-narrative-id="'+narrative_id+'"' in diary_html and 'data-diary-endpoint=' in diary_html
    # Changed persisted speech inputs must miss, while removed entries must not reuse private cached audio.
    diary_original_text=narrative_text.replace("'","''")
    alternate_author=str(uuid.uuid4())
    try:
        diary_sql(f"UPDATE lorkhan_internal.narrative_records SET content='Changed diary fixture.' WHERE narrative_id='{narrative_id}';")
        changed=json_request('/LorkhanServer/manage/api/v1/diary-audio','POST',diary_payload,csrf)
        changed_audio=changed.read()
        assert changed.status==200 and changed.headers.get('X-Diary-Audio-Cache')=='miss' and changed_audio.startswith(b'RIFF'), (changed.status,changed.headers.get('X-Diary-Audio-Cache'),len(changed_audio),changed_audio[:4])
        assert len(VoiceProvider.speech_requests)==before_diary_audio+2 and VoiceProvider.speech_requests[-1]['text']=='Changed diary fixture.'
        diary_sql(f"INSERT INTO lorkhan_internal.profiles(profile_id,installation_id,name,actor_identity,core_profile_id,created_at) VALUES('{alternate_author}','{valid['installation_id']}','Second diary author','{{}}','{diary_core}',clock_timestamp()); INSERT INTO lorkhan_internal.profile_revisions(profile_id,revision,content,change_reason,created_at) SELECT '{alternate_author}',1,content,'diary author fixture',clock_timestamp() FROM lorkhan_internal.profile_revisions WHERE profile_id='{profile_id}' AND revision={diary_profile['current_revision']}; UPDATE lorkhan_internal.narrative_records SET profile_id='{alternate_author}' WHERE narrative_id='{narrative_id}';")
        changed=json_request('/LorkhanServer/manage/api/v1/diary-audio','POST',diary_payload,csrf)
        changed_audio=changed.read()
        assert changed.status==200 and changed.headers.get('X-Diary-Audio-Cache')=='miss' and changed_audio.startswith(b'RIFF'), (changed.status,changed.headers.get('X-Diary-Audio-Cache'),len(changed_audio),changed_audio[:4])
        assert len(VoiceProvider.speech_requests)==before_diary_audio+3
        diary_sql(f"UPDATE lorkhan_internal.configuration_revisions SET content=jsonb_set(content,'{{language}}','\"fr\"'::jsonb) WHERE configuration_id='{diary_tts_id}' AND revision=1;")
        changed=json_request('/LorkhanServer/manage/api/v1/diary-audio','POST',diary_payload,csrf)
        changed_audio=changed.read()
        assert changed.status==200 and changed.headers.get('X-Diary-Audio-Cache')=='miss' and changed_audio.startswith(b'RIFF'), (changed.status,changed.headers.get('X-Diary-Audio-Cache'),len(changed_audio),changed_audio[:4])
        assert len(VoiceProvider.speech_requests)==before_diary_audio+4 and VoiceProvider.speech_requests[-1]['language']=='fr'
        diary_sql(f"UPDATE lorkhan_internal.narrative_records SET deleted_at=clock_timestamp() WHERE narrative_id='{narrative_id}';")
        removed=json_request('/LorkhanServer/manage/api/v1/diary-audio','POST',diary_payload,csrf)
        assert removed.status==404 and len(VoiceProvider.speech_requests)==before_diary_audio+4
    finally:
        diary_sql(f"UPDATE lorkhan_internal.narrative_records SET profile_id='{profile_id}',content='{diary_original_text}',deleted_at=NULL WHERE narrative_id='{narrative_id}'; DELETE FROM lorkhan_internal.profile_revisions WHERE profile_id='{alternate_author}'; DELETE FROM lorkhan_internal.profiles WHERE profile_id='{alternate_author}';")

finally:
    diary_document('profile_revisions','profile_id',profile_id,diary_profile['current_revision'],diary_profile['content'])
    diary_document('core_profile_revisions','core_profile_id',diary_core,diary_core_row['current_revision'],diary_core_row['content'])
    diary_sql(f"DELETE FROM lorkhan_internal.configuration_revisions WHERE configuration_id='{diary_tts_id}'; DELETE FROM lorkhan_internal.configuration_sets WHERE configuration_id='{diary_tts_id}';")
diary_revise=next(f for f in diary_page.forms if f['action'].endswith('/forms/narrative-revise') and f['fields'].get('narrative_id')==narrative_id)
assert diary_revise['fields']['title']==narrative_title and diary_revise['fields']['kind']=='diary'
assert '<input type="hidden" name="title"' in diary_html and 'diary-entry-metadata' not in diary_html
_,game_diary_html=parse(request(diary_url+'&calendar=tamrielic&game_year=427&game_month=8'))
assert 'Last Seed, 3E 427' in game_diary_html and 'Fredas' in game_diary_html and 'Not recorded' in game_diary_html and 'game_month=9' in game_diary_html
_,empty_diary_html=parse(request(diary_url+'&date=1900-01-01'))
assert narrative_text not in empty_diary_html and 'No diary entries found for this date.' in empty_diary_html
_,person_diary_html=parse(request(diary_url+'&view=people&person='+profile_id))
assert narrative_text in person_diary_html and 'data-diary-people-search' in person_diary_html
book_url='/LorkhanServer/ui/diary_book.php?'+urllib.parse.urlencode({'installation_id':valid['installation_id'],'playthrough_id':playthrough_id,'person':profile_id})
_,book_html=parse(request(book_url))
assert narrative_text in book_html and 'Print / Save as PDF' in book_html
assert request(book_url.replace(playthrough_id,str(uuid.uuid4()))).status==404
assert request(book_url.replace(valid['installation_id'],str(uuid.uuid4()))).status==404
assert request('/LorkhanServer/ui/diary_book.php').status==400
diary_export=request(diary_url+'&export=1')
assert diary_export.headers.get('Content-Type','').startswith('text/csv') and narrative_text in diary_export.read().decode()
# Isolated rows exercise the complete selected day, chronological ordering and Adventure CSV formatting.
adventure_scope=str(uuid.uuid4())
adventure_sql=f"""
INSERT INTO lorkhan_internal.playthroughs(playthrough_id,installation_id,profile_id,name,created_at)
VALUES ('{adventure_scope}','{valid['installation_id']}','{profile_id}','Adventure UI fixture','1900-01-01');
WITH events AS (
 INSERT INTO public.eventlog(type,data,people,location,localts,gamets)
 SELECT 'chat',CASE WHEN n<3 THEN 'Fargoth' ELSE 'Caius' END||': Adventure fixture '||lpad(n::text,2,'0')||' <script>literal</script> (Context location: duplicate)',
 '|Fargoth| Caius|',CASE WHEN n<3 THEN 'Seyda Neen' ELSE 'Balmora, South Wall Cornerclub' END,CASE WHEN n=25 THEN 1609502400 ELSE 1609416000 END,0
 FROM generate_series(1,25) n ORDER BY n RETURNING rowid
)
INSERT INTO lorkhan_internal.eventlog_metadata(rowid,installation_id,playthrough_id,projection_kind,projection_key,payload)
SELECT rowid,'{valid['installation_id']}','{adventure_scope}','ui_fixture','adventure-ui-'||rowid,
 '{{"calendar":{{"year":427,"month":7,"day":16}}}}'::jsonb FROM events;
"""
adventure_psql=['psql','-h','127.0.0.1','-p',sys.argv[3] if len(sys.argv)>3 else '55463','-d','lorkhan_management_http','-v','ON_ERROR_STOP=1']
subprocess.run(adventure_psql,input=adventure_sql,text=True,capture_output=True,check=True)
adventure_url='/LorkhanServer/ui/events-memories.php?'+urllib.parse.urlencode({'tab':'adventure','installation_id':valid['installation_id'],'playthrough_id':adventure_scope,'date':'2020-12-31'})
_,adventure_page_html=parse(request(adventure_url))
adventure_html=re.search(r'<table class="calendar-event-table adventure-event-table".*?</table>',adventure_page_html,re.S).group(0)
assert adventure_html.count('data-adventure-row=')==24 and adventure_html.index('Adventure fixture 01')<adventure_html.index('Adventure fixture 02')<adventure_html.index('Adventure fixture 10'),(adventure_html.count('data-adventure-row='),re.findall(r'Adventure fixture \d+',adventure_html))
assert 'Current Location: Seyda Neen' in adventure_html and 'Location Change: Balmora, South Wall Cornerclub' in adventure_html
assert 'speaker-even' in adventure_html and 'speaker-odd' in adventure_html and '&lt;script&gt;literal&lt;/script&gt;' in adventure_html and 'Context location: duplicate' not in adventure_html
_,adventure_last_page=parse(request(adventure_url+'&reader_page=2'))
adventure_last=re.search(r'<table class="calendar-event-table adventure-event-table".*?</table>',adventure_last_page,re.S).group(0)
assert adventure_last==adventure_html and 'reader-pagination' not in adventure_last_page and 'class="reader-toolbar"' not in adventure_last_page
assert adventure_html.count('location-change-row')==2 and adventure_html.count('class="speaker-even"')==22
adventure_csv=list(csv.DictReader(io.StringIO(request(adventure_url+'&export=1').read().decode())))
assert len(adventure_csv)==24 and list(adventure_csv[0])==['Context','Nearby People','Location & Tamrielic Time','Time(UTC)']
assert 'fixture 01' in adventure_csv[0]['Context'] and 'fixture 24' in adventure_csv[-1]['Context'] and adventure_csv[0]['Nearby People']=='Fargoth, Caius'
assert adventure_csv[0]['Time(UTC)']=='31-12-2020 12:00:00' and '16 Last Seed' in adventure_csv[0]['Location & Tamrielic Time']
adventure_unselected=adventure_url.replace('&date=2020-12-31','')
_,adventure_unselected_html=parse(request(adventure_unselected))
assert 'Select a date to view events.' in adventure_unselected_html and 'data-adventure-row=' not in adventure_unselected_html and 'export_all=1' in adventure_unselected_html
latest_adventure=list(csv.DictReader(io.StringIO(request(adventure_unselected+'&export=1').read().decode())))
all_adventure=list(csv.DictReader(io.StringIO(request(adventure_unselected+'&export=1&export_all=1').read().decode())))
assert len(latest_adventure)==1 and 'fixture 25' in latest_adventure[0]['Context'] and len(all_adventure)==25
_,adventure_other=parse(request(adventure_url.replace(adventure_scope,playthrough_id)))
assert 'Adventure fixture' not in adventure_other
_,adventure_empty=parse(request(adventure_url.replace('2020-12-31','1900-01-01')))
assert 'No events found for this date.' in adventure_empty and 'data-adventure-row=' not in adventure_empty
# Calendar diaries show a complete day oldest-first; author view shows all matches newest-first.
subprocess.run(adventure_psql,input=f"""
INSERT INTO lorkhan_internal.narrative_records(narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,created_at)
SELECT gen_random_uuid(),'{valid['installation_id']}','{profile_id}','{adventure_scope}','diary',
'DiaryDayFixture '||lpad(n::text,2,'0'),'DiaryDayFixture '||lpad(n::text,2,'0'),'{{}}',to_timestamp(1609416000+n)
FROM generate_series(1,24) n;
""",text=True,capture_output=True,check=True)
diary_day_path=adventure_url.replace('tab=adventure','tab=diaries')+'&q=DiaryDayFixture'
_,diary_day_html=parse(request(diary_day_path))
diary_day_table=re.search(r'<table class="calendar-event-table".*?</table>',diary_day_html,re.S).group(0)
assert diary_day_table.count('class="log-content-link"')==24 and diary_day_table.index('DiaryDayFixture 01')<diary_day_table.index('DiaryDayFixture 24')
assert 'reader-pagination' not in diary_day_html and 'class="reader-toolbar"' not in diary_day_html and diary_day_html.count('data-reader-stop')==1
_,diary_day_stale=parse(request(diary_day_path+'&reader_page=2'))
assert diary_day_table==re.search(r'<table class="calendar-event-table".*?</table>',diary_day_stale,re.S).group(0)
diary_person_path=diary_day_path.replace('&date=2020-12-31','')+'&view=people&person='+profile_id
_,diary_person_html=parse(request(diary_person_path))
diary_person_table=re.search(r'<table class="calendar-event-table".*?</table>',diary_person_html,re.S).group(0)
assert diary_person_table.count('class="log-content-link"')==24 and diary_person_table.index('DiaryDayFixture 24')<diary_person_table.index('DiaryDayFixture 01')
for path,order in [(diary_day_path,range(1,25)),(diary_person_path,range(24,0,-1))]:
    diary_rows=list(csv.DictReader(io.StringIO(request(path+'&export=1').read().decode())))
    assert [row['Content'] for row in diary_rows]==[f'DiaryDayFixture {n:02d}' for n in order]
subprocess.run(adventure_psql,input=f"DELETE FROM public.eventlog WHERE rowid IN (SELECT rowid FROM lorkhan_internal.eventlog_metadata WHERE playthrough_id='{adventure_scope}'); DELETE FROM lorkhan_internal.playthroughs WHERE playthrough_id='{adventure_scope}';",text=True,capture_output=True,check=True)
empty_export=request(diary_url+'&export=1&date=1900-01-01')
assert narrative_text not in empty_export.read().decode()
revise_narrative=diary_revise
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
# Keep the export regression in the existing isolated HTTP database, never the live playthrough.
response_session=str(uuid.uuid4()); response_turn=str(uuid.uuid4())
response_literal='Quoted "word", literal \\uNotUnicode and trailing slash \\'+ '\nSecond line.'
response_manifest=json.dumps({'message':{'_prompt':{'_messages':[{'role':'user','content':response_literal}]}},'api_key':'must-not-export-fixture'})
response_sql=f"""
INSERT INTO lorkhan_internal.sessions(session_id,installation_id,profile_id,playthrough_id,generation,content_fingerprint,openmw_version,openmw_commit,lua_api_revision,client_version,platform,state,created_at)
VALUES ('{response_session}','{valid['installation_id']}','{profile_id}','{playthrough_id}',999000,'sha256:'||repeat('0',64),'fixture',repeat('0',40),1,'fixture','fixture','ended',now());
INSERT INTO lorkhan_internal.turns(turn_id,request_id,message_id,session_id,generation,input_kind,input_language,input_text,speaker,target,audience,context,state,accepted_at,completed_at)
VALUES ('{response_turn}',gen_random_uuid(),gen_random_uuid(),'{response_session}',999000,'text','en','Export fixture input','{{}}','{{}}','[]','{{}}','complete',now(),now());
INSERT INTO lorkhan_internal.turn_provider_snapshots(turn_id,source_manifest,input_sha256,created_at)
VALUES ('{response_turn}',$export${response_manifest}$export$::jsonb,repeat('0',64),now());
WITH inserted AS (
 INSERT INTO public.log(localts,response,prompt,url)
 SELECT 1609416000+n/2,'ResponseExportFixture '||lpad(n::text,3,'0'),'must-not-export-raw-prompt','must-not-export-raw-url' FROM generate_series(1,61) n ORDER BY n RETURNING rowid
)
INSERT INTO lorkhan_internal.log_metadata(rowid,turn_id,request_id) SELECT rowid,'{response_turn}',gen_random_uuid() FROM inserted;
"""
subprocess.run(adventure_psql,input=response_sql,text=True,capture_output=True,check=True)
response_page_path='/LorkhanServer/ui/events-memories.php?'+urllib.parse.urlencode({'tab':'responselog','installation_id':valid['installation_id'],'playthrough_id':playthrough_id,'q':'ResponseExportFixture'})
_,response_page=parse(request(response_page_path))
assert response_page.count('data-log-open=')==50 and '61 rows' in response_page
assert response_page.index('ResponseExportFixture 061')<response_page.index('ResponseExportFixture 060')<response_page.index('ResponseExportFixture 012')
_,response_last_page=parse(request(response_page_path+'&reader_page=2'))
assert response_last_page.count('data-log-open=')==11
assert response_last_page.index('ResponseExportFixture 011')<response_last_page.index('ResponseExportFixture 010')<response_last_page.index('ResponseExportFixture 001')
response_export_path=response_page_path+'&export=1&reader_page=2'
response_export=request(response_export_path)
assert response_export.headers.get_content_type()=='text/csv' and 'attachment' in response_export.headers['Content-Disposition']
response_csv=csv.DictReader(io.StringIO(response_export.read().decode()))
assert response_csv.fieldnames==['rowid','time_utc','ai_response','oghma_topic','prompt','http_request']
response_rows=list(response_csv)
assert [row['ai_response'] for row in response_rows]==[f'ResponseExportFixture {n:03d}' for n in range(61,0,-1)]
for response_row in response_rows:
    response_prompt=json.loads(response_row['prompt'])
    assert response_prompt=={'messages':[{'role':'user','content':response_literal}]}
    assert response_row['oghma_topic']=='None' and response_row['http_request']=='text: Export fixture input'
empty_response_csv=list(csv.reader(io.StringIO(request(response_export_path.replace('q=ResponseExportFixture','q=export-no-match-fixture-926407')).read().decode())))
assert len(empty_response_csv)==1 and empty_response_csv[0]==response_csv.fieldnames
subprocess.run(adventure_psql,input=f"DELETE FROM public.log WHERE rowid IN (SELECT rowid FROM lorkhan_internal.log_metadata WHERE turn_id='{response_turn}'); DELETE FROM lorkhan_internal.log_metadata WHERE turn_id='{response_turn}'; DELETE FROM lorkhan_internal.turns WHERE turn_id='{response_turn}'; DELETE FROM lorkhan_internal.sessions WHERE session_id='{response_session}';",text=True,capture_output=True,check=True)
# Books follow game time and numeric row IDs, even when wall-clock arrival runs backwards.
book_order_sql=f"""
WITH inserted AS (
 INSERT INTO public.books(title,content,localts,gamets,ts)
 SELECT 'BookOrderFixture '||lpad(n::text,3,'0'),'Read-only book fixture',1609416000-n,n/2,n
 FROM generate_series(1,151) n ORDER BY n RETURNING rowid,title
)
INSERT INTO lorkhan_internal.book_metadata(rowid,installation_id,playthrough_id,record_id)
SELECT rowid,'{valid['installation_id']}','{playthrough_id}',title FROM inserted;
"""
subprocess.run(adventure_psql,input=book_order_sql,text=True,capture_output=True,check=True)
book_order_path='/LorkhanServer/ui/events-memories.php?'+urllib.parse.urlencode({'tab':'books','installation_id':valid['installation_id'],'playthrough_id':playthrough_id,'q':'BookOrderFixture'})
_,book_order_page=parse(request(book_order_path))
assert book_order_page.count('data-log-open=')==150 and book_order_page.index('BookOrderFixture 151')<book_order_page.index('BookOrderFixture 150')<book_order_page.index('BookOrderFixture 002')
_,book_order_last=parse(request(book_order_path+'&reader_page=2'))
assert book_order_last.count('data-log-open=')==1 and 'BookOrderFixture 001' in book_order_last
book_order_export=list(csv.DictReader(io.StringIO(request(book_order_path+'&reader_page=2&export=1').read().decode())))
assert [row['Title'] for row in book_order_export]==[f'BookOrderFixture {n:03d}' for n in range(151,0,-1)]
_,book_order_filtered=parse(request(book_order_path.replace('q=BookOrderFixture','q=BookOrderFixture+003')))
assert book_order_filtered.count('data-log-open=')==1 and 'BookOrderFixture 003' in book_order_filtered
_,book_order_empty=parse(request(book_order_path.replace('q=BookOrderFixture','q=BookOrderFixture-no-match')))
assert 'No books match this filter.' in book_order_empty and 'data-log-open=' not in book_order_empty
subprocess.run(adventure_psql,input="DELETE FROM public.books WHERE title LIKE 'BookOrderFixture %'; DELETE FROM lorkhan_internal.book_metadata WHERE record_id LIKE 'BookOrderFixture %';",text=True,capture_output=True,check=True)
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
assert 'Relationship LLM Logs' in body and 'Total Evaluations' in body and 'Manage relationships &amp; change history' in body
relationship_clear_path='/LorkhanServer/manage/api/v1/relationship-logs/clear'
relationship_clear_values={'installation_id':valid['installation_id'],'confirm':'Clear','age':'all'}
assert json_request(relationship_clear_path,'POST',relationship_clear_values).status==401
assert json_request(relationship_clear_path,'POST',dict(relationship_clear_values,age='invalid'),csrf).status==422
assert json_request(relationship_clear_path,'POST',dict(relationship_clear_values,confirm=''),csrf).status==422
assert json_request(relationship_clear_path,'POST',relationship_clear_values,csrf).status==200
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
assert 'name="direction"' in build_body and 'maxlength="2000"' in build_body
assert request(build_form['action'],'POST',dict(build_values,direction='x'*2001)).status==422
r=request(build_form['action'],'POST',build_values); build_page,build_body=parse(r)
assert r.status==200 and 'relationship_build_no_connector' in r.geturl() and 'role="alert"' in build_body
# Preview uses the authenticated JSON form boundary; failures never mutate relationships.
preview_path='/LorkhanServer/manage/forms/relationship-preview'
preview_values=dict(build_values,operation='generate')
r=request(preview_path,'POST',preview_values,accept='application/json')
preview_error=json.loads(r.read())
assert r.status==422 and preview_error['error']=='relationship_build_no_connector',(r.status,preview_error)
assert request(preview_path,'POST',dict(preview_values,history_limit='101'),accept='application/json').status==422
assert request(preview_path,'POST',dict(preview_values,operation='invalid'),accept='application/json').status==422
assert request(preview_path,'POST',dict(preview_values,operation='status',job_id=str(uuid.uuid4())),accept='application/json').status==404
assert request(preview_path,'POST',dict(preview_values,_csrf='wrong'),accept='application/json').status==401
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
npc_relationship_url='/LorkhanServer/ui/core/npc_master.php?'+urllib.parse.urlencode({'rel_profile':relationship_values['profile_id'],'rel_playthrough':playthrough_id})
npc_relationship_page,npc_relationship_body=parse(request(npc_relationship_url))
assert 'npc-rel-table' in npc_relationship_body and 'Recent Relationship Changes' in npc_relationship_body
assert 'class="npc-relationship-history-item"' in npc_relationship_body and 'class="relationship-change-delta ' in npc_relationship_body
assert 'class="relationship-change-target"' in npc_relationship_body and 'class="npc-relationship-history-time"' in npc_relationship_body
assert 'class="npc-rel-scope" action="/LorkhanServer/ui/core/npc_master.php"' in npc_relationship_body
assert 'href="/LorkhanServer/ui/relationship_logs.php?' in npc_relationship_body
assert 'action="/ui/' not in npc_relationship_body and 'href="/ui/' not in npc_relationship_body
relationship_scope_form=next(f for f in npc_relationship_page.forms if f['fields'].get('rel_profile')==relationship_values['profile_id'] and f['action']=='/LorkhanServer/ui/core/npc_master.php')
relationship_scope_response=request(relationship_scope_form['action']+'?'+urllib.parse.urlencode(relationship_scope_form['fields']))
assert relationship_scope_response.status==200
assert request('/LorkhanServer/ui/relationship_logs.php?installation_id='+valid['installation_id']).status==200
assert any(f['fields'].get('relationship_id')==relationship_id and f['fields'].get('relationship_page')=='npc' for f in npc_relationship_page.forms)
r=request(relationship_edit['action'],'POST',dict(relationship_edit['fields'],_csrf=csrf,relationship_page='npc',ui_q='HTTP',disposition='99',affinity='6'))
assert r.status==200 and '/ui/core/npc_master.php?' in r.geturl() and 'rel_profile='+relationship_values['profile_id'] in r.geturl() and 'relationship_revision_conflict' in r.geturl()
npc_row_form=next(f for f in npc_relationship_page.forms if f['fields'].get('relationship_id')==relationship_id and f['action'].endswith('/forms/relationships'))
r=request(npc_row_form['action'],'POST',dict(npc_row_form['fields'],_csrf=csrf,affinity='17',disposition='20',relationship_type='trusted_companion',reason='NPC table save',custom_info=''))
npc_saved,npc_saved_body=parse(r)
assert r.status==200 and '/ui/core/npc_master.php?' in r.geturl() and 'Relationship changes saved.' in npc_saved_body
relationship_page,body=parse(request('/LorkhanServer/ui/relationship_logs.php?installation_id='+relationship_values['installation_id']))
assert 'NPC table save' in body
npc_clear=next(f for f in npc_saved.forms if f['action'].endswith('/forms/relationship-clear') and f['fields'].get('profile_id')==relationship_values['profile_id'])
csrf_rejected=request(npc_clear['action'],'POST',dict(npc_clear['fields'],_csrf='bad',confirm_clear='Clear'))
assert csrf_rejected.status==200 and '/ui/home.php' in csrf_rejected.geturl(),(csrf_rejected.status,csrf_rejected.geturl())
csrf_probe,_=parse(request(npc_relationship_url))
assert next(f for f in csrf_probe.forms if f['action']==npc_clear['action'] and f['fields'].get('profile_id')==relationship_values['profile_id'])['fields']['snapshot_token']==npc_clear['fields']['snapshot_token']
assert request(npc_clear['action'],'POST',dict(npc_clear['fields'],_csrf=csrf,confirm_clear='no')).status==422
r=request(npc_clear['action'],'POST',dict(npc_clear['fields'],_csrf=csrf,confirm_clear='Clear',snapshot_token='0'*32))
assert r.status==200 and 'relationship_revision_conflict' in r.geturl()
relationship_delete=next(f for f in relationship_page.forms if f['fields'].get('relationship_id')==relationship_id and f['action'].endswith('/forms/relationship-delete'))
r=request(relationship_delete['action'],'POST',dict(relationship_delete['fields'],_csrf=csrf,expected_revision='1')); assert r.status==200 and 'relationship_revision_conflict' in r.geturl()
r=request(relationship_delete['action'],'POST',dict(relationship_delete['fields'],_csrf=csrf)); relationship_page,body=parse(r)
assert r.status==200 and not any(f['fields'].get('relationship_id')==relationship_id for f in relationship_page.forms)
assert 'HTTP relationship create' in body and 'HTTP relationship edit' in body and 'management delete' in body
assert 'Recent changes' in body and '>7 shown<' in body
# Clearing a newly created row uses the same page-wide scope token and retains its audit trail.
r=request(relationship_create['action'],'POST',dict(relationship_values,_csrf=csrf)); assert r.status==200
npc_clear_page,_=parse(request(npc_relationship_url))
npc_clear=next(f for f in npc_clear_page.forms if f['action'].endswith('/forms/relationship-clear') and f['fields'].get('profile_id')==relationship_values['profile_id'])
r=request(npc_clear['action'],'POST',dict(npc_clear['fields'],_csrf=csrf,confirm_clear='Clear')); clear_body=r.read().decode()
assert r.status==200 and 'relationships_cleared' in r.geturl() and 'Their change history was kept.' in clear_body
# The NPC Save transaction owns staged additions, edits, deletions and protected clear-all.
stage_target_name='HTTP staged target '+uuid.uuid4().hex
stage_document={'installation_id':valid['installation_id'],'name':stage_target_name,
    'actor_identity':dict(relationship_identity,record_id='http_staged_target',display_name=stage_target_name,refnum={'index':98766,'content_file':0}),
    'content':{'biography':'A bounded staging fixture.'}}
r=json_request('/LorkhanServer/manage/api/v1/profiles','POST',stage_document,csrf)
assert r.status==201
stage_target_id=json.loads(r.read())['profile_id']

# Read the actual editor revision and fields rather than assuming a fixture revision.
def staged_npc_form(owner=profile_id):
    page,body=parse(request('/LorkhanServer/ui/core/npc_master.php?'+urllib.parse.urlencode({'rel_profile':owner,'rel_playthrough':playthrough_id})))
    form=next(f for f in page.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==owner)
    revision=int(re.search('data-profile-form="management-form-profile-'+owner+r'" data-profile-revision="(\d+)"',body)[1])
    fields=Page(external_form='management-form-profile-'+owner); fields.feed(body)
    return form,dict(form['fields'],**fields.external_fields,_csrf=csrf),{'profile_revision':revision,'playthrough_id':playthrough_id,'updates':[],'additions':[],'deletes':[]},page

stage_form,stage_values,stage_batch,_=staged_npc_form()
stage_add={'actor_profile_id':stage_target_id,'affinity':15,'disposition':20,'relationship_type':'professional','reason':'Staged addition','custom_info':'Private staged note','details':{'relation':'mentor','note':'Shared a drink','best':'Saved the traveller','worst':'Broke a promise'}}
stage_bad=dict(stage_batch,additions=[stage_add,dict(stage_add,affinity=101)])
r=request(stage_form['action'],'POST',dict(stage_values,biography='Must roll back with the invalid row',npc_relationship_edits=json.dumps(stage_bad)))
assert r.status==422 and 'invalid_relationship_value' in r.read().decode() and staged_npc_form()[2]['profile_revision']==stage_batch['profile_revision']
assert not any(f['fields'].get('relationship_id') for f in staged_npc_form()[3].forms if f['fields'].get('profile_id')==profile_id)
stage_bad=dict(stage_batch,additions=[dict(stage_add,details={'best':'x'*1025})])
assert request(stage_form['action'],'POST',dict(stage_values,npc_relationship_edits=json.dumps(stage_bad))).status==422
assert staged_npc_form()[2]['profile_revision']==stage_batch['profile_revision']
stage_batch['additions']=[stage_add]
r=request(stage_form['action'],'POST',dict(stage_values,npc_relationship_edits=json.dumps(stage_batch))); stage_saved,stage_body=parse(r)
assert r.status==200 and 'rel_profile='+profile_id in r.geturl() and 'Private staged note' in stage_body,(r.status,r.geturl(),re.findall(r'role="alert"[^>]*>(.*?)</',stage_body,re.S))
assert request(stage_form['action'],'POST',dict(stage_values,npc_relationship_edits=json.dumps(stage_batch))).status==409
stage_row=next(f for f in stage_saved.forms if f['action'].endswith('/forms/relationships') and f['fields'].get('relationship_id') and f['fields'].get('profile_id')==profile_id)
stage_id=stage_row['fields']['relationship_id']
stage_form,stage_values,stage_batch,_=staged_npc_form()
stage_row_fields=Page(external_form=stage_row['id']); stage_row_fields.feed(stage_body)
stage_update=dict(stage_row['fields'],**stage_row_fields.external_fields)
stage_update['details']={key:stage_update.pop('details['+key+']') for key in ['relation','note','best','worst']}
assert stage_update['details']==stage_add['details']
stage_update['details']['best']=''
stage_update.update(affinity=30,custom_info='Updated private staged note',reason='Staged update')
stage_batch['updates']=[dict(stage_update,expected_revision=0)]
assert request(stage_form['action'],'POST',dict(stage_values,npc_relationship_edits=json.dumps(stage_batch))).status==422
assert staged_npc_form()[2]['profile_revision']==stage_batch['profile_revision']
stage_batch['updates']=[stage_update]
r=request(stage_form['action'],'POST',dict(stage_values,npc_relationship_edits=json.dumps(stage_batch))); stage_saved,stage_body=parse(r)
assert r.status==200 and 'Updated private staged note' in stage_body,(r.status,re.findall(r'role="alert"[^>]*>(.*?)</',stage_body,re.S))
saved_details=Page(external_form=stage_row['id']); saved_details.feed(stage_body)
assert saved_details.external_fields['details[best]']=='' and saved_details.external_fields['details[relation]']=='mentor'
stage_form,stage_values,stage_batch,stage_saved=staged_npc_form()
stage_batch['updates']=[stage_update]
assert request(stage_form['action'],'POST',dict(stage_values,npc_relationship_edits=json.dumps(stage_batch))).status==409
assert staged_npc_form()[2]['profile_revision']==stage_batch['profile_revision']
foreign_form,foreign_values,foreign_batch,_=staged_npc_form(stage_target_id)
foreign_batch['deletes']=[{'relationship_id':stage_id,'expected_revision':2}]
assert request(foreign_form['action'],'POST',dict(foreign_values,npc_relationship_edits=json.dumps(foreign_batch))).status==404
assert staged_npc_form(stage_target_id)[2]['profile_revision']==foreign_batch['profile_revision']
stage_batch.update(updates=[],deletes=foreign_batch['deletes'])
r=request(stage_form['action'],'POST',dict(stage_values,npc_relationship_edits=json.dumps(stage_batch))); assert r.status==200
assert not any(f['fields'].get('relationship_id')==stage_id for f in staged_npc_form()[3].forms)
stage_form,stage_values,stage_batch,_=staged_npc_form()
stage_batch['additions']=[stage_add]
r=request(stage_form['action'],'POST',dict(stage_values,npc_relationship_edits=json.dumps(stage_batch))); assert r.status==200
stage_form,stage_values,stage_batch,stage_saved=staged_npc_form()
stage_clear=next(f for f in stage_saved.forms if f['action'].endswith('/forms/relationship-clear') and f['fields'].get('profile_id')==profile_id)
stage_batch.update(updates=[],clear_snapshot=stage_clear['fields']['snapshot_token'])
assert request(stage_form['action'],'POST',dict(stage_values,npc_relationship_edits=json.dumps(stage_batch))).status==422
stage_batch['clear_confirm']='Clear'
r=request(stage_form['action'],'POST',dict(stage_values,npc_relationship_edits=json.dumps(stage_batch))); assert r.status==200
assert not any(f['fields'].get('relationship_id') for f in staged_npc_form()[3].forms if f['fields'].get('profile_id')==profile_id)
assert request('/LorkhanServer/manage/forms/profile-delete','POST',{'_csrf':csrf,'profile_id':stage_target_id}).status==200
backup_response=request('/LorkhanServer/manage/exports/playthroughs/'+playthrough_id+'.json'); backup=json.loads(backup_response.read().decode())
assert backup_response.status==200 and backup['schema']=='lorkhan.playthrough-export.v1' and backup['scope']=={'installation_id':valid['installation_id'],'profile_id':profile_id,'playthrough_id':playthrough_id},backup['scope']
diary_sql(f"INSERT INTO lorkhan_internal.playthroughs(playthrough_id,installation_id,profile_id,name,created_at) SELECT gen_random_uuid(),'{valid['installation_id']}','{profile_id}','Paging Fixture '||n,'2000-01-01'::timestamptz+n*interval '1 second' FROM generate_series(1,105) n;")
try:
    paging_url='/LorkhanServer/ui/playthrough_manager.php?installation_id='+valid['installation_id']
    first_page=request(paging_url).read().decode()
    second_page=request(paging_url+'&page=2&embed=1').read().decode()
    assert 'aria-label="Playthrough pages"' in first_page and '>Next</a>' in first_page
    assert 'Paging Fixture 1<' in second_page and '>Previous</a>' in second_page and 'embed=1' in second_page
    oldest_id=diary_sql("SELECT playthrough_id FROM lorkhan_internal.playthroughs WHERE name='Paging Fixture 1';")
    selected_page=request(paging_url+'&playthrough_id='+oldest_id).read().decode()
    assert 'value="'+oldest_id+'"' in selected_page and 'Paging Fixture 1' in selected_page
    beyond_page=request(paging_url+'&page=999').read().decode()
    assert 'No playthroughs on this page.' in beyond_page and '>Previous</a>' in beyond_page
finally:
    diary_sql(f"DELETE FROM lorkhan_internal.playthroughs WHERE installation_id='{valid['installation_id']}' AND name LIKE 'Paging Fixture %';")
playthroughs,_=parse(request('/LorkhanServer/ui/playthrough_manager.php'))
restore=next(f for f in playthroughs.forms if f['action'].endswith('/forms/playthrough-import'))
values=dict(restore['fields'],_csrf=csrf,profile_id=profile_id,playthrough_id=playthrough_id,playthrough_json=json.dumps(backup))
r=request(restore['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/playthrough_manager.php?status=saved'),(r.status,r.geturl(),values,backup['scope'],body)
characters,body=parse(request('/LorkhanServer/ui/core/character_manager.php'))
bio_form=next(f for f in characters.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==profile_id)
current_profile=json.loads(request('/LorkhanServer/manage/exports/profiles/'+profile_id+'.json').read().decode())
values=dict(bio_form['fields'],_csrf=csrf,profile_id=profile_id,base_content_json=json.dumps(current_profile['content']),biography='Updated from Character Manager.',change_reason='HTTP biography test',oghma_knowledge_tags='Tribunal; Ashlanders, Tribunal')
r=request(bio_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/npc_master.php?status=saved'),(r.status,r.geturl())
body=request('/LorkhanServer/ui/core/character_manager.php').read().decode(); assert 'Updated from Character Manager.' in body and 'Preserved personality field.' in body
saved_tags=json.loads(request('/LorkhanServer/manage/exports/profiles/'+profile_id+'.json').read().decode())
assert saved_tags['content']['oghma_knowledge_tags']=='Tribunal, Ashlanders'

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
assert 'class="server-file-list"' in body and 'name="backup_id"' in body and 'Type Restore to confirm' in body and 'Created ' in body and ' UTC' in body
backup_ids_after=set(re.findall(r'/exports/backups/([0-9a-f-]{36})\.json',body)); created_backup_ids=backup_ids_after-backup_ids_before; assert len(created_backup_ids)==1,(backup_ids_before,backup_ids_after)
configuration_backup_id=created_backup_ids.pop()
_,backup_health_html=parse(request('/LorkhanServer/ui/backup_health.php?q='+configuration_backup_id))
assert 'Showing 1 of 1 records.' in backup_health_html and configuration_backup_id in backup_health_html
backup_metadata=request('/LorkhanServer/ui/backup_health.php?q='+configuration_backup_id+'&export=csv')
backup_metadata_rows=list(csv.DictReader(io.StringIO(backup_metadata.read().decode('utf-8-sig'))))
assert len(backup_metadata_rows)==1 and backup_metadata_rows[0]['Status']=='created' and backup_metadata_rows[0]['Kind']=='configuration'
assert valid['installation_id'] in backup_metadata_rows[0]['Scope']
backup_response=request('/LorkhanServer/manage/exports/backups/'+configuration_backup_id+'.json'); configuration_backup=json.loads(backup_response.read().decode())
assert backup_response.status==200 and configuration_backup['schema']=='lorkhan.configuration-backup.v2' and configuration_backup['format_version']==2 and configuration_backup['installation_id']==valid['installation_id']
core_ids={row['core_profile_id'] for row in configuration_backup['data']['core_profiles']}; assert len(core_ids)>=1 and sum(row['default_npc'] is True for row in configuration_backup['data']['core_profiles'])==1
assert all(row['core_profile_id'] in core_ids for row in configuration_backup['data']['profiles']),configuration_backup['data']['profiles']
assert configuration_backup['backup_id']==configuration_backup_id and 'portrait' not in json.dumps(configuration_backup).lower() and '"api_key"' not in json.dumps(configuration_backup).lower()
assert 'fixture-tts-badge-key' not in json.dumps(configuration_backup)
assert next(row for row in configuration_backup['data']['configurations'] if row['configuration_id']==tts_id)['content']['credential']=='LORKHAN_CUSTOM_TTS_HTTP_API_KEY'
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
description_entries=[json.loads(html.unescape(value)) for value in re.findall(r'data-description-entry="([^"]+)"',body)]
saved_description=next(entry for entry in description_entries if entry['record_id']==record_id)
r=request('/LorkhanServer/manage/forms/description-delete','POST',{'_csrf':csrf,'installation_id':installation_id,'description_id':saved_description['description_id']}); assert r.status==200 and 'status=saved' in r.geturl()
r=request('/LorkhanServer/manage/forms/description-reset','POST',{'_csrf':csrf,'installation_id':installation_id,'confirm':'Reset'}); body=r.read().decode(); assert r.status==200 and 'status=saved' in r.geturl() and 'http_csv_item' not in body
llm_page,body=parse(request('/LorkhanServer/ui/core/llm_connectors.php?selected=runtime'))
runtime_test=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-runtime-test'))
r=request(runtime_test['action'],'POST',dict(runtime_test['fields'],_csrf=csrf)); body=r.read().decode(); assert r.status==200 and 'Test completed: 1 valid utterance' in body,(r.status,r.geturl(),body)
llm_create_page,llm_create_body=parse(request('/LorkhanServer/ui/core/llm_connectors.php?create=1'))
create_toolbar=re.search(r'<div class="llm-editor-toolbar">(.*?)</div>',llm_create_body,re.S).group(1)
assert '>Create</button>' in create_toolbar and '>Test</button>' not in create_toolbar and '>Export</' not in create_toolbar
llm_form=next(f for f in llm_create_page.forms if f['action'].endswith('/forms/providers'))
slot_name='HTTP model slot '+uuid.uuid4().hex
values=dict(llm_form['fields'],_csrf=csrf,name=slot_name,driver='mock',model='deterministic-mock-v1',mock_prefix='[http] ')
r=request(llm_form['action'],'POST',values); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/llm_connectors.php?status=saved') and slot_name in body,(r.status,r.geturl())
slot_id=connector_editor_id(body,slot_name)
llm_page,body=parse(request('/LorkhanServer/ui/core/llm_connectors.php?selected='+slot_id))
model_test=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-test') and f['fields'].get('configuration_id')==slot_id)
r=request(model_test['action'],'POST',dict(model_test['fields'],_csrf=csrf)); body=r.read().decode(); assert r.status==200 and 'Test completed: 1 valid utterance' in body,(r.status,r.geturl(),body)
r=request(model_test['action'],'POST',dict(model_test['fields'],_csrf=csrf),accept='application/json')
test_summary=json.load(r); assert r.status==200 and test_summary['ok'] is True and '1 valid utterance' in test_summary['message']
assert 'endpoint' not in test_summary and 'api_key' not in json.dumps(test_summary)
assert 'id="llm-test-dialog"' in body and 'data-llm-test-loading' in body
revise=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-revise') and f['fields'].get('configuration_id')==slot_id)
values=dict(revise['fields'],_csrf=csrf,driver='mock',model='deterministic-mock-v2',mock_prefix='[revised] ',change_reason='HTTP model-slot test')
r=request(revise['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'deterministic-mock-v2' in body,(r.status,r.geturl())
r=request(revise['action'],'POST',values,accept='application/json'); assert r.status==200 and json.load(r)=={'ok':True}
r=request(revise['action'],'POST',dict(values,model=''),accept='application/json'); assert r.status==422 and 'error' in json.load(r)
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
    quest_comments_present='1',setting_quest_comments_chance_percent='25',setting_bored_event_chance_percent='0',setting_behavior_combat_bark_period_seconds='600',setting_behavior_rechat='1',setting_behavior_rechat_max_depth='5',setting_behavior_rechat_probability_percent='65',setting_behavior_rechat_allow_actions='1',
    setting_memory_recent_turn_limit='24',setting_memory_short_term_max_summaries='37',setting_response_max_words='60',setting_response_core_lang='de',setting_response_lang_llm_xtts='1',diary_generation_configuration_id=slot_id,
    setting_diary_enabled='1',setting_diary_automatic_enabled='1',setting_diary_automatic_wait_enabled='1',
    setting_diary_automatic_interval_seconds='10',setting_diary_context_turn_limit='150',
    setting_diary_prompt='Record only witnessed events.')
core_values.pop('setting_diary_include_in_context',None)
core_values['setting_diary_latest_entry_in_context']='1'
core_values.update(profile_evolution_enabled='1',setting_profile_evolution_history_limit='20')
core_values['profile_evolution_fields[]']=['occupation','skills']
core_values['profile_rpg_events[]']=['sleep','wait']
core_values['setting_rpg_comments_chance_percent']='73'
core_response=request(core_form['action'],'POST',core_values); assert core_response.status==200
core_body=core_response.read().decode(); core_page=Page(); core_page.feed(core_body)
core_saved=next(f for f in core_page.forms if f['action'].endswith('/forms/core-profile-save'))
assert core_saved['fields']['diary_generation_configuration_id']==slot_id and core_saved['fields']['setting_diary_enabled']=='1'
assert core_saved['fields']['setting_diary_automatic_enabled']=='1' and core_saved['fields']['setting_diary_automatic_wait_enabled']=='1'
assert core_saved['fields']['setting_diary_automatic_interval_seconds']=='10'
assert core_saved['fields']['setting_behavior_rechat']=='1' and core_saved['fields']['setting_behavior_rechat_max_depth']=='5' and core_saved['fields']['setting_behavior_rechat_probability_percent']=='65'
assert core_saved['fields']['setting_memory_recent_turn_limit']=='24',core_saved
assert core_saved['fields']['setting_behavior_combat_bark_period_seconds']=='600'
assert core_saved['fields']['setting_bored_event_chance_percent']=='0'
assert core_saved['fields']['setting_quest_comments_chance_percent']=='25'
assert request(core_form['action'],'POST',dict(core_values,setting_quest_comments_chance_percent='30')).status==422
assert request(core_form['action'],'POST',dict(core_values,setting_bored_event_chance_percent='101')).status==422
assert request(core_form['action'],'POST',dict(core_values,setting_behavior_combat_bark_period_seconds='601')).status==422
assert core_saved['fields']['setting_memory_short_term_max_summaries']=='37'
assert request(core_form['action'],'POST',dict(core_values,setting_memory_short_term_max_summaries='51')).status==422
assert core_saved['fields']['setting_response_max_words']=='60',core_saved
assert core_saved['fields']['setting_response_core_lang']=='de'
assert core_saved['fields']['setting_response_lang_llm_xtts']=='1'
assert core_saved['fields']['setting_rpg_comments_chance_percent']=='73'
assert all('name="profile_rpg_events[]" value="'+event+'" checked' in core_body for event in ['sleep','wait'])
assert request(core_form['action'],'POST',dict(core_values,setting_response_core_lang='../de')).status==422
assert core_saved['fields']['profile_evolution_enabled']=='1'
assert core_saved['fields']['setting_profile_evolution_history_limit']=='20'
assert all('value="'+field+'" checked' in core_body for field in ['occupation','skills'])
invalid_evolution=dict(core_values); invalid_evolution['profile_evolution_fields[]']=['notes']
assert request(core_form['action'],'POST',invalid_evolution).status==422
invalid_word_values=dict(core_values,setting_response_max_words='10001')
assert request(core_form['action'],'POST',invalid_word_values).status==422
assert 'setting_diary_include_in_context' not in core_saved['fields'] and core_saved['fields']['setting_diary_context_turn_limit']=='150'
assert '<textarea id="profile-diary-prompt" name="setting_diary_prompt" rows="4" maxlength="8192" placeholder="Enter value">Record only witnessed events.</textarea>' in core_body
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
assert core_preset['schema']=='lorkhan.core-profile-settings.v2' and core_preset['settings_overrides']['behavior']=={'rechat':True,'rechat_max_depth':5,'rechat_probability_percent':65,'rechat_allow_actions':True,'combat_bark_period_seconds':600}
assert core_preset['settings_overrides']['memory']=={'recent_turn_limit':24,'short_term_enabled':True,'mid_term_enabled':True,'long_term_enabled':True,'short_term_max_summaries':37}
assert core_preset['settings_overrides']['response']=={'max_words':60,'core_lang':'de','lang_llm_xtts':True}
assert core_preset['settings_overrides']['rpg_comments']=={'events':['sleep','wait'],'chance_percent':73}
assert core_preset['settings_overrides']['profile_evolution']=={'enabled':True,'fields':['occupation','skills'],'history_limit':20}
assert core_preset['settings_overrides']['diary']=={'enabled':True,'automatic_enabled':True,'automatic_wait_enabled':True,'automatic_interval_seconds':10,'include_in_context':False,'latest_entry_in_context':True,'context_turn_limit':150,'prompt':'Record only witnessed events.'}
assert not any(key in core_preset for key in ['core_profile_id','installation_id','prompt','routing','slot','default_npc','revision','npc_assignments'])
core_preset['name']='HTTP imported Core settings '+uuid.uuid4().hex
core_preset['settings_overrides']['rpg_comments']={'events':[],'chance_percent':0}
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
assert imported_form['fields']['setting_diary_latest_entry_in_context']=='1'
assert imported_form['fields']['setting_diary_enabled']=='1' and 'setting_diary_include_in_context' not in imported_form['fields']
assert imported_form['fields']['setting_diary_automatic_enabled']=='1' and imported_form['fields']['setting_diary_automatic_wait_enabled']=='1'
assert imported_form['fields']['setting_diary_automatic_interval_seconds']=='10'
assert imported_form['fields']['setting_response_max_words']=='60'
assert imported_form['fields']['setting_response_core_lang']=='de'
assert imported_form['fields']['setting_response_lang_llm_xtts']=='1'
assert imported_form['fields']['setting_rpg_comments_chance_percent']=='0'
assert imported_form['fields']['setting_memory_short_term_max_summaries']=='37'
assert imported_form['fields']['setting_behavior_combat_bark_period_seconds']=='600'
assert imported_form['fields']['setting_bored_event_chance_percent']=='0'
assert imported_form['fields']['setting_quest_comments_chance_percent']=='25'
assert all('name="profile_rpg_events[]" value="'+event+'" checked' not in body for event in ['levelup','combat_end','sleep','wait'])
assert imported_form['fields']['profile_evolution_enabled']=='1'
assert all('value="'+field+'" checked' in body for field in ['occupation','skills'])
assert imported_form['fields']['setting_behavior_rechat_allow_actions']=='1'
assert imported_form['fields']['setting_diary_context_turn_limit']=='150' and '>Record only witnessed events.</textarea>' in body
assert all(imported_form['fields'].get(field,'')=='' for field in ['prompt_configuration_id','llm_configuration_id','llm_fast_configuration_id','llm_powerful_configuration_id','llm_experimental_configuration_id','llm_fallback_configuration_id','diary_generation_configuration_id','tts_configuration_id'])
assert imported_form['fields'].get('slot','')=='' and 'default_npc' not in imported_form['fields']
invalid_preset=dict(core_preset,unexpected='rejected')
# Named presets are a catalogue, not the existing import-as-new-profile workflow.
core_named_path='/LorkhanServer/manage/forms/core-profile-preset'
core_named_request=lambda values: request(core_named_path,'POST',values,accept='application/json')
core_named_values=dict(core_values,installation_id=valid['installation_id'],operation='save_new',preset_name='HTTP named Core preset',setting_diary_latest_entry_in_context='1',prompt='Do not capture this prompt.')
r=core_named_request(dict(core_named_values,_csrf='wrong')); assert r.status==401,r.status
r=core_named_request(core_named_values); named_result=json.loads(r.read()); assert r.status==200,(r.status,named_result)
core_named_id=named_result['preset_id']
core_named_html=request('/LorkhanServer/ui/core/core_profiles.php?edit='+core_edit.group(1)).read().decode()
assert 'data-core-presets' in core_named_html and 'HTTP named Core preset</option>' in core_named_html
assert all('data-core-preset-action="'+action+'"' in core_named_html for action in ['apply','save_new','overwrite','export','import'])
assert json.loads(request('/LorkhanServer/manage/exports/core-profile-settings/'+core_edit.group(1)+'.json').read())['settings_overrides']['response']['max_words']==60
core_named_scope={'_csrf':csrf,'installation_id':valid['installation_id'],'preset_id':core_named_id}
r=core_named_request(dict(core_named_scope,operation='export')); named_document=json.loads(r.read()); assert r.status==200
assert sorted(named_document)==['name','preset','schema'] and named_document['schema']=='lorkhan.named-core-preset-file.v1'
assert named_document['preset']['settings_overrides']['diary']['latest_entry_in_context'] is True
assert 'prompt' not in named_document['preset'] and 'llm_configuration_id' not in named_document['preset']['routing']
core_named_overwrite=dict(core_named_values,operation='overwrite',preset_id=core_named_id,preset_revision='1',setting_response_max_words='85')
core_named_overwrite.pop('setting_diary_latest_entry_in_context',None)
r=core_named_request(core_named_overwrite); assert r.status==422,r.status
r=core_named_request(dict(core_named_overwrite,confirm='Overwrite')); assert r.status==200,(r.status,r.read())
r=core_named_request(dict(core_named_overwrite,confirm='Overwrite')); assert r.status==409,r.status
r=core_named_request(dict(core_named_scope,operation='export')); named_document=json.loads(r.read())
assert named_document['preset']['settings_overrides']['response']['max_words']==85
assert named_document['preset']['settings_overrides']['diary']['latest_entry_in_context'] is False
r=core_named_request(dict(core_named_scope,operation='import',preset_name='HTTP imported named Core preset',preset_json=json.dumps(named_document)))
assert r.status==200,(r.status,r.read())
bad_named_document=dict(named_document,preset=dict(named_document['preset'],routing={'llm_configuration_id':slot_id}))
r=core_named_request(dict(core_named_scope,operation='import',preset_json=json.dumps(bad_named_document))); assert r.status==422,r.status
r=core_named_request(dict(core_named_scope,operation='catalogue')); assert len(json.loads(r.read())['presets'])==2
r=core_named_request(dict(core_named_scope,operation='export',installation_id=str(uuid.uuid4()))); assert r.status==404,r.status
core_named_apply=dict(core_named_scope,operation='apply',core_profile_id=imported_core_id,preset_revision='2',expected_revision='1')
r=core_named_request(core_named_apply); assert r.status==422,r.status
r=core_named_request(dict(core_named_apply,confirm='Apply',preset_revision='1')); assert r.status==409,r.status
r=core_named_request(dict(core_named_apply,confirm='Apply')); named_applied=json.loads(r.read())
assert r.status==200 and named_applied=={'applied':True,'revision':2},(r.status,named_applied)
named_applied_page=Page(); named_applied_page.feed(request('/LorkhanServer/ui/core/core_profiles.php?edit='+imported_core_id).read().decode())
named_applied_form=next(f for f in named_applied_page.forms if f['action'].endswith('/forms/core-profile-save') and f['fields'].get('core_profile_id')==imported_core_id)
assert 'setting_diary_latest_entry_in_context' not in named_applied_form['fields']
r=core_named_request(dict(core_named_apply,confirm='Apply')); assert r.status==409,r.status
named_target=request('/LorkhanServer/ui/core/core_profiles.php?edit='+imported_core_id).read().decode()
assert 'name="setting_response_max_words" value="85"' in named_target and '>Do not capture this prompt.</textarea>' not in named_target
named_target_page=Page(); named_target_page.feed(named_target)
named_target_form=next(f for f in named_target_page.forms if f['action'].endswith('/forms/core-profile-save'))
assert named_target_form['fields']['label']==core_preset['name'] and named_target_form['fields']['llm_configuration_id']==''
assert '<textarea id="profile-prompt" name="prompt" maxlength="65536"></textarea>' in named_target
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

# Explicit NPC overrides round-trip through the existing revisioned profile save.
npc_overrides={'quest_comments':{'enabled':False,'chance_percent':25},'bored_event':{'chance_percent':0},'behavior':{'rechat':False,'rechat_probability_percent':0},'memory':{'recent_turn_limit':3},'response':{'max_words':17}}
for submitted in [npc_overrides, None, {}]:
    override_values=dict(saved_routing['fields'],_csrf=csrf,change_reason='NPC overrides HTTP')
    if submitted is not None: override_values['npc_settings_overrides_json']=json.dumps(submitted)
    r=request(saved_routing['action'],'POST',override_values); override_page,override_body=parse(r)
    assert r.status==200,(r.status,override_body)
    saved_routing=next(f for f in override_page.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==routing_profile_id)
    saved_routing_content=json.loads(saved_routing['fields']['base_content_json'])
    assert saved_routing_content.get('settings_overrides',{})==(npc_overrides if submitted is None else submitted),saved_routing_content
for invalid_override in [{'quest_comments':{'chance_percent':30}},{'quest_comments':{'enabled':'false'}},{'bored_event':{'chance_percent':101}},{'behavior':{'rechat':'false'}},{'memory':{'recent_turn_limit':0}},{'response':{'max_words':10001}},{'narrator':{'enabled':True}}]:
    r=request(saved_routing['action'],'POST',dict(saved_routing['fields'],_csrf=csrf,npc_settings_overrides_json=json.dumps(invalid_override),biography='MUST NOT SAVE'))
    assert r.status==422,(r.status,r.read().decode())
    unchanged_page,_=parse(request('/LorkhanServer/ui/core/npc_master.php'))
    unchanged=next(f for f in unchanged_page.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==routing_profile_id)
    assert unchanged['fields']['base_content_json']==saved_routing['fields']['base_content_json']

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
lock_values=dict(diary_values)
for lock_value in ['1','0','inherit']:
    lock_values.update(_csrf=csrf,npc_relationship_locked=lock_value,change_reason='HTTP relationship lock')
    r=request(saved_routing['action'],'POST',lock_values); lock_page,lock_body=parse(r)
    assert r.status==200,(r.status,lock_body)
    lock_saved=next(f for f in lock_page.forms if f['action'].endswith('/forms/profile-revise') and f['fields'].get('profile_id')==routing_profile_id)
    lock_content=json.loads(lock_saved['fields']['base_content_json'])
    if lock_value=='inherit':assert 'locked' not in lock_content.get('relationship',{}),lock_content.get('relationship')
    else:assert lock_content['relationship']['locked']==(lock_value=='1'),lock_content['relationship']
    lock_values=dict(lock_saved['fields'])
r=request(saved_routing['action'],'POST',dict(lock_values,_csrf=csrf,npc_relationship_locked='invalid'))
assert r.status==422 and 'invalid_npc_relationship_override' in r.read().decode()
assert len(VoiceProvider.llm_requests)==provider_calls_before_routing_save

# System connectors are installation-owned Global Settings, never NPC profile fields.
for timestamp_enabled in [True, False]:
    timestamp_page,_=parse(request('/LorkhanServer/ui/core/global_settings.php'))
    timestamp_form=next(f for f in timestamp_page.forms if f['action'].endswith('/forms/global-settings-save'))
    timestamp_values=dict(timestamp_form['fields'],_csrf=csrf,change_reason='HTTP temporal context toggle')
    timestamp_values.pop('context_prompt_timestamp',None)
    if timestamp_enabled: timestamp_values['context_prompt_timestamp']='1'
    timestamp_values.pop('context_ground_items_descriptions_only',None)
    if timestamp_enabled: timestamp_values['context_ground_items_descriptions_only']='1'
    timestamp_values.pop('context_inventory_items_descriptions_only',None)
    if timestamp_enabled: timestamp_values['context_inventory_items_descriptions_only']='1'
    timestamp_response=request(timestamp_form['action'],'POST',timestamp_values)
    assert timestamp_response.status==200,(timestamp_response.status,timestamp_response.read().decode())
    timestamp_saved,timestamp_body=parse(request('/LorkhanServer/ui/core/global_settings.php'))
    timestamp_fields=next(f['fields'] for f in timestamp_saved.forms if f['action'].endswith('/forms/global-settings-save'))
    assert ('context_prompt_timestamp' in timestamp_fields)==timestamp_enabled,timestamp_enabled
    assert ('context_ground_items_descriptions_only' in timestamp_fields)==timestamp_enabled,timestamp_enabled
    assert ('context_inventory_items_descriptions_only' in timestamp_fields)==timestamp_enabled,timestamp_enabled
    assert '<h2>Context</h2>' in timestamp_body and '<h2>Context Selections' in timestamp_body
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
# Configured endpoints expose editable key bindings, while portable files cannot bind recipient keys.
configured_name='HTTP inherited key '+uuid.uuid4().hex
configured_values={'_csrf':csrf,'installation_id':valid['installation_id'],'name':configured_name,'driver':'configured','model':'fixture','credential':'openrouter'}
r=request('/LorkhanServer/manage/forms/providers','POST',configured_values); configured_body=r.read().decode(); assert r.status==200
configured_id=connector_editor_id(configured_body,configured_name)
configured_page,configured_editor=parse(request('/LorkhanServer/ui/core/llm_connectors.php?edit='+configured_id))
configured_form=next(f for f in configured_page.forms if f['action'].endswith('/forms/provider-revise'))
assert re.search(r'<option value="openrouter"[^>]* selected',configured_editor)
configured_export=json.load(request('/LorkhanServer/manage/exports/providers/'+configured_id+'.json'))
assert configured_export['content']['driver']=='configured' and configured_export['content']['credential']=='none'
configured_export['content']['credential']='openrouter'; configured_export['name']=configured_name+' imported'
r=request('/LorkhanServer/manage/forms/provider-import','POST',{'_csrf':csrf,'installation_id':valid['installation_id'],'provider_json':json.dumps(configured_export)})
assert r.status==200
configured_import_id=connector_editor_id(r.read().decode(),configured_export['name'])
configured_import_page,configured_import_editor=parse(request('/LorkhanServer/ui/core/llm_connectors.php?edit='+configured_import_id))
assert '<option value="none" selected>' in configured_import_editor
r=request(configured_form['action'],'POST',dict(configured_values,configuration_id=configured_id,change_reason='Restore inheritance',credential='__inherit__')); assert r.status==200
configured_page,configured_editor=parse(request('/LorkhanServer/ui/core/llm_connectors.php?edit='+configured_id))
assert '<option value="__inherit__" selected>' in configured_editor
for unused_id in [configured_id,configured_import_id]:
    assert request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':unused_id}).status==200
provider_export_response=request('/LorkhanServer/manage/exports/providers/'+slot_id+'.json'); provider_export=json.loads(provider_export_response.read().decode())
assert provider_export_response.status==200 and provider_export['schema']=='lorkhan.provider-export.v1' and 'installation_id' not in provider_export and 'endpoint' not in provider_export and 'api_key' not in json.dumps(provider_export).lower()
llm_page,_=parse(request('/LorkhanServer/ui/core/llm_connectors.php?selected='+slot_id))
clone_provider=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-clone') and f['fields'].get('configuration_id')==slot_id)
clone_name=slot_name+' clone'; r=request(clone_provider['action'],'POST',dict(clone_provider['fields'],_csrf=csrf,name=clone_name)); body=r.read().decode()
assert r.status==200 and clone_name in body,(r.status,r.geturl(),body)
clone_provider_id=connector_editor_id(body,clone_name)
r=request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':clone_provider_id}); assert r.status==200
provider_export['name']=slot_name+' imported'; llm_page,_=parse(request('/LorkhanServer/ui/core/llm_connectors.php?import=1&embed=1'))
import_provider=next(f for f in llm_page.forms if f['action'].endswith('/forms/provider-import'))
r=request(import_provider['action'],'POST',dict(import_provider['fields'],_csrf=csrf,installation_id=valid['installation_id'],provider_json=json.dumps(provider_export))); body=r.read().decode()
assert r.status==200 and provider_export['name'] in body,(r.status,r.geturl(),body)
assert import_provider['fields'].get('embed')=='1' and 'embed=1' in r.geturl() and 'edit=' in r.geturl(),r.geturl()
import_provider_id=connector_editor_id(body,provider_export['name'])
r=request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':import_provider_id}); assert r.status==200
# Keep the shared model available for the later player and narrator generation checks.
# Exercise the real adapter with a disposable local HTTP provider, never a paid endpoint.
direct_name='HTTP direct '+uuid.uuid4().hex
direct_values={'_csrf':csrf,'installation_id':valid['installation_id'],'name':direct_name,'driver':'openai-compatible','model':'local-test','service':'custom',
    'endpoint':'http://127.0.0.1:'+str(voice_provider.server_port)+'/llm/chat/completions','credential':'none','timeout_ms':'4000',
    'option_temperature':'0','option_top_p':'0','option_max_completion_tokens':'64','option_stream':'false','option_json_mode':'false',
    'option_provider_order':' together , google-vertex/us-east5 '}
r=request('/LorkhanServer/manage/forms/providers','POST',direct_values); body=r.read().decode(); assert r.status==200 and direct_name in body,(r.status,body)
direct_id=connector_editor_id(body,direct_name)
_,direct_editor=parse(request('/LorkhanServer/ui/core/llm_connectors.php?edit='+direct_id))
assert 'id="llm_service" name="service" value="custom"' in direct_editor
assert re.search(r'id="llm_option_max_completion_tokens"[^>]*value="64"',direct_editor),direct_editor
# Range companions must never duplicate the submitted override or turn blank defaults into zero.
llm_ranges=re.findall(r'<input type="range"[^>]+>',direct_editor)
assert len(llm_ranges)==8 and all(' name=' not in tag for tag in llm_ranges),llm_ranges
assert re.search(r'id="llm_option_presence_penalty"[^>]*value=""',direct_editor),direct_editor
assert re.search(r'id="llm_option_temperature"[^>]*value="0"',direct_editor),direct_editor
assert re.search(r'id="llm_provider"[^>]*value="together, google-vertex/us-east5"',direct_editor),direct_editor
assert direct_editor.index('for="llm_option_reasoning_model"') < direct_editor.index('for="llm_option_json_mode"') < direct_editor.index('for="llm_option_stream"') < direct_editor.index('<summary>Connection options</summary>') < direct_editor.index('for="llm_driver"'),direct_editor
assert re.search(r'name="option_stream"\s+data-direct-default="true"\s+data-runtime-default="(?:true|false)" data-inverted="true"',direct_editor),direct_editor
assert 'class="llm-help-details llm-connection-options" open' in direct_editor and direct_editor.index('<summary>Connection options</summary>') < direct_editor.index('id="llm_option_max_completion_tokens"'),direct_editor
assert 'Enforce JSON' in direct_editor and 'Disable Streaming' in direct_editor and '<span>Direct connection</span>' not in direct_editor
assert 'data-groq-catalogue="/LorkhanServer/manage/api/v1/llm-groq-models"' in direct_editor
assert 'data-llm-import-open' in direct_editor and 'id="llm-import-picker"' in direct_editor and 'accept="application/json,.json" multiple hidden' in direct_editor
assert 'data-llm-clear-advanced hidden>Clear advanced settings</button>' in direct_editor
assert 'Imported 2 connectors.' in request('/LorkhanServer/ui/core/llm_connectors.php?imported=2').read().decode()
assert 'Imported 21' not in request('/LorkhanServer/ui/core/llm_connectors.php?imported=21').read().decode()
assert 'No API key selected. Some services require a key.' in direct_editor
assert re.search(r'<option value="custom" data-empty="1">🔴 Custom LLM key — No key</option>',direct_editor),direct_editor
direct_test={'_csrf':csrf,'installation_id':valid['installation_id'],'configuration_id':direct_id}
r=request('/LorkhanServer/manage/forms/provider-test','POST',direct_test); body=r.read().decode(); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
headers,sent=VoiceProvider.llm_requests[-1]
assert 'service' not in sent, sent
assert 'Authorization' not in headers and sent['temperature']==0 and sent['top_p']==0 and sent['max_completion_tokens']==64 and sent['stream'] is False and 'response_format' not in sent,(headers,sent)
assert sent['provider']=={'order':['together','google-vertex/us-east5']} and 'provider_order' not in sent,sent
r=request('/LorkhanServer/ui/core/api_keys.php','POST',{'_csrf':csrf,'action':'set','variable':'LORKHAN_LLM_CUSTOM_API_KEY','credential':'local-parity-test-key'}); body=r.read().decode(); assert 'Credential saved.' in body,body
direct_values.update(configuration_id=direct_id,credential='custom',option_stream='true',option_json_mode='true',option_disable_reasoning='true',option_reasoning_model='true',change_reason='Exercise explicit key, streaming, and reasoning cleanup')
r=request('/LorkhanServer/manage/forms/provider-revise','POST',direct_values); assert r.status==200,(r.status,r.read().decode())
r=request('/LorkhanServer/ui/core/llm_connectors.php?edit='+direct_id); direct_key_editor=r.read().decode()
assert 'id="llm_service" name="service" value="custom"' in direct_key_editor
assert re.search(r'<option value="custom" data-empty="0" selected>🟢 Custom LLM key</option>',direct_key_editor),direct_key_editor
assert direct_key_editor.index('🟢 Custom LLM key') < direct_key_editor.index('— Missing Key —'),direct_key_editor
assert 'local-parity-test-key' not in direct_key_editor and 'id="llm_key_notice" class="api-key-notice warn" role="status"></div>' in direct_key_editor
r=request('/LorkhanServer/manage/forms/provider-test','POST',direct_test); body=r.read().decode(); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
headers,sent=VoiceProvider.llm_requests[-1]
assert headers.get('Authorization')=='Bearer local-parity-test-key' and sent['stream'] is True and sent['response_format']=={'type':'json_object'} and sent['reasoning']=={'exclude':True,'enabled':False},(headers,sent)
r=request('/LorkhanServer/manage/forms/provider-test','POST',direct_test,accept='application/json'); diagnostic_result=json.load(r)
assert r.status==200 and diagnostic_result['ok'] is True
assert diagnostic_result['diagnostics']['request']==VoiceProvider.llm_requests[-1][1]
assert diagnostic_result['diagnostics']['response']['utterances']==[{'text':'Greetings, traveller.'}]
assert diagnostic_result['diagnostics']['connector']['model']==direct_values['model']
assert 'local-parity-test-key' not in json.dumps(diagnostic_result) and 'Authorization' not in json.dumps(diagnostic_result)
direct_export=json.loads(request('/LorkhanServer/manage/exports/providers/'+direct_id+'.json').read().decode())
assert direct_export['content']['credential']=='none' and direct_export['content']['options']['reasoning_model'] is True and 'local-parity-test-key' not in json.dumps(direct_export),direct_export
assert direct_export['content']['options']['provider_order']==['together','google-vertex/us-east5'],direct_export
direct_export['name']=direct_name+' portable'; direct_export['content']['credential']='custom'
r=multipart_request('/LorkhanServer/manage/forms/provider-import',{'_csrf':csrf,'installation_id':valid['installation_id'],'provider_json':json.dumps(direct_export)}); body=r.read().decode(); assert r.status==200 and r.geturl().endswith('/ui/core/llm_connectors.php?status=saved'),(r.status,body)
portable_id=connector_editor_id(body,direct_export['name'])
r=request('/LorkhanServer/manage/forms/provider-test','POST',dict(direct_test,configuration_id=portable_id)); body=r.read().decode(); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
assert 'Authorization' not in VoiceProvider.llm_requests[-1][0],VoiceProvider.llm_requests[-1][0]
assert VoiceProvider.llm_requests[-1][1]['provider']=={'order':['together','google-vertex/us-east5']}
r=request('/LorkhanServer/manage/forms/provider-rollback','POST',dict(direct_test,revision='1')); assert r.status==200,(r.status,r.read().decode())
r=request('/LorkhanServer/manage/forms/provider-test','POST',direct_test); body=r.read().decode(); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
headers,sent=VoiceProvider.llm_requests[-1]; assert 'Authorization' not in headers and sent['stream'] is False and 'response_format' not in sent,(headers,sent)
direct_values.update(option_provider_order='',change_reason='Restore default routing')
r=request('/LorkhanServer/manage/forms/provider-revise','POST',direct_values); assert r.status==200,(r.status,r.read().decode())
r=request('/LorkhanServer/manage/forms/provider-test','POST',direct_test); assert r.status==200 and 'status=tested' in r.geturl()
assert 'provider' not in VoiceProvider.llm_requests[-1][1],VoiceProvider.llm_requests[-1][1]
# Saved explicit local connectors use the LAN transport, while normal Custom retains its stricter guard.
local_addresses=subprocess.run(['hostname','-I'],capture_output=True,text=True).stdout.split()
local_host=next((host for host in local_addresses if host.startswith(('10.','192.168.')) or (host.startswith('172.') and 16<=int(host.split('.')[1])<=31)),'127.0.0.1')
local_values=dict(direct_values,name='Local transport '+uuid.uuid4().hex,service='local',credential='none',endpoint='http://'+local_host+':'+str(voice_provider.server_port)+'/llm/chat/completions')
local_values.pop('configuration_id',None)
r=request('/LorkhanServer/manage/forms/providers','POST',local_values); body=r.read().decode(); assert r.status==200,(r.status,body)
local_id=connector_editor_id(body,local_values['name'])
local_editor=request('/LorkhanServer/ui/core/llm_connectors.php?edit='+local_id).read().decode()
assert 'id="llm_service" name="service" value="local"' in local_editor and local_values['endpoint'] in local_editor
r=request('/LorkhanServer/manage/forms/provider-test','POST',dict(direct_test,configuration_id=local_id)); assert r.status==200 and 'status=tested' in r.geturl(),(r.status,r.read().decode())
assert 'Authorization' not in VoiceProvider.llm_requests[-1][0]
local_export=json.load(request('/LorkhanServer/manage/exports/providers/'+local_id+'.json'))
assert local_export['content']['service']=='local' and local_export['content']['endpoint']==local_values['endpoint']
r=request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':local_id}); assert r.status==200
# Quickstart draft tests reuse the real adapter but never persist or activate their submitted settings.
original_opener,original_jar=opener,jar
jar=http.cookiejar.CookieJar(); opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
request('/LorkhanServer/ui/home.php').read(); draft_csrf=next(c.value for c in jar if c.name=='lorkhan_csrf')
draft_test_path='/LorkhanServer/manage/api/v1/quickstart-local-llm-test'
draft_setup={'server_type':'other','scope':'conversations','endpoint':local_values['endpoint'],'model':'draft-local-model','disable_streaming':True}
draft_body={'installation_id':valid['installation_id'],'setup':draft_setup}
plan_url='/LorkhanServer/manage/api/v1/quickstart-local-llm?installation_id='+valid['installation_id']
before_draft_plan=json.load(request(plan_url))
assert json_request(draft_test_path,'POST',draft_body).status==401
assert json_request(draft_test_path,'POST',dict(draft_body,setup=dict(draft_setup,api_key='must-not-save')),draft_csrf).status==422
before_draft_calls=len(VoiceProvider.llm_requests)
draft_test=json_request(draft_test_path,'POST',draft_body,draft_csrf); draft_result=json.load(draft_test)
assert draft_test.status==200 and draft_result['ok'] and 'valid utterance' in draft_result['message'],draft_result
assert len(VoiceProvider.llm_requests)==before_draft_calls+1 and VoiceProvider.llm_requests[-1][1]['model']=='draft-local-model'
assert 'Authorization' not in VoiceProvider.llm_requests[-1][0]
# Optional local credentials never replace a cloud key or apply to an unbound request.
local_key='isolated-local-key-'+uuid.uuid4().hex
local_key_path='/LorkhanServer/manage/api/v1/quickstart-key'
local_key_payload={'provider':'local_llm','credential':local_key}
assert json_request(local_key_path,'POST',local_key_payload).status==401
saved_local_key=json_request(local_key_path,'POST',local_key_payload,draft_csrf)
assert saved_local_key.status==200 and json.load(saved_local_key)=={'saved':True}
keyed_draft=json_request(draft_test_path,'POST',dict(draft_body,setup=dict(draft_setup,credential='badge:LORKHAN_CUSTOM_QUICKSTART_LOCAL_LLM_API_KEY')),draft_csrf)
keyed_result=json.load(keyed_draft)
assert keyed_draft.status==200 and keyed_result['ok'] and local_key not in json.dumps(keyed_result)
assert VoiceProvider.llm_requests[-1][0].get('Authorization')=='Bearer '+local_key
# Test accepts an unsaved key without replacing the persisted badge or changing routes.
transient_key='unsaved-local-key-'+uuid.uuid4().hex
transient_body=dict(draft_body,setup=dict(draft_setup,credential='badge:LORKHAN_CUSTOM_QUICKSTART_LOCAL_LLM_API_KEY'),api_key=transient_key)
assert json_request(draft_test_path,'POST',transient_body).status==401
transient_response=json_request(draft_test_path,'POST',transient_body,draft_csrf)
transient_result=json.load(transient_response)
assert transient_response.status==200 and transient_result['ok'] and transient_key not in json.dumps(transient_result)
assert VoiceProvider.llm_requests[-1][0].get('Authorization')=='Bearer '+transient_key
preserved_key=json_request(draft_test_path,'POST',dict(transient_body,api_key=''),draft_csrf)
assert preserved_key.status==200 and json.load(preserved_key)['ok']
assert VoiceProvider.llm_requests[-1][0].get('Authorization')=='Bearer '+local_key
calls_before_invalid_key=len(VoiceProvider.llm_requests)
for invalid_key in [None,[],42,'x'*8193,'bad\nheader']:
    rejected=json_request(draft_test_path,'POST',dict(draft_body,api_key=invalid_key),draft_csrf)
    assert rejected.status==422 and json.load(rejected)=={'error':'invalid_local_llm_test_key'}
assert len(VoiceProvider.llm_requests)==calls_before_invalid_key
unkeyed_draft=json_request(draft_test_path,'POST',draft_body,draft_csrf)
assert unkeyed_draft.status==200 and json.load(unkeyed_draft)['ok']
assert 'Authorization' not in VoiceProvider.llm_requests[-1][0]
bad_draft=json_request(draft_test_path,'POST',dict(draft_body,setup=dict(draft_setup,model='invalid-output')),draft_csrf)
assert bad_draft.status==502 and json.load(bad_draft)=={'error':'local_llm_test_failed'}
assert json.load(request(plan_url))==before_draft_plan
opener,jar=original_opener,original_jar
# Exercise the other real adapters with the same local provider, including operation-specific schemas.
generation_cases=[
    ({'generation_mode':'diary_generation'},{'title':'Fixture title','content':'A witnessed exchange.'}),
    ({'generation_mode':'memory_summary'},{'summary':'A witnessed exchange.'}),
    ({'generation_mode':'profile_evolution','dynamic_fields':['goals']},{'goals':'Reach Balmora.'}),
    ({'generation_mode':'player_autochat'},{'text':'Where is the inn?'}),
    ({'generation_mode':'relationship_build'},{'relationships':[]}),
    ({'generation_mode':'relationship_evaluation'},{'disposition_delta':0,'affinity_delta':0,'reason':'An ordinary greeting.','relationship_type':''}),
]
for profile,expected in generation_cases:
    profile['fixture_response']=expected
    probe=subprocess.run(['php','-r',
        r"require $argv[1].'/lib/Autoload.php'; $p=new LorkhanServer\Application\OpenAiCompatibleProfileGenerationProvider($argv[2],['127.0.0.1'],'structured-fixture','',options:['json_schema'=>true,'prefill_json'=>true,'extra_parameters_enabled'=>true,'extra_parameters_yaml'=>'metadata: {yaml_fixture: true}'],allowLoopbackHttp:true,directConnection:true); echo json_encode($p->generate(json_decode($argv[3],true),new LorkhanServer\Application\NeverCancelledToken()));",
        str(repository_root),'http://127.0.0.1:'+str(voice_provider.server_port)+'/llm/chat/completions',json.dumps(profile)],
        capture_output=True,text=True,timeout=15)
    assert probe.returncode==0 and json.loads(probe.stdout)==expected,(probe.stdout,probe.stderr)
    schema=VoiceProvider.llm_requests[-1][1]['response_format']['json_schema']['schema']
    assert set(schema['properties'])==set(expected) and schema['additionalProperties'] is False,schema
    assert VoiceProvider.llm_requests[-1][1]['metadata']=={'yaml_fixture':True}
topics_probe=subprocess.run(['php','-r',
    r"require $argv[1].'/lib/Autoload.php'; $p=new LorkhanServer\Application\OpenAiCompatibleOghmaTopicExtractor($argv[2],['127.0.0.1'],'structured-fixture','',options:['json_schema'=>true,'prefill_json'=>true,'extra_parameters_enabled'=>true,'extra_parameters_yaml'=>'metadata: {yaml_fixture: true}'],allowLoopbackHttp:true,directConnection:true); echo json_encode($p->extract($argv[3],2,new LorkhanServer\Application\NeverCancelledToken()));",
    str(repository_root),'http://127.0.0.1:'+str(voice_provider.server_port)+'/llm/chat/completions',json.dumps({'fixture_response':{'topics':['Balmora','Vivec']}})],
    capture_output=True,text=True,timeout=15)
assert topics_probe.returncode==0 and json.loads(topics_probe.stdout)==['Balmora','Vivec'],(topics_probe.stdout,topics_probe.stderr)
assert VoiceProvider.llm_requests[-1][1]['response_format']['json_schema']['schema']['properties']['topics']['maxItems']==2
assert VoiceProvider.llm_requests[-1][1]['metadata']=={'yaml_fixture':True}

# Both provider response styles work, streamed and buffered; saving the switches reaches the actual wire.
for stream,model in [('true','prefill-continuation'),('false','prefill-continuation'),('true','local-test')]:
    direct_values.update(option_json_schema='true',option_prefill_json='true',option_json_mode='true',
        option_extra_parameters_yaml="metadata:\n  parity_fixture: true\nlogit_bias: {}\nstop: [END, 'a,b']\n",
        option_extra_parameters_enabled='true',
        option_stream=stream,model=model,change_reason='Exercise schema and assistant continuation')
    r=request('/LorkhanServer/manage/forms/provider-revise','POST',direct_values); assert r.status==200
    r=request('/LorkhanServer/manage/forms/provider-test','POST',direct_test); body=r.read().decode()
    assert r.status==200 and 'status=tested' in r.geturl(),(r.status,body)
    sent=VoiceProvider.llm_requests[-1][1]
    assert sent['response_format']['type']=='json_schema' and sent['response_format']['json_schema']['strict'] is True,sent
    assert sent['response_format']['json_schema']['schema']['properties']['action']=={'type':'null'},sent
    assert sent['messages'][-1]=={'role':'assistant','content':'{"utterances":'},sent
    assert not any(field in sent for field in ['json_schema','prefill_json','json_mode']),sent
    assert sent['metadata']=={'parity_fixture':True} and sent['logit_bias']=={} and sent['stop']==['END','a,b'],sent
    assert 'extra_parameters_yaml' not in sent and 'extra_parameters_enabled' not in sent,sent
schema_export=json.loads(request('/LorkhanServer/manage/exports/providers/'+direct_id+'.json').read().decode())
assert schema_export['content']['options']['json_schema'] is True and schema_export['content']['options']['prefill_json'] is True,schema_export
assert schema_export['content']['options']['extra_parameters_yaml']==direct_values['option_extra_parameters_yaml'],schema_export
schema_editor=request('/LorkhanServer/ui/core/llm_connectors.php?edit='+direct_id).read().decode()
assert schema_editor.index('for="llm_option_json_mode"') < schema_editor.index('for="llm_option_json_schema"') < schema_editor.index('for="llm_option_prefill_json"') < schema_editor.index('for="llm_option_stream"')
assert 'Include Body Parameters (YAML)' in schema_editor and 'Enable YAML Body Parameters' in schema_editor and 'ui/js/ace/ace.js' in schema_editor
before_yaml_revision=schema_export['content']
invalid_yaml_values=dict(direct_values,option_extra_parameters_yaml='headers: {Authorization: never-echo-this}')
invalid_yaml_response=request('/LorkhanServer/manage/forms/provider-revise','POST',invalid_yaml_values)
invalid_yaml_body=invalid_yaml_response.read().decode()
assert invalid_yaml_response.status==422 and 'never-echo-this' not in invalid_yaml_body,(invalid_yaml_response.status,invalid_yaml_body)
assert json.loads(request('/LorkhanServer/manage/exports/providers/'+direct_id+'.json').read())['content']==before_yaml_revision
direct_values.update(option_extra_parameters_enabled='false')
direct_values.update(option_json_mode='false',change_reason='Preserve schema preference while JSON enforcement is disabled')
r=request('/LorkhanServer/manage/forms/provider-revise','POST',direct_values); assert r.status==200
r=request('/LorkhanServer/manage/forms/provider-test','POST',direct_test); assert r.status==200 and 'status=tested' in r.geturl()
assert 'response_format' not in VoiceProvider.llm_requests[-1][1]
assert 'metadata' not in VoiceProvider.llm_requests[-1][1]
disabled_yaml_export=json.loads(request('/LorkhanServer/manage/exports/providers/'+direct_id+'.json').read())
assert disabled_yaml_export['content']['options']['extra_parameters_yaml']==direct_values['option_extra_parameters_yaml'] and disabled_yaml_export['content']['options']['extra_parameters_enabled'] is False
disabled_yaml_export['name']=direct_name+' YAML portable'
r=multipart_request('/LorkhanServer/manage/forms/provider-import',{'_csrf':csrf,'installation_id':valid['installation_id'],'provider_json':json.dumps(disabled_yaml_export)})
assert r.status==200
yaml_portable_id=connector_editor_id(r.read().decode(),disabled_yaml_export['name'])
yaml_portable_export=json.loads(request('/LorkhanServer/manage/exports/providers/'+yaml_portable_id+'.json').read())
assert yaml_portable_export['content']['options']==disabled_yaml_export['content']['options']
assert yaml_portable_export['content']['credential']=='none'
r=request('/LorkhanServer/manage/forms/provider-delete','POST',{'_csrf':csrf,'configuration_id':yaml_portable_id}); assert r.status==200
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
        r=request(generate_style['action'],'POST',dict(generate_style['fields'],_csrf=csrf)); body=r.read().decode(); assert r.status==202 and 'job_id' in json.loads(body),(r.status,r.geturl(),body)
    player_id=match.group(1)
    edit_page,body=parse(request('/LorkhanServer/ui/core/player_management.php'))
    revise=next(f for f in edit_page.forms if f['action'].endswith('/forms/player-profile-revise'))
    values=dict(revise['fields'],_csrf=csrf,profile_id=player_id,biography='Arrived in Morrowind by prison ship.',biography_known_by_all='0',personality='Patient',goals='Find Fargoth.',
        player_elevenlabs_present='1',tts_elevenlabs_model_id='eleven_v3',tts_elevenlabs_speed='1.1',tts_elevenlabs_use_speaker_boost='false',tts_elevenlabs_v3_audio_tags='[curious]',
        diary_enabled='1',auto_diary_enabled='1',auto_diary_wait_enabled='1',diary_interval_seconds='90',change_reason='HTTP parity test')
    r=request(revise['action'],'POST',values); body=r.read().decode(); assert r.status==200 and 'Player profile saved.' in body and 'Patient' in body,(r.status,r.geturl())
    edit_page,body=parse(request('/LorkhanServer/ui/core/player_management.php'))
    revise=next(f for f in edit_page.forms if f['action'].endswith('/forms/player-profile-revise'))
    assert revise['fields'].get('biography_known_by_all')=='0' and 'id="player-biography-known-by-all"' in body
    assert revise['fields'].get('tts_elevenlabs_model_id')=='eleven_v3' and revise['fields'].get('tts_elevenlabs_speed')=='1.1'
    assert revise['fields'].get('tts_elevenlabs_use_speaker_boost')=='false' and re.search(r'<textarea[^>]*name="tts_elevenlabs_v3_audio_tags"[^>]*>\[curious\]</textarea>',body)
    invalid_voice=request(revise['action'],'POST',dict(revise['fields'],_csrf=csrf,tts_elevenlabs_speed='0'))
    assert invalid_voice.status==422,invalid_voice.read().decode()
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
for inline_key in ('dialogue_line_inline_response_narrator','inline_narration_prompt_narrator','dialogue_line_inline_response_npc','inline_narration_prompt_npc'):
    assert any(f['fields'].get('prompt_key')==inline_key for f in narrator_page.forms),inline_key

style_template_form=next(f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-prompt-save') and f['fields'].get('prompt_key')=='player_speech_style_prompt')
r=request(style_template_form['action'],'POST',dict(style_template_form['fields'],_csrf=csrf,custom_prompt='Describe terse vocabulary.'))
assert r.status==200 and json.loads(r.read())['ok']
assert 'Describe terse vocabulary.' in request('/LorkhanServer/ui/prompts_manager.php?installation_id='+valid['installation_id']).read().decode()
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
narrator_route_values=dict(narrator_revise['fields'],_csrf=csrf,name='Renamed HTTP Narrator',inline_narration_mode='Narrator',diary_enabled='1',oghma_knowledge_tags='knowall, Common, Tribunal, Tribunal',
    auto_diary_enabled='1',auto_diary_wait_enabled='1',diary_interval_seconds='90',change_reason='HTTP narrator portability route')
r=request(narrator_revise['action'],'POST',narrator_route_values); narrator_route_body=r.read().decode(); assert r.status==200,(r.status,r.geturl(),narrator_route_body)
narrator_page,body=parse(request('/LorkhanServer/ui/narrator_management.php'))
saved_narrator_form=next(f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-profile-revise'))
assert saved_narrator_form['fields'].get('oghma_knowledge_tags')=='knowall, Tribunal'
assert saved_narrator_form['fields']['name']=='Renamed HTTP Narrator'
stale_narrator=request(narrator_revise['action'],'POST',dict(narrator_route_values,name='Stale Narrator'))
assert stale_narrator.status==409

invalid_tags=request(narrator_revise['action'],'POST',dict(narrator_route_values,oghma_knowledge_tags='x'*4097))
assert invalid_tags.status==422,invalid_tags.read().decode()
assert saved_narrator_form['fields'].get('diary_enabled')=='1' and saved_narrator_form['fields'].get('auto_diary_enabled')=='1'
assert saved_narrator_form['fields'].get('auto_diary_wait_enabled')=='1' and saved_narrator_form['fields'].get('diary_interval_seconds')=='90'
generate_narrator=next(f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-profile-generate'))
narrator_import=next(f for f in narrator_page.forms if f['action'].endswith('/forms/narrator-profile-settings-import'))
narrator_id=generate_narrator['fields']['profile_id']
narrator_preset_response=request('/LorkhanServer/manage/exports/narrator-profile-settings/'+narrator_id+'.json')
narrator_preset=json.loads(narrator_preset_response.read().decode())
assert narrator_preset_response.status==200 and sorted(narrator_preset)==['exported_at','schema','settings']
assert narrator_preset['settings']['oghma_knowledge_tags']=='knowall, Tribunal'
assert narrator_preset['schema']=='lorkhan.narrator-profile-settings.v2' and sorted(narrator_preset['settings'])==['biography','book_events','bored_chance_percent','bored_events','context_visibility','core','enabled','goals','hide_from_context','inline_narration_mode','latest_diary_context_enabled','narration_filters','notes','oghma_knowledge_tags','only_diary_access','personality','prompt_head','quest_chance_percent','quest_cooldown_minutes','quest_events','random_chance_percent','random_cooldown_rounds','random_events','speech_style','voice','welcome_cooldown_minutes','welcome_events']
assert not any(key in narrator_preset for key in ['name','actor_identity','installation_id','profile_id','revision','routing'])
invalid_narrator_preset=dict(narrator_preset,unexpected='rejected')
r=request(narrator_import['action'],'POST',dict(narrator_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(invalid_narrator_preset))); invalid_body=r.read().decode()
assert r.status==422 and 'invalid_narrator_profile_settings_preset' in invalid_body,(r.status,invalid_body)
# Partial Narration presets change only supplied fields, including explicit clears.
for schema in ['lorkhan.narrator-profile-settings.v1','lorkhan.narrator-profile-settings.v2']:
    for personality in ['Partial narrator persona','']:
        before_partial=json.loads(request('/LorkhanServer/manage/exports/narrator-profile-settings/'+narrator_id+'.json').read())
        partial=dict(narrator_preset,schema=schema,settings={'personality':personality})
        r=request(narrator_import['action'],'POST',dict(narrator_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(partial)),accept='application/json')
        assert r.status==200 and json.loads(r.read())['ok'] is True
        after_partial=json.loads(request('/LorkhanServer/manage/exports/narrator-profile-settings/'+narrator_id+'.json').read())
        assert after_partial['settings']==dict(before_partial['settings'],personality=personality)
for settings in [{'personality':False},{'not_a_setting':True}]:
    invalid_partial=dict(narrator_preset,settings=settings)
    r=request(narrator_import['action'],'POST',dict(narrator_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(invalid_partial)),accept='application/json')
    assert r.status==422,r.read().decode()
narrator_preset['settings']['personality']='Portable narrator persona'
r=request(narrator_import['action'],'POST',dict(narrator_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(narrator_preset)),accept="application/json")
assert r.status==200 and json.loads(r.read())['ok'] is True
narrator_preset['settings']['inline_narration_mode']='Text Only'
narrator_preset['settings']['latest_diary_context_enabled']=True
narrator_preset['settings']['hide_from_context']=False
narrator_preset['settings']['only_diary_access']=True
r=request(narrator_import['action'],'POST',dict(narrator_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(narrator_preset))); imported_body=r.read().decode()
assert r.status==200 and 'status=imported' in r.geturl(),(r.status,r.geturl(),imported_body)
imported_narrator_page,imported_narrator_body=parse(request('/LorkhanServer/ui/narrator_management.php?installation_id='+valid['installation_id']+'&status=imported'))
assert 'Portable narrator settings imported as a new narrator profile revision.' in imported_narrator_body and 'Portable narrator persona' in imported_narrator_body,imported_narrator_body
imported_narrator_form=next(f for f in imported_narrator_page.forms if f['action'].endswith('/forms/narrator-profile-revise'))
assert imported_narrator_form['fields'].get('oghma_knowledge_tags')=='knowall, Tribunal'
assert imported_narrator_form['fields'].get('latest_diary_context_enabled')=='1'
assert 'hide_from_context' not in imported_narrator_form['fields']
assert imported_narrator_form['fields'].get('only_diary_access')=='1'
legacy_narrator_preset=dict(narrator_preset,settings=dict(narrator_preset['settings']))
legacy_narrator_preset['settings'].pop('oghma_knowledge_tags')
legacy_narrator_preset['settings'].pop('latest_diary_context_enabled')
legacy_narrator_preset['settings'].pop('hide_from_context')
legacy_narrator_preset['settings'].pop('only_diary_access')
r=request(narrator_import['action'],'POST',dict(narrator_import['fields'],_csrf=csrf,installation_id=valid['installation_id'],preset_json=json.dumps(legacy_narrator_preset)))
assert r.status==200,r.read().decode()
embedded_narrator_page,embedded_narrator_html=parse(request('/LorkhanServer/ui/narrator_management.php?embed=1'))
embedded_narrator_form=next(f for f in embedded_narrator_page.forms if f['action'].endswith('/forms/narrator-profile-revise'))
assert embedded_narrator_form['fields'].get('embed')=='1'
r=request(embedded_narrator_form['action'],'POST',dict(embedded_narrator_form['fields'],_csrf=csrf,inline_narration_mode='Text Only'))
assert r.status==200 and '/ui/narrator_management.php?status=saved&embed=1&installation_id=' in r.geturl(),(r.status,r.geturl(),r.read().decode())
legacy_narrator_export=json.loads(request('/LorkhanServer/manage/exports/narrator-profile-settings/'+narrator_id+'.json').read().decode())
assert legacy_narrator_export['settings']['oghma_knowledge_tags']=='knowall, Tribunal'
# Unchecking saves explicit false and does not revert to the Core Profile default.
embedded_narrator_page,_=parse(request('/LorkhanServer/ui/narrator_management.php?embed=1'))
embedded_narrator_form=next(f for f in embedded_narrator_page.forms if f['action'].endswith('/forms/narrator-profile-revise'))
unchecked_narrator=dict(embedded_narrator_form['fields'],_csrf=csrf,inline_narration_mode='Text Only')
unchecked_narrator.pop('latest_diary_context_enabled',None)
unchecked_narrator.pop('only_diary_access',None)
r=request(embedded_narrator_form['action'],'POST',unchecked_narrator)
assert r.status==200,r.read().decode()
unchecked_preset=json.loads(request('/LorkhanServer/manage/exports/narrator-profile-settings/'+narrator_id+'.json').read().decode())
assert unchecked_preset['settings']['latest_diary_context_enabled'] is False
assert unchecked_preset['settings']['only_diary_access'] is False
assert legacy_narrator_export['settings']['only_diary_access'] is True
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
assert len(re.findall(r'data-profile-copy-setting="',copy_body))==15
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
r=json_request(copy_path,'POST',dict(copy_values,setting='profile_evolution.history_limit',value=12),csrf); copied_history=json.load(r)
assert r.status==200 and copied_history['profiles_updated']>=1,copied_history
copy_history_body=request('/LorkhanServer/ui/core/core_profiles.php?edit='+core_edit.group(1)).read().decode()
assert re.search(r'name="setting_profile_evolution_history_limit" value="12"',copy_history_body)
r=json_request(copy_path,'POST',dict(copy_values,revision=copied_history['revision'],setting='response.core_lang',value='fr'),csrf)
assert r.status==200
language_copied=json.load(r)
copy_language_body=request('/LorkhanServer/ui/core/core_profiles.php?edit='+core_edit.group(1)).read().decode()
assert '<option value="fr" selected>' in copy_language_body
assert json_request(copy_path,'POST',dict(copy_values,revision=language_copied['revision'],setting='response.core_lang',value='xx'),csrf).status==422
r=json_request(copy_path,'POST',dict(copy_values,revision=language_copied['revision'],setting='behavior.combat_bark_period_seconds',value=540),csrf)
assert r.status==200
copy_combat_body=request('/LorkhanServer/ui/core/core_profiles.php?edit='+core_edit.group(1)).read().decode()
assert re.search(r'name="setting_behavior_combat_bark_period_seconds" value="540"',copy_combat_body)
# STT tests use the owned fixed sample, not a TTS call, and expose only bounded results.
# Earlier TTS checks deliberately exhaust their browser's shared speech-test budget.
jar.clear()
request('/LorkhanServer/ui/home.php').read()
csrf=next(c.value for c in jar if c.name=='lorkhan_csrf')
r=json_request('/LorkhanServer/manage/api/v1/stt-providers','POST',{'installation_id':valid['installation_id'],'name':'HTTP STT test','content':{'driver':'localwhisper','endpoint':'http://'+provider_host+':'+str(voice_provider.server_port)+'/stt-test','model':'whisper-1','language':'en','timeout_ms':30000,'options':{}}},csrf)
created_stt=json.loads(r.read()); assert r.status==201,(r.status,created_stt)
stt_page,stt_body=parse(request('/LorkhanServer/ui/core/stt_connectors.php?installation_id='+valid['installation_id']+'&driver=localwhisper&embed=1'))
stt_form=next(f for f in stt_page.forms if f['action'].endswith('/forms/connector-revise'))
stt_values=dict(stt_form['fields'],_csrf=csrf,endpoint='http://'+provider_host+':'+str(voice_provider.server_port)+'/stt-test',options_json='{}')
r=request(stt_form['action'],'POST',stt_values); r.read(); assert r.status==200 and 'embed=1' in r.geturl() and '/ui/core/stt_connectors.php?' in r.geturl() and r.geturl().count('?')==1,r.geturl()
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
rename_values={'_csrf':csrf,'action':'label','variable':custom_variable,'display_label':'Renamed Voice & LLM'}
r=request(badge_path,'POST',dict(rename_values,_csrf='invalid'),accept=ajax); assert r.status==401
r=request(badge_path,'POST',dict(rename_values,display_label=''),accept=ajax); assert r.status==422
r=request(badge_path,'POST',rename_values,accept=ajax); assert r.status==200 and json.load(r)['ok'] is True
card_page,card_body=parse(request(badge_path))
assert 'Renamed Voice &amp; LLM' in card_body and 'data-custom-display-label' in card_body
assert 'custom-card has-key' in card_body and 'data-variable="'+custom_variable+'"' in card_body
assert 'Preset Keys (Saves Automatically)' in card_body and 'id="apikey-test-dialog"' in card_body and dummy_key not in card_body
for connector_page in ('llm_connectors', 'tts_connectors', 'stt_connectors'):
    _, connector_body = parse(request('/LorkhanServer/ui/core/'+connector_page+'.php?create=1&installation_id='+valid['installation_id']))
    assert 'Renamed Voice &amp; LLM' in connector_body and dummy_key not in connector_body, connector_page
r=request(badge_path,'POST',{'_csrf':csrf,'delete_custom':custom_variable},accept=ajax)
assert r.status==200 and json.loads(r.read())['ok'] is True
_,card_body=parse(request(badge_path)); assert custom_variable not in card_body
# Additional built-in badges use the same editor without changing their stable references.
for extra_variable in ('LORKHAN_LLM_OPENAI_API_KEY','LORKHAN_TTS_POCKETTTS_API_KEY'):
    extra_label='HTTP Additional '+extra_variable
    r=request(badge_path,'POST',{'_csrf':csrf,'action':'set','variable':extra_variable,'credential':dummy_key},accept=ajax)
    assert r.status==200 and dummy_key not in r.read().decode()
    r=request(badge_path,'POST',{'_csrf':csrf,'action':'label','variable':extra_variable,'display_label':extra_label},accept=ajax)
    assert r.status==200
    _,extra_body=parse(request(badge_path))
    assert extra_label in extra_body and 'data-configured="true" data-key-card data-variable="'+extra_variable+'"' in extra_body and dummy_key not in extra_body
    for connector_page in ('llm_connectors','tts_connectors','stt_connectors'):
        _,extra_body=parse(request('/LorkhanServer/ui/core/'+connector_page+'.php?create=1&installation_id='+valid['installation_id']))
        expected_reference=('openai' if extra_variable=='LORKHAN_LLM_OPENAI_API_KEY' else 'badge:'+extra_variable) if connector_page=='llm_connectors' else extra_variable
        assert extra_label in extra_body and expected_reference in extra_body and dummy_key not in extra_body, (connector_page,extra_variable)
    r=request(badge_path,'POST',{'_csrf':csrf,'delete_custom':extra_variable},accept=ajax)
    assert r.status==200
    _,extra_body=parse(request(badge_path))
    assert extra_label not in extra_body and 'data-configured="false" data-key-card data-variable="'+extra_variable+'"' in extra_body
r=request(badge_path,'POST',{'_csrf':csrf,'delete_custom':'LORKHAN_TTS_OPENAI_API_KEY'},accept=ajax)
assert r.status==422
# Full biography backup includes global overrides as well as selected-installation templates.
biography_export_url='/LorkhanServer/manage/exports/biographies/custom.csv?installation_id='+biography_installation
mixed_header=biography_header+['scope']; mixed=io.StringIO(); mixed_writer=csv.DictWriter(mixed,fieldnames=mixed_header); mixed_writer.writeheader()
global_row=dict(zip(biography_header,biography_row),scope='global',content_file='',record_id='http_global_biography',name='HTTP Global Biography',core='',voice_id='global_voice')
mixed_writer.writerow(global_row)
assert multipart_request(biography_import['action'],{'_csrf':csrf,'installation_id':biography_installation},'csv_file','global-biography.csv','text/csv',mixed.getvalue().encode()).status==200
backup_csv=request(biography_export_url).read().decode('utf-8-sig'); backup_rows=list(csv.DictReader(io.StringIO(backup_csv)))
assert next(row for row in backup_rows if row['name']=='HTTP Global Biography')['voice_id']=='global_voice'
assert set(row['scope'] for row in backup_rows)=={'global','installation'} and 'Export Custom NPCs' in request('/LorkhanServer/ui/core/npc_biographies.php').read().decode()
bad_mixed=io.StringIO(); bad_writer=csv.DictWriter(bad_mixed,fieldnames=mixed_header); bad_writer.writeheader()
bad_writer.writerow(dict(zip(biography_header,biography_row),scope='installation',record_id='atomic_mixed_failure',name='Atomic Mixed Failure'))
bad_writer.writerow(dict(global_row,name='G'*129))
assert multipart_request(biography_import['action'],{'_csrf':csrf,'installation_id':biography_installation},'csv_file','invalid-mixed.csv','text/csv',bad_mixed.getvalue().encode()).status!=200
assert 'atomic_mixed_failure' not in request(biography_export_url).read().decode()
reset_fields={'_csrf':csrf,'installation_id':biography_installation,'confirm':'Reset','embed':'1'}
assert request('/LorkhanServer/manage/forms/biography-reset','POST',dict(reset_fields,confirm='')).status==422
bad_reset_csrf=request('/LorkhanServer/manage/forms/biography-reset','POST',dict(reset_fields,_csrf='invalid'))
assert bad_reset_csrf.geturl().endswith('/LorkhanServer/ui/home.php')
assert request('/LorkhanServer/manage/forms/biography-reset','POST',dict(reset_fields,installation_id=str(uuid.uuid4()))).status!=200
assert request(biography_export_url).read().decode('utf-8-sig')==backup_csv
reset_result=request('/LorkhanServer/manage/forms/biography-reset','POST',reset_fields)
assert reset_result.status==200 and 'status=reset' in reset_result.geturl() and 'embed=1' in reset_result.geturl()
assert list(csv.DictReader(io.StringIO(request(biography_export_url).read().decode('utf-8-sig'))))==[]
assert multipart_request(biography_import['action'],{'_csrf':csrf,'installation_id':biography_installation},'csv_file','restore-biographies.csv','text/csv',backup_csv.encode()).status==200
restored_rows=list(csv.DictReader(io.StringIO(request(biography_export_url).read().decode('utf-8-sig'))))
assert sorted(restored_rows,key=lambda r:(r['scope'],r['name']))==sorted(backup_rows,key=lambda r:(r['scope'],r['name']))
# Managed Local LLM setup uses browser CSRF and an exact routing snapshot, without a provider call.
local_path='/LorkhanServer/manage/api/v1/quickstart-local-llm'
plan_response=request(local_path+'?installation_id='+valid['installation_id'])
assert plan_response.status==200
local_plan=json.loads(plan_response.read())['routing_plan']
local_body={'installation_id':valid['installation_id'],'fingerprint':local_plan['fingerprint'],
    'setup':{'server_type':'lm_studio','scope':'conversations','endpoint':'http://127.0.0.1:1234/v1/chat/completions','model':'isolated-routing-fixture'}}
assert json_request(local_path,'POST',local_body,csrf_token='').status==401
assert json_request(local_path,'POST',dict(local_body,unexpected=True),csrf_token=csrf).status==422
assert json_request(local_path,'POST',dict(local_body,setup=dict(local_body['setup'],api_key='must-not-be-stored')),csrf_token=csrf).status==422
saved_local=json_request(local_path,'POST',local_body,csrf_token=csrf)
saved_local_body=json.loads(saved_local.read())
assert saved_local.status==200,(saved_local.status,saved_local_body)
assert set(saved_local_body)=={'configuration_id','routing_plan'}
assert saved_local_body['routing_plan']['connector_revision']==1
stale_local=json_request(local_path,'POST',local_body,csrf_token=csrf)
assert stale_local.status==409,(stale_local.status,stale_local.read())
reloaded_plan=json.loads(request(local_path+'?installation_id='+valid['installation_id']).read())['routing_plan']
assert reloaded_plan==saved_local_body['routing_plan']
# The visible Local setup participates in the same player/profile transaction.
qs_page,qs_body=parse(request('/LorkhanServer/ui/quickstart.php?installation_id='+valid['installation_id']))
qs_form=next(f for f in qs_page.forms if f['action'].endswith('/forms/quickstart-save'))
qs_values=dict(qs_form['fields'],_csrf=csrf,settings_preset='builtin:local_llm',local_model='form-local-model',local_timeout='45',local_scope='all',local_disable_streaming='1')
assert 'Local LLM Setup' in qs_body and 'Test connection' in qs_body
before_plan=json.load(request(local_path+'?installation_id='+valid['installation_id']))
r=request(qs_form['action'],'POST',dict(qs_values,local_timeout='121')); failure=r.read().decode()
assert r.status==422,(r.status,failure)
assert json.load(request(local_path+'?installation_id='+valid['installation_id']))==before_plan
r=request(qs_form['action'],'POST',dict(qs_values,player_revision='1')); failure=r.read().decode()
assert r.status in (409,422),(r.status,failure)
assert json.load(request(local_path+'?installation_id='+valid['installation_id']))==before_plan
r=request(qs_form['action'],'POST',qs_values); saved=r.read().decode()
assert r.status==200 and 'Quickstart settings saved.' in saved,(r.status,saved)
reloaded,reloaded_body=parse(request('/LorkhanServer/ui/quickstart.php?installation_id='+valid['installation_id']))
fields=next(f['fields'] for f in reloaded.forms if f['action'].endswith('/forms/quickstart-save'))
assert fields['local_model']=='form-local-model' and fields['local_timeout']=='45' and fields['local_scope']=='all'
assert fields['local_disable_streaming']==''
r=request(qs_form['action'],'POST',qs_values); assert r.status==409,(r.status,r.read())
# The saved Local preset also updates supported global consumers, then Default restores them.
qs_local_export=json.load(request('/LorkhanServer/manage/exports/global-settings/'+global_configuration_id+'.json'))
qs_global=qs_local_export['settings']
assert not qs_local_export['memory_policies']['summary']['enabled'] and not qs_local_export['memory_policies']['embedding']['enabled']
assert qs_global['profile_management']['autofill_custom_profiles'] is False
assert qs_global['relationship']=={'enabled':False,'update_chance_percent':0}
assert qs_global['context']['ground_items_descriptions_only'] and qs_global['context']['inventory_items_descriptions_only']
r=request(qs_form['action'],'POST',dict(fields,_csrf=csrf,settings_preset='builtin:default')); body=r.read().decode()
assert r.status==200 and 'Quickstart settings saved.' in body,(r.status,body)
qs_default=json.load(request('/LorkhanServer/manage/exports/global-settings/'+global_configuration_id+'.json'))['settings']
assert qs_default['profile_management']['autofill_custom_profiles'] is True
assert qs_default['relationship']=={'enabled':True,'update_chance_percent':50}
assert not qs_default['context']['ground_items_descriptions_only'] and not qs_default['context']['inventory_items_descriptions_only']
assert qs_default['system_routing']==qs_global['system_routing']
qs_default_export=json.load(request('/LorkhanServer/manage/exports/global-settings/'+global_configuration_id+'.json'))
assert qs_default_export['memory_policies']['summary']['enabled'] and qs_default_export['memory_policies']['embedding']['enabled']
assert qs_default_export['memory_policies']['summary']['provider_configuration_id']==qs_local_export['memory_policies']['summary']['provider_configuration_id']
assert qs_default_export['memory_policies']['embedding']['endpoint']==qs_local_export['memory_policies']['embedding']['endpoint']
print('browser-like management HTTP forms passed')
