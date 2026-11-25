import client from '@/api/client'
import type { GanttChart, GanttRow, GanttTask, GanttTaskKind } from '@/types'

/** API プレフィックス。講師ログイン時は /tutor 配下（講師自身の計画表。生徒側のデータとは別） */
function p(): string {
  return localStorage.getItem('sm_role') === 'tutor' ? '/tutor' : ''
}

export interface GanttChartInput {
  title: string
  note?: string | null
  startOn: string
  endOn: string
}

export interface GanttRowInput {
  title?: string
  group?: string | null
  /** 科目 ID。省略すると新規作成時は名前から推定、null は「科目なし」 */
  subjectId?: number | null
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

/** 計画表（ガントチャート）API。生徒・講師それぞれ自分側の計画表を扱う（生徒は講師の計画表を閲覧だけできる）。チャート → 行（科目・学習分野） → 区間（バー／節目） */
export const ganttApi = {
  async list(): Promise<GanttChart[]> {
    const { data } = await client.get(`${p()}/gantt-charts`)
    return data.data
  },

  async create(payload: GanttChartInput): Promise<GanttChart> {
    const { data } = await client.post(`${p()}/gantt-charts`, payload)
    return data.data
  },

  /** チャートと行・区間の一覧 */
  async show(id: number): Promise<GanttChartDetail> {
    const { data } = await client.get(`${p()}/gantt-charts/${id}`)
    return data.data
  },

  async update(id: number, patch: Partial<GanttChartInput>): Promise<GanttChartDetail> {
    const { data } = await client.put(`${p()}/gantt-charts/${id}`, patch)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await client.delete(`${p()}/gantt-charts/${id}`)
  },

  async duplicate(id: number): Promise<GanttChart> {
    const { data } = await client.post(`${p()}/gantt-charts/${id}/duplicate`)
    return data.data
  },

  /** 生徒用: 講師が登録した計画表の一覧（閲覧のみ。開くのは show） */
  async tutorCharts(): Promise<GanttChart[]> {
    const { data } = await client.get('/gantt-charts/tutor')
    return data.data
  },

  // ---- 行 ----
  async createRow(chartId: number, payload: GanttRowInput & { title: string }): Promise<GanttRow> {
    const { data } = await client.post(`${p()}/gantt-charts/${chartId}/rows`, payload)
    return data.data
  },

  async updateRow(rowId: number, patch: GanttRowInput): Promise<GanttRow> {
    const { data } = await client.put(`${p()}/gantt-rows/${rowId}`, patch)
    return data.data
  },

  /** 行の削除（行の区間もまとめて消える） */
  async removeRow(rowId: number): Promise<void> {
    await client.delete(`${p()}/gantt-rows/${rowId}`)
  },

  async reorderRows(chartId: number, ids: number[]): Promise<GanttRow[]> {
    const { data } = await client.put(`${p()}/gantt-charts/${chartId}/rows/reorder`, { ids })
    return data.data
  },

  // ---- 区間 ----
  async createTask(rowId: number, payload: GanttTaskInput & { title: string; startOn: string }): Promise<GanttTask> {
    const { data } = await client.post(`${p()}/gantt-rows/${rowId}/tasks`, payload)
    return data.data
  },

  /** 区間の更新（ドラッグ後の期間だけの部分更新にも使う） */
  async updateTask(taskId: number, patch: GanttTaskInput): Promise<GanttTask> {
    const { data } = await client.put(`${p()}/gantt-tasks/${taskId}`, patch)
    return data.data
  },

  async removeTask(taskId: number): Promise<void> {
    await client.delete(`${p()}/gantt-tasks/${taskId}`)
  },

  // ---- 出力 ----
  /** Excel（月単位の表＋区間一覧） */
  async excel(chartId: number): Promise<Blob> {
    const res = await client.get(`${p()}/gantt-charts/${chartId}/excel`, { responseType: 'blob' })
    return res.data
  },

  /** 画面で描画したチャート画像（JPEG）を送り、PDF にして受け取る */
  async pdf(chartId: number, image: Blob): Promise<Blob> {
    const fd = new FormData()
    fd.append('image', image, 'gantt.jpg')
    const res = await client.post(`${p()}/gantt-charts/${chartId}/pdf`, fd, { responseType: 'blob' })
    return new Blob([res.data], { type: 'application/pdf' })
  },
}
