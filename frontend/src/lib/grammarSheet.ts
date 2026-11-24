// 英文法・語法（スクランブル）の学習記録チェックシート。
// 付属のチェックシート PDF（docs/other/Scramble英文法語法_…_連番版.pdf）と同じ並び（章 → Theme → 連番・形式・区分）で、
// 回数欄に解いた日付を入れる（正解=黒・不正解=赤・あいまい=青）。構造データは data/scrambleSheet.ts。
import { type SheetChapter, type SheetMark, type SheetRow, type SheetSpec, openCheckSheet } from '@/lib/checkSheet'
import { SHEET_CHAPTERS, SHEET_QUESTIONS, SHEET_THEMES } from '@/data/scrambleSheet'
import { chapterParts } from '@/lib/grammar'
import type { GrammarBookKey, GrammarQuestion } from '@/types'

/** API の marks（連番 → [[日付, 0|1|2], ...]） */
export type GrammarMarks = Record<string, [string, 0 | 1 | 2][]>

const TAG_CLASS: Record<string, string> = { 基本: '', 頻出: 'p', 超頻: 'pf', 発展: 'd' }

function tagsHtml(tags: string): string {
  if (!tags) return ''
  return tags
    .split('・')
    .map((t) => `<span class="tag ${TAG_CLASS[t] ?? ''}">${t}</span>`)
    .join('')
}

function toMarks(list: [string, 0 | 1 | 2][] | undefined): SheetMark[] {
  return (list ?? []).map(([date, status]) => ({ date, status }))
}

/** 文法（第1〜14章）・語法（第15〜19章）の両方（既定）か、片方だけのシートにするか */
export type GrammarSheetPart = 'both' | 'grammar' | 'usage'

export function buildGrammarSheetSpec(marks: GrammarMarks, name: string, outputDate: string, part: GrammarSheetPart = 'both', extra: Partial<SheetSpec> = {}): SheetSpec {
  const byNo = new Map<number, (typeof SHEET_QUESTIONS)[number]>()
  for (const q of SHEET_QUESTIONS) byNo.set(q[0], q)

  const chapterDefs = SHEET_CHAPTERS.filter(([no]) => (part === 'grammar' ? no <= 14 : part === 'usage' ? no >= 15 : true))
  const chapters: SheetChapter[] = chapterDefs.map(([no, title, from, to]) => {
    const rows: SheetRow[] = []
    for (let n = from; n <= to; n++) {
      const q = byNo.get(n)
      if (!q) continue
      const theme = q[1]
      rows.push({
        no: n,
        cells: [q[2], { html: tagsHtml(q[3]) }],
        marks: toMarks(marks[String(n)]),
        group: { key: String(theme), title: `Theme ${theme}`, name: SHEET_THEMES[theme] },
      })
    }
    return {
      key: no,
      label: `第${no}章`,
      title,
      sub: `${to - from + 1}問・No.${from}–${to}`,
      rows,
      newPage: no === 1 || no === 15,
      badge: no === 1 ? '文法' : no === 15 ? '語法' : undefined,
      headline:
        no === 1
          ? 'Part 1 文法 ・ 第1章〜第14章 ・ 全473問（No.1–473） ／ Theme 1〜124'
          : no === 15
            ? 'Part 2 語法 ・ 第15章〜第19章 ・ 全252問（No.474–725） ／ Theme 125〜183'
            : undefined,
    }
  })

  return {
    title: 'Scramble 英文法・語法',
    name,
    note: `出力日 ${outputDate}`,
    groupColumn: { label: 'Theme', width: 23 },
    noLabel: '連番',
    noWidth: 7,
    columns: [
      { label: '形式', width: 7.5 },
      { label: '区分', width: 13.5 },
    ],
    chapters,
    ...extra,
  }
}

/** チェックシートを開く（別タブ／アプリ内ビューア）。成功時 true */
export function openGrammarCheckSheet(marks: GrammarMarks, name: string, outputDate: string): boolean {
  return openCheckSheet(buildGrammarSheetSpec(marks, name, outputDate))
}

/** 語い以降の Part の見出し（問題集 → Part 名）。付属チェックシートにはこれらの表が無いので、問題データから同じ体裁で組む */
const BOOK_PART: Partial<Record<GrammarBookKey, { badge: string; part: string }>> = {
  vocab: { badge: '語い', part: 'Part 3 語い' },
  idiom: { badge: 'イディオム', part: 'Part 4 イディオム' },
  conversation: { badge: '会話表現', part: 'Part 5 会話表現' },
  pronunciation: { badge: '発音・アクセント', part: 'Part 6 発音・アクセント' },
  special: { badge: '読解・作文', part: 'Part 7 読解・作文の文法' },
}

/**
 * 文法・語法以外の問題集（語い・イディオム・会話表現・発音・アクセント・読解・作文の文法）のチェックシート。
 * 章 → Theme → 連番の並びは問題データ（chapterNo・theme・no）から組む。区分（重要度）があれば付ける。
 */
export function buildBookSheetSpec(book: GrammarBookKey, bookName: string, questions: GrammarQuestion[], marks: GrammarMarks, name: string, outputDate: string, extra: Partial<SheetSpec> = {}): SheetSpec {
  const sorted = questions.slice().sort((a, b) => a.chapterNo - b.chapterNo || a.no - b.no)
  const chapters: SheetChapter[] = []
  for (const q of sorted) {
    let ch = chapters[chapters.length - 1]
    if (!ch || (ch as SheetChapter & { _no?: number })._no !== q.chapterNo) {
      const parts = chapterParts(q.chapter)
      ch = Object.assign({ key: q.chapterNo, label: `第${q.chapterNo}章`, title: parts.name || q.chapter, rows: [] as SheetRow[] }, { _no: q.chapterNo }) as SheetChapter
      chapters.push(ch)
    }
    ch.rows.push({
      no: q.no,
      cells: [q.formatLabel || '', { html: tagsHtml(q.importance ?? '') }],
      marks: toMarks(marks[String(q.no)]),
      group: q.theme ? { key: String(q.theme), title: `Theme ${q.theme}` } : undefined,
    })
  }
  for (const ch of chapters) {
    const nos = ch.rows.map((r) => Number(r.no))
    ch.sub = `${ch.rows.length}問・No.${Math.min(...nos)}–${Math.max(...nos)}`
    delete (ch as SheetChapter & { _no?: number })._no
  }
  const info = BOOK_PART[book]
  const total = sorted.length
  const first = chapters[0]
  if (first) {
    first.newPage = true
    first.badge = info?.badge ?? bookName
    const cFrom = sorted[0]?.chapterNo
    const cTo = sorted[sorted.length - 1]?.chapterNo
    first.headline = `${info?.part ?? bookName} ・ 第${cFrom}章〜第${cTo}章 ・ 全${total}問`
  }
  return {
    title: 'Scramble 英文法・語法',
    name,
    note: `出力日 ${outputDate}`,
    groupColumn: { label: 'Theme', width: 16 },
    noLabel: '連番',
    noWidth: 8,
    columns: [
      { label: '形式', width: 11 },
      { label: '区分', width: 13.5 },
    ],
    chapters,
    ...extra,
  }
}

export function openBookCheckSheet(book: GrammarBookKey, bookName: string, questions: GrammarQuestion[], marks: GrammarMarks, name: string, outputDate: string): boolean {
  return openCheckSheet(buildBookSheetSpec(book, bookName, questions, marks, name, outputDate))
}
