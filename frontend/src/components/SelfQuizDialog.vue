<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import HelpTip from '@/components/HelpTip.vue'
import PdfPagePicker, { type SelectedPage } from '@/components/PdfPagePicker.vue'
import VocabTestDialog from '@/components/VocabTestDialog.vue'
import { quizApi } from '@/api/quiz'
import { iso } from '@/lib/design'
import { buildQuizCover, buildQuizRenders, buildQuizStamps, newGroupKey, splitQuizParts, toQuizPageSpec } from '@/lib/quizBuild'
import { TEST_TYPE_LABEL } from '@/lib/vocabTest'
import { useUiStore } from '@/stores/ui'
import type { BookPdf, QuizBook, QuizPageSpec, QuizRow } from '@/types'

/**
 * 生徒用: 小テストを自分で出題する（自己出題）。
 * 講師の出題と同じ小テストとして登録され、講師側の一覧にも出る。提出後は先生が採点・添削するか、自分で採点・添削する。
 * 教材の PDF からページを選ぶ（講師と同じ PdfPagePicker）か、英単語テストのページを追加する。教材と英単語テストは別パート（別の問題 PDF・別提出）になる。
 */
const emit = defineEmits<{ close: []; created: [] }>()
const ui = useUiStore()

const books = ref<QuizBook[]>([])
const loading = ref(true)
const bookId = ref<number | null>(null)
const pdfs = ref<BookPdf[]>([])
const rows = ref<QuizRow[]>([])
const pages = ref<SelectedPage[]>([])
const loadingBook = ref(false)
const saving = ref(false)
const vocabOpen = ref(false)
const form = reactive<{ title: string; dueOn: string; note: string }>({ title: '', dueOn: '', note: '' })

const usable = computed(() => books.value.filter((b) => b.pdfCount > 0))
const book = computed(() => usable.value.find((b) => b.id === bookId.value) ?? null)
const defaultTitle = computed(() => {
  const d = new Date()
  return `自己出題 ${d.getMonth() + 1}月${d.getDate()}日`
})
const parts = computed(() => splitQuizParts(pages.value))
const pdfPages = computed(() => pages.value.filter((p) => p.kind !== 'vocab'))
const vocabPages = computed(() => pages.value.filter((p) => p.kind === 'vocab'))

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
  // 教材を変えたら、その教材のページ選択はやり直し（英単語テストのページは残す）
  pages.value = vocabPages.value
  pdfs.value = []
  rows.value = []
  if (id === null) return
  loadingBook.value = true
  try {
    const [p, r] = await Promise.all([quizApi.listPdfs(id), quizApi.rows(id)])
    // 「解答」を含むタイトルの PDF は採点用なので出題から除く（問題と解答が 1 冊だけの教材はそのまま使う）
    const problem = p.filter((x) => !x.title.includes('解答'))
    pdfs.value = problem.length ? problem : p
    rows.value = r
  } catch {
    ui.notify('教材の PDF の取得に失敗しました')
  } finally {
    loadingBook.value = false
  }
})

/** PdfPagePicker は教材のページだけを扱う（英単語テストのページは別に持つ） */
const pickerPages = computed<SelectedPage[]>({
  get: () => pdfPages.value,
  set: (v) => {
    pages.value = [...v.map((p) => ({ ...p, bookId: bookId.value, bookTitle: book.value?.title ?? '' })), ...vocabPages.value]
  },
})

function addVocabPages(specs: QuizPageSpec[]) {
  const stamp = Date.now().toString(36)
  pages.value = [...pages.value, ...specs.map((p, i): SelectedPage => ({ ...p, key: `vocab:${stamp}-${i}`, label: p.label ?? '英単語テスト', pdfTitle: '', bookId: null, bookTitle: '' }))]
  vocabOpen.value = false
  ui.notify(`英単語テストを ${specs.length} ページ追加しました`)
}
function removePage(key: string) {
  pages.value = pages.value.filter((p) => p.key !== key)
}
function setDueIn(days: number) {
  const d = new Date()
  d.setDate(d.getDate() + days)
  form.dueOn = iso(d)
}

async function save() {
  if (!pages.value.length) {
    ui.notify('ページを選んでください')
    return
  }
  saving.value = true
  const baseTitle = form.title.trim() || defaultTitle.value
  let created = 0
  try {
    const groupKey = newGroupKey()
    const list = parts.value
    for (const part of list) {
      const printTitle = list.length > 1 ? `${baseTitle}（${part.label}）` : baseTitle
      const payload = {
        title: baseTitle,
        note: form.note.trim() || null,
        dueOn: form.dueOn || null,
        maxScore: 10,
        bookId: part.bookId,
        groupKey,
        pages: part.pages.map(toQuizPageSpec),
      }
      await quizApi.create(payload, await buildQuizRenders(part.pages, printTitle), await buildQuizCover(books.value, printTitle, part.bookId, part.label, part.pages), await buildQuizStamps(part.pages))
      created++
    }
    ui.notify(`小テストを登録しました（${pages.value.length}ページ）。問題PDFを印刷して解き、撮影して提出しましょう`)
    emit('created')
    emit('close')
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    ui.notify((msg || '登録に失敗しました') + (created > 0 ? `（${created}パートまでは登録済みです）` : ''))
    if (created > 0) emit('created')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="overlay ui-overlay ui-sheet">
    <div class="dlg ui-panel ui-flush">
      <div class="head">
        <div style="display: flex; align-items: center; gap: 8px; min-width: 0">
          <b style="font-size: 15px">小テストを自分で出題</b>
          <HelpTip text="教材の PDF からページを選ぶか、英単語テストを追加して、自分用の小テストを登録します。&#10;登録した小テストは先生にも共有され（先生の一覧に「自己出題」として表示）、問題PDFを印刷して解き、撮影して提出すると、先生が採点・添削するか、自分で採点・添削することができます。&#10;教材と英単語テストは別パート（別の問題PDF・別提出）になります。" />
        </div>
        <button class="x" aria-label="閉じる" @click="emit('close')">×</button>
      </div>

      <div class="body">
        <div class="form">
          <label class="fld"><span>タイトル（未入力なら「{{ defaultTitle }}」）</span><input v-model="form.title" :placeholder="defaultTitle" /></label>
          <label class="fld"><span>期限（任意）</span>
            <div style="display: flex; gap: 6px; align-items: center">
              <input v-model="form.dueOn" type="date" style="flex: 1" />
              <button class="mini" type="button" @click="setDueIn(3)">3日後</button>
              <button class="mini" type="button" @click="setDueIn(7)">1週間後</button>
            </div>
          </label>
          <label class="fld"><span>メモ（任意。先生にも表示されます）</span><input v-model="form.note" placeholder="例: 苦手な章の確認" /></label>
        </div>

        <div v-if="loading" class="hint">読み込み中…</div>
        <template v-else>
          <div class="book-row">
            <span class="lbl">教材</span>
            <select v-model="bookId" class="sel">
              <option :value="null">教材を選択（英単語テストだけなら不要）</option>
              <option v-for="b in usable" :key="b.id" :value="b.id">{{ b.subjectName ? b.subjectName + '：' : '' }}{{ b.title }}</option>
            </select>
            <button class="mini vocab" type="button" @click="vocabOpen = true">＋ 英単語テスト</button>
          </div>
          <div v-if="vocabPages.length" class="vlist">
            <div v-for="(p, i) in vocabPages" :key="p.key" class="vrow">
              <span class="num">{{ i + 1 }}</span>
              <div style="flex: 1; min-width: 0">
                <div style="font-size: 12.5px; font-weight: 600">{{ p.label }}</div>
                <div style="font-size: 11px; color: var(--faint)">{{ p.vocab?.words.length }}問・{{ p.vocab ? TEST_TYPE_LABEL[p.vocab.testType] : '' }}</div>
              </div>
              <button class="mini" type="button" @click="removePage(p.key)">削除</button>
            </div>
          </div>
          <div v-if="loadingBook" class="hint">PDF を読み込んでいます…</div>
          <PdfPagePicker v-else-if="pdfs.length" v-model="pickerPages" :pdfs="pdfs" :rows="rows" :book-id="bookId" :book-title="book?.title" />
          <div v-else-if="bookId !== null" class="hint">この教材には PDF が登録されていません。</div>
        </template>
      </div>

      <div class="foot">
        <span class="cnt">{{ pages.length }} ページ選択中<template v-if="parts.length > 1">（{{ parts.length }}パート）</template></span>
        <button class="btn" type="button" @click="emit('close')">キャンセル</button>
        <button class="btn dark" :disabled="!pages.length || saving" @click="save">{{ saving ? '登録中…' : '小テストを登録' }}</button>
      </div>
    </div>

    <Transition name="ui-modal">
      <VocabTestDialog v-if="vocabOpen" @close="vocabOpen = false" @add="addVocabPages" />
    </Transition>
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
.form {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 10px;
  margin-bottom: 12px;
}
.fld span {
  font-size: 11.5px;
  color: var(--mut);
  display: block;
  margin-bottom: 4px;
}
.fld input {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  background: #fff;
  outline: none;
  font-family: inherit;
}
.book-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.lbl {
  font-size: 12px;
  font-weight: 700;
  color: var(--mut);
  white-space: nowrap;
}
.sel {
  flex: 1;
  min-width: 200px;
  max-width: 480px;
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 13px;
}
.mini {
  padding: 6px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.mini.vocab {
  color: #2f7a4f;
  border-color: #bfe3cc;
  background: #f1faf4;
}
.vlist {
  background: #f8f9fb;
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 6px 12px;
  margin-bottom: 12px;
}
.vrow {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 0;
}
.vrow + .vrow {
  border-top: 1px solid #eceef1;
}
.vrow .num {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #2e7d5b;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
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
@media (max-width: 700px) {
  .form {
    grid-template-columns: 1fr;
  }
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
  .btn {
    flex: 1;
    min-height: 42px;
  }
}
</style>
