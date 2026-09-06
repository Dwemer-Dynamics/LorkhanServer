# Third-party notices

## Home dashboard word cloud

The Home dashboard vendors D3 7.9.0 (ISC) and d3-cloud 1.2.7 (BSD-3-Clause)
to use HerikaServer's word-cloud layout without external runtime scripts.
Unmodified distributions and their full licenses are in `ui/lib/ui/d3/`.

- D3: https://github.com/d3/d3/tree/v7.9.0
- d3-cloud: https://github.com/jasondavies/d3-cloud/tree/v1.2.7

## Dwemer Dynamics shared server UI

Quickstart's section/card geometry and typography derive from HerikaServer
`529364c4c12b3a8bd4cc12a481f400ce19b3a344`, `ui/quickstart.php`, with
Lorkhan colors, protected forms and existing connector identities retained.

API Keys card/section layout, custom editor controls and test-reader geometry derive
from the same revision's `ui/core/api_badge.php` and `ui/core/tests/apikey_test.php`.
Lorkhan retains status-only credential rendering and fixed authentication probes.

The LorkhanServer management interface derives its presentation structure and selected CSS, font,
Bootstrap, and image assets from the maintained Dwemer Dynamics server UI lineage used by
DialecticServer and StobeServer. The shared StobeServer distribution records this UI lineage under
the MIT License:

Copyright (c) 2026 Dwemer Dynamics

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and
associated documentation files (the "Software"), to deal in the Software without restriction,
including without limitation the rights to use, copy, modify, merge, publish, distribute,
sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial
portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT
NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM,
DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT
OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

## UESP Morrowind reference material

The optional offline biography-generation tool can enrich official Morrowind NPC identity records
with material retrieved from the Unofficial Elder Scrolls Pages (UESP). UESP's MediaWiki rights
metadata identifies this material as Attribution-ShareAlike 2.5. Generated catalog artifacts retain
the exact source page and revision identifiers used for each character. UESP material is paraphrased;
raw page caches are local build inputs and are not distributed with LorkhanServer.

Source: https://en.uesp.net/wiki/UESPWiki:Copyright_and_Ownership
