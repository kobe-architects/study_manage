import client from '@/api/client'
import type { MistakeImage, MistakeLayout, MistakeNoteDetail, MistakeNoteSummary } from '@/types'

export interface MistakeMetaInput {
  subjectId?: number | null
  majorId?: number | null
  midId?: number | null
  studyItemId?: number | null
  title?: string | null
  source?: string | null
  notedOn?: string
}

/** 間違いノート API（生徒のみ）。画像は multipart、それ以外は JSON */
export const mistakeApi = {
  /** 一覧（バインダーのページ順: 科目 → 分野 → 並び順） */
  async list(): Promise<MistakeNoteSummary[]> {
    const { data } = await client.get('/mistake-notes')
    return data.data
  },

  /** 新規作成。対象の問題の写真が必須 */
  async create(image: Blob, meta: MistakeMetaInput, layout: MistakeLayout): Promise<MistakeNoteDetail> {
    const fd = new FormData()
    fd.append('image', image, 'problem.jpg')
    if (meta.subjectId) fd.append('subjectId', String(meta.subjectId))
    if (meta.majorId) fd.append('majorId', String(meta.majorId))
    if (meta.midId) fd.append('midId', String(meta.midId))
    if (meta.studyItemId) fd.append('studyItemId', String(meta.studyItemId))
    if (meta.title) fd.append('title', meta.title)
    if (meta.source) fd.append('source', meta.source)
    if (meta.notedOn) fd.append('notedOn', meta.notedOn)
    fd.append('layout', JSON.stringify(layout))
    const { data } = await client.post('/mistake-notes', fd)
    return data.data
  },

  async show(id: number): Promise<MistakeNoteDetail> {
    const { data } = await client.get(`/mistake-notes/${id}`)
    return data.data
  },

  async update(id: number, patch: MistakeMetaInput & { layout?: MistakeLayout; hidden?: boolean }): Promise<MistakeNoteDetail> {
    const { data } = await client.put(`/mistake-notes/${id}`, patch)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await client.delete(`/mistake-notes/${id}`)
  },

  async reorder(ids: number[]): Promise<MistakeNoteSummary[]> {
    const { data } = await client.put('/mistake-notes/reorder', { ids })
    return data.data
  },

  /** 画像の追加。role=problem は対象の問題の写真の差し替え */
  async addImage(noteId: number, image: Blob, role: 'problem' | 'extra'): Promise<MistakeImage> {
    const fd = new FormData()
    fd.append('image', image, `${role}.jpg`)
    fd.append('role', role)
    const { data } = await client.post(`/mistake-notes/${noteId}/images`, fd)
    return data.data
  },

  async removeImage(imageId: number): Promise<void> {
    await client.delete(`/mistake-note-images/${imageId}`)
  },

  /** 画像の実体（認証つき）。thumb=true でサムネイル */
  async imageBlob(imageId: number, thumb = false): Promise<Blob> {
    const res = await client.get(`/mistake-note-images/${imageId}`, { params: thumb ? { v: 'thumb' } : undefined, responseType: 'blob' })
    return res.data
  },
}
