// ガントチャート共通: 表示倍率・配色・日付計算
import { daysBetween, iso, parseDate } from '@/lib/design'

export type GanttZoom = 'year' | 'quarter' | 'month' | 'week'

/** 表示倍率。dayW = 1日あたりの横幅（px） */
export const GANTT_ZOOMS: { key: GanttZoom; label: string; dayW: number }[] = [
  { key: 'year', label: '年', dayW: 1.6 },
  { key: 'quarter', label: '四半期', dayW: 3.2 },
  { key: 'month', label: '月', dayW: 8 },
  { key: 'week', label: '週', dayW: 22 },
]

/** 項目の色の候補 */
export const GANTT_COLORS = ['#3b50cc', '#2e9d62', '#e0533d', '#d98a1f', '#8b5cf6', '#0ea5a4', '#b85188', '#5b6b8c', '#b7681a', '#1c2024']

export function addDays(d: Date, n: number): Date {
  const r = new Date(d)
  r.setDate(r.getDate() + n)
  return r
}

/** 表示開始日から見た日付のインデックス（0 始まり。範囲外は負や上限超えになる） */
export function dayIndex(rangeStart: Date, isoDate: string): number {
  return daysBetween(rangeStart, parseDate(isoDate))
}

/** インデックスから ISO 日付へ */
export function isoAt(rangeStart: Date, idx: number): string {
  return iso(addDays(rangeStart, idx))
}

export function todayIso(): string {
  return iso(new Date())
}

/** 2026/4/1 の形式 */
export function fmtYmd(isoDate: string): string {
  const d = parseDate(isoDate)
  return `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`
}

/** 2026年4月 の形式 */
export function fmtYm(isoDate: string): string {
  const d = parseDate(isoDate)
  return `${d.getFullYear()}年${d.getMonth() + 1}月`
}

/**
 * 新規チャートの既定の表示期間。開始は今年度の 4/1（1〜3月は前年の 4/1）、
 * 終了は受験日（設定済みで開始より後なら）の月末、なければ 3 年分。
 */
export function defaultChartRange(examDate: string | null | undefined): { startOn: string; endOn: string } {
  const now = new Date()
  const fy = now.getMonth() < 3 ? now.getFullYear() - 1 : now.getFullYear()
  const start = new Date(fy, 3, 1)
  let end = new Date(fy + 3, 2, 31)
  if (examDate) {
    const ex = parseDate(examDate)
    if (ex > start) end = new Date(ex.getFullYear(), ex.getMonth() + 1, 0)
  }
  return { startOn: iso(start), endOn: iso(end) }
}
