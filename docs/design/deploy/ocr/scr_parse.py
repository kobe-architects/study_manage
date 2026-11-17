# スクランブル: 問題ブロック（画像＋OCR）→ 行の文字列（英文は RapidOCR の文字 + Windows OCR の単語の切れ目、和文は Windows OCR ja）
import difflib, re

import cv2
import numpy as np

import scr_lib as L

SRC_RE = re.compile(r'[（(]\s*([^（）()]{1,22}?)\s*[)）]\s*$')
SRC_WORDS = ('大', 'センター', '学院', '短大', '高専', '共通テスト', '改', '大学', '大院', '女子大', '工大', '医大', '薬大', '高校')
NOTE_RE = re.compile(r'[〔\[［]\s*([^〔〕\[\]［］]{2,40})\s*[〕\]］]')
GAP_BLANK = 70  # これ以上の横の空きは空所「(　)」
X_LEFT = 316  # 問題文の左端（番号・基本/頻出 のラベルの右）
X_RIGHT = 1665  # ページの右端（断ち落としのノイズを除く）


def _nospace(t):
    return t.replace(' ', '').replace('　', '')


def merge_spacing(rapid_text, win_text):
    """RapidOCR の文字列（空白は不正確）に、Windows OCR の文字列の空白位置を移す。合わない部分は RapidOCR の空白のまま"""
    if not win_text:
        return rapid_text
    R = _nospace(rapid_text)
    if not R:
        return win_text
    # R の各文字の後ろに空白があるか（Rapid 由来）
    r_space = [False] * len(R)
    k = 0
    for i, ch in enumerate(rapid_text):
        if ch in ' 　':
            if k > 0:
                r_space[k - 1] = True
        else:
            k += 1
    W = win_text
    Wn = _nospace(W)
    w_space = [False] * len(Wn)
    k = 0
    for ch in W:
        if ch in ' 　':
            if k > 0:
                w_space[k - 1] = True
        else:
            k += 1
    norm = lambda s: s.lower().replace('l', 'i').replace('0', 'o').replace('’', "'")
    sm = difflib.SequenceMatcher(None, norm(R), norm(Wn), autojunk=False)
    out_space = r_space[:]
    prefix = ''
    ops = sm.get_opcodes()
    for tag, i1, i2, j1, j2 in ops:
        if tag == 'equal':
            for d in range(i2 - i1):
                out_space[i1 + d] = w_space[j1 + d]
    # RapidOCR が行頭の短い語（I / It / A）を落としたとき: Windows 側にだけある先頭の 1〜3 文字を足す
    if ops and ops[0][0] == 'insert' and ops[0][1] == 0 and 1 <= ops[0][4] <= 3 and len(ops) > 1 and ops[1][0] == 'equal':
        k = ops[0][4]
        if k < len(w_space) and w_space[k - 1]:
            prefix = Wn[:k]
            if re.fullmatch(r'I|It|A|a|An|We|He|If|In|Is|Do|Go|No|So|To|Up|Or|Of', prefix):
                prefix += ' '
            else:
                prefix = ''
    s = ''
    for i, ch in enumerate(R):
        s += ch
        if out_space[i] and i < len(R) - 1:
            s += ' '
    return prefix + s


def _win_pieces(lines, y0, y1, x0, x1):
    """Windows OCR の行のうち、縦に重なり（中心が y0..y1）横にも重なるもの（x 順）"""
    out = []
    for w in lines:
        if y0 <= w['cy'] <= y1 and w['x1'] > x0 - 10 and w['x'] < x1 + 10:
            out.append(w)
    out.sort(key=lambda w: w['x'])
    return out


def _ja_words_in(lines, y0, y1, x0, x1):
    """Windows OCR ja の単語のうち、枠（x0..x1, y0..y1）に重なるものをつなげる（中心が x0 より左の単語は除く）"""
    ws = []
    for w in lines:
        if not (y0 <= w['cy'] <= y1):
            continue
        for wd in w['words']:
            wx, wx1 = wd[1], wd[1] + wd[3]
            if wx1 > x0 and wx < x1 and (wx + wx1) / 2 >= x0:
                ws.append((wx, wd[0]))
    ws.sort()
    return L.ja_text(' '.join(t for _, t in ws))


HINT_RE = re.compile(r'\(\s*[A-Za-z]{1,2}-')


def _win_words_in(pieces, x0, x1):
    ws = []
    for w in pieces:
        for wd in w['words']:
            wx, wx1 = wd[1], wd[1] + wd[3]
            if wx1 > x0 and wx < x1:
                ws.append((wx, wd[0]))
    ws.sort()
    return ' '.join(t for _, t in ws).replace('（', '(').replace('）', ')')


def _paren_pairs(img, y0, y1, x0, x1):
    """帯 y0..y1、x0..x1 の中の「(   )」（細長い成分 2 つが 40〜300px 離れ、間に幅の広い成分が無い）の x 範囲"""
    if x1 - x0 < 60 or y1 - y0 < 16:
        return []
    g = cv2.cvtColor(img[max(0, y0):y1, x0:x1], cv2.COLOR_BGR2GRAY)
    b = (g < 215).astype('uint8')  # 括弧は細く薄い
    b = cv2.morphologyEx(b, cv2.MORPH_CLOSE, np.ones((9, 3), np.uint8))  # 途切れた弧を縦につなぐ
    n, lab, stats, _ = cv2.connectedComponentsWithStats(b, 8)
    hgt = y1 - y0
    comps = sorted((int(st[0]), int(st[1]), int(st[2]), int(st[3])) for st in stats[1:] if st[4] >= 8)
    thin = [c for c in comps if c[2] <= 18 and c[3] >= hgt * 0.4]
    out = []
    used = set()
    for i, a in enumerate(thin):
        if i in used:
            continue
        for j in range(i + 1, len(thin)):
            bb = thin[j]
            dist = bb[0] - (a[0] + a[2])
            if dist < 40:
                continue
            if dist > 300:
                break
            between = [c for c in comps if a[0] + a[2] < c[0] and c[0] + c[2] < bb[0]]
            if any(c[2] > 24 for c in between):
                break
            out.append((x0 + a[0], x0 + bb[0] + bb[2]))
            used.add(j)
            break
    return out


def is_marker(tok):
    """選択肢の番号（①〜⑤、または丸が読めず 1〜5）"""
    return bool(re.fullmatch(r'[①②③④⑤⑥]|[1-6]', tok))


def marker_index(tok):
    if tok in L.CIRCLED:
        return L.CIRCLED.index(tok)
    return int(tok) - 1


def block_rows(img, d, y0, y1, x0, cache_key):
    """
    ブロックを行に組み立てる。戻り値 rows: [{ y, kind: 'ja'|'en', text, source, note }]
    - 行の切り分けは RapidOCR の枠（y でまとめる）。各枠の文字は RapidOCR、単語の切れ目は Windows OCR（en-US / ja）から
    - 枠と枠の間が GAP_BLANK 以上空いていれば空所「( )」を補う
    - 和文の行は Windows OCR ja の文字列（RapidOCR は仮名が読めない）
    - 右端の「（〇〇大）」は出典、〔 〕は指示として分ける
    """
    crop = img[y0:y1, x0:X_RIGHT]
    items = L.rapid_block(crop, cache_key)
    for it in items:
        it['ax'] = it['x'] + x0
        it['ax1'] = it['x1'] + x0
        it['ay'] = it['y'] + y0
        it['ay1'] = it['y1'] + y0
    win_en = L.win_lines(d, 'win_en', y0, y1, x0)
    win_ja = L.win_lines(d, 'win_ja', y0, y1, x0)
    rows = []
    for row in L.group_rows(items):
        its = [i for i in row['items'] if i['x1'] >= 22]  # 左端のかけら（基本／頻出 のラベルの端）は捨てる
        if not its:
            continue
        ry0 = min(i['ay'] for i in its)
        ry1 = max(i['ay1'] for i in its)
        rx0 = min(i['ax'] for i in its)
        rx1 = max(i['ax1'] for i in its)
        ja_pieces = _win_pieces(win_ja, ry0 - 4, ry1 + 4, rx0, rx1)
        ja_full = ' '.join(L.ja_text(w['text']) for w in ja_pieces)
        source = None
        note = None
        body = ja_full
        m = SRC_RE.search(body)
        if m and any(k in m.group(1) for k in SRC_WORDS):
            body = body[: m.start()].strip()
        ja_n = len(L.JA.findall(body))
        # 和文の行（和訳・日本語の問題文・指示）: Windows OCR ja をそのまま
        if ja_n >= 3 and ja_n >= len(_nospace(body)) * 0.3:
            text = ja_full
            m = SRC_RE.search(text)
            if m and any(k in m.group(1) for k in SRC_WORDS):
                source = m.group(1)
                text = text[: m.start()].strip()
            m = NOTE_RE.search(text)
            if m:
                note = m.group(1)
                text = (text[: m.start()] + text[m.end():]).strip()
            rows.append({'y': ry0, 'y1': ry1, 'x': rx0, 'x1': rx1, 'kind': 'ja', 'text': text, 'source': source, 'note': note, 'toks': [], 'markers': 0, 'items': its})
            continue
        # 英文の行: 枠ごとに文字を組み立てる（空所の補いは分類の後）
        toks = []  # (x, x1, text)
        for it in its:
            t = L.spaced_text(it).replace('（', '(').replace('）', ')').replace('＝', '=').replace('，', ',')
            wp = _win_pieces(win_en, it['ay'] - 6, it['ay1'] + 6, it['ax'], it['ax1']) or _win_pieces(win_ja, it['ay'] - 6, it['ay1'] + 6, it['ax'], it['ax1'])
            wt = ' '.join(w['text'] for w in wp)
            # 「(p-  )」のようなヒントつきの空所は RapidOCR が崩しやすい → Windows OCR 側にその形があればそちら
            if HINT_RE.search(wt) and not HINT_RE.search(t):
                t = _win_words_in(wp, it['ax'] - 8, it['ax1'] + 8)
            if L.CJK.search(t):
                # 英文の行の中の日本語（出典・指示）: Windows OCR ja の同じ位置の単語で置き換える
                jt = _ja_words_in(win_ja, it['ay'] - 6, it['ay1'] + 6, max(it['ax'] - 8, x0 + 4), it['ax1'] + 8) or t
                m = SRC_RE.search(jt)
                if m and any(k in m.group(1) for k in SRC_WORDS):
                    source = m.group(1)
                    jt = jt[: m.start()].strip()
                m = NOTE_RE.search(jt)
                if m:
                    note = m.group(1)
                    jt = (jt[: m.start()] + jt[m.end():]).strip()
                if not jt.strip():
                    toks.append((it['ax'], it['ax1'], ''))
                    continue
                t = jt
            toks.append((it['ax'], it['ax1'], merge_spacing(t, wt)))
        # RapidOCR が拾えなかった短い単語（1 文字の I・a、行頭の「I (」、行末の空所「( )」など）を Windows OCR から足す。
        # 括弧だけの語は行の先頭より前・最後より後ろにあるものだけ（間の空所は枠の間の空きから補う）
        toks = [t for t in toks if t[2]]
        first_x = min((x for x, _, _ in toks), default=10**6)
        last_x1 = max((x1 for _, x1, _ in toks), default=0)
        extra = []
        for src in (win_en, win_ja):
            for w in _win_pieces(src, ry0 - 12, ry1 + 12, 0, 10**6):
                line_has_cjk = bool(L.JA.search(w['text']))
                for wd in w['words']:
                    wt = wd[0].strip().replace('（', '(').replace('）', ')')
                    wx, wx1 = wd[1], wd[1] + wd[3]
                    if wx < x0 or not wt or wx1 > X_RIGHT - 20:
                        continue
                    if line_has_cjk and re.fullmatch(r'[()]{1,2}[.,!?]?', wt):
                        continue  # 出典「（〇〇大）」の括弧
                    if any(min(wx1, x1) - max(wx, x) > 0.5 * max(1, wx1 - wx) or (wx <= x and wx1 >= x1) for x, x1, _ in toks):
                        continue
                    # 出典「（成蹊大）」など、捨てた RapidOCR の枠に重なる語（その括弧）も足さない
                    if any(min(wx1, it['ax1']) - max(wx, it['ax']) > 0.3 * max(1, wx1 - wx) for it in its):
                        continue
                    is_paren = bool(re.fullmatch(r'[()]{1,2}[.,!?]?', wt))
                    if is_paren:
                        if wx1 <= first_x + 4:
                            extra.append((wx, wx1, '( )'))  # 行頭の空所「(   ) hello to …」
                        elif wx >= last_x1 - 4:
                            extra.append((wx, wx1, '( )' + re.sub(r'[()]', '', wt)))
                        continue
                    if re.fullmatch(r'[IAa][.,!?:]?|\([A-Za-z]{1,2}-|[A-Za-z]{1,2}\s*\(', wt) or (len(re.findall(r'[A-Za-z]', wt)) >= 2 and not L.CJK.search(wt)):
                        extra.append((wx, wx1, wt))
        # 括弧の重複（同じ位置の ( と ( )）は 1 つに。隣の枠の端の語と同じ語（「ended up」+「up」）は二重読みなので捨てる
        seen = set()
        words_near = set()
        for x, x1, t in toks:
            ws_ = t.split()
            if ws_:
                words_near.add(ws_[0].lower().strip('.,!?'))
                words_near.add(ws_[-1].lower().strip('.,!?'))
        for x, x1, t in sorted(extra):
            key = (round(x / 30), t[:1])
            if key in seen:
                continue
            if t[0] != '(' and t.lower().strip('.,!?') in words_near:
                continue
            seen.add(key)
            toks.append((x, x1, t, 'win'))
        toks.sort()
        # 行末の「(   ).」を OCR がどちらも拾えなかったとき: 最後の枠の右にある細長い成分の対（括弧）を画像から探す
        if toks:
            lx1 = max(t[1] for t in toks)
            for px, px1 in _paren_pairs(img, ry0 - 4, ry1 + 4, lx1 + 6, X_RIGHT - 20):
                if not any(min(px1, x1) - max(px, x) > 10 for x, x1, *_ in toks):
                    toks.append((px, px1, '( )', 'win'))
            toks.sort()
        markers = sum(1 for tok in toks for tk in tok[2].split() if is_marker(tk)) + len(re.findall(r'[①②③④⑤⑥](?=\S)', ' '.join(tok[2] for tok in toks)))
        rows.append({'y': ry0, 'y1': ry1, 'x': rx0, 'x1': rx1, 'kind': 'en', 'text': '', 'source': source, 'note': note, 'toks': toks, 'markers': markers, 'items': its})
    # 選択肢の行: 番号が 2 つ以上、番号が 1 つで枠の間の大きな空きが 2 つ以上（4 列の選択肢で丸数字が読めなかった）、または番号が 1 つで隣の行も番号を持つ・隣が選択肢の行
    for r in rows:
        gaps = sum(1 for a, b in zip(r['toks'], r['toks'][1:]) if b[0] - a[1] >= 60)
        r['choice'] = r['kind'] == 'en' and (r['markers'] >= 2 or (r['markers'] >= 1 and gaps >= 2))
    for i, r in enumerate(rows):
        if r['kind'] == 'en' and not r['choice'] and r['markers'] >= 1:
            nb = [rows[j] for j in (i - 1, i + 1) if 0 <= j < len(rows)]
            if any(x['kind'] == 'en' and x['markers'] >= 1 and not x.get('choice') for x in nb):
                r['choice'] = True
    changed = True
    while changed:
        changed = False
        for i, r in enumerate(rows):
            if r['kind'] == 'en' and not r['choice'] and r['markers'] >= 1:
                if (i > 0 and rows[i - 1].get('choice')) or (i + 1 < len(rows) and rows[i + 1].get('choice')):
                    r['choice'] = True
                    changed = True
    for r in rows:
        if r['kind'] != 'en':
            continue
        parts = []
        prev_end = None
        for tok in r['toks']:
            x, x1, t = tok[0], tok[1], tok[2]
            from_win = len(tok) > 3 and tok[3] == 'win'
            if prev_end is None and t.startswith(')') and not r['choice']:
                parts.append('(')
            if prev_end is not None and x - prev_end >= GAP_BLANK and not r['choice']:
                gap = x - prev_end
                last = parts[-1] if parts else ''
                if from_win and t.startswith('('):
                    pass  # Windows OCR から足した行末の空所は、それ自体が空所
                elif re.search(r'\([^)]*$', last):
                    parts[-1] = last + ' )'  # 「(c-」「(」の後ろの空き = 空所の中
                    for _ in range(min(2, max(0, round((gap - 110) / 165)))):  # 続けて別の空所があるほど広い
                        parts.append('( )')
                elif t.startswith(')'):
                    parts.append('(')
                else:
                    for _ in range(min(3, max(1, round(gap / 165)))):  # 空所 1 つ ≈ 165px
                        parts.append('( )')
            if t:
                # 枠の境目で同じ短い語が二重に読まれたもの（「ended up」+「up (p-」）
                pw = parts[-1].split()[-1].lower() if parts and parts[-1].split() else ''
                fw = t.split()[0].lower() if t.split() else ''
                if pw and pw == fw and pw in ('up', 'on', 'in', 'out', 'to', 'of', 'at', 'by', 'for', 'with', 'off', 'away', 'over', 'down', 'into', 'from'):
                    t = t[len(t.split()[0]):].strip()
                if t:
                    parts.append(t)
            prev_end = x1
        text = ' '.join(parts)
        if not r['choice'] and re.search(r'\([^)]*$', text):
            text += ' )'
        text = re.sub(r'\(\s*\)', '( )', text)
        text = re.sub(r'\(\s*([A-Za-z]{1,2})-\s*\)', r'(\1- )', text)
        text = re.sub(r'\( \)\s*\)', '( )', text)
        text = re.sub(r'(\([A-Za-z]{1,2}- \))\s*\)', r'\1', text)  # 「(t- ) )」
        text = re.sub(r'\(\s*(\([A-Za-z]{1,2}-)', r'\1', text)  # 「( (s-」
        text = re.sub(r'(?<![(A-Za-z])([a-z]{1,2})- \( \)', r'(\1- )', text)  # 「p- ( )」→「(p- )」
        text = re.sub(r'\s*\( \)\s*', ' ( ) ', text)
        text = re.sub(r'^[基本頻出発展超]+\s*', '', text)  # 左余白のラベルの読み込み
        text = re.sub(r'\s+', ' ', text).strip()
        # 行末に残った出典（「（早 大 ）」のように分かれて読めたもの）
        if r['source'] is None:
            m = re.search(r'[（(]?\s*((?:[\u3400-\u9fff\u30a0-\u30ff]\s*){1,14})[)）]\s*$', text)
            if m and any(k in m.group(1).replace(' ', '') for k in SRC_WORDS):
                r['source'] = m.group(1).replace(' ', '')
                text = text[: m.start()].strip()
        if not re.search(r'[A-Za-z0-9]', text):
            text = ''  # 括弧だけの行（出典の括弧の読みかす）
        r['text'] = text
    return rows


def choices_from_rows(rows, col_gap=40):
    """
    選択肢の行（choice=True の行）から選択肢を組み立てる。
    枠の位置で区切る: 番号（①〜／1〜）の枠、または前の枠から col_gap 以上離れた枠で新しい選択肢が始まる。
    番号が読めた選択肢はその番号に、読めなかったものは前の番号 + 1 に置く。戻り値 (choices, ok)
    """
    found = {}
    nxt = 0
    for r in rows:
        toks = []
        for tok in r.get('toks', []):
            x, x1, t = tok[0], tok[1], tok[2]
            t = re.sub(r'^[()]\s*', '', t.strip())  # 空所の括弧の読み込み（選択肢の行に空所は無い）
            t = re.sub(r'\s*[()]$', '', t)
            if t and t not in ('( )',):
                toks.append((x, x1, t))
        groups = []
        prev_end = None
        for x, x1, t in toks:
            words = t.split()
            starts_marker = bool(words) and is_marker(words[0])
            if groups and not starts_marker and prev_end is not None and x - prev_end < col_gap:
                groups[-1].append(t)
            else:
                groups.append([t])
            prev_end = x1
        for g in groups:
            text = ' '.join(g)
            text = re.sub(r'(^|\s)([1-6])\s+[1-9]\s+(?=[A-Za-z])', r'\1\2 ', text)  # 「3 1 reading」= ③の誤読が 2 つ
            # 先頭や途中の番号で分ける（「① use ② do」が 1 枠のこともある）
            pieces = re.split(r'(?:^|\s)([①②③④⑤⑥]|[1-6](?=\s*[A-Za-z(]))\s*', text)
            lead = pieces[0].strip()
            lead = re.sub(r'^(?:\(?[A-Z0-9j]{1,2}\)?|\(|\)|[基本頻出発展超]+)\s+', '', lead)  # ①の誤読（O / D1 / (j)）や左余白のラベル
            lead = re.sub(r'^[B-HJ-Zb-hj-z]\s+(?=[A-Za-z])', '', lead)
            if lead:
                found[nxt] = lead
                nxt += 1
            i = 1
            while i < len(pieces) - 1:
                idx = marker_index(pieces[i])
                body = pieces[i + 1].strip()
                body = re.sub(r'^[1-9]\s+(?=[A-Za-z])', '', body)  # 丸数字の誤読（④→1）が残ったもの
                body = re.sub(r'^(?:[)\]|]|[B-HJ-Zb-hj-z])\s+(?=[A-Za-z])', '', body)  # 丸数字のかけら（「) adds」「S sink」）
                if idx < nxt - 1 and found:
                    idx = nxt  # 番号の誤読（③→1 など）: 並び順で置く
                if body:
                    found[idx] = body
                    nxt = idx + 1
                i += 2
    if not found:
        return [], False
    n = max(found) + 1
    choices = [found.get(i, '') for i in range(n)]
    return choices, all(choices) and n >= 2


def importance_of(d, y0, y1, x_margin_max, img=None):
    """左余白の 基本／頻出／発展 のラベル。「発展」は黒地に白抜きで OCR が読めないので、余白の黒い箱から判定する"""
    labs = []
    if img is not None:
        g = cv2.cvtColor(img[y0:y1, 222:302], cv2.COLOR_BGR2GRAY) < 90
        rows = g.mean(axis=1) > 0.55
        run = 0
        for v in rows:
            run = run + 1 if v else 0
            if run >= 16:
                labs.append('発展')
                break
    for w in L.win_lines(d, 'win_ja', y0, y1, 0, x_margin_max):
        t = L.ja_text(w['text']).replace('频', '頻')
        for k in ('基本', '頻出', '発展', '超頻'):
            if k in t and k not in labs:
                labs.append(k)
    return '・'.join(labs) if labs else None


def answer_rows(img, d, split, x0=150, cache_key=None):
    """解答欄（点線の下）の行。各行 { text: RapidOCR+Windows の英文（番号・括弧）, ja: Windows OCR ja の文字列 }。
    解答欄は左ページの下のことも右ページの下のこともある（右ページは Windows OCR ja しか無い）"""
    h = img.shape[0]
    y1 = h - 96  # 最後の解答の行はページの下の方まで来る（フッターの「288 Part 3」は番号が問題の範囲外なので無視される）
    if split is None or y1 - split < 30:
        return []
    crop = img[split + 4:y1, x0:X_RIGHT]
    items = L.rapid_block(crop, cache_key)
    for it in items:
        it['ax'] = it['x'] + x0
        it['ax1'] = it['x1'] + x0
        it['ay'] = it['y'] + split + 4
        it['ay1'] = it['y1'] + split + 4
    win_ja = L.win_lines(d, 'win_ja', split + 4, y1, x0)
    win_en = L.win_lines(d, 'win_en', split + 4, y1, x0)
    out = []
    for row in L.group_rows(items, tol=16):
        its = row['items']
        ry0 = min(i['ay'] for i in its)
        ry1 = max(i['ay1'] for i in its)
        parts = []
        for it in its:
            t = L.spaced_text(it)
            if not L.CJK.search(t):
                wp = _win_pieces(win_en, it['ay'] - 6, it['ay1'] + 6, it['ax'], it['ax1'])
                t = merge_spacing(t, ' '.join(w['text'] for w in wp))
            parts.append((it['ax'], t))
        ja = ' '.join(L.ja_text(w['text']) for w in _win_pieces(win_ja, ry0 - 4, ry1 + 4, 0, 10**6))
        out.append({'y': ry0, 'y1': ry1, 'x': min(i['ax'] for i in its), 'text': ' '.join(t for _, t in parts), 'parts': parts, 'ja': ja, 'items': its})
    return out
