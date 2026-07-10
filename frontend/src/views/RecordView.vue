<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Heatmap from '@/components/Heatmap.vue'
import { computeReviewOn, REVIEW_OPTIONS, TYPE_BADGE, iso } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useResourceStore } from '@/stores/resource'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { STUDY_TYPES, type RecordColor, type RecordListItem, type StudyType } from '@/types'

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
  color: 'red' as RecordColor | null,
  reviewIdx: 1,
  customDays: 7 as number | null,
})

// 学習日の色（赤/青/緑）
const DATE_COLORS: Record<RecordColor, string> = { red: '#d92d20', blue: '#2563eb', green: '#2e9d62' }
const COLOR_LABEL: Record<RecordColor, string> = { red: '赤', blue: '青', green: '緑' }
const RECORD_COLOR_KEYS: RecordColor[] = ['red', 'blue', 'green']

const reviewPreview = computed(() => {
  const opt = REVIEW_OPTIONS[form.reviewIdx]
  const on = computeReviewOn(form.date, opt, form.customDays)
  if (!on) return '復習項目一覧には表示されません'
  const d = new Date(on + 'T00:00:00')
  return `→ 復習期限: ${d.getMonth() + 1}/${d.getDate()}`
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
  const reviewOn = computeReviewOn(form.date, REVIEW_OPTIONS[form.reviewIdx], form.customDays)
  await resource.recordRow(form.rowId, form.date, form.color, reviewOn)
  await Promise.all([study.fetchItems(), study.fetchRecordStats(), study.fetchGoals(), study.fetchReviews()])
  ui.notify(reviewOn ? `「${row?.title ?? row?.sub ?? ''}」を登録しました（復習期限あり）` : `「${row?.title ?? row?.sub ?? ''}」を登録しました`)
}

async function removeRecord(id: number) {
  await study.deleteRecord(id)
  resource.refreshBookSummary().catch(() => {})
  ui.notify('学習記録を削除しました')
}

// ===== 学習記録の出力（画面表示 / Excel） =====
const EXPORT_RANGES = [
  { key: '7', label: '直近1週間' },
  { key: '14', label: '直近2週間' },
  { key: '1m', label: '直近1ヵ月' },
  { key: 'custom', label: '任意期間' },
] as const
type ExportRange = (typeof EXPORT_RANGES)[number]['key']

const exportRange = ref<ExportRange>('7')
const exportFrom = ref('')
const exportTo = ref('')
const exportRows = ref<RecordListItem[]>([])
const exportLoaded = ref(false)
const exportLoading = ref(false)
const exportShownPeriod = ref('')

onMounted(() => {
  const today = new Date()
  exportTo.value = iso(today)
  const from = new Date()
  from.setDate(from.getDate() - 7)
  exportFrom.value = iso(from)
})

function exportPeriod(): { from: string; to: string } {
  if (exportRange.value === 'custom') return { from: exportFrom.value, to: exportTo.value }
  const from = new Date()
  if (exportRange.value === '1m') from.setMonth(from.getMonth() - 1)
  else from.setDate(from.getDate() - Number(exportRange.value))
  return { from: iso(from), to: iso(new Date()) }
}

function validExportPeriod(): { from: string; to: string } | null {
  const p = exportPeriod()
  if (!p.from || !p.to) {
    ui.notify('期間を指定してください')
    return null
  }
  if (p.from > p.to) {
    ui.notify('開始日は終了日以前にしてください')
    return null
  }
  return p
}

async function showExportRecords() {
  const p = validExportPeriod()
  if (!p) return
  exportLoading.value = true
  try {
    exportRows.value = await study.fetchRecordList(p.from, p.to)
    exportShownPeriod.value = `${p.from} 〜 ${p.to}`
    exportLoaded.value = true
  } finally {
    exportLoading.value = false
  }
}

async function exportExcel() {
  const p = validExportPeriod()
  if (!p) return
  exportLoading.value = true
  try {
    await study.downloadRecordExport(p.from, p.to, `学習記録_${p.from}_${p.to}.xlsx`)
    ui.notify('Excelファイルを出力しました')
  } finally {
    exportLoading.value = false
  }
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
        <div class="fld"><span>色</span>
          <div style="display: flex; align-items: center; gap: 9px">
            <button class="rec-dot none" :class="{ sel: form.color === null }" title="色なし" @click="form.color = null"></button>
            <button
              v-for="c in RECORD_COLOR_KEYS"
              :key="c"
              class="rec-dot"
              :class="{ sel: form.color === c }"
              :style="{ background: DATE_COLORS[c] }"
              :title="COLOR_LABEL[c]"
              @click="form.color = c"
            ></button>
          </div>
        </div>
        <div class="fld"><span>復習期限</span>
          <div class="review-opts">
            <button
              v-for="(opt, i) in REVIEW_OPTIONS"
              :key="opt.label"
              class="review-chip"
              :class="{ on: form.reviewIdx === i }"
              @click="form.reviewIdx = i"
            >{{ opt.label }}</button>
            <input
              v-if="REVIEW_OPTIONS[form.reviewIdx].kind === 'custom'"
              v-model.number="form.customDays"
              type="number"
              min="1"
              class="review-custom"
              placeholder="日数"
            />
          </div>
          <span style="font-size: 11px; color: var(--faint); margin-top: 5px; font-weight: 400">{{ reviewPreview }}</span>
        </div>
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

      <!-- 学習記録の出力 -->
      <div class="card" style="padding: 18px 20px">
        <div style="font-size: 13.5px; font-weight: 700; margin-bottom: 4px">学習記録の出力</div>
        <div style="font-size: 12px; color: var(--faint); margin-bottom: 12px">期間を指定して学習記録を画面に表示、または Excel で出力します。</div>

        <div style="font-size: 12px; color: var(--mut); font-weight: 600; margin-bottom: 8px">対象期間</div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px">
          <button v-for="r in EXPORT_RANGES" :key="r.key" class="period" :class="{ on: exportRange === r.key }" @click="exportRange = r.key">{{ r.label }}</button>
        </div>
        <div v-if="exportRange === 'custom'" style="display: flex; gap: 10px; align-items: center; margin-bottom: 12px; flex-wrap: wrap">
          <input v-model="exportFrom" type="date" class="d-input" />
          <span style="color: var(--faint)">〜</span>
          <input v-model="exportTo" type="date" class="d-input" />
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap">
          <button class="btn-dark" :disabled="exportLoading" @click="showExportRecords">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 5px"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>画面に表示
          </button>
          <button class="btn-out" :disabled="exportLoading" @click="exportExcel">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 5px"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 19h16" /></svg>Excel出力
          </button>
        </div>

        <div v-if="exportLoaded" style="margin-top: 16px">
          <div style="font-size: 12.5px; font-weight: 700; margin-bottom: 8px">{{ exportShownPeriod }} の学習記録（{{ exportRows.length }}件）</div>
          <div v-if="!exportRows.length" style="padding: 20px; text-align: center; color: var(--faint); font-size: 13px">この期間の学習記録はありません</div>
          <div v-else class="exp-table-wrap">
            <table class="exp-table">
              <thead>
                <tr><th>学習日</th><th>科目</th><th>内容</th><th>教材</th><th>種別</th><th>色</th><th>復習期限</th><th>復習完了</th></tr>
              </thead>
              <tbody>
                <tr v-for="r in exportRows" :key="r.id">
                  <td class="dm" style="white-space: nowrap">{{ r.date }}</td>
                  <td style="white-space: nowrap">
                    <span :style="{ display: 'inline-block', width: '8px', height: '8px', borderRadius: '50%', background: ui.colorOf(r.colorSoft, r.colorVivid), marginRight: '6px' }"></span>{{ r.subjectName ?? '—' }}
                  </td>
                  <td>
                    <div style="font-weight: 500">{{ r.rowTitle ?? r.sub ?? '—' }}</div>
                    <div style="font-size: 11px; color: var(--faint)">{{ [r.major, r.mid].filter(Boolean).join(' › ') }}</div>
                  </td>
                  <td>{{ r.bookTitle ?? '—' }}</td>
                  <td style="white-space: nowrap">
                    <span :style="{ fontSize: '10.5px', fontWeight: 600, padding: '2px 8px', borderRadius: '99px', background: TYPE_BADGE[r.type].bg, color: TYPE_BADGE[r.type].fg }">{{ r.type }}</span>
                  </td>
                  <td style="text-align: center">
                    <span v-if="r.color" :style="{ display: 'inline-block', width: '10px', height: '10px', borderRadius: '50%', background: DATE_COLORS[r.color] }" :title="COLOR_LABEL[r.color]"></span>
                    <span v-else style="color: var(--faint)">—</span>
                  </td>
                  <td class="dm" style="white-space: nowrap">{{ r.reviewOn ?? '—' }}</td>
                  <td class="dm" style="white-space: nowrap">{{ r.reviewedOn ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
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
.rec-dot {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: 1.5px solid #fff;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.14);
  cursor: pointer;
  padding: 0;
}
.rec-dot:hover {
  transform: scale(1.12);
}
.rec-dot.sel {
  box-shadow: 0 0 0 2px #1c2024;
}
.rec-dot.none {
  background: #fff;
  box-shadow: 0 0 0 1px #cbd1d8;
}
.rec-dot.none.sel {
  box-shadow: 0 0 0 2px #1c2024;
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
.period {
  padding: 8px 14px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  font-size: 12.5px;
  cursor: pointer;
  font-weight: 600;
  color: var(--mut);
}
.period.on {
  background: #1c2024;
  color: #fff;
  border-color: #1c2024;
}
.d-input {
  padding: 8px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
}
.btn-dark {
  padding: 10px 16px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
}
.btn-out {
  padding: 10px 16px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  color: #1c2024;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-dark:disabled,
.btn-out:disabled {
  opacity: 0.55;
  cursor: default;
}
.exp-table-wrap {
  overflow-x: auto;
  border: 1px solid #eef0f3;
  border-radius: 10px;
}
.exp-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
  min-width: 640px;
}
.exp-table th {
  text-align: left;
  font-size: 11px;
  color: var(--faint);
  font-weight: 600;
  padding: 8px 10px;
  background: #f8f9fb;
  border-bottom: 1px solid #eef0f3;
  white-space: nowrap;
}
.exp-table td {
  padding: 8px 10px;
  border-bottom: 1px solid #f4f5f7;
  vertical-align: middle;
}
.exp-table tbody tr:last-child td {
  border-bottom: none;
}
</style>
