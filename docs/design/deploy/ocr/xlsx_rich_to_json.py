# Excel（.xlsx）を太字・斜体の情報つきで JSON に書き出す（鉄緑会 英文法の例文一覧の取り込み用。build_tk_excel.py の入力）
#   python xlsx_rich_to_json.py <xlsx> <out.json> <preview.txt>
import openpyxl, json, sys
from openpyxl.cell.rich_text import CellRichText, TextBlock
wb = openpyxl.load_workbook(sys.argv[1], rich_text=True)
out = {'sheets': []}
for ws in wb.worksheets:
    rows = []
    for row in ws.iter_rows():
        r = []
        for c in row:
            v = c.value
            if isinstance(v, CellRichText):
                parts = []
                for blk in v:
                    if isinstance(blk, TextBlock):
                        f = blk.font
                        parts.append({'t': blk.text, 'b': bool(f.b) if f is not None else False, 'i': bool(f.i) if f is not None else False})
                    else:
                        parts.append({'t': str(blk), 'b': bool(c.font and c.font.b), 'i': False})
                r.append({'rich': parts})
            else:
                r.append({'v': v, 'b': bool(c.font and c.font.b)})
        rows.append(r)
    out['sheets'].append({'title': ws.title, 'rows': rows, 'dims': ws.dimensions})
json.dump(out, open(sys.argv[2], 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
with open(sys.argv[3], 'w', encoding='utf-8') as f:
    for sh in out['sheets']:
        f.write(f"== {sh['title']} {sh['dims']} rows={len(sh['rows'])}\n")
        for r in sh['rows'][:6]:
            f.write(' | '.join((''.join(p['t'] for p in c['rich']) if 'rich' in c else str(c['v'])) for c in r) + '\n')
