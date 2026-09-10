<script setup lang="ts">
/**
 * ハテナアイコン。マウスホバー（またはタップ・フォーカス）で説明文をポップアップ表示する。
 * 画面右端に近い場所では align="right" で吹き出しを左側に展開する。
 */
defineProps<{ text: string; align?: 'left' | 'right' }>()
</script>

<template>
  <span class="help" :class="{ r: align === 'right' }" tabindex="0" role="button" aria-label="説明">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10" />
      <path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4" />
      <circle cx="12" cy="17.5" r="0.6" fill="currentColor" stroke="none" />
    </svg>
    <span class="tip">{{ text }}</span>
  </span>
</template>

<style scoped>
.help {
  position: relative;
  display: inline-flex;
  align-items: center;
  color: #9aa1ab;
  cursor: help;
  outline: none;
  flex-shrink: 0;
}
.help:hover,
.help:focus {
  color: #3b50cc;
}
.tip {
  display: none;
  position: absolute;
  top: calc(100% + 8px);
  left: -10px;
  width: min(340px, 78vw);
  background: #1c2024;
  color: #fff;
  font-size: 11.5px;
  font-weight: 400;
  line-height: 1.8;
  padding: 10px 13px;
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
  white-space: pre-line;
  z-index: 120;
  text-align: left;
}
.help.r .tip {
  left: auto;
  right: -10px;
}
.tip::before {
  content: '';
  position: absolute;
  top: -5px;
  left: 14px;
  width: 10px;
  height: 10px;
  background: #1c2024;
  transform: rotate(45deg);
}
.help.r .tip::before {
  left: auto;
  right: 14px;
}
.help:hover .tip,
.help:focus .tip {
  display: block;
}
</style>
