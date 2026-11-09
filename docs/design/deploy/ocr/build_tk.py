# 鉄緑会 英語実践講座 英文法: OCR 結果（ocr/tk）から学習カード JSON と画像を作る
#   例文（Ex.）→ kind=example: front=英文, back=和訳, extra.blanks=太字の語（空所補充用）の語インデックス範囲
#   囲み（□）  → kind=phrase: front=英語の表現, back=意味。囲み全体の画像を answerImage に
import json, os, re, sys
import numpy as np, cv2, pymupdf
from PIL import Image

PDF = r'C:\Apache24\htdocs\study_manage\docs\other\鉄緑会_英語実践講座_英文法.pdf'
OUT = 'out/tk'
IMG = 'out/cards_images/tetsuryoku_grammar'
os.makedirs(OUT, exist_ok=True)
os.makedirs(IMG, exist_ok=True)
doc = pymupdf.open(PDF)
DPI = 250  # OCR と同じ
IMG_DPI = 160

def render_gray(pg):
    pix = doc[pg - 1].get_pixmap(dpi=DPI, colorspace=pymupdf.csGRAY)
    return np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width).copy()

def crop_jpeg(pg, x0, y0, x1, y1, path):
    """OCR 座標（250dpi）で指定した範囲を切り出して JPEG に"""
    page = doc[pg - 1]
    s = 72 / DPI
    rect = pymupdf.Rect(x0 * s, y0 * s, x1 * s, y1 * s) & page.rect
    if rect.is_empty:
        return False
    pix = page.get_pixmap(dpi=IMG_DPI, clip=rect, colorspace=pymupdf.csRGB)
    Image.frombytes('RGB', [pix.width, pix.height], pix.samples).save(path, 'JPEG', quality=86, optimize=True)
    return True

def stroke_width(img, x, y, w, h):
    pad = 2
    crop = img[max(0, y - pad):y + h + pad, max(0, x - pad):x + w + pad]
    if crop.size == 0:
        return 0.0
    _, bw = cv2.threshold(crop, 0, 255, cv2.THRESH_BINARY_INV | cv2.THRESH_OTSU)
    ink = (bw > 0).sum()
    if ink < 10:
        return 0.0
    cnts, _ = cv2.findContours(bw, cv2.RETR_LIST, cv2.CHAIN_APPROX_NONE)
    per = sum(cv2.arcLength(c, True) for c in cnts)
    return float(2 * ink / per) if per else 0.0

def detect_boxes(img):
    _, bw = cv2.threshold(img, 0, 255, cv2.THRESH_BINARY_INV | cv2.THRESH_OTSU)
    hk = cv2.getStructuringElement(cv2.MORPH_RECT, (60, 1))
    vk = cv2.getStructuringElement(cv2.MORPH_RECT, (1, 40))
    hl = cv2.morphologyEx(bw, cv2.MORPH_OPEN, hk)
    vl = cv2.morphologyEx(bw, cv2.MORPH_OPEN, vk)
    lines = cv2.bitwise_or(hl, vl)
    cnts, _ = cv2.findContours(lines, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    boxes = []
    H, W = img.shape
    for c in cnts:
        x, y, w, h = cv2.boundingRect(c)
        if w > 300 and h > 60 and w < W * 0.95 and h < H * 0.8:
            # 内側の縦線（表かどうか）
            inner = vl[y + 5:y + h - 5, x + 15:x + w - 15]
            cols = (inner > 0).sum(axis=0)
            ncol = int(((cols > h * 0.5).astype(np.uint8)[1:] != (cols > h * 0.5).astype(np.uint8)[:-1]).sum() // 2)
            boxes.append({'x': x, 'y': y, 'w': w, 'h': h, 'table': ncol >= 1})
    boxes.sort(key=lambda b: b['y'])
    return boxes

def compact(s):
    """日本語 OCR のスペースを詰める（ASCII 同士の間だけ残す）"""
    out = []
    toks = s.split(' ')
    for i, t in enumerate(toks):
        if i and t and out and out[-1] and re.match(r'[A-Za-z0-9\'’,\.\?\!\(\)\-]$', out[-1][-1]) and re.match(r'^[A-Za-z0-9\'’\(]', t):
            out.append(' ')
        out.append(t)
    s = ''.join(out)
    return s.replace('，', '，').strip()

GARBAGE_RE = re.compile(r'[^\x20-\x7E‘’“”…—–éè]')
def clean_token(t):
    """en OCR の語。日本語部分を誤読した文字化け（ü å Ø など）を含む語は捨てる"""
    if GARBAGE_RE.search(t):
        return None
    if re.fullmatch(r'[^A-Za-z0-9]+', t) and t not in ('...', '…', '/', '+', '~', '-', '—', '(', ')', '[', ']'):
        return None
    return t

def is_japanese(text):
    t = text.replace(' ', '')
    if not t:
        return False
    jp = sum(1 for ch in t if ord(ch) > 0x2E80)
    return jp / len(t) >= 0.4

def line_box(l):
    xs = [w[1] for w in l['words']]
    ys = [w[2] for w in l['words']]
    x2 = max(w[1] + w[3] for w in l['words'])
    y2 = max(w[2] + w[4] for w in l['words'])
    return min(xs), min(ys), x2, y2

WEEK_RE = re.compile(r'(前期|後期).*?第(\d+)週')
SEC_RE = re.compile(r'^(\d+\.\d+)\s*(.*)$')
cards = []
book_rows = []
review = open(f'{OUT}/review.txt', 'w', encoding='utf-8')
week = None  # {'term','no','title','chapterNo','pages':[]}
chapter_no = 0
section = None
pending = None  # 前ページから続く例文カード
ex_counter = 0

def flush_pending():
    global pending
    if pending:
        finalize_example(pending)
        pending = None

def mark_bold(c):
    """太字判定（文全体のストローク幅の中央値に対する比）"""
    vals = [s for s in c['sw'] if s > 0]
    med = float(np.median(vals)) if vals else 1.0
    c['bold'] = []
    for tok, s in zip(c['en_tokens'], c['sw']):
        letters = re.sub(r'[^A-Za-z]', '', tok)
        r = s / med if med else 0
        c['bold'].append(bool(letters) and ((len(letters) >= 2 and r >= 1.12) or (len(letters) == 1 and r >= 1.2)))

def finalize_example(c):
    mark_bold(c)
    # 画像
    pg = c['page']
    path = f"{IMG}/p{pg:03d}_ex{c['exNo']:02d}.jpg"
    x0 = max(0, c['x0'] - 12)
    ok = crop_jpeg(pg, x0, max(0, c['y0'] - 10), c['x1'] + 14, c['y1'] + 10, path)
    front = ' '.join(c['en_tokens'])
    back = ''.join(c['ja']).strip()
    # 太字 → 語インデックスの範囲
    blanks = []
    run = None
    for i, b in enumerate(c['bold']):
        if b:
            if run and run[1] == i - 1:
                run[1] = i
            else:
                run = [i, i]
                blanks.append(run)
        else:
            run = None
    cards.append({
        'kind': 'example', 'chapterNo': c['chapterNo'], 'chapter': c['chapter'], 'section': c['section'],
        'front': front, 'back': back or None, 'extra': {'blanks': blanks, 'ex': c['exNo']} if blanks else {'ex': c['exNo']},
        'page': pg, 'bookPage': c['bookPage'], 'answerImage': os.path.relpath(path, 'out/cards_images').replace('\\', '/') if ok else None,
        '_y': c['y0'],
    })
    bw = [c['en_tokens'][i] for i, b in enumerate(c['bold']) if b]
    review.write(f"[ex] p{pg} {c['chapter']} / {c['section']}\n   {front}\n   {back}\n   bold={bw}\n")

for pg in range(33, 461):
    d = json.load(open(f'ocr/tk/p{pg:03d}.json', encoding='utf-8'))
    W, H = d['w'], d['h']
    ja = d['ja']
    en = d['en-US']
    # ---- 週ヘッダ ----
    bigs = [l for l in ja if l['words'][0][4] >= 45 and l['words'][0][2] < 400]
    bigtext = ''.join(l['text'].replace(' ', '') for l in sorted(bigs, key=lambda l: (l['words'][0][2], l['words'][0][1])))
    mt = re.search(r'前期|後期', bigtext)
    mw = re.search(r'第(\d+)週', bigtext)
    m = (mt.group(0), mw.group(1)) if (mt and mw) else None
    if not m and pg == 291:
        m = ('後期', '6'); bigtext = '後期第6週副詞'
    if m:
        flush_pending()
        term, wno = m
        title = re.sub(r'前期|後期|第\d+週', '', bigtext).strip()
        if pg == 291:
            title = '副詞'
        chapter_no += 1
        week = {'term': term, 'no': int(wno), 'title': title, 'chapterNo': chapter_no, 'pages': [pg], 'firstPage': pg}
        book_rows.append(week)
        section = None
        ex_counter = 0
    elif week:
        week['pages'].append(pg)
    if not week:
        continue
    chapter = f"{week['term']} 第{week['no']}週 {week['title']}"
    # 脚注のページ番号
    bookPage = None
    for l in en:
        if l['words'][0][2] > 1850:
            mm = re.search(r'(\d{1,3})', l['text'])
            if mm:
                bookPage = mm.group(1)
    img = None
    boxes = None

    # ---- 行を上から順に処理 ----
    items = []  # (y, kind, obj)
    for l in ja:
        x0, y0, x1, y1 = line_box(l)
        if y0 < 150 or y0 > 1900:
            continue
        t = compact(l['text'])
        if l['words'][0][4] >= 45:
            continue  # 週見出し
        if re.fullmatch(r'第\d+週', t.replace(' ', '')):
            continue  # 横のタブ
        items.append((y0, 'ja', l, t))
    for l in en:
        x0, y0, x1, y1 = line_box(l)
        if y0 < 150 or y0 > 1900:
            continue
        items.append((y0, 'en', l, l['text']))
    items.sort(key=lambda it: (it[0], it[1]))

    section_at_page_start = section
    # 囲み
    img = render_gray(pg)
    boxes = detect_boxes(img)
    def in_box(y):
        for b in boxes:
            if b['y'] <= y <= b['y'] + b['h']:
                return b
        return None

    # セクション見出し（y, 題名）をページ内で控えておく（囲みの所属を決めるのに使う）
    headings = []

    for idx, (y0, kind, l, t) in enumerate(items):
        x0, _, x1, y1 = line_box(l)
        b = in_box(y0)
        if b:
            continue  # 囲みの中は別処理
        if kind == 'ja':
            ms = SEC_RE.match(t)
            if ms and x0 < 450 and 20 <= l['words'][0][4] <= 36:
                flush_pending()
                # 英語の見出しは en 行を優先
                title = t
                for (yy, k2, l2, t2) in items:
                    if k2 == 'en' and abs(yy - y0) < 12 and t2.startswith(ms.group(1)) and len(re.findall(r'[A-Za-z]{2,}', t2)) >= 2:
                        title = t2
                        break
                section = title
                headings.append((y0, title))
                continue
            # 例文の和訳（インデントされた日本語行）
            if pending and is_japanese(t) and x0 >= pending['x0'] + 60 and x0 <= pending['x0'] + 180 and y0 - pending['y1'] < 80:
                pending['ja'].append(t)
                pending['y1'] = max(pending['y1'], y1)
                pending['x1'] = max(pending['x1'], x1)
                continue
            if pending and is_japanese(t) and pending['page'] != pg and x0 < 450 and y0 < 320 and not pending['ja']:
                # ページをまたいだ和訳（ページ先頭）
                pending['ja'].append(t)
                continue
            if pending and is_japanese(t) and pending['page'] != pg and x0 < 450 and y0 < 320 and pending['ja'] and y0 < 260:
                pending['ja'].append(t)
                continue
            if pending and is_japanese(t) and x0 < pending['x0'] + 60:
                flush_pending()
        else:
            txt = t.strip()
            if re.match(r'^E[xX][\.,]?\s', txt) or re.match(r'^E[xX]\.', txt):
                flush_pending()
                ex_counter += 1
                exx = l['words'][0][1]
                toks = [w for w in l['words'][1:] if w[1] >= exx + 90 and clean_token(w[0]) is not None]
                en_tokens = ['I' if w[0] == '1' else w[0] for w in toks]
                bold = []
                sws = [stroke_width(img, w[1], w[2], w[3], w[4]) for w in toks]
                pending = {
                    'page': pg, 'bookPage': bookPage, 'chapterNo': week['chapterNo'], 'chapter': chapter, 'section': section,
                    'exNo': ex_counter, 'x0': exx, 'y0': y0, 'y1': y1, 'x1': x1, 'en_tokens': en_tokens, 'sw': sws, 'bold': [], 'ja': [],
                }
                continue
            if pending and not pending['ja'] and x0 >= pending['x0'] + 60 and x0 <= pending['x0'] + 180 and y0 - pending['y1'] < 80 and not is_japanese(txt):
                # 英文の続き
                ws2 = [w for w in l['words'] if clean_token(w[0]) is not None]
                pending['en_tokens'] += ['I' if w[0] == '1' else w[0] for w in ws2]
                pending['sw'] += [stroke_width(img, w[1], w[2], w[3], w[4]) for w in ws2]
                pending['y1'] = max(pending['y1'], y1)
                pending['x1'] = max(pending['x1'], x1)
                continue
    # ページ末で和訳が未取得なら次ページへ持ち越す（取得済みなら確定）
    if pending and pending['ja'] and pending['page'] == pg:
        # 和訳が 1 行以上あればページ内で確定（次ページ先頭に続きがあれば上で追加される可能性は捨てる）
        pass
    # ---- 囲み ----
    for k, b in enumerate(boxes):
        rows = []
        for (y0, kind, l, t) in items:
            x0, _, x1, y1 = line_box(l)
            if not (b['y'] <= y0 <= b['y'] + b['h'] and x0 >= b['x'] - 5 and x0 <= b['x'] + b['w']):
                continue
            # 既存の行（y が近い）にまとめる
            for r in rows:
                if abs(r['y'] - y0) <= 14:
                    r[kind].append((x0, t, l))
                    break
            else:
                rows.append({'y': y0, 'ja': [], 'en': []})
                rows[-1][kind].append((x0, t, l))
        path = f"{IMG}/p{pg:03d}_box{k + 1}.jpg"
        ok = crop_jpeg(pg, b['x'] - 6, b['y'] - 6, b['x'] + b['w'] + 6, b['y'] + b['h'] + 6, path)
        rel = os.path.relpath(path, 'out/cards_images').replace('\\', '/') if ok else None
        # この囲みの所属: 囲みより上にある最後の見出し（無ければページ先頭から続く見出し）
        above = [t for (yy, t) in headings if yy < b['y']]
        section = above[-1] if above else (section_at_page_start if headings else section)
        sec_label = section or f"{week['title']}"
        if b['table'] or not rows:
            cards.append({'kind': 'phrase', 'chapterNo': week['chapterNo'], 'chapter': chapter, 'section': section,
                          'front': f"〔表〕{sec_label}", 'back': None, 'extra': {'table': True},
                          'page': pg, 'bookPage': bookPage, 'answerImage': rel, '_y': b['y']})
            review.write(f"[table] p{pg} {chapter} / {section}\n")
            continue
        rows_out = []
        for r in sorted(rows, key=lambda r: r['y']):
            en_parts = sorted(r['en'], key=lambda e: e[0])
            ja_parts = sorted(r['ja'], key=lambda e: e[0])
            # 英語の語（en OCR。文字化けを含む語は捨てる）と日本語の語（ja OCR の非 ASCII 語）を、
            # 位置の重なりで振り分ける: 英語の語の上にある「日本語」は英単語の誤読（done → 怩）、日本語の上にある「英語」は日本語の誤読
            def overlap(w1, w2):
                l = max(w1[1], w2[1])
                r = min(w1[1] + w1[3], w2[1] + w2[3])
                return max(0, r - l)
            en_all = [w for (_, _, l) in en_parts for w in l['words'] if clean_token(w[0]) is not None and re.search(r'[A-Za-z]{2,}|\d', w[0])]
            ja_all = [w for (_, _, l) in ja_parts for w in l['words'] if re.search(r'[^\x00-\x7F]', w[0])]
            ja_words = [w for w in ja_all if not any(overlap(w, e) > 0.5 * w[3] for e in en_all)]
            en_words = [w for (_, _, l) in en_parts for w in l['words'] if clean_token(w[0]) is not None and not any(overlap(w, j) > 0.5 * w[3] for j in ja_words)]
            ja_words.sort(key=lambda w: w[1])
            en_words.sort(key=lambda w: w[1])
            ja_text = compact(' '.join(w[0] for w in ja_words))
            en_text = ' '.join(clean_token(w[0]) for w in en_words).strip()
            en_text = re.sub(r'\s+([,\.\?\!])', r'\1', en_text)
            if not en_text:
                asc = [w[0] for (_, _, l) in ja_parts for w in l['words'] if not re.search(r'[^\x00-\x7F]', w[0])]
                en_text = ' '.join(asc).strip()
            if not en_text or not re.search(r'[A-Za-z]{2,}', en_text):
                continue
            # 意味が無く、英語も 2 語以下で句点で終わる行は、前の行の例文の折り返し（"possible." など）なので捨てる
            if not ja_text and len(en_text.split()) <= 2 and re.search(r'[\.\?!]$', en_text):
                continue
            # 「3.1 come to do / get to do」のような節の見出しは囲みの行ではない
            if re.match(r'^\d+\.\d+\s', en_text):
                continue
            # 意味の先頭の「…」「・・・」はそろえる
            ja_text = re.sub(r'^[・･…\.]{2,}', '…', ja_text)
            rows_out.append({'en': en_text, 'ja': ja_text or None})
            review.write(f"[row] p{pg} {section} | {en_text}  =>  {ja_text}\n")
        if rows_out:
            # 囲み 1 つ = カード 1 枚。front/back は一覧・検索用（行は extra.rows）
            cards.append({'kind': 'phrase', 'chapterNo': week['chapterNo'], 'chapter': chapter, 'section': section,
                          'front': ' ／ '.join(r['en'] for r in rows_out),
                          'back': ' ／ '.join(r['ja'] for r in rows_out if r['ja']) or None,
                          'extra': {'rows': rows_out},
                          'page': pg, 'bookPage': bookPage, 'answerImage': rel, '_y': b['y']})
        else:
            cards.append({'kind': 'phrase', 'chapterNo': week['chapterNo'], 'chapter': chapter, 'section': section,
                          'front': f"〔囲み〕{sec_label}", 'back': compact(' '.join(t for r in rows for (_, t, _) in r['ja'])) or None, 'extra': {'table': True},
                          'page': pg, 'bookPage': bookPage, 'answerImage': rel, '_y': b['y']})
            review.write(f"[box-only] p{pg} {section}\n")
    if pending and pending['page'] == pg and pending['ja']:
        # 和訳が取れていて、次ページ先頭に続きが来る可能性は低いので確定
        flush_pending()
flush_pending()
review.close()

# 並び替え（ページ → y）と通し番号
cards.sort(key=lambda c: (c['page'], c['_y']))
structure = []
for i, c in enumerate(cards):
    c['no'] = i + 1
    c.pop('_y', None)
    c['problemImage'] = None
    if not structure or structure[-1][0] != c['chapterNo']:
        structure.append([c['chapterNo'], c['chapter'], c['no'], c['no']])
    else:
        structure[-1][3] = c['no']
deck = {
    'key': 'tetsuryoku_grammar', 'subject': 'english', 'name': '鉄緑会 英語実践講座 英文法', 'shortName': '鉄緑会 英文法',
    'studentOnly': True, 'resourceBookTitle': '鉄緑会 英語実践講座 英文法', 'structure': structure, 'sortOrder': 3,
}
json.dump({'deck': deck, 'items': cards}, open(f'{OUT}/cards_tetsuryoku_grammar.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)

# 個別学習データ（週ごとの行）
rows = []
for w in book_rows:
    rows.append({
        'chapter': w['term'], 'seqNo': str(w['no']), 'title': f"第{w['no']}週 {w['title']}", 'subject': '英語', 'major': '', 'mid': '', 'sub': '',
        'meta': {'ページ': f"{w['pages'][0]}-{w['pages'][-1]}", 'quiz_pages': [[p, f"p.{p - 31}", f"第{w['no']}週"] for p in w['pages']][:0]},
        '_pages': w['pages'],
    })
pages = {str(i + 1): r.pop('_pages') for i, r in enumerate(rows)}
for r in rows:
    r['meta'].pop('quiz_pages')
json.dump({'title': '鉄緑会 英語実践講座 英文法', 'type': '教科書', 'subject': '英語', 'studentOnly': True,
           'pdf': {'title': '全ページ', 'map': 'none', 'offset': 0}, 'items': rows},
          open(f'{OUT}/book_tetsuryoku_grammar.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
json.dump({'rowPages': pages}, open(f'{OUT}/pages_tetsuryoku_grammar.json', 'w', encoding='utf-8'), ensure_ascii=False)
print('cards', len(cards), 'examples', sum(1 for c in cards if c['kind'] == 'example'), 'boxes', sum(1 for c in cards if c['kind'] == 'phrase'), 'rows', sum(len((c.get('extra') or {}).get('rows', [])) for c in cards), 'weeks', len(book_rows))
