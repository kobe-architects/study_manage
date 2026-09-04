import { acceptHMRUpdate, defineStore } from 'pinia'
import client from '@/api/client'
import type { Assignment, CalendarEvent, Goal, GoalItemDetail, GoalLinkBook, RecordListItem, RecordStats, ReviewItem, StudyItemRow, StudyType } from '@/types'

/**
 * API プレフィックス。家庭教師ログイン時は /tutor 配下の生徒スコープ API を使う。
 * これにより目標・学習記録まわりの画面/コンポーネントを両ロールで共用できる。
 */
function p(): string {
  return localStorage.getItem('sm_role') === 'tutor' ? '/tutor' : ''
}

interface State {
  items: StudyItemRow[]
  recordStats: RecordStats | null
  goals: Goal[]
  events: CalendarEvent[]
  reviews: ReviewItem[]
  assignments: Assignment[]
  loaded: boolean
}

export const useStudyStore = defineStore('study', {
  state: (): State => ({
    items: [],
    recordStats: null,
    goals: [],
    events: [],
    reviews: [],
    assignments: [],
    loaded: false,
  }),

  actions: {
    async fetchAll() {
      const [items, stats, goals, events, reviews] = await Promise.all([
        client.get('/study-items'),
        client.get('/records/stats'),
        client.get('/goals'),
        client.get('/events'),
        client.get('/records/reviews'),
      ])
      this.items = items.data.data
      this.recordStats = stats.data.data
      this.goals = goals.data.data
      this.events = events.data.data
      this.reviews = reviews.data.data
      this.loaded = true
    },

    async fetchReviews() {
      const { data } = await client.get('/records/reviews')
      this.reviews = data.data
    },

    /** 復習を完了記録（対象記録を復習済みにし、復習セッションを新規学習記録として登録） */
    async completeReview(recordId: number, studiedOn: string, color: string | null, reviewOn: string | null) {
      await client.post(`/records/${recordId}/review-complete`, { studiedOn, color, reviewOn })
      await Promise.all([this.fetchReviews(), this.fetchItems(), this.fetchRecordStats(), this.fetchGoals()])
    },

    async fetchItems() {
      const { data } = await client.get(`${p()}/study-items`)
      this.items = data.data
    },

    async fetchRecordStats() {
      const { data } = await client.get(`${p()}/records/stats`)
      this.recordStats = data.data
    },

    async fetchGoals() {
      const { data } = await client.get(`${p()}/goals`)
      this.goals = data.data
    },

    async fetchEvents() {
      const { data } = await client.get('/events')
      this.events = data.data
    },

    async addRecord(studyItemId: number, type: StudyType, studiedOn: string) {
      await client.post('/records', { studyItemId, type, studiedOn })
      await Promise.all([this.fetchItems(), this.fetchRecordStats(), this.fetchGoals()])
    },

    /** 学習記録一覧（期間指定）を取得（学習記録の出力機能・画面表示用） */
    async fetchRecordList(from: string, to: string) {
      const { data } = await client.get(`${p()}/records`, { params: { from, to } })
      return data.data as RecordListItem[]
    },

    /** 学習記録（期間指定）を Excel でダウンロード */
    async downloadRecordExport(from: string, to: string, filename: string) {
      const res = await client.get('/records/export', { params: { from, to }, responseType: 'blob' })
      const blobUrl = URL.createObjectURL(res.data)
      const a = document.createElement('a')
      a.href = blobUrl
      a.download = filename
      a.click()
      URL.revokeObjectURL(blobUrl)
    },

    async deleteRecord(id: number) {
      await client.delete(`/records/${id}`)
      await Promise.all([this.fetchItems(), this.fetchRecordStats(), this.fetchGoals()])
    },

    async createItem(midCategoryId: number, name: string) {
      const { data } = await client.post('/study-items', { midCategoryId, name })
      await this.fetchItems()
      return data.data.id as number
    },

    async updateItem(id: number, name: string) {
      await client.put(`/study-items/${id}`, { name })
      await this.fetchItems()
    },

    async deleteItem(id: number) {
      await client.delete(`/study-items/${id}`)
      await this.fetchItems()
    },

    async setIncluded(ids: number[], included: boolean) {
      await client.put('/study-items/included', { ids, included })
      // ローカル即時反映
      const set = new Set(ids)
      this.items = this.items.map((i) => (set.has(i.id) ? { ...i, included } : i))
    },

    async createGoal(payload: {
      title: string
      subjectId: number | null
      scope: string
      rangeLabel: string
      deadline: string
      target: number
    }) {
      const { data } = await client.post(`${p()}/goals`, payload)
      await this.fetchGoals()
      return data.data.id as number
    },

    async deleteGoal(id: number) {
      await client.delete(`${p()}/goals/${id}`)
      await this.fetchGoals() // 中間目標を含む入れ子構造のため取り直す
    },

    /** 目標・中間目標の編集（タイトル・期限） */
    async updateGoal(id: number, payload: { title?: string; deadline?: string }) {
      await client.put(`${p()}/goals/${id}`, payload)
      await this.fetchGoals()
    },

    /** 紐づけモーダル用ツリー（教材→章→行）を取得 */
    async fetchGoalLinkOptions() {
      const { data } = await client.get(`${p()}/goals/link-options`)
      return data.data as GoalLinkBook[]
    },

    /** 目標に紐づける個別学習データ（行ID配列）を一括設定 */
    async updateGoalItems(id: number, ids: number[]) {
      await client.put(`${p()}/goals/${id}/items`, { ids })
      await this.fetchGoals()
    },

    /** 目標に紐づく個別学習データの明細（学習済み/未学習）を取得 */
    async fetchGoalItems(id: number) {
      const { data } = await client.get(`${p()}/goals/${id}/items`)
      return data.data as GoalItemDetail[]
    },

    /** 中間目標の紐づけ用ツリー（親目標の紐づけ項目のみ）を取得 */
    async fetchSubLinkOptions(parentId: number) {
      const { data } = await client.get(`${p()}/goals/${parentId}/link-options`)
      return data.data as GoalLinkBook[]
    },

    /** 中間目標を作成 */
    async createSubGoal(parentId: number, payload: { title: string; deadline: string; ids: number[] }) {
      const { data } = await client.post(`${p()}/goals/${parentId}/sub-goals`, payload)
      await this.fetchGoals()
      return data.data.id as number
    },

    /** 紐づけ項目の学習済み/未学習を手動設定（トップ/目標画面からの直接設定） */
    async setGoalItemStudied(goalId: number, itemId: number, studied: boolean) {
      await client.put(`${p()}/goals/${goalId}/items/studied`, { itemId, studied })
      await this.fetchGoals()
    },

    /** 目標の達成/未達成を記録（true=達成 / false=未達成 / null=未記録） */
    async setGoalAchieved(id: number, achieved: boolean | null) {
      await client.put(`${p()}/goals/${id}`, { achieved })
      const g = this.goals.find((x) => x.id === id)
      if (g) g.achieved = achieved
    },

    // ===== 課題（家庭教師が設定 / 生徒は閲覧） =====

    /** 課題一覧を取得（進捗集計付き） */
    async fetchAssignments() {
      const { data } = await client.get(`${p()}/assignments`)
      this.assignments = data.data
    },

    /** 課題を作成（tutor のみ）。タイトルは任意（null で期限がタイトルになる） */
    async createAssignment(payload: { title: string | null; note: string | null; dueOn: string; ids: number[] }) {
      const { data } = await client.post(`${p()}/assignments`, payload)
      await this.fetchAssignments()
      return data.data.id as number
    },

    /** 課題を更新（tutor のみ） */
    async updateAssignment(
      id: number,
      payload: { title?: string | null; note?: string | null; dueOn?: string; achieved?: boolean | null; ids?: number[] },
    ) {
      await client.put(`${p()}/assignments/${id}`, payload)
      await this.fetchAssignments()
    },

    /** 課題を削除（tutor のみ） */
    async deleteAssignment(id: number) {
      await client.delete(`${p()}/assignments/${id}`)
      await this.fetchAssignments()
    },

    /** 課題に含めた個別学習データの明細（学習済み/未学習）を取得 */
    async fetchAssignmentItems(id: number) {
      const { data } = await client.get(`${p()}/assignments/${id}/items`)
      return data.data as GoalItemDetail[]
    },

    async saveEvent(date: string, title: string) {
      const { data } = await client.post('/events', { date, title })
      const idx = this.events.findIndex((e) => e.date === date)
      if (idx >= 0) this.events[idx] = data.data
      else this.events.push(data.data)
    },

    async deleteEvent(id: number) {
      await client.delete(`/events/${id}`)
      this.events = this.events.filter((e) => e.id !== id)
    },
  },
})

// Vite HMR: ストアにアクションを追加してもフルリロード不要で反映されるようにする
if (import.meta.hot) {
  import.meta.hot.accept(acceptHMRUpdate(useStudyStore, import.meta.hot))
}
