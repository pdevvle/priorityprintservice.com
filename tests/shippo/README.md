# Shippo client-contract harness

Headless acceptance test for the calculator-side Shippo wiring (the debounced
transit-estimate fetch). No real network: `window.fetch` is mocked before page
scripts run. See `docs/SHIPPO_TESTING.md` for the full regime.

## Setup (once per sandbox — CDNs are usually egress-blocked)

```bash
cd "$SCRATCH"   # any writable dir outside the repo
npm init -y && npm install react@18.3.1 react-dom@18.3.1 @babel/standalone@7.26.9 jspdf@2.5.1 pdfjs-dist@3.11.174
sed -e 's|https://unpkg.com/react@18.3.1/umd/react.production.min.js|./node_modules/react/umd/react.production.min.js|' \
    -e 's|https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js|./node_modules/react-dom/umd/react-dom.production.min.js|' \
    -e 's|https://unpkg.com/@babel/standalone@7.26.9/babel.min.js|./node_modules/@babel/standalone/babel.min.js|' \
    -e 's|https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js|./node_modules/pdfjs-dist/build/pdf.min.js|' \
    -e 's|https://unpkg.com/jspdf@2.5.1/dist/jspdf.umd.min.js|./node_modules/jspdf/dist/jspdf.umd.min.js|' \
    <repo>/calc-preview-test.html > calc-under-test.html
http-server -p 8123 -a 127.0.0.1 --silent . &
```

## Run

```bash
CALC_URL=http://127.0.0.1:8123/calc-under-test.html node <repo>/tests/shippo/client-contract.mjs              # baseline (informational)
CALC_URL=http://127.0.0.1:8123/calc-under-test.html node <repo>/tests/shippo/client-contract.mjs --acceptance # gate for the wiring PR
```

Repeat per calculator file being wired (swap the sed input). The `--acceptance`
run must pass on every wired calculator before the wiring ships.
