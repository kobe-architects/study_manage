# スタディサプリ ベーシックレベル数学 例題: build_ss.py のページ構成 JSON と、ページ画像から読み取った答えの JSON（text/<key>.json）を合流して
# 学習カードの JSON（backend/database/data/cards_ss_<key>.json）を作る。
#   python merge_ss.py <build_ss の出力先ディレクトリ> [<key> ...]
# 答えの JSON の形式は INSTRUCTIONS.md（no / lecture / lectureTitle / part / partTitle / label / subs[{label, answer, options[3]}] / skipped / note）。
# 検査: 件数の一致、講番号が単調（戻らない）、誤答 3 つが互いに異なり正解と重複しない（満たさない小問は落として partial にする）。
import glob, json, os, sys

OUT_DIR = r'C:\Apache24\htdocs\study_manage\backend\database\data'
DECKS = {
    'math1': ('スタディサプリ ベーシックレベル 数学Ⅰ 例題', 'スタサプ 数Ⅰ', 'スタディサプリ ベーシックレベル 数Ⅰ', 3),
    'math_a': ('スタディサプリ ベーシックレベル 数学A 例題', 'スタサプ 数A', 'スタディサプリ ベーシックレベル 数A', 4),
    'math2': ('スタディサプリ ベーシックレベル 数学Ⅱ 例題', 'スタサプ 数Ⅱ', 'スタディサプリ ベーシックレベル 数Ⅱ', 5),
    'math_b': ('スタディサプリ ベーシックレベル 数学B 例題', 'スタサプ 数B', 'スタディサプリ ベーシックレベル 数B', 6),
    'math3': ('スタディサプリ ベーシックレベル 数学Ⅲ 例題', 'スタサプ 数Ⅲ', 'スタディサプリ ベーシックレベル 数Ⅲ', 7),
    'math_c': ('スタディサプリ ベーシックレベル 数学C 例題', 'スタサプ 数C', 'スタディサプリ ベーシックレベル 数C', 8),
}


def norm(s):
    return ' '.join(str(s).split()).strip()


def cmpkey(s):
    """\u91cd\u8907\u5224\u5b9a\u7528\uff08\u30de\u30a4\u30ca\u30b9\u306e\u5b57\u5f62\u3068\u5927\u6587\u5b57\u5c0f\u6587\u5b57\u306e\u9055\u3044\u306f\u540c\u3058\u3068\u307f\u306a\u3059\uff09"""
    return norm(s).replace('\u2212', '-').lower()


def load_text(src, key):
    files = sorted(glob.glob(os.path.join(src, 'text', f'{key}.json')) + glob.glob(os.path.join(src, 'text', f'{key}_*.json')))
    rows = []
    for f in files:
        with open(f, encoding='utf-8') as fp:
            rows.extend(json.load(fp))
    rows.sort(key=lambda r: int(r['no']))
    return rows


def main():
    src = sys.argv[1]
    keys = sys.argv[2:] or list(DECKS)
    for key in keys:
        name, short, book_title, sort = DECKS[key]
        with open(os.path.join(src, f'ss_{key}_pages.json'), encoding='utf-8') as fp:
            pages = {int(p['no']): p for p in json.load(fp)}
        text = load_text(src, key)
        by_no = {int(r['no']): r for r in text}
        problems = []
        warn = []
        if set(by_no) != set(pages):
            warn.append(f'件数不一致: pages={len(pages)} text={len(by_no)} 差={sorted(set(pages) ^ set(by_no))}')
        last_lec = 0
        n_subs = n_partial = n_self = 0
        structure = []
        for no in sorted(pages):
            pg = pages[no]
            r = by_no.get(no)
            if r is None:
                warn.append(f'no {no}: 答えの JSON がない')
                continue
            lec = int(r['lecture'])
            if lec < last_lec:
                warn.append(f'no {no}: 講番号が戻っている {last_lec} → {lec}')
            last_lec = max(last_lec, lec)
            chapter = f"第{lec}講 {norm(r.get('lectureTitle', ''))}".strip()
            section = f"PART{int(r['part'])} {norm(r.get('partTitle', ''))}".strip() if r.get('part') else None
            label = f"例題 {r['label']}" if r.get('label') is not None else f'例題 {no}'
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
            extra = {
                'label': label,
                'problemPages': pg['pdfPages'][:1],
                'answerPages': pg['pdfPages'],
            }
            if subs:
                extra['subs'] = subs
            if subs and partial:
                extra['partial'] = True
            if r.get('skipped'):
                extra['skipped'] = r['skipped']
            if r.get('note'):
                extra['note'] = r['note']
            problems.append({
                'no': no,
                'chapterNo': lec,
                'chapter': chapter,
                'section': section,
                'kind': 'problem',
                'front': label,
                'back': None,
                'extra': extra,
                'page': pg['pdfPages'][0],
                'bookPage': pg['bookPage'],
                'problemImage': pg['problemImage'],
                'answerImage': pg['answerImage'],
            })
            if not structure or structure[-1][0] != lec:
                structure.append([lec, chapter, no, no])
            else:
                structure[-1][3] = no
        deck = {
            'key': f'ss_{key}', 'subject': 'math', 'name': name, 'shortName': short, 'studentOnly': False,
            'resourceBookTitle': book_title, 'structure': structure, 'sortOrder': sort,
        }
        out = os.path.join(OUT_DIR, f'cards_ss_{key}.json')
        with open(out, 'w', encoding='utf-8') as fp:
            json.dump({'deck': deck, 'items': problems}, fp, ensure_ascii=False, indent=1)
        print(f'{key}: {len(problems)} 枚（4 択 {len(problems) - n_self}・うち一部だけ {n_partial}・自己判定 {n_self}・小問 {n_subs}）→ {out}')
        for w in warn:
            print('   !!', w)


if __name__ == '__main__':
    main()
