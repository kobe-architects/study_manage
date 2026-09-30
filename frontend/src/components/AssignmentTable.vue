<script setup lang="ts">
/**
 * 講師の課題一覧（テーブル表示）。小テスト一覧と同じ見た目で、ステータスで絞り込める。
 * 1 行＝1 課題。対象データは「n件」をタップすると行の下に展開する。達成／未達成は行の右端で切り替える。
 */
import { computed, reactive } from 'vue'
import ActionMenu, { type ActionMenuItem } from '@/components/ActionMenu.vue'
import { assignmentTitle, daysBetween, parseDate, pct, TYPE_BADGE } from '@/lib/design'
import { viewportWidth } from '@/lib/native'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import type { Assignment, GoalItemDetail } from '@/types'

const props = defineProps<{ assignments: Assignment[] }>()
const emit = defineEmits<{ edit: [Assignment]; remove: [Assignment] }>()

const study = useStudyStore()
const ui = useUiStore()

const today = new Date()
today.setHours(0, 0, 0, 0)

type StatusKey = 'active' | 'overdue' | 'ok' | 'ng'
const STATUS_LABEL: Record<StatusKey, string> = { active: '進行中', overdue: '期限切れ', ok: '達成', ng: '未達成' }
const STATUS_ORDER: StatusKey[] = ['active', 'overdue', 'ok', 'ng']
/** 既定は進行中＋期限切れ（まだ結果を付けていない課題） */
const filter = reactive<{ status: StatusKey[] }>({ status: ['active', 'overdue'] })

function statusOf(a: Assignment): StatusKey {
  if (a.achieved === true) return 'ok'
  if (a.achieved === false) return 'ng'
  return a.overdue ? 'overdue' : 'active'
}
function toggleStatus(k: StatusKey) {
  const i = filter.status.indexOf(k)
  if (i >= 0) filter.status.splice(i, 1)
  else filter.status.push(k)
}
const counts = computed(() => {
  const c: Record<StatusKey, number> = { active: 0, overdue: 0, ok: 0, ng: 0 }
  for (const a of props.assignments) c[statusOf(a)]++
  return c
})
/** 期限が近い順 */
const rows = computed(() =>
  props.assignments
    .filter((a) => !filter.status.length || filter.status.includes(statusOf(a)))
    .slice()
    .sort((a, b) => a.dueOn.localeCompare(b.dueOn) || b.id - a.id),
)

/** タブレット・スマホでは操作ボタンを「操作」メニューにまとめる */
const compact = computed(() => viewportWidth.value <= 900)
const MENU: ActionMenuItem[] = [
  { key: 'edit', label: '編集' },
  { key: 'remove', label: '削除', danger: true },
]
function onMenu(a: Assignment, key: string) {
  if (key === 'edit') emit('edit', a)
  else if (key === 'remove') emit('remove', a)
}

function daysLeft(a: Assignment): number {
  return daysBetween(today, parseDate(a.dueOn))
}
function dueText(a: Assignment): string {
  const d = daysLeft(a)
  if (d < 0) return `${-d}日超過`
  if (d === 0) return '本日期限'
  return `あと${d}日`
}
function fmt(d: string): string {
  const [y, m, dd] = d.split(/[- :T]/)
  return `${y}/${Number(m)}/${Number(dd)}`
}
function barColor(a: Assignment): string {
  if (a.achieved === true) return '#2e9d62'
  return a.overdue && a.achieved === null ? '#e0533d' : '#2e4a8f'
}

// ---- 達成／未達成 ----
async function setAchieved(a: Assignment, value: boolean) {
  const next = a.achieved === value ? null : value
  try {
    await study.updateAssignment(a.id, { achieved: next })
    ui.notify(next === null ? '達成状態を未記録に戻しました' : next ? '達成として記録しました' : '未達成として記録しました')
  } catch {
    ui.notify('更新に失敗しました')
  }
}

// ---- 対象データの展開 ----
const expanded = reactive(new Set<number>())
const items = reactive<Record<number, GoalItemDetail[]>>({})
const loading = reactive(new Set<number>())
async function toggleExpand(a: Assignment) {
  if (expanded.has(a.id)) {
    expanded.delete(a.id)
    return
  }
  expanded.add(a.id)
  if (!items[a.id]) {
    loading.add(a.id)
    try {
      items[a.id] = await study.fetchAssignmentItems(a.id)
    } catch {
      ui.notify('対象データの取得に失敗しました')
    } finally {
      loading.delete(a.id)
    }
  }
}
/** 教材ごとにグループ化（教材名は見出しに一度だけ表示） */
function bookGroups(list: GoalItemDetail[]) {
  const map = new Map<string, GoalItemDetail[]>()
  for (const it of list) {
    const k = it.bookTitle ?? 'その他'
    if (!map.has(k)) map.set(k, [])
    map.get(k)!.push(it)
  }
  return [...map.entries()].map(([name, rows]) => ({ name, rows }))
}
/** 行の親項目名（章）。章がなければ小分類名、それもなければタイトル */
function parentLabel(it: GoalItemDetail): string {
  return it.chapter ?? it.sub ?? it.title ?? '（無題）'
}
</script>

<template>
  <div class="at">
    <!-- ステータスで絞り込み（複数選択） -->
    <div class="status-tabs">
      <button class="st all" :class="{ on: !filter.status.length }" @click="filter.status = []">すべて<span class="n">{{ assignments.length }}</span></button>
      <button v-for="k in STATUS_ORDER" :key="k" class="st" :class="[k, { on: filter.status.includes(k) }]" @click="toggleStatus(k)">
        {{ STATUS_LABEL[k] }}<span class="n">{{ counts[k] }}</span>
      </button>
    </div>

    <div v-if="rows.length" class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th class="c-actions"></th>
            <th class="c-status">ステータス</th>
            <th class="c-due">期限</th>
            <th class="c-title">課題</th>
            <th class="c-prog">進捗</th>
            <th class="c-items">対象</th>
            <th class="c-ach">達成</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="a in rows" :key="a.id">
            <tr class="row" :class="statusOf(a)">
              <td class="c-actions">
                <div class="acts">
                  <ActionMenu v-if="compact" :items="MENU" @select="onMenu(a, $event)" />
                  <template v-else>
                    <button class="btn" @click="emit('edit', a)">編集</button>
                    <button class="btn danger" @click="emit('remove', a)">削除</button>
                  </template>
                </div>
              </td>
              <td class="c-status">
                <div class="badges">
                  <span class="badge" :class="statusOf(a)">{{ STATUS_LABEL[statusOf(a)] }}</span>
                  <span v-if="a.achieved === null" class="due-note" :class="{ warn: daysLeft(a) <= 3 }">{{ dueText(a) }}</span>
                </div>
              </td>
              <td class="c-due nowrap">{{ fmt(a.dueOn) }}</td>
              <td class="c-title">
                <div class="ttl">{{ assignmentTitle(a.title, a.dueOn) }}</div>
                <div v-if="a.note" class="note">{{ a.note }}</div>
              </td>
              <td class="c-prog">
                <div class="prog">
                  <span class="dm num">{{ a.done }}<span class="max"> / {{ a.target }}</span></span>
                  <span class="bar"><span :style="{ width: pct(a.done, a.target) + '%', background: barColor(a) }"></span></span>
                  <span class="rate" :style="{ color: barColor(a) }">{{ pct(a.done, a.target) }}%</span>
                </div>
              </td>
              <td class="c-items">
                <button class="expand" :class="{ on: expanded.has(a.id) }" @click="toggleExpand(a)">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6" /></svg>
                  {{ a.target }}件
                </button>
              </td>
              <td class="c-ach">
                <div class="ach-toggle">
                  <button class="ach-btn ok" :class="{ on: a.achieved === true }" @click="setAchieved(a, true)">達成</button>
                  <button class="ach-btn ng" :class="{ on: a.achieved === false }" @click="setAchieved(a, false)">未達成</button>
                </div>
              </td>
            </tr>
            <!-- 対象データ（展開） -->
            <tr v-if="expanded.has(a.id)" class="detail-row">
              <td colspan="7">
                <div v-if="loading.has(a.id)" class="hint-line">読み込み中…</div>
                <div v-else class="items">
                  <div v-for="g in bookGroups(items[a.id] ?? [])" :key="g.name" class="ig">
                    <div class="ig-book">{{ g.name }}</div>
                    <div v-for="it in g.rows" :key="it.id" class="ig-row">
                      <span v-if="it.type" class="gi-badge" :style="{ background: TYPE_BADGE[it.type].bg, color: TYPE_BADGE[it.type].fg }">{{ it.type }}</span>
                      <span v-else class="gi-badge" style="background: #f1f2f4; color: #aeb4bd">—</span>
                      <span class="gi-mark" :style="{ color: it.studied ? '#2e9d62' : '#cbd1d8' }">{{ it.studied ? '✓' : '○' }}</span>
                      <span class="gi-title" :class="{ done: it.studied }">{{ parentLabel(it) }}</span>
                      <span class="gi-src"><template v-if="it.seqNo">{{ it.seqNo }}. </template>{{ it.title ?? it.sub ?? '' }}</span>
                    </div>
                  </div>
                  <div v-if="!(items[a.id] ?? []).length" class="hint-line">対象データがありません</div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
    <div v-else class="tbl-wrap empty">条件に一致する課題はありません</div>
  </div>
</template>

<style scoped>
.at {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
/* ---- ステータスの絞り込み（小テスト一覧と同じ見た目） ---- */
.status-tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.st {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 999px;
  background: #fff;
  font-size: 12px;
  font-weight: 700;
  color: var(--mut);
  cursor: pointer;
}
.st .n {
  min-width: 18px;
  padding: 0 5px;
  border-radius: 999px;
  background: #f1f2f4;
  font-size: 10.5px;
  text-align: center;
}
.st.active.on {
  background: #e8eefb;
  border-color: #c6d3f3;
  color: #2e4a8f;
}
.st.overdue.on {
  background: #fdf0f1;
  border-color: #f0b8be;
  color: #c0444f;
}
.st.ok.on {
  background: #e6f5ec;
  border-color: #b6e0c6;
  color: #2f7a4f;
}
.st.ng.on,
.st.all.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.st.on .n {
  background: rgba(255, 255, 255, 0.5);
}
.st.all.on .n,
.st.ng.on .n {
  background: rgba(255, 255, 255, 0.18);
}

/* ---- テーブル ---- */
.tbl-wrap {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  overflow-x: auto;
}
.tbl {
  width: 100%;
  min-width: 620px;
  border-collapse: collapse;
  font-size: 12.5px;
}
.tbl th {
  padding: 7px 10px;
  text-align: left;
  font-size: 11px;
  font-weight: 700;
  color: var(--mut);
  background: #f8f9fb;
  border-bottom: 1px solid var(--line);
  white-space: nowrap;
}
.tbl td {
  padding: 7px 10px;
  border-bottom: 1px solid #f1f2f4;
  vertical-align: middle;
  line-height: 1.35;
}
.tbl tbody tr.row:nth-of-type(even) {
  background: #f9fafc;
}
.tbl tr:last-child td {
  border-bottom: none;
}
.nowrap {
  white-space: nowrap;
}
.c-actions {
  width: 1%;
  white-space: nowrap;
}
.acts {
  display: flex;
  gap: 5px;
}
.btn {
  padding: 4px 9px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.btn.danger {
  color: #c0444f;
}
.c-status {
  width: 120px;
  white-space: nowrap;
}
.badges {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 3px;
}
.badge {
  display: inline-block;
  width: 84px;
  text-align: center;
  font-size: 10.5px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 999px;
  white-space: nowrap;
}
.badge.active {
  background: #e8eefb;
  color: #2e4a8f;
}
.badge.overdue {
  background: #fdf0f1;
  color: #c0444f;
}
.badge.ok {
  background: #e6f5ec;
  color: #2f7a4f;
}
.badge.ng {
  background: #f1f2f4;
  color: var(--mut);
}
.due-note {
  font-size: 10.5px;
  color: var(--faint);
  padding-left: 2px;
}
.due-note.warn {
  color: #e0533d;
  font-weight: 700;
}
.c-due {
  width: 84px;
  color: var(--mut);
}
.c-title {
  min-width: 160px;
}
.ttl {
  font-weight: 700;
  color: var(--ink);
}
.note {
  font-size: 11px;
  color: var(--faint);
  margin-top: 2px;
  white-space: pre-wrap;
}
.c-prog {
  width: 190px;
}
.prog {
  display: flex;
  align-items: center;
  gap: 8px;
}
.num {
  font-size: 13.5px;
  font-weight: 700;
  white-space: nowrap;
}
.num .max {
  font-size: 11px;
  font-weight: 500;
  color: var(--mut);
}
.bar {
  flex: 1;
  height: 6px;
  border-radius: 3px;
  background: #eef0f3;
  overflow: hidden;
  min-width: 50px;
}
.bar span {
  display: block;
  height: 100%;
  border-radius: 3px;
  transition: width 0.3s ease;
}
.rate {
  font-size: 11.5px;
  font-weight: 700;
  white-space: nowrap;
  min-width: 34px;
  text-align: right;
}
.c-items {
  width: 1%;
  white-space: nowrap;
}
.expand {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 9px 5px 7px;
  border: none;
  border-radius: 8px;
  background: transparent;
  font-size: 12px;
  font-weight: 600;
  color: #3b50cc;
  cursor: pointer;
}
.expand svg {
  transition: transform 0.15s ease;
}
.expand.on svg {
  transform: rotate(90deg);
}
.expand.on {
  background: #eef1fc;
}
.c-ach {
  width: 1%;
  white-space: nowrap;
}
.ach-toggle {
  display: flex;
  gap: 5px;
}
.ach-btn {
  padding: 5px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
  color: #9aa1ab;
  white-space: nowrap;
}
.ach-btn.ok.on {
  background: #eaf7ef;
  border-color: #9ed4b3;
  color: #1f7a45;
}
.ach-btn.ng.on {
  background: #fdeef0;
  border-color: #f0b8be;
  color: #c0444f;
}

/* ---- 対象データの展開行 ---- */
.detail-row td {
  background: #fafbfd;
  padding: 8px 14px 12px 20px;
  border-bottom: 1px solid #f1f2f4;
}
.items {
  display: flex;
  flex-direction: column;
  gap: 4px;
  max-height: 320px;
  overflow-y: auto;
}
.ig-book {
  font-size: 11px;
  font-weight: 700;
  color: var(--mut);
  padding: 6px 2px 2px;
}
.ig-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 4px;
  font-size: 12px;
  min-width: 0;
}
.gi-badge {
  font-size: 10px;
  font-weight: 700;
  padding: 1px 7px;
  border-radius: 999px;
  flex-shrink: 0;
}
.gi-mark {
  width: 14px;
  text-align: center;
  font-size: 12px;
  flex-shrink: 0;
}
.gi-title {
  font-weight: 600;
  color: var(--ink);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.gi-title.done {
  color: #9aa1ab;
  text-decoration: line-through;
}
.gi-src {
  margin-left: auto;
  font-size: 11px;
  color: var(--faint);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex-shrink: 1;
  min-width: 0;
}
.hint-line {
  font-size: 12px;
  color: var(--faint);
  padding: 4px 2px;
}
.empty {
  text-align: center;
  color: var(--faint);
  padding: 28px 12px;
  font-size: 12.5px;
}
/* タブレット縦: 列を詰める */
@media (min-width: 641px) and (max-width: 900px) {
  .tbl {
    min-width: 0;
  }
  .tbl th,
  .tbl td {
    padding-left: 7px;
    padding-right: 7px;
  }
  .c-prog {
    width: 150px;
  }
  .c-status {
    width: 104px;
  }
  .c-title {
    min-width: 120px;
  }
}
</style>
