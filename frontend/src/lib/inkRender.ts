/**
 * 手書きの線（MistakeStroke）の描き方。ペンの種類ごとの見た目を、画面（SVG）と印刷・閲覧（canvas）で共通にする。
 * - pen: いつものペン（丸い端）
 * - flat: 平型。幅広のペン先を 45° に構えたカリグラフィーの線（動かす向きで太さが変わる）。輪郭の多角形を塗って表す
 * - marker: マーカー（太く半透明。端は四角）
 * - pencil: 鉛筆（少し薄く細い）
 * 線の width は実際に描く太さ（ペンの太さの設定 × 種類ごとの scale を保存時に掛けておく）。
 */
import type { MistakeStroke, StrokeKind } from '@/types'

export interface StrokeKindDef {
  kind: StrokeKind
  label: string
  /** ペンの太さの設定（PEN_WIDTHS）に掛ける倍率 */
  scale: number
  opacity: number
  cap: 'round' | 'butt' | 'square'
}

export const STROKE_KINDS: StrokeKindDef[] = [
  { kind: 'pen', label: 'ペン', scale: 1, opacity: 1, cap: 'round' },
  { kind: 'flat', label: '平型', scale: 2.2, opacity: 1, cap: 'round' },
  { kind: 'marker', label: 'マーカー', scale: 5, opacity: 0.4, cap: 'square' },
  { kind: 'pencil', label: '鉛筆', scale: 0.8, opacity: 0.7, cap: 'round' },
]

export function kindOf(st: Pick<MistakeStroke, 'kind'>): StrokeKindDef {
  return STROKE_KINDS.find((k) => k.kind === (st.kind ?? 'pen')) ?? STROKE_KINDS[0]!
}

/** 平型のペン先の向き（45°）。この向きに動かすと細く、直交する向きに動かすと太い */
const NIB_X = Math.SQRT1_2
const NIB_Y = -Math.SQRT1_2
/** 平型の輪郭を縁取る細さ（ペン先と平行に動かしたときも線が消えないように） */
const flatEdge = (width: number) => Math.max(1, width * 0.12)

/** 平型: 線の輪郭（[x, y, ...] の多角形。閉じてはいない） */
export function flatOutline(points: number[], width: number): number[] {
  const hx = (NIB_X * width) / 2
  const hy = (NIB_Y * width) / 2
  const left: number[] = []
  const right: number[] = []
  for (let i = 0; i + 1 < points.length; i += 2) {
    left.push(points[i]! + hx, points[i + 1]! + hy)
    right.push(points[i]! - hx, points[i + 1]! - hy)
  }
  const out = left
  for (let i = right.length - 2; i >= 0; i -= 2) out.push(right[i]!, right[i + 1]!)
  return out
}

/** [x, y, ...] を SVG の path d に */
export function polyD(p: number[]): string {
  if (p.length < 2) return ''
  let d = `M${p[0]} ${p[1]}`
  for (let i = 2; i + 1 < p.length; i += 2) d += ` L${p[i]} ${p[i + 1]}`
  return d
}

/** 画面（SVG の <path>）用の属性 */
export function svgPathAttrs(st: MistakeStroke): Record<string, string | number> {
  const k = kindOf(st)
  if (k.kind === 'flat') {
    return {
      d: polyD(flatOutline(st.points, st.width)) + 'Z',
      fill: st.color,
      stroke: st.color,
      'stroke-width': flatEdge(st.width),
      'stroke-linejoin': 'round',
      opacity: k.opacity,
    }
  }
  return {
    d: polyD(st.points),
    fill: 'none',
    stroke: st.color,
    'stroke-width': st.width,
    'stroke-linecap': k.cap,
    'stroke-linejoin': 'round',
    opacity: k.opacity,
  }
}

/** 印刷・閲覧（canvas）用 */
export function drawStroke(ctx: CanvasRenderingContext2D, st: MistakeStroke) {
  const p = st.points
  if (p.length < 2) return
  const k = kindOf(st)
  ctx.save()
  ctx.globalAlpha = k.opacity
  ctx.strokeStyle = st.color
  ctx.fillStyle = st.color
  ctx.lineJoin = 'round'
  if (k.kind === 'flat') {
    const o = flatOutline(p, st.width)
    ctx.beginPath()
    tracePath(ctx, o)
    ctx.closePath()
    ctx.fill()
    ctx.lineWidth = flatEdge(st.width)
    ctx.stroke()
  } else {
    ctx.lineCap = k.cap
    ctx.lineWidth = st.width
    ctx.beginPath()
    tracePath(ctx, p)
    if (p.length === 2) ctx.lineTo(p[0]!, p[1]!) // 点
    ctx.stroke()
  }
  ctx.restore()
}

function tracePath(ctx: CanvasRenderingContext2D, p: number[]) {
  ctx.moveTo(p[0]!, p[1]!)
  for (let i = 2; i + 1 < p.length; i += 2) ctx.lineTo(p[i]!, p[i + 1]!)
}
