<script setup lang="ts">
/**
 * 請求書の発行フロー（ステップ表示）。生徒・講師の請求書管理で共用。
 * 完了したステップはチェック、いま止まっているステップは青い輪で示す。各ステップの担当者は実名のバッジ（生徒=緑・講師=青）。
 * 説明文は普段は出さず、ステップをタップしたときだけ下に表示する。
 */
import { computed, ref } from 'vue'
import type { InvoiceStatus } from '@/types'

const props = defineProps<{
  status: InvoiceStatus | null
  /** 生徒の氏名（バッジに表示） */
  studentName: string
  /** 講師の氏名（バッジに表示） */
  tutorName: string
}>()

interface Step {
  key: InvoiceStatus
  title: string
  who: 'student' | 'tutor'
  desc: string
}
const STEPS: Step[] = [
  { key: 'open', title: '稼働時間を登録', who: 'student', desc: '日付と開始〜終了（30分刻み）を月ごとに登録する' },
  { key: 'closed', title: '締め', who: 'student', desc: '入力を確定する。以降は編集できない（締め解除で戻せる）' },
  { key: 'issued', title: '仮発行', who: 'student', desc: '講師へ請求書を送る（LINE で通知）' },
  // ZWSP: 幅が足りないときは「・」の後ろで折り返す（単語の途中で切れないように）
  { key: 'confirmed', title: '内容確認・​正式発行', who: 'tutor', desc: '講師が請求内容を確認して正式発行にする' },
  { key: 'paid', title: '支払い', who: 'student', desc: '支払い後に「支払済み」に更新する（LINE で通知）' },
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
    name: s.who === 'student' ? props.studentName : props.tutorName,
  })),
)

/** タップして説明を表示しているステップ */
const openKey = ref<InvoiceStatus | null>(null)
const openStep = computed(() => steps.value.find((s) => s.key === openKey.value) ?? null)
function toggle(key: InvoiceStatus) {
  openKey.value = openKey.value === key ? null : key
}
</script>

<template>
  <div class="flow">
    <div class="steps" role="list" aria-label="請求書の発行フロー">
      <button
        v-for="s in steps"
        :key="s.key"
        type="button"
        class="step"
        :class="[s.state, { open: openKey === s.key }]"
        role="listitem"
        :aria-current="s.state === 'current' ? 'step' : undefined"
        :aria-expanded="openKey === s.key"
        @click="toggle(s.key)"
      >
        <span class="line-l"></span>
        <span class="line-r"></span>
        <span class="node">
          <svg v-if="s.state === 'done'" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7" /></svg>
          <span v-else-if="s.state === 'current'" class="cur-dot"></span>
          <span v-else class="num">{{ s.n }}</span>
        </span>
        <span v-if="s.state === 'current'" class="now">いまここ</span>
        <span class="title">{{ s.title }}</span>
        <span class="who" :class="s.who">{{ s.name }}</span>
      </button>
    </div>

    <!-- タップしたステップの説明 -->
    <Transition name="detail">
      <div v-if="openStep" class="detail">
        <span class="d-num" :class="openStep.state">{{ openStep.n }}</span>
        <span class="d-title">{{ openStep.title }}</span>
        <span class="who sm" :class="openStep.who">{{ openStep.name }}</span>
        <span class="d-desc">{{ openStep.desc }}</span>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.flow {
  --done: #2e4a8f;
  --cur: #4f8ddb;
  --todo: #dfe4ec;
}
.steps {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
}
.step {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  min-width: 0;
  padding: 22px 4px 8px;
  border: none;
  border-radius: 12px;
  background: transparent;
  font-family: inherit;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.step.open {
  background: #f3f5f9;
}
/* ノード間の線（左半分・右半分）。進んだところまで濃い色 */
.line-l,
.line-r {
  position: absolute;
  top: 37px;
  height: 3px;
  background: var(--todo);
  transition: background 0.3s ease;
}
.line-l {
  left: 0;
  right: 50%;
}
.line-r {
  left: 50%;
  right: 0;
}
.step:first-child .line-l,
.step:last-child .line-r {
  display: none;
}
.step.done .line-l,
.step.current .line-l,
.step.done .line-r {
  background: var(--done);
}
.node {
  position: relative;
  z-index: 1;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--todo);
  color: #7a8394;
  font-size: 12px;
  font-weight: 700;
  transition: background 0.3s ease, color 0.3s ease, box-shadow 0.3s ease;
}
.step.done .node {
  background: var(--done);
  color: #fff;
}
.step.current .node {
  background: #fff;
  box-shadow: 0 0 0 3px var(--cur);
  animation: nodePulse 1.8s ease-in-out infinite;
}
.cur-dot {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: var(--cur);
}
.now {
  position: absolute;
  top: 0;
  left: 50%;
  transform: translateX(-50%);
  padding: 1px 8px;
  border-radius: 999px;
  background: var(--cur);
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  white-space: nowrap;
  line-height: 1.5;
}
.title {
  font-size: 12px;
  font-weight: 700;
  line-height: 1.3;
  text-align: center;
  color: var(--ink);
  /* 日本語の途中では折り返さず、入りきらないときだけ任意の位置で折る */
  word-break: keep-all;
  overflow-wrap: anywhere;
}
.step.todo .title {
  color: var(--mut);
  font-weight: 600;
}
.step.done .title {
  color: var(--done);
}
/* 担当者の名前バッジ（生徒=緑・講師=青） */
.who {
  max-width: 100%;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 10.5px;
  font-weight: 700;
  line-height: 1.5;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.who.student {
  background: #e6f5ec;
  color: #2f7a4f;
}
.who.tutor {
  background: #e8eefb;
  color: #2e4a8f;
}
.step.todo .who {
  opacity: 0.55;
}
@keyframes nodePulse {
  0%,
  100% {
    box-shadow: 0 0 0 3px var(--cur);
  }
  50% {
    box-shadow:
      0 0 0 3px var(--cur),
      0 0 0 9px rgba(79, 141, 219, 0.18);
  }
}

/* 説明 */
.detail {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 6px 8px;
  margin-top: 8px;
  padding: 10px 12px;
  border-radius: 10px;
  background: #f3f5f9;
  font-size: 12.5px;
  line-height: 1.6;
}
.d-num {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
  background: var(--todo);
  color: #7a8394;
  align-self: center;
}
.d-num.done {
  background: var(--done);
  color: #fff;
}
.d-num.current {
  background: var(--cur);
  color: #fff;
}
.d-title {
  font-weight: 700;
}
.who.sm {
  align-self: center;
}
.d-desc {
  flex-basis: 100%;
  color: var(--mut);
}
.detail-enter-active,
.detail-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}
.detail-enter-from,
.detail-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

/* 幅が足りないとき（スマホ）: 縦に並べる */
@media (max-width: 640px) {
  .steps {
    grid-template-columns: 1fr;
  }
  .step {
    flex-direction: row;
    align-items: center;
    gap: 10px;
    padding: 7px 8px 7px 4px;
    text-align: left;
  }
  .line-l,
  .line-r {
    left: 18px;
    right: auto;
    width: 3px;
    height: auto;
  }
  .line-l {
    top: 0;
    bottom: 50%;
  }
  .line-r {
    top: 50%;
    bottom: 0;
  }
  .node {
    flex-shrink: 0;
  }
  .now {
    position: static;
    transform: none;
    order: 3;
  }
  .title {
    text-align: left;
    font-size: 13px;
    min-width: 0;
  }
  .who {
    order: 2;
    flex-shrink: 0;
  }
}
</style>
