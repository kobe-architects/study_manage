import client from '@/api/client'
import type { StudyTask, StudyTaskInput, StudyTaskLog, TaskSubject } from '@/types'

/** タスク管理 API（生徒のみ）。タスクの CRUD と日ごとの消化量 */
export const taskApi = {
  async list(): Promise<{ tasks: StudyTask[]; subjects: TaskSubject[] }> {
    const { data } = await client.get('/tasks')
    return data.data
  },

  async create(payload: StudyTaskInput): Promise<StudyTask> {
    const { data } = await client.post('/tasks', payload)
    return data.data
  },

  async update(id: number, patch: Partial<StudyTaskInput>): Promise<StudyTask> {
    const { data } = await client.put(`/tasks/${id}`, patch)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await client.delete(`/tasks/${id}`)
  },

  async reorder(ids: number[]): Promise<void> {
    await client.put('/tasks/reorder', { ids })
  },

  /** 期間内（ISO 日付・両端含む）の消化量 */
  async logs(from: string, to: string): Promise<StudyTaskLog[]> {
    const { data } = await client.get('/tasks/logs', { params: { from, to } })
    return data.data
  },

  /** その日の消化量を上書き（0 で取り消し） */
  async saveLog(taskId: number, date: string, amount: number): Promise<StudyTaskLog> {
    const { data } = await client.put(`/tasks/${taskId}/log`, { date, amount })
    return data.data
  },
}
