<script setup lang="ts">
/** 引っ張って更新のくるくる（スクロール領域の先頭、コンテンツが下がった隙間に表示する） */
import { computed } from 'vue'

const props = defineProps<{ pull: number; trigger: number; refreshing: boolean }>()

const progress = computed(() => Math.min(1, props.pull / props.trigger))
</script>

<template>
  <div v-show="pull > 0 || refreshing" class="ptr" :style="{ height: pull + 'px' }" aria-hidden="true">
    <div class="ball" :class="{ armed: progress >= 1 || refreshing }" :style="{ opacity: refreshing ? 1 : progress }">
      <svg
        width="20"
        height="20"
        viewBox="0 0 24 24"
        :class="{ spin: refreshing }"
        :style="refreshing ? undefined : { transform: `rotate(${progress * 270}deg)` }"
      >
        <circle cx="12" cy="12" r="9" fill="none" stroke="#e3e6ea" stroke-width="2.4" />
        <circle
          cx="12"
          cy="12"
          r="9"
          fill="none"
          stroke="#3b50cc"
          stroke-width="2.4"
          stroke-linecap="round"
          stroke-dasharray="56.5"
          :stroke-dashoffset="refreshing ? 40 : 56.5 - 42 * progress"
        />
      </svg>
    </div>
  </div>
</template>

<style scoped>
.ptr {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  pointer-events: none;
}
.ball {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.12);
  display: flex;
  align-items: center;
  justify-content: center;
  transform: scale(0.85);
  transition: transform 0.2s ease;
}
.ball.armed {
  transform: scale(1);
}
.spin {
  animation: ptrSpin 0.7s linear infinite;
}
@keyframes ptrSpin {
  to {
    transform: rotate(360deg);
  }
}
</style>
