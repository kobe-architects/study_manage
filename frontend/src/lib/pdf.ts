import * as pdfjsLib from 'pdfjs-dist'
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'

/**
 * pdf.js ラッパー。認証付き URL の PDF（ページ単位に抽出した小さなファイル）を描画して
 * JPEG の DataURL を返す。同じ URL・幅の結果はメモリにキャッシュする。
 */
pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl

const rendered = new Map<string, Promise<string>>()

export function renderPage(url: string, width: number, pageNo = 1, quality = 0.82): Promise<string> {
  const key = `${url}#${pageNo}@${width}`
  let t = rendered.get(key)
  if (!t) {
    t = (async () => {
      const doc = await pdfjsLib.getDocument({
        url,
        httpHeaders: { Authorization: `Bearer ${localStorage.getItem('sm_token') ?? ''}` },
      }).promise
      try {
        const page = await doc.getPage(pageNo)
        const base = page.getViewport({ scale: 1 })
        const viewport = page.getViewport({ scale: width / base.width })
        const canvas = document.createElement('canvas')
        canvas.width = Math.ceil(viewport.width)
        canvas.height = Math.ceil(viewport.height)
        const ctx = canvas.getContext('2d')!
        await page.render({ canvasContext: ctx, viewport }).promise
        const out = canvas.toDataURL('image/jpeg', quality)
        page.cleanup()
        return out
      } finally {
        doc.destroy().catch(() => {})
      }
    })()
    rendered.set(key, t)
    t.catch(() => rendered.delete(key))
  }
  return t
}
