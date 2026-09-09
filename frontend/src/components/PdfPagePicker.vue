<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import PdfThumb from '@/components/PdfThumb.vue'
import { MARK_COLOR, MARK_LABEL } from '@/api/quiz'
import type { BookPdf, QuizPageSpec, QuizRow } from '@/types'

/**
 * 出題ページの選択 UI。
 * - PDF はタブで切替。番号=ページ対応の PDF は「一覧から選ぶ」（例題 No. をチェックするとページが自動で決まる）、
 *   どの PDF でも「ページから選ぶ」（サムネイル）が使える。
 * - 右側の出題カートで順番の入れ替え・削除・解答参照ページの設定ができる。
 */
export interface SelectedPage extends QuizPageSpec {
  key: string
  label: string
  pdfTitle: string
}

const props = defineProps<{ pdfs: BookPdf[]; rows: QuizRow[]; modelValue: SelectedPage[] }>()
const emit = defineEmits<{ 'update:modelValue': [SelectedPage[]] }>()

const activePdfId = ref<number>(props.pdfs[0]?.id ?? 0)
const activePdf = computed(() => props.pdfs.find((p) => p.id === activePdfId.value) ?? null)
const canUseRows = computed(() => activePdf.value?.pageMap === 'seq' && props.rows.length > 0)
const mode = ref<'rows' | 'pages'>(canUseRows.value ? 'rows' : 'pages')
watch(activePdfId, () => {
  if (!canUseRows.value) mode.value = 'pages'
})

const search = ref('')
const chapter = ref('')
const onlyUnstudied = ref(false)
const preview = ref<{ pdfId: number; page: number } | null>(null)
/** 解答参照ページを設定中のカート項目 */
const refTarget = ref<string | null>(null)

const chapters = computed(() => Array.from(new Set(props.rows.map((r) => r.chapter ?? '').filter(Boolean))))

const filteredRows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return props.rows.filter((r) => {
    if (chapter.value && (r.chapter ?? '') !== chapter.value) return false
    if (onlyUnstudied.value && r.recordCount > 0) return false
    if (!q) return true
    return `${r.seqNo ?? ''} ${r.title ?? ''} ${r.chapter ?? ''}`.toLowerCase().includes(q)
  })
})

const groupedRows = computed(() => {
  const groups: { chapter: string; rows: QuizRow[] }[] = []
  for (const r of filteredRows.value) {
    const c = r.chapter ?? ''
    const g = groups[groups.length - 1]
    if (g && g.chapter === c) g.rows.push(r)
    else groups.push({ chapter: c, rows: [r] })
  }
  return groups
})

const selectedKeys = computed(() => new Set(props.modelValue.map((s) => s.key)))
function keyOf(pdfId: number, page: number): string {
  return `${pdfId}:${page}`
}
function pageOfRow(r: QuizRow): number | null {
  const p = r.pages[String(activePdfId.value)]
  return p ?? null
}
/** ページ → 対応する行（逆引き） */
const rowByPage = computed(() => {
  const m = new Map<number, QuizRow>()
  for (const r of props.rows) {
    const p = r.pages[String(activePdfId.value)]
    if (p !== undefined && !m.has(p)) m.set(p, r)
  }
  return m
})

function rowLabel(r: QuizRow): string {
  return `${r.seqNo ? 'No.' + r.seqNo + ' ' : ''}${r.title ?? ''}`.trim()
}

function toggle(pdf: BookPdf, page: number, row: QuizRow | null) {
  if (refTarget.value) {
    setRef(refTarget.value, pdf.id, page)
    return
  }
  const key = keyOf(pdf.id, page)
  if (selectedKeys.value.has(key)) {
    emit(
      'update:modelValue',
      props.modelValue.filter((s) => s.key !== key),
    )
    return
  }
  const item: SelectedPage = {
    key,
    pdfId: pdf.id,
    page,
    itemId: row?.id ?? null,
    label: row ? rowLabel(row) : `${pdf.title} p.${page}`,
    pdfTitle: pdf.title,
  }
  emit('update:modelValue', [...props.modelValue, item])
  preview.value = { pdfId: pdf.id, page }
}

function toggleRow(r: QuizRow) {
  const pdf = activePdf.value
  const page = pageOfRow(r)
  if (!pdf || page === null) return
  toggle(pdf, page, r)
}

function togglePage(page: number) {
  const pdf = activePdf.value
  if (!pdf) return
  toggle(pdf, page, rowByPage.value.get(page) ?? null)
}

function remove(key: string) {
  emit(
    'update:modelValue',
    props.modelValue.filter((s) => s.key !== key),
  )
  if (refTarget.value === key) refTarget.value = null
}
function move(key: string, dir: -1 | 1) {
  const list = [...props.modelValue]
  const i = list.findIndex((s) => s.key === key)
  const j = i + dir
  if (i < 0 || j < 0 || j >= list.length) return
  const tmp = list[i]!
  list[i] = list[j]!
  list[j] = tmp
  emit('update:modelValue', list)
}
function setRef(key: string, pdfId: number | null, page: number | null) {
  emit(
    'update:modelValue',
    props.modelValue.map((s) => (s.key === key ? { ...s, refPdfId: pdfId, refPage: page } : s)),
  )
  refTarget.value = null
}
function startRef(key: string) {
  refTarget.value = refTarget.value === key ? null : key
  if (refTarget.value) mode.value = 'pages'
}
function pdfTitleOf(id: number | null | undefined): string {
  return props.pdfs.find((p) => p.id === id)?.title ?? ''
}

// サムネイル一覧（ページから選ぶ）: 大量ページ向けにブロック単位で表示
const pageJump = ref<number | null>(null)
const pageBlock = ref(0)
const BLOCK = 60
const pageCount = computed(() => activePdf.value?.pageCount ?? 0)
const blockCount = computed(() => Math.max(1, Math.ceil(pageCount.value / BLOCK)))
const blockPages = computed(() => {
  const start = pageBlock.value * BLOCK + 1
  const end = Math.min(pageCount.value, start + BLOCK - 1)
  const list: number[] = []
  for (let p = start; p <= end; p++) list.push(p)
  return list
})
function jump() {
  const p = Number(pageJump.value)
  if (!p || p < 1 || p > pageCount.value) return
  pageBlock.value = Math.floor((p - 1) / BLOCK)
  preview.value = { pdfId: activePdfId.value, page: p }
}
watch(activePdfId, () => (pageBlock.value = 0))

const previewLabel = computed(() => {
  if (!preview.value) return ''
  const sel = props.modelValue.find((s) => s.key === keyOf(preview.value!.pdfId, preview.value!.page))
  return sel ? sel.label : `${pdfTitleOf(preview.value.pdfId)} p.${preview.value.page}`
})
function showPreview(pdfId: number, page: number | null) {
  if (page === null) return
  preview.value = { pdfId, page }
}
</script>

<template>
  <div class="picker">
    <div class="left">
      <!-- PDF タブ -->
      <div class="tabs">
        <button v-for="p in pdfs" :key="p.id" class="tab" :class="{ active: p.id === activePdfId }" @click="activePdfId = p.id">
          <span class="pdf-dot"></span>{{ p.title }}<span class="tab-sub">{{ p.pageCount }}p</span>
        </button>
      </div>
      <div class="mode-row">
        <div class="seg">
          <button :class="{ on: mode === 'rows' }" :disabled="!canUseRows" :title="canUseRows ? '' : 'この PDF は番号=ページ対応ではありません'" @click="mode = 'rows'">一覧から選ぶ</button>
          <button :class="{ on: mode === 'pages' }" @click="mode = 'pages'">ページから選ぶ</button>
        </div>
        <div v-if="refTarget" class="ref-banner">
          解答参照ページを選択中: <b>{{ modelValue.find((s) => s.key === refTarget)?.label }}</b>
          <button class="link" @click="refTarget = null">やめる</button>
        </div>
      </div>

      <!-- 一覧から選ぶ -->
      <template v-if="mode === 'rows'">
        <div class="filters">
          <input v-model="search" class="inp" placeholder="No. / タイトルで検索" />
          <select v-model="chapter" class="inp" style="max-width: 200px">
            <option value="">すべての章</option>
            <option v-for="c in chapters" :key="c" :value="c">{{ c }}</option>
          </select>
          <label class="chk"><input v-model="onlyUnstudied" type="checkbox" /> 未学習のみ</label>
        </div>
        <div class="rows">
          <template v-for="g in groupedRows" :key="g.chapter">
            <div class="chap">{{ g.chapter || '（章なし）' }}</div>
            <div
              v-for="r in g.rows"
              :key="r.id"
              class="row"
              :class="{ on: pageOfRow(r) !== null && selectedKeys.has(keyOf(activePdfId, pageOfRow(r)!)), off: pageOfRow(r) === null }"
              @click="toggleRow(r)"
              @mouseenter="showPreview(activePdfId, pageOfRow(r))"
            >
              <span class="box">
                <svg v-if="pageOfRow(r) !== null && selectedKeys.has(keyOf(activePdfId, pageOfRow(r)!))" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7" /></svg>
              </span>
              <span class="no">{{ r.seqNo ? 'No.' + r.seqNo : '' }}</span>
              <span class="ttl">
                {{ r.title }}
                <span v-if="r.important" class="imp">重要</span>
              </span>
              <span class="diff">{{ r.difficulty }}</span>
              <span class="st" :class="{ done: r.recordCount > 0 }">{{ r.recordCount > 0 ? `学習済 ${r.recordCount}回` : '未学習' }}</span>
              <span v-if="r.quizCount" class="hist" :style="{ color: r.lastMark ? MARK_COLOR[r.lastMark] : 'var(--faint)' }">
                出題{{ r.quizCount }}回<template v-if="r.lastMark"> {{ MARK_LABEL[r.lastMark] }}</template>
              </span>
              <span class="pg">{{ pageOfRow(r) !== null ? 'p.' + pageOfRow(r) : '対応なし' }}</span>
            </div>
          </template>
          <div v-if="!groupedRows.length" class="empty">該当する行がありません</div>
        </div>
      </template>

      <!-- ページから選ぶ -->
      <template v-else>
        <div class="filters">
          <div class="blocks">
            <button v-for="b in blockCount" :key="b" class="blk" :class="{ on: pageBlock === b - 1 }" @click="pageBlock = b - 1">
              {{ (b - 1) * BLOCK + 1 }}–{{ Math.min(pageCount, b * BLOCK) }}
            </button>
          </div>
          <div style="display: flex; gap: 6px; align-items: center; margin-left: auto">
            <input v-model.number="pageJump" class="inp" type="number" min="1" :max="pageCount" placeholder="ページ" style="width: 90px" @keyup.enter="jump" />
            <button class="mini" @click="jump">移動</button>
          </div>
        </div>
        <div class="grid">
          <div
            v-for="pg in blockPages"
            :key="pg"
            class="cell"
            :class="{ on: selectedKeys.has(keyOf(activePdfId, pg)), hi: preview?.pdfId === activePdfId && preview?.page === pg }"
            @click="togglePage(pg)"
            @mouseenter="showPreview(activePdfId, pg)"
          >
            <PdfThumb v-if="activePdf" :pdf-id="activePdf.id" :page="pg" :width="120" />
            <div class="cell-cap">
              <span>p.{{ pg }}</span>
              <span v-if="rowByPage.get(pg)" class="cell-no">No.{{ rowByPage.get(pg)!.seqNo }}</span>
            </div>
            <span v-if="selectedKeys.has(keyOf(activePdfId, pg))" class="badge">{{ modelValue.findIndex((s) => s.key === keyOf(activePdfId, pg)) + 1 }}</span>
          </div>
        </div>
      </template>
    </div>

    <div class="right">
      <!-- プレビュー -->
      <div class="prev card">
        <div class="prev-cap">{{ preview ? previewLabel : 'ページにカーソルを合わせるとプレビューします' }}</div>
        <div class="prev-body">
          <PdfThumb v-if="preview" :key="preview.pdfId + ':' + preview.page" :pdf-id="preview.pdfId" :page="preview.page" :width="420" eager />
        </div>
      </div>

      <!-- 出題カート -->
      <div class="cart card">
        <div class="cart-head">
          <span>出題ページ <b>{{ modelValue.length }}</b> ページ</span>
          <button v-if="modelValue.length" class="link" @click="emit('update:modelValue', [])">すべて解除</button>
        </div>
        <div v-if="!modelValue.length" class="empty">左の一覧やページをクリックして追加します</div>
        <div v-for="(s, i) in modelValue" :key="s.key" class="cart-row" :class="{ ref: refTarget === s.key }" @mouseenter="showPreview(s.pdfId, s.page)">
          <span class="num">{{ i + 1 }}</span>
          <div style="flex: 1; min-width: 0">
            <div class="cart-label">{{ s.label }}</div>
            <div class="cart-sub">
              {{ s.pdfTitle }} p.{{ s.page }}
              <template v-if="s.refPage">
                ・解答: {{ pdfTitleOf(s.refPdfId) }} p.{{ s.refPage }}
                <button class="link" @click="setRef(s.key, null, null)">解除</button>
              </template>
              <button v-else class="link" @click="startRef(s.key)">{{ refTarget === s.key ? '選択中…' : '解答ページ' }}</button>
            </div>
          </div>
          <button class="ic" :disabled="i === 0" title="上へ" @click="move(s.key, -1)">↑</button>
          <button class="ic" :disabled="i === modelValue.length - 1" title="下へ" @click="move(s.key, 1)">↓</button>
          <button class="ic del" title="削除" @click="remove(s.key)">×</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.picker {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 14px;
  min-height: 0;
}
@media (max-width: 900px) {
  .picker {
    grid-template-columns: 1fr;
  }
  .right {
    order: -1;
  }
  .prev-body {
    display: none;
  }
}
.left {
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
}
.right {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}
.card {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
}
.tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 999px;
  background: #fff;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.tab.active {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.pdf-dot {
  width: 8px;
  height: 8px;
  border-radius: 2px;
  background: #cf4444;
}
.tab-sub {
  font-size: 10.5px;
  opacity: 0.7;
}
.mode-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.seg {
  display: inline-flex;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  overflow: hidden;
}
.seg button {
  padding: 7px 12px;
  border: none;
  background: #fff;
  font-size: 12px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.seg button.on {
  background: #f1f2f4;
  color: var(--ink);
}
.seg button:disabled {
  opacity: 0.45;
  cursor: default;
}
.ref-banner {
  font-size: 12px;
  color: #8a5a00;
  background: #fff6e0;
  border: 1px solid #f5d98a;
  border-radius: 8px;
  padding: 5px 10px;
}
.filters {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.inp {
  padding: 7px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 12.5px;
  background: #fff;
  outline: none;
  flex: 1;
  min-width: 140px;
}
.chk {
  font-size: 12px;
  color: var(--mut);
  display: inline-flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
}
.rows {
  flex: 1;
  min-height: 200px;
  max-height: 60vh;
  overflow-y: auto;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: #fff;
}
.chap {
  position: sticky;
  top: 0;
  background: #f6f7f9;
  font-size: 11.5px;
  font-weight: 700;
  color: var(--mut);
  padding: 6px 12px;
  border-bottom: 1px solid var(--line);
  z-index: 1;
}
.row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 12px;
  border-bottom: 1px solid #f1f2f4;
  cursor: pointer;
  font-size: 12.5px;
}
.row:hover {
  background: #f8f9fb;
}
.row.on {
  background: #eef1fc;
}
.row.off {
  opacity: 0.45;
  cursor: default;
}
.box {
  width: 17px;
  height: 17px;
  border-radius: 5px;
  border: 1.5px solid #cfd4db;
  background: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.row.on .box {
  background: #3b50cc;
  border-color: #3b50cc;
}
.no {
  width: 56px;
  flex-shrink: 0;
  font-weight: 700;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}
.ttl {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.imp {
  font-size: 10px;
  color: #c0444f;
  background: #fdf0f1;
  border-radius: 4px;
  padding: 1px 5px;
  margin-left: 4px;
}
.diff {
  width: 44px;
  flex-shrink: 0;
  color: #d98a1a;
  font-size: 11px;
  letter-spacing: 1px;
}
.st {
  width: 72px;
  flex-shrink: 0;
  font-size: 10.5px;
  color: var(--faint);
}
.st.done {
  color: #2f7a4f;
}
.hist {
  width: 64px;
  flex-shrink: 0;
  font-size: 10.5px;
}
.pg {
  width: 52px;
  flex-shrink: 0;
  text-align: right;
  font-size: 11px;
  color: var(--faint);
  font-variant-numeric: tabular-nums;
}
.empty {
  font-size: 12px;
  color: var(--faint);
  text-align: center;
  padding: 20px 10px;
}
.blocks {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}
.blk {
  padding: 5px 9px;
  border: 1px solid #e3e6ea;
  border-radius: 7px;
  background: #fff;
  font-size: 11px;
  color: var(--mut);
  cursor: pointer;
  font-variant-numeric: tabular-nums;
}
.blk.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
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
}
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  gap: 10px;
  max-height: 60vh;
  overflow-y: auto;
  padding: 10px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: #fff;
}
.cell {
  position: relative;
  cursor: pointer;
  border-radius: 8px;
  padding: 4px;
  border: 2px solid transparent;
}
.cell:hover,
.cell.hi {
  background: #f3f4f7;
}
.cell.on {
  border-color: #3b50cc;
  background: #eef1fc;
}
.cell-cap {
  display: flex;
  justify-content: space-between;
  font-size: 10.5px;
  color: var(--mut);
  margin-top: 3px;
  padding: 0 2px;
}
.cell-no {
  font-weight: 700;
  color: var(--ink);
}
.badge {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #3b50cc;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}
.prev {
  padding: 10px;
}
.prev-cap {
  font-size: 11.5px;
  color: var(--mut);
  margin-bottom: 6px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.prev-body {
  display: flex;
  justify-content: center;
  min-height: 120px;
}
.prev-body :deep(.thumb) {
  width: 100% !important;
  max-width: 420px;
}
.cart {
  padding: 12px;
}
.cart-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12.5px;
  margin-bottom: 8px;
}
.cart-row {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 7px 6px;
  border-top: 1px solid #f1f2f4;
  border-radius: 6px;
}
.cart-row.ref {
  background: #fff6e0;
}
.num {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #1c2024;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.cart-label {
  font-size: 12.5px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cart-sub {
  font-size: 10.5px;
  color: var(--faint);
}
.ic {
  width: 24px;
  height: 24px;
  border: 1px solid #e3e6ea;
  border-radius: 6px;
  background: #fff;
  color: var(--mut);
  cursor: pointer;
  font-size: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ic:disabled {
  opacity: 0.35;
  cursor: default;
}
.ic.del {
  color: #c0444f;
}
.link {
  border: none;
  background: none;
  color: #3b50cc;
  font-size: 11px;
  cursor: pointer;
  padding: 0 4px;
}
</style>
