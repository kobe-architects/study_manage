// 学習カード（数学学習の例題: 合格る確率・合格る計算・スタディサプリ ベーシックレベル数学）から間違いノートのページを自動で作る。
// 問題は教材の切り抜き（カードの問題画像）をそのまま、正解は文字データ（小問ごとの答え・あなたの答え・出典）を canvas で描いた画像、
// 教材の解説の切り抜き（カードの解答画像）を説明用の画像として一緒にサーバーへ送る（POST /mistake-notes/from-card/{id}）。
// 分類・デッキ・出典はサーバー（CardNoteClassifier）が決める。
import client from '@/api/client'
import { cardsApi } from '@/api/cards'
import { mistakeApi } from '@/api/mistake'
import type { CardAnswerResult } from '@/components/CardQuestion.vue'
import { INK, MUTED, PRIMARY, paintLines, type Line, type Seg } from '@/lib/grammarNote'
import type { CardItem, MistakeNoteDetail } from '@/types'

async function fetchImage(item: CardItem, kind: 'problem' | 'answer'): Promise<Blob | null> {
  try {
    const res = await client.get(cardsApi.imageUrl(item.id, kind, item.iv), { responseType: 'blob' })
    return res.data as Blob
  } catch {
    return null
  }
}

function header(item: CardItem, deckName: string | null): Line {
  const parts = [deckName, item.chapter, item.section, item.extra?.label ?? `No.${item.no}`].filter((v): v is string => !!v)
  return { segs: [{ text: parts.join('　'), color: MUTED, weight: 700 }], size: 26 }
}

/** 問題の画像が無いカード用: 見出しと問題の文字だけの画像 */
export function renderCardProblemImage(item: CardItem, deckName: string | null): Promise<Blob> {
  return paintLines([header(item, deckName), { segs: [{ text: item.front, weight: 500 }], size: 40, gapBefore: 20 }])
}

/** 正解の画像（小問ごとの答え・あなたの答え・ページ） */
export function renderCardAnswerImage(item: CardItem, result: CardAnswerResult | null, deckName: string | null): Promise<Blob> {
  const lines: Line[] = []
  const label = (t: string): Seg => ({ text: `${t}　`, color: MUTED, weight: 700, size: 28 })
  const subs = item.extra?.subs ?? []
  if (subs.length) {
    subs.forEach((s, i) => {
      const segs: Seg[] = [label(i === 0 ? '正解' : '　　')]
      if (s.label) segs.push({ text: `${s.label} `, color: PRIMARY, weight: 700 })
      segs.push({ text: s.answer, color: PRIMARY, weight: 700 })
      lines.push({ segs, size: 40, gapBefore: i === 0 ? 0 : 8 })
    })
    if (item.extra?.partial) lines.push({ segs: [{ text: '※ 一部の小問の答えです。残りは解説で確認してください', color: '#b7681a' }], size: 26, gapBefore: 10 })
  } else if (item.back) {
    lines.push({ segs: [label('正解'), { text: item.back, color: PRIMARY, weight: 700 }], size: 40 })
  } else {
    lines.push({ segs: [label('正解'), { text: '下の解説を参照', color: INK }], size: 34 })
  }
  if (result && !result.isCorrect && result.answer && result.answer !== 'わからない') {
    lines.push({ segs: [label('あなたの答え'), { text: result.answer, color: '#c0444f', weight: 700 }], size: 32, gapBefore: 18 })
  } else if (result && !result.isCorrect) {
    lines.push({ segs: [label('あなたの答え'), { text: 'わからない', color: '#c0444f' }], size: 30, gapBefore: 18 })
  }
  const foot: string[] = []
  if (deckName) foot.push(`出典: ${deckName}`)
  if (item.bookPage) foot.push(`p.${item.bookPage}`)
  if (foot.length) lines.push({ segs: [{ text: foot.join('　'), color: MUTED }], size: 26, gapBefore: 18 })
  return paintLines(lines)
}

/**
 * カードから間違いノートのページを作る（問題の切り抜き → 正解の文字 → 解説の切り抜き）。
 * すでに作ってあればサーバーがそのページを返す（created=false）。
 */
export async function createCardNote(item: CardItem, result: CardAnswerResult | null, deckName: string | null): Promise<{ note: MistakeNoteDetail; created: boolean }> {
  const problem = (item.hasProblemImage ? await fetchImage(item, 'problem') : null) ?? (await renderCardProblemImage(item, deckName))
  const answer = await renderCardAnswerImage(item, result, deckName)
  const explanation = item.hasAnswerImage ? await fetchImage(item, 'answer') : null
  return mistakeApi.createFromCard(item.id, { problem, answer, explanation })
}
