import client from '@/api/client'
import type { InvoiceDetail, InvoiceStatus, InvoiceSummary } from '@/types'

/** API プレフィックス。講師ログイン時は /tutor 配下 */
function p(): string {
  return localStorage.getItem('sm_role') === 'tutor' ? '/tutor' : ''
}

export const invoiceApi = {
  async list(): Promise<InvoiceSummary[]> {
    const { data } = await client.get(`${p()}/invoices`)
    return data.data
  },

  async show(id: number): Promise<InvoiceDetail> {
    const { data } = await client.get(`${p()}/invoices/${id}`)
    return data.data
  },

  /** 稼働時間の登録（講師）。該当月の請求書がなければ自動作成される */
  async addEntry(payload: { workOn: string; startMin: number; endMin: number; note?: string | null }): Promise<InvoiceDetail> {
    const { data } = await client.post(`${p()}/invoice-entries`, payload)
    return data.data
  },

  async removeEntry(entryId: number): Promise<void> {
    await client.delete(`${p()}/invoice-entries/${entryId}`)
  },

  /** 時給・メモの更新（講師・仮発行前まで） */
  async update(id: number, patch: { hourlyRate?: number; note?: string | null }): Promise<InvoiceDetail> {
    const { data } = await client.put(`${p()}/invoices/${id}`, patch)
    return data.data
  },

  /** ステータス操作。close/reopen/confirm/confirm-payment は講師、issue/pay は生徒 */
  async action(id: number, act: 'close' | 'reopen' | 'issue' | 'pay' | 'confirm' | 'confirm-payment'): Promise<InvoiceDetail> {
    const { data } = await client.post(`${p()}/invoices/${id}/${act}`)
    return data.data
  },

  /**
   * 請求書 PDF を別タブでプレビュー。画面側で描画した請求書画像を送り、サーバーで PDF 化して受け取る。
   * ポップアップブロック回避のため、クリック直後（同期）に空タブを開いてから処理する。
   */
  async openPdf(id: number, image: Blob): Promise<void> {
    const w = window.open('', '_blank')
    try {
      const fd = new FormData()
      fd.append('image', image, 'invoice.jpg')
      const res = await client.post(`${p()}/invoices/${id}/pdf`, fd, { responseType: 'blob' })
      const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
      if (w) w.location.replace(url)
      else window.open(url, '_blank')
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch (e) {
      w?.close()
      throw e
    }
  },
}

/** ステータス表示（label と配色クラス） */
export const INVOICE_STATUS: Record<InvoiceStatus, { label: string; cls: string }> = {
  open: { label: '入力中', cls: 'open' },
  closed: { label: '締め済み（仮発行待ち）', cls: 'closed' },
  issued: { label: '仮発行済み（講師確認待ち）', cls: 'issued' },
  confirmed: { label: '正式発行（支払待ち）', cls: 'confirmed' },
  paid: { label: '支払済み（支払確認待ち）', cls: 'paid' },
  done: { label: '支払確認済み', cls: 'done' },
}

/** 分 → "H:MM" */
export function minToTime(min: number): string {
  return `${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')}`
}

/** 分 → "3.5" のような時間表記 */
export function hoursLabel(minutes: number): string {
  const h = minutes / 60
  return Number.isInteger(h) ? String(h) : h.toFixed(1)
}

export function yen(n: number): string {
  return '¥' + n.toLocaleString()
}

/** "2026-09-05" → "9/5(金)" */
export function workDateLabel(d: string): string {
  const [y, m, dd] = d.split('-').map(Number)
  const dow = '日月火水木金土'[new Date(y!, m! - 1, dd).getDay()]
  return `${m}/${dd}(${dow})`
}
