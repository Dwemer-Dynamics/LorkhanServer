// Native revisioned profile content with the same tree/code editor used by Herika.
const mounted = new Map();
let library;
const scan = () => {
    for (const [root, editor] of mounted) if (!root.isConnected) {
        editor?.destroy(); mounted.delete(root);
    }
    document.querySelectorAll('[data-profile-json-editor]').forEach(root => {
        if (root.dataset.initialized) return;
        root.dataset.initialized = '1';
        const source = root.querySelector('[data-json-editor-source]');
        const target = root.querySelector('[data-json-editor-target]');
        const status = root.querySelector('[data-json-editor-status]');
        const form = source.form;
        if (!form) return;
        const valid = () => {
            try {
                if (new TextEncoder().encode(source.value).length > 131072) throw new Error('Profile JSON exceeds 128 KiB.');
                const json = JSON.parse(source.value);
                if (!json || Array.isArray(json) || typeof json !== 'object') throw new Error('Profile JSON must be an object.');
                status.textContent = ''; return json;
            } catch (error) { status.textContent = error.message; return null; }
        };
        const update = content => {
            source.value = content.text !== undefined ? content.text : JSON.stringify(content.json, null, 2);
            valid();
            source.dispatchEvent(new Event('input', {bubbles:true}));
        };
        source.addEventListener('input', valid);
        form.addEventListener('submit', event => {
            const editor = mounted.get(root);
            // Validation flushes the library's debounced text edits before serialization.
            if (editor) editor.validate();
            if (valid() !== null) return;
            event.preventDefault(); event.stopImmediatePropagation();
            root.open = true;
            if (source.hidden) { target.tabIndex = -1; target.focus(); } else source.focus();
        }, true);
        root.addEventListener('toggle', async () => {
            if (!root.open || mounted.has(root)) return;
            const json = valid(); if (json === null) return;
            mounted.set(root, null);
            try {
                library ||= import('../lib/ui/vanilla-jsoneditor/standalone.js');
                const {createJSONEditor} = await library;
                if (!root.isConnected) { mounted.delete(root); return; }
                const editor = createJSONEditor({target, props: {content:{json}, onChange:update,
                    onError:error => { status.textContent = error.message; }}});
                mounted.set(root, editor);
                source.hidden = true;
                root.querySelector('label[for="' + source.id + '"]').hidden = true;
            } catch (_) {
                mounted.delete(root);
                status.textContent = 'Tree editor unavailable. Edit the JSON below; normal Save still works.';
            }
        });
    });
};
let scheduled = false;
new MutationObserver(() => {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => { scheduled = false; scan(); });
}).observe(document.body, {childList:true, subtree:true});
scan();
