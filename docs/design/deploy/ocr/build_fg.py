# Focus Gold 数学Ⅰ+A（スキャン PDF）→ 学習カードの画像（問題・解答）とページ構成の JSON
#   例題のみ PDF（docs/other/261118_2/focus_gold_AⅠ_例題のみ.pdf。1 ページ = 例題 1 問。例題の枠以外は白）と
#   全ページ PDF（docs/other/focus_gold_AⅠ.pdf。例題の枠 → 考え方 → 解答 → Focus → 練習）は同じスキャンなので、
#   例題のみページの枠の領域を全ページ側と画素で照合して例題 N のページを見つける（差が 0 に近いページ）。
#   問題 = 枠（例題のみページから、上の見出し帯と右端の章タブを除いて切り出し）。
#   解答 = 全ページの枠の下〜「練習」の上の罫線まで（罫線の下の左に練習のラベルがあるものだけを区切りとみなす）。
#   例題 N と N+1 の間に挟まるページは解答の続き（上の見出し帯の下〜練習の罫線まで）として縦につなぐ。
# 使い方: python build_fg.py <出力先ディレクトリ>
import json, os, sys
import cv2
import numpy as np
import pymupdf
from PIL import Image

EX_PDF = r'C:\Apache24\htdocs\study_manage\docs\other\261118_2\focus_gold_AⅠ_例題のみ.pdf'
FULL_PDF = r'C:\Apache24\htdocs\study_manage\docs\other\focus_gold_AⅠ.pdf'
KEY = 'fg_1a'
OUT = sys.argv[1] if len(sys.argv) > 1 else 'out/fg'
DPI = 200
MATCH_DPI = 40


def gray(doc, i, dpi):
    pix = doc[i].get_pixmap(dpi=dpi, colorspace=pymupdf.csGRAY)
    return np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width)


def rgb(doc, i, dpi):
    pix = doc[i].get_pixmap(dpi=dpi)
    return Image.frombytes('RGB', (pix.width, pix.height), pix.samples)


def hlines(g, x_from, x_to, min_frac=0.7, max_thick=8):
    """横罫線の [(y0, y1, x0, x1)]（g は濃淡。x_from..x_to の範囲で濃い画素が min_frac 以上の行）"""
    dark = g < 150
    seg = dark[:, x_from:x_to]
    frac = seg.mean(axis=1)
    ys = np.where(frac >= min_frac)[0]
    out = []
    for y in ys:
        if out and y - out[-1][1] <= 1:
            out[-1][1] = y
        else:
            out.append([y, y])
    res = []
    for y0, y1 in out:
        if y1 - y0 + 1 > max_thick:
            continue
        xs = np.where(dark[(y0 + y1) // 2])[0]
        res.append((y0, y1, xs.min(), xs.max()))
    return res


def ex_box(g):
    """例題のみページの枠（y0, y1, x0, x1）。
    枠の罫線は薄くて拾えないので、枠の左上の黒い帯（「例 題 N」。x 0.18〜0.26W が黒）を探して枠の上端とし、
    その下で（右端の章タブ x>0.92W を除いた）濃い画素のある最後の行を枠の下端とする"""
    h, w = g.shape
    dark = g < 150
    # 行ごとに「0.08W〜0.32W の間から始まる、長さ 0.07W 以上の黒い連続」を探す（帯の行）
    bar_rows = []
    lo, hi, min_run = int(w * 0.08), int(w * 0.32), int(w * 0.07)
    for y in range(int(h * 0.03), int(h * 0.4)):
        row = dark[y, lo:hi + min_run]
        if row.sum() < min_run:
            continue
        xs = np.where(row)[0]
        start = run = None
        best = None
        prev = None
        for x in xs:
            if prev is None or x - prev > 2:
                if start is not None and prev - start + 1 >= min_run and (best is None or prev - start > best[1] - best[0]):
                    best = (start, prev)
                start = x
            prev = x
        if start is not None and prev - start + 1 >= min_run and (best is None or prev - start > best[1] - best[0]):
            best = (start, prev)
        if best and best[0] + lo <= hi:
            bar_rows.append((y, best[0] + lo))
    groups = []
    for y, x in bar_rows:
        if groups and y - groups[-1][1] <= 2:
            groups[-1][1] = y
            groups[-1][2] = min(groups[-1][2], x)
        else:
            groups.append([y, y, x])
    groups = [gp for gp in groups if gp[1] - gp[0] >= 12]
    if not groups:
        return None
    y0 = groups[0][0]
    bar_x0 = groups[0][2]
    body = (g[:, int(w * 0.05):int(w * 0.92)] < 200)
    rows = np.where(body.sum(axis=1) > 3)[0]
    rows = rows[rows > y0]
    if not len(rows):
        return None
    # 枠の下端: y0 から続く濃い行のかたまりの終わり（60px 以上の空きで区切る）
    y1 = rows[0]
    for y in rows[1:]:
        if y - y1 > 60:
            break
        y1 = y
    x0 = int(bar_x0)
    cols = np.where(body[y0:y1 + 1].sum(axis=0) > 2)[0]
    x1 = int(cols.max()) + int(w * 0.05) if len(cols) else int(w * 0.92)
    return y0, y1, x0, x1


TPL = cv2.imread(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fg_renshu_tpl.png'), cv2.IMREAD_GRAYSCALE)


def answer_bottom(g, start_y):
    """全ページの解答の下端: 左下の「練習」のラベル（fg_renshu_tpl.png。100dpi のテンプレート照合、相関 0.6 以上）の上 60px（点線の上）。
    見つからなければ（解答が次ページに続く）ページ下の脚注の上。g は 200dpi の濃淡。(y, 見つかったか) を返す"""
    h, w = g.shape
    small = cv2.resize(g, (w // 2, h // 2), interpolation=cv2.INTER_AREA)
    sh, sw = small.shape
    top = max(int(sh * 0.1), start_y // 2)
    res = cv2.matchTemplate(small[top:, :int(sw * 0.3)], TPL, cv2.TM_CCOEFF_NORMED)
    _, mx, _, loc = cv2.minMaxLoc(res)
    if mx >= 0.6:
        return max(start_y + 20, (loc[1] + top) * 2 - 60), True
    return int(h * 0.955), False


def trim_rows(img, pad=14):
    a = np.asarray(img.convert('L'))
    rows = np.where((a < 200).sum(axis=1) > 2)[0]
    if not len(rows):
        return None
    return img.crop((0, max(0, rows[0] - pad), img.width, min(img.height, rows[-1] + pad)))


def stack(parts, gap=40):
    parts = [p for p in parts if p is not None]
    if not parts:
        return None
    w = max(p.width for p in parts)
    h = sum(p.height for p in parts) + gap * (len(parts) - 1)
    out = Image.new('RGB', (w, h), 'white')
    y = 0
    for p in parts:
        out.paste(p, (0, y))
        y += p.height + gap
    return out


def main():
    ex = pymupdf.open(EX_PDF)
    full = pymupdf.open(FULL_PDF)
    img_dir = os.path.join(OUT, 'cards_images', KEY)
    pg_dir = os.path.join(OUT, 'pages', KEY)
    os.makedirs(img_dir, exist_ok=True)
    os.makedirs(pg_dir, exist_ok=True)
    full_small = [gray(full, i, MATCH_DPI).astype(np.int16) for i in range(full.page_count)]
    # ---- 例題 N → 全ページのページ ----
    matches = []
    cursor = 0
    for n in range(ex.page_count):
        e = gray(ex, n, MATCH_DPI).astype(np.int16)
        dark = e < 160
        rows = np.where(dark.sum(axis=1) > 2)[0]
        cols = np.where(dark.sum(axis=0) > 2)[0]
        y0, y1, x0, x1 = rows.min(), rows.max(), cols.min(), cols.max()
        best = None
        second = 99.0
        # 例題 1 は全ページから、以降は直前の例題の次から 40 ページ以内で探す
        for i in range(cursor, full.page_count if n == 0 else min(full.page_count, cursor + 40)):
            f = full_small[i]
            if f.shape != e.shape:
                continue
            d = float(np.abs(f[y0:y1, x0:x1] - e[y0:y1, x0:x1]).mean())
            if best is None or d < best[1]:
                if best is not None:
                    second = best[1]
                best = (i, d)
            elif d < second:
                second = d
        # 同じスキャンなら差はほぼ 0〜6。次善（別のページ）は 5〜20 なので、最小が 3 未満か次善の 6 割未満なら一致とみなす
        if best is None or best[1] >= 12 or not (best[1] < 3.0 or best[1] < second * 0.6):
            print(f'  !! 例題 {n + 1}: 全ページ側に見つからない（最小差 {best} 次善 {second})', flush=True)
            matches.append(None)
            continue
        matches.append(best[0])
        cursor = best[0] + 1
    # ---- 切り出し ----
    out = []
    for n, fp in enumerate(matches):
        no = n + 1
        if fp is None:
            continue
        nxt = next((m for m in matches[n + 1:] if m is not None), full.page_count)
        eg = gray(ex, n, DPI)
        box = ex_box(eg)
        if box is None:
            print(f'  !! 例題 {no}: 枠が見つからない', flush=True)
            continue
        by0, by1, bx0, bx1 = box
        pad = 10
        eimg = rgb(ex, n, DPI)
        prob = eimg.crop((max(0, bx0 - pad), max(0, by0 - pad), min(eimg.width, bx1 + pad), min(eimg.height, by1 + pad)))
        fimg = rgb(full, fp, DPI)
        fg = gray(full, fp, DPI)
        h, w = fg.shape
        ab, ended = answer_bottom(fg, by1)
        left = max(0, bx0 - pad)
        right = min(w, int(w * 0.965))
        parts = [trim_rows(fimg.crop((left, by1 + 6, right, ab)))]
        # 練習の罫線が無い（解答が続く）ページは次のページ（次の例題の手前・最大 2 ページ）を続きとして足す
        cont = []
        cp = fp + 1
        while not ended and cp < nxt and len(cont) < 2:
            cg = gray(full, cp, DPI)
            cimg = rgb(full, cp, DPI)
            top = int(h * 0.075)
            cab, ended = answer_bottom(cg, top)
            parts.append(trim_rows(cimg.crop((left, top, right, cab))))
            cont.append(cp)
            cp += 1
        ans = stack(parts)
        pn = f'p{no:03d}.jpg'
        sn = f's{no:03d}.jpg'
        try:
            prob.save(os.path.join(img_dir, pn), quality=85, optimize=True)
            if ans is not None:
                if ans.height > 12000:
                    print(f'  !! 例題 {no}: 解答画像が高すぎる {ans.size}', flush=True)
                ans.save(os.path.join(img_dir, sn), quality=82, optimize=True)
        except Exception as e:  # noqa: BLE001
            print(f'  !! 例題 {no}: 画像の保存に失敗 {e} prob={prob.size} ans={ans.size if ans else None} box={box} ab={ab}', flush=True)
            continue
        # 読み取り用（全ページ側。150dpi）
        for j, pg in enumerate([fp] + cont):
            fn = f'p{no:03d}.png' if j == 0 else f'p{no:03d}_{j + 1}.png'
            full[pg].get_pixmap(dpi=150).save(os.path.join(pg_dir, fn))
        out.append({
            'no': no,
            'fullPages': [fp + 1] + [c + 1 for c in cont],
            'bookPage': str(fp),  # 全ページ PDF のページ番号 − 1 が本のページ
            'problemImage': f'{KEY}/{pn}',
            'answerImage': f'{KEY}/{sn}' if ans is not None else None,
        })
        if no % 40 == 0:
            print(no, flush=True)
    with open(os.path.join(OUT, f'{KEY}_pages.json'), 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
    multi = [o['no'] for o in out if len(o['fullPages']) > 1]
    print(KEY, ex.page_count, '例題 ->', len(out), '枚。続きページあり:', len(multi), multi[:30], flush=True)


if __name__ == '__main__':
    main()
