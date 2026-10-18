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
