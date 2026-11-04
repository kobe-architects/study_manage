// タスク管理の計算（登録した 1 日／1 週あたりの量を、日別・週別の予定量に展開する）
import { iso } from '@/lib/design'
import type { StudyTask, TaskPeriod } from '@/types'

export const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土']
/** 曜日ボタンの並び（月曜始まり） */
export const WEEKDAY_ORDER = [1, 2, 3, 4, 5, 6, 0]
/** 単位の入力候補 */
export const UNIT_SUGGESTIONS = ['words', '語', '単元', 'ページ', '問', '章', '節', '回', '分', 'Lesson', 'UNIT']

export function startOfDay(d: Date): Date {
  const r = new Date(d)
  r.setHours(0, 0, 0, 0)
  return r
}

export function addDays(d: Date, n: number): Date {
  const r = new Date(d)
  r.setDate(r.getDate() + n)
  return r
}

/** 週の始まり（月曜） */
export function weekStart(d: Date): Date {
  const r = startOfDay(d)
  r.setDate(r.getDate() - ((r.getDay() + 6) % 7))
  return r
}

/** 週の 7 日（月〜日） */
export function weekDates(start: Date): Date[] {
  return Array.from({ length: 7 }, (_, i) => addDays(start, i))
}

/** その曜日に取り組むタスクか（weekdays が null・空なら毎日） */
export function onWeekday(task: Pick<StudyTask, 'weekdays'>, d: Date): boolean {
  return !task.weekdays || task.weekdays.length === 0 || task.weekdays.includes(d.getDay())
}

/** 開始日〜終了日の中か */
export function inPeriod(task: Pick<StudyTask, 'startOn' | 'endOn'>, d: Date): boolean {
  const s = iso(d)
  return s >= task.startOn && (!task.endOn || s <= task.endOn)
}

/** その日に表示するタスクか */
export function isActiveOn(task: StudyTask, d: Date): boolean {
  return task.isActive && onWeekday(task, d) && inPeriod(task, d)
}

/**
 * その日の予定量。
 * 1 日あたりのタスクは登録した量そのまま。1 週あたりのタスクは、その週の取り組む曜日の数で割る
 * （140 words / 週・毎日 → 20 words。割り切れない端数は後ろの曜日に寄せ、週の合計が登録した量にそろう）。
 */
export function quotaOn(task: StudyTask, d: Date): number {
  if (!isActiveOn(task, d)) return 0
  if (task.period === 'day') return task.amount
  const days = weekDates(weekStart(d)).filter((x) => onWeekday(task, x))
  const n = days.length
  if (!n) return 0
  const key = iso(d)
  const i = days.findIndex((x) => iso(x) === key)
  if (i < 0) return 0
  return Math.floor((task.amount * (i + 1)) / n) - Math.floor((task.amount * i) / n)
}

/** 週（月曜始まり）の予定量の合計（表示される日の分だけ） */
export function weekQuota(task: StudyTask, start: Date): number {
  return weekDates(start).reduce((sum, x) => sum + quotaOn(task, x), 0)
}

/** 「20 words」「1単元」。単位が英字で始まるときだけ間に空白を入れる */
export function fmtAmount(n: number | string, unit: string): string {
  const u = unit.trim()
  if (!u) return String(n)
  return /^[A-Za-z]/.test(u) ? `${n} ${u}` : `${n}${u}`
}

export function periodLabel(p: TaskPeriod): string {
  return p === 'day' ? '日' : '週'
}

/** 「140 words / 週」 */
export function fmtRate(task: Pick<StudyTask, 'amount' | 'unit' | 'period'>): string {
  return `${fmtAmount(task.amount, task.unit)} / ${periodLabel(task.period)}`
}

/** 取り組む曜日の表示（毎日 / 月・水・金 など） */
export function weekdaysLabel(weekdays: number[] | null): string {
  if (!weekdays || weekdays.length === 0 || weekdays.length === 7) return '毎日'
  const set = new Set(weekdays)
  if (set.size === 5 && [1, 2, 3, 4, 5].every((d) => set.has(d))) return '平日'
  if (set.size === 2 && set.has(0) && set.has(6)) return '土日'
  return WEEKDAY_ORDER.filter((d) => set.has(d))
    .map((d) => WEEKDAYS[d])
    .join('・')
}

/** 「11/5(水)」 */
export function fmtDate(d: Date): string {
  return `${d.getMonth() + 1}/${d.getDate()}(${WEEKDAYS[d.getDay()]})`
}

/** 「11/3(月)〜11/9(日)」 */
export function fmtWeek(start: Date): string {
  return `${fmtDate(start)}〜${fmtDate(addDays(start, 6))}`
}
