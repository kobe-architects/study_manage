// 英文法・語法（スクランブル）の学習記録チェックシート。
// 付属のチェックシート PDF（docs/other/Scramble英文法語法_…_連番版.pdf）と同じ並び（章 → Theme → 連番・形式・区分）で、
// 回数欄に解いた日付を入れる（正解=黒・不正解=赤・あいまい=青）。構造データは data/scrambleSheet.ts。
import { type SheetChapter, type SheetMark, type SheetRow, type SheetSpec, openCheckSheet } from '@/lib/checkSheet'
import { SHEET_CHAPTERS, SHEET_QUESTIONS, SHEET_THEMES } from '@/data/scrambleSheet'

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

export function buildGrammarSheetSpec(marks: GrammarMarks, name: string, outputDate: string): SheetSpec {
  const byNo = new Map<number, (typeof SHEET_QUESTIONS)[number]>()
  for (const q of SHEET_QUESTIONS) byNo.set(q[0], q)

  const chapters: SheetChapter[] = SHEET_CHAPTERS.map(([no, title, from, to]) => {
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
  }
}

/** チェックシートを開く（別タブ／アプリ内ビューア）。成功時 true */
export function openGrammarCheckSheet(marks: GrammarMarks, name: string, outputDate: string): boolean {
  return openCheckSheet(buildGrammarSheetSpec(marks, name, outputDate))
}
