# vanilla-jsoneditor 3.13.0

MIT licensed. Source: https://registry.npmjs.org/vanilla-jsoneditor/-/vanilla-jsoneditor-3.13.0.tgz

The same editor library used by pinned HerikaServer metadata_json_editor.php.
Locally pinned to avoid runtime CDN dependencies. Original standalone.js SHA-256: 2345b95c5756bd7a1183bc6e31d9fca539f5adc70a44c9eb3f1f0082661dd160

Local change: the three style-element creation sites copy the page's CSP style
nonce from its metadata element. No unsafe-inline or unsafe-eval is enabled.
