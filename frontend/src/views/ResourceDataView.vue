<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import AuthImage from '@/components/AuthImage.vue'
import { computeReviewOn, iso, REVIEW_OPTIONS } from '@/lib/design'
import { openListPrint, type PrintCell, type PrintColumn } from '@/lib/printList'
import { useResourceStore } from '@/stores/resource'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import { STUDY_TYPES, type RecordColor, type RelatedProblemRow, type ResourceBook, type ResourceBookRow, type StudyDate, type StudyType } from '@/types'

const resource = useResourceStore()
const study = useStudyStore()
const ui = useUiStore()

const fileInput = ref<HTMLInputElement | null>(null)
const imgInput = ref<HTMLInputElement | null>(null)
const imgTargetId = ref<number | null>(null)

onMounted(async () => {
  try {
    await resource.fetchBooks()
  } catch {
    /* 認証前など */
  }
})

const TAB_LABEL: Record<StudyType, string> = { 講義: '講義一覧', 問題集: '問題集一覧', 教科書: '教科書一覧' }

async function switchTab(t: StudyType) {
  await resource.setType(t)
}

// ---- 教材（book） ----
const subjectOptions = computed(() => {
  const seen = new Map<number, string>()
  study.items.forEach((i) => seen.set(i.subjectId, i.subjectName))
  return [...seen.entries()].map(([id, name]) => ({ id, name }))
})

function pct(a: number, b: number) {
  return b ? Math.round((a / b) * 100) : 0
}

// 教材 追加/編集モーダル
const bookModal = reactive<{ open: boolean; id: number | null; title: string; subjectId: number | null }>({
  open: false,
  id: null,
  title: '',
  subjectId: null,
})
function openAddBook() {
  bookModal.open = true
  bookModal.id = null
  bookModal.title = ''
  bookModal.subjectId = subjectOptions.value[0]?.id ?? null
}
function openEditBook(b: ResourceBook) {
  bookModal.open = true
  bookModal.id = b.id
  bookModal.title = b.title
  bookModal.subjectId = b.subjectId
}
async function saveBook() {
  if (!bookModal.title.trim()) {
    ui.notify('タイトルを入力してください')
    return
  }
  if (bookModal.id) {
    await resource.updateBook(bookModal.id, { title: bookModal.title.trim(), subjectId: bookModal.subjectId })
    ui.notify('保存しました')
  } else {
    await resource.createBook(resource.activeType, bookModal.title.trim(), bookModal.subjectId)
    ui.notify('一覧を追加しました')
  }
  bookModal.open = false
}
async function delBook(b: ResourceBook) {
  if (!confirm(`「${b.title}」を削除しますか？（含まれる行・学習記録も削除されます）`)) return
  await resource.deleteBook(b.id)
  ui.notify('削除しました')
}

// ---- ピン止め ----
async function togglePin(b: ResourceBook) {
  await resource.togglePin(b.id, !b.pinned)
  ui.notify(b.pinned ? 'ピン止めを解除しました' : 'ピン止めしました')
}

// ---- カードの並び替え（ネイティブ D&D） ----
const dragId = ref<number | null>(null)
const dragOverId = ref<number | null>(null)

function onDragStart(b: ResourceBook, e: DragEvent) {
  dragId.value = b.id
  if (e.dataTransfer) {
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.setData('text/plain', String(b.id)) // Firefox でドラッグ開始に必要
  }
}
function onDragOver(b: ResourceBook) {
  if (dragId.value !== null && dragId.value !== b.id) dragOverId.value = b.id
}
async function onDrop(target: ResourceBook) {
  const src = dragId.value
  dragId.value = null
  dragOverId.value = null
  if (src === null || src === target.id) return
  const ids = resource.booksOfType.map((b) => b.id)
  const from = ids.indexOf(src)
  const to = ids.indexOf(target.id)
  if (from < 0 || to < 0) return
  ids.splice(to, 0, ids.splice(from, 1)[0])
  await resource.reorderBooks(ids)
}
function onDragEnd() {
  dragId.value = null
  dragOverId.value = null
}

// 画像
function pickImage(id: number) {
  imgTargetId.value = id
  imgInput.value?.click()
}
async function onImageSelected(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file && imgTargetId.value) {
    await resource.uploadImage(imgTargetId.value, file)
    ui.notify('画像を設定しました')
  }
  if (imgInput.value) imgInput.value.value = ''
}
async function removeImage(b: ResourceBook) {
  await resource.deleteImage(b.id)
  ui.notify('画像を削除しました')
}

// ---- Excel ----
function downloadTemplate() {
  resource.download(`/resource-books/template?type=${encodeURIComponent(resource.activeType)}`, `${resource.activeType}_テンプレート.xlsx`)
}
function exportBook() {
  if (!resource.activeBookId) {
    ui.notify('教材を選択してください')
    return
  }
  resource.download(`/resource-books/${resource.activeBookId}/export`, `${resource.activeBook?.title ?? 'export'}.xlsx`)
}
function triggerImport() {
  if (!resource.activeBookId) {
    ui.notify('取り込み先の教材を選択してください')
    return
  }
  fileInput.value?.click()
}
async function onImportSelected(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file && resource.activeBookId) {
    try {
      const r = await resource.importFile(resource.activeBookId, file)
      ui.notify(`${r.imported}件を取り込みました（スキップ${r.skipped}件）`)
    } catch {
      ui.notify('インポートに失敗しました')
    }
  }
  if (fileInput.value) fileInput.value.value = ''
}

// ---- 行テーブル ----
const q = ref('')
const fImportant = ref(false) // 重要のみで絞り込み
const fCheck = ref(false) // Check有りのみ
const fThink = ref(false) // Think有りのみ
const page = ref(1)
const perPage = 20

const filteredRows = computed(() => {
  const term = q.value.trim()
  return resource.rows.filter((r) => {
    if (fImportant.value && !r.important) return false
    // Check/Think 列が無い教材では該当トグルは無視（全件が消えないように）
    if (fCheck.value && visibleCols.value.check && !r.checkFlag) return false
    if (fThink.value && hasThink.value && !r.meta?.Think) return false
    if (term && !`${r.title ?? ''}${r.chapter ?? ''}${r.sub ?? ''}${r.mid ?? ''}`.includes(term)) return false
    return true
  })
})
const totalPages = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / perPage)))
const curPage = computed(() => Math.min(page.value, totalPages.value))
const pageRows = computed(() => {
  const start = (curPage.value - 1) * perPage
  return filteredRows.value.slice(start, start + perPage)
})
const rangeText = computed(() => {
  const n = filteredRows.value.length
  if (!n) return '0件'
  const start = (curPage.value - 1) * perPage
  return `${start + 1}–${Math.min(start + perPage, n)} / 全${n}件`
})

// 選択中の教材が実際に使っている列のみ表示（教材ごとに列構成が異なるため）
const visibleCols = computed(() => ({
  chapter: resource.rows.some((r) => r.chapter),
  seqNo: resource.rows.some((r) => r.seqNo),
  check: resource.rows.some((r) => r.checkFlag),
  difficulty: resource.rows.some((r) => r.difficulty),
}))
// インポートで取り込んだ教材固有の追加列（meta）を出現順に列挙。
// 「Think」は Check と同じ○印列としてタイトルの左に別途表示するため、ここからは除外する。
const metaKeys = computed(() => {
  const keys: string[] = []
  for (const r of resource.rows) {
    if (r.meta) for (const k of Object.keys(r.meta)) if (k !== 'Think' && !keys.includes(k)) keys.push(k)
  }
  return keys
})
// Think 列（meta.Think）を持つ行があるか
const hasThink = computed(() => resource.rows.some((r) => r.meta?.Think))

// ---- 章グルーピング（章がある教材は章を親項目として折りたたみ表示） ----
const grouped = computed(() => visibleCols.value.chapter)

interface ChapterGroup {
  key: string
  label: string
  rows: ResourceBookRow[]
  done: number // 1回以上学習済みの行数
}
const chapterGroups = computed<ChapterGroup[]>(() => {
  const map = new Map<string, ChapterGroup>()
  for (const r of filteredRows.value) {
    const label = r.chapter?.trim() || '（章未設定）'
    let g = map.get(label)
    if (!g) {
      g = { key: label, label, rows: [], done: 0 }
      map.set(label, g)
    }
    g.rows.push(r)
    if (r.recordCount > 0) g.done++
  }
  return [...map.values()]
})

// 学習済みの割合（小数1桁）: 例 5/41 → 12.2
function pct1(a: number, b: number) {
  return b ? ((a / b) * 100).toFixed(1) : '0.0'
}

// デフォルトは全章折りたたみ（キー未設定＝falsy）
const chapterExpand = reactive<Record<string, boolean>>({})
function toggleChapter(k: string) {
  chapterExpand[k] = !chapterExpand[k]
}
function setAllChapters(open: boolean) {
  for (const g of chapterGroups.value) chapterExpand[g.key] = open
}

// テーブルに描画する行（章見出し行 + 展開中の章の行）
type ListItem = { type: 'chapter'; group: ChapterGroup } | { type: 'row'; row: ResourceBookRow }
const listItems = computed<ListItem[]>(() => {
  if (!grouped.value) return pageRows.value.map((row) => ({ type: 'row', row }))
  const out: ListItem[] = []
  for (const g of chapterGroups.value) {
    out.push({ type: 'chapter', group: g })
    if (chapterExpand[g.key]) for (const row of g.rows) out.push({ type: 'row', row })
  }
  return out
})

// 章見出し行の colspan 用（章列は見出しに集約するため通常列からは除外）
// 基本7列: 対象 / 重要 / タイトル / 小分類 / 学習回数 / 学習日 / 操作
const colCount = computed(
  () =>
    7 +
    (visibleCols.value.seqNo ? 1 : 0) +
    (visibleCols.value.check ? 1 : 0) +
    (hasThink.value ? 1 : 0) +
    (visibleCols.value.difficulty ? 1 : 0) +
    metaKeys.value.length,
)

function fmtMd(s: string | null) {
  if (!s) return '—'
  const d = new Date(s + 'T00:00:00')
  return `${d.getMonth() + 1}/${d.getDate()}`
}

// 学習日の色分け（red/blue/green）。未指定はグレー。
const DATE_COLORS: Record<RecordColor, string> = { red: '#d92d20', blue: '#2563eb', green: '#2e9d62' }
const COLOR_LABEL: Record<RecordColor, string> = { red: '赤', blue: '青', green: '緑' }
const RECORD_COLOR_KEYS: RecordColor[] = ['red', 'blue', 'green']
function dateColorHex(c: RecordColor | null): string {
  return c ? DATE_COLORS[c] : '#6b7280'
}
// 印刷用: 全学習日を色付きHTMLで「7/1, 7/2」のように横並び出力（値は固定の色/日付のみで安全）
function datesHtml(dates: StudyDate[]) {
  if (!dates.length) return '—'
  return dates
    .map((d) => `<span style="color:${dateColorHex(d.color)};font-weight:${d.color ? 600 : 400}">${fmtMd(d.date)}</span>`)
    .join(', ')
}

function todayLabel() {
  const d = new Date()
  return `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`
}

// ---- 重要フラグ ----
async function toggleImportant(r: ResourceBookRow) {
  await resource.toggleImportant(r.id, !r.important)
  ui.notify(r.important ? '重要を解除しました' : '重要に登録しました')
}

// ---- 教材ごとの一覧を画面出力（PDF印刷可） ----
function printBookList() {
  const b = resource.activeBook
  if (!b) return
  const rows = filteredRows.value
  if (!rows.length) {
    ui.notify('印刷対象の行がありません')
    return
  }
  const cols: PrintColumn[] = [{ label: 'No', align: 'right', width: '34px' }]
  cols.push({ label: '章', width: '118px' })
  if (visibleCols.value.check) cols.push({ label: 'Check', align: 'center', width: '46px' })
  if (hasThink.value) cols.push({ label: 'Think', align: 'center', width: '46px' })
  cols.push({ label: 'タイトル', width: '150px' })
  if (visibleCols.value.difficulty) cols.push({ label: '難易度', align: 'center', width: '50px' })
  cols.push(
    { label: '小分類' }, // 幅指定なし＝残り幅を占有（最も広い）
    { label: '重要', align: 'center', width: '36px' },
    { label: '学習回数', align: 'center', width: '48px' },
    { label: '学習日', width: '104px' },
  )

  const data = rows.map((r, i) => {
    const cells: PrintCell[] = [i + 1, r.chapter ?? '']
    if (visibleCols.value.check) cells.push(r.checkFlag ?? '')
    if (hasThink.value) cells.push(r.meta?.Think ?? '')
    cells.push(r.title ?? '')
    if (visibleCols.value.difficulty) cells.push(r.difficulty ?? '')
    cells.push(r.sub ?? '未紐づけ', r.important ? '★' : '', r.recordCount, { html: datesHtml(r.dates) })
    return cells
  })

  const ok = openListPrint({
    title: `${b.title}　学習データ一覧`,
    subtitle: `${b.subjectName ?? '科目未設定'}・全${rows.length}行${fImportant.value ? '（重要のみ）' : ''}・出力日 ${todayLabel()}`,
    columns: cols,
    rows: data,
  })
  if (!ok) ui.notify('ポップアップがブロックされました。ブラウザ設定で許可してください。')
}

// ---- 講義に関連する問題を画面出力（PDF印刷可） ----
async function printRelatedProblems() {
  const b = resource.activeBook
  if (!b) return
  let rows: RelatedProblemRow[]
  try {
    rows = await resource.fetchRelatedProblems(b.id)
  } catch {
    ui.notify('関連問題の取得に失敗しました')
    return
  }
  const hasCheck = rows.some((r) => r.checkFlag)
  const hasThinkR = rows.some((r) => r.think)
  const cols: PrintColumn[] = [
    { label: 'No', align: 'right', width: '34px' },
    { label: '出典（問題集）', width: '150px' },
    { label: '章', width: '118px' },
  ]
  if (hasCheck) cols.push({ label: 'Check', align: 'center', width: '46px' })
  if (hasThinkR) cols.push({ label: 'Think', align: 'center', width: '46px' })
  cols.push(
    { label: 'タイトル', width: '160px' },
    { label: '難易度', align: 'center', width: '50px' },
    { label: '小分類' }, // 幅指定なし＝残り幅を占有（最も広い）
    { label: '重要', align: 'center', width: '36px' },
    { label: '学習日', width: '104px' },
  )
  const data = rows.map((r, i) => {
    const cells: PrintCell[] = [i + 1, r.bookTitle ?? '', r.chapter ?? '']
    if (hasCheck) cells.push(r.checkFlag ?? '')
    if (hasThinkR) cells.push(r.think ?? '')
    cells.push(r.title ?? '', r.difficulty ?? '', r.sub ?? '', r.important ? '★' : '', { html: datesHtml(r.dates) })
    return cells
  })
  const ok = openListPrint({
    title: `${b.title}　関連問題一覧`,
    subtitle: `講義「${b.title}」と同じ小分類に紐づく問題集の問題・全${rows.length}問・出力日 ${todayLabel()}`,
    columns: cols,
    rows: data,
    emptyText: '関連する問題集の問題が見つかりませんでした（講義の各行が小分類に紐づいているか、同じ小分類の問題集があるかご確認ください）',
  })
  if (!ok) ui.notify('ポップアップがブロックされました。ブラウザ設定で許可してください。')
}

async function delRow(r: ResourceBookRow) {
  if (!confirm('この行を削除しますか？')) return
  await resource.deleteRow(r.id)
  ui.notify('行を削除しました')
}

// ---- 学習記録の管理（一覧・追加・削除。色・復習期限を設定） ----
const recModal = reactive<{
  open: boolean
  row: ResourceBookRow | null
  records: { id: number; studiedOn: string; color: RecordColor | null; reviewOn: string | null }[]
  loading: boolean
  date: string
  color: RecordColor | null
  reviewIdx: number
  customDays: number | null
}>({
  open: false,
  row: null,
  records: [],
  loading: false,
  date: iso(new Date()),
  color: 'red',
  reviewIdx: 1,
  customDays: 7,
})
async function openRecords(r: ResourceBookRow) {
  if (!r.studyItemId) {
    ui.notify('この行は学習項目に紐づいていません')
    return
  }
  recModal.open = true
  recModal.row = r
  recModal.loading = true
  recModal.date = iso(new Date())
  recModal.color = 'red'
  recModal.reviewIdx = 1
  recModal.customDays = 7
  recModal.records = await resource.fetchRowRecords(r.id)
  recModal.loading = false
}
async function addRecordInModal() {
  if (!recModal.row) return
  const opt = REVIEW_OPTIONS[recModal.reviewIdx]
  const reviewOn = computeReviewOn(recModal.date, opt, recModal.customDays)
  await resource.recordRow(recModal.row.id, recModal.date, recModal.color, reviewOn)
  recModal.records = await resource.fetchRowRecords(recModal.row.id)
  refreshProgress()
  study.fetchReviews().catch(() => {})
  ui.notify(reviewOn ? `学習記録を追加しました（復習期限 ${fmtMd(reviewOn)}）` : '学習記録を追加しました')
}
async function deleteRecordInModal(id: number) {
  await resource.deleteRecord(id)
  recModal.records = recModal.records.filter((x) => x.id !== id)
  refreshProgress()
  study.fetchReviews().catch(() => {})
  ui.notify('学習記録を削除しました')
}
const reviewPreview = computed(() => {
  const opt = REVIEW_OPTIONS[recModal.reviewIdx]
  const on = computeReviewOn(recModal.date, opt, recModal.customDays)
  return on ? `→ この記録の復習期限: ${fmtMd(on)}` : '復習項目一覧には表示されません'
})

// ---- 進捗対象の設定（学習項目への紐づけ 登録/解除） ----
const rowView = ref<'list' | 'target'>('list')
const expand = reactive<Record<string, boolean>>({})

interface TargetGroup {
  key: string
  itemId: number | null
  label: string
  color: string
  rows: ResourceBookRow[]
  included: number
  total: number
}

const targetGroups = computed<TargetGroup[]>(() => {
  const map = new Map<string, TargetGroup>()
  for (const r of resource.rows) {
    const key = r.studyItemId ? `i${r.studyItemId}` : 'none'
    if (!map.has(key)) {
      map.set(key, {
        key,
        itemId: r.studyItemId,
        label: r.studyItemId ? `${r.subjectName} › ${r.major} › ${r.mid} › ${r.sub}` : '未紐づけ（学習項目に紐づいていません）',
        color: r.colorVivid,
        rows: [],
        included: 0,
        total: 0,
      })
    }
    const g = map.get(key)!
    g.rows.push(r)
    g.total++
    if (r.included) g.included++
  }
  // 紐づけ済みを上に、未紐づけを最後に
  return [...map.values()].sort((a, b) => (a.itemId ? 0 : 1) - (b.itemId ? 0 : 1))
})

const targetSummary = computed(() => {
  const linked = resource.rows.filter((r) => r.studyItemId)
  return { included: linked.filter((r) => r.included).length, total: linked.length }
})

function groupState(g: TargetGroup): 'on' | 'mid' | 'off' {
  if (!g.itemId) return 'off'
  if (g.included >= g.total) return 'on'
  if (g.included === 0) return 'off'
  return 'mid'
}
const checkStyle = {
  on: { bg: '#1c2024', bd: '#1c2024', co: '#fff', mark: '✓' },
  mid: { bg: '#fff', bd: '#1c2024', co: '#1c2024', mark: '−' },
  off: { bg: '#fff', bd: '#cbd1d8', co: 'transparent', mark: '' },
}

function refreshProgress() {
  Promise.all([study.fetchItems(), study.fetchGoals()]).catch(() => {})
}
async function toggleGroup(g: TargetGroup) {
  if (!resource.activeBookId || !g.itemId) return
  const target = g.included < g.total // 一部でも解除されていれば全登録、全登録なら全解除
  await resource.setRowsIncluded(resource.activeBookId, g.rows.map((r) => r.id), target)
  refreshProgress()
}
async function toggleRow(r: ResourceBookRow) {
  if (!resource.activeBookId || !r.studyItemId) return
  await resource.setRowsIncluded(resource.activeBookId, [r.id], !r.included)
  refreshProgress()
}
async function setAllTarget(on: boolean) {
  if (!resource.activeBookId) return
  const ids = resource.rows.filter((r) => r.studyItemId).map((r) => r.id)
  if (!ids.length) return
  await resource.setRowsIncluded(resource.activeBookId, ids, on)
  refreshProgress()
}
function toggleExpand(k: string) {
  expand[k] = !expand[k]
}

// 行 追加モーダル
const rowModal = reactive<{ open: boolean; chapter: string; seqNo: string; title: string; difficulty: string; sub: string }>({
  open: false,
  chapter: '',
  seqNo: '',
  title: '',
  difficulty: '',
  sub: '',
})
function openAddRow() {
  if (!resource.activeBookId) {
    ui.notify('先に教材を選択してください')
    return
  }
  Object.assign(rowModal, { open: true, chapter: '', seqNo: '', title: '', difficulty: '', sub: '' })
}
async function saveRow() {
  if (!resource.activeBookId) return
  const b = resource.activeBook
  await resource.createRow(resource.activeBookId, {
    chapter: rowModal.chapter || null,
    seqNo: rowModal.seqNo || null,
    title: rowModal.title || null,
    difficulty: rowModal.difficulty || null,
    subject: b?.subjectName ?? '',
    sub: rowModal.sub || '',
  })
  ui.notify('行を追加しました')
  rowModal.open = false
}
</script>

<template>
  <div class="resource-view">
    <!-- タブ -->
    <div class="seg" style="margin-bottom: 16px">
      <button v-for="t in STUDY_TYPES" :key="t" class="seg-btn" :class="{ on: resource.activeType === t }" @click="switchTab(t)">
        {{ TAB_LABEL[t] }}
      </button>
    </div>

    <!-- ツールバー -->
    <div style="display: flex; gap: 9px; flex-wrap: wrap; margin-bottom: 16px">
      <button class="btn-dark" @click="openAddBook">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>新規一覧
      </button>
      <button class="btn-out" @click="downloadTemplate">ExcelフォーマットDL</button>
      <button class="btn-out" @click="exportBook">エクスポート</button>
      <button class="btn-out" @click="triggerImport">インポート</button>
      <input ref="fileInput" type="file" accept=".xlsx,.xls,.csv,.txt" style="display: none" @change="onImportSelected" />
      <input ref="imgInput" type="file" accept="image/*" style="display: none" @change="onImageSelected" />
    </div>

    <div class="split">
      <!-- 左: 教材カード（縦スクロール） -->
      <aside class="book-col">
      <div v-if="resource.booksOfType.length" class="book-list">
      <div
        v-for="b in resource.booksOfType"
        :key="b.id"
        class="book-card"
        :class="{ active: b.id === resource.activeBookId, pinned: b.pinned, dragging: dragId === b.id, 'drag-over': dragOverId === b.id }"
        draggable="true"
        @click="resource.selectBook(b.id)"
        @dragstart="onDragStart(b, $event)"
        @dragover.prevent="onDragOver(b)"
        @drop.prevent="onDrop(b)"
        @dragend="onDragEnd"
      >
        <span class="drag-handle" title="ドラッグで並び替え" @click.stop>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.6" /><circle cx="15" cy="6" r="1.6" /><circle cx="9" cy="12" r="1.6" /><circle cx="15" cy="12" r="1.6" /><circle cx="9" cy="18" r="1.6" /><circle cx="15" cy="18" r="1.6" /></svg>
        </span>
        <button class="pin-btn" :class="{ on: b.pinned }" :title="b.pinned ? 'ピン止め中（クリックで解除）' : 'ピン止め'" @click.stop="togglePin(b)">
          <svg width="15" height="15" viewBox="0 0 24 24" :fill="b.pinned ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v5M9 3h6l-1 6 3 3H7l3-3-1-6z" /></svg>
        </button>
        <div class="book-thumb" :style="{ background: b.imageUrl ? '#fff' : '#f1f2f4' }">
          <AuthImage v-if="b.imageUrl" :src="b.imageUrl" alt="" />
          <span v-else style="font-size: 11px; color: #aeb4bd">No Image</span>
        </div>
        <div style="flex: 1; min-width: 0">
          <div class="book-title">{{ b.title }}</div>
          <div style="display: flex; align-items: center; gap: 6px; margin: 4px 0 6px">
            <span v-if="b.subjectName" :style="{ width: '8px', height: '8px', borderRadius: '50%', background: b.colorVivid }"></span>
            <span style="font-size: 11.5px; color: var(--faint)">{{ b.subjectName ?? '科目未設定' }}</span>
          </div>
          <div class="prog-row">
            <div class="prog-bar"><span :style="{ width: pct(b.doneRows, b.totalRows) + '%', background: b.colorVivid }"></span></div>
            <span style="font-size: 11px; color: var(--mut); white-space: nowrap">{{ b.doneRows }}/{{ b.totalRows }}（{{ pct(b.doneRows, b.totalRows) }}%）</span>
          </div>
          <div style="font-size: 10.5px; color: var(--faint); margin-top: 3px">進捗対象 {{ b.targetRows }}/{{ b.totalRows }}行</div>
          <div class="book-actions">
            <button class="mini" @click.stop="pickImage(b.id)">画像</button>
            <button v-if="b.imageUrl" class="mini" @click.stop="removeImage(b)">画像削除</button>
            <button class="mini" @click.stop="openEditBook(b)">編集</button>
            <button class="mini danger" @click.stop="delBook(b)">削除</button>
          </div>
        </div>
      </div>
    </div>
      <div v-else class="card" style="padding: 30px; text-align: center; color: var(--faint); font-size: 13px">
        この種別の一覧データはまだありません。「新規一覧」から追加してください。
      </div>
      </aside>

      <!-- 右: 学習データ -->
      <section class="data-col">
    <!-- 選択中の教材（タイトルで絞り込み） -->
    <div v-if="resource.activeBook" style="display: flex; align-items: center; gap: 10px; margin: 2px 2px 10px">
      <span v-if="resource.activeBook.subjectName" :style="{ width: '10px', height: '10px', borderRadius: '50%', background: resource.activeBook.colorVivid }"></span>
      <span style="font-size: 16px; font-weight: 700">{{ resource.activeBook.title }}</span>
      <span style="font-size: 12px; color: var(--faint)">{{ resource.activeBook.subjectName ?? '科目未設定' }}・{{ resource.rows.length }}行</span>
    </div>

    <!-- 行テーブル / 進捗対象の設定 -->
    <div v-if="resource.activeBookId" class="card" style="overflow: hidden">
      <div class="tbl-toolbar">
        <div class="seg seg-sm">
          <button class="seg-btn" :class="{ on: rowView === 'list' }" @click="rowView = 'list'">一覧</button>
          <button class="seg-btn" :class="{ on: rowView === 'target' }" @click="rowView = 'target'">進捗対象の設定</button>
        </div>
        <template v-if="rowView === 'list'">
          <div style="position: relative; flex: 1; min-width: 160px">
            <input v-model="q" placeholder="タイトル・章・小分類で検索…" class="search" @input="page = 1" />
          </div>
          <button class="mini-btn imp-filter" :class="{ on: fImportant }" title="重要フラグの行のみ表示" @click="fImportant = !fImportant; page = 1">★ 重要のみ</button>
          <button v-if="visibleCols.check" class="mini-btn flt-check" :class="{ on: fCheck }" title="Checkの付いた行のみ表示" @click="fCheck = !fCheck; page = 1">Checkのみ</button>
          <button v-if="hasThink" class="mini-btn flt-think" :class="{ on: fThink }" title="Thinkの付いた行のみ表示" @click="fThink = !fThink; page = 1">Thinkのみ</button>
          <template v-if="grouped">
            <button class="mini-btn" style="color: #3b50cc" @click="setAllChapters(true)">全て展開</button>
            <button class="mini-btn" style="color: #9aa1ab" @click="setAllChapters(false)">全て折りたたむ</button>
          </template>
          <button class="btn-out" @click="printBookList">一覧を印刷</button>
          <button v-if="resource.activeType === '講義'" class="btn-out" @click="printRelatedProblems">関連問題を出力</button>
          <button class="btn-out" @click="openAddRow">＋ 行を追加</button>
        </template>
        <template v-else>
          <span style="flex: 1; font-size: 12px; color: var(--mut)">進捗対象 {{ targetSummary.included }}/{{ targetSummary.total }}行（チェックを外すと進捗集計から除外）</span>
          <button class="mini-btn" style="color: #3b50cc" @click="setAllTarget(true)">全登録</button>
          <button class="mini-btn" style="color: #9aa1ab" @click="setAllTarget(false)">全解除</button>
        </template>
      </div>

      <!-- 進捗対象の設定（小分類ごとに登録/解除） -->
      <template v-if="rowView === 'target'">
        <div style="max-height: 580px; overflow-y: auto; padding: 4px 0">
          <div v-for="g in targetGroups" :key="g.key">
            <div class="tree-row">
              <button class="chev" :style="{ opacity: g.itemId ? 1 : 0 }" @click="g.itemId && toggleExpand(g.key)">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" :style="{ transform: expand[g.key] ? 'rotate(90deg)' : 'none' }"><path d="M9 6l6 6-6 6" /></svg>
              </button>
              <button
                class="chk"
                :disabled="!g.itemId"
                :style="{ borderColor: checkStyle[groupState(g)].bd, background: checkStyle[groupState(g)].bg, color: checkStyle[groupState(g)].co, cursor: g.itemId ? 'pointer' : 'default' }"
                @click="toggleGroup(g)"
              >{{ checkStyle[groupState(g)].mark }}</button>
              <span style="flex: 1; min-width: 0; font-size: 12.5px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis" :style="{ color: g.itemId ? '#1c2024' : '#aeb4bd' }">
                <span v-if="g.itemId" :style="{ display: 'inline-block', width: '7px', height: '7px', borderRadius: '50%', background: g.color, marginRight: '6px' }"></span>{{ g.label }}
              </span>
              <span style="font-size: 11px; color: var(--faint); flex-shrink: 0">{{ g.included }}/{{ g.total }}行</span>
            </div>
            <template v-if="expand[g.key] && g.itemId">
              <div v-for="r in g.rows" :key="r.id" class="tree-row" style="padding-left: 54px">
                <button
                  class="chk"
                  :style="{ borderColor: r.included ? '#1c2024' : '#cbd1d8', background: r.included ? '#1c2024' : '#fff', color: r.included ? '#fff' : 'transparent' }"
                  @click="toggleRow(r)"
                >{{ r.included ? '✓' : '' }}</button>
                <span style="flex: 1; min-width: 0; font-size: 12px; color: #4b5563; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ r.seqNo ? r.seqNo + '. ' : '' }}{{ r.title ?? '（無題）' }}</span>
                <span style="font-size: 10.5px; color: #b85188; flex-shrink: 0">{{ r.difficulty ?? '' }}</span>
              </div>
            </template>
          </div>
          <div v-if="!targetGroups.length" style="padding: 36px; text-align: center; color: var(--faint); font-size: 13px">行データがありません</div>
        </div>
      </template>

      <!-- 一覧テーブル -->
      <div v-show="rowView === 'list'" style="overflow-x: auto">
        <table class="tbl">
          <thead>
            <tr>
              <th style="width: 46px; text-align: center">対象</th>
              <th style="width: 40px; text-align: center">重要</th>
              <th v-if="visibleCols.seqNo" style="width: 52px">番号</th>
              <th v-if="visibleCols.check" style="width: 46px">Check</th>
              <th v-if="hasThink" style="width: 46px; text-align: center">Think</th>
              <th>タイトル</th>
              <th v-if="visibleCols.difficulty" style="width: 70px">難易度</th>
              <th v-for="k in metaKeys" :key="k">{{ k }}</th>
              <th>小分類（科目›大›中）</th>
              <th style="width: 72px; text-align: center">学習回数</th>
              <th style="width: 150px">学習日</th>
              <th style="width: 130px; text-align: right">操作</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="item in listItems" :key="item.type === 'chapter' ? 'c:' + item.group.key : 'r:' + item.row.id">
            <tr v-if="item.type === 'chapter'" class="chapter-row" @click="toggleChapter(item.group.key)">
              <td :colspan="colCount">
                <span class="chapter-cell">
                  <svg class="chapter-chev" :class="{ open: chapterExpand[item.group.key] }" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M9 6l6 6-6 6" /></svg>
                  <span class="chapter-name">{{ item.group.label }}</span>
                  <span class="chapter-count">{{ item.group.done }}/{{ item.group.rows.length }}（{{ pct1(item.group.done, item.group.rows.length) }}%）</span>
                </span>
              </td>
            </tr>
            <tr v-else>
              <td style="text-align: center">
                <input type="checkbox" class="tgt-chk" :checked="item.row.included" :disabled="!item.row.studyItemId" :title="item.row.studyItemId ? '進捗対象（チェックを外すと集計から除外）' : '学習項目に未紐づけ'" @change="toggleRow(item.row)" />
              </td>
              <td style="text-align: center">
                <button class="imp-star" :class="{ on: item.row.important }" :title="item.row.important ? '重要（クリックで解除）' : '重要にする'" @click="toggleImportant(item.row)">★</button>
              </td>
              <td v-if="visibleCols.seqNo" style="color: #aeb4bd">{{ item.row.seqNo ?? '' }}</td>
              <td v-if="visibleCols.check" style="text-align: center; color: #3a8a5c; font-weight: 700">{{ item.row.checkFlag ?? '' }}</td>
              <td v-if="hasThink" style="text-align: center; color: #6b5bd0; font-weight: 700">{{ item.row.meta?.Think ?? '' }}</td>
              <td style="font-weight: 500">{{ item.row.title ?? '' }}</td>
              <td v-if="visibleCols.difficulty" style="color: #b85188; letter-spacing: 1px">{{ item.row.difficulty ?? '' }}</td>
              <td v-for="k in metaKeys" :key="k" style="font-size: 12px; color: #4b5563">{{ item.row.meta?.[k] ?? '' }}</td>
              <td style="font-size: 11.5px; color: #6b7280">
                <span v-if="item.row.sub" style="display: inline-flex; align-items: center; gap: 5px">
                  <span :style="{ width: '7px', height: '7px', borderRadius: '50%', background: item.row.colorVivid }"></span>{{ item.row.sub }}
                </span>
                <span v-else style="color: #cdd2d9">未紐づけ</span>
                <span v-if="item.row.sub" style="color: #b8bec7"> ｜ {{ item.row.subjectName }}›{{ item.row.major }}›{{ item.row.mid }}</span>
                <span v-if="item.row.sub && !item.row.included" style="margin-left: 6px; font-size: 10px; font-weight: 600; color: #9aa1ab; background: #f1f2f4; padding: 1px 6px; border-radius: 99px">対象外</span>
              </td>
              <td style="text-align: center">
                <button class="cnt-btn" :disabled="!item.row.studyItemId" :style="{ color: item.row.recordCount > 0 ? '#1c2024' : '#cdd2d9', fontWeight: item.row.recordCount > 0 ? 700 : 400 }" title="学習記録を管理" @click="openRecords(item.row)">{{ item.row.recordCount }}</button>
              </td>
              <td style="font-size: 11.5px; line-height: 1.55">
                <template v-if="item.row.dates.length"
                  ><span v-for="(d, di) in item.row.dates" :key="di"
                    ><span :style="{ color: dateColorHex(d.color), fontWeight: d.color ? 600 : 400 }">{{ fmtMd(d.date) }}</span
                    ><span v-if="di < item.row.dates.length - 1" style="color: #c9ced6">, </span></span
                  ></template
                ><span v-else style="color: #c9ced6">—</span>
              </td>
              <td style="text-align: right; white-space: nowrap">
                <button
                  class="rec-log-btn"
                  :disabled="!item.row.studyItemId"
                  :title="item.row.studyItemId ? '学習記録を追加・管理（色・復習期限を設定）' : '学習項目に未紐づけ'"
                  @click="openRecords(item.row)"
                >学習記録</button>
                <button class="icon-btn danger" title="削除" @click="delRow(item.row)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg>
                </button>
              </td>
            </tr>
            </template>
          </tbody>
        </table>
      </div>
      <div v-if="rowView === 'list' && !filteredRows.length" style="padding: 36px; text-align: center; color: var(--faint); font-size: 13px">行データがありません</div>
      <div v-show="rowView === 'list' && !grouped" class="pager">
        <span>{{ rangeText }}</span>
        <div style="display: flex; align-items: center; gap: 6px">
          <button class="pg-btn" @click="page = Math.max(1, curPage - 1)">前へ</button>
          <span style="padding: 0 6px">{{ curPage }} / {{ totalPages }}</span>
          <button class="pg-btn" @click="page = Math.min(totalPages, curPage + 1)">次へ</button>
        </div>
      </div>
    </div>
        <div v-else-if="resource.booksOfType.length" class="card" style="padding: 44px; text-align: center; color: var(--faint); font-size: 13px">
          左の一覧から教材を選択してください。
        </div>
      </section>
    </div>

    <!-- 教材モーダル -->
    <Teleport to="body">
    <div v-if="bookModal.open" class="modal-bg" @click.self="bookModal.open = false">
      <div class="modal">
        <div class="modal-title">{{ bookModal.id ? '一覧を編集' : `新規${TAB_LABEL[resource.activeType]}` }}</div>
        <label class="fld"><span>タイトル</span><input v-model="bookModal.title" placeholder="例: Focus Gold 数学I+数学A" /></label>
        <label class="fld"><span>科目</span>
          <select v-model="bookModal.subjectId">
            <option :value="null">未設定</option>
            <option v-for="s in subjectOptions" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <div class="modal-actions">
          <button class="btn-out" @click="bookModal.open = false">キャンセル</button>
          <button class="btn-dark" @click="saveBook">保存</button>
        </div>
      </div>
    </div>
    </Teleport>

    <!-- 学習記録 管理モーダル -->
    <Teleport to="body">
    <div v-if="recModal.open" class="modal-bg" @click.self="recModal.open = false">
      <div class="modal rec-modal">
        <div class="modal-title">学習記録の管理</div>
        <div style="font-size: 12.5px; color: var(--mut); margin-top: -4px">
          {{ recModal.row?.title ?? recModal.row?.sub ?? '' }}
          <span v-if="recModal.row?.sub" style="color: var(--faint)"> ｜ {{ recModal.row?.subjectName }}›{{ recModal.row?.major }}›{{ recModal.row?.mid }}›{{ recModal.row?.sub }}</span>
        </div>
        <div style="max-height: 220px; overflow-y: auto; border: 1px solid #eceef0; border-radius: 10px">
          <div v-if="recModal.loading" style="padding: 20px; text-align: center; color: var(--faint); font-size: 13px">読み込み中…</div>
          <div v-else-if="!recModal.records.length" style="padding: 20px; text-align: center; color: var(--faint); font-size: 13px">学習記録はありません</div>
          <div v-for="rec in recModal.records" v-else :key="rec.id" class="rec-row">
            <span style="font-size: 13px; display: inline-flex; align-items: center; gap: 9px">
              <span class="rec-dot-static" :style="{ background: dateColorHex(rec.color) }"></span>
              <span :style="{ color: dateColorHex(rec.color), fontWeight: rec.color ? 600 : 400 }">{{ rec.studiedOn }}</span>
              <span v-if="rec.reviewOn" class="review-pill">復習 {{ fmtMd(rec.reviewOn) }}</span>
            </span>
            <button class="mini danger" @click="deleteRecordInModal(rec.id)">削除</button>
          </div>
        </div>

        <!-- 記録の追加（学習日・色・復習期限を設定） -->
        <div class="rec-form-head">記録を追加</div>
        <div class="rec-form">
          <div class="rec-form-row">
            <label class="fld" style="flex: 1"><span>学習日</span><input v-model="recModal.date" type="date" /></label>
            <div class="fld" style="flex: 0 0 auto">
              <span>色</span>
              <div style="display: flex; align-items: center; gap: 8px; height: 37px">
                <button
                  class="rec-dot none"
                  :class="{ sel: recModal.color === null }"
                  title="色なし"
                  @click="recModal.color = null"
                ></button>
                <button
                  v-for="c in RECORD_COLOR_KEYS"
                  :key="c"
                  class="rec-dot"
                  :class="{ sel: recModal.color === c }"
                  :style="{ background: DATE_COLORS[c] }"
                  :title="COLOR_LABEL[c]"
                  @click="recModal.color = c"
                ></button>
              </div>
            </div>
          </div>
          <div class="fld">
            <span>復習期限</span>
            <div class="review-opts">
              <button
                v-for="(opt, i) in REVIEW_OPTIONS"
                :key="opt.label"
                class="review-chip"
                :class="{ on: recModal.reviewIdx === i }"
                @click="recModal.reviewIdx = i"
              >{{ opt.label }}</button>
              <input
                v-if="REVIEW_OPTIONS[recModal.reviewIdx].kind === 'custom'"
                v-model.number="recModal.customDays"
                type="number"
                min="1"
                class="review-custom"
                placeholder="日数"
              />
            </div>
            <span style="font-size: 11px; color: var(--faint); font-weight: 400; margin-top: 4px">
              {{ reviewPreview }}
            </span>
          </div>
        </div>

        <div class="modal-actions">
          <button class="btn-out" @click="recModal.open = false">閉じる</button>
          <button class="btn-dark" @click="addRecordInModal">＋ 記録を追加</button>
        </div>
      </div>
    </div>
    </Teleport>

    <!-- 行モーダル -->
    <Teleport to="body">
    <div v-if="rowModal.open" class="modal-bg" @click.self="rowModal.open = false">
      <div class="modal">
        <div class="modal-title">行を追加</div>
        <label class="fld"><span>タイトル</span><input v-model="rowModal.title" placeholder="例: 整式の整理" /></label>
        <label class="fld"><span>小分類名（紐づけ先）</span><input v-model="rowModal.sub" placeholder="例: 整式の計算・因数分解" /></label>
        <div style="display: flex; gap: 10px">
          <label class="fld" style="flex: 1"><span>章</span><input v-model="rowModal.chapter" /></label>
          <label class="fld" style="width: 90px"><span>番号</span><input v-model="rowModal.seqNo" /></label>
          <label class="fld" style="width: 90px"><span>難易度</span><input v-model="rowModal.difficulty" placeholder="*" /></label>
        </div>
        <div style="font-size: 11px; color: var(--faint); margin-top: 4px">科目は教材の科目「{{ resource.activeBook?.subjectName ?? '未設定' }}」を使います。</div>
        <div class="modal-actions">
          <button class="btn-out" @click="rowModal.open = false">キャンセル</button>
          <button class="btn-dark" @click="saveRow">追加</button>
        </div>
      </div>
    </div>
    </Teleport>
  </div>
</template>

<style scoped>
.seg {
  display: flex;
  background: #fff;
  border: 1px solid #e6e8eb;
  border-radius: 11px;
  padding: 3px;
  width: fit-content;
}
.seg-btn {
  padding: 8px 18px;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  background: transparent;
  color: var(--mut);
}
.seg-btn.on {
  background: #1c2024;
  color: #fff;
}
.btn-dark {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 9px 15px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-out {
  padding: 9px 15px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  color: #1c2024;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
/* 共通レイアウト(.content max-width:1280 / padding:30px)の制限を打ち消し、
   この画面だけ広い横幅を使う。ビューポート中央に対称に広がるよう配置。 */
.resource-view {
  width: calc(100vw - 48px);
  max-width: 1680px;
  margin-left: 50%;
  transform: translateX(-50%);
}
.split {
  display: flex;
  gap: 16px;
  align-items: flex-start;
}
.book-col {
  flex: 0 0 320px;
  width: 320px;
  /* スクロールしても左カラムを画面上部に固定表示 */
  position: sticky;
  top: 8px;
  align-self: flex-start;
  max-height: calc(100vh - 96px);
}
.data-col {
  flex: 1;
  min-width: 0;
}
.book-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  max-height: calc(100vh - 96px);
  overflow-y: auto;
  padding: 2px 8px 8px 2px;
}
.book-card {
  position: relative;
  display: flex;
  gap: 12px;
  padding: 13px 13px 13px 26px;
  background: #fff;
  border: 1.5px solid #e9ebee;
  border-radius: 13px;
  cursor: pointer;
  transition:
    border-color 0.15s,
    box-shadow 0.15s,
    opacity 0.15s;
}
/* ドラッグ用グリップ（左端） */
.drag-handle {
  position: absolute;
  left: 3px;
  top: 0;
  bottom: 0;
  width: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #cbd1d8;
  cursor: grab;
}
.book-card:hover .drag-handle {
  color: #9aa1ab;
}
.drag-handle:active {
  cursor: grabbing;
}
/* ピン止めボタン（右上） */
.pin-btn {
  position: absolute;
  top: 8px;
  right: 8px;
  width: 26px;
  height: 26px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: #c2c8d0;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.pin-btn:hover {
  background: #f1f2f4;
  color: #6b7280;
}
.pin-btn.on {
  color: #e0a53b;
}
.book-card.pinned {
  background: #fffdf6;
  border-color: #f0dfae;
}
.book-card.dragging {
  opacity: 0.45;
}
.book-card.drag-over {
  border-color: #3b50cc;
  box-shadow: 0 0 0 2px rgba(59, 80, 204, 0.18);
}
@media (max-width: 880px) {
  /* スマホでは横幅ブレイクアウトを解除（100vw/translateによる見切れを防ぐ）。
     通常フローに戻すことで、テーブルは overflow-x:auto ラッパー内で横スクロール可能になる。 */
  .resource-view {
    width: auto;
    max-width: none;
    margin-left: 0;
    transform: none;
  }
  .split {
    flex-direction: column;
    /* 縦積みでは align-items が横方向の制御になる。flex-start のままだと
       .data-col がテーブル(min-width:900)の幅まで広がり画面外に出て横スクロール不能になる。
       stretch で画面幅に収め、内側の overflow-x:auto を効かせる。 */
    align-items: stretch;
  }
  .book-col {
    flex: none;
    width: 100%;
    /* 縦積みレイアウトでは固定表示を解除 */
    position: static;
    max-height: none;
  }
  .book-list {
    flex-direction: row;
    max-height: none;
    overflow-x: auto;
    overflow-y: hidden;
  }
  .book-card {
    flex: 0 0 300px;
  }
}
.book-card.active {
  border-color: #1c2024;
}
.book-thumb {
  width: 58px;
  height: 76px;
  border-radius: 7px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border: 1px solid #eceef0;
}
.book-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.book-title {
  font-size: 13.5px;
  font-weight: 700;
  line-height: 1.35;
  padding-right: 26px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.prog-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.prog-bar {
  flex: 1;
  height: 6px;
  background: #eef0f2;
  border-radius: 99px;
  overflow: hidden;
}
.prog-bar span {
  display: block;
  height: 100%;
  border-radius: 99px;
}
.book-actions {
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
  margin-top: 9px;
}
.mini {
  border: 1px solid #e3e6ea;
  background: #fff;
  border-radius: 7px;
  padding: 4px 9px;
  font-size: 11px;
  cursor: pointer;
  color: #4b5563;
  font-weight: 600;
}
.mini.danger {
  color: #cf5563;
}
.tbl-toolbar {
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 12px 14px;
  border-bottom: 1px solid #f0f1f3;
  flex-wrap: wrap;
}
.search {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
}
.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  min-width: 900px;
}
.tbl thead tr {
  background: #f8f9fb;
  color: var(--faint);
  font-size: 11.5px;
  text-align: left;
}
.tbl th {
  padding: 10px;
  font-weight: 600;
}
.tbl th:first-child {
  padding-left: 14px;
}
.tbl td {
  padding: 9px 10px;
  border-top: 1px solid #f0f1f3;
  vertical-align: middle;
}
.tbl td:first-child {
  padding-left: 14px;
}
.chapter-row {
  cursor: pointer;
  background: #f4f6f9;
  user-select: none;
}
.chapter-row:hover {
  background: #eef1f5;
}
.chapter-row td {
  padding: 8px 14px;
}
.chapter-cell {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.chapter-chev {
  color: #6b7280;
  transition: transform 0.12s ease;
  flex-shrink: 0;
}
.chapter-chev.open {
  transform: rotate(90deg);
}
.chapter-name {
  font-weight: 700;
  font-size: 12.5px;
  color: #1c2024;
}
.chapter-count {
  font-size: 11px;
  color: var(--faint);
  font-weight: 500;
}
.rec-btn {
  border: none;
  background: #1c2024;
  color: #fff;
  border-radius: 7px;
  padding: 5px 11px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  margin-right: 4px;
}
/* 学習日 登録用の色ドット（赤/青/緑） */
.rec-dots {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  vertical-align: middle;
  margin-right: 8px;
}
.rec-dot {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 1.5px solid #fff;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.14);
  cursor: pointer;
  padding: 0;
}
.rec-dot:hover:not(:disabled) {
  transform: scale(1.15);
}
.rec-dot:disabled {
  opacity: 0.28;
  cursor: default;
}
.rec-dot.sel {
  box-shadow: 0 0 0 2px #1c2024;
}
.rec-dot-static {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  border: 1.5px solid #fff;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.14);
  flex-shrink: 0;
}
/* 色なしドット（グレーの輪郭のみ） */
.rec-dot.none {
  background: #fff;
  box-shadow: 0 0 0 1px #cbd1d8;
}
.rec-dot.none.sel {
  box-shadow: 0 0 0 2px #1c2024;
}
/* 学習記録ボタン（操作列） */
.rec-log-btn {
  border: 1px solid #d7dbe0;
  background: #fff;
  color: #1c2024;
  border-radius: 8px;
  padding: 5px 12px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  margin-right: 8px;
  vertical-align: middle;
}
.rec-log-btn:hover:not(:disabled) {
  background: #1c2024;
  color: #fff;
  border-color: #1c2024;
}
.rec-log-btn:disabled {
  opacity: 0.4;
  cursor: default;
}
/* 復習期限バッジ（記録一覧内） */
.review-pill {
  font-size: 10.5px;
  font-weight: 600;
  color: #b07d18;
  background: #fbf3df;
  border: 1px solid #eddfba;
  padding: 1px 7px;
  border-radius: 99px;
}
/* 学習記録モーダル（一覧＋追加フォームを収めるため広め・縦スクロール可） */
.rec-modal {
  max-width: 520px;
  max-height: 88vh;
  overflow-y: auto;
}
.rec-form-head {
  font-size: 12.5px;
  font-weight: 700;
  color: #1c2024;
  border-top: 1px solid #eceef0;
  padding-top: 12px;
  margin-top: 2px;
}
/* 記録追加フォーム */
.rec-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.rec-form-row {
  display: flex;
  gap: 12px;
  align-items: flex-end;
  flex-wrap: wrap;
}
.review-opts {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
}
.review-chip {
  border: 1px solid #e3e6ea;
  background: #fff;
  border-radius: 8px;
  padding: 6px 11px;
  font-size: 12px;
  font-weight: 600;
  color: #4b5563;
  cursor: pointer;
}
.review-chip.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.fld input.review-custom {
  width: 84px;
  padding: 7px 9px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 12.5px;
  outline: none;
}
.cnt-btn {
  border: 1px solid transparent;
  background: transparent;
  border-radius: 7px;
  padding: 3px 10px;
  font-size: 13px;
  cursor: pointer;
}
.cnt-btn:hover:not(:disabled) {
  border-color: #e3e6ea;
  background: #f8f9fb;
}
.cnt-btn:disabled {
  cursor: default;
}
.tgt-chk {
  width: 16px;
  height: 16px;
  cursor: pointer;
  accent-color: #1c2024;
}
.tgt-chk:disabled {
  cursor: default;
}
.rec-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 9px 13px;
  border-bottom: 1px solid #f2f3f5;
}
.rec-row:last-child {
  border-bottom: none;
}
.icon-btn {
  border: none;
  background: transparent;
  cursor: pointer;
  color: #9aa1ab;
  padding: 4px;
}
.icon-btn.danger {
  color: #d99;
}
.pager {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 11px 16px;
  border-top: 1px solid #f0f1f3;
  font-size: 12.5px;
  color: var(--mut);
  flex-wrap: wrap;
  gap: 8px;
}
.pg-btn {
  padding: 6px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  cursor: pointer;
  font-size: 12.5px;
  color: #4b5563;
}
.seg-sm {
  flex-shrink: 0;
}
.seg-sm .seg-btn {
  padding: 6px 12px;
  font-size: 12px;
}
.mini-btn {
  border: 1px solid #e3e6ea;
  background: #fff;
  border-radius: 8px;
  padding: 6px 12px;
  font-size: 12px;
  cursor: pointer;
  font-weight: 600;
  flex-shrink: 0;
}
/* 重要のみ 絞り込みトグル */
.imp-filter {
  color: #b0894b;
}
.imp-filter.on {
  background: #fbf3df;
  border-color: #e7c987;
  color: #b07d18;
}
/* Check有りのみ 絞り込み */
.flt-check {
  color: #3a8a5c;
}
.flt-check.on {
  background: #e8f5ee;
  border-color: #a7d9bd;
  color: #2e7d50;
}
/* Think有りのみ 絞り込み */
.flt-think {
  color: #6b5bd0;
}
.flt-think.on {
  background: #eeecfa;
  border-color: #c4bcf0;
  color: #5849c0;
}
/* 行の重要スター */
.imp-star {
  border: none;
  background: transparent;
  cursor: pointer;
  font-size: 16px;
  line-height: 1;
  color: #d6dae0;
  padding: 2px;
}
.imp-star:hover {
  color: #e7c56b;
}
.imp-star.on {
  color: #e0a53b;
}
.tree-row {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 7px 14px;
  border-top: 1px solid #f6f7f8;
}
.chev {
  width: 18px;
  height: 18px;
  border: none;
  background: none;
  cursor: pointer;
  color: #9aa1ab;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.chk {
  width: 19px;
  height: 19px;
  border: 1.6px solid;
  border-radius: 5px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
}
.modal-bg {
  position: fixed;
  inset: 0;
  background: rgba(20, 22, 26, 0.42);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 50;
  padding: 16px;
}
.modal {
  background: #fff;
  border-radius: 15px;
  padding: 22px;
  width: 100%;
  max-width: 440px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.modal-title {
  font-size: 16px;
  font-weight: 700;
}
.fld span {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  display: block;
  margin-bottom: 5px;
}
.fld input,
.fld select {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
  background: #fff;
}
.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 9px;
  margin-top: 4px;
}
</style>
