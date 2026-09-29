<script setup lang="ts">
import { computed } from 'vue'
import { rateColor } from '@/api/quiz'
import type { QuizStats } from '@/types'

/**
 * 小テストの分析（生徒・講師共用）。採点は小テスト全体の得点／満点。
 * 要約タイルのあと、科目ごと → 教材ごとに採点済みの小テスト（得点・得点率・採点日）を表示する。
 */
const props = defineProps<{ stats: QuizStats }>()

const s = computed(() => props.stats.summary)
const hasData = computed(() => s.value.gradedCount > 0)
const subjects = computed(() => props.stats.bySubject ?? [])

/** 背景色の明るさに応じて文字色を白／黒にする */
function textOn(bg: string): string {
  const m = /^#?([0-9a-f]{6})$/i.exec(bg.trim())
  if (!m) return '#fff'
  const n = parseInt(m[1]!, 16)
  const lum = (0.2126 * ((n >> 16) & 255) + 0.7152 * ((n >> 8) & 255) + 0.0722 * (n & 255)) / 255
  return lum > 0.6 ? '#1c2024' : '#fff'
}
function ymd(d: string | null): string {
  return d ? d.replace(/-/g, '/') : ''
}
</script>

<template>
  <div class="stats">
    <div v-if="!hasData" class="empty">
      採点・添削済みの小テストがまだありません。採点・添削が完了すると、科目・教材ごとの得点率がここに表示されます。
      <div v-if="s.quizCount" style="margin-top: 6px; font-size: 11.5px">
        出題 {{ s.quizCount }}件（未提出 {{ s.assignedCount }}・採点・添削待ち {{ s.submittedCount }}）
      </div>
    </div>

    <template v-else>
      <!-- 要約 -->
      <div class="tiles">
        <div class="tile">
          <div class="t-label">合計得点率</div>
          <div class="t-val"><b :style="{ color: rateColor(s.avgRate) }">{{ s.avgRate ?? '–' }}</b><span>%</span></div>
          <div class="t-sub">合計 {{ s.score }} / {{ s.max }}点</div>
        </div>
        <div class="tile">
          <div class="t-label">1回あたりの平均得点率</div>
          <div class="t-val"><b :style="{ color: rateColor(s.avgQuizRate) }">{{ s.avgQuizRate ?? '–' }}</b><span>%</span></div>
          <div class="t-sub">最高 {{ s.bestRate ?? '–' }}%・直近 {{ s.lastRate ?? '–' }}%</div>
        </div>
        <div class="tile">
          <div class="t-label">採点・添削済み</div>
          <div class="t-val"><b>{{ s.gradedCount }}</b><span>回</span></div>
          <div class="t-sub">{{ s.pageCount }}ページ分</div>
        </div>
        <div class="tile">
          <div class="t-label">進行中</div>
          <div class="t-val"><b>{{ s.assignedCount + s.submittedCount }}</b><span>件</span></div>
          <div class="t-sub">未提出 {{ s.assignedCount }}・採点・添削待ち {{ s.submittedCount }}</div>
        </div>
      </div>

      <!-- 科目ごと → 教材ごと -->
      <div v-for="sub in subjects" :key="sub.name" class="subject">
        <div class="subj-head">
          <span class="subj-badge" :style="{ background: sub.color, color: textOn(sub.color) }">{{ sub.name }}</span>
          <span class="subj-total"><b :style="{ color: rateColor(sub.rate) }">{{ sub.rate ?? '–' }}%</b>{{ sub.score }} / {{ sub.max }}点・{{ sub.quizCount }}回</span>
        </div>

        <div v-for="b in sub.books" :key="b.bookId ?? 'vocab'" class="card book">
          <div class="book-head">
            <div class="book-title">{{ b.title }}</div>
            <div class="book-rate">
              <span class="bar-track"><span :style="{ width: (b.rate ?? 0) + '%', background: rateColor(b.rate) }"></span></span>
              <b :style="{ color: rateColor(b.rate) }">{{ b.rate ?? '–' }}%</b>
              <span class="book-sub">{{ b.score }} / {{ b.max }}点・{{ b.quizCount }}回</span>
            </div>
          </div>

          <table class="tbl">
            <thead>
              <tr>
                <th>採点日</th>
                <th>小テスト</th>
                <th class="r">得点</th>
                <th class="r">得点率</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="q in b.quizzes" :key="q.id">
                <td class="nowrap">{{ ymd(q.gradedOn) }}</td>
                <td>{{ q.title }}<span v-if="q.selfGraded" class="self">自己採点</span></td>
                <td class="r"><b>{{ q.score }}</b> / {{ q.max }}</td>
                <td class="r">
                  <span class="mini-track"><span :style="{ width: q.rate + '%', background: rateColor(q.rate) }"></span></span>
                  <b :style="{ color: rateColor(q.rate) }">{{ q.rate }}%</b>
                </td>
              </tr>
            </tbody>
          </table>

        </div>
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
.subject {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.subj-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 2px;
}
.subj-badge {
  display: inline-block;
  padding: 3px 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.5;
}
.subj-total {
  font-size: 11.5px;
  color: var(--mut);
}
.subj-total b {
  font-size: 14px;
  margin-right: 6px;
}
.card {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
}
.book {
  padding: 12px 16px 14px;
}
.book-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.book-title {
  font-size: 13px;
  font-weight: 700;
}
.book-rate {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 11.5px;
}
.book-rate .bar-track {
  width: 140px;
}
.book-rate b {
  font-size: 14px;
}
.book-sub {
  color: var(--faint);
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
  padding: 5px 8px;
  border-bottom: 1px solid #f1f2f4;
}
.tbl tbody tr:nth-child(even) {
  background: #eef2f9;
}
.tbl .r {
  text-align: right;
  white-space: nowrap;
}
.nowrap {
  white-space: nowrap;
}
.self {
  display: inline-block;
  margin-left: 6px;
  font-size: 10px;
  font-weight: 700;
  padding: 1px 7px;
  border-radius: 999px;
  background: #efe9fb;
  color: #5b3fa0;
  vertical-align: middle;
}
.mini-track {
  display: inline-block;
  width: 70px;
  height: 7px;
  border-radius: 99px;
  background: #eef0f4;
  overflow: hidden;
  vertical-align: middle;
  margin-right: 8px;
}
.mini-track span {
  display: block;
  height: 100%;
  border-radius: 99px;
  min-width: 2px;
}
@media (max-width: 640px) {
  .mini-track {
    display: none;
  }
}
.bar-track {
  display: block;
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
</style>
