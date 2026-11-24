# 鉄緑会 英文法: 例文カードに出す画像を「その例文が属する節の説明部分」（節の見出し 〜 その節の最初の Ex. の直前。説明文・図・語句の囲みを含む）にする。
#   python build_tk_sections.py <OCR dir> <PDF> <cards JSON> <画像出力 dir> <report>
# - OCR（ocr_tk.py。250dpi・ja / en-US）の行から、週見出し・節見出し（「1.3 …」）を拾う
# - 例文カードは英文の類似度で OCR の英語行（その週のページ）に対応づけ、位置（ページ・y）を得る。「Ex.」の印が読めていない行も拾えるようにする
# - 節ごとに「見出し（直前に章見出し「2 単純形」があればそこから）〜 その節の最初の例文（Ex. の印 or カードが対応づいた行）の直前」を切り出す。
#   ページをまたぐときは 2 ページ分を縦につなぐ
# - 例文カードの answerImage をその節の画像にする（page も対応づいた行のページに直す）。対応づかないカードは前後のカードの節を使う
import json, os, re, sys, difflib
import pymupdf
from PIL import Image

OCR, PDF, JSON_PATH, IMG_DIR, REPORT = sys.argv[1:6]
FIRST, LAST = 33, 460
DPI = 250
IMG_DPI = 130
doc = pymupdf.open(PDF)
os.makedirs(IMG_DIR, exist_ok=True)

SEC_RE = re.compile(r'^(\d{1,2}\.\d{1,2})\s*(.*)$')
CHAP_RE = re.compile(r'^[1-9]\d?\s*[^\d\.\s]')
EX_RE = re.compile(r'^E[xX][\.,]?\s*')


def line_box(l):
    xs = [w[1] for w in l['words']]
    ys = [w[2] for w in l['words']]
    x2 = max(w[1] + w[3] for w in l['words'])
    y2 = max(w[2] + w[4] for w in l['words'])
    return min(xs), min(ys), x2, y2


def norm_tokens(s):
    s = s.replace('“', '"').replace('”', '"').replace('’', "'").replace('‘', "'").lower()
    return re.sub(r'[^a-z0-9 ]', ' ', s).split()


def render(pg, y0, y1, x0, x1):
    page = doc[pg - 1]
    s = 72 / DPI
    rect = pymupdf.Rect(x0 * s, y0 * s, x1 * s, y1 * s) & page.rect
    if rect.is_empty or rect.height < 5:
        return None
    pix = page.get_pixmap(dpi=IMG_DPI, clip=rect, colorspace=pymupdf.csRGB)
    return Image.frombytes('RGB', [pix.width, pix.height], pix.samples)


# ---- OCR から見出し・英語行を集める ----
pages = {}
chapter_no = 0
headings = []  # {'chapterNo','page','y0','ytop','title','k','img'}
enlines = []   # {'chapterNo','page','y0','text','is_ex'}
for pg in range(FIRST, LAST + 1):
    fn = f'{OCR}/p{pg:03d}.json'
    if not os.path.exists(fn):
        print('missing OCR page', pg)
        break
    d = json.load(open(fn, encoding='utf-8'))
    W, H = d['w'], d['h']
    ja, en = d['ja'], d['en-US']
    bigs = [l for l in ja if l['words'][0][4] >= 45 and l['words'][0][2] < 400]
    bigtext = ''.join(l['text'].replace(' ', '') for l in sorted(bigs, key=lambda l: (l['words'][0][2], l['words'][0][1])))
    if (re.search(r'前期|後期', bigtext) and re.search(r'第(\d+)週', bigtext)) or pg == 291:
        chapter_no += 1
    if chapter_no == 0:
        continue
    # 本文の範囲（脚注のページ番号と右端のタブを除く）
    body = []
    for l in ja + en:
        x0, y0, x1, y1 = line_box(l)
        if y0 < 150 or y1 > 1860 or x0 > W - 160:
            continue
        body.append((x0, y0, x1, y1))
    if not body:
        continue
    bx0 = max(60, min(b[0] for b in body) - 24)
    bx1 = min(W - 60, max(b[2] for b in body) + 24)
    bottom = min(1860, max(b[3] for b in body) + 14)
    pages[pg] = {'W': W, 'H': H, 'x0': bx0, 'x1': bx1, 'bottom': bottom}
    # 章見出し（「2 単純形」。節見出しの直前にあれば切り出しに含める）
    chap_lines = []
    for l in ja:
        x0, y0, x1, y1 = line_box(l)
        t = l['text'].replace(' ', '')
        h = l['words'][0][4]
        if 150 <= y0 <= 1860 and x0 < 450 and 24 <= h <= 44 and CHAP_RE.match(t) and not SEC_RE.match(t) and len(t) <= 20:
            chap_lines.append((y0, y1))
    k = 0
    for l in sorted(ja, key=lambda l: line_box(l)[1]):
        x0, y0, x1, y1 = line_box(l)
        t = l['text'].replace(' ', '')
        h = l['words'][0][4]
        m = SEC_RE.match(t)
        if m and 150 <= y0 <= 1860 and x0 < 450 and 20 <= h <= 40:
            k += 1
            ytop = y0
            for (cy0, cy1) in chap_lines:
                if 0 <= y0 - cy1 <= 140:
                    ytop = min(ytop, cy0)
            headings.append({'chapterNo': chapter_no, 'page': pg, 'y0': y0, 'ytop': ytop, 'title': t, 'k': k, 'img': None})
    for l in en:
        x0, y0, x1, y1 = line_box(l)
        if not (150 <= y0 <= 1860) or x0 > 800:
            continue
        txt = l['text'].strip()
        is_ex = bool(EX_RE.match(txt))
        if is_ex:
            exx = l['words'][0][1]
            toks = [w[0] for w in l['words'][1:] if w[1] >= exx + 90]
            txt = ' '.join(toks)
        enlines.append({'chapterNo': chapter_no, 'page': pg, 'y0': y0, 'text': txt, 'is_ex': is_ex})

headings.sort(key=lambda h: (h['page'], h['y0']))


def heading_at(page, y0):
    """位置の直前の見出し（index）"""
    idx = None
    for i, hd in enumerate(headings):
        if (hd['page'], hd['y0']) <= (page, y0):
            idx = i
        else:
            break
    return idx


# ---- 例文カード → 英語行 ----
data = json.load(open(JSON_PATH, encoding='utf-8'))
items = data['items']
by_chapter = {}
for e in enlines:
    by_chapter.setdefault(e['chapterNo'], []).append(e)
report = open(REPORT, 'w', encoding='utf-8')
examples = [it for it in items if it['kind'] == 'example']
pos_of = {}  # card no -> (page, y0)
for it in examples:
    ft = norm_tokens(it['front'])
    if len(ft) < 2:
        continue
    best = None
    for e in by_chapter.get(it['chapterNo'], []):
        et = norm_tokens(e['text'])
        if len(et) < 2:
            continue
        r = difflib.SequenceMatcher(None, ft, et).ratio()
        # 例文が 2 行に折り返しているとき OCR の行は 1 行目だけなので前方一致も見る（短い行は囲みの「How long ...?」などに誤って付くので 4 語以上・例文の 4 割以上）
        pre = difflib.SequenceMatcher(None, ft[:len(et)], et).ratio() if len(ft) > len(et) >= max(4, 0.4 * len(ft)) else 0
        s = max(r, pre)
        if s >= 0.55 and (best is None or s > best[1] or (s == best[1] and abs(e['page'] - (it.get('page') or 0)) < abs(best[0]['page'] - (it.get('page') or 0)))):
            best = (e, s)
    if best:
        pos_of[it['no']] = (best[0]['page'], best[0]['y0'])

# 節ごとの最初の例文位置（Ex. の印のある行 と カードが対応づいた行）
ex_positions = [(e['page'], e['y0']) for e in enlines if e['is_ex']] + list(pos_of.values())
first_ex = {}
for (pg, y0) in sorted(set(ex_positions)):
    hidx = heading_at(pg, y0)
    if hidx is not None and hidx not in first_ex:
        first_ex[hidx] = (pg, y0)

# ---- 節の画像 ----
made = 0
for i, hd in enumerate(headings):
    pos = first_ex.get(i)
    if not pos:
        continue
    pg = hd['page']
    P = pages[pg]
    parts = []
    y_start = max(150, hd['ytop'] - 16)
    if pos[0] == pg:
        if pos[1] - 10 - y_start < 30:
            continue
        parts.append(render(pg, y_start, pos[1] - 10, P['x0'], P['x1']))
    else:
        parts.append(render(pg, y_start, P['bottom'], P['x0'], P['x1']))
        p2 = pos[0]
        if p2 == pg + 1 and p2 in pages and pos[1] - 10 > 170:
            Q = pages[p2]
            parts.append(render(p2, 150, pos[1] - 10, Q['x0'], Q['x1']))
    parts = [p for p in parts if p is not None and p.height > 20]
    if not parts:
        continue
    w = max(p.width for p in parts)
    h = sum(p.height for p in parts) + 10 * (len(parts) - 1)
    img = Image.new('RGB', (w, h), 'white')
    y = 0
    for p in parts:
        img.paste(p, (0, y))
        y += p.height + 10
    name = f"sec_p{pg:03d}_{hd['k']}.jpg"
    img.save(os.path.join(IMG_DIR, name), 'JPEG', quality=82, optimize=True)
    hd['img'] = f'tetsuryoku_grammar/{name}'
    made += 1
    report.write(f"[sec] p{pg} {hd['title'][:30]} -> {name} ({w}x{h}) first-ex@p{pos[0]}\n")

# ---- 割り当て ----
assigned = 0
fallback = 0
failed = []
sec_of = {}
for it in examples:
    pos = pos_of.get(it['no'])
    if pos:
        hidx = heading_at(*pos)
        if hidx is not None and headings[hidx]['img']:
            sec_of[it['no']] = hidx
            it['page'] = pos[0]
for idx, it in enumerate(examples):
    if it['no'] in sec_of:
        it['answerImage'] = headings[sec_of[it['no']]]['img']
        assigned += 1
        continue
    # 前後の例文カード（同じ週）の節を使う。ページが同じ方を優先
    prev_ = next((x for x in reversed(examples[:idx]) if x['no'] in sec_of and x['chapterNo'] == it['chapterNo']), None)
    next_ = next((x for x in examples[idx + 1:] if x['no'] in sec_of and x['chapterNo'] == it['chapterNo']), None)
    pick = None
    if prev_ and next_:
        pick = prev_ if (it.get('page') == prev_.get('page') or it.get('page') != next_.get('page')) else next_
    else:
        pick = prev_ or next_
    if pick:
        it['answerImage'] = headings[sec_of[pick['no']]]['img']
        fallback += 1
        report.write(f"[fallback] no={it['no']} p{it.get('page')} {it['front'][:60]} -> {it['answerImage']}\n")
    else:
        failed.append(it)
        report.write(f"[failed] no={it['no']} p{it.get('page')} {it['front'][:60]}\n")

json.dump(data, open(JSON_PATH, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
summary = f"headings={len(headings)} section_images={made} examples={len(examples)} matched_lines={len(pos_of)} assigned={assigned} fallback={fallback} failed={len(failed)}"
report.write(summary + '\n')
report.close()
print(summary)
