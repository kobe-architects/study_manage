import { defineStore } from 'pinia'
import { grammarApi } from '@/api/grammar'
import type { GrammarBook, GrammarBookKey, GrammarQuestion } from '@/types'

const BOOK_KEY = 'sm_grammar_book' // 選択中の問題集（端末に記憶）

interface State {
  books: GrammarBook[]
  book: GrammarBookKey
  questions: Partial<Record<GrammarBookKey, GrammarQuestion[]>>
  loading: boolean
}

/** 英文法・語法 問題演習（スクランブル）。問題は 1 冊ずつ全件取得して画面側で絞り込む */
export const useGrammarStore = defineStore('grammar', {
  state: (): State => ({
    books: [],
    book: (localStorage.getItem(BOOK_KEY) as GrammarBookKey | null) === 'usage' ? 'usage' : 'grammar',
    questions: {},
    loading: false,
  }),

  getters: {
    current: (s): GrammarQuestion[] => s.questions[s.book] ?? [],
    currentBook: (s): GrammarBook | undefined => s.books.find((b) => b.key === s.book),
  },

  actions: {
    async fetchBooks() {
      this.books = await grammarApi.books()
    },

    selectBook(key: GrammarBookKey) {
      this.book = key
      localStorage.setItem(BOOK_KEY, key)
    },

    /** 1 冊分の問題を取得（取得済みなら再利用。force で再取得） */
    async fetchQuestions(book?: GrammarBookKey, force = false) {
      const key: GrammarBookKey = book ?? this.book
      if (!force && this.questions[key]?.length) return this.questions[key]!
      this.loading = true
      try {
        const list = await grammarApi.questions(key)
        this.questions = { ...this.questions, [key]: list }
        return list
      } finally {
        this.loading = false
      }
    },

    /** 回答を記録して問題の状況を更新する。失敗時は null（画面の進行は止めない） */
    async recordAttempt(q: GrammarQuestion, isCorrect: boolean, answer: string | null): Promise<number | null> {
      try {
        const res = await grammarApi.attempt(q.id, { isCorrect, answer })
        this.applyStat(q.id, res.stat)
        return res.attemptId
      } catch {
        return null
      }
    },

    /** 自己判定で正誤を変える */
    async overrideAttempt(q: GrammarQuestion, attemptId: number | null, isCorrect: boolean) {
      try {
        const res = attemptId
          ? await grammarApi.updateAttempt(attemptId, isCorrect)
          : await grammarApi.attempt(q.id, { isCorrect, answer: null })
        this.applyStat(q.id, res.stat)
        return res.attemptId
      } catch {
        return attemptId
      }
    },

    applyStat(questionId: number, stat: GrammarQuestion['stat']) {
      for (const list of Object.values(this.questions)) {
        const q = list?.find((x) => x.id === questionId)
        if (q) q.stat = stat
      }
      // 冊ごとの件数も最新の状況から数え直す
      const list = this.questions[this.book]
      const b = this.books.find((x) => x.key === this.book)
      if (list && b) {
        b.correct = list.filter((x) => x.stat?.last === true).length
        b.incorrect = list.filter((x) => x.stat?.last === false).length
        b.unanswered = Math.max(0, b.total - b.correct - b.incorrect)
      }
    },
  },
})
