/**
 * 手書きの線の図形補正。線を引いたまま少し止める（ホールド）と、直線・円／楕円・長方形に整える（iPad のメモと同じ操作感）。
 * recognizeShape に [x0, y0, x1, y1, ...]（ページ座標）を渡すと、図形と判定できたときは整えた点の列を返す（できなければ null）。
 * 判定は外枠（bbox）を基準にした簡単なもの:
 * - 直線: 始点と終点を結ぶ線分からのずれが小さく、道のりが線分の長さとほぼ同じ
 * - 円／楕円: 始点と終点が近く（閉じている）、外枠に内接する楕円からのずれが小さい。縦横がほぼ同じなら正円
 * - 長方形: 閉じていて、外枠の 4 辺の近くだけを通っている
 */

export type InkShape = 'line' | 'ellipse' | 'rect'

interface Pt {
  x: number
  y: number
}

/** これより短い線は補正しない（ページ座標。6px = 1mm） */
const MIN_LEN = 30
const ELLIPSE_SEGMENTS = 72

export function recognizeShape(flat: number[]): { kind: InkShape; points: number[] } | null {
  const pts: Pt[] = []
  for (let i = 0; i + 1 < flat.length; i += 2) pts.push({ x: flat[i]!, y: flat[i + 1]! })
  if (pts.length < 3) return null

  let len = 0
  let x1 = Infinity
  let y1 = Infinity
  let x2 = -Infinity
  let y2 = -Infinity
  for (let i = 0; i < pts.length; i++) {
    const p = pts[i]!
    if (i) len += Math.hypot(p.x - pts[i - 1]!.x, p.y - pts[i - 1]!.y)
    x1 = Math.min(x1, p.x)
    y1 = Math.min(y1, p.y)
    x2 = Math.max(x2, p.x)
    y2 = Math.max(y2, p.y)
  }
  if (len < MIN_LEN) return null
  const bw = x2 - x1
  const bh = y2 - y1
  const first = pts[0]!
  const last = pts[pts.length - 1]!

  // ---- 直線 ----
  const chord = Math.hypot(last.x - first.x, last.y - first.y)
  if (chord >= len * 0.9 && chord >= MIN_LEN) {
    let maxDev = 0
    for (const p of pts) maxDev = Math.max(maxDev, distToSegment(p, first, last))
    if (maxDev <= Math.max(5, chord * 0.06)) return { kind: 'line', points: straightLine(first, last) }
  }

  // ---- 閉じた図形（円／楕円・長方形）: 始点と終点が近く、外枠に対して十分な道のりがある ----
  const size = Math.max(bw, bh)
  if (bw < 24 || bh < 24 || size < MIN_LEN) return null
  if (chord > size * 0.3 || len < (bw + bh) * 1.4) return null

  const cx = (x1 + x2) / 2
  const cy = (y1 + y2) / 2
  const rx = bw / 2
  const ry = bh / 2
  const half = Math.min(bw, bh) / 2
  // 楕円からのずれ: 中心からの（楕円の半径で割った）距離が 1 からどれだけ離れているか
  let eSum = 0
  let eMax = 0
  // 長方形からのずれ: 外枠の辺までの距離を短い辺の半分で割ったもの。各辺の近くを通ったかも数える
  let rSum = 0
  let rMax = 0
  const near = [0, 0, 0, 0]
  for (const p of pts) {
    const e = Math.abs(Math.hypot((p.x - cx) / rx, (p.y - cy) / ry) - 1)
    eSum += e
    eMax = Math.max(eMax, e)
    const d = [p.x - x1, x2 - p.x, p.y - y1, y2 - p.y]
    const m = Math.min(d[0]!, d[1]!, d[2]!, d[3]!) / half
    rSum += m
    rMax = Math.max(rMax, m)
    for (let k = 0; k < 4; k++) if (d[k]! / half <= 0.15) near[k]!++
  }
  const n = pts.length
  const eMean = eSum / n
  const rMean = rSum / n
  const allEdges = near.every((c) => c >= n * 0.08)
  if (rMean <= 0.09 && rMax <= 0.35 && allEdges && rMean < eMean) {
    return { kind: 'rect', points: [r1(x1), r1(y1), r1(x2), r1(y1), r1(x2), r1(y2), r1(x1), r1(y2), r1(x1), r1(y1)] }
  }
  if (eMean <= 0.11 && eMax <= 0.32) {
    const circle = Math.abs(rx - ry) <= Math.max(rx, ry) * 0.12
    const ax = circle ? (rx + ry) / 2 : rx
    const ay = circle ? (rx + ry) / 2 : ry
    // 書き始めた位置から一周させる
    const a0 = Math.atan2((first.y - cy) / ry, (first.x - cx) / rx)
    const out: number[] = []
    for (let i = 0; i <= ELLIPSE_SEGMENTS; i++) {
      const a = a0 + (i / ELLIPSE_SEGMENTS) * Math.PI * 2
      out.push(r1(cx + ax * Math.cos(a)), r1(cy + ay * Math.sin(a)))
    }
    return { kind: 'ellipse', points: out }
  }
  return null
}

/** a から b への直線。水平・垂直に近い（5° 以内）ものはぴったり水平・垂直にする */
export function straightLine(a: Pt, b: Pt): number[] {
  const ang = Math.abs(Math.atan2(b.y - a.y, b.x - a.x)) // 0..π
  const tol = (5 * Math.PI) / 180
  if (ang <= tol || Math.PI - ang <= tol) return [r1(a.x), r1(a.y), r1(b.x), r1(a.y)]
  if (Math.abs(ang - Math.PI / 2) <= tol) return [r1(a.x), r1(a.y), r1(a.x), r1(b.y)]
  return [r1(a.x), r1(a.y), r1(b.x), r1(b.y)]
}

/** 点 p から線分 ab までの距離 */
export function distToSegment(p: Pt, a: Pt, b: Pt): number {
  const vx = b.x - a.x
  const vy = b.y - a.y
  const l2 = vx * vx + vy * vy
  if (!l2) return Math.hypot(p.x - a.x, p.y - a.y)
  const t = Math.max(0, Math.min(1, ((p.x - a.x) * vx + (p.y - a.y) * vy) / l2))
  return Math.hypot(p.x - (a.x + t * vx), p.y - (a.y + t * vy))
}

const r1 = (n: number) => Math.round(n * 10) / 10
