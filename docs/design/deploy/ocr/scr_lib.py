# スクランブル英文法・語法: ocr_scramble.py の出力（左ページの JPEG と OCR JSON）から問題ブロックを切り出し、
# 文字（英文は RapidOCR の文字枠から単語の切れ目を復元、和文は Windows OCR ja）を組み立てる共通処理。
import json, os, re, statistics

import cv2
import numpy as np

SCRATCH = None  # build 側で設定
_RAPID = None

CJK = re.compile(r'[　-ヿ㐀-鿿！-｠]')
JA = re.compile(r'[぀-ヿ㐀-鿿]')  # 仮名・漢字だけ（行の分類用）
CIRCLED = '①②③④⑤⑥⑦⑧⑨'


def rapid():
    global _RAPID
    if _RAPID is None:
        from rapidocr_onnxruntime import RapidOCR
        _RAPID = RapidOCR()
    return _RAPID


def load_spread(s):
    with open(os.path.join(SCRATCH, 'scr', 'ocr', f's{s:03d}.json'), encoding='utf-8') as f:
        return json.load(f)


def page_img(s, side='L'):
    return cv2.imread(os.path.join(SCRATCH, 'scr', 'pages', f's{s:03d}_{side}.jpg'))


# ---------------------------------------------------------------- 行・単語
def ja_text(line_text):
    """Windows OCR ja の行（全角文字の間に空白が入る）を詰める。英単語の間の空白は残す"""
    t = line_text
    t = re.sub(r'(?<=[^\x00-\x7f])\s+(?=[^\x00-\x7f])', '', t)
    t = re.sub(r'(?<=[^\x00-\x7f])\s+(?=[\x21-\x7e])', '', t)
    t = re.sub(r'(?<=[\x21-\x7e])\s+(?=[^\x00-\x7f])', '', t)
    t = t.replace('( ', '(').replace(' )', ')').replace('(', '（').replace(')', '）')
    t = t.replace('，', '，').replace(' ,', '，').replace(',', '，')
    return t.strip()


def win_lines(d, key, y0, y1, x0=0, x1=10**6):
    """Windows OCR の行のうち、中心が y0..y1 / x0..x1 に入るもの → [(y, x, text, words)]"""
    out = []
    for ln in d.get(key, []):
        ws = ln['words']
        if not ws:
            continue
        xs0 = min(w[1] for w in ws)
        xs1 = max(w[1] + w[3] for w in ws)
        ys0 = min(w[2] for w in ws)
        ys1 = max(w[2] + w[4] for w in ws)
        cy = (ys0 + ys1) / 2
        if y0 <= cy <= y1 and xs1 > x0 and xs0 < x1:
            out.append({'y': ys0, 'y1': ys1, 'cy': cy, 'x': xs0, 'x1': xs1, 'text': ln['text'], 'words': ws})
    out.sort(key=lambda r: (r['y'], r['x']))
    return out


def rapid_block(img, cache_key=None):
    """ブロック画像を RapidOCR（文字枠つき）にかけ、行ごとに { x, y, x1, y1, text, chars:[(ch, x0, x1)] } を返す"""
    fn = None
    if cache_key:
        fn = os.path.join(SCRATCH, 'scr', 'blocks', cache_key + '.json')
        if os.path.exists(fn):
            with open(fn, encoding='utf-8') as f:
                return json.load(f)
    res, _ = rapid()(img, return_word_box=True)
    out = []
    for r in res or []:
        box, txt, sc = r[0], r[1], r[2]
        chars = []
        if len(r) >= 5 and r[3] and r[4]:
            for cb, ch in zip(r[3], r[4]):
                xs = [p[0] for p in cb]
                chars.append([ch, int(min(xs)), int(max(xs))])
        xs = [p[0] for p in box]
        ys = [p[1] for p in box]
        out.append({'x': int(min(xs)), 'y': int(min(ys)), 'x1': int(max(xs)), 'y1': int(max(ys)), 'text': txt, 'score': round(float(sc), 3), 'chars': chars})
    out.sort(key=lambda r: (r['y'], r['x']))
    if fn:
        os.makedirs(os.path.dirname(fn), exist_ok=True)
        with open(fn, 'w', encoding='utf-8') as f:
            json.dump(out, f, ensure_ascii=False)
    return out


def spaced_text(r):
    """RapidOCR の 1 行を、文字枠の間隔から単語の切れ目を補って文字列にする"""
    chars = r.get('chars') or []
    if not chars:
        return r['text']
    widths = [c[2] - c[1] for c in chars if c[0].strip() and c[2] > c[1]]
    med = statistics.median(widths) if widths else 20
    out = ''
    prev_end = None
    for ch, x0, x1 in chars:
        if ch == ' ':
            if out and not out.endswith(' '):
                out += ' '
            prev_end = None if prev_end is None else prev_end
            continue
        if prev_end is not None and out and not out.endswith(' '):
            gap = x0 - prev_end
            if gap > med * 0.33:
                out += ' '
        out += ch
        prev_end = x1
    return out.strip()


def group_rows(items, tol=18, key='y'):
    """y でまとめて行にする（items は dict。'y','y1' を持つ）。同じ行は x 順"""
    rows = []
    for it in sorted(items, key=lambda r: (r['y'], r['x'])):
        cy = (it['y'] + it['y1']) / 2
        for row in rows:
            if abs(row['cy'] - cy) <= tol:
                row['items'].append(it)
                row['cy'] = (row['cy'] * (len(row['items']) - 1) + cy) / len(row['items'])
                break
        else:
            rows.append({'cy': cy, 'items': [it]})
    for row in rows:
        row['items'].sort(key=lambda r: r['x'])
    rows.sort(key=lambda r: r['cy'])
    return rows


def is_ja(text):
    return bool(CJK.search(text))


# ---------------------------------------------------------------- ページ構造
def _runs(bits):
    """True の連続（run）の [開始, 長さ] と、run の間の空きの長さ"""
    runs = []
    gaps = []
    start = None
    for i, v in enumerate(bits):
        if v and start is None:
            start = i
        elif not v and start is not None:
            runs.append((start, i - start))
            start = None
    if start is not None:
        runs.append((start, len(bits) - start))
    for (s0, l0), (s1, l1) in zip(runs, runs[1:]):
        gaps.append(s1 - (s0 + l0))
    return runs, gaps


def dotted_line_y(img, y_from=1200):
    """問題と解答を分ける点線（横の破線）の y。無ければ None。
    少し傾いていたり太い点だったりするので、上下 4px ずつを重ねた 1 本の明暗列を作り、
    点（run）が 30 個以上で長さと間隔がそろっている行を探す（文字の行は重ねると単語ごとの長い run になる）"""
    g = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    h, w = g.shape
    x0, x1 = int(w * 0.12), int(w * 0.93)
    dark = g[:, x0:x1] < 190
    best = None
    for y in range(y_from, h - 120, 2):
        bits = dark[y - 4:y + 5].any(axis=0)
        frac = bits.mean()
        if not (0.05 < frac < 0.85):
            continue
        runs, gaps = _runs(bits)
        if len(runs) < 30:
            continue
        lens = [l for _, l in runs]
        ml = statistics.median(lens)
        mg = statistics.median(gaps)
        if not (2 <= ml <= 18) or not (2 <= mg <= 26):
            continue
        sl = statistics.pstdev(lens)
        sg = statistics.pstdev(gaps)
        if sl > 3 or sg > 5:
            continue
        score = len(runs) - sl * 5 - sg * 3
        if best is None or score > best[1]:
            best = (y, score)
    return best[0] if best else None


def number_candidates(d, img_w):
    """左余白にある整数（問題番号・解答の番号）→ [(y, x, n, src)]"""
    out = []
    xmax = int(img_w * 0.2)
    for key in ('win_en', 'win_ja'):
        for ln in d.get(key, []):
            for w in ln['words']:
                t = w[0].strip()
                if re.fullmatch(r'\d{1,4}', t) and w[1] < xmax:
                    out.append((w[2], w[1], int(t), key, w[4]))
    for r in d.get('rapid', []):
        t = r[4].strip()
        if re.fullmatch(r'\d{1,4}', t) and r[0] < xmax:
            out.append((r[1], r[0], int(t), 'rapid', r[3] - r[1]))
            continue
        # 特別編の「(10) If we should …」のように括弧つきの番号で始まる枠
        m = re.match(r'^[(（]\s*(\d{1,2})\s*[)）]', t)
        if m and r[0] < xmax:
            out.append((r[1], r[0], int(m.group(1)), 'rapid', r[3] - r[1]))
    for ln in d.get('win_ja', []):
        for w in ln['words']:
            m = re.fullmatch(r'[(（]\s*(\d{1,2})\s*[)）]', w[0].strip())
            if m and w[1] < xmax:
                out.append((w[2], w[1], int(m.group(1)), 'win_ja', w[4]))
    return out


def _sequential(by_no, expected, max_no, prev_y, limit, min_gap):
    qs = []
    missing = []
    n = expected
    skipped = 0
    while n <= max_no:
        ys = sorted(set(y for y, x in by_no.get(n, []) if prev_y + min_gap <= y <= limit))
        if not ys:
            skipped += 1
            if skipped > 2:
                if not qs:
                    later = sorted(k for k in by_no if k > n and k <= max_no and any(prev_y + min_gap <= y <= limit for y, x in by_no[k]))
                    if later:
                        missing.extend(range(expected, later[0]))
                        n = later[0]
                        skipped = 0
                        continue
                break
            n += 1
            continue
        if skipped:
            missing.extend(range(n - skipped, n))
            skipped = 0
        qs.append((n, ys[0]))
        prev_y = ys[0]
        n += 1
    return qs, missing


def find_anchors(d, img, expected, max_no, min_gap=60, x_max_ratio=0.2):
    """
    問題番号の位置。expected から順に、前の番号より下にある最初の候補を採る（Power Up の表の ①〜 などを避ける）。
    解答欄の始まりは「ページの最初の問題番号が下の方にもう一度出る所」（無ければ点線）。
    戻り値 (questions:[(no, y)], answers:[(no, y)], split_y, missing:[no])
    """
    h, w = img.shape[:2]
    cands = [c for c in number_candidates(d, w) if c[1] < w * x_max_ratio and 18 <= c[4] <= 60]
    by_no = {}
    for y, x, n, src, hh in cands:
        by_no.setdefault(n, []).append((y, x))
    qs, missing = _sequential(by_no, expected, max_no, 60, h - 150, min_gap)
    split = page_split(d, img, qs[-1][1] if qs else int(h * 0.4))
    if split:
        qs = [(n, y) for n, y in qs if y < split]
    ans = []
    if split:
        m = {}
        for y, x, n, src, hh in cands:
            if y > split and qs and qs[0][0] <= n <= qs[-1][0] + 1:
                m.setdefault(n, []).append(y)
        ans = sorted((n_, int(statistics.median(v))) for n_, v in m.items())
    return qs, ans, split, missing


HEADER_RE = re.compile(r'^(Theme|Point|Rule|Power\s*Up|.?CHECK|特別編|第\d+章)', re.I)


def header_cut(d, y0, y1, x_max=1300):
    """y0..y1 の間にある見出し行（Theme / Point / Rule / Power Up! / CHECK!）の上端。無ければ y1"""
    best = y1
    for key in ('win_en', 'win_ja'):
        for ln in d.get(key, []):
            ws = ln['words']
            if not ws or ws[0][1] > x_max:
                continue
            ty = ws[0][2]
            if y0 + 40 <= ty <= y1 and HEADER_RE.match(ln['text'].strip().replace(' ', '')) or (y0 + 40 <= ty <= y1 and HEADER_RE.match(ln['text'].strip())):
                best = min(best, ty - 16)
    return best


ANS_HEAD_RE = re.compile(r'^\(?\s*\d{1,4}\s*\)?\s*[（(]')


def answer_start_by_rows(d, img, y_min):
    """解答欄の最初の行の y（RapidOCR のページ全体の枠から: 左端の番号のすぐ右に括弧が続く行）。無ければ None"""
    h, w = img.shape[:2]
    boxes = sorted(d.get('rapid', []), key=lambda r: (r[1], r[0]))
    rows = []
    for r in boxes:
        cy = (r[1] + r[3]) / 2
        if cy < y_min:
            continue
        for row in rows:
            if abs(row['cy'] - cy) <= 16:
                row['items'].append(r)
                break
        else:
            rows.append({'cy': cy, 'items': [r]})
    for row in sorted(rows, key=lambda x: x['cy']):
        its = sorted(row['items'], key=lambda r: r[0])
        first = its[0]
        if first[0] > w * 0.25:
            continue
        t = first[4].strip()
        if ANS_HEAD_RE.match(t):
            return int(first[1]) - 24
        if re.fullmatch(r'\(?\s*\d{1,4}\s*\)?', t) and len(its) > 1 and its[1][0] - first[2] < 150 and its[1][4].strip()[:1] in '(（':
            return int(first[1]) - 24
    return None


def answer_start_by_win(d, img, y_min, nos=None):
    """RapidOCR のページ全体の結果が無い右ページ用: Windows OCR ja の行（同じ高さの行はつなぐ）で
    「番号 (」「番号 A. (」「(1) (」で始まる最初の行の y。番号の読み違い（863 → 3）があっても可"""
    h, w = img.shape[:2]
    rows = []
    for ln in d.get('win_ja', []):
        ws = ln['words']
        if not ws:
            continue
        cy = ws[0][2] + ws[0][4] / 2
        if cy < y_min:
            continue
        for row in rows:
            if abs(row['cy'] - cy) <= 12:
                row['words'].extend(ws)
                break
        else:
            rows.append({'cy': cy, 'words': list(ws)})
    best = None
    for row in rows:
        ws = sorted(row['words'], key=lambda x: x[1])
        if ws[0][1] > w * 0.25:
            continue
        joined = ''.join(x[0] for x in ws[:8]).replace('（', '(').replace('）', ')').replace(' ', '')
        if re.match(r'^\(?\d{1,4}\)?(?:[AB][.．]?)?\(', joined):
            y = ws[0][2]
            if best is None or y < best:
                best = y
    return best - 24 if best is not None else None


def page_split(d, img, last_question_y, nos=None):
    """問題と解答欄の境目の y。点線があればそれ、無ければ解答欄の最初の行から。どちらも無ければ None"""
    split = dotted_line_y(img)
    if split is not None and split <= last_question_y:
        split = None
    by_rows = answer_start_by_rows(d, img, last_question_y + 40) if 'rapid' in d else answer_start_by_win(d, img, last_question_y + 40, nos)
    if split is not None and by_rows is not None and by_rows < split - 40:
        return by_rows  # 点線が解答の行の間などに誤検出されたとき
    return split if split is not None else by_rows


class Circled:
    """解答欄の丸数字（①〜⑤）をテンプレート照合で読む。テンプレートは RapidOCR が確信をもって読めた丸数字の切り抜きから集める"""

    def __init__(self):
        self.templates = {}  # idx -> [gray crops]

    def harvest(self, img, items, max_per=10):
        g = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
        for it in items:
            if it.get('score', 0) < 0.8:
                continue
            for ch, x0, x1 in it.get('chars') or []:
                if ch in CIRCLED[:6] and x1 - x0 >= 18:
                    idx = CIRCLED.index(ch)
                    lst = self.templates.setdefault(idx, [])
                    if len(lst) >= max_per:
                        continue
                    ax0 = it['ax'] + x0 - 3
                    ax1 = it['ax'] + x1 + 3
                    crop = g[it['ay'] - 2:it['ay1'] + 2, max(0, ax0):ax1]
                    if crop.shape[0] >= 20 and crop.shape[1] >= 16:
                        lst.append(crop)

    def ready(self):
        return sum(len(v) for v in self.templates.values()) >= 6 and len(self.templates) >= 3

    def read(self, img, x0, x1, y0, y1):
        """領域の中で最もよく合う丸数字 (idx, score)。無ければ (None, 0)"""
        g = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
        region = g[max(0, y0):y1, max(0, x0):x1]
        best = (None, 0.0)
        for idx, tpls in self.templates.items():
            for t in tpls:
                for sc in (1.0, 0.9, 1.1):
                    tt = t if sc == 1.0 else cv2.resize(t, None, fx=sc, fy=sc)
                    if region.shape[0] < tt.shape[0] or region.shape[1] < tt.shape[1]:
                        continue
                    r = cv2.matchTemplate(region, tt, cv2.TM_CCOEFF_NORMED)
                    v = float(r.max())
                    if v > best[1]:
                        best = (idx, v)
        return best if best[1] >= 0.55 else (None, best[1])


def read_circled_rec(img, x0, x1, y0, y1):
    """番号のすぐ右の「（②）」を RapidOCR の認識モデルだけで読む（高さ 48px に揃えた切り抜きと 3 倍の切り抜きの 2 通り）。
    両方が同じ丸数字なら (idx, score)、片方だけ読めて確信が高ければそれ、合わなければ (None, 0)"""
    crop = img[max(0, y0):y1, max(0, x0):x1]
    if crop.size == 0 or crop.shape[0] < 10:
        return None, 0.0
    eng = rapid()
    h = crop.shape[0]
    up1 = cv2.resize(crop, None, fx=48 / h, fy=48 / h, interpolation=cv2.INTER_CUBIC)
    up2 = cv2.resize(crop, None, fx=3, fy=3, interpolation=cv2.INTER_CUBIC)
    outs = []
    try:
        r1 = eng.text_rec([up1])
        if r1:
            outs.append((r1[0][0], float(r1[0][1])))
    except Exception:
        pass
    try:
        r2, _ = eng(up2, use_det=False)
        if r2:
            outs.append((r2[0][0], float(r2[0][1])))
    except Exception:
        pass
    found = []
    for t, sc in outs:
        m = re.search(r'[①②③④⑤⑥]', t)
        if m:
            found.append((CIRCLED.index(m.group(0)), sc))
        else:
            m = re.search(r'[（(]\s*([1-6])\s*[)）]', t)
            if m:
                found.append((int(m.group(1)) - 1, sc * 0.9))
    if not found:
        return None, 0.0
    if len(found) == 2 and found[0][0] == found[1][0]:
        return found[0][0], max(found[0][1], found[1][1])
    if len(found) == 2:
        best = max(found, key=lambda f: f[1])
        return (best[0], best[1]) if best[1] >= 0.72 and min(found[0][1], found[1][1]) < 0.6 else (None, best[1])
    return found[0] if found[0][1] >= 0.62 else (None, found[0][1])


_WIN = {}


def _win_engine(lang):
    if lang not in _WIN:
        from winrt.windows.globalization import Language
        from winrt.windows.media.ocr import OcrEngine
        _WIN[lang] = OcrEngine.try_create_from_language(Language(lang))
    return _WIN[lang]


def win_text(bgr, lang='en-US'):
    """小さな切り抜きを Windows OCR で読む（投票用）"""
    import asyncio
    from winrt.windows.graphics.imaging import BitmapDecoder
    from winrt.windows.storage.streams import DataWriter, InMemoryRandomAccessStream

    async def go(png):
        stream = InMemoryRandomAccessStream()
        w = DataWriter(stream.get_output_stream_at(0))
        w.write_bytes(png)
        await w.store_async()
        await w.flush_async()
        dec = await BitmapDecoder.create_async(stream)
        bmp = await dec.get_software_bitmap_async()
        res = await _win_engine(lang).recognize_async(bmp)
        return res.text

    ok, png = cv2.imencode('.png', bgr)
    try:
        return asyncio.run(go(png.tobytes()))
    except Exception:
        return ''


def read_circled_votes(img, x0, x1, y0, y1):
    """read_circled_rec に Windows OCR（3 倍の切り抜き）の票を足し、多数決で決める。戻り値 (idx, votes:list, agree:bool)"""
    crop = img[max(0, y0):y1, max(0, x0):x1]
    if crop.size == 0 or crop.shape[0] < 10:
        return None, [], False
    eng = rapid()
    h = crop.shape[0]
    votes = []
    try:
        r1 = eng.text_rec([cv2.resize(crop, None, fx=48 / h, fy=48 / h, interpolation=cv2.INTER_CUBIC)])
        if r1:
            votes.append(('rec', r1[0][0], float(r1[0][1])))
    except Exception:
        pass
    up3 = cv2.resize(crop, None, fx=3, fy=3, interpolation=cv2.INTER_CUBIC)
    try:
        r2, _ = eng(up3, use_det=False)
        if r2:
            votes.append(('rec3', r2[0][0], float(r2[0][1])))
    except Exception:
        pass
    votes.append(('win', win_text(up3, 'en-US'), 0.5))
    idxs = []
    for name, t, sc in votes:
        m = re.search(r'[①②③④⑤⑥]', t)
        if m:
            idxs.append((CIRCLED.index(m.group(0)), name))
            continue
        m = re.search(r'[（(]?\s*([1-6])\s*[)）]', t) or re.search(r'(?<!\d)([1-6])(?!\d)', t)
        if m:
            idxs.append((int(m.group(1)) - 1, name))
    if not idxs:
        return None, votes, False
    counts = {}
    for i, _ in idxs:
        counts[i] = counts.get(i, 0) + 1
    best = max(counts, key=lambda k: counts[k])
    agree = counts[best] >= 2 and all(i == best for i, _ in idxs)
    if counts[best] >= 2:
        return best, votes, agree
    return None, votes, False


class CircledGlyphs:
    """丸数字 ①〜⑤ の字形テンプレートで、解答欄の丸数字を照合する。
    テンプレートは問題の選択肢の行にある丸数字（左から ①②③④ の順に並ぶ）の連結成分から集める。
    解答欄の丸数字はごく薄い赤なので、B と G の小さい方のチャネルを 232 で 2 値化して閉じる。
    RapidOCR の認識モデルは ④ を ① と読み違えることがあるので、字形の照合を優先する"""

    SIZE = 36

    def __init__(self):
        self.tpl = {}  # idx -> [SIZE×SIZE float arrays]

    @staticmethod
    def binarize(bgr):
        mn = np.minimum(bgr[:, :, 0], bgr[:, :, 1])
        b = (mn < 232).astype('uint8')
        return cv2.morphologyEx(b, cv2.MORPH_CLOSE, np.ones((3, 3), np.uint8))

    @staticmethod
    def rings(bgr, lo=22, hi=48, strict=True):
        """丸数字らしい連結成分（ほぼ正方形の bbox）を x 順に。strict=False は薄い丸（解答欄）向けに形の検査を緩める"""
        if bgr is None or bgr.size == 0 or bgr.shape[0] < 4 or bgr.shape[1] < 4:
            return []
        b = CircledGlyphs.binarize(bgr)
        n, lab, stats, _ = cv2.connectedComponentsWithStats(b, 8)
        out = []
        for i in range(1, n):
            x, y, w, h, area = stats[i]
            if not (lo <= w <= hi and lo <= h <= hi and 0.8 <= w / h <= 1.25 and 0.15 * w * h <= area <= 0.6 * w * h):
                continue
            if not strict:
                out.append((int(x), int(y), int(w), int(h)))
                continue
            # 丸は bbox の 4 辺の中点を通る（文字のかけら「to」「ld」や漢字は通らない）
            comp = (lab[y:y + h, x:x + w] == i)
            cx, cy = w // 2, h // 2
            m = 3
            if not (comp[0:2, max(0, cx - m):cx + m].any() and comp[h - 2:h, max(0, cx - m):cx + m].any()
                    and comp[max(0, cy - m):cy + m, 0:2].any() and comp[max(0, cy - m):cy + m, w - 2:w].any()):
                continue
            # 中が空いている（丸の内側に背景がある）
            inner = comp[h // 4:3 * h // 4, w // 4:3 * w // 4]
            if inner.mean() > 0.55:
                continue
            out.append((int(x), int(y), int(w), int(h)))
        return sorted(out)

    @staticmethod
    def between_parens(bgr):
        """「( ② )」の最初の括弧の対の間にある成分をまとめた bbox（薄い丸が途切れていても拾える）。無ければ None"""
        if bgr is None or bgr.size == 0 or bgr.shape[0] < 4:
            return None
        b = CircledGlyphs.binarize(bgr)
        n, lab, stats, _ = cv2.connectedComponentsWithStats(b, 8)
        comps = sorted((int(st[0]), int(st[1]), int(st[2]), int(st[3])) for st in stats[1:] if st[4] >= 6)
        parens = [c for c in comps if c[2] <= 16 and c[3] >= 24]
        if len(parens) < 2:
            return None
        xo = parens[0]
        xc = next((c for c in parens[1:] if c[0] > xo[0] + xo[2] + 18), None)
        if xc is None:
            return None
        inner = [c for c in comps if c[0] >= xo[0] + xo[2] - 2 and c[0] + c[2] <= xc[0] + 2 and c[3] >= 6]
        if not inner:
            return None
        x = min(c[0] for c in inner)
        y = min(c[1] for c in inner)
        x1 = max(c[0] + c[2] for c in inner)
        y1 = max(c[1] + c[3] for c in inner)
        if not (16 <= x1 - x <= 60 and 16 <= y1 - y <= 60):
            return None
        return (x, y, x1 - x, y1 - y)

    @staticmethod
    def glyph(bgr, box):
        x, y, w, h = box
        mn = np.minimum(bgr[:, :, 0], bgr[:, :, 1])
        side = max(w, h) + 4
        cx, cy = x + w / 2, y + h / 2
        pad = np.pad(mn, side, constant_values=255)
        x0 = int(round(cx - side / 2)) + side
        y0 = int(round(cy - side / 2)) + side
        crop = pad[y0:y0 + side, x0:x0 + side]
        if crop.size == 0:
            return None
        g = cv2.resize(crop, (CircledGlyphs.SIZE, CircledGlyphs.SIZE), interpolation=cv2.INTER_AREA).astype('float32')
        # 濃さを揃える（黒の丸数字と薄い赤の丸数字を同じ尺度に）
        lo, hi = float(g.min()), float(np.percentile(g, 90))
        if hi - lo < 10:
            return None
        return np.clip((g - lo) / (hi - lo), 0, 1)

    def harvest_choice_row(self, img, y0, y1, x0, x1, toks=None, max_per=24):
        """選択肢の行の丸数字の字形を集める。番号は RapidOCR が読んだ丸数字（①〜⑤。x が丸と重なるもの）で決める。
        丸数字が読めていない行は、丸が 3〜5 個あって互いに 60px 以上離れ、読めた数字が左から増えているときだけ x 順に ①②③…"""
        reg = img[max(0, y0 - 4):y1 + 4, x0:x1]
        boxes = self.rings(reg)
        if not boxes:
            return
        labels = {}
        marks = []  # (abs_x, idx, reliable)
        for tok in toks or []:
            tx, tx1, t = tok[0], tok[1], tok[2]
            m = re.match(r'^\s*([①②③④⑤]|[1-5])(?=\s|$|[A-Za-z(])', t)
            if m:
                c = m.group(1)
                marks.append((tx, CIRCLED.index(c) if c in CIRCLED else int(c) - 1, c in CIRCLED))
        marks.sort()
        seq = [idx for _, idx, _ in marks]
        digits_ok = len(seq) >= 2 and seq == list(range(seq[0], seq[0] + len(seq)))  # 読めた番号が左から 1 つずつ増えている
        for k, (bx, by, bw, bh) in enumerate(boxes):
            ax = x0 + bx
            near = sorted((abs(mx - ax), idx, rel) for mx, idx, rel in marks if abs(mx - ax) <= 18)
            if not near:
                continue
            _, idx, rel = near[0]
            if rel or digits_ok:
                labels[k] = idx
        for k, idx in labels.items():
            lst = self.tpl.setdefault(idx, [])
            if len(lst) >= max_per:
                continue
            gl = self.glyph(reg, boxes[k])
            if gl is not None:
                lst.append(gl)

    def ready(self):
        return len(self.tpl) >= 4 and all(len(self.tpl.get(i, [])) >= 3 for i in range(4))

    def classify(self, img, x0, x1, y0, y1):
        """領域の中の最初の丸数字を照合。戻り値 (idx, best_score, margin)。丸が見つからなければ (None, 0, 0)"""
        reg = img[max(0, y0):y1, max(0, x0):x1]
        box = self.between_parens(reg)
        if box is None:
            boxes = self.rings(reg, strict=False)
            if not boxes:
                return None, 0.0, 0.0
            box = boxes[0]
        gl = self.glyph(reg, box)
        if gl is None:
            return None, 0.0, 0.0
        scores = {}
        for idx, tpls in self.tpl.items():
            vals = sorted((float(cv2.matchTemplate(gl, t, cv2.TM_CCOEFF_NORMED).max()) for t in tpls), reverse=True)
            scores[idx] = sum(vals[:3]) / min(3, len(vals))
        ranked = sorted(scores.items(), key=lambda kv: -kv[1])
        best = ranked[0]
        second = ranked[1][1] if len(ranked) > 1 else 0.0
        return best[0], best[1], best[1] - second
