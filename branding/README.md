# Keel brand assets

Monochrome marks for Keel. Every file uses `currentColor`, so the mark inherits
its color from context and works on light or dark backgrounds without edits.

The mark is a sailboat in side profile: a hull on the waterline with the keel
fin (and its bulb) below the surface. The half-opacity waterline doubles as the
"even keel" cue behind the tagline, *Sensible defaults for steady sites.*

| File | Use |
| --- | --- |
| `keel-mark.svg` | Primary mark. Square, text-free — the icon and favicon source. |
| `keel-mark-fin.svg` | Minimal alternative: just the keel fin + bulb. Best at very small sizes. |
| `keel-logo-horizontal.svg` | Mark + wordmark, side by side. |
| `keel-logo-stacked.svg` | Mark above the wordmark, centered. |

`.wordpress-org/icon.svg` is a copy of `keel-mark.svg` — it is the icon the
wordpress.org plugin directory renders, and lives there because the deploy
action maps `.wordpress-org/` to the listing's SVN `assets/` folder.

## Notes

- The two wordmark lockups set "Keel" with a system sans font stack so they stay
  editable. **Convert that text to outlines before shipping a production logo**
  so it renders identically everywhere.
- For a raster wordpress.org icon, render `keel-mark.svg` to
  `icon-256x256.png` (and `icon-128x128.png`) on a transparent or solid
  background.
