# スクランブル英文法・語法（見開き 265 ページの画像 PDF）を、見開きの左右に分けて OCR し JSON に保存する。
#   python -I ocr_scramble.py <scratch> [from] [to]
# <scratch>/scr/pages/sNNN_L.jpg / sNNN_R.jpg … 300dpi の左右ページ（切り抜き用）
# <scratch>/scr/ocr/sNNN.json … { L: { win_ja, win_en, rapid }, R: { win_ja } }
#   win_*: [ { text, words: [[text, x, y, w, h], ...] } ]（Windows OCR の行）
#   rapid: [ [x1, y1, x2, y2, text, score], ... ]（RapidOCR の行。英文向き）
# 左ページ = 問題（と下部の解答）、右ページ = 解説。印刷のページ番号は 左 = 2*spread-2、右 = 2*spread-1。
import asyncio, json, os, sys, time

import cv2
import numpy as np
import pymupdf
from rapidocr_onnxruntime import RapidOCR
from winrt.windows.globalization import Language
from winrt.windows.graphics.imaging import BitmapDecoder
from winrt.windows.media.ocr import OcrEngine
from winrt.windows.storage.streams import DataWriter, InMemoryRandomAccessStream

PDF = r'C:\Apache24\htdocs\study_manage\docs\other\Scramble_英文法・語法_origin.pdf'
DPI = 300
ENG = {lang: OcrEngine.try_create_from_language(Language(lang)) for lang in ['ja', 'en-US']}
RAPID = RapidOCR()


async def win_ocr(png_bytes, lang):
    stream = InMemoryRandomAccessStream()
    w = DataWriter(stream.get_output_stream_at(0))
    w.write_bytes(png_bytes)
    await w.store_async()
    await w.flush_async()
    dec = await BitmapDecoder.create_async(stream)
    bmp = await dec.get_software_bitmap_async()
    res = await ENG[lang].recognize_async(bmp)
    out = []
    for line in res.lines:
        words = []
        for wd in line.words:
            r = wd.bounding_rect
            words.append([wd.text, round(r.x), round(r.y), round(r.width), round(r.height)])
        out.append({'text': line.text, 'words': words})
    return out


def rapid_ocr(bgr):
    res, _ = RAPID(bgr)
    out = []
    for box, txt, sc in res or []:
        xs = [p[0] for p in box]
        ys = [p[1] for p in box]
        out.append([round(min(xs)), round(min(ys)), round(max(xs)), round(max(ys)), txt, round(float(sc), 3)])
    return out


def main():
    scratch = sys.argv[1]
    a = int(sys.argv[2]) if len(sys.argv) > 2 else 24
    b = int(sys.argv[3]) if len(sys.argv) > 3 else 250
    pdir = os.path.join(scratch, 'scr', 'pages')
    odir = os.path.join(scratch, 'scr', 'ocr')
    os.makedirs(pdir, exist_ok=True)
    os.makedirs(odir, exist_ok=True)
    doc = pymupdf.open(PDF)
    t0 = time.time()
    for s in range(a, b + 1):
        fn = os.path.join(odir, f's{s:03d}.json')
        if os.path.exists(fn):
            continue
        pix = doc[s - 1].get_pixmap(dpi=DPI)
        img = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, pix.n)
        if pix.n == 4:
            img = img[:, :, :3]
        half = pix.width // 2
        rec = {'spread': s, 'w': half, 'h': pix.height, 'dpi': DPI}
        for side, part in (('L', img[:, :half]), ('R', img[:, half:])):
            bgr = cv2.cvtColor(np.ascontiguousarray(part), cv2.COLOR_RGB2BGR)
            cv2.imwrite(os.path.join(pdir, f's{s:03d}_{side}.jpg'), bgr, [cv2.IMWRITE_JPEG_QUALITY, 90])
            ok, png = cv2.imencode('.png', bgr)
            png = png.tobytes()
            d = {'win_ja': asyncio.run(win_ocr(png, 'ja'))}
            if side == 'L':
                d['win_en'] = asyncio.run(win_ocr(png, 'en-US'))
                d['rapid'] = rapid_ocr(bgr)
            rec[side] = d
        with open(fn, 'w', encoding='utf-8') as f:
            json.dump(rec, f, ensure_ascii=False)
        print(s, round(time.time() - t0), 's', flush=True)
    print('done', flush=True)


if __name__ == '__main__':
    main()
