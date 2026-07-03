<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Heatmap from '@/components/Heatmap.vue'
import { TYPE_BADGE, iso } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useResourceStore } from '@/stores/resource'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { STUDY_TYPES, type StudyType } from '@/types'

const study = useStudyStore()
const resource = useResourceStore()
const auth = useAuthStore()
const ui = useUiStore()

const vw = ref(window.innerWidth)
window.addEventListener('resize', () => (vw.value = window.innerWidth))
const isMobile = computed(() => vw.value < 860)

const form = reactive({
  type: (auth.settings?.defaultType ?? '問題集') as StudyType,
  bookId: 0,
  rowId: 0,
  date: iso(new Date()),
})

onMounted(async () => {
  try {
    if (!resource.books.length) await resource.fetchBooks()
    syncBook()
  } catch {
    /* 認証前など */
  }
})

const books = computed(() => resource.books.filter((b) => b.type === form.type))
const rows = computed(() => resource.rows)

function syncBook() {
  if (!books.value.find((b) => b.id === form.bookId)) {
    form.bookId = books.value[0]?.id ?? 0
  }
  if (form.bookId) resource.selectBook(form.bookId).then(syncRow)
  else form.rowId = 0
}
function syncRow() {
  if (!rows.value.find((r) => r.id === form.rowId)) {
    form.rowId = rows.value[0]?.id ?? 0
  }
}

watch(() => form.type, syncBook)
watch(() => form.bookId, (id) => {
  if (id) resource.selectBook(id).then(syncRow)
})
watch(() => resource.books.length, () => { if (!form.bookId) syncBook() })

const stat = computed(() => study.recordStats ?? { week: 0, streak: 0, total: 0, heatmap: {}, recent: [] })

const recent = computed(() =>
  stat.value.recent.map((r) => {
    const d = new Date(r.date + 'T00:00:00')
    return {
      ...r,
      dateLabel: `${d.getMonth() + 1}/${d.getDate()}`,
      color: ui.colorOf(r.colorSoft, r.colorVivid),
      path: `${r.subjectName} › ${r.major} › ${r.mid}`,
      badge: TYPE_BADGE[r.type],
    }
  }),
)

const recCols = computed(() => (isMobile.value ? '1fr' : 'minmax(300px,360px) 1fr'))

async function register() {
  if (!form.rowId) {
    ui.notify('行を選択してください')
    return
  }
  const row = rows.value.find((r) => r.id === form.rowId)
  await resource.recordRow(form.rowId, form.date)
  await Promise.all([study.fetchItems(), study.fetchRecordStats(), study.fetchGoals()])
  ui.notify(`「${row?.title ?? row?.sub ?? ''}」を登録しました`)
}

async function removeRecord(id: number) {
  await study.deleteRecord(id)
  resource.refreshBookSummary().catch(() => {})
  ui.notify('学習記録を削除しました')
}
</script>

<template>
  <div :style="{ display: 'grid', gridTemplateColumns: recCols, gap: '18px', alignItems: 'start' }">
    <!-- form -->
    <div class="card" style="padding: 20px">
      <div style="font-size: 15px; font-weight: 700; margin-bottom: 4px">学習日を登録</div>
      <div style="font-size: 12px; color: var(--faint); margin-bottom: 16px">
        教材（講義・問題集・教科書）の各行に学習日を記録します。同じ行に何度でも登録できます（復習用）。
      </div>
      <div style="display: flex; flex-direction: column; gap: 12px">
        <label class="fld"><span>種別</span>
          <div class="seg2">
            <button v-for="t in STUDY_TYPES" :key="t" :class="{ on: form.type === t }" @click="form.type = t">{{ t }}</button>
          </div>
        </label>
        <label class="fld"><span>教材</span>
          <select v-model.number="form.bookId">
            <option v-for="b in books" :key="b.id" :value="b.id">{{ b.title }}</option>
          </select>
          <span v-if="!books.length" style="font-size: 11px; color: var(--faint); margin-top: 4px; font-weight: 400">この種別の教材がありません。「個別学習一覧データ」で作成してください。</span>
        </label>
        <label class="fld"><span>行（問題・項目）</span>
          <select v-model.number="form.rowId">
            <option v-for="r in rows" :key="r.id" :value="r.id">{{ r.seqNo ? r.seqNo + '. ' : '' }}{{ r.title ?? r.sub ?? '（無題）' }}</option>
          </select>
        </label>
        <label class="fld"><span>学習日</span>
          <input v-model="form.date" type="date" />
        </label>
        <button class="register-btn" @click="register">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5" /></svg>
          学習日を登録
        </button>
      </div>
    </div>

    <!-- right -->
    <div style="display: flex; flex-direction: column; gap: 18px">
      <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px">
        <div class="card stat-card"><div class="stat-label">今週の記録</div><div class="dm stat-num">{{ stat.week }}<span>件</span></div></div>
        <div class="card stat-card"><div class="stat-label">連続学習</div><div class="dm stat-num">{{ stat.streak }}<span>日</span></div></div>
        <div class="card stat-card"><div class="stat-label">累計記録</div><div class="dm stat-num">{{ stat.total }}<span>件</span></div></div>
      </div>

      <div class="card" style="padding: 18px 20px">
        <div class="row-between" style="margin-bottom: 13px">
          <span style="font-size: 13.5px; font-weight: 700">学習カレンダー</span>
          <span style="font-size: 11px; color: var(--faint)">直近16週間</span>
        </div>
        <Heatmap :counts="stat.heatmap" :cell="13" />
      </div>

      <div class="card" style="padding: 18px 20px">
        <div style="font-size: 13.5px; font-weight: 700; margin-bottom: 12px">最近の学習記録</div>
        <div style="display: flex; flex-direction: column">
          <div v-for="(r, i) in recent" :key="i" class="recent-row">
            <div style="font-size: 12px; color: var(--faint); width: 54px; flex-shrink: 0">{{ r.dateLabel }}</div>
            <span :style="{ width: '8px', height: '8px', borderRadius: '50%', background: r.color, flexShrink: 0 }"></span>
            <div style="flex: 1; min-width: 0">
              <div style="font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ r.sub }}</div>
              <div style="font-size: 11px; color: var(--faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ r.path }}</div>
            </div>
            <span :style="{ fontSize: '10.5px', fontWeight: 600, padding: '2px 8px', borderRadius: '99px', background: r.badge.bg, color: r.badge.fg, flexShrink: 0 }">{{ r.type }}</span>
            <button class="del-rec" title="この記録を削除" @click="removeRecord(r.id)">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg>
            </button>
          </div>
          <div v-if="!recent.length" style="padding: 20px; text-align: center; color: var(--faint); font-size: 13px">まだ記録がありません</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fld span {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  display: block;
  margin-bottom: 5px;
}
.fld select,
.fld input {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  background: #fff;
  outline: none;
}
.seg2 {
  display: flex;
  background: #f1f3f5;
  border-radius: 9px;
  padding: 3px;
}
.seg2 button {
  flex: 1;
  padding: 7px;
  border: none;
  border-radius: 7px;
  cursor: pointer;
  font-size: 12.5px;
  font-weight: 600;
  background: transparent;
  color: var(--mut);
}
.seg2 button.on {
  background: #fff;
  color: var(--ink);
}
.register-btn {
  margin-top: 4px;
  padding: 11px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
}
.row-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.stat-card {
  padding: 15px 16px;
}
.stat-label {
  font-size: 11.5px;
  color: var(--faint);
  margin-bottom: 4px;
}
.stat-num {
  font-size: 26px;
  font-weight: 700;
}
.stat-num span {
  font-size: 12px;
  color: var(--faint);
  margin-left: 3px;
  font-family: 'Zen Kaku Gothic New', sans-serif;
}
.recent-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 9px 0;
  border-top: 1px solid #f4f5f7;
}
.recent-row:first-child {
  border-top: none;
}
.del-rec {
  border: none;
  background: transparent;
  cursor: pointer;
  color: #c9ced6;
  padding: 4px;
  flex-shrink: 0;
}
.del-rec:hover {
  color: #cf5563;
}
</style>
