# Keel brand assets

The mark is a small two-sail boat in side profile: a low hull on a half-opacity
waterline carrying a mainsail and jib, with a deep centerboard-style keel below
the surface. The waterline doubles as the "even keel" cue behind the tagline,
*Sensible defaults for steady sites.*

## Source marks (`currentColor`, theme to any context)

| File | Use |
| --- | --- |
| `keel-mark.svg` | Primary mark. Square, text-free — embedded in the plugin UI and the favicon source. |
| `keel-logo-horizontal.svg` | Mark + wordmark, side by side. The word "Keel" is **outlined** (Avenir Next Medium), so it needs no font at render time. |
| `keel-logo-stacked.svg` | Mark above the wordmark, centered. Wordmark outlined. |

## wordpress.org listing assets (`.wordpress-org/`)

The deploy action maps `.wordpress-org/` to the listing's SVN `assets/` folder.
These use an explicit **white mark on navy `#1b2a38`** (not `currentColor`), so
they render identically everywhere.

| File | Purpose |
| --- | --- |
| `icon.svg` | Vector listing icon (preferred by wp.org when present). |
| `icon-256x256.png` / `icon-128x128.png` | Raster listing icons. |
| `banner-772x250.png` / `banner-1544x500.png` | Listing banner (1x + retina); white mark, outlined "Keel" (Avenir Next Demi Bold), and the tagline. |

Rasters were rendered from the SVGs with `qlmanage`; regenerate them the same way
if the mark changes.
