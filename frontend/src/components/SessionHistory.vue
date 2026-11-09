<script setup lang="ts">
/**
 * 出題履歴（1 回の出題ごとの日時・問題数・正答率・1 問あたりの解答時間）。
 * スクランブル・英単語・学習カードの設定画面の下に置く。reload() で取り直せる。
 */
import { onMounted, ref, watch } from 'vue'
import { fmtMs, sessionsApi } from '@/api/sessions'
import type { QuizSessionInfo, SessionKind } from '@/types'

const props = defineProps<{ kind: SessionKind; refId?: number | null; refKey?: string | null; limit?: number; modeLabel?: (m: string | null) => string }>()
const list = ref<QuizSessionInfo[]>([])
const loading = ref(false)
const open = ref(false)

async function reload() {
  loading.value = true
  try {
    list.value = await sessionsApi.list({ kind: props.kind, refId: props.refId ?? undefined, refKey: props.refKey ?? undefined, limit: props.limit ?? 30 })
  } catch {
    list.value = []
  } finally {
    loading.value = false
  }
}
defineExpose({ reload })
onMounted(reload)
watch(() => [props.refId, props.refKey], reload)

function fmtDate(iso: string): string {
  const d = new Date(iso)
  return `${d.getMonth() + 1}/${d.getDate()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}
function rate(s: QuizSessionInfo): number {
  return s.total ? Math.round((s.correct / s.total) * 100) : 0
}
</script>

<template>
  <div class="card hist">
    <button class="hist-head bare" @click="open = !open">
      <span class="hist-title">出題履歴</span>
      <span class="hist-sub">{{ list.length ? `直近 ${list.length} 回` : '—' }}</span>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" :style="{ transform: open ? 'rotate(180deg)' : 'none', color: '#9aa1ab' }"><path d="M6 9l6 6 6-6" /></svg>
    </button>
    <div v-if="open" class="hist-body">
      <div v-if="loading" class="hist-empty">読み込み中…</div>
      <div v-else-if="!list.length" class="hist-empty">まだ出題の記録がありません</div>
      <table v-else class="hist-tbl">
        <thead>
          <tr><th>日時</th><th v-if="modeLabel">形式</th><th class="r">問題数</th><th class="r">正解</th><th class="r">正答率</th><th class="r">1問あたり</th></tr>
        </thead>
        <tbody>
          <tr v-for="s in list" :key="s.id">
            <td class="dm">{{ fmtDate(s.startedAt) }}</td>
            <td v-if="modeLabel">{{ modeLabel(s.mode) }}</td>
            <td class="r dm">{{ s.total }}</td>
            <td class="r dm">{{ s.correct }}<span v-if="s.unsure" class="un">（△{{ s.unsure }}）</span></td>
            <td class="r dm" :style="{ color: rate(s) >= 80 ? '#2e9d62' : rate(s) >= 50 ? '#d98a2b' : '#cf5563', fontWeight: 700 }">{{ rate(s) }}%</td>
            <td class="r dm">{{ fmtMs(s.avgMs) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.hist {
  overflow: hidden;
}
.hist-head {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 12px 16px;
  text-align: left;
}
.hist-title {
  font-size: 13.5px;
  font-weight: 700;
}
.hist-sub {
  flex: 1;
  font-size: 11.5px;
  color: var(--faint);
}
.hist-body {
  border-top: 1px solid #f0f1f3;
  padding: 6px 12px 10px;
  overflow-x: auto;
}
.hist-empty {
  padding: 14px 4px;
  font-size: 12.5px;
  color: var(--faint);
}
.hist-tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}
.hist-tbl th {
  padding: 6px 6px;
  font-size: 11px;
  font-weight: 600;
  color: var(--faint);
  text-align: left;
  border-bottom: 1px solid #eef0f3;
  white-space: nowrap;
}
.hist-tbl td {
  padding: 7px 6px;
  border-bottom: 1px solid #f4f5f7;
  white-space: nowrap;
}
.hist-tbl .r {
  text-align: right;
}
.un {
  font-size: 10.5px;
  color: #2f5fc4;
  margin-left: 2px;
}
</style>
