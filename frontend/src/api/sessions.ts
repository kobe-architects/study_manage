import client from '@/api/client'
import type { QuizSessionInfo, SessionKind } from '@/types'

/**
 * 1 回の出題（セッション）の記録。出題開始で start、終了（中断を含む）で finish を呼ぶ。
 * 回答ごとの解答時間は各回答（attempt）に付け、finish でサーバーが集計する。
 */
export const sessionsApi = {
  async start(payload: { kind: SessionKind; refId?: number | null; refKey?: string | null; mode?: string | null }): Promise<QuizSessionInfo> {
    const { data } = await client.post('/quiz-sessions', payload)
    return data.data
  },

  /** 終了。回答が 1 つも無いセッションは削除される（null が返る） */
  async finish(id: number): Promise<QuizSessionInfo | null> {
    const { data } = await client.post(`/quiz-sessions/${id}/finish`)
    return data.data
  },

  async list(params: { kind: SessionKind; refId?: number | null; refKey?: string | null; limit?: number }): Promise<QuizSessionInfo[]> {
    const { data } = await client.get('/quiz-sessions', { params })
    return data.data
  },
}

/** 解答時間（ミリ秒）の表示（4.2秒 / 1分12秒） */
export function fmtMs(ms: number | null | undefined): string {
  if (ms === null || ms === undefined) return '—'
  const s = ms / 1000
  if (s < 60) return `${s < 10 ? s.toFixed(1) : Math.round(s)}秒`
  const m = Math.floor(s / 60)
  return `${m}分${Math.round(s - m * 60)}秒`
}
