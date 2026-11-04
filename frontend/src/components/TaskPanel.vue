<script setup lang="ts">
/**
 * トップページの「タスク」タブ。登録したタスク（1 日／1 週あたりの量）を日別・週別の計画に展開して表示し、消化量を記録する。
 * 日別: その日のタスクを科目ごとに並べ、○ で完了・行をタップして数を記録。上に「今日の進み具合」。
 * 週別: 月〜日 × タスクの表。セルをタップしてその日の数を記録。
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import HelpTip from '@/components/HelpTip.vue'
import TaskEditModal from '@/components/TaskEditModal.vue'
import { hexA, iso } from '@/lib/design'
import { addDays, fmtAmount, fmtDate, fmtRate, fmtWeek, isActiveOn, quotaOn, startOfDay, WEEKDAYS, weekDates, weekStart } from '@/lib/tasks'
import { useTaskStore } from '@/stores/task'
import { useUiStore } from '@/stores/ui'
import type { StudyTask, StudyTaskInput } from '@/types'

const tasks = useTaskStore()
const ui = useUiStore()
const router = useRouter()

const today = startOfDay(new Date())
const todayIso = iso(today)

// ---- 表示モード（端末に保存） ----
const saved = localStorage.getItem('sm_task_mode')
const mode = ref<'day' | 'week'>(saved === 'week' ? 'week' : 'day')
watch(mode, (m) => localStorage.setItem('sm_task_mode', m))

const date = ref(new Date(today)) // 日別で表示している日
const wstart = ref(weekStart(today)) // 週別で表示している週（月曜）
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    await Promise.all([tasks.fetchTasks(), tasks.ensureWeek(weekStart(date.value), true)])
  } catch {
    ui.notify('タスクの取得に失敗しました')
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(date, (d) => tasks.ensureWeek(weekStart(d)).catch(() => ui.notify('消化量の取得に失敗しました')))
watch(wstart, (d) => tasks.ensureWeek(d).catch(() => ui.notify('消化量の取得に失敗しました')))

// ---- 日・週の移動 ----
function shift(n: number) {
  if (mode.value === 'day') date.value = addDays(date.value, n)
  else wstart.value = addDays(wstart.value, 7 * n)
}
function goToday() {
  date.value = new Date(today)
  wstart.value = weekStart(today)
}
const isToday = computed(() => iso(date.value) === todayIso)
const isThisWeek = computed(() => iso(wstart.value) === iso(weekStart(today)))
const atToday = computed(() => (mode.value === 'day' ? isToday.value : isThisWeek.value))
const navLabel = computed(() => (mode.value === 'day' ? fmtDate(date.value) : fmtWeek(wstart.value)))
const dayTitle = computed(() => {
  const diff = Math.round((date.value.getTime() - today.getTime()) / 86400000)
  if (diff === 0) return '今日のタスク'
  if (diff === 1) return '明日のタスク'
  if (diff === -1) return '昨日のタスク'
  return `${fmtDate(date.value)} のタスク`
})

function colorOf(t: StudyTask) {
  return ui.colorOf(t.colorSoft, t.colorVivid)
}

// ---- 科目ごとのグループ化（科目の並び順 → タスクの並び順。科目なしは最後） ----
interface Group<R> {
  key: string
  name: string
  color: string
  rows: R[]
}
function groupBySubject<R extends { task: StudyTask }>(rows: R[]): Group<R>[] {
  const order = new Map<number, number>(tasks.subjects.map((s, i) => [s.id, i]))
  const rank = (key: string) => (key === 'none' ? 9999 : (order.get(Number(key)) ?? 9998))
  const map = new Map<string, Group<R>>()
  for (const r of rows) {
    const key = r.task.subjectId === null ? 'none' : String(r.task.subjectId)
    let g = map.get(key)
    if (!g) {
      g = { key, name: r.task.subjectName ?? 'その他', color: colorOf(r.task), rows: [] }
      map.set(key, g)
    }
    g.rows.push(r)
  }
  return [...map.values()].sort((a, b) => rank(a.key) - rank(b.key))
}

// ---- 日別 ----
interface DayRow {
  task: StudyTask
  quota: number
  done: number
  pct: number
  complete: boolean
  weekDone: number
  weekQuota: number
}
const dayGroups = computed<Group<DayRow>[]>(() => {
  const d = date.value
  const ds = iso(d)
  const wds = weekDates(weekStart(d))
  const rows: DayRow[] = []
  for (const t of tasks.tasks) {
    if (!isActiveOn(t, d)) continue
    const quota = quotaOn(t, d)
    if (!quota) continue
    const done = tasks.doneOn(t.id, ds)
    let weekDone = 0
    let weekQuota = 0
    if (t.period === 'week') {
      for (const x of wds) {
        weekQuota += quotaOn(t, x)
        weekDone += tasks.doneOn(t.id, iso(x))
      }
    }
    rows.push({ task: t, quota, done, pct: Math.min(100, Math.round((done / quota) * 100)), complete: done >= quota, weekDone, weekQuota })
  }
  return groupBySubject(rows)
})
const daySummary = computed(() => {
  const rows = dayGroups.value.flatMap((g) => g.rows)
  const total = rows.length
  const complete = rows.filter((r) => r.complete).length
  const ratio = total ? rows.reduce((a, r) => a + Math.min(1, r.done / r.quota), 0) / total : 0
  const pct = Math.round(ratio * 100)
  const circ = 2 * Math.PI * 30
  return { total, complete, pct, ringDash: `${((pct / 100) * circ).toFixed(1)} ${circ.toFixed(1)}`, remaining: total - complete }
})
const dayMessage = computed(() => {
  const s = daySummary.value
  if (!s.total) return ''
  if (s.complete === s.total) return 'すべて完了！'
  if (s.pct >= 50) return `あと ${s.remaining} 件`
  return `残り ${s.remaining} 件`
})

// ---- 週別 ----
interface WeekCell {
  date: string
  quota: number
  done: number
  active: boolean
  state: 'off' | 'none' | 'part' | 'done'
  today: boolean
  past: boolean
}
interface WeekRow {
  task: StudyTask
  cells: WeekCell[]
  quota: number
  done: number
  pct: number
}
const weekDays = computed(() => weekDates(wstart.value))
const weekGroups = computed<Group<WeekRow>[]>(() => {
  const rows: WeekRow[] = []
  for (const t of tasks.tasks) {
    const cells: WeekCell[] = []
    let quota = 0
    let done = 0
    for (const d of weekDays.value) {
      const ds = iso(d)
      const q = quotaOn(t, d)
      const n = tasks.doneOn(t.id, ds)
      const active = q > 0
      quota += q
      done += active ? n : 0
      cells.push({
        date: ds,
        quota: q,
        done: n,
        active,
        state: !active ? 'off' : n >= q ? 'done' : n > 0 ? 'part' : 'none',
        today: ds === todayIso,
        past: ds < todayIso,
      })
    }
    if (!quota) continue // この週に 1 日も出ないタスクは表に出さない
    rows.push({ task: t, cells, quota, done, pct: Math.min(100, Math.round((done / quota) * 100)) })
  }
  return groupBySubject(rows)
})
const weekSummary = computed(() => {
  const rows = weekGroups.value.flatMap((g) => g.rows)
  const total = rows.length
  const ratio = total ? rows.reduce((a, r) => a + Math.min(1, r.done / r.quota), 0) / total : 0
  // 日ごとの進み具合（その日に出るタスクの達成率の平均）
  const days = weekDays.value.map((d, i) => {
    const cells = rows.map((r) => r.cells[i]!).filter((c) => c.active)
    const ds = iso(d)
    const p = cells.length ? Math.round((cells.reduce((a, c) => a + Math.min(1, c.done / c.quota), 0) / cells.length) * 100) : 0
    return { d, ds, pct: p, count: cells.length, complete: cells.filter((c) => c.done >= c.quota).length, today: ds === todayIso, past: ds < todayIso }
  })
  const complete = rows.filter((r) => r.done >= r.quota).length
  return { total, complete, pct: Math.round(ratio * 100), days }
})

// ---- 消化量の記録 ----
const rec = reactive<{ open: boolean; task: StudyTask | null; date: string; quota: number; amount: number; saving: boolean }>({
  open: false,
  task: null,
  date: todayIso,
  quota: 0,
  amount: 0,
  saving: false,
})
function openRecord(t: StudyTask, d: string, quota: number) {
  rec.task = t
  rec.date = d
  rec.quota = quota
  rec.amount = tasks.doneOn(t.id, d)
  rec.saving = false
  rec.open = true
}
const recStep = computed(() => (rec.quota >= 100 ? 10 : rec.quota >= 30 ? 5 : 1))
function bump(n: number) {
  rec.amount = Math.max(0, Math.floor(Number(rec.amount) || 0) + n)
}
const recPct = computed(() => (rec.quota ? Math.min(100, Math.round(((Number(rec.amount) || 0) / rec.quota) * 100)) : 0))
async function saveRecord() {
  if (!rec.task || rec.saving) return
  const amount = Math.max(0, Math.floor(Number(rec.amount) || 0))
  rec.saving = true
  try {
    await tasks.setLog(rec.task.id, rec.date, amount)
    rec.open = false
    ui.notify(amount >= rec.quota ? '完了にしました' : amount === 0 ? '記録を取り消しました' : `${fmtAmount(amount, rec.task.unit)} を記録しました`)
  } catch {
    ui.notify('記録の保存に失敗しました')
  } finally {
    rec.saving = false
  }
}
const busy = reactive<Record<string, boolean>>({})
/** ○ のタップ: 未完了なら予定量ぶん完了に、完了なら 0 に戻す */
async function toggleComplete(t: StudyTask, d: string, quota: number, complete: boolean) {
  const k = `${t.id}|${d}`
  if (busy[k]) return
  busy[k] = true
  try {
    await tasks.setLog(t.id, d, complete ? 0 : quota)
  } catch {
    ui.notify('記録の保存に失敗しました')
  } finally {
    busy[k] = false
  }
}

// ---- タスクの追加（編集・削除は「タスク管理」で） ----
const editOpen = ref(false)
const editSaving = ref(false)
async function saveNew(payload: StudyTaskInput) {
  editSaving.value = true
  try {
    await tasks.createTask(payload)
    editOpen.value = false
    ui.notify('タスクを追加しました')
  } catch {
    ui.notify('タスクの追加に失敗しました')
  } finally {
    editSaving.value = false
  }
}
</script>

<template>
  <div class="tp">
    <!-- 上段: 日別／週別 ・ 日付の移動 ・ 追加／管理 -->
    <div class="tp-bar">
      <div v-seg class="tp-seg">
        <button class="tp-seg-btn" :class="{ on: mode === 'day' }" @click="mode = 'day'">日別</button>
        <button class="tp-seg-btn" :class="{ on: mode === 'week' }" @click="mode = 'week'">週別</button>
      </div>
      <div class="tp-nav">
        <button class="tp-arrow" aria-label="前へ" @click="shift(-1)">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>
        </button>
        <button class="tp-date" :class="{ off: !atToday }" :title="atToday ? '' : '今日へ戻る'" @click="goToday">
          {{ navLabel }}<span v-if="!atToday" class="tp-date-hint">{{ mode === 'day' ? '今日へ' : '今週へ' }}</span>
        </button>
        <button class="tp-arrow" aria-label="次へ" @click="shift(1)">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7" /></svg>
        </button>
      </div>
      <div class="tp-actions">
        <button class="tp-add" @click="editOpen = true">＋ タスク追加</button>
        <button class="tp-manage" @click="router.push({ name: 'tasks' })">管理 ›</button>
        <HelpTip align="right" text="科目ごとに登録した「やるべきこと」を日別・週別に展開して表示します。○ をタップすると予定量ぶん完了に、行（週別はセル）をタップすると数を記録できます。タスクの編集・削除は「管理」から。" />
      </div>
    </div>

    <!-- タスク未登録 -->
    <div v-if="tasks.loaded && !tasks.tasks.length" class="card tp-empty">
      タスクがありません。<br />「タスク追加」から、科目ごとのやるべきことを 1 日あたり／1 週あたりの量で登録すると、日別・週別の計画がここに表示されます。<br />
      <span class="tp-empty-ex">例: 英単語 LEAP basic 140 words / 週 → 日別では 20 words</span>
    </div>

    <!-- ===== 日別 ===== -->
    <template v-else-if="mode === 'day'">
      <div class="card tp-sum">
        <div class="tp-ring">
          <svg width="64" height="64" viewBox="0 0 70 70">
            <circle cx="35" cy="35" r="30" fill="none" stroke="#eef0f3" stroke-width="8" />
            <circle cx="35" cy="35" r="30" fill="none" :stroke="daySummary.complete === daySummary.total && daySummary.total ? '#2e9d62' : '#1c2024'" stroke-width="8" stroke-linecap="round" :stroke-dasharray="daySummary.ringDash" transform="rotate(-90 35 35)" style="transition: stroke-dasharray 0.3s" />
          </svg>
          <div class="dm tp-ring-num">{{ daySummary.pct }}<span>%</span></div>
        </div>
        <div style="flex: 1; min-width: 0">
          <div class="tp-sum-title">{{ dayTitle }}</div>
          <div class="tp-sum-big">
            <span class="dm">{{ daySummary.complete }}</span><span class="tp-sum-slash">/ {{ daySummary.total }}</span><span class="tp-sum-unit">件 完了</span>
            <span v-if="dayMessage" class="tp-sum-msg" :class="{ ok: daySummary.complete === daySummary.total && daySummary.total }">{{ dayMessage }}</span>
          </div>
          <div class="tp-track" style="margin-top: 8px">
            <div class="tp-fill" :style="{ width: daySummary.pct + '%', background: daySummary.complete === daySummary.total && daySummary.total ? '#2e9d62' : '#1c2024' }"></div>
          </div>
        </div>
      </div>

      <div v-if="!dayGroups.length" class="card tp-empty">この日に取り組むタスクはありません。</div>

      <div v-for="g in dayGroups" :key="g.key" class="card tp-group">
        <div class="tp-g-head">
          <span class="tp-dot" :style="{ background: g.color }"></span>
          <span class="tp-g-name">{{ g.name }}</span>
          <span class="tp-g-cnt">{{ g.rows.filter((r) => r.complete).length }} / {{ g.rows.length }}</span>
        </div>
        <div
          v-for="r in g.rows"
          :key="r.task.id"
          class="tp-row tap"
          :class="{ done: r.complete }"
          @click="openRecord(r.task, iso(date), r.quota)"
        >
          <button
            class="tp-check"
            :class="{ on: r.complete }"
            :style="r.complete ? { background: g.color, borderColor: g.color } : {}"
            :title="r.complete ? '完了を取り消す' : '完了にする'"
            @click.stop="toggleComplete(r.task, iso(date), r.quota, r.complete)"
          >
            <svg v-if="r.complete" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7" /></svg>
          </button>
          <div class="tp-main">
            <div class="tp-title-line">
              <span class="tp-title">{{ r.task.title }}</span>
              <span class="tp-amt" :style="{ color: g.color, background: hexA(g.color, 0.12) }">{{ fmtAmount(r.quota, r.task.unit) }}</span>
            </div>
            <div class="tp-prog">
              <div class="tp-track"><div class="tp-fill" :style="{ width: r.pct + '%', background: g.color }"></div></div>
              <span class="tp-num dm">{{ r.done }}<span class="tp-num-q">/{{ r.quota }}</span></span>
            </div>
            <div v-if="r.task.period === 'week'" class="tp-week-note">今週 {{ r.weekDone }} / {{ fmtAmount(r.weekQuota, r.task.unit) }}</div>
          </div>
          <svg class="tp-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6" /></svg>
        </div>
      </div>
    </template>

    <!-- ===== 週別 ===== -->
    <template v-else>
      <div class="card tp-sum tp-sum-week">
        <div class="tp-ring">
          <svg width="64" height="64" viewBox="0 0 70 70">
            <circle cx="35" cy="35" r="30" fill="none" stroke="#eef0f3" stroke-width="8" />
            <circle cx="35" cy="35" r="30" fill="none" stroke="#1c2024" stroke-width="8" stroke-linecap="round" :stroke-dasharray="`${((weekSummary.pct / 100) * 2 * Math.PI * 30).toFixed(1)} ${(2 * Math.PI * 30).toFixed(1)}`" transform="rotate(-90 35 35)" style="transition: stroke-dasharray 0.3s" />
          </svg>
          <div class="dm tp-ring-num">{{ weekSummary.pct }}<span>%</span></div>
        </div>
        <div style="flex: 1; min-width: 0">
          <div class="tp-sum-title">{{ isThisWeek ? '今週のタスク' : fmtWeek(wstart) + ' のタスク' }}</div>
          <div class="tp-sum-big">
            <span class="dm">{{ weekSummary.complete }}</span><span class="tp-sum-slash">/ {{ weekSummary.total }}</span><span class="tp-sum-unit">件 週の予定を達成</span>
          </div>
          <!-- 日ごとの進み具合 -->
          <div class="tp-days">
            <div v-for="dd in weekSummary.days" :key="dd.ds" class="tp-day" :class="{ today: dd.today, past: dd.past, none: !dd.count }" :title="dd.count ? `${WEEKDAYS[dd.d.getDay()]} ${dd.complete}/${dd.count} 件完了` : ''">
              <div class="tp-day-bar"><div :style="{ height: dd.pct + '%', background: dd.pct >= 100 ? '#2e9d62' : '#1c2024' }"></div></div>
              <span class="tp-day-wd" :class="{ sat: dd.d.getDay() === 6, sun: dd.d.getDay() === 0 }">{{ WEEKDAYS[dd.d.getDay()] }}</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="!weekGroups.length" class="card tp-empty">この週に取り組むタスクはありません。</div>

      <div v-else class="card tp-week">
        <div class="tp-tbl-wrap">
          <table class="tp-tbl">
            <thead>
              <tr>
                <th class="tp-th-task">タスク</th>
                <th v-for="d in weekDays" :key="iso(d)" class="tp-th-day" :class="{ today: iso(d) === todayIso, sat: d.getDay() === 6, sun: d.getDay() === 0 }">
                  <div class="tp-th-wd">{{ WEEKDAYS[d.getDay()] }}</div>
                  <div class="tp-th-d dm">{{ d.getDate() }}</div>
                </th>
                <th class="tp-th-week">週</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="g in weekGroups" :key="g.key">
                <tr class="tp-tr-sub">
                  <td colspan="9"><span class="tp-dot" :style="{ background: g.color }"></span>{{ g.name }}</td>
                </tr>
                <tr v-for="r in g.rows" :key="r.task.id">
                  <td class="tp-td-task">
                    <div class="tp-title">{{ r.task.title }}</div>
                    <div class="tp-rate">{{ fmtRate(r.task) }}</div>
                  </td>
                  <td
                    v-for="c in r.cells"
                    :key="c.date"
                    class="tp-cell"
                    :class="[c.state, { today: c.today, past: c.past, tap: c.active }]"
                    @click="c.active && openRecord(r.task, c.date, c.quota)"
                  >
                    <template v-if="c.active">
                      <div class="tp-c-box" :style="c.state === 'done' ? { background: g.color, borderColor: g.color, color: '#fff' } : c.state === 'part' ? { background: hexA(g.color, 0.14), borderColor: hexA(g.color, 0.4), color: g.color } : {}">
                        <div class="tp-c-done dm">{{ c.done }}</div>
                        <div class="tp-c-quota">/{{ c.quota }}</div>
                      </div>
                    </template>
                    <span v-else class="tp-c-off">–</span>
                  </td>
                  <td class="tp-td-week">
                    <div class="dm tp-w-pct" :style="{ color: r.done >= r.quota ? '#2e9d62' : '#1c2024' }">{{ r.pct }}%</div>
                    <div class="tp-c-quota">{{ r.done }}/{{ r.quota }}</div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- 消化量の記録モーダル -->
    <Teleport to="body">
      <Transition name="ui-modal">
        <div v-if="rec.open && rec.task" class="tp-mbg ui-overlay ui-sheet ui-swipe" @click.self="rec.open = false">
          <div class="tp-modal ui-panel">
            <div class="tp-m-title">進み具合を記録</div>
            <div class="tp-m-sub">
              <span class="tp-dot" :style="{ background: colorOf(rec.task) }"></span>
              <span style="font-weight: 700">{{ rec.task.title }}</span>
              <span style="color: var(--faint)">｜ {{ fmtDate(new Date(rec.date + 'T00:00:00')) }} ・ 予定 {{ fmtAmount(rec.quota, rec.task.unit) }}</span>
            </div>
            <div class="tp-m-stepper">
              <button class="tp-m-step" :disabled="rec.amount <= 0" @click="bump(-recStep)">−{{ recStep }}</button>
              <div class="tp-m-input">
                <input v-model.number="rec.amount" type="number" inputmode="numeric" min="0" step="1" @keydown.enter.prevent="saveRecord" />
                <span class="tp-m-unit">{{ rec.task.unit }}</span>
              </div>
              <button class="tp-m-step" @click="bump(recStep)">+{{ recStep }}</button>
            </div>
            <div class="tp-track" style="height: 8px">
              <div class="tp-fill" :style="{ width: recPct + '%', background: recPct >= 100 ? '#2e9d62' : colorOf(rec.task) }"></div>
            </div>
            <div class="tp-m-quick">
              <button class="tp-chip" :class="{ on: rec.amount === 0 }" @click="rec.amount = 0">0</button>
              <button class="tp-chip" :class="{ on: rec.amount === Math.floor(rec.quota / 2) && rec.quota > 1 }" @click="rec.amount = Math.floor(rec.quota / 2)">半分</button>
              <button class="tp-chip ok" :class="{ on: rec.amount === rec.quota }" @click="rec.amount = rec.quota">完了（{{ rec.quota }}）</button>
            </div>
            <div class="tp-m-actions">
              <button class="tp-btn-out" @click="rec.open = false">キャンセル</button>
              <button class="tp-btn-dark" :disabled="rec.saving" @click="saveRecord">記録する</button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Transition name="ui-modal">
      <TaskEditModal v-if="editOpen" :task="null" :subjects="tasks.subjects" :saving="editSaving" @save="saveNew" @close="editOpen = false" />
    </Transition>
  </div>
</template>

<style scoped>
.tp {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
/* ---- 上段 ---- */
.tp-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.tp-seg {
  display: flex;
  background: #fff;
  border: 1px solid #e6e8eb;
  border-radius: 10px;
  padding: 3px;
}
.tp-seg-btn {
  padding: 6px 14px;
  border: none;
  border-radius: 7px;
  cursor: pointer;
  font-size: 12.5px;
  font-weight: 600;
  background: transparent;
  color: var(--mut);
}
.tp-seg-btn.on {
  background: #1c2024;
  color: #fff;
}
.tp-nav {
  display: flex;
  align-items: center;
  gap: 2px;
  background: #fff;
  border: 1px solid #e6e8eb;
  border-radius: 10px;
  padding: 3px;
}
.tp-arrow {
  width: 32px;
  height: 30px;
  border: none;
  border-radius: 7px;
  background: transparent;
  color: var(--mut);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
.tp-arrow:hover {
  background: #f3f4f6;
}
.tp-date {
  min-width: 118px;
  height: 30px;
  padding: 0 10px;
  border: none;
  border-radius: 7px;
  background: transparent;
  font-size: 13px;
  font-weight: 700;
  color: var(--ink);
  cursor: pointer;
  white-space: nowrap;
}
.tp-date.off {
  color: #3b50cc;
}
.tp-date-hint {
  margin-left: 6px;
  font-size: 10px;
  font-weight: 600;
  color: #3b50cc;
  background: #eef0fb;
  border-radius: 99px;
  padding: 1px 7px;
}
.tp-actions {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 8px;
}
.tp-add {
  padding: 7px 13px;
  border: none;
  border-radius: 9px;
  background: #1c2024;
  color: #fff;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.tp-manage {
  padding: 7px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  color: var(--mut);
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.tp-empty {
  padding: 32px 20px;
  text-align: center;
  color: var(--faint);
  font-size: 13px;
  line-height: 1.8;
}
.tp-empty-ex {
  display: inline-block;
  margin-top: 6px;
  font-size: 12px;
  color: #3a8a5c;
  background: #eef7f0;
  border-radius: 8px;
  padding: 3px 10px;
}

/* ---- まとめカード ---- */
.tp-sum {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px 20px;
}
.tp-ring {
  position: relative;
  width: 64px;
  height: 64px;
  flex-shrink: 0;
}
.tp-ring-num {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  font-weight: 700;
}
.tp-ring-num span {
  font-size: 9px;
  margin-left: 1px;
  color: var(--mut);
}
.tp-sum-title {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
}
.tp-sum-big {
  display: flex;
  align-items: baseline;
  gap: 5px;
  flex-wrap: wrap;
  margin-top: 1px;
}
.tp-sum-big > .dm {
  font-size: 26px;
  font-weight: 700;
  line-height: 1.1;
}
.tp-sum-slash {
  font-size: 14px;
  color: var(--mut);
  font-weight: 600;
}
.tp-sum-unit {
  font-size: 12px;
  color: var(--mut);
}
.tp-sum-msg {
  margin-left: 6px;
  font-size: 11px;
  font-weight: 700;
  color: #e0533d;
  background: #fdecea;
  border-radius: 99px;
  padding: 1px 8px;
}
.tp-sum-msg.ok {
  color: #2e9d62;
  background: #eef7f0;
}
.tp-track {
  height: 7px;
  background: #eef0f3;
  border-radius: 99px;
  overflow: hidden;
  flex: 1;
}
.tp-fill {
  height: 100%;
  border-radius: 99px;
  transition: width 0.25s ease;
}
.tp-days {
  display: flex;
  gap: 6px;
  margin-top: 8px;
  max-width: 320px;
}
.tp-day {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 3px;
}
.tp-day-bar {
  width: 100%;
  height: 26px;
  background: #eef0f3;
  border-radius: 5px;
  overflow: hidden;
  display: flex;
  align-items: flex-end;
}
.tp-day-bar > div {
  width: 100%;
  border-radius: 5px 5px 0 0;
  transition: height 0.25s ease;
}
.tp-day.none .tp-day-bar {
  opacity: 0.4;
}
.tp-day-wd {
  font-size: 10px;
  color: var(--faint);
  font-weight: 600;
}
.tp-day.today .tp-day-wd {
  color: #fff;
  background: #1c2024;
  border-radius: 99px;
  padding: 0 5px;
}
.tp-day-wd.sat {
  color: #2563eb;
}
.tp-day-wd.sun {
  color: #d92d20;
}

/* ---- 科目グループ（日別） ---- */
.tp-group {
  padding: 6px 0 4px;
}
.tp-g-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 18px 6px;
}
.tp-dot {
  display: inline-block;
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex-shrink: 0;
}
.tp-g-name {
  font-size: 13px;
  font-weight: 700;
}
.tp-g-cnt {
  margin-left: auto;
  font-size: 11px;
  color: var(--faint);
  font-weight: 600;
}
.tp-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px 10px 18px;
  border-top: 1px solid #f2f3f5;
  cursor: pointer;
}
@media (hover: hover) {
  .tp-row:hover {
    background: #fafbfc;
  }
}
.tp-check {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2px solid #d3d7dd;
  background: #fff;
  cursor: pointer;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  transition: background 0.15s, border-color 0.15s;
}
.tp-check:hover {
  border-color: #9aa1ab;
}
.tp-main {
  flex: 1;
  min-width: 0;
}
.tp-title-line {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.tp-title {
  font-size: 13.5px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.tp-row.done .tp-title {
  color: #9aa1ab;
  text-decoration: line-through;
}
.tp-amt {
  font-size: 11.5px;
  font-weight: 700;
  border-radius: 99px;
  padding: 1px 8px;
  white-space: nowrap;
  flex-shrink: 0;
}
.tp-prog {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 6px;
}
.tp-prog .tp-track {
  height: 6px;
}
.tp-num {
  font-size: 12.5px;
  font-weight: 700;
  white-space: nowrap;
  min-width: 44px;
  text-align: right;
}
.tp-num-q {
  font-size: 11px;
  color: var(--faint);
  font-weight: 600;
}
.tp-week-note {
  font-size: 10.5px;
  color: var(--faint);
  margin-top: 3px;
}
.tp-chev {
  color: #c5cad2;
  flex-shrink: 0;
}

/* ---- 週別の表 ---- */
.tp-week {
  padding: 0;
  overflow: hidden;
}
.tp-tbl-wrap {
  overflow-x: auto;
}
.tp-tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
  min-width: 520px;
}
.tp-tbl th {
  padding: 8px 6px;
  font-size: 11px;
  color: var(--faint);
  font-weight: 600;
  border-bottom: 1px solid var(--line);
  text-align: center;
  background: #fafbfc;
}
.tp-th-task {
  text-align: left !important;
  padding-left: 16px !important;
  min-width: 140px;
}
.tp-th-day.sat .tp-th-wd {
  color: #2563eb;
}
.tp-th-day.sun .tp-th-wd {
  color: #d92d20;
}
.tp-th-d {
  font-size: 13px;
  color: var(--ink);
  font-weight: 700;
  line-height: 1.2;
}
.tp-th-day.today .tp-th-d {
  display: inline-block;
  min-width: 22px;
  background: #1c2024;
  color: #fff;
  border-radius: 99px;
  padding: 0 4px;
}
.tp-th-week {
  min-width: 56px;
}
.tp-tr-sub td {
  padding: 9px 16px 4px;
  font-size: 12px;
  font-weight: 700;
  border-top: 1px solid var(--line);
}
.tp-tr-sub td .tp-dot {
  margin-right: 7px;
  vertical-align: 1px;
}
.tp-td-task {
  padding: 6px 8px 6px 16px;
  min-width: 140px;
  max-width: 220px;
}
.tp-td-task .tp-title {
  font-size: 12.5px;
}
.tp-rate {
  font-size: 10.5px;
  color: var(--faint);
  white-space: nowrap;
}
.tp-cell {
  padding: 4px 3px;
  text-align: center;
  width: 46px;
}
.tp-cell.today {
  background: #f6f7fb;
}
.tp-c-box {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-width: 38px;
  padding: 3px 4px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  line-height: 1.15;
  cursor: pointer;
  transition: transform 0.12s;
}
@media (hover: hover) {
  .tp-c-box:hover {
    transform: scale(1.06);
  }
}
.tp-cell.none.past .tp-c-box {
  border-color: #f0b4ad;
  color: #d92d20;
}
.tp-c-done {
  font-size: 13px;
  font-weight: 700;
}
.tp-c-quota {
  font-size: 9.5px;
  opacity: 0.75;
}
.tp-c-off {
  color: #d3d7dd;
}
.tp-td-week {
  text-align: center;
  padding: 4px 8px;
  border-left: 1px solid var(--line);
}
.tp-w-pct {
  font-size: 13px;
  font-weight: 700;
}

/* ---- 記録モーダル ---- */
.tp-mbg {
  position: fixed;
  inset: 0;
  background: rgba(20, 22, 26, 0.42);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 60;
  padding: 16px;
}
.tp-modal {
  background: #fff;
  border-radius: 15px;
  padding: 22px;
  width: 100%;
  max-width: 420px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.tp-m-title {
  font-size: 16px;
  font-weight: 700;
}
.tp-m-sub {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 12.5px;
  margin-top: -6px;
  flex-wrap: wrap;
}
.tp-m-stepper {
  display: flex;
  align-items: center;
  gap: 10px;
}
.tp-m-step {
  width: 56px;
  height: 46px;
  border: 1px solid #e3e6ea;
  border-radius: 12px;
  background: #fff;
  font-size: 14px;
  font-weight: 700;
  color: var(--ink);
  cursor: pointer;
  flex-shrink: 0;
}
.tp-m-step:disabled {
  opacity: 0.35;
  cursor: default;
}
.tp-m-input {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 6px;
  border: 1px solid #e3e6ea;
  border-radius: 12px;
  padding: 0 12px;
  height: 46px;
}
.tp-m-input input {
  flex: 1;
  min-width: 0;
  border: none;
  outline: none;
  font-size: 22px;
  font-weight: 700;
  text-align: right;
  font-family: 'DM Sans', sans-serif;
  background: transparent;
  color: var(--ink);
}
.tp-m-input input::-webkit-outer-spin-button,
.tp-m-input input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.tp-m-unit {
  font-size: 12.5px;
  color: var(--mut);
  white-space: nowrap;
}
.tp-m-quick {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.tp-chip {
  padding: 6px 13px;
  border: 1px solid #e3e6ea;
  border-radius: 99px;
  background: #fff;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.tp-chip.on {
  border-color: #1c2024;
  color: #1c2024;
  background: #f3f4f6;
}
.tp-chip.ok {
  color: #2e9d62;
  border-color: #cfe8d8;
}
.tp-chip.ok.on {
  background: #2e9d62;
  border-color: #2e9d62;
  color: #fff;
}
.tp-m-actions {
  display: flex;
  justify-content: flex-end;
  gap: 9px;
}
.tp-btn-out {
  padding: 9px 15px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  color: #1c2024;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.tp-btn-dark {
  padding: 9px 18px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.tp-btn-dark:disabled {
  opacity: 0.5;
  cursor: default;
}

@media (max-width: 600px) {
  .tp-bar {
    gap: 8px;
  }
  .tp-actions {
    width: 100%;
    margin-left: 0;
  }
  .tp-actions .tp-add {
    flex: 1;
  }
  .tp-date {
    min-width: 0;
    font-size: 12.5px;
  }
  .tp-sum {
    padding: 14px 16px;
  }
  .tp-row {
    padding: 10px 10px 10px 14px;
  }
  .tp-tbl {
    min-width: 0;
  }
  .tp-th-task,
  .tp-td-task {
    min-width: 96px;
    max-width: 120px;
    padding-left: 12px;
  }
  .tp-cell {
    width: 40px;
    padding: 4px 1px;
  }
  .tp-c-box {
    min-width: 34px;
    padding: 2px 3px;
  }
}
</style>
