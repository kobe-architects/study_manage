<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import HelpTip from '@/components/HelpTip.vue'
import PdfPagePicker, { type SelectedPage } from '@/components/PdfPagePicker.vue'
import { quizApi } from '@/api/quiz'
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

/** 選択ページ（英単語テストは対象外）を API 用に変換 */
function payload(): { pdfId: number; page: number }[] {
  return pages.value.filter((p) => p.pdfId && p.page).map((p) => ({ pdfId: p.pdfId!, page: p.page! }))
}

async function output(mode: 'preview' | 'download') {
  const list = payload()
  if (!list.length) {
    ui.notify('ページを選んでください')
    return
  }
  busy.value = true
  try {
    await quizApi.printPdf(list, title.value, mode)
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    ui.notify(msg || 'PDF の作成に失敗しました')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="overlay">
    <div class="dlg">
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
  background: transparent;
  font-size: 22px;
  color: #9aa1ab;
  cursor: pointer;
  line-height: 1;
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
</style>
