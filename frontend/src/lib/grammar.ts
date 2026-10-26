// 英文法・語法 問題演習（スクランブル）の表示・採点ヘルパー（純粋関数）
import type { GrammarFormat, GrammarQuestion } from '@/types'

export const CIRCLED = ['①', '②', '③', '④', '⑤', '⑥', '⑦', '⑧', '⑨']

/** 形式のバッジ表示 */
export const FORMAT_INFO: Record<GrammarFormat, { label: string; short: string; bg: string; fg: string }> = {
  choice: { label: '選択', short: '選択', bg: '#eef1f6', fg: '#5b6b8c' },
  error: { label: '誤り指摘', short: '誤り', bg: '#fdeef0', fg: '#cf5563' },
  order: { label: '語句整序', short: '整序', bg: '#eef7f0', fg: '#3a8a5c' },
  fill: { label: '空所補充', short: '補充', bg: '#fff4e5', fg: '#b7681a' },
  image: { label: '画像', short: '画像', bg: '#f1f3fb', fg: '#3b50cc' },
}
export const FORMATS: GrammarFormat[] = ['choice', 'order', 'fill', 'error', 'image']

/** 空所「( )」 */
const BLANK_RE = /\(\s*\)/g

/** 採点用の正規化（小文字・引用符の統一・句読点を除去・空白を 1 つに） */
export function normalizeAnswer(s: string): string {
  return s
    .toLowerCase()
    .replace(/[’‘]/g, "'")
    .replace(/[“”]/g, '"')
    .replace(/[.,!?;:"]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

/** 空所の数 */
export function blankCount(text: string): number {
  return (text.match(BLANK_RE) ?? []).length
}

/** 空所で分割（空所の数 + 1 個の文字列） */
export function splitBlanks(text: string): string[] {
  return text.split(BLANK_RE)
}

/** 空所補充: 入力欄の数（正答の区切り数。空所の数と合わないときは 1 つの欄に共通語を入れる） */
export function fillInputCount(q: GrammarQuestion): number {
  return q.answers?.length ?? 1
}

/** 空所補充: 空所の中に入力欄を置けるか（空所の数と正答の数が一致） */
export function fillInline(q: GrammarQuestion): boolean {
  const n = fillInputCount(q)
  return n >= 1 && blankCount(q.question) === n
}

/** 空所補充の採点。各欄が正答候補（[ ] の別解を含む）のどれかに一致すれば正解 */
export function gradeFill(q: GrammarQuestion, inputs: string[]): boolean {
  const answers = q.answers ?? []
  if (!answers.length || inputs.length !== answers.length) return false
  return answers.every((alts, i) => {
    const v = normalizeAnswer(inputs[i] ?? '')
    return v !== '' && alts.some((a) => normalizeAnswer(a) === v)
  })
}

/** 整序: 完成させる部分（括弧内を並べた正答文字列） */
export function orderTarget(q: GrammarQuestion): string {
  return q.answers?.[0]?.[0] ?? ''
}

/** 整序の採点。選んだ語句を順に並べた文字列が正答と一致すれば正解（不要語は使わない・不足語は入力） */
export function gradeOrder(q: GrammarQuestion, chosen: string[]): boolean {
  if (!chosen.length) return false
  return normalizeAnswer(chosen.join(' ')) === normalizeAnswer(orderTarget(q))
}

/** 整序: 問題文の語句グループ「( a / b / c )」の前後の文。グループが無ければ null */
export function orderSlot(text: string): { before: string; after: string } | null {
  const m = text.match(/\(([^)]*\/[^)]*)\)/)
  if (!m || m.index === undefined) return null
  return { before: text.slice(0, m.index), after: text.slice(m.index + m[0].length) }
}

/** 問題文の先頭の〔指示〕を分ける（「〔適切でないものを1つ選べ〕 I ( ) …」など） */
export function splitLeadingNote(text: string): { note: string | null; text: string } {
  const m = text.match(/^〔(.+?)〕\s*/)
  if (!m) return { note: null, text }
  return { note: m[1] ?? null, text: text.slice(m[0].length) }
}

/** 括弧つきの文を「括弧の中（強調）」「それ以外」に分ける。整序の正解文の表示用 */
export function answerSegments(text: string): { text: string; hl: boolean }[] {
  const out: { text: string; hl: boolean }[] = []
  const re = /\(([^)]*)\)/g
  let last = 0
  let m: RegExpExecArray | null
  while ((m = re.exec(text))) {
    if (m.index > last) out.push({ text: text.slice(last, m.index), hl: false })
    out.push({ text: m[1] ?? '', hl: true })
    last = m.index + m[0].length
  }
  if (last < text.length) out.push({ text: text.slice(last), hl: false })
  return out
}

export type ErrorToken = { kind: 'text'; text: string } | { kind: 'seg'; idx: number; text: string; rest: string }

/** 誤り指摘: 問題文を「①〜⑤ の下線部」とそれ以外に分ける */
export function errorTokens(q: GrammarQuestion): ErrorToken[] {
  const text = q.question
  const marks: { pos: number; idx: number }[] = []
  CIRCLED.forEach((c, idx) => {
    const p = text.indexOf(c)
    if (p >= 0) marks.push({ pos: p, idx })
  })
  marks.sort((a, b) => a.pos - b.pos)
  if (!marks.length) return [{ kind: 'text', text }]
  const out: ErrorToken[] = []
  if (marks[0]!.pos > 0) out.push({ kind: 'text', text: text.slice(0, marks[0]!.pos) })
  marks.forEach((m, i) => {
    const end = i + 1 < marks.length ? marks[i + 1]!.pos : text.length
    const segment = text.slice(m.pos + 1, end)
    const trimmed = segment.replace(/^\s+/, '')
    const lead = segment.length - trimmed.length
    const given = q.choices?.[m.idx]
    let seg: string
    if (given && trimmed.startsWith(given)) seg = given
    else seg = trimmed.replace(/[\s.,!?;:]+$/, '')
    const rest = segment.slice(lead + seg.length)
    out.push({ kind: 'seg', idx: m.idx, text: seg, rest })
  })
  return out
}

/** 誤り指摘の訂正「a → b」を分ける */
export function splitCorrection(answerText: string | null): { from: string; to: string } | null {
  if (!answerText) return null
  const m = answerText.match(/^(.*?)\s*(?:→|->)\s*(.*)$/)
  return m ? { from: m[1]!.trim(), to: m[2]!.trim() } : null
}

/** 選択: 正解後に空所へ埋める語句（空所が複数のときは「 — 」区切りの正解を順に。合わないときは null） */
export function choiceFillParts(q: GrammarQuestion): string[] | null {
  const n = blankCount(q.question)
  if (n === 0 || !q.answerText) return null
  if (n === 1) return [q.answerText]
  const parts = q.answerText.split(/\s*[—–]\s*/)
  return parts.length === n ? parts : null
}

/** 空所補充: 正解後に空所へ埋める語句（空所と正答の数が一致するときだけ） */
export function fillAnswerParts(q: GrammarQuestion): string[] | null {
  if (!fillInline(q)) return null
  return (q.answers ?? []).map((alts) => alts[0] ?? '')
}

/** 空所に語句を埋めた文 */
export function fillSentence(text: string, parts: string[]): string {
  const pieces = splitBlanks(text)
  let out = ''
  pieces.forEach((p, i) => {
    out += p
    if (i < pieces.length - 1) out += parts[i] ?? ''
  })
  return out
}

/** 読み上げ用の完成文（指示の〔 〕・（下線部 …）は除く） */
export function completedSentence(q: GrammarQuestion): string {
  if (q.format === 'image') return q.question
  let s = q.question
  if (q.format === 'choice') {
    const parts = choiceFillParts(q)
    if (parts) s = fillSentence(s, parts)
  } else if (q.format === 'fill') {
    const parts = fillAnswerParts(q)
    if (parts) s = fillSentence(s, parts)
    else if (q.answers?.length === 1) s = fillSentence(s, Array(blankCount(s)).fill(q.answers[0]![0] ?? ''))
  } else if (q.format === 'order') {
    s = (q.answerText ?? s).replace(/[()]/g, '')
  } else if (q.format === 'error') {
    const corr = splitCorrection(q.answerText)
    s = errorTokens(q)
      .map((t) => (t.kind === 'text' ? t.text : (corr && t.idx === q.answerIndex ? t.text.replace(corr.from, corr.to.replace(/\(.*?\)\s*/g, '')) : t.text) + t.rest))
      .join('')
  }
  return s
    .replace(/〔.*?〕/g, '')
    .replace(/（.*?）/g, '')
    .replace(/NO (ERROR|WORD)/g, '')
    .replace(/\s+/g, ' ')
    .trim()
}

/** 一覧用: 問題文の先頭（指示の〔 〕を除く） */
export function questionPreview(q: GrammarQuestion): string {
  return q.question.replace(/〔.*?〕\s*/g, '').trim()
}

/** 「第1章 時制」→ { no: 1, name: '時制' } */
export function chapterParts(chapter: string): { no: number; name: string } {
  const m = chapter.match(/^第(\d+)章\s*(.*)$/)
  return m ? { no: Number(m[1]), name: m[2] ?? chapter } : { no: 0, name: chapter }
}

/** 回答状況の表示 */
export function statusOf(q: GrammarQuestion): { t: string; c: string; key: 'correct' | 'incorrect' | 'none' } {
  const last = q.stat?.last
  if (last === true) return { t: '○', c: '#2e9d62', key: 'correct' }
  if (last === false) return { t: '×', c: '#cf5563', key: 'incorrect' }
  return { t: '—', c: '#cdd2d9', key: 'none' }
}
