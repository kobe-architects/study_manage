// JPEG 画像を 1 ページ 1 枚で並べた PDF をブラウザ内で組み立てる（ライブラリ不要）。
// 間違いノートの出力に使う。文字は画像に描いてあるのでフォントの埋め込みは不要。
// サーバーの upload 上限（5MB）を気にせず何十ページでも作れる。

export interface PdfPageImage {
  /** JPEG */
  blob: Blob
  width: number
  height: number
}

/** A4 のサイズ（pt） */
export const A4_PT = { w: 595.28, h: 841.89 }

const enc = new TextEncoder()

function concat(parts: Uint8Array[]): Uint8Array {
  const len = parts.reduce((n, p) => n + p.length, 0)
  const out = new Uint8Array(len)
  let o = 0
  for (const p of parts) {
    out.set(p, o)
    o += p.length
  }
  return out
}

/**
 * JPEG のページ画像から PDF を作る。各ページは pageW×pageH（pt）いっぱいに画像を置く
 * （画像はページと同じ縦横比で描いてある前提）。
 */
export async function jpegsToPdf(pages: PdfPageImage[], pageW = A4_PT.w, pageH = A4_PT.h): Promise<Blob> {
  const parts: Uint8Array[] = []
  const offsets: number[] = []
  let pos = 0
  const push = (p: Uint8Array | string) => {
    const b = typeof p === 'string' ? enc.encode(p) : p
    parts.push(b)
    pos += b.length
  }
  const beginObj = (n: number) => {
    offsets[n] = pos
    push(`${n} 0 obj\n`)
  }

  // オブジェクト番号: 1=Catalog, 2=Pages, 以降ページごとに Page / Contents / Image の 3 つ
  const total = 2 + pages.length * 3
  push('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n')

  beginObj(1)
  push('<< /Type /Catalog /Pages 2 0 R >>\nendobj\n')

  const kids = pages.map((_, i) => `${3 + i * 3} 0 R`).join(' ')
  beginObj(2)
  push(`<< /Type /Pages /Kids [${kids}] /Count ${pages.length} >>\nendobj\n`)

  for (let i = 0; i < pages.length; i++) {
    const page = pages[i]!
    const pageNo = 3 + i * 3
    const contentNo = pageNo + 1
    const imageNo = pageNo + 2
    const bytes = new Uint8Array(await page.blob.arrayBuffer())

    beginObj(pageNo)
    push(
      `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageW.toFixed(2)} ${pageH.toFixed(2)}] ` +
        `/Resources << /XObject << /Im${i} ${imageNo} 0 R >> >> /Contents ${contentNo} 0 R >>\nendobj\n`,
    )

    const content = `q ${pageW.toFixed(2)} 0 0 ${pageH.toFixed(2)} 0 0 cm /Im${i} Do Q`
    beginObj(contentNo)
    push(`<< /Length ${enc.encode(content).length} >>\nstream\n${content}\nendstream\nendobj\n`)

    beginObj(imageNo)
    push(
      `<< /Type /XObject /Subtype /Image /Width ${page.width} /Height ${page.height} ` +
        `/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${bytes.length} >>\nstream\n`,
    )
    push(bytes)
    push('\nendstream\nendobj\n')
  }

  const xrefPos = pos
  let xref = `xref\n0 ${total + 1}\n0000000000 65535 f \n`
  for (let n = 1; n <= total; n++) xref += `${String(offsets[n] ?? 0).padStart(10, '0')} 00000 n \n`
  push(xref)
  push(`trailer\n<< /Size ${total + 1} /Root 1 0 R >>\nstartxref\n${xrefPos}\n%%EOF\n`)

  return new Blob([concat(parts) as BlobPart], { type: 'application/pdf' })
}
