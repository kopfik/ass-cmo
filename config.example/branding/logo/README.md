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
