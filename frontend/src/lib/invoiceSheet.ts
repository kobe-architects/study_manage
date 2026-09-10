import { hoursLabel, minToTime, workDateLabel, yen } from '@/api/invoice'
import type { InvoiceDetail } from '@/types'

/**
 * 請求書を A4（1240x1754px ≒ 150dpi）の canvas に描画して JPEG Blob を返す。
 * サーバー側の FPDF は日本語フォントを持たないため、日本語の描画はここで行い、
 * サーバーでは画像を1ページの PDF に変換するだけにしている。
 */
const W = 1240
const H = 1754
const FONT = "'Zen Kaku Gothic New', 'Hiragino Sans', 'Yu Gothic', 'Meiryo', sans-serif"

export async function renderInvoiceSheet(inv: InvoiceDetail): Promise<Blob> {
  // フォント読み込みを待ってから描画（フォールバック字形での出力を防ぐ）
  if (document.fonts?.ready) {
    await document.fonts.ready
  }
  const c = document.createElement('canvas')
  c.width = W
  c.height = H
  const x = c.getContext('2d')!
  x.fillStyle = '#ffffff'
  x.fillRect(0, 0, W, H)
  x.textBaseline = 'alphabetic'

  const M = 100 // 左右余白
  const right = W - M
  const provisional = inv.status === 'closed' || inv.status === 'issued'

  // タイトル
  x.fillStyle = '#1c2024'
  x.font = `700 56px ${FONT}`
  x.textAlign = 'center'
  x.fillText('請　求　書', W / 2, 150)
  x.strokeStyle = '#1c2024'
  x.lineWidth = 3
  line(x, W / 2 - 170, 172, W / 2 + 170, 172)

  // 仮発行スタンプ
  if (provisional) {
    x.save()
    x.strokeStyle = '#cf4444'
    x.fillStyle = '#cf4444'
    x.lineWidth = 4
    x.strokeRect(right - 150, 90, 150, 64)
    x.font = `700 34px ${FONT}`
    x.fillText('仮発行', right - 75, 135)
    x.restore()
  }

  // 発行日・請求書番号（右上）
  x.textAlign = 'right'
  x.font = `400 26px ${FONT}`
  x.fillStyle = '#333'
  const issued = inv.issuedAt ?? inv.closedAt
  const d = issued ? new Date(issued.replace(' ', 'T')) : new Date()
  x.fillText(`発行日: ${d.getFullYear()}年${d.getMonth() + 1}月${d.getDate()}日`, right, 230)
  x.fillText(`No. ${inv.year}${String(inv.month).padStart(2, '0')}-${inv.id}`, right, 268)

  // 宛先（左）
  x.textAlign = 'left'
  x.font = `700 40px ${FONT}`
  x.fillStyle = '#1c2024'
  x.fillText(`${inv.studentName ?? ''} 様`, M, 320)
  x.lineWidth = 2
  x.strokeStyle = '#9aa1ab'
  line(x, M, 336, M + 420, 336)

  // 請求元（右）
  x.textAlign = 'right'
  x.font = `400 26px ${FONT}`
  x.fillStyle = '#333'
  x.fillText('請求元:', right - 220, 330)
  x.font = `700 30px ${FONT}`
  x.fillText(`${inv.tutorName ?? ''}`, right, 330)

  // 件名
  x.textAlign = 'left'
  x.font = `400 28px ${FONT}`
  x.fillText(`下記のとおりご請求申し上げます。（${inv.year}年${inv.month}月分 指導料）`, M, 410)

  // 合計金額ボックス
  x.fillStyle = '#f2f4f7'
  x.fillRect(M, 445, W - 2 * M, 92)
  x.strokeStyle = '#1c2024'
  x.lineWidth = 2.5
  x.strokeRect(M, 445, W - 2 * M, 92)
  x.fillStyle = '#1c2024'
  x.font = `700 32px ${FONT}`
  x.fillText('ご請求金額', M + 30, 505)
  x.textAlign = 'right'
  x.font = `700 46px ${FONT}`
  x.fillText(`${yen(inv.amount)} -`, right - 30, 508)

  // 明細テーブル
  const tableX = M
  const tableW = W - 2 * M
  const col = { date: tableX, time: tableX + 300, hours: tableX + 640, note: tableX + 830 }
  let y = 590
  const n = inv.entries.length
  const rowH = Math.max(30, Math.min(40, Math.floor((1470 - y - 46) / Math.max(1, n))))

  x.fillStyle = '#e8ebef'
  x.fillRect(tableX, y, tableW, 46)
  x.strokeStyle = '#8a9099'
  x.lineWidth = 1.5
  x.strokeRect(tableX, y, tableW, 46)
  x.fillStyle = '#1c2024'
  x.font = `700 24px ${FONT}`
  x.textAlign = 'left'
  x.fillText('日付', col.date + 16, y + 32)
  x.fillText('時間', col.time + 16, y + 32)
  x.fillText('稼働時間', col.hours + 16, y + 32)
  x.fillText('備考', col.note + 16, y + 32)
  y += 46

  x.font = `400 24px ${FONT}`
  for (const e of inv.entries) {
    x.strokeStyle = '#c8cdd4'
    x.lineWidth = 1
    x.strokeRect(tableX, y, tableW, rowH)
    x.fillStyle = '#333'
    x.textAlign = 'left'
    const ty = y + rowH / 2 + 9
    x.fillText(workDateLabel(e.workOn), col.date + 16, ty)
    x.fillText(`${minToTime(e.startMin)} 〜 ${minToTime(e.endMin)}`, col.time + 16, ty)
    x.fillText(`${hoursLabel(e.minutes)} 時間`, col.hours + 16, ty)
    if (e.note) x.fillText(e.note.slice(0, 18), col.note + 16, ty)
    y += rowH
  }

  // 合計欄
  y += 24
  x.textAlign = 'right'
  x.font = `400 28px ${FONT}`
  x.fillStyle = '#333'
  x.fillText(`合計稼働時間　${hoursLabel(inv.totalMinutes)} 時間`, right, y + 30)
  x.fillText(`時給　${yen(inv.hourlyRate)}`, right, y + 74)
  x.font = `700 34px ${FONT}`
  x.fillStyle = '#1c2024'
  x.fillText(`合計金額　${yen(inv.amount)}（税込）`, right, y + 128)
  x.strokeStyle = '#1c2024'
  x.lineWidth = 2
  line(x, right - 520, y + 144, right, y + 144)

  // メモ
  if (inv.note) {
    x.textAlign = 'left'
    x.font = `400 24px ${FONT}`
    x.fillStyle = '#555'
    x.fillText(`備考: ${inv.note.slice(0, 40)}`, M, y + 200)
  }

  // フッター
  x.textAlign = 'center'
  x.font = `400 20px ${FONT}`
  x.fillStyle = '#9aa1ab'
  x.fillText('受験ナビ - 講師請求管理', W / 2, H - 60)

  return await new Promise<Blob>((resolve, reject) =>
    c.toBlob((b) => (b ? resolve(b) : reject(new Error('render'))), 'image/jpeg', 0.92),
  )
}

function line(x: CanvasRenderingContext2D, x1: number, y1: number, x2: number, y2: number): void {
  x.beginPath()
  x.moveTo(x1, y1)
  x.lineTo(x2, y2)
  x.stroke()
}
