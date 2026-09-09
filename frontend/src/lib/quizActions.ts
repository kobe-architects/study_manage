import { reactive } from 'vue'
import { quizApi } from '@/api/quiz'
import { useUiStore } from '@/stores/ui'
import type { QuizDetail, QuizSummary } from '@/types'

/**
 * 生徒側の小テスト操作（問題 PDF ダウンロード / 撮影して提出 / 結果閲覧）を
 * トップページと小テスト一覧で共用するための状態とハンドラ。
 */
export function useQuizActions(reload: () => Promise<void> | void) {
  const ui = useUiStore()
  const state = reactive<{ capture: QuizDetail | null; resultId: number | null; busy: boolean }>({
    capture: null,
    resultId: null,
    busy: false,
  })

  async function download(q: QuizSummary) {
    state.busy = true
    try {
      await quizApi.downloadQuizPdf(q.id, q.title)
    } catch {
      ui.notify('ダウンロードに失敗しました')
    } finally {
      state.busy = false
    }
  }

  async function openCapture(q: QuizSummary) {
    try {
      state.capture = await quizApi.show(q.id)
    } catch {
      ui.notify('小テストの取得に失敗しました')
    }
  }

  function openResult(q: QuizSummary) {
    state.resultId = q.id
  }

  async function onSubmitted() {
    state.capture = null
    await reload()
  }

  return { state, download, openCapture, openResult, onSubmitted }
}
