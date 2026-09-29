// iPad・スマホでネイティブアプリに近い操作感にするための共通処理（main.ts で一度だけ初期化する）
import { ref } from 'vue'

export const isIOS =
  /iP(hone|od|ad)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)

/** ホーム画面から起動した（ブラウザの UI が無い）状態か */
export const isStandalone =
  window.matchMedia('(display-mode: standalone)').matches ||
  (navigator as Navigator & { standalone?: boolean }).standalone === true

/** 指でのタッチ操作が主の端末か */
export const isTouch = window.matchMedia('(pointer: coarse)').matches

/** モーダルを下からのシートとして出す幅 */
const phoneMq = window.matchMedia('(max-width: 600px)')

/** 画面幅（全画面で 1 つの resize リスナーを共有する） */
export const viewportWidth = ref(window.innerWidth)

/** 軽い触覚フィードバック（対応端末のみ。iOS Safari は未対応のため何もしない） */
export function haptic(ms = 8) {
  try {
    navigator.vibrate?.(ms)
  } catch {
    // ignore
  }
}

/**
 * ファイルを端末に保存する。
 * ホーム画面から起動した iPhone・iPad では <a download> だとプレビューが開いたまま戻れなくなることがあるため、
 * 共有シート（「ファイルに保存」「画像を保存」など）を使う。preferShare のときはタッチ端末なら常に共有シートにする（画像向け）。
 */
export async function saveFile(blob: Blob, filename: string, opts: { preferShare?: boolean } = {}) {
  const useShare = (opts.preferShare ? isTouch : isIOS && isStandalone) && typeof navigator.canShare === 'function'
  if (useShare) {
    const file = new File([blob], filename, { type: blob.type || 'application/octet-stream' })
    if (navigator.canShare({ files: [file] })) {
      try {
        await navigator.share({ files: [file] })
        return
      } catch (e) {
        // キャンセルはそのまま終了。それ以外（操作から時間が経って共有できない等）は通常のダウンロードにする
        if ((e as DOMException)?.name === 'AbortError') return
      }
    }
  }
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  // Safari はクリック直後に解放するとダウンロードに失敗することがあるので少し待つ
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

/**
 * iOS は 16px 未満の入力欄にフォーカスすると画面を勝手に拡大する。
 * maximum-scale=1 で抑止する（iOS はこの指定があってもピンチでの拡大は許可するので、拡大操作は失われない）。
 * Android はこの指定でピンチ拡大まで禁止されるため iOS のときだけ付ける。
 */
function preventInputZoom() {
  if (!isIOS) return
  const meta = document.querySelector<HTMLMetaElement>('meta[name=viewport]')
  if (meta && !/maximum-scale/.test(meta.content)) meta.content += ', maximum-scale=1'
}

/**
 * ボトムシート（.ui-swipe > .ui-panel）を下にスワイプして閉じる。
 * 閉じる処理は背景クリック（overlay.click()）に任せるので、.ui-swipe は背景クリックで閉じるモーダルにだけ付ける。
 */
function installSheetSwipe() {
  interface Drag {
    panel: HTMLElement
    overlay: HTMLElement
    x0: number
    y0: number
    dy: number
    active: boolean
    lastY: number
    lastT: number
    v: number
  }
  let d: Drag | null = null

  document.addEventListener(
    'touchstart',
    (e) => {
      d = null
      if (e.touches.length !== 1 || !phoneMq.matches) return
      const target = e.target as HTMLElement
      const panel = target.closest<HTMLElement>('.ui-swipe > .ui-panel')
      if (!panel || !panel.parentElement) return
      if (target.closest('input, textarea, select, canvas, [contenteditable], .no-swipe')) return
      // パネル内でスクロール途中の領域があればスクロールを優先する
      for (let n: HTMLElement | null = target; n && n !== panel.parentElement; n = n.parentElement) {
        if (n.scrollTop > 0) return
      }
      const t = e.touches[0]
      d = {
        panel,
        overlay: panel.parentElement,
        x0: t.clientX,
        y0: t.clientY,
        dy: 0,
        active: false,
        lastY: t.clientY,
        lastT: e.timeStamp,
        v: 0,
      }
    },
    { passive: true },
  )

  document.addEventListener(
    'touchmove',
    (e) => {
      if (!d) return
      const t = e.touches[0]
      const dx = t.clientX - d.x0
      const dy = t.clientY - d.y0
      if (!d.active) {
        if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return
        // 上方向・横方向の動きはシートを動かさない（通常のスクロールに任せる）
        if (dy <= 0 || Math.abs(dx) > dy) {
          d = null
          return
        }
        d.active = true
        d.panel.style.transition = 'none'
      }
      e.preventDefault()
      d.dy = Math.max(0, dy)
      const dt = e.timeStamp - d.lastT
      if (dt > 0) d.v = (t.clientY - d.lastY) / dt
      d.lastY = t.clientY
      d.lastT = e.timeStamp
      d.panel.style.transform = `translateY(${d.dy}px)`
    },
    { passive: false },
  )

  const end = () => {
    if (!d || !d.active) {
      d = null
      return
    }
    const { panel, overlay, dy, v } = d
    d = null
    const dismiss = dy > Math.min(140, panel.offsetHeight * 0.3) || (v > 0.55 && dy > 24)
    if (dismiss) {
      // 閉じるアニメーションを指を離した位置から続ける（global.css の ui-sheet-out が --ui-drag を使う）
      panel.style.setProperty('--ui-drag', `${dy}px`)
      panel.style.transition = ''
      panel.style.transform = ''
      overlay.click()
    } else {
      panel.style.transition = 'transform 0.3s cubic-bezier(0.2, 0.9, 0.25, 1)'
      panel.style.transform = ''
      window.setTimeout(() => (panel.style.transition = ''), 320)
    }
  }
  document.addEventListener('touchend', end)
  document.addEventListener('touchcancel', end)
}

/**
 * デプロイで古いチャンクが消えた後に、開きっぱなしのアプリ（ホーム画面起動だと数日そのまま）が
 * 画面遷移しようとすると読み込みに失敗する。そのときはページを読み直して最新版にする。
 */
function reloadOnStaleChunk() {
  window.addEventListener('vite:preloadError', (e) => {
    e.preventDefault()
    reloadOnce()
  })
}

export function reloadOnce(url?: string) {
  const key = 'sm_chunk_reload'
  // 読み直しても失敗し続ける場合に無限リロードしないよう、30 秒以内の再リロードはしない
  const last = Number(sessionStorage.getItem(key) || 0)
  if (Date.now() - last < 30000) return
  sessionStorage.setItem(key, String(Date.now()))
  if (url) window.location.assign(url)
  else window.location.reload()
}

export function installNativeFeel() {
  preventInputZoom()
  installSheetSwipe()
  reloadOnStaleChunk()
  window.addEventListener('resize', () => (viewportWidth.value = window.innerWidth))
  const root = document.documentElement
  if (isStandalone) root.classList.add('is-standalone')
  if (isIOS) root.classList.add('is-ios')
}
