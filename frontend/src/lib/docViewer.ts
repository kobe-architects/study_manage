// アプリ内の書類ビューア（表示は components/DocViewerHost.vue）。
// ホーム画面から起動したアプリでは別タブ（window.open）が使えない・blob の URL を別タブに渡せないため、
// PDF のプレビューや印刷用の一覧はアプリ内の全画面ビューアで表示し、共有シートから印刷・保存する。
import { reactive } from 'vue'
import { isTouch } from '@/lib/native'

/**
 * 別タブの代わりにアプリ内ビューアを使うか。
 * タッチ端末（iPhone・iPad・Android）は、ホーム画面起動では別タブが使えず、Safari でも blob の PDF を新しいタブに渡すと
 * 表示できないことがあるため、常にアプリ内ビューアで表示する。
 */
export const useInAppViewer = isTouch

export const docState = reactive<{
  open: boolean
  /** 表示のたびに増える（ビューアが読み込み直す合図） */
  seq: number
  kind: 'pdf' | 'html'
  title: string
  filename: string
  blob: Blob | null
  html: string
}>({ open: false, seq: 0, kind: 'pdf', title: '', filename: '', blob: null, html: '' })

/** PDF をアプリ内で表示する */
export function showPdf(blob: Blob, filename: string, title?: string) {
  Object.assign(docState, {
    open: true,
    seq: docState.seq + 1,
    kind: 'pdf',
    title: title ?? filename.replace(/\.pdf$/i, ''),
    filename,
    blob,
    html: '',
  })
}

/** 印刷用の HTML をアプリ内で表示する */
export function showHtml(html: string, title: string) {
  Object.assign(docState, { open: true, seq: docState.seq + 1, kind: 'html', title, filename: '', blob: null, html })
}

export function closeDoc() {
  docState.open = false
}
