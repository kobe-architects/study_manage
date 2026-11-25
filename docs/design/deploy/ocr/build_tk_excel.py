# 鉄緑会 英文法の例文カード（全 42 週）を、ユーザー作成の Excel（docs/other/鉄緑会_英文法_全例文一覧.xlsx の「全例文」シート）に合わせて作り直す。
#   python build_tk_excel.py <excel.json（xlsx_rich_to_json.py の出力）> <repo> <out dir> [--write]
#   その後 build_tk_sections.py で例文カードの節の画像・page を付け直す
# - 週（カードの chapterNo）ごとに、既存の例文カード（OCR 起こし）と Excel の例文行を順番を保った最適対応（DP）で結び、
#   英文・和訳・節名・Ex. 番号・空所（原本の太字）を Excel の値に差し替える（no/id は保つ → 回答履歴は残る）
# - Excel にだけある例文は、直前の Excel 行に対応するカードの直後に挿入する
# - Excel に無い既存の例文カード（OCR が拾った番号なしの用例・書き換え文の重複など）は削除対象にする
# - セルの 2 行目以降（× の誤文・= / ⇔ の書き換え・別の言い方）は extra.notes / extra.notesJa に入れ、解答後に表示する
# - セクション内の小見出し（①予定 など。Ex. の無い行）は extra.sub に入れる
# - 囲み（phrase）カードはそのまま。束全体の no を 1 から振り直し、structure も更新する
# 出力: --write のとき cards_tetsuryoku_grammar.json を上書きし、renumber_tetsuryoku_261125b.php を書く。review.txt は常に書く
import json, re, sys, difflib

EXCEL, REPO, OUT = sys.argv[1:4]
WRITE = '--write' in sys.argv
JSON_PATH = REPO + '/backend/database/data/cards_tetsuryoku_grammar.json'
PHP_PATH = REPO + '/backend/database/data/renumber_tetsuryoku_261125b.php'

excel = json.load(open(EXCEL, encoding='utf-8'))
data = json.load(open(JSON_PATH, encoding='utf-8'))
items = data['items']
structure = data['deck']['structure']


def cell_text(c):
    if 'rich' in c:
        return ''.join(p['t'] for p in c['rich'])
    return '' if c.get('v') is None else str(c['v'])


def plain_en(s):
    return s.replace('“', '"').replace('”', '"').replace('’', "'").replace('‘', "'")


def norm(s):
    return re.sub(r'[^a-z0-9 ]', ' ', plain_en(s).lower()).split()


def first_line_with_blanks(c):
    """セルの 1 行目の英文と、太字の語の範囲（空白区切りの語インデックス）"""
    runs = c['rich'] if 'rich' in c else [{'t': cell_text(c), 'b': False}]
    text = ''.join(p['t'] for p in runs)
    bold = []
    for p in runs:
        bold += [bool(p.get('b'))] * len(p['t'])
    end = text.find('\n')
    if end < 0:
        end = len(text)
    line, bold = text[:end], bold[:end]
    spans = [(m.start(), m.end()) for m in re.finditer(r'\S+', line)]
    bw = [any(bold[a:b]) for a, b in spans]
    blanks = []
    i = 0
    while i < len(bw):
        if bw[i]:
            j = i
            while j + 1 < len(bw) and bw[j + 1]:
                j += 1
            blanks.append([i, j])
            i = j + 1
        else:
            i += 1
    return re.sub(r'\s+', ' ', plain_en(line)).strip(), blanks


# ---- Excel の例文行（週ごと） ----
sheet = [s for s in excel['sheets'] if s['title'] == '全例文'][0]
weeks = []  # [(key, [rows])]
sub = {}
for r in sheet['rows'][1:]:
    key = tuple(cell_text(c).strip() for c in r[:3])
    if not weeks or weeks[-1][0] != key:
        weeks.append((key, []))
    sec = cell_text(r[4]).strip()
    exv = r[5].get('v') if 'v' in r[5] else cell_text(r[5])
    en_all = cell_text(r[6])
    if exv in (None, ''):
        if en_all.strip():
            sub[(key, sec)] = en_all.strip()  # 小見出し（以降の同じ節の例文に付ける）
        continue
    if sub.get((key, sec)) is None:
        sub[(key, sec)] = None
    front, blanks = first_line_with_blanks(r[6])
    lines = [l.strip() for l in en_all.split('\n') if l.strip()]
    ja_lines = [l.strip() for l in cell_text(r[7]).split('\n') if l.strip()]
    weeks[-1][1].append({
        'section': sec,
        'sub': sub.get((key, sec)),
        'ex': int(exv),
        'front': front,
        'back': ja_lines[0] if ja_lines else '',
        'blanks': blanks,
        'notes': [plain_en(l) for l in lines[1:]],
        'notesJa': ja_lines[1:],
        'all_lines': [front] + [plain_en(l) for l in lines[1:]],
    })
assert len(weeks) == len(structure), (len(weeks), len(structure))


def score_line(old_front, new_front):
    """類似度: 文字列比 と「OCR 側の語が順番どおりに Excel 側に含まれる割合」（OCR が途中で切れた文用）の大きい方"""
    a, b = norm(old_front), norm(new_front)
    if not a or not b:
        return 0
    ratio = difflib.SequenceMatcher(None, a, b).ratio()
    j = hit = 0
    for w in a:
        while j < len(b) and b[j] != w:
            j += 1
        if j < len(b):
            hit += 1
            j += 1
    contain = hit / len(a) if len(a) >= 3 else 0
    return max(ratio, contain)


def contained(a, b):
    """a の語が順番どおりに b に含まれる割合"""
    j = hit = 0
    for w in a:
        while j < len(b) and b[j] != w:
            j += 1
        if j < len(b):
            hit += 1
            j += 1
    return hit / len(a) if a else 0


def norm_ja(s):
    return re.sub(r'[\s,，、。.．「」『』()（）]', '', s or '')


def score(old, row):
    # 1 行目との一致を優先（書き換え文との一致は少し割り引く）
    s0 = score_line(old['front'], row['front'])
    s1 = max([score_line(old['front'], l) * 0.9 for l in row['all_lines'][1:]] or [0])
    # OCR で書き換え文が後ろにつながったカード: Excel の 1 行目が OCR 側に順番どおり含まれる
    nb, na = norm(row['front']), norm(old['front'])
    s2 = contained(nb, na) * 0.9 if len(nb) >= 4 and na[:2] == nb[:2] else 0
    # 英文が欠けた・折り返しの後半だけのカード: 和訳で照合
    ja_o, ja_n = norm_ja(old.get('back')), norm_ja(row['back'])
    s3 = difflib.SequenceMatcher(None, ja_o, ja_n).ratio() * 0.9 if len(ja_o) >= 6 and len(ja_n) >= 6 else 0
    if s3 < 0.68:
        s3 = 0
    return max(s0, s1, s2, s3)


THR = 0.5
review = []
matched_pairs = {}  # id(old item) -> row
to_delete = []
week_rows = {}
for wi, (key, rows) in enumerate(weeks):
    ch = structure[wi][0]
    week_rows[ch] = rows
    olds = [it for it in items if it['chapterNo'] == ch and it['kind'] == 'example']
    n, m = len(olds), len(rows)
    S = [[score(o, r) for r in rows] for o in olds]
    # DP（順番を保った最大対応）
    dp = [[0.0] * (m + 1) for _ in range(n + 1)]
    bt = [[0] * (m + 1) for _ in range(n + 1)]
    for i in range(1, n + 1):
        for j in range(1, m + 1):
            best, b = dp[i - 1][j], 1
            if dp[i][j - 1] > best:
                best, b = dp[i][j - 1], 2
            s = S[i - 1][j - 1]
            if s >= THR and dp[i - 1][j - 1] + s > best:
                best, b = dp[i - 1][j - 1] + s, 3
            dp[i][j], bt[i][j] = best, b
    pairs = []
    i, j = n, m
    while i > 0 and j > 0:
        if bt[i][j] == 3:
            pairs.append((i - 1, j - 1))
            i, j = i - 1, j - 1
        elif bt[i][j] == 1:
            i -= 1
        else:
            j -= 1
    pairs.reverse()
    mo = {p[0]: p[1] for p in pairs}
    mr = {p[1]: p[0] for p in pairs}
    review.append(f"\n===== ch{ch} {structure[wi][1]} | Excel {'/'.join(key)} rows={m} old={n} matched={len(pairs)} new={m - len(pairs)} delete={n - len(pairs)}")
    for j, r in enumerate(rows):
        if j in mr:
            o = olds[mr[j]]
            s = S[mr[j]][j]
            flag = '' if s >= 0.8 else '  <<< LOW'
            review.append(f"  MATCH {s:.2f} no={o['no']:>4} | {o['front'][:70]}\n        => {r['section'][:20]} Ex.{r['ex']} {r['front'][:90]}{flag}")
        else:
            review.append(f"  NEW              | {r['section'][:20]} Ex.{r['ex']} {r['front'][:90]}")
    for oi, o in enumerate(olds):
        if oi not in mo:
            bestj = max(range(m), key=lambda j: S[oi][j]) if m else None
            hint = f" (best {S[oi][bestj]:.2f} Ex.{rows[bestj]['ex']} {rows[bestj]['front'][:50]})" if bestj is not None else ''
            review.append(f"  DELETE no={o['no']:>4} | {o['front'][:80]}{hint}")
            to_delete.append(o)
    for oi, rj in mo.items():
        matched_pairs[id(olds[oi])] = rows[rj]

# ---- 並びを作り直す ----
def new_item(ch_item, row):
    return {
        'kind': 'example', 'chapterNo': ch_item['chapterNo'], 'chapter': ch_item['chapter'], 'section': row['section'],
        'front': row['front'], 'back': row['back'], 'extra': {}, 'page': ch_item.get('page'), 'bookPage': None,
        'answerImage': None, 'no': None, 'problemImage': None,
    }


def apply_row(it, row):
    it['section'] = row['section']
    it['front'] = row['front']
    it['back'] = row['back']
    extra = dict(it.get('extra') or {})
    extra['blanks'] = row['blanks']
    extra['ex'] = row['ex']
    for k in ('notes', 'notesJa', 'sub'):
        extra.pop(k, None)
    if row['notes']:
        extra['notes'] = row['notes']
    if row['notesJa']:
        extra['notesJa'] = row['notesJa']
    if row['sub']:
        extra['sub'] = row['sub']
    it['extra'] = extra


del_ids = {id(x) for x in to_delete}
all_items = []
for s in structure:
    ch = s[0]
    seq = [it for it in items if it['chapterNo'] == ch and id(it) not in del_ids]
    rows = week_rows[ch]
    row_item = {}
    for it in seq:
        if id(it) in matched_pairs:
            row_item[id(matched_pairs[id(it)])] = it
    for it in seq:
        if id(it) in matched_pairs:
            apply_row(it, matched_pairs[id(it)])
    prev_item = None
    first_example = next((x for x in seq if x['kind'] == 'example'), None)
    for r in rows:
        if id(r) in row_item:
            prev_item = row_item[id(r)]
            continue
        if prev_item is None:
            pos = seq.index(first_example) if first_example is not None else len(seq)
        else:
            pos = seq.index(prev_item) + 1
        # 新しい節の最初の例文は、その節の囲み・表（phrase。節番号が同じ）の後ろに置く
        mnum = re.match(r'\d+\.\d+', r['section'])
        sec_no = mnum.group(0) if mnum else None
        while sec_no and pos < len(seq) and seq[pos]['kind'] == 'phrase' and re.match(r'(\d+\.\d+)', seq[pos].get('section') or '') and re.match(r'(\d+\.\d+)', seq[pos]['section']).group(1) == sec_no:
            pos += 1
        base = seq[pos - 1] if pos > 0 else (seq[0] if seq else {'chapterNo': ch, 'chapter': s[1], 'page': None})
        ni = new_item(base, r)
        apply_row(ni, r)
        seq.insert(pos, ni)
        prev_item = ni
    all_items += seq

# ---- no の振り直し ----
old_to_new = {}
for i, it in enumerate(all_items, start=1):
    if it['no'] is not None:
        old_to_new[it['no']] = i
    it['no'] = i
chapters = {}
for it in all_items:
    chapters.setdefault(it['chapterNo'], [it['no'], it['no']])
    chapters[it['chapterNo']][1] = it['no']
for s in structure:
    s[2], s[3] = chapters[s[0]]
data['items'] = all_items

n_ex = sum(1 for it in all_items if it['kind'] == 'example')
n_excel = sum(len(r) for _, r in weeks)
summary = (f"excel rows={n_excel} old examples={sum(1 for it in items if it['kind'] == 'example')} matched={len(matched_pairs)} "
           f"new={n_excel - len(matched_pairs)} delete={len(to_delete)} | total items={len(all_items)} examples={n_ex}")
low = sum(1 for l in review if '<<< LOW' in l)
with open(OUT + '/review.txt', 'w', encoding='utf-8') as f:
    f.write(summary + f" low-score matches={low}\n")
    f.write('\n'.join(review) + '\n')
print(summary, 'low', low)
assert n_ex == n_excel

if WRITE:
    json.dump(data, open(JSON_PATH, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    old_count = len(items)
    old_max = max(it_no for it_no in [x for x in old_to_new] + [d['no'] for d in to_delete])
    changed = {o: n for o, n in old_to_new.items() if o != n}
    php_map = ', '.join(f'{o}=>{n}' for o, n in sorted(changed.items()))
    del_nos = ', '.join(str(d['no']) for d in sorted(to_delete, key=lambda d: d['no']))
    php = f'''<?php
// 2026-11-25 鉄緑会 英文法の例文カード（全 42 週）を Excel（docs/other/鉄緑会_英文法_全例文一覧.xlsx）に合わせて作り直した。
// - Excel に無い例文カード（OCR が拾った番号なしの用例・書き換え文の重複など）を削除し、
// - 残るカードの no を id を保ったまま付け替えてから cards:import で取り込む（Excel にだけある例文はここで新しく入る）。
// 回答履歴・復習状態・間違いノートの紐づけは card_items.id に付いているので、残るカードでは保たれる（--reset は使わない）。
// 実行（backend で）: php database/data/renumber_tetsuryoku_261125b.php   ※ 2 回目以降は付け替えをせず取り込みだけ行う
$base = dirname(__DIR__, 2);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Illuminate\\Support\\Facades\\DB;

$delete = [{del_nos}]; // 削除する旧 no
$map = [{php_map}]; // 旧 no => 新 no（変わるものだけ）
$expectedCount = {old_count};
$expectedMax = {old_max};

$deck = DB::table('card_decks')->where('key', 'tetsuryoku_grammar')->first();
if (! $deck) {{
    echo "deck not found\\n";
    exit(1);
}}
$ids = DB::table('card_items')->where('card_deck_id', $deck->id)->pluck('id', 'no');
if ($ids->count() !== $expectedCount || (int) $ids->keys()->max() !== $expectedMax) {{
    echo "skip renumber: already applied or unexpected state (count={{$ids->count()}} max={{$ids->keys()->max()}})\\n";
}} else {{
    $delIds = collect($delete)->map(fn ($no) => $ids[$no] ?? null)->filter()->values()->all();
    $lost = DB::table('card_attempts')->whereIn('card_item_id', $delIds)->count();
    echo 'attempts on deleted cards: '.$lost."\\n";
    DB::transaction(function () use ($deck, $ids, $map, $delete, $delIds) {{
        DB::table('review_states')->where('kind', 'card')->whereIn('item_id', $delIds)->delete();
        DB::table('card_items')->whereIn('id', $delIds)->delete();
        DB::table('card_items')->where('card_deck_id', $deck->id)->update(['no' => DB::raw('no + 100000')]);
        $n = 0;
        foreach ($ids as $oldNo => $id) {{
            if (in_array($oldNo, $delete, true)) {{
                continue;
            }}
            DB::table('card_items')->where('id', $id)->update(['no' => $map[$oldNo] ?? $oldNo]);
            $n++;
        }}
        echo "deleted ".count($delIds)." cards, renumbered {{$n}} cards\\n";
    }});
}}
$code = Illuminate\\Support\\Facades\\Artisan::call('cards:import', ['--file' => $base.'/database/data/cards_tetsuryoku_grammar.json']);
echo Illuminate\\Support\\Facades\\Artisan::output();
exit($code);
'''
    open(PHP_PATH, 'w', encoding='utf-8', newline='\n').write(php)
    print('written', JSON_PATH, PHP_PATH)
