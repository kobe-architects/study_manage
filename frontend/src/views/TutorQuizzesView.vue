<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import BookPdfManager from '@/components/BookPdfManager.vue'
import PdfPagePicker, { type SelectedPage } from '@/components/PdfPagePicker.vue'
import QuizCard from '@/components/QuizCard.vue'
import QuizStats from '@/components/QuizStats.vue'
import { quizApi } from '@/api/quiz'
import { iso } from '@/lib/design'
import { useUiStore } from '@/stores/ui'
import type { BookPdf, QuizBook, QuizRow, QuizStats as QuizStatsT, QuizSummary } from '@/types'

/**
 * 講師用: 小テストの出題（教材 → ページ選択 → 設定）、一覧、分析。
 */
const router = useRouter()
const ui = useUiStore()

const tab = ref<'list' | 'stats'>('list')
const quizzes = ref<QuizSummary[]>([])
const loading = ref(true)
const stats = ref<QuizStatsT | null>(null)

async function load() {
  try {
    quizzes.value = await quizApi.list()
  } catch {
    ui.notify('小テストの取得に失敗しました')
  } finally {
    loading.value = false
  }
}
async function loadStats() {
  try {
    stats.value = await quizApi.stats()
  } catch {
    ui.notify('分析データの取得に失敗しました')
  }
}
onMounted(load)
watch(tab, (t) => {
  if (t === 'stats') loadStats()
})

const groups = computed(() => [
  { key: 'submitted', label: '添削待ち', list: quizzes.value.filter((q) => q.status === 'submitted') },
  { key: 'assigned', label: '出題中（未提出）', list: quizzes.value.filter((q) => q.status === 'assigned') },
  { key: 'graded', label: '添削済み', list: quizzes.value.filter((q) => q.status === 'graded') },
])

async function download(q: QuizSummary) {
  try {
    await quizApi.downloadQuizPdf(q.id, q.title)
  } catch {
    ui.notify('ダウンロードに失敗しました')
  }
}
async function remove(q: QuizSummary) {
  if (!confirm(`小テスト「${q.title}」を削除しますか？\n提出された回答・添削も削除されます。`)) return
  try {
    await quizApi.remove(q.id)
    ui.notify('削除しました')
    await load()
  } catch {
    ui.notify('削除に失敗しました')
  }
}
function grade(q: QuizSummary) {
  router.push({ name: 'tutor-quiz-grade', params: { id: q.id } })
}

// ---------- 出題ウィザード ----------
const wiz = reactive<{
  open: boolean
  step: 1 | 2 | 3
  id: number | null
  books: QuizBook[]
  bookId: number | null
  bookTitle: string
  pdfs: BookPdf[]
  rows: QuizRow[]
  pages: SelectedPage[]
  title: string
  note: string
  dueOn: string
  maxScore: number
  loading: boolean
  saving: boolean
}>({
  open: false,
  step: 1,
  id: null,
  books: [],
  bookId: null,
  bookTitle: '',
  pdfs: [],
  rows: [],
  pages: [],
  title: '',
  note: '',
  dueOn: '',
  maxScore: 10,
  loading: false,
  saving: false,
})
const pdfMgr = reactive({ open: false, bookId: 0, bookTitle: '' })

async function loadBooks() {
  wiz.books = await quizApi.books()
}

async function openWizard(edit?: QuizSummary) {
  wiz.open = true
  wiz.step = 1
  wiz.id = null
  wiz.bookId = null
  wiz.pages = []
  wiz.title = ''
  wiz.note = ''
  wiz.dueOn = ''
  wiz.maxScore = 10
  wiz.loading = true
  try {
    await loadBooks()
    if (edit) {
      const detail = await quizApi.show(edit.id)
      wiz.id = detail.id
      wiz.title = detail.title
      wiz.note = detail.note ?? ''
      wiz.dueOn = detail.dueOn ?? ''
      wiz.maxScore = detail.maxScorePerPage
      const book = wiz.books.find((b) => b.id === detail.bookId)
      if (book) {
        await chooseBook(book, false)
        wiz.pages = detail.pages
          .filter((p) => p.pdfId !== null)
          .map((p) => ({
            key: `${p.pdfId}:${p.pdfPage}`,
            pdfId: p.pdfId!,
            page: p.pdfPage,
            itemId: p.itemId,
            label: p.label ?? `p.${p.pdfPage}`,
            pdfTitle: p.pdfTitle ?? '',
            refPdfId: p.refPdfId,
            refPage: p.refPage,
          }))
        wiz.step = 2
      }
    }
  } catch {
    ui.notify('出題データの取得に失敗しました')
  } finally {
    wiz.loading = false
  }
}

async function chooseBook(b: QuizBook, advance = true) {
  if (!b.pdfCount) {
    ui.notify('この教材には PDF が紐づいていません。「PDF」ボタンから紐づけてください')
    return
  }
  wiz.loading = true
  try {
    const [pdfs, rows] = await Promise.all([quizApi.listPdfs(b.id), quizApi.rows(b.id)])
    wiz.bookId = b.id
    wiz.bookTitle = b.title
    wiz.pdfs = pdfs
    wiz.rows = rows
    if (wiz.bookId !== b.id) wiz.pages = []
    if (advance) wiz.step = 2
  } catch {
    ui.notify('教材データの取得に失敗しました')
  } finally {
    wiz.loading = false
  }
}

function openPdfManager(b: QuizBook) {
  pdfMgr.bookId = b.id
  pdfMgr.bookTitle = b.title
  pdfMgr.open = true
}
async function onPdfChanged() {
  await loadBooks()
  if (wiz.bookId === pdfMgr.bookId) {
    wiz.pdfs = await quizApi.listPdfs(wiz.bookId)
    wiz.rows = await quizApi.rows(wiz.bookId)
  }
}

function toStep3() {
  if (!wiz.pages.length) {
    ui.notify('出題するページを1つ以上選択してください')
    return
  }
  if (!wiz.dueOn && wiz.id === null) wiz.dueOn = ''
  wiz.step = 3
}

const defaultTitle = computed(() => {
  const d = new Date()
  return `${wiz.bookTitle} 小テスト ${d.getMonth() + 1}月${d.getDate()}日`
})

async function save() {
  if (!wiz.bookId || !wiz.pages.length) return
  wiz.saving = true
  const payload = {
    title: wiz.title.trim() || null,
    note: wiz.note.trim() || null,
    dueOn: wiz.dueOn || null,
    maxScore: Math.max(1, Number(wiz.maxScore) || 10),
    bookId: wiz.bookId,
    pages: wiz.pages.map((p) => ({ pdfId: p.pdfId, page: p.page, itemId: p.itemId ?? null, label: p.label, refPdfId: p.refPdfId ?? null, refPage: p.refPage ?? null })),
  }
  try {
    if (wiz.id === null) {
      await quizApi.create(payload)
      ui.notify(`小テストを出題しました（${wiz.pages.length}ページ）`)
    } else {
      await quizApi.update(wiz.id, payload)
      ui.notify('小テストを更新しました')
    }
    wiz.open = false
    await load()
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    ui.notify(msg || '保存に失敗しました')
  } finally {
    wiz.saving = false
  }
}

function setDueIn(days: number) {
  const d = new Date()
  d.setDate(d.getDate() + days)
  wiz.dueOn = iso(d)
}
</script>

<template>
  <div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; gap: 10px; flex-wrap: wrap">
      <div style="display: flex; align-items: center; gap: 14px">
        <div style="font-size: 17px; font-weight: 700">小テスト</div>
        <div class="seg">
          <button :class="{ on: tab === 'list' }" @click="tab = 'list'">一覧</button>
          <button :class="{ on: tab === 'stats' }" @click="tab = 'stats'">分析</button>
        </div>
      </div>
      <button class="btn-dark" @click="openWizard()">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>小テストを出題
      </button>
    </div>

    <template v-if="tab === 'list'">
      <div v-if="loading" class="hint">読み込み中…</div>
      <div v-else-if="!quizzes.length" class="hint" style="text-align: center">
        小テストはまだありません。「小テストを出題」から、PDF を紐づけた個別学習データのページを選んで出題してください。
      </div>
      <template v-else>
        <template v-for="g in groups" :key="g.key">
          <div v-if="g.list.length" class="group">
            <div class="group-title">{{ g.label }}<span class="cnt">{{ g.list.length }}</span></div>
            <div class="cards">
              <QuizCard v-for="q in g.list" :key="q.id" :quiz="q" role="tutor" @download="download(q)" @grade="grade(q)" @edit="openWizard(q)" @remove="remove(q)" />
            </div>
          </div>
        </template>
      </template>
      <div class="hint">
        出題した小テストは生徒のトップページに表示され、生徒は問題 PDF を印刷して回答し、スマホで撮影して提出します。
        提出されると「添削待ち」に表示され、「添削する」から画面上で添削・採点できます。
      </div>
    </template>

    <template v-else>
      <QuizStats v-if="stats" :stats="stats" />
      <div v-else class="hint">読み込み中…</div>
    </template>

    <!-- 出題ウィザード -->
    <div v-if="wiz.open" class="overlay">
      <div class="wizard">
        <div class="wiz-head">
          <div style="display: flex; align-items: center; gap: 14px; min-width: 0">
            <div style="font-size: 15px; font-weight: 700; white-space: nowrap">{{ wiz.id === null ? '小テストを出題' : '小テストを編集' }}</div>
            <div class="steps">
              <span :class="{ on: wiz.step === 1, done: wiz.step > 1 }">1 教材</span>
              <span :class="{ on: wiz.step === 2, done: wiz.step > 2 }">2 ページ選択</span>
              <span :class="{ on: wiz.step === 3 }">3 設定・出題</span>
            </div>
          </div>
          <button class="x" @click="wiz.open = false">×</button>
        </div>

        <div class="wiz-body">
          <div v-if="wiz.loading" class="hint">読み込み中…</div>

          <!-- Step 1 -->
          <template v-else-if="wiz.step === 1">
            <div class="hint" style="margin: 0 0 12px">PDF を紐づけた教材から出題できます。PDF が未紐づけの教材は「PDF」ボタンから紐づけてください（例題 No. とページが一致する PDF は、一覧から例題を選ぶだけで出題できます）。</div>
            <div class="books">
              <div v-for="b in wiz.books" :key="b.id" class="book" :class="{ ok: b.pdfCount > 0, cur: b.id === wiz.bookId }" @click="chooseBook(b)">
                <div class="book-top">
                  <span class="type">{{ b.type }}</span>
                  <span :style="{ width: '8px', height: '8px', borderRadius: '50%', background: b.colorVivid }"></span>
                  <span style="font-size: 11px; color: var(--faint)">{{ b.subjectName ?? '科目未設定' }}</span>
                </div>
                <div class="book-title">{{ b.title }}</div>
                <div class="book-foot">
                  <span :class="b.pdfCount ? 'pdf-ok' : 'pdf-ng'">{{ b.pdfCount ? `PDF ${b.pdfCount}件・出題可能` : 'PDF 未紐づけ' }}</span>
                  <span style="color: var(--faint)">{{ b.rowCount }}行</span>
                  <button class="mini" @click.stop="openPdfManager(b)">PDF</button>
                </div>
              </div>
            </div>
          </template>

          <!-- Step 2 -->
          <template v-else-if="wiz.step === 2">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap">
              <span style="font-size: 13px; font-weight: 700">{{ wiz.bookTitle }}</span>
              <button class="mini" @click="wiz.step = 1">教材を変更</button>
              <button class="mini" @click="openPdfManager({ id: wiz.bookId!, title: wiz.bookTitle } as QuizBook)">PDF を管理</button>
            </div>
            <PdfPagePicker v-if="wiz.pdfs.length" v-model="wiz.pages" :pdfs="wiz.pdfs" :rows="wiz.rows" />
            <div v-else class="hint">この教材に PDF が紐づいていません。</div>
          </template>

          <!-- Step 3 -->
          <template v-else>
            <div class="form">
              <label class="fld"><span>タイトル（未入力の場合は「{{ defaultTitle }}」）</span><input v-model="wiz.title" :placeholder="defaultTitle" /></label>
              <div class="two">
                <label class="fld"><span>期限（任意）</span>
                  <div style="display: flex; gap: 6px; align-items: center">
                    <input v-model="wiz.dueOn" type="date" style="flex: 1" />
                    <button class="mini" @click="setDueIn(3)">3日後</button>
                    <button class="mini" @click="setDueIn(7)">1週間後</button>
                  </div>
                </label>
                <label class="fld"><span>満点（1問あたり）</span><input v-model.number="wiz.maxScore" type="number" min="1" max="1000" /></label>
              </div>
              <label class="fld"><span>生徒へのメモ（任意）</span><textarea v-model="wiz.note" rows="2" placeholder="例: 途中式も書くこと"></textarea></label>
              <div>
                <div class="fld-label">出題ページ（{{ wiz.pages.length }}ページ・合計 {{ wiz.pages.length * (Number(wiz.maxScore) || 10) }}点）</div>
                <ol class="page-list">
                  <li v-for="p in wiz.pages" :key="p.key">
                    <b>{{ p.label }}</b><span>{{ p.pdfTitle }} p.{{ p.page }}<template v-if="p.refPage">・解答 p.{{ p.refPage }}</template></span>
                  </li>
                </ol>
              </div>
            </div>
          </template>
        </div>

        <div class="wiz-foot">
          <button v-if="wiz.step > 1" class="btn-ghost" @click="wiz.step = (wiz.step - 1) as 1 | 2">戻る</button>
          <span style="flex: 1"></span>
          <span v-if="wiz.step === 2" style="font-size: 12px; color: var(--mut)">{{ wiz.pages.length }} ページ選択中</span>
          <button v-if="wiz.step === 2" class="btn-dark" :disabled="!wiz.pages.length" @click="toStep3">次へ</button>
          <button v-if="wiz.step === 3" class="btn-dark" :disabled="wiz.saving" @click="save">{{ wiz.saving ? '保存中…' : wiz.id === null ? '出題する' : '保存する' }}</button>
        </div>
      </div>
    </div>

    <BookPdfManager v-if="pdfMgr.open" :book-id="pdfMgr.bookId" :book-title="pdfMgr.bookTitle" @close="pdfMgr.open = false" @changed="onPdfChanged" />
  </div>
</template>

<style scoped>
.seg {
  display: inline-flex;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  overflow: hidden;
  background: #fff;
}
.seg button {
  padding: 7px 14px;
  border: none;
  background: #fff;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.seg button.on {
  background: #1c2024;
  color: #fff;
}
.btn-dark {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 16px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-dark:disabled {
  opacity: 0.4;
  cursor: default;
}
.btn-ghost {
  padding: 9px 18px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: var(--mut);
}
.mini {
  padding: 5px 9px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 11px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.hint {
  background: #f8f9fb;
  border: 1px dashed #d8dce1;
  border-radius: 14px;
  padding: 15px 18px;
  margin-top: 14px;
  font-size: 12.5px;
  color: var(--faint);
  line-height: 1.7;
}
.group {
  margin-bottom: 18px;
}
.group-title {
  font-size: 13px;
  font-weight: 700;
  margin: 0 2px 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.cnt {
  font-size: 10.5px;
  background: #f1f2f4;
  color: var(--mut);
  padding: 1px 7px;
  border-radius: 999px;
}
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 12px;
}
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 50;
  padding: 14px;
}
.wizard {
  background: #f6f7f9;
  border-radius: 16px;
  width: 100%;
  max-width: 1120px;
  height: 94vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
  overflow: hidden;
}
.wiz-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 14px 18px;
  background: #fff;
  border-bottom: 1px solid var(--line);
}
.steps {
  display: flex;
  gap: 4px;
}
.steps span {
  font-size: 11.5px;
  padding: 4px 10px;
  border-radius: 999px;
  background: #f1f2f4;
  color: var(--faint);
  white-space: nowrap;
}
.steps span.on {
  background: #1c2024;
  color: #fff;
}
.steps span.done {
  background: #e6f5ec;
  color: #2f7a4f;
}
.x {
  border: none;
  background: transparent;
  font-size: 24px;
  color: #9aa1ab;
  cursor: pointer;
  line-height: 1;
}
.wiz-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 14px 18px;
}
.wiz-foot {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 18px;
  background: #fff;
  border-top: 1px solid var(--line);
}
.books {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  gap: 10px;
}
.book {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 12px 14px;
  cursor: pointer;
  opacity: 0.6;
}
.book.ok {
  opacity: 1;
}
.book.ok:hover {
  border-color: #3b50cc;
}
.book.cur {
  border-color: #3b50cc;
  background: #eef1fc;
}
.book-top {
  display: flex;
  align-items: center;
  gap: 6px;
}
.type {
  font-size: 10px;
  font-weight: 700;
  color: #2e4a8f;
  background: #e8eefb;
  padding: 1px 7px;
  border-radius: 999px;
}
.book-title {
  font-size: 13px;
  font-weight: 700;
  margin: 6px 0;
  line-height: 1.4;
}
.book-foot {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
}
.book-foot .mini {
  margin-left: auto;
}
.pdf-ok {
  color: #2f7a4f;
  font-weight: 600;
}
.pdf-ng {
  color: var(--faint);
}
.form {
  display: flex;
  flex-direction: column;
  gap: 13px;
  max-width: 640px;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 18px;
}
.two {
  display: grid;
  grid-template-columns: 1fr 160px;
  gap: 12px;
}
.fld span,
.fld-label {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  display: block;
  margin-bottom: 5px;
}
.fld input,
.fld textarea {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
  background: #fff;
  font-family: inherit;
  resize: vertical;
}
.page-list {
  margin: 0;
  padding-left: 22px;
  font-size: 12.5px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.page-list span {
  color: var(--faint);
  font-size: 11px;
  margin-left: 8px;
}
</style>
