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

  async attempt(questionId: number, payload: { isCorrect: boolean; answer: string | null }): Promise<{ attemptId: number; stat: GrammarStat }> {
    const { data } = await client.post(`/grammar/questions/${questionId}/attempt`, payload)
    return data.data
  },

  /** 自己判定で正誤を変更する */
  async updateAttempt(attemptId: number, isCorrect: boolean): Promise<{ attemptId: number; stat: GrammarStat }> {
    const { data } = await client.put(`/grammar/attempts/${attemptId}`, { isCorrect })
    return data.data
  },
}
