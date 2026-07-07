import { acceptHMRUpdate, defineStore } from 'pinia'
import client from '@/api/client'
import type { RecordColor, RelatedProblemRow, ResourceBook, ResourceBookRow, StudyType } from '@/types'

interface State {
  activeType: StudyType
  books: ResourceBook[]
  activeBookId: number | null
  rows: ResourceBookRow[]
  loadingRows: boolean
}

export const useResourceStore = defineStore('resource', {
  state: (): State => ({
    activeType: '問題集',
    books: [],
    activeBookId: null,
    rows: [],
    loadingRows: false,
  }),

  getters: {
    booksOfType(state): ResourceBook[] {
      return state.books
        .filter((b) => b.type === state.activeType)
        .sort((a, b) => Number(b.pinned) - Number(a.pinned) || a.sortOrder - b.sortOrder || a.id - b.id)
    },
    activeBook(state): ResourceBook | null {
      return state.books.find((b) => b.id === state.activeBookId) ?? null
    },
  },

  actions: {
    async fetchBooks() {
      const { data } = await client.get('/resource-books')
      this.books = data.data
      // 現タブの先頭教材を選択
      const list = this.books.filter((b) => b.type === this.activeType)
      if (!list.find((b) => b.id === this.activeBookId)) {
        this.activeBookId = list[0]?.id ?? null
      }
      if (this.activeBookId) await this.fetchRows(this.activeBookId)
      else this.rows = []
    },

    async setType(type: StudyType) {
      this.activeType = type
      const list = this.books.filter((b) => b.type === type)
      this.activeBookId = list[0]?.id ?? null
      if (this.activeBookId) await this.fetchRows(this.activeBookId)
      else this.rows = []
    },

    async selectBook(id: number) {
      this.activeBookId = id
      await this.fetchRows(id)
    },

    async fetchRows(bookId: number) {
      this.loadingRows = true
      try {
        const { data } = await client.get(`/resource-books/${bookId}/rows`)
        this.rows = data.data
      } finally {
        this.loadingRows = false
      }
    },

    async createBook(type: StudyType, title: string, subjectId: number | null) {
      const { data } = await client.post('/resource-books', { type, title, subjectId })
      await this.fetchBooks()
      this.activeBookId = data.data.id
      await this.fetchRows(data.data.id)
      return data.data.id as number
    },

    async updateBook(id: number, payload: { title?: string; subjectId?: number | null }) {
      await client.put(`/resource-books/${id}`, payload)
      await this.fetchBooks()
    },

    /** ピン止めの切り替え（ローカル即時反映のうえ永続化・再取得） */
    async togglePin(id: number, pinned: boolean) {
      const b = this.books.find((x) => x.id === id)
      if (b) b.pinned = pinned
      await client.put(`/resource-books/${id}`, { pinned })
      await this.refreshBookSummary()
    },

    /** カードの並び替え（現タブの表示順の id 配列を渡す。ローカル即時反映のうえ永続化） */
    async reorderBooks(orderedIds: number[]) {
      // ローカルの sort_order を即時反映（ちらつき防止）
      const rank = new Map(orderedIds.map((id, i) => [id, i]))
      this.books.forEach((b) => {
        if (rank.has(b.id)) b.sortOrder = rank.get(b.id)!
      })
      await client.post('/resource-books/reorder', { ids: orderedIds })
      await this.refreshBookSummary()
    },

    async deleteBook(id: number) {
      await client.delete(`/resource-books/${id}`)
      if (this.activeBookId === id) this.activeBookId = null
      await this.fetchBooks()
    },

    async uploadImage(id: number, file: File) {
      const fd = new FormData()
      fd.append('image', file)
      await client.post(`/resource-books/${id}/image`, fd)
      await this.fetchBooks()
    },

    async deleteImage(id: number) {
      await client.delete(`/resource-books/${id}/image`)
      await this.fetchBooks()
    },

    async createRow(bookId: number, payload: Record<string, unknown>) {
      await client.post(`/resource-books/${bookId}/rows`, payload)
      await this.fetchRows(bookId)
      await this.refreshBookSummary()
    },

    async updateRow(rowId: number, payload: Record<string, unknown>) {
      await client.put(`/resource-book-rows/${rowId}`, payload)
      if (this.activeBookId) await this.fetchRows(this.activeBookId)
      await this.refreshBookSummary()
    },

    /** 行の重要フラグを切り替え（ローカル即時反映のうえ永続化） */
    async toggleImportant(rowId: number, important: boolean) {
      const r = this.rows.find((x) => x.id === rowId)
      if (r) r.important = important
      await client.put(`/resource-book-rows/${rowId}`, { important })
    },

    /** 講義教材に関連する問題（同じ小分類の問題集の行）を取得 */
    async fetchRelatedProblems(bookId: number) {
      const { data } = await client.get(`/resource-books/${bookId}/related-problems`)
      return data.data as RelatedProblemRow[]
    },

    /** 行の進捗対象(紐づけ)を一括登録/解除 */
    async setRowsIncluded(bookId: number, ids: number[], included: boolean) {
      await client.put(`/resource-books/${bookId}/rows/included`, { ids, included })
      // ローカル即時反映
      const set = new Set(ids)
      this.rows = this.rows.map((r) => (set.has(r.id) ? { ...r, included } : r))
      await this.refreshBookSummary()
    },

    async deleteRow(rowId: number) {
      await client.delete(`/resource-book-rows/${rowId}`)
      if (this.activeBookId) await this.fetchRows(this.activeBookId)
      await this.refreshBookSummary()
    },

    /** 行に学習記録を登録（行ベース）。color: red/blue/green（任意）, reviewOn: 復習期限（任意） */
    async recordRow(rowId: number, studiedOn: string, color?: RecordColor | null, reviewOn?: string | null) {
      await client.post(`/resource-book-rows/${rowId}/record`, { studiedOn, color: color ?? null, reviewOn: reviewOn ?? null })
      if (this.activeBookId) await this.fetchRows(this.activeBookId)
      await this.refreshBookSummary()
    },

    /** 行に紐づく学習記録の一覧を取得（削除UI用） */
    async fetchRowRecords(rowId: number) {
      const { data } = await client.get(`/resource-book-rows/${rowId}/records`)
      return data.data as { id: number; studiedOn: string; color: RecordColor | null; reviewOn: string | null }[]
    },

    /** 学習記録を1件削除 */
    async deleteRecord(recordId: number) {
      await client.delete(`/records/${recordId}`)
      if (this.activeBookId) await this.fetchRows(this.activeBookId)
      await this.refreshBookSummary()
    },

    async importFile(bookId: number, file: File) {
      const fd = new FormData()
      fd.append('file', file)
      const { data } = await client.post(`/resource-books/${bookId}/import`, fd)
      await this.fetchRows(bookId)
      await this.refreshBookSummary()
      return data.data as { imported: number; skipped: number }
    },

    /** 達成行数のサマリだけ更新（教材リストを取り直す） */
    async refreshBookSummary() {
      const { data } = await client.get('/resource-books')
      this.books = data.data
    },

    /** Excel テンプレート / エクスポートのダウンロード */
    async download(path: string, filename: string) {
      const res = await client.get(path, { responseType: 'blob' })
      const blobUrl = URL.createObjectURL(res.data)
      const a = document.createElement('a')
      a.href = blobUrl
      a.download = filename
      a.click()
      URL.revokeObjectURL(blobUrl)
    },
  },
})

// Vite HMR: ストアにアクションを追加してもフルリロード不要で反映されるようにする
if (import.meta.hot) {
  import.meta.hot.accept(acceptHMRUpdate(useResourceStore, import.meta.hot))
}
