# 合格る確率 / 合格る計算 → 学習カード（類題の画像 + 解答編の画像）・教材 JSON・ページ対応表
import json, re, os, glob, sys
import numpy as np
import pymupdf
from PIL import Image

BASE = r'C:\Apache24\htdocs\study_manage\docs\other'
OUT = 'out/math'
IMG = 'out/cards_images'
os.makedirs(OUT, exist_ok=True)

BOOKS = {
    'ukaru_kakuritsu': dict(
        q='kk', a='kka', qpdf='合格る確率.pdf', apdf='合格る確率_解答.pdf', maxN=100,
        name='合格る確率＋場合の数', short='合格る確率', sort=1,
        chapters=[(1, 'Stage1 原理原則編', 1, 17), (2, 'Stage2 典型手法編', 18, 30), (3, 'Stage3 入試実戦編', 31, 66),
                  (4, 'Stage4 実戦融合問題編', 67, 91), (5, 'Stage5 超高難度有名問題編', 92, 100)],
    ),
    'ukaru_keisan': dict(
        q='kc', a='kca', qpdf='合格る計算.pdf', apdf='合格る計算_解答.pdf', maxN=89,
        name='合格る計算 数学I・A・II・B・C', short='合格る計算', sort=2,
        chapters=[(1, '1章 整数', 1, 7), (2, '2章 数値計算', 8, 12), (3, '3章 整式の変形', 13, 20), (4, '4章 分数と分数式', 21, 23),
                  (5, '5章 簡単な関数のグラフ', 24, 29), (6, '6章 方程式', 30, 37), (7, '7章 不等式', 38, 42), (8, '8章 三角関数', 43, 51),
                  (9, '9章 平面図形・三角比', 52, 60), (10, '10章 ベクトル', 61, 67), (11, '11章 図形と式', 68, 71), (12, '12章 微分・積分', 72, 76),
                  (13, '13章 指数関数・対数関数', 77, 80), (14, '14章 場合の数・確率', 81, 82), (15, '15章 数列', 83, 88), (16, '16章 データの分析', 89)],
    ),
}

report = []

def load(name):
    pages = {}
    for f in sorted(glob.glob(f'ocr/{name}/p*.json')):
        d = json.load(open(f, encoding='utf-8'))
        pages[d['page']] = d
    return pages

def lines(d):
    for l in d['ja']:
        w0 = l['words'][0]
        yield l['text'].replace(' ', ''), w0[1], w0[2], w0[3], w0[4]

# ---------- ITEM ヘッダー ----------
def item_headers(pages, maxN):
    """ページ → (N, title)。番号は昇順にしか進まないものとして照合する"""
    found = {}
    expect = 1
    for pg in sorted(pages):
        d = pages[pg]; W, H = d['w'], d['h']
        hdr = [(t, x, y, w, h) for t, x, y, w, h in lines(d) if y < 0.08 * H and x < 0.3 * W]
        has_item = any(re.search(r'ITEM|TEM|iTE|lTE|ITE', t) for t, *_ in hdr)
        big = [(t, x, y, w, h) for t, x, y, w, h in hdr if h >= 40]
        if not (has_item or big):
            continue
        n = None
        for t, *_ in big:
            m = re.match(r'(\d+)', t)
            if m:
                s = m.group(1)
                # 期待する番号（またはそれに近い番号）で始まっていれば採用
                for cand in range(expect, min(maxN, expect + 3) + 1):
                    if s == str(cand) or s.startswith(str(cand)):
                        n = cand
                        break
                if n:
                    break
        if n is None:
            if has_item:
                found.setdefault(('unknown', pg), None)
            continue
        title = ''
        for t, *_ in big:
            tt = re.sub(r'^\d+', '', t)
            if tt and not re.fullmatch(r'\d*', t):
                title = tt
                break
        title = title.strip('・.、')
        found[n] = (pg, title)
        expect = n + 1
    return found

# ---------- 解答編の参照行（類題の末尾） ----------
def answer_ref_pages(pages):
    refs = {}
    for pg in sorted(pages):
        d = pages[pg]; H = d['h']
        for t, x, y, w, h in lines(d):
            if (re.search(r'解答.{0,3}編', t) or '編p' in t) and y > 0.88 * H:
                refs[pg] = (y, h)
    return refs

# ---------- 解答編: フッター「N〜Mの解答」 ----------
def answer_footers(pages, maxN):
    ranges = {}
    prev_hi = 0
    pgs = sorted(pages)
    raw = {}
    for pg in pgs:
        d = pages[pg]; H = d['h']
        cand = None
        for t, x, y, w, h in lines(d):
            if y > 0.93 * H and '解答' in t:
                cand = t
        raw[pg] = cand
    # まず読めた範囲
    for pg in pgs:
        t = raw[pg]
        if not t:
            continue
        head = t.split('の解答')[0] if 'の解答' in t else t
        ints = [int(s) for s in re.findall(r'\d+', head)]
        ints = [i for i in ints if prev_hi - 1 <= i <= maxN]
        if not ints:
            continue
        lo, hi = min(ints), max(ints)
        if re.match(r'^[~〜～ー]', head) and prev_hi + 1 < lo:
            lo = prev_hi + 1
        ranges[pg] = [lo, hi]
        prev_hi = hi
    # 読めなかったページは前後から補う（前のページの末尾 +1 〜 次のページの先頭 −1）
    known = sorted(ranges)
    for pg in pgs:
        if pg in ranges:
            continue
        prev = max([k for k in known if k < pg], default=None)
        nxt = min([k for k in known if k > pg], default=None)
        if prev is None and nxt is None:
            continue
        lo = ranges[prev][1] + 1 if prev else 1
        hi = ranges[nxt][0] - 1 if nxt else maxN
        if lo > hi:
            # 空白ページか、前の解答の続き
            if prev and nxt and ranges[prev][1] >= ranges[nxt][0] - 1 and lo > hi:
                lo, hi = ranges[prev][1], ranges[prev][1]
            else:
                continue
        ranges[pg] = [lo, hi]
        report.append(f'answer footer unreadable p{pg} -> assumed {lo}..{hi}')
    # 隣とのすき間を埋める（27〜[29] が 27 とだけ読めた等）
    ks = sorted(ranges)
    for i, pg in enumerate(ks[:-1]):
        nxt = ks[i + 1]
        if ranges[pg][1] < ranges[nxt][0] - 1:
            report.append(f'answer footer gap p{pg} {ranges[pg]} -> p{nxt} {ranges[nxt]}: extended')
            ranges[pg][1] = ranges[nxt][0] - 1
    return ranges, raw

# ---------- 類題バッジ（色）----------
def pink_bands(doc, pg, W, H):
    """左側 30% にあるピンクの帯 → [(y0, y1, 幅/W, 左端/W, 画素数, 濃い割合)]（200dpi 座標）。傾いたスキャンで割れた帯は結合する"""
    pix = doc[pg - 1].get_pixmap(dpi=100)
    img = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, pix.n)[:, :, :3].astype(int)
    sy = img.shape[0] / H
    region = img[:, 0:int(0.3 * img.shape[1])]
    r, g, b = region[:, :, 0], region[:, :, 1], region[:, :, 2]
    pink = (r > 150) & (r - g > 35) & (r - b > 5)
    strong = pink & (r - g > 60) & (r > 170)
    frac = pink.mean(axis=1)
    rows = frac > 0.04
    raw = []
    y = 0
    while y < len(rows):
        if rows[y]:
            y0 = y
            while y < len(rows) and rows[y]:
                y += 1
            raw.append([y0, y])
        else:
            y += 1
    merged = []
    for y0, y1 in raw:
        if merged and y0 - merged[-1][1] <= 2:
            merged[-1][1] = y1
        else:
            merged.append([y0, y1])
    bands = []
    for y0, y1 in merged:
        if y1 - y0 < 4:
            continue
        cols = pink[y0:y1, :].mean(axis=0) > 0.35
        xs = np.where(cols)[0]
        if not len(xs):
            continue
        n = int(pink[y0:y1].sum())
        st = float(strong[y0:y1].sum()) / max(1, n)
        bands.append((y0 / sy, y1 / sy, (xs.max() - xs.min() + 1) / img.shape[1], xs.min() / img.shape[1], n, st))
    return bands

def text_margin(d):
    """本文の左端（OCR 行の x の小さい方から）/ W"""
    xs = sorted(l['words'][0][1] for l in d['ja'] if len(l['words']) >= 3)
    if not xs:
        return 0.03
    return xs[min(2, len(xs) - 1)] / d['w']

def badges_on(doc, pg, W, H, margin):
    """類題バッジ: 高さ 34〜45px（200dpi）・濃いピンク・本文左端のそば・画素数が「類題」2 文字分＋番号枠（薄い見出し帯や幅広のバナーは除く）"""
    out = []
    for a, b, wd, xmin, n, st in pink_bands(doc, pg, W, H):
        h = b - a
        if not (34 <= h <= 45):
            continue
        if wd < 0.05 or wd > 0.30 or xmin > margin + 0.03 or a <= 0.03 * H:
            continue
        if n < 280 or n > 1200:
            continue
        if st < 0.35 and not (st >= 0.25 and n >= 500 and wd >= 0.09):
            continue
        out.append((a, b))
    return out

def badge_y(doc, pg, W, H, ref_y):
    pix = doc[pg - 1].get_pixmap(dpi=100)
    img = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, pix.n)[:, :, :3].astype(int)
    sx = img.shape[1] / W
    sy = img.shape[0] / H
    strip = img[:, int(0.02 * img.shape[1]):int(0.14 * img.shape[1])]
    r, g, b = strip[:, :, 0], strip[:, :, 1], strip[:, :, 2]
    pink = (r > 150) & (r - g > 35) & (r - b > 5)
    frac = pink.mean(axis=1)
    rows = frac > 0.12
    bands = []
    y = 0
    while y < len(rows):
        if rows[y]:
            y0 = y
            while y < len(rows) and rows[y]:
                y += 1
            bands.append((y0 / sy, y / sy))
        else:
            y += 1
    cands = [(a, b) for a, b in bands if 8 <= (b - a) <= 80 and b < ref_y + 5 and a > 0.15 * H]
    if not cands:
        return None
    a, b = max(cands, key=lambda ab: ab[0])
    return a

def crop_jpeg(doc, pg, x0, y0, x1, y1, W, H, path, dpi=170):
    """200dpi のピクセル座標 → PDF 座標で切り出し、JPEG 保存"""
    page = doc[pg - 1]
    pw, ph = page.rect.width, page.rect.height
    rect = pymupdf.Rect(x0 / W * pw, y0 / H * ph, x1 / W * pw, y1 / H * ph) & page.rect
    pix = page.get_pixmap(dpi=dpi, clip=rect)
    im = Image.frombytes('RGB', [pix.width, pix.height], pix.samples)
    im.save(path, 'JPEG', quality=85, optimize=True)
    return im

def ink_bounds(doc, pg, y0, y1, W, H):
    """横方向の余白を詰める（インクのある範囲 + 余白）"""
    page = doc[pg - 1]
    pw, ph = page.rect.width, page.rect.height
    rect = pymupdf.Rect(0, y0 / H * ph, pw, y1 / H * ph)
    pix = page.get_pixmap(dpi=80, clip=rect, colorspace=pymupdf.csGRAY)
    g = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width)
    cols = (g < 160).sum(axis=0)
    # 端の黒い帯（スキャンの影）は除く: 両端 4% を無視
    n = len(cols)
    lo, hi = int(n * 0.04), int(n * 0.96)
    xs = [i for i in range(lo, hi) if cols[i] > 2]
    if not xs:
        return 0.03 * W, 0.97 * W
    x0 = max(0, (min(xs) - 10) / n * W)
    x1 = min(W, (max(xs) + 12) / n * W)
    return x0, x1

def build(key, spec):
    qpages = load(spec['q']); apages = load(spec['a'])
    qdoc = pymupdf.open(os.path.join(BASE, spec['qpdf']))
    adoc = pymupdf.open(os.path.join(BASE, spec['apdf']))
    maxN = spec['maxN']
    hdrs = item_headers(qpages, maxN)
    unknown = [pg for k, pg in [k for k in hdrs if isinstance(k, tuple)]]
    hdrs = {k: v for k, v in hdrs.items() if not isinstance(k, tuple)}
    refs = answer_ref_pages(qpages)
    aranges, raw = answer_footers(apages, maxN)
    os.makedirs(f'{IMG}/{key}', exist_ok=True)

    # 足りない ITEM の見出しを補う: 番号なしの ITEM 見出しページを順に当てる
    for n in range(1, maxN + 1):
        if n not in hdrs:
            prev_pg = hdrs[n - 1][0] if n - 1 in hdrs else 0
            next_pg = hdrs[n + 1][0] if n + 1 in hdrs else max(qpages) + 1
            cands = [pg for pg in unknown if prev_pg < pg < next_pg]
            if cands:
                hdrs[n] = (cands[0], '')
                report.append(f'{key} ITEM {n}: header number unreadable, used page {cands[0]}')
            else:
                # 参照行のあるページから逆算（直前の ITEM の範囲内で 2 つ目の参照ページ）
                rp = [pg for pg in sorted(refs) if prev_pg < pg < next_pg]
                if len(rp) >= 2:
                    hdrs[n] = (rp[-1] - 1 if rp[-1] - 1 > rp[-2] else rp[-1], '')
                    report.append(f'{key} ITEM {n}: header not found, guessed page {hdrs[n][0]} from answer refs')
                elif rp:
                    hdrs[n] = (max(prev_pg + 1, rp[0] - 1), '')
                    report.append(f'{key} ITEM {n}: header not found, guessed page {hdrs[n][0]}')
                else:
                    hdrs[n] = (prev_pg + 2, '')
                    report.append(f'{key} ITEM {n}: header not found, assumed page {prev_pg + 2}')

    items = []
    book_rows = []
    item_pages = {}; problem_page = {}; answer_pages = {}
    for n in range(1, maxN + 1):
        pg0, title = hdrs[n]
        pg1 = (hdrs[n + 1][0] - 1) if n + 1 in hdrs else pg0 + 1
        if pg1 < pg0:
            pg1 = pg0
        rng = list(range(pg0, pg1 + 1))
        # 類題のブロック: 範囲内の各ページで 類題バッジ（左余白の幅広いピンクの帯）を探し、
        # その下の「解答▶解答編」行まで（無ければ次のピンクの帯の手前、それも無ければページ下端）を切り出す
        blocks = []  # (page, y0, y1)
        for pg in rng:
            d = qpages[pg]; W, H = d['w'], d['h']
            all_refs = [(y, h) for t, x, y, w, h in lines(d) if re.search(r'解答.{0,3}編', t) or '編p' in t]
            bands = pink_bands(qdoc, pg, W, H)
            bl = badges_on(qdoc, pg, W, H, text_margin(d))
            for a, b in bl:
                below_ref = [y + h for y, h in all_refs if y > b]
                # 最後のページ以外では、下に「解答編」の行があるか、バッジの行の OCR に「類題」が読めたものだけを採用（左ページの見出しラベルの誤検出を除く）
                ocr_hit = any(('題' in t or '類' in t) and a - 12 <= y <= b + 12 and x < 0.4 * W for t, x, y, w, h in lines(d))
                if pg != pg1 and not below_ref and not ocr_hit:
                    report.append(f'{key} ITEM {n}: candidate badge on p{pg} ({int(a)}px) skipped (no reference, no 類題 text)')
                    continue
                if below_ref:
                    y1 = min(below_ref) + 10
                else:
                    nxt = [bd[0] for bd in bands if bd[0] > b + 30 and bd[2] >= 0.03]
                    y1 = (min(nxt) - 12) if nxt else 0.935 * H
                    report.append(f'{key} ITEM {n}: no 解答編 reference below 類題 badge on p{pg}; ended at {int(y1)}px')
                blocks.append((pg, max(0, a - 14), min(H, y1)))
        dedup = []
        for pg, y0, y1 in blocks:
            if dedup and dedup[-1][0] == pg and y0 < dedup[-1][2]:
                dedup[-1] = (pg, min(dedup[-1][1], y0), max(dedup[-1][2], y1))
            else:
                dedup.append((pg, y0, y1))
        blocks = dedup
        if not blocks:
            rp = [pg for pg in rng if pg in refs]
            ppg = rp[-1] if rp else pg1
            d = qpages[ppg]; W, H = d['w'], d['h']
            ref_y, ref_h = refs.get(ppg, (0.93 * H, 0))
            blocks.append((ppg, max(0.1 * H, ref_y - 0.25 * H), min(H, ref_y + ref_h + 10)))
            report.append(f'{key} ITEM {n}: 類題 badge not found on pages {pg0}-{pg1}; cropped bottom of p{ppg}')
        ppg = blocks[-1][0]
        pimg = f'{key}/q{n:03d}.jpg'
        crops = []
        for pg, y0, y1 in blocks:
            d = qpages[pg]; W, H = d['w'], d['h']
            x0, x1 = ink_bounds(qdoc, pg, y0, y1, W, H)
            crops.append(crop_jpeg(qdoc, pg, x0, y0, x1, y1, W, H, f'{IMG}/{key}/_tmp.jpg'))
        if len(crops) == 1:
            crops[0].save(f'{IMG}/{pimg}', 'JPEG', quality=85, optimize=True)
        else:
            w = max(im.width for im in crops)
            total = sum(im.height for im in crops) + 16 * (len(crops) - 1)
            big = Image.new('RGB', (w, total), (255, 255, 255))
            y = 0
            for im in crops:
                big.paste(im, (0, y)); y += im.height + 16
            big.save(f'{IMG}/{pimg}', 'JPEG', quality=85, optimize=True)
        if len(blocks) > 1:
            report.append(f'{key} ITEM {n}: {len(blocks)} 類題 blocks (pages {[b[0] for b in blocks]}) stacked')
        # 解答ページ
        ap = [pg for pg in sorted(aranges) if aranges[pg][0] <= n <= aranges[pg][1]]
        if not ap:
            report.append(f'{key} ITEM {n}: no answer page found')
            aimg = None
        else:
            aimg = f'{key}/a{n:03d}.jpg'
            ims = []
            for pg in ap[:4]:
                page = adoc[pg - 1]
                pw, ph = page.rect.width, page.rect.height
                rect = pymupdf.Rect(pw * 0.025, ph * 0.02, pw * 0.975, ph * 0.985)
                pix = page.get_pixmap(dpi=190, clip=rect)
                ims.append(Image.frombytes('RGB', [pix.width, pix.height], pix.samples))
            w = max(im.width for im in ims)
            total = sum(im.height for im in ims) + 12 * (len(ims) - 1)
            big = Image.new('RGB', (w, total), (200, 200, 200))
            y = 0
            for im in ims:
                big.paste(im, (0, y)); y += im.height + 12
            big.save(f'{IMG}/{aimg}', 'JPEG', quality=82, optimize=True)
        chap = next(c for c in spec['chapters'] if c[2] <= n <= (c[3] if len(c) > 3 else c[2]))
        sec = f'ITEM {n} {title}'.strip()
        items.append({
            'no': n, 'chapterNo': chap[0], 'chapter': chap[1], 'section': sec, 'kind': 'problem',
            'front': f'類題 {n}', 'back': None,
            'extra': {'problemPages': sorted({b[0] for b in blocks}), 'answerPages': ap, 'itemPages': rng, 'title': title},
            'page': ppg, 'bookPage': str(ppg), 'problemImage': pimg, 'answerImage': aimg,
        })
        book_rows.append({
            'chapter': chap[1], 'seqNo': str(n), 'title': sec, 'subject': '数学', 'major': '', 'mid': '', 'sub': '',
            'meta': {'ページ': f'{pg0}-{pg1}' if pg1 > pg0 else str(pg0), '解答ページ': ','.join(map(str, ap))},
        })
        item_pages[str(n)] = rng; problem_page[str(n)] = sorted({b[0] for b in blocks}); answer_pages[str(n)] = ap

    structure = [[c[0], c[1], c[2], (c[3] if len(c) > 3 else c[2])] for c in spec['chapters']]
    deck = {'key': key, 'subject': 'math', 'name': spec['name'], 'shortName': spec['short'], 'studentOnly': False,
            'resourceBookTitle': spec['name'], 'structure': structure, 'sortOrder': spec['sort']}
    json.dump({'deck': deck, 'items': items}, open(f'{OUT}/cards_{key}.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    json.dump({'title': spec['name'], 'type': '問題集', 'subject': '数学', 'items': book_rows},
              open(f'{OUT}/book_{key}.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    json.dump({'itemPages': item_pages, 'problemPage': problem_page, 'answerPages': answer_pages},
              open(f'{OUT}/pages_{key}.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    json.dump({'headers': {str(k): v for k, v in hdrs.items()}, 'answerRanges': {str(k): v for k, v in aranges.items()}, 'footerRaw': {str(k): v for k, v in raw.items()}},
              open(f'{OUT}/debug_{key}.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)

    # コンタクトシート
    def sheet(ns, kind, path, cols=4, cw=420, ch=420):
        tiles = []
        for n in ns:
            it = items[n - 1]
            p = it['problemImage'] if kind == 'q' else it['answerImage']
            if not p:
                continue
            im = Image.open(f'{IMG}/{p}'); im.thumbnail((cw, ch))
            tiles.append((n, im))
        rows = (len(tiles) + cols - 1) // cols
        big = Image.new('RGB', (cols * (cw + 10), rows * (ch + 10)), 'white')
        from PIL import ImageDraw
        dr = ImageDraw.Draw(big)
        for i, (n, im) in enumerate(tiles):
            x, y = (i % cols) * (cw + 10), (i // cols) * (ch + 10)
            big.paste(im, (x, y + 14)); dr.text((x + 2, y), f'ITEM {n}', fill='red')
        big.save(path)
    sheet([1, 2, 3, 7, 12, 19, 20, 33, 50, 60, 67, 80, 87, 88, maxN - 1, maxN], 'q', f'{OUT}/sheet_{key}_q.png')
    if os.path.exists(f'{IMG}/{key}/_tmp.jpg'):
        os.remove(f'{IMG}/{key}/_tmp.jpg')
    sheet([2, 7, 50, 67, maxN - 1, maxN], 'a', f'{OUT}/sheet_{key}_a.png', cols=3, cw=360, ch=520)
    return len(items)

if __name__ == '__main__':
    counts = {k: build(k, s) for k, s in BOOKS.items()}
    with open(f'{OUT}/REPORT.txt', 'w', encoding='utf-8') as f:
        f.write('counts: ' + repr(counts) + '\n')
        f.write('\n'.join(report) + '\n')
    print('done', counts, 'report lines', len(report))
