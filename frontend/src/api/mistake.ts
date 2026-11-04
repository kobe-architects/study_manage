import client from '@/api/client'
import type { MistakeDeck, MistakeGrade, MistakeImage, MistakeImageRole, MistakeLayout, MistakeNoteDetail, MistakeNoteSummary, MistakeReviewState } from '@/types'

export interface MistakeMetaInput {
  /** 科目。「その他」は OTHER_SUBJECT（-1） */
  subjectId?: number | null
  majorId?: number | null
  midId?: number | null
  studyItemId?: number | null
  resourceBookId?: number | null
  deckId?: number | null
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
    if (meta.resourceBookId) fd.append('resourceBookId', String(meta.resourceBookId))
    if (meta.deckId) fd.append('deckId', String(meta.deckId))
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

  /** 問題解答モードの評価を保存（忘却曲線に合わせて次回の出題時刻が決まる） */
  async review(noteId: number, grade: MistakeGrade): Promise<MistakeReviewState> {
    const { data } = await client.post(`/mistake-notes/${noteId}/review`, { grade })
    return data.data
  },

  /** 画像の追加。role=problem / answer は同じ役割の画像の差し替え */
  async addImage(noteId: number, image: Blob, role: MistakeImageRole): Promise<MistakeImage> {
    const fd = new FormData()
    fd.append('image', image, `${role}.jpg`)
    fd.append('role', role)
    const { data } = await client.post(`/mistake-notes/${noteId}/images`, fd)
    return data.data
  },

  async removeImage(imageId: number): Promise<void> {
    await client.delete(`/mistake-note-images/${imageId}`)
  },

  /** 画像の役割の変更。problem / answer にすると、それまでの同じ役割の画像は説明用になる。戻り値はノートの全画像 */
  async setImageRole(imageId: number, role: MistakeImageRole): Promise<MistakeImage[]> {
    const { data } = await client.put(`/mistake-note-images/${imageId}`, { role })
    return data.data
  },

  /** 画像の実体（認証つき）。thumb=true でサムネイル */
  async imageBlob(imageId: number, thumb = false): Promise<Blob> {
    const res = await client.get(`/mistake-note-images/${imageId}`, { params: thumb ? { v: 'thumb' } : undefined, responseType: 'blob' })
    return res.data
  },

  // ---- デッキ（本棚の「本」） ----
  async decks(): Promise<MistakeDeck[]> {
    const { data } = await client.get('/mistake-decks')
    return data.data
  },
  async createDeck(input: { name: string; color?: string | null }): Promise<MistakeDeck> {
    const { data } = await client.post('/mistake-decks', input)
    return data.data
  },
  async updateDeck(id: number, patch: { name?: string; color?: string | null }): Promise<MistakeDeck> {
    const { data } = await client.put(`/mistake-decks/${id}`, patch)
    return data.data
  },
  async removeDeck(id: number): Promise<void> {
    await client.delete(`/mistake-decks/${id}`)
  },
  async reorderDecks(ids: number[]): Promise<MistakeDeck[]> {
    const { data } = await client.put('/mistake-decks/reorder', { ids })
    return data.data
  },
  /** ページをデッキへ移す（deckId=null でデッキなし）。戻り値はデッキ一覧 */
  async assignDeck(ids: number[], deckId: number | null): Promise<MistakeDeck[]> {
    const { data } = await client.put('/mistake-decks/assign', { ids, deckId })
    return data.data
  },
}
