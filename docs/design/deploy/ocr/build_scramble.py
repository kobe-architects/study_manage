# スクランブル英文法・語法: OCR（ocr_scramble.py の出力）から 語い・イディオム・会話表現・発音・アクセント・読解作文 の問題を
# 文字データ（choice / fill）に起こし、英文法・語法（Excel 由来の既存データ）は OCR と突き合わせて差分を報告する。
#   python -I build_scramble.py <scratch> [--books vocab,idiom,...] [--write]
# 出力: <scratch>/out/scr/<book>.json（取り込み形式の questions）、REPORT_<book>.txt（1 問ずつの結果と要確認）、
#       --write で backend/database/data/scramble_grammar.json を更新（文字に起こせた問題だけ置き換え、他は旧データのまま）
import json, os, re, statistics, sys

import cv2

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import scr_lib as L  # noqa: E402
import scr_parse as P  # noqa: E402

ROOT = r'C:\Apache24\htdocs\study_manage'
DATA = os.path.join(ROOT, 'backend', 'database', 'data', 'scramble_grammar.json')

# book: (見開きの範囲, 連番の範囲)
PARTS = {
    'grammar': (25, 100, 1, 473),
    'usage': (102, 140, 474, 725),
    'vocab': (142, 168, 726, 887),
    'idiom': (169, 206, 888, 1306),
    'conversation': (208, 218, 1307, 1365),
    'pronunciation': (220, 238, 1, 184),
    'special': (240, 249, 1, 34),
}
BLANK_RE = re.compile(r'\(\s*(?:[A-Za-z]{1,2}-)?\s*\)')
CIRC = '①②③④⑤⑥⑦⑧⑨'


def circ_index(t):
    t = t.strip()
    if t and t[0] in CIRC:
        return CIRC.index(t[0])
    m = re.fullmatch(r'[(（]?\s*([1-9])\s*[)）]?', t)
    return int(m.group(1)) - 1 if m else None


# ---------------------------------------------------------------- 解答欄
def _norm_group(t):
    t = t.strip().replace('，', ',').replace('．', '.').replace('　', ' ')
    t = re.sub(r'\s*,\s*', ', ', t)
    t = re.sub(r'(?<=[A-Za-z])\.\s*(?=[a-z])', ', ', t)  # 「set. in」「refer.to」= カンマの誤読
    t = re.sub(r'\s+', ' ', t)
    return t.strip()


def _pick_group(rapid_t, win_t):
    """同じ括弧の中身の 2 つの読み（RapidOCR / Windows）から良い方。空白を除いて同じなら空白の少ない方。
    RapidOCR 側に英字が 2 文字も無い（「（ g」など崩れた読み）なら Windows 側"""
    a = _norm_group(rapid_t)
    b = _norm_group(win_t) if win_t else ''
    if b and a.replace(' ', '').lower() == b.replace(' ', '').lower():
        return b if b.count(' ') < a.count(' ') else a
    if b and not re.search(r'[A-Za-z]{2,}', a) and re.search(r'[A-Za-z]{2,}', b) and not L.CJK.search(b):
        return b
    return a


def _num_end_x(row, numstr, after_x=0):
    """行の中で番号 numstr の右端の x（丸数字を探す範囲の始まり）。after_x より右で最初に見つかるもの（1 行に 3 つ並ぶ発音の解答欄など）。
    RapidOCR の文字枠を順に見て、数字の並びが numstr と一致する所を探す"""
    digits = numstr
    # Windows OCR が数字だけの単語として読んだ位置があればそれ（RapidOCR は 1 行に並ぶ 3 問を 1 枠に読むことがある）
    for x, x1, t in row.get('nums', []):
        if t == digits and x1 > after_x:
            return x1
    for it in sorted(row.get('items', []), key=lambda i: i['ax']):
        chars = it.get('chars') or []
        if chars:
            seq = ''.join(c[0] if c[0].isdigit() else ' ' for c in chars)
            start = 0
            while True:
                k = seq.find(digits, start)
                if k < 0:
                    break
                before_ok = k == 0 or not seq[k - 1].isdigit()
                after_ok = k + len(digits) >= len(seq) or not seq[k + len(digits)].isdigit()
                x_end = it['ax'] + chars[k + len(digits) - 1][2]
                if before_ok and after_ok and x_end > after_x:
                    return x_end
                start = k + 1
        else:
            t = it['text'].replace(' ', '')
            if (t.startswith(digits) or t.lstrip('(（').startswith(digits)) and it['ax'] + 20 * len(digits) + 8 > after_x:
                return it['ax'] + 20 * len(digits) + 8
    return max(after_x + 60, row['x'] + 20 * len(digits) + 8)


def parse_answer_rows(rows, nos, img=None, reader=None):
    """
    解答欄の行 → { no: { 'groups': [括弧の中身...], 'labels': ['A','B',...] or None, 'ja': 和訳 } }
    行の例: 「726 （②） フランス語の辞書なら…」「888 (turn, out) 889 (in) …」「1307 A. (How) 1308 A. (up [new]) B. (special)」
            「1 (④) [æ] 他は [eɪ]」「(1) ( ③ ) 彼はあなたを…」
    番号の誤読は、同じ行の中で前の番号 + 1 に直す。番号の無い行（和訳の続き）は前の番号の和訳に足す。
    """
    out = {}
    nos_set = set(nos)
    last = None
    last_y1 = None
    for r in rows:
        text = r['text'].replace('（', '(').replace('）', ')')
        ja = r['ja'].replace('（', '(').replace('）', ')')
        # 番号の位置（「726」「(1)」。括弧つき番号は特別編）
        heads = [m for m in re.finditer(r'(?<![\w.,])\(?\s*(\d{1,4})\s*\)?(?=\s*(?:[A-B]\s*[.．]\s*)?\()', text)]
        if not heads:
            heads = [m for m in re.finditer(r'(?<![\w.,])(\d{1,4})(?![\w.,])', text)]
            heads = [m for m in heads if int(m.group(1)) in nos_set]
        if not heads:
            # 番号の無い行 = 直前の和訳の続き（すぐ下で、字下げされている行だけ）
            if last is not None and ja.strip() and last_y1 is not None and r['y'] - last_y1 < 45 and r['x'] > 260:
                out[last]['ja'] = (out[last]['ja'] + ' ' + ja.strip()).strip()
                last_y1 = r['y1']
            continue
        segs = []
        for i, m in enumerate(heads):
            end = heads[i + 1].start() if i + 1 < len(heads) else len(text)
            segs.append((int(m.group(1)), text[m.end():end]))
        # 番号の補正（行の中で連続しているはず）
        fixed = []
        prev = None
        for n, seg in segs:
            if n not in nos_set and prev is not None and prev + 1 in nos_set:
                n = prev + 1
            fixed.append((n, seg))
            prev = n
        # 和訳（Windows OCR ja）: 先頭の「番号 ( … )」（丸数字の誤読「（ツ）」「2）」なども）を取り除く
        ja_body = re.sub(r'^[^\u3040-\u30ff\u3400-\u9fff(（A-Za-z]*', '', ja).strip()
        ja_body = re.sub(r'^[(（][^()（）]{0,4}[)）]\s*', '', ja_body).strip()
        ja_body = re.sub(r'^[(（]?\s*[0-9０-９ーツヨ工朝ョ・\s]{1,3}[)）つ]\s*', '', ja_body).strip()
        ja_body = re.sub(r'^[^\u3040-\u30ff\u3400-\u9fff(（A-Za-z「]*', '', ja_body).strip()
        ja_body = re.sub(r'^\([^()]*[A-Za-z][^()]*\)\s*', '', ja_body).strip()  # 「(sharp)」の読み（空所補充の答え）
        ja_body = re.sub(r'^[^)）(（「]{1,3}[)）]\s*', '', ja_body).strip()  # 丸数字の読みかす「いに)」
        ja_groups = re.findall(r'\(([^()]*)\)', ja)
        gi = 0
        prev_x = 0
        # 1 行に複数の問題（発音の解答欄「1 (④)… 2 (④)… 3 (①)…」）: 行全体の丸数字を左から順に読んで番号に対応づける
        row_all = []
        if len(fixed) >= 2 and img is not None and GLYPHS.ready():
            row_all = GLYPHS.classify_all(img, r['x'], P.X_RIGHT, r['y'] - 6, r['y1'] + 6)
            if len(row_all) != len(fixed):
                row_all = []
        for k, (n, seg) in enumerate(fixed):
            groups = re.findall(r'\(([^()]*)\)', seg)
            labels = re.findall(r'([AB])\s*[.．]\s*\(', seg)
            picked = []
            for g in groups:
                w = ja_groups[gi] if gi < len(ja_groups) else ''
                gi += 1
                picked.append(_pick_group(g, w))
            if n not in nos_set:
                continue
            # 丸数字が読めていない（空・数字のかけら）ときはテンプレート照合で読む
            picked = [g for g in picked if g.strip().lower() not in ('a', 'b')]  # 「(a)」「(b)」のラベル
            if not picked:
                m2 = re.search(r'[①②③④⑤⑥]', seg[:12])
                if m2:
                    picked = [m2.group(0)]
            # 丸数字（選択の正解）は字形の照合（GLYPHS）を優先。RapidOCR が読んだ丸数字と食い違えば DIFF として記録
            is_word = bool(picked) and bool(re.search(r'[A-Za-z]{2,}', picked[0]))
            low = {}
            if not is_word and img is not None and GLYPHS.ready():
                if row_all:
                    _, idx, sc, mg = row_all[k]
                    xs = row_all[k][0]
                else:
                    xs = _num_end_x(r, str(n), prev_x)
                    idx, sc, mg = GLYPHS.classify(img, xs - 2, xs + 130, r['y'] - 6, r['y1'] + 6)
                prev_x = xs + 40
                rapid_idx = circ_index(picked[0]) if picked else None
                if idx is not None and sc >= 0.6:
                    tag = 'tpl'
                    if rapid_idx is not None and rapid_idx != idx:
                        tag = f'DIFF rapid={CIRC[rapid_idx]}'
                    elif mg < 0.03:
                        tag = 'LOWMARGIN'
                    if picked and not is_word:
                        picked[0] = CIRC[idx]
                    else:
                        picked.insert(0, CIRC[idx])
                    low = {'rec': f'{tag} {sc:.2f}/{mg:.2f}'}
                elif rapid_idx is None:
                    low = {'rec': 'NONE'}
            out[n] = {'groups': picked, 'labels': labels or None, 'ja': ja_body if (k == len(fixed) - 1 and len(fixed) == 1) else '', **low}
            last = n
            last_y1 = r['y1']
    return out


_WORDS = None


def is_word(w):
    """英単語らしいか（wordninja の語彙）。固有名詞・活用形は無いことがあるので目安"""
    global _WORDS
    if _WORDS is None:
        try:
            import wordninja
            _WORDS = set(wordninja.DEFAULT_LANGUAGE_MODEL._wordcost.keys())
        except Exception:
            _WORDS = set()
    if not _WORDS:
        return True
    toks = re.findall(r"[A-Za-z']+", w)
    if not toks:
        return False
    return all((len(t) >= 2 or t in ('a', 'I')) and (t.lower().strip("'") in _WORDS or t.lower().strip("'").rstrip('s') in _WORDS or t.lower().endswith("'s")) for t in toks)


def _fix_il(t):
    """OCR の I と l の取り違え（「Iine」→「line」）: 辞書にある方へ"""
    if is_word(t):
        return t
    cands = [t.replace('I', 'l'), t.replace('l', 'I'), t[:1] + t[1:].replace('I', 'l')]
    for c in cands:
        if c != t and is_word(c):
            return c
    return t


def _collapse(t):
    """「se n d」「fro m」= 1 語の中に入った空白を詰める（詰めると辞書にある語のとき）。複数語は隣どうしで試す"""
    t = (t or '').strip()
    if not t:
        return t
    if ' ' in t and not is_word(t) and is_word(t.replace(' ', '')):
        return t.replace(' ', '')
    ws = t.split()
    out = []
    i = 0
    while i < len(ws):
        if i + 1 < len(ws) and not (is_word(ws[i]) and is_word(ws[i + 1])) and is_word(ws[i] + ws[i + 1]):
            out.append(ws[i] + ws[i + 1])
            i += 2
        else:
            out.append(ws[i])
            i += 1
    return ' '.join(out)


def answers_from_groups(groups, n_blanks):
    """「turn, out」「stayed [sat]」→ 空所ごとの正答候補 [[...], ...]。空所の数と合わなければ None（1 つなら共通語として許す）"""
    if not groups:
        return None
    parts = []
    for g in groups:
        g = g.strip()
        if not g:
            continue
        if g[0] in CIRC:
            return None
        for p in re.split(r'\s*,\s*', g):
            p = p.strip()
            if not p:
                continue
            alts = []
            m = re.match(r'^(.*?)\s*[\[［]\s*(.*?)\s*[\]］]\s*(.*)$', p)
            if m:
                g1, g2, g3 = (_collapse(m.group(k)) for k in (1, 2, 3))
                base = (g1 + ' ' + g3).strip()
                alt = (g1 + ' ' + g2 + ' ' + g3).strip() if g1 or g3 else g2
                alts = [base, alt] if base else [g2]
                # 「stayed [sat]」= stayed / sat（1 語の言い換え）
                if g1 and not g3 and ' ' not in g2:
                    alts = [g1.strip(), g2.strip()]
            else:
                alts = [x.strip() for x in p.split('/') if x.strip()]
            alts = [_fix_il(_collapse(re.sub(r'[\s]+', ' ', a))) for a in alts if re.search(r'[A-Za-z]', a)]
            if alts:
                parts.append(alts)
    if not parts:
        return None
    return parts


# ---------------------------------------------------------------- 発音の下線
def underline_marks(img, item, x0):
    """選択肢の単語（RapidOCR の枠。chars は crop 内の x）の下線を探し、下線のある文字を ⟨ ⟩ で囲んだ文字列を返す。無ければ素の文字列"""
    chars = item.get('chars') or []
    word = ''.join(c[0] for c in chars) if chars else item['text']
    # 文字枠が文字列と合わない（枠が欠けている）ときは印を付けずに文字列をそのまま
    if not chars or word.replace(' ', '') != item['text'].replace(' ', ''):
        return item['text'], False
    g = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    ax, ax1 = item['ax'], item['ax1']
    band = g[item['ay1'] - 3:item['ay1'] + 16, max(0, ax - 4):ax1 + 4] < 160
    best = None
    for yy in range(band.shape[0]):
        runs, _ = L._runs(band[yy])
        runs = [(s0, l0) for s0, l0 in runs if l0 >= 16]
        if not runs:
            continue
        total = sum(l for _, l in runs)
        if best is None or total > best[0]:
            best = (total, runs)
    if not best:
        return word, False
    ranges = [(s0 + ax - 4, s0 + l0 + ax - 4) for s0, l0 in best[1]]
    flags = []
    for ch, cx0, cx1 in chars:
        cx0 += x0
        cx1 += x0
        mid = (cx0 + cx1) / 2
        flags.append(any(r0 - 3 <= mid <= r1 + 3 for r0, r1 in ranges))
    if not any(flags) or all(flags):
        return word, False
    out = ''
    i = 0
    while i < len(chars):
        if flags[i]:
            j = i
            while j < len(chars) and flags[j]:
                j += 1
            out += '⟨' + ''.join(c[0] for c in chars[i:j]) + '⟩'
            i = j
        else:
            out += chars[i][0]
            i += 1
    return out, True


# ---------------------------------------------------------------- ブロック → 問題
def parse_block(book, rows, img):
    """行 → { instruction, question, choices, note, source, blanks, kind }"""
    sent = [r for r in rows if r['kind'] == 'en' and not r.get('choice') and r['text']]
    choice_rows = [r for r in rows if r.get('choice')]
    ja = [r for r in rows if r['kind'] == 'ja' and r['text']]
    note = next((r['note'] for r in rows if r['note']), None)
    source = next((r['source'] for r in rows if r['source']), None)
    res = {'note': note, 'source': source, 'choices': None, 'choices_ok': False, 'instruction': None, 'question': ''}
    if book == 'conversation':
        label = None
        segs = []
        inst = []
        for r in rows:
            if r['kind'] == 'ja':
                m = re.match(r'^\W*([AB])\s*[:：・.]?\s*(.*)$', r['text'])
                if m:
                    label = m.group(1)
                    inst.append(f'{label}: {m.group(2).strip()}')
                elif r['text']:
                    inst.append(r['text'])
            elif r['text']:
                if segs and segs[-1][0] == label and not segs[-1][2]:
                    segs[-1][1] += ' ' + r['text']
                else:
                    segs.append([label, r['text'], False])
                # 直前の和文に対応する英文が 2 行になる場合のため、和文を見た後の最初の英文であることを記録
                segs[-1][2] = False
        res['instruction'] = '　'.join(inst) if inst else None
        res['question'] = ' '.join(f'{lb}: {t}' if lb else t for lb, t, _ in segs)
    else:
        res['instruction'] = '　'.join(r['text'] for r in ja) if ja else None
        res['question'] = ' '.join(r['text'] for r in sent)
        if choice_rows:
            if book == 'pronunciation':
                # 単語ごとに下線を調べる
                items = []
                for r in choice_rows:
                    for it in r['items']:
                        t = P.L.spaced_text(it)
                        if re.fullmatch(r'[①②③④⑤⑥1-6]', t.strip()) or L.CJK.search(t) or not re.search(r'[A-Za-z]', t):
                            continue  # 番号だけ・出典（大学名）・記号は選択肢ではない
                        items.append(it)
                words = []
                any_ul = False
                for it in items:
                    w, ul = underline_marks(img, it, P.X_LEFT)
                    w = re.sub(r'^[①②③④⑤⑥1-6]\s*', '', w)
                    if w.strip():
                        words.append(w.strip())
                        any_ul = any_ul or ul
                res['choices'] = words
                res['choices_ok'] = 3 <= len(words) <= 5
                res['underline'] = any_ul
            else:
                ch, ok = P.choices_from_rows(choice_rows)
                res['choices'] = ch
                res['choices_ok'] = ok
    res['question'] = re.sub(r'\s+', ' ', res['question']).strip()
    # 下線部の同意語を選ぶ問題（空所が無く選択肢がある）: 下線の語に印を付ける（発音と同じ ⟨ ⟩）
    if book in ('vocab', 'idiom', 'conversation') and res['choices_ok'] and not BLANK_RE.search(res['question']):
        for r in sent:
            for it in r.get('items', []):
                if not it.get('chars'):
                    continue
                w, ul = underline_marks(img, it, P.X_LEFT)
                if not ul:
                    continue
                for m in re.finditer(r'⟨([^⟩]+)⟩', w):
                    word = m.group(1)
                    if len(word) >= 3 and word in res['question'] and '⟨' + word not in res['question']:
                        res['question'] = res['question'].replace(word, '⟨' + word + '⟩', 1)
                        break
    if res['instruction']:
        res['instruction'] = re.sub(r'^\d{1,4}\s*[、,.．・:：]?\s*', '', res['instruction']).strip() or None  # 行頭に残った問題番号
    res['blanks'] = len(BLANK_RE.findall(res['question']))
    return res


def build_question(book, no, old, blk, ans, imp, report):
    """OCR の結果と解答を 1 問のデータにまとめる。文字に起こせなければ None（旧データのまま）"""
    q = dict(old)
    q['source'] = blk['source'] or old.get('source')
    if imp:
        q['importance'] = imp
    question = blk['question']
    if blk['note']:
        question = f'〔{blk["note"]}〕 ' + question
    choices = blk['choices'] if blk['choices_ok'] else None
    groups = ans['groups'] if ans else []
    idx = circ_index(groups[0]) if groups else None
    translation = (ans or {}).get('ja') or None
    if translation:
        body = re.sub(r'\s', '', translation)
        ja_n = len(re.findall(r'[\u3040-\u30ff\u3400-\u9fff]', body))
        if ja_n < 3 or ja_n < len(body) * 0.4:
            translation = None  # 和訳でなく英語の答えの読みかす（会話表現の解答欄など）
    if translation:
        translation = re.sub(r'\s+', '', translation) if not re.search(r'[A-Za-z]{2,}', translation) else translation
    # 1 文字だけの単語のかけら（「took K me」「ensure e that」= 隣の単語の端の二重読み）を除く
    question = re.sub(r'(?<=\b(\w))\s+\1\s+(?=\w)', ' ', question) if False else question
    toks = question.split(' ')
    cleaned = []
    for i, t in enumerate(toks):
        if len(t) == 1 and t.isalpha() and t not in ('I', 'a', 'A'):
            prev = toks[i - 1] if i > 0 else ''
            nxt = toks[i + 1] if i + 1 < len(toks) else ''
            if (prev and prev[-1].lower() == t.lower()) or (nxt and nxt[0].lower() == t.lower()):
                continue
        cleaned.append(t)
    question = ' '.join(cleaned)
    if book == 'special':
        question = re.sub(r'^\(\s*\d{1,2}\s*\)\s*', '', question).strip()
    if (not question or len(question) < 4) and not (choices and book == 'pronunciation'):
        report.append(f'{book} {no}: 問題文が読めない → 旧データ')
        return None
    if choices and idx is not None and idx < len(choices):
        label = '選択'
        if blk['note'] and ('適切でない' in blk['note'] or '不適' in blk['note']):
            label = '不適切選択'
        elif blk['note'] and '同じ意味' in blk['note']:
            label = '同意語選択'
        q.update({
            'format': 'choice', 'formatLabel': label, 'instruction': blk['instruction'], 'question': question,
            'choices': choices, 'answerIndex': idx, 'answerText': choices[idx], 'answers': None, 'answerNote': None,
            'missing': [], 'unneeded': [], 'translation': translation, 'note': None, 'choiceCount': None,
        })
        return q
    if blk['blanks'] >= 1:
        answers = answers_from_groups(groups, blk['blanks'])
        if answers:
            label = '空所補充'
            if blk['note'] and '共通' in blk['note']:
                label = '共通語補充'
            bad = [a for alts in answers for a in alts if not is_word(a)]
            if bad:
                report.append(f'{book} {no}: CHECKWORD 答えが辞書に無い {bad}')
            q.update({
                'format': 'fill', 'formatLabel': label, 'instruction': blk['instruction'], 'question': question,
                'choices': None, 'answerIndex': None, 'answerText': ', '.join(' / '.join(a) for a in answers), 'answers': answers, 'answerNote': None,
                'missing': [], 'unneeded': [], 'translation': translation, 'note': None, 'choiceCount': None,
            })
            return q
        report.append(f'{book} {no}: 空所 {blk["blanks"]} に対して解答が合わない {groups} → 旧データ')
        return None
    if choices:
        report.append(f'{book} {no}: 選択肢は読めたが正解が読めない {groups} → 旧データ')
    else:
        report.append(f'{book} {no}: 形式が分からない（選択肢 {blk["choices"]}, 空所 0, 解答 {groups}）→ 旧データ')
    return None


# ---------------------------------------------------------------- アクセント（2 列の単語と音節番号）
def parse_accent_page(d, img, expected, max_no, split):
    """アクセントのページ: 左右 2 列に「35 ma·gi·cian / ① ② ③」。ページ全体の RapidOCR の枠から、番号の右の単語と下の音節番号を取る"""
    h, w = img.shape[:2]
    limit = split if split else h - 150
    cands = [c for c in L.number_candidates(d, w) if c[1] < w * 0.62 and 18 <= c[4] <= 60 and c[0] < limit - 20 and expected <= c[2] <= max_no]
    by_no = {}
    for y, x, n, src, hh in cands:
        by_no.setdefault(n, []).append((y, x))
    boxes = d.get('rapid', [])
    out = []
    for n in sorted(by_no):
        y, x = sorted(by_no[n])[0]
        x0, x1 = x + 55, x + 780
        words = [b for b in boxes if x0 <= b[0] <= x1 and abs((b[1] + b[3]) / 2 - (y + 18)) <= 28 and re.search(r'[A-Za-z]', b[4])]
        marks = [b for b in boxes if x0 <= b[0] <= x1 and 30 <= (b[1] + b[3]) / 2 - (y + 18) <= 80 and re.fullmatch(r'[①②③④⑤⑥⑦1-7\s()]+', b[4].strip())]
        if not words:
            continue
        word = ' '.join(b[4] for b in sorted(words, key=lambda b: b[0]))
        word = re.sub(r'\s*[·・•]\s*', '・', word).strip('・ ')
        word = re.sub(r'\s+', '', word)
        syl = [t for t in word.split('・') if t]
        nmarks = sum(len(re.findall(r'[①②③④⑤⑥⑦]|\d', b[4])) for b in marks)
        out.append((n, y, word, syl, nmarks))
    return out


# ---------------------------------------------------------------- 本体
READER = L.Circled()
GLYPHS = L.CircledGlyphs()


def run(book, old_by, write_report):
    s0, s1, n0, n1 = PARTS[book]
    report = []
    built = {}
    expected = n0
    accent_nos = {no for (b, no), q in old_by.items() if b == 'pronunciation' and q.get('chapterNo') == 31}
    # 丸数字の字形テンプレート: 先に数ページの選択肢の行から集めておく（解答欄の照合に使う）
    if not GLYPHS.ready():
        exp0 = n0
        for s in range(s0, min(s1, s0 + 8) + 1):
            d = L.load_spread(s)
            img = L.page_img(s)
            if img is None:
                continue
            qs0, _, split0, _ = L.find_anchors(d['L'], img, exp0, n1)
            for i, (n, y) in enumerate(qs0):
                y1 = qs0[i + 1][1] - 25 if i + 1 < len(qs0) else ((split0 - 8) if split0 else img.shape[0] - 150)
                for r in P.block_rows(img, d['L'], y - 30, L.header_cut(d['L'], y + 20, y1), P.X_LEFT, f's{s}_{book}_{n}'):
                    if r.get('choice'):
                        GLYPHS.harvest_choice_row(img, r['y'], r['y1'], P.X_LEFT, 1600, r.get('toks'))
            if qs0:
                exp0 = qs0[-1][0] + 1
    for s in range(s0, s1 + 1):
        d = L.load_spread(s)
        img = L.page_img(s)
        imgR = L.page_img(s, 'R')
        if img is None:
            continue
        dL = d['L']
        dR = d.get('R', {})
        # アクセントのページは「Rule N」の見出し（2 列の単語と音節番号）
        is_accent = book == 'pronunciation' and (expected in accent_nos or any(r[4].strip().startswith('Rule') and r[1] < 400 for r in dL.get('rapid', [])))
        if is_accent:
            split = L.page_split(dL, img, int(img.shape[0] * 0.4))
            items = parse_accent_page(dL, img, expected, n1, split)
            qs = [(n, y) for n, y, *_ in items]
        else:
            qs, _, split, missing = L.find_anchors(dL, img, expected, n1)
            for m in missing:
                report.append(f'{book} {m}: 見開き {s} で番号が見つからない → 旧データ')
        if not qs:
            continue
        # 解答欄: 左ページの下（点線の下）。残りが右ページの下に続くこともあるので右も見る
        page_nos = [n for n, _ in qs]
        a_rows = P.answer_rows(img, dL, split, cache_key=f's{s}_Lans') if split else []
        answers = parse_answer_rows(a_rows, page_nos, img, READER)
        if imgR is not None and len(answers) < len(page_nos):
            splitR = L.page_split(dR, imgR, int(imgR.shape[0] * 0.35), set(page_nos))
            if splitR:
                r_rows = P.answer_rows(imgR, dR, splitR, x0=10, cache_key=f's{s}_Rans10')
                for n, a in parse_answer_rows(r_rows, page_nos, imgR, READER).items():
                    answers.setdefault(n, a)
        if is_accent:
            for n, y, word, syl, marks in items:
                old = old_by.get((book, n))
                if not old:
                    continue
                ans = answers.get(n)
                idx = circ_index(ans['groups'][0]) if ans and ans['groups'] else None
                choices = syl if len(syl) >= 2 else [str(i + 1) for i in range(marks)]
                if idx is None or idx >= len(choices) or len(choices) < 2:
                    report.append(f'{book} {n}: アクセント {word} 音節 {syl} 解答 {ans} → 旧データ')
                    continue
                q = dict(old)
                q.update({
                    'format': 'choice', 'formatLabel': 'アクセント', 'instruction': '最も強く発音する部分（音節）を選びなさい。', 'question': word,
                    'choices': choices, 'answerIndex': idx, 'answerText': choices[idx], 'answers': None, 'answerNote': None,
                    'missing': [], 'unneeded': [], 'translation': None, 'note': None, 'choiceCount': None,
                })
                built[n] = q
                report.append(f'{book} {n}: OK accent {word} -> {choices[idx]}')
            expected = max(page_nos) + 1
            continue
        x_left = 130 if book == 'special' else P.X_LEFT  # 特別編は番号の列が無く本文が左端から始まる
        page_blocks = []
        for i, (n, y) in enumerate(qs):
            y0 = y - 30
            y1 = qs[i + 1][1] - 25 if i + 1 < len(qs) else ((split - 8) if split else img.shape[0] - 150)
            y1 = L.header_cut(dL, y + 20, y1)
            rows = P.block_rows(img, dL, y0, y1, x_left, f's{s}_{book}_{n}' + ('_l130' if x_left != P.X_LEFT else ''))
            for r in rows:
                if r.get('choice'):
                    GLYPHS.harvest_choice_row(img, r['y'], r['y1'], P.X_LEFT, 1600, r.get('toks'))
            imp = P.importance_of(dL, y0, y1, P.X_LEFT, img)
            blk = parse_block(book, rows, img)
            old = old_by.get((book, n))
            if not old:
                # 旧データに無い番号（前回の取り込みで落ちた問題）: 直前の問題の章・Theme を引き継いで新しく作る
                prev = old_by.get((book, n - 1)) or built.get(n - 1)
                if not prev:
                    report.append(f'{book} {n}: 旧データに無い番号（章が分からない）')
                    continue
                old = {k: prev.get(k) for k in ('chapterNo', 'chapter', 'theme')}
                old.update({'no': n, 'explanationPage': prev.get('explanationPage'), 'problemImage': None, 'answerImage': None, 'explanationImage': None})
                report.append(f'{book} {n}: 旧データに無い番号 → 新規（章 {old["chapter"]}）')
            if book == 'pronunciation':
                blk['instruction'] = '下線部の発音が他と異なるものを 1 つ選びなさい。' if blk.get('underline') else (blk['instruction'] or '発音が他と異なるものを 1 つ選びなさい。')
                blk['question'] = '' if blk['choices_ok'] and len(blk['question']) < 12 else blk['question']
            if book == 'special':
                page_blocks.append((n, old, blk, imp))
                continue
            q = build_question(book, n, old, blk, answers.get(n), imp, report)
            if q:
                built[n] = q
                rec = answers.get(n, {}).get('rec')
                report.append(f'{book} {n}: OK {q["format"]}{" rec=" + str(rec) if rec else ""} | {q["question"][:70]} | {q.get("choices")} | ans={q.get("answerText")} | 訳={q.get("translation")}')
        if book == 'special' and page_blocks:
            # 特別編: ①〜⑤ の選択肢は大問で共通（最後の小問のブロックやページの下にある）。指示文（「…選びなさい。」）もページで共通
            shared = next((b['choices'] for _, _, b, _ in page_blocks if b['choices_ok']), None)
            inst = next((b['instruction'] for _, _, b, _ in page_blocks if b['instruction'] and re.search(r'選び|答え|書き|入れ', b['instruction'])), None)
            for n, old, blk, imp in page_blocks:
                if not blk['choices_ok'] and shared:
                    blk['choices'] = shared
                    blk['choices_ok'] = True
                blk['instruction'] = inst
                # 小問の行に ①〜 の選択肢の文が混ざっていたら除く（共通の選択肢として別に持つ）
                if shared:
                    for c in shared:
                        if c and c in blk['question'] and blk['question'].strip() != c:
                            blk['question'] = blk['question'].replace(c, '').strip()
                    blk['question'] = re.sub(r'\s*[①②③④⑤⑥]\s*$', '', blk['question']).strip()
                q = build_question(book, n, old, blk, answers.get(n), imp, report)
                if q:
                    built[n] = q
                    rec = answers.get(n, {}).get('rec')
                    report.append(f'{book} {n}: OK {q["format"]}{" rec=" + str(rec) if rec else ""} | {q["question"][:70]} | {q.get("choices")} | ans={q.get("answerText")} | 訳={q.get("translation")}')
        expected = qs[-1][0] + 1
    return built, report


def compare_book(book, old_by):
    """英文法・語法: 既存データ（Excel 由来）と OCR の突き合わせ。似ていない問題を報告"""
    import difflib
    s0, s1, n0, n1 = PARTS[book]
    report = []
    expected = n0
    norm = lambda t: re.sub(r'[^a-z0-9]', '', (t or '').lower().replace('①', '').replace('②', '').replace('③', '').replace('④', '').replace('⑤', ''))
    plain = lambda t: (t or '').replace('⟨', '').replace('⟩', '')
    for s in range(s0, s1 + 1):
        d = L.load_spread(s)
        img = L.page_img(s)
        qs, _, split, missing = L.find_anchors(d['L'], img, expected, n1)
        if not qs:
            continue
        for i, (n, y) in enumerate(qs):
            y1 = qs[i + 1][1] - 25 if i + 1 < len(qs) else ((split - 8) if split else img.shape[0] - 150)
            y1 = L.header_cut(d['L'], y + 20, y1)
            rows = P.block_rows(img, d['L'], y - 30, y1, P.X_LEFT, f's{s}_{book}_{n}')
            blk = parse_block(book, rows, img)
            old = old_by.get((book, n))
            if not old:
                continue
            # 問題文は問題文どうし、選択肢（整序は語句が問題文の中にあるので除く）は選択肢どうしで比べる
            q_ratio = difflib.SequenceMatcher(None, norm(plain(blk['question'])), norm(old.get('question') or ''), autojunk=False).ratio()
            c_ratio = 1.0
            if old.get('format') in ('choice', 'error') and old.get('choices'):
                c_ratio = difflib.SequenceMatcher(None, norm(' '.join(blk['choices'] or [])), norm(' '.join(old.get('choices') or [])), autojunk=False).ratio()
            ratio = min(q_ratio, c_ratio)
            flag = 'OK ' if ratio >= 0.9 else 'CHECK'
            ocr_txt = blk['question'] + ' | ' + ' / '.join(blk['choices'] or [])
            old_txt = (old.get('question') or '') + ' | ' + ' / '.join(old.get('choices') or [])
            report.append(f'{flag} q={q_ratio:.3f} c={c_ratio:.3f} {book} {n} s{s} y{y}\n   OLD: {old_txt}\n   OCR: {ocr_txt}')
        expected = qs[-1][0] + 1
    return report


FIXES = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'scr_fixes.json')


def apply_fixes(book, built):
    """目視確認で見つけた読み違いの手直し（scr_fixes.json: { book: { no: { field: value, ... } } }）。no の値が null ならその問題は旧データのまま"""
    if not os.path.exists(FIXES):
        return built
    with open(FIXES, encoding='utf-8') as f:
        fixes = json.load(f).get(book, {})
    for no_s, patch in fixes.items():
        no = int(no_s)
        if patch is None:
            built.pop(no, None)
            continue
        if no in built:
            built[no].update(patch)
    return built


def merge_from_out(data, books):
    """OCR をやり直さずに <scratch>/out/scr/<book>.json を取り込んで scramble_grammar.json を更新する（--merge）"""
    out_dir = os.path.join(L.SCRATCH, 'out', 'scr')
    for book in books:
        fn = os.path.join(out_dir, f'{book}.json')
        if not os.path.exists(fn):
            continue
        with open(fn, encoding='utf-8') as f:
            built = {q['no']: q for q in json.load(f)}
        built = apply_fixes(book, built)
        for b in data['books']:
            if b['key'] == book:
                have = {q['no'] for q in b['questions']}
                b['questions'] = [built.get(q['no'], q) for q in b['questions']] + [built[n] for n in sorted(built) if n not in have]
                b['questions'].sort(key=lambda q: q['no'])
        print(book, 'merged', len(built), flush=True)


def main():
    L.SCRATCH = sys.argv[1]
    args = sys.argv[2:]
    books = ['vocab', 'idiom', 'conversation', 'pronunciation']  # special（特別編）は ⑤ の丸数字の照合が弱く、今は画像出題のまま（--books special で試せる）
    if '--books' in args:
        books = args[args.index('--books') + 1].split(',')
    with open(DATA, encoding='utf-8') as f:
        data = json.load(f)
    old_by = {(b['key'], q['no']): q for b in data['books'] for q in b['questions']}
    out_dir = os.path.join(L.SCRATCH, 'out', 'scr')
    os.makedirs(out_dir, exist_ok=True)
    if '--merge' in args:
        merge_from_out(data, books)
        with open(DATA, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=1)
        print('written', DATA)
        return
    for book in books:
        if book in ('grammar', 'usage'):
            rep = compare_book(book, old_by)
            with open(os.path.join(out_dir, f'COMPARE_{book}.txt'), 'w', encoding='utf-8') as f:
                f.write('\n'.join(rep))
            n_chk = sum(1 for r in rep if r.startswith('CHECK'))
            print(book, 'compared', len(rep), 'check', n_chk, flush=True)
            continue
        built, rep = run(book, old_by, True)
        with open(os.path.join(out_dir, f'REPORT_{book}.txt'), 'w', encoding='utf-8') as f:
            f.write('\n'.join(rep))
        with open(os.path.join(out_dir, f'{book}.json'), 'w', encoding='utf-8') as f:
            json.dump([built[k] for k in sorted(built)], f, ensure_ascii=False, indent=1)
        total = sum(1 for (b, _) in old_by if b == book)
        print(book, 'built', len(built), '/', total, flush=True)
        if '--write' in args:
            built = apply_fixes(book, built)
            for b in data['books']:
                if b['key'] == book:
                    have = {q['no'] for q in b['questions']}
                    b['questions'] = [built.get(q['no'], q) for q in b['questions']] + [built[n] for n in sorted(built) if n not in have]
                    b['questions'].sort(key=lambda q: q['no'])
    if '--write' in args:
        with open(DATA, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=1)
        print('written', DATA)


if __name__ == '__main__':
    main()
