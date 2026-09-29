// レイアウト（AppLayout / TutorLayout）共通の画面遷移まわり。
// - 遷移の向きに合わせたアニメーション（下の階層へ=右から / 戻る=左から / タブ切替=フェード）
// - 画面を切り替えたらスクロールを先頭へ、ブラウザの「戻る」では元の位置へ戻す
// - 上端から引っ張って更新（タッチ端末）
// - ホーム画面から起動した iOS では、下の階層の画面で左端からスワイプして戻る
import { nextTick, onMounted, onUnmounted, ref, type Ref } from 'vue'
import { useRouter, type RouteLocationNormalized } from 'vue-router'
import { haptic, isIOS, isStandalone, isTouch } from '@/lib/native'

const depthOf = (r: RouteLocationNormalized) => Number(r.meta.depth ?? 1)

/** モーダル・全画面表示が開いているか（開いている間はジェスチャーを無効にする） */
const overlayOpen = () => !!document.querySelector('.ui-overlay, .ui-fullscreen')

/** 画面共通のジェスチャーを起こさない場所（入力欄・キャンバス・添削エディタなど）か。Apple Pencil の操作も対象外 */
function gestureBlocked(e: TouchEvent) {
  const t = e.touches[0] as Touch & { touchType?: string }
  if (t?.touchType === 'stylus') return true
  return !!(e.target as HTMLElement).closest('input, textarea, select, canvas, [contenteditable], .no-gesture')
}

/** タッチした要素から scroller までの間に、スクロール途中の要素があるか */
function innerScrolled(target: HTMLElement | null, scroller: HTMLElement) {
  for (let n = target; n && n !== scroller; n = n.parentElement) {
    if (n.scrollTop > 0) return true
  }
  return false
}

export function usePageNav(scroller: Ref<HTMLElement | null>, content: Ref<HTMLElement | null>) {
  const router = useRouter()

  /** 表示する画面に付けるアニメーションのクラス */
  const pageAnim = ref('page-fade')
  /** 画面の再マウント用（引っ張って更新で使う） */
  const refreshKey = ref(0)

  // ---- 遷移アニメーションとスクロール位置 ----
  const saved = new Map<number, number>()
  let curPos: number | null = null
  let popNav = false
  const onPop = () => (popNav = true)

  let nextAnim = 'page-fade'
  const offBefore = router.beforeEach((to, from) => {
    if (curPos !== null && scroller.value) saved.set(curPos, scroller.value.scrollTop)
    const d1 = depthOf(from)
    const d2 = depthOf(to)
    nextAnim = d2 > d1 ? 'page-push' : d2 < d1 ? 'page-pop' : 'page-fade'
  })

  const offAfter = router.afterEach((_to, _from, failure) => {
    const isPop = popNav
    popNav = false
    if (failure) return
    // 遷移が確定してから切り替える（beforeEach で変えると、消える前の画面にもアニメーションがかかる）
    pageAnim.value = nextAnim
    const pos = window.history.state?.position
    curPos = typeof pos === 'number' ? pos : null
    const target = isPop && curPos !== null ? (saved.get(curPos) ?? 0) : 0
    restoreScroll(target)
  })

  /** 新しい画面の描画を待ってスクロール位置を合わせる（高さが足りるまで数フレーム試す） */
  function restoreScroll(top: number) {
    let tries = 0
    const apply = () => {
      const el = scroller.value
      if (!el) return
      el.scrollTop = top
      if (Math.abs(el.scrollTop - top) > 2 && tries++ < 20) requestAnimationFrame(apply)
    }
    nextTick(apply)
  }

  function scrollToTop() {
    scroller.value?.scrollTo({ top: 0, behavior: 'smooth' })
  }

  // ---- 引っ張って更新 ----
  const PULL_TRIGGER = 64
  /** コンテンツを下げている量（px） */
  const pull = ref(0)
  const refreshing = ref(false)
  let refreshHandler: (() => Promise<unknown>) | null = null
  let pt: { x0: number; y0: number; active: boolean; armed: boolean } | null = null

  function setContentOffset(px: number, animate: boolean) {
    const el = content.value
    if (!el) return
    el.style.transition = animate ? 'transform 0.32s cubic-bezier(0.2, 0.9, 0.25, 1)' : 'none'
    el.style.transform = px ? `translateY(${px}px)` : ''
  }

  function onPullStart(e: TouchEvent) {
    pt = null
    const el = scroller.value
    if (!refreshHandler || !el || refreshing.value || e.touches.length !== 1) return
    if (el.scrollTop > 0 || overlayOpen()) return
    if (gestureBlocked(e) || innerScrolled(e.target as HTMLElement, el)) return
    pt = { x0: e.touches[0].clientX, y0: e.touches[0].clientY, active: false, armed: false }
  }

  function onPullMove(e: TouchEvent) {
    if (!pt) return
    const t = e.touches[0]
    const dx = t.clientX - pt.x0
    const dy = t.clientY - pt.y0
    if (!pt.active) {
      if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return
      if (dy <= 0 || Math.abs(dx) > dy || (scroller.value?.scrollTop ?? 0) > 0) {
        pt = null
        return
      }
      pt.active = true
    }
    e.preventDefault()
    // 指の移動量に対してゴムのように少しずつ下がる
    const px = Math.min(130, Math.max(0, dy) * 0.5)
    pull.value = px
    setContentOffset(px, false)
    const armed = px >= PULL_TRIGGER
    if (armed && !pt.armed) haptic(10)
    pt.armed = armed
  }

  function onPullEnd() {
    if (!pt || !pt.active) {
      pt = null
      return
    }
    const armed = pt.armed
    pt = null
    if (!armed || !refreshHandler) {
      pull.value = 0
      setContentOffset(0, true)
      return
    }
    refreshing.value = true
    pull.value = 52
    setContentOffset(52, true)
    const started = Date.now()
    refreshHandler()
      .catch(() => {})
      .finally(() => {
        // 一瞬で終わってもくるくるが見えるよう最低 0.5 秒は表示する
        window.setTimeout(
          () => {
            pageAnim.value = 'page-fade'
            refreshKey.value++
            refreshing.value = false
            pull.value = 0
            setContentOffset(0, true)
          },
          Math.max(0, 500 - (Date.now() - started)),
        )
      })
  }

  /** 引っ張って更新で呼ぶ処理を登録する（タッチ端末のみ有効） */
  function onPullRefresh(fn: () => Promise<unknown>) {
    if (isTouch) refreshHandler = fn
  }

  // ---- 左端スワイプで戻る（ホーム画面起動の iOS のみ。Safari では標準の戻るジェスチャーと重なるため） ----
  const edgeEnabled = isIOS && isStandalone
  let es: { x0: number; y0: number; active: boolean; lastX: number; lastT: number; v: number } | null = null

  function onEdgeStart(e: TouchEvent) {
    es = null
    if (!edgeEnabled || e.touches.length !== 1) return
    const route = router.currentRoute.value
    if (depthOf(route) < 2 || !route.meta.parent || overlayOpen() || gestureBlocked(e)) return
    const t = e.touches[0]
    if (t.clientX > 22) return
    es = { x0: t.clientX, y0: t.clientY, active: false, lastX: t.clientX, lastT: e.timeStamp, v: 0 }
  }

  function onEdgeMove(e: TouchEvent) {
    if (!es) return
    const t = e.touches[0]
    const dx = t.clientX - es.x0
    const dy = t.clientY - es.y0
    if (!es.active) {
      if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return
      if (dx <= 0 || Math.abs(dy) > dx) {
        es = null
        return
      }
      es.active = true
    }
    e.preventDefault()
    const dt = e.timeStamp - es.lastT
    if (dt > 0) es.v = (t.clientX - es.lastX) / dt
    es.lastX = t.clientX
    es.lastT = e.timeStamp
    const el = content.value
    if (el) {
      el.style.transition = 'none'
      el.style.transform = `translateX(${Math.max(0, dx)}px)`
      el.style.opacity = String(1 - Math.min(0.5, dx / 800))
    }
  }

  function onEdgeEnd() {
    if (!es || !es.active) {
      es = null
      return
    }
    const dx = es.lastX - es.x0
    const back = dx > 90 || (es.v > 0.5 && dx > 30)
    es = null
    const el = content.value
    if (el) {
      el.style.transition = 'transform 0.25s ease, opacity 0.25s ease'
      el.style.transform = back ? 'translateX(40%)' : ''
      el.style.opacity = back ? '0' : ''
    }
    if (!back) return
    const parent = String(router.currentRoute.value.meta.parent)
    const parentPath = router.resolve({ name: parent }).fullPath
    window.setTimeout(() => {
      if (el) {
        el.style.transition = ''
        el.style.transform = ''
        el.style.opacity = ''
      }
      if (window.history.state?.back === parentPath) router.back()
      else router.push({ name: parent })
    }, 180)
  }

  // ---- リスナーの登録 ----
  const onStart = (e: TouchEvent) => {
    onEdgeStart(e)
    if (!es) onPullStart(e)
  }
  const onMove = (e: TouchEvent) => {
    if (es) onEdgeMove(e)
    else onPullMove(e)
  }
  const onEnd = () => {
    if (es) onEdgeEnd()
    else onPullEnd()
  }

  let bound: HTMLElement | null = null
  function bind(el: HTMLElement | null) {
    if (bound === el) return
    if (bound) {
      bound.removeEventListener('touchstart', onStart)
      bound.removeEventListener('touchmove', onMove)
      bound.removeEventListener('touchend', onEnd)
      bound.removeEventListener('touchcancel', onEnd)
    }
    bound = el
    if (!el || !isTouch) return
    el.addEventListener('touchstart', onStart, { passive: true })
    el.addEventListener('touchmove', onMove, { passive: false })
    el.addEventListener('touchend', onEnd)
    el.addEventListener('touchcancel', onEnd)
  }

  onMounted(() => {
    window.addEventListener('popstate', onPop)
    const pos = window.history.state?.position
    curPos = typeof pos === 'number' ? pos : null
  })
  onUnmounted(() => {
    window.removeEventListener('popstate', onPop)
    offBefore()
    offAfter()
    bind(null)
  })

  return { pageAnim, refreshKey, pull, refreshing, pullTrigger: PULL_TRIGGER, scrollToTop, onPullRefresh, bind }
}
