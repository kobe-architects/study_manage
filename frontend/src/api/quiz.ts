import client from '@/api/client'
import type { AnnotationDoc, BookPdf, PdfPageMap, QuizBook, QuizDetail, QuizMark, QuizPageSpec, QuizRow, QuizStats, QuizSummary } from '@/types'

/** API プレフィックス。家庭教師ログイン時は /tutor 配下の生徒スコープ API を使う */
function p(): string {
  return localStorage.getItem('sm_role') === 'tutor' ? '/tutor' : ''
}

function token(): string {
  return localStorage.getItem('sm_token') ?? ''
}

const CHUNK_SIZE = 4 * 1024 * 1024 // 4MB（共有サーバーの post_max_size を超えないサイズ）

/** 認証付き URL を取得して ObjectURL にする（画像・PDF の表示用） */
export async function fetchBlobUrl(url: string): Promise<string> {
  const res = await client.get(url, { responseType: 'blob' })
  return URL.createObjectURL(res.data)
}

/** 認証付き URL のファイルをダウンロード（保存ダイアログ） */
export async function downloadFile(url: string, filename: string): Promise<void> {
  const res = await client.get(url, { responseType: 'blob' })
  const blobUrl = URL.createObjectURL(res.data)
  const a = document.createElement('a')
  a.href = blobUrl
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000)
}

export const quizApi = {
  prefix: p,
  token,

  // ===== 教材への PDF 紐づけ =====
  async listPdfs(bookId: number): Promise<BookPdf[]> {
    const { data } = await client.get(`${p()}/resource-books/${bookId}/pdfs`)
    return data.data
  },

  /** 分割アップロード → 確定。onProgress は 0..1 */
  async uploadPdf(
    bookId: number,
    file: File,
    meta: { title: string; pageMap: PdfPageMap; pageOffset: number },
    onProgress?: (ratio: number) => void,
  ): Promise<BookPdf> {
    const uploadId = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`
    const total = Math.max(1, Math.ceil(file.size / CHUNK_SIZE))
    for (let i = 0; i < total; i++) {
      const fd = new FormData()
      fd.append('uploadId', uploadId)
      fd.append('index', String(i))
      fd.append('total', String(total))
      fd.append('chunk', file.slice(i * CHUNK_SIZE, Math.min(file.size, (i + 1) * CHUNK_SIZE)), 'chunk.bin')
      await client.post(`${p()}/resource-books/${bookId}/pdfs/chunk`, fd)
      onProgress?.((i + 1) / total)
    }
    const { data } = await client.post(`${p()}/resource-books/${bookId}/pdfs`, { uploadId, ...meta })
    return data.data
  },

  async updatePdf(id: number, patch: { title?: string; pageMap?: PdfPageMap; pageOffset?: number }): Promise<BookPdf> {
    const { data } = await client.put(`${p()}/resource-book-pdfs/${id}`, patch)
    return data.data
  },

  async deletePdf(id: number): Promise<void> {
    await client.delete(`${p()}/resource-book-pdfs/${id}`)
  },

  /** PDF 全体の URL（要 Bearer） */
  pdfFileUrl(id: number): string {
    return `/api${p()}/resource-book-pdfs/${id}/file`
  },

  /** 1ページだけの小さな PDF（サムネイル・プレビュー描画用、要 Bearer） */
  pdfPageUrl(id: number, page: number): string {
    return `/api${p()}/resource-book-pdfs/${id}/pages/${page}`
  },

  // ===== 出題（講師） =====
  async books(): Promise<QuizBook[]> {
    const { data } = await client.get(`${p()}/quiz-books`)
    return data.data
  },

  async rows(bookId: number): Promise<QuizRow[]> {
    const { data } = await client.get(`${p()}/resource-books/${bookId}/quiz-rows`)
    return data.data
  },

  async create(payload: {
    title: string | null
    note: string | null
    dueOn: string | null
    maxScore: number
    bookId: number
    pages: QuizPageSpec[]
  }): Promise<number> {
    const { data } = await client.post(`${p()}/quizzes`, payload)
    return data.data.id
  },

  async update(
    id: number,
    payload: { title?: string | null; note?: string | null; dueOn?: string | null; maxScore?: number; pages?: QuizPageSpec[] },
  ): Promise<void> {
    await client.put(`${p()}/quizzes/${id}`, payload)
  },

  async remove(id: number): Promise<void> {
    await client.delete(`${p()}/quizzes/${id}`)
  },

  // ===== 一覧・詳細（両方） =====
  async list(): Promise<QuizSummary[]> {
    const { data } = await client.get(`${p()}/quizzes`)
    return data.data
  },

  async show(id: number): Promise<QuizDetail> {
    const { data } = await client.get(`${p()}/quizzes/${id}`)
    return data.data
  },

  async stats(): Promise<QuizStats> {
    const { data } = await client.get(`${p()}/quizzes/stats`)
    return data.data
  },

  downloadQuizPdf(id: number, title: string): Promise<void> {
    return downloadFile(`${p()}/quizzes/${id}/download`, `${title}.pdf`)
  },

  downloadResultPdf(id: number, title: string): Promise<void> {
    return downloadFile(`${p()}/quizzes/${id}/result-pdf`, `${title}_添削.pdf`)
  },

  answerImageUrl(quizId: number, pageId: number, version: number | null): string {
    return `${p()}/quizzes/${quizId}/pages/${pageId}/answer-image?v=${version ?? 0}`
  },

  annotatedImageUrl(quizId: number, pageId: number, version: number | null): string {
    return `${p()}/quizzes/${quizId}/pages/${pageId}/annotated-image?v=${version ?? 0}`
  },

  // ===== 提出（生徒） =====
  async uploadAnswer(quizId: number, pageId: number, image: Blob): Promise<void> {
    const fd = new FormData()
    fd.append('image', image, 'answer.jpg')
    await client.post(`/quizzes/${quizId}/pages/${pageId}/answer`, fd)
  },

  async submit(quizId: number): Promise<void> {
    await client.post(`/quizzes/${quizId}/submit`)
  },

  // ===== 添削・採点（講師） =====
  async saveAnnotations(quizId: number, pageId: number, annotations: AnnotationDoc | null, image: Blob | null): Promise<void> {
    const fd = new FormData()
    fd.append('annotations', annotations ? JSON.stringify(annotations) : '')
    if (image) fd.append('image', image, 'annotated.jpg')
    await client.post(`${p()}/quizzes/${quizId}/pages/${pageId}/annotations`, fd)
  },

  async grade(quizId: number, pageId: number, payload: { mark: QuizMark | null; score: number | null; comment: string | null }): Promise<void> {
    await client.put(`${p()}/quizzes/${quizId}/pages/${pageId}/grade`, payload)
  },

  async finish(quizId: number): Promise<void> {
    await client.post(`${p()}/quizzes/${quizId}/finish`)
  },

  async reopen(quizId: number): Promise<void> {
    await client.post(`${p()}/quizzes/${quizId}/reopen`)
  },
}

/** 表示用ヘルパー */
export const QUIZ_STATUS_LABEL: Record<string, string> = {
  assigned: '未提出',
  submitted: '提出済み（添削待ち）',
  graded: '添削済み',
}

export const MARK_LABEL: Record<QuizMark, string> = { o: '○', tri: '△', x: '×' }
export const MARK_COLOR: Record<QuizMark, string> = { o: '#2f9e5b', tri: '#d98a1a', x: '#cf4444' }

export function scoreForMark(mark: QuizMark, max: number): number {
  if (mark === 'o') return max
  if (mark === 'tri') return Math.ceil(max / 2)
  return 0
}
