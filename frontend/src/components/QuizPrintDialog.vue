<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import HelpTip from '@/components/HelpTip.vue'
import PdfPagePicker, { type SelectedPage } from '@/components/PdfPagePicker.vue'
import { quizApi, type PrintSource } from '@/api/quiz'
import { renderChapterCover } from '@/lib/pdfCover'
import { useUiStore } from '@/stores/ui'
import type { BookPdf, QuizBook, QuizRow } from '@/types'

/**
 * 生徒用: 教材の PDF から問題ページを選んで、自分用の問題 PDF を出力する（表示・保存）。
 * 小テストとしては登録せず、出力の記録も残さない。ページ選択は講師の出題と同じ PdfPagePicker
 * （Focus Gold などは「checkのみ」「難易度（★の数）」で絞れる）。
 */
const emit = defineEmits<{ close: [] }>()
const ui = useUiStore()

const books = ref<QuizBook[]>([])
const loading = ref(true)
const bookId = ref<number | null>(null)
const pdfs = ref<BookPdf[]>([])
const rows = ref<QuizRow[]>([])
const pages = ref<SelectedPage[]>([])
const busy = ref(false)
const loadingBook = ref(false)
/** 章ごとに表紙を付ける／両面印刷用に章の切れ目で白紙を入れる（既定オン） */
const withCovers = ref(true)
const duplex = ref(true)

const usable = computed(() => books.value.filter((b) => b.pdfCount > 0))
const book = computed(() => usable.value.find((b) => b.id === bookId.value) ?? null)
const title = computed(() => (book.value ? `${book.value.title}_${pages.value.length}ページ` : '小テスト'))

onMounted(async () => {
  try {
    books.value = await quizApi.books()
    if (usable.value.length === 1) bookId.value = usable.value[0]!.id
  } catch {
    ui.notify('教材の取得に失敗しました')
  } finally {
    loading.value = false
  }
})

watch(bookId, async (id) => {
  pages.value = []
  pdfs.value = []
  rows.value = []
  if (id === null) return
  loadingBook.value = true
  try {
    const [p, r] = await Promise.all([quizApi.listPdfs(id), quizApi.rows(id)])
    pdfs.value = p
    rows.value = r
  } catch {
    ui.notify('教材の PDF の取得に失敗しました')
  } finally {
    loadingBook.value = false
  }
})

/** 行 ID → 章名（ページ選択の行から引く。行に対応しないページは章なし） */
function chapterOf(itemId: number | null | undefined): string {
  if (!itemId) return ''
  return rows.value.find((r) => r.id === itemId)?.chapter ?? ''
}

/**
 * 出力するページ列を組み立てる。章ごとにまとめ（出現順）、章の先頭に表紙、
 * 両面印刷用に章の枚数（表紙込み）が奇数なら白紙を足して次の章が必ず表面（新しい用紙）から始まるようにする。
 */
async function buildSources(): Promise<{ sources: PrintSource[]; images: Blob[] }> {
  const sel = pages.value.filter((p) => p.pdfId && p.page)
  const groups: { chapter: string; pages: typeof sel }[] = []
  for (const p of sel) {
    const ch = chapterOf(p.itemId)
    let g = groups.find((x) => x.chapter === ch)
    if (!g) {
      g = { chapter: ch, pages: [] }
      groups.push(g)
    }
    g.pages.push(p)
  }
  const sources: PrintSource[] = []
  const images: Blob[] = []
  for (const g of groups) {
    let count = 0
    if (withCovers.value && g.chapter) {
      images.push(await renderChapterCover(g.chapter, book.value?.title ?? ''))
      sources.push({ type: 'image', index: images.length - 1 })
      count++
    }
    for (const p of g.pages) {
      sources.push({ type: 'pdf', pdfId: p.pdfId!, page: p.page! })
      count++
    }
    if (duplex.value && count % 2 === 1) sources.push({ type: 'blank' })
  }
  // 最後の白紙は不要
  while (sources.length && sources[sources.length - 1]!.type === 'blank') sources.pop()
  return { sources, images }
}

async function output(mode: 'preview' | 'download') {
  if (!pages.value.some((p) => p.pdfId && p.page)) {
    ui.notify('ページを選んでください')
    return
  }
  busy.value = true
  try {
    const { sources, images } = await buildSources()
    await quizApi.printPdf(sources, images, title.value, mode)
  } catch (e: unknown) {
    // responseType が blob のためエラー本文も Blob で届く。JSON の message を読み出して表示する
    const res = (e as { response?: { status?: number; data?: unknown } })?.response
    let msg = ''
    try {
      const d = res?.data
      if (d instanceof Blob) msg = (JSON.parse(await d.text()) as { message?: string })?.message ?? ''
      else msg = (d as { message?: string })?.message ?? ''
    } catch {
      msg = ''
    }
    ui.notify(msg || `PDF の作成に失敗しました${res?.status ? `（${res.status}）` : ''}`)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="overlay ui-overlay ui-sheet">
    <div class="dlg ui-panel ui-flush">
      <div class="head">
        <div style="display: flex; align-items: center; gap: 8px; min-width: 0">
          <b style="font-size: 15px">問題PDFを作る</b>
          <HelpTip text="教材の PDF からページを選んで、自分用の問題 PDF を表示・保存します。&#10;小テストとしては登録されず、出力の記録も残りません。&#10;Focus Gold などは「checkのみ」「難易度（★の数）」で絞り込めます。" />
        </div>
        <button class="x" aria-label="閉じる" @click="emit('close')">×</button>
      </div>

      <div class="body">
        <div v-if="loading" class="hint">読み込み中…</div>
        <div v-else-if="!usable.length" class="hint">PDF が登録されている教材がありません。</div>
        <template v-else>
          <div class="book-row">
            <span class="lbl">教材</span>
            <select v-model="bookId" class="sel">
              <option :value="null">教材を選択</option>
              <option v-for="b in usable" :key="b.id" :value="b.id">{{ b.subjectName ? b.subjectName + '：' : '' }}{{ b.title }}</option>
            </select>
          </div>
          <div v-if="loadingBook" class="hint">PDF を読み込んでいます…</div>
          <PdfPagePicker v-else-if="pdfs.length" v-model="pages" :pdfs="pdfs" :rows="rows" :book-id="bookId" :book-title="book?.title" />
          <div v-else-if="bookId !== null" class="hint">この教材には PDF が登録されていません。</div>
        </template>
      </div>

      <div class="foot">
        <span class="cnt">{{ pages.length }} ページ選択中</span>
        <label class="opt"><input v-model="withCovers" type="checkbox" /> 章の表紙</label>
        <label class="opt"><input v-model="duplex" type="checkbox" /> 両面印刷用</label>
        <HelpTip text="章の表紙: 章ごとに章名を大きく書いた表紙ページを先頭に入れます。&#10;両面印刷用: 章の枚数（表紙込み）が奇数のとき白紙を足し、次の章の表紙が必ず新しい用紙の表面から始まるようにします。" />
        <button class="btn" :disabled="!pages.length || busy" @click="output('download')">保存</button>
        <button class="btn dark" :disabled="!pages.length || busy" @click="output('preview')">PDFを表示</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 70;
  padding: 12px;
}
.dlg {
  background: #fff;
  border-radius: 16px;
  width: min(1100px, 100%);
  max-height: 94vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
  overflow: hidden;
}
.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 14px 18px 10px;
  border-bottom: 1px solid var(--line);
}
.x {
  border: none;
  background: #f2f3f5;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  font-size: 20px;
  color: #6b7280;
  cursor: pointer;
  line-height: 1;
  flex-shrink: 0;
}
.body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 12px 18px;
}
.book-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}
.lbl {
  font-size: 12px;
  font-weight: 700;
  color: var(--mut);
  white-space: nowrap;
}
.sel {
  flex: 1;
  max-width: 480px;
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 13px;
}
.hint {
  font-size: 12.5px;
  color: var(--faint);
  padding: 20px;
  text-align: center;
}
.foot {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  padding: 10px 18px;
  border-top: 1px solid var(--line);
}
.cnt {
  margin-right: auto;
  font-size: 12px;
  color: var(--mut);
}
.opt {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: var(--mut);
  cursor: pointer;
}
.btn {
  padding: 8px 16px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ink);
  cursor: pointer;
}
.btn.dark {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.btn:disabled {
  opacity: 0.5;
  cursor: default;
}
/* スマホ: 下のシートで表示。操作欄は 2 段にし、ボタンを押しやすい大きさにする */
@media (max-width: 600px) {
  .body {
    padding: 12px 14px;
  }
  .foot {
    flex-wrap: wrap;
    row-gap: 10px;
    padding: 10px 14px;
  }
  .cnt {
    width: 100%;
  }
  .opt {
    white-space: nowrap;
  }
  .btn {
    flex: 1;
    min-height: 42px;
    white-space: nowrap;
  }
}
</style>
