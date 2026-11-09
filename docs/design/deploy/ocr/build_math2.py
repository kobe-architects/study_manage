# 合格る確率 / 合格る計算 → 学習カード（例題の画像 + OCR で読んだ答えの 4 択 + 解説の画像）・教材 JSON・ページ対応表
#   例題の枠（桃色の細い罫で囲まれた矩形。左上に「例題」のラベル）を色で検出し、枠の下〜類題バッジまでを「解説」とする。
#   答えは解説領域の OCR 行から「よって…は N 通り」「…=N」「最大公約数は 6」などを拾う。読めない例題は自己判定（画像）のまま。
import json, re, os, sys, random, hashlib
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import numpy as np
import pymupdf
from PIL import Image
import build_math as bm

OUT = 'out/math2'
IMG = 'out/cards_images'
os.makedirs(OUT, exist_ok=True)
report = []
REVIEW = open(f'{OUT}/answers_review.txt', 'w', encoding='utf-8')

# ---------------- 画像ユーティリティ ----------------
_img_cache = {}
def page_img(doc, pg, dpi=200):
    key = (doc.name, pg, dpi)
    if key in _img_cache:
        return _img_cache[key]
    pix = doc[pg - 1].get_pixmap(dpi=dpi)
    img = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, pix.n)[:, :, :3].astype(int)
    if len(_img_cache) > 4:
        _img_cache.clear()
    _img_cache[key] = img
    return img

def red_mask(img):
    r, g, b = img[:, :, 0], img[:, :, 1], img[:, :, 2]
    return (r - g > 25) & (r - b > 0) & (r > 120)

def longest_run(xs, maxgap=30):
    if not len(xs):
        return None
    best = None
    start = prev = xs[0]
    for x in xs[1:]:
        if x - prev > maxgap:
            if best is None or prev - start > best[1] - best[0]:
                best = (start, prev)
            start = x
        prev = x
    if best is None or prev - start > best[1] - best[0]:
        best = (start, prev)
    return best

EXCLUDE_LABEL = re.compile(r'基本確認|解き方のコツ|ツボ|入試|基本パターン|基本バターン')

def reidai_frames(doc, pg, W, H, ja_lines):
    """例題の枠 [(y0, y1, x0, x1)]（OCR と同じ 200dpi 座標）。
    桃色の細い横罫（幅 0.6W 以上）のうち、直下の左寄りに桃色の文字（例題 ラベル）があるか OCR に「例題」がある罫を上罫とし、
    左端のそろった次の横罫を下罫とする。直下の OCR が「基本確認」「解説・解き方のコツ」などの囲みは除く"""
    img = page_img(doc, pg)
    red = red_mask(img).copy()
    h, w = red.shape
    sx, sy = W / w, H / h
    pad = np.zeros((h + 12, w), dtype=bool)
    for k in range(13):
        pad[k:k + h] |= red
    redd = pad[6:6 + h]
    rows = []
    for y in range(h):
        xs = np.where(redd[y])[0]
        if len(xs) < 0.2 * w:
            continue
        run = longest_run(xs)
        if run is None or run[1] - run[0] < 0.6 * w:
            continue
        rows.append((y, run[0], run[1]))
    lines = []
    for y, x0, x1 in rows:
        if lines and y - lines[-1]['y1'] <= 1:
            lines[-1]['y1'] = y
            lines[-1]['x0'] = min(lines[-1]['x0'], x0)
            lines[-1]['x1'] = max(lines[-1]['x1'], x1)
        else:
            lines.append({'y0': y, 'y1': y, 'x0': x0, 'x1': x1})
    thin = [l for l in lines if l['y1'] - l['y0'] <= 28]
    labels = []
    texts = []
    for l in ja_lines:
        w0 = l['words'][0]
        t = l['text'].replace(' ', '')
        texts.append((w0[2] / sy, w0[1] / sx, t))
        if '例題' in t:
            labels.append((w0[2] / sy, w0[1] / sx))
    out = []
    for i, t in enumerate(thin):
        lab = red[max(0, t['y1'] - 6):t['y1'] + 52, t['x0'] + 8:t['x0'] + 150]
        dens = float(lab.mean()) if lab.size else 0.0
        ocr_hit = any(t['y0'] - 15 <= ly <= t['y1'] + 60 and lx < t['x0'] + 300 for ly, lx in labels)
        if not (0.02 <= dens <= 0.6) and not ocr_hit:
            continue
        # 直下 70px の OCR 行に「基本確認」などがあれば例題ではない
        below = ' '.join(tx for ly, lx, tx in texts if t['y0'] - 10 <= ly <= t['y1'] + 70)
        if EXCLUDE_LABEL.search(below) and '例題' not in below:
            continue
        bottom = None
        for j in range(i + 1, len(thin)):
            b = thin[j]
            gap = b['y0'] - t['y1']
            if gap < 30:
                continue
            if gap > 700:
                break
            if abs(t['x0'] - b['x0']) > 15:
                continue
            bottom = b
            break
        if bottom is None:
            continue
        out.append((int(t['y0'] * sy), int(bottom['y1'] * sy), int(t['x0'] * sx), int(max(t['x1'], bottom['x1']) * sx)))
    out.sort()
    dedup = []
    for f in out:
        if dedup and f[0] < dedup[-1][1]:
            if (f[1] - f[0]) > (dedup[-1][1] - dedup[-1][0]):
                dedup[-1] = f
            continue
        dedup.append(f)
    return dedup

def item_headers_full(qpages, maxN, key):
    hdrs = bm.item_headers(qpages, maxN)
    unknown = [pg for k, pg in [k for k in hdrs if isinstance(k, tuple)]]
    hdrs = {k: v for k, v in hdrs.items() if not isinstance(k, tuple)}
    refs = bm.answer_ref_pages(qpages)
    for n in range(1, maxN + 1):
        if n not in hdrs:
            prev_pg = hdrs[n - 1][0] if n - 1 in hdrs else 0
            next_pg = hdrs[n + 1][0] if n + 1 in hdrs else max(qpages) + 1
            cands = [pg for pg in unknown if prev_pg < pg < next_pg]
            if cands:
                hdrs[n] = (cands[0], '')
                report.append(f'{key} ITEM {n}: header number unreadable, used page {cands[0]}')
            else:
                rp = [pg for pg in sorted(refs) if prev_pg < pg < next_pg]
                if len(rp) >= 2:
                    hdrs[n] = (rp[-1] - 1 if rp[-1] - 1 > rp[-2] else rp[-1], '')
                elif rp:
                    hdrs[n] = (max(prev_pg + 1, rp[0] - 1), '')
                else:
                    hdrs[n] = (prev_pg + 2, '')
                report.append(f'{key} ITEM {n}: header not found, guessed page {hdrs[n][0]}')
    return hdrs

# ---------------- OCR テキスト ----------------
def compact(s):
    toks = s.split(' ')
    out = []
    for i, t in enumerate(toks):
        if i and t and out and out[-1] and re.match(r'[A-Za-z0-9\)\]]$', out[-1][-1]) and re.match(r'^[A-Za-z0-9\(\[]', t):
            out.append(' ')
        out.append(t)
    return ''.join(out).strip()

def region_lines(qpages, regs):
    """領域内の OCR 行 → [(page, y, x, h, text)]（ページ → y の順）"""
    out = []
    for (p, y0, y1) in regs:
        d = qpages[p]
        ls = []
        for l in d['ja']:
            w0 = l['words'][0]
            yc = w0[2] + w0[4] / 2
            if y0 <= yc <= y1:
                ls.append((w0[2], w0[1], l))
        ls.sort()
        for y, x, l in ls:
            out.append((p, y, x, l['words'][0][4], compact(l['text'])))
    return out

CIRCLED = {'①': 1, '②': 2, '③': 3, '④': 4, '⑤': 5, '⑥': 6}
def sub_marker(text):
    """行頭の小問番号 (1) / （1） / ① → 1。無ければ None"""
    t = text.lstrip('。・.、 ')
    m = re.match(r'^[\(（]\s*([1-9lI])\s*[\)）]', t)
    if m:
        s = m.group(1)
        return 1 if s in 'lI' else int(s)
    if t and t[0] in CIRCLED:
        return CIRCLED[t[0]]
    return None

# OCR の誤読の軽い補正（数式らしい文脈でだけ使う）
def fix_math(s):
    s = s.replace('ェ', 'x').replace('ミ', '≦').replace('十', '+').replace('ー', '-').replace('一', '-').replace('．', '.').replace('，', ',')
    s = s.replace('><', '×').replace('・', '·')
    s = re.sub(r'(?<=\d)\s*通り', '通り', s)
    return s

UNIT_RE = r'(?:通り|個|本|枚|人|組|回|円|cm|度|°|%|通)'
VALUE_RE = re.compile(r'^[-+±]?\d[\d,\.]*(?:\s*' + UNIT_RE + r')?$')
NOUN_RE = re.compile(r'^(最大公約数|最小公倍数|最大値|最小値|平均値|分散|標準偏差|期待値|面積|体積|長さ|個数|商|余り)\s*(?:は|＝|=|:|：)\s*(.+)$')

def clean_value(v):
    v = v.strip().rstrip('.。,、;；:：')
    v = re.sub(r'\s*[\(（]\s*(' + UNIT_RE + r')\s*[\)）]\s*$', r'\1', v)  # 10 (通り) → 10通り
    v = v.replace(' ', '')
    v = re.sub(r'(通り|個|本|枚|人|組|回|円)(です|である|となる|になる)?$', r'\1', v)
    return v

def is_clean(v):
    """選択肢に出せる答えか: 数値（小数・分数・負号）＋単位、または x=数値 / a≦x≦b の形だけ"""
    if not v or len(v) > 20:
        return False
    num = r'[-+]?\d+(?:\.\d+)?(?:/\d+)?'
    if re.fullmatch(num + r'(?:' + UNIT_RE + r')?', v):
        return True
    if re.fullmatch(r'[a-zA-Z]\s*(?:=|≦|≧|<|>)\s*' + num, v):
        return True
    if re.fullmatch(num + r'\s*(?:≦|<)\s*[a-zA-Z]\s*(?:≦|<)\s*' + num, v):
        return True
    if re.fullmatch(r'[a-zA-Z]\s*=\s*' + num + r'\s*,\s*[a-zA-Z]\s*=\s*' + num, v):
        return True
    return False

def last_eq_value(line):
    """「… = 36 (通り).」のような行から最後の = の右側を返す（無ければ None）"""
    if '=' not in line and '＝' not in line:
        return None
    parts = re.split(r'[=＝]', line)
    v = parts[-1]
    return clean_value(fix_math(v))

KEY_RE = re.compile(r'(よって|ゆえに|したがって|以上より|∴|求める.{0,10}?は|答え?は)')
TAIL_RE = re.compile(r'(確率|場合の数|個数|総数|値|数|方法)は[.。,，]?$')

def answer_section(lines, W):
    """「解答」の見出し行があれば、その最後の見出しより後ろだけを返す（方針・注意の数字を拾わないため）"""
    idx = [i for i, (p, y, x, h, t) in enumerate(lines) if x < 0.35 * W and re.match(r'^(解答|解法|解説・解き方のコツ|解説)', t.replace(' ', ''))]
    sec = lines[idx[-1] + 1:] if idx else lines
    # 解答のあとの 注意・補足・参考・発展・別解（OCR では 洋意・注第・耋・00・091 などに化ける）から先は使わない
    cut = None
    for i, (p, y, x, h, t) in enumerate(sec):
        t0 = t.replace(' ', '')
        if i >= 1 and x < 0.3 * W and re.match(r'^(注意?\d?|洋意|注第|注濠|補足\d?|参考|発展|別解|研究|耋|0{2}\d?|0\d{2}|00|重要|入試)', t0):
            cut = i
            break
    return sec[:cut] if cut is not None else sec

def extract_answers(lines, W, want_all=False):
    """解説領域の OCR 行（1 小問ぶん）から答えの候補を作る。返り値: 答えの文字列 or None（want_all なら候補の一覧）"""
    labeled = {}   # 名詞 → 値（最大公約数 → 6 など。後の行で上書き）
    plain = []     # 単独の値（順に）
    texts = [t for (_, _, _, _, t) in lines]
    for idx, (p, y, x, h, t) in enumerate(lines):
        if h < 3:
            continue
        t0 = t.replace(' ', '')
        # (a) 名詞 = 値 / 名詞は値
        for seg in re.split(r'[.。]', t0):
            seg = re.sub(r'^(よって|ゆえに|したがって|以上より|∴|次に|また|求める)[,，、]?', '', seg)
            m = NOUN_RE.match(fix_math(seg))
            if m:
                noun, rest = m.group(1), m.group(2)
                v = last_eq_value(rest) if re.search(r'[=＝]', rest) else clean_value(fix_math(rest))
                if is_clean(v) and len(noun) <= 8:
                    labeled[noun] = v
        # (b) キーワードのある行
        mk = KEY_RE.search(t0)
        if mk or TAIL_RE.search(t0):
            rest = t0[mk.end():].lstrip(',，、.:：') if mk else ''
            while True:
                m2 = KEY_RE.match(rest)
                if not m2:
                    break
                rest = rest[m2.end():].lstrip(',，、.:：')
            if TAIL_RE.search(rest) or (not mk):
                rest = ''
            v = last_eq_value(rest) if re.search(r'[=＝]', rest) else clean_value(fix_math(rest))
            if is_clean(v):
                plain.append(v)
                continue
            # 直後の分数（縦に並んだ数字 2 行。キーワード行の前後に分かれることもある）
            frac = stacked_fraction(lines, idx)
            if frac:
                plain.append(frac)
                continue
            # 次の行がそのまま値（「5通り」など）
            if idx + 1 < len(lines):
                nv = clean_value(fix_math(lines[idx + 1][4].replace(' ', '')))
                if re.search(r'[=＝]', lines[idx + 1][4]):
                    nv = last_eq_value(lines[idx + 1][4].replace(' ', ''))
                if is_clean(nv):
                    plain.append(nv)
                    continue
        # (c) 「… = N (通り).」
        if re.search(r'[=＝]', t0) and re.search(r'\d\s*[\(（]?\s*' + UNIT_RE + r'\s*[\)）]?\s*[.。]?$', t0):
            v = last_eq_value(t0)
            if is_clean(v):
                plain.append(v)
    if want_all:
        out = []
        for v in plain:
            if not out or out[-1] != v:
                out.append(v)
        return out
    if labeled:
        # 名詞つきの答えを「最大公約数 6、最小公倍数 36」の形に
        items = [f'{k} {v}' for k, v in labeled.items()]
        return '、'.join(items[-3:])
    if plain:
        return plain[-1]
    return None

BOOK_KEY = ''
def validate(ans, qtext=''):
    """読み取った答えの妥当性: 分数の先頭 0 は不可。確率の本では分数は a<b、単位なしの 0・1・-1 は不可。
    問題文が「確率」を問うなら整数だけの答えは不可、「何通り」「場合の数」を問うなら分数は不可"""
    if not ans:
        return None
    if '、' not in ans and ' ' not in ans:
        if '確率' in qtext and re.fullmatch(r'[-+]?\d+', ans):
            return None
        if ('通り' in qtext or '場合の数' in qtext or '個数' in qtext or '何個' in qtext) and re.fullmatch(r'\d+/\d+', ans):
            return None
        # 合格る計算: 答えが式のことが多く、単位も名詞も無い裸の数は誤読が多いので、「値を求めよ」「何個」などの問いのときだけ
        if BOOK_KEY == 'ukaru_keisan' and re.fullmatch(r'[-+]?\d+(?:\.\d+)?(?:/\d+)?', ans) and not re.search(r'値|何個|個数|何通り|何人|何回', qtext):
            return None
    for part in re.split(r'、', ans):
        v = part.split(' ')[-1]
        m = re.fullmatch(r'(\d+)/(\d+)' + r'(?:' + UNIT_RE + r')?', v)
        if m:
            a, b = m.group(1), m.group(2)
            if a.startswith('0') or b.startswith('0') or int(b) == 0:
                return None
            if int(a) >= int(b):
                return None
        if re.fullmatch(r'[-+]?[01]', v):
            return None
    return ans

def stacked_fraction(lines, idx):
    """lines[idx]（キーワード行）の前後 ±70px にある、短い数字だけの 2 行（分子・分母。x がそろい y が近い）を a/b に"""
    y0 = lines[idx][1]
    nums = []
    for (p, y, x, h, t) in lines[max(0, idx - 3):idx + 7]:
        if p != lines[idx][0]:
            continue
        t0 = t.replace(' ', '')
        if re.fullmatch(r'\d{1,4}', t0) and abs(y - y0) < 80:
            nums.append((y, x, t0))
    nums.sort()
    for i in range(len(nums) - 1):
        a, b = nums[i], nums[i + 1]
        if abs(a[1] - b[1]) < 40 and 0 < b[0] - a[0] < 60:
            return f'{a[2]}/{b[2]}'
    return None

# ---------------- 選択肢（誤答）の生成 ----------------
def perturb_number(s, rnd):
    """数値文字列を少し変える（整数・小数）"""
    if '.' in s:
        try:
            f = float(s)
        except ValueError:
            return s
        cands = [f + 1, f - 1, f * 2, f / 2, f + 0.5, f - 0.5]
        c = rnd.choice(cands)
        return ('%g' % round(c, 2))
    try:
        n = int(s.replace(',', ''))
    except ValueError:
        return s
    cands = [n + 1, n - 1, n + 2, n - 2, n * 2, n + 10, n - 10, n + 6, n - 6, n * 3, n + 100 if n >= 100 else n + 3]
    if n % 2 == 0:
        cands.append(n // 2)
    if n % 3 == 0:
        cands.append(n // 3)
    cands = [c for c in cands if c > 0 and c != n]
    return str(rnd.choice(cands))

def distractors(answer, rnd, pool_values):
    """正解から 3 つの誤答を作る。分数は分子・分母を変える、「名詞 値、名詞 値」は値だけ変える。pool_values は同じ教材の他の答え（候補に使う）"""
    out = set()
    tries = 0
    while len(out) < 3 and tries < 60:
        tries += 1
        if re.fullmatch(r'\d+/\d+', answer):
            a, b = answer.split('/')
            a, b = int(a), int(b)
            k = rnd.randrange(6)
            if k == 0:
                v = f'{a + 1}/{b}'
            elif k == 1:
                v = f'{a}/{b + 1}' if b + 1 != a else f'{a}/{b + 2}'
            elif k == 2:
                v = f'{b - a}/{b}' if 0 < b - a != a else f'{a + 2}/{b}'
            elif k == 3:
                v = f'{a}/{b * 2}'
            elif k == 4:
                v = f'{max(1, a - 1)}/{b}' if a - 1 >= 1 else f'{a + 3}/{b}'
            else:
                v = f'{a * 2}/{b}' if a * 2 < b else f'{a}/{b - 1}' if b - 1 > a else f'{a + 1}/{b + 1}'
        else:
            # 数字を 1 つ（複数あれば 1〜2 個）変える
            nums = list(re.finditer(r'\d+(?:\.\d+)?', answer))
            if not nums:
                break
            idxs = [rnd.randrange(len(nums))]
            if len(nums) > 1 and rnd.random() < 0.4:
                idxs.append((idxs[0] + 1) % len(nums))
            v = answer
            for i in sorted(set(idxs), reverse=True):
                m = nums[i]
                v = v[:m.start()] + perturb_number(m.group(0), rnd) + v[m.end():]
        if v != answer:
            out.add(v)
    # 足りなければ同じ教材の他の答えから
    for pv in pool_values:
        if len(out) >= 3:
            break
        if pv != answer and pv not in out and len(pv) <= len(answer) + 8:
            out.add(pv)
    return list(out)[:3]

# ---------------- 切り出し ----------------
def crop(doc, pg, x0, y0, x1, y1, W, H, dpi=170):
    page = doc[pg - 1]
    pw, ph = page.rect.width, page.rect.height
    rect = pymupdf.Rect(max(0, x0) / W * pw, max(0, y0) / H * ph, min(W, x1) / W * pw, min(H, y1) / H * ph) & page.rect
    if rect.is_empty or rect.height < 2:
        return None
    pix = page.get_pixmap(dpi=dpi, clip=rect)
    return Image.frombytes('RGB', [pix.width, pix.height], pix.samples)

def stack(ims, gap=14, bg=(255, 255, 255)):
    ims = [im for im in ims if im is not None]
    if not ims:
        return None
    if len(ims) == 1:
        return ims[0]
    w = max(im.width for im in ims)
    total = sum(im.height for im in ims) + gap * (len(ims) - 1)
    big = Image.new('RGB', (w, total), bg)
    y = 0
    for im in ims:
        big.paste(im, (0, y)); y += im.height + gap
    return big

# ---------------- 本体 ----------------
def build(key, spec):
    global BOOK_KEY
    BOOK_KEY = key
    qpages = bm.load(spec['q'])
    qdoc = pymupdf.open(os.path.join(bm.BASE, spec['qpdf']))
    maxN = spec['maxN']
    hdrs = item_headers_full(qpages, maxN, key)
    os.makedirs(f'{IMG}/{key}', exist_ok=True)
    # 古い画像（類題 q*/a*）は消す
    for fn in os.listdir(f'{IMG}/{key}'):
        os.remove(f'{IMG}/{key}/{fn}')
    rnd = random.Random(hashlib.md5(key.encode()).hexdigest())

    items = []
    book_rows = []
    item_pages = {}; problem_page = {}; answer_pages = {}
    # 解答編の対応（類題→解答編）は従来どおり pages JSON に残す
    apages = bm.load(spec['a'])
    aranges, _raw = bm.answer_footers(apages, maxN)

    pending_cards = []
    for n in range(1, maxN + 1):
        pg0, title = hdrs[n]
        pg1 = (hdrs[n + 1][0] - 1) if n + 1 in hdrs else pg0 + 1
        if pg1 < pg0:
            pg1 = pg0
        rng = list(range(pg0, pg1 + 1))
        badges = []
        frames = []
        for pg in rng:
            d = qpages[pg]; W, H = d['w'], d['h']
            for a, b in bm.badges_on(qdoc, pg, W, H, bm.text_margin(d)):
                badges.append((pg, a, b))
            for f in reidai_frames(qdoc, pg, W, H, d['ja']):
                if any(bp == pg and abs(a - f[0]) < 80 for (bp, a, b) in badges):
                    continue
                frames.append((pg,) + f)
        fallback = False
        if not frames:
            for pg in rng[:2]:
                d = qpages[pg]; W, H = d['w'], d['h']
                labs = [l for l in d['ja'] if '例題' in l['text'].replace(' ', '') and l['words'][0][1] < 0.35 * W]
                if not labs:
                    continue
                l0 = min(labs, key=lambda l: l['words'][0][2])
                y = l0['words'][0][2]
                below = [l['words'][0][2] for l in d['ja'] if l['words'][0][2] > y + 30 and l['words'][0][1] < 0.3 * W and re.match(r'^(解答|方針|解説|解法|考え方|着眼)', l['text'].replace(' ', ''))]
                y1 = (min(below) - 10) if below and min(below) - y < 700 else y + 400
                frames.append((pg, max(0, y - 20), int(y1), int(0.08 * W), int(0.97 * W)))
                fallback = True
                report.append(f'{key} ITEM {n}: 例題 frame not found; used OCR label on p{pg}')
                break
        if not frames:
            report.append(f'{key} ITEM {n}: 例題 not found at all (pages {rng}); skipped')
            continue
        frames.sort()
        chap = next(c for c in spec['chapters'] if c[2] <= n <= (c[3] if len(c) > 3 else c[2]))
        sec = f'ITEM {n} {title}'.strip()
        ap = [pg for pg in sorted(aranges) if aranges[pg][0] <= n <= aranges[pg][1]]
        for fi, f in enumerate(frames):
            pg, fy0, fy1, fx0, fx1 = f
            d = qpages[pg]; W, H = d['w'], d['h']
            sfx = '' if len(frames) == 1 else 'abcdef'[fi]
            # ラベル（例題 12A など。OCR が読めていればそれを使う）
            label = f'例題 {n}' if key == 'ukaru_kakuritsu' else '例題'
            for l in d['ja']:
                w0 = l['words'][0]
                t = l['text'].replace(' ', '')
                if fy0 - 15 <= w0[2] <= fy0 + 70 and w0[1] < fx0 + 300:
                    m = re.match(r'^.{0,3}例題\s*(\d{1,3}\s*[A-Z]?)', t)
                    if m and key == 'ukaru_kakuritsu':
                        label = '例題 ' + m.group(1).replace(' ', '')
                        break
            if len(frames) > 1 and not re.search(r'[A-Z]$', label):
                label += 'ABCDEF'[fi]
            # 例題の画像
            pimg = f'{key}/e{n:03d}{sfx}.jpg'
            im = crop(qdoc, pg, fx0 - 14, fy0 + 2, fx1 + 14, fy1 + 10, W, H)
            if im is None:
                report.append(f'{key} ITEM {n}{sfx}: empty problem crop')
                continue
            im.save(f'{IMG}/{pimg}', 'JPEG', quality=86, optimize=True)
            # 解説の領域: 枠の下 → 次の例題枠／類題バッジ／ページ下端。次ページへ続く（最大 3 ページ）
            regs = []
            y = fy1 + 6
            pages_after = [p for p in rng if p >= pg]
            for p in pages_after[:3]:
                dd = qpages[p]; HH = dd['h']
                start = y if p == pg else int(0.05 * HH)
                stops = [g[1] - 8 for g in frames if g[0] == p and g[1] > start + 20]
                stops += [a - 14 for (bp, a, b) in badges if bp == p and a > start + 20]
                if stops:
                    regs.append((p, start, int(min(stops))))
                    break
                regs.append((p, start, int(0.935 * HH)))
            regs = [(p, a, b) for (p, a, b) in regs if b - a > 40]
            aimg = None
            if regs:
                ims = []
                for (p, a, b) in regs:
                    dd = qpages[p]; WW, HH = dd['w'], dd['h']
                    x0, x1 = bm.ink_bounds(qdoc, p, a, b, WW, HH)
                    ims.append(crop(qdoc, p, x0, a, x1, b, WW, HH, dpi=150))
                big = stack(ims)
                if big is not None:
                    aimg = f'{key}/s{n:03d}{sfx}.jpg'
                    big.save(f'{IMG}/{aimg}', 'JPEG', quality=82, optimize=True)
            # 小問と答え
            qlines = region_lines(qpages, [(pg, fy0, fy1)])
            qtext = ''.join(t.replace(' ', '') for (_, _, _, _, t) in qlines)
            sub_nos = []
            for (_, _, x, h, t) in qlines:
                k = sub_marker(t)
                if k and k not in sub_nos:
                    sub_nos.append(k)
            sub_nos.sort()
            slines = region_lines(qpages, regs) if regs else []
            slines = answer_section(slines, W)
            subs = []
            if sub_nos and len(sub_nos) >= 2:
                # 小問ごとの区間: その番号の「最後の」出現から次の番号まで（方針→解答と 2 度出るときは解答の方）
                marks = [(i, sub_marker(t)) for i, (_, _, x, h, t) in enumerate(slines) if sub_marker(t) and x < 0.35 * W]
                if not any(kk in sub_nos for (_, kk) in marks):
                    # 解答部分に小問の番号が無い（百十一 のような書き方）→ 候補を順に小問へ
                    cands = extract_answers(slines, W, want_all=True)
                    if len(cands) == len(sub_nos):
                        marks = None
                        for k, v in zip(sub_nos, cands):
                            subs.append({'label': f'({k})', 'answer': validate(v, qtext)})
                for k in (sub_nos if marks is not None else []):
                    occ = [i for (i, kk) in marks if kk == k]
                    if not occ:
                        subs.append({'label': f'({k})', 'answer': None})
                        continue
                    i0 = occ[-1]
                    nxt = [i for (i, kk) in marks if i > i0]
                    i1 = nxt[0] if nxt else len(slines)
                    ans = validate(extract_answers(slines[i0:i1], W), qtext)
                    subs.append({'label': f'({k})', 'answer': ans})
            else:
                ans = validate(extract_answers(slines, W), qtext)
                subs.append({'label': None, 'answer': ans})
            good = [s for s in subs if s['answer']]
            REVIEW.write(f"{key} ITEM {n}{sfx} ({label}) p{pg} subs={len(subs)} ok={len(good)}\n")
            for s in subs:
                REVIEW.write(f"    {s['label'] or '-'}: {s['answer']}\n")
            pending_cards.append({
                'n': n, 'sfx': sfx, 'label': label, 'chap': chap, 'sec': sec, 'title': title, 'pg': pg, 'pages': rng,
                'pimg': pimg, 'aimg': aimg, 'subs': subs, 'ap': ap, 'fallback': fallback,
            })
        book_rows.append({
            'chapter': chap[1], 'seqNo': str(n), 'title': sec, 'subject': '数学', 'major': '', 'mid': '', 'sub': '',
            'meta': {'ページ': f'{pg0}-{pg1}' if pg1 > pg0 else str(pg0), '解答ページ': ','.join(map(str, ap))},
        })
        item_pages[str(n)] = rng
        problem_page[str(n)] = sorted({f[0] for f in frames})
        answer_pages[str(n)] = ap

    # 選択肢を作る（誤答の補充用に同じ教材の答えを集める）
    pool = [s['answer'] for c in pending_cards for s in c['subs'] if s['answer']]
    no = 0
    n_choice = 0
    for c in pending_cards:
        no += 1
        subs_out = []
        for s in c['subs']:
            if not s['answer']:
                continue
            ds = distractors(s['answer'], rnd, pool)
            if len(ds) < 3:
                continue
            subs_out.append({'label': s['label'], 'answer': s['answer'], 'options': ds})
        complete = len(subs_out) == len(c['subs'])
        extra = {'problemPages': [c['pg']], 'answerPages': c['ap'], 'itemPages': c['pages'], 'title': c['title'], 'label': c['label']}
        if subs_out and complete:
            extra['subs'] = subs_out
            n_choice += 1
        elif subs_out:
            # 一部の小問しか読めないときは読めた小問だけ 4 択にする（残りは解説画像で確認）
            extra['subs'] = subs_out
            extra['partial'] = True
            n_choice += 1
        items.append({
            'no': no, 'chapterNo': c['chap'][0], 'chapter': c['chap'][1], 'section': c['sec'], 'kind': 'problem',
            'front': c['label'], 'back': None, 'extra': extra,
            'page': c['pg'], 'bookPage': str(c['pg']), 'problemImage': c['pimg'], 'answerImage': c['aimg'],
        })
    structure = []
    for it in items:
        if not structure or structure[-1][0] != it['chapterNo']:
            structure.append([it['chapterNo'], it['chapter'], it['no'], it['no']])
        else:
            structure[-1][3] = it['no']
    deck = {'key': key, 'subject': 'math', 'name': spec['name'], 'shortName': spec['short'], 'studentOnly': False,
            'resourceBookTitle': spec['name'], 'structure': structure, 'sortOrder': spec['sort']}
    json.dump({'deck': deck, 'items': items}, open(f'{OUT}/cards_{key}.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    json.dump({'title': spec['name'], 'type': '問題集', 'subject': '数学', 'items': book_rows},
              open(f'{OUT}/book_{key}.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    json.dump({'itemPages': item_pages, 'problemPage': problem_page, 'answerPages': answer_pages},
              open(f'{OUT}/pages_{key}.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    report.append(f'{key}: cards {len(items)}, with choices {n_choice}, self-judge {len(items) - n_choice}')
    return items

def sheet(key, items, kind, path, ns, cols=4, cw=420, ch=300):
    from PIL import ImageDraw
    tiles = []
    for it in items:
        if it['no'] not in ns:
            continue
        p = it['problemImage'] if kind == 'q' else it['answerImage']
        if not p or not os.path.exists(f'{IMG}/{p}'):
            continue
        im = Image.open(f'{IMG}/{p}'); im.thumbnail((cw, ch))
        tiles.append((it['no'], it['front'], im))
    rows = (len(tiles) + cols - 1) // cols
    big = Image.new('RGB', (cols * (cw + 10), max(1, rows) * (ch + 16)), 'white')
    dr = ImageDraw.Draw(big)
    for i, (n, lab, im) in enumerate(tiles):
        x, y = (i % cols) * (cw + 10), (i // cols) * (ch + 16)
        big.paste(im, (x, y + 14)); dr.text((x + 2, y), f'No.{n} {lab}', fill='red')
    big.save(path)

if __name__ == '__main__':
    counts = {}
    for k, s in bm.BOOKS.items():
        its = build(k, s)
        counts[k] = len(its)
        sheet(k, its, 'q', f'{OUT}/sheet_{k}_q.png', set(range(1, 13)))
        sheet(k, its, 'a', f'{OUT}/sheet_{k}_a.png', {1, 2, 3, 7, 50}, cols=5, cw=300, ch=600)
    REVIEW.close()
    with open(f'{OUT}/REPORT.txt', 'w', encoding='utf-8') as f:
        f.write('counts: ' + repr(counts) + '\n')
        f.write('\n'.join(report) + '\n')
    print('done', counts)
    for r in report[-2:]:
        print(r)
