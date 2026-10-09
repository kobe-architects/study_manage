import client from '@/api/client'
import type { GanttChart, GanttRow, GanttTask, GanttTaskKind } from '@/types'

export interface GanttChartInput {
  title: string
  note?: string | null
  startOn: string
  endOn: string
}

export interface GanttRowInput {
  title?: string
  group?: string | null
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

export type GanttChartDetail = GanttChart & { rows: GanttRow[] }

/** ガントチャート API（生徒のみ）。チャート → 行（科目・学習分野） → 区間（バー／節目） */
export const ganttApi = {
  async list(): Promise<GanttChart[]> {
    const { data } = await client.get('/gantt-charts')
    return data.data
  },

  async create(payload: GanttChartInput): Promise<GanttChart> {
    const { data } = await client.post('/gantt-charts', payload)
    return data.data
  },

  /** チャートと行・区間の一覧 */
  async show(id: number): Promise<GanttChartDetail> {
    const { data } = await client.get(`/gantt-charts/${id}`)
    return data.data
  },

  async update(id: number, patch: Partial<GanttChartInput>): Promise<GanttChartDetail> {
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

  // ---- 行 ----
  async createRow(chartId: number, payload: GanttRowInput & { title: string }): Promise<GanttRow> {
    const { data } = await client.post(`/gantt-charts/${chartId}/rows`, payload)
    return data.data
  },

  async updateRow(rowId: number, patch: GanttRowInput): Promise<GanttRow> {
    const { data } = await client.put(`/gantt-rows/${rowId}`, patch)
    return data.data
  },

  /** 行の削除（行の区間もまとめて消える） */
  async removeRow(rowId: number): Promise<void> {
    await client.delete(`/gantt-rows/${rowId}`)
  },

  async reorderRows(chartId: number, ids: number[]): Promise<GanttRow[]> {
    const { data } = await client.put(`/gantt-charts/${chartId}/rows/reorder`, { ids })
    return data.data
  },

  // ---- 区間 ----
  async createTask(rowId: number, payload: GanttTaskInput & { title: string; startOn: string }): Promise<GanttTask> {
    const { data } = await client.post(`/gantt-rows/${rowId}/tasks`, payload)
    return data.data
  },

  /** 区間の更新（ドラッグ後の期間だけの部分更新にも使う） */
  async updateTask(taskId: number, patch: GanttTaskInput): Promise<GanttTask> {
    const { data } = await client.put(`/gantt-tasks/${taskId}`, patch)
    return data.data
  },

  async removeTask(taskId: number): Promise<void> {
    await client.delete(`/gantt-tasks/${taskId}`)
  },

  // ---- 出力 ----
  /** Excel（月単位の表＋区間一覧） */
  async excel(chartId: number): Promise<Blob> {
    const res = await client.get(`/gantt-charts/${chartId}/excel`, { responseType: 'blob' })
    return res.data
  },

  /** 画面で描画したチャート画像（JPEG）を送り、PDF にして受け取る */
  async pdf(chartId: number, image: Blob): Promise<Blob> {
    const fd = new FormData()
    fd.append('image', image, 'gantt.jpg')
    const res = await client.post(`/gantt-charts/${chartId}/pdf`, fd, { responseType: 'blob' })
    return new Blob([res.data], { type: 'application/pdf' })
  },
}
