# Keel — brand package

Mark: a racing yacht under sail, one fin keel, drawn on a 200×200 grid.
Tagline: **Keeps your site upright with balanced defaults.**
(Short alternate: Balanced defaults for steady sites.)

## Colours

| Role | Light ground | Dark ground |
| --- | --- | --- |
| Mast + hull | #1d1f20 | #ffffff |
| Sails | #75797c | #9a9ea1 |
| Keel | #5980a6 | #7ba3c9 |
| Ground | #ffffff | #1d1f20 |

Dark-ground values are lifted, not inverted — do not flip the light set.

## Type

Barlow Condensed, uppercase, ~0.10em tracking for the name. Barlow regular for the tagline.

## Files

- `svg/` — the mark in every colourway. `*-reverse` and `-white` are for dark grounds; `keel-on-steel` is for the #5980a6 tile.
- `png/` — 256 and 128px directory icons, light / dark / steel.
- `banner/` — WordPress plugin banners, 772×250 and 1544×500, light and dark.

## Rules

- Minimum size 16px; below 24px use the 2-colour version — the grey sails stop separating.
- Clear space: one mast-width (5 grid units ≈ 2.5% of the mark) on all sides.
- Never recolour the keel to anything but the steel or the ground colour; it is the namesake.
- Do not add outlines, gradients or shadows.

## Changing the banner text

The banners have no layered source. The 2026-10-13 tagline change was made by
covering the old line on the existing PNG and setting the new one over it in
Barlow regular, colour `#4a4e51`: 34px on the 1544×500 banner and 17px on the
772×250 one, left-aligned with the rule under the name. Everything outside the
tagline is unchanged pixel for pixel.
