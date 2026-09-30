<script setup lang="ts">
/**
 * 請求書の発行フロー図（矢羽根のステップ表示）。生徒・講師の請求書管理で共用。
 * 現在のステータスまでを濃い色、次にやる操作を明るい色で示す。「あなた」= 見ている側の操作。
 */
import { computed } from 'vue'
import type { InvoiceStatus } from '@/types'

const props = defineProps<{ status: InvoiceStatus | null; role: 'owner' | 'tutor' }>()

interface Step {
  key: InvoiceStatus
  title: string
  who: 'owner' | 'tutor'
  desc: string
}
const STEPS: Step[] = [
  { key: 'open', title: '稼働時間を登録', who: 'owner', desc: '日付と開始〜終了（30分刻み）を月ごとに登録する' },
  { key: 'closed', title: '締め', who: 'owner', desc: '入力を確定する。以降は編集できない（締め解除で戻せる）' },
  { key: 'issued', title: '請求書を仮発行', who: 'owner', desc: '講師へ請求書を送る（LINE で通知）' },
  { key: 'confirmed', title: '内容確認・正式発行', who: 'tutor', desc: '講師が請求内容を確認して正式発行にする' },
  { key: 'paid', title: '支払い', who: 'owner', desc: '支払い後に「支払済み」に更新する（LINE で通知）' },
  { key: 'done', title: '支払確認', who: 'tutor', desc: '講師が入金を確認して完了' },
]

/** 完了したステップ数（open＝登録中は 0） */
const doneCount = computed(() => {
  if (!props.status || props.status === 'open') return 0
  return STEPS.findIndex((s) => s.key === props.status) + 1
})

const steps = computed(() =>
  STEPS.map((s, i) => ({
    ...s,
    n: i + 1,
    state: i < doneCount.value ? 'done' : i === doneCount.value ? 'current' : 'todo',
    actor: s.who === props.role ? 'あなた' : s.who === 'owner' ? '生徒' : '講師',
  })),
)
</script>

<template>
  <div class="flow" role="list" aria-label="請求書の発行フロー">
    <div v-for="s in steps" :key="s.key" class="step" :class="s.state" role="listitem" :aria-current="s.state === 'current' ? 'step' : undefined">
      <span class="num">{{ s.n }}</span>
      <div class="chev">
        <span class="actor">{{ s.actor }}</span>
        <span class="title">{{ s.title }}</span>
      </div>
      <div class="desc">
        <span v-if="s.state === 'current'" class="now">いまここ</span>
        {{ s.desc }}
      </div>
    </div>
  </div>
</template>

<style scoped>
.flow {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 0;
}
.step {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
/* 矢羽根 */
.chev {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  min-height: 66px;
  padding: 8px 18px 8px 22px;
  margin-right: -8px;
  clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 50%, calc(100% - 16px) 100%, 0 100%, 16px 50%);
  background: #dfe6f2;
  color: #5b6577;
  text-align: center;
  transition: background 0.3s ease, color 0.3s ease;
}
.step:first-child .chev {
  clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 50%, calc(100% - 16px) 100%, 0 100%);
  padding-left: 14px;
  border-radius: 10px 0 0 10px;
}
.step:last-child .chev {
  margin-right: 0;
}
.step.done .chev {
  background: #2e4a8f;
  color: #fff;
}
.step.current .chev {
  background: #4f8ddb;
  color: #fff;
  animation: flowPulse 1.6s ease-in-out infinite;
}
.num {
  display: none;
}
.actor {
  font-size: 10px;
  font-weight: 600;
  opacity: 0.85;
  line-height: 1.2;
}
.title {
  font-size: 12.5px;
  font-weight: 700;
  line-height: 1.3;
  word-break: keep-all;
}
.desc {
  padding: 8px 8px 0 4px;
  font-size: 11px;
  line-height: 1.55;
  color: var(--mut);
  text-align: center;
}
.step.todo .desc {
  color: var(--faint);
}
.now {
  display: inline-block;
  margin-bottom: 3px;
  padding: 1px 8px;
  border-radius: 999px;
  background: #4f8ddb;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
}
@keyframes flowPulse {
  0%,
  100% {
    filter: brightness(1);
  }
  50% {
    filter: brightness(1.12);
  }
}

/* 幅が足りないとき（スマホ）: 縦に並べ、番号付きの行にする */
@media (max-width: 720px) {
  .flow {
    grid-template-columns: 1fr;
    gap: 8px;
  }
  .step {
    display: grid;
    grid-template-columns: 26px minmax(0, 1fr);
    grid-template-areas:
      'num chev'
      'num desc';
    column-gap: 10px;
    align-items: start;
  }
  .num {
    grid-area: num;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: #dfe6f2;
    color: #5b6577;
    font-size: 12px;
    font-weight: 700;
  }
  .step.done .num {
    background: #2e4a8f;
    color: #fff;
  }
  .step.current .num {
    background: #4f8ddb;
    color: #fff;
    box-shadow: 0 0 0 4px #dce9f9;
  }
  .chev {
    grid-area: chev;
    flex-direction: row;
    align-items: baseline;
    justify-content: flex-start;
    gap: 6px;
    min-height: 26px;
    padding: 0;
    margin: 0;
    clip-path: none;
    border-radius: 0;
    background: transparent !important;
    color: var(--ink);
    animation: none;
    text-align: left;
  }
  .step:first-child .chev {
    padding-left: 0;
  }
  .step.done .chev,
  .step.current .chev {
    color: var(--ink);
  }
  .step.todo .chev {
    color: var(--mut);
  }
  .actor {
    order: 2;
    font-size: 10.5px;
    color: var(--faint);
    opacity: 1;
  }
  .title {
    order: 1;
    font-size: 13px;
  }
  .desc {
    grid-area: desc;
    padding: 1px 0 0;
    text-align: left;
  }
}
</style>
