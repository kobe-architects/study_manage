// 学習カードを 4 択の小問に組み立てる。
//   例文・和訳（translate）… 英文を見て、正しい和訳を 4 つから選ぶ（誤答は同じ週の他の例文の和訳）
//   例文・空所補充（fill）… 太字の語（空所）ごとに、入る語句を 4 つから選ぶ（誤答は他の例文の空所の語句。語数が同じものを優先）
//   囲み・意味→表現（ja2en）／表現→意味（en2ja）… 囲みの 1 行ずつが小問。囲み 1 つを 1 枚のカードとしてまとめて答える
//     （誤答は同じ週の他の囲みの行。同じ囲みの行は使わない）
//   問題（image。合格るの例題）… OCR で読んだ答えと、数値を変えた誤答（データに入っている extra.subs）
// 選択肢の並びはカードごとに決まった乱数で混ぜる（描き直しても変わらない）。組み立てられないカード（和訳の無い例文、表だけの囲み、
// 答えが読めなかった問題）は null を返し、従来どおり「答えを見る → ○／×」の自己判定で出題する。
import type { CardItem, CardMode } from '@/types'

export interface SubQuestion {
  key: string
  /** 小問の番号など（(1)・1.）。無ければ null */
  label: string | null
  /** 小問の問い（意味・表現。例文の和訳や空所補充では本文側に出すので null） */
  prompt: string | null
  answer: string
  /** 正解を含む選択肢（混ぜてある） */
  options: string[]
}

/** 決まった種で同じ並びになる乱数（mulberry32） */
function rng(seed: number): () => number {
  let a = seed >>> 0
  return () => {
    a = (a + 0x6d2b79f5) >>> 0
    let t = a
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}
function shuffleWith<T>(arr: T[], rand: () => number): T[] {
  const a = arr.slice()
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(rand() * (i + 1))
    ;[a[i], a[j]] = [a[j]!, a[i]!]
  }
  return a
}
const MODE_SEED: Record<CardMode, number> = { translate: 1, fill: 2, ja2en: 3, en2ja: 4, image: 5 }

export function normalizeText(s: string): string {
  return s
    .toLowerCase()
    .replace(/[’‘]/g, "'")
    .replace(/[“”]/g, '"')
    .replace(/[.,!?;:"、。，．・…]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

/** 誤答の候補として出せる体裁か（OCR の断片を避ける: 括弧が閉じている・数字を含まない・長すぎない） */
function tidy(s: string): boolean {
  if (s.length > 48) return false
  if (/\d/.test(s)) return false
  const open = (s.match(/[(（]/g) ?? []).length
  const close = (s.match(/[)）]/g) ?? []).length
  if (open !== close) return false
  return !/[A-Za-z]\s*\/\s*$/.test(s)
}

/** 候補から正解と重複しないものを 3 つ選ぶ。first を優先し、足りなければ rest から（体裁の悪い候補は後回し） */
function pick3(answer: string, first: string[], rest: string[], rand: () => number): string[] {
  const seen = new Set<string>([normalizeText(answer)])
  const out: string[] = []
  const groups = [first.filter(tidy), rest.filter(tidy), first.filter((s) => !tidy(s)), rest.filter((s) => !tidy(s))]
  for (const group of groups) {
    for (const c of shuffleWith(group, rand)) {
      const n = normalizeText(c)
      if (!n || seen.has(n)) continue
      seen.add(n)
      out.push(c)
      if (out.length >= 3) return out
    }
  }
  return out
}
function finish(answer: string, wrong: string[], rand: () => number): string[] | null {
  if (wrong.length < 2) return null
  return shuffleWith([answer, ...wrong.slice(0, 3)], rand)
}

const words = (s: string) => s.split(/\s+/).filter(Boolean)
export function blankText(item: CardItem, b: [number, number]): string {
  const w = words(item.front)
  return w.slice(b[0], Math.min(b[1], w.length - 1) + 1).join(' ')
}

/**
 * @param item 出すカード
 * @param mode 出題形式
 * @param pool 同じ束の全カード（誤答の候補に使う）
 */
export function buildSubQuestions(item: CardItem, mode: CardMode, pool: CardItem[]): SubQuestion[] | null {
  const rand = rng(item.id * 7919 + MODE_SEED[mode] * 104729)
  const sameChapter = (c: CardItem) => c.id !== item.id && c.chapterNo === item.chapterNo
  const other = (c: CardItem) => c.id !== item.id && c.chapterNo !== item.chapterNo

  // ---- 問題（合格る）: データの答えと誤答 ----
  if (item.kind === 'problem' || mode === 'image') {
    const subs = item.extra?.subs
    if (!subs?.length) return null
    return subs.map((s, i) => ({
      key: `s${i}`,
      label: s.label,
      prompt: null,
      answer: s.answer,
      options: shuffleWith([s.answer, ...s.options.slice(0, 3)], rand),
    }))
  }

  // ---- 囲み（表現）: 1 行 = 1 小問 ----
  if (item.kind === 'phrase') {
    const rows = (item.extra?.rows ?? []).filter((r) => r.en && r.ja)
    if (!rows.length) return null
    const rowsOf = (c: CardItem) => (c.extra?.rows ?? []).filter((r) => r.en && r.ja)
    const near = pool.filter((c) => c.kind === 'phrase' && sameChapter(c)).flatMap(rowsOf)
    const far = pool.filter((c) => c.kind === 'phrase' && other(c)).flatMap(rowsOf)
    const out: SubQuestion[] = []
    rows.forEach((r, i) => {
      const toEn = mode === 'ja2en'
      const answer = toEn ? r.en : r.ja!
      const wrong = pick3(
        answer,
        near.map((x) => (toEn ? x.en : x.ja!)),
        far.map((x) => (toEn ? x.en : x.ja!)),
        rand,
      )
      const options = finish(answer, wrong, rand)
      if (!options) return
      out.push({ key: `r${i}`, label: rows.length > 1 ? `${i + 1}.` : null, prompt: toEn ? r.ja! : r.en, answer, options })
    })
    return out.length ? out : null
  }

  // ---- 例文 ----
  if (mode === 'fill') {
    const blanks = (item.extra?.blanks ?? []).filter((b) => b[0] <= b[1] && b[0] < words(item.front).length)
    if (!blanks.length) return null
    const blankTexts = (c: CardItem) => (c.extra?.blanks ?? []).map((b) => blankText(c, b)).filter(Boolean)
    const near = pool.filter((c) => c.kind === 'example' && sameChapter(c)).flatMap(blankTexts)
    const far = pool.filter((c) => c.kind === 'example' && other(c)).flatMap(blankTexts)
    const out: SubQuestion[] = []
    blanks.forEach((b, i) => {
      const answer = blankText(item, b)
      const n = words(answer).length
      const sameLen = (s: string) => words(s).length === n
      // 語数が同じものを優先し、足りなければ語数の違うものも
      const wrong = pick3(answer, [...near.filter(sameLen), ...far.filter(sameLen)], [...near, ...far], rand)
      const options = finish(answer, wrong, rand)
      if (!options) return
      out.push({ key: `b${i}`, label: blanks.length > 1 ? `(${i + 1})` : null, prompt: null, answer, options })
    })
    return out.length === blanks.length ? out : null
  }
  // 和訳
  if (!item.back) return null
  const backs = (list: CardItem[]) => list.filter((c) => c.kind === 'example' && c.back).map((c) => c.back!)
  const wrong = pick3(item.back, backs(pool.filter(sameChapter)), backs(pool.filter(other)), rand)
  const options = finish(item.back, wrong, rand)
  if (!options) return null
  return [{ key: 't', label: null, prompt: null, answer: item.back, options }]
}
