# HerikaServer UI provenance

This document records the presentation-only HerikaServer baseline imported for the LorkhanServer
management UI rebuild. Herika runtime bootstrap, database access, Skyrim behavior, and provider logic
are not sources for the LORKHAN implementation.

## Pinned source

The 2026-09-05 presentation follow-up uses Dwemer-Dynamics/HerikaServer
`529364c4c12b3a8bd4cc12a481f400ce19b3a344` for current hub navigation, compact
headers, Action Editor, dashboard and log reader geometry. See
[WEB-UI-PARITY.md](WEB-UI-PARITY.md) for the full page map, adaptations and evidence.
The original import below is retained as historical provenance; no Herika runtime
code was imported by the follow-up. Existing source license notices remain.

The all-pages follow-up also adapts `ui/ai-response.php` prompt/table presentation,
`ui/css/diary_adventure.css` calendar and paper reader presentation, and the Memories
and Books tables in `ui/events-memories.php`. `ui/images/paper.jpg` and
`ui/css/font/SkyrimBooks_Handwritten_Bold-Regular.ttf` are copied unchanged from
the same pinned snapshot. Data access and form handlers remain Lorkhan-owned.

- Repository: `https://github.com/abeiro/HerikaServer.git`
- Ref inspected: `origin/unstable`
- Presentation import commit: `c6ba5921737e0cecbe59ab3f1f15cf06406e0fdf`
- Active schema/runtime comparison commit: `508d7335d26f8027b3d576137bf161b331ed8ad9`
- Import mode: byte-identical presentation baseline before LORKHAN rewiring

## Imported files

| HerikaServer source | LorkhanServer destination |
| --- | --- |
| `ui/global_settings.php` | `ui/global_settings.php` |
| `ui/core/config_hub.php` | `ui/core/config_hub.php` |
| `ui/core/core_profiles.php` | `ui/core/core_profiles.php` |
| `ui/core/npc_master.php` | `ui/core/npc_master.php` |
| `ui/core/tmpl/data_tables.php` | `ui/core/tmpl/data_tables.php` |
| `ui/core/tmpl/header.php` | `ui/core/tmpl/header.php` |
| `ui/core/tmpl/metadata_json_editor.php` | `ui/core/tmpl/metadata_json_editor.php` |
| `ui/core/tmpl/override_editor.php` | `ui/core/tmpl/override_editor.php` |
| `ui/core/tmpl/ui_utils.php` | `ui/core/tmpl/ui_utils.php` |
| `ui/tmpl/head.html` | `ui/tmpl/head.html` |
| `ui/tmpl/footer.html` | `ui/tmpl/footer.html` |
| `ui/tmpl/navbar.php` | `ui/tmpl/navbar.php` |
| `ui/css/chim-theme.css` | `ui/css/chim-theme.css` |
| `ui/css/clickme.gif` | `ui/css/clickme.gif` |
| `ui/css/collapse.gif` | `ui/css/collapse.gif` |
| `ui/css/expand.gif` | `ui/css/expand.gif` |
| `ui/css/hub-navigation.css` | `ui/css/hub-navigation.css` |
| `ui/css/main.css` | `ui/css/main.css` |
| `ui/css/management.css` | `ui/css/management.css` |
| `ui/css/navbar.css` | `ui/css/navbar.css` |
| `ui/css/style.css` | `ui/css/style.css` |
| `ui/css/style_new.css` | `ui/css/style_new.css` |
| `ui/css/font/MagicCardsNormal.ttf` | `ui/css/font/MagicCardsNormal.ttf` |
| `ui/lib/ui/bootstrap/bootstrap.bundle.min.js` | `ui/lib/ui/bootstrap/bootstrap.bundle.min.js` |
| `ui/lib/ui/bootstrap/bootstrap.bundle.min.js.map` | `ui/lib/ui/bootstrap/bootstrap.bundle.min.js.map` |
| `ui/lib/ui/bootstrap/bootstrap.min.css` | `ui/lib/ui/bootstrap/bootstrap.min.css` |
| `ui/lib/ui/bootstrap/bootstrap.min.css.map` | `ui/lib/ui/bootstrap/bootstrap.min.css.map` |
| `ui/images/DwemerDynamics.png` | `ui/images/DwemerDynamics.png` |
| `ui/images/favicon.ico` | `ui/images/favicon.ico` |
| `ui/images/navbarback.png` | `ui/images/navbarback.png` |
| `ui/images/serverlogo.png` | `ui/images/serverlogo.png` |
| `ui/images/serverlogodev.png` | `ui/images/serverlogodev.png` |

## Replaced brand assets

The imported files table records the original Herika import. The brand assets below no longer hold
that byte-identical baseline; they were regenerated from Dwemer Dynamics' LORKHAN artwork
(`icon.png` pictorial mark, `ServerLogo.png` wordmark) and are owned by this project.

| LorkhanServer path | Regenerated from | Notes |
| --- | --- | --- |
| `ui/images/favicon.ico` | `icon.png` | 16/32/48 px, 32-bit BMP entries, alpha preserved |
| `ui/images/lorkhan-logo.png` | `icon.png` | 512x512 RGBA navbar/brand mark; established asset path retained |
| `ui/images/serverlogo.png` | `ServerLogo.png` | 103x42 RGBA wordmark, unscaled; rendered as the navbar text wordmark beside the brand mark |
| `ui/images/serverlogodev.png` | `ServerLogo.png` | wordmark centred on the original 153x42 canvas; currently unreferenced |

`ui/images/DwemerDynamics.png` and `ui/images/navbarback.png` remain the imported
shared Dwemer Dynamics assets and are unchanged. `navbarback.png` is still used as the navbar
background texture; `DwemerDynamics.png` is retained but no longer rendered, because the navbar brand
group now shows only the LORKHAN mark and wordmark.

## Rewiring boundary

The imported PHP pages are an HTML, CSS, JavaScript, and interaction reference. Before deployment,
their bootstrap and mutations must use LORKHAN browser sessions, CSRF validation, typed services,
PostgreSQL migrations, OpenMW identities, and the revisioned Global Settings -> Core Profile -> NPC
resolver. The Herika STT connector page is rewired as a single installation-global LORKHAN connector;
the ITT page and Soulgaze surface are removed for beta, while remaining Skyrim-only and Background
Life controls stay excluded.


## Quickstart and biography reset parity follow-up

Reference: HerikaServer `b08ffba70178abca08d7023c9304f46d57139045`.
`ui/quickstart.php` and `ui/css/quickstart.css` reimplement the
section/card setup workflow from `ui/quickstart.php`, using Lorkhan's existing
connectors, Core Profiles, CSRF, and revision services. No Herika configuration
writer or arbitrary endpoint probe is imported.

The Reset NPC action in `ui/tmpl/resource_page.php` follows
`ui/core/npc_master.php`'s non-empty biography template reset. Lorkhan matches
stable record/content identities and writes an explicit reversible revision;
voice, routing and game saves are preserved. This is distinct from CHIM's
save-time profile history pullback, which remains outside this web workflow.
