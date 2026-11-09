// 学習カード（鉄緑会 英文法・合格る確率・合格る計算）の学習記録チェックシート。
// 章（週・Stage）→ カード（通し番号・種類・表／見出し）の並びで、回数欄に解いた日付を入れる（正解=黒・不正解=赤・あいまい=青）。
// 体裁はスクランブルのチェックシートと同じ部品（lib/checkSheet.ts）。
import { type SheetChapter, type SheetMark, type SheetRow, type SheetSpec, openCheckSheet } from '@/lib/checkSheet'
import type { CardDeck, CardItem } from '@/types'

export type CardMarks = Record<string, [string, 0 | 1 | 2][]>

function toMarks(list: [string, 0 | 1 | 2][] | undefined): SheetMark[] {
  return (list ?? []).map(([date, status]) => ({ date, status }))
}
function esc(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}
const KIND_TAG: Record<string, string> = { phrase: '表現', example: '例文', problem: '問題' }

/**
 * @param items 束の全カード（no 順）
 * @param targetIds 出すカード（null なら全カード）
 */
export function buildCardSheetSpec(deck: CardDeck, items: CardItem[], targetIds: Set<number> | null, marks: CardMarks, name: string, outputDate: string): SheetSpec {
  const isMath = deck.subject === 'math'
  const groups: { key: string; label?: string; title: string; rows: SheetRow[]; from: number; to: number }[] = []
  for (const it of items) {
    if (targetIds && !targetIds.has(it.id)) continue
    const key = String(it.chapterNo)
    let g = groups[groups.length - 1]
    if (!g || g.key !== key) {
      // 「前期 第9週 動名詞(1)」→ label「前期 第9週」+ title「動名詞(1)」 / 「Stage1 原理原則編」→ label「Stage1」+ title
      const m = it.chapter.match(/^((?:前期|後期)\s*第\d+週|Stage\s*\d+|\d+章)\s*(.*)$/)
      g = { key, label: m ? m[1] : undefined, title: m && m[2] ? m[2] : it.chapter, rows: [], from: it.no, to: it.no }
      groups.push(g)
    }
    g.to = it.no
    const text = isMath ? it.front : it.kind === 'example' ? it.front : `${it.front}${it.back ? `　${it.back}` : ''}`
    g.rows.push({
      no: it.no,
      cells: isMath
        ? [it.section ?? '', text]
        : [{ html: `<span class="tag ${it.kind === 'example' ? '' : 'p'}">${KIND_TAG[it.kind] ?? it.kind}</span>` }, text],
      marks: toMarks(marks[String(it.no)]),
      group: isMath ? undefined : { key: `${it.chapterNo}|${it.section ?? ''}`, title: (it.section ?? '').replace(/^(\d+\.\d+)\s*/, '$1 ').slice(0, 40) },
    })
  }
  const chapters: SheetChapter[] = groups.map((g) => ({
    label: g.label,
    title: g.title,
    sub: `${g.rows.length}${isMath ? '問' : '枚'}・No.${g.from}–${g.to}`,
    rows: g.rows,
  }))
  const total = chapters.reduce((n, c) => n + c.rows.length, 0)

  return {
    title: deck.name,
    badge: isMath ? '数学' : '英語',
    headline: `${targetIds ? '選択した範囲' : '全カード'} ・ ${total}${isMath ? '問' : '枚'}${total ? `（No.${groups[0]!.from}–${groups[groups.length - 1]!.to}）` : ''}`,
    name,
    note: `出力日 ${outputDate}`,
    noLabel: 'No',
    noWidth: 8,
    groupColumn: isMath ? undefined : { label: '項目', width: 22 },
    columns: isMath ? [{ label: 'ITEM', width: 30 }, { label: '問題', width: 16 }] : [{ label: '種類', width: 7 }, { label: '内容', width: 30 }],
    chapters,
    accent: isMath ? '#2f6fd6' : '#3a8a5c',
  }
}

/** チェックシートを開く（別タブ／アプリ内ビューア）。成功時 true */
export function openCardCheckSheet(...args: Parameters<typeof buildCardSheetSpec>): boolean {
  return openCheckSheet(buildCardSheetSpec(...args))
}

export { esc as escCardText }
