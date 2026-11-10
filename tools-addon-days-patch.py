# Add-on and paper lead days sit ON TOP of the minimum turnaround (owner 2026-11-10: "paper
# lead time should be atop the minimum, other addons are outside the minimum as well").
# Before: days = max(minimum, base print days + paper + add-ons), so a small run on factory
# paper (base 0, minimum 2, paper 2) read 2 days, the same as an in-stock paper.
# After:  days = max(minimum, base print days) + paper + add-ons (+ the saddle's human day).
# Patches the compiled calculators: one anchored edit each, asserted unique.
# Usage: python3 -I tools-addon-days-patch.py <dir with calc-*.html> <out dir>
import re,sys,os
src,dst=sys.argv[1],sys.argv[2]
FLATS=("calc-brochure.html","calc-postcard.html","calc-greeting-card.html","calc-letterhead.html","calc-sticker.html")
for fn in FLATS+("calc-perfect-bound.html","calc-coupon-book.html","calc-preview-test.html"):
    s=open(os.path.join(src,fn),encoding='utf-8').read(); o=s
    if fn in FLATS:
        s,n=re.subn(r'const days=Math\.max\(PCF\.minimum_turnaround_days,baseDays\+([^;]*?)\);',
                    r'const days=Math.max(PCF.minimum_turnaround_days,baseDays)+(\1);',s)
    elif fn=="calc-preview-test.html":
        b='const days=Math.max(PCF.minimum_turnaround_days,_summedDays)+hT;'
        d='minimumApplied:_summedDays<PCF.minimum_turnaround_days'
        assert s.count(b)==1 and s.count(d)==1,(fn,'anchors')
        # _summedDays stays for the debug panel; the minimum now floors the print days only.
        s=s.replace(b,'const days=Math.max(PCF.minimum_turnaround_days,sheetsToDays(tS))+Math.floor(pT+aT+artT)+hT;')
        s=s.replace(d,'minimumApplied:sheetsToDays(tS)<PCF.minimum_turnaround_days'); n=1
    else:
        a='const days=Math.max(PCF.minimum_turnaround_days,Math.floor(sheetsToDays(tS)+pT+aT+artT));'
        # Perfect binding itself (perfBindTurnaround, in aT) is how every one of these books is
        # made, not an option, so it stays with the print days inside the minimum.
        aT=re.search(r'const aT=([^;]*);',s)
        assert s.count('const perfBindTurnaround=')==1 and aT and aT.group(1).count('+perfBindTurnaround')==1,(fn,'bind')
        n=s.count(a); s=s.replace(a,'const days=Math.max(PCF.minimum_turnaround_days,sheetsToDays(tS)+perfBindTurnaround)+Math.floor(pT+(aT-perfBindTurnaround)+artT);')
    assert n==1,(fn,n)
    open(os.path.join(dst,fn),'w',encoding='utf-8').write(s)
    print(fn,len(o.encode()),'->',len(s.encode()))
