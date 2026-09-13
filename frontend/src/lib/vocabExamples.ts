import type { VocabExample, Vocabulary } from '@/types'

type ExampleSource = Pick<Vocabulary, 'examples' | 'exampleSentence' | 'exampleTranslation'>

/** 単語の例文一覧。examples が未設定の単語は exampleSentence / exampleTranslation を1件の例文として返す */
export function examplesOf(w: ExampleSource | null | undefined): VocabExample[] {
  if (!w) return []
  if (w.examples && w.examples.length) {
    return w.examples.filter((e) => e.sentence && e.sentence.trim()).map((e) => ({ label: e.label ?? '', sentence: e.sentence, translation: e.translation ?? null }))
  }
  return w.exampleSentence ? [{ label: '', sentence: w.exampleSentence, translation: w.exampleTranslation ?? null }] : []
}

/** 例文中の見出語をハイライトするための分割 */
export function splitByWord(sentence: string, word: string): { before: string; word: string; after: string } {
  try {
    const escaped = word.replace(/[-[\]{}()*+?.,\\^$|#]/g, '\\$&')
    const re = new RegExp('\\b' + escaped + '[a-z]*', 'i')
    const m = sentence.match(re)
    if (m && m.index !== undefined) {
      return { before: sentence.slice(0, m.index), word: m[0], after: sentence.slice(m.index + m[0].length) }
    }
  } catch {
    // ignore
  }
  return { before: sentence, word: '', after: '' }
}
