# Runtime logo branding

Copy custom logo files to:

- config.local/branding/logo/header.svg
- config.local/branding/logo/header.png

SVG is preferred. Runtime branding files are intentionally not tracked by Git.

## Recommended size

The header logo is scaled by CSS to fit the brand box: max 212 px wide, max 36 px tall
(aspect ratio is preserved). A wide/horizontal logo works best.

- SVG: any size, but it must have a `viewBox` attribute to scale correctly.
- PNG/WebP: about 424x72 px (2x for sharp rendering on HiDPI displays).

## Logo for dark themes

If the header logo is hard to see on dark backgrounds, add a light variant next to it:

- config.local/branding/logo/header-dark.svg (or .png / .webp)

It is shown on the Dark, Midnight, Dust Dark, Rosé Pine Moon and Rosé Pine themes, and in Auto when the system is dark. The regular `header.*` logo is used on the other themes. `header-dark.*` is ignored when there is no `header.*`.
