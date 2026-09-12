/* NPC override drafts use Herika's row/picker flow and the existing revisioned NPC Save. */
(() => {
    document.querySelectorAll('[data-npc-overrides]').forEach(root => {
        const catalog = JSON.parse(root.dataset.catalog);
        const form = document.getElementById(root.dataset.form);
        const field = root.querySelector('[data-npc-override-value]');
        const list = root.querySelector('[data-npc-override-list]');
        const raw = root.querySelector('[data-npc-override-json]');
        const status = root.querySelector('[data-npc-override-status]');
        const dialog = root.querySelector('dialog');
        const picker = root.querySelector('[data-npc-override-picker]');
        const editor = root.querySelector('[data-npc-override-edit]');
        const search = root.querySelector('[data-npc-override-search]');
        const options = root.querySelector('[data-npc-override-options]');
        const save = root.querySelector('[data-npc-override-save]');
        const boolean = root.querySelector('[data-npc-override-bool]');
        const number = root.querySelector('[data-npc-override-number]');
        const text = root.querySelector('[data-npc-override-text]');
        let values = JSON.parse(field.value), selected = '', opener = null, dirty = false;
        const button = (text, action) => {
            const element = document.createElement('button'); element.type = 'button'; element.textContent = text;
            element.addEventListener('click', action); return element;
        };
        // All JSON paths are catalogued and typed before they can alter the draft.
        const validate = value => {
            if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Enter a JSON object of supported settings.');
            for (const [section, fields] of Object.entries(value)) {
                if (!fields || typeof fields !== 'object' || Array.isArray(fields)) throw new Error('Each section must be an object.');
                if (!Object.keys(catalog).some(path => path.startsWith(section + '.'))) throw new Error('Unsupported settings section: ' + section);
                for (const [key, setting] of Object.entries(fields)) {
                    const definition = catalog[section + '.' + key];
                    if (!definition) throw new Error('Unsupported setting: ' + section + '.' + key);
                    if (definition.type === 'boolean' ? typeof setting !== 'boolean'
                        : definition.type === 'choice' ? !definition.choices.includes(setting)
                        : definition.type === 'string' ? typeof setting !== 'string' || (!definition.allowEmpty && !setting.trim()) || new TextEncoder().encode(setting).length > definition.maxBytes
                        : definition.type === 'textlist' ? !Array.isArray(setting) || setting.length > 256 || setting.some(entry => typeof entry !== 'string' || new TextEncoder().encode(entry).length > 256)
                        : !Number.isInteger(setting) || setting < definition.range[0] || setting > definition.range[1])
                        throw new Error('Invalid value for ' + definition.label + '.');
                }
            }
            return value;
        };
        const render = () => {
            list.replaceChildren();
            for (const [path, definition] of Object.entries(catalog)) {
                const [section, key] = path.split('.'); if (!Object.hasOwn(values[section] || {}, key)) continue;
                const row = document.createElement('div'); row.className = 'npc-ovr-item';
                const icon = document.createElement('span'); icon.textContent = section === 'quest_comments' ? '🧭' : section === 'memory' ? '🧠' : section === 'behavior' ? '🔁' : '⚙️';
                const info = document.createElement('div'); info.className = 'npc-ovr-info';
                const label = document.createElement('strong'); label.textContent = definition.label;
                const value = document.createElement('span'); value.className = 'npc-ovr-value'; value.textContent = definition.labels?.[values[section][key]] ?? String(values[section][key]) + (definition.suffix || ''); info.append(label, value);
                const actions = document.createElement('div'); actions.className = 'npc-ovr-actions';
                const edit = button('Edit', () => { opener = edit; openSetting(path); dialog.showModal(); });
                edit.setAttribute('aria-label', 'Edit ' + definition.label);
                const remove = button('×', () => { delete values[section][key]; if (!Object.keys(values[section]).length) delete values[section]; sync(); root.querySelector('[data-npc-override-add]').focus(); });
                remove.className = 'danger'; remove.setAttribute('aria-label', 'Remove ' + definition.label);
                actions.append(edit, remove); row.append(icon, info, actions); list.append(row);
            }
            if (!list.children.length) { const empty = document.createElement('p'); empty.className = 'npc-ovr-empty'; empty.textContent = 'No overrides set. Click "Add Override" to customize settings for this NPC.'; list.append(empty); }
            raw.value = JSON.stringify(values, null, 2);
        };
        const sync = () => {
            field.value = JSON.stringify(values); dirty = true;
            field.dispatchEvent(new Event('change', {bubbles:true})); render();
            status.textContent = 'Unsaved changes. Save the NPC to apply these overrides.'; status.classList.remove('error');
        };
        const openSetting = path => {
            selected = path; const definition = catalog[path], [section, key] = path.split('.');
            root.querySelector('[data-npc-override-title]').textContent = 'Edit Override';
            picker.hidden = true; editor.hidden = false; save.hidden = false;
            const label = root.querySelector('[data-npc-override-label]'); label.textContent = definition.label;
            const isBoolean = definition.type === 'boolean', isChoice = definition.type === 'choice', isText = ['string','textlist'].includes(definition.type);
            boolean.hidden = !(isBoolean || isChoice); number.hidden = isBoolean || isChoice || isText; text.hidden = !isText;
            boolean.disabled = boolean.hidden; number.disabled = number.hidden; text.disabled = text.hidden;
            boolean.replaceChildren(...(isChoice ? definition.choices.map(value => new Option(definition.labels?.[value] ?? String(value) + (definition.suffix || ''), String(value))) : [new Option('On','true'),new Option('Off','false')]));
            label.htmlFor = isText ? text.id : isBoolean || isChoice ? boolean.id : number.id;
            const current = values[section]?.[key] ?? definition.value;
            if (isText) { text.required = !definition.allowEmpty; text.maxLength = definition.maxBytes; text.value = definition.type === 'textlist' ? current.join('\n') : current; text.setCustomValidity(''); }
            else if (isBoolean || isChoice) boolean.value = String(current);
            else { number.required = true; number.min = definition.range[0]; number.max = definition.range[1]; number.value = current; }
            root.querySelector('[data-npc-override-help]').textContent = isText ? 'Enter instructions. Removing this override restores inheritance.' : isBoolean ? 'An explicit On or Off overrides the inherited setting.' : isChoice ? 'Choose one of the listed values. Removing this override restores inheritance.' : 'Allowed range: ' + definition.range.join('–') + '. Removing this override restores inheritance.';
            if (definition.help) root.querySelector('[data-npc-override-help]').textContent = definition.help;
        };
        const filter = () => {
            options.replaceChildren();
            for (const [path, definition] of Object.entries(catalog)) {
                if (!(definition.label + ' ' + path).toLowerCase().includes(search.value.toLowerCase())) continue;
                options.append(button(definition.label, () => { openSetting(path); (text.hidden ? boolean.hidden ? number : boolean : text).focus(); }));
            }
            if (!options.children.length) options.textContent = 'No settings match your search.';
        };
        root.querySelector('[data-npc-override-add]').addEventListener('click', event => {
            opener = event.currentTarget; picker.hidden = false; editor.hidden = true; save.hidden = true;
            root.querySelector('[data-npc-override-title]').textContent = 'Add Override'; search.value = ''; filter(); dialog.showModal(); search.focus();
        });
        search.addEventListener('input', filter);
        for (const key of ['close','cancel']) root.querySelector('[data-npc-override-' + key + ']').addEventListener('click', () => dialog.close());
        dialog.addEventListener('keydown', event => { if (event.key === 'Escape') event.stopPropagation(); });
        dialog.addEventListener('close', () => opener?.focus());
        save.addEventListener('click', () => {
            const definition = catalog[selected], [section,key] = selected.split('.');
            if (definition.type === 'integer' && !number.reportValidity()) return;
            if (definition.type === 'string') {
                text.setCustomValidity((!definition.allowEmpty && !text.value.trim()) || new TextEncoder().encode(text.value).length > definition.maxBytes
                    ? 'Enter between ' + (definition.allowEmpty ? '0' : '1') + ' and ' + definition.maxBytes + ' UTF-8 bytes.' : '');
                if (!text.reportValidity()) return;
            }
            const choice = definition.type === 'choice' ? definition.choices.find(value => String(value) === boolean.value) : undefined;
            if (definition.type === 'choice' && choice === undefined) return;
            let value = Number(number.value);
            if (definition.type === 'boolean') value = boolean.value === 'true';
            else if (definition.type === 'choice') value = choice;
            else if (definition.type === 'string') value = text.value;
            else if (definition.type === 'textlist') {
                value = text.value.split(/\r?\n/).map(entry => entry.trim()).filter(Boolean);
                text.setCustomValidity(value.length > 256 || value.some(entry => new TextEncoder().encode(entry).length > 256) ? 'Use at most 256 entries, each at most 256 UTF-8 bytes.' : '');
                if (!text.reportValidity()) return;
            }
            values[section] ||= {}; values[section][key] = value;
            sync(); dialog.close();
        });
        root.querySelector('[data-npc-override-apply]').addEventListener('click', () => {
            try { values = validate(JSON.parse(raw.value)); sync(); }
            catch (error) { status.textContent = error.message; status.classList.add('error'); }
        });
        // Relationship batches already own their Save request; mirror failures into this visible tab.
        const relationshipStatus = form.closest('[data-npc-modal]')?.querySelector('[data-rel-draft-status]');
        if (relationshipStatus) new MutationObserver(() => { if (dirty) status.textContent = relationshipStatus.textContent; }).observe(relationshipStatus, {childList:true,subtree:true,characterData:true});
        form.addEventListener('submit', async event => {
            if (!dirty || event.defaultPrevented) return;
            event.preventDefault(); if (form.getAttribute('aria-busy') === 'true') return;
            const body = new URLSearchParams(new FormData(form));
            const controls = [...form.elements, ...form.closest('[data-npc-modal]').querySelectorAll('button')].map(control => [control,control.disabled]);
            field.dispatchEvent(new Event('change',{bubbles:true}));
            form.setAttribute('aria-busy','true'); controls.forEach(([control]) => { control.disabled = true; });
            status.textContent = 'Saving NPC changes…';
            try {
                const response = await fetch(form.action, {method:'POST',body,credentials:'same-origin'});
                const destination = new URL(response.url);
                if (!response.ok) throw new Error(response.status === 409 ? 'The NPC changed. Your draft is still here; review the latest saved values before retrying.' : 'Save failed (' + response.status + '). Your draft is still here.');
                if (destination.origin !== location.origin || !destination.pathname.endsWith('/ui/core/npc_master.php') || destination.searchParams.get('status') !== 'saved') throw new Error('Save was not confirmed. Your draft is still here.');
                form.dispatchEvent(new Event('lorkhan:discard-draft')); dirty = false; location.assign(destination.href);
            } catch (error) { status.textContent = error.message; status.classList.add('error'); }
            finally { form.removeAttribute('aria-busy'); controls.forEach(([control,disabled]) => { control.disabled = disabled; }); if (dirty) field.dispatchEvent(new Event('change',{bubbles:true})); }
        });
        render();
    });
})();
