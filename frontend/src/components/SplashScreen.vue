<script setup lang="ts">
/** 起動時の読み込み画面（index.html の起動スプラッシュと同じ見た目で、切り替わりが分からないようにする） */
defineProps<{ message?: string }>()
</script>

<template>
  <div class="splash">
    <div class="logo">
      <svg width="40" height="40" viewBox="0 0 32 32" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M16 9.6C14.5 8.4 12.3 7.8 9.7 7.8C8.5 7.8 7.5 7.9 6.7 8.1V23.1C7.5 22.9 8.5 22.8 9.7 22.8C12.3 22.8 14.5 23.4 16 24.6" />
        <path d="M16 9.6C17.5 8.4 19.7 7.8 22.3 7.8C23.5 7.8 24.5 7.9 25.3 8.1V23.1C24.5 22.9 23.5 22.8 22.3 22.8C19.7 22.8 17.5 23.4 16 24.6" />
        <path d="M16 9.6V24.6" />
      </svg>
    </div>
    <div class="msg">
      <span class="dot"></span>
      {{ message ?? '読み込み中…' }}
    </div>
  </div>
</template>

<style scoped>
.splash {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg);
}
.logo {
  width: 68px;
  height: 68px;
  border-radius: 17px;
  background: #1c2024;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 12px 30px rgba(28, 32, 36, 0.18);
}
/* ロゴの位置は index.html と同じ（画面中央）にし、文言はその下に重ねて出す */
.msg {
  position: absolute;
  top: calc(50% + 56px);
  left: 0;
  right: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 12.5px;
  color: var(--faint);
  letter-spacing: 0.04em;
  /* 一瞬で読み込みが終わるときは文言を出さない */
  animation: splashMsg 0.3s ease 0.35s both;
}
.dot {
  width: 14px;
  height: 14px;
  border: 2px solid #dfe2e7;
  border-top-color: #3b50cc;
  border-radius: 50%;
  animation: splashSpin 0.8s linear infinite;
}
@keyframes splashMsg {
  from {
    opacity: 0;
  }
}
@keyframes splashSpin {
  to {
    transform: rotate(360deg);
  }
}
</style>
