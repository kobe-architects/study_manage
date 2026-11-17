// スクランブル（英文法・語法）の問題から間違いノートのページを自動で作る。
// 問題文・選択肢・正解・和訳などの文字データを canvas で「問題の画像」「解答の画像」に描き（画像出題の問題集は教材の切り抜きをそのまま使う）、
// 教材の詳細解説の画像と一緒にサーバーへ送る（POST /mistake-notes/from-grammar/{id}）。分類・デッキ・出典はサーバーが決める。
import client from '@/api/client'
import { grammarApi } from '@/api/grammar'
import { mistakeApi } from '@/api/mistake'
import type { GrammarAnswerResult } from '@/components/GrammarQuestionCard.vue'
import { CIRCLED, answerSegments, completedSentence, splitCorrection, splitLeadingNote } from '@/lib/grammar'
import type { GrammarQuestion, MistakeNoteDetail } from '@/types'

const W = 1400
const PAD = 56
const FONT = "'Zen Kaku Gothic New', 'DM Sans', 'Hiragino Sans', 'Yu Gothic', 'Meiryo', sans-serif"
const INK = '#1c2024'
const MUTED = '#6b7280'
const PRIMARY = '#3b50cc'

interface Seg {
  text: string
  size?: number
  weight?: number
  color?: string
}
interface Line {
  segs: Seg[]
  /** 行の基本サイズ（行間の計算に使う） */
  size: number
  gapBefore?: number
  indent?: number
}

/** 文字列を折り返し単位（英単語は単語ごと、日本語は 1 文字ずつ。空白は前の語に付ける）に分ける */
function tokens(text: string): string[] {
  const out: string[] = []
  let cur = ''
  for (const ch of Array.from(text)) {
    if (/[A-Za-z0-9'’\-.,;:!?()[\]"“”]/.test(ch)) {
      cur += ch
      continue
    }
    if (cur) {
      out.push(cur)
      cur = ''
    }
    if (ch === ' ') {
      if (out.length) out[out.length - 1] += ' '
      else out.push(' ')
    } else out.push(ch)
  }
  if (cur) out.push(cur)
  return out
}

/** 複数のスタイルを含む行を折り返して描く（measure=true なら高さだけ返す） */
function flow(ctx: CanvasRenderingContext2D, line: Line, y: number, measure: boolean): number {
  const maxW = W - PAD * 2 - (line.indent ?? 0)
  const x0 = PAD + (line.indent ?? 0)
  const lh = Math.round(line.size * 1.55)
  let x = 0
  let rows = 1
  const place: { text: string; x: number; row: number; seg: Seg }[] = []
  for (const seg of line.segs) {
    const size = seg.size ?? line.size
    ctx.font = `${seg.weight ?? 400} ${size}px ${FONT}`
    for (const tk of tokens(seg.text)) {
      const w = ctx.measureText(tk).width
      if (x > 0 && x + ctx.measureText(tk.trimEnd()).width > maxW) {
        rows++
        x = 0
        if (tk === ' ') continue
      }
      place.push({ text: tk, x, row: rows, seg })
      x += w
    }
  }
  if (!measure) {
    ctx.textBaseline = 'alphabetic'
    for (const p of place) {
      const size = p.seg.size ?? line.size
      ctx.font = `${p.seg.weight ?? 400} ${size}px ${FONT}`
      ctx.fillStyle = p.seg.color ?? INK
      ctx.fillText(p.text, x0 + p.x, y + (p.row - 1) * lh + line.size * 1.1)
    }
  }
  return rows * lh
}

function paint(lines: Line[]): Promise<Blob> {
  const probe = document.createElement('canvas').getContext('2d')!
  let h = PAD
  for (const ln of lines) h += (ln.gapBefore ?? 0) + flow(probe, ln, 0, true)
  h += PAD
  const c = document.createElement('canvas')
  c.width = W
  c.height = Math.max(200, Math.round(h))
  const ctx = c.getContext('2d')!
  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, c.width, c.height)
  let y = PAD
  for (const ln of lines) {
    y += ln.gapBefore ?? 0
    y += flow(ctx, ln, y, false)
  }
  return new Promise((resolve, reject) => c.toBlob((b) => (b ? resolve(b) : reject(new Error('画像の作成に失敗しました'))), 'image/jpeg', 0.9))
}

function header(q: GrammarQuestion): Line {
  const parts = [`No.${q.no}`, q.chapter, `Theme ${q.theme}`, q.formatLabel]
  if (q.importance) parts.push(q.importance)
  return { segs: [{ text: parts.join('　'), color: MUTED, weight: 700 }], size: 26 }
}

/** 問題の画像（文字データから） */
export function renderProblemImage(q: GrammarQuestion): Promise<Blob> {
  const lead = splitLeadingNote(q.question)
  const lines: Line[] = [header(q)]
  const inst: string[] = []
  if (lead.note) inst.push(`〔${lead.note}〕`)
  if (q.instruction) inst.push(q.instruction)
  else if (q.format === 'error') inst.push('誤りを含む下線部を選べ')
  for (const t of inst) lines.push({ segs: [{ text: t, color: '#4b5563' }], size: 32, gapBefore: 18 })
  lines.push({ segs: [{ text: lead.text, weight: 500 }], size: 42, gapBefore: 22 })
  if (q.format === 'choice' && q.choices?.length) {
    const short = q.choices.every((c) => c.length <= 20) && q.choices.length <= 4
    if (short) {
      const half = Math.ceil(q.choices.length / 2)
      for (let r = 0; r < half; r++) {
        const segs: Seg[] = []
        for (const i of [r, r + half]) {
          if (i >= q.choices.length) continue
          segs.push({ text: `${CIRCLED[i]} `, color: PRIMARY, weight: 700 }, { text: q.choices[i]! + '　　　　' })
        }
        lines.push({ segs, size: 36, gapBefore: r === 0 ? 26 : 10, indent: 20 })
      }
    } else {
      q.choices.forEach((c, i) => lines.push({ segs: [{ text: `${CIRCLED[i]} `, color: PRIMARY, weight: 700 }, { text: c }], size: 36, gapBefore: i === 0 ? 26 : 10, indent: 20 }))
    }
  } else if (q.format === 'order' && q.choices?.length) {
    lines.push({ segs: [{ text: '語句: ', color: MUTED, weight: 700 }, { text: q.choices.join(' ／ ') }], size: 32, gapBefore: 22, indent: 20 })
  }
  return paint(lines)
}

/** 解答の画像（正解・完成文・和訳・注記・あなたの答え・出典） */
export function renderAnswerImage(q: GrammarQuestion, result: GrammarAnswerResult | null): Promise<Blob> {
  const lines: Line[] = []
  const label = (t: string): Seg => ({ text: `${t}　`, color: MUTED, weight: 700, size: 28 })
  if (q.format === 'choice') {
    lines.push({ segs: [label('正解'), { text: `${CIRCLED[q.answerIndex ?? 0]} ${q.answerText ?? ''}`, color: PRIMARY, weight: 700 }], size: 40 })
  } else if (q.format === 'error') {
    const corr = splitCorrection(q.answerText)
    const segs: Seg[] = [label('正解'), { text: `${CIRCLED[q.answerIndex ?? 0]} ${corr ? corr.from : (q.answerText ?? '')}`, color: PRIMARY, weight: 700 }]
    if (corr) segs.push({ text: `　→ ${corr.to}`, color: '#1f7a45', weight: 700 })
    lines.push({ segs, size: 40 })
  } else if (q.format === 'order') {
    const segs: Seg[] = [label('正解')]
    for (const s of answerSegments(q.answerText ?? '')) segs.push({ text: s.text, color: s.hl ? PRIMARY : INK, weight: s.hl ? 700 : 400 })
    lines.push({ segs, size: 38 })
  } else if (q.format === 'fill') {
    lines.push({ segs: [label('正解'), { text: (q.answers ?? []).map((a) => a.join(' / ')).join('，'), color: PRIMARY, weight: 700 }], size: 40 })
  } else if (q.answerText) {
    lines.push({ segs: [label('正解'), { text: q.answerText, color: PRIMARY, weight: 700 }], size: 38 })
  }
  if (q.format !== 'image') {
    const sentence = completedSentence(q)
    if (sentence && q.format !== 'order') lines.push({ segs: [{ text: sentence, weight: 500 }], size: 36, gapBefore: 18 })
  }
  if (q.translation) lines.push({ segs: [{ text: q.translation, color: '#4b5563' }], size: 32, gapBefore: 14 })
  if (q.answerNote) lines.push({ segs: [{ text: `〔${q.answerNote}〕`, color: '#b7681a' }], size: 28, gapBefore: 10 })
  if (q.format === 'order' && q.unneeded.length) lines.push({ segs: [{ text: `不要な語句: ${q.unneeded.join(' / ')}`, color: MUTED }], size: 28, gapBefore: 6 })
  if (q.format === 'order' && q.missing.length) lines.push({ segs: [{ text: `不足している語: ${q.missing.join(' / ')}`, color: MUTED }], size: 28, gapBefore: 6 })
  if (result && !result.isCorrect && result.answer && result.answer !== 'わからない') {
    lines.push({ segs: [label('あなたの答え'), { text: result.answer, color: '#c0444f', weight: 700 }], size: 32, gapBefore: 18 })
  } else if (result && !result.isCorrect) {
    lines.push({ segs: [label('あなたの答え'), { text: 'わからない', color: '#c0444f' }], size: 30, gapBefore: 18 })
  }
  const foot: string[] = []
  if (q.source) foot.push(`出典: ${q.source}`)
  if (q.note) foot.push(q.note)
  if (foot.length) lines.push({ segs: [{ text: foot.join('　'), color: MUTED }], size: 26, gapBefore: 18 })
  return paint(lines)
}

async function fetchImage(q: GrammarQuestion, kind: 'problem' | 'answer' | 'explanation'): Promise<Blob | null> {
  try {
    const res = await client.get(grammarApi.imageUrl(q.id, kind), { responseType: 'blob' })
    return res.data as Blob
  } catch {
    return null
  }
}

/**
 * 問題から間違いノートのページを作る。
 * 文字の問題（選択・誤り指摘・整序・空所補充）は問題・解答を描いた画像、画像出題は教材の切り抜き（問題・解答欄）をそのまま使い、
 * 詳細解説の画像があれば説明用の画像として足す。
 */
export async function createGrammarNote(q: GrammarQuestion, result: GrammarAnswerResult | null): Promise<{ note: MistakeNoteDetail; created: boolean }> {
  let problem: Blob | null = null
  let answer: Blob | null = null
  if (q.format === 'image' && q.hasProblemImage) {
    problem = await fetchImage(q, 'problem')
    answer = q.hasAnswerImage ? await fetchImage(q, 'answer') : null
    // 答えの文字があれば、切り抜きの下に文字でも残す（画像が無いときは文字だけ）
    if (!answer && (q.answerText || q.answers?.length)) answer = await renderAnswerImage(q, result)
  }
  if (!problem) problem = await renderProblemImage(q)
  if (!answer && q.format !== 'image') answer = await renderAnswerImage(q, result)
  const explanation = q.hasExplanationImage ? await fetchImage(q, 'explanation') : null
  return mistakeApi.createFromGrammar(q.id, { problem, answer, explanation })
}
