<script setup lang="ts">
import { computed, ref } from 'vue'
import { MARK_COLOR, MARK_LABEL } from '@/api/quiz'
import type { QuizStatGroup, QuizStats } from '@/types'

/**
 * 小テストの分析（生徒・講師共用）。
 * 平均得点率などの要約、得点率の推移（折れ線）、章別・中分類別・難易度別の得点率（横棒）、弱点例題。
 */
const props = defineProps<{ stats: QuizStats }>()

const s = computed(() => props.stats.summary)
const hasData = computed(() => s.value.gradedCount > 0)

// ---- 推移（折れ線・○率） ----
const timeline = computed(() => props.stats.timeline.filter((t) => t.marks && t.marks.o + t.marks.tri + t.marks.x > 0))
const CW = 640
const CH = 200
const PAD = { l: 36, r: 16, t: 14, b: 30 }
const points = computed(() =>
  timeline.value.map((t, i) => {
    const n = timeline.value.length
    const x = PAD.l + (n === 1 ? (CW - PAD.l - PAD.r) / 2 : ((CW - PAD.l - PAD.r) * i) / (n - 1))
    const y = PAD.t + ((CH - PAD.t - PAD.b) * (100 - (oRateOf(t.marks) ?? 0))) / 100
    return { x, y, t }
  }),
)
const path = computed(() => points.value.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '))
const hover = ref<number | null>(null)
function yOf(v: number): number {
  return PAD.t + ((CH - PAD.t - PAD.b) * (100 - v)) / 100
}
function md(d: string | null): string {
  if (!d) return ''
  const [, m, dd] = d.split('-')
  return `${Number(m)}/${Number(dd)}`
}

// ---- 横棒（章別など） ----
type GroupKey = 'byChapter' | 'byMid' | 'byDifficulty'
const groupTab = ref<GroupKey>('byChapter')
const GROUP_TABS: { key: GroupKey; label: string }[] = [
  { key: 'byChapter', label: '章別' },
  { key: 'byMid', label: '中分類別' },
  { key: 'byDifficulty', label: '難易度別' },
]
const groups = computed<QuizStatGroup[]>(() => {
  const list = [...props.stats[groupTab.value]]
  if (groupTab.value === 'byDifficulty') return list
  return list.sort((a, b) => (groupORate(a) ?? 0) - (groupORate(b) ?? 0))
})
function groupORate(g: QuizStatGroup): number | null {
  return oRateOf({ o: g.o, tri: g.tri, x: g.x })
}
function rateColor(rate: number | null): string {
  if (rate === null) return '#cfd4db'
  if (rate < 60) return '#cf4444'
  if (rate < 80) return '#d98a1a'
  return '#3b50cc'
}
/** ○の割合（%） */
function oRateOf(m: { o: number; tri: number; x: number }): number | null {
  const n = m.o + m.tri + m.x
  return n > 0 ? Math.round((m.o / n) * 100) : null
}
</script>

<template>
  <div class="stats">
    <div v-if="!hasData" class="empty">
      採点・添削済みの小テストがまだありません。採点・添削が完了すると、得点率の推移や単元別の正答率がここに表示されます。
      <div v-if="s.quizCount" style="margin-top: 6px; font-size: 11.5px">
        出題 {{ s.quizCount }}件（未提出 {{ s.assignedCount }}・採点・添削待ち {{ s.submittedCount }}）
      </div>
    </div>

    <template v-else>
      <!-- 要約 -->
      <div class="tiles">
        <div class="tile">
          <div class="t-label">評価の内訳</div>
          <div class="t-marks">
            <span v-for="m in (['o', 'tri', 'x'] as const)" :key="m" :style="{ color: MARK_COLOR[m] }"><b>{{ MARK_LABEL[m] }}</b>{{ s.marks[m] }}</span>
          </div>
          <div class="t-sub">○ 正解・△ おしい・× 不正解</div>
        </div>
        <div class="tile">
          <div class="t-label">○率</div>
          <div class="t-val"><b>{{ oRateOf(s.marks) ?? '–' }}</b><span>%</span></div>
          <div class="t-sub">評価済み {{ s.marks.o + s.marks.tri + s.marks.x }}問のうち ○ の割合</div>
        </div>
        <div class="tile">
          <div class="t-label">採点・添削済み</div>
          <div class="t-val"><b>{{ s.gradedCount }}</b><span>回</span></div>
          <div class="t-sub">{{ s.pageCount }}問を評価</div>
        </div>
        <div class="tile">
          <div class="t-label">進行中</div>
          <div class="t-val"><b>{{ s.assignedCount + s.submittedCount }}</b><span>件</span></div>
          <div class="t-sub">未提出 {{ s.assignedCount }}・採点・添削待ち {{ s.submittedCount }}</div>
        </div>
      </div>

      <!-- 推移 -->
      <div class="card sec">
        <div class="sec-title">○率の推移</div>
        <div class="chart-wrap">
          <svg :viewBox="`0 0 ${CW} ${CH}`" class="chart" @mouseleave="hover = null">
            <g v-for="v in [0, 25, 50, 75, 100]" :key="v">
              <line :x1="PAD.l" :x2="CW - PAD.r" :y1="yOf(v)" :y2="yOf(v)" stroke="#eceef1" stroke-width="1" />
              <text :x="PAD.l - 6" :y="yOf(v) + 4" text-anchor="end" font-size="10" fill="#9aa1ab">{{ v }}</text>
            </g>
            <path v-if="points.length > 1" :d="path" fill="none" stroke="#3b50cc" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
            <g v-for="(p, i) in points" :key="p.t.id">
              <circle :cx="p.x" :cy="p.y" r="4.5" fill="#3b50cc" stroke="#fff" stroke-width="2" />
              <text :x="p.x" :y="CH - PAD.b + 16" text-anchor="middle" font-size="10" fill="#6b7280">{{ md(p.t.gradedOn) }}</text>
              <rect :x="p.x - 14" :y="PAD.t" width="28" :height="CH - PAD.t - PAD.b" fill="transparent" @mouseenter="hover = i" />
            </g>
            <g v-if="hover !== null && points[hover]">
              <line :x1="points[hover]!.x" :x2="points[hover]!.x" :y1="PAD.t" :y2="CH - PAD.b" stroke="#c9cdd6" stroke-dasharray="3 3" />
            </g>
          </svg>
          <div v-if="hover !== null && points[hover]" class="tip" :style="{ left: (points[hover]!.x / CW) * 100 + '%' }">
            <div style="font-weight: 700">{{ points[hover]!.t.title }}</div>
            <div>○{{ points[hover]!.t.marks.o }} △{{ points[hover]!.t.marks.tri }} ×{{ points[hover]!.t.marks.x }}（○率 {{ oRateOf(points[hover]!.t.marks) }}%）・{{ points[hover]!.t.gradedOn?.replace(/-/g, '/') }}</div>
          </div>
        </div>
        <table class="tbl">
          <thead><tr><th>小テスト</th><th>採点・添削日</th><th class="r">評価（○△×）</th><th class="r">○率</th></tr></thead>
          <tbody>
            <tr v-for="t in [...timeline].reverse()" :key="t.id">
              <td>{{ t.title }}</td>
              <td>{{ t.gradedOn?.replace(/-/g, '/') }}</td>
              <td class="r">
                <span v-for="m in (['o', 'tri', 'x'] as const)" :key="m" :style="{ color: MARK_COLOR[m], fontWeight: 700, marginLeft: '8px' }">{{ MARK_LABEL[m] }}{{ t.marks[m] }}</span>
              </td>
              <td class="r"><b :style="{ color: rateColor(oRateOf(t.marks)) }">{{ oRateOf(t.marks) }}%</b></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 単元別 -->
      <div class="card sec">
        <div class="sec-title" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
          単元別の○率
          <div class="seg">
            <button v-for="t in GROUP_TABS" :key="t.key" :class="{ on: groupTab === t.key }" @click="groupTab = t.key">{{ t.label }}</button>
          </div>
          <span style="font-size: 11px; color: var(--faint); font-weight: 400; margin-left: auto">○率の低い順</span>
        </div>
        <div v-if="!groups.length" class="empty small">集計対象がありません</div>
        <div v-else class="bars">
          <div v-for="g in groups" :key="g.key" class="bar-row" :title="`${g.label}: ○${g.o} △${g.tri} ×${g.x}`">
            <div class="bar-label">
              <div class="bl-main">{{ g.label }}</div>
              <div v-if="g.sub" class="bl-sub">{{ g.sub }}</div>
            </div>
            <div class="bar-track"><span :style="{ width: (groupORate(g) ?? 0) + '%', background: rateColor(groupORate(g)) }"></span></div>
            <div class="bar-val"><b :style="{ color: rateColor(groupORate(g)) }">{{ groupORate(g) ?? '–' }}%</b><span>{{ g.pages }}問・○{{ g.o }} △{{ g.tri }} ×{{ g.x }}</span></div>
          </div>
        </div>
      </div>

      <!-- 弱点 -->
      <div class="card sec">
        <div class="sec-title">弱点の例題 <span class="sec-note">評価率 60% 未満（○=100%・△=50%換算）、または最新の評価が △ / ×</span></div>
        <div v-if="!stats.weak.length" class="empty small">弱点の例題はありません。よくできています。</div>
        <table v-else class="tbl">
          <thead><tr><th>例題</th><th>章</th><th>難易度</th><th class="r">評価率</th><th class="r">最新</th><th class="r">回数</th><th class="r">最終</th></tr></thead>
          <tbody>
            <tr v-for="w in stats.weak" :key="w.itemId ?? w.label ?? ''">
              <td><b>{{ w.label }}</b></td>
              <td>{{ w.chapter ?? '' }}</td>
              <td style="color: #d98a1a">{{ w.difficulty ?? '' }}</td>
              <td class="r"><b :style="{ color: rateColor(w.rate) }">{{ w.rate ?? '–' }}%</b></td>
              <td class="r"><span v-if="w.lastMark" :style="{ color: MARK_COLOR[w.lastMark], fontWeight: 700 }">{{ MARK_LABEL[w.lastMark] }}</span></td>
              <td class="r">{{ w.attempts }}</td>
              <td class="r">{{ w.lastOn?.slice(5).replace('-', '/') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.stats {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.empty {
  font-size: 12.5px;
  color: var(--faint);
  padding: 24px 18px;
  border: 1px dashed #d8dce1;
  border-radius: 14px;
  text-align: center;
  line-height: 1.7;
}
.empty.small {
  padding: 14px;
  border: none;
}
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 10px;
}
.tile {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 12px 14px;
}
.t-label {
  font-size: 11px;
  color: var(--mut);
  font-weight: 600;
}
.t-val {
  margin-top: 2px;
}
.t-val b {
  font-size: 26px;
  font-weight: 700;
}
.t-val span {
  font-size: 12px;
  color: var(--mut);
  margin-left: 2px;
}
.t-sub {
  font-size: 11px;
  color: var(--faint);
  margin-top: 2px;
}
.t-marks {
  display: flex;
  gap: 10px;
  font-size: 14px;
  margin-top: 6px;
}
.t-marks b {
  font-size: 16px;
  margin-right: 2px;
}
.card {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
}
.sec {
  padding: 14px 16px;
}
.sec-title {
  font-size: 13px;
  font-weight: 700;
  margin-bottom: 10px;
}
.sec-note {
  font-size: 11px;
  color: var(--faint);
  font-weight: 400;
  margin-left: 8px;
}
.chart-wrap {
  position: relative;
}
.chart {
  width: 100%;
  height: auto;
  display: block;
}
.tip {
  position: absolute;
  top: 6px;
  transform: translateX(-50%);
  background: #1c2024;
  color: #fff;
  font-size: 11px;
  padding: 6px 9px;
  border-radius: 8px;
  pointer-events: none;
  white-space: nowrap;
  max-width: 260px;
  overflow: hidden;
  text-overflow: ellipsis;
}
.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
  margin-top: 8px;
}
.tbl th {
  text-align: left;
  font-size: 10.5px;
  color: var(--faint);
  font-weight: 600;
  padding: 4px 8px;
  border-bottom: 1px solid var(--line);
}
.tbl td {
  padding: 6px 8px;
  border-bottom: 1px solid #f1f2f4;
}
.tbl .r {
  text-align: right;
  white-space: nowrap;
}
.seg {
  display: inline-flex;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  overflow: hidden;
}
.seg button {
  padding: 5px 10px;
  border: none;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.seg button.on {
  background: #f1f2f4;
  color: var(--ink);
}
.bars {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.bar-row {
  display: grid;
  grid-template-columns: minmax(120px, 220px) 1fr 150px;
  align-items: center;
  gap: 10px;
}
@media (max-width: 640px) {
  .bar-row {
    grid-template-columns: 1fr;
    gap: 3px;
  }
}
.bl-main {
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bl-sub {
  font-size: 10.5px;
  color: var(--faint);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bar-track {
  height: 10px;
  border-radius: 99px;
  background: #eef0f4;
  overflow: hidden;
}
.bar-track span {
  display: block;
  height: 100%;
  border-radius: 99px;
  min-width: 2px;
}
.bar-val {
  display: flex;
  align-items: baseline;
  gap: 6px;
  font-size: 11px;
  color: var(--faint);
  white-space: nowrap;
}
.bar-val b {
  font-size: 13px;
}
</style>
