#!/usr/bin/env python3
"""Edit/reorder restores cardstock papers on the brochure and greeting card (2026-11-12).

Their restore looked only in PAPERS_NC, so a cardstock job (paper.type "cardstock", label
"... (Cardstock)") came back on the default text paper at a lower price — found replaying a
share-cart Edit Specs: 14pt C2S 21x7 trifold, $565.73 on order, $396.75 after Edit.
One anchored edit per file, asserted unique.  usage: tools-paper-restore-patch.py in.html out.html
"""
import sys
OLD = 'const match=PAPERS_NC.find(p=>p.label===rc.paper.label);'
NEW = ('const _pl=rc.paper.label,_pf=L=>L.find(p=>p.label===_pl||p.label+" (Cardstock)"===_pl);'
       'const match=rc.paper.type==="cardstock"?(_pf(PAPERS_CS)||_pf(PAPERS_NC)):(_pf(PAPERS_NC)||_pf(PAPERS_CS));')
src, dst = sys.argv[1], sys.argv[2]
s = open(src, encoding='utf-8').read()
assert s.count(OLD) == 1, f'{src}: anchor found {s.count(OLD)} times'
assert 'PAPERS_CS=' in s
open(dst, 'w', encoding='utf-8').write(s.replace(OLD, NEW))
print(dst, len(s.encode()), '->', len(s.replace(OLD, NEW).encode()))
