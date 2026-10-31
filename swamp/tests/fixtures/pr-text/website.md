Updates the dependencies listed below.

| Package | From | To | Change |
| --- | --- | --- | --- |
| @types/node | 25.9.9 | 26.6.5 | major |
| lucide-react | 0.577.0 | 1.60.0 | major |
| shaders | 2.5.135 | 4.0.4 | major |
| typescript | 5.9.3 | 7.0.2 | major |
| puppeteer | 25.3.0 | 25.15.0 | security fix |
| shadcn | 4.21.4 | 4.22.1 | security fix |

Security fixes

puppeteer and shadcn fix [GHSA-mrhx-c7xc-m3w5](https://github.com/advisories/GHSA-mrhx-c7xc-m3w5).

## What changes on the site

- **Types (@types/node 26, typescript 7).** Page modules now have explicit types; pages render the same.
- **lucide-react 1.** No code changes needed.
- **Background effect (shaders 4).** The light-ray effect now appears only when the browser offers WebGPU.
- **puppeteer 25.15.0.** No code changes needed.
- **shadcn 4.22.1.** No code changes needed.
