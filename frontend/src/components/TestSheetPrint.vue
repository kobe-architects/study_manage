<script setup lang="ts">
import { shuffle } from '@/lib/design'
import type { PrintTestFormat, PrintTestType, Vocabulary } from '@/types'

const props = defineProps<{
  words: Vocabulary[]
  allWords: Vocabulary[]
  resourceName: string
  testType: PrintTestType
  testFormat: PrintTestFormat
}>()
const emit = defineEmits<{
  close: []
  'update:testType': [v: PrintTestType]
  'update:testFormat': [v: PrintTestFormat]
}>()

const typeOptions: { v: PrintTestType; label: string }[] = [
  { v: 'meaning', label: '意味を回答（単語→意味）' },
  { v: 'spelling', label: 'スペル書き取り（意味→単語）' },
  { v: 'fill_spelling', label: '例文穴埋め（書き取り）' },
]
const formatOptions: { v: PrintTestFormat; label: string }[] = [
  { v: 'free', label: 'フリー回答方式' },
  { v: 'choice', label: '4択選択方式' },
]

const typeLabel = () => typeOptions.find((o) => o.v === props.testType)?.label ?? ''
const formatLabel = () => formatOptions.find((o) => o.v === props.testFormat)?.label ?? ''

function esc(s: string | null | undefined): string {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

// fill_spelling は例文のある単語のみ
function targetWords(): Vocabulary[] {
  return props.testType === 'fill_spelling'
    ? props.words.filter((w) => w.exampleSentence)
    : props.words
}

function question(w: Vocabulary): string {
  if (props.testType === 'meaning') return w.word
  if (props.testType === 'spelling') return w.meaning
  return fillSentence(w)
}
function answer(w: Vocabulary): string {
  return props.testType === 'meaning' ? w.meaning : w.word
}

function fillSentence(w: Vocabulary): string {
  if (!w.exampleSentence) return ''
  try {
    const re = new RegExp('\\b' + w.word + '[a-z]*', 'i')
    return w.exampleSentence.replace(re, '(　________　)')
  } catch {
    return w.exampleSentence
  }
}

function choicesFor(w: Vocabulary): string[] {
  const correct = answer(w)
  const pool = props.allWords
    .filter((x) => x.id !== w.id)
    .map((x) => (props.testType === 'meaning' ? x.meaning : x.word))
  const dummies = shuffle(pool).filter((m) => m !== correct).slice(0, 3)
  return shuffle([correct, ...dummies])
}

/** 印刷用シートのHTMLを組み立てる */
function buildHtml(): string {
  const words = targetWords()
  const isChoice = props.testFormat === 'choice'
  const isFill = props.testType === 'fill_spelling'
  // 穴埋め・4択は1列、フリー（意味/スペル）は2列
  const qCols = isFill || isChoice ? 1 : 2
  const listClass = isChoice ? 'choice' : isFill ? 'fill' : 'free'

  const qItems = words
    .map((w, i) => {
      const no = `<span class="n">${i + 1}</span>`
      if (isChoice) {
        const opts = choicesFor(w)
          .map((c, ci) => `<span class="op">${'ABCD'[ci]}. ${esc(c)}</span>`)
          .join('')
        return `<li>${no}<div class="cbody"><div class="cq"><span class="q">${esc(question(w))}</span><span class="pick">（　　）</span></div><div class="opts">${opts}</div></div></li>`
      }
      if (isFill) {
        const tr = w.exampleTranslation
          ? `<div class="qtr">${esc(w.exampleTranslation)}</div>`
          : ''
        return `<li>${no}<div class="qbody"><div class="q">${esc(question(w))}</div>${tr}</div></li>`
      }
      // フリー（意味/スペル）：番号・単語・解答欄を固定幅で揃える
      return `<li>${no}<span class="q">${esc(question(w))}</span><span class="blank">答<span class="ul"></span></span></li>`
    })
    .join('')

  const aItems = words
    .map((w, i) => {
      const pos = w.partOfSpeech ? ` <span class="pos">［${esc(w.partOfSpeech)}］</span>` : ''
      const memo = w.memo ? `<span class="memo">※${esc(w.memo)}</span>` : ''
      // 例文穴埋め（書き取り）はその単語の意味も併記する
      const mean = isFill && w.meaning ? `<span class="amean">（${esc(w.meaning)}）</span>` : ''
      return `<li><span class="n">${i + 1}</span><span class="qa"><strong>${esc(question(w))}</strong> — ${esc(answer(w))}${pos}${mean}</span>${memo}</li>`
    })
    .join('')

  const title = esc(props.resourceName) + ' 小テスト'
  const sub = `${esc(typeLabel())}・${esc(formatLabel())}（全${words.length}問）`

  return `<!DOCTYPE html>
<html lang="ja"><head><meta charset="utf-8"><title>${title}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, "Segoe UI", "Hiragino Kaku Gothic ProN", "Yu Gothic", Meiryo, sans-serif; color: #1c2024; margin: 0; padding: 24px; background: #eceef1; min-height: 100vh; display: flex; justify-content: center; align-items: center; }
  .sheet { width: 100%; max-width: 820px; background: #fff; padding: 32px 40px; box-shadow: 0 6px 24px rgba(0, 0, 0, 0.12); }
  .toolbar { position: fixed; top: 14px; right: 16px; }
  .toolbar button { padding: 8px 18px; border: none; border-radius: 8px; background: #3b50cc; color: #fff; font-size: 13px; font-weight: 700; cursor: pointer; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2); }
  .page { page-break-after: always; }
  .page:last-child { page-break-after: auto; }
  .head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; border-bottom: 2px solid #1c2024; padding-bottom: 8px; margin-bottom: 18px; }
  h1 { font-size: 18px; margin: 0; }
  .sub { font-size: 12px; color: #6b7280; margin-top: 3px; }
  .meta { font-size: 12px; white-space: nowrap; }
  ol { padding-left: 0; list-style: none; margin: 0; }
  .qlist { column-gap: 20px; }
  .qlist li { break-inside: avoid; margin-bottom: 22px; }
  .n { font-weight: 700; font-size: 12px; color: #1c2024; }
  .q { font-weight: 600; font-size: 14px; word-break: break-word; }

  /* フリー（意味/スペル）：番号 / 単語 / 解答欄 を固定幅グリッドで統一 */
  .qlist.free li { display: grid; grid-template-columns: 26px 150px 1fr; gap: 8px; align-items: end; }
  .qlist.free .n { text-align: right; align-self: center; }
  .qlist.free .blank { display: flex; align-items: flex-end; gap: 5px; color: #6b7280; font-size: 12px; }
  .qlist.free .blank .ul { flex: 1; border-bottom: 1px solid #b9bfc7; height: 15px; }

  /* 穴埋め */
  .qlist.fill li { display: grid; grid-template-columns: 26px 1fr; gap: 8px; align-items: baseline; }
  .qlist.fill .n { text-align: right; }
  .qlist.fill .q { font-weight: 500; line-height: 1.7; }
  .qlist.fill .qtr { color: #6b7280; font-size: 12px; line-height: 1.6; margin-top: 3px; }

  /* 4択 */
  .qlist.choice li { display: grid; grid-template-columns: 26px 1fr; gap: 8px; align-items: baseline; }
  .qlist.choice .n { text-align: right; }
  .cq { display: flex; align-items: baseline; gap: 8px; }
  .pick { margin-left: auto; font-size: 13px; }
  .opts { display: flex; flex-wrap: wrap; gap: 4px 18px; margin-top: 4px; font-size: 12px; color: #4b5563; }

  .alist { column-gap: 24px; }
  .alist li { display: grid; grid-template-columns: 26px 1fr; gap: 8px; break-inside: avoid; margin-bottom: 7px; font-size: 12.5px; align-items: baseline; }
  .alist .n { text-align: right; }
  .alist strong { font-weight: 700; }
  .pos { color: #6b7280; font-size: 11px; }
  .amean { color: #4b5563; font-size: 11.5px; }
  .memo { grid-column: 2; color: #9aa1ab; font-size: 11px; }

  @media print {
    .toolbar { display: none; }
    body { display: block; background: #fff; padding: 0; min-height: 0; }
    .sheet { max-width: none; box-shadow: none; padding: 0; }
  }
</style></head>
<body>
  <div class="toolbar"><button onclick="window.print()">印刷</button></div>
  <div class="sheet">
    <section class="page">
      <div class="head">
        <div><h1>${title}</h1><div class="sub">${sub}</div></div>
        <div class="meta">名前 ____________　日付 ____ / ____　点 ____ / ${words.length}</div>
      </div>
      <ol class="qlist ${listClass}" style="column-count:${qCols}">${qItems}</ol>
    </section>

    <section class="page">
      <div class="head"><h1>解答</h1><div class="meta">${esc(props.resourceName)}</div></div>
      <ol class="alist" style="column-count:2">${aItems}</ol>
    </section>
  </div>

  <script>window.onload = function () { window.print(); };<\/script>
</body></html>`
}

function doPrint() {
  const words = targetWords()
  if (!words.length) {
    window.alert('対象の単語がありません')
    return
  }
  const win = window.open('', '_blank')
  if (!win) {
    window.alert('ポップアップがブロックされました。ブラウザの設定で許可してください。')
    return
  }
  win.document.write(buildHtml())
  win.document.close()
  emit('close')
}
</script>

<template>
  <div class="overlay no-print" @click="emit('close')">
    <div class="modal" @click.stop>
      <div style="font-size: 16px; font-weight: 700; margin-bottom: 18px">小テストの種類</div>

      <div class="sec-label">テストタイプ</div>
      <div class="opt-list">
        <button
          v-for="o in typeOptions"
          :key="o.v"
          class="opt"
          :class="{ on: testType === o.v }"
          @click="emit('update:testType', o.v)"
        >
          <span class="radio" :class="{ on: testType === o.v }"></span>
          {{ o.label }}
        </button>
      </div>

      <div class="divider"></div>

      <div class="sec-label">回答方式</div>
      <div class="opt-list">
        <button
          v-for="o in formatOptions"
          :key="o.v"
          class="opt"
          :class="{ on: testFormat === o.v }"
          @click="emit('update:testFormat', o.v)"
        >
          <span class="radio" :class="{ on: testFormat === o.v }"></span>
          {{ o.label }}
        </button>
      </div>

      <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 24px">
        <button class="btn-ghost" @click="emit('close')">キャンセル</button>
        <button class="btn-print" @click="doPrint">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z" /></svg>
          印刷
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.5);
  z-index: 60;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.modal {
  background: #fff;
  border-radius: 16px;
  padding: 26px;
  width: 100%;
  max-width: 460px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
}
.sec-label {
  font-size: 12px;
  font-weight: 700;
  color: var(--mut);
  margin-bottom: 10px;
}
.opt-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.opt {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 11px 14px;
  border: 1px solid #e3e6ea;
  border-radius: 11px;
  background: #fff;
  cursor: pointer;
  font-size: 14px;
  font-weight: 500;
  color: #1c2024;
  text-align: left;
}
.opt.on {
  border-color: #1c2024;
  background: #f8f9fb;
}
.radio {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 2px solid #cbd1d8;
  flex-shrink: 0;
  position: relative;
}
.radio.on {
  border-color: #1c2024;
}
.radio.on::after {
  content: '';
  position: absolute;
  inset: 3px;
  border-radius: 50%;
  background: #1c2024;
}
.divider {
  height: 1px;
  background: #eef0f3;
  margin: 20px 0;
}
.btn-ghost {
  padding: 9px 18px;
  border: none;
  background: none;
  color: var(--mut);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-print {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 10px 20px;
  border: none;
  border-radius: 10px;
  background: #3b50cc;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
</style>
