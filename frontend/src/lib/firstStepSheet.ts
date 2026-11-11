// 英文法ファーストステップ（高校リード問題集）の学習記録チェックシート。
// 章（第1章〜第20章・まとめのテスト 1〜5・総合問題 1・2）→ 区分（STEP 1 / STEP 2 / まとめのテスト / 総合問題、ページ）→ 小問（連番・大問と番号・形式）
// の並びで、回数欄に解いた日付を入れる（正解=黒・不正解=赤・あいまい=青）。構造は問題データ（grammar_questions の first_step）そのものから作る。
import { type SheetChapter, type SheetMark, type SheetRow, type SheetSpec, openCheckSheet } from '@/lib/checkSheet'
import type { GrammarMarks } from '@/lib/grammarSheet'
import type { GrammarQuestion } from '@/types'

const FORMAT_SHORT: Record<string, string> = { 記号選択: '記号', 記入: '記入', 記述: '記述' }

function toMarks(list: [string, 0 | 1 | 2][] | undefined): SheetMark[] {
  return (list ?? []).map(([date, status]) => ({ date, status }))
}

/** 「p.5 STEP 1・1A〜C (1)」→ 「1A〜C (1)」 */
function subLabel(q: GrammarQuestion): string {
  const s = q.source ?? ''
  const i = s.indexOf('・')
  return i >= 0 ? s.slice(i + 1) : s
}

export function buildFirstStepSheetSpec(questions: GrammarQuestion[], marks: GrammarMarks, name: string, outputDate: string): SheetSpec {
  const sorted = questions.slice().sort((a, b) => a.no - b.no)
  const byChapter = new Map<number, GrammarQuestion[]>()
  for (const q of sorted) {
    const list = byChapter.get(q.chapterNo) ?? []
    list.push(q)
    byChapter.set(q.chapterNo, list)
  }
  const chapters: SheetChapter[] = []
  for (const [chapterNo, list] of Array.from(byChapter.entries()).sort((a, b) => a[0] - b[0])) {
    const chapter = list[0]!.chapter
    const m = chapter.match(/^第(\d+)章\s*(.*)$/)
    const t = chapter.match(/^(.*?)（(.*)）$/)
    const rows: SheetRow[] = list.map((q) => ({
      no: q.no,
      cells: [subLabel(q), FORMAT_SHORT[q.formatLabel] ?? q.formatLabel],
      marks: toMarks(marks[String(q.no)]),
      group: { key: `${q.theme}-${q.importance ?? ''}`, title: q.importance ?? '', name: `p.${q.theme}` },
    }))
    const from = list[0]!.no
    const to = list[list.length - 1]!.no
    chapters.push({
      label: m ? `第${m[1]}章` : undefined,
      title: m ? (m[2] ?? chapter) : t ? (t[1] ?? chapter) : chapter,
      sub: `${list.length}問・No.${from}–${to}${t ? `・${t[2]}` : ''}`,
      rows,
      newPage: chapterNo === 1,
    })
  }
  return {
    title: '英文法ファーストステップ',
    headline: `高校リード問題集 英文法ファーストステップ ・ STEP 1／STEP 2／まとめのテスト／総合問題 ・ 全${sorted.length}問`,
    name,
    note: `出力日 ${outputDate}`,
    accent: '#2f6fd6',
    groupColumn: { label: '区分', width: 13 },
    noLabel: '連番',
    noWidth: 7.5,
    columns: [
      { label: '問', width: 16 },
      { label: '形式', width: 7 },
    ],
    chapters,
  }
}

/** チェックシートを開く（別タブ／アプリ内ビューア）。成功時 true */
export function openFirstStepCheckSheet(questions: GrammarQuestion[], marks: GrammarMarks, name: string, outputDate: string): boolean {
  return openCheckSheet(buildFirstStepSheetSpec(questions, marks, name, outputDate))
}
