// 小テストの出題（講師の出題ウィザード・生徒の自己出題）で共通に使う、送信データの組み立て。
// 表紙・目安時間のスタンプ・英単語テストの問題用紙／解答用紙は、サーバーに日本語フォントが無いため画面側で画像に描いて送る。
import type { SelectedPage } from '@/components/PdfPagePicker.vue'
import { renderQuizCover, renderTimeStamp } from '@/lib/pdfCover'
import { pageGuideMinutes } from '@/lib/design'
import { renderVocabSheet, TEST_FORMAT_LABEL, TEST_TYPE_LABEL } from '@/lib/vocabTest'
import type { QuizBook, QuizPageSpec } from '@/types'

/** 小テストの表紙（科目バッジ・「小テスト」・小テスト名・教材名・氏名／解答日欄）。英単語テストは「英語」の科目色を使う */
export async function buildQuizCover(books: QuizBook[], quizTitle: string, bookId: number | null, bookTitle: string, pages: SelectedPage[]): Promise<Blob> {
  const book = bookId !== null ? books.find((b) => b.id === bookId) : books.find((b) => b.subjectName === '英語')
  const subject = bookId !== null ? (book?.subjectName ?? '') : '英語'
  return renderQuizCover({ subject: subject || '－', color: book?.colorVivid ?? '#475569', quizTitle, bookTitle, totalMinutes: totalGuideMinutes(pages) })
}

/** 目安時間の合計（★のある行のみ。無ければ null） */
export function totalGuideMinutes(pages: SelectedPage[]): number | null {
  let sum = 0
  let any = false
  for (const p of pages) {
    const m = pageGuideMinutes(p)
    if (m !== null) {
      sum += m
      any = true
    }
  }
  return any ? sum : null
}

/** 選んだページに出てくる目安時間（分）ごとのスタンプ画像（サーバーが各ページの右上に置く） */
export async function buildQuizStamps(pages: SelectedPage[]): Promise<Record<number, Blob>> {
  const out: Record<number, Blob> = {}
  for (const p of pages) {
    const m = pageGuideMinutes(p)
    if (m !== null && !out[m]) out[m] = await renderTimeStamp(m)
  }
  return out
}

export function toQuizPageSpec(p: SelectedPage): QuizPageSpec {
  return p.kind === 'vocab'
    ? { kind: 'vocab', label: p.label, vocab: p.vocab }
    : { kind: 'pdf', pdfId: p.pdfId, page: p.page, itemId: p.itemId ?? null, label: p.label, refPdfId: p.refPdfId ?? null, refPage: p.refPage ?? null }
}

/** 英単語テストの問題用紙／解答用紙を画像として描画（サーバーで PDF に組み込む）。問題番号はページを跨いで連番 */
export async function buildQuizRenders(pages: SelectedPage[], printTitle: string): Promise<Record<number, { question: Blob; answer: Blob }>> {
  const renders: Record<number, { question: Blob; answer: Blob }> = {}
  let no = 1
  for (let i = 0; i < pages.length; i++) {
    const p = pages[i]!
    if (p.kind !== 'vocab' || !p.vocab) continue
    const v = p.vocab
    const names = v.sectionNames.slice(0, 3).join('、') + (v.sectionNames.length > 3 ? ' 他' : '')
    const spec = {
      title: `${v.resourceName} 英単語テスト`,
      sub: `${TEST_TYPE_LABEL[v.testType]}・${TEST_FORMAT_LABEL[v.testFormat]}（${v.words.length}問）${names ? '　' + names : ''}`,
      type: v.testType,
      format: v.testFormat,
      words: v.words,
      startNo: no,
      pageLabel: `${printTitle}　${i + 1} / ${pages.length}`,
    }
    no += v.words.length
    renders[i] = { question: await renderVocabSheet(spec, false), answer: await renderVocabSheet(spec, true) }
  }
  return renders
}

/** 1 回の出題を教材ごとのパート（別の問題 PDF・別提出）に分ける。英単語テストは 1 つのパート */
export interface QuizPart {
  key: string
  bookId: number | null
  label: string
  pages: SelectedPage[]
}
export function splitQuizParts(pages: SelectedPage[]): QuizPart[] {
  const list: QuizPart[] = []
  for (const p of pages) {
    const key = p.kind === 'vocab' ? 'vocab' : `b${p.bookId ?? 0}`
    let part = list.find((x) => x.key === key)
    if (!part) {
      part = { key, bookId: p.kind === 'vocab' ? null : (p.bookId ?? null), label: p.kind === 'vocab' ? '英単語テスト' : p.bookTitle || '教材', pages: [] }
      list.push(part)
    }
    part.pages.push(p)
  }
  return list
}

/** 新しい出題のグループキー（教材ごとに分かれるパートを 1 つの小テストとしてまとめる） */
export function newGroupKey(): string {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`
}
