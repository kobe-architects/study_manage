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

/**
 * iOS のダブルタップ拡大を止める（html の touch-action: manipulation だけでは効かない端末向けの保険）。
 * el の中では touchend の既定動作をすべて止める（ブラウザの合成クリックも出なくなる）ので、
 * ボタン・ラベル・リンク・[data-tap] のタップは自前で click() を起こす（1 本指で、動かさず、長押しでないとき）。
 * 入力欄（input / select / textarea / contenteditable）はブラウザに任せる。戻り値を呼ぶと解除する。
 */
export function blockDoubleTapZoom(el: HTMLElement): () => void {
  const starts = new Map<number, { x: number; y: number; t: number }>()
  const onStart = (e: TouchEvent) => {
    for (const t of Array.from(e.changedTouches)) starts.set(t.identifier, { x: t.clientX, y: t.clientY, t: e.timeStamp })
  }
  const onEnd = (e: TouchEvent) => {
    const target = e.target as Element | null
    if (target?.closest('input, select, textarea, [contenteditable]')) return
    e.preventDefault()
    const t = e.changedTouches[0]
    const s = t ? starts.get(t.identifier) : undefined
    if (t) starts.delete(t.identifier)
    const ctl = target?.closest<HTMLElement>('button, label, a, [data-tap]')
    if (!ctl || !t || !s) return
    if (e.touches.length) return // 他の指が残っている（ピンチなど）
    if (Math.hypot(t.clientX - s.x, t.clientY - s.y) > 12 || e.timeStamp - s.t > 700) return // 動かした・長押し
    ctl.click()
  }
  const onCancel = (e: TouchEvent) => {
    for (const t of Array.from(e.changedTouches)) starts.delete(t.identifier)
  }
  el.addEventListener('touchstart', onStart, { passive: true })
  el.addEventListener('touchend', onEnd, { passive: false })
  el.addEventListener('touchcancel', onCancel, { passive: true })
  return () => {
    el.removeEventListener('touchstart', onStart)
    el.removeEventListener('touchend', onEnd)
    el.removeEventListener('touchcancel', onCancel)
  }
}

/**
 * el の中の UI の文字が選択されないようにする（iPad で Apple Pencil で書いている最中や長押しで、
 * CSS の user-select: none を無視してボタンの文字が選ばれ「コピー」などのメニューが出るのを防ぐ）。
 * selectstart を止め、それでも選択されたら即座に解除する。入力欄（input / textarea / contenteditable）の中は対象外。
 * 戻り値を呼ぶと解除する。
 */
export function blockUiTextSelection(el: HTMLElement): () => void {
  const editable = (n: Node | null | undefined): boolean => {
    const e = n instanceof Element ? n : n?.parentElement
    return !!e?.closest('input, textarea, [contenteditable]')
  }
  const onSelectStart = (e: Event) => {
    if (!editable(e.target as Node)) e.preventDefault()
  }
  const onSelectionChange = () => {
    const a = document.activeElement
    if (a && a.matches('input, textarea, [contenteditable]')) return
    const sel = document.getSelection()
    if (!sel || !sel.rangeCount || sel.isCollapsed) return
    if (!el.contains(sel.anchorNode) && !el.contains(sel.focusNode)) return
    if (editable(sel.anchorNode)) return
    sel.removeAllRanges()
  }
  const onContextMenu = (e: Event) => {
    if (!editable(e.target as Node)) e.preventDefault()
  }
  el.addEventListener('selectstart', onSelectStart)
  el.addEventListener('contextmenu', onContextMenu)
  document.addEventListener('selectionchange', onSelectionChange)
  return () => {
    el.removeEventListener('selectstart', onSelectStart)
    el.removeEventListener('contextmenu', onContextMenu)
    document.removeEventListener('selectionchange', onSelectionChange)
  }
}

/**
 * 手書き画面向けのタッチの保険（iPad）。Apple Pencil で書いている最中に手のひらが画面に触れると、iOS がそれを長押しと解釈して
 * 近くの文字を選択し、手を離したときに「コピー」などのメニューを出す。これを止める。
 * - ボタン・入力欄以外では touchstart の既定動作を止める（長押しの選択・Pencil のドラッグ選択・スクロールを防ぐ。
 *   道具の横スクロールは MistakeCanvas が pointer イベントで自前で動かす）
 * - Pencil が触れている間と離して 1 秒以内は、指（手のひら）がボタン等に当たっても既定動作を止める（パームリジェクション）
 * - 接触面の大きいタッチ（手のひら）も止める
 * Pencil 自体のタップはボタン等で普通に効く（クリックは blockDoubleTapZoom が自前で起こす）。戻り値を呼ぶと解除する。
 */
export function installInkTouchGuard(el: HTMLElement): () => void {
  let penDown = false
  let penUpAt = 0
  const penActive = () => penDown || performance.now() - penUpAt < 1000
  const onPointerDown = (e: PointerEvent) => {
    if (e.pointerType === 'pen') penDown = true
  }
  const onPointerUp = (e: PointerEvent) => {
    if (e.pointerType === 'pen') {
      penDown = false
      penUpAt = performance.now()
    }
  }
  const onTouchStart = (e: TouchEvent) => {
    const t = e.target as Element | null
    const interactive = !!t?.closest('button, a, input, select, textarea, label, [contenteditable]')
    let stylus = false
    let palm = false
    for (const x of Array.from(e.changedTouches)) {
      if ((x as Touch & { touchType?: string }).touchType === 'stylus') stylus = true
      if (x.radiusX > 30 || x.radiusY > 30) palm = true
    }
    if (stylus) {
      if (!interactive) e.preventDefault()
      return
    }
    if (!interactive || palm || penActive()) e.preventDefault()
  }
  document.addEventListener('pointerdown', onPointerDown, true)
  document.addEventListener('pointerup', onPointerUp, true)
  document.addEventListener('pointercancel', onPointerUp, true)
  el.addEventListener('touchstart', onTouchStart, { passive: false })
  return () => {
    document.removeEventListener('pointerdown', onPointerDown, true)
    document.removeEventListener('pointerup', onPointerUp, true)
    document.removeEventListener('pointercancel', onPointerUp, true)
    el.removeEventListener('touchstart', onTouchStart)
  }
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
