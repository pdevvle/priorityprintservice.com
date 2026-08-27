// Build an offline-runnable copy of a compiled calculator: vendor the CDN
// scripts, and load the live theme stylesheet so the harness renders what
// production actually serves. Injecting only the img/svg/video line (the last
// harness) still hid every other theme rule that reaches portalled modals --
// notably `button { color: inherit; background: none; border: 0 }`.
import fs from 'fs';
const SRC=process.argv[2], OUT=process.argv[3];
const REPL=[
 ['https://unpkg.com/react@18.3.1/umd/react.production.min.js','./node_modules/react/umd/react.production.min.js'],
 ['https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js','./node_modules/react-dom/umd/react-dom.production.min.js'],
 ['https://unpkg.com/jspdf@2.5.1/dist/jspdf.umd.min.js','./node_modules/jspdf/dist/jspdf.umd.min.js'],
 ['https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/legacy/build/','./node_modules/pdfjs-dist/legacy/build/'],
 ['https://unpkg.com/pdfjs-dist@4.10.38/legacy/build/','./node_modules/pdfjs-dist/legacy/build/'],
];
let s=fs.readFileSync(SRC,'utf8');
for(const [a,b] of REPL) s=s.split(a).join(b);
const i=s.indexOf('</head>');
if(i<0) throw new Error('no </head>');
s=s.slice(0,i)+'<link rel="stylesheet" href="./theme-main.css">'+s.slice(i);
fs.writeFileSync(OUT,s);
console.log('wrote',OUT,s.length,'bytes');
