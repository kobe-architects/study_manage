// 問題（スクランブル）・学習カード（数学の例題）と、そこから自動で作った間違いノートのページとの結び付き（noteId）の扱い。
// 問題一覧は Pinia（grammar ストア）、カードは window 常駐のキャッシュに置かれ、画面を行き来しても取り直さない。
// そのため間違いノート一覧でページを削除しても noteId が残り、「間違いノートを開く」のまま削除済みのページへ飛んで
// 作り直せない不具合があった（2026-11-22 修正）。削除時にここで noteId を消し、開く前にもサーバーに実在を確かめる。
import { mistakeApi } from '@/api/mistake'
import { useGrammarStore } from '@/stores/grammar'
import type { CardItem } from '@/types'

/** 束ごとのカードのキャッシュ（CardDeckView と共有。タブを行き来しても取り直さない） */
export function cardCache(): Map<string, CardItem[]> {
  return ((window as unknown as { __cardCache?: Map<string, CardItem[]> }).__cardCache ??= new Map())
}

/** 間違いノートのページを削除したとき、問題・カード側が覚えている「作成済みのページ」の ID を消す */
export function forgetMistakeNote(noteId: number) {
  const g = useGrammarStore()
  for (const list of Object.values(g.questions)) {
    for (const q of list ?? []) if (q.noteId === noteId) q.noteId = null
  }
  for (const list of cardCache().values()) {
    for (const c of list) if (c.noteId === noteId) c.noteId = null
  }
}

/** 間違いノートのページがサーバーに残っているか（別の端末や一覧で削除されていれば false） */
export async function mistakeNoteExists(noteId: number): Promise<boolean> {
  try {
    await mistakeApi.show(noteId)
    return true
  } catch {
    return false
  }
}
