# スタディサプリ ベーシックレベル数学（例題のみ PDF）→ 学習カードの画像（問題・解答）とページ構成の JSON
#   PDF は文字がアウトライン化されていて文字データが無い。各ページは「上: 問題 / 点線 / 下: [解答]」。
#   解答が長いときは次のページに続く（続きのページには見出しの灰色帯が無い）。
#   1. 灰色の帯（見出し）があるページを例題の開始ページ、無いページを直前の例題の続きとする
#   2. 点線（幅 400pt 超・高さ 3pt 未満の破線）の上を問題、下を解答として 200dpi で切り出す。続きのページは解答の下に縦につなぐ
#   3. 答えの文字（4 択）は別途、ページ画像（110dpi PNG）を読んで JSON にする（merge_ss.py で合流）
# 使い方: python build_ss.py <出力先ディレクトリ>
#   <出力先>/cards_images/ss_<key>/p###.jpg, s###.jpg   … カードの画像（storage/app/private/cards/ss_<key>/ に置く）
#   <出力先>/pages/<key>/p###.png（＋ p###_2.png …）      … 読み取り用のページ画像
#   <出力先>/ss_<key>_pages.json                          … 例題ごとの PDF ページ・印刷ページ・画像ファイル名
import glob, json, os, re, sys
import numpy as np
import pymupdf
from PIL import Image

SRC = r'C:\Apache24\htdocs\study_manage\docs\other\261118'
OUT = sys.argv[1] if len(sys.argv) > 1 else 'out/ss'
KEYS = {'Ⅰ': 'math1', 'Ⅱ': 'math2', 'Ⅲ': 'math3', 'A': 'math_a', 'B': 'math_b', 'C': 'math_c'}
DPI = 200
S = DPI / 72.0
# ページ座標（pt）: 本文の左右・見出し帯の下・脚注線
X0, X1 = 40, 556
FOOT = 806
CONT_TOP = 42  # 続きのページの上の点線の下


def page_info_raster(page):
    """画像だけのページ（数学Ⅰの p13・p14 のように、文字がアウトラインでなくスキャン画像のページ）用。
    描画で帯・点線が取れないので、100dpi の濃淡で 帯（上端から続く灰色の行）と 点線（短い濃い線が 40 本以上並ぶ細い行）を探す"""
    dpi = 100
    k = 72.0 / dpi
    pix = page.get_pixmap(dpi=dpi, colorspace=pymupdf.csGRAY)
    a = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width)
    h, w = a.shape
    x0, x1 = int(X0 / k), int(X1 / k)
    band = None
    for y in range(0, int(150 / k)):
        row = a[y, x0:x1]
        if ((row > 180) & (row < 238)).mean() > 0.8:
            band = y
        elif band is not None and y - band > 4:
            break
    sep = None
    for y in range(int(130 / k), int(FOOT / k)):
        row = a[y, x0:x1] < 200
        cov = row.mean()
        if not (0.25 <= cov <= 0.75):
            continue
        runs = int(np.count_nonzero(row[1:] != row[:-1]))
        if runs < 80:
            continue
        above = (a[max(0, y - 10):max(0, y - 4), x0:x1] < 200).mean()
        below = (a[y + 4:y + 10, x0:x1] < 200).mean()
        if above < 0.02 and below < 0.02:
            sep = y * k
            break
    return (band * k if band is not None else None), sep


def page_info(page):
    """(band_bottom: 見出し帯の下端(pt。帯が無い＝続きのページなら None), sep_y: 点線の y(pt) or None)
    帯は灰色（0.8〜0.9）・幅 580pt 超・高さ 60〜140pt（講の先頭ページは高く、PART だけのページは低い）"""
    start = None
    seps = []
    for x in page.get_drawings():
        r = x['rect']
        fl = x.get('fill')
        if fl and r.width > 580 and 60 < r.height < 140 and r.y0 < 2 and 0.75 < fl[0] < 0.95:
            start = r.y1
        if r.width > 400 and r.height < 3 and r.y0 > 60 and (len(x['items']) > 20 or (x.get('dashes') and x['dashes'] != '[] 0')):
            seps.append(r.y0)
    sep = min(seps) if seps else None
    if start is None and sep is None and page.get_images():
        return page_info_raster(page)
    return start, sep


def render(page, y0pt, y1pt):
    clip = pymupdf.Rect(X0, y0pt, X1, y1pt)
    pix = page.get_pixmap(dpi=DPI, clip=clip)
    img = Image.frombytes('RGB', (pix.width, pix.height), pix.samples)
    return img


def trim(img, pad=18):
    a = np.asarray(img.convert('L'))
    rows = np.where((a < 235).sum(axis=1) > 0)[0]
    cols = np.where((a < 235).sum(axis=0) > 0)[0]
    if not len(rows) or not len(cols):
        return None
    y0, y1 = max(0, rows[0] - pad), min(a.shape[0], rows[-1] + pad)
    # 横は本文幅をそろえる（左右は切り詰めない）
    return img.crop((0, y0, img.width, y1))


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
    os.makedirs(OUT, exist_ok=True)
    for pdf in sorted(glob.glob(os.path.join(SRC, '*.pdf'))):
        m = re.search(r'数学(.)_', os.path.basename(pdf))
        key = KEYS[m.group(1)]
        doc = pymupdf.open(pdf)
        img_dir = os.path.join(OUT, 'cards_images', f'ss_{key}')
        pg_dir = os.path.join(OUT, 'pages', key)
        os.makedirs(img_dir, exist_ok=True)
        os.makedirs(pg_dir, exist_ok=True)
        infos = [page_info(doc[i]) for i in range(doc.page_count)]
        problems = []
        for i, (start, sep) in enumerate(infos):
            if start is not None:
                problems.append({'pages': [i + 1], 'sep': sep, 'top': start})
            elif problems:
                problems[-1]['pages'].append(i + 1)
        out = []
        for n, pr in enumerate(problems, 1):
            first = pr['pages'][0]
            page = doc[first - 1]
            sep = pr['sep']
            if sep is None:
                print(f'  !! {key} p{first}: 点線なし（問題がページをまたぐ？）', flush=True)
                sep = FOOT
            prob = trim(render(page, pr['top'] + 1, sep - 2))
            parts = [trim(render(page, sep + 3, FOOT))]
            for cp in pr['pages'][1:]:
                parts.append(trim(render(doc[cp - 1], CONT_TOP, FOOT)))
            ans = stack(parts)
            pn = f'p{n:03d}.jpg'
            sn = f's{n:03d}.jpg'
            prob.save(os.path.join(img_dir, pn), quality=85, optimize=True)
            if ans is not None:
                ans.save(os.path.join(img_dir, sn), quality=85, optimize=True)
            # 読み取り用ページ画像
            for j, pg in enumerate(pr['pages']):
                fn = f'p{n:03d}.png' if j == 0 else f'p{n:03d}_{j + 1}.png'
                doc[pg - 1].get_pixmap(dpi=110).save(os.path.join(pg_dir, fn))
            out.append({
                'no': n,
                'pdfPages': pr['pages'],
                'bookPage': str(first + 1),  # 印刷ページ番号は PDF ページ + 1（表紙が無い）
                'problemImage': f'ss_{key}/{pn}',
                'answerImage': f'ss_{key}/{sn}' if ans is not None else None,
            })
        with open(os.path.join(OUT, f'ss_{key}_pages.json'), 'w', encoding='utf-8') as f:
            json.dump(out, f, ensure_ascii=False, indent=1)
        print(key, doc.page_count, 'pages ->', len(out), 'problems', flush=True)


if __name__ == '__main__':
    main()
