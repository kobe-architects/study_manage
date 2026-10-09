// ガントチャート共通: 表示倍率・配色・日付計算
import { daysBetween, iso, parseDate } from '@/lib/design'

export type GanttZoom = 'fit' | 'year' | 'quarter' | 'month' | 'week'

/** 表示倍率。dayW = 1日あたりの横幅（px）。fit は枠の幅に合わせる（横スクロールなし） */
export const GANTT_ZOOMS: { key: GanttZoom; label: string; dayW: number }[] = [
  { key: 'fit', label: '全体', dayW: 0 },
  { key: 'year', label: '年', dayW: 1.6 },
  { key: 'quarter', label: '四半期', dayW: 3.2 },
  { key: 'month', label: '月', dayW: 8 },
  { key: 'week', label: '週', dayW: 22 },
]

/** 区間のひな形（学習の段階）。名前と色をまとめて入れる */
export const GANTT_PHASES: { label: string; color: string }[] = [
  { label: '範囲学習', color: '#dcdde1' },
  { label: '復習・演習', color: '#a9abb0' },
  { label: '過去問', color: '#35373b' },
]

/** 区間の色の候補（前半はひな形の灰色） */
export const GANTT_COLORS = ['#dcdde1', '#a9abb0', '#35373b', '#3b50cc', '#2e9d62', '#e0533d', '#d98a1f', '#8b5cf6', '#0ea5a4', '#b85188']

/** 暗い色か（上に白い文字を置くべきか） */
export function isDark(hex: string): boolean {
  const h = (hex || '').replace('#', '')
  if (h.length !== 6) return false
  const r = parseInt(h.slice(0, 2), 16)
  const g = parseInt(h.slice(2, 4), 16)
  const b = parseInt(h.slice(4, 6), 16)
  return (0.299 * r + 0.587 * g + 0.114 * b) / 255 < 0.55
}

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

/** 目盛りの区切り（年・月）。startIdx = 表示開始日からの日数、days = その区切りの日数 */
export interface Span {
  key: string
  year: number
  month: number // 年の区切りでは 0
  startIdx: number
  days: number
}

export function yearSpans(rangeStart: Date, rangeEnd: Date): Span[] {
  const out: Span[] = []
  let d = new Date(rangeStart)
  while (d <= rangeEnd) {
    const y = d.getFullYear()
    const yEnd = new Date(y, 11, 31)
    const segEnd = yEnd < rangeEnd ? yEnd : rangeEnd
    out.push({ key: String(y), year: y, month: 0, startIdx: daysBetween(rangeStart, d), days: daysBetween(d, segEnd) + 1 })
    d = new Date(y + 1, 0, 1)
  }
  return out
}

export function monthSpans(rangeStart: Date, rangeEnd: Date): Span[] {
  const out: Span[] = []
  let d = new Date(rangeStart)
  while (d <= rangeEnd) {
    const y = d.getFullYear()
    const m = d.getMonth()
    const mEnd = new Date(y, m + 1, 0)
    const segEnd = mEnd < rangeEnd ? mEnd : rangeEnd
    out.push({ key: `${y}-${m + 1}`, year: y, month: m + 1, startIdx: daysBetween(rangeStart, d), days: daysBetween(d, segEnd) + 1 })
    d = new Date(y, m + 1, 1)
  }
  return out
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
