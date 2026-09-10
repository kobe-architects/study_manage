import client from '@/api/client'
import type { AnnotationDoc, BookPdf, PdfPageMap, QuizBook, QuizDetail, QuizMark, QuizPageSpec, QuizRow, QuizStats, QuizSummary, StudyResource, Vocabulary } from '@/types'

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

/**
 * 認証付き PDF を別タブでプレビュー表示する（保存ダイアログを出さずブラウザのビューアで開く）。
 * ポップアップブロック回避のため、クリック直後（同期）に空タブを開いてから取得する。
 */
export async function previewPdf(url: string): Promise<void> {
  const w = window.open('', '_blank')
  try {
    const res = await client.get(url, { responseType: 'blob' })
    const blobUrl = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    if (w) w.location.replace(blobUrl)
    else window.open(blobUrl, '_blank')
    setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000)
  } catch (e) {
    w?.close()
    throw e
  }
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

function toForm(payload: object, renders: Record<number, { question: Blob; answer: Blob }>): FormData {
  const fd = new FormData()
  fd.append('payload', JSON.stringify(payload))
  for (const [idx, r] of Object.entries(renders)) {
    fd.append(`renders[${idx}]`, r.question, `q${idx}.jpg`)
    fd.append(`answerRenders[${idx}]`, r.answer, `a${idx}.jpg`)
  }
  return fd
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

  /** 担当生徒の単語帳一覧（英単語テスト出題用） */
  async resources(): Promise<StudyResource[]> {
    const { data } = await client.get(`${p()}/study-resources`)
    return data.data
  },

  async vocabularies(resourceId: number): Promise<Vocabulary[]> {
    const { data } = await client.get(`${p()}/study-resources/${resourceId}/vocabularies`)
    return data.data
  },

  /**
   * 出題。英単語テストのページがある場合は問題用紙／解答用紙の画像を multipart で同送する
   * （renders[index] / answerRenders[index]、index は pages 配列の位置）。
   */
  async create(
    payload: { title: string | null; note: string | null; dueOn: string | null; maxScore: number; bookId: number | null; pages: QuizPageSpec[] },
    renders: Record<number, { question: Blob; answer: Blob }> = {},
  ): Promise<number> {
    const { data } = await client.post(`${p()}/quizzes`, toForm(payload, renders))
    return data.data.id
  },

  async update(
    id: number,
    payload: { title?: string | null; note?: string | null; dueOn?: string | null; maxScore?: number; pages?: QuizPageSpec[] },
    renders: Record<number, { question: Blob; answer: Blob }> = {},
  ): Promise<void> {
    // multipart は PUT で解析されないため POST + _method
    const fd = toForm(payload, renders)
    fd.append('_method', 'PUT')
    await client.post(`${p()}/quizzes/${id}`, fd)
  },

  downloadAnswersPdf(id: number, title: string): Promise<void> {
    return downloadFile(`${p()}/quizzes/${id}/answers-pdf`, `${title}_解答.pdf`)
  },

  renderImageUrl(quizId: number, pageId: number): string {
    return `${p()}/quizzes/${quizId}/pages/${pageId}/render-image`
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

  /** 問題 PDF を別タブでプレビュー表示する。withAnswers は講師のみ有効（英単語テストの解答用紙を末尾に付ける） */
  previewQuizPdf(id: number, withAnswers = false): Promise<void> {
    return previewPdf(`${p()}/quizzes/${id}/download${withAnswers ? '?answers=1' : ''}`)
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
  submitted: '提出済み（採点・添削待ち）',
  graded: '採点・添削済み',
}

/**
 * 小テスト一覧を「箱」（1回の出題）ごとにまとめる。
 * groupKey が同じ行（教材ごとのパート）を1グループにし、グループ内は作成順（id 昇順）。
 */
export function groupQuizzes(list: QuizSummary[]): QuizSummary[][] {
  const map = new Map<string, QuizSummary[]>()
  const order: string[] = []
  for (const q of list) {
    const k = q.groupKey ?? `solo-${q.id}`
    if (!map.has(k)) {
      map.set(k, [])
      order.push(k)
    }
    map.get(k)!.push(q)
  }
  return order.map((k) => map.get(k)!.sort((a, b) => a.id - b.id))
}

/** 箱の代表ステータス（一覧のグループ分け用）。owner: 未提出優先 / tutor: 採点・添削待ち優先 */
export function groupStatus(parts: QuizSummary[], role: 'owner' | 'tutor'): 'assigned' | 'submitted' | 'graded' {
  const has = (s: string) => parts.some((q) => q.status === s)
  if (role === 'tutor') {
    if (has('submitted')) return 'submitted'
    if (has('assigned')) return 'assigned'
    return 'graded'
  }
  if (has('assigned')) return 'assigned'
  if (has('submitted')) return 'submitted'
  return 'graded'
}

export const MARK_LABEL: Record<QuizMark, string> = { o: '○', tri: '△', x: '×' }
export const MARK_COLOR: Record<QuizMark, string> = { o: '#2f9e5b', tri: '#d98a1a', x: '#cf4444' }

export function scoreForMark(mark: QuizMark, max: number): number {
  if (mark === 'o') return max
  if (mark === 'tri') return Math.ceil(max / 2)
  return 0
}
