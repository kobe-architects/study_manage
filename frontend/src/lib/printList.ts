// 個別学習データ／関連問題の「画面出力＋PDF印刷」共通ヘルパー。
// 新規ウィンドウに一覧を表示し、印刷ボタン（→ ブラウザのPDF保存）を備える。

export interface PrintColumn {
  label: string
  align?: 'left' | 'center' | 'right'
  width?: string // 例: '60px'
  nowrap?: boolean
}

export interface PrintListOptions {
  title: string
  subtitle?: string
  columns: PrintColumn[]
  rows: (string | number | null | undefined)[][]
  emptyText?: string
}

function esc(s: string | number | null | undefined): string {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function buildHtml(o: PrintListOptions): string {
  const head = o.columns
    .map(
      (c) =>
        `<th style="text-align:${c.align ?? 'left'}${c.width ? `;width:${c.width}` : ''}">${esc(c.label)}</th>`,
    )
    .join('')

  const body = o.rows.length
    ? o.rows
        .map(
          (r, i) =>
            `<tr>${r
              .map((cell, ci) => {
                const c = o.columns[ci]
                const align = c?.align ?? 'left'
                const nowrap = c?.nowrap ? ';white-space:nowrap' : ''
                // 先頭列が空なら連番を補完しない（呼び出し側で番号を渡す）
                void i
                return `<td style="text-align:${align}${nowrap}">${esc(cell)}</td>`
              })
              .join('')}</tr>`,
        )
        .join('')
    : `<tr><td colspan="${o.columns.length}" class="empty">${esc(o.emptyText ?? '該当するデータがありません')}</td></tr>`

  return `<!DOCTYPE html>
<html lang="ja"><head><meta charset="utf-8"><title>${esc(o.title)}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, "Segoe UI", "Hiragino Kaku Gothic ProN", "Yu Gothic", Meiryo, sans-serif; color: #1c2024; margin: 0; padding: 24px; background: #eceef1; }
  .sheet { width: 100%; max-width: 1000px; margin: 0 auto; background: #fff; padding: 30px 34px; box-shadow: 0 6px 24px rgba(0,0,0,0.12); }
  .toolbar { position: fixed; top: 14px; right: 16px; }
  .toolbar button { padding: 8px 18px; border: none; border-radius: 8px; background: #3b50cc; color: #fff; font-size: 13px; font-weight: 700; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
  .head { border-bottom: 2px solid #1c2024; padding-bottom: 8px; margin-bottom: 16px; }
  h1 { font-size: 18px; margin: 0; }
  .sub { font-size: 12px; color: #6b7280; margin-top: 4px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; table-layout: fixed; }
  thead tr { background: #f4f6f9; }
  th { padding: 8px 9px; font-weight: 700; border-bottom: 1.5px solid #cfd4da; font-size: 11.5px; word-break: break-word; }
  td { padding: 7px 9px; border-bottom: 1px solid #eceef1; vertical-align: top; line-height: 1.5; word-break: break-word; overflow-wrap: anywhere; }
  tbody tr:nth-child(even) td { background: #fafbfc; }
  .empty { text-align: center; color: #9aa1ab; padding: 30px; }
  @media print {
    .toolbar { display: none; }
    body { background: #fff; padding: 0; }
    .sheet { max-width: none; box-shadow: none; padding: 0; }
    thead { display: table-header-group; }
    tr { break-inside: avoid; }
    tbody tr:nth-child(even) td { background: #f5f6f8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    thead tr { background: #eef1f5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style></head>
<body>
  <div class="toolbar"><button onclick="window.print()">印刷 / PDF保存</button></div>
  <div class="sheet">
    <div class="head">
      <h1>${esc(o.title)}</h1>
      ${o.subtitle ? `<div class="sub">${esc(o.subtitle)}</div>` : ''}
    </div>
    <table>
      <thead><tr>${head}</tr></thead>
      <tbody>${body}</tbody>
    </table>
  </div>
</body></html>`
}

/** 一覧を新規ウィンドウで画面表示する（印刷ボタンでPDF保存可能）。成功時 true。 */
export function openListPrint(o: PrintListOptions): boolean {
  const win = window.open('', '_blank')
  if (!win) return false
  win.document.write(buildHtml(o))
  win.document.close()
  return true
}
