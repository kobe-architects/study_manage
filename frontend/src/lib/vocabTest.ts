import { shuffle } from '@/lib/design'
import type { PrintTestFormat, PrintTestType, VocabTestWord, Vocabulary } from '@/types'

/**
 * 英単語テスト（小テスト印刷・講師の小テストへの英単語テスト追加）の共通ロジック。
 * 問題文・解答の組み立てと、問題用紙／解答用紙の画像描画（canvas）を提供する。
 */

/** 1枚（A4 1ページ）あたりの最大出題数 */
export const VOCAB_PER_PAGE: Record<PrintTestFormat, Record<PrintTestType, number>> = {
  free: { meaning: 30, spelling: 30, fill_spelling: 10 },
  choice: { meaning: 12, spelling: 12, fill_spelling: 8 },
}

export const TEST_TYPE_LABEL: Record<PrintTestType, string> = {
  meaning: '意味を回答（単語→意味）',
  spelling: 'スペル書き取り（意味→単語）',
  fill_spelling: '例文穴埋め（書き取り）',
}
export const TEST_FORMAT_LABEL: Record<PrintTestFormat, string> = {
  free: 'フリー回答方式',
  choice: '4択選択方式',
}

export function fillSentence(w: Vocabulary): string {
  if (!w.exampleSentence) return ''
  try {
    const re = new RegExp('\\b' + w.word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[a-z]*', 'i')
    return w.exampleSentence.replace(re, '(　________　)')
  } catch {
    return w.exampleSentence
  }
}

export function questionOf(w: Vocabulary, type: PrintTestType): string {
  if (type === 'meaning') return w.word
  if (type === 'spelling') return w.meaning
  return fillSentence(w)
}
export function answerOf(w: Vocabulary, type: PrintTestType): string {
  return type === 'meaning' ? w.meaning : w.word
}

function choicesFor(w: Vocabulary, pool: Vocabulary[], type: PrintTestType): string[] {
  const correct = answerOf(w, type)
  const others = pool.filter((x) => x.id !== w.id).map((x) => (type === 'meaning' ? x.meaning : x.word))
  const dummies = shuffle(Array.from(new Set(others))).filter((m) => m !== correct).slice(0, 3)
  return shuffle([correct, ...dummies])
}

/** 出題語を VocabTestWord に変換（4択の選択肢はここで確定させる） */
export function buildTestWords(words: Vocabulary[], pool: Vocabulary[], type: PrintTestType, format: PrintTestFormat): VocabTestWord[] {
  return words
    .filter((w) => type !== 'fill_spelling' || w.exampleSentence)
    .map((w) => ({
      id: w.id,
      question: questionOf(w, type),
      answer: answerOf(w, type),
      choices: format === 'choice' ? choicesFor(w, pool, type) : undefined,
      extra: type === 'fill_spelling' ? w.exampleTranslation ?? (w.meaning ? `（${w.meaning}）` : null) : w.partOfSpeech ? `［${w.partOfSpeech}］` : null,
    }))
}

// ---------------- 画像描画（canvas） ----------------

const FONT = "'Zen Kaku Gothic New', 'Hiragino Kaku Gothic ProN', 'Yu Gothic', Meiryo, sans-serif"

export interface SheetSpec {
  title: string
  sub: string
  type: PrintTestType
  format: PrintTestFormat
  words: VocabTestWord[]
  /** 通し番号の開始（複数ページに分ける場合） */
  startNo?: number
  pageLabel?: string
}

function wrap(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string[] {
  const lines: string[] = []
  for (const para of text.split('\n')) {
    let cur = ''
    // 英文は単語単位、日本語は文字単位で折り返す
    const tokens = /[^\x00-\x7f]/.test(para) ? Array.from(para) : para.split(/(\s+)/)
    for (const tk of tokens) {
      const next = cur + tk
      if (ctx.measureText(next).width > maxWidth && cur) {
        lines.push(cur.trimEnd())
        cur = tk.trimStart()
      } else {
        cur = next
      }
    }
    lines.push(cur)
  }
  return lines
}

/**
 * A4（1654×2339px ≒ 200dpi）の問題用紙／解答用紙を描画して JPEG Blob を返す。
 */
export async function renderVocabSheet(spec: SheetSpec, answerKey: boolean): Promise<Blob> {
  try {
    await (document as Document & { fonts?: FontFaceSet }).fonts?.ready
  } catch {
    // フォント待ちに失敗しても描画は続行
  }
  const W = 1654
  const H = 2339
  const canvas = document.createElement('canvas')
  canvas.width = W
  canvas.height = H
  const ctx = canvas.getContext('2d')!
  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, W, H)
  ctx.fillStyle = '#1c2024'
  ctx.textBaseline = 'top'

  const M = 110 // 余白
  const startNo = spec.startNo ?? 1
  const words = spec.words
  const isChoice = spec.format === 'choice'
  const isFill = spec.type === 'fill_spelling'

  // ---- ヘッダー ----
  ctx.font = `700 44px ${FONT}`
  ctx.fillText(answerKey ? `${spec.title}　解答` : spec.title, M, M)
  ctx.font = `400 26px ${FONT}`
  ctx.fillStyle = '#6b7280'
  // 右側の名前・点数欄と重ならない幅に収める（長い場合は末尾を省略）
  const subMax = answerKey ? W - 2 * M : W - 2 * M - 560
  let sub = spec.sub
  while (sub.length > 4 && ctx.measureText(sub + '…').width > subMax) sub = sub.slice(0, -2)
  ctx.fillText(sub === spec.sub ? sub : sub + '…', M, M + 60)
  if (!answerKey) {
    ctx.textAlign = 'right'
    ctx.fillStyle = '#1c2024'
    ctx.font = `400 28px ${FONT}`
    ctx.fillText(`名前 ______________　　点 ____ / ${words.length}`, W - M, M + 20)
    ctx.textAlign = 'left'
  }
  ctx.fillStyle = '#1c2024'
  ctx.fillRect(M, M + 110, W - 2 * M, 3)

  // ---- 本文 ----
  let y = M + 150
  const cols = !answerKey && !isChoice && !isFill ? 2 : answerKey && !isFill ? 2 : 1
  const colW = (W - 2 * M - (cols - 1) * 60) / cols
  const rows = Math.ceil(words.length / cols)
  const availH = H - y - M
  const rowH = Math.max(1, Math.min(isChoice ? 210 : isFill ? 190 : 88, availH / rows))
  const baseFont = isChoice || isFill ? 30 : 32

  words.forEach((w, i) => {
    const col = Math.floor(i / rows)
    const row = i % rows
    const x = M + col * (colW + 60)
    const yy = y + row * rowH
    const no = `${startNo + i}.`
    ctx.fillStyle = '#1c2024'
    ctx.font = `700 ${baseFont - 4}px ${FONT}`
    ctx.fillText(no, x, yy + 6)
    const tx = x + 64
    const tw = colW - 64

    if (answerKey) {
      // 解答: 問題 — 解答（補足）
      ctx.font = `700 ${baseFont}px ${FONT}`
      const q = isFill ? w.answer : w.question
      const qw = Math.min(ctx.measureText(q).width, tw * 0.45)
      const qLines = wrap(ctx, q, tw * 0.45)
      ctx.fillText(qLines[0] ?? '', tx, yy + 4)
      ctx.font = `400 ${baseFont - 2}px ${FONT}`
      ctx.fillStyle = '#374151'
      const a = isFill ? `（${w.extra ?? ''}）` : `— ${w.answer}${w.extra ? ` ${w.extra}` : ''}`
      const aLines = wrap(ctx, a, tw - qw - 16)
      aLines.slice(0, 2).forEach((l, li) => ctx.fillText(l, tx + qw + 16, yy + 6 + li * (baseFont + 4)))
      return
    }

    if (isChoice) {
      ctx.font = `700 ${baseFont}px ${FONT}`
      const qLines = wrap(ctx, w.question, tw - 140)
      qLines.slice(0, 2).forEach((l, li) => ctx.fillText(l, tx, yy + 4 + li * (baseFont + 6)))
      ctx.font = `400 ${baseFont}px ${FONT}`
      ctx.textAlign = 'right'
      ctx.fillText('（　　）', x + colW, yy + 4)
      ctx.textAlign = 'left'
      ctx.font = `400 ${baseFont - 4}px ${FONT}`
      ctx.fillStyle = '#374151'
      const oy = yy + 4 + Math.min(2, qLines.length) * (baseFont + 6) + 6
      const opts = (w.choices ?? []).map((c, ci) => `${'ABCD'[ci]}. ${c}`)
      // 2列に並べる
      const ow = (tw - 20) / 2
      opts.forEach((o, oi) => {
        const ox = tx + (oi % 2) * (ow + 20)
        const oyy = oy + Math.floor(oi / 2) * (baseFont + 8)
        const ol = wrap(ctx, o, ow)
        ctx.fillText(ol[0] ?? '', ox, oyy)
      })
      return
    }

    if (isFill) {
      ctx.font = `500 ${baseFont}px ${FONT}`
      const qLines = wrap(ctx, w.question, tw)
      qLines.slice(0, 3).forEach((l, li) => ctx.fillText(l, tx, yy + 4 + li * (baseFont + 10)))
      if (w.extra) {
        ctx.font = `400 ${baseFont - 6}px ${FONT}`
        ctx.fillStyle = '#6b7280'
        const tl = wrap(ctx, w.extra, tw)
        ctx.fillText(tl[0] ?? '', tx, yy + 4 + Math.min(3, qLines.length) * (baseFont + 10) + 4)
      }
      return
    }

    // フリー: 単語（または意味） ＋ 解答欄
    ctx.font = `700 ${baseFont}px ${FONT}`
    const qLines = wrap(ctx, w.question, tw * 0.5)
    ctx.fillText(qLines[0] ?? '', tx, yy + 6)
    const bx = tx + tw * 0.5 + 16
    ctx.strokeStyle = '#9aa1ab'
    ctx.lineWidth = 2
    ctx.beginPath()
    ctx.moveTo(bx, yy + baseFont + 16)
    ctx.lineTo(x + colW, yy + baseFont + 16)
    ctx.stroke()
  })

  // ---- フッター ----
  ctx.fillStyle = '#9aa1ab'
  ctx.font = `400 22px ${FONT}`
  ctx.textAlign = 'center'
  ctx.fillText(spec.pageLabel ?? '', W / 2, H - 70)
  ctx.textAlign = 'left'

  return new Promise((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('render failed'))), 'image/jpeg', 0.9))
}
