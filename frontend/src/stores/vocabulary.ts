import { defineStore } from 'pinia'
import client from '@/api/client'
import type {
  QuizAnswerRecord,
  QuizQuestion,
  QuizSettings,
  StudyResource,
  Vocabulary,
  VocabProgress,
  VocabularyStats,
} from '@/types'

const RESOURCE_KEY = 'sm_vocab_resource' // 選択中の単語帳（端末に記憶）

interface State {
  resource: StudyResource | null
  resources: StudyResource[]
  items: Vocabulary[]
  loading: boolean
  stats: VocabularyStats | null
  progress: VocabProgress[]
  quizQuestions: QuizQuestion[]
  quizIndex: number
  quizResults: QuizAnswerRecord[]
  quizType: 'choice' | 'input'
}

export const useVocabularyStore = defineStore('vocabulary', {
  state: (): State => ({
    resource: null,
    resources: [],
    items: [],
    loading: false,
    stats: null,
    progress: [],
    quizQuestions: [],
    quizIndex: 0,
    quizResults: [],
    quizType: 'choice',
  }),

  getters: {
    currentQuestion: (s): QuizQuestion | null => s.quizQuestions[s.quizIndex] ?? null,
    isQuizComplete: (s) => s.quizIndex >= s.quizQuestions.length,
    quizScore: (s) => ({
      correct: s.quizResults.filter((r) => r && r.isCorrect).length,
      total: s.quizQuestions.length,
    }),
    incorrectResults: (s): Vocabulary[] =>
      s.quizQuestions
        .filter((_q, i) => s.quizResults[i] && !s.quizResults[i].isCorrect)
        .map((q) => q.vocabulary),
    currentAnswer: (s): QuizAnswerRecord | null => s.quizResults[s.quizIndex] ?? null,
  },

  actions: {
    /** 単語帳一覧を取得し、記憶している単語帳（なければ先頭）を選択する */
    async fetchResources(): Promise<StudyResource | null> {
      const { data } = await client.get('/study-resources')
      this.resources = data.data
      const saved = Number(localStorage.getItem(RESOURCE_KEY) ?? 0)
      const keep = this.resource?.id ?? saved
      this.resource = this.resources.find((r: StudyResource) => r.id === keep) ?? this.resources[0] ?? null
      return this.resource
    },

    /** 単語帳を切り替える（単語・統計・クイズ状態をリセットして再取得） */
    async selectResource(id: number) {
      const r = this.resources.find((x) => x.id === id)
      if (!r) return
      this.resource = r
      localStorage.setItem(RESOURCE_KEY, String(id))
      this.items = []
      this.stats = null
      this.quizQuestions = []
      this.quizIndex = 0
      this.quizResults = []
      await Promise.all([this.fetchByResource(id), this.fetchStats(id).catch(() => {})])
    },

    async fetchByResource(resourceId: number) {
      this.loading = true
      try {
        const { data } = await client.get(`/study-resources/${resourceId}/vocabularies`)
        this.items = data.data
      } finally {
        this.loading = false
      }
    },

    async fetchStats(resourceId: number) {
      const { data } = await client.get(`/study-resources/${resourceId}/vocabularies/stats`)
      this.stats = data.data
    },

    /** トップページ用: 英単語の習得進捗（全体 + セクション別） */
    async fetchProgress() {
      const { data } = await client.get('/vocabulary-progress')
      this.progress = data.data as VocabProgress[]
    },

    async startQuiz(resourceId: number, settings: QuizSettings) {
      const params = new URLSearchParams()
      params.set('count', String(settings.count))
      params.set('quizType', settings.quizType)
      if (settings.sectionIds?.length) params.set('sectionIds', settings.sectionIds.join(','))
      if (settings.importances?.length) params.set('importances', settings.importances.join(','))
      if (settings.labels?.length) params.set('labels', settings.labels.join(','))
      if (settings.ordered) params.set('ordered', '1')
      if (settings.offset) params.set('offset', String(settings.offset))
      if (settings.vocabularyIds?.length)
        params.set('vocabularyIds', settings.vocabularyIds.join(','))

      const { data } = await client.get(
        `/study-resources/${resourceId}/quiz?${params.toString()}`,
      )
      this.quizQuestions = data.data
      this.quizIndex = 0
      this.quizResults = []
      this.quizType = settings.quizType
      return this.quizQuestions.length
    },

    /** 回答を記録（結果へ push ＋ 非同期 attempt 送信。失敗はサイレント無視） */
    submitAnswer(answer: QuizAnswerRecord) {
      const q = this.quizQuestions[this.quizIndex]
      if (!q) return
      this.quizResults[this.quizIndex] = answer
      // バックグラウンド送信
      client
        .post(`/vocabularies/${q.vocabulary.id}/attempt`, {
          is_correct: answer.isCorrect,
          quiz_type: this.quizType,
        })
        .catch(() => {})
    },

    advanceQuiz() {
      this.quizIndex++
    },

    goBackQuiz() {
      if (this.quizIndex > 0) this.quizIndex--
    },

    updateQuizVocabulary(id: number, patch: Partial<Vocabulary>) {
      this.quizQuestions = this.quizQuestions.map((q) =>
        q.vocabulary.id === id ? { ...q, vocabulary: { ...q.vocabulary, ...patch } } : q,
      )
      this.items = this.items.map((v) => (v.id === id ? { ...v, ...patch } : v))
    },

    resetQuiz() {
      this.quizQuestions = []
      this.quizIndex = 0
      this.quizResults = []
    },

    // ---- CRUD ----
    async create(sectionId: number, payload: Partial<Vocabulary>) {
      const { data } = await client.post(`/sections/${sectionId}/vocabularies`, payload)
      return data.data as Vocabulary
    },

    async update(id: number, payload: Partial<Vocabulary>) {
      const { data } = await client.put(`/vocabularies/${id}`, payload)
      this.items = this.items.map((v) => (v.id === id ? data.data : v))
      return data.data as Vocabulary
    },

    async remove(id: number) {
      await client.delete(`/vocabularies/${id}`)
      this.items = this.items.filter((v) => v.id !== id)
    },

    async uploadImage(id: number, file: File) {
      const form = new FormData()
      form.append('image', file)
      const { data } = await client.post(`/vocabularies/${id}/image`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      this.items = this.items.map((v) => (v.id === id ? data.data : v))
      return data.data as Vocabulary
    },

    async deleteImage(id: number) {
      await client.delete(`/vocabularies/${id}/image`)
      this.items = this.items.map((v) => (v.id === id ? { ...v, imageUrl: null } : v))
    },

    async importFile(resourceId: number, file: File) {
      const form = new FormData()
      form.append('file', file)
      const { data } = await client.post(
        `/study-resources/${resourceId}/vocabularies/import`,
        form,
        { headers: { 'Content-Type': 'multipart/form-data' } },
      )
      return data.data as { imported: number; skipped: number }
    },

    async fetchIncorrect(resourceId: number, since: string, until?: string) {
      const params = new URLSearchParams({ since })
      if (until) params.set('until', until)
      const { data } = await client.get(
        `/study-resources/${resourceId}/vocabularies/incorrect?${params.toString()}`,
      )
      return { words: data.data as Vocabulary[], meta: data.meta }
    },
  },
  persist: {
    pick: ['items', 'stats', 'progress'],
  } as never,
})
