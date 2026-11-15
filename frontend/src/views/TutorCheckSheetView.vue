<script setup lang="ts">
/**
 * 講師用: スクランブル英文法・語法のチェックシートを画面上で表示する。
 * 生徒側の出力（解いた日付）と違い、回数欄は ○（正解・黒）△（あいまい・青）×（不正解・赤）で表す（日付はツールチップ）。
 * 文法／語法／語い／イディオム／会話表現／発音・アクセント／読解・作文の文法 を上のタブで切り替える。
 * シートの HTML は生徒側と同じ部品（lib/checkSheet.ts）で組み、iframe に入れて表示する（印刷は iframe の内容を印刷）。
 */
import { computed, onMounted, ref, watch } from 'vue'
import HelpTip from '@/components/HelpTip.vue'
import { grammarApi } from '@/api/grammar'
import { buildCheckSheetHtml } from '@/lib/checkSheet'
import { buildBookSheetSpec, buildGrammarSheetSpec, type GrammarMarks } from '@/lib/grammarSheet'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import type { GrammarBookKey, GrammarQuestion } from '@/types'

const ui = useUiStore()
const auth = useAuthStore()

const BOOKS: { key: GrammarBookKey; label: string }[] = [
  { key: 'grammar', label: '文法' },
  { key: 'usage', label: '語法' },
  { key: 'vocab', label: '語い' },
  { key: 'idiom', label: 'イディオム' },
  { key: 'conversation', label: '会話表現' },
  { key: 'pronunciation', label: '発音・アクセント' },
  { key: 'special', label: '読解・作文の文法' },
]
const KEY = 'sm_tutor_sheet_book'
const saved = localStorage.getItem(KEY) as GrammarBookKey | null
const book = ref<GrammarBookKey>(saved && BOOKS.some((b) => b.key === saved) ? saved : 'grammar')
const loading = ref(false)
const html = ref('')
const totals = ref<Record<string, { total: number; correct: number; incorrect: number; unsure: number }>>({})
const frame = ref<HTMLIFrameElement | null>(null)

/** 取得した結果のキャッシュ（問題集ごと） */
const marksCache = new Map<GrammarBookKey, GrammarMarks>()
const questionsCache = new Map<GrammarBookKey, GrammarQuestion[]>()

const studentName = computed(() => auth.user?.student?.name ?? '')
const bookLabel = computed(() => BOOKS.find((b) => b.key === book.value)?.label ?? '')

async function load() {
  loading.value = true
  try {
    const key = book.value
    let marks = marksCache.get(key)
    if (!marks) {
      marks = await grammarApi.checksheet(key === 'grammar' || key === 'usage' ? undefined : key)
      marksCache.set(key, marks)
    }
    const d = new Date()
    const date = `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`
    const extra = { markMode: 'symbol' as const, noToolbar: true, note: `${date} 時点` }
    let spec
    if (key === 'grammar' || key === 'usage') {
      spec = buildGrammarSheetSpec(marks, studentName.value, date, key, extra)
    } else {
      let qs = questionsCache.get(key)
      if (!qs) {
        qs = await grammarApi.questions(key)
        questionsCache.set(key, qs)
      }
      spec = buildBookSheetSpec(key, bookLabel.value, qs, marks, studentName.value, date, extra)
    }
    // 集計（この問題集の問題のうち、最新の結果が ○／△／×）
    let total = 0
    let correct = 0
    let incorrect = 0
    let unsure = 0
    for (const ch of spec.chapters) {
      for (const r of ch.rows) {
        total++
        const last = r.marks[r.marks.length - 1]
        if (!last) continue
        if (last.status === 0) incorrect++
        else if (last.status === 2) unsure++
        else correct++
      }
    }
    totals.value = { ...totals.value, [key]: { total, correct, incorrect, unsure } }
    html.value = buildCheckSheetHtml(spec)
  } catch {
    ui.notify('チェックシートの取得に失敗しました')
  } finally {
    loading.value = false
  }
}

function select(k: GrammarBookKey) {
  if (book.value === k) return
  book.value = k
  localStorage.setItem(KEY, k)
}
watch(book, load)
onMounted(load)

function print() {
  frame.value?.contentWindow?.print()
}
async function reload() {
  marksCache.delete(book.value)
  await load()
}
const stat = computed(() => totals.value[book.value])
</script>

<template>
  <div class="sheet-view">
    <div class="head-row">
      <div style="display: flex; align-items: center; gap: 8px; min-width: 0">
        <div style="font-size: 17px; font-weight: 700; white-space: nowrap">スクランブル チェックシート</div>
        <HelpTip text="担当生徒のスクランブル英文法・語法の解答結果を、教材付属のチェックシートと同じ並び（章・Theme・連番）で表示します。&#10;回数欄は解いた日ごとに ○（正解・黒）△（あいまい・青。正解だったが自信がない）×（不正解・赤）。同じ日に何度か解いたときは 1 日 1 つにまとめ、1 度でも不正解なら × になります。日付はマークにカーソルを合わせると表示されます。&#10;上のタブで 文法／語法／語い／イディオム／会話表現／発音・アクセント／読解・作文の文法 を切り替えます。" />
      </div>
      <div style="display: flex; align-items: center; gap: 8px">
        <button class="btn-outline" :disabled="loading" @click="reload">更新</button>
        <button class="btn-outline" :disabled="!html" @click="print">印刷 / PDF</button>
      </div>
    </div>

    <div v-seg class="tabs" role="tablist" aria-label="問題集">
      <button v-for="b in BOOKS" :key="b.key" class="tab" :class="{ on: book === b.key }" role="tab" :aria-selected="book === b.key" @click="select(b.key)">{{ b.label }}</button>
    </div>

    <div v-if="stat" class="stat-row">
      <span class="st"><b>{{ stat.total }}</b>問</span>
      <span class="st ok">○ <b>{{ stat.correct }}</b></span>
      <span class="st un">△ <b>{{ stat.unsure }}</b></span>
      <span class="st ng">× <b>{{ stat.incorrect }}</b></span>
      <span class="st none">未回答 <b>{{ stat.total - stat.correct - stat.unsure - stat.incorrect }}</b></span>
      <span class="st faint">※最新の結果で集計</span>
    </div>

    <div class="frame-wrap">
      <div v-if="loading" class="hint">読み込み中…</div>
      <iframe v-else-if="html" ref="frame" class="frame" :srcdoc="html" :title="`${bookLabel} チェックシート`"></iframe>
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
.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  background: #fff;
  border: 1px solid #e3e6ea;
  border-radius: 12px;
  padding: 3px;
  margin-bottom: 10px;
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
.stat-row {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
  font-size: 12.5px;
  color: var(--mut);
  margin-bottom: 10px;
  padding: 0 4px;
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
.frame-wrap {
  flex: 1;
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
@media (max-width: 600px) {
  .tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }
  .sheet-view {
    min-height: calc(100vh - 110px);
    min-height: calc(100dvh - 110px);
  }
}
</style>
