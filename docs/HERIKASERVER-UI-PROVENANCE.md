# HerikaServer UI provenance

This document records the presentation-only HerikaServer baseline imported for the LORKHANserver
management UI rebuild. Herika runtime bootstrap, database access, Skyrim behavior, and provider logic
are not sources for the LORKHAN implementation.

## Pinned source

- Repository: `https://github.com/abeiro/HerikaServer.git`
- Ref inspected: `origin/unstable`
- Presentation import commit: `c6ba5921737e0cecbe59ab3f1f15cf06406e0fdf`
- Active schema/runtime comparison commit: `508d7335d26f8027b3d576137bf161b331ed8ad9`
- Import mode: byte-identical presentation baseline before LORKHAN rewiring

## Imported files

| HerikaServer source | LORKHANserver destination |
| --- | --- |
| `ui/global_settings.php` | `public/ui/global_settings.php` |
| `ui/core/config_hub.php` | `public/ui/core/config_hub.php` |
| `ui/core/core_profiles.php` | `public/ui/core/core_profiles.php` |
| `ui/core/npc_master.php` | `public/ui/core/npc_master.php` |
| `ui/core/tmpl/data_tables.php` | `public/ui/core/tmpl/data_tables.php` |
| `ui/core/tmpl/header.php` | `public/ui/core/tmpl/header.php` |
| `ui/core/tmpl/metadata_json_editor.php` | `public/ui/core/tmpl/metadata_json_editor.php` |
| `ui/core/tmpl/override_editor.php` | `public/ui/core/tmpl/override_editor.php` |
| `ui/core/tmpl/ui_utils.php` | `public/ui/core/tmpl/ui_utils.php` |
| `ui/tmpl/head.html` | `public/ui/tmpl/head.html` |
| `ui/tmpl/footer.html` | `public/ui/tmpl/footer.html` |
| `ui/tmpl/navbar.php` | `public/ui/tmpl/navbar.php` |
| `ui/css/chim-theme.css` | `public/ui/css/chim-theme.css` |
| `ui/css/clickme.gif` | `public/ui/css/clickme.gif` |
| `ui/css/collapse.gif` | `public/ui/css/collapse.gif` |
| `ui/css/expand.gif` | `public/ui/css/expand.gif` |
| `ui/css/hub-navigation.css` | `public/ui/css/hub-navigation.css` |
| `ui/css/main.css` | `public/ui/css/main.css` |
| `ui/css/management.css` | `public/ui/css/management.css` |
| `ui/css/navbar.css` | `public/ui/css/navbar.css` |
| `ui/css/style.css` | `public/ui/css/style.css` |
| `ui/css/style_new.css` | `public/ui/css/style_new.css` |
| `ui/css/font/MagicCardsNormal.ttf` | `public/ui/css/font/MagicCardsNormal.ttf` |
| `ui/lib/ui/bootstrap/bootstrap.bundle.min.js` | `public/ui/lib/ui/bootstrap/bootstrap.bundle.min.js` |
| `ui/lib/ui/bootstrap/bootstrap.bundle.min.js.map` | `public/ui/lib/ui/bootstrap/bootstrap.bundle.min.js.map` |
| `ui/lib/ui/bootstrap/bootstrap.min.css` | `public/ui/lib/ui/bootstrap/bootstrap.min.css` |
| `ui/lib/ui/bootstrap/bootstrap.min.css.map` | `public/ui/lib/ui/bootstrap/bootstrap.min.css.map` |
| `ui/images/DwemerDynamics.png` | `public/ui/images/DwemerDynamics.png` |
| `ui/images/favicon.ico` | `public/ui/images/favicon.ico` |
| `ui/images/navbarback.png` | `public/ui/images/navbarback.png` |
| `ui/images/serverlogo.png` | `public/ui/images/serverlogo.png` |
| `ui/images/serverlogodev.png` | `public/ui/images/serverlogodev.png` |

## Replaced brand assets

The imported files table records the original Herika import. The brand assets below no longer hold
that byte-identical baseline; they were regenerated from Dwemer Dynamics' LORKHAN artwork
(`icon.png` pictorial mark, `ServerLogo.png` wordmark) and are owned by this project.

| LORKHANserver path | Regenerated from | Notes |
| --- | --- | --- |
| `public/ui/images/favicon.ico` | `icon.png` | 16/32/48 px, 32-bit BMP entries, alpha preserved |
| `public/ui/images/lorkhan-logo.png` | `icon.png` | 512x512 RGBA navbar/brand mark; established asset path retained |
| `public/ui/images/serverlogo.png` | `ServerLogo.png` | 103x42 RGBA wordmark, unscaled; rendered as the navbar text wordmark beside the brand mark |
| `public/ui/images/serverlogodev.png` | `ServerLogo.png` | wordmark centred on the original 153x42 canvas; currently unreferenced |

`public/ui/images/DwemerDynamics.png` and `public/ui/images/navbarback.png` remain the imported
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
