import client from '@/api/client'
import type { CardDeck, CardItem, CardMode, CardStat } from '@/types'

/** 学習カード（鉄緑会 英文法・合格る確率・合格る計算）の API。生徒専用 */
export const cardsApi = {
  async decks(subject?: 'english' | 'math'): Promise<CardDeck[]> {
    const { data } = await client.get('/cards/decks', { params: subject ? { subject } : undefined })
    return data.data
  },

  /** 束の全カード（絞り込み・出題順は画面側） */
  async items(deckId: number): Promise<CardItem[]> {
    const { data } = await client.get(`/cards/decks/${deckId}/items`)
    return data.data
  },

  /** 問題・解答の画像 URL（要 Bearer。AuthImage で表示）。iv は画像の版（差し替え時のキャッシュ避け） */
  imageUrl(itemId: number, kind: 'problem' | 'answer', iv?: string): string {
    return `/cards/items/${itemId}/image/${kind}${iv ? `?v=${iv}` : ''}`
  },

  async attempt(
    itemId: number,
    payload: { mode: CardMode; isCorrect: boolean; isUnsure?: boolean; answer?: string | null; elapsedMs?: number | null; sessionId?: number | null },
  ): Promise<{ attemptId: number; stat: CardStat }> {
    const { data } = await client.post(`/cards/items/${itemId}/attempt`, payload)
    return data.data
  },

  /** 回答を変更する（isCorrect: 自己判定の変更 / isUnsure: 「あいまい」の付け外し） */
  async updateAttempt(attemptId: number, payload: { isCorrect?: boolean; isUnsure?: boolean }): Promise<{ attemptId: number; stat: CardStat }> {
    const { data } = await client.put(`/cards/attempts/${attemptId}`, payload)
    return data.data
  },

  /** チェックシート用: カードの no → [[日付, 0=不正解|1=正解|2=あいまい], ...] */
  async checksheet(deckId: number): Promise<Record<string, [string, 0 | 1 | 2][]>> {
    const { data } = await client.get(`/cards/decks/${deckId}/checksheet`, { params: { tz: -new Date().getTimezoneOffset() } })
    return data.data.marks ?? {}
  },
}
