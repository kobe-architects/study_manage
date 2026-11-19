# Focus Gold 全ページ PDF → 例題のみ PDF（参考: focus_gold_AⅠ_例題のみ.pdf。ページはそのまま、例題の枠より下を白く塗る）
# 使い方: python build.py <book: bc|n2>
import json, os, re, sys
import cv2
import numpy as np
import pymupdf

BOOK = sys.argv[1]
BASE = r'C:\Apache24\htdocs\study_manage\docs\other\261119'
SCR = os.path.dirname(os.path.abspath(__file__))
CONF = {
    'bc': dict(src='focus_gold_BC.pdf', out='focus_gold_BC_例題のみ.pdf', tpl=[24, 25, 27, 103, 195, 199, 475],  # 右ページは章によって帯の位置と綴じ目側の陰りが違うので複数
               # 章ごとの例題番号（例題のタイトル一覧 p.14〜17 より）
               chapters=[('B1.', 1, 66), ('B2.', 1, 16), ('C1.', 1, 41), ('C1.', 42, 81), ('C2.', 1, 47), ('C2.', 48, 84)]),
    'n2': dict(src='focus_gold_Ⅱ.pdf', out='focus_gold_Ⅱ_例題のみ.pdf', tpl=[25, 26],
               chapters=[('', 1, 34), ('', 35, 66), ('', 67, 120), ('', 121, 152), ('', 153, 178), ('', 179, 217), ('', 218, 245)]),
}[BOOK]
DPI = 100
DARK = 140


def gray(doc, i):
    pix = doc[i].get_pixmap(dpi=DPI, colorspace=pymupdf.csGRAY)
    return np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width)


def bar_cands(g):
    """「例 題 N」の黒い帯の候補 [(y0, y1, x0, x1)]。
    濃い画素を 9×25 で閉じて白文字を埋め、19×19 で開いて細い罫線と本文を消した連結成分のうち
    幅 0.14〜0.65W・左端 ≤0.35W・上端 0.04〜0.3H のもの（右隣の題名や上の Think、枠の縦線がくっつくことがある）。
    成分の左端 0.08W 分の行ごとの充填率が 0.85 以上の行の連続（長さ 23〜38px）を帯の行とする
    （まとめの帯は 47px で連続が長すぎるので除かれる）"""
    h, w = g.shape
    m = (g < DARK).astype(np.uint8)
    m = cv2.morphologyEx(m, cv2.MORPH_CLOSE, np.ones((9, 25), np.uint8))
    m = cv2.morphologyEx(m, cv2.MORPH_OPEN, np.ones((19, 19), np.uint8))
    n, lab, stats, _ = cv2.connectedComponentsWithStats(m, connectivity=8)
    out = []
    for k in range(1, n):
        x, y, bw, bh, area = stats[k]
        if not (23 <= bh <= 130) or not (w * 0.14 <= bw <= w * 0.65):
            continue
        if x > w * 0.35 or not (h * 0.04 <= y <= h * 0.3):
            continue
        prof = m[y:y + bh, x:x + int(w * 0.08)].mean(axis=1) >= 0.85
        s = None
        for yy in range(bh + 1):
            on = yy < bh and prof[yy]
            if on and s is None:
                s = yy
            elif not on and s is not None:
                if 23 <= yy - s <= 38:
                    # 帯のすぐ下は枠の中（白）。まとめの「まとめ 1」の黒い块は文字の行で run が切れても下に黒が続くので除く
                    below = m[y + yy + 1:y + yy + 4, x:x + int(w * 0.08)]
                    if below.size and below.mean() >= 0.3:
                        s = None
                        continue
                    # 左端も詰める: 帯の行での列ごとの充填率が 0.85 以上になる最初の列（枠の縦線や Think がくっついた分を除く）
                    # 閉じたマスク m では枠の縦線と帯の隙間が埋まるので、生の濃淡で見る。
                    # 帯の行で 0.7 以上濃い列が 6 列続く最初の位置（枠の縦線は 1〜2 列なので飛ばす）
                    cols = (g[y + s:y + yy, x:x + int(w * 0.1)] < DARK).mean(axis=0) >= 0.7
                    dx = 0
                    for cx in range(len(cols) - 6):
                        if cols[cx:cx + 6].all():
                            dx = cx
                            break
                    out.append((int(y + s), int(y + yy - 1), int(x + dx), int(x + bw - 1)))
                    break
                s = None
    return out


def box_bottom(g, bar):
    """枠の下端（下罫線のすぐ下の行）。
    (1) 枠の左の縦罫線を追う: 帯の左端 -3〜+7 の細い窓で、枠内の背景より 40 暗い画素が 2 列以上ある行が
        帯の直下から続く run の終わり（枠内の本文は 8px 以上右から始まるので窓に入らない）。
    (2) 見つからなければ横罫線: 背景より 20 暗い画素を横 40px で開いて長い横線だけ残し、6 行の窓で幅の 80% 以上になる最初のかたまり。"""
    h, w = g.shape
    y0, y1, bx0, bx1 = bar
    bg = int(np.median(g[y1 + 6:y1 + 30, bx0 + 8:bx0 + 300]))
    cnt = (g[:, max(0, bx0 - 3):bx0 + 8] < bg - 40).sum(axis=1)
    s = None
    for y in range(y1 + 1, min(h, y1 + 360)):
        if cnt[y] >= 2:
            if s is None:
                s = y
        else:
            if s is not None:
                if s <= y1 + 4 and y - 1 - s >= 20:
                    return int(y - 1 + 2), 'v'
                if s <= y1 + 4:
                    break
            s = None
    x0 = bx0 + 4
    x1 = min(w - 2, bx0 + int(w * 0.7))
    W = x1 - x0
    ink = (g[:, x0:x1] < bg - 20).astype(np.uint8)
    m = cv2.morphologyEx(ink, cv2.MORPH_OPEN, np.ones((1, 40), np.uint8))
    c2 = m.sum(axis=1)
    win = np.convolve(c2, np.ones(6), 'same')
    for y in range(y1 + 8, min(h - 12, y1 + 360)):
        if win[y] >= 0.8 * W:
            rows = [yy for yy in range(y, min(h - 12, y + 12)) if c2[yy] >= 0.2 * W]
            return int((rows[-1] if rows else y + 3) + 2), 'h'
    return None, 'none'


src = pymupdf.open(os.path.join(BASE, CONF['src']))

# テンプレート: 帯の左端 38px（「例 題」）。左右ページで帯の位置が違うので 2 ページ分
TPLS = []
for tp in CONF['tpl']:
    tg = gray(src, tp)
    cands = bar_cands(tg)
    assert cands, 'template bar not found %d' % tp
    y0, y1, x0, x1 = sorted(cands)[0]
    TPLS.append(tg[y0:y1 + 1, x0:x0 + 38])


def score(g, bar):
    y0, y1, x0, x1 = bar
    region = g[max(0, y0 - 4):y1 + 5, max(0, x0 - 4):x0 + 46]
    scs = [float(cv2.matchTemplate(region, t, cv2.TM_CCOEFF_NORMED).max()) for t in TPLS
           if region.shape[0] >= t.shape[0] and region.shape[1] >= t.shape[1]]
    return max(scs) if scs else -1


pages = []
rejected = []
for i in range(src.page_count):
    g = gray(src, i)
    cands = bar_cands(g)
    if not cands:
        continue
    best = max(((score(g, c), c) for c in cands), key=lambda t: t[0])
    # テンプレート一致度のしきい値 0.40（本物の最低は 0.42。まとめの帯・章扉は 0.37 以下）
    if best[0] < 0.40:
        rejected.append((i, round(best[0], 3)))
        continue
    bar = best[1]
    bottom, how = box_bottom(g, bar)
    pages.append({'page': i, 'bar': bar, 'score': round(best[0], 3), 'bottom': bottom, 'how': how, 'h': g.shape[0], 'w': g.shape[1]})

json.dump(pages, open(os.path.join(SCR, BOOK + '_pages.json'), 'w', encoding='utf-8'), ensure_ascii=False)
print('detected', len(pages), 'no bottom', [p['page'] for p in pages if p['bottom'] is None],
      'how', {k: sum(1 for p in pages if p['how'] == k) for k in ('v', 'h', 'none')})
print('top rejected scores', sorted(rejected, key=lambda t: -t[1])[:8])
print('tall boxes (>300px)', [(p['page'], p['bottom'] - p['bar'][0]) for p in pages if p['bottom'] and p['bottom'] - p['bar'][0] > 300])
print('low score', [(p['page'], p['score']) for p in pages if p['score'] < 0.5])
print('box height range', min(p['bottom'] - p['bar'][0] for p in pages if p['bottom']), max(p['bottom'] - p['bar'][0] for p in pages if p['bottom']))

# 元のしおり（章・節）と章ごとの件数の確認
toc = src.get_toc()
chap_starts = [(t[2] - 1, t[1]) for t in toc if t[0] == 1]
chapters = [(p, title) for p, title in chap_starts if title.startswith('第')]
assert len(chapters) == len(CONF['chapters']), (chapters, CONF['chapters'])
page_idx = [p['page'] for p in pages]
ok = True
for ci, (cp, title) in enumerate(chapters):
    nxt = chap_starts[[p for p, _ in chap_starts].index(cp) + 1][0]
    cnt = sum(1 for p in page_idx if cp <= p < nxt)
    pre, a, b = CONF['chapters'][ci]
    exp = b - a + 1
    print('%-40s %3d / %3d %s' % (title, cnt, exp, '' if cnt == exp else '<<< mismatch'))
    if cnt != exp:
        ok = False
if not ok:
    print('COUNT MISMATCH: stop (no output written)')
    sys.exit(1)


def whiteout_below(doc, pg, y_disp_pt):
    """表示上の y_disp_pt（上から。回転後の座標）より下を白く塗る。
    PyMuPDF の draw_rect は MediaBox が [0 415 583 830] のページで位置がずれるので、
    PDF のユーザー座標で矩形を計算し、内容ストリームを 1 本追加する。/Rotate 90 では表示の縦方向 = ユーザー座標の x 方向"""
    mb = [float(v) for v in re.findall(r'[-\d.]+', doc.xref_get_key(pg.xref, 'MediaBox')[1])]
    x0, y0, x1, y1 = mb
    rot = pg.rotation
    # 端の描画のにじみが残らないよう、ページの外側へ 2pt はみ出させる（ページ外は表示されない）
    if rot == 90:
        r = (x0 + y_disp_pt, y0 - 2, x1 + 2, y1 + 2)
    elif rot == 270:
        r = (x0 - 2, y0 - 2, x1 - y_disp_pt, y1 + 2)
    elif rot == 180:
        r = (x0 - 2, y0 + y_disp_pt, x1 + 2, y1 + 2)
    else:
        r = (x0 - 2, y0 - 2, x1 + 2, y1 - y_disp_pt)
    if r[2] <= r[0] or r[3] <= r[1]:
        return
    stream = ('q 1 1 1 rg %.2f %.2f %.2f %.2f re f Q' % (r[0], r[1], r[2] - r[0], r[3] - r[1])).encode()
    xref = doc.get_new_xref()
    doc.update_object(xref, '<<>>')
    doc.update_stream(xref, stream)
    kind, val = doc.xref_get_key(pg.xref, 'Contents')
    if kind == 'array':
        refs = val.strip('[]').strip()
    else:
        refs = val
    doc.xref_set_key(pg.xref, 'Contents', '[%s %d 0 R]' % (refs, xref))


# 出力 PDF
out = pymupdf.open()
new_index = {}
for k, p in enumerate(pages):
    out.insert_pdf(src, from_page=p['page'], to_page=p['page'])
    pg = out[-1]
    new_index[p['page']] = k
    bottom = p['bottom'] if p['bottom'] is not None else p['bar'][1] + 260
    whiteout_below(out, pg, (bottom + 6) * 72.0 / DPI)

# しおり: 章 → 節 → 例題 N
new_toc = []
num_by_page = {}
for ci, (cp, title) in enumerate(chapters):
    nxt = chap_starts[[p for p, _ in chap_starts].index(cp) + 1][0]
    pre, a, b = CONF['chapters'][ci]
    n = a
    for p in page_idx:
        if cp <= p < nxt:
            num_by_page[p] = '%s%d' % (pre, n)
            n += 1
l1_pages = [p for p, _ in chap_starts]
for lvl, title, pno in toc:
    sp = pno - 1
    if lvl == 1:
        end = min([q for q in l1_pages if q > sp], default=src.page_count)
    else:
        later = [t[2] - 1 for t in toc if t[2] - 1 > sp]
        end = min(later, default=src.page_count)
    inside = [p for p in page_idx if sp <= p < end]
    if not inside:
        continue
    new_toc.append([lvl, title.strip(), new_index[inside[0]] + 1])
    if lvl == 2:
        for p in inside:
            new_toc.append([3, '例題 ' + num_by_page[p], new_index[p] + 1])
out.set_toc(new_toc)
out.set_metadata({'title': os.path.splitext(CONF['out'])[0]})
dst = os.path.join(BASE, CONF['out'])
out.save(dst, garbage=3, deflate=True)
print('wrote', dst, out.page_count, 'pages', os.path.getsize(dst) // 1024 // 1024, 'MB', 'toc', len(new_toc))
