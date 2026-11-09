import client from '@/api/client'
import type { GrammarBook, GrammarBookKey, GrammarQuestion, GrammarStat } from '@/types'

/** 英文法・語法 問題演習（スクランブル）の API。生徒専用 */
export const grammarApi = {
  async books(): Promise<GrammarBook[]> {
    const { data } = await client.get('/grammar/books')
    return data.data
  },

  /** 1 冊分の問題を全件取得（絞り込みは画面側） */
  async questions(book: GrammarBookKey): Promise<GrammarQuestion[]> {
    const { data } = await client.get('/grammar/questions', { params: { book } })
    return data.data
  },

  /** 問題・解答欄・解説の画像 URL（要 Bearer。AuthImage で表示） */
  imageUrl(questionId: number, kind: 'problem' | 'answer' | 'explanation'): string {
    return `/grammar/questions/${questionId}/image/${kind}`
  },

  /** 教材の解説ページの文章（詳細解説） */
  async explanation(questionId: number): Promise<{ id: number; explanation: string | null; explanationPage: number | null }> {
    const { data } = await client.get(`/grammar/questions/${questionId}/explanation`)
    return data.data
  },

  async attempt(questionId: number, payload: { isCorrect: boolean; answer: string | null; isUnsure?: boolean; elapsedMs?: number | null; sessionId?: number | null }): Promise<{ attemptId: number; stat: GrammarStat }> {
    const { data } = await client.post(`/grammar/questions/${questionId}/attempt`, payload)
    return data.data
  },

  /** 回答を変更する（isCorrect: 自己判定で正誤を変更 / isUnsure: 「あいまい」の付け外し） */
  async updateAttempt(attemptId: number, payload: { isCorrect?: boolean; isUnsure?: boolean }): Promise<{ attemptId: number; stat: GrammarStat }> {
    const { data } = await client.put(`/grammar/attempts/${attemptId}`, payload)
    return data.data
  },

  /** チェックシート用: 文法・語法 725 問の日ごとの結果（連番 → [[日付, 0=不正解|1=正解|2=あいまい], ...]） */
  async checksheet(): Promise<Record<string, [string, 0 | 1 | 2][]>> {
    const { data } = await client.get('/grammar/checksheet', { params: { tz: -new Date().getTimezoneOffset() } })
    return data.data.marks ?? {}
  },
}
