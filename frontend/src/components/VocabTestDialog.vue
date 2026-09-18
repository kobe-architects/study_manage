<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { quizApi } from '@/api/quiz'
import { TEST_FORMAT_LABEL, TEST_TYPE_LABEL, VOCAB_PER_PAGE, buildTestWords } from '@/lib/vocabTest'
import { shuffle } from '@/lib/design'
import { useUiStore } from '@/stores/ui'
import type { PrintTestFormat, PrintTestType, QuizPageSpec, StudyResource, Vocabulary } from '@/types'

/**
 * 講師の小テストに追加する「英単語テスト」の設定ダイアログ。
 * 単語帳 → 出題範囲（単語帳の通し番号 No.）→ 出題数・形式 を選ぶと、1枚あたりの上限で複数ページに分割して返す。
 */
const emit = defineEmits<{ close: []; add: [pages: QuizPageSpec[]] }>()
const ui = useUiStore()

const resources = ref<StudyResource[]>([])
const loading = ref(true)
const words = ref<Vocabulary[]>([])
const loadingWords = ref(false)

const form = reactive<{ resourceId: number | null; from: number | null; to: number | null; count: number; type: PrintTestType; format: PrintTestFormat; order: 'random' | 'ordered'; unstudiedFirst: boolean }>({
  resourceId: null,
  from: 1,
  to: 20,
  count: 20,
  type: 'meaning',
  format: 'free',
  order: 'ordered', // デフォルトは単語帳の順番
  unstudiedFirst: false,
})

onMounted(async () => {
  try {
    resources.value = await quizApi.resources()
    if (resources.value[0]) await selectResource(resources.value[0].id)
  } catch {
    ui.notify('単語帳の取得に失敗しました')
  } finally {
    loading.value = false
  }
})

const resource = computed(() => resources.value.find((r) => r.id === form.resourceId) ?? null)
/** セクション ID → セクション名（範囲に含まれるセクションの表示用） */
const sectionNameOf = computed(() => {
  const m: Record<number, string> = {}
  for (const sec of resource.value?.sections ?? []) m[sec.id] = sec.name
  return m
})

async function selectResource(id: number) {
  form.resourceId = id
  loadingWords.value = true
  try {
    // 一覧 API はセクション順 → 並び順で返るので、配列位置 + 1 が単語帳の通し番号（No.）
    words.value = await quizApi.vocabularies(id)
    setRange(weekBlockStart(words.value.length), BLOCK)
  } catch {
    ui.notify('単語の取得に失敗しました')
    words.value = []
  } finally {
    loadingWords.value = false
  }
}

/** 1週間の出題ブロックの語数。毎週木曜日に次のブロックへ進む */
const BLOCK = 140
/** 基準となる木曜日（この週が No.1〜140）。2026-09-17（木）の週が 141〜280 になるよう 2026-09-10 を基準にする */
const BLOCK_EPOCH = new Date(2026, 8, 10)
/** 今日が属する週（木曜始まり）の木曜日 0:00 */
function thursdayOf(d: Date): Date {
  const t = new Date(d.getFullYear(), d.getMonth(), d.getDate())
  t.setDate(t.getDate() - ((t.getDay() + 7 - 4) % 7))
  return t
}
/** 今週のブロックの開始番号。単語帳の末尾を超えたら先頭に戻る */
function weekBlockStart(total: number): number {
  if (total <= BLOCK) return 1
  const weeks = Math.round((thursdayOf(new Date()).getTime() - BLOCK_EPOCH.getTime()) / 604800000)
  const blocks = Math.ceil(total / BLOCK)
  const idx = ((weeks % blocks) + blocks) % blocks
  return idx * BLOCK + 1
}
const thisWeekFrom = computed(() => weekBlockStart(words.value.length))

/** 有効な番号範囲（1 〜 語数、from <= to） */
const range = computed<{ from: number; to: number } | null>(() => {
  const n = words.value.length
  const f = Number(form.from)
  const t = Number(form.to)
  if (!n || !Number.isInteger(f) || !Number.isInteger(t) || f < 1 || t < f) return null
  return { from: f, to: Math.min(t, n) }
})
const rangeWords = computed(() => (range.value ? words.value.slice(range.value.from - 1, range.value.to) : []))
const candidates = computed(() => rangeWords.value.filter((w) => form.type !== 'fill_spelling' || !!w.exampleSentence))
const perPage = computed(() => VOCAB_PER_PAGE[form.format][form.type])
/** 出題数の上限（用紙 8 枚分） */
const maxCount = computed(() => perPage.value * 8)
const effectiveCount = computed(() => Math.min(form.count, candidates.value.length, maxCount.value))
const pageCount = computed(() => Math.ceil(effectiveCount.value / perPage.value))
/** 範囲内の単語が属するセクション名（重複なし） */
const rangeSectionNames = computed(() =>
  Array.from(new Set(rangeWords.value.map((w) => sectionNameOf.value[w.sectionId]).filter((x): x is string => !!x))),
)

// 番号範囲を変えたら、その語数を出題数にする（生徒側の「番号で指定」と同じ挙動）
watch(range, (r) => {
  if (r) form.count = Math.min(r.to - r.from + 1, maxCount.value)
})
watch(
  () => [form.format, form.type],
  () => {
    if (form.count > maxCount.value) form.count = maxCount.value
  },
)
function setRange(from: number, size: number) {
  form.from = Math.max(1, Math.min(from, Math.max(1, words.value.length)))
  form.to = Math.min(words.value.length, form.from + size - 1)
}

function add() {
  if (!resource.value) return
  if (!effectiveCount.value) {
    ui.notify('出題できる単語がありません（番号の範囲を確認してください）')
    return
  }
  let pool = [...candidates.value]
  if (form.unstudiedFirst) {
    // 未学習・習熟度の低い語を優先
    const rank = (w: Vocabulary) => (w.learningStat ? (w.proficiency === 'high' ? 2 : w.proficiency === 'medium' ? 1 : 0) : -1)
    pool.sort((a, b) => rank(a) - rank(b))
    pool = [...pool.slice(0, effectiveCount.value * 2)]
  }
  const picked = (form.order === 'random' ? shuffle(pool) : pool).slice(0, effectiveCount.value)
  const ordered = form.order === 'random' ? picked : picked.sort((a, b) => a.sectionId - b.sectionId || a.sortOrder - b.sortOrder)
  const testWords = buildTestWords(ordered, words.value, form.type, form.format)
  const rangeLabel = range.value ? `No.${range.value.from}〜${range.value.to}` : ''
  const sectionNames = [rangeLabel, ...rangeSectionNames.value]
  const pages: QuizPageSpec[] = []
  for (let i = 0; i < testWords.length; i += perPage.value) {
    const chunk = testWords.slice(i, i + perPage.value)
    const pageNo = Math.floor(i / perPage.value) + 1
    pages.push({
      kind: 'vocab',
      label: `英単語テスト（${resource.value.name} ${rangeLabel}・${chunk.length}問${pageCount.value > 1 ? ` ${pageNo}/${pageCount.value}` : ''}）`,
      vocab: {
        resourceId: resource.value.id,
        resourceName: resource.value.name,
        sectionNames,
        testType: form.type,
        testFormat: form.format,
        words: chunk,
      },
    })
  }
  emit('add', pages)
}
</script>

<template>
  <div class="overlay" @click="emit('close')">
    <div class="modal" @click.stop>
      <div class="head">
        <div style="font-size: 15px; font-weight: 700">英単語テストを追加</div>
        <button class="x" @click="emit('close')">×</button>
      </div>
      <div v-if="loading" class="hint">読み込み中…</div>
      <div v-else-if="!resources.length" class="hint">単語帳がありません。</div>
      <template v-else>
        <div class="lab">単語帳</div>
        <div class="seg">
          <button v-for="r in resources" :key="r.id" :class="{ on: form.resourceId === r.id }" @click="selectResource(r.id)">{{ r.name }}<span class="cnt">{{ r.wordCount }}語</span></button>
        </div>

        <div class="lab" style="display: flex; align-items: center; gap: 10px">
          出題範囲（単語帳の番号 No.）
          <span style="margin-left: auto; font-weight: 400; color: var(--faint)">全 {{ words.length }}語・初期値は今週の{{ BLOCK }}語（毎週木曜日に次へ進む）</span>
        </div>
        <div class="range-row">
          <span class="rl">No.</span>
          <input v-model.number="form.from" type="number" min="1" :max="words.length" class="rng" :disabled="loadingWords" />
          <span class="rl">〜</span>
          <input v-model.number="form.to" type="number" min="1" :max="words.length" class="rng" :disabled="loadingWords" />
          <span class="rl" style="color: var(--faint)">{{ range ? range.to - range.from + 1 + '語' : '範囲が不正です' }}</span>
          <span class="quick">
            <button class="link" :disabled="!range || range.from <= 1" @click="setRange((range?.from ?? 1) - BLOCK, BLOCK)">‹ 前の{{ BLOCK }}語</button>
            <button class="link" @click="setRange(thisWeekFrom, BLOCK)">今週（No.{{ thisWeekFrom }}〜{{ Math.min(thisWeekFrom + BLOCK - 1, words.length) }}）</button>
            <button class="link" :disabled="!range || range.to >= words.length" @click="setRange((range?.to ?? 0) + 1, BLOCK)">次の{{ BLOCK }}語 ›</button>
          </span>
        </div>
        <div v-if="range && rangeWords.length" class="range-note">
          <b>No.{{ range.from }} {{ rangeWords[0]!.word }}</b> 〜 <b>No.{{ range.to }} {{ rangeWords[rangeWords.length - 1]!.word }}</b>
          <span v-if="rangeSectionNames.length" style="color: var(--faint)">（{{ rangeSectionNames.slice(0, 3).join('、') }}{{ rangeSectionNames.length > 3 ? ' 他' : '' }}）</span>
        </div>

        <div class="grid">
          <label class="fld"><span>出題形式</span>
            <select v-model="form.type">
              <option v-for="(l, k) in TEST_TYPE_LABEL" :key="k" :value="k">{{ l }}</option>
            </select>
          </label>
          <label class="fld"><span>回答方式</span>
            <select v-model="form.format">
              <option v-for="(l, k) in TEST_FORMAT_LABEL" :key="k" :value="k">{{ l }}</option>
            </select>
          </label>
          <label class="fld"><span>出題数（1枚 {{ perPage }}問まで・最大 {{ maxCount }}問）</span>
            <input v-model.number="form.count" type="number" min="1" :max="maxCount" />
          </label>
          <label class="fld"><span>出題順</span>
            <select v-model="form.order">
              <option value="random">ランダム</option>
              <option value="ordered">単語帳の順番</option>
            </select>
          </label>
        </div>
        <label class="chk"><input v-model="form.unstudiedFirst" type="checkbox" /> 未学習・習熟度の低い語を優先する</label>

        <div class="summary">
          <b>{{ effectiveCount }}問</b> を <b>{{ pageCount }}枚</b> の用紙に出題。
          <span v-if="form.type === 'fill_spelling'" style="color: var(--faint)">例文のある単語のみ対象</span>
        </div>
      </template>
      <div class="foot">
        <button class="btn-ghost" @click="emit('close')">キャンセル</button>
        <button class="btn-dark" :disabled="loading || loadingWords || !effectiveCount" @click="add">追加する</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.range-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 6px;
}
.range-row .rl {
  font-size: 12.5px;
  color: var(--mut);
}
.range-row .rng {
  width: 84px;
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 14px;
  font-weight: 700;
  text-align: right;
}
.range-row .quick {
  display: flex;
  gap: 10px;
  margin-left: auto;
}
.range-row .quick button:disabled {
  opacity: 0.35;
  cursor: default;
}
.range-note {
  font-size: 12px;
  color: var(--mut);
  margin-bottom: 10px;
}
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 70;
  padding: 16px;
}
.modal {
  background: #fff;
  border-radius: 16px;
  padding: 20px 22px;
  width: 100%;
  max-width: 640px;
  max-height: 92vh;
  overflow-y: auto;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}
.x {
  border: none;
  background: transparent;
  font-size: 22px;
  color: #9aa1ab;
  cursor: pointer;
}
.hint {
  font-size: 12.5px;
  color: var(--faint);
  padding: 16px;
  text-align: center;
}
.lab {
  font-size: 12px;
  color: var(--mut);
  font-weight: 600;
  margin: 12px 0 6px;
}
.seg {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.seg button,
.grp,
.chip {
  padding: 6px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 999px;
  background: #fff;
  font-size: 12px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.seg button.on,
.grp.on,
.chip.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.cnt {
  font-size: 10px;
  font-weight: 500;
  opacity: 0.7;
}
.groups {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 6px;
}
.grp {
  border-radius: 8px;
  background: #f6f7f9;
}
.chips {
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
  max-height: 180px;
  overflow-y: auto;
  padding: 4px 0;
}
.chip {
  padding: 4px 10px;
  font-size: 11.5px;
}
.link {
  border: none;
  background: none;
  color: #3b50cc;
  font-size: 11.5px;
  cursor: pointer;
  padding: 0;
}
.grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px 12px;
  margin-top: 12px;
}
.fld span {
  font-size: 11.5px;
  color: var(--mut);
  display: block;
  margin-bottom: 4px;
}
.fld input,
.fld select {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 13px;
  background: #fff;
}
.chk {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--mut);
  margin-top: 10px;
}
.summary {
  margin-top: 12px;
  padding: 10px 12px;
  background: #f6f7f9;
  border-radius: 10px;
  font-size: 12.5px;
}
.foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 16px;
}
.btn-ghost {
  padding: 9px 16px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 13px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.btn-dark {
  padding: 9px 16px;
  border: none;
  border-radius: 9px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-dark:disabled {
  opacity: 0.4;
}
</style>
