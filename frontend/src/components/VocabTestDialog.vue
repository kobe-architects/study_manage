<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { quizApi } from '@/api/quiz'
import { TEST_FORMAT_LABEL, TEST_TYPE_LABEL, VOCAB_PER_PAGE, buildTestWords } from '@/lib/vocabTest'
import { shuffle } from '@/lib/design'
import { useUiStore } from '@/stores/ui'
import type { PrintTestFormat, PrintTestType, QuizPageSpec, StudyResource, Vocabulary } from '@/types'

/**
 * 講師の小テストに追加する「英単語テスト」の設定ダイアログ。
 * 単語帳 → セクション（Part / Week）→ 出題数・形式 を選ぶと、1枚あたりの上限で複数ページに分割して返す。
 */
const emit = defineEmits<{ close: []; add: [pages: QuizPageSpec[]] }>()
const ui = useUiStore()

const resources = ref<StudyResource[]>([])
const loading = ref(true)
const words = ref<Vocabulary[]>([])
const loadingWords = ref(false)

const form = reactive<{ resourceId: number | null; sectionIds: Set<number>; count: number; type: PrintTestType; format: PrintTestFormat; order: 'random' | 'ordered'; unstudiedFirst: boolean }>({
  resourceId: null,
  sectionIds: new Set(),
  count: 20,
  type: 'meaning',
  format: 'free',
  order: 'random',
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
const sections = computed(() => resource.value?.sections ?? [])

/** セクション名の「/」より前（Part 1 など）でグループ化 */
const groups = computed(() => {
  const m = new Map<string, number[]>()
  for (const s of sections.value) {
    const key = s.name.includes(' / ') ? s.name.split(' / ')[0]! : ''
    if (!m.has(key)) m.set(key, [])
    m.get(key)!.push(s.id)
  }
  return Array.from(m.entries()).filter(([k]) => k !== '')
})

async function selectResource(id: number) {
  form.resourceId = id
  form.sectionIds = new Set()
  loadingWords.value = true
  try {
    words.value = await quizApi.vocabularies(id)
  } catch {
    ui.notify('単語の取得に失敗しました')
    words.value = []
  } finally {
    loadingWords.value = false
  }
}

function toggleSection(id: number) {
  const next = new Set(form.sectionIds)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  form.sectionIds = next
}
function toggleGroup(ids: number[]) {
  const all = ids.every((id) => form.sectionIds.has(id))
  const next = new Set(form.sectionIds)
  ids.forEach((id) => (all ? next.delete(id) : next.add(id)))
  form.sectionIds = next
}
function selectAll(on: boolean) {
  form.sectionIds = on ? new Set(sections.value.map((s) => s.id)) : new Set()
}

const candidates = computed(() =>
  words.value.filter((w) => form.sectionIds.has(w.sectionId) && (form.type !== 'fill_spelling' || !!w.exampleSentence)),
)
const perPage = computed(() => VOCAB_PER_PAGE[form.format][form.type])
const effectiveCount = computed(() => Math.min(form.count, candidates.value.length))
const pageCount = computed(() => Math.ceil(effectiveCount.value / perPage.value))

watch(
  () => [form.format, form.type],
  () => {
    if (form.count > perPage.value * 4) form.count = perPage.value * 4
  },
)

function add() {
  if (!resource.value) return
  if (!effectiveCount.value) {
    ui.notify('出題できる単語がありません（セクションを選択してください）')
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
  const sectionNames = sections.value.filter((s) => form.sectionIds.has(s.id)).map((s) => s.name)
  const pages: QuizPageSpec[] = []
  for (let i = 0; i < testWords.length; i += perPage.value) {
    const chunk = testWords.slice(i, i + perPage.value)
    const pageNo = Math.floor(i / perPage.value) + 1
    pages.push({
      kind: 'vocab',
      label: `英単語テスト（${resource.value.name}${sectionNames.length === 1 ? ' ' + sectionNames[0] : ''}・${chunk.length}問${pageCount.value > 1 ? ` ${pageNo}/${pageCount.value}` : ''}）`,
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
          出題範囲（セクション）
          <button class="link" @click="selectAll(true)">全選択</button>
          <button class="link" style="color: #9aa1ab" @click="selectAll(false)">解除</button>
          <span style="margin-left: auto; font-weight: 400; color: var(--faint)">{{ form.sectionIds.size }} / {{ sections.length }} セクション・{{ candidates.length }}語</span>
        </div>
        <div v-if="groups.length" class="groups">
          <button v-for="[g, ids] in groups" :key="g" class="grp" :class="{ on: ids.every((id) => form.sectionIds.has(id)) }" @click="toggleGroup(ids)">{{ g }}</button>
        </div>
        <div class="chips">
          <button v-for="s in sections" :key="s.id" class="chip" :class="{ on: form.sectionIds.has(s.id) }" @click="toggleSection(s.id)">
            {{ s.name.includes(' / ') ? s.name.split(' / ')[1] : s.name }}<span class="cnt">{{ s.wordCount }}</span>
          </button>
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
          <label class="fld"><span>出題数（1枚 {{ perPage }}問まで・最大 {{ perPage * 4 }}問）</span>
            <input v-model.number="form.count" type="number" min="1" :max="perPage * 4" />
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
          <b>{{ effectiveCount }}問</b> を <b>{{ pageCount }}枚</b> の用紙に出題（各ページの満点 = 出題数）。
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
