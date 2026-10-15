/**
 * 消しゴムの計算（間違いノートの手書き）。線は [x0, y0, x1, y1, ...] の折れ線、消しゴムは中心 (cx, cy)・半径 r の円。
 * - strokeHits: 線が円に触れているか（オブジェクト消し: 触れた線をまるごと消す）
 * - clipStroke: 線から円の内側を取り除き、残った部分を線の配列で返す（ピクセル消し）。触れていなければ null
 *   点が円の中にあるかだけでなく、線分が円を横切る所も計算するので、図形補正した長い直線（点が 2 つ）も途中だけ消せる
 */
import { distToSegment } from '@/lib/inkShapes'

export function strokeHits(pts: number[], cx: number, cy: number, r: number): boolean {
  const n = pts.length / 2
  if (n === 1) return Math.hypot(pts[0]! - cx, pts[1]! - cy) <= r
  for (let i = 0; i + 1 < n; i++) {
    if (distToSegment({ x: cx, y: cy }, { x: pts[2 * i]!, y: pts[2 * i + 1]! }, { x: pts[2 * i + 2]!, y: pts[2 * i + 3]! }) <= r) return true
  }
  return false
}

export function clipStroke(pts: number[], cx: number, cy: number, r: number): number[][] | null {
  const n = pts.length / 2
  const r2 = r * r
  const inside = (x: number, y: number) => (x - cx) ** 2 + (y - cy) ** 2 <= r2
  if (n < 2) return n && inside(pts[0]!, pts[1]!) ? [] : null

  const out: number[][] = []
  let cur: number[] = []
  let touched = false
  const flush = () => {
    if (cur.length >= 4) out.push(cur)
    cur = []
  }
  for (let i = 0; i + 1 < n; i++) {
    const ax = pts[2 * i]!
    const ay = pts[2 * i + 1]!
    const bx = pts[2 * i + 2]!
    const by = pts[2 * i + 3]!
    const aIn = inside(ax, ay)
    const bIn = inside(bx, by)
    if (aIn && bIn) {
      touched = true
      flush()
      continue
    }
    // 線分 A→B と円の交点（t は A からの割合。t1 で入り t2 で出る）
    const dx = bx - ax
    const dy = by - ay
    const a = dx * dx + dy * dy
    let t1 = Infinity
    let t2 = -Infinity
    if (a > 0) {
      const fx = ax - cx
      const fy = ay - cy
      const b = 2 * (fx * dx + fy * dy)
      const c = fx * fx + fy * fy - r2
      const disc = b * b - 4 * a * c
      if (disc > 0) {
        const s = Math.sqrt(disc)
        t1 = (-b - s) / (2 * a)
        t2 = (-b + s) / (2 * a)
      }
    }
    if (!aIn && !bIn) {
      if (!cur.length) cur.push(ax, ay)
      if (t1 > 0 && t2 < 1 && t1 < t2) {
        // 途中で円を横切る: 入る所までを残し、出た所から新しい線を始める
        touched = true
        cur.push(r1(ax + dx * t1), r1(ay + dy * t1))
        flush()
        cur.push(r1(ax + dx * t2), r1(ay + dy * t2), bx, by)
      } else {
        cur.push(bx, by)
      }
    } else if (!aIn && bIn) {
      // 円に入る: 入る所までを残す
      touched = true
      if (!cur.length) cur.push(ax, ay)
      const t = Math.min(Math.max(t1, 0), 1)
      cur.push(r1(ax + dx * t), r1(ay + dy * t))
      flush()
    } else {
      // 円から出る: 出た所から新しい線を始める
      touched = true
      flush()
      const t = Math.min(Math.max(t2, 0), 1)
      cur.push(r1(ax + dx * t), r1(ay + dy * t), bx, by)
    }
  }
  flush()
  return touched ? out : null
}

const r1 = (n: number) => Math.round(n * 10) / 10
