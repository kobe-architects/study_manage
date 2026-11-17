<script setup lang="ts">
/**
 * 講師用: チェックシートを画面上で表示する。
 * 教材（スクランブル英文法・語法／英文法ファーストステップ）を選び、スクランブルは問題集（文法／語法／語い…）をタブで切り替える。
 * 生徒側の出力（解いた日付）と違い、回数欄は ○（正解・黒）△（あいまい・青）×（不正解・赤）で表す（日付は出さない）。
 * 表示形式は 2 つ（記憶する。既定は分野別）:
 *   - 分野別: 左の索引で章（分野）を選ぶと、その章だけを表示する
 *   - まとめ: 全章を 1 枚のシートとして表示。索引を押すとその章まで送る
 * シートの HTML は生徒側と同じ部品（lib/checkSheet.ts）で組み、iframe に入れて表示する（印刷は iframe の内容を印刷）。
 */
import { computed, onMounted, ref, watch } from 'vue'
import { grammarApi } from '@/api/grammar'
import { buildCheckSheetHtml, type SheetChapter, type SheetSpec } from '@/lib/checkSheet'
import { buildFirstStepSheetSpec } from '@/lib/firstStepSheet'
import { buildBookSheetSpec, buildGrammarSheetSpec, type GrammarMarks } from '@/lib/grammarSheet'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import type { GrammarBookKey, GrammarQuestion } from '@/types'

const ui = useUiStore()
const auth = useAuthStore()

type Material = 'scramble' | 'first_step'
const MATERIALS: { key: Material; label: string; short: string }[] = [
  { key: 'scramble', label: 'スクランブル英文法・語法', short: 'スクランブル' },
  { key: 'first_step', label: '英文法ファーストステップ', short: 'ファーストステップ' },
]
const BOOKS: { key: GrammarBookKey; label: string }[] = [
  { key: 'grammar', label: '文法' },
  { key: 'usage', label: '語法' },
  { key: 'vocab', label: '語い' },
  { key: 'idiom', label: 'イディオム' },
  { key: 'conversation', label: '会話表現' },
  { key: 'pronunciation', label: '発音・アクセント' },
  { key: 'special', label: '読解・作文の文法' },
]
type Mode = 'chapter' | 'all'

// 選択の記憶
const KEY_MATERIAL = 'sm_tutor_sheet_material'
const KEY_BOOK = 'sm_tutor_sheet_book'
const KEY_MODE = 'sm_tutor_sheet_mode'
const savedMaterial = localStorage.getItem(KEY_MATERIAL) as Material | null
const savedBook = localStorage.getItem(KEY_BOOK) as GrammarBookKey | null
const savedMode = localStorage.getItem(KEY_MODE) as Mode | null
const material = ref<Material>(savedMaterial && MATERIALS.some((m) => m.key === savedMaterial) ? savedMaterial : 'scramble')
const book = ref<GrammarBookKey>(savedBook && BOOKS.some((b) => b.key === savedBook) ? savedBook : 'grammar')
const mode = ref<Mode>(savedMode === 'all' ? 'all' : 'chapter')

const loading = ref(false)
/** 今の教材・問題集の全章のシート */
const spec = ref<SheetSpec | null>(null)
const frame = ref<HTMLIFrameElement | null>(null)

/** 取得した結果のキャッシュ（教材・問題集ごと） */
const marksCache = new Map<GrammarBookKey, GrammarMarks>()
const questionsCache = new Map<GrammarBookKey, GrammarQuestion[]>()
/** 分野別で最後に見ていた章（教材・問題集ごと） */
const chapterMemory = new Map<GrammarBookKey, number>()
const chapterIdx = ref(0)

const studentName = computed(() => auth.user?.student?.name ?? '')
/** 読み込みのキー（ファーストステップは 1 冊なので book=first_step） */
const sheetKey = computed<GrammarBookKey>(() => (material.value === 'first_step' ? 'first_step' : book.value))
const bookLabel = computed(() => (material.value === 'first_step' ? '英文法ファーストステップ' : `スクランブル ${BOOKS.find((b) => b.key === book.value)?.label ?? ''}`))

async function load() {
  loading.value = true
  const key = sheetKey.value
  try {
    let marks = marksCache.get(key)
    if (!marks) {
      marks = await grammarApi.checksheet(key === 'grammar' || key === 'usage' ? undefined : key)
      marksCache.set(key, marks)
    }
    const d = new Date()
    const date = `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`
    const extra = { markMode: 'symbol' as const, noToolbar: true, note: `${date} 時点` }
    let built: SheetSpec
    if (key === 'grammar' || key === 'usage') {
      built = buildGrammarSheetSpec(marks, studentName.value, date, key, extra)
    } else {
      let qs = questionsCache.get(key)
      if (!qs) {
        qs = await grammarApi.questions(key)
        questionsCache.set(key, qs)
      }
      built = key === 'first_step' ? buildFirstStepSheetSpec(qs, marks, studentName.value, date, extra) : buildBookSheetSpec(key, BOOKS.find((b) => b.key === key)?.label ?? '', qs, marks, studentName.value, date, extra)
    }
    if (sheetKey.value !== key) return // 読んでいる間に切り替わった
    spec.value = built
    chapterIdx.value = Math.min(chapterMemory.get(key) ?? 0, Math.max(0, built.chapters.length - 1))
  } catch {
    ui.notify('チェックシートの取得に失敗しました')
  } finally {
    if (sheetKey.value === key) loading.value = false
  }
}

function selectMaterial(m: Material) {
  if (material.value === m) return
  material.value = m
  localStorage.setItem(KEY_MATERIAL, m)
}
function selectBook(k: GrammarBookKey) {
  if (book.value === k) return
  book.value = k
  localStorage.setItem(KEY_BOOK, k)
}
function selectMode(m: Mode) {
  if (mode.value === m) return
  mode.value = m
  localStorage.setItem(KEY_MODE, m)
}
watch(sheetKey, load)
onMounted(load)

/** 索引: 章ごとの問数と、最新の結果が × の数・未回答の数 */
const index = computed(() =>
  (spec.value?.chapters ?? []).map((ch, i) => {
    let ng = 0
    let none = 0
    for (const r of ch.rows) {
      const last = r.marks[r.marks.length - 1]
      if (!last) none++
      else if (last.status === 0) ng++
    }
    return { i, label: ch.label ?? '', title: ch.title, total: ch.rows.length, ng, none }
  }),
)
function selectChapter(i: number) {
  chapterIdx.value = i
  chapterMemory.set(sheetKey.value, i)
  if (mode.value === 'all') {
    // まとめ表示では、その章まで送る
    const doc = frame.value?.contentWindow?.document
    doc?.getElementById(`ch-${i}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }
}

/** 画面に出す章（分野別なら選んだ 1 章。バッジ・小見出しは前の章から引き継ぐ） */
const shownChapters = computed<SheetChapter[]>(() => {
  const s = spec.value
  if (!s) return []
  if (mode.value === 'all') return s.chapters
  const i = Math.min(chapterIdx.value, s.chapters.length - 1)
  const ch = s.chapters[i]
  if (!ch) return []
  let badge = s.badge
  let headline = s.headline
  for (let k = 0; k <= i; k++) {
    const c = s.chapters[k]!
    if (c.badge !== undefined) badge = c.badge
    if (c.headline !== undefined) headline = c.headline
  }
  return [{ ...ch, newPage: false, badge, headline }]
})
const html = computed(() => (spec.value ? buildCheckSheetHtml({ ...spec.value, chapters: shownChapters.value }) : ''))

/** 集計（表示中の章。最新の結果が ○／△／×） */
const stat = computed(() => {
  let total = 0
  let correct = 0
  let incorrect = 0
  let unsure = 0
  for (const ch of shownChapters.value) {
    for (const r of ch.rows) {
      total++
      const last = r.marks[r.marks.length - 1]
      if (!last) continue
      if (last.status === 0) incorrect++
      else if (last.status === 2) unsure++
      else correct++
    }
  }
  return total ? { total, correct, incorrect, unsure } : null
})

function print() {
  frame.value?.contentWindow?.print()
}
async function reload() {
  marksCache.delete(sheetKey.value)
  await load()
}
</script>

<template>
  <div class="sheet-view">
    <div class="head-row">
      <div style="display: flex; align-items: center; gap: 8px; min-width: 0">
        <div style="font-size: 17px; font-weight: 700; white-space: nowrap">チェックシート</div>
      </div>
      <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap">
        <div v-seg class="seg" role="radiogroup" aria-label="表示形式">
          <button :class="{ on: mode === 'chapter' }" role="radio" :aria-checked="mode === 'chapter'" @click="selectMode('chapter')">分野別</button>
          <button :class="{ on: mode === 'all' }" role="radio" :aria-checked="mode === 'all'" @click="selectMode('all')">まとめ</button>
        </div>
        <button class="btn-outline" :disabled="loading" @click="reload">更新</button>
        <button class="btn-outline" :disabled="!html" @click="print">印刷 / PDF</button>
      </div>
    </div>

    <!-- 教材 -->
    <div v-seg class="tabs mat" role="tablist" aria-label="教材">
      <button v-for="m in MATERIALS" :key="m.key" class="tab" :class="{ on: material === m.key }" role="tab" :aria-selected="material === m.key" @click="selectMaterial(m.key)">
        <span class="long">{{ m.label }}</span><span class="shortl">{{ m.short }}</span>
      </button>
    </div>
    <!-- スクランブルの問題集 -->
    <div v-if="material === 'scramble'" v-seg class="tabs" role="tablist" aria-label="問題集">
      <button v-for="b in BOOKS" :key="b.key" class="tab" :class="{ on: book === b.key }" role="tab" :aria-selected="book === b.key" @click="selectBook(b.key)">{{ b.label }}</button>
    </div>

    <div v-if="stat" class="stat-row">
      <span class="st where">{{ mode === 'chapter' ? `${index[chapterIdx]?.label ?? ''} ${index[chapterIdx]?.title ?? ''}` : bookLabel }}</span>
      <span class="st"><b>{{ stat.total }}</b>問</span>
      <span class="st ok"><i class="sym ok"></i><b>{{ stat.correct }}</b></span>
      <span class="st un"><i class="sym un"></i><b>{{ stat.unsure }}</b></span>
      <span class="st ng"><i class="sym ng"></i><b>{{ stat.incorrect }}</b></span>
      <span class="st none">未回答 <b>{{ stat.total - stat.correct - stat.unsure - stat.incorrect }}</b></span>
      <span class="st faint">※最新の結果で集計</span>
    </div>

    <div class="body">
      <!-- 分野（章）の索引 -->
      <aside v-if="index.length" class="index" aria-label="分野">
        <button v-for="it in index" :key="it.i" class="ix" :class="{ on: it.i === chapterIdx }" @click="selectChapter(it.i)">
          <span class="ix-l">{{ it.label }}</span>
          <span class="ix-t">{{ it.title }}</span>
          <span class="ix-n">
            <span v-if="it.ng" class="ng">×{{ it.ng }}</span>
            <span class="tot">{{ it.total }}</span>
          </span>
        </button>
      </aside>

      <div class="frame-wrap">
        <div v-if="loading" class="hint">読み込み中…</div>
        <iframe v-else-if="html" ref="frame" class="frame" :srcdoc="html" :title="`${bookLabel} チェックシート`"></iframe>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sheet-view {
  display: flex;
  flex-direction: column;
  /* 画面の高さいっぱいに使い、シートは内側でスクロールする */
  min-height: calc(100vh - 140px);
  min-height: calc(100dvh - 140px);
}
.head-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.btn-outline {
  padding: 7px 14px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
  white-space: nowrap;
}
.btn-outline:disabled {
  opacity: 0.5;
  cursor: default;
}
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
.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  background: #fff;
  border: 1px solid #e3e6ea;
  border-radius: 12px;
  padding: 3px;
  margin-bottom: 8px;
}
.tabs.mat .tab {
  color: var(--ink);
}
.tabs.mat .tab.on {
  background: #d0266f;
}
.tab {
  flex: 1 1 auto;
  min-height: 38px;
  padding: 6px 12px;
  border: none;
  border-radius: 9px;
  background: transparent;
  color: var(--mut);
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
}
.tab.on {
  background: #1c2024;
  color: #fff;
}
.tab .shortl {
  display: none;
}
.stat-row {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
  font-size: 12.5px;
  color: var(--mut);
  margin: 2px 0 10px;
  padding: 0 4px;
}
.st {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.st.where {
  color: var(--ink);
  font-weight: 700;
}
.st b {
  font-size: 15px;
  margin: 0 2px;
}
.st.ok b {
  color: #1c2024;
}
.st.un b {
  color: #1f5fd0;
}
.st.ng b {
  color: #d8232a;
}
.st.none b {
  color: #9aa1ab;
}
.st.faint {
  font-size: 11px;
  color: var(--faint);
}
/* 集計の ○△× も太い線で */
.sym {
  display: inline-block;
  width: 13px;
  height: 13px;
  flex-shrink: 0;
}
.sym.ok {
  border: 2.2px solid #1c2024;
  border-radius: 50%;
}
.sym.un {
  width: 0;
  height: 0;
  border-left: 7px solid transparent;
  border-right: 7px solid transparent;
  border-bottom: 12px solid #1f5fd0;
  position: relative;
}
.sym.un::after {
  content: '';
  position: absolute;
  left: -3.6px;
  top: 4.2px;
  border-left: 3.6px solid transparent;
  border-right: 3.6px solid transparent;
  border-bottom: 6.4px solid #fff;
}
.sym.ng {
  position: relative;
}
.sym.ng::before,
.sym.ng::after {
  content: '';
  position: absolute;
  left: 5.5px;
  top: -1px;
  width: 2.4px;
  height: 15px;
  border-radius: 2px;
  background: #d8232a;
  transform: rotate(45deg);
}
.sym.ng::after {
  transform: rotate(-45deg);
}
.body {
  flex: 1;
  min-height: 480px;
  display: flex;
  gap: 10px;
  align-items: stretch;
}
.index {
  width: 220px;
  flex-shrink: 0;
  background: #fff;
  border: 1px solid #e3e6ea;
  border-radius: 14px;
  padding: 6px;
  overflow-y: auto;
  max-height: calc(100dvh - 250px);
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.ix {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: baseline;
  gap: 0 6px;
  width: 100%;
  padding: 7px 9px;
  border: none;
  border-radius: 9px;
  background: transparent;
  text-align: left;
  font-family: inherit;
  cursor: pointer;
  color: var(--ink);
}
.ix:hover {
  background: #f4f5f7;
}
.ix.on {
  background: #1c2024;
  color: #fff;
}
.ix-l {
  font-size: 10.5px;
  font-weight: 700;
  color: var(--mut);
  white-space: nowrap;
}
.ix.on .ix-l {
  color: #cdd3dc;
}
.ix-t {
  font-size: 12.5px;
  font-weight: 700;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}
.ix-n {
  display: inline-flex;
  gap: 6px;
  font-size: 10.5px;
  white-space: nowrap;
}
.ix-n .tot {
  color: var(--faint);
}
.ix.on .ix-n .tot {
  color: #cdd3dc;
}
.ix-n .ng {
  color: #d8232a;
  font-weight: 800;
}
.ix.on .ix-n .ng {
  color: #ff8c95;
}
.frame-wrap {
  flex: 1;
  min-width: 0;
  min-height: 480px;
  background: #e6e8ec;
  border: 1px solid #e3e6ea;
  border-radius: 14px;
  overflow: hidden;
  display: flex;
}
.frame {
  flex: 1;
  width: 100%;
  min-height: 480px;
  border: none;
  background: #e6e8ec;
}
.hint {
  padding: 40px;
  text-align: center;
  color: var(--faint);
  font-size: 13px;
  flex: 1;
}
@media (max-width: 760px) {
  .tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }
  .tab .long {
    display: none;
  }
  .tab .shortl {
    display: inline;
  }
  .sheet-view {
    min-height: calc(100vh - 110px);
    min-height: calc(100dvh - 110px);
  }
  /* 狭い画面では索引を横に並べてスクロール */
  .body {
    flex-direction: column;
  }
  .index {
    width: 100%;
    max-height: none;
    flex-direction: row;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 5px;
  }
  .ix {
    width: auto;
    flex-shrink: 0;
    grid-template-columns: auto auto;
    grid-template-rows: auto auto;
    padding: 6px 10px;
  }
  .ix-l {
    grid-column: 1;
    grid-row: 1;
  }
  .ix-n {
    grid-column: 2;
    grid-row: 1;
    justify-self: end;
  }
  .ix-t {
    grid-column: 1 / -1;
    grid-row: 2;
    max-width: 160px;
  }
}
</style>
