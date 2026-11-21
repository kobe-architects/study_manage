import { defineStore } from 'pinia'
import { questionsApi } from '@/api/questions'

/** 質問事項の未読（ナビの「質問事項」とトップページのバッジ用）。レイアウトが起動時・引っ張って更新で取り直す */
export const useQuestionStore = defineStore('questions', {
  state: () => ({
    /** 未読のあるスレッド数 */
    unreadThreads: 0,
    /** 未読メッセージ数 */
    unreadMessages: 0,
    loaded: false,
  }),

  actions: {
    async fetchUnread() {
      try {
        const u = await questionsApi.unreadCount()
        this.unreadThreads = u.threads
        this.unreadMessages = u.messages
        this.loaded = true
      } catch {
        // 通信できないときは前の値のまま
      }
    },
    setUnread(threads: number, messages?: number) {
      this.unreadThreads = threads
      if (messages !== undefined) this.unreadMessages = messages
    },
  },
})
