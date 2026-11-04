import { acceptHMRUpdate, defineStore } from 'pinia'
import { taskApi } from '@/api/task'
import { iso } from '@/lib/design'
import { addDays } from '@/lib/tasks'
import type { StudyTask, StudyTaskInput, TaskSubject } from '@/types'

interface State {
  tasks: StudyTask[]
  subjects: TaskSubject[]
  /** 消化量。キーは `${taskId}|${ISO 日付}` */
  logs: Record<string, number>
  loaded: boolean
  /** 取得済みの週（月曜の ISO 日付） */
  fetchedWeeks: Record<string, true>
}

const key = (taskId: number, date: string) => `${taskId}|${date}`

/** タスク管理（生徒）。タスク一覧と日ごとの消化量 */
export const useTaskStore = defineStore('task', {
  state: (): State => ({ tasks: [], subjects: [], logs: {}, loaded: false, fetchedWeeks: {} }),

  getters: {
    doneOn: (s) => (taskId: number, date: string) => s.logs[key(taskId, date)] ?? 0,
    activeTasks: (s) => s.tasks.filter((t) => t.isActive),
  },

  actions: {
    async fetchTasks() {
      const d = await taskApi.list()
      this.tasks = d.tasks
      this.subjects = d.subjects
      this.loaded = true
    },

    /** 期間内の消化量を取り直す（期間内の古い値は消してから入れ替える） */
    async fetchLogs(from: string, to: string) {
      const list = await taskApi.logs(from, to)
      const next = { ...this.logs }
      for (const k of Object.keys(next)) {
        const date = k.split('|')[1] ?? ''
        if (date >= from && date <= to) delete next[k]
      }
      for (const l of list) next[key(l.taskId, l.date)] = l.amount
      this.logs = next
    },

    /** その週（月曜始まり）の消化量を未取得なら取得する */
    async ensureWeek(start: Date, force = false) {
      const k = iso(start)
      if (!force && this.fetchedWeeks[k]) return
      this.fetchedWeeks[k] = true
      try {
        await this.fetchLogs(k, iso(addDays(start, 6)))
      } catch (e) {
        delete this.fetchedWeeks[k]
        throw e
      }
    },

    /** 消化量を保存（先に画面へ反映し、失敗したら戻す） */
    async setLog(taskId: number, date: string, amount: number) {
      const k = key(taskId, date)
      const prev = this.logs[k]
      if (amount > 0) this.logs[k] = amount
      else delete this.logs[k]
      try {
        await taskApi.saveLog(taskId, date, amount)
      } catch (e) {
        if (prev === undefined) delete this.logs[k]
        else this.logs[k] = prev
        throw e
      }
    },

    async createTask(payload: StudyTaskInput) {
      const t = await taskApi.create(payload)
      this.tasks.push(t)
      return t
    },

    async updateTask(id: number, patch: Partial<StudyTaskInput>) {
      const t = await taskApi.update(id, patch)
      const i = this.tasks.findIndex((x) => x.id === id)
      if (i >= 0) this.tasks[i] = t
      else this.tasks.push(t)
      return t
    },

    async removeTask(id: number) {
      await taskApi.remove(id)
      this.tasks = this.tasks.filter((t) => t.id !== id)
      for (const k of Object.keys(this.logs)) if (k.startsWith(`${id}|`)) delete this.logs[k]
    },

    /** 並び順を ID の配列で確定（画面はすぐ並べ替える） */
    async reorder(ids: number[]) {
      const pos = new Map(ids.map((id, i) => [id, i]))
      this.tasks = [...this.tasks]
        .sort((a, b) => (pos.get(a.id) ?? 9999) - (pos.get(b.id) ?? 9999))
        .map((t, i) => ({ ...t, sortOrder: i }))
      await taskApi.reorder(ids)
    },
  },
})

if (import.meta.hot) {
  import.meta.hot.accept(acceptHMRUpdate(useTaskStore, import.meta.hot))
}
