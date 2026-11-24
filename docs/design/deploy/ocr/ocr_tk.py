# 鉄緑会 PDF の 33〜460 ページを Windows OCR（ja / en-US）で文字起こし（docs/design/deploy/ocr/ocr_batch.py と同じ形式）
import asyncio, sys, json, time, os
import pymupdf
from winrt.windows.media.ocr import OcrEngine
from winrt.windows.globalization import Language
from winrt.windows.graphics.imaging import BitmapDecoder
from winrt.windows.storage.streams import InMemoryRandomAccessStream, DataWriter

ENG = {lang: OcrEngine.try_create_from_language(Language(lang)) for lang in ['ja', 'en-US']}

async def ocr_png(png_bytes, lang):
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

pdf, out, first, last = sys.argv[1], sys.argv[2], int(sys.argv[3]), int(sys.argv[4])
d = pymupdf.open(pdf)
os.makedirs(out, exist_ok=True)
t = time.time()
for i in range(first - 1, last):
    fn = f'{out}/p{i+1:03d}.json'
    if os.path.exists(fn):
        continue
    pix = d[i].get_pixmap(dpi=250)
    png = pix.tobytes('png')
    rec = {'page': i + 1, 'w': pix.width, 'h': pix.height, 'dpi': 250}
    for lang in ['ja', 'en-US']:
        rec[lang] = asyncio.run(ocr_png(png, lang))
    with open(fn, 'w', encoding='utf-8') as f:
        json.dump(rec, f, ensure_ascii=False)
    if (i + 1) % 20 == 0:
        print(i + 1, round(time.time() - t), 's', flush=True)
print('done', flush=True)
