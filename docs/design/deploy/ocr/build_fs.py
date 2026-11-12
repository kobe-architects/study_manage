# 高校リード問題集 英文法ファーストステップ（STEP 1・STEP 2・まとめのテスト・総合問題）を
# 「英文法・語法」の問題データ（grammar:import の JSON。book = first_step）に起こす。
#
# 入力: ocr_batch.py と同じ要領で OCR した JSON（<scratch>/ocr/fsq = 問題編、<scratch>/ocr/fsa = 解答編。ja と en-US、250dpi）と元の PDF。
#   python build_fs.py <scratch>
# 方針:
#   - 解答編の「P.5 STEP 1」のような見出し（タグ）で、問題編のどのページが演習ページかを知る。タグごとに
#     〔答えの箱（【解説】の前）〕と〔解説（【解説】の後）〕がある。箱の行は「大問ラベル (1) 答え (2) 答え …」。
#   - 問題編の演習ページは、左端の大問ラベル（1・1A〜C・2①・1・2・3 など）と、その右の小問番号「(2)」〜 で区切る。
#     「(1)」は OCR で落ちやすい（い・は)・I) などになる）ので、見出しの直後の本文行や記号くずれの行を (1) の先頭とみなす。
#     まとめのテストの { } で組になった小問は番号が 2 行の中央に来るので、番号の少し上の行も同じ小問に入れる。
#     (1) long (2) easy のように 1 行に 2 列並ぶ大問は列ごとに切る。語群「〔go walk …〕」や「ア … イ …」が大問の末尾に
#     まとまっているときは、全部の小問の画像に付ける。
#   - 箱の答えは (1) で始まる行を大問の切れ目として分け、ページ内の大問の小問数と突き合わせる。合わない大問は
#     小問数の手がかり（答えの数）で問題側を切り直し（{ } 組＝2 行で 1 問、日本文で始まる行＝次の小問）、それでも合わなければ
#     答えの文字は付けず「答えの箱の画像を見て自己判定」にする。
#   - 1 小問 = 1 問。問題画像 = 大問の見出し + その小問の行（+ 語群）。解答画像 = その大問の答えの行、解説画像 = その大問の解説。
#   - 形式: 答えが ア〜オ 1 文字なら記号選択（choiceCount・answerIndex）、英単語 1〜3 語の空所（「, 」「 / 」区切り、〔 〕[ ] は別解）
#     なら記入（answers で自動採点）、それ以外（書きかえ・和訳・英作文・文型）は答えを見て自己判定。
# 出力: backend/database/data/first_step_grammar.json、画像 backend/storage/app/private/grammar/first_step/、
#       確認用 <scratch>/out/fs/REPORT.txt・answers.txt
import json, os, re, sys, io
import pymupdf
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..', '..', '..'))
SCRATCH = sys.argv[1] if len(sys.argv) > 1 else os.getcwd()
OCR_Q = os.path.join(SCRATCH, 'ocr', 'fsq')
OCR_A = os.path.join(SCRATCH, 'ocr', 'fsa')
PDF_Q = os.path.join(ROOT, 'docs', 'other', '高校リード問題集_英文法_ファーストステップ.pdf')
PDF_A = os.path.join(ROOT, 'docs', 'other', '高校リード問題集_英文法_ファーストステップ解答.pdf')
IMG_DIR = os.path.join(ROOT, 'backend', 'storage', 'app', 'private', 'grammar', 'first_step')
OUT_JSON = os.path.join(ROOT, 'backend', 'database', 'data', 'first_step_grammar.json')
OUT_DIR = os.path.join(SCRATCH, 'out', 'fs')

# 目次（章番号 or テスト, 名前, 先頭ページ）。次の項目の先頭ページの手前までがその章
TOC = [
    (1, '基本時制', 4), (2, '助動詞', 10), (3, '名詞，冠詞', 16), (4, '代名詞', 22), (5, '形容詞，副詞', 28), (6, '比較', 34),
    (7, '基本文型', 42), ('T1', 'まとめのテスト 1', 46), (8, '受動態', 48), (9, '現在完了', 54), (10, '過去完了', 60),
    ('T2', 'まとめのテスト 2', 66), (11, '不定詞', 68), (12, '動名詞', 76), (13, '分詞', 82), (14, '分詞構文', 88),
    ('T3', 'まとめのテスト 3', 92), (15, '関係代名詞', 94), (16, '関係副詞', 100), (17, '間接疑問文，付加疑問文', 106),
    ('T4', 'まとめのテスト 4', 112), (18, '接続詞', 114), (19, '前置詞', 120), (20, '仮定法', 126), ('T5', 'まとめのテスト 5', 132),
    ('G1', '総合問題 1', 134), ('G2', '総合問題 2', 136), (None, 'Key Sentences のまとめ', 138),
]
TEST_RANGE = {'T1': '第1章〜第7章', 'T2': '第8章〜第10章', 'T3': '第11章〜第14章', 'T4': '第15章〜第17章', 'T5': '第18章〜第20章'}
PITCH = 56  # 本文の行ピッチ（250dpi のおよその値）


def chapter_of(page):
    cur = None
    for key, name, start in TOC:
        if page >= start:
            cur = (key, name)
    key, name = cur
    if key is None:
        return None
    if isinstance(key, int):
        return {'no': key, 'name': f'第{key}章 {name}', 'kind': 'chapter'}
    if key.startswith('T'):
        n = int(key[1:])
        return {'no': 20 + n, 'name': f'{name}（{TEST_RANGE[key]}）', 'kind': 'test'}
    return {'no': 25 + int(key[1:]), 'name': name, 'kind': 'general'}


# ---------------- OCR の読み込み ----------------
def load(dir_):
    pages = {}
    for fn in sorted(os.listdir(dir_)):
        if fn.endswith('.json'):
            d = json.load(open(os.path.join(dir_, fn), encoding='utf-8'))
            pages[d['page'] - 1] = d  # PDF のページ番号（0 始まり）= 本のページ番号
    return pages


def lines_of(d, lang):
    out = []
    for ln in d[lang]:
        ws = ln['words']
        if not ws:
            continue
        x0 = min(w[1] for w in ws)
        y0 = min(w[2] for w in ws)
        x1 = max(w[1] + w[3] for w in ws)
        y1 = max(w[2] + w[4] for w in ws)
        h = max(w[4] for w in ws)
        if h < 10 or x1 - x0 < 6:
            continue
        out.append({'x': x0, 'y': y0, 'x1': x1, 'y1': y1, 'h': h, 't': ln['text'], 'lang': lang, 'words': ws})
    return out


JA_RE = re.compile(r'[぀-ヿ㐀-鿿！-｠]')


def has_ja(s):
    return bool(JA_RE.search(s))


def clean_ja(s):
    """ja OCR は文字ごとに空白が入るので、日本語の文字の間の空白を詰める"""
    s = s.replace('　', ' ')
    prev = None
    out = []
    i = 0
    PUNCT = '〔〕（）「」〈〉《》、。，．・：；く'
    while i < len(s):
        c = s[i]
        if c == ' ':
            j = i
            while j < len(s) and s[j] == ' ':
                j += 1
            nxt = s[j] if j < len(s) else None
            if prev is not None and nxt is not None and (has_ja(prev) or has_ja(nxt) or prev in PUNCT or nxt in PUNCT):
                i = j
                continue
            out.append(' ')
            i = j
            continue
        out.append(c)
        prev = c
        i += 1
    return ''.join(out).strip()


def rows_of(lines, tol=14):
    """y が近い行を 1 つの「行（row）」にまとめる（ラベルと本文が別の OCR 行になるため）"""
    lines = sorted(lines, key=lambda l: (l['y'], l['x']))
    rows = []
    for l in lines:
        if rows and abs(l['y'] - rows[-1]['y']) <= tol:
            r = rows[-1]
            r['lines'].append(l)
            r['x'] = min(r['x'], l['x'])
            r['x1'] = max(r['x1'], l['x1'])
            r['y1'] = max(r['y1'], l['y1'])
        else:
            rows.append({'y': l['y'], 'y1': l['y1'], 'x': l['x'], 'x1': l['x1'], 'lines': [l]})
    for r in rows:
        r['lines'].sort(key=lambda l: l['x'])
        r['tj'] = ' '.join(l['t'] for l in r['lines'] if l['lang'] == 'ja')
        r['te'] = ' '.join(l['t'] for l in r['lines'] if l['lang'] == 'en-US')
        r['first'] = r['lines'][0]
    return rows


# ---------------- 記号の正規化 ----------------
CIRC = {'①': '(1)', '②': '(2)', '③': '(3)', '④': '(4)', '⑤': '(5)', '⑥': '(6)', '⑦': '(7)', '⑧': '(8)', '⑨': '(9)', '⑩': '(10)',
        '⑴': '(1)', '⑵': '(2)', '⑶': '(3)', '⑷': '(4)', '⑸': '(5)', '⑹': '(6)', '⑺': '(7)', '⑻': '(8)', '⑼': '(9)', '⑽': '(10)'}
CIRC_REV = {'1': '①', '2': '②', '3': '③', '4': '④'}


def norm_markers(s):
    """小問番号 (n) の OCR ゆれを (n) に揃える。読めない番号は (?)"""
    for k, v in CIRC.items():
        s = s.replace(k, v)
    s = s.replace('（', '(').replace('）', ')')
    s = re.sub(r'\(\s*(\d{1,2})\s*\)', r'(\1)', s)
    s = re.sub(r'\(\s*[lIi|!]\s*\)', '(1)', s)
    s = re.sub(r'[〇○。]\s*(?=\(|は\s*\)|い\s*\))', '', s)
    s = re.sub(r'(?<![A-Za-z぀-鿿])[はい]\s*\)', '(1) ', s)
    s = re.sub(r'\(\s*い\s*', '(1) ', s)
    s = re.sub(r'(?<![぀-鿿A-Za-z])い(?=[A-Z])', '(1) ', s)
    s = re.sub(r'(?<![぀-鿿])田(?=\s*[ア-ンA-Za-z])', '(1) ', s)
    s = re.sub(r'\(\s*[Oo0]\s+(?=[A-Za-z0-9\'぀-鿿「])', '(?) ', s)
    s = re.sub(r'\(\s*わ\s*', '(4) ', s)
    s = re.sub(r'(?<![A-Za-z぀-鿿])[目日]\s*\)', '(1) ', s)
    s = re.sub(r'\(\s*[ー・]?\s*幻\s*', '(2) ', s)
    s = re.sub(r'\(\s*[ー・]\s*い\s*', '(4) ', s)
    s = re.sub(r'(?<![A-Za-z])\(\s*d\s*\)', '(4)', s)
    s = re.sub(r'(?<![\d(])1\s*\)(?=\s)', '(1) ', s)
    return s


MARK_RE = re.compile(r'\((\d{1,2}|\?)\)')
# 行頭の (1) くずれ（い・I)・( l)・(l など）
JUNK1_RE = re.compile(r'^\s*(?:[(（]\s*[いlI|!1]\s*[)）]?|[いlI|!]\s*[)）]|い|1\s*[)）])\s*(?=\S|$)')
# 行頭の番号くずれ（番号は不明）
JUNKN_RE = re.compile(r'^\s*(?:[問罰田目日]|[(（]\s*[ー・]\s*[^\s)）]{0,2}\s*[)）]?)\s*(?=\S|$)')

LABEL_RE = re.compile(r'^\s*([1-9IlＩ])\s*(?:([A-Z])\s*(?:[—－‐\-~〜～ーew]+)\s*([A-F])(?![A-Za-z])|([A-F])(?![a-z])|(\(\d\)|\(\?\))|((?:\s*[・･.。,、]\s*[1-9])+))?')


def parse_label(s):
    """行頭の大問ラベル → (正規化したラベル, 残りの文字列)。ラベルでなければ None"""
    s2 = norm_markers(s)
    m = LABEL_RE.match(s2)
    if not m:
        return None
    n = m.group(1)
    n = '1' if n in 'IlＩ' else n
    rest = s2[m.end():]
    if m.group(2):
        label = f'{n}{m.group(2)}〜{m.group(3)}'
    elif m.group(4):
        label = f'{n}{m.group(4)}'
    elif m.group(5):
        label = n
        rest = m.group(5) + rest
    elif m.group(6):
        label = n + '・' + '・'.join(re.findall(r'[1-9]', m.group(6)))
    else:
        label = n
    return label, rest


def markers_in_row(r):
    """行の中の小問番号 (n) と x 位置（ja・en の単語から）"""
    out = []
    for l in r['lines']:
        ws = l['words']
        i = 0
        while i < len(ws):
            hit = None
            # 「( 2 )」のように語が分かれていることがあるので 1〜3 語をつなげて試す
            for k in (1, 2, 3):
                if i + k > len(ws):
                    break
                t = norm_markers(''.join(w[0] for w in ws[i:i + k]))
                m = MARK_RE.fullmatch(t.strip())
                if m:
                    hit = (ws[i][1], int(m.group(1)) if m.group(1) != '?' else None, k)
            if hit:
                out.append((hit[0], hit[1]))
                i += hit[2]
            else:
                i += 1
    # 同じ番号の重複（ja と en 両方）を x でまとめる
    out.sort()
    merged = []
    for x, n in out:
        if merged and abs(merged[-1][0] - x) < 30:
            if merged[-1][1] is None:
                merged[-1] = (merged[-1][0], n)
            continue
        merged.append((x, n))
    return merged


# ---------------- 問題編 ----------------
def page_bottom(d, ja, en):
    W, H = d['w'], d['h']
    bottom = int(0.95 * H)
    for l in ja + en:
        if l['y'] > 0.88 * H and re.fullmatch(r'[—\-–―一ー\s]*\d+[—\-–―一ー\s]*', l['t'].strip()) and abs((l['x'] + l['x1']) / 2 - W / 2) < 0.2 * W:
            bottom = min(bottom, l['y'] - 16)
    return bottom


def parse_question_page(d, pg):
    """演習ページの大問と小問の位置を求める"""
    W, H = d['w'], d['h']
    ja = [l for l in lines_of(d, 'ja') if l['x'] >= 120]
    en = [l for l in lines_of(d, 'en-US') if l['x'] >= 120]
    bottom = page_bottom(d, ja, en)
    body = [l for l in ja + en if 0.04 * H < l['y'] < bottom]
    rows = rows_of(body)
    # 語注より下は使わない。STEP の帯・テストの見出しより上も使わない
    gochu_y = None
    for r in rows:
        if re.match(r'^\s*語\s*注', r['tj']) and r['first']['x'] < 0.25 * W:
            gochu_y = r['y']
            break
    banner_y = 0
    for r in rows:
        if re.search(r'S\s*T\s*E\s*P|まとめのテスト|総合問題', clean_ja(r['tj'])) and r['y'] < 0.3 * H and (r['first']['x'] > 0.3 * W or 'まとめ' in clean_ja(r['tj']) or '総合' in clean_ja(r['tj'])):
            banner_y = max(banner_y, r['y1'])
    rows = [r for r in rows if r['y'] > banner_y and (gochu_y is None or r['y'] < gochu_y - 4)]
    end_y = (gochu_y - 10) if gochu_y else bottom
    if not rows:
        return [], {'L': 180, 'xmax': W - 60, 'W': W, 'H': H, 'bottom': bottom}
    # 左端（大問ラベルの x）: 数字で始まる行の最小 x。無ければ全体の最小
    cand = sorted(r['first']['x'] for r in rows if re.match(r'^\s*[1-9]', r['first']['t']) and r['first']['x'] < 0.25 * W)
    if not cand:
        cand = sorted(r['first']['x'] for r in rows if len(r['first']['t'].strip()) >= 2 and r['first']['x'] < 0.25 * W)
    L = cand[0] if cand else 180
    # 行の種類
    for r in rows:
        first = r['first']
        ft = norm_markers(first['t'])
        tjc = clean_ja(norm_markers(r['tj']))
        r['kind'] = 'content'
        r['mnum'] = None
        if first['x'] <= L + 18 and parse_label(ft) and not MARK_RE.match(ft.strip()) and not JUNK1_RE.match(ft) or (
                first['x'] <= L + 18 and re.match(r'^\s*[1-9]\s*$', first['t'])):
            r['kind'] = 'label'
            continue
        # 本文の x より左の語（番号の列）
        left = [l for l in r['lines'] if l['x'] <= L + 58]
        if left:
            lt = norm_markers(left[0]['t'])
            m = MARK_RE.match(lt.strip())
            if m:
                r['kind'] = 'marker'
                r['mnum'] = int(m.group(1)) if m.group(1) != '?' else None
            elif JUNK1_RE.match(lt) and not re.match(r'^\s*[(（]?\s*[いlI|!1]\s*[)）]?\s*[でにをかがはのともへ]', lt):
                r['kind'] = 'marker'
                r['mnum'] = 1
            elif JUNKN_RE.match(lt) and len(r['lines']) >= 1 and (len(lt) > 4 or len(r['lines']) > 1):
                r['kind'] = 'marker'
                r['mnum'] = None
            elif first['x'] <= L + 58:
                r['kind'] = 'wrap'  # 見出しの折り返し（番号の列から始まる本文）
            else:
                r['kind'] = 'content'
        else:
            # ラベルが読めなかった大問の見出し（〈…〉や「次の」で始まる本文位置の行）
            if re.match(r'^[〈く<][^〉>]{1,16}[〉>]|^次の', tjc) and first['x'] <= L + 140:
                r['kind'] = 'label'
                r['guess'] = True
    # 大問ごとにまとめる
    items = []
    cur = None
    for r in rows:
        if r['kind'] == 'label':
            if cur:
                cur['end'] = r['y'] - 8
            if r.get('guess'):
                prev = items[-1]['label'] if items else '0'
                mm = re.match(r'\d', prev)
                lab = str(int(mm.group(0)) + 1) if mm else '?'
            else:
                lab, _ = parse_label(norm_markers(r['first']['t']))
            cur = {'label': lab, 'y': r['y'], 'rows': [r], 'end': end_y}
            items.append(cur)
        elif cur is not None:
            cur['rows'].append(r)
        elif r['first']['x'] <= L + 140 and len(r['lines']) >= 1 and (r['kind'] == 'marker' or len(r['tj'] + r['te']) >= 8):
            # 最初のラベルより前の本文: ラベルが読めなかった 1 番目の大問（帯の数字などは除く）
            cur = {'label': '1', 'y': r['y'], 'rows': [r], 'end': end_y, 'nohead': True}
            items.append(cur)
    out = []
    for it in items:
        rs = it['rows']
        if it.get('nohead'):
            head = []
            i = 0
        else:
            head = [rs[0]]
            i = 1
            while i < len(rs) and rs[i]['kind'] == 'wrap':
                head.append(rs[i])
                i += 1
            # 本文位置から始まる見出しの折り返し（「＿に適する語を書きなさい。」のように空所で始まる行）
            if i < len(rs) and rs[i]['kind'] == 'content' and not rs[i]['te'].strip():
                tjc = clean_ja(rs[i]['tj'])
                if tjc and not re.search(r'[A-Za-z]', tjc) and re.search(r'(なさい|こと|ます|する)[。.、,]*$', tjc) and len(tjc) <= 40:
                    head.append(rs[i])
                    i += 1
        body_rows = rs[i:]
        if not body_rows:
            continue
        lab = it['label']
        hj = norm_markers(' '.join(r['tj'] for r in head))
        m = re.match(r'^\s*[1-9IlＩ]\s*\((\d)\)\s*[〈く<（(]', hj)
        if m and lab in '123456789' and m.group(1) in CIRC_REV:
            lab = lab + CIRC_REV[m.group(1)]
        # 語群（〔go walk …〕・ア … イ …）が大問の末尾にあれば全小問に付ける
        foot = []
        n_kata_rows = sum(1 for r in body_rows if re.match(r'^\s*[ア-オ]\s*\S', clean_ja(r['tj'])))
        while body_rows and len(body_rows) > 1:
            t = clean_ja(body_rows[-1]['tj']) or body_rows[-1]['te']
            if body_rows[-1]['kind'] != 'marker' and (re.match(r'^\s*[〔\[]', t) or (n_kata_rows == 1 and re.match(r'^\s*[ア-オ]\s*\S', t))):
                foot.insert(0, body_rows.pop())
            else:
                break
        # 2 段組み: 行の中に本文より右の番号があれば、その x で列を分ける
        right_marks = []
        for r in body_rows:
            for x, n in markers_in_row(r):
                if x > L + 58 + 180:
                    right_marks.append(x)
        col_x = None
        if len(right_marks) >= 2:
            col_x = min(right_marks) - 14
        starts = []  # [y, num, row, column]
        if col_x:
            for r in body_rows:
                for x, n in markers_in_row(r):
                    starts.append([r['y'], n, r, 0 if x < col_x else 1])
            # 左列の (1) が読めていないとき
            if not any(s[3] == 0 and s[1] == 1 for s in starts) and body_rows:
                starts.insert(0, [body_rows[0]['y'], 1, body_rows[0], 0])
        else:
            for r in body_rows:
                if r['kind'] == 'marker':
                    starts.append([r['y'], r['mnum'], r, 0])
            if not starts or starts[0][0] > body_rows[0]['y'] + 8:
                starts.insert(0, [body_rows[0]['y'], 1, body_rows[0], 0])
            elif starts[0][1] is not None and starts[0][1] >= 2 and head and starts[0][0] - head[-1]['y1'] > 1.3 * PITCH:
                starts.insert(0, [head[-1]['y1'] + 6, 1, body_rows[0], 0])
            # { } で組になった小問: 番号だけの行（番号が行の中央に来る）なら、本文の行を小問数で均等に分ける
            marker_only = [s for s in starts if s[2]['kind'] == 'marker' and len(s[2]['lines']) == 1 and len(s[2]['first']['t'].strip()) <= 5]
            content = [r for r in body_rows if r not in [s[2] for s in marker_only]]
            n_groups = len(starts)
            if marker_only and len(marker_only) == len([s for s in starts if s[2]['kind'] == 'marker']) and content and len(content) % n_groups == 0:
                per = len(content) // n_groups
                starts = [[content[i * per]['y'], starts[i][1], content[i * per], 0] for i in range(n_groups)]
            else:
                # 番号行の少し上（0.62 ピッチ以内）の本文行（本文の列で始まるもの）を同じ小問に入れる
                for s in starts[1:]:
                    above = [r for r in body_rows if r['kind'] in ('content', 'wrap') and 0 < s[0] - r['y'] <= 0.62 * PITCH and r['first']['x'] <= L + 130]
                    if above:
                        s[0] = min(r['y'] for r in above)
        foot_y = foot[0]['y'] - 8 if foot else it['end']
        subs = []
        for c in ([0, 1] if col_x else [0]):
            cs = sorted([s for s in starts if s[3] == c], key=lambda s: s[0])
            for k, s in enumerate(cs):
                y0 = s[0] - 10
                y1 = (cs[k + 1][0] - 10) if k + 1 < len(cs) else foot_y
                srows = [r for r in body_rows if y0 <= r['y'] < y1]
                x0 = max(0, L - 28) if c == 0 else col_x - 10
                x1 = (col_x - 12) if (col_x and c == 0) else None
                subs.append({'y0': y0, 'y1': y1, 'rows': srows, 'num': s[1], 'x0': x0, 'x1': x1})
        # 2 段組みで番号が全部読めていれば番号順（(1) long (2) easy のように行ごとに左右へ振られている）
        if col_x and all(s['num'] is not None for s in subs) and len({s['num'] for s in subs}) == len(subs):
            subs.sort(key=lambda s: s['num'])
        head_y0 = (head[0]['y'] - 10) if head else max(0, body_rows[0]['y'] - 12)
        head_y1 = (min(s['y0'] for s in subs) - 2) if head else head_y0
        header_text = clean_ja(norm_markers(' '.join(r['tj'] for r in head)))
        header_text = re.sub(r'^\s*[1-9IlＩ]\s*(?:[A-Z]\s*[—－‐\-~〜～ーew]+\s*[A-F](?![A-Za-z])|[A-F](?![a-z])|\(\d\)|(?:\s*[・･.。,、]\s*[1-9])+)?\s*', '', header_text)
        out.append({'label': lab, 'hy0': head_y0, 'hy1': head_y1, 'subs': subs, 'header': header_text, 'x0': max(0, L - 28), 'end': it['end'],
                    'foot': (foot[0]['y'] - 8, foot[-1]['y1'] + 10) if foot else None, 'body_rows': body_rows, 'L': L, 'twocol': bool(col_x)})
    xmax = max([l['x1'] for l in body], default=W - 60)
    return out, {'L': L, 'xmax': min(W - 12, xmax + 16), 'W': W, 'H': H, 'bottom': bottom}


def resplit_subs(it, n):
    """小問数が答えの数 n と合わないとき、行の並びから切り直す。できなければ None"""
    if it['twocol']:
        return None
    rows = list(it['body_rows'])
    content = [r for r in rows if not (r['kind'] == 'marker' and len(r['lines']) == 1 and len(r['first']['t'].strip()) <= 4)]
    foot_y = it['foot'][0] if it['foot'] else it['end']
    starts = None
    if content and len(content) % n == 0 and len(content) // n <= 4:
        per = len(content) // n
        starts = [content[i]['y'] for i in range(0, len(content), per)]
    else:
        # 日本文で始まる行（直前が英文の行）を小問の先頭とみなす
        cand = []
        prev_en = False
        for r in content:
            tjc = clean_ja(r['tj'])
            ja_first = bool(tjc) and has_ja(tjc[:2].replace('(', '').replace(')', '').replace('1', ''))
            if r['kind'] == 'marker' or (ja_first and prev_en) or r is content[0]:
                cand.append(r['y'])
            prev_en = not ja_first and bool(r['te'] or re.match(r'^[A-Za-z]', tjc))
        if len(cand) == n:
            starts = cand
    if not starts:
        return None
    subs = []
    for k, y in enumerate(starts):
        y0 = y - 10
        y1 = (starts[k + 1] - 10) if k + 1 < len(starts) else foot_y
        subs.append({'y0': y0, 'y1': y1, 'rows': [r for r in it['body_rows'] if y0 <= r['y'] < y1], 'num': k + 1, 'x0': it['x0'], 'x1': None})
    return subs


def sub_text(sub):
    x1 = sub.get('x1') or 10 ** 6
    ls = [l for r in sub['rows'] for l in r['lines'] if sub['x0'] - 5 <= l['x'] < x1]
    en = ' '.join(l['t'] for l in ls if l['lang'] == 'en-US')
    ja = ' '.join(l['t'] for l in ls if l['lang'] == 'ja')
    t = en if len(en) >= 6 else clean_ja(ja)
    t = norm_markers(t)
    t = re.sub(r'^\s*\(\d{1,2}\)\s*', '', t)
    return re.sub(r'\s+', ' ', t).strip()[:300]


def choice_count_of(rows):
    """行にある ア イ ウ エ オ の数（記号選択の選択肢数）"""
    s = ' '.join(l['t'] for r in rows for l in r['lines'] if l['lang'] == 'ja')
    s = s.replace('工', 'エ').replace('ェ', 'エ')
    n = 0
    for i, k in enumerate('アイウエオ'):
        if re.search(r'(^|[\s(〔])' + k + r'(\s|$)', s):
            n = i + 1
    return n


# ---------------- 解答編 ----------------
TAG_RE = re.compile(r'^\s*[PpＰ]\s*[.,．，。、\s]*\s*(\d{1,3})\b')


def parse_answer_book(pagesA):
    """解答編をタグ（P.5 STEP 1）ごとの区間に分ける。各区間: 箱の行（【解説】の前）と解説の行（後）。ページをまたぐ"""
    stream = []
    for pg in sorted(pagesA):
        d = pagesA[pg]
        W, H = d['w'], d['h']
        ja = [l for l in lines_of(d, 'ja') if l['x'] >= 100 and 0.03 * H < l['y'] < 0.95 * H]
        en = [l for l in lines_of(d, 'en-US') if l['x'] >= 100 and 0.03 * H < l['y'] < 0.95 * H]
        bottom = page_bottom(d, ja, en)
        body = [l for l in ja + en if l['y'] < bottom]
        cand = sorted(l['x'] for l in body if l['y'] > 0.06 * H and len(l['t'].strip()) >= 1)
        L = cand[0] if cand else 230
        rows = rows_of(body)
        for r in rows:
            r['pg'] = pg
            first = r['first']
            r['L'] = L
            r['bottom'] = bottom
            r['W'] = W
            ft = norm_markers(first['t'])
            m = TAG_RE.match(first['t']) or TAG_RE.match(r['tj']) or TAG_RE.match(r['te'])
            if m and first['x'] <= L + 60:
                r['kind'] = 'tag'
                r['page'] = int(m.group(1))
                r['step'] = 1 if re.search(r'STEP\s*[1１①]', r['tj'] + r['te']) else 2 if re.search(r'STEP\s*[2２②]', r['tj'] + r['te']) else None
            elif re.match(r'^\s*[【\[［]\s*解\s*説', r['tj']):
                r['kind'] = 'kaisetsu'
            elif first['x'] <= L + 12 and parse_label(ft) and not MARK_RE.match(ft.strip()) and not JUNK1_RE.match(ft) and r['y'] > 0.06 * H:
                r['kind'] = 'elabel'  # 解説の大問ラベル（箱の左より外側）
            elif first['x'] <= L + 60 and parse_label(ft) and not MARK_RE.match(ft.strip()) and not JUNK1_RE.match(ft):
                r['kind'] = 'blabel'  # 箱の大問ラベル
            else:
                r['kind'] = 'text'
            stream.append(r)
    sections = []
    cur = None
    for r in stream:
        if r['kind'] == 'tag':
            cur = {'page': r['page'], 'step': r['step'], 'tag': r, 'box': [], 'exp': [], 'in_exp': False}
            sections.append(cur)
        elif cur is None:
            continue
        elif r['kind'] == 'kaisetsu':
            cur['in_exp'] = True
        elif cur['in_exp']:
            cur['exp'].append(r)
        else:
            cur['box'].append(r)
    return sections


def box_row_text(r):
    """箱の 1 行の答えの文字列（英語だけなら en-US、日本語を含めば ja を詰めたもの）"""
    tj, te = r['tj'], r['te']
    if has_ja(re.sub(r'[〔〕（）]', '', tj)) or len(te) < 3:
        return norm_markers(clean_ja(tj))
    t = norm_markers(te)
    # en-US は行頭の大問ラベルが落ちることがあるので、ja の行頭のラベルを補う
    if r['kind'] == 'blabel' and not parse_label(t):
        pl = parse_label(norm_markers(r['first']['t']))
        if pl:
            t = pl[0] + ' ' + t
    return t


def split_answers(text):
    """'1A〜C (1) has (2) buy …' → (ラベル or None, [(番号 or None, 答え), ...])"""
    text = norm_markers(text).strip()
    label = None
    pl = parse_label(text)
    if pl and not MARK_RE.match(text) and not JUNK1_RE.match(text):
        label, text = pl
        text = text.strip()
    parts = []
    ms = list(MARK_RE.finditer(text))
    lead = text[: ms[0].start()].strip() if ms else text.strip()
    lead = re.sub(r'^[。.、,〇○:：]+', '', lead).strip()
    if lead and label is not None and (not ms or ms[0].group(1) != '1'):
        parts.append((1, lead))
        lead = ''
    for i, m in enumerate(ms):
        end = ms[i + 1].start() if i + 1 < len(ms) else len(text)
        num = int(m.group(1)) if m.group(1) != '?' else None
        t = text[m.end():end].strip()
        if not t or re.fullmatch(r'[\d〇○。.,、:：\s]+', t):
            continue
        parts.append((num, t))
    return label, parts, lead


def numbering_ok(g):
    """大問の答えの番号が 1 から順に並んでいる（読めない番号は飛ばす）か。途中で 1 に戻れば別の大問が混ざっている"""
    last = 0
    for _, num, _ in g:
        if num is None:
            continue
        if num <= last and num == 1 and last > 0:
            return False
        if num < last:
            return False
        last = num
    return True


# ---------------- 答えの形式 ----------------
KATA = {'ア': 0, 'イ': 1, 'ウ': 2, 'エ': 3, 'オ': 4, '工': 3, 'ェ': 3, 'ァ': 0, 'ィ': 1, 'ゥ': 2}


def classify(ans):
    """答えの文字列 → (kind, answers, answer_index, 整えた答え)。kind: choice / typed / self"""
    a = ans.strip().rstrip('。.、').strip()
    if len(a) == 1 and a in KATA:
        return 'choice', None, KATA[a], 'アイウエオ'[KATA[a]]
    if not a or has_ja(a):
        return 'self', None, None, a
    if re.search(r'[.?!]$', a) or len(a) > 40:
        return 'self', None, None, a
    blanks = [b.strip() for b in re.split(r'\s*/\s*|,\s+', a) if b.strip()]
    if not blanks or len(blanks) > 3:
        return 'self', None, None, a
    answers = []
    for b in blanks:
        m = re.match(r"^([A-Za-z'’\- ]+?)\s*[\[〔(]\s*([A-Za-z'’\- ]+?)\s*[\]〕)]\s*$", b)
        if m:
            alts = [m.group(1).strip(), m.group(2).strip()]
            # 「won't[will not]」は別解。「(e)s」「(the)」のような短い括弧は別解ではない
            if len(alts[1]) <= 2 or not alts[0]:
                return 'self', None, None, a
        else:
            if not re.fullmatch(r"[A-Za-z'’\-]+(?: [A-Za-z'’\-]+){0,2}", b):
                return 'self', None, None, a
            alts = [b]
        if any(len(x.split()) > 3 for x in alts):
            return 'self', None, None, a
        answers.append([x.replace('’', "'") for x in alts])
    return 'typed', answers, None, a


# ---------------- 画像 ----------------
def crop(doc, pg, x0, y0, x1, y1, W, H):
    page = doc[pg]
    rect = page.rect
    sx, sy = rect.width / W, rect.height / H
    clip = pymupdf.Rect(max(0, x0) * sx, max(0, y0) * sy, min(W, x1) * sx, min(H, y1) * sy)
    pix = page.get_pixmap(dpi=200, clip=clip)
    return Image.open(io.BytesIO(pix.tobytes('png'))).convert('RGB')


def stack(images, gap=10):
    w = max(im.width for im in images)
    h = sum(im.height for im in images) + gap * (len(images) - 1)
    out = Image.new('RGB', (w, h), (255, 255, 255))
    y = 0
    for i, im in enumerate(images):
        out.paste(im, (0, y))
        y += im.height
        if i < len(images) - 1:
            line = Image.new('RGB', (w, 2), (225, 228, 232))
            out.paste(line, (0, y + gap // 2 - 1))
            y += gap
    return out


def save_jpg(im, path):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    if im.width > 1500:
        im = im.resize((1500, int(im.height * 1500 / im.width)), Image.LANCZOS)
    im.save(path, 'JPEG', quality=82, optimize=True)


# ---------------- 答えの箱を大問に分ける ----------------
def group_box(box_rows, expected):
    """箱の行 → 大問ごとの [(row, num, text), ...]。(1) で始まる行を大問の切れ目とする。
    返り値: (groups, ok_flags)。大問数が合わないときは小問数で順に切る"""
    groups = []
    for r in box_rows:
        label, parts, lead = split_answers(box_row_text(r))
        if label is not None or not groups:
            groups.append([])
            if lead and label is None:
                parts.insert(0, (1, lead))
                lead = ''
        if lead and groups[-1]:
            # ラベルの無い行の行頭の文字列は、前の答えの折り返し
            pr, pn, pt = groups[-1][-1]
            groups[-1][-1] = (pr, pn, (pt + ' ' + lead).strip())
        for num, t in parts:
            groups[-1].append((r, num, t))
    groups = [g for g in groups if g]
    if len(groups) == len(expected):
        return groups, [len(g) == n and numbering_ok(g) for g, n in zip(groups, expected)]
    # 順に小問数で切る
    seq = [e for g in groups for e in g]
    out = []
    pos = 0
    flags = []
    for n in expected:
        g = seq[pos:pos + n]
        out.append(g)
        flags.append(len(g) == n and (not g or g[0][1] in (1, None)) and numbering_ok(g))
        pos += n
    if pos != len(seq):
        flags = [False] * len(expected)
    return out, flags


# ---------------- main ----------------
def main():
    os.makedirs(OUT_DIR, exist_ok=True)
    pagesQ = load(OCR_Q)
    pagesA = load(OCR_A)
    docQ = pymupdf.open(PDF_Q)
    docA = pymupdf.open(PDF_A)
    sections = parse_answer_book(pagesA)
    report = []
    questions = []
    no = 0
    written = set()
    n_bad = 0
    for sec in sections:
        page = sec['page']
        ch = chapter_of(page)
        if ch is None or page not in pagesQ:
            report.append(f'!! P.{page}: 章が不明か問題編に無い')
            continue
        step = sec['step']
        if ch['kind'] == 'chapter' and step is None:
            step = 1
        section_label = f'STEP {step}' if ch['kind'] == 'chapter' else ('まとめのテスト' if ch['kind'] == 'test' else '総合問題')
        items, geo = parse_question_page(pagesQ[page], page)
        expected = [len(it['subs']) for it in items]
        groups, flags = group_box(sec['box'], expected)
        # 合わない大問は答えの数で切り直してみる
        for k, it in enumerate(items):
            if not flags[k] and groups[k] and len(groups) == len(items):
                n = len(groups[k])
                subs = resplit_subs(it, n)
                if subs:
                    it['subs'] = subs
                    flags[k] = True
                    expected[k] = n
        labels = [it['label'] for it in items]
        bad = [labels[k] for k in range(len(items)) if not flags[k]]
        n_bad += len(bad)
        report.append(f"P.{page} {section_label}: 大問 {labels} 小問数 {expected} 答え {[len(g) for g in groups]} {'OK' if not bad else '!! ' + str(bad)}")
        if bad:
            for k in range(len(items)):
                if not flags[k]:
                    report.append('   ' + labels[k] + ': ' + ' | '.join(f'{n}:{t[:18]}' for (_, n, t) in groups[k]))
        elabels = [r for r in sec['exp'] if r['kind'] == 'elabel']
        if len(elabels) != len(items):
            report.append(f'   解説ラベル {len(elabels)} 個（大問 {len(items)}）→ 解説は区間全体')
            elabels = []
        for k, it in enumerate(items):
            g = groups[k] if flags[k] else []
            # 解答画像: その大問の答えの行（合わないときは箱全体）
            rows = sorted({id(x[0]): x[0] for x in g}.values(), key=lambda r: (r['pg'], r['y'])) if flags[k] else sec['box']
            it['answer_image'] = None
            if rows:
                ims = []
                for pg in sorted({r['pg'] for r in rows}):
                    rs = [r for r in rows if r['pg'] == pg]
                    d = pagesA[pg]
                    y0 = max(0, min(r['y'] for r in rs) - 12)
                    y1 = min(d['h'], max(r['y1'] for r in rs) + 14)
                    x0 = max(0, rs[0]['L'] + 8)
                    x1 = min(d['w'] - 10, max(r['x1'] for r in sec['box']) + 24)
                    ims.append(crop(docA, pg, x0, y0, x1, y1, d['w'], d['h']))
                fn = f'a{page:03d}_{k}.jpg'
                save_jpg(stack(ims), os.path.join(IMG_DIR, fn))
                it['answer_image'] = fn
            # 解説画像
            it['explanation_image'] = None
            exp_rows = sec['exp']
            if elabels:
                y_from = (elabels[k]['pg'], elabels[k]['y'])
                y_to = (elabels[k + 1]['pg'], elabels[k + 1]['y']) if k + 1 < len(elabels) else None
                exp_rows = [r for r in sec['exp'] if (r['pg'], r['y']) >= y_from and (y_to is None or (r['pg'], r['y']) < y_to)]
            if exp_rows:
                ims = []
                for pg in sorted({r['pg'] for r in exp_rows}):
                    rs = [r for r in exp_rows if r['pg'] == pg]
                    d = pagesA[pg]
                    y0 = max(0, min(r['y'] for r in rs) - 12)
                    y1 = min(d['h'], max(r['y1'] for r in rs) + 14)
                    x0 = max(0, rs[0]['L'] - 20)
                    x1 = min(d['w'] - 10, max(r['x1'] for r in sec['exp']) + 24)
                    ims.append(crop(docA, pg, x0, y0, x1, y1, d['w'], d['h']))
                fn = f'e{page:03d}_{k}.jpg'
                save_jpg(stack(ims), os.path.join(IMG_DIR, fn))
                it['explanation_image'] = fn
            # 問題画像は大問ごと（見出し〜語群まで丸ごと。画面では大問の小問をまとめて出題する）
            g_end = it['foot'][1] if it['foot'] else max([s['y1'] for s in it['subs']] + [it['hy1']])
            fn_g = f'g{page:03d}_{k}.jpg'
            save_jpg(crop(docQ, page, it['x0'], it['hy0'], geo['xmax'], g_end, geo['W'], geo['H']), os.path.join(IMG_DIR, fn_g))
            written.add(fn_g)
            nchoice_item = max([choice_count_of(s['rows']) for s in it['subs']] + [choice_count_of([r for r in it['body_rows']])])
            for j, sub in enumerate(it['subs']):
                no += 1
                ans = g[j][2] if (flags[k] and j < len(g)) else None
                kind, answers, aidx, ans_clean = classify(ans) if ans is not None else ('self', None, None, None)
                nchoice = choice_count_of(sub['rows']) or nchoice_item or 4
                fn = fn_g
                label_sub = f'{it["label"]} ({j + 1})'
                questions.append({
                    'no': no,
                    'chapterNo': ch['no'],
                    'chapter': ch['name'],
                    'theme': page,
                    'format': 'image',
                    'formatLabel': {'choice': '記号選択', 'typed': '記入', 'self': '記述'}[kind],
                    'importance': section_label,
                    'instruction': it['header'] or None,
                    'question': sub_text(sub) or label_sub,
                    'choices': ['ア', 'イ', 'ウ', 'エ', 'オ'][:max(2, nchoice)] if kind == 'choice' else None,
                    'answerIndex': aidx,
                    'answerText': ans_clean,
                    'answers': answers,
                    'answerNote': None,
                    'missing': [],
                    'unneeded': [],
                    'translation': None,
                    'source': f'p.{page} {section_label}・{label_sub}',
                    'note': None,
                    'explanation': None,
                    'explanationPage': None,
                    'problemImage': f'first_step/{fn}',
                    'answerImage': f'first_step/{it["answer_image"]}' if it['answer_image'] else None,
                    'explanationImage': f'first_step/{it["explanation_image"]}' if it['explanation_image'] else None,
                    'choiceCount': max(2, nchoice) if kind == 'choice' else None,
                })
    for fn in os.listdir(IMG_DIR):
        if fn[0] in 'qg' and fn not in written:
            os.remove(os.path.join(IMG_DIR, fn))
    with open(OUT_JSON, 'w', encoding='utf-8') as f:
        json.dump({'books': [{'key': 'first_step', 'name': 'ファーストステップ', 'questions': questions}]}, f, ensure_ascii=False, indent=1)
    kinds = {}
    for q in questions:
        kinds[q['formatLabel']] = kinds.get(q['formatLabel'], 0) + 1
    report.append(f'合計 {len(questions)} 問 {kinds} 答えが付かなかった大問 {n_bad}')
    with open(os.path.join(OUT_DIR, 'REPORT.txt'), 'w', encoding='utf-8') as f:
        f.write('\n'.join(report))
    with open(os.path.join(OUT_DIR, 'answers.txt'), 'w', encoding='utf-8') as f:
        for q in questions:
            f.write(f"{q['no']}\t{q['source']}\t{q['formatLabel']}\t{q['answerText']}\t{q['answers']}\t{q['question'][:60]}\n")
    print(report[-1])


if __name__ == '__main__':
    main()
