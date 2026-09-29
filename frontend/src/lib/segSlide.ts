// v-seg: セグメント切替（子要素のうち .on が選択中）の選択背景を、iOS の切替ボタンのようにスライドさせる。
// 選択中ボタンの背景色・角丸・影を読み取って下敷き（.seg-ind）に移し、ボタン自体の背景は透明にする（global.css の .seg-anim）。
import type { Directive } from 'vue'

interface SegState {
  ind: HTMLSpanElement
  mo: MutationObserver
  ro: ResizeObserver
  styled: boolean
  raf: number
}

const states = new WeakMap<HTMLElement, SegState>()

function activeOf(el: HTMLElement, st: SegState): HTMLElement | undefined {
  return Array.from(el.children).find((c) => c !== st.ind && c.classList.contains('on')) as HTMLElement | undefined
}

/** 選択中ボタン本来の見た目（背景・角丸・影）を下敷きに写す */
function copyStyle(el: HTMLElement, st: SegState, on: HTMLElement) {
  el.classList.remove('seg-anim')
  const cs = getComputedStyle(on)
  const bg = cs.backgroundColor
  const radius = cs.borderRadius
  const shadow = cs.boxShadow
  el.classList.add('seg-anim')
  st.ind.style.background = bg
  st.ind.style.borderRadius = radius
  st.ind.style.boxShadow = shadow === 'none' ? '' : shadow
  st.styled = true
}

function place(el: HTMLElement, animate: boolean) {
  const st = states.get(el)
  if (!st) return
  // コンテナの class が描き直しで上書きされた場合に付け直す
  if (st.styled && !el.classList.contains('seg-anim')) el.classList.add('seg-anim')
  const on = activeOf(el, st)
  if (!on || !on.offsetWidth) {
    st.ind.style.opacity = '0'
    return
  }
  if (!st.styled) copyStyle(el, st, on)
  const s = st.ind.style
  if (!animate || s.opacity === '0') {
    s.transition = 'none'
    s.opacity = '1'
  }
  s.width = `${on.offsetWidth}px`
  s.height = `${on.offsetHeight}px`
  s.transform = `translate(${on.offsetLeft}px, ${on.offsetTop}px)`
  if (s.transition === 'none') {
    void st.ind.offsetWidth // 位置を確定させてからアニメーションを戻す
    s.transition = ''
  }
}

function schedule(el: HTMLElement, animate: boolean) {
  const st = states.get(el)
  if (!st) return
  cancelAnimationFrame(st.raf)
  st.raf = requestAnimationFrame(() => place(el, animate))
}

export const vSeg: Directive<HTMLElement> = {
  mounted(el) {
    if (getComputedStyle(el).position === 'static') el.style.position = 'relative'
    const ind = document.createElement('span')
    ind.className = 'seg-ind'
    ind.setAttribute('aria-hidden', 'true')
    ind.style.opacity = '0'
    el.insertBefore(ind, el.firstChild)
    const mo = new MutationObserver(() => schedule(el, true))
    mo.observe(el, { attributes: true, attributeFilter: ['class'], subtree: true, childList: true })
    const ro = new ResizeObserver(() => schedule(el, false))
    ro.observe(el)
    states.set(el, { ind, mo, ro, styled: false, raf: 0 })
    el.classList.add('seg-anim')
    place(el, false)
  },
  unmounted(el) {
    const st = states.get(el)
    if (!st) return
    st.mo.disconnect()
    st.ro.disconnect()
    cancelAnimationFrame(st.raf)
    states.delete(el)
  },
}
