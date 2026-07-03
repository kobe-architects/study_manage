import { defineStore } from 'pinia'
import client from '@/api/client'
import type { CalendarEvent, Goal, RecordStats, StudyItemRow, StudyType } from '@/types'

interface State {
  items: StudyItemRow[]
  recordStats: RecordStats | null
  goals: Goal[]
  events: CalendarEvent[]
  loaded: boolean
}

export const useStudyStore = defineStore('study', {
  state: (): State => ({
    items: [],
    recordStats: null,
    goals: [],
    events: [],
    loaded: false,
  }),

  actions: {
    async fetchAll() {
      const [items, stats, goals, events] = await Promise.all([
        client.get('/study-items'),
        client.get('/records/stats'),
        client.get('/goals'),
        client.get('/events'),
      ])
      this.items = items.data.data
      this.recordStats = stats.data.data
      this.goals = goals.data.data
      this.events = events.data.data
      this.loaded = true
    },

    async fetchItems() {
      const { data } = await client.get('/study-items')
      this.items = data.data
    },

    async fetchRecordStats() {
      const { data } = await client.get('/records/stats')
      this.recordStats = data.data
    },

    async fetchGoals() {
      const { data } = await client.get('/goals')
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
      await client.post('/goals', payload)
      await this.fetchGoals()
    },

    async deleteGoal(id: number) {
      await client.delete(`/goals/${id}`)
      this.goals = this.goals.filter((g) => g.id !== id)
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
