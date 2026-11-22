// 質問事項のメッセージの装飾（簡易タグ）。
// 入力欄のボタンで選択範囲をタグで囲み、表示側はタグを解析してスタイル付きの要素に描く（HTML は一切解釈しないので安全）。
//   [b]太字[/b]  [s]取消し線[/s]  [u]下線[/u]  [m]マーカー[/m]  [color=red]色[/color]（色名は RICH_COLORS のキーか #rrggbb）
//   [q]引用[/q]  [re=メッセージID]名前: 抜粋[/re]（特定のメッセージへの返信。タップでそのメッセージへ飛ぶ）
// URL は自動でリンクにする。ファイル共有のページ指定リンク（/files?file=ID&p=N）はアプリ内で開く。

export type RichTag = 'b' | 's' | 'u' | 'm' | 'q' | 're' | 'color'

export type RichNode =
  | { type: 'text'; text: string }
  | { type: 'br' }
  | { type: 'link'; href: string; text: string; shared: { file: number; page: number } | null }
  | { type: 'tag'; tag: RichTag; value: string | null; children: RichNode[] }

export const RICH_COLORS: { key: string; hex: string; label: string }[] = [
  { key: 'red', hex: '#e5322d', label: '赤' },
  { key: 'blue', hex: '#2d5be5', label: '青' },
  { key: 'green', hex: '#1f9d55', label: '緑' },
  { key: 'orange', hex: '#e58f2d', label: 'オレンジ' },
  { key: 'purple', hex: '#8b5cf6', label: '紫' },
  { key: 'gray', hex: '#6b7280', label: '灰' },
]

const TAG_RE = /\[(\/?)(b|s|u|m|q|re|color)(?:=([#\w]+))?\]/g
const REPLY_RE = /\[re=\d+\][\s\S]*?\[\/re\]\n?/g
const URL_RE = /https?:\/\/[^\s<>"'\]\[]+/g

/** 色の指定（名前か #rrggbb）を CSS の色にする。不正なら null */
export function richColor(value: string | null): string | null {
  if (!value) return null
  const named = RICH_COLORS.find((c) => c.key === value)
  if (named) return named.hex
  return /^#[0-9a-fA-F]{6}$/.test(value) ? value : null
}

/** ファイル共有のページ指定リンク（このアプリの /files?file=ID&p=N か /tutor/files?...）なら file と page を返す */
export function parseSharedLink(href: string): { file: number; page: number } | null {
  try {
    const u = new URL(href)
    if (u.origin !== location.origin) return null
    if (!/^\/(tutor\/)?files\/?$/.test(u.pathname)) return null
    const file = Number(u.searchParams.get('file'))
    if (!Number.isInteger(file) || file <= 0) return null
    const page = Number(u.searchParams.get('p'))
    return { file, page: Number.isInteger(page) && page > 0 ? page : 1 }
  } catch {
    return null
  }
}

/** 文字部分: 改行と URL を分ける */
function textNodes(text: string): RichNode[] {
  const out: RichNode[] = []
  const lines = text.split('\n')
  lines.forEach((line, li) => {
    if (li > 0) out.push({ type: 'br' })
    let last = 0
    for (const m of line.matchAll(URL_RE)) {
      const start = m.index ?? 0
      if (start > last) out.push({ type: 'text', text: line.slice(last, start) })
      // 末尾の句読点・閉じ括弧はリンクに含めない
      let href = m[0]
      let trail = ''
      while (/[.,。、）)!?]$/.test(href)) {
        trail = href.slice(-1) + trail
        href = href.slice(0, -1)
      }
      out.push({ type: 'link', href, text: href, shared: parseSharedLink(href) })
      if (trail) out.push({ type: 'text', text: trail })
      last = start + m[0].length
    }
    if (last < line.length) out.push({ type: 'text', text: line.slice(last) })
  })
  return out
}

/** 簡易タグ付きの文字を木にする。閉じていないタグは末尾で閉じ、対応しない閉じタグは文字として残す */
export function parseRich(src: string): RichNode[] {
  const root: RichNode[] = []
  const stack: { tag: RichTag; value: string | null; children: RichNode[] }[] = []
  const cur = () => (stack.length ? stack[stack.length - 1]!.children : root)
  let last = 0
  for (const m of src.matchAll(TAG_RE)) {
    const idx = m.index ?? 0
    if (idx > last) cur().push(...textNodes(src.slice(last, idx)))
    last = idx + m[0].length
    const closing = m[1] === '/'
    const tag = m[2] as RichTag
    if (!closing) {
      const value = tag === 'color' || tag === 're' ? (m[3] ?? null) : null
      if ((tag === 'color' && !richColor(value)) || (tag === 're' && !/^\d+$/.test(value ?? ''))) {
        cur().push({ type: 'text', text: m[0] })
        continue
      }
      stack.push({ tag, value, children: [] })
    } else {
      const i = stack.map((s) => s.tag).lastIndexOf(tag)
      if (i < 0) {
        cur().push({ type: 'text', text: m[0] })
        continue
      }
      // 間に開いたままのタグがあれば一緒に閉じる
      while (stack.length > i) {
        const top = stack.pop()!
        cur().push({ type: 'tag', tag: top.tag, value: top.value, children: top.children })
      }
    }
  }
  if (last < src.length) cur().push(...textNodes(src.slice(last)))
  while (stack.length) {
    const top = stack.pop()!
    cur().push({ type: 'tag', tag: top.tag, value: top.value, children: top.children })
  }
  return root
}

/** タグを外した文字だけ（一覧の抜粋・通知用）。返信先の引用は丸ごと外す */
export function richPlain(src: string): string {
  return src.replace(REPLY_RE, '').replace(TAG_RE, '')
}

/** 抜粋（改行・空白を詰めて len 文字まで） */
export function richExcerpt(src: string, len: number): string {
  const t = richPlain(src).replace(/\s+/g, ' ').trim()
  return t.length > len ? t.slice(0, len) + '…' : t
}

/** 特定のメッセージへの返信を表すタグ（本文の先頭に置く） */
export function replyTag(messageId: number, name: string, excerpt: string): string {
  return `[re=${messageId}]${name}: ${excerpt.replace(/\[/g, '［').replace(/\]/g, '］')}[/re]\n`
}

/** 選択範囲をタグで囲む（textarea 用）。選択が無ければ空のタグ対を入れてその中にカーソルを置く */
export function wrapSelection(text: string, start: number, end: number, tag: RichTag, value?: string): { text: string; start: number; end: number } {
  const open = value ? `[${tag}=${value}]` : `[${tag}]`
  const close = `[/${tag}]`
  const sel = text.slice(start, end)
  const next = text.slice(0, start) + open + sel + close + text.slice(end)
  if (sel) return { text: next, start, end: start + open.length + sel.length + close.length }
  return { text: next, start: start + open.length, end: start + open.length }
}
