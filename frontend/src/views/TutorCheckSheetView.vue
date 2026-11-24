<script setup lang="ts">
/**
 * 講師用: チェックシートを画面上で表示する。
 * 画面に出すのは 集計行・左の索引（上に 分野別／まとめ の切替）・シート だけ。
 * 科目 → 教材 → 問題集 の切り替えは、右下の丸いボタンを押すと出るパネルで行う（ふだんは隠す。選択は記憶）。
 * いまは 英語 → スクランブル（文法／語法／語い／イディオム／会話表現／発音・アクセント／読解・作文の文法）のみ。
 * 生徒側の出力（解いた日付）と違い、回数欄は ○（正解・黒）△（あいまい・青）×（不正解・赤）で表す（日付は出さない）。
 * 表示形式は 2 つ（記憶する。既定は分野別）:
 *   - 分野別: 索引で章（分野）を選ぶと、その章だけを表示する
 *   - まとめ: 全章を 1 枚のシートとして表示。索引を押すとその章まで送る
 * シートの HTML は生徒側と同じ部品（lib/checkSheet.ts）で組み、iframe に入れて表示する。
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { grammarApi } from '@/api/grammar'
import { attachRecordMarks, buildCheckSheetHtml, type SheetChapter, type SheetMarks, type SheetSpec } from '@/lib/checkSheet'
import { buildBookSheetSpec, buildGrammarSheetSpec } from '@/lib/grammarSheet'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import type { GrammarBookKey, GrammarQuestion } from '@/types'

const ui = useUiStore()
const auth = useAuthStore()

interface BookDef {
  key: GrammarBookKey
  label: string
}
interface MaterialDef {
  key: string
  label: string
  books: BookDef[]
}
interface SubjectDef {
  key: string
  label: string
  materials: MaterialDef[]
}
const SCRAMBLE_BOOKS: BookDef[] = [
  { key: 'grammar', label: '文法' },
  { key: 'usage', label: '語法' },
  { key: 'vocab', label: '語い' },
  { key: 'idiom', label: 'イディオム' },
  { key: 'conversation', label: '会話表現' },
  { key: 'pronunciation', label: '発音・アクセント' },
  { key: 'special', label: '読解・作文の文法' },
]
/** 科目 → 教材 → 問題集。教材を増やすときはここに足す */
const SUBJECTS: SubjectDef[] = [{ key: 'english', label: '英語', materials: [{ key: 'scramble', label: 'スクランブル', books: SCRAMBLE_BOOKS }] }]
type Mode = 'chapter' | 'all'

// 選択の記憶
const KEY_SUBJECT = 'sm_tutor_sheet_subject'
const KEY_MATERIAL = 'sm_tutor_sheet_material'
const KEY_BOOK = 'sm_tutor_sheet_book'
const KEY_MODE = 'sm_tutor_sheet_mode'
const subject = ref<SubjectDef>(SUBJECTS.find((s) => s.key === localStorage.getItem(KEY_SUBJECT)) ?? SUBJECTS[0]!)
const material = ref<MaterialDef>(subject.value.materials.find((m) => m.key === localStorage.getItem(KEY_MATERIAL)) ?? subject.value.materials[0]!)
const book = ref<BookDef>(material.value.books.find((b) => b.key === localStorage.getItem(KEY_BOOK)) ?? material.value.books[0]!)
const mode = ref<Mode>(localStorage.getItem(KEY_MODE) === 'all' ? 'all' : 'chapter')
/** 右下のボタンで出す切り替えパネル */
const pickerOpen = ref(false)

const loading = ref(false)
/** 今の問題集の全章のシート */
const spec = ref<SheetSpec | null>(null)
const frame = ref<HTMLIFrameElement | null>(null)

/** 取得した結果のキャッシュ（問題集ごと） */
const marksCache = new Map<GrammarBookKey, SheetMarks>()
const questionsCache = new Map<GrammarBookKey, GrammarQuestion[]>()
/** 分野別で最後に見ていた章（問題集ごと） */
const chapterMemory = new Map<GrammarBookKey, number>()
const chapterIdx = ref(0)

const studentName = computed(() => auth.user?.student?.name ?? '')
const sheetKey = computed<GrammarBookKey>(() => book.value.key)
const bookLabel = computed(() => `${material.value.label} ${book.value.label}`)

async function load() {
  loading.value = true
  const key = sheetKey.value
  try {
    let data = marksCache.get(key)
    if (!data) {
      data = await grammarApi.checksheet(key === 'grammar' || key === 'usage' ? undefined : key)
      marksCache.set(key, data)
    }
    const marks = data.marks
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
      built = buildBookSheetSpec(key, book.value.label, qs, marks, studentName.value, date, extra)
    }
    if (sheetKey.value !== key) return // 読んでいる間に切り替わった
    spec.value = attachRecordMarks(built, data) // 生徒が個別学習データで章単位に登録した学習記録は章の見出しに
    chapterIdx.value = Math.min(chapterMemory.get(key) ?? 0, Math.max(0, built.chapters.length - 1))
  } catch {
    ui.notify('チェックシートの取得に失敗しました')
  } finally {
    if (sheetKey.value === key) loading.value = false
  }
}

function selectSubject(s: SubjectDef) {
  if (subject.value.key === s.key) return
  subject.value = s
  localStorage.setItem(KEY_SUBJECT, s.key)
  selectMaterial(s.materials[0]!, true)
}
function selectMaterial(m: MaterialDef, force = false) {
  if (!force && material.value.key === m.key) return
  material.value = m
  localStorage.setItem(KEY_MATERIAL, m.key)
  selectBook(m.books[0]!, true)
}
function selectBook(b: BookDef, force = false) {
  if (!force && book.value.key === b.key) return
  book.value = b
  localStorage.setItem(KEY_BOOK, b.key)
  pickerOpen.value = false
}
function selectMode(m: Mode) {
  if (mode.value === m) return
  mode.value = m
  localStorage.setItem(KEY_MODE, m)
}
watch(sheetKey, load)
onMounted(load)

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape' && pickerOpen.value) pickerOpen.value = false
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

/** 索引: 章ごとの問数と、最新の結果が × の数 */
const index = computed(() =>
  (spec.value?.chapters ?? []).map((ch, i) => {
    let ng = 0
    for (const r of ch.rows) {
      const last = r.marks[r.marks.length - 1]
      if (last && last.status === 0) ng++
    }
    return { i, label: ch.label ?? '', title: ch.title, total: ch.rows.length, ng }
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
</script>

<template>
  <div class="sheet-view">
    <div v-if="stat" class="stat-row">
      <span class="st where">{{ mode === 'chapter' ? `${index[chapterIdx]?.label ?? ''} ${index[chapterIdx]?.title ?? ''}` : bookLabel }}</span>
      <span class="st"><b>{{ stat.total }}</b>問</span>
      <span class="st ok"><i class="sym ok"></i><b>{{ stat.correct }}</b></span>
      <span class="st un"><i class="sym un"></i><b>{{ stat.unsure }}</b></span>
      <span class="st ng"><i class="sym ng"></i><b>{{ stat.incorrect }}</b></span>
      <span class="st none">未回答 <b>{{ stat.total - stat.correct - stat.unsure - stat.incorrect }}</b></span>
    </div>

    <div class="body">
      <!-- 左: 表示形式と分野（章）の索引 -->
      <aside class="index" aria-label="分野">
        <div v-seg class="seg" role="radiogroup" aria-label="表示形式">
          <button :class="{ on: mode === 'chapter' }" role="radio" :aria-checked="mode === 'chapter'" @click="selectMode('chapter')">分野別</button>
          <button :class="{ on: mode === 'all' }" role="radio" :aria-checked="mode === 'all'" @click="selectMode('all')">まとめ</button>
        </div>
        <div class="ix-list">
          <button v-for="it in index" :key="it.i" class="ix" :class="{ on: it.i === chapterIdx }" @click="selectChapter(it.i)">
            <span class="ix-l">{{ it.label }}</span>
            <span class="ix-t">{{ it.title }}</span>
            <span class="ix-n">
              <span v-if="it.ng" class="ng">×{{ it.ng }}</span>
              <span class="tot">{{ it.total }}</span>
            </span>
          </button>
        </div>
      </aside>

      <div class="frame-wrap">
        <div v-if="loading" class="hint">読み込み中…</div>
        <iframe v-else-if="html" ref="frame" class="frame" :srcdoc="html" :title="`${bookLabel} チェックシート`"></iframe>
      </div>
    </div>

    <!-- 右下: 科目・教材・問題集の切り替え（ふだんは丸いボタンだけ） -->
    <div v-if="pickerOpen" class="picker-scrim" @click="pickerOpen = false"></div>
    <Transition name="pick">
      <div v-if="pickerOpen" class="picker" role="dialog" aria-label="科目・教材の切り替え">
        <div class="pk-row">
          <div class="pk-h">科目</div>
          <div class="pk-chips">
            <button v-for="s in SUBJECTS" :key="s.key" class="chip" :class="{ on: subject.key === s.key }" @click="selectSubject(s)">{{ s.label }}</button>
          </div>
        </div>
        <div class="pk-row">
          <div class="pk-h">教材</div>
          <div class="pk-chips">
            <button v-for="m in subject.materials" :key="m.key" class="chip" :class="{ on: material.key === m.key }" @click="selectMaterial(m)">{{ m.label }}</button>
          </div>
        </div>
        <div class="pk-row">
          <div class="pk-h">問題集</div>
          <div class="pk-chips">
            <button v-for="b in material.books" :key="b.key" class="chip" :class="{ on: book.key === b.key }" @click="selectBook(b)">{{ b.label }}</button>
          </div>
        </div>
      </div>
    </Transition>
    <button class="fab" :class="{ on: pickerOpen }" :title="`${subject.label}・${bookLabel}（科目・教材を切り替え）`" aria-label="科目・教材を切り替え" @click="pickerOpen = !pickerOpen">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M4 6h16" /><path d="M4 12h16" /><path d="M4 18h16" />
        <circle cx="9" cy="6" r="2" fill="currentColor" /><circle cx="15" cy="12" r="2" fill="currentColor" /><circle cx="11" cy="18" r="2" fill="currentColor" />
      </svg>
    </button>
  </div>
</template>

<style scoped>
.sheet-view {
  display: flex;
  flex-direction: column;
  /* 見出しを無くしたぶん上に詰める。シートは内側でスクロールする */
  margin-top: -10px;
  min-height: calc(100vh - 120px);
  min-height: calc(100dvh - 120px);
}
.stat-row {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
  font-size: 12.5px;
  color: var(--mut);
  margin: 0 0 8px;
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
  max-height: calc(100dvh - 170px);
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.seg {
  display: flex;
  flex-shrink: 0;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  overflow: hidden;
  background: #fff;
}
.seg button {
  flex: 1;
  padding: 7px 10px;
  border: none;
  background: #fff;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--mut);
  cursor: pointer;
}
.seg button.on {
  background: #1c2024;
  color: #fff;
}
.ix-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
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
/* 右下の丸いボタンと切り替えパネル */
.fab {
  position: fixed;
  right: max(18px, env(safe-area-inset-right));
  bottom: max(18px, env(safe-area-inset-bottom));
  width: 52px;
  height: 52px;
  border: none;
  border-radius: 50%;
  background: #1c2024;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
  cursor: pointer;
  z-index: 60;
}
.fab.on {
  background: #3b50cc;
}
.picker-scrim {
  position: fixed;
  inset: 0;
  z-index: 58;
}
.picker {
  position: fixed;
  right: max(18px, env(safe-area-inset-right));
  bottom: calc(max(18px, env(safe-area-inset-bottom)) + 62px);
  width: min(360px, calc(100vw - 36px));
  background: #fff;
  border: 1px solid #e3e6ea;
  border-radius: 16px;
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.2);
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  z-index: 59;
}
.pk-row {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.pk-h {
  font-size: 11px;
  font-weight: 700;
  color: var(--faint);
}
.pk-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.chip {
  padding: 7px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 999px;
  background: #fff;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ink);
  cursor: pointer;
}
.chip.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.pick-enter-active,
.pick-leave-active {
  transition: opacity 0.16s ease, transform 0.16s ease;
}
.pick-enter-from,
.pick-leave-to {
  opacity: 0;
  transform: translateY(8px) scale(0.98);
}
@media (max-width: 760px) {
  .sheet-view {
    margin-top: -4px;
    min-height: calc(100vh - 100px);
    min-height: calc(100dvh - 100px);
  }
  /* 狭い画面では索引を横に並べてスクロール */
  .body {
    flex-direction: column;
  }
  .index {
    width: 100%;
    max-height: none;
    padding: 5px;
  }
  .ix-list {
    flex-direction: row;
    overflow-x: auto;
    overflow-y: hidden;
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
