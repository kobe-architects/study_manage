<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import EventModal from '@/components/EventModal.vue'
import MonthCalendar from '@/components/MonthCalendar.vue'
import { assignmentTitle, daysBetween, iso, parseDate, TYPE_BADGE } from '@/lib/design'
import { useAuthStore } from '@/stores/auth'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import type { RecordListItem } from '@/types'

const auth = useAuthStore()
const study = useStudyStore()
const ui = useUiStore()
const router = useRouter()

const today = new Date()
today.setHours(0, 0, 0, 0)

// ---- 期間選択（デフォルト: 直近1週間） ----
type PeriodKey = 'week' | 'twoWeeks' | 'month' | 'custom'
const PERIODS: { key: PeriodKey; label: string }[] = [
  { key: 'week', label: '直近1週間' },
  { key: 'twoWeeks', label: '直近2週間' },
  { key: 'month', label: '直近1ヵ月' },
  { key: 'custom', label: '詳細期間選択' },
]
const period = ref<PeriodKey>('week')
const customFrom = ref(iso(new Date(today.getTime() - 6 * 86400000)))
const customTo = ref(iso(today))

function periodRange(): { from: string; to: string } {
  if (period.value === 'custom') return { from: customFrom.value, to: customTo.value }
  const from = new Date(today)
  if (period.value === 'week') from.setDate(from.getDate() - 6)
  else if (period.value === 'twoWeeks') from.setDate(from.getDate() - 13)
  else from.setMonth(from.getMonth() - 1)
  return { from: iso(from), to: iso(today) }
}

const periodLabel = computed(() => {
  if (period.value !== 'custom') return PERIODS.find((x) => x.key === period.value)!.label
  return `${customFrom.value} 〜 ${customTo.value}`
})

const records = ref<RecordListItem[]>([])
const loading = ref(true)

async function fetchRecords() {
  const { from, to } = periodRange()
  if (from > to) {
    ui.notify('期間の開始日は終了日以前にしてください')
    return
  }
  loading.value = true
  try {
    records.value = await study.fetchRecordList(from, to)
  } catch {
    ui.notify('学習記録の取得に失敗しました')
  } finally {
    loading.value = false
  }
}

function selectPeriod(key: PeriodKey) {
  period.value = key
  if (key !== 'custom') fetchRecords()
}

onMounted(() => {
  fetchRecords()
  study.fetchAssignments().catch(() => {})
  study.fetchEvents().catch(() => {})
})

/** 学習記録を科目別にグルーピング（最新の記録がある科目を先頭に、科目内は日付降順） */
const subjectGroups = computed(() => {
  const map = new Map<string, { name: string; colorSoft: string; colorVivid: string; rows: RecordListItem[] }>()
  for (const r of records.value) {
    const key = r.subjectName ?? '（科目未設定）'
    if (!map.has(key)) {
      map.set(key, { name: key, colorSoft: r.colorSoft, colorVivid: r.colorVivid, rows: [] })
    }
    map.get(key)!.rows.push(r)
  }
  return [...map.values()].sort((a, b) => (a.rows[0].date < b.rows[0].date ? 1 : -1))
})

// ---- 課題サマリ（未記録のみ・期限昇順で3件） ----
const pendingAssignments = computed(() =>
  study.assignments
    .filter((a) => a.achieved === null)
    .slice(0, 3)
    .map((a) => ({
      ...a,
      displayTitle: assignmentTitle(a.title, a.dueOn),
      daysLeft: daysBetween(today, parseDate(a.dueOn)),
    })),
)

// ---- カレンダー（生徒と同じ: 模試予定などの登録・削除） ----
const examDate = computed(() => auth.user?.student?.examDate ?? null)
const eventModal = ref<{ date: string; title: string } | null>(null)
function openEvent(date: string, title: string) {
  eventModal.value = { date, title }
}
async function saveEvent(title: string) {
  if (!eventModal.value) return
  const date = eventModal.value.date
  if (title.trim()) {
    await study.saveEvent(date, title.trim())
    ui.notify('予定を保存しました')
  } else {
    const ev = study.events.find((e) => e.date === date)
    if (ev) {
      await study.deleteEvent(ev.id)
      ui.notify('予定を削除しました')
    }
  }
  eventModal.value = null
}
async function deleteEvent() {
  if (!eventModal.value) return
  const ev = study.events.find((e) => e.date === eventModal.value!.date)
  if (ev) {
    await study.deleteEvent(ev.id)
    ui.notify('予定を削除しました')
  }
  eventModal.value = null
}

function fmtMd(isoDate: string) {
  const d = parseDate(isoDate)
  return `${d.getMonth() + 1}/${d.getDate()}`
}

function recordColorHex(c: string | null): string {
  return c === 'red' ? '#d92d20' : c === 'blue' ? '#2563eb' : c === 'green' ? '#2e9d62' : '#1c2024'
}
</script>

<template>
  <div>
    <div class="grid">
      <!-- LEFT: 課題 + カレンダー + ヒートマップ -->
      <div style="display: flex; flex-direction: column; gap: 14px">
        <div class="card" style="padding: 16px 18px">
          <div class="row-between" style="margin-bottom: 10px">
            <span style="font-size: 13px; font-weight: 700">進行中の課題</span>
            <button class="link-btn" @click="router.push({ name: 'tutor-assignments' })">課題設定へ →</button>
          </div>
          <div v-if="pendingAssignments.length" style="display: flex; flex-direction: column; gap: 10px">
            <div v-for="a in pendingAssignments" :key="a.id" style="display: flex; align-items: center; gap: 10px">
              <div style="flex: 1; min-width: 0">
                <div style="font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ a.displayTitle }}</div>
                <div style="font-size: 11px; color: var(--faint)">{{ a.done }} / {{ a.target }} 項目</div>
              </div>
              <span :style="{ fontSize: '11px', fontWeight: 700, flexShrink: 0, color: a.daysLeft <= 3 ? '#e0533d' : '#9aa1ab' }">
                {{ a.daysLeft < 0 ? `${-a.daysLeft}日超過` : a.daysLeft === 0 ? '本日期限' : `あと${a.daysLeft}日` }}
              </span>
            </div>
          </div>
          <div v-else style="font-size: 12px; color: var(--faint)">進行中の課題はありません</div>
        </div>

        <MonthCalendar :events="study.events" :exam-date="examDate" @day-click="openEvent" />
      </div>

      <!-- RIGHT: 直近の学習記録（科目別・期間選択付き） -->
      <div style="min-width: 0">
        <div class="card period-bar">
          <span style="font-size: 13.5px; font-weight: 700; flex-shrink: 0">直近の学習記録（科目別）</span>
          <span style="flex: 1"></span>
          <div class="period-chips">
            <button
              v-for="pOpt in PERIODS"
              :key="pOpt.key"
              class="p-chip"
              :class="{ on: period === pOpt.key }"
              @click="selectPeriod(pOpt.key)"
            >{{ pOpt.label }}</button>
          </div>
        </div>

        <!-- 詳細期間選択 -->
        <div v-if="period === 'custom'" class="card custom-range">
          <input v-model="customFrom" type="date" :max="customTo" />
          <span style="color: var(--faint)">〜</span>
          <input v-model="customTo" type="date" :min="customFrom" />
          <button class="apply-btn" @click="fetchRecords">表示</button>
        </div>

        <div class="card" style="padding: 6px 0">
          <div v-if="loading" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px">読み込み中…</div>
          <template v-else>
            <div style="padding: 8px 18px 0; font-size: 11px; color: var(--faint)">{{ periodLabel }}・全{{ records.length }}件</div>
            <div v-for="g in subjectGroups" :key="g.name" class="subj-block">
              <div class="subj-head">
                <span class="subj-dot" :style="{ background: ui.colorOf(g.colorSoft, g.colorVivid) }"></span>
                <span class="subj-name">{{ g.name }}</span>
                <span style="flex: 1"></span>
                <span style="font-size: 11.5px; color: var(--faint)">{{ g.rows.length }}件</span>
              </div>
              <div style="display: flex; flex-direction: column">
                <div v-for="r in g.rows" :key="r.id" class="rec-row">
                  <span style="font-size: 11.5px; color: var(--mut); width: 36px; flex-shrink: 0">{{ fmtMd(r.date) }}</span>
                  <span class="rec-badge" :style="{ background: TYPE_BADGE[r.type]?.bg ?? '#f1f2f4', color: TYPE_BADGE[r.type]?.fg ?? '#6b7280' }">{{ r.type }}</span>
                  <span class="rec-title" :style="{ color: recordColorHex(r.color) }">
                    <span v-if="r.seqNo" style="color: #aeb4bd">{{ r.seqNo }}.</span>
                    {{ r.rowTitle ?? r.sub ?? '（無題）' }}
                  </span>
                  <span class="rec-src">{{ r.bookTitle ?? (r.major ? `${r.major}›${r.mid}` : '') }}</span>
                </div>
              </div>
            </div>
            <div v-if="!subjectGroups.length" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px">
              この期間の学習記録はありません
            </div>
          </template>
        </div>
      </div>
    </div>

    <EventModal
      v-if="eventModal"
      :date="eventModal.date"
      :title="eventModal.title"
      @save="saveEvent"
      @delete="deleteEvent"
      @close="eventModal = null"
    />
  </div>
</template>

<style scoped>
.grid {
  display: grid;
  grid-template-columns: minmax(280px, 330px) 1fr;
  gap: 16px;
  align-items: start;
}
@media (max-width: 860px) {
  .grid {
    grid-template-columns: 1fr;
  }
}
.row-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.link-btn {
  border: none;
  background: none;
  color: #3b50cc;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 0;
}
.period-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding: 10px 16px;
  margin-bottom: 10px;
}
.period-chips {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.p-chip {
  border: 1px solid #e3e6ea;
  background: #fff;
  border-radius: 99px;
  padding: 5px 12px;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.p-chip.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.custom-range {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.custom-range input {
  padding: 7px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 12.5px;
  outline: none;
}
.apply-btn {
  padding: 7px 16px;
  border: none;
  border-radius: 8px;
  background: #1c2024;
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}
.subj-block {
  padding: 8px 18px 10px;
  border-top: 1px solid #f2f3f5;
}
.subj-block:first-of-type {
  border-top: none;
}
.subj-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
}
.subj-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex-shrink: 0;
}
.subj-name {
  font-size: 13.5px;
  font-weight: 700;
}
.rec-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 0;
  min-width: 0;
}
.rec-badge {
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 99px;
  width: 44px;
  text-align: center;
}
.rec-title {
  flex: 1;
  min-width: 0;
  font-size: 12.5px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rec-src {
  flex-shrink: 0;
  max-width: 40%;
  font-size: 10.5px;
  color: #aeb4bd;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
