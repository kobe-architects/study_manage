<script setup lang="ts">
/**
 * アプリ内の書類ビューア（lib/docViewer.ts）。App.vue に 1 つだけ置く。
 * PDF は pdf.js で画面に入ったページから順に画像にして表示し（全ページを一度に描くと iPhone のメモリが足りない）、
 * 「共有・印刷」で共有シート（プリント・ファイルに保存など）を開く。印刷用 HTML は iframe で表示して印刷する。
 */
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import type { PDFDocumentProxy } from 'pdfjs-dist'
import { closeDoc, docState } from '@/lib/docViewer'
import { saveFile } from '@/lib/native'

interface PageImg {
  no: number
  url: string | null
  /** 高さ / 幅 */
  ratio: number
}

const pages = ref<PageImg[]>([])
const loading = ref(false)
const error = ref('')
const body = ref<HTMLElement | null>(null)
const frame = ref<HTMLIFrameElement | null>(null)

let doc: PDFDocumentProxy | null = null
let io: IntersectionObserver | null = null
const queue: number[] = []
let busy = false

watch(
  () => docState.seq,
  () => {
    cleanup()
    if (docState.kind === 'pdf' && docState.blob) loadPdf(docState.blob)
  },
)
watch(
  () => docState.open,
  (open) => {
    // 閉じるアニメーションが終わってから片付ける
    if (!open) window.setTimeout(() => !docState.open && cleanup(), 300)
  },
)

async function loadPdf(blob: Blob) {
  loading.value = true
  error.value = ''
  try {
    const { pdfjsLib } = await import('@/lib/pdf')
    const d = await pdfjsLib.getDocument({ data: new Uint8Array(await blob.arrayBuffer()) }).promise
    doc = d
    const first = await d.getPage(1)
    const vp = first.getViewport({ scale: 1 })
    pages.value = Array.from({ length: d.numPages }, (_, i) => ({ no: i + 1, url: null, ratio: vp.height / vp.width }))
    await nextTick()
    io = new IntersectionObserver(
      (entries) => {
        for (const e of entries) if (e.isIntersecting) enqueue(Number((e.target as HTMLElement).dataset.no))
      },
      { root: body.value, rootMargin: '800px 0px' },
    )
    body.value?.querySelectorAll('[data-no]').forEach((el) => io!.observe(el))
  } catch {
    error.value = 'PDF を表示できませんでした'
  } finally {
    loading.value = false
  }
}

function enqueue(no: number) {
  const p = pages.value[no - 1]
  if (!p || p.url || queue.includes(no)) return
  queue.push(no)
  pump()
}

/** 1 ページずつ画像にする（同時に描くとメモリを使いすぎる） */
async function pump() {
  if (busy || !doc) return
  busy = true
  const d = doc
  while (queue.length && doc === d) {
    const no = queue.shift()!
    try {
      const page = await d.getPage(no)
      const base = page.getViewport({ scale: 1 })
      const cssW = body.value?.clientWidth ?? 800
      const w = Math.min(1800, Math.round(Math.min(cssW, 900) * Math.min(2, window.devicePixelRatio || 1)))
      const vp = page.getViewport({ scale: w / base.width })
      const c = document.createElement('canvas')
      c.width = Math.ceil(vp.width)
      c.height = Math.ceil(vp.height)
      await page.render({ canvasContext: c.getContext('2d')!, viewport: vp }).promise
      const b = await new Promise<Blob | null>((r) => c.toBlob(r, 'image/jpeg', 0.85))
      c.width = 0
      c.height = 0
      page.cleanup()
      const p = pages.value[no - 1]
      if (b && p && doc === d) {
        p.url = URL.createObjectURL(b)
        p.ratio = vp.height / vp.width
      }
    } catch {
      // このページは描けなかった（空白のまま）
    }
  }
  busy = false
}

function cleanup() {
  io?.disconnect()
  io = null
  queue.length = 0
  pages.value.forEach((p) => p.url && URL.revokeObjectURL(p.url))
  pages.value = []
  doc?.destroy().catch(() => {})
  doc = null
  error.value = ''
}

async function share() {
  if (docState.kind === 'pdf') {
    if (docState.blob) await saveFile(docState.blob, docState.filename)
  } else {
    frame.value?.contentWindow?.print()
  }
}

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape' && docState.open) closeDoc()
}
window.addEventListener('keydown', onKey)
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  cleanup()
})
</script>

<template>
  <Transition name="ui-modal">
    <div v-if="docState.open" class="dv ui-fullscreen">
      <div class="dv-top">
        <button class="dv-btn" @click="closeDoc">閉じる</button>
        <div class="dv-title">{{ docState.title }}</div>
        <button class="dv-btn primary" :disabled="docState.kind === 'pdf' && !docState.blob" @click="share">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12" /><path d="M7 8l5-5 5 5" /><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" /></svg>
          {{ docState.kind === 'pdf' ? '共有・印刷' : '印刷' }}
        </button>
      </div>
      <div ref="body" class="dv-body" :class="{ html: docState.kind === 'html' }">
        <template v-if="docState.kind === 'pdf'">
          <div v-if="loading" class="dv-msg">読み込み中…</div>
          <div v-else-if="error" class="dv-msg">{{ error }}</div>
          <div v-for="p in pages" :key="p.no" :data-no="p.no" class="dv-page" :style="{ aspectRatio: `1 / ${p.ratio}` }">
            <img v-if="p.url" :src="p.url" :alt="`${p.no}ページ`" />
          </div>
          <div v-if="pages.length" class="dv-count">{{ pages.length }}ページ</div>
        </template>
        <iframe v-else ref="frame" class="dv-frame" :srcdoc="docState.html" :title="docState.title"></iframe>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.dv {
  position: fixed;
  inset: 0;
  z-index: 2500;
  display: flex;
  flex-direction: column;
  background: #eceef1;
  padding: 0 env(safe-area-inset-right) 0 env(safe-area-inset-left);
}
.dv-top {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 10px;
  padding: max(8px, env(safe-area-inset-top)) 12px 8px;
  background: #fff;
  border-bottom: 1px solid #e3e6ea;
  flex-shrink: 0;
}
.dv-top > .dv-btn:first-child {
  justify-self: start;
}
.dv-top > .dv-btn:last-child {
  justify-self: end;
}
.dv-title {
  min-width: 0;
  max-width: 46vw;
  text-align: center;
  font-size: 14px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.dv-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-height: 36px;
  padding: 0 12px;
  border: none;
  border-radius: 10px;
  background: transparent;
  color: var(--primary);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  flex-shrink: 0;
}
.dv-btn.primary {
  background: #1c2024;
  color: #fff;
}
.dv-btn:disabled {
  opacity: 0.4;
}
.dv-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 12px 12px calc(20px + env(safe-area-inset-bottom));
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}
.dv-body.html {
  padding: 0;
}
.dv-page {
  width: 100%;
  max-width: 900px;
  background: #fff;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
  flex-shrink: 0;
}
.dv-page img {
  display: block;
  width: 100%;
  height: 100%;
}
.dv-msg,
.dv-count {
  font-size: 12.5px;
  color: var(--faint);
  padding: 20px 0;
}
.dv-count {
  padding: 4px 0 0;
}
.dv-frame {
  flex: 1;
  width: 100%;
  border: none;
  background: #fff;
}
</style>
