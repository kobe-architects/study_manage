# Focus Gold 数学Ⅰ+A 例題: build_fg.py のページ構成 JSON と、ページ画像から読み取った答えの JSON（text/fg_*.json）と、
# 既存の問題集データ（backend/database/data/focus_gold_1a.json: 章・題名・難易度・Check）を合流して学習カードの JSON を作る。
#   python merge_fg.py <build_fg の出力先ディレクトリ>
# 検査は merge_ss.py と同じ（誤答 3 つが互いに異なり正解と重複しない。満たさない小問は落として partial）。
import glob, json, os, re, sys

DATA_DIR = r'C:\Apache24\htdocs\study_manage\backend\database\data'
KEY = 'fg_1a'
DECK = {
    'key': KEY, 'subject': 'math', 'name': 'Focus Gold 数学Ⅰ+A 例題', 'shortName': 'FG 数ⅠA', 'studentOnly': False,
    'resourceBookTitle': 'Focus Gold 数学I+数学A 例題', 'sortOrder': 9,
}


def norm(s):
    return ' '.join(str(s).split()).strip()


def cmpkey(s):
    return norm(s).replace('\u2212', '-').lower()


def main():
    src = sys.argv[1]
    with open(os.path.join(src, f'{KEY}_pages.json'), encoding='utf-8') as fp:
        pages = {int(p['no']): p for p in json.load(fp)}
    rows = []
    for f in sorted(glob.glob(os.path.join(src, 'text', 'fg_*.json'))):
        with open(f, encoding='utf-8') as fp:
            rows.extend(json.load(fp))
    by_no = {int(r['no']): r for r in rows}
    with open(os.path.join(DATA_DIR, 'focus_gold_1a.json'), encoding='utf-8') as fp:
        book = json.load(fp)
    meta = {int(it['seqNo']): it for it in book['items']}
    chapters = []
    for it in book['items']:
        ch = re.sub(r'^(第\d+章)\s*', r'\1 ', norm(it['chapter']))
        if ch not in chapters:
            chapters.append(ch)
    warn = []
    if set(by_no) != set(pages):
        warn.append(f'件数不一致: pages={len(pages)} text={len(by_no)} 差={sorted(set(pages) ^ set(by_no))}')
    items = []
    structure = []
    n_subs = n_partial = n_self = 0
    for no in sorted(pages):
        pg = pages[no]
        r = by_no.get(no)
        m = meta.get(no)
        if r is None or m is None:
            warn.append(f'no {no}: 答え or 問題集データがない')
            continue
        chapter = re.sub(r'^(第\d+章)\s*', r'\1 ', norm(m['chapter']))
        chapter_no = chapters.index(chapter) + 1
        title = norm(m.get('title') or r.get('title') or '')
        subs = []
        partial = bool(r.get('skipped'))
        for s in r.get('subs') or []:
            ans = norm(s.get('answer', ''))
            opts = []
            seen = {cmpkey(ans)}
            for o in s.get('options') or []:
                o = norm(o)
                if not o or cmpkey(o) in seen:
                    continue
                seen.add(cmpkey(o))
                opts.append(o)
            if not ans or len(opts) < 3:
                warn.append(f'no {no} {s.get("label")}: 選択肢が足りない（{len(opts)}）→ 4 択にしない')
                partial = True
                continue
            subs.append({'label': s.get('label') or None, 'answer': ans, 'options': opts[:3]})
        if subs:
            n_subs += len(subs)
            if partial:
                n_partial += 1
        else:
            n_self += 1
        label = f'例題 {no}'
        extra = {'label': label, 'title': title, 'difficulty': m.get('difficulty') or None, 'check': bool(m.get('check')),
                 'problemPages': pg['fullPages'][:1], 'answerPages': pg['fullPages']}
        if subs:
            extra['subs'] = subs
        if subs and partial:
            extra['partial'] = True
        if r.get('skipped'):
            extra['skipped'] = r['skipped']
        if r.get('note'):
            extra['note'] = r['note']
        items.append({
            'no': no, 'chapterNo': chapter_no, 'chapter': chapter,
            'section': (title + (f"　{m['difficulty']}" if m.get('difficulty') else '')) or None,
            'kind': 'problem', 'front': label, 'back': None, 'extra': extra,
            'page': pg['fullPages'][0], 'bookPage': pg['bookPage'],
            'problemImage': pg['problemImage'], 'answerImage': pg['answerImage'],
        })
        if not structure or structure[-1][0] != chapter_no:
            structure.append([chapter_no, chapter, no, no])
        else:
            structure[-1][3] = no
    out = os.path.join(DATA_DIR, f'cards_{KEY}.json')
    with open(out, 'w', encoding='utf-8') as fp:
        json.dump({'deck': DECK | {'structure': structure}, 'items': items}, fp, ensure_ascii=False, indent=1)
    print(f'{KEY}: {len(items)} 枚（4 択 {len(items) - n_self}・うち一部だけ {n_partial}・自己判定 {n_self}・小問 {n_subs}）→ {out}')
    for w in warn:
        print('   !!', w)


if __name__ == '__main__':
    main()
