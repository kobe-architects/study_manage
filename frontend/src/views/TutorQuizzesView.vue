<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import HelpTip from '@/components/HelpTip.vue'
import PdfPagePicker, { type SelectedPage } from '@/components/PdfPagePicker.vue'
import VocabTestDialog from '@/components/VocabTestDialog.vue'
import { renderVocabSheet, TEST_FORMAT_LABEL, TEST_TYPE_LABEL } from '@/lib/vocabTest'
import { groupQuizzes, groupStatus, quizApi } from '@/api/quiz'
import { iso } from '@/lib/design'
import { useUiStore } from '@/stores/ui'
import type { BookPdf, QuizBook, QuizPageSpec, QuizRow, QuizSummary } from '@/types'

/**
 * 講師用: 小テストの出題・一覧（リスト表示）・分析（実施済み結果の累積表示）。
 * 出題は「1 設定 → 2 教材選択 → 3 ページ選択」の流れで、1回の出題（箱）に
 * 複数の教材・英単語テストを組み合わせられる。教材ごとに別パート（別の問題 PDF・
 * 別提出・別採点）として出題され、一覧では同じ箱としてまとめて表示される。
 */
const router = useRouter()
const ui = useUiStore()

const tab = ref<'list' | 'stats'>('list')
const quizzes = ref<QuizSummary[]>([])
const loading = ref(true)

async function load() {
  try {
    quizzes.value = await quizApi.list()
  } catch {
    ui.notify('小テストの取得に失敗しました')
  } finally {
    loading.value = false
  }
}
onMounted(load)

const boxes = computed(() => groupQuizzes(quizzes.value))
const groups = computed(() => [
  { key: 'submitted', label: '採点・添削待ち', list: boxes.value.filter((b) => groupStatus(b, 'tutor') === 'submitted') },
  { key: 'assigned', label: '出題中（未提出）', list: boxes.value.filter((b) => groupStatus(b, 'tutor') === 'assigned') },
  { key: 'graded', label: '採点・添削済み', list: boxes.value.filter((b) => groupStatus(b, 'tutor') === 'graded') },
])

function partChip(q: QuizSummary): { label: string; cls: string } {
  if (q.status === 'graded') return { label: '採点・添削済み', cls: 'graded' }
  if (q.status === 'submitted') return { label: '採点・添削待ち', cls: 'submitted' }
  if (q.overdue) return { label: '期限切れ', cls: 'overdue' }
  return { label: '未提出', cls: 'assigned' }
}
function partLabel(q: QuizSummary): string {
  return q.bookTitle ?? '英単語テスト'
}
function fmt(d: string | null): string {
  if (!d) return ''
  const [y, m, dd] = d.split(/[- :]/)
  return `${y}/${Number(m)}/${Number(dd)}`
}

// ---------- 分析: 採点・添削済みの結果を累積表示 ----------
const gradedList = computed(() =>
  quizzes.value
    .filter((q) => q.status === 'graded' && q.score !== null)
    .sort((a, b) => (b.gradedAt ?? '').localeCompare(a.gradedAt ?? '') || b.id - a.id),
)
const gradedSum = computed(() => gradedList.value.reduce((s, q) => s + (q.score ?? 0), 0))
const gradedMax = computed(() => gradedList.value.reduce((s, q) => s + q.maxScore, 0))
const gradedAvg = computed(() => (gradedMax.value > 0 ? Math.round((gradedSum.value / gradedMax.value) * 100) : null))

async function openPdf(q: QuizSummary) {
  try {
    // 講師には英単語テストの解答用紙を末尾に付けてプレビューする
    await quizApi.previewQuizPdf(q.id, true)
  } catch {
    ui.notify('PDF の表示に失敗しました')
  }
}
async function remove(q: QuizSummary) {
  const part = q.bookTitle ?? '英単語テスト'
  if (!confirm(`小テスト「${q.title}」（${part}）を削除しますか？\n提出された回答・採点・添削も削除されます。`)) return
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
const vocabOpen = ref(false)

// ---------- Step2: 教材の絞り込み（科目別・種別別ラジオ） ----------
const bookFilter = reactive({ subject: '', type: '' })
const bookSubjects = computed(() => Array.from(new Set(wiz.books.map((b) => b.subjectName ?? '').filter(Boolean))))
const bookTypes = computed(() => Array.from(new Set(wiz.books.map((b) => b.type))))
/** PDF 紐づけ済みの教材のみ表示（紐づけは教材データ画面で行う） */
const filteredBooks = computed(() =>
  wiz.books.filter(
    (b) =>
      b.pdfCount > 0 &&
      (!bookFilter.subject || (b.subjectName ?? '') === bookFilter.subject) &&
      (!bookFilter.type || b.type === bookFilter.type),
  ),
)

// ---------- パート（教材ごとの提出単位） ----------
interface Part {
  key: string
  bookId: number | null
  label: string
  pages: SelectedPage[]
}
const parts = computed<Part[]>(() => {
  const list: Part[] = []
  for (const p of wiz.pages) {
    const key = p.kind === 'vocab' ? 'vocab' : `b${p.bookId ?? 0}`
    let part = list.find((x) => x.key === key)
    if (!part) {
      part = { key, bookId: p.kind === 'vocab' ? null : (p.bookId ?? null), label: p.kind === 'vocab' ? '英単語テスト' : p.bookTitle || '教材', pages: [] }
      list.push(part)
    }
    part.pages.push(p)
  }
  return list
})

async function loadBooks() {
  wiz.books = await quizApi.books()
}

async function openWizard(edit?: QuizSummary) {
  wiz.open = true
  wiz.step = 1
  wiz.id = null
  wiz.bookId = null
  wiz.bookTitle = ''
  wiz.pdfs = []
  wiz.rows = []
  wiz.pages = []
  wiz.title = ''
  wiz.note = ''
  wiz.dueOn = ''
  wiz.maxScore = 10
  wiz.loading = true
  bookFilter.subject = ''
  bookFilter.type = ''
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
      if (book) await chooseBook(book, false)
      wiz.pages = detail.pages
        .filter((p) => (p.kind === 'vocab' ? !!p.vocabSpec && !!p.vocabWords : p.pdfId !== null))
        .map(
          (p): SelectedPage =>
            p.kind === 'vocab'
              ? {
                  key: `vocab:${p.id}`,
                  kind: 'vocab',
                  label: p.label ?? '英単語テスト',
                  pdfTitle: '',
                  bookId: null,
                  bookTitle: '',
                  vocab: {
                    resourceId: p.vocabSpec!.resourceId,
                    resourceName: p.vocabSpec!.resourceName,
                    sectionNames: p.vocabSpec!.sectionNames,
                    testType: p.vocabSpec!.testType,
                    testFormat: p.vocabSpec!.testFormat,
                    words: p.vocabWords!,
                  },
                }
              : {
                  key: `${p.pdfId}:${p.pdfPage}`,
                  kind: 'pdf',
                  pdfId: p.pdfId!,
                  page: p.pdfPage!,
                  itemId: p.itemId,
                  label: p.label ?? `p.${p.pdfPage}`,
                  pdfTitle: p.pdfTitle ?? '',
                  bookId: detail.bookId,
                  bookTitle: detail.bookTitle ?? '',
                  refPdfId: p.refPdfId,
                  refPage: p.refPage,
                },
        )
    }
  } catch {
    ui.notify('出題データの取得に失敗しました')
  } finally {
    wiz.loading = false
  }
}

async function chooseBook(b: QuizBook, advance = true) {
  wiz.loading = true
  try {
    const [pdfs, rows] = await Promise.all([quizApi.listPdfs(b.id), quizApi.rows(b.id)])
    wiz.bookId = b.id
    wiz.bookTitle = b.title
    wiz.pdfs = pdfs
    wiz.rows = rows
    if (advance) wiz.step = 3
  } catch {
    ui.notify('教材データの取得に失敗しました')
  } finally {
    wiz.loading = false
  }
}

function addVocabPages(pages: QuizPageSpec[]) {
  const stamp = Date.now().toString(36)
  wiz.pages = [
    ...wiz.pages,
    ...pages.map((p, i): SelectedPage => ({ ...p, key: `vocab:${stamp}-${i}`, label: p.label ?? '英単語テスト', pdfTitle: '', bookId: null, bookTitle: '' })),
  ]
  vocabOpen.value = false
  ui.notify(`英単語テストを ${pages.length} ページ追加しました`)
}

function removePage(key: string) {
  wiz.pages = wiz.pages.filter((p) => p.key !== key)
}

const defaultTitle = computed(() => {
  const d = new Date()
  return `小テスト ${d.getMonth() + 1}月${d.getDate()}日`
})

async function save() {
  if (!wiz.pages.length) return
  wiz.saving = true
  const baseTitle = wiz.title.trim() || defaultTitle.value
  let created = 0
  try {
    if (wiz.id !== null) {
      // 編集: 1つの小テスト（パート）として保存
      const payload = {
        title: wiz.title.trim() || null,
        note: wiz.note.trim() || null,
        dueOn: wiz.dueOn || null,
        maxScore: Math.max(1, Number(wiz.maxScore) || 10),
        pages: wiz.pages.map(toPageSpec),
      }
      await quizApi.update(wiz.id, payload, await buildRenders(wiz.pages, baseTitle))
      ui.notify('小テストを更新しました')
    } else {
      // 新規: 教材ごとに別パート（別の小テスト行）として出題し、同じ groupKey でまとめる
      const groupKey = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`
      const list = parts.value
      for (const part of list) {
        const printTitle = list.length > 1 ? `${baseTitle}（${part.label}）` : baseTitle
        const payload = {
          title: baseTitle,
          note: wiz.note.trim() || null,
          dueOn: wiz.dueOn || null,
          maxScore: Math.max(1, Number(wiz.maxScore) || 10),
          bookId: part.bookId,
          groupKey,
          pages: part.pages.map(toPageSpec),
        }
        await quizApi.create(payload, await buildRenders(part.pages, printTitle))
        created++
      }
      ui.notify(
        list.length > 1
          ? `小テストを出題しました（${list.length}パート・計 ${wiz.pages.length}ページ）`
          : `小テストを出題しました（${wiz.pages.length}ページ）`,
      )
    }
    wiz.open = false
    await load()
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    const partial = wiz.id === null && created > 0 ? `（${created}パートまでは出題済みです）` : ''
    ui.notify((msg || '保存に失敗しました') + partial)
    if (created > 0) await load()
  } finally {
    wiz.saving = false
  }
}

function toPageSpec(p: SelectedPage): QuizPageSpec {
  return p.kind === 'vocab'
    ? { kind: 'vocab', label: p.label, vocab: p.vocab }
    : { kind: 'pdf', pdfId: p.pdfId, page: p.page, itemId: p.itemId ?? null, label: p.label, refPdfId: p.refPdfId ?? null, refPage: p.refPage ?? null }
}

/** 英単語テストの問題用紙／解答用紙を画像として描画（サーバーで PDF に組み込む） */
async function buildRenders(pages: SelectedPage[], printTitle: string): Promise<Record<number, { question: Blob; answer: Blob }>> {
  const renders: Record<number, { question: Blob; answer: Blob }> = {}
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
      pageLabel: `${printTitle}　${i + 1} / ${pages.length}`,
    }
    renders[i] = { question: await renderVocabSheet(spec, false), answer: await renderVocabSheet(spec, true) }
  }
  return renders
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
        <div style="display: flex; align-items: center; gap: 8px">
          <div style="font-size: 17px; font-weight: 700">小テスト</div>
          <HelpTip
            text="出題した小テストは生徒のトップページに表示され、生徒は問題PDFを印刷して回答し、スマホで撮影して提出します。&#10;提出されると「採点・添削待ち」に表示され、「採点・添削する」から画面上で採点・添削できます。&#10;1回の出題に複数の教材・英単語テストを組み合わせた場合、問題PDF・提出・採点は教材ごとに行われます。"
          />
        </div>
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
        小テストはまだありません。「小テストを出題」から、PDF を紐づけた教材や英単語テストを選んで出題してください。
      </div>
      <template v-else>
        <template v-for="g in groups" :key="g.key">
          <div v-if="g.list.length" class="group">
            <div class="group-title">{{ g.label }}<span class="cnt">{{ g.list.length }}</span></div>
            <div class="qlist">
              <div v-for="b in g.list" :key="b[0]!.id" class="qbox">
                <div class="qbox-head">
                  <b class="qb-title">{{ b[0]!.title }}</b>
                  <span class="qb-meta">
                    <template v-if="b.length > 1">{{ b.length }}教材・</template>{{ b.reduce((s, q) => s + q.pageCount, 0) }}ページ
                    <template v-if="b[0]!.dueOn">・期限 {{ fmt(b[0]!.dueOn) }}</template>
                    <template v-if="b[0]!.note">・{{ b[0]!.note }}</template>
                  </span>
                </div>
                <div v-for="q in b" :key="q.id" class="qrow">
                  <span class="qr-name">{{ partLabel(q) }}</span>
                  <span class="qr-pages">{{ q.pageCount }}ページ</span>
                  <span class="qr-chip" :class="partChip(q).cls">{{ partChip(q).label }}</span>
                  <span class="qr-score">
                    <template v-if="q.status === 'graded' && q.score !== null"><b>{{ q.score }}</b> / {{ q.maxScore }}点（{{ q.rate }}%）</template>
                    <template v-else-if="q.status === 'assigned' && q.answeredCount">{{ q.answeredCount }}/{{ q.pageCount }} 撮影済み</template>
                  </span>
                  <span class="qr-actions">
                    <button v-if="q.status === 'submitted'" class="btn primary" @click="grade(q)">採点・添削する</button>
                    <button v-else-if="q.status === 'graded'" class="btn primary" @click="grade(q)">採点・添削結果</button>
                    <button v-else class="btn" @click="openWizard(q)">編集</button>
                    <button class="btn" title="問題 PDF を別タブでプレビュー" @click="openPdf(q)">問題PDF</button>
                    <button class="btn danger" @click="remove(q)">削除</button>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </template>
      </template>
    </template>

    <!-- 分析: 実施済み小テストの結果を累積表示 -->
    <template v-else>
      <div v-if="loading" class="hint">読み込み中…</div>
      <div v-else-if="!gradedList.length" class="hint">
        採点・添削済みの小テストがまだありません。採点・添削が完了すると、ここに結果が積み上がっていきます。
      </div>
      <template v-else>
        <div class="st-sum">
          実施 <b>{{ gradedList.length }}</b> 回・合計 <b>{{ gradedSum }}</b> / {{ gradedMax }}点・平均得点率 <b>{{ gradedAvg }}%</b>
        </div>
        <div class="st-table-wrap">
          <table class="st-table">
            <thead>
              <tr><th>採点・添削日</th><th>タイトル</th><th>教材</th><th class="r">ページ</th><th class="r">得点</th><th class="r">得点率</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="q in gradedList" :key="q.id">
                <td class="nowrap">{{ q.gradedAt ? fmt(q.gradedAt) : '–' }}</td>
                <td class="ttl-cell">{{ q.title }}</td>
                <td>{{ partLabel(q) }}</td>
                <td class="r">{{ q.pageCount }}</td>
                <td class="r nowrap"><b>{{ q.score }}</b> / {{ q.maxScore }}</td>
                <td class="r rate-cell">
                  <span class="rate-bar"><span :style="{ width: (q.rate ?? 0) + '%' }"></span></span>
                  <span class="nowrap">{{ q.rate }}%</span>
                </td>
                <td class="r"><button class="btn" style="padding: 5px 10px" @click="grade(q)">結果</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>

    <!-- 出題ウィザード -->
    <div v-if="wiz.open" class="overlay">
      <div class="wizard">
        <div class="wiz-head">
          <div style="display: flex; align-items: center; gap: 14px; min-width: 0">
            <div style="font-size: 15px; font-weight: 700; white-space: nowrap">{{ wiz.id === null ? '小テストを出題' : '小テストを編集' }}</div>
            <div class="steps">
              <span :class="{ on: wiz.step === 1, done: wiz.step > 1 }">1 設定</span>
              <span :class="{ on: wiz.step === 2, done: wiz.step > 2 }">2 教材選択</span>
              <span :class="{ on: wiz.step === 3 }">3 ページ選択</span>
            </div>
          </div>
          <button class="x" @click="wiz.open = false">×</button>
        </div>

        <div class="wiz-body">
          <div v-if="wiz.loading" class="hint">読み込み中…</div>

          <!-- Step 1: 設定 -->
          <template v-else-if="wiz.step === 1">
            <div class="form">
              <div style="display: flex; align-items: center; gap: 6px">
                <span style="font-size: 13px; font-weight: 700">小テストの設定</span>
                <HelpTip
                  text="先にタイトルや期限などを設定し、次のステップで出題する教材・ページを選びます。&#10;複数の教材や英単語テストを組み合わせた場合も1つの小テストとしてまとまり、問題PDFのダウンロードや回答の提出は教材ごとに行えます。"
                />
              </div>
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
            </div>
          </template>

          <!-- Step 2: 教材選択 -->
          <template v-else-if="wiz.step === 2">
            <div class="filter-row">
              <span style="font-size: 12.5px; font-weight: 700">出題する教材を選択</span>
              <HelpTip text="PDF を紐づけ済みの教材から出題できます（PDF の紐づけは教材データ画面で行います）。教材は複数選べ、教材ごとに別の問題PDF・提出になります。「英単語テスト」も追加できます。" />
            </div>
            <div class="frow">
              <span class="flab">科目</span>
              <label class="radio"><input v-model="bookFilter.subject" type="radio" value="" />すべて</label>
              <label v-for="s in bookSubjects" :key="s" class="radio"><input v-model="bookFilter.subject" type="radio" :value="s" />{{ s }}</label>
            </div>
            <div class="frow" style="margin-bottom: 12px">
              <span class="flab">種別</span>
              <label class="radio"><input v-model="bookFilter.type" type="radio" value="" />すべて</label>
              <label v-for="t in bookTypes" :key="t" class="radio"><input v-model="bookFilter.type" type="radio" :value="t" />{{ t }}</label>
            </div>
            <div class="books">
              <div class="book ok vocab-only" @click="vocabOpen = true">
                <div class="book-top"><span class="type" style="background: #e6f5ec; color: #2f7a4f">英単語</span></div>
                <div class="book-title">英単語テストを追加</div>
                <div class="book-foot"><span class="pdf-ok">LEAP basic などの単語帳から出題</span></div>
              </div>
              <div v-for="b in filteredBooks" :key="b.id" class="book ok" :class="{ cur: b.id === wiz.bookId }" @click="chooseBook(b)">
                <div class="book-top">
                  <span class="type">{{ b.type }}</span>
                  <span :style="{ width: '8px', height: '8px', borderRadius: '50%', background: b.colorVivid }"></span>
                  <span style="font-size: 11px; color: var(--faint)">{{ b.subjectName ?? '科目未設定' }}</span>
                </div>
                <div class="book-title">{{ b.title }}</div>
                <div class="book-foot">
                  <span class="pdf-ok">PDF {{ b.pdfCount }}件・出題可能</span>
                  <span style="color: var(--faint)">{{ b.rowCount }}行</span>
                </div>
              </div>
            </div>
            <div v-if="!filteredBooks.length" class="hint" style="margin-top: 10px">絞り込み条件に一致する教材がありません（PDF 紐づけ済みの教材のみ表示されます）。</div>

            <!-- 選択中の出題内容（パートごと） -->
            <div v-if="wiz.pages.length" style="margin-top: 16px; max-width: 680px">
              <div class="fld-label">選択中の出題内容（{{ wiz.pages.length }}ページ<template v-if="parts.length > 1">・{{ parts.length }}パート</template>）</div>
              <div v-for="pt in parts" :key="pt.key" class="part-box">
                <div class="part-head">{{ pt.label }}<span>{{ pt.pages.length }}ページ</span></div>
                <div v-for="(p, i) in pt.pages" :key="p.key" class="vrow">
                  <span class="num" :class="{ vocab: p.kind === 'vocab' }">{{ i + 1 }}</span>
                  <div style="flex: 1; min-width: 0">
                    <div style="font-size: 12.5px; font-weight: 600">{{ p.label }}</div>
                    <div style="font-size: 11px; color: var(--faint)">
                      <template v-if="p.kind === 'vocab'">{{ p.vocab?.words.length }}問・{{ p.vocab ? TEST_TYPE_LABEL[p.vocab.testType] : '' }}</template>
                      <template v-else>{{ p.pdfTitle }} p.{{ p.page }}</template>
                    </div>
                  </div>
                  <button class="mini" @click="removePage(p.key)">削除</button>
                </div>
              </div>
            </div>
          </template>

          <!-- Step 3: ページ選択 -->
          <template v-else>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap">
              <span style="font-size: 13px; font-weight: 700">{{ wiz.bookTitle }}</span>
              <button class="mini" @click="wiz.step = 2">別の教材を追加・変更</button>
            </div>
            <PdfPagePicker v-if="wiz.pdfs.length" v-model="wiz.pages" :pdfs="wiz.pdfs" :rows="wiz.rows" :book-id="wiz.bookId" :book-title="wiz.bookTitle" />
            <div v-else class="hint">教材が選択されていません。「別の教材を追加・変更」から教材を選んでください。</div>
          </template>
        </div>

        <div class="wiz-foot">
          <button v-if="wiz.step > 1" class="btn-ghost" @click="wiz.step = (wiz.step - 1) as 1 | 2">戻る</button>
          <span style="flex: 1"></span>
          <span v-if="wiz.step > 1" style="font-size: 12px; color: var(--mut)">
            {{ wiz.pages.length }} ページ選択中<template v-if="parts.length > 1">（{{ parts.length }}パート）</template>
          </span>
          <button v-if="wiz.step === 1" class="btn-dark" @click="wiz.step = 2">次へ</button>
          <button v-else class="btn-dark" :disabled="!wiz.pages.length || wiz.saving" @click="save">
            {{ wiz.saving ? '保存中…' : wiz.id === null ? '出題する' : '保存する' }}
          </button>
        </div>
      </div>
    </div>

    <VocabTestDialog v-if="vocabOpen" @close="vocabOpen = false" @add="addVocabPages" />
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
/* ---------- 一覧（リスト表示） ---------- */
.qlist {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.qbox {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  overflow: hidden;
}
.qbox-head {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
  padding: 10px 14px;
  background: #f8f9fb;
  border-bottom: 1px solid var(--line);
}
.qb-title {
  font-size: 13px;
}
.qb-meta {
  font-size: 11px;
  color: var(--faint);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.qrow {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 14px;
  flex-wrap: wrap;
}
.qrow + .qrow {
  border-top: 1px solid #f1f2f4;
}
.qr-name {
  font-size: 12.5px;
  font-weight: 600;
  flex: 1;
  min-width: 140px;
}
.qr-pages {
  font-size: 11.5px;
  color: var(--faint);
  width: 64px;
  flex-shrink: 0;
}
.qr-chip {
  font-size: 10.5px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 999px;
  flex-shrink: 0;
  white-space: nowrap;
}
.qr-chip.assigned {
  background: #f1f2f4;
  color: var(--mut);
}
.qr-chip.overdue {
  background: #fdf0f1;
  color: #c0444f;
}
.qr-chip.submitted {
  background: #e8eefb;
  color: #2e4a8f;
}
.qr-chip.graded {
  background: #e6f5ec;
  color: #2f7a4f;
}
.qr-score {
  font-size: 11.5px;
  color: var(--mut);
  width: 150px;
  flex-shrink: 0;
  white-space: nowrap;
}
.qr-score b {
  font-size: 14px;
  color: var(--ink);
}
.qr-actions {
  display: flex;
  gap: 6px;
  margin-left: auto;
  flex-wrap: wrap;
}
.btn {
  padding: 6px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.btn.primary {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.btn.danger {
  color: #c0444f;
  border-color: #f0b8be;
}
/* ---------- 分析（累積結果） ---------- */
.st-sum {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 12px 16px;
  font-size: 12.5px;
  color: var(--mut);
  margin-bottom: 12px;
}
.st-sum b {
  font-size: 16px;
  color: var(--ink);
}
.st-table-wrap {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  overflow-x: auto;
}
.st-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}
.st-table th {
  text-align: left;
  font-size: 11px;
  color: var(--faint);
  font-weight: 600;
  padding: 9px 12px;
  border-bottom: 1px solid var(--line);
  background: #f8f9fb;
  white-space: nowrap;
}
.st-table td {
  padding: 9px 12px;
  border-bottom: 1px solid #f1f2f4;
  vertical-align: middle;
}
.st-table tr:last-child td {
  border-bottom: none;
}
.st-table .r {
  text-align: right;
}
.st-table .nowrap {
  white-space: nowrap;
}
.st-table .ttl-cell {
  max-width: 280px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.rate-cell {
  min-width: 140px;
}
.rate-bar {
  display: inline-block;
  vertical-align: middle;
  width: 80px;
  height: 7px;
  border-radius: 99px;
  background: #e8ebf5;
  overflow: hidden;
  margin-right: 8px;
}
.rate-bar span {
  display: block;
  height: 100%;
  background: #3b50cc;
  border-radius: 99px;
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
  max-width: 1280px;
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
.filter-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 10px;
  flex-wrap: wrap;
}
.frow {
  display: flex;
  align-items: center;
  gap: 4px 12px;
  flex-wrap: wrap;
  margin-bottom: 6px;
}
.flab {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--mut);
  width: 34px;
}
.radio {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.radio input {
  accent-color: #1c2024;
  cursor: pointer;
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
.pdf-ok {
  color: #2f7a4f;
  font-weight: 600;
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
.book.vocab-only {
  border-style: dashed;
}
.part-box {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 10px 12px;
  margin-bottom: 8px;
}
.part-head {
  font-size: 12.5px;
  font-weight: 700;
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 6px;
}
.part-head span {
  font-size: 11px;
  font-weight: 400;
  color: var(--faint);
}
.vrow {
  display: flex;
  align-items: center;
  gap: 10px;
  border-top: 1px solid #f1f2f4;
  padding: 7px 0;
}
.vrow .num {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #1c2024;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.vrow .num.vocab {
  background: #2e7d5b;
}
</style>
