import client from '@/api/client'
import type { GanttChart, GanttTask, GanttTaskKind } from '@/types'

export interface GanttChartInput {
  title: string
  note?: string | null
  startOn: string
  endOn: string
}

export interface GanttTaskInput {
  title?: string
  kind?: GanttTaskKind
  startOn?: string
  endOn?: string | null
  color?: string
  progress?: number
  note?: string | null
}

/** ガントチャート API（生徒のみ） */
export const ganttApi = {
  async list(): Promise<GanttChart[]> {
    const { data } = await client.get('/gantt-charts')
    return data.data
  },

  async create(payload: GanttChartInput): Promise<GanttChart> {
    const { data } = await client.post('/gantt-charts', payload)
    return data.data
  },

  /** チャートと項目一覧 */
  async show(id: number): Promise<GanttChart & { tasks: GanttTask[] }> {
    const { data } = await client.get(`/gantt-charts/${id}`)
    return data.data
  },

  async update(id: number, patch: Partial<GanttChartInput>): Promise<GanttChart & { tasks: GanttTask[] }> {
    const { data } = await client.put(`/gantt-charts/${id}`, patch)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await client.delete(`/gantt-charts/${id}`)
  },

  async duplicate(id: number): Promise<GanttChart> {
    const { data } = await client.post(`/gantt-charts/${id}/duplicate`)
    return data.data
  },

  async createTask(chartId: number, payload: GanttTaskInput & { title: string; startOn: string }): Promise<GanttTask> {
    const { data } = await client.post(`/gantt-charts/${chartId}/tasks`, payload)
    return data.data
  },

  /** 項目の更新（ドラッグ後の期間だけの部分更新にも使う） */
  async updateTask(taskId: number, patch: GanttTaskInput): Promise<GanttTask> {
    const { data } = await client.put(`/gantt-tasks/${taskId}`, patch)
    return data.data
  },

  async removeTask(taskId: number): Promise<void> {
    await client.delete(`/gantt-tasks/${taskId}`)
  },

  async reorderTasks(chartId: number, ids: number[]): Promise<GanttTask[]> {
    const { data } = await client.put(`/gantt-charts/${chartId}/tasks/reorder`, { ids })
    return data.data
  },
}
