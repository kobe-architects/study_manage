import client from '@/api/client'
import type { QuestionMessage, QuestionStatus, QuestionThread, QuestionThreadDetail } from '@/types'

/** API プレフィックス。講師ログイン時は /tutor 配下（担当生徒のスレッドを同じように読み書きする） */
function p(): string {
  return localStorage.getItem('sm_role') === 'tutor' ? '/tutor' : ''
}

const CHUNK_SIZE = 4 * 1024 * 1024 // 4MB（共有サーバーの post_max_size を超えないサイズ）

/** 送信前の添付（画面側で持つ。uploadId はサーバーへ片を送ったあとに付く） */
export interface PendingAttachment {
  key: string
  file: Blob
  name: string
  kind: 'image' | 'file'
  /** 画像のプレビュー（object URL） */
  previewUrl: string | null
}

export interface QuestionCategoryInput {
  subjectId?: number | null
  majorId?: number | null
  midId?: number | null
}

/** 質問事項（生徒 ⇄ 講師の質問スレッド）の API */
export const questionsApi = {
  prefix: p,

  async list(): Promise<{ threads: QuestionThread[]; unreadThreads: number }> {
    const { data } = await client.get(`${p()}/questions`)
    return { threads: data.data, unreadThreads: data.unreadThreads ?? 0 }
  },

  async unreadCount(): Promise<{ threads: number; messages: number }> {
    const { data } = await client.get(`${p()}/questions/unread-count`)
    return data.data
  },

  async show(id: number): Promise<QuestionThreadDetail> {
    const { data } = await client.get(`${p()}/questions/${id}`)
    return data.data
  },

  /**
   * 添付を片（チャンク）でアップロードして uploadId を返す。メッセージ送信時に uploadId・名前・種類を渡して確定する
   */
  async uploadAttachment(file: Blob, onProgress?: (ratio: number) => void): Promise<string> {
    const uploadId = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`
    const total = Math.max(1, Math.ceil(file.size / CHUNK_SIZE))
    for (let i = 0; i < total; i++) {
      const fd = new FormData()
      fd.append('uploadId', uploadId)
      fd.append('index', String(i))
      fd.append('total', String(total))
      fd.append('chunk', file.slice(i * CHUNK_SIZE, Math.min(file.size, (i + 1) * CHUNK_SIZE)), 'chunk.bin')
      await client.post(`${p()}/questions/attachments/chunk`, fd)
      onProgress?.((i + 1) / total)
    }
    return uploadId
  },

  /** 添付をすべてアップロードして送信用の配列にする */
  async prepareAttachments(items: PendingAttachment[], onProgress?: (done: number, total: number) => void): Promise<{ uploadId: string; name: string; mime: string | null }[]> {
    const out: { uploadId: string; name: string; mime: string | null }[] = []
    for (let i = 0; i < items.length; i++) {
      const a = items[i]!
      const uploadId = await this.uploadAttachment(a.file)
      out.push({ uploadId, name: a.name, mime: a.file.type || null })
      onProgress?.(i + 1, items.length)
    }
    return out
  },

  async createThread(input: QuestionCategoryInput & { title?: string | null; body: string; notifyLine: boolean; attachments: { uploadId: string; name: string; mime: string | null }[] }): Promise<QuestionThreadDetail> {
    const { data } = await client.post(`${p()}/questions`, input)
    return data.data
  },

  async sendMessage(threadId: number, input: { body: string; notifyLine: boolean; attachments: { uploadId: string; name: string; mime: string | null }[] }): Promise<QuestionMessage> {
    const { data } = await client.post(`${p()}/questions/${threadId}/messages`, input)
    return data.data
  },

  async updateThread(id: number, patch: QuestionCategoryInput & { title?: string | null; status?: QuestionStatus }): Promise<QuestionThread> {
    const { data } = await client.put(`${p()}/questions/${id}`, patch)
    return data.data
  },

  async removeThread(id: number): Promise<void> {
    await client.delete(`${p()}/questions/${id}`)
  },

  /** 本文の編集（本人だけ） */
  async updateMessage(id: number, body: string): Promise<QuestionMessage> {
    const { data } = await client.put(`${p()}/question-messages/${id}`, { body })
    return data.data
  },

  async removeMessage(id: number): Promise<void> {
    await client.delete(`${p()}/question-messages/${id}`)
  },

  /** 添付の URL（要 Bearer。画像は AuthImage で表示、ファイルは fetchAttachment → saveFile） */
  attachmentUrl(id: number, thumb = false): string {
    return `/api${p()}/question-attachments/${id}${thumb ? '?v=thumb' : ''}`
  },

  async fetchAttachment(id: number): Promise<Blob> {
    const res = await client.get(`${p()}/question-attachments/${id}`, { responseType: 'blob' })
    return res.data
  },
}
