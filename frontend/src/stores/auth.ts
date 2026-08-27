import { defineStore } from 'pinia'
import client from '@/api/client'
import type { AuthUser, UserSettings } from '@/types'

interface State {
  user: AuthUser | null
  token: string | null
}

export const useAuthStore = defineStore('auth', {
  state: (): State => ({
    user: null,
    token: localStorage.getItem('sm_token'),
  }),

  getters: {
    isAuthenticated: (s) => !!s.token,
    settings: (s): UserSettings | null => s.user?.settings ?? null,
    // ルーターガードは user 取得前にも動くため localStorage の役割を併用する
    isTutor: (s) => (s.user ? s.user.role === 'tutor' : localStorage.getItem('sm_role') === 'tutor'),
  },

  actions: {
    async login(email: string, password: string) {
      const { data } = await client.post('/login', { email, password })
      this.token = data.token
      this.user = data.user
      localStorage.setItem('sm_token', data.token)
      localStorage.setItem('sm_role', data.user.role)
    },

    async fetchMe() {
      const { data } = await client.get('/me')
      this.user = data.user
      localStorage.setItem('sm_role', data.user.role)
      return data.user as AuthUser
    },

    async updateSettings(patch: Partial<UserSettings>) {
      const { data } = await client.put('/settings', patch)
      if (this.user) {
        this.user.settings = data.data
      }
      return data.data as UserSettings
    },

    async logout() {
      try {
        await client.post('/logout')
      } catch {
        // ignore
      }
      this.token = null
      this.user = null
      localStorage.removeItem('sm_token')
      localStorage.removeItem('sm_role')
    },
  },
})
