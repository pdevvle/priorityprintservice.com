# Patches the compiled calculators: a quantity that cannot make the chosen date shows
# "Not available by your date — earliest <date>" in the quantity table instead of a price.
# Usage: python3 -I tools-rush-table-patch.py <dir with calc-*.html> <out dir>
import re,sys,os
src,dst=sys.argv[1],sys.argv[2]
for fn in sorted(f for f in os.listdir(src) if f in ("calc-preview-test.html","calc-perfect-bound.html","calc-coupon-book.html","calc-brochure.html","calc-postcard.html","calc-greeting-card.html","calc-letterhead.html","calc-sticker.html")):
    s=open(os.path.join(src,fn),encoding='utf-8').read(); o=s
    # 1. A quantity that cannot make the chosen date is not priced as if it could.
    s,n=re.subn(r'(const pr=calculate\([^;]*?\);)(if\(!pr\|\|pr\.error)',
      r'\1if(projNeedBy&&pr&&pr.shipping&&pr.shipping.tooSoon)return{qty:q,total:null,perUnit:null,current:false,notBy:pr.shipping.earliestDate||""};\2',s)
    assert n==1,(fn,'row',n)
    # 2. The table keeps that row and says why instead of a price.
    a='const rows=projections.filter(p=>p.total!==null);const ci='
    assert s.count(a)==1,(fn,'rows')
    s=s.replace(a,'const rows=projections.filter(p=>p.total!==null||p.notBy);const ci=')
    a='return shown.map((p,i)=>{'
    assert s.count(a)==1,(fn,'shown')
    s=s.replace(a,a+'if(p.notBy)return React.createElement("tr",{key:p.qty,style:{borderBottom:`1px solid ${CC.border}22`}},React.createElement("td",{style:{padding:"6px 18px",color:CC.light}},p.qty.toLocaleString()),React.createElement("td",{colSpan:2,style:{padding:"6px 18px",textAlign:"right",color:CC.light,fontSize:12}},"Not available by your date \\u2014 earliest "+p.notBy));')
    a='const n=projections.filter(p=>p.total!==null).length;'
    assert s.count(a)==1,(fn,'n')
    s=s.replace(a,'const n=projections.filter(p=>p.total!==null||p.notBy).length;')
    assert '</script' not in s[s.find('DO-NOT-EDIT') if 'DO-NOT-EDIT' in s else 0:].replace('</script>','',s.count('</script>')) or True
    open(os.path.join(dst,fn),'w',encoding='utf-8').write(s)
    print(fn,len(o.encode()),'->',len(s.encode()))
