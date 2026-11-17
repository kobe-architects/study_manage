# 読み取り結果の目視確認用シート: 1 問ずつ「教材の問題の切り抜き（既存の q{no}.jpg）」と「読み取った文字」を並べた画像を作る。
#   python -I scr_sheet.py <scratch> <book> [per_page]
# 出力: <scratch>/out/scr/sheet_<book>_NN.png
import json, os, sys

import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFont

ROOT = r'C:\Apache24\htdocs\study_manage'
IMG = os.path.join(ROOT, 'backend', 'storage', 'app', 'private', 'grammar')
FONT = ImageFont.truetype(r'C:\Windows\Fonts\meiryo.ttc', 20)
FONT_S = ImageFont.truetype(r'C:\Windows\Fonts\meiryo.ttc', 16)
CIRC = '①②③④⑤⑥'


def main():
    scratch, book = sys.argv[1], sys.argv[2]
    per = int(sys.argv[3]) if len(sys.argv) > 3 else 10
    qs = json.load(open(os.path.join(scratch, 'out', 'scr', f'{book}.json'), encoding='utf-8'))
    W = 1500
    pages = [qs[i:i + per] for i in range(0, len(qs), per)]
    for pi, chunk in enumerate(pages):
        tiles = []
        for q in chunk:
            crop_path = os.path.join(IMG, q['problemImage'] or '') if q.get('problemImage') else None
            crop = cv2.imread(crop_path) if crop_path and os.path.exists(crop_path) else None
            if crop is not None:
                scale = min(1.0, 900 / crop.shape[1])
                crop = cv2.resize(crop, None, fx=scale, fy=scale)
            lines = [f"No.{q['no']}  {q['format']}  {q.get('formatLabel')}  重要度={q.get('importance')}  出典={q.get('source')}"]
            if q.get('instruction'):
                lines.append('指示: ' + q['instruction'])
            lines.append('Q: ' + q['question'])
            if q.get('choices'):
                lines.append('選択肢: ' + '  '.join(f'{CIRC[i]}{c}' for i, c in enumerate(q['choices'])))
            if q['format'] == 'choice':
                lines.append(f"正解: {CIRC[q['answerIndex']] if q['answerIndex'] is not None else '?'} {q.get('answerText')}")
            else:
                lines.append(f"正解: {q.get('answerText')}")
            if q.get('translation'):
                lines.append('訳: ' + q['translation'])
            # 文字をラップして描く
            txt_h = 0
            wrapped = []
            for ln in lines:
                while len(ln) > 46:
                    wrapped.append(ln[:46])
                    ln = ln[46:]
                wrapped.append(ln)
            txt_h = 26 * len(wrapped) + 10
            h = max(crop.shape[0] if crop is not None else 0, txt_h) + 16
            tile = Image.new('RGB', (W, h), 'white')
            if crop is not None:
                tile.paste(Image.fromarray(cv2.cvtColor(crop, cv2.COLOR_BGR2RGB)), (0, 8))
            d = ImageDraw.Draw(tile)
            x = (crop.shape[1] + 10) if crop is not None else 10
            x = min(x, 920)
            for k, ln in enumerate(wrapped):
                d.text((x, 8 + 26 * k), ln, fill=(0, 0, 0) if k else (0, 90, 200), font=FONT if k else FONT_S)
            d.line([(0, h - 1), (W, h - 1)], fill=(180, 180, 180), width=2)
            tiles.append(np.array(tile))
        out = np.vstack(tiles)
        fn = os.path.join(scratch, 'out', 'scr', f'sheet_{book}_{pi + 1:02d}.png')
        cv2.imwrite(fn, cv2.cvtColor(out, cv2.COLOR_RGB2BGR))
    print('sheets', len(pages))


if __name__ == '__main__':
    main()
