import fs from 'fs';
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const Babel = require(process.env.SCRATCH + '/node_modules/@babel/standalone/babel.min.js');
const html = fs.readFileSync(process.argv[2], 'utf8');
// Extract every <script type="text/babel"> block. The literal </script> close
// tag is escaped as <\/script> inside the block, so a plain close-tag match is safe.
const re = /<script\b[^>]*type=["']text\/babel["'][^>]*>([\s\S]*?)<\/script>/g;
let m, n = 0, failed = false;
while ((m = re.exec(html))) {
  n++;
  const code = m[1];
  try {
    Babel.transform(code, { presets: ['react'], filename: `block${n}.jsx` });
    console.log(`block ${n}: OK (${code.length} chars)`);
  } catch (e) {
    failed = true;
    console.log(`block ${n}: SYNTAX ERROR`);
    console.log(String(e.message).split('\n').slice(0, 8).join('\n'));
  }
}
console.log(`\n${n} babel block(s) checked — ${failed ? 'FAIL' : 'ALL COMPILE'}`);
process.exit(failed ? 1 : 0);
