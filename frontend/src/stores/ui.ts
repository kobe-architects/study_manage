import { defineStore } from 'pinia'

export type ColorMode = '落ち着いた' | '鮮やか' | 'モノトーン'
export type NavStyle = 'サイドバー' | 'アイコンレール' | 'トップナビ'
export type ProgressStyle = 'バー' | 'リング' | 'ドット'

interface State {
  toast: string
  toastShow: boolean
  colorMode: ColorMode
  navStyle: NavStyle
  progressStyle: ProgressStyle
}

let toastTimer: ReturnType<typeof setTimeout> | undefined

export const useUiStore = defineStore('ui', {
  state: (): State => ({
    toast: '',
    toastShow: false,
    colorMode: '落ち着いた',
    navStyle: 'トップナビ',
    progressStyle: 'バー',
  }),

  actions: {
    notify(message: string) {
      this.toast = message
      this.toastShow = true
      clearTimeout(toastTimer)
      toastTimer = setTimeout(() => {
        this.toastShow = false
      }, 2400)
    },

    colorOf(soft: string, vivid: string): string {
      if (this.colorMode === '鮮やか') return vivid
      if (this.colorMode === 'モノトーン') return '#475569'
      return soft
    },
  },
  persist: {
    pick: ['colorMode', 'navStyle', 'progressStyle'],
  } as never,
})
