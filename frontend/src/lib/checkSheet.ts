// 学習記録「チェックシート」（印刷用 HTML）の共通ビルダー。
// スクランブル英文法・語法の付属チェックシート（Theme｜連番｜形式｜区分｜1回目〜5回目）と同じ体裁で、
// 回数欄には解いた日付を入れる（正解=黒・不正解=赤・あいまい=青）。英単語帳（鉄壁・LEAP basic）も同じ部品で出す。
// A4 縦 1 ページに 2 段組み。行の高さを固定し、段ごとの行数で自分でページを割る（印刷でも画面でも同じ割り付けになる）。
import { openPrintHtml } from '@/lib/printList'

/** 0=不正解（赤） 1=正解（黒） 2=あいまい（青。正解だったが自信がない） */
export type MarkStatus = 0 | 1 | 2
export interface SheetMark {
  /** YYYY-MM-DD */
  date: string
  status: MarkStatus
}
export type SheetCell = string | { html: string }
export interface SheetRow {
  no: number | string
  /** 連番の右の列（spec.columns と同じ数） */
  cells: SheetCell[]
  /** 解いた日ごとの結果（古い順） */
  marks: SheetMark[]
  /** 左端のグループ列（Theme など）。同じ key が続く行はまとめて 1 つのセルにする */
  group?: { key: string; title: string; name?: string }
}
export interface SheetChapter {
  /** 見出しの前に小さく出す（第1章・SECTION #1・Part 1 など） */
  label?: string
  title: string
  /** 問数と範囲（28問・No.1–28） */
  sub?: string
  rows: SheetRow[]
  /** この章から新しいページにする */
  newPage?: boolean
  /** この章以降のページ見出しのバッジ（文法／語法）と小見出し。省略時は前の章（最初は spec の値）を引き継ぐ */
  badge?: string
  headline?: string
}
export interface SheetColumn {
  label: string
  /** 幅（mm） */
  width: number
  align?: 'left' | 'center' | 'right'
}
export interface SheetSpec {
  title: string
  badge?: string
  headline?: string
  /** 名前欄に入れる名前 */
  name: string
  /** 出力日などの注記（ヘッダー右下） */
  note?: string
  columns: SheetColumn[]
  groupColumn?: { label: string; width: number }
  noLabel?: string
  /** 連番の幅（mm） */
  noWidth?: number
  /** 回数欄の数（省略時は 5 と実際の最大回数の大きい方。段の幅に入る数が上限） */
  markCount?: number
  chapters: SheetChapter[]
  /** 1 段に入る行数（章見出しは 2 行分） */
  capacity?: number
  accent?: string
  /** 用紙: portrait2 = A4 縦 2 段（既定）/ landscape1 = A4 横 1 段（回数欄を 30 回ぶん並べる英単語用） */
  layout?: 'portrait2' | 'landscape1'
}

const ROW_MM = 5.6
const CHAPTER_UNITS = 2
/** 1 段の幅（mm）: A4 縦は 幅 210 − 左右余白 9×2 − 段の間 5 を 2 で割る。A4 横 1 段は 297 − 18 */
function colMm(spec: SheetSpec): number {
  return spec.layout === 'landscape1' ? 297 - 18 : (210 - 18 - 5) / 2
}
function colsPerPage(spec: SheetSpec): number {
  return spec.layout === 'landscape1' ? 1 : 2
}
/** 回数欄 1 つの最小幅（mm）。小さい文字（5.2pt）で「11/22」が入る幅 */
const MARK_MIN_MM = 5.6
/** 回数欄がこの幅（mm）より狭いときは小さい文字にする（6pt の「11/22」が入らない） */
const MARK_TIGHT_MM = 7.4

function esc(s: string | number | null | undefined): string {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}
function cellHtml(c: SheetCell): string {
  return typeof c === 'string' ? esc(c) : c.html
}
/** YYYY-MM-DD → 11/2 */
export function markLabel(date: string): string {
  const m = date.match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (!m) return date
  return `${Number(m[2])}/${Number(m[3])}`
}

type Block = { kind: 'chap'; ch: SheetChapter; cont: boolean } | { kind: 'row'; row: SheetRow }
interface Page {
  badge: string
  headline: string
  cols: Block[][]
}

/** 章と行を、段（1 ページ 2 段）に割り付ける */
function paginate(spec: SheetSpec): Page[] {
  const cap = spec.capacity ?? (spec.layout === 'landscape1' ? 29 : 44)
  const nCols = colsPerPage(spec)
  const pages: Page[] = []
  let used = 0
  let badge = spec.badge ?? ''
  let headline = spec.headline ?? ''
  let forceNewPage = false

  const curPage = (): Page | undefined => pages[pages.length - 1]
  const curCol = (): Block[] | undefined => {
    const p = curPage()
    return p ? p.cols[p.cols.length - 1] : undefined
  }
  /** 次の段へ（ページの 2 段目が埋まっていれば新しいページ） */
  const newCol = (): Block[] => {
    let p = curPage()
    if (!p || p.cols.length >= nCols || forceNewPage) {
      p = { badge, headline, cols: [] }
      pages.push(p)
      forceNewPage = false
    }
    const c: Block[] = []
    p.cols.push(c)
    used = 0
    return c
  }

  for (const ch of spec.chapters) {
    if (ch.badge !== undefined) badge = ch.badge
    if (ch.headline !== undefined) headline = ch.headline
    const p = curPage()
    if (ch.newPage && p && p.cols.some((c) => c.length)) forceNewPage = true
    // 見出し＋最低 2 行が入らなければ次の段へ
    let col = curCol()
    if (!col || forceNewPage || used + CHAPTER_UNITS + Math.min(2, ch.rows.length) > cap) col = newCol()
    col.push({ kind: 'chap', ch, cont: false })
    used += CHAPTER_UNITS
    for (const row of ch.rows) {
      if (used + 1 > cap) {
        col = newCol()
        col.push({ kind: 'chap', ch, cont: true })
        used += CHAPTER_UNITS
      }
      col.push({ kind: 'row', row })
      used += 1
    }
  }
  return pages
}

function renderColumn(spec: SheetSpec, blocks: Block[], markCount: number, markWidth: number): string {
  const nCols = (spec.groupColumn ? 1 : 0) + 1 + spec.columns.length + markCount
  const head: string[] = []
  if (spec.groupColumn) head.push(`<th class="g">${esc(spec.groupColumn.label)}</th>`)
  head.push(`<th class="no">${esc(spec.noLabel ?? '連番')}</th>`)
  spec.columns.forEach((c) => head.push(`<th style="text-align:${c.align ?? 'left'}">${esc(c.label)}</th>`))
  for (let i = 1; i <= markCount; i++) head.push(`<th class="mk">${i}回目</th>`)

  const colgroup: string[] = []
  if (spec.groupColumn) colgroup.push(`<col style="width:${spec.groupColumn.width}mm">`)
  colgroup.push(`<col style="width:${spec.noWidth ?? 7}mm">`)
  spec.columns.forEach((c) => colgroup.push(`<col style="width:${c.width}mm">`))
  for (let i = 0; i < markCount; i++) colgroup.push(`<col style="width:${markWidth.toFixed(2)}mm">`)

  // グループ列: 同じ key が続く行数（章見出しで区切る）。先頭行に行数、続く行は -1
  const span: number[] = Array(blocks.length).fill(0)
  for (let i = 0; i < blocks.length; i++) {
    const b = blocks[i]!
    if (b.kind !== 'row' || span[i] === -1) continue
    const key = b.row.group?.key
    let n = 1
    while (i + n < blocks.length) {
      const nx = blocks[i + n]!
      if (nx.kind !== 'row' || (nx.row.group?.key ?? null) !== (key ?? null)) break
      n++
    }
    span[i] = n
    for (let k = 1; k < n; k++) span[i + k] = -1
  }

  const body: string[] = []
  blocks.forEach((b, i) => {
    if (b.kind === 'chap') {
      const ch = b.ch
      const label = ch.label ? `<span class="chap-l">${esc(ch.label)}</span>` : ''
      const sub = b.cont ? '<span class="chap-s">（続き）</span>' : ch.sub ? `<span class="chap-s">${esc(ch.sub)}</span>` : ''
      body.push(`<tr class="chap"><td colspan="${nCols}">${label}<span class="chap-t">${esc(ch.title)}</span>${sub}</td></tr>`)
      return
    }
    const r = b.row
    const tds: string[] = []
    if (spec.groupColumn) {
      const n = span[i]!
      if (n > 0) {
        const g = r.group
        const maxH = (n * ROW_MM - 1.6).toFixed(1)
        tds.push(
          `<td class="g" rowspan="${n}"><div class="gbox" style="max-height:${maxH}mm">${
            g ? `<div class="gt">${esc(g.title)}</div>${g.name ? `<div class="gn">${esc(g.name)}</div>` : ''}` : ''
          }</div></td>`,
        )
      }
    }
    tds.push(`<td class="no">${esc(r.no)}</td>`)
    r.cells.forEach((c, ci) => tds.push(`<td style="text-align:${spec.columns[ci]?.align ?? 'left'}">${cellHtml(c)}</td>`))
    const marks = r.marks.length > markCount ? r.marks.slice(r.marks.length - markCount) : r.marks
    for (let k = 0; k < markCount; k++) {
      const m = marks[k]
      if (!m) tds.push('<td class="mk"></td>')
      else tds.push(`<td class="mk ${m.status === 0 ? 'ng' : m.status === 2 ? 'un' : 'ok'}" title="${esc(m.date)}">${esc(markLabel(m.date))}</td>`)
    }
    body.push(`<tr>${tds.join('')}</tr>`)
  })

  return `<table class="t${markWidth < MARK_TIGHT_MM ? ' tight' : ''}"><colgroup>${colgroup.join('')}</colgroup><thead><tr>${head.join('')}</tr></thead><tbody>${body.join('')}</tbody></table>`
}

export function buildCheckSheetHtml(spec: SheetSpec): string {
  const accent = spec.accent ?? '#d0266f'
  let maxMarks = 0
  for (const ch of spec.chapters) for (const r of ch.rows) maxMarks = Math.max(maxMarks, r.marks.length)
  // 回数欄: 固定列を除いた残りの幅に入るだけ（最低 5 つ。多すぎる回数は新しい方から入れる）
  const fixed = (spec.groupColumn?.width ?? 0) + (spec.noWidth ?? 7) + spec.columns.reduce((n, c) => n + c.width, 0)
  const avail = Math.max(MARK_MIN_MM * 5, colMm(spec) - fixed)
  const maxFit = Math.max(5, Math.floor(avail / MARK_MIN_MM))
  const markCount = Math.min(maxFit, spec.markCount ?? Math.max(5, Math.min(maxMarks, maxFit)))
  const markWidth = avail / markCount
  const pages = paginate(spec)
  const total = pages.length
  const landscape = spec.layout === 'landscape1'
  const nCols = colsPerPage(spec)

  const pageHtml = pages
    .map((p, pi) => {
      const cols = Array.from({ length: nCols }, (_, k) => `<div class="col">${p.cols[k] ? renderColumn(spec, p.cols[k]!, markCount, markWidth) : ''}</div>`).join('')
      return `<section class="page">
  <div class="head">
    <div class="head-l">
      <div class="title">${esc(spec.title)}${p.badge ? `<span class="badge">${esc(p.badge)}</span>` : ''}<span class="sheet">チェックシート</span></div>
      <div class="headline">${esc(p.headline)}</div>
    </div>
    <div class="head-r">
      <div class="name">名前 <span class="name-v">${esc(spec.name)}</span></div>
      <div class="legend"><span class="ok">11/2 正解</span><span class="ng">11/2 不正解</span><span class="un">11/2 あいまい</span>${spec.note ? `<span class="note">${esc(spec.note)}</span>` : ''}</div>
    </div>
  </div>
  <div class="cols">${cols}</div>
  <div class="foot">p.${pi + 1} / ${total}</div>
</section>`
    })
    .join('\n')

  return `<!DOCTYPE html>
<html lang="ja"><head><meta charset="utf-8"><title>${esc(spec.title)} チェックシート</title>
<style>
  :root { --accent: ${accent}; --s: 1; }
  * { box-sizing: border-box; }
  @page { size: A4 ${landscape ? 'landscape' : 'portrait'}; margin: 0; }
  html, body { margin: 0; padding: 0; }
  body { background: #e6e8ec; font-family: -apple-system, "Segoe UI", "Hiragino Kaku Gothic ProN", "Yu Gothic", Meiryo, sans-serif; color: #1c2024; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .toolbar { position: fixed; top: 12px; right: 14px; z-index: 5; }
  .toolbar button { padding: 8px 18px; border: none; border-radius: 8px; background: #3b50cc; color: #fff; font-size: 13px; font-weight: 700; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
  .pages { zoom: var(--s); padding: 8mm 0; }
  .page { position: relative; width: ${landscape ? '297mm' : '210mm'}; height: ${landscape ? '210mm' : '297mm'}; margin: 0 auto 8mm; padding: 9mm 9mm 7mm; background: #fff; box-shadow: 0 4px 18px rgba(0,0,0,0.12); overflow: hidden; page-break-after: always; break-after: page; }
  .page:last-child { margin-bottom: 0; }
  .head { display: flex; justify-content: space-between; align-items: flex-end; gap: 6mm; border-bottom: 0.6mm solid var(--accent); padding-bottom: 1.6mm; margin-bottom: 2.4mm; height: 13mm; }
  .title { font-size: 15pt; font-weight: 800; color: var(--accent); letter-spacing: 0.02em; line-height: 1.1; white-space: nowrap; }
  .badge { display: inline-block; margin-left: 2.5mm; padding: 0.4mm 2.2mm; border-radius: 1.2mm; background: var(--accent); color: #fff; font-size: 8pt; font-weight: 700; vertical-align: middle; }
  .sheet { margin-left: 2.5mm; font-size: 9.5pt; font-weight: 700; color: #1c2024; vertical-align: middle; }
  .headline { font-size: 6.5pt; color: #555; margin-top: 1.2mm; }
  .head-r { text-align: right; white-space: nowrap; }
  .name { font-size: 8pt; color: #333; }
  .name-v { display: inline-block; min-width: 42mm; border-bottom: 0.3mm solid #333; padding: 0 2mm 0.4mm; font-size: 9.5pt; font-weight: 600; text-align: left; }
  .legend { margin-top: 1.4mm; font-size: 6.2pt; display: flex; gap: 2.4mm; justify-content: flex-end; }
  .legend .note { color: #777; }
  .cols { display: grid; grid-template-columns: ${nCols === 1 ? '1fr' : '1fr 1fr'}; gap: 5mm; align-items: start; }
  .col { min-width: 0; }
  .t { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 6.5pt; }
  .t th { height: 5mm; padding: 0 0.8mm; font-size: 5.6pt; font-weight: 600; color: #555; text-align: left; border-bottom: 0.3mm solid #888; white-space: nowrap; overflow: hidden; }
  .t th.no { text-align: right; }
  .t th.mk { text-align: center; border-left: 0.2mm solid #ddd; }
  .t td { height: ${ROW_MM}mm; padding: 0 0.8mm; border-bottom: 0.2mm solid #ddd; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
  .t td.no { text-align: right; font-size: 8.2pt; font-weight: 700; }
  .t td.mk { text-align: center; font-size: 6pt; font-weight: 700; padding: 0 0.5mm; border-left: 0.2mm solid #e3e3e3; letter-spacing: -0.02em; text-overflow: clip; }
  .t.tight td.mk { font-size: 5.2pt; padding: 0 0.25mm; letter-spacing: -0.04em; }
  .t.tight th.mk { font-size: 4.8pt; padding: 0 0.25mm; }
  .t td.g { background: #fde4ed; vertical-align: top; padding: 0.9mm 0.8mm 0; border-bottom: 0.3mm solid #fff; white-space: normal; }
  .gbox { overflow: hidden; }
  .gt { font-size: 6.2pt; font-weight: 800; color: var(--accent); line-height: 1.2; }
  .gn { font-size: 5.1pt; line-height: 1.25; color: #333; margin-top: 0.4mm; word-break: break-all; }
  .t tr.chap td { height: ${ROW_MM * CHAPTER_UNITS}mm; padding: 0 0 0.6mm; border-bottom: 0.4mm solid #1c2024; vertical-align: bottom; white-space: nowrap; }
  .chap-l { font-size: 7pt; font-weight: 700; color: #1c2024; margin-right: 1.2mm; }
  .chap-t { font-size: 10.5pt; font-weight: 800; color: #1c2024; }
  .chap-s { font-size: 6.2pt; color: #666; margin-left: 2mm; }
  .ok { color: #1c2024; } .ng { color: #d8232a; } .un { color: #1f5fd0; }
  .tag { display: inline-block; padding: 0 0.5mm; margin-right: 0.4mm; border: 0.2mm solid #999; border-radius: 0.6mm; font-size: 4.6pt; font-weight: 700; line-height: 1.6; color: #444; vertical-align: middle; letter-spacing: -0.02em; }
  .tag.p { border-color: var(--accent); color: var(--accent); }
  .tag.pf { border-color: var(--accent); background: var(--accent); color: #fff; }
  .tag.d { border-color: #333; background: #333; color: #fff; }
  .star { color: #e0a93b; font-size: 5.5pt; margin-left: 0.6mm; }
  .foot { position: absolute; left: 9mm; bottom: 3.2mm; font-size: 6pt; color: #888; }
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .pages { zoom: 1; padding: 0; }
    .page { margin: 0; box-shadow: none; }
  }
</style></head>
<body>
  <div class="toolbar"><button onclick="window.print()">印刷 / PDF保存</button></div>
  <div class="pages">${pageHtml}</div>
  <script>
    // 画面が A4 より狭いとき（スマホ・アプリ内表示）は縮小して全体を見せる。印刷時は等倍
    (function () {
      function fit() {
        var page = document.querySelector('.page');
        if (!page) return;
        var w = document.documentElement.clientWidth;
        document.documentElement.style.setProperty('--s', '1');
        var pw = page.getBoundingClientRect().width;
        var s = Math.min(1, (w - 12) / pw);
        document.documentElement.style.setProperty('--s', String(s));
      }
      fit();
      window.addEventListener('resize', fit);
      window.addEventListener('beforeprint', function () { document.documentElement.style.setProperty('--s', '1'); });
      window.addEventListener('afterprint', fit);
    })();
  </script>
</body></html>`
}

/** チェックシートを別タブ（タッチ端末はアプリ内ビューア）で開く。成功時 true */
export function openCheckSheet(spec: SheetSpec): boolean {
  return openPrintHtml(buildCheckSheetHtml(spec), `${spec.title} チェックシート`)
}
