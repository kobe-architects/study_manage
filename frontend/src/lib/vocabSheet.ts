// 英単語帳（鉄壁・LEAP basic など）の学習記録チェックシート。
// セクション → 単語（通し番号・単語・意味）の並びで、回数欄に解いた日付を入れる（正解=黒・不正解=赤・あいまい=青）。
// 体裁はスクランブルのチェックシートと同じ部品（lib/checkSheet.ts）だが、用紙は A4 横 1 段で回数欄を過去 30 回ぶん並べる。
import { type SheetChapter, type SheetMark, type SheetRow, type SheetSpec, openCheckSheet } from '@/lib/checkSheet'
import type { StudyResourceSection, Vocabulary } from '@/types'

/** API の marks（単語 ID → [[日付, 0|1|2], ...]） */
export type VocabMarks = Record<string, [string, 0 | 1 | 2][]>

/** セクション名を見出しの「小さい部分」と「大きい部分」に分ける（「Part 1 / Week 1（1〜40）」「SECTION #1 重要な・ささいな」） */
function splitSectionName(name: string): { label?: string; title: string } {
  if (name.includes(' / ')) {
    const [label, ...rest] = name.split(' / ')
    return { label, title: rest.join(' / ') }
  }
  const m = name.match(/^(SECTION\s*#?\s*\d+|Section\s*\d+|Part\s*\d+|第\d+[章節回])\s*(.*)$/)
  if (m && m[2]) return { label: m[1], title: m[2] }
  return { title: name }
}

function toMarks(list: [string, 0 | 1 | 2][] | undefined): SheetMark[] {
  return (list ?? []).map(([date, status]) => ({ date, status }))
}

function esc(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

/**
 * @param allItems 単語帳の全単語（セクション順・並び順。配列位置 + 1 = 通し番号 No.）
 * @param targetIds 出す単語（null なら全単語）
 */
export function buildVocabSheetSpec(
  resourceName: string,
  sections: StudyResourceSection[],
  allItems: Vocabulary[],
  targetIds: Set<number> | null,
  marks: VocabMarks,
  name: string,
  outputDate: string,
): SheetSpec {
  const secName = new Map<number, string>()
  sections.forEach((s) => secName.set(s.id, s.name))

  // セクションごとに（並び順のまま）まとめる
  const groups: { sectionId: number; rows: SheetRow[]; from: number; to: number }[] = []
  allItems.forEach((w, i) => {
    const no = i + 1
    if (targetIds && !targetIds.has(w.id)) return
    let g = groups[groups.length - 1]
    if (!g || g.sectionId !== w.sectionId) {
      g = { sectionId: w.sectionId, rows: [], from: no, to: no }
      groups.push(g)
    }
    g.to = no
    g.rows.push({
      no,
      cells: [
        { html: `${esc(w.word)}${w.importance ? `<span class="star">${'★'.repeat(w.importance)}</span>` : ''}` },
        w.meaning,
      ],
      marks: toMarks(marks[String(w.id)]),
    })
  })

  const chapters: SheetChapter[] = groups.map((g) => {
    const { label, title } = splitSectionName(secName.get(g.sectionId) ?? '')
    return { label, title, sub: `${g.rows.length}語・No.${g.from}–${g.to}`, rows: g.rows }
  })

  const total = chapters.reduce((n, c) => n + c.rows.length, 0)
  return {
    title: resourceName,
    badge: '英単語',
    headline: `${targetIds ? '選択した範囲' : '全セクション'} ・ ${total}語${total ? `（No.${groups[0]!.from}–${groups[groups.length - 1]!.to}）` : ''}`,
    name,
    note: `出力日 ${outputDate}`,
    noLabel: 'No',
    noWidth: 7.5,
    columns: [
      { label: '単語', width: 34 },
      { label: '意味', width: 44 },
    ],
    chapters,
    accent: '#2f5fc4',
    // 英単語は何度も繰り返すので、A4 横 1 段で回数欄を 30 回ぶん並べる（多い回は新しい方から）
    layout: 'landscape1',
    markCount: 30,
  }
}

/** チェックシートを開く（別タブ／アプリ内ビューア）。成功時 true */
export function openVocabCheckSheet(...args: Parameters<typeof buildVocabSheetSpec>): boolean {
  return openCheckSheet(buildVocabSheetSpec(...args))
}
